<?php

declare(strict_types=1);

/**
 * Regression coverage for the OAuth authorization endpoint and consent page,
 * and for the after-login mechanism they depend on (#331 step 3/6,
 * oauth_authorize.php, after_login.php, oauth_server.php).
 *
 * Run with:  php tests/oauth_authorize_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network are
 * needed: it reuses the throwaway-docroot harness in
 * tests/lib/pushover_harness.php (the pattern tests/oauth_token_test.php and
 * tests/mcp_endpoint_test.php use) and drives the real endpoints through PHP's
 * built-in web server — the only SAPI they run under, since they refuse CLI.
 *
 * Where the real page under test is pro_auth.php or pro_login.php, they are the
 * real files, copied into the probe by the harness and by this suite. The stub
 * config.php the harness writes is extended here (never edited) with the three
 * things those pages ask config.php for by name and the SQLite schema this
 * suite layers on: proUserIsSuspended(), proSessionEndIfSuspended() and a PDO
 * subclass that skips `CREATE TABLE IF NOT EXISTS`.
 *
 * That last one matters: TwoFactorAuth::ensureSchema() emits MySQL DDL (ENUM,
 * ENGINE=InnoDB) that SQLite rejects, so without it the real 2FA branch of
 * pro_auth.php cannot run at all. The subclass skips only those statements —
 * every class in the flow, TwoFactorAuth included, is the shipped one, and the
 * TOTP codes this suite presents are computed from RFC 4226 and checked against
 * TwoFactorAuth::verifyCode() before they are used.
 *
 * What is checked is the endpoints' own behaviour: which failures render a page
 * on our origin and which redirect (and to where), the signed-out resume
 * through the real login, the eligibility refusals, the CSRF binding, the scope
 * the user picked, the code that comes out the other end — and the one thing
 * that must never happen: a code, a state or the redirect query in a log, or a
 * redirect to a URI the client did not register.
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
require $msRepoRoot . '/mcp_tools.php';
require $msRepoRoot . '/pii_crypto.php';

/** The PKCE pair from RFC 7636 Appendix B, as tests/oauth_token_test.php uses. */
const MS_OA_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const MS_OA_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const MS_OA_REDIRECT = 'https://example.com/cb';
const MS_OA_STATE = 'st-abc-123';

/** The keys the endpoints read out of the environment (pii_crypto, TwoFactorAuth). */
const MS_OA_PII_ENCRYPTION_KEY = 'ms-oauth-authorize-suite-encryption-key-0123456789';
const MS_OA_PII_INDEX_KEY = 'ms-oauth-authorize-suite-index-key-0123456789abcd';
const MS_OA_TOTP_KEY = 'ms-oauth-authorize-suite-totp-secret-key-32b';

// ---------------------------------------------------------------------
// Entitlement helpers for the in-process calls
// ---------------------------------------------------------------------

/**
 * mcp_tokens.php and oauth_server.php ask config.php for these by name. The
 * suite has no config.php, so it mirrors the real bodies against the same
 * SQLite file the probe writes to.
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
// Schema
// ---------------------------------------------------------------------

/**
 * Everything on top of ms_test_schema(): the columns the real login paths read,
 * the OAuth and token tables migrate_oauth.php / migrate_mcp_tokens.php create,
 * the abuse guard's three tables, and the 2FA tables.
 */
function ms_oa_extra_schema(): string
{
    return <<<'SQL'
ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL;
ALTER TABLE pro_users ADD COLUMN email_hash TEXT NULL;
ALTER TABLE pro_users ADD COLUMN password_hash TEXT NULL;
ALTER TABLE pro_users ADD COLUMN email_verified_at TEXT NULL;
ALTER TABLE pro_users ADD COLUMN suspended_at TEXT NULL;
ALTER TABLE pro_users ADD COLUMN last_login_at TEXT NULL;
ALTER TABLE pro_users ADD COLUMN password_changed_at TEXT NULL;

CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER NULL, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', last_used_step INTEGER NULL, confirmed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL);
CREATE TABLE pro_user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NULL, used_at TEXT NULL, created_at TEXT NULL);
CREATE TABLE two_factor_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, ip TEXT NOT NULL, success INTEGER NOT NULL, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, selector TEXT NOT NULL UNIQUE, validator_hash TEXT NOT NULL, label TEXT NULL, created_at TEXT NULL, last_used_at TEXT NULL, expires_at TEXT NOT NULL);

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

function ms_oa_apply_schema(PDO $pdo): void
{
    foreach (explode(';', ms_oa_extra_schema()) as $statement) {
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
 * The pieces the shipped pages ask config.php for by name, plus the PDO
 * subclass described in the file header. Appended to the harness' stub — the
 * harness file itself is never edited.
 */
function ms_oa_probe_config_extra(array $abuse): string
{
    return "\n// --- added by tests/oauth_authorize_test.php -------------------------\n"
        . '$config[\'trial\'] = [\'hash_key\' => str_repeat(\'k\', 32)];' . "\n"
        . '$config[\'abuse\'] = ' . var_export($abuse, true) . ';' . "\n"
        . <<<'PHP'
/**
 * Skips exactly the DDL TwoFactorAuth::ensureSchema() emits: MySQL syntax
 * (ENUM, ENGINE=InnoDB) SQLite cannot parse. Every other statement runs.
 */
class MsOaProbePdo extends PDO {
    public function exec(string $statement): int|false {
        if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $statement)) {
            return 0;
        }
        return parent::exec($statement);
    }
}
$pdo = new MsOaProbePdo('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

/**
 * config.php copies every .env value into $_ENV as well as putenv(); several
 * libraries (TwoFactorAuth's TOTP key) read $_ENV only, so the stub has to do
 * the same or those paths see nothing.
 */
foreach (['PII_ENCRYPTION_KEY', 'PII_INDEX_KEY', 'TOTP_ENCRYPTION_KEY'] as $msOaEnvKey) {
    $msOaEnvValue = getenv($msOaEnvKey);
    if (is_string($msOaEnvValue) && $msOaEnvValue !== '') {
        $_ENV[$msOaEnvKey] = $msOaEnvValue;
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
 * Build a probe docroot with the OAuth schema, and return its path.
 *
 * @param array<string,int> $abuse
 */
function ms_oa_build_probe(string $repoRoot, array $abuse, bool $withOauth = true): string
{
    $probe = ms_test_probe_build($repoRoot);

    foreach ([
        'oauth_authorize.php', 'oauth_server.php', 'oauth_token.php', 'oauth_revoke.php',
        'after_login.php', 'mcp.php', 'mcp_tools.php', 'email_html_sanitizer.php',
    ] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }

    if (file_put_contents($probe . '/config.php', ms_test_stub_config_php() . ms_oa_probe_config_extra($abuse)) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }

    if ($withOauth) {
        ms_oa_apply_schema(ms_test_db(ms_test_probe_sqlite($probe)));
    }
    return $probe;
}

// ---------------------------------------------------------------------
// The built-in web server and a cookie-carrying client
// ---------------------------------------------------------------------

function ms_oa_start_server(string $root): array
{
    $sessions = $root . '/sessions';
    if (!is_dir($sessions)) {
        @mkdir($sessions, 0700, true);
    }
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
        'PII_ENCRYPTION_KEY' => MS_OA_PII_ENCRYPTION_KEY,
        'PII_INDEX_KEY' => MS_OA_PII_INDEX_KEY,
        'TOTP_ENCRYPTION_KEY' => base64_encode(hash('sha256', MS_OA_TOTP_KEY, true)),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL',
                '-d', 'session.save_path=' . $sessions, '-S', "127.0.0.1:{$port}", '-t', $root],
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

function ms_oa_stop_server(array $server): void
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
 * sends the user is exactly what these tests assert on.
 */
final class MsOaBrowser
{
    /** @var array<string,string> */
    public array $cookies = [];

    public function __construct(private int $port)
    {
    }

    /** The cookies as one request header value, for a raw request. */
    public function cookieHeader(): string
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return implode('; ', $pairs);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $form
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function request(string $method, string $path, array $headers = [], ?array $form = null): array
    {
        $body = $form !== null ? http_build_query($form) : '';
        $headers = $form !== null ? ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers : $headers;
        return $this->send($method, $path, $headers, $body);
    }

    /**
     * A request whose body is sent verbatim — the JSON-RPC body mcp.php needs.
     *
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function requestRaw(string $method, string $path, array $headers, string $rawBody): array
    {
        return $this->send($method, $path, $headers, $rawBody);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    private function send(string $method, string $path, array $headers, string $body): array
    {
        $lines = '';
        if ($this->cookies !== []) {
            $lines .= 'Cookie: ' . $this->cookieHeader() . "\r\n";
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
            // Where the answer points is what is under test; never chase it.
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
                // name=value; Path=/; HttpOnly; SameSite=Lax
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

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, $headers);
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function post(string $path, array $form, array $headers = []): array
    {
        return $this->request('POST', $path, $headers, $form);
    }
}

// ---------------------------------------------------------------------
// Fixtures and small helpers
// ---------------------------------------------------------------------

/** Register a public client, as oauth_register.php does. */
function ms_oa_seed_client(PDO $pdo, string $name, string $redirectUri = MS_OA_REDIRECT): string
{
    $result = oauthClientRegister($pdo, ['client_name' => $name, 'redirect_uris' => [$redirectUri]]);
    if (!$result['ok']) {
        throw new RuntimeException('Could not register the fixture client');
    }
    return (string) $result['client']['client_id'];
}

/** A user with everything the real login paths read. */
function ms_oa_seed_user(PDO $pdo, string $email, string $accountType = 'pro', ?string $password = null): int
{
    $id = ms_test_seed_user($pdo, $email, $accountType);
    $pdo->prepare('UPDATE pro_users SET email_enc = ?, email_hash = ?, email_verified_at = ?, password_hash = ? WHERE id = ?')
        ->execute([
            piiEmailEncrypt($email, MS_OA_PII_ENCRYPTION_KEY),
            piiEmailHash($email, MS_OA_PII_INDEX_KEY),
            date('Y-m-d H:i:s'),
            $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null,
            $id,
        ]);
    return $id;
}

/** Sign $browser in as $userId through the real pro_auth.php magic-link GET. */
function ms_oa_sign_in(PDO $pdo, MsOaBrowser $browser, int $userId): array
{
    $token = loginTokenCreate($pdo, $userId, 30);
    return $browser->get('/pro_auth.php?token=' . urlencode($token));
}

/** The authorize query the consent page is reached with. */
function ms_oa_query(string $clientId, array $overrides = []): array
{
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => MS_OA_REDIRECT,
        'response_type' => 'code',
        'code_challenge' => MS_OA_CHALLENGE,
        'code_challenge_method' => 'S256',
        'scope' => 'mcp:read mcp:write',
        'state' => MS_OA_STATE,
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return $params;
}

function ms_oa_authorize_path(array $params): string
{
    return '/oauth_authorize.php?' . http_build_query($params);
}

/**
 * The URL the app resumes to is the public route `/oauth/authorize`, which
 * .htaccess rewrites (and which test 14j checks). PHP's built-in server has no
 * .htaccess, so a request for it falls through to index.php — here it has to
 * name the file that answers.
 */
function ms_oa_probe_path(string $path): string
{
    return str_starts_with($path, '/oauth/authorize')
        ? '/oauth_authorize.php' . substr($path, strlen('/oauth/authorize'))
        : $path;
}

/** The CSRF token the consent page carries. */
function ms_oa_csrf_from(string $html): ?string
{
    return preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : null;
}

/** The query of a Location header, split into an array. */
function ms_oa_location_query(array $response): array
{
    $location = (string) ($response['headers']['location'] ?? '');
    $query = parse_url($location, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return [];
    }
    $parsed = [];
    parse_str($query, $parsed);
    return $parsed;
}

/** One JSON-RPC request against the real mcp.php, with a Bearer token. */
function ms_oa_mcp_call(int $port, string $token, int $id): array
{
    $browser = new MsOaBrowser($port);
    return $browser->requestRaw('POST', '/mcp.php', [
        'Content-Type' => 'application/json',
        'Origin' => MS_TEST_ORIGIN,
        'Authorization' => 'Bearer ' . $token,
    ], (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list', 'params' => []]));
}

/** One scalar read, with the statement closed (SQLite WAL snapshots). */
function ms_oa_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
}

/**
 * The current TOTP code for a base32 secret, per RFC 4226 / RFC 6238. The
 * suite proves it agrees with the shipped verifier before it uses it.
 */
function ms_oa_totp_code(string $secretB32): string
{
    $key = TwoFactorAuth::base32Decode($secretB32);
    $counter = intdiv(time(), 30);
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------
// The suite process' own environment
// ---------------------------------------------------------------------

putenv('PII_ENCRYPTION_KEY=' . MS_OA_PII_ENCRYPTION_KEY);
putenv('PII_INDEX_KEY=' . MS_OA_PII_INDEX_KEY);
$_ENV['PII_ENCRYPTION_KEY'] = MS_OA_PII_ENCRYPTION_KEY;
$_ENV['PII_INDEX_KEY'] = MS_OA_PII_INDEX_KEY;
$_ENV['TOTP_ENCRYPTION_KEY'] = base64_encode(hash('sha256', MS_OA_TOTP_KEY, true));

require_once $msRepoRoot . '/TwoFactorAuth.php';

echo "Mail Shield — OAuth authorization endpoint and consent page (#331 step 3/6)\n";

$generousLimits = [
    'oauth_authorize_ip_hour' => 1000,
    'oauth_authorize_ip_day' => 1000,
    'oauth_authorize_user_hour' => 1000,
    'oauth_authorize_user_day' => 1000,
];

$mainProbe = ms_oa_build_probe($msRepoRoot, $generousLimits);
$mainServer = ms_oa_start_server($mainProbe);
$mainPort = $mainServer[1];
$pdo = ms_test_db(ms_test_probe_sqlite($mainProbe));

$userPro = ms_oa_seed_user($pdo, 'pro@example.com', 'pro', 'correct-horse');
$userRegular = ms_oa_seed_user($pdo, 'regular@example.com', 'regular', 'correct-horse');
$userSuspended = ms_oa_seed_user($pdo, 'suspended@example.com', 'pro', 'correct-horse');
$userCapped = ms_oa_seed_user($pdo, 'capped@example.com', 'pro', 'correct-horse');
$userTwoFactor = ms_oa_seed_user($pdo, 'twofactor@example.com', 'pro', 'correct-horse');
$pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $userSuspended]);

$clientA = ms_oa_seed_client($pdo, 'Example App');
$clientB = ms_oa_seed_client($pdo, 'Second App');

// ---------------------------------------------------------------------
// 1. The after-login mechanism (pure functions)
// ---------------------------------------------------------------------

ms_test_section('1. The after-login mechanism is allow-listed, single-use and site-relative');

$_SESSION = [];
ms_test_same('1a. an unknown route is refused', false, afterLoginSet('evil_page', []));
ms_test_same('1b. ... and nothing is stored', null, afterLoginUrl());
ms_test_same('1c. the only route in the allow-list', ['oauth_authorize'], array_keys(afterLoginRoutes()));

ms_test_same('1d. a known route is stored', true, afterLoginSet('oauth_authorize', ['client_id' => str_repeat('a', 32)]));
ms_test_same('1e. ... and read back as a site-relative URL', '/oauth/authorize?client_id=' . str_repeat('a', 32), afterLoginUrl());
ms_test_same('1f. taking it consumes it', '/oauth/authorize?client_id=' . str_repeat('a', 32), afterLoginTake());
ms_test_same('1g. a second take returns nothing', null, afterLoginTake());

$_SESSION = [];
afterLoginSet('oauth_authorize', ['state' => "a\r\nLocation: https://evil.example/"]);
$url = (string) afterLoginUrl();
ms_test_same('1h. a CR/LF in a value is dropped, not encoded', '/oauth/authorize', $url);
$_SESSION = [];
afterLoginSet('oauth_authorize', ['ok' => 'fine', 'bad' => ['array']]);
ms_test_same('1i. a non-string value is dropped', '/oauth/authorize?ok=fine', afterLoginUrl());

$_SESSION = [];
afterLoginSet('oauth_authorize', ['state' => 'x y&z']);
$encoded = (string) afterLoginUrl();
ms_test_check('1j. a value is re-encoded, never passed through raw', str_contains($encoded, 'state=x%20y%26z'), $encoded);

$_SESSION = [];
afterLoginSet('oauth_authorize', []);
$_SESSION[AFTER_LOGIN_SESSION_KEY]['created_at'] = time() - AFTER_LOGIN_TTL_SECONDS - 1;
ms_test_same('1k. an expired entry resumes nothing', null, afterLoginUrl());

$_SESSION = [];
ms_test_same('1l. a stored route that left the allow-list resumes nothing', null, (static function (): ?string {
    $_SESSION[AFTER_LOGIN_SESSION_KEY] = ['route' => 'not_a_route', 'query' => [], 'created_at' => time()];
    return afterLoginUrl();
})());

// ---------------------------------------------------------------------
// 2. The authorization request (pure validation)
// ---------------------------------------------------------------------

ms_test_section('2. The request is validated in the RFC 6749 §4.1.2.1 order');

$canonical = oauthCanonicalResource(MS_TEST_ORIGIN);
ms_test_same('2a. the canonical resource is <base_url>/mcp', MS_TEST_ORIGIN . '/mcp', $canonical);

$valid = oauthAuthorizeRequestValidate($pdo, ms_oa_query($clientA), MS_TEST_ORIGIN);
ms_test_check('2b. a well-formed request validates', $valid['ok'] === true, ms_test_dump($valid));
ms_test_same('2c. ... carrying the client id', $clientA, $valid['request']['client_id'] ?? null);
ms_test_same('2d. ... the exact registered redirect_uri', MS_OA_REDIRECT, $valid['request']['redirect_uri'] ?? null);
ms_test_same('2e. ... the challenge', MS_OA_CHALLENGE, $valid['request']['code_challenge'] ?? null);
ms_test_same('2f. ... the state', MS_OA_STATE, $valid['request']['state'] ?? null);
ms_test_same('2g. ... and both scopes', ['read', 'write'], $valid['request']['scopes'] ?? null);

$noScope = oauthAuthorizeRequestValidate($pdo, ms_oa_query($clientA, ['scope' => null]), MS_TEST_ORIGIN);
ms_test_same('2h. no scope at all means read', ['read'], $noScope['request']['scopes'] ?? null);

$unknownClient = oauthAuthorizeRequestValidate($pdo, ms_oa_query(str_repeat('f', 32)), MS_TEST_ORIGIN);
ms_test_same('2i. an unknown client is fatal', true, $unknownClient['fatal'] ?? null);
$malformedClient = oauthAuthorizeRequestValidate($pdo, ms_oa_query('not-a-client-id'), MS_TEST_ORIGIN);
ms_test_same('2j. a malformed client id is fatal too', true, $malformedClient['fatal'] ?? null);

$badRedirect = oauthAuthorizeRequestValidate($pdo, ms_oa_query($clientA, ['redirect_uri' => MS_OA_REDIRECT . '/']), MS_TEST_ORIGIN);
ms_test_same('2k. a redirect_uri that is not an exact match is fatal', true, $badRedirect['fatal'] ?? null);
$noRedirect = oauthAuthorizeRequestValidate($pdo, ms_oa_query($clientA, ['redirect_uri' => null]), MS_TEST_ORIGIN);
ms_test_same('2l. a missing redirect_uri is fatal', true, $noRedirect['fatal'] ?? null);

$nonFatal = [
    'a wrong response_type' => [['response_type' => 'token'], 'unsupported_response_type'],
    'a missing response_type' => [['response_type' => null], 'invalid_request'],
    'a missing challenge' => [['code_challenge' => null], 'invalid_request'],
    'a challenge of the wrong length' => [['code_challenge' => str_repeat('a', 42)], 'invalid_request'],
    'a challenge outside base64url' => [['code_challenge' => str_repeat('a', 42) . '+'], 'invalid_request'],
    'a missing method' => [['code_challenge_method' => null], 'invalid_request'],
    'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'],
    'an unknown scope' => [['scope' => 'mcp:admin'], 'invalid_scope'],
    'a foreign resource' => [['resource' => 'https://evil.example/mcp'], 'invalid_request'],
    'a state over 512 characters' => [['state' => str_repeat('a', 513)], 'invalid_request'],
];
foreach ($nonFatal as $label => [$overrides, $expectedError]) {
    $result = oauthAuthorizeRequestValidate($pdo, ms_oa_query($clientA, $overrides), MS_TEST_ORIGIN);
    ms_test_same("2m.{$label}: not fatal", false, $result['fatal'] ?? null);
    ms_test_same("2n.{$label}: {$expectedError}", $expectedError, $result['error'] ?? null);
    ms_test_same("2o.{$label}: the redirect_uri rides along", MS_OA_REDIRECT, $result['redirect_uri'] ?? null);
}

$ownResource = oauthAuthorizeRequestValidate($pdo, ms_oa_query($clientA, ['resource' => $canonical]), MS_TEST_ORIGIN);
ms_test_same('2p. our own resource is accepted', $canonical, $ownResource['request']['resource'] ?? null);

ms_test_check('2q. the resource is built from base_url, not a literal', oauthCanonicalResource('https://other.example/') === 'https://other.example/mcp');

// ---------------------------------------------------------------------
// 3. The consent page
// ---------------------------------------------------------------------

ms_test_section('3. A signed-in Pro user is shown the consent page');

$pro = new MsOaBrowser($mainPort);
ms_oa_sign_in($pdo, $pro, $userPro);

$response = $pro->get(ms_oa_authorize_path(ms_oa_query($clientA)));
ms_test_same('3a. the consent page is 200', 200, $response['status']);
// session_start()'s own cache limiter adds its headers on top of ours.
ms_test_check('3b. it is not cached', str_contains((string) ($response['headers']['cache-control'] ?? ''), 'no-store'), (string) ($response['headers']['cache-control'] ?? ''));
ms_test_same('3c. it refuses framing (X-Frame-Options)', 'DENY', $response['headers']['x-frame-options'] ?? null);
ms_test_check('3d. ... and refuses it in the CSP as well', str_contains((string) ($response['headers']['content-security-policy'] ?? ''), "frame-ancestors 'none'"));
ms_test_check('3e. it is noindex, nofollow', str_contains($response['body'], 'content="noindex, nofollow"'));
ms_test_check('3f. it names the app as the app calls itself', str_contains($response['body'], 'the name the app gives itself'));
ms_test_check('3g. it shows the app name', str_contains($response['body'], 'Example App'));
ms_test_check('3h. it shows the redirect host', str_contains($response['body'], '<code>example.com</code>'));
ms_test_check('3i. it offers read only', str_contains($response['body'], 'value="mcp:read"'));
ms_test_check('3j. it offers read and change when write was asked for', str_contains($response['body'], 'value="mcp:read mcp:write"'));
ms_test_check('3k. read only is the one that starts checked', preg_match('/value="mcp:read"[^>]*checked/', $response['body']) === 1);
ms_test_check('3l. it says where the message content goes', str_contains($response['body'], 'sent to whoever runs it'));
ms_test_check('3m. it has both answers', str_contains($response['body'], 'value="allow"') && str_contains($response['body'], 'value="cancel"'));
ms_test_check('3n. it carries a CSRF token', ms_oa_csrf_from($response['body']) !== null);
ms_test_check('3o. it carries no hidden client_id, redirect_uri or challenge', !str_contains($response['body'], 'name="client_id"')
    && !str_contains($response['body'], 'name="redirect_uri"')
    && !str_contains($response['body'], 'name="code_challenge"'));
ms_test_check('3p. it loads no analytics', !str_contains($response['body'], 'gtag')
    && !str_contains($response['body'], 'googletagmanager')
    && !str_contains($response['body'], 'analytics'));
ms_test_check('3q. the stylesheets are the app style group', str_contains($response['body'], 'assets/css/mailshield.css')
    && str_contains($response['body'], 'assets/css/mailshield-bootstrap.css')
    && str_contains($response['body'], 'bootstrap@5.3.0'));

$readOnly = $pro->get(ms_oa_authorize_path(ms_oa_query($clientA, ['scope' => 'mcp:read'])));
ms_test_check('3r. a read-only request offers no write option', !str_contains($readOnly['body'], 'value="mcp:read mcp:write"'));

// The app's name is attacker-controlled: it must arrive escaped.
$markupClient = ms_oa_seed_client($pdo, '<script>alert(1)</script>');
$markupPage = $pro->get(ms_oa_authorize_path(ms_oa_query($markupClient)));
ms_test_same('3s. an app name with markup still renders 200', 200, $markupPage['status']);
ms_test_check('3t. ... and the markup is escaped', str_contains($markupPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
ms_test_check('3u. ... never as live markup', !str_contains($markupPage['body'], '<script>alert(1)</script>'));

// ---------------------------------------------------------------------
// 4. Failures that must not redirect
// ---------------------------------------------------------------------

ms_test_section('4. An unproven client or redirect_uri renders a page and never redirects');

foreach ([
    'an unknown client' => ms_oa_query(str_repeat('f', 32)),
    'a malformed client id' => ms_oa_query('nonsense'),
    'a redirect_uri that is not registered' => ms_oa_query($clientA, ['redirect_uri' => 'https://evil.example/cb']),
    'a redirect_uri that is a prefix of the registered one' => ms_oa_query($clientA, ['redirect_uri' => 'https://example.com/']),
    'a missing redirect_uri' => ms_oa_query($clientA, ['redirect_uri' => null]),
] as $label => $params) {
    $response = $pro->get(ms_oa_authorize_path($params));
    ms_test_same("4a.{$label}: 400", 400, $response['status']);
    ms_test_same("4b.{$label}: no Location header", null, $response['headers']['location'] ?? null);
    ms_test_check("4c.{$label}: it answers on our own origin", str_contains($response['body'], 'This link cannot be used'));
}

// ---------------------------------------------------------------------
// 5. Failures that must redirect — with the right code, state and iss
// ---------------------------------------------------------------------

ms_test_section('5. A parameter error goes back to the redirect_uri with iss');

foreach ([
    'a wrong response_type' => [['response_type' => 'token'], 'unsupported_response_type'],
    'a missing challenge' => [['code_challenge' => null], 'invalid_request'],
    'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'],
    'an unknown scope' => [['scope' => 'mcp:admin'], 'invalid_scope'],
    'a foreign resource' => [['resource' => 'https://evil.example/mcp'], 'invalid_request'],
] as $label => [$overrides, $expectedError]) {
    $response = $pro->get(ms_oa_authorize_path(ms_oa_query($clientA, $overrides)));
    ms_test_same("5a.{$label}: 302", 302, $response['status']);
    $location = (string) ($response['headers']['location'] ?? '');
    ms_test_check("5b.{$label}: to the registered redirect_uri", str_starts_with($location, MS_OA_REDIRECT . '?'), $location);
    $query = ms_oa_location_query($response);
    ms_test_same("5c.{$label}: {$expectedError}", $expectedError, $query['error'] ?? null);
    ms_test_same("5d.{$label}: the state is passed through", MS_OA_STATE, $query['state'] ?? null);
    ms_test_same("5e.{$label}: iss is our origin", MS_TEST_ORIGIN, $query['iss'] ?? null);
    ms_test_check("5f.{$label}: no code is issued", !isset($query['code']));
}

// A redirect_uri may itself carry a query string; the parameters are appended.
$queryClient = ms_oa_seed_client($pdo, 'Query App', 'https://example.com/cb?tenant=1');
$response = $pro->get(ms_oa_authorize_path(ms_oa_query($queryClient, ['redirect_uri' => 'https://example.com/cb?tenant=1', 'scope' => 'mcp:admin'])));
$location = (string) ($response['headers']['location'] ?? '');
ms_test_check('5g. a redirect_uri with a query gets its parameters appended', str_starts_with($location, 'https://example.com/cb?tenant=1&error=invalid_scope'), $location);

// ---------------------------------------------------------------------
// 6. Signing in resumes the request
// ---------------------------------------------------------------------

ms_test_section('6. A signed-out visitor signs in and comes back to the same request');

$guest = new MsOaBrowser($mainPort);
$params = ms_oa_query($clientA);
$response = $guest->get(ms_oa_authorize_path($params));
ms_test_same('6a. the consent page sends a signed-out visitor away', 302, $response['status']);
ms_test_same('6b. ... to the login page, not to the client', 'pro_login.php', $response['headers']['location'] ?? null);

$response = ms_oa_sign_in($pdo, $guest, $userPro);
ms_test_same('6c. the magic-link sign-in answers a redirect', 302, $response['status']);
$resumeTo = (string) ($response['headers']['location'] ?? '');
ms_test_check('6d. ... back to the authorize request', str_starts_with($resumeTo, '/oauth/authorize?'), $resumeTo);
ms_test_check('6e. ... site-relative, never an absolute URL', !str_contains($resumeTo, '://'), $resumeTo);
$resumeQuery = [];
parse_str((string) parse_url($resumeTo, PHP_URL_QUERY), $resumeQuery);
ms_test_same('6f. ... for the same client', $clientA, $resumeQuery['client_id'] ?? null);
ms_test_same('6g. ... with the same state', MS_OA_STATE, $resumeQuery['state'] ?? null);
ms_test_same('6h. ... and the same challenge', MS_OA_CHALLENGE, $resumeQuery['code_challenge'] ?? null);

$response = $guest->get(ms_oa_probe_path($resumeTo));
ms_test_same('6i. the resumed request renders the consent page', 200, $response['status']);
ms_test_check('6j. ... for the same app', str_contains($response['body'], 'Example App'), substr($response['body'], 0, 300));

// A second sign-in must not replay the stored request: it was taken.
$pdo->prepare('UPDATE pro_users SET suspended_at = NULL WHERE id = ?')->execute([$userSuspended]);
$second = ms_oa_sign_in($pdo, $guest, $userPro);
ms_test_same('6k. signing in again lands on the dashboard, not the old request', 'pro.php', $second['headers']['location'] ?? null);

// Nothing a visitor can put in a URL turns into a resume target.
$guest2 = new MsOaBrowser($mainPort);
$guest2->get('/oauth_authorize.php?' . http_build_query(ms_oa_query($clientA)));
$guest2->get('/pro_login.php?next=https://evil.example/&return=/evil');
$response = ms_oa_sign_in($pdo, $guest2, $userPro);
$parsedResume = [];
parse_str((string) parse_url((string) ($response['headers']['location'] ?? ''), PHP_URL_QUERY), $parsedResume);
ms_test_same('6l. a next= parameter changes nothing about where the resume goes', $clientA, $parsedResume['client_id'] ?? null);
ms_test_check('6m. ... and no part of it points at the attacker', !str_contains((string) ($response['headers']['location'] ?? ''), 'evil.example'), (string) ($response['headers']['location'] ?? ''));

// ---------------------------------------------------------------------
// 7. 2FA resumes the request too
// ---------------------------------------------------------------------

ms_test_section('7. The resume survives the real password + 2FA path');

$secret = TwoFactorAuth::generateSecret();
ms_test_check('7a. the suite\'s TOTP generator agrees with the shipped verifier',
    TwoFactorAuth::verifyCode($secret, ms_oa_totp_code($secret)) !== null);
$pdo->prepare("INSERT INTO pro_user_totp (user_id, secret_enc, status, last_used_step) VALUES (?, ?, 'active', NULL)")
    ->execute([$userTwoFactor, TwoFactorAuth::encryptSecret($secret)]);

$twoFactor = new MsOaBrowser($mainPort);
$twoFactor->get(ms_oa_authorize_path($params));

$response = $twoFactor->post('/pro_auth.php', [
    'action' => 'password_login',
    'email' => 'twofactor@example.com',
    'password' => 'correct-horse',
    'stay_days' => '0',
], ['Origin' => MS_TEST_ORIGIN]);
$body = json_decode($response['body'], true);
ms_test_check('7b. the password step answers JSON', is_array($body), substr($response['body'], 0, 200));
ms_test_same('7c. ... asking for the 2FA code', true, $body['requires_2fa'] ?? null);

$response = $twoFactor->post('/pro_auth.php', [
    'action' => 'verify_2fa',
    'code' => ms_oa_totp_code($secret),
    'remember_device' => '0',
], ['Origin' => MS_TEST_ORIGIN]);
$body = json_decode($response['body'], true);
ms_test_check('7d. the code is accepted', is_array($body) && ($body['success'] ?? false) === true, substr($response['body'], 0, 200));
$twoFactorTo = (string) ($body['redirect'] ?? '');
ms_test_check('7e. ... and the answer resumes the authorize request', str_starts_with($twoFactorTo, '/oauth/authorize?'), $twoFactorTo);
$twoFactorQuery = [];
parse_str((string) parse_url($twoFactorTo, PHP_URL_QUERY), $twoFactorQuery);
ms_test_same('7f. ... for the same client', $clientA, $twoFactorQuery['client_id'] ?? null);

$response = $twoFactor->get(ms_oa_probe_path($twoFactorTo));
ms_test_same('7g. the resumed page renders', 200, $response['status']);
ms_test_check('7h. ... for the app that asked', str_contains($response['body'], 'Example App'));

// ---------------------------------------------------------------------
// 8. Who may approve
// ---------------------------------------------------------------------

ms_test_section('8. Non-Pro, suspended and over-cap accounts get no consent page');

$regular = new MsOaBrowser($mainPort);
ms_oa_sign_in($pdo, $regular, $userRegular);
$response = $regular->get(ms_oa_authorize_path($params));
ms_test_same('8a. a non-Pro account is refused', 403, $response['status']);
ms_test_check('8b. ... and told why', str_contains($response['body'], 'needs Pro'));
ms_test_same('8c. ... with no redirect anywhere', null, $response['headers']['location'] ?? null);
ms_test_check('8d. ... and no form at all', !str_contains($response['body'], 'name="csrf"'));

$pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $userPro]);
$response = $pro->get(ms_oa_authorize_path($params));
ms_test_same('8e. a suspended account\'s session is signed out', 302, $response['status']);
ms_test_same('8f. ... and it is sent to log in, as everywhere else', 'pro_login.php', $response['headers']['location'] ?? null);
$pdo->prepare('UPDATE pro_users SET suspended_at = NULL WHERE id = ?')->execute([$userPro]);
// The suspension signed that browser out; sign it back in for the rest.
ms_oa_sign_in($pdo, $pro, $userPro);

// The cap: ten grants, the eleventh app refused — but re-authorising one of
// the ten is not an eleventh app.
for ($i = 1; $i <= OAUTH_GRANT_MAX_PER_USER; $i++) {
    $capClient = ms_oa_seed_client($pdo, 'Cap app ' . $i);
    $pdo->prepare('INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at, oauth_client_id, grant_created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?)')
        ->execute([$userCapped, 'Cap app ' . $i, hash('sha256', 'cap-' . $i), 'msk_cap', 'read', date('Y-m-d H:i:s'), $capClient, date('Y-m-d H:i:s')]);
    if ($i === 1) {
        $oneOfTheTen = $capClient;
    }
}
$capped = new MsOaBrowser($mainPort);
ms_oa_sign_in($pdo, $capped, $userCapped);
$response = $capped->get(ms_oa_authorize_path(ms_oa_query($clientB)));
ms_test_same('8g. an eleventh app is refused', 403, $response['status']);
ms_test_check('8h. ... and pointed at Connected apps', str_contains($response['body'], 'pro_profile_page.php#settings-security'));
$response = $capped->get(ms_oa_authorize_path(ms_oa_query($oneOfTheTen)));
ms_test_same('8i. re-authorising one of the ten is allowed', 200, $response['status']);

// ---------------------------------------------------------------------
// 9. The decision: CSRF and scope
// ---------------------------------------------------------------------

ms_test_section('9. The decision is CSRF-bound, and the scope may only narrow');

/** GET the consent page for $browser and return [csrf, status]. */
$msOaConsent = static function (MsOaBrowser $browser, array $params): array {
    $response = $browser->get(ms_oa_authorize_path($params));
    return [ms_oa_csrf_from($response['body']), $response];
};

[$csrf, $consent] = $msOaConsent($pro, $params);
ms_test_check('9a. the consent page carries a token', $csrf !== null);

$response = $pro->post('/oauth_authorize.php', ['decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9b. a missing CSRF token is refused', 403, $response['status']);
ms_test_same('9c. ... with no code', null, $response['headers']['location'] ?? null);

$response = $pro->post('/oauth_authorize.php', ['csrf' => str_repeat('0', 64), 'decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9d. a wrong CSRF token is refused', 403, $response['status']);

// A token from another browser's session is not this session's token: the
// other browser is signed in as the same account and holds its own token.
$otherBrowser = new MsOaBrowser($mainPort);
ms_oa_sign_in($pdo, $otherBrowser, $userPro);
[$otherCsrf, $otherConsent] = $msOaConsent($otherBrowser, $params);
ms_test_check('9e. the second session has a token of its own', is_string($otherCsrf) && $otherCsrf !== $csrf, (string) $otherCsrf);
$response = $otherBrowser->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9f. one session\'s token does not work in another', 403, $response['status']);

// A token minted for one request cannot be replayed against another: the
// second browser now holds a pending request for a different client.
$response = $otherBrowser->get(ms_oa_authorize_path(ms_oa_query($clientB)));
ms_test_same('9g. the second session can start a request of its own', 200, $response['status']);
$response = $otherBrowser->post('/oauth_authorize.php', ['csrf' => (string) $otherCsrf, 'decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9h. a token from another request is refused', 403, $response['status']);

// Cancel: access_denied, no code.
// Cancel: access_denied, no code.
[$csrf] = $msOaConsent($pro, $params);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'cancel', 'scope' => 'mcp:read']);
ms_test_same('9i. cancelling answers 302', 302, $response['status']);
$query = ms_oa_location_query($response);
ms_test_same('9j. ... with access_denied', 'access_denied', $query['error'] ?? null);
ms_test_same('9k. ... the state', MS_OA_STATE, $query['state'] ?? null);
ms_test_same('9l. ... and iss', MS_TEST_ORIGIN, $query['iss'] ?? null);
ms_test_check('9m. cancelling issues no code', !isset($query['code']));

// Allow, narrowing write to read.
[$csrf] = $msOaConsent($pro, $params);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9n. allowing answers 302', 302, $response['status']);
$query = ms_oa_location_query($response);
$code = (string) ($query['code'] ?? '');
ms_test_same('9o. ... with a 64-hex code', 1, preg_match('/^[a-f0-9]{64}$/', $code));
ms_test_same('9p. ... the state', MS_OA_STATE, $query['state'] ?? null);
ms_test_same('9q. ... and iss', MS_TEST_ORIGIN, $query['iss'] ?? null);
ms_test_same('9r. the narrowed scope is what was stored', 'mcp:read', ms_oa_scalar($pdo, 'SELECT scopes FROM oauth_authorization_codes WHERE code_hash = ?', [oauthHash($code)]));
ms_test_same('9s. the code row names the account', $userPro, (int) ms_oa_scalar($pdo, 'SELECT pro_user_id FROM oauth_authorization_codes WHERE code_hash = ?', [oauthHash($code)]));
ms_test_check('9t. only the code\'s hash is stored',
    (int) ms_oa_scalar($pdo, 'SELECT COUNT(*) FROM oauth_authorization_codes WHERE code_hash = ?', [$code]) === 0);

// Replaying the answered form changes nothing.
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9u. a replayed decision is refused', 400, $response['status']);
ms_test_check('9v. ... with no second code', !isset(ms_oa_location_query($response)['code']));

// Upgrading is impossible: a read-only request cannot be answered with write.
$readParams = ms_oa_query($clientA, ['scope' => 'mcp:read']);
[$readCsrf, $readConsent] = $msOaConsent($pro, $readParams);
ms_test_check('9w. a read-only consent page has a token', $readCsrf !== null);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $readCsrf, 'decision' => 'allow', 'scope' => 'mcp:read mcp:write']);
ms_test_same('9x. asking for write on a read request is refused', 400, $response['status']);
ms_test_check('9y. ... with no code', !isset(ms_oa_location_query($response)['code']));

[$readCsrf] = $msOaConsent($pro, $readParams);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $readCsrf, 'decision' => 'allow', 'scope' => 'mcp:admin']);
ms_test_same('9z. an unknown scope is refused', 400, $response['status']);

// Nothing chosen at all is the narrowest scope, never a wider one.
[$readCsrf] = $msOaConsent($pro, $readParams);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $readCsrf, 'decision' => 'allow', 'scope' => '']);
ms_test_same('9aa. an empty scope is accepted as read', 302, $response['status']);
$emptyCode = (string) (ms_oa_location_query($response)['code'] ?? '');
ms_test_same('9ab. ... and stored as read', 'mcp:read', ms_oa_scalar($pdo, 'SELECT scopes FROM oauth_authorization_codes WHERE code_hash = ?', [oauthHash($emptyCode)]));

// A POST with no consent page behind it, a decision that is neither, and a
// browser that never had a session at all.
$response = $pro->post('/oauth_authorize.php', ['csrf' => str_repeat('a', 64), 'decision' => 'allow', 'scope' => 'mcp:read']);
ms_test_same('9ac. a POST with no pending request is refused', 400, $response['status']);
[$csrf] = $msOaConsent($pro, $params);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'maybe', 'scope' => 'mcp:read']);
ms_test_same('9ad. a decision that is neither allow nor cancel is refused', 400, $response['status']);
$expiredBrowser = new MsOaBrowser($mainPort);
$expiredBrowser->get(ms_oa_authorize_path($params));
$response = $expiredBrowser->request('POST', '/oauth_authorize.php', [], []);
ms_test_same('9ae. a POST from a browser with no session is refused', 400, $response['status']);

// ---------------------------------------------------------------------
// 10. The code #333 exchanges, into a working mcp.php call
// ---------------------------------------------------------------------

ms_test_section('10. The issued code round-trips through the real token endpoint');

[$csrf] = $msOaConsent($pro, $params);
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'allow', 'scope' => 'mcp:read mcp:write']);
$query = ms_oa_location_query($response);
$issuedCode = (string) ($query['code'] ?? '');
ms_test_check('10a. a full-scope code was issued', preg_match('/^[a-f0-9]{64}$/', $issuedCode) === 1);

$tokenResponse = $pro->post('/oauth_token.php', [
    'grant_type' => 'authorization_code',
    'code' => $issuedCode,
    'redirect_uri' => MS_OA_REDIRECT,
    'client_id' => $clientA,
    'code_verifier' => MS_OA_VERIFIER,
]);
$tokenBody = json_decode($tokenResponse['body'], true);
ms_test_same('10b. /oauth/token exchanges it for 200', 200, $tokenResponse['status']);
ms_test_check('10c. ... with an access token', is_array($tokenBody) && isset($tokenBody['access_token']), substr($tokenResponse['body'], 0, 200));
$accessToken = (string) ($tokenBody['access_token'] ?? '');
ms_test_same('10d. ... and the scope the user chose', 'mcp:read mcp:write', $tokenBody['scope'] ?? null);

// The JSON-RPC body goes verbatim, so it goes through a raw request.
$raw = ms_oa_mcp_call($mainPort, $accessToken, 1);
ms_test_same('10e. the access token drives mcp.php', 200, $raw['status']);
$mcpBody = json_decode($raw['body'], true);
$names = array_column(is_array($mcpBody['result']['tools'] ?? null) ? $mcpBody['result']['tools'] : [], 'name');
ms_test_check('10f. ... and sees the write tools the scope allows', in_array('create_sticky_address', $names, true), implode(',', $names));

// A read-only grant sees no write tool.
[$csrf] = $msOaConsent($pro, ms_oa_query($clientA, ['scope' => 'mcp:read']));
$response = $pro->post('/oauth_authorize.php', ['csrf' => (string) $csrf, 'decision' => 'allow', 'scope' => 'mcp:read']);
$readCode = (string) (ms_oa_location_query($response)['code'] ?? '');
$tokenResponse = $pro->post('/oauth_token.php', [
    'grant_type' => 'authorization_code',
    'code' => $readCode,
    'redirect_uri' => MS_OA_REDIRECT,
    'client_id' => $clientA,
    'code_verifier' => MS_OA_VERIFIER,
]);
$readToken = (string) (json_decode($tokenResponse['body'], true)['access_token'] ?? '');
$raw = ms_oa_mcp_call($mainPort, $readToken, 2);
$mcpBody = json_decode($raw['body'], true);
$names = array_column(is_array($mcpBody['result']['tools'] ?? null) ? $mcpBody['result']['tools'] : [], 'name');
ms_test_check('10g. a read-only grant sees no write tool', !in_array('create_sticky_address', $names, true), implode(',', $names));

// ---------------------------------------------------------------------
// 11. Method and transport
// ---------------------------------------------------------------------

ms_test_section('11. Only GET and POST answer');

foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'] as $httpMethod) {
    $response = $pro->request($httpMethod, '/oauth_authorize.php');
    ms_test_same("11a.{$httpMethod}: 405", 405, $response['status']);
    ms_test_same("11b.{$httpMethod}: names GET and POST", 'GET, POST', $response['headers']['allow'] ?? null);
}

// ---------------------------------------------------------------------
// 12. The rate limit
// ---------------------------------------------------------------------

ms_test_section('12. The authorize endpoint is rate limited per IP');

$rateProbe = ms_oa_build_probe($msRepoRoot, [
    'oauth_authorize_ip_hour' => 3,
    'oauth_authorize_ip_day' => 1000,
    'oauth_authorize_user_hour' => 1000,
    'oauth_authorize_user_day' => 1000,
]);
$rateServer = ms_oa_start_server($rateProbe);
$ratePort = $rateServer[1];
$ratePdo = ms_test_db(ms_test_probe_sqlite($rateProbe));
$rateClient = ms_oa_seed_client($ratePdo, 'Rate App');
$rateBrowser = new MsOaBrowser($ratePort);

for ($i = 1; $i <= 3; $i++) {
    $response = $rateBrowser->get(ms_oa_authorize_path(ms_oa_query($rateClient)));
    ms_test_same("12a. request {$i} of 3 is served", 302, $response['status']);
}
$response = $rateBrowser->get(ms_oa_authorize_path(ms_oa_query($rateClient)));
ms_test_same('12b. the request after the limit is 429', 429, $response['status']);
$retryAfter = (int) ($response['headers']['retry-after'] ?? 0);
ms_test_check('12c. ... with a Retry-After pointing at the end of the window', $retryAfter >= 1 && $retryAfter <= 3600, 'Retry-After: ' . $retryAfter);
$counted = (int) ms_oa_scalar($ratePdo, "SELECT COALESCE(SUM(hits), 0) FROM abuse_counters WHERE scope = 'oauth_authorize_ip'");
ms_test_check('12d. every attempt was counted, the refused one included', $counted >= 4, 'counted: ' . $counted);
ms_test_check('12e. the counter is keyed on a hash, never the address', (int) ms_oa_scalar($ratePdo, "SELECT COUNT(*) FROM abuse_counters WHERE subject LIKE '%127.0.0.1%'") === 0);

// The per-account half only applies once there is an account to key on.
$userRateProbe = ms_oa_build_probe($msRepoRoot, [
    'oauth_authorize_ip_hour' => 1000,
    'oauth_authorize_ip_day' => 1000,
    'oauth_authorize_user_hour' => 2,
    'oauth_authorize_user_day' => 1000,
]);
$userRateServer = ms_oa_start_server($userRateProbe);
$userRatePort = $userRateServer[1];
$userRatePdo = ms_test_db(ms_test_probe_sqlite($userRateProbe));
$userRateClient = ms_oa_seed_client($userRatePdo, 'User rate app');
$userRateUser = ms_oa_seed_user($userRatePdo, 'rater@example.com', 'pro');
$userRateBrowser = new MsOaBrowser($userRatePort);
$token = loginTokenCreate($userRatePdo, $userRateUser, 30);
$userRateBrowser->get('/pro_auth.php?token=' . urlencode($token));
$userRateBrowser->get(ms_oa_authorize_path(ms_oa_query($userRateClient)));
$userRateBrowser->get(ms_oa_authorize_path(ms_oa_query($userRateClient)));
$response = $userRateBrowser->get(ms_oa_authorize_path(ms_oa_query($userRateClient)));
ms_test_same('12f. the per-account limit answers 429 as well', 429, $response['status']);
ms_test_check('12g. ... keyed on the account id, never an address',
    (int) ms_oa_scalar($userRatePdo, "SELECT COUNT(*) FROM abuse_counters WHERE scope = 'oauth_authorize_user' AND subject = ?", ['user:' . $userRateUser]) > 0);

// ---------------------------------------------------------------------
// 13. Fail closed before the migration has run
// ---------------------------------------------------------------------

ms_test_section('13. Before the migration the endpoint says so');

$freshProbe = ms_oa_build_probe($msRepoRoot, $generousLimits, false);
$freshServer = ms_oa_start_server($freshProbe);
$freshPort = $freshServer[1];
$freshBrowser = new MsOaBrowser($freshPort);

$response = $freshBrowser->get('/oauth_authorize.php?client_id=' . $clientA);
ms_test_same('13a. a request before the migration is 503', 503, $response['status']);
ms_test_check('13b. ... and says so on our own origin', str_contains($response['body'], 'Not available yet'));
ms_test_same('13c. ... with no redirect', null, $response['headers']['location'] ?? null);

// ---------------------------------------------------------------------
// 14. What must never reach the log, and where a redirect may point
// ---------------------------------------------------------------------

ms_test_section('14. Nothing sensitive is logged, and the route is wired up');

$sources = [
    'oauth_authorize.php' => (string) file_get_contents($msRepoRoot . '/oauth_authorize.php'),
    'after_login.php' => (string) file_get_contents($msRepoRoot . '/after_login.php'),
];

$badLogs = [];
foreach ($sources as $file => $text) {
    if (preg_match_all('/logMessage\((?:[^();]|\([^()]*\))*\)/s', $text, $matches)) {
        foreach ($matches[0] as $call) {
            // The credentials and the request parameters this endpoint handles:
            // the authorization code, the CSRF token, the validated request and
            // the resume query. A client id and an account id are fine — the
            // token endpoint logs those too.
            if (preg_match('/\$(code|csrf|csrfToken|csrfSecret|pendingParams|resumeParams|pending|validated|location|chosenRaw)\b/i', $call)
                || preg_match('/\'(code|state|redirect_uri|error_description|csrf|code_challenge|resource)\'\s*=>/i', $call)) {
                $badLogs[] = $file . ': ' . preg_replace('/\s+/', ' ', mb_substr($call, 0, 140));
            }
        }
    }
    if (str_contains($text, 'error_log(')) {
        $badLogs[] = $file . ': uses error_log()';
    }
}
ms_test_check('14a. no log call is handed a code, a state, a CSRF token or the request', $badLogs === [], implode("\n       ", $badLogs));

$authorizeSource = $sources['oauth_authorize.php'];
ms_test_check('14b. the approval INFO line names the account, the client and the scopes',
    str_contains($authorizeSource, "logMessage('INFO', 'OAuth authorization approved',")
        && str_contains($authorizeSource, "'user_id' => \$userId")
        && str_contains($authorizeSource, "'client_id' => (string) \$authRequest['client_id']")
        && str_contains($authorizeSource, "'scopes' => oauthScopeExternalFromSet(\$chosenSet)"));
ms_test_check('14c. a refused CSRF token raises a WARNING with the IP',
    str_contains($authorizeSource, "logMessage('WARNING', 'OAuth authorization rejected: bad CSRF token',"));
ms_test_check('14d. oauth_server.php still logs nothing itself',
    preg_match('/logMessage\(|error_log\(/', (string) file_get_contents($msRepoRoot . '/oauth_server.php')) !== 1);
ms_test_check('14e. after_login.php logs nothing at all',
    preg_match('/logMessage\(|error_log\(/', $sources['after_login.php']) !== 1);

// The allow-list is the only thing that can become a Location header.
ms_test_check('14f. afterLoginUrl() can only ever return an allow-listed path',
    preg_match_all("/'(oauth_authorize)'\s*=>\s*'(\/[^']*)'/", $sources['after_login.php'], $routes) === 1
        && $routes[1] === ['oauth_authorize']
        && $routes[2] === ['/oauth/authorize']);

// Every Location the endpoint writes is either the login page or the one
// variable built from the proven redirect_uri — never a request parameter.
preg_match_all("/header\(\s*'Location: ([^']*)'/", $authorizeSource, $locations);
ms_test_same('14g. the endpoint writes exactly three Locations: the helper, and login twice',
    ['', 'pro_login.php', 'pro_login.php'], $locations[1]);
ms_test_check('14h. ... and the one variable is the helper\'s own',
    str_contains($authorizeSource, "header('Location: ' . \$location, true, 302);"));
ms_test_check('14i. the redirect target never comes from the request',
    preg_match('/\$_(GET|POST|REQUEST)\[\s*[\'"]redirect_uri[\'"]/', $authorizeSource) !== 1);

$htaccess = (string) file_get_contents($msRepoRoot . '/.htaccess');
ms_test_check('14j. .htaccess rewrites /oauth/authorize to the endpoint', str_contains($htaccess, 'RewriteRule ^oauth/authorize/?$ oauth_authorize.php'));
ms_test_check('14k. robots.txt disallows /oauth/', str_contains((string) file_get_contents($msRepoRoot . '/robots.txt'), 'Disallow: /oauth/'));
ms_test_check('14l. pro_auth.php resumes through afterLoginTake() on all four paths',
    substr_count((string) file_get_contents($msRepoRoot . '/pro_auth.php'), 'afterLoginTake()') === 4);
ms_test_check('14m. pro_login.php resumes through afterLoginTake() on both paths',
    substr_count((string) file_get_contents($msRepoRoot . '/pro_login.php'), 'afterLoginTake()') === 2);

// ---------------------------------------------------------------------
// 15. Nothing tripped a PHP warning
// ---------------------------------------------------------------------

ms_test_section('15. The endpoints answer without a PHP warning, notice or fatal');

foreach ([
    'main probe' => $mainServer,
    'rate-limit probe' => $rateServer,
    'user rate-limit probe' => $userRateServer,
    'pre-migration probe' => $freshServer,
] as $label => $server) {
    $noise = [];
    foreach (explode("\n", (string) stream_get_contents($server[2][2])) as $line) {
        if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $line) === 1) {
            $noise[] = trim($line);
        }
    }
    ms_test_check("15.{$label}: no PHP warning, notice or fatal", $noise === [], implode(' | ', array_slice($noise, 0, 3)));
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_oa_stop_server($mainServer);
ms_oa_stop_server($rateServer);
ms_oa_stop_server($userRateServer);
ms_oa_stop_server($freshServer);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($mainProbe);
    ms_test_cleanup($rateProbe);
    ms_test_cleanup($userRateProbe);
    ms_test_cleanup($freshProbe);
} else {
    echo "Probe docroots left in place for inspection: {$mainProbe}, {$rateProbe}, {$userRateProbe}, {$freshProbe}\n";
}
exit($exitCode);
