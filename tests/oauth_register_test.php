<?php

declare(strict_types=1);

/**
 * Regression coverage for OAuth dynamic client registration (#331 step 1/6,
 * oauth_register.php, oauth_server.php).
 *
 * Run with:  php tests/oauth_register_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network are
 * needed: it reuses the throwaway-docroot harness in
 * tests/lib/pushover_harness.php (the same pattern tests/mcp_endpoint_test.php
 * uses), copies oauth_register.php and oauth_server.php into that docroot and
 * drives the real endpoint through PHP's built-in web server — the one SAPI
 * oauth_register.php runs under (it refuses CLI) — against a SQLite database it
 * lays the OAuth and abuse-guard tables onto itself, with a tiny router script
 * standing in for the .htaccess rewrite the built-in server ignores.
 *
 * What is checked is the endpoint's own behaviour: a valid public client, every
 * redirect-URI rule, the metadata and transport errors with their RFC codes,
 * the rate limit and the global cap, the fail-closed "not available" path
 * before the migration, and the one thing that must never happen — a redirect
 * URI reaching the log.
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

// ---------------------------------------------------------------------
// Schema + docroot helpers (kept local to this suite)
// ---------------------------------------------------------------------

/**
 * DDL on top of ms_test_schema(): the two tables migrate_oauth.php creates,
 * mcp_access_tokens with the OAuth columns it adds, and the abuse guard's
 * three tables (so abuseGuardAvailable() is true in the probes that switch the
 * per-IP limit on).
 */
function ms_oauth_extra_schema(): string
{
    return <<<'SQL'
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
CREATE UNIQUE INDEX uniq_mcp_access_tokens_refresh ON mcp_access_tokens (refresh_token_hash);

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

function ms_oauth_apply_schema(PDO $pdo): void
{
    foreach (explode(';', ms_oauth_extra_schema()) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/**
 * A router stand-in for the .htaccess rewrite `^oauth/register/?$` the
 * built-in server does not apply.
 */
function ms_oauth_router_php(): string
{
    return <<<'PHP'
<?php
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/oauth/register' || $path === '/oauth/register/') {
    require __DIR__ . '/oauth_register.php';
    return true;
}
http_response_code(404);
header('Content-Type: text/plain');
echo "not found";
return true;
PHP;
}

/**
 * Copy the endpoint and its library into a docroot ms_test_probe_build()
 * already built, append the trial key (so oauthIpHash() has a key to derive
 * from) and the abuse settings this suite wants, and drop in the router.
 */
function ms_oauth_prepare_probe(string $repoRoot, string $probe, array $abuse): void
{
    foreach (['oauth_register.php', 'oauth_server.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    $extra = "\n// --- added by tests/oauth_register_test.php -------------------------\n"
        . '$config[\'trial\'] = [\'hash_key\' => str_repeat(\'k\', 32)];' . "\n"
        . '$config[\'abuse\'] = ' . var_export($abuse, true) . ';' . "\n";

    if (file_put_contents($probe . '/config.php', ms_test_stub_config_php() . $extra) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }
    if (file_put_contents($probe . '/ms_oauth_router.php', ms_oauth_router_php()) === false) {
        throw new RuntimeException('Could not write the router script');
    }
}

/** Build a probe docroot, optionally with the OAuth schema, and return its path. */
function ms_oauth_build_probe(string $repoRoot, array $abuse, bool $withOauth = true): string
{
    $probe = ms_test_probe_build($repoRoot);
    ms_oauth_prepare_probe($repoRoot, $probe, $abuse);
    if ($withOauth) {
        ms_oauth_apply_schema(ms_test_db(ms_test_probe_sqlite($probe)));
    }
    return $probe;
}

// ---------------------------------------------------------------------
// The built-in web server + HTTP client
// ---------------------------------------------------------------------

function ms_oauth_start_server(string $root): array
{
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL', '-S', "127.0.0.1:{$port}", '-t', $root, $root . '/ms_oauth_router.php'],
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

function ms_oauth_stop_server(array $server): void
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
function ms_oauth_request_raw(int $port, string $method, string $path, array $headers, string $requestBody): array
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
 * POST a client-metadata document to /oauth/register.
 *
 * @param array{payload?:array|string|null, content_type?:?string, method?:string,
 *              path?:string, headers?:array<string,string>} $opts
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function ms_oauth_call(int $port, array $opts = []): array
{
    $headers = [];
    $contentType = array_key_exists('content_type', $opts) ? $opts['content_type'] : 'application/json';
    if ($contentType !== null) {
        $headers['Content-Type'] = (string) $contentType;
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

    return ms_oauth_request_raw(
        $port,
        (string) ($opts['method'] ?? 'POST'),
        (string) ($opts['path'] ?? '/oauth/register'),
        $headers,
        $requestBody
    );
}

/** Decode a response body, reporting a body that is not JSON at all. */
function ms_oauth_decoded(string $label, array $response): ?array
{
    $decoded = json_decode($response['body'], true);
    ms_test_check(
        $label,
        is_array($decoded),
        'status ' . $response['status'] . ', body: ' . substr($response['body'], 0, 200)
    );
    return is_array($decoded) ? $decoded : null;
}

/** The RFC 7591 error code of a response, or null. */
function ms_oauth_error(array $response): ?string
{
    $decoded = json_decode($response['body'], true);
    return is_array($decoded) && isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null;
}

// ---------------------------------------------------------------------
// Docroots + database
// ---------------------------------------------------------------------

echo "Mail Shield — OAuth client registration (#331)\n";

$mainProbe = ms_oauth_build_probe($msRepoRoot, ['enabled' => false]);
$mainServer = ms_oauth_start_server($mainProbe);
$mainPort = $mainServer[1];
$mainPdo = ms_test_db(ms_test_probe_sqlite($mainProbe));

// ---------------------------------------------------------------------
// 1. A valid public client
// ---------------------------------------------------------------------

ms_test_section('1. A valid public client is registered');

$response = ms_oauth_call($mainPort, ['payload' => [
    'client_name' => 'Test Client',
    'redirect_uris' => ['https://example.com/cb'],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => 'none',
    'scope' => 'mcp:read mcp:write',
]]);
ms_test_same('1a. a valid registration is 201', 201, $response['status']);
ms_test_same('1b. the answer is JSON', true, str_contains((string) ($response['headers']['content-type'] ?? ''), 'application/json'));
ms_test_same('1c. the answer is not cached', 'no-store', $response['headers']['cache-control'] ?? null);

$body = ms_oauth_decoded('1d. ... with a JSON body', $response);
$clientId = is_array($body) ? (string) ($body['client_id'] ?? '') : '';
ms_test_same('1e. client_id is 32 lowercase hex characters', 1, preg_match('/^[a-f0-9]{32}$/', $clientId));
ms_test_check('1f. client_id_issued_at is an integer timestamp', is_int($body['client_id_issued_at'] ?? null) && ($body['client_id_issued_at'] ?? 0) > 1600000000);
ms_test_same('1g. redirect_uris is echoed back', ['https://example.com/cb'], $body['redirect_uris'] ?? null);
ms_test_same('1h. client_name is kept', 'Test Client', $body['client_name'] ?? null);
ms_test_same('1i. grant_types is kept', ['authorization_code', 'refresh_token'], $body['grant_types'] ?? null);
ms_test_same('1j. response_types is code', ['code'], $body['response_types'] ?? null);
ms_test_same('1k. token_endpoint_auth_method is none', 'none', $body['token_endpoint_auth_method'] ?? null);
ms_test_check('1l. no secret is issued', !isset($body['client_secret']));
ms_test_check('1m. scope is not echoed as a stored field', !array_key_exists('scope', is_array($body) ? $body : []));

$mainPdo = ms_test_refresh_db(ms_test_probe_sqlite($mainProbe));
$stmt = $mainPdo->prepare('SELECT client_name, redirect_uris, registered_ip_hash FROM oauth_clients WHERE client_id = ?');
$stmt->execute([$clientId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
ms_test_check('1n. the client is stored', is_array($row));
ms_test_same('1o. ... with its redirect_uris as a JSON array', ['https://example.com/cb'], json_decode((string) ($row['redirect_uris'] ?? '[]'), true));
ms_test_check('1p. ... and the IP stored only as a keyed hash', is_string($row['registered_ip_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/', (string) $row['registered_ip_hash']) === 1);

$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => ['https://example.com/cb']]]);
ms_test_same('1q. a client with no client_name is still registered', 201, $response['status']);
$body = ms_oauth_decoded('1r. ... with a JSON body', $response);
ms_test_same('1s. ... named "Unnamed app"', 'Unnamed app', $body['client_name'] ?? null);
ms_test_same('1t. ... and the default grant type', ['authorization_code'], $body['grant_types'] ?? null);

// ---------------------------------------------------------------------
// 2. Redirect-URI rules (pure function, then through the endpoint)
// ---------------------------------------------------------------------

ms_test_section('2. Every redirect-URI rule');

foreach ([
    'https any host' => 'https://example.com/cb',
    'https with a port and a query' => 'https://example.com:8443/cb?x=1',
    'http on 127.0.0.1' => 'http://127.0.0.1:8080/cb',
    'http on localhost' => 'http://localhost/cb',
    'http on [::1]' => 'http://[::1]:1234/cb',
    'a cursor custom scheme' => 'cursor://anysphere.cursor-retrieval/callback',
    'a vscode custom scheme' => 'vscode://foo/bar',
    'a reverse-dns scheme with a path' => 'com.example.app:/oauth2redirect',
] as $label => $uri) {
    ms_test_same("2a. accepts {$label}", true, oauthRedirectUriValid($uri));
}

foreach ([
    'javascript:' => 'javascript:alert(1)',
    'data:' => 'data:text/html,hi',
    'file:' => 'file:///etc/passwd',
    'an http look-alike scheme' => 'httpx://example.com/cb',
    'a fragment' => 'https://example.com/cb#frag',
    'an empty fragment' => 'https://example.com/cb#',
    'http on a non-loopback host' => 'http://evil.example/cb',
    'userinfo' => 'https://user@example.com/cb',
    'a relative path' => '/cb',
    'a protocol-relative URL' => '//example.com/cb',
    'no host' => 'https://',
    'an empty string' => '',
    'a space' => 'https://example.com/c b',
    'a bare custom scheme' => 'vscode://',
] as $label => $uri) {
    ms_test_same("2b. rejects {$label}", false, oauthRedirectUriValid($uri));
}

ms_test_same('2c. rejects a URI over 512 characters', false, oauthRedirectUriValid('https://example.com/' . str_repeat('a', 520)));

// Through the endpoint: a rejected URI is invalid_redirect_uri (400).
$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => ['javascript:alert(1)']]]);
ms_test_same('2d. a javascript: redirect over HTTP is 400', 400, $response['status']);
ms_test_same('2e. ... with invalid_redirect_uri', 'invalid_redirect_uri', ms_oauth_error($response));

$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => ['http://evil.example/cb']]]);
ms_test_same('2f. http on a non-loopback host is refused', 'invalid_redirect_uri', ms_oauth_error($response));

$six = [];
for ($i = 1; $i <= 6; $i++) {
    $six[] = "https://example.com/cb{$i}";
}
$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => $six]]);
ms_test_same('2g. six redirect URIs are refused', 'invalid_redirect_uri', ms_oauth_error($response));

$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => []]]);
ms_test_same('2h. an empty redirect_uris array is refused', 'invalid_redirect_uri', ms_oauth_error($response));

$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => 'https://example.com/cb']]);
ms_test_same('2i. a redirect_uris that is not an array is refused', 'invalid_redirect_uri', ms_oauth_error($response));

$response = ms_oauth_call($mainPort, ['payload' => ['redirect_uris' => [123]]]);
ms_test_same('2j. a non-string redirect URI is refused', 'invalid_redirect_uri', ms_oauth_error($response));

// ---------------------------------------------------------------------
// 3. client_name sanitisation
// ---------------------------------------------------------------------

ms_test_section('3. client_name is sanitised, never trusted as sent');

ms_test_same('3a. an over-long name is cut to 64 characters', 64, mb_strlen(oauthClientNameSanitise(str_repeat('x', 100))));
ms_test_same('3b. control characters are stripped', 'EvilName', oauthClientNameSanitise("Evil\n\tName"));
ms_test_same('3c. a whitespace-only name falls back', 'Unnamed app', oauthClientNameSanitise("   \n\t "));
ms_test_same('3d. a non-string name falls back', 'Unnamed app', oauthClientNameSanitise(12345));

$response = ms_oauth_call($mainPort, ['payload' => ['client_name' => str_repeat('y', 100), 'redirect_uris' => ['https://example.com/cb']]]);
$body = ms_oauth_decoded('3e. the endpoint returns a JSON body', $response);
ms_test_same('3f. ... with the name cut to 64 characters', 64, mb_strlen((string) ($body['client_name'] ?? '')));

// ---------------------------------------------------------------------
// 4. Metadata rules
// ---------------------------------------------------------------------

ms_test_section('4. Metadata errors are invalid_client_metadata');

$validRedirect = ['redirect_uris' => ['https://example.com/cb']];

foreach ([
    'client_secret_basic auth method' => ['token_endpoint_auth_method' => 'client_secret_basic'],
    'client_secret_post auth method' => ['token_endpoint_auth_method' => 'client_secret_post'],
    'an unknown grant type' => ['grant_types' => ['client_credentials']],
    'an empty grant_types array' => ['grant_types' => []],
    'a grant_types that is not an array' => ['grant_types' => 'authorization_code'],
    'a non-code response type' => ['response_types' => ['token']],
    'code plus token response types' => ['response_types' => ['code', 'token']],
    'an empty response_types array' => ['response_types' => []],
] as $label => $extra) {
    $response = ms_oauth_call($mainPort, ['payload' => $extra + $validRedirect]);
    ms_test_same("4a.{$label}: 400", 400, $response['status']);
    ms_test_same("4b.{$label}: invalid_client_metadata", 'invalid_client_metadata', ms_oauth_error($response));
}

// ---------------------------------------------------------------------
// 5. Transport-level failures
// ---------------------------------------------------------------------

ms_test_section('5. Method, Content-Type, body size and JSON');

$response = ms_oauth_call($mainPort, ['method' => 'GET', 'payload' => null]);
ms_test_same('5a. a GET is 405', 405, $response['status']);
ms_test_same('5b. ... naming POST', 'POST', $response['headers']['allow'] ?? null);

foreach (['PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD'] as $httpMethod) {
    $response = ms_oauth_call($mainPort, ['method' => $httpMethod, 'payload' => null]);
    ms_test_same("5c.{$httpMethod}: 405", 405, $response['status']);
}

$response = ms_oauth_call($mainPort, ['content_type' => 'text/plain', 'payload' => ['redirect_uris' => ['https://example.com/cb']]]);
ms_test_same('5d. a non-JSON Content-Type is 415', 415, $response['status']);
ms_test_same('5e. ... with invalid_client_metadata', 'invalid_client_metadata', ms_oauth_error($response));

$response = ms_oauth_call($mainPort, ['content_type' => null, 'payload' => ['redirect_uris' => ['https://example.com/cb']]]);
ms_test_same('5f. a missing Content-Type is 415', 415, $response['status']);

$oversized = '{"redirect_uris":["https://example.com/cb"],"pad":"' . str_repeat('x', 9000) . '"}';
$response = ms_oauth_call($mainPort, ['payload' => $oversized]);
ms_test_same('5g. a body over 8 KB is 413', 413, $response['status']);

$response = ms_oauth_call($mainPort, ['payload' => '{"redirect_uris":']);
ms_test_same('5h. invalid JSON is 400', 400, $response['status']);
ms_test_same('5i. ... with invalid_client_metadata', 'invalid_client_metadata', ms_oauth_error($response));

$response = ms_oauth_call($mainPort, ['payload' => '[]']);
ms_test_same('5j. a JSON array is 400', 400, $response['status']);

$response = ms_oauth_call($mainPort, ['payload' => '"just a string"']);
ms_test_same('5k. a JSON scalar is 400', 400, $response['status']);

$response = ms_oauth_call($mainPort, ['payload' => new stdClass()]);
ms_test_same('5l. an object with no redirect_uris is 400', 400, $response['status']);
ms_test_same('5m. ... with invalid_redirect_uri', 'invalid_redirect_uri', ms_oauth_error($response));

// ---------------------------------------------------------------------
// 6. Unknown fields are ignored, never stored
// ---------------------------------------------------------------------

ms_test_section('6. Unknown fields are ignored, never stored');

$response = ms_oauth_call($mainPort, ['payload' => [
    'client_name' => 'Known',
    'redirect_uris' => ['https://example.com/cb'],
    'evil' => 'surprise',
    'nested' => ['a' => 1],
    'grant_types_extra' => 'x',
]]);
ms_test_same('6a. extra fields do not break a valid registration', 201, $response['status']);
$body = ms_oauth_decoded('6b. ... with a JSON body', $response);
ms_test_check('6c. ... that carries none of the unknown fields', !isset($body['evil']) && !isset($body['nested']) && !isset($body['grant_types_extra']));

$extraClientId = (string) ($body['client_id'] ?? '');
$mainPdo = ms_test_refresh_db(ms_test_probe_sqlite($mainProbe));
$stmt = $mainPdo->prepare('SELECT client_name, redirect_uris FROM oauth_clients WHERE client_id = ?');
$stmt->execute([$extraClientId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
ms_test_same('6d. the stored row holds only the known fields', 'Known', $row['client_name'] ?? null);
ms_test_same('6e. ... and the redirect_uris it was given', ['https://example.com/cb'], json_decode((string) ($row['redirect_uris'] ?? '[]'), true));

// ---------------------------------------------------------------------
// 7. The per-IP rate limit
// ---------------------------------------------------------------------

ms_test_section('7. The per-IP rate limit answers 429 with Retry-After');

$rateProbe = ms_oauth_build_probe($msRepoRoot, [
    'enabled' => true,
    'oauth_register_ip_hour' => 3,
    'oauth_register_ip_day' => 100,
    'oauth_max_clients' => 1000,
]);
$rateServer = ms_oauth_start_server($rateProbe);
$ratePort = $rateServer[1];

for ($i = 1; $i <= 3; $i++) {
    $response = ms_oauth_call($ratePort, ['payload' => ['redirect_uris' => ["https://example.com/cb{$i}"]]]);
    ms_test_same("7a. registration {$i} of 3 is served", 201, $response['status']);
}
$response = ms_oauth_call($ratePort, ['payload' => ['redirect_uris' => ['https://example.com/cb4']]]);
ms_test_same('7b. the registration after the limit is 429', 429, $response['status']);
$retryAfter = (int) ($response['headers']['retry-after'] ?? 0);
ms_test_check('7c. ... with a Retry-After that points at the end of the window', $retryAfter >= 1 && $retryAfter <= 3600, 'Retry-After: ' . $retryAfter);
ms_test_same('7d. ... and temporarily_unavailable', 'temporarily_unavailable', ms_oauth_error($response));

$ratePdo = ms_test_refresh_db(ms_test_probe_sqlite($rateProbe));
$counted = (int) $ratePdo->query("SELECT COALESCE(SUM(hits), 0) FROM abuse_counters WHERE scope = 'oauth_reg_ip'")->fetchColumn();
ms_test_check('7e. every attempt was counted, the refused one included', $counted >= 4, 'counted: ' . $counted);

// ---------------------------------------------------------------------
// 8. The global cap on stored clients
// ---------------------------------------------------------------------

ms_test_section('8. The global cap on stored clients answers 429');

$capProbe = ms_oauth_build_probe($msRepoRoot, [
    'enabled' => true,
    'oauth_register_ip_hour' => 100,
    'oauth_register_ip_day' => 1000,
    'oauth_max_clients' => 2,
]);
$capServer = ms_oauth_start_server($capProbe);
$capPort = $capServer[1];

for ($i = 1; $i <= 2; $i++) {
    $response = ms_oauth_call($capPort, ['payload' => ['redirect_uris' => ["https://example.com/cb{$i}"]]]);
    ms_test_same("8a. registration {$i} of 2 is served", 201, $response['status']);
}
$response = ms_oauth_call($capPort, ['payload' => ['redirect_uris' => ['https://example.com/cb3']]]);
ms_test_same('8b. the registration past the cap is 429', 429, $response['status']);
ms_test_check('8c. ... with a Retry-After', (int) ($response['headers']['retry-after'] ?? 0) >= 1);

// ---------------------------------------------------------------------
// 9. Fail closed before the migration has run
// ---------------------------------------------------------------------

ms_test_section('9. Before the migration the endpoint answers "not available"');

$freshProbe = ms_oauth_build_probe($msRepoRoot, ['enabled' => false], false);
$freshServer = ms_oauth_start_server($freshProbe);
$freshPort = $freshServer[1];

$response = ms_oauth_call($freshPort, ['payload' => ['redirect_uris' => ['https://example.com/cb']]]);
ms_test_same('9a. a registration before the migration is 503', 503, $response['status']);
ms_test_same('9b. ... with temporarily_unavailable', 'temporarily_unavailable', ms_oauth_error($response));

// ---------------------------------------------------------------------
// 10. What must never reach the log, and the deploy wiring
// ---------------------------------------------------------------------

ms_test_section('10. No redirect URI in a log, and the route is wired up');

$endpointSource = (string) file_get_contents($msRepoRoot . '/oauth_register.php');
$badLogs = [];
if (preg_match_all('/logMessage\((?:[^();]|\([^()]*\))*\)/s', $endpointSource, $matches)) {
    foreach ($matches[0] as $call) {
        if (preg_match('/redirect_uri|\$uri\b|\$metadata\b|\$rawBody\b|\$shape\b/', $call)) {
            $badLogs[] = preg_replace('/\s+/', ' ', mb_substr($call, 0, 160));
        }
    }
}
ms_test_check('10a. no log call is handed a redirect URI or the request body', $badLogs === [], implode("\n       ", $badLogs));
ms_test_check(
    '10b. the success INFO line names the client_id and the client name',
    str_contains($endpointSource, "logMessage('INFO', 'OAuth client registered'")
        && str_contains($endpointSource, "'client_id' => (string) \$client['client_id']")
        && str_contains($endpointSource, "'client_name' => (string) \$client['client_name']")
);
ms_test_check(
    '10c. a rejected registration answers with an RFC error code and no origin/CSRF gate',
    str_contains($endpointSource, 'invalid_client_metadata') && str_contains($endpointSource, 'invalid_redirect_uri')
);

$htaccess = (string) file_get_contents($msRepoRoot . '/.htaccess');
ms_test_check(
    '10d. .htaccess rewrites /oauth/register to the endpoint',
    str_contains($htaccess, 'RewriteRule ^oauth/register/?$ oauth_register.php')
);
ms_test_check(
    '10e. robots.txt disallows /oauth/',
    str_contains((string) file_get_contents($msRepoRoot . '/robots.txt'), "Disallow: /oauth/")
);

// ---------------------------------------------------------------------
// 11. Nothing tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('11. The endpoint answers without a PHP warning, notice or fatal');

foreach ([
    'main probe' => $mainServer,
    'rate-limit probe' => $rateServer,
    'cap probe' => $capServer,
    'pre-migration probe' => $freshServer,
] as $label => $server) {
    $noise = [];
    foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
        if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
            $noise[] = trim($line);
        }
    }
    ms_test_check("11.{$label}: no PHP warning, notice or fatal", $noise === [], implode(' | ', array_slice($noise, 0, 3)));
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_oauth_stop_server($mainServer);
ms_oauth_stop_server($rateServer);
ms_oauth_stop_server($capServer);
ms_oauth_stop_server($freshServer);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($mainProbe);
    ms_test_cleanup($rateProbe);
    ms_test_cleanup($capProbe);
    ms_test_cleanup($freshProbe);
} else {
    echo "Probe docroots left in place for inspection: {$mainProbe}, {$rateProbe}, {$capProbe}, {$freshProbe}\n";
}
exit($exitCode);
