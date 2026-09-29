<?php

declare(strict_types=1);

/**
 * Regression coverage for OAuth discovery (#331 step 5/6): the two metadata
 * documents served by oauth_metadata.php, the 401 challenge mcp.php now
 * carries, the kill switch that keeps both dark, and a scripted client that
 * walks the whole flow from the discovery document to a tools/list (and a
 * first tools/call) answer.
 *
 * Run with:  php tests/oauth_discovery_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network are
 * needed: it reuses the throwaway-docroot harness in
 * tests/lib/pushover_harness.php (the pattern tests/oauth_authorize_test.php
 * uses) and drives the real endpoints through PHP's built-in web server.
 *
 * The built-in server has no .htaccess, so the probe gets a tiny router that
 * emulates the production rewrites the suite is about: `/.well-known/*` to
 * oauth_metadata.php, `/oauth/*` to its endpoint, `/mcp` to mcp.php. The
 * router is what lets the scripted client follow the URLs it read out of the
 * discovery document instead of being handed the .php paths directly — and
 * the .htaccess rules themselves are asserted separately by a source scan.
 *
 * What is checked: that both documents parse and every URL in them sits on the
 * configured origin (and follows BASE_URL when it changes); the path-specific
 * resource document; the headers and CORS; that every 401 carries
 * resource_metadata for every rejection reason while 403/429/415/405 carry
 * none; that with the kill switch off or the schema missing mcp.php answers
 * byte for byte as it did before and the metadata URLs are 404; and a scripted
 * client that registers, is authorised by a seeded session, exchanges the code
 * and lists the tools.
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

// TwoFactorAuth.php guards on this define, exactly as every page does.
define('TEMPMAIL_APP', true);

require __DIR__ . '/lib/pushover_harness.php';
require $msRepoRoot . '/oauth_server.php';
require $msRepoRoot . '/after_login.php';
require $msRepoRoot . '/login_tokens.php';
require $msRepoRoot . '/mcp_tokens.php';
require $msRepoRoot . '/mcp_tools.php';
require $msRepoRoot . '/pii_crypto.php';

/** The PKCE pair from RFC 7636 Appendix B, as the sibling OAuth suites use. */
const MS_OD_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const MS_OD_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const MS_OD_REDIRECT = 'https://example.com/cb';
const MS_OD_STATE = 'st-discovery-1';

const MS_OD_PII_ENCRYPTION_KEY = 'ms-oauth-discovery-suite-encryption-key-01234567';
const MS_OD_PII_INDEX_KEY = 'ms-oauth-discovery-suite-index-key-0123456789ab';
const MS_OD_TOTP_KEY = 'ms-oauth-discovery-suite-totp-secret-key-32b';

/** The metadata paths under test. */
const MS_OD_RESOURCE_PATH = '/.well-known/oauth-protected-resource';
const MS_OD_RESOURCE_MCP_PATH = '/.well-known/oauth-protected-resource/mcp';
const MS_OD_SERVER_PATH = '/.well-known/oauth-authorization-server';
const MS_OD_OPENID_PATH = '/.well-known/openid-configuration';

// ---------------------------------------------------------------------
// Entitlement helpers for the in-process calls
// ---------------------------------------------------------------------

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
// Schema
// ---------------------------------------------------------------------

/**
 * Everything on top of ms_test_schema(): the OAuth tables migrate_oauth.php /
 * migrate_mcp_tokens.php create, the columns the real login path reads, and
 * the abuse guard's three tables so the rate limits are live.
 */
function ms_od_extra_schema(): string
{
    return <<<'SQL'
ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL;
ALTER TABLE pro_users ADD COLUMN email_hash TEXT NULL;
ALTER TABLE pro_users ADD COLUMN email_verified_at TEXT NULL;
ALTER TABLE pro_users ADD COLUMN suspended_at TEXT NULL;
ALTER TABLE pro_users ADD COLUMN last_login_at TEXT NULL;
ALTER TABLE pro_users ADD COLUMN password_hash TEXT NULL;

CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER NULL, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);

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

function ms_od_apply_schema(PDO $pdo): void
{
    foreach (explode(';', ms_od_extra_schema()) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

// ---------------------------------------------------------------------
// The probe docroot
// ---------------------------------------------------------------------

/**
 * The pieces the shipped pages ask config.php for by name, the kill switch,
 * the PDO subclass that skips TwoFactorAuth's MySQL-only DDL, and an optional
 * BASE_URL override so the "every URL follows BASE_URL" check can be made.
 */
function ms_od_probe_config_extra(array $abuse, bool $enabled, ?string $baseUrl = null): string
{
    $extra = "\n// --- added by tests/oauth_discovery_test.php -------------------------\n"
        . '$config[\'trial\'] = [\'hash_key\' => str_repeat(\'k\', 32)];' . "\n"
        . '$config[\'abuse\'] = ' . var_export($abuse, true) . ';' . "\n"
        . '$config[\'oauth\'] = [\'enabled\' => ' . ($enabled ? 'true' : 'false') . '];' . "\n";
    if ($baseUrl !== null) {
        $extra .= '$config[\'email\'][\'base_url\'] = ' . var_export($baseUrl, true) . ';' . "\n";
    }

    return $extra . <<<'PHP'
/**
 * Skips exactly the DDL TwoFactorAuth::ensureSchema() emits: MySQL syntax
 * (ENUM, ENGINE=InnoDB) SQLite cannot parse. Every other statement runs.
 */
class MsOdProbePdo extends PDO {
    public function exec(string $statement): int|false {
        if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $statement)) {
            return 0;
        }
        return parent::exec($statement);
    }
}
$pdo = new MsOdProbePdo('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

/** config.php copies every .env value into $_ENV as well as putenv(). */
foreach (['PII_ENCRYPTION_KEY', 'PII_INDEX_KEY', 'TOTP_ENCRYPTION_KEY'] as $msOdEnvKey) {
    $msOdEnvValue = getenv($msOdEnvKey);
    if (is_string($msOdEnvValue) && $msOdEnvValue !== '') {
        $_ENV[$msOdEnvKey] = $msOdEnvValue;
    }
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

/** Mirrors config.php's proSessionEndIfSuspended(), minus the device restore. */
function proSessionEndIfSuspended(): bool {
    $userId = (int) ($_SESSION['pro_user_id'] ?? 0);
    if ($userId <= 0 || !proUserIsSuspended($userId)) {
        return false;
    }
    unset($_SESSION['pro_user_id'], $_SESSION['pro_user_email'], $_SESSION['pro_login_method'], $_SESSION['pending_2fa']);
    logMessage('INFO', 'Session of a suspended account signed out', ['user_id' => $userId]);
    return true;
}
PHP;
}

/**
 * The built-in server's front controller: it emulates exactly the .htaccess
 * rewrites the suite is about, so the scripted client can follow the URLs it
 * read out of the discovery document. It follows the same order the real file
 * documents.
 */
function ms_od_router_php(): string
{
    return <<<'PHP'
<?php
/** Test-only front controller (tests/oauth_discovery_test.php). */
$msOdPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

$msOdRoutes = [
    '/mcp' => 'mcp.php',
    '/oauth/register' => 'oauth_register.php',
    '/oauth/token' => 'oauth_token.php',
    '/oauth/revoke' => 'oauth_revoke.php',
    '/oauth/authorize' => 'oauth_authorize.php',
];
if (isset($msOdRoutes[$msOdPath])) {
    require __DIR__ . '/' . $msOdRoutes[$msOdPath];
    return true;
}
// The whole .well-known prefix goes to oauth_metadata.php, which serves the
// three documents and 404s anything else. (The real .htaccess rewrites only
// the three exact paths — asserted by the suite's source scan — but the
// outcome for an unknown one is the same 404.)
if (str_starts_with($msOdPath, '/.well-known/')) {
    require __DIR__ . '/oauth_metadata.php';
    return true;
}
// Everything else (pro_auth.php, ...) is served as the file it names.
return false;
PHP;
}

/**
 * Build a probe docroot with (optionally) the OAuth schema and a chosen kill
 * switch / BASE_URL, and return its path.
 *
 * @param array<string,int> $abuse
 */
function ms_od_build_probe(string $repoRoot, array $abuse, bool $enabled, bool $withOauth = true, ?string $baseUrl = null): string
{
    $probe = ms_test_probe_build($repoRoot);

    foreach ([
        'oauth_server.php', 'oauth_register.php', 'oauth_token.php', 'oauth_revoke.php',
        'oauth_authorize.php', 'oauth_metadata.php', 'after_login.php',
        'mcp.php', 'mcp_tools.php', 'email_html_sanitizer.php',
    ] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    if (file_put_contents($probe . '/config.php', ms_test_stub_config_php() . ms_od_probe_config_extra($abuse, $enabled, $baseUrl)) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }
    file_put_contents($probe . '/ms_probe_router.php', ms_od_router_php());

    if ($withOauth) {
        ms_od_apply_schema(ms_test_db(ms_test_probe_sqlite($probe)));
    }
    return $probe;
}

// ---------------------------------------------------------------------
// The built-in web server and a cookie-carrying client
// ---------------------------------------------------------------------

function ms_od_start_server(string $root): array
{
    $sessions = $root . '/sessions';
    if (!is_dir($sessions)) {
        @mkdir($sessions, 0700, true);
    }
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
        'PII_ENCRYPTION_KEY' => MS_OD_PII_ENCRYPTION_KEY,
        'PII_INDEX_KEY' => MS_OD_PII_INDEX_KEY,
        'TOTP_ENCRYPTION_KEY' => base64_encode(hash('sha256', MS_OD_TOTP_KEY, true)),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL',
                '-d', 'session.save_path=' . $sessions, '-S', "127.0.0.1:{$port}", '-t', $root, $root . '/ms_probe_router.php'],
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

function ms_od_stop_server(array $server): void
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
 * A browser: it keeps the cookies the server set, so a session survives from
 * one request to the next. Redirects are never followed — where a response
 * points is exactly what these tests assert on.
 */
final class MsOdBrowser
{
    /** @var array<string,string> */
    public array $cookies = [];

    public function __construct(private int $port)
    {
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get(string $path, array $headers = []): array
    {
        return $this->send('GET', $path, $headers, '');
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function post(string $path, array $form, array $headers = []): array
    {
        return $this->send('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers, http_build_query($form));
    }

    /** A request whose body is sent verbatim — the JSON bodies OAuth and MCP need. */
    public function requestRaw(string $method, string $path, array $headers, string $body): array
    {
        return $this->send($method, $path, $headers, $body);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    private function send(string $method, string $path, array $headers, string $body): array
    {
        $lines = '';
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $lines .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
        }
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $responseBody = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $responseHeaders = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if ($name === 'set-cookie') {
                $pair = explode(';', $value, 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $this->cookies[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
                }
                continue;
            }
            $responseHeaders[$name] = $value;
        }

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : $responseBody,
            'headers' => $responseHeaders,
        ];
    }
}

// ---------------------------------------------------------------------
// Fixtures and small helpers
// ---------------------------------------------------------------------

/** The decoded body of a metadata document, or null. */
function ms_od_json(array $response): ?array
{
    $decoded = json_decode($response['body'], true);
    return is_array($decoded) ? $decoded : null;
}

/** Every http(s) URL anywhere in a decoded document, at any depth. */
function ms_od_urls(array $document): array
{
    $urls = [];
    array_walk_recursive($document, static function ($value) use (&$urls): void {
        if (is_string($value) && preg_match('#^https?://#', $value) === 1) {
            $urls[] = $value;
        }
    });
    return $urls;
}

/** The site-relative path (+ query) of an absolute URL, for the probe port. */
function ms_od_path_of(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
}

/** A user with everything the real login path reads. */
function ms_od_seed_user(PDO $pdo, string $email, string $accountType = 'pro'): int
{
    $id = ms_test_seed_user($pdo, $email, $accountType);
    $pdo->prepare('UPDATE pro_users SET email_enc = ?, email_hash = ?, email_verified_at = ? WHERE id = ?')
        ->execute([
            piiEmailEncrypt($email, MS_OD_PII_ENCRYPTION_KEY),
            piiEmailHash($email, MS_OD_PII_INDEX_KEY),
            date('Y-m-d H:i:s'),
            $id,
        ]);
    return $id;
}

/** Seed one access-token row and return its plaintext. */
function ms_od_seed_token(PDO $pdo, int $userId, string $scopes = 'read', array $overrides = []): string
{
    $token = mcpTokenGenerate();
    $pdo->prepare(
        'INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)'
    )->execute([
        $userId,
        (string) ($overrides['name'] ?? 'discovery token'),
        mcpTokenHash($token),
        mcpTokenPrefixOf($token),
        $scopes,
        date('Y-m-d H:i:s'),
        $overrides['expires_at'] ?? null,
        $overrides['revoked_at'] ?? null,
    ]);
    return $token;
}

/** Sign a browser in through the real pro_auth.php magic link. */
function ms_od_sign_in(PDO $pdo, MsOdBrowser $browser, int $userId): array
{
    return $browser->get('/pro_auth.php?token=' . urlencode(loginTokenCreate($pdo, $userId, 30)));
}

/** One JSON-RPC request against the real mcp.php route, with a Bearer token. */
function ms_od_mcp_call(int $port, ?string $token, $id, string $method, array $params = []): array
{
    $headers = [
        'Content-Type' => 'application/json',
        'Origin' => MS_TEST_ORIGIN,
    ];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    $browser = new MsOdBrowser($port);
    return $browser->requestRaw('POST', '/mcp', $headers, (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ]));
}

/** One scalar read, with the statement closed (SQLite WAL snapshots). */
function ms_od_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
}

// ---------------------------------------------------------------------
// The suite process' own environment
// ---------------------------------------------------------------------

putenv('PII_ENCRYPTION_KEY=' . MS_OD_PII_ENCRYPTION_KEY);
putenv('PII_INDEX_KEY=' . MS_OD_PII_INDEX_KEY);
$_ENV['PII_ENCRYPTION_KEY'] = MS_OD_PII_ENCRYPTION_KEY;
$_ENV['PII_INDEX_KEY'] = MS_OD_PII_INDEX_KEY;
$_ENV['TOTP_ENCRYPTION_KEY'] = base64_encode(hash('sha256', MS_OD_TOTP_KEY, true));

require_once $msRepoRoot . '/TwoFactorAuth.php';

echo "Mail Shield — OAuth discovery (#331 step 5/6)\n";

/** Generous limits so nothing but the deliberate 429 trips. */
$generousLimits = [
    'mcp_rate_per_minute' => 1000,
    'mcp_rate_per_hour' => 1000,
    'oauth_register_ip_hour' => 1000,
    'oauth_register_ip_day' => 1000,
    'oauth_max_clients' => 2000,
    'oauth_token_ip_hour' => 1000,
    'oauth_token_ip_day' => 1000,
    'oauth_authorize_ip_hour' => 1000,
    'oauth_authorize_ip_day' => 1000,
    'oauth_authorize_user_hour' => 1000,
    'oauth_authorize_user_day' => 1000,
];

$enabledProbe = ms_od_build_probe($msRepoRoot, $generousLimits, true);
$enabledServer = ms_od_start_server($enabledProbe);
$enabledPort = $enabledServer[1];
$pdo = ms_test_db(ms_test_probe_sqlite($enabledProbe));

$userPro = ms_od_seed_user($pdo, 'pro@example.com', 'pro');
$userRegular = ms_od_seed_user($pdo, 'regular@example.com', 'regular');
$tokenPro = ms_od_seed_token($pdo, $userPro, 'read,write');
$tokenRegular = ms_od_seed_token($pdo, $userRegular, 'read');
$tokenRevoked = ms_od_seed_token($pdo, $userPro, 'read', ['revoked_at' => date('Y-m-d H:i:s')]);
$tokenExpired = ms_od_seed_token($pdo, $userPro, 'read', ['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

// ---------------------------------------------------------------------
// 1. Protected Resource Metadata (RFC 9728)
// ---------------------------------------------------------------------

ms_test_section('1. The protected-resource metadata document');

$resource = (new MsOdBrowser($enabledPort))->get(MS_OD_RESOURCE_PATH);
ms_test_same('1a. it answers 200', 200, $resource['status']);
ms_test_same('1b. with a JSON content type', 'application/json; charset=utf-8', $resource['headers']['content-type'] ?? null);
ms_test_same('1c. cached for an hour', 'public, max-age=3600', $resource['headers']['cache-control'] ?? null);
ms_test_same('1d. readable cross-origin', '*', $resource['headers']['access-control-allow-origin'] ?? null);

$document = ms_od_json($resource);
ms_test_check('1e. the body parses as a JSON object', $document !== null, substr($resource['body'], 0, 200));
ms_test_same('1f. resource is the canonical MCP identifier', MS_TEST_ORIGIN . '/mcp', $document['resource'] ?? null);
ms_test_same('1g. authorization_servers is our origin', [MS_TEST_ORIGIN], $document['authorization_servers'] ?? null);
ms_test_same('1h. the two MCP scopes are offered', ['mcp:read', 'mcp:write'], $document['scopes_supported'] ?? null);
ms_test_same('1i. the bearer token travels in a header', ['header'], $document['bearer_methods_supported'] ?? null);
ms_test_same('1j. it names the resource', 'Mail Shield', $document['resource_name'] ?? null);

$post = (new MsOdBrowser($enabledPort))->post(MS_OD_RESOURCE_PATH, []);
ms_test_same('1k. another method is 405', 405, $post['status']);
ms_test_same('1l. ... naming the methods that answer', 'GET, HEAD', $post['headers']['allow'] ?? null);

// ---------------------------------------------------------------------
// 2. The path-specific resource document
// ---------------------------------------------------------------------

ms_test_section('2. The path-specific protected-resource document');

$resourceMcp = (new MsOdBrowser($enabledPort))->get(MS_OD_RESOURCE_MCP_PATH);
ms_test_same('2a. /.well-known/oauth-protected-resource/mcp answers 200', 200, $resourceMcp['status']);
ms_test_same('2b. ... with the same canonical resource', MS_TEST_ORIGIN . '/mcp', (ms_od_json($resourceMcp)['resource'] ?? null));
ms_test_same('2c. ... and the trailing-slash form too', 200, (new MsOdBrowser($enabledPort))->get(MS_OD_RESOURCE_MCP_PATH . '/')['status']);

// ---------------------------------------------------------------------
// 3. Authorization Server Metadata (RFC 8414) and the OpenID alias
// ---------------------------------------------------------------------

ms_test_section('3. The authorization-server metadata document');

$server = (new MsOdBrowser($enabledPort))->get(MS_OD_SERVER_PATH);
ms_test_same('3a. it answers 200', 200, $server['status']);
ms_test_same('3b. with a JSON content type', 'application/json; charset=utf-8', $server['headers']['content-type'] ?? null);
ms_test_same('3c. cached for an hour', 'public, max-age=3600', $server['headers']['cache-control'] ?? null);
ms_test_same('3d. readable cross-origin', '*', $server['headers']['access-control-allow-origin'] ?? null);

$serverDoc = ms_od_json($server);
$expectedServer = [
    'issuer' => MS_TEST_ORIGIN,
    'authorization_endpoint' => MS_TEST_ORIGIN . '/oauth/authorize',
    'token_endpoint' => MS_TEST_ORIGIN . '/oauth/token',
    'registration_endpoint' => MS_TEST_ORIGIN . '/oauth/register',
    'revocation_endpoint' => MS_TEST_ORIGIN . '/oauth/revoke',
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported' => ['S256'],
    'token_endpoint_auth_methods_supported' => ['none'],
    'scopes_supported' => ['mcp:read', 'mcp:write'],
];
foreach ($expectedServer as $key => $value) {
    ms_test_same("3e.{$key}", $value, $serverDoc[$key] ?? null);
}
ms_test_same('3f. it says iss comes back on the authorization response', true, $serverDoc['authorization_response_iss_parameter_supported'] ?? null);

$openid = (new MsOdBrowser($enabledPort))->get(MS_OD_OPENID_PATH);
ms_test_same('3g. the OpenID-Connect alias answers the same document', 200, $openid['status']);
ms_test_same('3h. ... byte for byte', $server['body'], $openid['body']);

$unknown = (new MsOdBrowser($enabledPort))->get('/.well-known/something-else');
ms_test_same('3i. a .well-known path we do not serve is 404', 404, $unknown['status']);

// ---------------------------------------------------------------------
// 4. Every URL follows BASE_URL
// ---------------------------------------------------------------------

ms_test_section('4. Every URL in both documents sits on the configured origin');

foreach (['resource document' => $document, 'server document' => $serverDoc] as $label => $decoded) {
    $urls = ms_od_urls($decoded);
    $offOrigin = array_values(array_filter($urls, static fn(string $url): bool => !str_starts_with($url, MS_TEST_ORIGIN)));
    ms_test_check(
        "4a.{$label}: every URL is on " . MS_TEST_ORIGIN . ' (' . count($urls) . ' checked)',
        $urls !== [] && $offOrigin === [],
        implode(', ', $offOrigin)
    );
}

// A second probe with another BASE_URL proves nothing is a literal.
$otherBaseUrl = 'https://mail.example.com/';
$otherProbe = ms_od_build_probe($msRepoRoot, $generousLimits, true, true, $otherBaseUrl);
$otherServer = ms_od_start_server($otherProbe);
$otherPort = $otherServer[1];

$otherResource = ms_od_json((new MsOdBrowser($otherPort))->get(MS_OD_RESOURCE_PATH));
ms_test_same('4b. with another BASE_URL the resource follows it', $otherBaseUrl . 'mcp', $otherResource['resource'] ?? null);
ms_test_same('4c. ... and so does authorization_servers', ['https://mail.example.com'], $otherResource['authorization_servers'] ?? null);

$otherServerDoc = ms_od_json((new MsOdBrowser($otherPort))->get(MS_OD_SERVER_PATH));
ms_test_same('4d. ... and the issuer', 'https://mail.example.com', $otherServerDoc['issuer'] ?? null);
ms_test_same('4e. ... and each endpoint', 'https://mail.example.com/oauth/token', $otherServerDoc['token_endpoint'] ?? null);

// The Origin must be the configured origin here too, or the DNS-rebinding
// check answers 403 before the token is looked at.
$other401 = (new MsOdBrowser($otherPort))->requestRaw('POST', '/mcp', [
    'Content-Type' => 'application/json',
    'Origin' => 'https://mail.example.com',
], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]));
ms_test_same('4f. a tokenless request on the other origin is 401', 401, $other401['status']);
ms_test_check(
    '4g. ... and its challenge points at the other origin',
    str_contains((string) ($other401['headers']['www-authenticate'] ?? ''), 'resource_metadata="https://mail.example.com/.well-known/oauth-protected-resource"'),
    (string) ($other401['headers']['www-authenticate'] ?? '(none)')
);

// ---------------------------------------------------------------------
// 5. The 401 challenge
// ---------------------------------------------------------------------

ms_test_section('5. Every 401 carries resource_metadata; 403/429/415/405 carry none');

$expectedMetadata = 'resource_metadata="' . MS_TEST_ORIGIN . '/.well-known/oauth-protected-resource"';

$rejections = [
    'a missing token' => null,
    'a malformed Authorization header' => 'Basic dXNlcjpwYXNz',
    'a token of the right shape that does not exist' => 'msk_' . str_repeat('0', 64),
    'a revoked token' => $tokenRevoked,
    'an expired token' => $tokenExpired,
];
foreach ($rejections as $label => $credential) {
    $headers = ['Content-Type' => 'application/json', 'Origin' => MS_TEST_ORIGIN];
    if ($credential !== null) {
        $headers['Authorization'] = str_starts_with($credential, 'Basic') ? $credential : 'Bearer ' . $credential;
    }
    $response = (new MsOdBrowser($enabledPort))->requestRaw('POST', '/mcp', $headers, (string) json_encode(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => []]
    ));
    ms_test_same("5a.{$label}: 401", 401, $response['status']);
    ms_test_check(
        "5b.{$label}: the challenge names the metadata document",
        str_contains((string) ($response['headers']['www-authenticate'] ?? ''), $expectedMetadata),
        (string) ($response['headers']['www-authenticate'] ?? '(none)')
    );
}

// The helpful wording, and only for the missing-token case.
$missing = ms_od_mcp_call($enabledPort, null, 1, 'ping');
$missingBody = ms_od_json($missing);
ms_test_check(
    '5c. a missing token is told both ways to get one',
    is_array($missingBody)
        && str_contains((string) ($missingBody['error']['message'] ?? ''), 'sign-in flow')
        && str_contains((string) ($missingBody['error']['message'] ?? ''), 'Connected apps'),
    (string) ($missingBody['error']['message'] ?? '(none)')
);

// 403: the credential is fine, the account is not entitled. No challenge.
$notPro = ms_od_mcp_call($enabledPort, $tokenRegular, 1, 'ping');
ms_test_same('5d. a non-Pro account is 403', 403, $notPro['status']);
ms_test_check('5e. ... with no challenge at all', !isset($notPro['headers']['www-authenticate']));

// 429: a token whose counter is already over the limit.
$tokenLimited = ms_od_seed_token($pdo, $userPro, 'read');
$limitedId = (int) ms_od_scalar($pdo, 'SELECT id FROM mcp_access_tokens WHERE token_hash = ?', [mcpTokenHash($tokenLimited)]);
$pdo->prepare('INSERT INTO abuse_counters (scope, subject, window_start, hits, bytes, strikes) VALUES (?, ?, ?, ?, 0, 0)')
    ->execute(['mcp_token', 'token:' . $limitedId, date('Y-m-d H:i:s', time() - (time() % 60)), 2100]);
$limited = ms_od_mcp_call($enabledPort, $tokenLimited, 1, 'ping');
ms_test_same('5f. an over-limit token is 429', 429, $limited['status']);
ms_test_check('5g. ... with no challenge either', !isset($limited['headers']['www-authenticate']));

// 415 and 405 happen before the token is even looked at.
$wrongType = (new MsOdBrowser($enabledPort))->requestRaw('POST', '/mcp', [
    'Content-Type' => 'text/plain',
    'Origin' => MS_TEST_ORIGIN,
    'Authorization' => 'Bearer ' . $tokenPro,
], '{}');
ms_test_same('5h. a wrong Content-Type is 415', 415, $wrongType['status']);
ms_test_check('5i. ... with no challenge', !isset($wrongType['headers']['www-authenticate']));

$wrongMethod = (new MsOdBrowser($enabledPort))->get('/mcp', ['Origin' => MS_TEST_ORIGIN, 'Authorization' => 'Bearer ' . $tokenPro]);
ms_test_same('5j. GET is 405', 405, $wrongMethod['status']);
ms_test_check('5k. ... with no challenge', !isset($wrongMethod['headers']['www-authenticate']));

// ---------------------------------------------------------------------
// 6. Gating: the kill switch, and the schema
// ---------------------------------------------------------------------

ms_test_section('6. With OAuth off, or the schema missing, nothing is advertised');

$offProbe = ms_od_build_probe($msRepoRoot, $generousLimits, false);
$offServer = ms_od_start_server($offProbe);
$offPort = $offServer[1];
$offPdo = ms_test_db(ms_test_probe_sqlite($offProbe));
$offToken = ms_od_seed_token($offPdo, ms_od_seed_user($offPdo, 'off@example.com', 'pro'), 'read');

$offBrowser = new MsOdBrowser($offPort);
foreach ([MS_OD_RESOURCE_PATH, MS_OD_RESOURCE_MCP_PATH, MS_OD_SERVER_PATH, MS_OD_OPENID_PATH] as $path) {
    ms_test_same("6a.{$path}: 404 while the kill switch is off", 404, $offBrowser->get($path)['status']);
}
$off401 = ms_od_mcp_call($offPort, null, 1, 'ping');
ms_test_same('6b. a 401 is still 401', 401, $off401['status']);
ms_test_same(
    '6c. ... with exactly the challenge it always sent',
    'Bearer realm="Mail Shield"',
    $off401['headers']['www-authenticate'] ?? null
);
$offBody = ms_od_json($off401);
ms_test_same(
    '6d. ... and exactly the message it always sent',
    'A Bearer token is required.',
    $offBody['error']['message'] ?? null
);
ms_test_same('6e. an existing token still works', 200, ms_od_mcp_call($offPort, $offToken, 1, 'tools/list')['status']);

$noSchemaProbe = ms_od_build_probe($msRepoRoot, $generousLimits, true, false);
$noSchemaServer = ms_od_start_server($noSchemaProbe);
$noSchemaPort = $noSchemaServer[1];
ms_test_same('6f. the metadata is 404 with no schema either', 404, (new MsOdBrowser($noSchemaPort))->get(MS_OD_RESOURCE_PATH)['status']);
ms_test_same('6g. the server document too', 404, (new MsOdBrowser($noSchemaPort))->get(MS_OD_SERVER_PATH)['status']);
$noSchema401 = ms_od_mcp_call($noSchemaPort, null, 1, 'ping');
ms_test_same(
    '6h. and the challenge is the bare one',
    'Bearer realm="Mail Shield"',
    $noSchema401['headers']['www-authenticate'] ?? null
);

// ---------------------------------------------------------------------
// 7. A scripted client: discovery → register → authorize → token → tools
// ---------------------------------------------------------------------

ms_test_section('7. A client that follows discovery completes the whole flow');

$client = new MsOdBrowser($enabledPort);

// Step 1: read the protected-resource document and follow it to the issuer.
$discovered = ms_od_json($client->get(MS_OD_RESOURCE_PATH));
ms_test_same('7a. the client finds the authorization server', [MS_TEST_ORIGIN], $discovered['authorization_servers'] ?? null);

// Step 2: read the authorization-server document.
$as = ms_od_json($client->get(ms_od_path_of((string) ($discovered['authorization_servers'][0] ?? '') . '/.well-known/oauth-authorization-server')));
ms_test_check('7b. it learns where to register', is_string($as['registration_endpoint'] ?? null), ms_test_dump($as));

// Step 3: register a public client (RFC 7591) at the advertised endpoint.
$registration = $client->requestRaw('POST', ms_od_path_of((string) $as['registration_endpoint']), ['Content-Type' => 'application/json'], (string) json_encode([
    'client_name' => 'Discovery suite client',
    'redirect_uris' => [MS_OD_REDIRECT],
]));
ms_test_same('7c. registration answers 201', 201, $registration['status']);
$clientId = (string) (ms_od_json($registration)['client_id'] ?? '');
ms_test_check('7d. ... with a client id', preg_match('/^[a-f0-9]{32}$/', $clientId) === 1, $clientId);

// Step 4: go to the advertised authorization endpoint. Signed out, the user is
// sent to log in and the request waits in the session.
$authorizeQuery = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => MS_OD_REDIRECT,
    'response_type' => 'code',
    'code_challenge' => MS_OD_CHALLENGE,
    'code_challenge_method' => 'S256',
    'scope' => 'mcp:read mcp:write',
    'state' => MS_OD_STATE,
    'resource' => MS_TEST_ORIGIN . '/mcp',
]);
$authorize = $client->get(ms_od_path_of((string) $as['authorization_endpoint']) . '?' . $authorizeQuery);
ms_test_same('7e. a signed-out client is sent to log in', 302, $authorize['status']);
ms_test_same('7f. ... to the login page', 'pro_login.php', $authorize['headers']['location'] ?? null);

// Step 5: the user signs in (the seeded session) and resumes the request.
$signIn = ms_od_sign_in($pdo, $client, $userPro);
$resume = (string) ($signIn['headers']['location'] ?? '');
ms_test_check('7g. signing in resumes the authorization request', str_starts_with($resume, '/oauth/authorize?'), $resume);

$consent = $client->get($resume);
ms_test_same('7h. the consent page renders', 200, $consent['status']);
ms_test_check('7i. ... for the app that asked', str_contains($consent['body'], 'Discovery suite client'));
$csrf = preg_match('/name="csrf" value="([a-f0-9]{64})"/', $consent['body'], $m) === 1 ? $m[1] : null;
ms_test_check('7j. ... carrying a CSRF token', $csrf !== null);

// Step 6: approve, and take the code off the redirect.
$decision = $client->post('/oauth/authorize', ['csrf' => (string) $csrf, 'decision' => 'allow', 'scope' => 'mcp:read mcp:write']);
ms_test_same('7k. approving answers 302', 302, $decision['status']);
$location = (string) ($decision['headers']['location'] ?? '');
ms_test_check('7l. ... to the registered redirect_uri', str_starts_with($location, MS_OD_REDIRECT . '?'), $location);
parse_str((string) parse_url($location, PHP_URL_QUERY), $redirectQuery);
$code = (string) ($redirectQuery['code'] ?? '');
ms_test_same('7m. ... with the state', MS_OD_STATE, $redirectQuery['state'] ?? null);
ms_test_check('7n. ... and a code', preg_match('/^[a-f0-9]{64}$/', $code) === 1, $code);

// Step 7: exchange the code at the advertised token endpoint.
$tokenExchange = $client->post(ms_od_path_of((string) $as['token_endpoint']), [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => MS_OD_REDIRECT,
    'client_id' => $clientId,
    'code_verifier' => MS_OD_VERIFIER,
    // The authorization bound a resource (RFC 8707), so the exchange must name
    // the same one.
    'resource' => MS_TEST_ORIGIN . '/mcp',
]);
ms_test_same('7o. the exchange answers 200', 200, $tokenExchange['status']);
$tokenBody = ms_od_json($tokenExchange);
$accessToken = (string) ($tokenBody['access_token'] ?? '');
ms_test_check('7p. ... with an access token', str_starts_with($accessToken, 'msk_'), substr($tokenExchange['body'], 0, 200));
ms_test_same('7q. ... the scope the user chose', 'mcp:read mcp:write', $tokenBody['scope'] ?? null);
ms_test_check('7r. ... and a refresh token', str_starts_with((string) ($tokenBody['refresh_token'] ?? ''), 'msr_'));

// Step 8: use it at the MCP endpoint the metadata named.
$toolsList = ms_od_mcp_call($enabledPort, $accessToken, 1, 'tools/list');
ms_test_same('7s. the token drives the MCP endpoint', 200, $toolsList['status']);
$toolsBody = ms_od_json($toolsList);
$toolNames = array_column(is_array($toolsBody['result']['tools'] ?? null) ? $toolsBody['result']['tools'] : [], 'name');
ms_test_check('7t. ... and lists the tools the scope allows', in_array('create_sticky_address', $toolNames, true), implode(',', $toolNames));

// The acceptance criterion the scripted client can prove: a first tools/call.
$firstCall = ms_od_mcp_call($enabledPort, $accessToken, 2, 'tools/call', ['name' => 'list_addresses', 'arguments' => []]);
ms_test_same('7u. a tools/call answers 200', 200, $firstCall['status']);
$callBody = ms_od_json($firstCall);
ms_test_same('7v. ... and is not an error', false, $callBody['result']['isError'] ?? null);
ms_test_check('7w. ... with structured content', is_array($callBody['result']['structuredContent'] ?? null));
ms_test_check('7x. ... and no credential is echoed back', !str_contains($firstCall['body'], $accessToken));

// ---------------------------------------------------------------------
// 8. The wiring: .htaccess, robots.txt, sitemap and the docs
// ---------------------------------------------------------------------

ms_test_section('8. The rewrites and the docs are wired up');

$htaccess = (string) file_get_contents($msRepoRoot . '/.htaccess');
$dotfileRule = strpos($htaccess, 'RewriteRule (?:^|/)\\.(?!well-known');
foreach ([
    'protected-resource' => 'RewriteRule ^\\.well-known/oauth-protected-resource(?:/mcp)?/?$ oauth_metadata.php [L]',
    'authorization-server' => 'RewriteRule ^\\.well-known/oauth-authorization-server/?$ oauth_metadata.php [L]',
    'openid-configuration' => 'RewriteRule ^\\.well-known/openid-configuration/?$ oauth_metadata.php [L]',
] as $label => $rule) {
    $at = strpos($htaccess, $rule);
    ms_test_check("8a.{$label}: .htaccess rewrites it", $at !== false);
    ms_test_check("8b.{$label}: ... before the dotfile block", $at !== false && $dotfileRule !== false && $at < $dotfileRule);
}
ms_test_check(
    '8c. the .well-known exemption in the dotfile block is untouched',
    str_contains($htaccess, 'RewriteRule (?:^|/)\\.(?!well-known(?:/|$)) - [F,L]')
);

$robots = (string) file_get_contents($msRepoRoot . '/robots.txt');
ms_test_check(
    '8d. robots.txt still disallows /oauth/ and /mcp but never /.well-known/',
    str_contains($robots, 'Disallow: /oauth/')
        && str_contains($robots, 'Disallow: /mcp')
        && !str_contains($robots, 'Disallow: /.well-known')
);

$sitemap = (string) file_get_contents($msRepoRoot . '/sitemap.php');
ms_test_check('8e. sitemap.php does not list the metadata routes', !str_contains($sitemap, 'well-known'));

$envExample = (string) file_get_contents($msRepoRoot . '/.env.example');
ms_test_check('8f. .env.example documents OAUTH_ENABLED', str_contains($envExample, 'OAUTH_ENABLED'));

$metaSource = (string) file_get_contents($msRepoRoot . '/oauth_metadata.php');
ms_test_check(
    '8g. oauth_metadata.php never hardcodes an origin',
    preg_match('#https?://#', $metaSource) !== 1
);
$configSource = (string) file_get_contents($msRepoRoot . '/config.php');
ms_test_check(
    '8h. config.php parses OAUTH_ENABLED with FILTER_VALIDATE_BOOLEAN',
    preg_match('/\$_ENV\[\'OAUTH_ENABLED\'\][^;]*FILTER_VALIDATE_BOOLEAN/', $configSource) === 1
);
$claudeMd = (string) file_get_contents($msRepoRoot . '/CLAUDE.md');
ms_test_check(
    '8i. CLAUDE.md documents the new flag and the metadata file',
    str_contains($claudeMd, 'OAUTH_ENABLED') && str_contains($claudeMd, 'oauth_metadata.php')
);

// ---------------------------------------------------------------------
// 9. Nothing tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('9. The endpoints answer without a PHP warning, notice or fatal');

foreach ([
    'enabled probe' => $enabledServer,
    'other BASE_URL probe' => $otherServer,
    'kill-switch probe' => $offServer,
    'no-schema probe' => $noSchemaServer,
] as $label => $server) {
    $noise = [];
    foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
        if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
            $noise[] = trim($line);
        }
    }
    ms_test_check("9.{$label}: no PHP warning, notice or fatal", $noise === [], implode(' | ', array_slice($noise, 0, 3)));
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_od_stop_server($enabledServer);
ms_od_stop_server($otherServer);
ms_od_stop_server($offServer);
ms_od_stop_server($noSchemaServer);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($enabledProbe);
    ms_test_cleanup($otherProbe);
    ms_test_cleanup($offProbe);
    ms_test_cleanup($noSchemaProbe);
} else {
    echo "Probe docroots left in place for inspection: {$enabledProbe}, {$otherProbe}, {$offProbe}, {$noSchemaProbe}\n";
}
exit($exitCode);
