<?php

declare(strict_types=1);

/**
 * Regression coverage for OAuth grants in the "Connected apps" card (epic
 * #331, step 4/6): mcp_tokens.php's grant-aware listing and cleanup,
 * oauth_server.php's code/client sweep, the two groups of pro_profile_page.php,
 * and that every credential-removal path takes a grant with it.
 *
 * Run with:  php tests/oauth_grants_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network beyond
 * 127.0.0.1, and PHP's built-in web server is what serves the real mcp.php:
 *
 *   A. the libraries on SQLite — mcpTokenList() tells a grant from a manual
 *      token (and keeps the same shape when the OAuth columns are absent),
 *      revoking a grant kills both of its credentials, and mcpTokenCleanup()
 *      never removes a row whose refresh token can still be used.
 *   B. the real pro_profile.php actions and pro_profile_page.php through the
 *      shared probe docroot — the list carries the grant fields, an app's own
 *      (attacker-controlled) name is transported as data and inserted with
 *      .text(), revoking a grant works, and the card renders both groups.
 *   C. the real mcp.php over PHP's built-in web server — a grant's access token
 *      drives the endpoint until the grant is revoked through the profile, and
 *      answers 401 straight after.
 *   D. the real pro_auth.php as subprocesses — a password change, an email
 *      change, both undo links and an account deletion each revoke the
 *      account's OAuth grants too (mcpTokensRevokeAllFor() must not let a
 *      grant row escape its WHERE).
 *   E. wiring: the cron sweep and the audit, and no log line handed a name.
 */

$repoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

date_default_timezone_set('UTC');
require $repoRoot . '/mcp_tokens.php';
require $repoRoot . '/oauth_server.php';
require __DIR__ . '/lib/pushover_harness.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "\n       {$detail}" : '') . "\n";
    }
}

/**
 * mcp_access_tokens as migrate_mcp_tokens.php + migrate_oauth.php leave it,
 * plus the two OAuth tables. Deliberately no pro_users: the probe docroot
 * already has one (ms_test_schema()), and the orphan branch of the sweep joins
 * against it — each caller creates its own. SQLite, so every function under
 * test runs with an explicit clock.
 */
function ms_og_schema(): string
{
    return <<<'SQL'
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
SQL;
}

/** The pre-#331 table: no grant columns, so nothing here can be a grant. */
function ms_og_schema_without_grants(): string
{
    return <<<'SQL'
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
SQL;
}

function ms_og_db(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/** Executes a multi-statement SQL string; PDO's SQLite driver takes one at a time. */
function ms_og_exec(PDO $pdo, string $sql): void
{
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/**
 * Stores one grant row directly, so the cleanup tests can place its two
 * expiries wherever they need them. Returns the plaintext credentials, which
 * exist nowhere else.
 */
function ms_og_insert_grant(PDO $pdo, int $userId, array $overrides = []): array
{
    $access = mcpTokenGenerate();
    $refresh = oauthRefreshTokenGenerate();
    $now = (int) ($overrides['now'] ?? time());
    $clientId = (string) ($overrides['client_id'] ?? str_repeat('a', 32));
    $pdo->prepare('INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at, oauth_client_id, refresh_token_hash, refresh_expires_at, grant_created_at, rotated_refresh_hash, rotated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, NULL, NULL)')
        ->execute([
            $userId,
            (string) ($overrides['name'] ?? 'Connected app'),
            mcpTokenHash($access),
            mcpTokenPrefixOf($access),
            (string) ($overrides['scopes'] ?? 'read'),
            (string) ($overrides['created_at'] ?? date('Y-m-d H:i:s', $now)),
            $overrides['expires_at'] ?? null,
            $overrides['revoked_at'] ?? null,
            $clientId,
            oauthHash($refresh),
            $overrides['refresh_expires_at'] ?? null,
            (string) ($overrides['grant_created_at'] ?? date('Y-m-d H:i:s', $now)),
        ]);
    return ['id' => (int) $pdo->lastInsertId(), 'access' => $access, 'refresh' => $refresh, 'client_id' => $clientId];
}

/** One row by id, or null — the "is it still there?" read the cleanup checks use. */
function ms_og_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mcp_access_tokens WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// The entitlement doubles oauth_server.php and mcpTokenResolve() consult.
$GLOBALS['og_pro'] = [];
$GLOBALS['og_suspended'] = [];
function proUserIsPro(int $userId): bool
{
    return (bool) ($GLOBALS['og_pro'][$userId] ?? true);
}
function proUserIsSuspended(int $userId): bool
{
    return (bool) ($GLOBALS['og_suspended'][$userId] ?? false);
}

$tmpDb = sys_get_temp_dir() . '/ms_oauth_grants_' . bin2hex(random_bytes(6));

// ---------------------------------------------------------------------------
echo "A. mcp_tokens.php and oauth_server.php on SQLite\n";
// ---------------------------------------------------------------------------

$t0 = strtotime('2026-03-01 12:00:00');

// The RFC 7636 Appendix B vector, so a grant minted here is one the real
// clients mint too.
$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$redirectUri = 'https://app.example/cb';

$grantDb = ms_og_db($tmpDb . '-grants.sqlite');
ms_og_exec($grantDb, ms_og_schema());
$grantDb->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
$grantDb->exec('INSERT INTO pro_users (id) VALUES (1), (2), (3)');

check('A1. the grant columns are detected', mcpTokenGrantColumnsAvailable($grantDb) === true);

$registered = oauthClientRegister($grantDb, ['client_name' => 'Example App', 'redirect_uris' => [$redirectUri]], null, $t0);
$clientId = (string) ($registered['client']['client_id'] ?? '');
check('A2. a client registers', ($registered['ok'] ?? false) === true && strlen($clientId) === 32);

$code = oauthAuthorizationCodeCreate($grantDb, $clientId, 1, $redirectUri, 'mcp:read', $challenge, '', $t0);
$exchanged = oauthAuthorizationCodeExchange($grantDb, ['code' => (string) $code, 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'code_verifier' => $verifier], $t0 + 5);
check('A3. the code exchanges into a grant', ($exchanged['ok'] ?? false) === true, json_encode($exchanged));
$grantAccess = (string) ($exchanged['access_token'] ?? '');
$grantRefresh = (string) ($exchanged['refresh_token'] ?? '');
$grantId = (int) ($exchanged['token_id'] ?? 0);
check('A4. it is an ordinary access token that resolves', mcpTokenResolve($grantDb, $grantAccess, $t0 + 10) !== null);

// A manual token beside it, so the list has one of each.
$manual = mcpTokenCreate($grantDb, 1, 'Pasted by hand', 'read,write', null, $t0 + 1);
check('A5. a manual token is created beside it', is_array($manual));

$list = mcpTokenList($grantDb, 1, $t0 + 20);
$byName = [];
foreach ($list as $entry) {
    $byName[$entry['name']] = $entry;
}
$grantEntry = $byName['Example App'] ?? null;
$manualEntry = $byName['Pasted by hand'] ?? null;
check('A6. both the grant and the manual token are listed', $grantEntry !== null && $manualEntry !== null, json_encode(array_keys($byName)));
check('A7. the grant is flagged, with the client name and the grant time',
    $grantEntry !== null && $grantEntry['is_oauth'] === true
    && $grantEntry['oauth_client_name'] === 'Example App'
    && $grantEntry['grant_created_at'] === date('Y-m-d H:i:s', $t0 + 5));
check('A8. the manual token is not a grant and carries no grant fields',
    $manualEntry !== null && $manualEntry['is_oauth'] === false
    && $manualEntry['oauth_client_name'] === null && $manualEntry['grant_created_at'] === null);
check('A9. the grant row never leaks a secret', $grantEntry !== null
    && !array_key_exists('token_hash', $grantEntry) && strpos((string) json_encode($list), $grantAccess) === false
    && strpos((string) json_encode($list), $grantRefresh) === false);

// The library is loaded without the migration too: the shape must be the same,
// every row simply not a grant.
$plainDb = ms_og_db($tmpDb . '-plain.sqlite');
ms_og_exec($plainDb, ms_og_schema_without_grants());
$plainDb->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
$plainDb->exec('INSERT INTO pro_users (id) VALUES (1)');
check('A10. without the OAuth columns the feature is unavailable', mcpTokenGrantColumnsAvailable($plainDb) === false);
$plainToken = mcpTokenCreate($plainDb, 1, 'Old token', 'read', null, $t0);
$plainList = mcpTokenList($plainDb, 1, $t0 + 1);
check('A11. ... and the list still answers, with no row a grant',
    $plainToken !== null && count($plainList) === 1
    && $plainList[0]['is_oauth'] === false && $plainList[0]['oauth_client_name'] === null
    && $plainList[0]['grant_created_at'] === null && array_key_exists('expired', $plainList[0]));
check('A12. ... and the sweep touches no column that is not there', mcpTokenCleanup($plainDb, $t0 + 1) === 0);

// Refresh works while the grant is live.
$refreshed = oauthRefreshTokenExchange($grantDb, ['refresh_token' => $grantRefresh, 'client_id' => $clientId], $t0 + 30);
check('A13. the grant refreshes while it is live', ($refreshed['ok'] ?? false) === true, json_encode($refreshed));
$rotatedRefresh = (string) ($refreshed['refresh_token'] ?? '');
check('A14. ... and hands back a new refresh token', $rotatedRefresh !== '' && $rotatedRefresh !== $grantRefresh);

// Revoking one row kills both credentials, because the row holds both.
check('A15. revoking the grant succeeds', mcpTokenRevoke($grantDb, 1, $grantId, $t0 + 40) === true);
check('A16. the access token stops resolving at once', mcpTokenResolve($grantDb, (string) ($refreshed['access_token'] ?? ''), $t0 + 41) === null);
$afterRevoke = oauthRefreshTokenExchange($grantDb, ['refresh_token' => $rotatedRefresh, 'client_id' => $clientId], $t0 + 42);
check('A17. the live refresh token answers invalid_grant', ($afterRevoke['ok'] ?? false) === false && ($afterRevoke['error'] ?? '') === 'invalid_grant', json_encode($afterRevoke));
check('A18. ... and "one grant per (user, client)" no longer sees it', oauthGrantExists($grantDb, $clientId, 1) === false);

// Revoke-all is scoped by account only, so a grant row cannot escape it.
$grantForAll = ms_og_insert_grant($grantDb, 2, ['now' => $t0]);
$manualForAll = mcpTokenCreate($grantDb, 2, 'Manual', 'read', null, $t0);
check('A19. revoke-all revokes the grant and the manual token alike',
    mcpTokenRevokeAll($grantDb, 2, $t0 + 10) === 2
    && ms_og_row($grantDb, $grantForAll['id'])['revoked_at'] !== null
    && mcpTokenResolve($grantDb, $grantForAll['access'], $t0 + 11) === null);

// Cleanup: a grant whose refresh token is still live must survive the sweep,
// whatever its access token's expiry says.
$grantDb->exec('DELETE FROM mcp_access_tokens');
$sweepAt = $t0 + MCP_TOKEN_SWEEP_DAYS * 86400 + 1;
// A grant made 100 days before the sweep, so its access token (one hour) is
// long past the grace period either way. The client is the registered one, so
// A24's refresh can actually go through.
$oldAccessLiveRefresh = ms_og_insert_grant($grantDb, 1, [
    'now' => $t0 - 100 * 86400,
    'client_id' => $clientId,
    'expires_at' => date('Y-m-d H:i:s', $t0 - 100 * 86400 + 3600),
    'refresh_expires_at' => date('Y-m-d H:i:s', $t0 + 80 * 86400),
]);
$oldAccessDeadRefresh = ms_og_insert_grant($grantDb, 1, [
    'now' => $t0 - 100 * 86400,
    'expires_at' => date('Y-m-d H:i:s', $t0 - 100 * 86400 + 3600),
    'refresh_expires_at' => date('Y-m-d H:i:s', $t0 - 40 * 86400),
]);
$revokedOld = ms_og_insert_grant($grantDb, 1, [
    'now' => $t0 - 100 * 86400,
    'expires_at' => date('Y-m-d H:i:s', $t0 - 100 * 86400 + 3600),
    'refresh_expires_at' => date('Y-m-d H:i:s', $t0 + 80 * 86400),
    'revoked_at' => date('Y-m-d H:i:s', $t0 - 100 * 86400),
]);
$orphanGrant = ms_og_insert_grant($grantDb, 999, [
    'now' => $t0 - 100 * 86400,
    'expires_at' => date('Y-m-d H:i:s', $t0 - 100 * 86400 + 3600),
    'refresh_expires_at' => date('Y-m-d H:i:s', $t0 + 80 * 86400),
]);

mcpTokenCleanup($grantDb, $sweepAt);
check('A20. the sweep keeps a grant whose refresh token is still valid', ms_og_row($grantDb, $oldAccessLiveRefresh['id']) !== null);
check('A21. it removes one whose refresh token has gone too', ms_og_row($grantDb, $oldAccessDeadRefresh['id']) === null);
check('A22. it removes an old revoked row however long its refresh would have lived', ms_og_row($grantDb, $revokedOld['id']) === null);
check('A23. and a grant whose account is gone', ms_og_row($grantDb, $orphanGrant['id']) === null);
check('A24. ... and the kept grant still refreshes afterwards',
    (oauthRefreshTokenExchange($grantDb, ['refresh_token' => $oldAccessLiveRefresh['refresh'], 'client_id' => $oldAccessLiveRefresh['client_id']], $sweepAt)['ok'] ?? false) === true);

// Authorization codes: swept once they are a day old, whatever their state.
$codeRecentlyExpired = oauthAuthorizationCodeCreate($grantDb, $clientId, 1, $redirectUri, 'mcp:read', $challenge, '', $t0);
$codeLongExpired = oauthAuthorizationCodeCreate($grantDb, $clientId, 1, $redirectUri, 'mcp:read', $challenge, '', $t0 - 3 * 86400);
$codeSweepAt = $t0 + OAUTH_CODE_SWEEP_SECONDS + 1;
check('A25. the code sweep removes a code older than a day', oauthCodeCleanup($grantDb, $codeSweepAt) >= 1
    && (int) $grantDb->query('SELECT COUNT(*) FROM oauth_authorization_codes WHERE code_hash = ' . $grantDb->quote(oauthHash((string) $codeLongExpired)))->fetchColumn() === 0);
check('A26. it keeps a code that expired within the day', (int) $grantDb->query('SELECT COUNT(*) FROM oauth_authorization_codes WHERE code_hash = ' . $grantDb->quote(oauthHash((string) $codeRecentlyExpired)))->fetchColumn() === 1);

// Clients: only one nothing points at, and only once it has been idle.
$grantDb->exec('DELETE FROM mcp_access_tokens');
$dbCleanupAt = $t0 + 40 * 86400;
$insertClient = function (PDO $pdo, string $id, string $name, int $createdAt): void {
    $pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at, last_used_at, registered_ip_hash) VALUES (?, ?, ?, ?, NULL, NULL)')
        ->execute([$id, $name, '["https://app.example/cb"]', date('Y-m-d H:i:s', $createdAt)]);
};
$linkedClient = str_repeat('a', 32);
$idleClient = str_repeat('b', 32);
$freshClient = str_repeat('c', 32);
$insertClient($grantDb, $linkedClient, 'Linked app', $t0);
$insertClient($grantDb, $idleClient, 'Idle app', $t0);
$insertClient($grantDb, $freshClient, 'Fresh app', $dbCleanupAt);
ms_og_insert_grant($grantDb, 1, ['now' => $t0, 'client_id' => $linkedClient]);

$removedClients = oauthClientCleanup($grantDb, $dbCleanupAt);
$hasClient = fn(string $id): int => (int) $grantDb->query('SELECT COUNT(*) FROM oauth_clients WHERE client_id = ' . $grantDb->quote($id))->fetchColumn();
check('A27. the idle client with no token row is removed', $removedClients >= 1 && $hasClient($idleClient) === 0);
check('A28. the client a token still points at is kept', $hasClient($linkedClient) === 1);
check('A29. a client created within the idle window is kept', $hasClient($freshClient) === 1);

$grantDb = null;
$plainDb = null;
foreach (glob($tmpDb . '*.sqlite') ?: [] as $f) {
    @unlink($f);
}

// ---------------------------------------------------------------------------
echo "\nB. the real pro_profile.php and pro_profile_page.php through the probe\n";
// ---------------------------------------------------------------------------

$msProbe = ms_test_probe_build($repoRoot);
echo "probe docroot: {$msProbe}\n";

$pdo = ms_test_db(ms_test_probe_sqlite($msProbe));
ms_og_exec($pdo, ms_og_schema());

$userPro = ms_test_seed_user($pdo, 'greta@example.com', 'pro');
$userOther = ms_test_seed_user($pdo, 'nils@example.com', 'pro');

// An app whose registered name is markup: it is the app's own string and
// reaches the browser as data, never as HTML.
$evilName = '<script>alert(1)</script>App';
$evilClient = str_repeat('c', 32);
$pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at, last_used_at, registered_ip_hash) VALUES (?, ?, ?, ?, NULL, NULL)')
    ->execute([$evilClient, $evilName, '["https://evil.example/cb"]', date('Y-m-d H:i:s', time())]);

$grant = ms_og_insert_grant($pdo, $userPro, ['name' => $evilName, 'client_id' => $evilClient, 'scopes' => 'read,write']);
mcpTokenCreate($pdo, $userPro, 'Pasted by hand', 'read', null);
$otherGrant = ms_og_insert_grant($pdo, $userOther, ['name' => 'Other app', 'client_id' => str_repeat('d', 32)]);
$pdo = null;

$action = function (string $name, array $fields, int $userId, ?string $origin = MS_TEST_ORIGIN) use ($msProbe): array {
    return ms_test_request($msProbe, [
        'page' => 'pro_profile.php',
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'post' => array_merge(['action' => $name], $fields),
        'origin' => $origin,
    ]);
};

$response = $action('mcp_token_list', [], $userPro);
ms_test_no_php_errors('B1. mcp_token_list answers without PHP errors', $response);
$listed = ms_test_json('B2. mcp_token_list answers with JSON', $response);
$tokens = $listed['tokens'] ?? [];
check('B3. the account\'s own two credentials are listed', count($tokens) === 2, json_encode($tokens));

$grantRow = null;
$manualRow = null;
foreach ($tokens as $token) {
    if ($token['is_oauth'] === true) {
        $grantRow = $token;
    } else {
        $manualRow = $token;
    }
}
check('B4. the grant carries is_oauth, the app name and the grant time',
    $grantRow !== null && $grantRow['oauth_client_name'] === $evilName
    && $grantRow['grant_created_at'] !== null && $grantRow['scopes'] === 'read,write');
check('B5. the manual token is not a grant', $manualRow !== null && $manualRow['is_oauth'] === false
    && $manualRow['oauth_client_name'] === null && $manualRow['grant_created_at'] === null);
check('B6. another account\'s grant is not in the answer', $grantRow !== null
    && strpos((string) json_encode($tokens), 'Other app') === false && ($grantRow['id'] ?? 0) !== $otherGrant['id']);

// The page: both groups, and the name is never markup.
$render = ms_test_request($msProbe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user_id' => $userPro, 'user_email' => 'greta@example.com']);
ms_test_no_php_errors('B7. pro_profile_page.php renders without PHP errors', $render);
check('B8. the card renders both group headings and both lists',
    strpos($render['stdout'], 'Connected apps') !== false
    && strpos($render['stdout'], '>Access tokens<') !== false
    && strpos($render['stdout'], 'id="connectedAppsGrantList"') !== false
    && strpos($render['stdout'], 'id="connectedAppsList"') !== false
    && strpos($render['stdout'], 'id="connectedAppsGrantsEmpty"') !== false);
check('B9. the help text says an app with a Connect/Sign in button needs no token',
    strpos($render['stdout'], 'Sign in') !== false && strpos($render['stdout'], 'needs no token') !== false);
check('B10. no app name reaches the page as markup', strpos($render['stdout'], 'alert(1)') === false);
check('B11. the renderer inserts every app name with .text(), never .html()',
    preg_match('/\.text\(t\.oauth_client_name/', $render['stdout']) === 1
    && preg_match('/\.html\(\s*t\.oauth_client_name/', $render['stdout']) === 0);

// Revoke the grant through the action it shares with a manual token.
$response = $action('mcp_token_revoke', ['id' => (string) $grant['id']], $userPro);
$revoked = ms_test_json('B12. revoking a grant is answered', $response);
check('B13. it succeeds', ($revoked['success'] ?? null) === true);
$fresh = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
check('B14. the row is marked revoked', ms_og_row($fresh, $grant['id'])['revoked_at'] !== null);
check('B15. its access token no longer resolves', mcpTokenResolve($fresh, $grant['access']) === null);
$refreshAfter = oauthRefreshTokenExchange($fresh, ['refresh_token' => $grant['refresh'], 'client_id' => $evilClient], time());
check('B16. its refresh token answers invalid_grant', ($refreshAfter['ok'] ?? false) === false && ($refreshAfter['error'] ?? '') === 'invalid_grant');

// Another account's grant is untouched and unreachable.
$response = $action('mcp_token_revoke', ['id' => (string) $otherGrant['id']], $userPro);
check('B17. revoking another account\'s grant is refused', (ms_test_json('', $response)['success'] ?? null) === false
    && ms_og_row(ms_test_refresh_db(ms_test_probe_sqlite($msProbe)), $otherGrant['id'])['revoked_at'] === null);

// ---------------------------------------------------------------------------
echo "\nC. the real mcp.php over PHP's built-in web server\n";
// ---------------------------------------------------------------------------

// oauth_server.php is what mcp.php requires for the 401 challenge since #331
// step 5/6 (this probe has no OAuth schema, so it stays the bare one).
foreach (['mcp.php', 'mcp_tools.php', 'email_html_sanitizer.php', 'oauth_server.php'] as $file) {
    if (!copy($repoRoot . '/' . $file, $msProbe . '/' . $file)) {
        throw new RuntimeException("Could not copy {$file} into the probe docroot");
    }
}

/** One HTTP request against the probe's built-in server. */
function ms_og_request(int $port, array $headers, string $body): array
{
    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= $name . ': ' . $value . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headerLines,
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $responseBody = @file_get_contents('http://127.0.0.1:' . $port . '/mcp.php', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    return ['status' => $status, 'body' => $responseBody === false ? '' : $responseBody];
}

/** POST one JSON-RPC message to mcp.php with (optionally) a bearer token. */
function ms_og_mcp(int $port, ?string $token, string $method): array
{
    $headers = ['Content-Type' => 'application/json', 'Origin' => MS_TEST_ORIGIN];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    return ms_og_request($port, $headers, (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $method === 'initialize'
            ? ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'suite', 'version' => '1']]
            : [],
    ]));
}

$server = null;
$port = 0;
for ($attempt = 0; $attempt < 10 && $server === null; $attempt++) {
    $candidate = random_int(20000, 60000);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', "127.0.0.1:{$candidate}", '-t', $msProbe],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $msProbe,
        ['PROBE_SQLITE' => ms_test_probe_sqlite($msProbe), 'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
    );
    if (!is_resource($proc)) {
        continue;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    usleep(300000);
    if (proc_get_status($proc)['running']) {
        $server = [$proc, $pipes];
        $port = $candidate;
    } else {
        proc_close($proc);
    }
}
if ($server === null) {
    check('C0. the built-in web server starts', false, 'could not bind a port');
} else {
    // A fresh grant, seeded directly so the suite holds its plaintext.
    $pdo = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
    $httpGrant = ms_og_insert_grant($pdo, $userPro, ['name' => 'HTTP app', 'client_id' => str_repeat('e', 32)]);
    $pdo = null;

    $live = ms_og_mcp($port, $httpGrant['access'], 'initialize');
    check('C1. a live grant\'s access token drives the endpoint', $live['status'] === 200, 'status=' . $live['status'] . ' body=' . substr($live['body'], 0, 200));

    $response = $action('mcp_token_revoke', ['id' => (string) $httpGrant['id']], $userPro);
    check('C2. the grant is revoked through the profile action', (ms_test_json('', $response)['success'] ?? null) === true);

    $dead = ms_og_mcp($port, $httpGrant['access'], 'initialize');
    check('C3. the very next mcp.php call answers 401', $dead['status'] === 401, 'status=' . $dead['status'] . ' body=' . substr($dead['body'], 0, 200));

    [$proc, $pipes] = $server;
    proc_terminate($proc);
    proc_close($proc);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

ms_test_cleanup($msProbe);

// ---------------------------------------------------------------------------
echo "\nD. the real pro_auth.php removal paths (subprocess)\n";
// ---------------------------------------------------------------------------

$stub = <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/oauth_grants_test.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');
$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => 'http://localhost:8085/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => 60, 'hash_key' => 'test-trial-hash-key-0123456789abcdef', 'claim_retention_days' => 1825],
];

/** MySQL-only syntax the exercised paths use, rewritten for SQLite. */
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            [
                'DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                ' FOR UPDATE',
                'DELETE d FROM pro_webhook_deliveries d JOIN pro_webhooks w ON w.id = d.webhook_id WHERE w.user_id = ?',
            ],
            [
                'PROBE_MINUTES_AGO(?)',
                '',
                'DELETE FROM pro_webhook_deliveries WHERE webhook_id IN (SELECT id FROM pro_webhooks WHERE pro_webhooks.user_id = ?)',
            ],
            $query
        );
        return parent::prepare($query, $options);
    }

    /** MySQL self-healing DDL (CREATE TABLE IF NOT EXISTS ... ENGINE=, ALTER): the probe schema already has it. */
    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*(CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS|ALTER\s+TABLE)\b/i', $statement)) {
            return 0;
        }
        return parent::exec($statement);
    }
}
$pdo = new ProbePdo('sqlite:' . getenv('PROBE_SQLITE'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('PROBE_MINUTES_AGO', static fn($m): string => date('Y-m-d H:i:s', time() - 60 * (int) $m), 1);

function logMessage($level, $message, $context = null) {
    file_put_contents((string) getenv('PROBE_LOG'), json_encode([$level, $message, $context]) . "\n", FILE_APPEND);
}
function tableHasColumn($table, $column) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
    $stmt->execute([(string) $table, (string) $column]);
    return (int) $stmt->fetchColumn() > 0;
}
function getVisitorIp(): string { return '10.0.0.1'; }
function flagMaliciousActivity(string $ip, string $reason, ?PDO $pdoConnection = null): bool { return true; }
function requireSameOriginRequest(): bool { return true; }
function appIsProduction(): bool { return false; }
function appCookieSecure(): bool { return false; }
function isDisposableEmailDomain(string $email): bool { return false; }
function proUserIsPro(int $userId): bool { return true; }
function proUserIsSuspended(int $userId): bool { return false; }
function sanitizeString($input, int $maxLength = 0, bool $stripHtml = false): ?string {
    if ($input === null || $input === '') return null;
    $s = trim(str_replace("\0", '', (string) $input));
    if ($stripHtml) $s = strip_tags($s);
    return $maxLength > 0 && mb_strlen($s) > $maxLength ? mb_substr($s, 0, $maxLength) : $s;
}
function sanitizeAlphanumeric($input, int $maxLength = 64, bool $allowDashes = false): ?string {
    $s = sanitizeString($input, $maxLength, true);
    if ($s === null) return null;
    return preg_match($allowDashes ? '/^[a-zA-Z0-9_-]+$/' : '/^[a-zA-Z0-9]+$/', $s) ? $s : null;
}
function detectSuspiciousPatterns(string $input): array { return []; }
function patternsWarrantingIpFlag(array $patterns): array { return $patterns; }
function deleteDirectAdminForwarder(...$args) { return true; }
function createDirectAdminForwarder(...$args) { return true; }

$request = json_decode((string) getenv('PROBE_REQUEST'), true);
$_SERVER['REQUEST_METHOD'] = $request['method'];
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:8085';
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];
PHP;

/** The pro_auth.php removal paths' tables, plus the grant schema. */
function ms_og_probe_schema(): string
{
    return <<<'SQL'
CREATE TABLE pro_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    email_enc TEXT NULL,
    email_hash CHAR(64) NULL,
    password_hash TEXT NULL,
    account_type TEXT NOT NULL DEFAULT 'regular',
    email_verified_at TEXT NULL,
    address_ttl_days INTEGER NOT NULL DEFAULT 1,
    pro_expires_at TEXT NULL,
    password_changed_at TEXT NULL,
    last_login_at TEXT NULL,
    inactivity_warned_at TEXT NULL,
    suspended_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending');
CREATE TABLE pro_user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NULL);
CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, selector TEXT NULL, validator_hash TEXT NULL, expires_at TEXT NULL);
CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE pending_profile_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, action TEXT NOT NULL, data TEXT, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE pro_webhooks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL);
CREATE TABLE pro_webhook_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, webhook_id INTEGER NOT NULL);
CREATE TABLE temp_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_address TEXT NOT NULL, pro_user_id INTEGER NULL, is_personal INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stored_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, temp_email_id INTEGER NOT NULL);
CREATE TABLE email_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, email_id INTEGER NOT NULL, filename TEXT NULL, file_path TEXT NULL);
SQL;
}

$encKey = str_repeat('e', 40);
$idxKey = str_repeat('i', 40);
$keys = ['PII_ENCRYPTION_KEY' => $encKey, 'PII_INDEX_KEY' => $idxKey];

$probe = sys_get_temp_dir() . '/ms_oauth_grants_probe_' . bin2hex(random_bytes(6));
mkdir($probe);
foreach (['pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'login_tokens.php', 'email_log_ref.php', 'pii_crypto.php', 'pro_remember.php', 'mcp_tokens.php', 'oauth_server.php', 'after_login.php'] as $file) {
    copy($repoRoot . '/' . $file, $probe . '/' . $file);
}
file_put_contents($probe . '/config.php', $stub);
$dbPath = $probe . '/probe.sqlite';
$logPath = $probe . '/probe.log';

$pdo = ms_og_db($dbPath);
ms_og_exec($pdo, ms_og_probe_schema());
ms_og_exec($pdo, ms_og_schema());
$pdo->exec("INSERT INTO pro_users (id, email) VALUES (999, 'gone@example.com')");

require_once $repoRoot . '/pii_crypto.php';
$addUser = function (string $email) use ($pdo, $encKey, $idxKey): int {
    $pdo->prepare("INSERT INTO pro_users (email, email_enc, email_hash, password_hash, account_type, email_verified_at) VALUES (?, ?, ?, ?, 'pro', '2026-01-01 00:00:00')")
        ->execute([$email, piiEmailEncrypt($email, $encKey), piiEmailHash($email, $idxKey), password_hash('correct-horse', PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
};
$alice = $addUser('alice@example.com');
$bob = $addUser('bob@example.com');

/** Live rows of an account, grants included. */
$liveRows = function (int $userId) use ($dbPath): int {
    $fresh = ms_og_db($dbPath);
    $stmt = $fresh->prepare('SELECT COUNT(*) FROM mcp_access_tokens WHERE pro_user_id = ? AND revoked_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
};

$run = function (array $request) use ($probe, $dbPath, $logPath, $keys): string {
    $env = ['PROBE_SQLITE' => $dbPath, 'PROBE_LOG' => $logPath, 'PROBE_REQUEST' => json_encode($request), 'PATH' => (string) getenv('PATH')] + $keys;
    $sendmail = 'sendmail_path=cat >> ' . escapeshellarg($probe . '/mail.log');
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', $sendmail, '-d', 'session.save_path=' . $probe, $probe . '/pro_auth.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return (string) $out . ($err !== '' ? "\n[stderr] " . $err : '');
};

/** Insert a pending change and return the GET request that confirms or undoes it. */
$pending = function (int $userId, string $action, array $data, string $param) use ($dbPath): array {
    $fresh = ms_og_db($dbPath);
    $token = bin2hex(random_bytes(24));
    $fresh->prepare('INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $action, json_encode($data), hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
    return ['method' => 'GET', 'get' => [$param => $token]];
};

/** One grant row for $userId, written straight into the probe's database. */
$seedGrant = function (int $userId) use ($dbPath): array {
    $fresh = ms_og_db($dbPath);
    return ms_og_insert_grant($fresh, $userId, ['client_id' => str_repeat('f', 32)]);
};

// A grant per account, plus one for Bob that must survive everything.
$seedGrant($alice);
$seedGrant($bob);
check('D1. both accounts start with a live grant', $liveRows($alice) === 1 && $liveRows($bob) === 1);

$out = $run($pending($alice, 'set_password', ['password_hash' => password_hash('new-horse', PASSWORD_DEFAULT)], 'confirm_profile_change'));
check('D2. a confirmed password change goes through', strpos($out, 'Password change confirmed') !== false, $out);
check('D3. ... and revokes the account\'s grant', $liveRows($alice) === 0);
check('D4. ... and none of anyone else\'s', $liveRows($bob) === 1);

$seedGrant($alice);
$out = $run($pending($alice, 'update_email', ['new_email' => 'alice2@example.com'], 'confirm_profile_change'));
check('D5. a confirmed email change goes through', strpos($out, 'Email change confirmed') !== false, $out);
check('D6. ... and revokes the account\'s grant', $liveRows($alice) === 0);
check('D7. ... and none of anyone else\'s', $liveRows($bob) === 1);

$seedGrant($alice);
$out = $run($pending($alice, 'undo_update_email', ['old_email' => 'alice@example.com', 'new_email' => 'alice2@example.com'], 'undo_profile_change'));
check('D8. an email-change undo goes through', strpos($out, 'Email change reverted') !== false, $out);
check('D9. ... and revokes the account\'s grant', $liveRows($alice) === 0);

$seedGrant($alice);
$out = $run($pending($alice, 'undo_set_password', ['old_hash' => password_hash('correct-horse', PASSWORD_DEFAULT)], 'undo_profile_change'));
check('D10. a password-change undo goes through', strpos($out, 'Password change reverted') !== false, $out);
check('D11. ... and revokes the account\'s grant', $liveRows($alice) === 0);

$seedGrant($alice);
$out = $run($pending($alice, 'delete_account', [], 'confirm_profile_change'));
$fresh = ms_og_db($dbPath);
$userGone = (int) $fresh->query("SELECT COUNT(*) FROM pro_users WHERE id = {$alice}")->fetchColumn() === 0;
check('D12. the account is deleted', $userGone, $out);
check('D13. its grant is revoked', $liveRows($alice) === 0);
check('D14. and the other account keeps its grant', $liveRows($bob) === 1);

foreach (glob($probe . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($probe);

// ---------------------------------------------------------------------------
echo "\nE. wiring\n";
// ---------------------------------------------------------------------------

$src = fn(string $file): string => (string) file_get_contents($repoRoot . '/' . $file);

check('E1. revoke-all is scoped by account alone, so no grant row escapes it',
    preg_match('/UPDATE mcp_access_tokens SET revoked_at = \? WHERE pro_user_id = \? AND revoked_at IS NULL/', $src('mcp_tokens.php')) === 1
    && strpos($src('pro_auth.php'), 'mcpTokensRevokeAllFor(') !== false
    && strpos($src('abuse_admin.php'), 'mcpTokensRevokeAllFor(') !== false
    && strpos($src('cron/cleanup.php'), 'mcpTokensRevokeAllFor(') !== false);

check('E2. cron/cleanup.php sweeps codes and idle clients, after the token sweep',
    strpos($src('cron/cleanup.php'), 'oauthCodeCleanup($pdo)') !== false
    && strpos($src('cron/cleanup.php'), 'oauthClientCleanup($pdo)') !== false
    && strpos($src('cron/cleanup.php'), 'cleanupExpiredOauth()') !== false
    && strpos($src('cron/cleanup.php'), 'cleanupExpiredMcpTokens()') !== false);

check('E3. the client sweep refuses to strand a token link',
    strpos($src('oauth_server.php'), 'client_id NOT IN (SELECT oauth_client_id FROM mcp_access_tokens') !== false);

check('E4. the token sweep guards the expiry branch on a live refresh token',
    strpos($src('mcp_tokens.php'), 'refresh_expires_at IS NULL OR refresh_expires_at <= ?') !== false);

check('E5. check_oauth.php reports the grant counts',
    strpos($src('check_oauth.php'), 'active grants') !== false
    && strpos($src('check_oauth.php'), 'clients without grants') !== false
    && strpos($src('check_oauth.php'), 'expired codes not yet swept') !== false
    && strpos($src('check_oauth.php'), 'OAUTH_GRANT_MAX_PER_USER') !== false
    && strpos($src('check_oauth.php'), 'belong to a missing account') !== false);

check('E6. the profile returns the grant fields',
    strpos($src('pro_profile.php'), "'is_oauth' =>") !== false
    && strpos($src('pro_profile.php'), "'oauth_client_name' =>") !== false
    && strpos($src('pro_profile.php'), "'grant_created_at' =>") !== false);

check('E7. pro_profile.php loads mcp_tokens.php, not oauth_server.php',
    strpos($src('pro_profile.php'), "require_once __DIR__ . '/mcp_tokens.php';") !== false);

// The code this step touches logs no app name and no credential. (The
// registration endpoint logs the client name by design — that line is #331
// step 1/6's and is not touched here — so the scan is the grant-aware files.)
$badLogs = [];
foreach (['mcp_tokens.php', 'oauth_server.php', 'pro_profile.php', 'cron/cleanup.php'] as $file) {
    $text = (string) file_get_contents($repoRoot . '/' . $file);
    if (preg_match_all('/logMessage\((?:[^();]|\([^()]*\))*\)/s', $text, $m)) {
        foreach ($m[0] as $call) {
            if (preg_match('/oauth_client_name|client_name|refresh_token|\$accessToken\b/', $call)) {
                $badLogs[] = $file . ': ' . preg_replace('/\s+/', ' ', mb_substr($call, 0, 120));
            }
        }
    }
}
check('E8. the grant-aware code logs no app name and no credential', $badLogs === [], implode("\n       ", array_slice($badLogs, 0, 5)));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
