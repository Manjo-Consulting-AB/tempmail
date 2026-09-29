<?php

declare(strict_types=1);

/**
 * Regression coverage for the OAuth token endpoint and revocation (#331 step
 * 2/6, oauth_token.php, oauth_revoke.php, oauth_server.php).
 *
 * Run with:  php tests/oauth_token_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network are
 * needed: it reuses the throwaway-docroot harness in
 * tests/lib/pushover_harness.php (the same pattern tests/oauth_register_test.php
 * and tests/mcp_endpoint_test.php use), copies the two endpoints, their
 * library and the MCP endpoint into that docroot and drives them through PHP's
 * built-in web server — the only SAPI any of these files runs under (they
 * refuse CLI) — against a SQLite database it lays the OAuth, token, mail and
 * abuse-guard tables onto itself. The harness file is not modified.
 *
 * A real authorization code is seeded the way the authorization endpoint will
 * write one (step 3/6); the PKCE pair is the S256 vector from RFC 7636
 * Appendix B, so the happy path proves interop rather than agreement with
 * itself.
 *
 * What is checked is the endpoints' own behaviour: the full exchange, every
 * way a code can be refused (all answering the same invalid_grant, with a
 * second use also revoking what the code granted), refresh rotation and the
 * re-use detection that revokes the grant, scope narrowing, the issued access
 * token working against the real mcp.php with the right scopes and stopping
 * after a revoke, the revocation endpoint's four cases, the transport errors,
 * the rate limit, the fail-closed answer before the migration, and the one
 * thing that must never happen — a token, code or verifier reaching the log.
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
require $msRepoRoot . '/oauth_server.php';
require $msRepoRoot . '/mcp_tools.php';

/** The PKCE pair from RFC 7636 Appendix B. */
const MS_OT_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const MS_OT_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const MS_OT_REDIRECT = 'https://example.com/cb';

// ---------------------------------------------------------------------
// Entitlement helpers for the in-process calls
// ---------------------------------------------------------------------

/**
 * mcp_tokens.php asks config.php for these by name. The suite has no
 * config.php, so it mirrors the real bodies against the same SQLite file the
 * probe writes to — which is what lets mcpTokenResolve() be called here and
 * answer honestly about the rows a probe request just created.
 */
function proUserIsPro(int $userId): bool
{
    global $pdo;
    if ($userId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT account_type, pro_expires_at FROM pro_users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return $row['account_type'] === 'pro'
        && ($row['pro_expires_at'] === null || strtotime((string) $row['pro_expires_at']) >= time());
}

function proUserIsSuspended(int $userId): bool
{
    global $pdo;
    if ($userId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== null;
}

// ---------------------------------------------------------------------
// Extra schema + docroot helpers (kept local to this suite)
// ---------------------------------------------------------------------

/**
 * DDL on top of ms_test_schema(): pro_users.suspended_at, the #320 token table
 * with the OAuth columns migrate_oauth.php adds, the abuse guard's three
 * tables (so abuseGuardAvailable() is true and the rate limit is live), and
 * the two tables migrate_oauth.php creates.
 */
function ms_ot_extra_schema(): string
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
    revoked_at TEXT NULL,
    oauth_client_id TEXT NULL,
    refresh_token_hash TEXT NULL,
    refresh_expires_at TEXT NULL,
    grant_created_at TEXT NULL,
    rotated_refresh_hash TEXT NULL,
    rotated_at TEXT NULL
);
CREATE UNIQUE INDEX uniq_mcp_access_tokens_hash ON mcp_access_tokens (token_hash);
CREATE UNIQUE INDEX uniq_mcp_access_tokens_refresh ON mcp_access_tokens (refresh_token_hash);

CREATE TABLE oauth_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    client_name TEXT NOT NULL,
    redirect_uris TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    registered_ip_hash TEXT NULL
);
CREATE UNIQUE INDEX uniq_oauth_clients_client_id ON oauth_clients (client_id);

CREATE TABLE oauth_authorization_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_hash TEXT NOT NULL,
    client_id TEXT NOT NULL,
    pro_user_id INTEGER NOT NULL,
    redirect_uri TEXT NOT NULL,
    scopes TEXT NOT NULL,
    code_challenge TEXT NOT NULL,
    resource TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL
);
CREATE UNIQUE INDEX uniq_oauth_authorization_codes_hash ON oauth_authorization_codes (code_hash);

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

function ms_ot_apply_schema(PDO $pdo): void
{
    foreach (explode(';', ms_ot_extra_schema()) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/**
 * Copy the two endpoints, their library and the MCP endpoint into a docroot
 * ms_test_probe_build() already built, and append the things its stub config
 * does not carry: a trial hash key (so oauthIpHash() can hash and the per-IP
 * limits are live), the rate limits this suite wants, and
 * proUserIsSuspended() — mirroring config.php's own body, since
 * mcpTokenResolve() and the exchange ask for it by name.
 */
function ms_ot_prepare_probe(string $repoRoot, string $probe, array $abuse): void
{
    foreach (['oauth_token.php', 'oauth_revoke.php', 'oauth_server.php', 'mcp.php', 'mcp_tools.php', 'email_html_sanitizer.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    $extra = "\n// --- added by tests/oauth_token_test.php ----------------------------\n"
        . '$config[\'trial\'] = [\'hash_key\' => str_repeat(\'k\', 32)];' . "\n"
        . '$config[\'abuse\'] = ' . var_export($abuse, true) . ';' . "\n"
        . <<<'PHP'
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

    if (file_put_contents($probe . '/config.php', ms_test_stub_config_php() . $extra) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }
}

/** Build a probe docroot, optionally with the OAuth schema, and return its path. */
function ms_ot_build_probe(string $repoRoot, array $abuse, bool $withOauth = true): string
{
    $probe = ms_test_probe_build($repoRoot);
    ms_ot_prepare_probe($repoRoot, $probe, $abuse);
    if ($withOauth) {
        ms_ot_apply_schema(ms_test_db(ms_test_probe_sqlite($probe)));
    }
    return $probe;
}

// ---------------------------------------------------------------------
// The built-in web server + HTTP client
// ---------------------------------------------------------------------

function ms_ot_start_server(string $root): array
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

function ms_ot_stop_server(array $server): void
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

/**
 * One request against a probe's built-in server.
 *
 * @param array<string,string> $headers
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function ms_ot_request_raw(int $port, string $method, string $path, array $headers, string $requestBody): array
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
 * POST a form to one of the two OAuth endpoints.
 *
 * @param array<string,mixed> $fields
 * @param array{content_type?:?string, method?:string, path?:string, raw?:string} $opts
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function ms_ot_call(int $port, array $fields, array $opts = []): array
{
    $headers = [];
    $contentType = array_key_exists('content_type', $opts) ? $opts['content_type'] : 'application/x-www-form-urlencoded';
    if ($contentType !== null) {
        $headers['Content-Type'] = (string) $contentType;
    }
    $requestBody = array_key_exists('raw', $opts) ? (string) $opts['raw'] : http_build_query($fields);

    return ms_ot_request_raw(
        $port,
        (string) ($opts['method'] ?? 'POST'),
        (string) ($opts['path'] ?? '/oauth_token.php'),
        $headers,
        $requestBody
    );
}

/** POST a JSON-RPC message to the MCP endpoint. */
function ms_ot_mcp_call(int $port, ?string $token, string $method, $id, array $params = []): array
{
    $headers = [
        'Content-Type' => 'application/json',
        'Origin' => MS_TEST_ORIGIN,
    ];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    return ms_ot_request_raw($port, 'POST', '/mcp.php', $headers, (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ]));
}

/** Decode a response body, reporting a body that is not JSON at all. */
function ms_ot_decoded(string $label, array $response): ?array
{
    $decoded = json_decode($response['body'], true);
    ms_test_check(
        $label,
        is_array($decoded),
        'status ' . $response['status'] . ', body: ' . substr($response['body'], 0, 200)
    );
    return is_array($decoded) ? $decoded : null;
}

/** The RFC 6749 §5.2 error code of a response, or null. */
function ms_ot_error(array $response): ?string
{
    $decoded = json_decode($response['body'], true);
    return is_array($decoded) && isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null;
}

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

/** Register a public client the way tests/oauth_register_test.php drives it. */
function ms_ot_seed_client(PDO $pdo, string $name, string $redirectUri = MS_OT_REDIRECT): string
{
    $result = oauthClientRegister($pdo, ['client_name' => $name, 'redirect_uris' => [$redirectUri]]);
    if (!$result['ok']) {
        throw new RuntimeException('Could not register the fixture client');
    }
    return (string) $result['client']['client_id'];
}

/** One pending authorization code, written the way step 3/6 will write it. */
function ms_ot_seed_code(PDO $pdo, string $clientId, int $userId, array $overrides = []): string
{
    $code = oauthCodeGenerate();
    $stmt = $pdo->prepare('INSERT INTO oauth_authorization_codes (code_hash, client_id, pro_user_id, redirect_uri, scopes, code_challenge, resource, expires_at, used_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)');
    $stmt->execute([
        oauthHash($code),
        $clientId,
        $userId,
        (string) ($overrides['redirect_uri'] ?? MS_OT_REDIRECT),
        (string) ($overrides['scopes'] ?? 'mcp:read mcp:write'),
        (string) ($overrides['challenge'] ?? MS_OT_CHALLENGE),
        (string) ($overrides['resource'] ?? ''),
        (string) ($overrides['expires_at'] ?? date('Y-m-d H:i:s', time() + 60)),
    ]);
    return $code;
}

/** A manual token (#320) — oauth_client_id NULL, unreachable from /oauth/revoke. */
function ms_ot_seed_manual_token(PDO $pdo, int $userId, string $scopes = 'read,write'): string
{
    $token = mcpTokenGenerate();
    $pdo->prepare('INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL)')
        ->execute([$userId, 'manual token', mcpTokenHash($token), mcpTokenPrefixOf($token), $scopes, date('Y-m-d H:i:s')]);
    return $token;
}

/** One grant row, as it looks after a successful exchange. */
function ms_ot_grant_row(PDO $pdo, string $accessToken): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mcp_access_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([mcpTokenHash($accessToken)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return is_array($row) ? $row : null;
}

/**
 * One scalar read on the suite's own connection. The statement is closed
 * before returning, and that matters: this suite writes to the same SQLite
 * file the probe's web server writes to, and a statement left open holds a
 * WAL read snapshot. The next write on this connection would then fail with
 * SQLITE_BUSY_SNAPSHOT ("database is locked", and the busy handler is not
 * even consulted) as soon as the server had committed in between.
 */
function ms_ot_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
}

/** The full authorization_code request for a seeded code. */
function ms_ot_code_fields(string $code, string $clientId, array $overrides = []): array
{
    return array_merge([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => MS_OT_REDIRECT,
        'client_id' => $clientId,
        'code_verifier' => MS_OT_VERIFIER,
    ], $overrides);
}

// ---------------------------------------------------------------------
// Docroots, servers and database
// ---------------------------------------------------------------------

echo "Mail Shield — OAuth token endpoint and revocation (#331 step 2/6)\n";

$generousLimits = ['oauth_token_ip_hour' => 1000, 'oauth_token_ip_day' => 1000];

$mainProbe = ms_ot_build_probe($msRepoRoot, $generousLimits);
$mainServer = ms_ot_start_server($mainProbe);
$mainPort = $mainServer[1];
$pdo = ms_test_db(ms_test_probe_sqlite($mainProbe));

$userPro = ms_test_seed_user($pdo, 'pro@example.com', 'pro');
$userRegular = ms_test_seed_user($pdo, 'regular@example.com', 'regular');
$userSuspended = ms_test_seed_user($pdo, 'suspended@example.com', 'pro');
$pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $userSuspended]);

$clientA = ms_ot_seed_client($pdo, 'Client A');
$clientB = ms_ot_seed_client($pdo, 'Client B');

// ---------------------------------------------------------------------
// 1. PKCE and the scope helpers (pure functions)
// ---------------------------------------------------------------------

ms_test_section('1. PKCE S256 and the scope helpers');

$computed = rtrim(strtr(base64_encode(hash('sha256', MS_OT_VERIFIER, true)), '+/', '-_'), '=');
ms_test_same('1a. the RFC 7636 Appendix B vector computes the documented challenge', MS_OT_CHALLENGE, $computed);
ms_test_same('1b. ... and oauthCodeChallengeMatches() accepts it', true, oauthCodeChallengeMatches(MS_OT_VERIFIER, MS_OT_CHALLENGE));
ms_test_same('1c. another verifier does not match', false, oauthCodeChallengeMatches(str_repeat('a', 43), MS_OT_CHALLENGE));
ms_test_same('1d. a verifier shorter than 43 characters does not match', false, oauthCodeChallengeMatches(substr(MS_OT_VERIFIER, 0, 42), MS_OT_CHALLENGE));
ms_test_same('1e. a verifier over 128 characters does not match', false, oauthCodeChallengeMatches(str_repeat('a', 129), MS_OT_CHALLENGE));
ms_test_same('1f. a verifier outside the allowed charset does not match', false, oauthCodeChallengeMatches(str_repeat('a', 42) . '!', MS_OT_CHALLENGE));
ms_test_same('1g. an empty challenge never matches', false, oauthCodeChallengeMatches(MS_OT_VERIFIER, ''));

ms_test_same('1h. mcp:read is one internal scope', ['read'], oauthScopeSetFromExternal('mcp:read'));
ms_test_same('1i. mcp:write implies read', ['write'], oauthScopeSetFromExternal('mcp:write'));
ms_test_same('1j. both scopes parse', ['read', 'write'], oauthScopeSetFromExternal('mcp:read mcp:write'));
ms_test_same('1k. extra whitespace is fine', ['read', 'write'], oauthScopeSetFromExternal("  mcp:read \t mcp:write  "));
ms_test_same('1l. an empty scope is an empty set', [], oauthScopeSetFromExternal(''));
ms_test_same('1m. an unknown scope is refused', null, oauthScopeSetFromExternal('mcp:admin'));
ms_test_same('1n. a bare word is refused', null, oauthScopeSetFromExternal('read'));
ms_test_same('1o. write narrows the stored scopes to read,write', 'read,write', oauthScopeInternalFromSet(['write']));
ms_test_same('1p. read stays read', 'read', oauthScopeInternalFromSet(['read']));
ms_test_same('1q. the wire form of read,write', 'mcp:read mcp:write', oauthScopeExternalFromSet(['read', 'write']));
ms_test_same('1r. the set behind a stored read,write', ['read', 'write'], oauthScopeSetFromInternal('read,write'));

ms_test_same('1s. a code is 64 hex characters', 1, preg_match('/^[a-f0-9]{64}$/', oauthCodeGenerate()));
ms_test_same('1t. a well-formed code validates', true, oauthCodeValidate(oauthCodeGenerate()) !== null);
ms_test_same('1u. a malformed code does not', null, oauthCodeValidate('not-a-code'));
ms_test_same('1v. a refresh token is msr_ plus 64 hex characters', 1, preg_match('/^msr_[a-f0-9]{64}$/', oauthRefreshTokenGenerate()));
ms_test_same('1w. a refresh token is not an access token', null, mcpTokenValidate(oauthRefreshTokenGenerate()));

// ---------------------------------------------------------------------
// 2. The full exchange from a seeded code
// ---------------------------------------------------------------------

ms_test_section('2. authorization_code: the full exchange');

$code1 = ms_ot_seed_code($pdo, $clientA, $userPro);
$response = ms_ot_call($mainPort, ms_ot_code_fields($code1, $clientA));
ms_test_same('2a. the exchange answers 200', 200, $response['status']);
ms_test_same('2b. it is JSON', true, str_contains((string) ($response['headers']['content-type'] ?? ''), 'application/json'));
ms_test_same('2c. it must not be cached', 'no-store', $response['headers']['cache-control'] ?? null);
ms_test_same('2d. ... and not by a proxy either', 'no-cache', $response['headers']['pragma'] ?? null);

$body = ms_ot_decoded('2e. ... with a JSON body', $response);
$access1 = (string) ($body['access_token'] ?? '');
$refresh1 = (string) ($body['refresh_token'] ?? '');
ms_test_same('2f. the access token is an msk_ token', 1, preg_match('/^msk_[a-f0-9]{64}$/', $access1));
ms_test_same('2g. the refresh token is an msr_ token', 1, preg_match('/^msr_[a-f0-9]{64}$/', $refresh1));
ms_test_same('2h. token_type is Bearer', 'Bearer', $body['token_type'] ?? null);
ms_test_same('2i. expires_in is one hour', 3600, $body['expires_in'] ?? null);
ms_test_same('2j. the scope is echoed in the wire form', 'mcp:read mcp:write', $body['scope'] ?? null);

$row = ms_ot_grant_row($pdo, $access1);
ms_test_check('2k. the token is stored as a grant row', is_array($row));
ms_test_same('2l. ... named after the client', 'Client A', $row['name'] ?? null);
ms_test_same('2m. ... linked to the client', $clientA, $row['oauth_client_id'] ?? null);
ms_test_same('2n. ... owned by the approving account', $userPro, (int) ($row['pro_user_id'] ?? 0));
ms_test_same('2o. ... with the stored scopes', 'read,write', $row['scopes'] ?? null);
ms_test_same('2p. ... holding only the sha256 of the refresh token', oauthHash($refresh1), $row['refresh_token_hash'] ?? null);
ms_test_check('2q. ... with no plaintext credential in it', !in_array($access1, $row, true) && !in_array($refresh1, $row, true));
ms_test_check('2r. ... and nothing rotated yet', ($row['rotated_refresh_hash'] ?? null) === null && ($row['rotated_at'] ?? null) === null);
$expiresIn = strtotime((string) $row['expires_at']) - time();
ms_test_check('2s. the access token expires in about an hour', $expiresIn > 3500 && $expiresIn <= 3600, 'expires in ' . $expiresIn);
$refreshIn = strtotime((string) $row['refresh_expires_at']) - time();
ms_test_check('2t. the refresh token expires in about 90 days', $refreshIn > 90 * 86400 - 120 && $refreshIn <= 90 * 86400, 'expires in ' . $refreshIn);
ms_test_check('2u. the grant records when it began', ($row['grant_created_at'] ?? null) !== null);

ms_test_check('2v. the code is marked used', ms_ot_scalar($pdo, 'SELECT used_at FROM oauth_authorization_codes WHERE code_hash = ?', [oauthHash($code1)]) !== null);
ms_test_check('2w. the client is stamped as used', ms_ot_scalar($pdo, 'SELECT last_used_at FROM oauth_clients WHERE client_id = ?', [$clientA]) !== null);

// The issue asks for this to be verified rather than assumed: mcpTokenResolve()
// needed no change at all for an OAuth access token.
$resolved = mcpTokenResolve($pdo, $access1, time(), $reason);
ms_test_check('2x. mcpTokenResolve() accepts the OAuth access token unchanged', is_array($resolved) && $resolved['user_id'] === $userPro, 'reason: ' . $reason);
ms_test_same('2y. ... with the scopes the token row carries', 'read,write', $resolved['scopes'] ?? null);

// ---------------------------------------------------------------------
// 3. The issued token works against the real mcp.php
// ---------------------------------------------------------------------

ms_test_section('3. The issued access token drives mcp.php, filtered by scope');

$response = ms_ot_mcp_call($mainPort, $access1, 'tools/list', 1);
ms_test_same('3a. tools/list with the OAuth token is 200', 200, $response['status']);
$body = ms_ot_decoded('3b. ... with a JSON body', $response);
$names = array_column(is_array($body['result']['tools'] ?? null) ? $body['result']['tools'] : [], 'name');
ms_test_check('3c. a read,write grant sees the read tools', in_array('list_addresses', $names, true));
ms_test_check('3d. ... and the write tools', in_array('create_sticky_address', $names, true));

$readCode = ms_ot_seed_code($pdo, $clientA, $userPro, ['scopes' => 'mcp:read']);
$response = ms_ot_call($mainPort, ms_ot_code_fields($readCode, $clientA));
$body = ms_ot_decoded('3e. a read-only exchange succeeds', $response);
$accessRead = (string) ($body['access_token'] ?? '');
ms_test_same('3f. ... and reports the narrower scope', 'mcp:read', $body['scope'] ?? null);

$response = ms_ot_mcp_call($mainPort, $accessRead, 'tools/list', 2);
$body = ms_ot_decoded('3g. tools/list with the read-only token', $response);
$names = array_column(is_array($body['result']['tools'] ?? null) ? $body['result']['tools'] : [], 'name');
ms_test_check('3h. a read grant sees the read tools', in_array('list_addresses', $names, true));
ms_test_check('3i. ... and none of the write tools', !in_array('create_sticky_address', $names, true));

$response = ms_ot_mcp_call($mainPort, $accessRead, 'tools/call', 3, ['name' => 'create_sticky_address', 'arguments' => ['local_part' => 'abcdef1234']]);
$body = ms_ot_decoded('3j. calling a write tool with a read token answers', $response);
ms_test_check('3k. ... with a JSON-RPC error, not a result', isset($body['error']));

// ---------------------------------------------------------------------
// 4. Every way a code is refused answers the same invalid_grant
// ---------------------------------------------------------------------

ms_test_section('4. A bad authorization code is always the same invalid_grant');

$cases = [
    'wrong verifier' => ['code_verifier' => str_repeat('z', 43)],
    'wrong redirect_uri' => ['redirect_uri' => 'https://example.com/other'],
    'another client' => ['client_id' => 'CLIENT_B'],
    'short verifier' => ['code_verifier' => 'too-short'],
];

foreach ($cases as $label => $overrides) {
    $code = ms_ot_seed_code($pdo, $clientA, $userPro);
    $fields = ms_ot_code_fields($code, $clientA);
    if (($overrides['client_id'] ?? '') === 'CLIENT_B') {
        $overrides['client_id'] = $clientB;
    }
    $response = ms_ot_call($mainPort, array_merge($fields, $overrides));
    ms_test_same("4a.{$label}: 400", 400, $response['status']);
    ms_test_same("4b.{$label}: invalid_grant", 'invalid_grant', ms_ot_error($response));
    ms_test_same("4c.{$label}: the code is not consumed", null, ms_ot_scalar($pdo, 'SELECT used_at FROM oauth_authorization_codes WHERE code_hash = ?', [oauthHash($code)]));
}

$expiredCode = ms_ot_seed_code($pdo, $clientA, $userPro, ['expires_at' => date('Y-m-d H:i:s', time() - 5)]);
$response = ms_ot_call($mainPort, ms_ot_code_fields($expiredCode, $clientA));
ms_test_same('4d. an expired code is 400', 400, $response['status']);
ms_test_same('4e. ... with invalid_grant', 'invalid_grant', ms_ot_error($response));

$response = ms_ot_call($mainPort, ms_ot_code_fields(oauthCodeGenerate(), $clientA));
ms_test_same('4f. an unknown code is invalid_grant', 'invalid_grant', ms_ot_error($response));

$response = ms_ot_call($mainPort, ms_ot_code_fields('not-a-code-at-all', $clientA));
ms_test_same('4g. a malformed code is invalid_grant', 'invalid_grant', ms_ot_error($response));

$response = ms_ot_call($mainPort, ms_ot_code_fields($code1, 'not-a-client-id'));
ms_test_same('4h. a malformed client_id is invalid_client', 'invalid_client', ms_ot_error($response));
ms_test_same('4i. ... and answers 401', 401, $response['status']);

$response = ms_ot_call($mainPort, ms_ot_code_fields($code1, str_repeat('a', 32)));
ms_test_same('4j. an unknown client_id is invalid_client', 'invalid_client', ms_ot_error($response));

// A code with a bound resource must be exchanged with that same resource.
$boundCode = ms_ot_seed_code($pdo, $clientA, $userPro, ['resource' => 'https://manjo.me/mcp']);
$response = ms_ot_call($mainPort, ms_ot_code_fields($boundCode, $clientA));
ms_test_same('4k. a bound resource must be sent back', 'invalid_grant', ms_ot_error($response));
$response = ms_ot_call($mainPort, ms_ot_code_fields($boundCode, $clientA, ['resource' => 'https://evil.example/mcp']));
ms_test_same('4l. ... and must match', 'invalid_grant', ms_ot_error($response));
$response = ms_ot_call($mainPort, ms_ot_code_fields($boundCode, $clientA, ['resource' => 'https://manjo.me/mcp']));
ms_test_same('4m. the matching resource is accepted', 200, $response['status']);

// An account that is not (or no longer) entitled.
$regularCode = ms_ot_seed_code($pdo, $clientA, $userRegular);
$response = ms_ot_call($mainPort, ms_ot_code_fields($regularCode, $clientA));
ms_test_same('4n. a non-Pro account cannot exchange a code', 'invalid_grant', ms_ot_error($response));

$suspendedCode = ms_ot_seed_code($pdo, $clientA, $userSuspended);
$response = ms_ot_call($mainPort, ms_ot_code_fields($suspendedCode, $clientA));
ms_test_same('4o. a suspended account cannot exchange a code', 'invalid_grant', ms_ot_error($response));

// ---------------------------------------------------------------------
// 5. A code presented twice revokes what it granted
// ---------------------------------------------------------------------

ms_test_section('5. A replayed code is refused and the grant it made is revoked');

$replayCode = ms_ot_seed_code($pdo, $clientB, $userPro);
$response = ms_ot_call($mainPort, ms_ot_code_fields($replayCode, $clientB));
$body = ms_ot_decoded('5a. the first exchange succeeds', $response);
$replayAccess = (string) ($body['access_token'] ?? '');
$replayRefresh = (string) ($body['refresh_token'] ?? '');
ms_test_check('5b. it issued a working token', mcpTokenResolve($pdo, $replayAccess, time()) !== null);

$response = ms_ot_call($mainPort, ms_ot_code_fields($replayCode, $clientB));
ms_test_same('5c. the second exchange is 400', 400, $response['status']);
ms_test_same('5d. ... with invalid_grant', 'invalid_grant', ms_ot_error($response));

ms_test_check('5e. the access token the code granted no longer resolves', mcpTokenResolve($pdo, $replayAccess, time()) === null);
$response = ms_ot_mcp_call($mainPort, $replayAccess, 'tools/list', 5);
ms_test_same('5f. ... and mcp.php refuses it', 401, $response['status']);
$revokedRow = ms_ot_grant_row($pdo, $replayAccess);
ms_test_check('5g. the grant row carries a revocation timestamp', ($revokedRow['revoked_at'] ?? null) !== null);
ms_test_check('5h. the refresh token it issued is dead too', oauthRefreshTokenExchange($pdo, [
    'grant_type' => 'refresh_token',
    'refresh_token' => $replayRefresh,
    'client_id' => $clientB,
])['ok'] === false);

// ---------------------------------------------------------------------
// 6. Request-shape errors
// ---------------------------------------------------------------------

ms_test_section('6. Missing parameters and the transport errors');

foreach ([
    'no grant_type' => ['code' => oauthCodeGenerate(), 'redirect_uri' => MS_OT_REDIRECT, 'client_id' => $clientA, 'code_verifier' => MS_OT_VERIFIER],
    'no code' => ['grant_type' => 'authorization_code', 'redirect_uri' => MS_OT_REDIRECT, 'client_id' => $clientA, 'code_verifier' => MS_OT_VERIFIER],
    'no redirect_uri' => ['grant_type' => 'authorization_code', 'code' => oauthCodeGenerate(), 'client_id' => $clientA, 'code_verifier' => MS_OT_VERIFIER],
    'no client_id' => ['grant_type' => 'authorization_code', 'code' => oauthCodeGenerate(), 'redirect_uri' => MS_OT_REDIRECT, 'code_verifier' => MS_OT_VERIFIER],
    'no code_verifier' => ['grant_type' => 'authorization_code', 'code' => oauthCodeGenerate(), 'redirect_uri' => MS_OT_REDIRECT, 'client_id' => $clientA],
    'no refresh_token' => ['grant_type' => 'refresh_token', 'client_id' => $clientA],
] as $label => $fields) {
    $response = ms_ot_call($mainPort, $fields);
    ms_test_same("6a.{$label}: 400", 400, $response['status']);
    ms_test_same("6b.{$label}: invalid_request", 'invalid_request', ms_ot_error($response));
}

$response = ms_ot_call($mainPort, ['grant_type' => 'password', 'username' => 'x', 'password' => 'y']);
ms_test_same('6c. an unsupported grant type is 400', 400, $response['status']);
ms_test_same('6d. ... with unsupported_grant_type', 'unsupported_grant_type', ms_ot_error($response));

$response = ms_ot_call($mainPort, [], ['method' => 'GET']);
ms_test_same('6e. a GET is 405', 405, $response['status']);
ms_test_same('6f. ... naming POST', 'POST', $response['headers']['allow'] ?? null);

foreach (['PUT', 'DELETE', 'OPTIONS', 'PATCH'] as $httpMethod) {
    $response = ms_ot_call($mainPort, [], ['method' => $httpMethod]);
    ms_test_same("6g.{$httpMethod}: 405", 405, $response['status']);
}

$response = ms_ot_call($mainPort, ['grant_type' => 'authorization_code'], ['content_type' => 'application/json']);
ms_test_same('6h. a JSON Content-Type is 400', 400, $response['status']);
ms_test_same('6i. ... with invalid_request', 'invalid_request', ms_ot_error($response));

$response = ms_ot_call($mainPort, [], ['content_type' => null]);
ms_test_same('6j. a missing Content-Type is 400', 400, $response['status']);

$response = ms_ot_call($mainPort, [], ['raw' => 'grant_type=authorization_code&pad=' . str_repeat('x', 9000)]);
ms_test_same('6k. a body over 8 KB is 400', 400, $response['status']);
ms_test_same('6l. ... with invalid_request', 'invalid_request', ms_ot_error($response));

// ---------------------------------------------------------------------
// 7. Refresh: rotation, narrowing, and the re-use detection
// ---------------------------------------------------------------------

ms_test_section('7. refresh_token: rotation, narrowing and re-use detection');

$fields = ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userPro), $clientA);
$body = ms_ot_decoded('7a. a fresh grant', ms_ot_call($mainPort, $fields));
$accessA = (string) ($body['access_token'] ?? '');
$refreshA = (string) ($body['refresh_token'] ?? '');

$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshA, 'client_id' => $clientA]);
ms_test_same('7b. the refresh answers 200', 200, $response['status']);
$body = ms_ot_decoded('7c. ... with a JSON body', $response);
$accessA2 = (string) ($body['access_token'] ?? '');
$refreshA2 = (string) ($body['refresh_token'] ?? '');
ms_test_same('7d. a new access token is issued', false, $accessA2 === $accessA);
ms_test_same('7e. a new refresh token is issued', false, $refreshA2 === $refreshA);
ms_test_same('7f. the scope is unchanged', 'mcp:read mcp:write', $body['scope'] ?? null);
ms_test_same('7g. expires_in is still an hour', 3600, $body['expires_in'] ?? null);
ms_test_check('7h. the old access token stops working', mcpTokenResolve($pdo, $accessA, time()) === null);
ms_test_check('7i. the new one works', mcpTokenResolve($pdo, $accessA2, time()) !== null);

$row = ms_ot_grant_row($pdo, $accessA2);
ms_test_same('7j. the row kept its identity as the grant', $clientA, $row['oauth_client_id'] ?? null);
ms_test_same('7k. ... remembers the previous refresh token by hash', oauthHash($refreshA), $row['rotated_refresh_hash'] ?? null);
ms_test_same('7l. ... and holds only the new one as live', oauthHash($refreshA2), $row['refresh_token_hash'] ?? null);
ms_test_check('7m. ... with the rotation stamped', ($row['rotated_at'] ?? null) !== null);
$refreshIn = strtotime((string) $row['refresh_expires_at']) - time();
ms_test_check('7n. the refresh window slid forward', $refreshIn > 90 * 86400 - 120, 'expires in ' . $refreshIn);

// The previous refresh token is a credential the client has already spent.
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshA, 'client_id' => $clientA]);
ms_test_same('7o. replaying the rotated refresh token is 400', 400, $response['status']);
ms_test_same('7p. ... with invalid_grant', 'invalid_grant', ms_ot_error($response));
ms_test_check('7q. ... and revokes the whole grant', mcpTokenResolve($pdo, $accessA2, time()) === null);
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshA2, 'client_id' => $clientA]);
ms_test_same('7r. the live refresh token is dead as well', 'invalid_grant', ms_ot_error($response));

// Narrowing is allowed; widening is not.
$body = ms_ot_decoded('7s. a grant to narrow', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userPro), $clientA)));
$narrowRefresh = (string) ($body['refresh_token'] ?? '');
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $narrowRefresh, 'client_id' => $clientA, 'scope' => 'mcp:read']);
ms_test_same('7t. narrowing to mcp:read is allowed', 200, $response['status']);
$body = ms_ot_decoded('7u. ... and reported back', $response);
ms_test_same('7v. ... as the narrower scope', 'mcp:read', $body['scope'] ?? null);
$narrowedRefresh = (string) ($body['refresh_token'] ?? '');
ms_test_same('7w. ... and stored as read only', 'read', ms_ot_grant_row($pdo, (string) $body['access_token'])['scopes'] ?? null);

$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $narrowedRefresh, 'client_id' => $clientA, 'scope' => 'mcp:read mcp:write']);
ms_test_same('7x. widening back to write is 400', 400, $response['status']);
ms_test_same('7y. ... with invalid_scope', 'invalid_scope', ms_ot_error($response));

$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $narrowedRefresh, 'client_id' => $clientA, 'scope' => 'mcp:admin']);
ms_test_same('7z. an unknown scope is invalid_scope', 'invalid_scope', ms_ot_error($response));

// A refresh token belongs to one client.
$otherRefresh = (string) (ms_ot_decoded('7aa. another grant', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientB, $userPro), $clientB)))['refresh_token'] ?? '');
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $otherRefresh, 'client_id' => $clientA]);
ms_test_same('7ab. another client cannot refresh this grant', 'invalid_grant', ms_ot_error($response));

$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => 'msr_' . str_repeat('a', 64), 'client_id' => $clientA]);
ms_test_same('7ac. an unknown refresh token is invalid_grant', 'invalid_grant', ms_ot_error($response));

$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => 'not-a-token', 'client_id' => $clientA]);
ms_test_same('7ad. a malformed refresh token is invalid_grant', 'invalid_grant', ms_ot_error($response));

// ---------------------------------------------------------------------
// 8. Refresh after the account lost Pro or was suspended
// ---------------------------------------------------------------------

ms_test_section('8. An account that lost Pro, or is suspended, cannot refresh');

$body = ms_ot_decoded('8a. a grant for the Pro account', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userPro), $clientA)));
$proRefresh = (string) ($body['refresh_token'] ?? '');
$pdo->prepare('UPDATE pro_users SET account_type = ? WHERE id = ?')->execute(['regular', $userPro]);
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $proRefresh, 'client_id' => $clientA]);
ms_test_same('8b. a lapsed account cannot refresh', 'invalid_grant', ms_ot_error($response));
$pdo->prepare('UPDATE pro_users SET account_type = ? WHERE id = ?')->execute(['pro', $userPro]);
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $proRefresh, 'client_id' => $clientA]);
ms_test_same('8c. restoring Pro lets the same grant refresh again', 200, $response['status']);
$proRefresh2 = (string) (ms_ot_decoded('8d. ... with a new refresh token', $response)['refresh_token'] ?? '');

$pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $userPro]);
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $proRefresh2, 'client_id' => $clientA]);
ms_test_same('8e. a suspended account cannot refresh', 'invalid_grant', ms_ot_error($response));
$pdo->prepare('UPDATE pro_users SET suspended_at = NULL WHERE id = ?')->execute([$userPro]);
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $proRefresh2, 'client_id' => $clientA]);
ms_test_same('8f. lifting the suspension lets it refresh again', 200, $response['status']);

// ---------------------------------------------------------------------
// 9. One grant per (user, client), and the cap
// ---------------------------------------------------------------------

ms_test_section('9. Authorising again replaces the previous grant');

$pdo->prepare('DELETE FROM mcp_access_tokens WHERE pro_user_id = ? AND oauth_client_id IS NOT NULL')->execute([$userRegular]);
$pdo->prepare('UPDATE pro_users SET account_type = ? WHERE id = ?')->execute(['pro', $userRegular]);
$body = ms_ot_decoded('9a. a first grant', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userRegular), $clientA)));
$firstAccess = (string) ($body['access_token'] ?? '');
$body = ms_ot_decoded('9b. a second authorisation of the same client', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userRegular), $clientA)));
$secondAccess = (string) ($body['access_token'] ?? '');

ms_test_same('9c. only one grant row is left for the pair', 1, (int) ms_ot_scalar($pdo, 'SELECT COUNT(*) FROM mcp_access_tokens WHERE pro_user_id = ? AND oauth_client_id = ?', [$userRegular, $clientA]));
ms_test_check('9d. the first token was replaced', mcpTokenResolve($pdo, $firstAccess, time()) === null);
ms_test_check('9e. the second works', mcpTokenResolve($pdo, $secondAccess, time()) !== null);

// The cap: eleven distinct clients, the eleventh refused.
$cappedUser = ms_test_seed_user($pdo, 'capped@example.com', 'pro');
for ($i = 1; $i <= 10; $i++) {
    $client = ms_ot_seed_client($pdo, 'Cap client ' . $i);
    $fields = ms_ot_code_fields(ms_ot_seed_code($pdo, $client, $cappedUser), $client);
    $response = ms_ot_call($mainPort, $fields);
    ms_test_same("9f. grant {$i} of 10 is issued", 200, $response['status']);
}
$eleventh = ms_ot_seed_client($pdo, 'Cap client 11');
$response = ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $eleventh, $cappedUser), $eleventh));
ms_test_same('9g. the eleventh grant is refused', 'invalid_grant', ms_ot_error($response));
ms_test_check('9h. ... and no row was written', oauthGrantCount($pdo, $cappedUser) === 10);

// ---------------------------------------------------------------------
// 10. /oauth/revoke
// ---------------------------------------------------------------------

ms_test_section('10. Revocation revokes the grant, and says nothing either way');

$body = ms_ot_decoded('10a. a grant to revoke by access token', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userPro), $clientA)));
$revokeAccess = (string) ($body['access_token'] ?? '');
$revokeRefresh = (string) ($body['refresh_token'] ?? '');
$response = ms_ot_call($mainPort, ['token' => $revokeAccess, 'client_id' => $clientA], ['path' => '/oauth_revoke.php']);
ms_test_same('10b. revoking by access token answers 200', 200, $response['status']);
ms_test_same('10c. ... with an empty body', '', trim($response['body']));
ms_test_check('10d. the grant is revoked', mcpTokenResolve($pdo, $revokeAccess, time()) === null);
$response = ms_ot_call($mainPort, ['grant_type' => 'refresh_token', 'refresh_token' => $revokeRefresh, 'client_id' => $clientA]);
ms_test_same('10e. ... refresh token included', 'invalid_grant', ms_ot_error($response));

$body = ms_ot_decoded('10f. a grant to revoke by refresh token', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userPro), $clientA)));
$revokeAccess2 = (string) ($body['access_token'] ?? '');
$revokeRefresh2 = (string) ($body['refresh_token'] ?? '');
$response = ms_ot_call($mainPort, ['token' => $revokeRefresh2, 'client_id' => $clientA], ['path' => '/oauth_revoke.php']);
ms_test_same('10g. revoking by refresh token answers 200', 200, $response['status']);
ms_test_check('10h. ... and kills the access token too', mcpTokenResolve($pdo, $revokeAccess2, time()) === null);

$body = ms_ot_decoded('10i. a grant that must survive', ms_ot_call($mainPort, ms_ot_code_fields(ms_ot_seed_code($pdo, $clientA, $userPro), $clientA)));
$survivor = (string) ($body['access_token'] ?? '');
$response = ms_ot_call($mainPort, ['token' => 'msk_' . str_repeat('b', 64), 'client_id' => $clientA], ['path' => '/oauth_revoke.php']);
ms_test_same('10j. an unknown token still answers 200', 200, $response['status']);
$response = ms_ot_call($mainPort, ['token' => 'nonsense', 'client_id' => $clientA], ['path' => '/oauth_revoke.php']);
ms_test_same('10k. a malformed token still answers 200', 200, $response['status']);
$response = ms_ot_call($mainPort, ['token' => $survivor, 'client_id' => $clientB], ['path' => '/oauth_revoke.php']);
ms_test_same('10l. another client\'s token still answers 200', 200, $response['status']);
ms_test_check('10m. ... but revokes nothing', mcpTokenResolve($pdo, $survivor, time()) !== null);
$response = ms_ot_call($mainPort, ['token' => $survivor], ['path' => '/oauth_revoke.php']);
ms_test_same('10n. no client_id at all still answers 200', 200, $response['status']);
ms_test_check('10o. ... and revokes nothing', mcpTokenResolve($pdo, $survivor, time()) !== null);

// A manual token (#320) is not reachable through this endpoint at all.
$manualToken = ms_ot_seed_manual_token($pdo, $userPro);
$response = ms_ot_call($mainPort, ['token' => $manualToken, 'client_id' => $clientA], ['path' => '/oauth_revoke.php']);
ms_test_same('10p. a manual token answers 200 like everything else', 200, $response['status']);
ms_test_check('10q. ... and is not revoked', mcpTokenResolve($pdo, $manualToken, time()) !== null);

$response = ms_ot_call($mainPort, ['token' => $survivor, 'client_id' => $clientA], ['path' => '/oauth_revoke.php', 'method' => 'GET']);
ms_test_same('10r. a GET on /oauth/revoke is 405', 405, $response['status']);
ms_test_same('10s. ... naming POST', 'POST', $response['headers']['allow'] ?? null);
$response = ms_ot_call($mainPort, ['token' => $survivor, 'client_id' => $clientA], ['path' => '/oauth_revoke.php', 'content_type' => 'application/json']);
ms_test_same('10t. a JSON Content-Type on /oauth/revoke is 400', 400, $response['status']);

// ---------------------------------------------------------------------
// 11. The per-IP rate limit
// ---------------------------------------------------------------------

ms_test_section('11. The per-IP rate limit answers 429 with Retry-After');

$rateProbe = ms_ot_build_probe($msRepoRoot, ['oauth_token_ip_hour' => 3, 'oauth_token_ip_day' => 1000]);
$rateServer = ms_ot_start_server($rateProbe);
$ratePort = $rateServer[1];
// A registered client in this probe too, so the requests that are served get
// as far as the exchange itself — the limit runs before it.
$ratePdo = ms_test_db(ms_test_probe_sqlite($rateProbe));
$rateClient = ms_ot_seed_client($ratePdo, 'Client A');

for ($i = 1; $i <= 3; $i++) {
    $response = ms_ot_call($ratePort, ms_ot_code_fields(oauthCodeGenerate(), $rateClient));
    ms_test_same("11a. request {$i} of 3 is served", 400, $response['status']);
}
$response = ms_ot_call($ratePort, ms_ot_code_fields(oauthCodeGenerate(), $rateClient));
ms_test_same('11b. the request after the limit is 429', 429, $response['status']);
$retryAfter = (int) ($response['headers']['retry-after'] ?? 0);
ms_test_check('11c. ... with a Retry-After that points at the end of the window', $retryAfter >= 1 && $retryAfter <= 3600, 'Retry-After: ' . $retryAfter);
ms_test_same('11d. ... and temporarily_unavailable', 'temporarily_unavailable', ms_ot_error($response));

$counted = (int) ms_ot_scalar($ratePdo, "SELECT COALESCE(SUM(hits), 0) FROM abuse_counters WHERE scope = 'oauth_token_ip'");
ms_test_check('11e. every attempt was counted, the refused one included', $counted >= 4, 'counted: ' . $counted);
ms_test_check('11f. the counter is keyed on a hash, never the address', (int) ms_ot_scalar($ratePdo, "SELECT COUNT(*) FROM abuse_counters WHERE subject LIKE '%127.0.0.1%'") === 0);

// ---------------------------------------------------------------------
// 12. Fail closed before the migration has run
// ---------------------------------------------------------------------

ms_test_section('12. Before the migration the endpoint answers "not available"');

$freshProbe = ms_ot_build_probe($msRepoRoot, $generousLimits, false);
$freshServer = ms_ot_start_server($freshProbe);
$freshPort = $freshServer[1];

$response = ms_ot_call($freshPort, ms_ot_code_fields(oauthCodeGenerate(), $clientA));
ms_test_same('12a. an exchange before the migration is 503', 503, $response['status']);
ms_test_same('12b. ... with temporarily_unavailable', 'temporarily_unavailable', ms_ot_error($response));
$response = ms_ot_call($freshPort, ['token' => 'msk_' . str_repeat('c', 64), 'client_id' => $clientA], ['path' => '/oauth_revoke.php']);
ms_test_same('12c. revocation before the migration is still 200 (nothing to revoke)', 200, $response['status']);

// ---------------------------------------------------------------------
// 13. What must never reach the log, and the deploy wiring
// ---------------------------------------------------------------------

ms_test_section('13. No token, code or verifier in a log, and the routes are wired up');

$sources = [
    'oauth_token.php' => (string) file_get_contents($msRepoRoot . '/oauth_token.php'),
    'oauth_revoke.php' => (string) file_get_contents($msRepoRoot . '/oauth_revoke.php'),
    'oauth_server.php' => (string) file_get_contents($msRepoRoot . '/oauth_server.php'),
];

$badLogs = [];
foreach ($sources as $file => $text) {
    if (preg_match_all('/logMessage\((?:[^();]|\([^()]*\))*\)/s', $text, $matches)) {
        foreach ($matches[0] as $call) {
            if (preg_match('/\$(token|code|verifier|refreshToken|accessToken|rawBody|params|result)\b/i', $call)
                || preg_match('/\'(token|code|verifier|refresh_token|access_token|code_verifier)\'\s*=>/i', $call)) {
                $badLogs[] = $file . ': ' . preg_replace('/\s+/', ' ', mb_substr($call, 0, 140));
            }
        }
    }
    if (str_contains($text, 'error_log(')) {
        $badLogs[] = $file . ': uses error_log()';
    }
}
ms_test_check('13a. no log call is handed a token, a code or a verifier', $badLogs === [], implode("\n       ", $badLogs));

$tokenSource = $sources['oauth_token.php'];
ms_test_check(
    '13b. the exchange INFO line names the grant type, the outcome and the ids',
    str_contains($tokenSource, "logMessage('INFO', 'OAuth token exchange',")
        && str_contains($tokenSource, "'grant_type' => \$grantType")
        && str_contains($tokenSource, "'outcome' => \$outcome")
        && str_contains($tokenSource, "'token_id' => \$logTokenId")
        && str_contains($tokenSource, "'client_id' => \$logClientId")
);
ms_test_check(
    '13c. a re-used code or refresh token raises a WARNING',
    str_contains($tokenSource, "logMessage('WARNING', 'OAuth credential presented twice; the grant was revoked'")
);
ms_test_check(
    '13d. oauth_server.php never logs anything itself',
    preg_match('/logMessage\(|error_log\(/', $sources['oauth_server.php']) !== 1
);

$htaccess = (string) file_get_contents($msRepoRoot . '/.htaccess');
ms_test_check('13e. .htaccess rewrites /oauth/token to the endpoint', str_contains($htaccess, 'RewriteRule ^oauth/token/?$ oauth_token.php'));
ms_test_check('13f. .htaccess rewrites /oauth/revoke to the endpoint', str_contains($htaccess, 'RewriteRule ^oauth/revoke/?$ oauth_revoke.php'));
ms_test_check('13g. robots.txt disallows /oauth/', str_contains((string) file_get_contents($msRepoRoot . '/robots.txt'), 'Disallow: /oauth/'));

// ---------------------------------------------------------------------
// 14. Nothing tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('14. The endpoints answer without a PHP warning, notice or fatal');

foreach ([
    'main probe' => $mainServer,
    'rate-limit probe' => $rateServer,
    'pre-migration probe' => $freshServer,
] as $label => $server) {
    $noise = [];
    foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
        if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
            $noise[] = trim($line);
        }
    }
    ms_test_check("14.{$label}: no PHP warning, notice or fatal", $noise === [], implode(' | ', array_slice($noise, 0, 3)));
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_ot_stop_server($mainServer);
ms_ot_stop_server($rateServer);
ms_ot_stop_server($freshServer);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($mainProbe);
    ms_test_cleanup($rateProbe);
    ms_test_cleanup($freshProbe);
} else {
    echo "Probe docroots left in place for inspection: {$mainProbe}, {$rateProbe}, {$freshProbe}\n";
}
exit($exitCode);
