<?php

declare(strict_types=1);

/**
 * Regression coverage for the MCP endpoint (#321, mcp.php, mcp_tools.php).
 *
 * Run with:  php tests/mcp_endpoint_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network are
 * needed: it reuses the throwaway-docroot harness in
 * tests/lib/pushover_harness.php (the same pattern tests/webhook_routing_test.php
 * and tests/feed_token_test.php use), copies mcp.php and mcp_tools.php into
 * that docroot and drives the *real* endpoint through PHP's built-in web
 * server — the only SAPI mcp.php runs under (it refuses CLI) — against a
 * SQLite database it lays the MCP and abuse-guard tables onto itself. The
 * harness file is not modified.
 *
 * What is checked is the endpoint's own behaviour, not a copy of its
 * decisions: the transport checks in the order the issue lists them (method,
 * Content-Type, protocol version, Origin, Bearer token, Pro entitlement, rate
 * limit, body size, JSON, dispatch), the JSON-RPC shapes it answers with, and
 * the one thing that must never happen — a credential or a body reaching the
 * log.
 *
 * The hand check the issue asks for, `claude mcp add --transport http
 * mailshield <base_url>/mcp --header "Authorization: Bearer msk_…"` followed
 * by listing the tools, is a manual step against a real client; it cannot live
 * in this suite.
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
require $msRepoRoot . '/mcp_tokens.php';
require $msRepoRoot . '/mcp_tools.php';

// ---------------------------------------------------------------------
// Extra schema + docroot helpers (kept local to this suite)
// ---------------------------------------------------------------------

/**
 * DDL on top of ms_test_schema(): the #320 token table (as
 * migrate_mcp_tokens.php creates it), the abuse guard's three tables (so
 * abuseGuardAvailable() is true and the per-token rate limit is live), and
 * pro_users.suspended_at (mirroring migrate_abuse_guard.php).
 */
function ms_mcp_extra_schema(): string
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
    local_part TEXT NOT NULL PRIMARY KEY,
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
 * Copies the endpoint and its registry into a docroot ms_test_probe_build()
 * already built, and appends to its stub config.php the two things the stub
 * does not carry: the rate limits this suite wants (so the limit can be
 * reached in a handful of requests instead of 121), and proUserIsSuspended()
 * — mirroring config.php's own body, since mcpTokenResolve() asks for it by
 * name and would otherwise skip the suspension check entirely.
 */
function ms_mcp_prepare_probe(string $repoRoot, string $probe, int $perMinute, int $perHour): void
{
    // oauth_server.php came along with #331 step 5/6: mcp.php requires it for
    // the discovery challenge on a 401. This suite never creates the OAuth
    // schema, so oauthAvailable() is false and the challenge stays the bare
    // one — which is exactly the "nothing changes while OAuth is off" case.
    foreach (['mcp.php', 'mcp_tools.php', 'oauth_server.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    $extra = sprintf(<<<'PHP'

// --- added by tests/mcp_endpoint_test.php ---------------------------
$config['abuse'] = ['mcp_rate_per_minute' => %d, 'mcp_rate_per_hour' => %d];

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
PHP, $perMinute, $perHour);

    if (file_put_contents($probe . '/config.php', ms_test_stub_config_php() . $extra) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }
}

/**
 * mcp.php refuses php_sapi_name() === 'cli', so it cannot be driven through
 * the CLI probe runner ms_test_request() uses. This starts PHP's built-in web
 * server (SAPI 'cli-server', which passes that guard) against a probe docroot
 * and returns [process, port, pipes] to fetch from and later shut down.
 */
function ms_mcp_start_server(string $root): array
{
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // log_errors/error_reporting are set the way ms_test_request() sets
        // them for the CLI probe runner: with display_errors off, a warning
        // would otherwise be swallowed entirely and section 10 could never
        // see one.
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

function ms_mcp_stop_server(array $server): void
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

/**
 * One request against the probe's built-in server.
 *
 * @param array<string,string> $headers
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function ms_mcp_request_raw(int $port, string $method, string $path, array $headers, string $requestBody): array
{
    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= $name . ': ' . $value . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headerLines,
        'content' => $requestBody,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $responseBody = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
            $responseHeaders = [];
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $responseHeaders[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }
    }
    return [
        'status' => $status,
        'body' => $responseBody === false ? '' : $responseBody,
        'headers' => $responseHeaders,
    ];
}

/**
 * POST a JSON-RPC message to the endpoint.
 *
 * @param array{token?:?string, authorization?:?string, payload?:array|string|null,
 *              origin?:?string, content_type?:?string, method?:string, path?:string,
 *              headers?:array<string,string>} $opts
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function ms_mcp_call(int $port, array $opts = []): array
{
    $headers = [];
    $contentType = array_key_exists('content_type', $opts) ? $opts['content_type'] : 'application/json';
    if ($contentType !== null) {
        $headers['Content-Type'] = (string) $contentType;
    }
    $headers['Accept'] = 'application/json, text/event-stream';
    // No 'origin' key at all means the suite's own origin; origin => null
    // means the header is absent, which the endpoint must also accept.
    if (array_key_exists('origin', $opts)) {
        if ($opts['origin'] !== null) {
            $headers['Origin'] = (string) $opts['origin'];
        }
    } else {
        $headers['Origin'] = MS_TEST_ORIGIN;
    }
    if (array_key_exists('token', $opts) && $opts['token'] !== null) {
        $headers['Authorization'] = 'Bearer ' . $opts['token'];
    }
    if (array_key_exists('authorization', $opts) && $opts['authorization'] !== null) {
        $headers['Authorization'] = (string) $opts['authorization'];
    }
    foreach (($opts['headers'] ?? []) as $name => $value) {
        $headers[$name] = (string) $value;
    }

    $payload = $opts['payload'] ?? null;
    if (is_string($payload)) {
        $requestBody = $payload;
    } elseif ($payload === null) {
        $requestBody = '';
    } else {
        $requestBody = (string) json_encode($payload);
    }

    return ms_mcp_request_raw(
        $port,
        (string) ($opts['method'] ?? 'POST'),
        (string) ($opts['path'] ?? '/mcp.php'),
        $headers,
        $requestBody
    );
}

/** A JSON-RPC request (with an id) as the endpoint should receive it. */
function ms_mcp_rpc(string $method, $id, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

/** A JSON-RPC notification: the same message without an id. */
function ms_mcp_notification(string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
}

/** Decode a response body, reporting a body that is not JSON at all. */
function ms_mcp_decoded(string $label, array $response): ?array
{
    $decoded = json_decode($response['body'], true);
    ms_test_check(
        $label,
        is_array($decoded),
        'status ' . $response['status'] . ', body: ' . substr($response['body'], 0, 200)
    );
    return is_array($decoded) ? $decoded : null;
}

/** Seed one access token row and return its plaintext. */
function ms_mcp_seed_token(PDO $pdo, int $userId, string $scopes = 'read', array $overrides = []): string
{
    $token = mcpTokenGenerate();
    $stmt = $pdo->prepare(
        'INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)'
    );
    $stmt->execute([
        $userId,
        (string) ($overrides['name'] ?? 'test token'),
        mcpTokenHash($token),
        mcpTokenPrefixOf($token),
        $scopes,
        date('Y-m-d H:i:s'),
        $overrides['expires_at'] ?? null,
        $overrides['revoked_at'] ?? null,
    ]);
    return $token;
}

// ---------------------------------------------------------------------
// Docroot + database
// ---------------------------------------------------------------------

echo "Mail Shield — MCP endpoint (#321)\n";

$probe = ms_test_probe_build($msRepoRoot);
echo "probe docroot: {$probe}\n";
ms_mcp_prepare_probe($msRepoRoot, $probe, 1000, 1000);
$probeServer = ms_mcp_start_server($probe);
$probePort = $probeServer[1];

$pdo = ms_test_db(ms_test_probe_sqlite($probe));
foreach (explode(';', ms_mcp_extra_schema()) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$userPro = ms_test_seed_user($pdo, 'pro@example.com', 'pro');
$userRegular = ms_test_seed_user($pdo, 'regular@example.com', 'regular');
$userSuspended = ms_test_seed_user($pdo, 'suspended@example.com', 'pro');
$pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')
    ->execute([date('Y-m-d H:i:s'), $userSuspended]);

$tokenPro = ms_mcp_seed_token($pdo, $userPro, 'read');
$tokenWrite = ms_mcp_seed_token($pdo, $userPro, 'read,write');

// ---------------------------------------------------------------------
// 1. initialize → notifications/initialized → tools/list → ping
// ---------------------------------------------------------------------

ms_test_section('1. A full initialize → notifications/initialized → tools/list → ping round trip');

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_rpc('initialize', 1, [
    'protocolVersion' => '2025-11-25',
    'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'test client', 'version' => '1.0.0'],
])]);
ms_test_same('1a. initialize answers 200', 200, $response['status']);
$body = ms_mcp_decoded('1b. ... with a JSON body', $response);
ms_test_same('1c. the negotiated protocol version is the pinned one', '2025-11-25', $body['result']['protocolVersion'] ?? null);
ms_test_same('1d. the server names itself', 'Mail Shield', $body['result']['serverInfo']['name'] ?? null);
ms_test_check('1e. it advertises the tools capability', isset($body['result']['capabilities']['tools']));
ms_test_same('1f. the request id is echoed back', 1, $body['id'] ?? null);
ms_test_check('1g. no session id is ever issued', !isset($response['headers']['mcp-session-id']));
ms_test_same('1h. the answer is not cached', 'no-store', $response['headers']['cache-control'] ?? null);
ms_test_check('1i. no cookie is set', !isset($response['headers']['set-cookie']));

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_notification('notifications/initialized')]);
ms_test_same('1j. notifications/initialized answers 202', 202, $response['status']);
ms_test_same('1k. ... with no body at all', '', $response['body']);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_rpc('tools/list', 2)]);
ms_test_same('1l. tools/list answers 200', 200, $response['status']);
$body = ms_mcp_decoded('1m. ... with a JSON body', $response);
ms_test_same(
    '1n. ... listing the three read tools #322 registered',
    ['list_addresses', 'list_messages', 'get_message'],
    array_column($body['result']['tools'] ?? [], 'name')
);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_rpc('ping', 3)]);
ms_test_same('1o. ping answers 200', 200, $response['status']);
ms_test_check(
    '1p. ... with an empty JSON object as its result, not an empty array',
    str_contains($response['body'], '"result":{}'),
    'body: ' . $response['body']
);

$pdo = ms_test_refresh_db(ms_test_probe_sqlite($probe));
$used = $pdo->query('SELECT last_used_at FROM mcp_access_tokens WHERE token_hash = ' . $pdo->quote(mcpTokenHash($tokenPro)))->fetchColumn();
ms_test_check('1q. serving a request went through mcpTokenResolve() (last_used_at is set)', is_string($used) && $used !== '');

// ---------------------------------------------------------------------
// 2. Credentials: missing, malformed, unknown, revoked, expired
// ---------------------------------------------------------------------

ms_test_section('2. Missing, malformed, unknown, revoked and expired tokens are all 401');

$response = ms_mcp_call($probePort, ['payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('2a. no Authorization header is 401', 401, $response['status']);
ms_test_check(
    '2b. ... with a WWW-Authenticate challenge naming the realm',
    str_contains((string) ($response['headers']['www-authenticate'] ?? ''), 'Bearer realm="Mail Shield"'),
    'got: ' . (string) ($response['headers']['www-authenticate'] ?? '(none)')
);
// #331 step 5/6 appends resource_metadata once OAuth discovery is on; this
// suite has no OAuth schema and sets no OAUTH_ENABLED, so the challenge must be
// exactly what it always was — the "nothing changes for existing token users"
// case the issue asks to keep.
ms_test_same(
    '2b2. ... and exactly the bare challenge while OAuth discovery is off',
    'Bearer realm="Mail Shield"',
    $response['headers']['www-authenticate'] ?? null
);

foreach ([
    'not a bearer credential' => ['authorization' => 'Basic dXNlcjpwYXNz'],
    'an empty bearer value' => ['authorization' => 'Bearer '],
    'too short' => ['token' => 'msk_abc123'],
    'uppercase hex' => ['token' => 'msk_' . str_repeat('A', 64)],
    'non-hex' => ['token' => 'msk_' . str_repeat('z', 64)],
    'right shape, not a token' => ['token' => 'msk_' . str_repeat('0', 64)],
] as $label => $opts) {
    $response = ms_mcp_call($probePort, $opts + ['payload' => ms_mcp_rpc('tools/list', 1)]);
    ms_test_same("2c.{$label}: 401", 401, $response['status']);
}

$revoked = ms_mcp_seed_token($pdo, $userPro, 'read', ['name' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')]);
$expired = ms_mcp_seed_token($pdo, $userPro, 'read', ['name' => 'expired', 'expires_at' => date('Y-m-d H:i:s', time() - 60)]);

$response = ms_mcp_call($probePort, ['token' => $revoked, 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('2d. a revoked token is 401', 401, $response['status']);
$response = ms_mcp_call($probePort, ['token' => $expired, 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('2e. an expired token is 401', 401, $response['status']);

// ---------------------------------------------------------------------
// 3. Entitlement: not Pro, suspended
// ---------------------------------------------------------------------

ms_test_section('3. An account without Pro, and a suspended account, are 403');

$regularToken = ms_mcp_seed_token($pdo, $userRegular, 'read');
$suspendedToken = ms_mcp_seed_token($pdo, $userSuspended, 'read');

$response = ms_mcp_call($probePort, ['token' => $regularToken, 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('3a. an account that is not Pro is 403', 403, $response['status']);
$body = ms_mcp_decoded('3b. ... with a JSON-RPC error saying Pro is required', $response);
ms_test_check(
    '3c. ... naming Pro, so the holder knows what to fix',
    stripos((string) ($body['error']['message'] ?? ''), 'pro') !== false,
    'message: ' . (string) ($body['error']['message'] ?? '(none)')
);

$response = ms_mcp_call($probePort, ['token' => $suspendedToken, 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('3d. a suspended account is 403', 403, $response['status']);
ms_test_check('3e. ... and is not told anything about a credential', !isset($response['headers']['www-authenticate']));

// ---------------------------------------------------------------------
// 4. Origin and method
// ---------------------------------------------------------------------

ms_test_section('4. Foreign Origin is 403; every method but POST is 405');

foreach ([
    'another site' => 'https://evil.example',
    'another port on our own host' => 'http://localhost:9999',
    'the literal null origin' => 'null',
    'our own host over another scheme' => 'https://localhost:8085',
] as $label => $foreignOrigin) {
    $response = ms_mcp_call($probePort, ['token' => $tokenPro, 'origin' => $foreignOrigin, 'payload' => ms_mcp_rpc('tools/list', 1)]);
    ms_test_same("4a.{$label}: 403", 403, $response['status']);
}

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'origin' => null, 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('4b. an absent Origin is accepted (a non-browser client sends none)', 200, $response['status']);

foreach (['GET', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD'] as $httpMethod) {
    $response = ms_mcp_call($probePort, ['token' => $tokenPro, 'method' => $httpMethod, 'payload' => null]);
    ms_test_same("4c.{$httpMethod}: 405", 405, $response['status']);
}
$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'method' => 'GET']);
ms_test_same('4d. the 405 names the one method that works', 'POST', $response['headers']['allow'] ?? null);

// ---------------------------------------------------------------------
// 5. Transport-level request failures
// ---------------------------------------------------------------------

ms_test_section('5. Content-Type, protocol version, body size, invalid JSON, batches');

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'content_type' => 'text/plain', 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('5a. a non-JSON Content-Type is 415', 415, $response['status']);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'content_type' => null, 'payload' => ms_mcp_rpc('tools/list', 1)]);
ms_test_same('5b. a missing Content-Type is 415', 415, $response['status']);

$response = ms_mcp_call($probePort, [
    'token' => $tokenPro,
    'headers' => ['MCP-Protocol-Version' => '2026-07-28'],
    'payload' => ms_mcp_rpc('tools/list', 1),
]);
ms_test_same('5c. a protocol version this endpoint does not speak is 400', 400, $response['status']);

$response = ms_mcp_call($probePort, [
    'token' => $tokenPro,
    'headers' => ['MCP-Protocol-Version' => '2025-11-25'],
    'payload' => ms_mcp_rpc('tools/list', 1),
]);
ms_test_same('5d. the pinned version is accepted', 200, $response['status']);

$oversized = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . str_repeat('x', 70000) . '"}}';
$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => $oversized]);
ms_test_same('5e. a body over 64 KB is 413', 413, $response['status']);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => '{"jsonrpc":"2.0",']);
ms_test_same('5f. invalid JSON is 400', 400, $response['status']);
$body = ms_mcp_decoded('5g. ... with a JSON body anyway', $response);
ms_test_same('5h. ... carrying the JSON-RPC parse error code', -32700, $body['error']['code'] ?? null);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => '[{"jsonrpc":"2.0","id":1,"method":"ping"}]']);
ms_test_same('5i. a batch is 400', 400, $response['status']);
$body = ms_mcp_decoded('5j. ... with a JSON body', $response);
ms_test_same('5k. ... carrying Invalid Request', -32600, $body['error']['code'] ?? null);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => '"just a string"']);
ms_test_same('5l. a JSON scalar where a message belongs is 400', 400, $response['status']);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ['jsonrpc' => '2.0', 'id' => 4]]);
ms_test_same('5m. a message with no method is 400', 400, $response['status']);

// ---------------------------------------------------------------------
// 6. Dispatch: unknown method, and notifications are never answered
// ---------------------------------------------------------------------

ms_test_section('6. Unknown methods, and notifications that get no answer');

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_rpc('resources/list', 7)]);
ms_test_same('6a. an unknown method answers 200 (JSON-RPC errors travel in the body)', 200, $response['status']);
$body = ms_mcp_decoded('6b. ... with a JSON body', $response);
ms_test_same('6c. ... carrying Method not found', -32601, $body['error']['code'] ?? null);
ms_test_same('6d. ... against the request id', 7, $body['id'] ?? null);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_notification('notifications/cancelled')]);
ms_test_same('6e. an unknown notification is still 202 with no body', 202, $response['status']);
ms_test_same('6f. ... and no body', '', $response['body']);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_notification('tools/call')]);
ms_test_same('6g. a notification never gets a result, not even for a known method', 202, $response['status']);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_rpc('tools/call', 8, ['name' => 'no_such_tool'])]);
ms_test_same('6h. calling a tool that does not exist is 200', 200, $response['status']);
$body = ms_mcp_decoded('6i. ... with a JSON body', $response);
ms_test_same('6j. ... carrying Invalid params', -32602, $body['error']['code'] ?? null);

$response = ms_mcp_call($probePort, ['token' => $tokenPro, 'payload' => ms_mcp_rpc('tools/call', 9, [])]);
ms_test_same('6k. calling with no tool name is an error too', -32602, (ms_mcp_decoded('6l. ... with a JSON body', $response)['error']['code'] ?? null));

// ---------------------------------------------------------------------
// 7. The tool registry and the scope filter
// ---------------------------------------------------------------------

ms_test_section('7. tools/list is filtered by the token scope');

$fixtures = [
    ['name' => 'read_tool', 'scope' => 'read', 'description' => 'reads', 'inputSchema' => ['type' => 'object'], 'handler' => 'strlen'],
    ['name' => 'write_tool', 'scope' => 'write', 'description' => 'writes', 'inputSchema' => ['type' => 'object'], 'handler' => 'strlen'],
    ['name' => 'typo_tool', 'scope' => 'admin', 'description' => 'a typo in the registry', 'inputSchema' => [], 'handler' => 'strlen'],
];

$readOnly = mcpToolsForScopes($fixtures, 'read');
ms_test_same('7a. a read token sees exactly the read tool', ['read_tool'], array_column($readOnly, 'name'));
$readWrite = mcpToolsForScopes($fixtures, 'read,write');
ms_test_same('7b. a read,write token sees the read and the write tool', ['read_tool', 'write_tool'], array_column($readWrite, 'name'));
ms_test_same('7c. a tool whose scope is not a real scope is never offered', [], array_column(mcpToolsForScopes($fixtures, 'admin'), 'name'));
ms_test_check(
    '7d. the scope and handler keys never leave the server',
    $readWrite !== [] && !array_key_exists('scope', $readWrite[0]) && !array_key_exists('handler', $readWrite[0]),
    'the internal keys leaked into a tools/list entry'
);
ms_test_same('7e. read does not cover write', false, mcpTokenAllowsScope('read', 'write'));
ms_test_same('7f. read,write covers write', true, mcpTokenAllowsScope('read,write', 'write'));
ms_test_same('7g. read,write covers read', true, mcpTokenAllowsScope('read,write', 'read'));
ms_test_same('7h. an unknown scope is never allowed', false, mcpTokenAllowsScope('read,write', 'admin'));
ms_test_same(
    '7i. the shipped registry holds the three read tools #322 added',
    ['list_addresses', 'list_messages', 'get_message'],
    array_column(mcpToolsForScopes(mcpToolRegistry(), 'read'), 'name')
);
ms_test_check(
    '7i2. every shipped read tool carries readOnlyHint, the write tools announce a deletion or not, '
        . 'and none leaks its scope or handler',
    (static function (): bool {
        foreach (mcpToolRegistry() as $tool) {
            $annotations = $tool['annotations'] ?? [];
            if ($tool['scope'] === 'read' && ($annotations['readOnlyHint'] ?? null) !== true) {
                return false;
            }
            if ($tool['scope'] === 'write' && !array_key_exists('destructiveHint', $annotations)) {
                return false;
            }
        }
        foreach (mcpToolsForScopes(mcpToolRegistry(), 'read,write') as $entry) {
            if (array_key_exists('scope', $entry) || array_key_exists('handler', $entry)) {
                return false;
            }
        }
        return true;
    })()
);

$response = ms_mcp_call($probePort, ['token' => $tokenWrite, 'payload' => ms_mcp_rpc('tools/list', 10)]);
$body = ms_mcp_decoded('7j. a read,write token gets a tool list too', $response);
ms_test_same(
    '7k. ... with the read tools #322 added and the write tools #323 added',
    [
        'list_addresses', 'list_messages', 'get_message',
        'create_sticky_address', 'create_timed_address', 'delete_address',
    ],
    array_column($body['result']['tools'] ?? [], 'name')
);

// ---------------------------------------------------------------------
// 8. The per-token rate limit
// ---------------------------------------------------------------------

ms_test_section('8. The per-token rate limit answers 429 with Retry-After');

// A second docroot with a limit small enough to reach in a few requests —
// three a minute — so the check exercises the real counting rather than
// hammering the endpoint 121 times.
$limitedProbe = ms_test_probe_build($msRepoRoot);
ms_mcp_prepare_probe($msRepoRoot, $limitedProbe, 3, 100);
$limitedServer = ms_mcp_start_server($limitedProbe);
$limitedPort = $limitedServer[1];
$limitedPdo = ms_test_db(ms_test_probe_sqlite($limitedProbe));
foreach (explode(';', ms_mcp_extra_schema()) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $limitedPdo->exec($statement);
    }
}
$limitedUser = ms_test_seed_user($limitedPdo, 'limited@example.com', 'pro');
$limitedToken = ms_mcp_seed_token($limitedPdo, $limitedUser, 'read');
$otherToken = ms_mcp_seed_token($limitedPdo, $limitedUser, 'read');

for ($i = 1; $i <= 3; $i++) {
    $response = ms_mcp_call($limitedPort, ['token' => $limitedToken, 'payload' => ms_mcp_rpc('ping', $i)]);
    ms_test_same("8a. request {$i} of 3 is served", 200, $response['status']);
}

$response = ms_mcp_call($limitedPort, ['token' => $limitedToken, 'payload' => ms_mcp_rpc('ping', 4)]);
ms_test_same('8b. the request after the limit is 429', 429, $response['status']);
$retryAfter = (int) ($response['headers']['retry-after'] ?? 0);
ms_test_check('8c. ... with a Retry-After that points at the end of the window', $retryAfter >= 1 && $retryAfter <= 60, 'Retry-After: ' . $retryAfter);

// The subject is the token, not the account: another token of the same
// account still has its own budget.
$response = ms_mcp_call($limitedPort, ['token' => $otherToken, 'payload' => ms_mcp_rpc('ping', 5)]);
ms_test_same('8d. a second token of the same account is not affected', 200, $response['status']);

$limitedPdo = ms_test_refresh_db(ms_test_probe_sqlite($limitedProbe));
$counted = (int) $limitedPdo->query("SELECT COALESCE(SUM(hits), 0) FROM abuse_counters WHERE scope = 'mcp_token'")->fetchColumn();
ms_test_check('8e. every request was counted, the refused one included', $counted >= 5, 'counted: ' . $counted);

// ---------------------------------------------------------------------
// 9. What must never reach the log
// ---------------------------------------------------------------------

ms_test_section('9. The endpoint never logs a credential or a request body');

$source = (string) file_get_contents($msRepoRoot . '/mcp.php');
$badLogs = [];
if (preg_match_all('/logMessage\((?:[^();]|\([^()]*\))*\)/s', $source, $matches)) {
    foreach ($matches[0] as $call) {
        if (preg_match('/\$rawBody\b|\$bearer\b|\$authorization\b|\$message\b|\$params\b|\$_SERVER\b|HTTP_AUTHORIZATION|\'Authorization\'|"Authorization"/', $call)) {
            $badLogs[] = preg_replace('/\s+/', ' ', mb_substr($call, 0, 140));
        }
    }
}
ms_test_check(
    '9a. no log call is handed the Authorization header or the request body',
    $badLogs === [],
    implode("\n       ", $badLogs)
);
ms_test_check(
    '9b. the endpoint logs one INFO line per tool call, with the ids and the outcome',
    str_contains($source, "logMessage('INFO', 'MCP tool call'")
        && str_contains($source, "'user_id' => (int) \$tokenRow['user_id']")
        && str_contains($source, "'token_id' => (int) \$tokenRow['id']")
        && str_contains($source, "'outcome' => \$outcome")
);
ms_test_check(
    '9c. the three WARNINGs the issue asks for are there, with the visitor IP',
    substr_count($source, 'getVisitorIp()') >= 3
        && str_contains($source, 'foreign Origin')
        && str_contains($source, 'malformed token')
        && str_contains($source, 'rate limit')
);

// ---------------------------------------------------------------------
// 10. Nothing the endpoint did tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('10. The endpoint answers without a PHP warning, notice or fatal');

// The servers log to stderr with display_errors off, so a warning the suite
// never sees in a response body still shows up here — an undefined index in a
// header or Origin a client actually sends is exactly the kind of defect that
// otherwise only appears in production.
foreach (['main probe' => $probeServer, 'rate-limit probe' => $limitedServer] as $label => $server) {
    $noise = [];
    // Not anchored: the built-in server prefixes every line it writes with
    // its own [date] stamp, unlike the CLI probe runner the shared harness
    // reads.
    foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
        if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
            $noise[] = trim($line);
        }
    }
    ms_test_check("10.{$label}: no PHP warning, notice or fatal", $noise === [], implode(' | ', array_slice($noise, 0, 3)));
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_mcp_stop_server($probeServer);
ms_mcp_stop_server($limitedServer);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($probe);
    ms_test_cleanup($limitedProbe);
} else {
    echo "Probe docroots left in place for inspection: {$probe}, {$limitedProbe}\n";
}
exit($exitCode);
