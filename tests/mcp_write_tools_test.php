<?php

declare(strict_types=1);

/**
 * Regression coverage for the MCP write tools (#323: create_sticky_address,
 * create_timed_address, delete_address, registered in mcp_tools.php).
 *
 * Run with:  php tests/mcp_write_tools_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network: it
 * reuses the throwaway-docroot harness in tests/lib/pushover_harness.php (the
 * same pattern tests/mcp_read_tools_test.php uses), copies mcp.php,
 * mcp_tools.php and the files their handlers need into that docroot and drives
 * the *real* endpoint through PHP's built-in web server — the only SAPI
 * mcp.php runs under (it refuses CLI) — against a SQLite database carrying the
 * token, address, message and cool-off tables.
 *
 * The stub config.php the harness writes is patched here so that the parts the
 * write tools actually depend on behave like the real ones: the mail and
 * attachment rows a delete removes, the attachment files it unlinks, and the
 * DirectAdmin forwarder calls, which are recorded (and can be made to fail) so
 * the rollback path is observable.
 *
 * What is checked is the tools' own behaviour through the transport: the scope
 * filter, every refusal the site makes (a reserved name, invalid syntax, the
 * cap of 10, another account's cool-off, an address that is not this
 * account's), the two "nothing is deleted by accident" gates (replace_existing
 * and confirm), that no mail, attachment row or file survives a deletion the
 * caller did ask for, the rollback that keeps the old Timed address when the
 * forwarder cannot be created, and that the creation rate limits are the same
 * buckets index.php uses.
 */

$msRepoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension (it brings its own SQLite database).\n");
    exit(1);
}

require __DIR__ . '/lib/pushover_harness.php';
require $msRepoRoot . '/mcp_tokens.php';
require $msRepoRoot . '/mcp_tools.php';

// ---------------------------------------------------------------------
// Extra schema + docroot helpers (kept local to this suite)
// ---------------------------------------------------------------------

/**
 * DDL on top of ms_test_schema(): the #320 token table, the two mail tables,
 * the cool-off table, the abuse guard's three tables (so the rate limits are
 * really enforced) and pro_users.suspended_at, which mcpTokenResolve() reads.
 */
function ms_mwt_extra_schema(): string
{
    return <<<'SQL'
ALTER TABLE pro_users ADD COLUMN suspended_at TEXT NULL;

CREATE TABLE mcp_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pro_user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL,
    token_prefix TEXT NOT NULL,
    scopes TEXT NOT NULL DEFAULT 'read',
    created_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    expires_at TEXT NULL,
    revoked_at TEXT NULL
);
CREATE UNIQUE INDEX uniq_mcp_access_tokens_hash ON mcp_access_tokens (token_hash);

CREATE TABLE stored_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    to_address TEXT NOT NULL,
    from_address TEXT NOT NULL DEFAULT '',
    subject TEXT NULL,
    body_text TEXT NULL,
    body_html TEXT NULL,
    received_at TEXT NULL,
    expires_at TEXT NULL,
    temp_email_id INTEGER NULL
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

CREATE TABLE address_cooldowns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    local_part TEXT NOT NULL UNIQUE,
    pro_user_id INTEGER NOT NULL,
    released_at TEXT NOT NULL,
    blocked_until TEXT NOT NULL
);

CREATE TABLE abuse_counters (
    scope TEXT NOT NULL,
    subject TEXT NOT NULL,
    window_start TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    bytes INTEGER NOT NULL DEFAULT 0,
    strikes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject, window_start)
);

CREATE TABLE address_quarantines (
    local_part TEXT PRIMARY KEY,
    temp_email_id INTEGER NOT NULL,
    pro_user_id INTEGER NULL,
    reason TEXT NOT NULL,
    quarantined_at TEXT NOT NULL,
    quarantined_until TEXT NULL,
    forwarder_removed INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE abuse_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    subject TEXT NULL,
    pro_user_id INTEGER NULL,
    detail TEXT NULL,
    notify INTEGER NOT NULL DEFAULT 0,
    notified_at TEXT NULL
);
SQL;
}

/**
 * Copy the endpoint, the registry and the files the write handlers load into
 * the docroot ms_test_probe_build() already built, create the attachments
 * directory, and patch the stub config.php: sanitizeLocalPart() (the stub has
 * none, and without it every create would answer "Invalid local part"),
 * proUserIsSuspended() (mcpTokenResolve() asks for it by name), and — the
 * point of this suite — a mail/attachment deletion and forwarder layer that
 * behaves like the deployed one instead of the stub's no-ops.
 */
function ms_mwt_prepare_probe(string $repoRoot, string $probe): void
{
    foreach (['mcp.php', 'mcp_tools.php', 'email_html_sanitizer.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }
    if (!is_dir($probe . '/attachments') && !mkdir($probe . '/attachments', 0700, true)) {
        throw new RuntimeException('Could not create the attachments directory');
    }

    $stub = ms_test_stub_config_php();

    $replacements = [
        // Mirrors config.php's deleteStoredEmailsForTempEmail(): collect the
        // attachment files, drop the rows, return the absolute paths.
        "function deleteStoredEmailsForTempEmail(PDO \$pdo, int \$tempEmailId): array { return []; }" =>
            "function deleteStoredEmailsForTempEmail(PDO \$pdo, int \$tempEmailId): array {\n"
            . "    \$stmt = \$pdo->prepare('SELECT ea.file_path FROM email_attachments ea JOIN stored_emails se ON se.id = ea.email_id WHERE se.temp_email_id = ?');\n"
            . "    \$stmt->execute([\$tempEmailId]);\n"
            . "    \$filePaths = \$stmt->fetchAll(PDO::FETCH_COLUMN);\n"
            . "    \$pdo->prepare('DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM stored_emails WHERE temp_email_id = ?)')->execute([\$tempEmailId]);\n"
            . "    \$pdo->prepare('DELETE FROM stored_emails WHERE temp_email_id = ?')->execute([\$tempEmailId]);\n"
            . "    \$paths = [];\n"
            . "    foreach (\$filePaths as \$filePath) { \$paths[] = __DIR__ . '/attachments/' . basename((string) \$filePath); }\n"
            . "    return \$paths;\n"
            . "}",
        // Unlinks for real, so a test can see the file disappear.
        "function unlinkAttachmentFiles(array \$paths): void {}" =>
            "function unlinkAttachmentFiles(array \$paths): void {\n"
            . "    foreach (\$paths as \$path) { if (is_file(\$path)) { @unlink(\$path); } }\n"
            . "}",
        // Recorded, so a test can assert the forwarder was removed.
        "function deleteDirectAdminForwarder(...\$args) { return true; }" =>
            "function deleteDirectAdminForwarder(...\$args) {\n"
            . "    file_put_contents(__DIR__ . '/deleted-forwarders.txt', (string) (\$args[0] ?? '') . \"\\n\", FILE_APPEND);\n"
            . "    return true;\n"
            . "}",
        // Fails while the sentinel file exists, so the rollback path can be
        // exercised (mailboxCreateTimed()/mailboxCreateSticky() must then undo
        // their insert).
        "function createDirectAdminForwarder(...\$args) { return true; }" =>
            "function createDirectAdminForwarder(...\$args) {\n"
            . "    return !is_file(__DIR__ . '/fail-forwarder');\n"
            . "}",
    ];

    foreach ($replacements as $search => $replace) {
        $stub = str_replace($search, $replace, $stub, $count);
        if ($count !== 1) {
            throw new RuntimeException('Could not patch the stub config.php: ' . substr($search, 0, 60));
        }
    }

    $extra = <<<'PHP'

// --- added by tests/mcp_write_tools_test.php -------------------------

/** Mirrors config.php's sanitizeLocalPart() (the stub has none). */
function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = sanitizeString($local, $maxLength, true);
    if ($s === null || strlen($s) < $minLength || !preg_match('/^[a-zA-Z0-9._-]+$/', $s)) return null;
    return strtolower($s);
}

/** Mirrors config.php's proUserIsSuspended(): suspended_at is set. */
function proUserIsSuspended(int $userId): bool {
    global $pdo;
    if ($userId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null;
    } catch (Exception $e) {
        return false;
    }
}
PHP;

    if (file_put_contents($probe . '/config.php', $stub . $extra) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }
}

/** PHP's built-in server (SAPI 'cli-server', which passes mcp.php's CLI guard). */
function ms_mwt_start_server(string $root): array
{
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL', '-S', "127.0.0.1:{$port}", '-t', $root],
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

function ms_mwt_stop_server(array $server): void
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

// ---------------------------------------------------------------------
// The HTTP client
// ---------------------------------------------------------------------

/** @return array{status:int, body:string} */
function ms_mwt_post(int $port, ?string $token, array $payload): array
{
    $headers = "Content-Type: application/json\r\n"
        . 'Origin: ' . MS_TEST_ORIGIN . "\r\n"
        . "Accept: application/json, text/event-stream\r\n";
    if ($token !== null) {
        $headers .= 'Authorization: Bearer ' . $token . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headers,
        'content' => (string) json_encode($payload),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . '/mcp.php', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

/** The decoded JSON body, with a failing check when there is none. */
function ms_mwt_json(string $label, array $response): ?array
{
    $decoded = json_decode($response['body'], true);
    ms_test_check(
        $label,
        is_array($decoded),
        'status ' . $response['status'] . ', body: ' . substr($response['body'], 0, 200)
    );
    return is_array($decoded) ? $decoded : null;
}

/** Call one tool and return its `result`, or null (with a failing check). */
function ms_mwt_call_tool(int $port, string $token, string $name, array $arguments = [], $id = 1): ?array
{
    $response = ms_mwt_post($port, $token, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);
    $body = ms_mwt_json("{$name}: the answer is JSON", $response);
    if ($body === null || !isset($body['result'])) {
        ms_test_check("{$name}: the answer carries a result", false, 'body: ' . substr($response['body'], 0, 200));
        return null;
    }
    return $body['result'];
}

/** A tool result's structuredContent, or null after a failing check. */
function ms_mwt_data(?array $result, string $label): ?array
{
    if ($result === null) {
        return null;
    }
    $data = $result['structuredContent'] ?? null;
    ms_test_check($label, is_array($data), 'no structuredContent in: ' . substr((string) json_encode($result), 0, 200));
    return is_array($data) ? $data : null;
}

/** The text of a tool error, for asserting the site's own wording. */
function ms_mwt_error_text(?array $result): string
{
    return (string) ($result['content'][0]['text'] ?? '');
}

/** Seed one access token row and return its plaintext. */
function ms_mwt_seed_token(PDO $pdo, int $userId, string $scopes = 'read,write'): string
{
    $token = mcpTokenGenerate();
    $pdo->prepare(
        'INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, 'test token', mcpTokenHash($token), mcpTokenPrefixOf($token), $scopes, date('Y-m-d H:i:s')]);
    return $token;
}

function ms_mwt_seed_address(PDO $pdo, string $local, int $userId, bool $personal, string $expiresAt): int
{
    $pdo->prepare(
        'INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at, created_at, feed_token, pushover_enabled)
         VALUES (?, ?, ?, ?, ?, NULL, 0)'
    )->execute([$local, $userId, $personal ? 1 : 0, $expiresAt, date('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
}

/** A stored message for one address, returned with the attachment it was given. */
function ms_mwt_seed_message_with_attachment(PDO $pdo, int $tempEmailId, string $toAddress, string $probe, string $fileName): int
{
    $pdo->prepare(
        'INSERT INTO stored_emails (to_address, from_address, subject, body_text, received_at, temp_email_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$toAddress, 'sender@example.com', 'Hello', 'body', date('Y-m-d H:i:s'), $tempEmailId]);
    $emailId = (int) $pdo->lastInsertId();

    file_put_contents($probe . '/attachments/' . $fileName, 'fixture bytes');
    $pdo->prepare(
        'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$emailId, $fileName, $fileName, 'application/pdf', 13, date('Y-m-d H:i:s')]);

    return $emailId;
}

function ms_mwt_address_row(PDO $pdo, string $local): ?array
{
    $stmt = $pdo->prepare('SELECT id, pro_user_id, is_personal, expires_at FROM temp_emails WHERE unique_address = ?');
    $stmt->execute([$local]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ms_mwt_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** The addresses whose forwarder the probe removed, oldest first. */
function ms_mwt_deleted_forwarders(string $probe): array
{
    $path = $probe . '/deleted-forwarders.txt';
    if (!is_file($path)) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($path))), static fn($l) => $l !== ''));
}

/** True when $value parses as ISO 8601 within a minute of $expectedTs. */
function ms_mwt_is_iso_near(?string $value, int $expectedTs, int $tolerance = 120): bool
{
    if (!is_string($value)) {
        return false;
    }
    $ts = strtotime($value);
    return $ts !== false && abs($ts - $expectedTs) <= $tolerance;
}

// ---------------------------------------------------------------------
// Docroot + database
// ---------------------------------------------------------------------

echo "Mail Shield — MCP write tools (#323)\n";

$probe = ms_test_probe_build($msRepoRoot);
echo "probe docroot: {$probe}\n";
ms_mwt_prepare_probe($msRepoRoot, $probe);
$server = ms_mwt_start_server($probe);
$port = $server[1];

$pdo = ms_test_db(ms_test_probe_sqlite($probe));
foreach (explode(';', ms_mwt_extra_schema()) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$domain = MS_TEST_EMAIL_DOMAIN;
$farFuture = date('Y-m-d H:i:s', strtotime('+50 years'));

$alice = ms_test_seed_user($pdo, 'alice@example.com', 'pro');
$bob = ms_test_seed_user($pdo, 'bob@example.com', 'pro');
$carol = ms_test_seed_user($pdo, 'carol@example.com', 'pro');
$dave = ms_test_seed_user($pdo, 'dave@example.com', 'pro');
$erin = ms_test_seed_user($pdo, 'erin@example.com', 'pro');
$frank = ms_test_seed_user($pdo, 'frank@example.com', 'pro');
$gina = ms_test_seed_user($pdo, 'gina@example.com', 'pro');

$tokenAlice = ms_mwt_seed_token($pdo, $alice);
$tokenAliceRead = ms_mwt_seed_token($pdo, $alice, 'read');
$tokenBob = ms_mwt_seed_token($pdo, $bob);
$tokenCarol = ms_mwt_seed_token($pdo, $carol);
$tokenDave = ms_mwt_seed_token($pdo, $dave);
$tokenErin = ms_mwt_seed_token($pdo, $erin);
$tokenGina = ms_mwt_seed_token($pdo, $gina);

ms_mwt_seed_address($pdo, 'alice.sticky', $alice, true, $farFuture);
ms_mwt_seed_address($pdo, 'bob.sticky', $bob, true, $farFuture);
ms_mwt_seed_address($pdo, 'bob.timed', $bob, false, date('Y-m-d H:i:s', strtotime('+24 hours')));
ms_mwt_seed_address($pdo, 'frank.sticky', $frank, true, $farFuture);
for ($i = 1; $i <= 10; $i++) {
    ms_mwt_seed_address($pdo, 'carol.cap' . $i, $carol, true, $farFuture);
}

// A cool-off reservation bob holds on an address he released.
$pdo->prepare('INSERT INTO address_cooldowns (local_part, pro_user_id, released_at, blocked_until) VALUES (?, ?, ?, ?)')
    ->execute(['released.addr', $bob, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+6 months'))]);

// dave's Timed address, with mail and an attachment file, for the replacement
// and rollback checks.
$daveTimedId = ms_mwt_seed_address($pdo, 'd4v3t1m3d', $dave, false, date('Y-m-d H:i:s', strtotime('+24 hours')));
ms_mwt_seed_message_with_attachment($pdo, $daveTimedId, 'd4v3t1m3d@' . $domain, $probe, 'dave_report.pdf');

// gina's Timed address, with mail and a file, for the plain deletion check.
$ginaTimedId = ms_mwt_seed_address($pdo, 'g1n4t1m3d', $gina, false, date('Y-m-d H:i:s', strtotime('+24 hours')));
ms_mwt_seed_message_with_attachment($pdo, $ginaTimedId, 'g1n4t1m3d@' . $domain, $probe, 'gina_report.pdf');

// ---------------------------------------------------------------------
// 1. Scope: a read-only token sees no write tool and cannot call one
// ---------------------------------------------------------------------

ms_test_section('1. The scope filter decides who may see and call the write tools');

$response = ms_mwt_post($port, $tokenAliceRead, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
$body = ms_mwt_json('1a. a read token gets a tool list', $response);
$listed = $body['result']['tools'] ?? [];
ms_test_same('1b. ... of the three read tools only', ['list_addresses', 'list_messages', 'get_message'], array_column($listed, 'name'));

$response = ms_mwt_post($port, $tokenAlice, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
$body = ms_mwt_json('1c. a read,write token gets a tool list', $response);
$listed = $body['result']['tools'] ?? [];
ms_test_same(
    '1d. ... with the three write tools',
    ['create_sticky_address', 'create_timed_address', 'delete_address'],
    array_slice(array_column($listed, 'name'), 3)
);
$annotations = [];
foreach ($listed as $tool) {
    $annotations[$tool['name']] = $tool['annotations'] ?? [];
}
ms_test_same('1e. create_sticky_address does not announce a deletion', false, $annotations['create_sticky_address']['destructiveHint'] ?? null);
ms_test_same('1f. create_timed_address does', true, $annotations['create_timed_address']['destructiveHint'] ?? null);
ms_test_same('1g. delete_address does', true, $annotations['delete_address']['destructiveHint'] ?? null);

// A client that kept a write tool's name from a wider-scoped token still
// cannot call it through a read-only one.
$response = ms_mwt_post($port, $tokenAliceRead, [
    'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
    'params' => ['name' => 'delete_address', 'arguments' => ['address' => 'alice.sticky', 'confirm' => true]],
]);
$body = ms_mwt_json('1h. a read token calling delete_address is answered', $response);
ms_test_same('1i. ... with Invalid params', -32602, $body['error']['code'] ?? null);
ms_test_same('1j. ... and the address is untouched', true, ms_mwt_address_row($pdo, 'alice.sticky') !== null);

// ---------------------------------------------------------------------
// 2. create_sticky_address — every rule the site applies
// ---------------------------------------------------------------------

ms_test_section('2. create_sticky_address applies the site\'s own rules');

$result = ms_mwt_call_tool($port, $tokenAlice, 'create_sticky_address', ['local_part' => 'Alice.Orders'], 4);
ms_test_same('2a. the call succeeds', false, $result['isError'] ?? null);
$data = ms_mwt_data($result, '2b. the answer carries structuredContent');
ms_test_same('2c. the address is the full address', 'alice.orders@' . $domain, $data['address'] ?? null);
ms_test_same('2d. the kind is sticky', 'sticky', $data['kind'] ?? null);
ms_test_check('2e. a Sticky address never expires', array_key_exists('expires_at', $data) && $data['expires_at'] === null, ms_test_dump($data['expires_at'] ?? 'missing'));
ms_test_check('2f. the row was created, lowercased', ms_mwt_address_row($pdo, 'alice.orders') !== null);

$result = ms_mwt_call_tool($port, $tokenAlice, 'create_sticky_address', ['local_part' => 'postmaster'], 5);
ms_test_same('2g. a reserved local part is refused', 'This local part is not allowed', ms_mwt_error_text($result));
ms_test_same('2h. ... and no row is created', null, ms_mwt_address_row($pdo, 'postmaster'));

$result = ms_mwt_call_tool($port, $tokenAlice, 'create_sticky_address', ['local_part' => '.leadingdot'], 6);
ms_test_same('2i. invalid syntax is refused', 'Invalid local part', ms_mwt_error_text($result));

$result = ms_mwt_call_tool($port, $tokenAlice, 'create_sticky_address', ['local_part' => 'bob.sticky'], 7);
ms_test_same('2j. an address someone else holds is refused', 'Address already taken', ms_mwt_error_text($result));

$result = ms_mwt_call_tool($port, $tokenAlice, 'create_sticky_address', ['local_part' => 'released.addr'], 8);
ms_test_same('2k. someone else\'s cool-off answers the same "taken"', 'Address already taken', ms_mwt_error_text($result));
ms_test_same('2l. ... and the reservation stands', $bob, ms_mwt_count($pdo, 'SELECT pro_user_id FROM address_cooldowns WHERE local_part = ?', ['released.addr']));

$result = ms_mwt_call_tool($port, $tokenBob, 'create_sticky_address', ['local_part' => 'released.addr'], 9);
ms_test_same('2m. the owner takes its own reservation back', false, $result['isError'] ?? null);
ms_test_check('2n. ... and the address is his', (int) (ms_mwt_address_row($pdo, 'released.addr')['pro_user_id'] ?? 0) === $bob);
ms_test_same('2o. ... and the reservation ended', 0, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM address_cooldowns WHERE local_part = ?', ['released.addr']));

$result = ms_mwt_call_tool($port, $tokenCarol, 'create_sticky_address', ['local_part' => 'carol.one-more'], 10);
ms_test_same('2p. the 11th sticky address is refused', 'Maximum of 10 sticky addresses allowed', ms_mwt_error_text($result));
ms_test_same('2q. ... and no row is created', null, ms_mwt_address_row($pdo, 'carol.one-more'));

$result = ms_mwt_call_tool($port, $tokenAlice, 'create_sticky_address', [], 11);
ms_test_same('2r. a missing local_part is refused', true, $result['isError'] ?? null);

// ---------------------------------------------------------------------
// 3. create_timed_address — one address, nothing replaced by accident
// ---------------------------------------------------------------------

ms_test_section('3. create_timed_address replaces only when told to');

$result = ms_mwt_call_tool($port, $tokenCarol, 'create_timed_address', [], 12);
ms_test_same('3a. an account without one gets a Timed address', false, $result['isError'] ?? null);
$data = ms_mwt_data($result, '3b. the answer carries structuredContent');
ms_test_same('3c. the kind is timed', 'timed', $data['kind'] ?? null);
ms_test_check('3d. expires_at is ISO 8601, about a day out', ms_mwt_is_iso_near($data['expires_at'] ?? null, time() + 86400, 300));
ms_test_check('3e. nothing was replaced', array_key_exists('replaced', $data) && $data['replaced'] === null, ms_test_dump($data['replaced'] ?? 'missing'));
$carolTimedLocal = (string) ($data['address'] ?? '');
$carolTimedLocal = substr($carolTimedLocal, 0, (int) strpos($carolTimedLocal, '@'));

$result = ms_mwt_call_tool($port, $tokenCarol, 'create_timed_address', [], 13);
ms_test_same('3f. a second call without replace_existing is refused', true, $result['isError'] ?? null);
ms_test_check('3g. ... naming the address that would go', str_contains(ms_mwt_error_text($result), $carolTimedLocal . '@' . $domain));
ms_test_check('3h. ... and saying its mail would be deleted', str_contains(ms_mwt_error_text($result), 'deletes it and all its'));
ms_test_check('3i. ... and the address is still there', ms_mwt_address_row($pdo, $carolTimedLocal) !== null);

$result = ms_mwt_call_tool($port, $tokenCarol, 'create_timed_address', ['replace_existing' => true], 14);
ms_test_same('3j. with replace_existing it succeeds', false, $result['isError'] ?? null);
$data = ms_mwt_data($result, '3k. the answer carries structuredContent');
ms_test_same('3l. ... and reports the address it replaced', $carolTimedLocal . '@' . $domain, $data['replaced'] ?? null);
ms_test_same('3m. ... which is gone', null, ms_mwt_address_row($pdo, $carolTimedLocal));

foreach ([['replace_existing' => 'yes'], ['replace_existing' => 1]] as $i => $badArgs) {
    $result = ms_mwt_call_tool($port, $tokenCarol, 'create_timed_address', $badArgs, 15 + $i);
    ms_test_same('3n.' . $i . '. a non-boolean replace_existing is refused', true, $result['isError'] ?? null);
}

// The replacement removes the old address' mail, attachment rows and files.
$result = ms_mwt_call_tool($port, $tokenDave, 'create_timed_address', ['replace_existing' => true], 17);
ms_test_same('3o. replacing dave\'s Timed address succeeds', false, $result['isError'] ?? null);
$data = ms_mwt_data($result, '3p. the answer carries structuredContent');
ms_test_same('3q. ... and names the address it replaced', 'd4v3t1m3d@' . $domain, $data['replaced'] ?? null);
ms_test_same('3r. the old address row is gone', null, ms_mwt_address_row($pdo, 'd4v3t1m3d'));
ms_test_same('3s. ... its mail is gone', 0, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM stored_emails WHERE to_address = ?', ['d4v3t1m3d@' . $domain]));
ms_test_same('3t. ... its attachment row is gone', 0, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM email_attachments WHERE file_path = ?', ['dave_report.pdf']));
ms_test_check('3u. ... its attachment file is gone', !is_file($probe . '/attachments/dave_report.pdf'));
ms_test_check('3v. ... and its forwarder was removed', in_array('d4v3t1m3d', ms_mwt_deleted_forwarders($probe), true));

// A failed forwarder rolls the replacement back and keeps the old address.
$daveTimedLocal = (string) ($data['address'] ?? '');
$daveTimedLocal = substr($daveTimedLocal, 0, (int) strpos($daveTimedLocal, '@'));
$daveTimedId2 = (int) (ms_mwt_address_row($pdo, $daveTimedLocal)['id'] ?? 0);
ms_mwt_seed_message_with_attachment($pdo, $daveTimedId2, $daveTimedLocal . '@' . $domain, $probe, 'dave_kept.pdf');
file_put_contents($probe . '/fail-forwarder', '1');

$result = ms_mwt_call_tool($port, $tokenDave, 'create_timed_address', ['replace_existing' => true], 18);

unlink($probe . '/fail-forwarder');
ms_test_same('3w. a failed forwarder is refused', true, $result['isError'] ?? null);
ms_test_check('3x. ... and the previous Timed address is kept', ms_mwt_address_row($pdo, $daveTimedLocal) !== null);
ms_test_same('3y. ... with its mail intact', 1, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM stored_emails WHERE to_address = ?', [$daveTimedLocal . '@' . $domain]));
ms_test_check('3z. ... and its attachment file intact', is_file($probe . '/attachments/dave_kept.pdf'));

// ---------------------------------------------------------------------
// 4. delete_address — confirm, both kinds, and the same "not found"
// ---------------------------------------------------------------------

ms_test_section('4. delete_address needs confirm and answers "not found" the same way');

$result = ms_mwt_call_tool($port, $tokenAlice, 'delete_address', ['address' => 'alice.sticky'], 19);
ms_test_same('4a. without confirm the call is refused', true, $result['isError'] ?? null);
ms_test_check('4b. ... naming the address that would go', str_contains(ms_mwt_error_text($result), 'alice.sticky@' . $domain));
ms_test_check('4c. ... and saying it cannot be undone', str_contains(ms_mwt_error_text($result), 'confirm: true'));
ms_test_check('4d. ... and the address is untouched', ms_mwt_address_row($pdo, 'alice.sticky') !== null);

$result = ms_mwt_call_tool($port, $tokenAlice, 'delete_address', ['address' => 'alice.sticky@' . $domain, 'confirm' => false], 20);
ms_test_same('4e. confirm: false is refused too', true, $result['isError'] ?? null);

$result = ms_mwt_call_tool($port, $tokenAlice, 'delete_address', ['address' => 'alice.sticky', 'confirm' => true], 21);
ms_test_same('4f. with confirm the Sticky address is deleted', false, $result['isError'] ?? null);
$data = ms_mwt_data($result, '4g. the answer carries structuredContent');
ms_test_same('4h. the kind is sticky', 'sticky', $data['kind'] ?? null);
ms_test_same('4i. the address is named back', 'alice.sticky@' . $domain, $data['address'] ?? null);
ms_test_check('4j. it says until when it stays reserved', ms_mwt_is_iso_near($data['reserved_until'] ?? null, strtotime('+6 months'), 90000));
ms_test_same('4k. the row is gone', null, ms_mwt_address_row($pdo, 'alice.sticky'));
ms_test_same('4l. ... and reserved for its owner', $alice, ms_mwt_count($pdo, 'SELECT pro_user_id FROM address_cooldowns WHERE local_part = ?', ['alice.sticky']));

// A Timed address is deleted the same way, but is not reserved.
$result = ms_mwt_call_tool($port, $tokenGina, 'delete_address', ['address' => 'g1n4t1m3d', 'confirm' => true], 22);
ms_test_same('4m. a Timed address is deleted', false, $result['isError'] ?? null);
$data = ms_mwt_data($result, '4n. the answer carries structuredContent');
ms_test_same('4o. the kind is timed', 'timed', $data['kind'] ?? null);
ms_test_check('4p. a Timed address is not reserved', array_key_exists('reserved_until', $data) && $data['reserved_until'] === null, ms_test_dump($data['reserved_until'] ?? 'missing'));
ms_test_same('4q. the row is gone', null, ms_mwt_address_row($pdo, 'g1n4t1m3d'));
ms_test_same('4r. ... its mail is gone', 0, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM stored_emails WHERE to_address = ?', ['g1n4t1m3d@' . $domain]));
ms_test_same('4s. ... its attachment row is gone', 0, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM email_attachments WHERE file_path = ?', ['gina_report.pdf']));
ms_test_check('4t. ... its attachment file is gone', !is_file($probe . '/attachments/gina_report.pdf'));
ms_test_check('4u. ... its forwarder was removed', in_array('g1n4t1m3d', ms_mwt_deleted_forwarders($probe), true));
ms_test_same('4v. ... and no cool-off row was written for it', 0, ms_mwt_count($pdo, 'SELECT COUNT(*) FROM address_cooldowns WHERE local_part = ?', ['g1n4t1m3d']));

// Every address that is not this account's answers exactly the same.
foreach ([
    'another account\'s Sticky address' => 'frank.sticky',
    'another account\'s Timed address' => 'bob.timed',
    'an address that does not exist' => 'nosuchaddress',
    'an address on another domain' => 'frank.sticky@evil.example',
] as $label => $address) {
    $result = ms_mwt_call_tool($port, $tokenAlice, 'delete_address', ['address' => $address, 'confirm' => true], 23);
    ms_test_same("4w.{$label}: an ordinary tool error", true, $result['isError'] ?? null);
    ms_test_same("4x.{$label}: ... saying exactly \"Address not found\"", 'Address not found', ms_mwt_error_text($result));
}
ms_test_check('4y. the other account\'s addresses survive', ms_mwt_address_row($pdo, 'frank.sticky') !== null && ms_mwt_address_row($pdo, 'bob.timed') !== null);

// ---------------------------------------------------------------------
// 5. The creation rate limits are the buckets index.php uses
// ---------------------------------------------------------------------

ms_test_section('5. create_timed_address shares the gen_user bucket with index.php');

$refusedAt = 0;
for ($i = 1; $i <= 31; $i++) {
    $result = ms_mwt_call_tool($port, $tokenErin, 'create_timed_address', ['replace_existing' => true], 30 + $i);
    if (($result['isError'] ?? null) === true) {
        $refusedAt = $i;
        break;
    }
}
ms_test_same('5a. the 31st Timed creation in a day is refused', 31, $refusedAt);
ms_test_check('5b. ... with the site\'s own message', str_contains(ms_mwt_error_text($result), 'Too many new addresses in a short time'));

$res = ms_test_request($probe, [
    'page' => 'index.php',
    'method' => 'POST',
    'user_id' => $erin,
    'user_email' => 'erin@example.com',
    'post' => ['action' => 'generate'],
]);
ms_test_no_php_errors('5c. index.php generate raises no PHP error', $res);
$json = ms_test_json('5d. index.php generate answers JSON', $res);
ms_test_same('5e. ... and is refused: the tool and the page share one bucket', [false, true], [$json['success'] ?? null, $json['rate_limited'] ?? null]);

// A fresh account is not affected by another account's spending.
$result = ms_mwt_call_tool($port, $tokenAlice, 'create_timed_address', ['replace_existing' => true], 70);
ms_test_same('5f. another account is still allowed', false, $result['isError'] ?? null);

// ---------------------------------------------------------------------
// 6. Nothing the endpoint did tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('6. The endpoint answers without a PHP warning, notice or fatal');

$noise = [];
foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
    if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
        $noise[] = trim($line);
    }
}
ms_test_check('6a. no PHP warning, notice or fatal', $noise === [], implode(' | ', array_slice($noise, 0, 3)));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_mwt_stop_server($server);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($probe);
} else {
    echo "Probe docroot left in place for inspection: {$probe}\n";
}
exit($exitCode);
