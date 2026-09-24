<?php

declare(strict_types=1);

/**
 * Shared harness for the webhook routing and email storage suites
 * (tests/webhook_routing_test.php, tests/email_storage_test.php), in the same
 * spirit as the repository's check_*.php CLI scripts — but where those are
 * read-only diagnostics that need a live MySQL, this one runs anywhere PHP
 * does, because it brings its own database.
 *
 * Two mechanisms, both aimed at exercising the code that ships rather than a
 * copy of its decisions:
 *
 *  1. A SQLite database carrying the columns those paths read
 *     (temp_emails.is_personal, pro_webhooks.filter_mode,
 *     pro_webhook_deliveries, plus the per-hook routing tables
 *     ms_test_webhook_address_schema() adds for the suites that need them). The
 *     dispatch scenarios drive the *real* ImapProcessor::dispatchWebhooks()
 *     against it.
 *
 *  2. A throwaway docroot (the "probe") holding copies of the real pages under
 *     test plus a stub config.php backed by the same SQLite file, so
 *     pro_profile.php / pro_profile_page.php / index.php run unmodified. The
 *     stub replaces the database layer and the infrastructure helpers only:
 *     every routing, ownership and Pro-gating decision under test is the
 *     page's own code.
 *
 * The stub's helpers are deliberately narrow. Where a helper is part of what is
 * being tested (requireSameOriginRequest, proUserIsPro) its real body is
 * mirrored; where it is incidental (logMessage, flagMaliciousActivity) it is a
 * no-op. Nothing here is loaded by the deployed site.
 *
 * Usage: see tests/webhook_routing_test.php — run it with
 * `php tests/webhook_routing_test.php`.
 */

const MS_TEST_ORIGIN = 'http://localhost:8085';
const MS_TEST_EMAIL_DOMAIN = 'manjo.me';

// Pushover credentials for the fixtures. They are unmistakably not real, and
// they live only in the throwaway SQLite file: the routing under test reads the
// address flag, never the webhook's config, and one of the checks below asserts
// that neither of these values ever reaches a client response.
const MS_TEST_FAKE_TOKEN = 'fixture-pushover-app-token-not-a-credential';
const MS_TEST_FAKE_USER_KEY = 'fixture-pushover-user-key-not-a-credential';

$GLOBALS['ms_test_passed'] = 0;
$GLOBALS['ms_test_failed'] = 0;
$GLOBALS['ms_test_current_section'] = '';
$GLOBALS['ms_test_logs'] = [];

// ---------------------------------------------------------------------
// Reporting (same [OK]/[FEL] shape as the check_*.php scripts)
// ---------------------------------------------------------------------

function ms_test_section(string $title): void {
    $GLOBALS['ms_test_current_section'] = $title;
    echo "\n== {$title} ==\n";
}

function ms_test_dump($value): string {
    if (is_bool($value)) return $value ? 'true' : 'false';
    if ($value === null) return 'null';
    if (is_array($value)) return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    return var_export($value, true);
}

function ms_test_check(string $label, bool $ok, string $detail = ''): bool {
    if ($ok) {
        $GLOBALS['ms_test_passed']++;
        echo "[OK]  {$label}\n";
    } else {
        $GLOBALS['ms_test_failed']++;
        echo "[FEL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
    return $ok;
}

function ms_test_same(string $label, $expected, $actual): bool {
    $ok = $expected === $actual;
    return ms_test_check($label, $ok, $ok
        ? ''
        : 'expected ' . ms_test_dump($expected) . ', got ' . ms_test_dump($actual));
}

function ms_test_summary(): int {
    $passed = (int) $GLOBALS['ms_test_passed'];
    $failed = (int) $GLOBALS['ms_test_failed'];
    echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
    return $failed === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------

/**
 * DDL for the tables the webhook routing and the storage paths touch,
 * translated to SQLite. Only the columns these paths read are modelled; the
 * real schema has more (see migrate_webhook_addresses.php and friends).
 *
 * temp_emails.pushover_enabled is the one exception — nothing reads it since
 * #251 step 6 retired it, but it is still in the deployed schema, so it stays
 * here too.
 */
function ms_test_schema(): string {
    return <<<'SQL'
CREATE TABLE pro_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    account_type TEXT NOT NULL DEFAULT 'regular',
    pro_expires_at TEXT NULL,
    address_ttl_days INTEGER NOT NULL DEFAULT 1,
    feed_token TEXT NULL,
    created_at TEXT NULL
);

CREATE TABLE temp_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    unique_address TEXT NOT NULL,
    pro_user_id INTEGER NULL,
    is_personal INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NULL,
    created_at TEXT NULL,
    feed_token TEXT NULL,
    pushover_enabled INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE pro_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NULL,
    url TEXT NULL,
    kind TEXT NOT NULL DEFAULT 'generic',
    config TEXT NULL,
    secret TEXT NULL,
    filter_mode TEXT NOT NULL DEFAULT 'all',
    created_at TEXT NULL
);

CREATE TABLE pro_webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    next_attempt_at TEXT NULL,
    created_at TEXT NULL
);

CREATE TABLE pending_profile_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    used INTEGER NOT NULL DEFAULT 0
);
SQL;
}

/**
 * DDL for the per-hook address routing schema (epic #251, step 2), translated
 * to SQLite from migrate_webhook_addresses.php.
 *
 * Suites that exercise the per-hook routing apply this *on top of*
 * ms_test_schema() — it is an ALTER against two of ms_test_schema()'s tables,
 * so it cannot stand alone. ms_test_schema() is deliberately left without it:
 * the suites that shipped before the routing exist to keep the pre-migration
 * path honest (no pro_webhook_addresses, no pro_webhooks.include_temporary and
 * no temp_emails.hooks_paused), and they keep getting exactly that.
 */
function ms_test_webhook_address_schema(): string {
    return <<<'SQL'
CREATE TABLE pro_webhook_addresses (
    webhook_id INTEGER NOT NULL,
    temp_email_id INTEGER NOT NULL,
    created_at TEXT NULL,
    PRIMARY KEY (webhook_id, temp_email_id)
);

ALTER TABLE pro_webhooks ADD COLUMN include_temporary INTEGER NOT NULL DEFAULT 0;

ALTER TABLE temp_emails ADD COLUMN hooks_paused INTEGER NOT NULL DEFAULT 0;
SQL;
}

function ms_test_db(string $sqlitePath): PDO {
    $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL keeps the harness process' own connection from blocking the probe
    // subprocesses that write through the same file.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    return $pdo;
}

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

function ms_test_seed_user(PDO $pdo, string $email, string $accountType = 'pro', ?string $proExpiresAt = null): int {
    $stmt = $pdo->prepare('INSERT INTO pro_users (email, account_type, pro_expires_at, address_ttl_days, created_at) VALUES (?, ?, ?, 1, ?)');
    $stmt->execute([$email, $accountType, $proExpiresAt, date('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
}

/**
 * @param array{pro_user_id?:int, is_personal?:int, pushover_enabled?:int, feed_token?:?string} $overrides
 */
function ms_test_seed_address(PDO $pdo, string $localPart, array $overrides = []): int {
    $stmt = $pdo->prepare(
        'INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at, created_at, feed_token, pushover_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $localPart,
        $overrides['pro_user_id'] ?? null,
        $overrides['is_personal'] ?? 1,
        date('Y-m-d H:i:s', time() + 86400),
        date('Y-m-d H:i:s'),
        $overrides['feed_token'] ?? null,
        $overrides['pushover_enabled'] ?? 0,
    ]);
    return (int) $pdo->lastInsertId();
}

function ms_test_seed_webhook(PDO $pdo, int $userId, string $kind = 'generic', string $filterMode = 'all', string $name = ''): int {
    $config = $kind === 'pushover'
        ? (string) json_encode(['token' => MS_TEST_FAKE_TOKEN, 'user' => MS_TEST_FAKE_USER_KEY])
        : null;
    $url = $kind === 'pushover' ? 'https://api.pushover.net/1/messages.json' : 'https://example.invalid/hook';
    $stmt = $pdo->prepare('INSERT INTO pro_webhooks (user_id, name, url, kind, config, secret, filter_mode, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
    $stmt->execute([$userId, $name !== '' ? $name : ucfirst($kind) . ' hook', $url, $kind, $config, $filterMode, date('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
}

/**
 * Deliveries queued for a user, as [webhook_id => count].
 *
 * @return array<int,int>
 */
function ms_test_deliveries(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT webhook_id, COUNT(*) AS n FROM pro_webhook_deliveries WHERE user_id = ? GROUP BY webhook_id');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
        $out[(int) $row[0]] = (int) $row[1];
    }
    return $out;
}

/**
 * One address' temp_emails.pushover_enabled, or null when the row is gone.
 *
 * The column is retired (#251 step 6): no code reads it and no suite asserts on
 * it any more. The helper and its DDL stay so the schema keeps matching the
 * deployed one — the column is still there, unread, and a rollback of the code
 * has to find it.
 */
function ms_test_pushover_flag(PDO $pdo, int $addressId): ?int {
    $stmt = $pdo->prepare('SELECT pushover_enabled FROM temp_emails WHERE id = ?');
    $stmt->execute([$addressId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function ms_test_address_exists(PDO $pdo, int $addressId): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM temp_emails WHERE id = ?');
    $stmt->execute([$addressId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Payloads queued for one webhook, oldest first.
 *
 * @return list<array<string,mixed>>
 */
function ms_test_delivery_payloads(PDO $pdo, int $webhookId): array {
    $stmt = $pdo->prepare('SELECT payload FROM pro_webhook_deliveries WHERE webhook_id = ? ORDER BY id');
    $stmt->execute([$webhookId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $decoded = json_decode((string) $raw, true);
        $out[] = is_array($decoded) ? $decoded : [];
    }
    return $out;
}

/**
 * Point the in-process globals at a fresh connection to the same file, so a
 * write made by a probe subprocess is visible to the next dispatch.
 */
function ms_test_refresh_db(string $sqlitePath): PDO {
    $GLOBALS['pdo'] = ms_test_db($sqlitePath);
    return $GLOBALS['pdo'];
}

/**
 * Drive the real ImapProcessor::dispatchWebhooks() for one message.
 *
 * Requires the global $pdo (the one from the probe's stub config.php) to point
 * at the same SQLite file, because the class reaches for
 * tableHasColumn()/proUserIsPro() as globals, exactly as it does in production.
 *
 * debugMode is on so the DEBUG-level "why was this hook skipped" lines are
 * recorded: the scenarios assert on both the queued rows and the reason, which
 * is what tells "skipped by design" apart from "the lookup failed and nothing
 * was queued either way".
 */
function ms_test_dispatch(int $proUserId, array $payload): void {
    $method = new ReflectionMethod(ImapProcessor::class, 'dispatchWebhooks');
    $method->setAccessible(true);
    $processor = new ImapProcessor(['app' => ['log_level' => 'ERROR']], $GLOBALS['pdo'], true);
    $method->invoke($processor, $proUserId, $payload);
}

/**
 * Did the dispatch loop log $needle for this run? Used to tell "skipped by
 * design" apart from "the lookup blew up and nothing was queued either way".
 */
function ms_test_logged(string $needle): bool {
    foreach ($GLOBALS['ms_test_logs'] as $entry) {
        if (str_contains((string) $entry['message'], $needle)) {
            return true;
        }
    }
    return false;
}

function ms_test_forget_logs(): void {
    $GLOBALS['ms_test_logs'] = [];
}

// ---------------------------------------------------------------------
// The probe docroot: real pages + a stub config.php
// ---------------------------------------------------------------------

function ms_test_rrmdir(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        ms_test_rrmdir($path . '/' . $entry);
    }
    @rmdir($path);
}

/** Tear down both things the suite creates outside the repository. */
function ms_test_cleanup(string $probeRoot): void {
    ms_test_rrmdir($probeRoot);
    ms_test_rrmdir(sys_get_temp_dir() . '/ms-pushover-test-sessions');
}

/**
 * Build the throwaway docroot and its SQLite database. Returns the docroot.
 *
 * Pages are *copied*, never symlinked: they resolve config.php through `__DIR__`
 * or the cwd, and a symlink would send them back to the real config.php (and
 * hence to a database that is not there).
 */
function ms_test_probe_build(string $repoRoot): string {
    $root = rtrim(sys_get_temp_dir(), '/') . '/ms-pushover-test-' . bin2hex(random_bytes(4));
    if (!mkdir($root . '/client/backend', 0700, true) && !is_dir($root)) {
        throw new RuntimeException("Could not create probe docroot at {$root}");
    }

    foreach (['pro_profile.php', 'pro_profile_page.php', 'index.php', 'pro_auth.php', 'pro_trial.php', 'login_tokens.php', 'TwoFactorAuth.php', 'php_imap_processor.php', 'paddle_sync.php', 'reserved_local_parts.php'] as $page) {
        if (!copy($repoRoot . '/' . $page, $root . '/' . $page)) {
            throw new RuntimeException("Could not copy {$page} into the probe docroot");
        }
    }
    // bootstrap.php does `require __DIR__ . '/../../config.php'`, so it has to be
    // a copy too or that resolves to the real config.php.
    copy($repoRoot . '/client/backend/bootstrap.php', $root . '/client/backend/bootstrap.php');

    symlink($repoRoot . '/partials', $root . '/partials');
    symlink($repoRoot . '/assets', $root . '/assets');

    file_put_contents($root . '/config.php', ms_test_stub_config_php());
    file_put_contents($root . '/ms_probe_runner.php', ms_test_probe_runner_php());

    $pdo = ms_test_db($root . '/mailshield.sqlite');
    $pdo->exec(ms_test_schema());
    $pdo = null;

    return $root;
}

function ms_test_probe_sqlite(string $root): string {
    return $root . '/mailshield.sqlite';
}

/**
 * Run one request against the probe: seed a session, fake the request, include
 * one real page. One process per request — the pages under test exit() when
 * they answer a JSON action.
 *
 * @param array{page:string, method?:string, user_id?:int, user_email?:string,
 *              post?:array, get?:array, session?:string, origin?:?string} $request
 * @return array{stdout:string, stderr:string, exit:int, json:?array}
 */
function ms_test_request(string $root, array $request): array {
    $env = [
        'PROBE_PAGE' => (string) $request['page'],
        'PROBE_METHOD' => (string) ($request['method'] ?? 'POST'),
        'PROBE_USER_ID' => (string) ($request['user_id'] ?? 0),
        'PROBE_USER_EMAIL' => (string) ($request['user_email'] ?? ''),
        'PROBE_POST' => (string) json_encode($request['post'] ?? []),
        'PROBE_GET' => (string) json_encode($request['get'] ?? []),
        // 'origin' => null sends no Origin header at all (and no Referer).
        'PROBE_ORIGIN' => array_key_exists('origin', $request)
            ? ($request['origin'] === null ? '-' : (string) $request['origin'])
            : MS_TEST_ORIGIN,
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PROBE_SESSION_ID' => (string) ($request['session'] ?? 'msprobe1'),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Errors are logged (to stderr) rather than displayed, so stdout stays pure
    // JSON/HTML — a displayed warning ahead of the JSON would break decoding.
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL', $root . '/ms_probe_runner.php'],
        $descriptors,
        $pipes,
        $root,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the probe runner');
    }
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
 * A probed request must not have hit a PHP warning, notice or fatal — those
 * read as "the page fell over", which would make every other assertion in the
 * scenario meaningless. index.php's own error_log() lines do not carry the
 * "PHP " prefix these do, so they do not trip this.
 */
function ms_test_no_php_errors(string $label, array $response): bool {
    $errors = [];
    foreach (explode("\n", (string) $response['stderr']) as $line) {
        if (preg_match('/^PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', trim($line))) {
            $errors[] = trim($line);
        }
    }
    return ms_test_check($label, $errors === [], implode(' | ', array_slice($errors, 0, 3)));
}

/**
 * Assert a probed JSON action answered with a decodable body, and return it.
 *
 * @return array<string,mixed>|null
 */
function ms_test_json(string $label, array $response): ?array {
    $ok = ms_test_check(
        $label,
        $response['json'] !== null,
        'no JSON on stdout; exit=' . $response['exit'] . ' stdout=' . substr(trim((string) $response['stdout']), 0, 200)
            . ' stderr=' . substr(trim((string) $response['stderr']), 0, 300)
    );
    return $ok ? $response['json'] : null;
}

/**
 * The stub config.php written into the probe docroot. Same shape as the real
 * one ($config array + global $pdo); SQLite instead of MySQL.
 */
function ms_test_stub_config_php(): string {
    return <<<'PHP'
<?php
/**
 * TEST DOUBLE of config.php (#176) — written into a throwaway docroot by
 * tests/lib/pushover_harness.php. Never deployed, never committed to the real
 * tree; the copy that exists at runtime lives under sys_get_temp_dir().
 *
 * It provides the shape the pages expect and nothing more. The helpers that
 * take part in the behaviour under test (requireSameOriginRequest,
 * proUserIsPro) mirror their real bodies so the pages' own gates are exercised
 * for real; the rest are no-ops or loose stubs.
 */

// index.php defines this itself before requiring config.php.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}

$GLOBALS['ms_test_logs'] = [];

$sqlitePath = $GLOBALS['MS_TEST_SQLITE'] ?? getenv('PROBE_SQLITE');
if (!is_string($sqlitePath) || $sqlitePath === '') {
    fwrite(STDERR, "MS_TEST_SQLITE is not set - this file is a test double, not config.php\n");
    exit(70);
}

$config = [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mailshield_test',
        'user' => 'test',
        'password' => 'test',
    ],
    'email' => [
        'domain' => 'manjo.me',
        'base_url' => 'http://localhost:8085/',
    ],
    'app' => [
        'version' => 'test',
        'log_level' => 'ERROR',
        'debug_mode' => false,
        'environment' => 'test',
        'address_length' => 10,
    ],
    'attachments' => ['ttl' => 3600, 'max_size' => 10485760],
    'cron' => ['http_secret' => 'test'],
];

$pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

/** Recorded rather than written, so the harness can assert on the branch taken. */
function logMessage($level, $message, $context = null) {
    $GLOBALS['ms_test_logs'][] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    return true;
}

/** MySQL's information_schema does not exist here; sqlite has pragma_table_info. */
function tableHasColumn($table, $column) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
        $stmt->execute([(string) $table, (string) $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function sanitizeString($input, int $maxLength = 0, bool $stripHtml = false): ?string {
    if ($input === null || $input === '') return null;
    $s = str_replace("\0", '', (string) $input);
    $s = trim($s);
    if ($stripHtml) $s = strip_tags($s);
    if ($maxLength > 0 && mb_strlen($s) > $maxLength) $s = mb_substr($s, 0, $maxLength);
    return $s;
}

function sanitizeAlphanumeric($input, int $maxLength = 64, bool $allowDashes = false): ?string {
    $s = sanitizeString($input, $maxLength, true);
    if ($s === null) return null;
    $pattern = $allowDashes ? '/^[a-zA-Z0-9_-]+$/' : '/^[a-zA-Z0-9]+$/';
    return preg_match($pattern, $s) ? $s : null;
}

function sanitizeEmail($input): ?string {
    $s = sanitizeString($input, 254, true);
    return $s !== null && filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : null;
}

function escapeHtml($input): string {
    return htmlspecialchars((string) $input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Mirrors config.php's proUserIsPro(): account_type plus the expiry fallback. */
function proUserIsPro(int $userId): bool {
    global $pdo;
    static $hasColumnCache = null;
    if ($hasColumnCache === null) {
        $hasColumnCache = tableHasColumn('pro_users', 'account_type');
    }
    try {
        if ($hasColumnCache) {
            $stmt = $pdo->prepare('SELECT account_type, pro_expires_at FROM pro_users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            return $row['account_type'] === 'pro'
                && (is_null($row['pro_expires_at']) || strtotime((string) $row['pro_expires_at']) >= time());
        }
        $stmt = $pdo->prepare('SELECT pro_expires_at FROM pro_users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return is_null($row['pro_expires_at']) || strtotime((string) $row['pro_expires_at']) >= time();
    } catch (Exception $e) {
        return false;
    }
}

function proUserAccountType(int $userId): string {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT account_type FROM pro_users WHERE id = ?');
        $stmt->execute([$userId]);
        $type = $stmt->fetchColumn();
        return $type === false ? 'regular' : (string) $type;
    } catch (Exception $e) {
        return 'regular';
    }
}

/** Mirrors config.php's origin check — a mutating action's real gate. */
function requireSameOriginRequest(): bool {
    global $config;
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
    if (!is_string($source) || trim($source) === '' || strcasecmp(trim($source), 'null') === 0) return false;
    $sourceScheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
    if ($sourceScheme !== 'http' && $sourceScheme !== 'https') return false;
    $sourceHost = parse_url((string) $source, PHP_URL_HOST);
    $expectedHost = parse_url((string) ($config['email']['base_url'] ?? ''), PHP_URL_HOST);
    if (empty($sourceHost) || empty($expectedHost)) return false;
    return strcasecmp((string) $sourceHost, (string) $expectedHost) === 0;
}

/** Incidental helpers: present so an unrelated code path cannot fatal, not under test. */
function detectSuspiciousPatterns(string $input): array { return []; }
function getVisitorIp() { return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
function flagMaliciousActivity(...$args) { return true; }
function patternsWarrantingIpFlag(array $patterns): array { return $patterns; }
function deleteDirectAdminForwarder(...$args) { return true; }
function createDirectAdminForwarder(...$args) { return true; }
function generateUniqueString($length = null) { return bin2hex(random_bytes(8)); }
function isValidAddress($address) { return is_string($address) && preg_match('/^[a-f0-9]{8,16}$/i', $address) === 1; }
function isValidLocalPart($localPart) { return isValidAddress($localPart); }
function generateSignedAttachmentUrl(...$args) { return ''; }
function hasSessionAddressAccess(...$args) { return false; }
function grantSessionAddressAccess(...$args) { return true; }
function deleteStoredEmailsForTempEmail(PDO $pdo, int $tempEmailId): array { return []; }
function unlinkAttachmentFiles(array $paths): void {}
function getStats() { return []; }
function resolveUrlToPublicTarget(...$args) { return ['ip' => '127.0.0.1', 'host' => 'localhost']; }
PHP;
}

function ms_test_probe_runner_php(): string {
    return <<<'PHP'
<?php
/**
 * Test-only request runner (#176). Seeds a session and a fake request, then
 * includes one real page from the throwaway docroot this file sits in.
 */
chdir(__DIR__);

$savePath = sys_get_temp_dir() . '/ms-pushover-test-sessions';
if (!is_dir($savePath)) {
    @mkdir($savePath, 0700, true);
}
ini_set('session.use_strict_mode', '0');
session_save_path($savePath);
session_id((string) (getenv('PROBE_SESSION_ID') ?: 'msprobe1'));
session_start();
$_SESSION['pro_user_id'] = (int) getenv('PROBE_USER_ID');
$probeEmail = (string) getenv('PROBE_USER_EMAIL');
if ($probeEmail !== '') {
    $_SESSION['pro_user_email'] = $probeEmail;
}
session_write_close();

$probePage = (string) getenv('PROBE_PAGE');
$_SERVER['REQUEST_METHOD'] = (string) (getenv('PROBE_METHOD') ?: 'POST');
$_SERVER['REQUEST_URI'] = '/' . $probePage;
$_SERVER['SCRIPT_NAME'] = '/' . $probePage;
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $probePage;
$probeOrigin = (string) (getenv('PROBE_ORIGIN') ?: 'http://localhost:8085');
if ($probeOrigin !== '-') {
    $_SERVER['HTTP_ORIGIN'] = $probeOrigin;
}
$_SERVER['HTTP_HOST'] = 'localhost:8085';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$probePost = json_decode((string) getenv('PROBE_POST'), true);
$probeGet = json_decode((string) getenv('PROBE_GET'), true);
$_POST = is_array($probePost) ? $probePost : [];
$_GET = is_array($probeGet) ? $probeGet : [];

require __DIR__ . '/' . $probePage;
PHP;
}
