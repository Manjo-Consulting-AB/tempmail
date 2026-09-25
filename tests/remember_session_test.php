<?php

declare(strict_types=1);

/**
 * Regression coverage for "Stay signed in" (pro_remember.php, table
 * pro_remember_tokens from migrate_remember_tokens.php).
 *
 * Run with:  php tests/remember_session_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 * part A runs the library on an in-memory SQLite database; part B runs the
 * real pro_auth.php (sign-in, password change, account deletion) and the
 * real session restore as subprocesses on SQLite; part C scans that every
 * path the design relies on is wired up.
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
require $repoRoot . '/pro_remember.php';

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

/** SQLite version of the table migrate_remember_tokens.php creates. */
function rememberSchema(): string
{
    return <<<'SQL'
CREATE TABLE pro_remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    prev_validator_hash TEXT NULL,
    rotated_at TEXT NULL,
    days INTEGER NOT NULL,
    label TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    last_used_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
SQL;
}

function tokenCount(PDO $pdo, ?int $userId = null): int
{
    if ($userId === null) {
        return (int) $pdo->query('SELECT COUNT(*) FROM pro_remember_tokens')->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pro_remember_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
echo "A. pro_remember.php on SQLite\n";
// ---------------------------------------------------------------------------

check('A1. 1, 7 and 30 days are accepted', proRememberNormaliseDays('1') === 1 && proRememberNormaliseDays(7) === 7 && proRememberNormaliseDays(' 30 ') === 30);
check('A2. anything else means only this visit', proRememberNormaliseDays('0') === 0 && proRememberNormaliseDays('365') === 0
    && proRememberNormaliseDays('7; DROP') === 0 && proRememberNormaliseDays(null) === 0 && proRememberNormaliseDays(['7']) === 0 && proRememberNormaliseDays('-7') === 0);

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check('A3. unavailable without the table', proRememberAvailable($db) === false);
$db2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db2->exec(rememberSchema());
check('A4. available with the table', proRememberAvailable($db2) === true);
$db = $db2;

$t0 = strtotime('2026-03-01 12:00:00');
$tok = proRememberCreate($db, 1, 7, 'Safari on iOS', $t0);
check('A5. cookie is selector.validator', proRememberParseCookie($tok['cookie_value']) !== null);
$row = $db->query('SELECT * FROM pro_remember_tokens')->fetch(PDO::FETCH_ASSOC);
check('A6. the validator itself is never stored', strpos(json_encode($row), explode('.', $tok['cookie_value'])[1]) === false
    && $row['validator_hash'] === hash('sha256', explode('.', $tok['cookie_value'])[1]));
check('A7. expires after the chosen period', $tok['expires_at'] === '2026-03-08 12:00:00');

$v = proRememberVerify($db, $tok['cookie_value'], $t0 + 3600);
check('A8. a valid cookie signs its owner in', $v !== null && $v['user_id'] === 1 && $v['days'] === 7);
check('A9. and is rotated', $v !== null && $v['cookie_value'] !== null && $v['cookie_value'] !== $tok['cookie_value']
    && explode('.', $v['cookie_value'])[0] === explode('.', $tok['cookie_value'])[0]);
check('A10. the period slides from the last use', $v !== null && $v['expires_at'] === '2026-03-08 13:00:00');

$grace = proRememberVerify($db, $tok['cookie_value'], $t0 + 3600 + 30);
check('A11. the replaced cookie still works during the grace period', $grace !== null && $grace['user_id'] === 1);
check('A12. but gets no new cookie (the rotating request set it)', $grace !== null && $grace['cookie_value'] === null);
check('A13. the replaced cookie fails once the grace period is over', proRememberVerify($db, $tok['cookie_value'], $t0 + 3600 + PRO_REMEMBER_ROTATION_GRACE + 1) === null);

$current = $v['cookie_value'];
[$sel] = explode('.', $current);
check('A14. a wrong validator fails', proRememberVerify($db, $sel . '.' . str_repeat('0', 64), $t0 + 7200) === null);
check('A15. a malformed cookie fails', proRememberVerify($db, 'garbage', $t0) === null && proRememberVerify($db, $sel . '.', $t0) === null
    && proRememberVerify($db, strtoupper($current), $t0 + 7200) === null);
check('A16. an unknown selector fails', proRememberVerify($db, str_repeat('a', 32) . '.' . str_repeat('b', 64), $t0) === null);
check('A17. an expired token fails', proRememberVerify($db, $current, strtotime('2026-03-08 13:00:00')) === null);

// Sliding never goes past PRO_REMEMBER_MAX_AGE_DAYS after the sign-in.
$long = proRememberCreate($db, 2, 30, 'x', $t0);
$cookie = $long['cookie_value'];
$at = $t0;
for ($i = 0; $i < 5; $i++) {
    $at += 25 * 86400;
    $r = proRememberVerify($db, $cookie, $at);
    if ($r === null) {
        break;
    }
    $cookie = $r['cookie_value'];
}
check('A18. used every 25 days, a 30-day token still ends 90 days after sign-in', $i === 3,
    'lasted ' . $i . ' uses');
check('A19. and is refused after that', $r === null);

// A concurrent pair: the second request, holding the same cookie, is let in without a new cookie.
$pair = proRememberCreate($db, 3, 1, 'x', $t0);
$first = proRememberVerify($db, $pair['cookie_value'], $t0 + 10);
$second = proRememberVerify($db, $pair['cookie_value'], $t0 + 11);
check('A20. two requests with one cookie: both get in, only one rotates', $first['cookie_value'] !== null && $second !== null && $second['cookie_value'] === null);
check('A21. the rotated cookie is the one that keeps working', proRememberVerify($db, $first['cookie_value'], $t0 + 12) !== null);

// Listing, revoking, the per-user cap, cleanup.
$db->exec('DELETE FROM pro_remember_tokens');
$a = proRememberCreate($db, 10, 7, 'A', $t0);
$b = proRememberCreate($db, 10, 7, 'B', $t0 + 1);
$c = proRememberCreate($db, 11, 7, 'C', $t0 + 2);
check('A22. list shows only the account\'s own tokens, newest use first', array_column(proRememberList($db, 10, $t0 + 5), 'label') === ['B', 'A']);
check('A23. list exposes no secret', !array_key_exists('selector', proRememberList($db, 10, $t0 + 5)[0]) && !array_key_exists('validator_hash', proRememberList($db, 10, $t0 + 5)[0]));
check('A24. revoking someone else\'s token does nothing', proRememberRevoke($db, 10, $c['id']) === false && tokenCount($db, 11) === 1);
check('A25. revoking an own token removes it', proRememberRevoke($db, 10, $a['id']) === true && tokenCount($db, 10) === 1);
proRememberCreate($db, 10, 7, 'D', $t0 + 3);
check('A26. revoke all but one keeps that one', proRememberRevokeAll($db, 10, $b['id']) === 1 && tokenCount($db, 10) === 1
    && proRememberFindBySelector($db, $b['cookie_value'], $t0 + 5) !== null);
check('A27. revoke all removes every token of the account, no other', proRememberRevokeAll($db, 10) === 1 && tokenCount($db, 10) === 0 && tokenCount($db, 11) === 1);
proRememberDeleteBySelector($db, $c['cookie_value']);
check('A28. sign-out removes the token its cookie names', tokenCount($db, 11) === 0);

for ($i = 0; $i < PRO_REMEMBER_MAX_PER_USER + 3; $i++) {
    proRememberCreate($db, 12, 1, 'n' . $i, $t0 + $i);
}
check('A29. at most PRO_REMEMBER_MAX_PER_USER tokens per account', tokenCount($db, 12) === PRO_REMEMBER_MAX_PER_USER);
check('A30. the least recently used are the ones dropped', !in_array('n0', array_column(proRememberList($db, 12, $t0), 'label'), true)
    && in_array('n' . (PRO_REMEMBER_MAX_PER_USER + 2), array_column(proRememberList($db, 12, $t0), 'label'), true));
check('A31. cleanup removes only expired tokens', proRememberCleanup($db, $t0 + 86400 + 5) === 3 && tokenCount($db, 12) === PRO_REMEMBER_MAX_PER_USER - 3);

// ---------------------------------------------------------------------------
echo "B. the real pro_auth.php and session restore, as subprocesses on SQLite\n";
// ---------------------------------------------------------------------------

$encKey = str_repeat('e', 40);
$idxKey = str_repeat('i', 40);
$keys = ['PII_ENCRYPTION_KEY' => $encKey, 'PII_INDEX_KEY' => $idxKey];

$stub = <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/remember_session_test.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');
$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => 'http://localhost:8085/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => 60, 'hash_key' => '', 'claim_retention_days' => 1825],
];
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(['DATE_SUB(NOW(), INTERVAL ? MINUTE)', ' FOR UPDATE', 'DELETE d FROM pro_webhook_deliveries d JOIN pro_webhooks w ON w.id = d.webhook_id WHERE w.user_id = ?'],
            ['PROBE_MINUTES_AGO(?)', '', 'DELETE FROM pro_webhook_deliveries WHERE webhook_id IN (SELECT id FROM pro_webhooks WHERE user_id = ?)'], $query);
        return parent::prepare($query, $options);
    }
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
function proUserIsSuspended(int $userId): bool {
    global $pdo;
    $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return $v !== false && $v !== null;
}
require_once __DIR__ . '/pii_crypto.php';
require_once __DIR__ . '/pro_remember.php';
$request = json_decode((string) getenv('PROBE_REQUEST'), true);
$_SERVER['REQUEST_METHOD'] = $request['method'];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1';
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];
$_COOKIE = $request['cookie'] ?? [];
// What the request left in $_COOKIE (setcookie() mirrors it there).
register_shutdown_function(function () {
    echo "\n@@COOKIE@@" . json_encode($_COOKIE[PRO_REMEMBER_COOKIE] ?? null);
});
PHP;

// Restores a session from the cookie, as proSessionEndIfSuspended() does.
$restoreRunner = <<<'PHP'
<?php
require __DIR__ . '/config.php';
session_start();
$restored = proRememberRestoreSession();
echo json_encode(['restored' => $restored, 'session' => $_SESSION, 'cookie' => $_COOKIE[PRO_REMEMBER_COOKIE] ?? null]);
PHP;

$probe = sys_get_temp_dir() . '/ms_remember_' . bin2hex(random_bytes(6));
mkdir($probe);
foreach (['pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'login_tokens.php', 'email_log_ref.php', 'pii_crypto.php', 'pro_remember.php'] as $file) {
    copy($repoRoot . '/' . $file, $probe . '/' . $file);
}
file_put_contents($probe . '/config.php', $stub);
file_put_contents($probe . '/restore.php', $restoreRunner);
$dbPath = $probe . '/probe.sqlite';
$logPath = $probe . '/probe.log';

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, email_enc TEXT NULL, email_hash CHAR(64) NULL UNIQUE,
    password_hash TEXT NULL, account_type TEXT NOT NULL DEFAULT 'regular', email_verified_at TEXT NULL, address_ttl_days INTEGER NOT NULL DEFAULT 1,
    pro_expires_at TEXT NULL, password_changed_at TEXT NULL, last_login_at TEXT NULL, suspended_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending')");
$pdo->exec('CREATE TABLE pro_user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE pending_profile_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, action TEXT NOT NULL, data TEXT, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE pro_webhooks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE pro_webhook_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, webhook_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE temp_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_address TEXT NOT NULL, pro_user_id INTEGER NULL, is_personal INTEGER NOT NULL DEFAULT 0)');
$pdo->exec(rememberSchema());

require_once $repoRoot . '/pii_crypto.php';
$addUser = function (string $email) use ($pdo, $encKey, $idxKey): int {
    $pdo->prepare("INSERT INTO pro_users (email, email_enc, email_hash, password_hash, email_verified_at) VALUES (?, ?, ?, ?, '2026-01-01 00:00:00')")
        ->execute([$email, piiEmailEncrypt($email, $encKey), piiEmailHash($email, $idxKey), password_hash('correct-horse', PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
};
$alice = $addUser('alice@example.com');
$bob = $addUser('bob@example.com');

$run = function (string $script, array $request) use ($probe, $dbPath, $logPath, $keys): string {
    // The trailing '#' comments out the -f envelope option mail() appends.
    $env = ['PROBE_SQLITE' => $dbPath, 'PROBE_LOG' => $logPath, 'PROBE_REQUEST' => json_encode($request), 'PATH' => (string) getenv('PATH')] + $keys;
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'sendmail_path=cat >> ' . escapeshellarg($probe . '/mail.log') . ' #', '-d', 'session.save_path=' . $probe, $probe . '/' . $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return (string) $out . ($err !== '' ? "\n[stderr] " . $err : '');
};
$cookieOf = function (string $out): ?string {
    $pos = strrpos($out, '@@COOKIE@@');
    return $pos === false ? null : json_decode(substr($out, $pos + 10), true);
};
$login = function (string $email, $stay, array $cookie = []) use ($run): string {
    return $run('pro_auth.php', ['method' => 'POST', 'post' => ['action' => 'password_login', 'email' => $email, 'password' => 'correct-horse', 'stay_days' => $stay], 'cookie' => $cookie]);
};
$restore = function (?string $cookie) use ($run): array {
    $out = $run('restore.php', ['method' => 'GET', 'cookie' => $cookie === null ? [] : [PRO_REMEMBER_COOKIE => $cookie]]);
    $json = strstr($out, "\n@@COOKIE@@", true);
    return json_decode($json === false ? $out : $json, true) ?? ['raw' => $out];
};

$out = $login('alice@example.com', '0');
check('B1. password sign-in, "until I close the browser": signed in', strpos($out, '"success":true') !== false, $out);
check('B2. and no token is created', tokenCount($pdo) === 0);

$out = $login('alice@example.com', '7');
$aliceCookie = $cookieOf($out);
check('B3. "7 days": a token for the account, for 7 days', tokenCount($pdo, $alice) === 1
    && (int) $pdo->query('SELECT days FROM pro_remember_tokens')->fetchColumn() === 7, $out);
check('B4. the browser is given its cookie', is_string($aliceCookie) && proRememberParseCookie($aliceCookie) !== null, $out);
check('B5. labelled with browser and OS, not the raw user agent', $pdo->query('SELECT label FROM pro_remember_tokens')->fetchColumn() === 'Safari on iOS');

$out = $login('alice@example.com', '365');
check('B6. a period that is not offered creates no token', tokenCount($pdo, $alice) === 1, $out);

$out = $login('alice@example.com', '30', [PRO_REMEMBER_COOKIE => $aliceCookie]);
$aliceCookie2 = $cookieOf($out);
check('B7. signing in again on the same device replaces its token', tokenCount($pdo, $alice) === 1
    && (int) $pdo->query('SELECT days FROM pro_remember_tokens')->fetchColumn() === 30 && $aliceCookie2 !== $aliceCookie);

$r = $restore($aliceCookie2);
check('B8. a closed browser comes back signed in', ($r['restored'] ?? null) === true && (int) ($r['session']['pro_user_id'] ?? 0) === $alice, json_encode($r));
check('B9. with the decrypted address and method in the session', ($r['session']['pro_user_email'] ?? '') === 'alice@example.com' && ($r['session']['pro_login_method'] ?? '') === 'remember');
check('B10. and a rotated cookie', is_string($r['cookie'] ?? null) && $r['cookie'] !== $aliceCookie2);
check('B11. the restore counts as activity (last_login_at)', $pdo->query("SELECT last_login_at FROM pro_users WHERE id = {$alice}")->fetchColumn() !== null);
$aliceCookie3 = $r['cookie'];

$r = $restore(str_repeat('a', 32) . '.' . str_repeat('b', 64));
check('B12. an unknown cookie signs nobody in, and is cleared', ($r['restored'] ?? null) === false && empty($r['session']['pro_user_id']) && array_key_exists('cookie', $r) && $r['cookie'] === null, json_encode($r));
$r = $restore(null);
check('B13. no cookie, no session', ($r['restored'] ?? null) === false && empty($r['session']['pro_user_id']));

$pdo->exec("UPDATE pro_users SET suspended_at = '2026-01-02 00:00:00' WHERE id = {$alice}");
$r = $restore($aliceCookie3);
check('B14. a suspended account is not restored', ($r['restored'] ?? null) === false && empty($r['session']['pro_user_id']), json_encode($r));
check('B15. and loses the token', tokenCount($pdo, $alice) === 0);
$pdo->exec("UPDATE pro_users SET suspended_at = NULL WHERE id = {$alice}");

// A password change removes every device kept signed in.
$login('alice@example.com', '7');
$login('alice@example.com', '1');
$login('bob@example.com', '7');
check('B16. two devices for alice, one for bob', tokenCount($pdo, $alice) === 2 && tokenCount($pdo, $bob) === 1);
$confirm = bin2hex(random_bytes(24));
$pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, 'set_password', ?, ?, ?)")
    ->execute([$alice, json_encode(['password_hash' => password_hash('new-horse', PASSWORD_DEFAULT)]), hash('sha256', $confirm), date('Y-m-d H:i:s', time() + 3600)]);
$out = $run('pro_auth.php', ['method' => 'GET', 'get' => ['confirm_profile_change' => $confirm]]);
check('B17. confirming a password change removes all of the account\'s tokens', strpos($out, 'Password change confirmed') !== false && tokenCount($pdo, $alice) === 0, $out);
check('B18. and no one else\'s', tokenCount($pdo, $bob) === 1);

// Account deletion removes them too.
$confirm = bin2hex(random_bytes(24));
$pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, 'delete_account', '{}', ?, ?)")
    ->execute([$bob, hash('sha256', $confirm), date('Y-m-d H:i:s', time() + 3600)]);
$out = $run('pro_auth.php', ['method' => 'GET', 'get' => ['confirm_profile_change' => $confirm]]);
check('B19. deleting the account removes its tokens', (int) $pdo->query("SELECT COUNT(*) FROM pro_users WHERE id = {$bob}")->fetchColumn() === 0 && tokenCount($pdo, $bob) === 0, $out);

// Magic link: the chosen period rides in the link.
$pdo->exec('DELETE FROM magic_link_requests');
@unlink($probe . '/mail.log');
$out = $run('pro_auth.php', ['method' => 'POST', 'post' => ['action' => 'request_login_link', 'email' => 'alice@example.com', 'stay_days' => '30']]);
$mail = (string) @file_get_contents($probe . '/mail.log');
check('B20. the magic link carries the chosen period', preg_match('~pro_login\.php\?token=[A-Za-z0-9%_-]+&stay=30~', $mail) === 1, $mail . $out);
@unlink($probe . '/mail.log');
$run('pro_auth.php', ['method' => 'POST', 'post' => ['action' => 'request_login_link', 'email' => 'alice@example.com', 'stay_days' => '0']]);
$mail = (string) @file_get_contents($probe . '/mail.log');
check('B21. and carries none for "until I close the browser"', strpos($mail, 'pro_login.php?token=') !== false && strpos($mail, '&stay=') === false, $mail);

$log = (string) @file_get_contents($logPath);
check('B22. no address is logged', strpos($log, 'alice@example.com') === false && strpos($log, 'bob@example.com') === false);
check('B23. no cookie value is logged', !is_string($aliceCookie) || strpos($log, explode('.', $aliceCookie)[1]) === false);

foreach (glob($probe . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($probe);

// ---------------------------------------------------------------------------
echo "C. wiring\n";
// ---------------------------------------------------------------------------

$src = fn(string $file): string => (string) file_get_contents($repoRoot . '/' . $file);
$config = $src('config.php');
$fn = substr($config, (int) strpos($config, 'function proSessionEndIfSuspended('), 400);
check('C1. config.php loads pro_remember.php', strpos($config, "require_once __DIR__ . '/pro_remember.php';") !== false);
check('C2. every session page restores through proSessionEndIfSuspended()', strpos($fn, 'proRememberRestoreSession();') !== false);
check('C3. a suspended session also loses its cookie', strpos($fn, 'proRememberForgetCurrent();') !== false);
check('C4. sign-out removes the token server-side', strpos($src('pro_logout.php'), 'proRememberForgetCurrent();') !== false);
$auth = $src('pro_auth.php');
check('C5. pro_auth.php issues a token on all three password paths and the magic-link GET', substr_count($auth, 'proRememberIssue(') === 4);
check('C6. pro_auth.php revokes on password change, email change, both undos and deletion', substr_count($auth, 'proRememberRevokeAllFor(') === 5);
$login = $src('pro_login.php');
check('C7. pro_login.php issues after a magic-link sign-in', substr_count($login, 'proRememberIssue(') === 1);
check('C8. pro_login.php never restores over a magic link', strpos($login, "if (!isset(\$_GET['token'])) {\n    proRememberRestoreSession();") !== false);
$cleanup = $src('cron/cleanup.php');
check('C9. cron/cleanup.php sweeps expired tokens and a deleted inactive account\'s', strpos($cleanup, 'proRememberCleanup($pdo)') !== false && substr_count($cleanup, 'proRememberRevokeAllFor(') === 1);
check('C10. the cookie is HttpOnly and SameSite=Lax', preg_match("~'httponly' => true,\s*'samesite' => 'Lax'~", $src('pro_remember.php')) === 1);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
