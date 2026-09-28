<?php

declare(strict_types=1);

/**
 * Regression coverage for the MCP read tools (#322: list_addresses,
 * list_messages, get_message, registered in mcp_tools.php).
 *
 * Run with:  php tests/mcp_read_tools_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network: it
 * reuses the throwaway-docroot harness in tests/lib/pushover_harness.php (the
 * same pattern tests/mcp_endpoint_test.php uses), copies mcp.php, mcp_tools.php
 * and the files their handlers need into that docroot and drives the *real*
 * endpoint through PHP's built-in web server — the only SAPI mcp.php runs
 * under (it refuses CLI) — against a SQLite database carrying the token,
 * address and message tables.
 *
 * What is checked is the tools' own behaviour through the transport: what an
 * owner sees, what another account or a missing id sees (always the same
 * answer, so ids and local parts reveal nothing), the Timed-address ownership
 * gap #319 left for the MCP surface, expiry, HTML-only bodies flattened to
 * text, the 20 000-character body cut, signed attachment links, the scope
 * filter for a read-only token, and the limit bounds.
 *
 * The hand check the issue asks for — "what did my newest mail say?" from
 * Claude Code against a local server — is a manual step against a real client;
 * it cannot live in this suite.
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
 * DDL on top of ms_test_schema(): the #320 token table, the two mail tables
 * (translated from the real schema, as tests/lib/email_storage_harness.php
 * does) and pro_users.suspended_at, which mcpTokenResolve()'s suspension
 * check reads.
 */
function ms_mrt_extra_schema(): string
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
SQL;
}

/**
 * Copy the endpoint, the registry and the files the read handlers load into a
 * docroot ms_test_probe_build() already built, and patch its stub config.php:
 * a real-shaped generateSignedAttachmentUrl() (the stub returns ''), the
 * attachment TTL the tool reports, and proUserIsSuspended() — mirroring
 * config.php's own body, since mcpTokenResolve() asks for it by name and would
 * otherwise skip the suspension check entirely.
 */
function ms_mrt_prepare_probe(string $repoRoot, string $probe): void
{
    // email_html_sanitizer.php is not part of ms_test_probe_build()'s set, and
    // mailbox_service.php requires it whenever it shapes a message.
    foreach (['mcp.php', 'mcp_tools.php', 'email_html_sanitizer.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    $stub = ms_test_stub_config_php();
    $stub = str_replace(
        "function generateSignedAttachmentUrl(...\$args) { return ''; }",
        "function generateSignedAttachmentUrl(int \$attachmentId, ?int \$ttlSeconds = null, ?int \$baseTime = null): string {\n"
        . "    \$ttl = \$ttlSeconds ?? 3600;\n"
        . "    \$expires = (\$baseTime ?? time()) + \$ttl;\n"
        . "    return 'http://localhost:8085/files.php?id=' . \$attachmentId . '&expires=' . \$expires . '&sig=fixture';\n"
        . "}",
        $stub,
        $replaced
    );
    if ($replaced !== 1) {
        throw new RuntimeException('Could not replace the stub generateSignedAttachmentUrl()');
    }

    $extra = <<<'PHP'

// --- added by tests/mcp_read_tools_test.php -------------------------
$config['attachments']['download_ttl'] = 3600;

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
function ms_mrt_start_server(string $root): array
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

function ms_mrt_stop_server(array $server): void
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
function ms_mrt_post(int $port, ?string $token, array $payload): array
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
function ms_mrt_json(string $label, array $response): ?array
{
    $decoded = json_decode($response['body'], true);
    ms_test_check(
        $label,
        is_array($decoded),
        'status ' . $response['status'] . ', body: ' . substr($response['body'], 0, 200)
    );
    return is_array($decoded) ? $decoded : null;
}

/**
 * Call one tool and return its `result`, or null (with a failing check) when
 * the answer was not a JSON-RPC result.
 */
function ms_mrt_call_tool(int $port, string $token, string $name, array $arguments = [], $id = 1): ?array
{
    $response = ms_mrt_post($port, $token, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);
    $body = ms_mrt_json("{$name}: the answer is JSON", $response);
    if ($body === null || !isset($body['result'])) {
        ms_test_check("{$name}: the answer carries a result", false, 'body: ' . substr($response['body'], 0, 200));
        return null;
    }
    return $body['result'];
}

/** The tool's structuredContent, or null after a failing check. */
function ms_mrt_data(?array $result, string $label): ?array
{
    if ($result === null) {
        return null;
    }
    $data = $result['structuredContent'] ?? null;
    ms_test_check($label, is_array($data), 'no structuredContent in: ' . substr((string) json_encode($result), 0, 200));
    return is_array($data) ? $data : null;
}

/** Seed one access token row and return its plaintext. */
function ms_mrt_seed_token(PDO $pdo, int $userId, string $scopes = 'read'): string
{
    $token = mcpTokenGenerate();
    $pdo->prepare(
        'INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, 'test token', mcpTokenHash($token), mcpTokenPrefixOf($token), $scopes, date('Y-m-d H:i:s')]);
    return $token;
}

function ms_mrt_seed_address(PDO $pdo, string $local, int $userId, bool $personal, string $expiresAt): void
{
    $pdo->prepare(
        'INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at, created_at, feed_token, pushover_enabled)
         VALUES (?, ?, ?, ?, ?, NULL, 0)'
    )->execute([$local, $userId, $personal ? 1 : 0, $expiresAt, date('Y-m-d H:i:s')]);
}

/** @param array{from?:string, subject?:string, body_text?:?string, body_html?:?string, received_at?:string, expires_at?:?string} $overrides */
function ms_mrt_seed_message(PDO $pdo, string $toAddress, array $overrides = []): int
{
    $pdo->prepare(
        'INSERT INTO stored_emails (to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
    )->execute([
        $toAddress,
        (string) ($overrides['from'] ?? 'sender@example.com'),
        $overrides['subject'] ?? 'Hello',
        $overrides['body_text'] ?? null,
        $overrides['body_html'] ?? null,
        (string) ($overrides['received_at'] ?? date('Y-m-d H:i:s')),
        $overrides['expires_at'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** True when $value parses as ISO 8601 within a minute of $expectedTs. */
function ms_mrt_is_iso_near(?string $value, int $expectedTs, int $tolerance = 120): bool
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

echo "Mail Shield — MCP read tools (#322)\n";

$probe = ms_test_probe_build($msRepoRoot);
echo "probe docroot: {$probe}\n";
ms_mrt_prepare_probe($msRepoRoot, $probe);
$server = ms_mrt_start_server($probe);
$port = $server[1];

$pdo = ms_test_db(ms_test_probe_sqlite($probe));
foreach (explode(';', ms_mrt_extra_schema()) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$domain = MS_TEST_EMAIL_DOMAIN;
$alice = ms_test_seed_user($pdo, 'alice@example.com', 'pro');
$bob = ms_test_seed_user($pdo, 'bob@example.com', 'pro');
$tokenAlice = ms_mrt_seed_token($pdo, $alice, 'read');
$tokenBob = ms_mrt_seed_token($pdo, $bob, 'read');
$tokenAliceWrite = ms_mrt_seed_token($pdo, $alice, 'read,write');

ms_mrt_seed_address($pdo, 'alice.mail', $alice, true, date('Y-m-d H:i:s', strtotime('+50 years')));
ms_mrt_seed_address($pdo, 'a1b2c3d4', $alice, false, date('Y-m-d H:i:s', strtotime('+24 hours')));
ms_mrt_seed_address($pdo, 'bob.mail', $bob, true, date('Y-m-d H:i:s', strtotime('+50 years')));

$aliceAddress = 'alice.mail@' . $domain;
$timedAddress = 'a1b2c3d4@' . $domain;
$bobAddress = 'bob.mail@' . $domain;

$now = time();
$mailFirst = ms_mrt_seed_message($pdo, $aliceAddress, [
    'from' => 'news@example.com', 'subject' => 'First', 'body_text' => 'Hello there',
    'received_at' => date('Y-m-d H:i:s', $now - 300),
]);
$mailHtml = ms_mrt_seed_message($pdo, $aliceAddress, [
    'from' => 'html@example.com', 'subject' => 'Second', 'body_html' => '<p>HTML <b>only</b></p>',
    'received_at' => date('Y-m-d H:i:s', $now - 200),
]);
$mailLong = ms_mrt_seed_message($pdo, $aliceAddress, [
    'from' => 'long@example.com', 'subject' => 'Third', 'body_text' => str_repeat('x', 25000),
    'received_at' => date('Y-m-d H:i:s', $now - 100),
]);
$mailExpired = ms_mrt_seed_message($pdo, $aliceAddress, [
    'from' => 'old@example.com', 'subject' => 'Expired', 'body_text' => 'gone',
    'received_at' => date('Y-m-d H:i:s', $now - 7200),
    'expires_at' => date('Y-m-d H:i:s', $now - 3600),
]);
$mailTimed = ms_mrt_seed_message($pdo, $timedAddress, [
    'from' => 'timed@example.com', 'subject' => 'Timed mail', 'body_text' => 'timed body',
    'received_at' => date('Y-m-d H:i:s', $now - 50),
]);
$mailBob = ms_mrt_seed_message($pdo, $bobAddress, [
    'from' => 'bob@example.com', 'subject' => 'Bob only', 'body_text' => 'secret',
    'received_at' => date('Y-m-d H:i:s', $now - 10),
]);

$pdo->prepare('INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$mailFirst, 'report.pdf', '1_ab_report.pdf', 'application/pdf', 2048, date('Y-m-d H:i:s')]);

// ---------------------------------------------------------------------
// 1. list_addresses — the owner's own two kinds
// ---------------------------------------------------------------------

ms_test_section('1. list_addresses returns the account\'s Sticky and Timed addresses');

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_addresses');
ms_test_same('1a. the call succeeds', false, $result['isError'] ?? null);
$data = ms_mrt_data($result, '1b. the answer carries structuredContent');
ms_test_same('1c. the text item states the same thing as structuredContent', json_encode($data), $result['content'][0]['text'] ?? null);
ms_test_same('1d. two addresses (one Sticky, one Timed)', 2, count($data['addresses'] ?? []));

$byKind = [];
foreach ((array) ($data['addresses'] ?? []) as $address) {
    $byKind[$address['kind'] ?? '?'] = $address;
}
ms_test_same('1e. the Sticky address is the full address', $aliceAddress, $byKind['sticky']['address'] ?? null);
ms_test_check(
    '1f. a Sticky address never expires',
    array_key_exists('expires_at', $byKind['sticky'] ?? []) && $byKind['sticky']['expires_at'] === null,
    'got: ' . ms_test_dump($byKind['sticky']['expires_at'] ?? 'missing')
);
ms_test_check(
    '1g. the Sticky address is not paused',
    array_key_exists('paused_until', $byKind['sticky'] ?? []) && $byKind['sticky']['paused_until'] === null,
    'got: ' . ms_test_dump($byKind['sticky']['paused_until'] ?? 'missing')
);
ms_test_same('1h. the Sticky address is not closed', false, $byKind['sticky']['closed'] ?? 'missing');
ms_test_same('1i. the Timed address is the full address', $timedAddress, $byKind['timed']['address'] ?? null);
ms_test_check('1j. the Timed address expires in ISO 8601', ms_mrt_is_iso_near($byKind['timed']['expires_at'] ?? null, $now + 86400));
ms_test_same('1k. the Sticky count is reported', 1, $data['sticky_count'] ?? null);
ms_test_same('1l. ... with the cap of 10', 10, $data['sticky_limit'] ?? null);

// ---------------------------------------------------------------------
// 2. list_addresses — the kind filter
// ---------------------------------------------------------------------

ms_test_section('2. list_addresses honours the kind filter and refuses anything else');

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_addresses', ['kind' => 'sticky'], 2);
$data = ms_mrt_data($result, '2a. kind=sticky answers structuredContent');
ms_test_same('2b. ... with only the Sticky address', ['sticky'], array_column($data['addresses'] ?? [], 'kind'));
ms_test_same('2c. ... and the full Sticky count', 1, $data['sticky_count'] ?? null);

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_addresses', ['kind' => 'timed'], 3);
$data = ms_mrt_data($result, '2d. kind=timed answers structuredContent');
ms_test_same('2e. ... with only the Timed address', ['timed'], array_column($data['addresses'] ?? [], 'kind'));

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_addresses', ['kind' => 'everything'], 4);
ms_test_same('2f. an unknown kind is an ordinary tool error', true, $result['isError'] ?? null);
ms_test_check('2g. ... naming the allowed values', str_contains((string) ($result['content'][0]['text'] ?? ''), 'sticky'));

// ---------------------------------------------------------------------
// 3. list_messages — the owner's mail
// ---------------------------------------------------------------------

ms_test_section('3. list_messages returns the address\' unexpired mail, newest first');

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => 'alice.mail'], 5);
ms_test_same('3a. the call succeeds', false, $result['isError'] ?? null);
$data = ms_mrt_data($result, '3b. the answer carries structuredContent');
ms_test_same('3c. the full address is named back', $aliceAddress, $data['address'] ?? null);
ms_test_same('3d. the expired message is left out', [$mailLong, $mailHtml, $mailFirst], array_column($data['messages'] ?? [], 'id'));

$messages = [];
foreach ((array) ($data['messages'] ?? []) as $message) {
    $messages[$message['id']] = $message;
}
ms_test_same('3e. the sender is reported', 'news@example.com', $messages[$mailFirst]['from'] ?? null);
ms_test_same('3f. the subject is reported', 'First', $messages[$mailFirst]['subject'] ?? null);
ms_test_check('3g. received_at is ISO 8601', ms_mrt_is_iso_near($messages[$mailFirst]['received_at'] ?? null, $now - 300));
ms_test_same('3h. the preview is the plain text', 'Hello there', $messages[$mailFirst]['preview'] ?? null);
ms_test_same('3i. ... and the message with an attachment counts it', 1, $messages[$mailFirst]['attachment_count'] ?? null);
ms_test_same('3j. a message without attachments counts zero', 0, $messages[$mailHtml]['attachment_count'] ?? null);
ms_test_check(
    '3k. a preview is at most 200 characters',
    mb_strlen((string) ($messages[$mailLong]['preview'] ?? '')) === 200,
    'length: ' . mb_strlen((string) ($messages[$mailLong]['preview'] ?? ''))
);

// The full address is accepted as well as the local part.
$result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => $aliceAddress], 6);
$data = ms_mrt_data($result, '3l. the full address is accepted');
ms_test_same('3m. ... and returns the same three messages', [$mailLong, $mailHtml, $mailFirst], array_column($data['messages'] ?? [], 'id'));

// ---------------------------------------------------------------------
// 4. list_messages — another account, a missing address, another domain
// ---------------------------------------------------------------------

ms_test_section('4. Another account, a missing address and another domain answer the same thing');

foreach ([
    'another account\'s address' => 'bob.mail',
    'an address that does not exist' => 'nosuchaddress',
    'an address on another domain' => 'alice.mail@evil.example',
] as $label => $address) {
    $result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => $address], 7);
    ms_test_same("4a.{$label}: an ordinary tool error", true, $result['isError'] ?? null);
    ms_test_same(
        "4b.{$label}: ... saying exactly \"Address not found\"",
        'Address not found',
        $result['content'][0]['text'] ?? null
    );
}

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => 'not valid!!'], 8);
ms_test_same('4c. invalid syntax is refused before any lookup', 'Invalid address', $result['content'][0]['text'] ?? null);

// ---------------------------------------------------------------------
// 5. The Timed address' mail (the ownership gap #319 left for MCP)
// ---------------------------------------------------------------------

ms_test_section('5. A Timed address\' mail belongs to its account too');

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => 'a1b2c3d4'], 9);
ms_test_same('5a. the owning account reads its Timed address', false, $result['isError'] ?? null);
$data = ms_mrt_data($result, '5b. ... with structuredContent');
ms_test_same('5c. ... and sees its one message', [$mailTimed], array_column($data['messages'] ?? [], 'id'));

$result = ms_mrt_call_tool($port, $tokenBob, 'list_messages', ['address' => 'a1b2c3d4'], 10);
ms_test_same('5d. another account is refused the same Timed address', 'Address not found', $result['content'][0]['text'] ?? null);

$result = ms_mrt_call_tool($port, $tokenBob, 'get_message', ['message_id' => $mailTimed], 11);
ms_test_same('5e. ... and cannot read its message either', true, $result['isError'] ?? null);

// ---------------------------------------------------------------------
// 6. list_messages — the limit bounds
// ---------------------------------------------------------------------

ms_test_section('6. limit is bounded to 1-50, defaulting to 20');

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => 'alice.mail', 'limit' => 1], 12);
$data = ms_mrt_data($result, '6a. limit=1 answers structuredContent');
ms_test_same('6b. ... with the single newest message', [$mailLong], array_column($data['messages'] ?? [], 'id'));

$result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => 'alice.mail', 'limit' => 50], 13);
$data = ms_mrt_data($result, '6c. limit=50 is allowed');
ms_test_same('6d. ... and returns all three', 3, count($data['messages'] ?? []));

foreach (['0' => 0, '51' => 51, 'a string' => 'ten'] as $label => $limit) {
    $result = ms_mrt_call_tool($port, $tokenAlice, 'list_messages', ['address' => 'alice.mail', 'limit' => $limit], 14);
    ms_test_same("6e. limit={$label} is refused", true, $result['isError'] ?? null);
}

// ---------------------------------------------------------------------
// 7. get_message — the owner's message, as plain text
// ---------------------------------------------------------------------

ms_test_section('7. get_message returns one message as plain text with its attachments');

$result = ms_mrt_call_tool($port, $tokenAlice, 'get_message', ['message_id' => $mailFirst], 15);
ms_test_same('7a. the call succeeds', false, $result['isError'] ?? null);
$data = ms_mrt_data($result, '7b. the answer carries structuredContent');
ms_test_same('7c. the sender is reported', 'news@example.com', $data['from'] ?? null);
ms_test_same('7d. the recipient is reported', $aliceAddress, $data['to'] ?? null);
ms_test_same('7e. the subject is reported', 'First', $data['subject'] ?? null);
ms_test_check('7f. received_at is ISO 8601', ms_mrt_is_iso_near($data['received_at'] ?? null, $now - 300));
ms_test_same('7g. the body is the text part', 'Hello there', $data['body'] ?? null);
ms_test_same('7h. nothing was cut', false, $data['truncated'] ?? null);
ms_test_check('7i. no HTML body is returned', !array_key_exists('body_html', $data));
ms_test_check('7j. no raw headers are returned', !array_key_exists('headers', $data));

$attachment = $data['attachments'][0] ?? [];
ms_test_same('7k. the attachment is listed', 1, count($data['attachments'] ?? []));
ms_test_same('7l. ... with its filename', 'report.pdf', $attachment['filename'] ?? null);
ms_test_same('7m. ... its content type', 'application/pdf', $attachment['content_type'] ?? null);
ms_test_same('7n. ... its size', 2048, $attachment['size'] ?? null);
ms_test_check(
    '7o. ... and a signed download URL for its id',
    str_contains((string) ($attachment['download_url'] ?? ''), 'id=' . ($pdo->query('SELECT id FROM email_attachments LIMIT 1')->fetchColumn() ?: ''))
);
ms_test_check(
    '7p. ... that says when it stops working',
    ms_mrt_is_iso_near($attachment['download_expires_at'] ?? null, $now + 3600)
);

// ---------------------------------------------------------------------
// 8. get_message — every failure answers the same
// ---------------------------------------------------------------------

ms_test_section('8. Another account\'s message, a missing id and an expired one all answer "Message not found"');

foreach ([
    'another account\'s message' => $mailBob,
    'a message id that does not exist' => 999999,
    'an expired message' => $mailExpired,
    'id 0' => 0,
    'a value that is not an id' => 'abc',
] as $label => $messageId) {
    $result = ms_mrt_call_tool($port, $tokenAlice, 'get_message', ['message_id' => $messageId], 16);
    ms_test_same("8a.{$label}: an ordinary tool error", true, $result['isError'] ?? null);
    ms_test_same(
        "8b.{$label}: ... saying exactly \"Message not found\"",
        'Message not found',
        $result['content'][0]['text'] ?? null
    );
}

// ---------------------------------------------------------------------
// 9. An HTML-only message comes back as plain text
// ---------------------------------------------------------------------

ms_test_section('9. An HTML-only body is flattened to plain text');

$result = ms_mrt_call_tool($port, $tokenAlice, 'get_message', ['message_id' => $mailHtml], 17);
$data = ms_mrt_data($result, '9a. the call answers structuredContent');
ms_test_same('9b. the body is the flattened text', 'HTML only', $data['body'] ?? null);
ms_test_check(
    '9c. no tag from the message reaches the answer',
    !str_contains((string) ($data['body'] ?? ''), '<') && !str_contains((string) ($data['body'] ?? ''), '>')
);
ms_test_check(
    '9d. the raw HTML appears nowhere in the response',
    !str_contains((string) json_encode($result), '<p>HTML') && !str_contains((string) json_encode($result), '<b>')
);

// ---------------------------------------------------------------------
// 10. A body over 20 000 characters is cut and flagged
// ---------------------------------------------------------------------

ms_test_section('10. A body over 20 000 characters is cut at 20 000 and flagged');

$result = ms_mrt_call_tool($port, $tokenAlice, 'get_message', ['message_id' => $mailLong], 18);
$data = ms_mrt_data($result, '10a. the call answers structuredContent');
ms_test_same('10b. truncated is true', true, $data['truncated'] ?? null);
ms_test_same('10c. the body is exactly 20 000 characters', 20000, mb_strlen((string) ($data['body'] ?? '')));

// ---------------------------------------------------------------------
// 11. Scope: a read-only token never sees a write tool
// ---------------------------------------------------------------------

ms_test_section('11. A read-only token sees the read tools and no write tool');

// #323 has not landed yet, so the write tool is a stand-in: the registry as
// shipped plus one, filtered the same way the endpoint filters it.
$withWrite = array_merge(mcpToolRegistry(), [[
    'name' => 'create_address',
    'scope' => 'write',
    'description' => 'a stand-in for #323',
    'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
    'handler' => 'strlen',
]]);
ms_test_same(
    '11a. a read token\'s list holds no write tool',
    ['list_addresses', 'list_messages', 'get_message'],
    array_column(mcpToolsForScopes($withWrite, 'read'), 'name')
);
ms_test_check(
    '11b. ... while a read,write token is offered it',
    in_array('create_address', array_column(mcpToolsForScopes($withWrite, 'read,write'), 'name'), true)
);

$response = ms_mrt_post($port, $tokenAlice, ['jsonrpc' => '2.0', 'id' => 19, 'method' => 'tools/list', 'params' => []]);
$body = ms_mrt_json('11c. the read token gets a tool list', $response);
$listed = $body['result']['tools'] ?? [];
ms_test_same('11d. ... of the three read tools', ['list_addresses', 'list_messages', 'get_message'], array_column($listed, 'name'));
ms_test_check(
    '11e. ... each marked read-only',
    $listed !== [] && array_reduce($listed, static fn($ok, $tool) => $ok && (($tool['annotations']['readOnlyHint'] ?? null) === true), true)
);
ms_test_check(
    '11f. ... and the two that carry message content call it untrusted',
    array_reduce($listed, static function ($ok, $tool) {
        if (!in_array($tool['name'] ?? '', ['list_messages', 'get_message'], true)) {
            return $ok;
        }
        $description = strtolower((string) ($tool['description'] ?? ''));
        return $ok && (str_contains($description, 'untrusted') && str_contains($description, 'instructions'));
    }, true)
);

// A client that kept a write tool name from a wider-scoped token still cannot
// call it through a read-only one.
$response = ms_mrt_post($port, $tokenAliceWrite, ['jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/call', 'params' => ['name' => 'create_address', 'arguments' => []]]);
$body = ms_mrt_json('11g. a write-only name is known but not offered yet', $response);
ms_test_check(
    '11h. ... so calling an unregistered tool is Invalid params',
    ($body['error']['code'] ?? null) === -32602 || ($body['result']['isError'] ?? null) === true
);

// ---------------------------------------------------------------------
// 12. Nothing the endpoint did tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('12. The endpoint answers without a PHP warning, notice or fatal');

$noise = [];
foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
    if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
        $noise[] = trim($line);
    }
}
ms_test_check('12a. no PHP warning, notice or fatal', $noise === [], implode(' | ', array_slice($noise, 0, 3)));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_mrt_stop_server($server);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($probe);
} else {
    echo "Probe docroot left in place for inspection: {$probe}\n";
}
exit($exitCode);
