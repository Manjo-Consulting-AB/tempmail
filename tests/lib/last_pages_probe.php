<?php
/**
 * Probe helpers for the last three pages of epic #428 (step 7/8): welcome.php,
 * pro_contact.php (both rendered through the sign-in probe docroot of
 * tests/lib/auth_probe.php) and the OAuth consent page (oauth_authorize.php,
 * which refuses the CLI, so it is served by PHP's built-in web server from a
 * docroot of tests/lib/pushover_harness.php). Shared by tests/i18n_test.php
 * and the fixture generation (tests/fixtures/i18n/welcome.*, contact.*,
 * consent.*). Nothing here is deployed.
 */

require_once __DIR__ . '/auth_probe.php';
require_once __DIR__ . '/pushover_harness.php';

/**
 * welcome.php at $uri — '/welcome.php', '/sv/welcome.php' — as a visitor or as
 * alice. $locales is the LOCALES_ENABLED value.
 *
 * @return array{html:string,err:string,log:string}
 */
function ms_welcome_page(string $root, string $uri, bool $signedIn, string $locales = 'en'): array
{
    [$db, $ids] = ms_auth_probe_db($root);
    $request = ['method' => 'GET', 'uri' => $uri, 'get' => [], 'locales' => $locales];
    if ($signedIn) {
        $id = 'probesess' . bin2hex(random_bytes(4));
        ms_auth_probe_session($root, $id, ['pro_user_id' => $ids['alice'], 'pro_user_email' => 'alice@example.com']);
        $request['session_id'] = $id;
    }
    $run = ms_auth_probe_run($root, 'welcome.php', $request, $db);
    @unlink($db);
    @unlink($db . '.log');
    return ['html' => $run['out'], 'err' => $run['err'], 'log' => $run['log']];
}

/**
 * [case name => [POST fields, origin ('ok'|'bad')]] for pro_contact.php's
 * answers. The names are the fixture names
 * (tests/fixtures/i18n/contact.<name>.en.json).
 *
 * @return array<string,array{0:array<string,string>,1:string}>
 */
function ms_contact_json_cases(): array
{
    return [
        'sent' => [['category' => 'Question', 'message' => 'Hello there'], 'ok'],
        'invalid_category' => [['category' => 'Nope', 'message' => 'Hello'], 'ok'],
        'message_required' => [['category' => 'Bug report', 'message' => '   '], 'ok'],
        'too_long' => [['category' => 'Suggestion', 'message' => str_repeat('x', 5001)], 'ok'],
        'forbidden' => [['category' => 'Question', 'message' => 'Hello'], 'bad'],
    ];
}

/**
 * Posts one ms_contact_json_cases() entry to pro_contact.php as alice.
 * $locale is sent as X-MS-Locale, $acceptLanguage as Accept-Language,
 * $accountLocale is written to pro_users.locale and $svApp offers Swedish in
 * the app area (the '@app' gate). 'mail' is what the operator's mail looked
 * like (sendmail_path writes it to mail.log).
 *
 * @param array<string,string> $post
 * @return array{body:string,json:?array,err:string,mail:string}
 */
function ms_contact_json(string $root, array $post, string $origin = 'ok', ?string $locale = null, bool $svApp = false, string $acceptLanguage = '', ?string $accountLocale = null): array
{
    [$db, $ids] = ms_auth_probe_db($root, function (PDO $pdo, array $ids) use ($accountLocale): void {
        if ($accountLocale !== null) {
            $pdo->prepare('UPDATE pro_users SET locale = ? WHERE id = ?')->execute([$accountLocale, $ids['alice']]);
        }
    });
    $request = ['method' => 'POST', 'uri' => '/pro_contact.php', 'post' => $post, 'origin' => $origin, 'locales' => $svApp ? 'en,sv' : 'en', 'is_pro' => true, 'app_sv' => $svApp];
    $server = [];
    if ($locale !== null) {
        $server['HTTP_X_MS_LOCALE'] = $locale;
    }
    if ($acceptLanguage !== '') {
        $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
    }
    if ($server) {
        $request['server'] = $server;
    }
    $sid = 'probesess' . bin2hex(random_bytes(4));
    ms_auth_probe_session($root, $sid, ['pro_user_id' => $ids['alice'], 'pro_user_email' => 'alice@example.com']);
    $request['session_id'] = $sid;
    @unlink($root . '/mail.log');
    $run = ms_auth_probe_run($root, 'pro_contact.php', $request, $db);
    @unlink($db);
    @unlink($db . '.log');
    $body = trim($run['out']);
    return ['body' => $body, 'json' => json_decode($body, true), 'err' => $run['err'], 'mail' => (string) @file_get_contents($root . '/mail.log')];
}

// ---------------------------------------------------------------------
// The OAuth consent page
// ---------------------------------------------------------------------

const MS_OC_REDIRECT = 'https://example.com/cb';
const MS_OC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const MS_OC_APP_NAME = 'Example App';
/** A client id that is well formed but registered nowhere. */
const MS_OC_UNKNOWN_CLIENT = '0123456789abcdef0123456789abcdef';

/**
 * A probe docroot for oauth_authorize.php: the harness stub config with the
 * language gates read from the environment (PROBE_LOCALES, PROBE_APP_SV), the
 * two suspension helpers the page asks config.php for, and the OAuth tables.
 * Seeded: user 1 (Pro), user 2 (Regular), user 3 (Pro, account language
 * Swedish), user 4 (Pro, account language English) and the client
 * MS_OC_APP_NAME (id in the returned array).
 *
 * @return array{root:string,client:string,pro:int,regular:int,svAccount:int,enAccount:int}
 */
function ms_oc_build(string $repoRoot, string $clientName = MS_OC_APP_NAME, string $redirectUri = MS_OC_REDIRECT): array
{
    $root = ms_test_probe_build($repoRoot);
    foreach (['oauth_authorize.php', 'oauth_server.php', 'after_login.php', 'mcp_tokens.php', 'abuse_guard.php'] as $file) {
        copy($repoRoot . '/' . $file, $root . '/' . $file);
    }
    $config = ms_test_stub_config_php();
    $config = str_replace(
        "'i18n' => ['enabled' => ['en']],",
        '\'i18n\' => [\'enabled\' => array_values(array_filter(array_map(\'trim\', explode(\',\', (string) (getenv(\'PROBE_LOCALES\') ?: \'en\')))))],',
        $config
    );
    $config .= <<<'PHP'

// Only when a case asks: the '@app' gate forced open (1) or closed (0), instead of the shipped list (I18N.md 2.1).
if (getenv('PROBE_APP_SV') !== false) {
    i18nTranslatedPages(array_merge(i18nTranslatedPages(), ['@app' => getenv('PROBE_APP_SV') === '1' ? ['en', 'sv'] : ['en']]));
}
function proUserIsSuspended(int $userId): bool { return false; }
function proSessionEndIfSuspended(): bool { return false; }
PHP;
    file_put_contents($root . '/config.php', $config);

    $pdo = ms_test_db(ms_test_probe_sqlite($root));
    $pdo->exec('ALTER TABLE pro_users ADD COLUMN locale TEXT NULL');
    $pdo->exec('CREATE TABLE mcp_access_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, pro_user_id INTEGER NOT NULL, name TEXT NOT NULL, token_hash TEXT NOT NULL,
        token_prefix TEXT NOT NULL, scopes TEXT NOT NULL DEFAULT \'read\', created_at TEXT NOT NULL, last_used_at TEXT NULL,
        expires_at TEXT NULL, revoked_at TEXT NULL, oauth_client_id TEXT NULL, refresh_token_hash TEXT NULL,
        refresh_expires_at TEXT NULL, grant_created_at TEXT NULL, rotated_refresh_hash TEXT NULL, rotated_at TEXT NULL)');
    $pdo->exec('CREATE TABLE oauth_clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL, client_name TEXT NOT NULL, redirect_uris TEXT NOT NULL,
        created_at TEXT NOT NULL, last_used_at TEXT NULL, registered_ip_hash TEXT NULL)');
    $pdo->exec('CREATE TABLE oauth_authorization_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, code_hash TEXT NOT NULL, client_id TEXT NOT NULL, pro_user_id INTEGER NOT NULL,
        redirect_uri TEXT NOT NULL, scopes TEXT NOT NULL, code_challenge TEXT NOT NULL, resource TEXT NOT NULL,
        expires_at TEXT NOT NULL, used_at TEXT NULL)');
    $ins = $pdo->prepare('INSERT INTO pro_users (email, account_type, pro_expires_at, locale) VALUES (?, ?, NULL, ?)');
    $ins->execute(['pro@example.com', 'pro', null]);
    $pro = (int) $pdo->lastInsertId();
    $ins->execute(['regular@example.com', 'regular', null]);
    $regular = (int) $pdo->lastInsertId();
    $ins->execute(['sv@example.com', 'pro', 'sv']);
    $svAccount = (int) $pdo->lastInsertId();
    $ins->execute(['en@example.com', 'pro', 'en']);
    $enAccount = (int) $pdo->lastInsertId();
    $client = ms_oc_add_client($pdo, $clientName, $redirectUri);
    return ['root' => $root, 'client' => $client, 'pro' => $pro, 'regular' => $regular, 'svAccount' => $svAccount, 'enAccount' => $enAccount];
}

/** Registers a public client the way oauthClientRegister() stores one; returns its client_id. */
function ms_oc_add_client(PDO $pdo, string $name, string $redirectUri = MS_OC_REDIRECT): string
{
    $clientId = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$clientId, $name, json_encode([$redirectUri]), date('Y-m-d H:i:s')]);
    return $clientId;
}

/**
 * Starts PHP's built-in server on the docroot with the language gates in its
 * environment. Returns [process, port, pipes].
 *
 * @return array{0:resource,1:int,2:array<int,resource>}
 */
function ms_oc_start(string $root, string $locales = 'en', ?bool $appSv = null): array
{
    $sessions = $root . '/sessions';
    if (!is_dir($sessions)) {
        @mkdir($sessions, 0700, true);
    }
    $env = ['PROBE_SQLITE' => ms_test_probe_sqlite($root), 'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'), 'PROBE_LOCALES' => $locales];
    if ($appSv !== null) {
        $env['PROBE_APP_SV'] = $appSv ? '1' : '0';
    }
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'session.save_path=' . $sessions, '-S', "127.0.0.1:{$port}", '-t', $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
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
        if (proc_get_status($proc)['running']) {
            return [$proc, $port, $pipes];
        }
        proc_close($proc);
    }
    throw new RuntimeException("Could not start PHP's built-in server for {$root}");
}

function ms_oc_stop(array $server): void
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
 * One HTTP request, redirects never followed. $session signs the request in as
 * that user id (a session file is written for it). Header names come back
 * lower-cased; every value of a repeated header is joined with ", ".
 *
 * @param array<string,string> $headers
 * @param array<string,string>|null $form
 * @return array{status:int,body:string,headers:array<string,string>}
 */
function ms_oc_http(string $root, int $port, string $method, string $path, ?int $session = null, array $headers = [], ?array $form = null, ?string $cookieSid = null): array
{
    $lines = '';
    $sid = $cookieSid;
    if ($session !== null && $sid === null) {
        $sid = 'ocsess' . bin2hex(random_bytes(6));
        file_put_contents($root . '/sessions/sess_' . $sid, 'pro_user_id|' . serialize($session) . 'pro_user_email|' . serialize('x@example.com'));
    }
    if ($sid !== null) {
        $lines .= "Cookie: PHPSESSID={$sid}\r\n";
    }
    $body = '';
    if ($form !== null) {
        $body = http_build_query($form);
        $lines .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    foreach ($headers as $name => $value) {
        $lines .= $name . ': ' . $value . "\r\n";
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $lines, 'content' => $body, 'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0]]);
    $responseBody = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
            $out = [];
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $out[$name] = isset($out[$name]) ? $out[$name] . ', ' . $value : $value;
        }
    }
    return ['status' => $status, 'body' => $responseBody === false ? '' : $responseBody, 'headers' => $out];
}

/** A session file for user $uid; the id to send as $cookieSid so several requests share one session. */
function ms_oc_session(string $root, int $uid): string
{
    $sid = 'ocsess' . bin2hex(random_bytes(6));
    file_put_contents($root . '/sessions/sess_' . $sid, 'pro_user_id|' . serialize($uid) . 'pro_user_email|' . serialize('x@example.com'));
    return $sid;
}

/** The authorize query string for $clientId; $overrides replaces a field, null drops it. */
function ms_oc_path(string $clientId, array $overrides = []): string
{
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => MS_OC_REDIRECT,
        'response_type' => 'code',
        'code_challenge' => MS_OC_CHALLENGE,
        'code_challenge_method' => 'S256',
        'scope' => 'mcp:read',
        'state' => 'st-abc-123',
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return '/oauth_authorize.php?' . http_build_query($params);
}

/** The consent page's volatile parts replaced, so a page can be compared with a fixture. */
function ms_oc_norm(string $html): string
{
    $html = preg_replace('/\?v=\d+/', '?v=0', $html);
    $html = preg_replace('/(name="csrf" value=")[0-9a-f]+"/', '$1{csrf}"', $html);
    $html = str_replace('&copy; ' . date('Y'), '&copy; {year}', $html);
    $html = preg_replace('#<script>.*?</script>#s', '<script></script>', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $html));
}

/**
 * [fixture name => [kind, arguments]] for the consent page's English states:
 * the page with read and with read+write asked for, the two fatal error pages
 * and three of the refusals. 'kind' is 'get' (query overrides, user key),
 * 'put' (a method the page refuses) or 'post_expired' (a decision with
 * nothing waiting).
 *
 * @return array<string,array{0:string,1:array<string,string|null>,2:string}>
 */
function ms_oc_cases(): array
{
    return [
        'read' => ['get', ['scope' => 'mcp:read'], 'pro'],
        'write' => ['get', ['scope' => 'mcp:read mcp:write'], 'pro'],
        'error.unknown_client' => ['get', ['client_id' => MS_OC_UNKNOWN_CLIENT], 'pro'],
        'error.redirect_mismatch' => ['get', ['redirect_uri' => 'https://evil.example/cb'], 'pro'],
        'error.not_pro' => ['get', [], 'regular'],
        'error.method' => ['put', [], 'pro'],
        'error.expired' => ['post_expired', [], 'pro'],
    ];
}

/**
 * Renders one ms_oc_cases() state against a running server as the probe user
 * $user (default: the case's own; "svAccount" / "enAccount" for an account language).
 *
 * @param array<string,mixed> $probe ms_oc_build() result
 * @param array<string,string> $headers
 * @return array{status:int,body:string,headers:array<string,string>}
 */
function ms_oc_render(array $probe, int $port, string $name, array $headers = [], ?string $user = null): array
{
    [$kind, $query, $userKey] = ms_oc_cases()[$name];
    $uid = (int) $probe[$user ?? $userKey];
    $root = (string) $probe['root'];
    if ($kind === 'put') {
        return ms_oc_http($root, $port, 'PUT', ms_oc_path((string) $probe['client']), $uid, $headers);
    }
    if ($kind === 'post_expired') {
        return ms_oc_http($root, $port, 'POST', '/oauth_authorize.php', $uid, $headers, ['csrf' => 'x', 'decision' => 'allow']);
    }
    return ms_oc_http($root, $port, 'GET', ms_oc_path((string) $probe['client'], $query), $uid, $headers);
}
