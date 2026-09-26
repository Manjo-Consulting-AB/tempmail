<?php

declare(strict_types=1);

/**
 * Regression coverage for RSS feed tokens at rest (#315, feed_token.php,
 * migrate_feed_token_encryption.php).
 *
 * Run with:  php tests/feed_token_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database and no network
 * are needed: it reuses the throwaway-docroot harness in
 * tests/lib/pushover_harness.php (tests/webhook_routing_test.php's own
 * pattern) for the account-wide/per-address feed actions in pro_profile.php
 * and index.php, adds pro_feed.php + feed_token.php to that same docroot for
 * the public lookup-by-hash endpoint, and drives the real
 * migrate_feed_token_encryption.php as a separate subprocess (its own
 * throwaway SQLite file) for the migration section - the same "real CLI
 * entrypoint as a subprocess" pattern tests/email_storage_test.php uses for
 * parse.php.
 *
 * The harness file itself is not modified: this suite copies the extra files
 * it needs into the docroot after ms_test_probe_build() and layers its own
 * ALTER TABLE statements onto the SQLite schema it returns.
 */

$msRepoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension (it brings its own SQLite database so it needs no MySQL).\n");
    exit(1);
}

require __DIR__ . '/lib/pushover_harness.php';

$FEED_TEST_KEY = 'feed-token-test-webhooks-key-' . bin2hex(random_bytes(8));
putenv('WEBHOOKS_KEY=' . $FEED_TEST_KEY);
$_ENV['WEBHOOKS_KEY'] = $FEED_TEST_KEY;
require $msRepoRoot . '/feed_token.php';

// ---------------------------------------------------------------------
// Extra schema + docroot helpers (kept local to this suite; the shared
// harness is not touched)
// ---------------------------------------------------------------------

/** DDL this suite needs on top of ms_test_schema(): the #315 columns, plus
 * the minimal stored_emails table pro_feed.php's queries join against. */
function ms_feed_extra_schema(): string
{
    return <<<'SQL'
ALTER TABLE pro_users ADD COLUMN feed_token_hash TEXT NULL;
ALTER TABLE pro_users ADD COLUMN feed_token_enc TEXT NULL;
ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL;
ALTER TABLE temp_emails ADD COLUMN feed_token_hash TEXT NULL;
ALTER TABLE temp_emails ADD COLUMN feed_token_enc TEXT NULL;

CREATE TABLE stored_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    temp_email_id INTEGER NOT NULL,
    from_address TEXT NULL,
    subject TEXT NULL,
    body_html TEXT NULL,
    body_text TEXT NULL,
    received_at TEXT NULL
);

CREATE TABLE email_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_id INTEGER NOT NULL,
    filename TEXT NULL,
    mime_type TEXT NULL
);
SQL;
}

/**
 * Copies the extra files pro_feed.php needs (and feed_token.php itself) into
 * a docroot ms_test_probe_build() already built, and patches its stub
 * config.php to (a) expose $webhooksKey via the environment when
 * $webhooksKey is not null, and (b) define sanitizeInt(), which pro_feed.php
 * needs and the shared stub does not define.
 */
function ms_feed_prepare_probe(string $repoRoot, string $probe, ?string $webhooksKey): void
{
    foreach (['pro_feed.php', 'feed_token.php', 'email_html_sanitizer.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    $configPhp = ms_test_stub_config_php();
    $extra = "\nfunction sanitizeInt(\$input, int \$min = 0, int \$max = PHP_INT_MAX, ?int \$default = null): ?int {\n"
        . "    if (\$input === null || \$input === '' || !is_numeric(\$input)) return \$default;\n"
        . "    \$v = (int) \$input;\n"
        . "    if (\$v < \$min) return \$min;\n"
        . "    if (\$v > \$max) return \$max;\n"
        . "    return \$v;\n"
        . "}\n";
    if ($webhooksKey !== null) {
        $escaped = addslashes($webhooksKey);
        $extra .= "putenv('WEBHOOKS_KEY=" . $escaped . "');\n\$_ENV['WEBHOOKS_KEY'] = '" . $escaped . "';\n";
    }
    $configPhp = str_replace("<?php\n", "<?php\n{$extra}", $configPhp, $count);
    if ($count !== 1) {
        throw new RuntimeException('Could not patch the stub config.php');
    }
    if (file_put_contents($probe . '/config.php', $configPhp) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }
}

/**
 * pro_feed.php refuses php_sapi_name() === 'cli' (see its own top-of-file
 * guard against being invoked as a script rather than served), so it cannot
 * be driven through the CLI-based probe runner ms_test_request() uses for
 * every other page in this suite. This starts PHP's built-in web server
 * (SAPI 'cli-server', which passes that guard) against a probe docroot and
 * returns [process resource, port] to fetch from and later shut down.
 */
function ms_feed_start_server(string $root): array
{
    // The stub config.php reads its SQLite path from PROBE_SQLITE (set by
    // ms_test_request() for the CLI probe runner); the built-in server is a
    // long-lived process of its own, so it needs the same variable in its
    // environment for the whole time it is up.
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:{$port}", '-t', $root],
            $descriptors,
            $pipes,
            $root,
            $env
        );
        if (!is_resource($proc)) {
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        usleep(300000);
        $status = proc_get_status($proc);
        if ($status['running']) {
            return [$proc, $port, $pipes];
        }
        proc_close($proc);
    }
    throw new RuntimeException("Could not start PHP's built-in server for {$root}");
}

function ms_feed_stop_server(array $server): void
{
    [$proc, , $pipes] = $server;
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

/** GET pro_feed.php?token=... against a running built-in-server probe; returns the raw body. */
function ms_feed_fetch(int $port, string $token): string
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . '/pro_feed.php?token=' . rawurlencode($token), false, $ctx);
    return $body === false ? '' : $body;
}

/** POST one pro_profile.php feed action as $userId. */
function ms_feed_action(string $probe, string $action, array $fields, int $userId): array
{
    $response = ms_test_request($probe, [
        'page' => 'pro_profile.php',
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'post' => array_merge(['action' => $action], $fields),
    ]);
    return ms_test_json("action {$action}", $response) ?? [];
}

/** One row's feed_token/_hash/_enc from $table, or null when the row is gone. */
function ms_feed_row(PDO $pdo, string $table, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT feed_token, feed_token_hash, feed_token_enc FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Run migrate_feed_token_encryption.php as a subprocess against its own throwaway SQLite file. */
function ms_feed_run_migration(string $migProbe, array $args, ?string $webhooksKey): array
{
    $env = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    if ($webhooksKey !== null) {
        $env['WEBHOOKS_KEY'] = $webhooksKey;
    }
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        array_merge([PHP_BINARY, '-d', 'display_errors=0', $migProbe . '/migrate_feed_token_encryption.php'], $args),
        $descriptors,
        $pipes,
        $migProbe,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the migration subprocess');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
}

/** Build the throwaway docroot for the migration subprocess: just the three files it needs, plus a minimal stub config.php/SQLite file. */
function ms_feed_build_migration_probe(string $repoRoot): array
{
    $root = rtrim(sys_get_temp_dir(), '/') . '/ms-feed-migration-' . bin2hex(random_bytes(4));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException("Could not create migration probe at {$root}");
    }
    foreach (['migrate_feed_token_encryption.php', 'feed_token.php', 'webhook_secret.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $root . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the migration probe");
        }
    }
    $sqlitePath = $root . '/mailshield.sqlite';
    $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT, feed_token TEXT NULL)');
    $pdo->exec('CREATE TABLE temp_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, feed_token TEXT NULL)');
    $pdo = null;

    // Minimal stub: only what migrate_feed_token_encryption.php itself calls
    // (tableHasColumn(), the global $pdo/$config) - no page-request machinery.
    $configPhp = <<<PHP
<?php
if (!defined('TEMPMAIL_APP')) { define('TEMPMAIL_APP', true); }
\$config = ['db' => ['name' => 'mailshield_test']];
\$pdo = new PDO('sqlite:{$sqlitePath}', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function tableHasColumn(\$table, \$column) {
    global \$pdo;
    try {
        \$stmt = \$pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
        \$stmt->execute([(string) \$table, (string) \$column]);
        return (int) \$stmt->fetchColumn() > 0;
    } catch (Exception \$e) {
        return false;
    }
}
PHP;
    file_put_contents($root . '/config.php', $configPhp);

    return [$root, $sqlitePath];
}

// ---------------------------------------------------------------------
// Docroot: pro_profile.php / index.php feed actions + pro_feed.php lookup
// ---------------------------------------------------------------------

echo "Mail Shield — RSS feed tokens at rest (#315)\n";

$probe = ms_test_probe_build($msRepoRoot);
echo "probe docroot: {$probe}\n";
ms_feed_prepare_probe($msRepoRoot, $probe, $FEED_TEST_KEY);
$probeServer = ms_feed_start_server($probe);
$probePort = $probeServer[1];

$pdo = ms_test_db(ms_test_probe_sqlite($probe));
foreach (explode(';', ms_feed_extra_schema()) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
        $pdo->exec($stmt);
    }
}

$userA = ms_test_seed_user($pdo, 'alice@example.com', 'pro');
$addrA = ms_test_seed_address($pdo, 'feedaddr1', ['pro_user_id' => $userA]);

// ---------------------------------------------------------------------
// 1. Account-wide feed: generate, show, regenerate
// ---------------------------------------------------------------------

ms_test_section('1. Account-wide feed: generate, show, regenerate');

$res = ms_feed_action($probe, 'feed_get_token', [], $userA);
ms_test_same('1a. feed_get_token succeeds', true, $res['success'] ?? null);
$tokenV1 = (string) ($res['token'] ?? '');
ms_test_check('1b. the token is 64 hex characters', preg_match('/^[a-f0-9]{64}$/', $tokenV1) === 1);

$pdo = ms_test_refresh_db(ms_test_probe_sqlite($probe));
$row = ms_feed_row($pdo, 'pro_users', $userA);
ms_test_same('1c. feed_token_hash is the SHA-256 of the token', feedTokenHash($tokenV1), $row['feed_token_hash'] ?? null);
ms_test_same('1d. feed_token_enc decrypts back to the token', $tokenV1, webhookSecretDecrypt($row['feed_token_enc'] ?? null));
ms_test_same('1e. the plaintext feed_token column is never written', null, $row['feed_token'] ?? null);

$res2 = ms_feed_action($probe, 'feed_get_token', [], $userA);
ms_test_same('1f. a second feed_get_token returns the same token (show, not a new one)', $tokenV1, $res2['token'] ?? null);

$res3 = ms_feed_action($probe, 'feed_regenerate', [], $userA);
ms_test_same('1g. feed_regenerate succeeds', true, $res3['success'] ?? null);
$tokenV2 = (string) ($res3['token'] ?? '');
ms_test_check('1h. regeneration returns a different token', $tokenV2 !== '' && $tokenV2 !== $tokenV1);

$pdo = ms_test_refresh_db(ms_test_probe_sqlite($probe));
$row = ms_feed_row($pdo, 'pro_users', $userA);
ms_test_same('1i. the stored hash now matches the new token', feedTokenHash($tokenV2), $row['feed_token_hash'] ?? null);

// ---------------------------------------------------------------------
// 2. pro_feed.php: resolves by hash, old token 404s after regenerate
// ---------------------------------------------------------------------

ms_test_section('2. pro_feed.php resolves by hash; regeneration invalidates the old token');

$oldResp = ms_feed_fetch($probePort, $tokenV1);
ms_test_check('2a. the pre-regeneration token no longer resolves', str_contains($oldResp, 'Invalid token'));

$newResp = ms_feed_fetch($probePort, $tokenV2);
ms_test_check('2b. the current token resolves', str_starts_with(trim($newResp), '<?xml'));
ms_test_check('2c. ... and renders as RSS', str_contains($newResp, '<rss version="2.0">'));

// ---------------------------------------------------------------------
// 3. A malformed token is rejected before any query
// ---------------------------------------------------------------------

ms_test_section('3. A malformed token is rejected before any query');

foreach ([
    'too-short' => 'abc123',
    'uppercase' => strtoupper($tokenV2),
    'non-hex' => str_repeat('g', 64),
    'empty' => '',
] as $label => $bad) {
    $resp = ms_feed_fetch($probePort, $bad);
    ms_test_check(
        "3.{$label}: rejected without matching the hash lookup",
        str_contains($resp, 'Missing or invalid token') || str_contains($resp, 'Invalid request'),
        'got: ' . trim($resp)
    );
}

// ---------------------------------------------------------------------
// 4. Per-address feed: generate, show, regenerate, disable
// ---------------------------------------------------------------------

ms_test_section('4. Per-address feed: generate, show, regenerate, disable');

$res = ms_feed_action($probe, 'address_feed_get_token', ['id' => $addrA], $userA);
ms_test_same('4a. address_feed_get_token succeeds', true, $res['success'] ?? null);
$addrTokenV1 = (string) ($res['token'] ?? '');
ms_test_check('4b. the token is 64 hex characters', preg_match('/^[a-f0-9]{64}$/', $addrTokenV1) === 1);

$pdo = ms_test_refresh_db(ms_test_probe_sqlite($probe));
$row = ms_feed_row($pdo, 'temp_emails', $addrA);
ms_test_same('4c. feed_token_hash matches', feedTokenHash($addrTokenV1), $row['feed_token_hash'] ?? null);
ms_test_same('4d. feed_token_enc decrypts back', $addrTokenV1, webhookSecretDecrypt($row['feed_token_enc'] ?? null));
ms_test_same('4e. the plaintext column stays NULL', null, $row['feed_token'] ?? null);

$res2 = ms_feed_action($probe, 'address_feed_get_token', ['id' => $addrA], $userA);
ms_test_same('4f. a second call shows the same token', $addrTokenV1, $res2['token'] ?? null);

$respFeed = ms_feed_fetch($probePort, $addrTokenV1);
ms_test_check('4g. the per-address token resolves via pro_feed.php', str_contains($respFeed, '<rss version="2.0">'));

$res3 = ms_feed_action($probe, 'address_feed_regenerate', ['id' => $addrA], $userA);
$addrTokenV2 = (string) ($res3['token'] ?? '');
ms_test_check('4h. regeneration returns a new token', $addrTokenV2 !== '' && $addrTokenV2 !== $addrTokenV1);

$oldResp = ms_feed_fetch($probePort, $addrTokenV1);
ms_test_check('4i. the old per-address token 404s after regeneration', str_contains($oldResp, 'Invalid token'));
$newResp = ms_feed_fetch($probePort, $addrTokenV2);
ms_test_check('4j. the new one resolves', str_contains($newResp, '<rss version="2.0">'));

$res4 = ms_feed_action($probe, 'address_feed_disable', ['id' => $addrA], $userA);
ms_test_same('4k. address_feed_disable succeeds', true, $res4['success'] ?? null);

$pdo = ms_test_refresh_db(ms_test_probe_sqlite($probe));
$row = ms_feed_row($pdo, 'temp_emails', $addrA);
ms_test_same('4l. feed_token is cleared', null, $row['feed_token'] ?? null);
ms_test_same('4m. feed_token_hash is cleared', null, $row['feed_token_hash'] ?? null);
ms_test_same('4n. feed_token_enc is cleared', null, $row['feed_token_enc'] ?? null);

$offResp = ms_feed_fetch($probePort, $addrTokenV2);
ms_test_check('4o. the disabled feed no longer resolves', str_contains($offResp, 'Invalid token'));

// ---------------------------------------------------------------------
// 5. list_personal.feed_enabled follows feed_token_hash
// ---------------------------------------------------------------------

ms_test_section('5. list_personal.feed_enabled follows feed_token_hash');

$addrB = ms_test_seed_address($pdo, 'feedaddr2', ['pro_user_id' => $userA]);
$listResp = ms_test_request($probe, ['page' => 'index.php', 'method' => 'POST', 'user_id' => $userA, 'user_email' => 'alice@example.com', 'post' => ['action' => 'list_personal']]);
$list = ms_test_json('5a. list_personal answers', $listResp);
$byId = [];
foreach (($list['personal'] ?? []) as $r) {
    $byId[(int) $r['id']] = $r;
}
ms_test_same('5b. the disabled address reports feed_enabled=false', false, $byId[$addrA]['feed_enabled'] ?? null);
ms_test_same('5c. an address with no feed reports feed_enabled=false', false, $byId[$addrB]['feed_enabled'] ?? null);

$res = ms_feed_action($probe, 'address_feed_get_token', ['id' => $addrB], $userA);
ms_test_same('5d. minting a token for it succeeds', true, $res['success'] ?? null);
$listResp = ms_test_request($probe, ['page' => 'index.php', 'method' => 'POST', 'user_id' => $userA, 'user_email' => 'alice@example.com', 'post' => ['action' => 'list_personal']]);
$list = ms_test_json('5e. list_personal answers again', $listResp);
$byId = [];
foreach (($list['personal'] ?? []) as $r) {
    $byId[(int) $r['id']] = $r;
}
ms_test_same('5f. it now reports feed_enabled=true', true, $byId[$addrB]['feed_enabled'] ?? null);
ms_test_check(
    '5g. list_personal never returns the credential itself',
    !array_key_exists('feed_token', $byId[$addrB] ?? []) && !array_key_exists('feed_token_hash', $byId[$addrB] ?? []),
    'the credential leaked into the address list'
);

// ---------------------------------------------------------------------
// 6. Without WEBHOOKS_KEY: create refuses, lookup still works
// ---------------------------------------------------------------------

ms_test_section('6. Without WEBHOOKS_KEY: create refuses, lookup still works');

$probeNoKey = ms_test_probe_build($msRepoRoot);
ms_feed_prepare_probe($msRepoRoot, $probeNoKey, null);
$probeNoKeyServer = ms_feed_start_server($probeNoKey);
$probeNoKeyPort = $probeNoKeyServer[1];
$pdoNoKey = ms_test_db(ms_test_probe_sqlite($probeNoKey));
foreach (explode(';', ms_feed_extra_schema()) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
        $pdoNoKey->exec($stmt);
    }
}

$userNoKey = ms_test_seed_user($pdoNoKey, 'nokey@example.com', 'pro');

// Seed an already-migrated token directly (as if minted before the key went
// missing, or restored from a backup) - lookup by hash needs no key at all.
$preExistingToken = feedTokenGenerate();
$stmt = $pdoNoKey->prepare('UPDATE pro_users SET feed_token_hash = ?, feed_token_enc = ? WHERE id = ?');
$stmt->execute([feedTokenHash($preExistingToken), webhookSecretEncrypt($preExistingToken, $FEED_TEST_KEY), $userNoKey]);

$res = ms_feed_action($probeNoKey, 'feed_get_token', [], $userNoKey);
ms_test_same('6a. show refuses (the existing token cannot be decrypted without the key)', false, $res['success'] ?? null);
ms_test_check('6b. ... and offers no ciphertext', !isset($res['token']));

$pdoNoKey = ms_test_refresh_db(ms_test_probe_sqlite($probeNoKey));
$row = ms_feed_row($pdoNoKey, 'pro_users', $userNoKey);
ms_test_same('6c. the existing pair is untouched by the failed show', feedTokenHash($preExistingToken), $row['feed_token_hash'] ?? null);

$freshUserNoKey = ms_test_seed_user($pdoNoKey, 'freshnokey@example.com', 'pro');
$res = ms_feed_action($probeNoKey, 'feed_get_token', [], $freshUserNoKey);
ms_test_same('6d. minting a brand-new token also refuses', false, $res['success'] ?? null);

$pdoNoKey = ms_test_refresh_db(ms_test_probe_sqlite($probeNoKey));
$row = ms_feed_row($pdoNoKey, 'pro_users', $freshUserNoKey);
ms_test_same('6e. nothing was written for the fresh account', null, $row['feed_token_hash'] ?? null);

$lookupResp = ms_feed_fetch($probeNoKeyPort, $preExistingToken);
ms_test_check('6f. lookup by the existing token still resolves without the key', str_contains($lookupResp, '<rss version="2.0">'));

// ---------------------------------------------------------------------
// 7. Migration: backfill and --null-plaintext (the real script, subprocess)
// ---------------------------------------------------------------------

ms_test_section('7. Migration: backfill and --null-plaintext');

[$migProbe, $migSqlite] = ms_feed_build_migration_probe($msRepoRoot);
$migPdo = new PDO('sqlite:' . $migSqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$migTokenA = feedTokenGenerate();
$migTokenB = feedTokenGenerate();
$migPdo->exec("INSERT INTO pro_users (feed_token) VALUES ('{$migTokenA}')");
$migPdo->exec("INSERT INTO temp_emails (feed_token) VALUES ('{$migTokenB}')");
$migPdo->exec('INSERT INTO pro_users (feed_token) VALUES (NULL)'); // untouched row, must stay untouched

// No key: refuses outright (exit 2), nothing written.
$noKeyRun = ms_feed_run_migration($migProbe, ['--dry-run'], null);
ms_test_same('7a. without WEBHOOKS_KEY the migration exits 2', 2, $noKeyRun['exit']);

// Dry run: reports what it would do, writes nothing.
$dry = ms_feed_run_migration($migProbe, ['--dry-run'], $FEED_TEST_KEY);
ms_test_same('7b. a dry run exits 0', 0, $dry['exit']);
ms_test_check('7c. ... and reports it would add the columns', str_contains($dry['stdout'], 'would add column'));

$hasHashColYet = (int) $migPdo->query("SELECT COUNT(*) FROM pragma_table_info('pro_users') WHERE name = 'feed_token_hash'")->fetchColumn() > 0;
ms_test_check('7d. the dry run left the table without the new columns', !$hasHashColYet, 'feed_token_hash already exists after a dry run');

// Real run: adds the columns, fills the pair, leaves the plaintext in place.
$real = ms_feed_run_migration($migProbe, [], $FEED_TEST_KEY);
ms_test_same('7e. the real run exits 0', 0, $real['exit']);

$rowA = $migPdo->query("SELECT * FROM pro_users WHERE feed_token = '{$migTokenA}'")->fetch();
ms_test_same('7f. pro_users.feed_token_hash is filled correctly', feedTokenHash($migTokenA), $rowA['feed_token_hash'] ?? null);
ms_test_same('7g. pro_users.feed_token_enc decrypts back to the token', $migTokenA, webhookSecretDecrypt($rowA['feed_token_enc'] ?? null, $FEED_TEST_KEY));
ms_test_same('7h. the plaintext survives the backfill (no --null-plaintext yet)', $migTokenA, $rowA['feed_token'] ?? null);

$rowB = $migPdo->query("SELECT * FROM temp_emails WHERE feed_token = '{$migTokenB}'")->fetch();
ms_test_same('7i. temp_emails.feed_token_hash is filled correctly', feedTokenHash($migTokenB), $rowB['feed_token_hash'] ?? null);

$nullRow = $migPdo->query('SELECT feed_token_hash FROM pro_users WHERE feed_token IS NULL')->fetch();
ms_test_same('7j. a row without a token is left alone', null, $nullRow['feed_token_hash'] ?? null);

// A second real run is a no-op (idempotent).
$second = ms_feed_run_migration($migProbe, [], $FEED_TEST_KEY);
ms_test_same('7k. a second run exits 0', 0, $second['exit']);
ms_test_check('7l. ... and reports the row as already consistent, not refilled', str_contains($second['stdout'], 'already consistent'));

// --null-plaintext: dry run first, then for real.
$nullDry = ms_feed_run_migration($migProbe, ['--null-plaintext', '--dry-run'], $FEED_TEST_KEY);
ms_test_same('7m. --null-plaintext --dry-run exits 0', 0, $nullDry['exit']);
ms_test_check('7n. ... and reports it would null the plaintext', str_contains($nullDry['stdout'], 'would be nulled'));

$rowA = $migPdo->query("SELECT feed_token FROM pro_users WHERE feed_token_hash = '" . feedTokenHash($migTokenA) . "'")->fetch();
ms_test_same('7o. the dry run left the plaintext untouched', $migTokenA, $rowA['feed_token'] ?? null);

$nullReal = ms_feed_run_migration($migProbe, ['--null-plaintext'], $FEED_TEST_KEY);
ms_test_same('7p. --null-plaintext exits 0', 0, $nullReal['exit']);

$rowA = $migPdo->query("SELECT feed_token, feed_token_hash FROM pro_users WHERE feed_token_hash = '" . feedTokenHash($migTokenA) . "'")->fetch();
ms_test_same('7q. the plaintext is now NULL', null, $rowA['feed_token'] ?? null);
ms_test_same('7r. ... while the hash survives, so lookup still resolves the same row', feedTokenHash($migTokenA), $rowA['feed_token_hash'] ?? null);

// ---------------------------------------------------------------------
// 8. Repository scan: no SQL reads feed_token= any more (except the
//    migration and the check script)
// ---------------------------------------------------------------------

ms_test_section('8. Repository scan: no SQL reads feed_token = (except the migration/check scripts)');

$allowed = [
    'migrate_feed_token_encryption.php',
    'migrate_address_feed_tokens.php', // pre-#315 migration: adds/inspects the plaintext column, never looks a token up by it
    'check_address_feeds.php',
    'feed_token.php', // defines feedTokenHash()/feedTokenValidate(); no SQL of its own
];
$skipDirs = ['.git', 'vendor', 'node_modules', '.claude'];

$violations = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($msRepoRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($msRepoRoot) + 1);
    $skip = false;
    foreach ($skipDirs as $dir) {
        if (str_starts_with($relative, $dir . '/')) {
            $skip = true;
            break;
        }
    }
    if ($skip || in_array(basename($relative), $allowed, true)) {
        continue;
    }
    $src = file_get_contents($file->getPathname());
    if ($src === false) {
        continue;
    }
    if (preg_match_all('/feed_token\s*=\s*\?/i', $src, $matches, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }
    foreach ($matches[0] as [$matchText, $offset]) {
        $precedingStart = max(0, $offset - 20);
        $preceding = substr($src, $precedingStart, $offset - $precedingStart);
        // A write ("SET feed_token = ?") is fine; only a lookup ("WHERE"/"AND"
        // equality on feed_token) is what #315 retired.
        if (preg_match('/\bSET\s*$/i', $preceding) === 1) {
            continue;
        }
        $line = substr_count(substr($src, 0, $offset), "\n") + 1;
        $violations[] = "{$relative}:{$line}";
    }
}
ms_test_check(
    '8a. no other file looks a row up by feed_token =',
    $violations === [],
    'found in: ' . implode(', ', $violations)
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_feed_stop_server($probeServer);
ms_feed_stop_server($probeNoKeyServer);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($probe);
    ms_test_cleanup($probeNoKey);
    ms_test_rrmdir($migProbe);
} else {
    echo "Probe docroots left in place for inspection: {$probe}, {$probeNoKey}, {$migProbe}\n";
}
exit($exitCode);
