<?php

declare(strict_types=1);

/**
 * Harness for the Email Storage suite (#197, epic #169 step 9/10).
 *
 * The repository's first CLI suite is the Pushover one, and this is the second:
 * rather than copying tests/lib/pushover_harness.php it *requires* it and adds
 * only what the storage paths need on top.
 *
 *   - ms_test_storage_extra_schema(): the three tables the storage path writes
 *     that the Pushover schema does not model — stored_emails,
 *     email_attachments, email_stats. The probe builder below runs these
 *     alongside ms_test_schema(), never instead of it.
 *   - ms_test_storage_db(): the shared SQLite connection plus the two pieces of
 *     MySQL dialect the shipped SQL uses and SQLite lacks — TIMESTAMPDIFF()
 *     (the duplicate rule, §7.4) and the information_schema.COLUMNS probe
 *     AttachmentStorage performs before it self-heals the content_id column.
 *     Without them the service would take its *failure* branch on both, which
 *     is not what the suite means to exercise.
 *   - ms_test_storage_probe_build(): ms_test_probe_build() (root, SQLite file,
 *     schema, stub config) with the storage files staged into it and the stub
 *     config swapped for ms_test_storage_stub_config_php(). It builds on the
 *     Pushover builder rather than repeating it; the only thing it does not
 *     inherit is that builder's page list, which stages the pro_* pages this
 *     suite never requests.
 *   - ms_test_cli_php(): run one of those staged copies as the CLI process
 *     production runs it as (the DirectAdmin pipe target, the Python fallback's
 *     bridge).
 *
 * Everything else — reporting, the SQLite connection, the fixtures, the stub
 * config.php, the teardown — is the Pushover harness', used unchanged.
 *
 * The staged copies matter: EmailStorage resolves attachments/ from its own
 * __DIR__, so a suite that loaded the repository's own class would write
 * attachment files into the repository. Loading the copies keeps every file the
 * suite creates inside the throwaway docroot that ms_test_cleanup() deletes.
 *
 * Usage: see tests/email_storage_test.php — run it with
 * `php tests/email_storage_test.php`.
 */

require_once __DIR__ . '/pushover_harness.php';

/** The probe docroot's attachments/ directory, where the service writes files. */
function ms_test_storage_attachments_dir(string $root): string
{
    return rtrim($root, '/') . '/attachments';
}

/** The SQLite database the Pushover builder created inside the probe docroot. */
function ms_test_storage_sqlite(string $root): string
{
    return ms_test_probe_sqlite($root);
}

// ---------------------------------------------------------------------
// Schema: the storage tables the Pushover schema does not model
// ---------------------------------------------------------------------

/**
 * `stored_emails`, `email_attachments` and `email_stats`, translated to SQLite.
 *
 * Only the columns these paths read or write are modelled; the real schema has
 * more. Two of the columns here exist for the dialect rather than for the data:
 *
 *  - `content_id`, because AttachmentStorage's self-heal cannot run under SQLite
 *    (its `ALTER ... AFTER` is MySQL-only) — see
 *    ms_test_storage_emulate_information_schema() for the other half of that;
 *  - `MINUTE`, because MySQL's `TIMESTAMPDIFF(MINUTE, a, b)` takes its unit as a
 *    bare keyword, and SQLite resolves a bare word in that position as a column
 *    reference: the shipped duplicate-rule SQL would fail to *prepare* with "no
 *    such column: MINUTE" even with the function registered. Giving the table a
 *    NULL `MINUTE` column makes the shipped SQL prepare, and the registered
 *    stand-in ignores the unit it is handed.
 */
function ms_test_storage_extra_schema(): string
{
    return <<<'SQL'
CREATE TABLE stored_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    to_address TEXT NOT NULL,
    from_address TEXT NOT NULL DEFAULT '',
    subject TEXT NULL,
    body_text TEXT NULL,
    body_html TEXT NULL,
    received_at TEXT NULL,
    expires_at TEXT NULL,
    temp_email_id INTEGER NULL,
    MINUTE TEXT NULL
);

CREATE TABLE email_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    file_path TEXT NOT NULL,
    mime_type TEXT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NULL,
    content_id TEXT NULL
);

CREATE TABLE email_stats (
    stat_name TEXT PRIMARY KEY,
    stat_value INTEGER NOT NULL DEFAULT 0,
    last_updated TEXT NULL
);
SQL;
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------

/**
 * MySQL's `TIMESTAMPDIFF(MINUTE, a, b)` is `b - a`; SQLite has no such
 * function. The duplicate rule's SQL is shipped verbatim (see EmailStorage),
 * so the dialect it runs on is what has to bend — this stand-in, plus the NULL
 * `MINUTE` column the shipped SQL's bare unit keyword resolves to (see
 * ms_test_storage_extra_schema()). $unit is that column's value and is ignored.
 *
 * Note: the probe's stub config.php registers the same stand-in for its own
 * connection, because the bridge and the pipe run in their own processes and
 * cannot share this one. Two copies of six lines, deliberately, so that neither
 * process depends on the other's file.
 */
function ms_test_storage_timestampdiff(): callable
{
    return static function ($unit, $a, $b): int {
        $from = strtotime((string) $a);
        $to = strtotime((string) $b);
        if ($from === false || $to === false) {
            return 0;
        }
        return (int) (($to - $from) / 60);
    };
}

/**
 * Make AttachmentStorage's content_id probe answer under SQLite.
 *
 * The class asks MySQL's `information_schema.COLUMNS ... WHERE TABLE_SCHEMA =
 * DATABASE()` whether `email_attachments.content_id` exists, and adds it with an
 * `ALTER TABLE ... AFTER mime_type` if not. Neither exists here, so without this
 * the probe would answer "missing", the ALTER would fail on the `AFTER` clause,
 * and every attachment would silently take the six-column INSERT — i.e. the
 * suite would test neither the self-heal nor the column.
 *
 * So the probe's view of the schema is emulated: an in-memory database attached
 * under the name the query expects, carrying exactly the one row the storage
 * path looks up. That is a stand-in for the dialect, not for the decision — the
 * lookup, the column list and the INSERT all stay the shipped ones.
 */
function ms_test_storage_emulate_information_schema(PDO $pdo): void
{
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');

    $stmt = $pdo->prepare('INSERT INTO information_schema.COLUMNS (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME) VALUES (?, ?, ?)');
    $stmt->execute(['mailshield_test', 'email_attachments', 'content_id']);
}

/** The suite's connection: the shared one, plus the MySQL dialect above. */
function ms_test_storage_db(string $sqlitePath): PDO
{
    $pdo = ms_test_db($sqlitePath);
    $pdo->sqliteCreateFunction('TIMESTAMPDIFF', ms_test_storage_timestampdiff(), 3);
    // The database name the emulated information_schema rows are filed under.
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'mailshield_test', 0);
    ms_test_storage_emulate_information_schema($pdo);

    return $pdo;
}

// ---------------------------------------------------------------------
// The probe docroot: staged copies of the storage paths + a stub config.php
// ---------------------------------------------------------------------

/**
 * Build the throwaway docroot the storage suite runs against and return its
 * path. Extends what ms_test_probe_build() produced (see the file header).
 */
function ms_test_storage_probe_build(string $repoRoot): string
{
    $root = ms_test_probe_build($repoRoot);

    // The storage stub is the shared one plus the helpers these paths reach for
    // at their boundary; both probes overwrite the same stub filename, and each
    // builder writes the variant its own pages need.
    file_put_contents($root . '/config.php', ms_test_storage_stub_config_php());

    // parse.php redirects PHP's error_log into debug_logs/ so a pipe delivery
    // stays silent; give it a directory to write to.
    @mkdir($root . '/debug_logs', 0700, true);

    // The classes under test are these copies, never the repository's own: see
    // the file header. debug_logger.php comes along because MailParser requires
    // it unconditionally, and it writes into the docroot's own debug_logs/.
    foreach (['MailParser.php', 'debug_logger.php', 'php_imap_processor.php', 'parse.php', 'python_imap_bridge.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $root . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the storage probe docroot");
        }
    }
    if (!is_dir($root . '/EmailStorage') && !mkdir($root . '/EmailStorage', 0700, true)) {
        throw new RuntimeException('Could not create EmailStorage/ in the storage probe docroot');
    }
    foreach (glob($repoRoot . '/EmailStorage/*.php') ?: [] as $file) {
        if (!copy($file, $root . '/EmailStorage/' . basename($file))) {
            throw new RuntimeException('Could not copy ' . basename($file) . ' into the storage probe docroot');
        }
    }

    // parse.php requires vendor/autoload.php when it is there. The suite does
    // not require a composer install (MailParser degrades to an empty parse
    // without it), but when vendor/ exists the staged copy gets the real MIME
    // parser and the .eml fixtures parse for real.
    if (is_dir($repoRoot . '/vendor')) {
        symlink($repoRoot . '/vendor', $root . '/vendor');
    }

    $pdo = ms_test_db(ms_test_storage_sqlite($root));
    $pdo->exec(ms_test_storage_extra_schema());
    $pdo = null;

    return $root;
}

/**
 * The stub config.php for the storage probe: the shared stub plus the two
 * helpers the storage adapters call and the one SQL function the duplicate rule
 * needs. Appended rather than duplicated — the shared stub's body is the single
 * copy of everything the two probes have in common.
 */
function ms_test_storage_stub_config_php(): string
{
    return ms_test_stub_config_php() . <<<'PHP'

/**
 * --- Email Storage suite additions (#197) ---------------------------------
 * Appended by tests/lib/email_storage_harness.php. Only what these paths call
 * at their boundary, and the one piece of MySQL dialect that has no SQLite
 * equivalent.
 */

/** Mirrors config.php's sanitizeLocalPart() — parse.php validates with it. */
function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = sanitizeString($local, $maxLength, true);
    if ($s === null) return null;
    if (strlen($s) < $minLength) return null;
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $s)) return null;
    return strtolower($s);
}

/**
 * Mirrors config.php's updateStat() against the probe's email_stats table, so
 * the counters the service writes are readable as rows rather than as a
 * side-channel of this double.
 */
function updateStat($statName, $increment = 1) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT INTO email_stats (stat_name, stat_value, last_updated) VALUES (?, ?, ?) ON CONFLICT(stat_name) DO UPDATE SET stat_value = stat_value + excluded.stat_value, last_updated = excluded.last_updated');
        return $stmt->execute([(string)$statName, (int)$increment, date('Y-m-d H:i:s')]);
    } catch (Exception $e) {
        return false;
    }
}

// The suite's own connection gets the same stand-in from ms_test_storage_db();
// this copy serves the subprocesses (parse.php, python_imap_bridge.php), which
// cannot share it. MySQL's TIMESTAMPDIFF(MINUTE, a, b) is b - a.
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', static function ($unit, $a, $b): int {
    $from = strtotime((string)$a);
    $to = strtotime((string)$b);
    if ($from === false || $to === false) return 0;
    return (int)(($to - $from) / 60);
}, 3);
PHP;
}

// ---------------------------------------------------------------------
// Running a staged script as the CLI process production runs it as
// ---------------------------------------------------------------------

/**
 * Run one staged script as its own PHP process, feeding it stdin.
 *
 * This is how the suite reaches the two adapters that are CLI entrypoints: the
 * DirectAdmin pipe target (parse.php) and the Python fallback's storage bridge
 * (python_imap_bridge.php). Their exit code and their streams are part of what
 * they promise — the pipe must print nothing at all, because Exim bounces on
 * any output — so the suite needs the real process, not an include.
 *
 * @param list<string> $argv Arguments after the script path.
 * @param array<string,string> $env Extra environment; PROBE_SQLITE points the
 *        stub config.php at the probe's database.
 * @return array{stdout:string, stderr:string, exit:int, json:?array}
 */
function ms_test_cli_php(string $root, string $script, string $stdin = '', array $env = [], array $argv = []): array
{
    $command = array_merge(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL', $root . '/' . $script],
        $argv
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $root,
        $env + ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start {$script}");
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $json = json_decode(trim($stdout), true);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit' => $exit,
        'json' => is_array($json) ? $json : null,
    ];
}

/**
 * The environment every staged CLI run needs: the probe's database, which the
 * stub config.php resolves through PROBE_SQLITE.
 */
function ms_test_storage_env(string $root): array
{
    return ['PROBE_SQLITE' => ms_test_storage_sqlite($root)];
}

// ---------------------------------------------------------------------
// Fixtures and readers
// ---------------------------------------------------------------------

/** `pro_users.address_ttl_days`, the Pro retention window the service reads. */
function ms_test_set_ttl(PDO $pdo, int $userId, int $days): void
{
    $stmt = $pdo->prepare('UPDATE pro_users SET address_ttl_days = ? WHERE id = ?');
    $stmt->execute([$days, $userId]);
}

/** Move an address' own expiry into the past, so the pipe's gate can refuse it. */
function ms_test_expire_address(PDO $pdo, int $addressId): void
{
    $stmt = $pdo->prepare('UPDATE temp_emails SET expires_at = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s', time() - 3600), $addressId]);
}

/** An address' own `expires_at`, as the service reads it. */
function ms_test_address_expiry(PDO $pdo, int $addressId): string
{
    $stmt = $pdo->prepare('SELECT expires_at FROM temp_emails WHERE id = ?');
    $stmt->execute([$addressId]);

    return (string) $stmt->fetchColumn();
}

/** An IncomingEmail with the fixture values the suite mostly wants. */
function ms_test_incoming(array $overrides = []): IncomingEmail
{
    $has = static fn(string $key): bool => array_key_exists($key, $overrides);

    return new IncomingEmail(
        toAddress: (string) ($overrides['toAddress'] ?? ''),
        receivedAt: $overrides['receivedAt'] ?? new DateTimeImmutable(),
        fromAddress: $has('fromAddress') ? $overrides['fromAddress'] : 'sender@example.com',
        subject: $has('subject') ? $overrides['subject'] : 'Fixture subject',
        bodyText: $has('bodyText') ? $overrides['bodyText'] : 'Fixture body.',
        bodyHtml: $has('bodyHtml') ? $overrides['bodyHtml'] : null,
        tempEmailId: $overrides['tempEmailId'] ?? null,
        proUserId: $overrides['proUserId'] ?? null,
        expiresAt: $overrides['expiresAt'] ?? null,
        attachments: $overrides['attachments'] ?? []
    );
}

/** One `stored_emails` row, or null when there is none. */
function ms_test_stored_email(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM stored_emails WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Rows in $table, optionally narrowed by a WHERE fragment.
 *
 * $table and $where are interpolated because every call site passes a literal
 * table name and a fixed fragment; only the compared values are bound.
 */
function ms_test_count(PDO $pdo, string $table, string $where = '1', array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** One `email_stats` counter, 0 when the stat was never written. */
function ms_test_stat(PDO $pdo, string $statName): int
{
    $stmt = $pdo->prepare('SELECT stat_value FROM email_stats WHERE stat_name = ?');
    $stmt->execute([$statName]);
    $value = $stmt->fetchColumn();

    return $value === false ? 0 : (int) $value;
}

/**
 * `email_attachments` rows for one email, oldest first.
 *
 * @return list<array<string,mixed>>
 */
function ms_test_attachment_rows(PDO $pdo, int $emailId): array
{
    $stmt = $pdo->prepare('SELECT * FROM email_attachments WHERE email_id = ? ORDER BY id');
    $stmt->execute([$emailId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * The files directly inside a docroot's attachments/ directory, basenames
 * sorted. An absent directory reads as empty, so a suite that wrote nothing can
 * assert on it without special-casing.
 *
 * @return list<string>
 */
function ms_test_storage_files(string $root): array
{
    $directory = ms_test_storage_attachments_dir($root);
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_file($directory . '/' . $entry)) {
            $files[] = $entry;
        }
    }
    sort($files);

    return $files;
}

// ---------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------

/**
 * Will the staged copies have a MIME parser?
 *
 * MailParser parses nothing without composer's ZBateson package — the silent
 * degradation parse.php's own header comment warns about — so the assertions
 * that depend on real parsing are skipped, loudly, when vendor/ is absent
 * rather than asserting the empty-parse fallback instead. Checked on the
 * filesystem, because what decides it is whether parse.php finds
 * vendor/autoload.php next to itself, not what this process has loaded.
 */
function ms_test_has_mime_parser(): bool
{
    return is_file(dirname(__DIR__, 2) . '/vendor/autoload.php');
}

/** Report a check that could not run, without counting it as a pass or a fail. */
function ms_test_skip(string $label, string $reason): void
{
    echo "[SKIP] {$label} -- {$reason}\n";
}
