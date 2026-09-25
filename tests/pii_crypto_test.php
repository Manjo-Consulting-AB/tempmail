<?php

declare(strict_types=1);

/**
 * Regression coverage for users' email addresses at rest (phases A and B1):
 * pii_crypto.php (normalisation, blind index, AES-256-GCM round trip,
 * tamper/wrong-key/missing-key failures), the dual-write in pro_auth.php
 * (registration, voucher account creation, confirmed email change, undo),
 * and the B1 reads - login, magic link, registration and the email change
 * find accounts by email_hash only, mail goes to the address decrypted from
 * email_enc (never to the plaintext column, never to garbage), and without
 * keys everything fails closed - all run for real as a subprocess on
 * SQLite. Repository scans pin who writes pro_users.email and that no SQL
 * reads it. The Paddle side is covered in tests/paddle_sync_test.php.
 *
 * Run with:  php tests/pii_crypto_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. SQLite only: no MySQL, no
 * network, no real mail (the subprocess's sendmail appends to a file).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

require __DIR__ . '/../pii_crypto.php';

$repoRoot = dirname(__DIR__);
$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$encKey = str_repeat('e', 32);
$idxKey = str_repeat('i', 32);
$otherKey = str_repeat('o', 32);

// Nothing from the caller's environment may leak into the no-key cases.
putenv('PII_ENCRYPTION_KEY');
putenv('PII_INDEX_KEY');
unset($_ENV['PII_ENCRYPTION_KEY'], $_ENV['PII_INDEX_KEY']);

// ---------------------------------------------------------------------------
// 1. Normalisation and the blind index
// ---------------------------------------------------------------------------

same('N1. trim + lowercase', 'alice@example.com', piiEmailNormalize("  Alice@EXAMPLE.com \n"));
same('N2. +tags and dots are kept (one address, one account)', 'a.lice+x@example.com', piiEmailNormalize('A.Lice+X@example.com'));
same('N3. empty after trim: null', null, piiEmailNormalize("  \t "));

$hash = piiEmailHash('alice@example.com', $idxKey);
check('H1. the index is 64 lowercase hex characters', is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1, var_export($hash, true));
same('H2. stable: same address and key, same value', $hash, piiEmailHash('alice@example.com', $idxKey));
same('H3. case and whitespace are normalised', $hash, piiEmailHash(' ALICE@example.com ', $idxKey));
check('H4. a different address gives a different value', $hash !== piiEmailHash('bob@example.com', $idxKey));
check('H5. keyed: a different key gives a different value', $hash !== piiEmailHash('alice@example.com', $otherKey));
check('H6. not an unkeyed hash, not HMAC under the raw key (a sub-key is used)',
    $hash !== hash('sha256', 'alice@example.com') && $hash !== hash_hmac('sha256', 'alice@example.com', $idxKey));
check('H7. never equal to the pro_trial_claims hash under the same raw key',
    !function_exists('proTrialEmailHash') || $hash !== proTrialEmailHash('alice@example.com', $idxKey));
same('H8. no key: null', null, piiEmailHash('alice@example.com'));
same('H9. a key shorter than 32 characters: null', null, piiEmailHash('alice@example.com', str_repeat('i', 31)));
same('H10. an empty address: null', null, piiEmailHash('   ', $idxKey));

// ---------------------------------------------------------------------------
// 2. Encryption
// ---------------------------------------------------------------------------

$enc = piiEmailEncrypt('Alice@example.com', $encKey);
check('E1. the stored form is "v1:" + base64', is_string($enc) && strncmp($enc, 'v1:', 3) === 0 && base64_decode(substr($enc, 3), true) !== false, var_export($enc, true));
same('E2. round trip returns the exact value written (no normalisation)', 'Alice@example.com', piiEmailDecrypt($enc, $encKey));
check('E3. a fresh nonce per call: two encryptions differ', $enc !== piiEmailEncrypt('Alice@example.com', $encKey));
check('E4. the address does not appear in the ciphertext', stripos((string) $enc, 'alice') === false && stripos((string) base64_decode(substr((string) $enc, 3)), 'alice') === false);
same('E5. wrong key: null', null, piiEmailDecrypt($enc, $otherKey));
$raw = base64_decode(substr((string) $enc, 3));
$flipped = 'v1:' . base64_encode(substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 1));
same('E6. a flipped ciphertext bit: null', null, piiEmailDecrypt($flipped, $encKey));
$tagFlip = 'v1:' . base64_encode(substr($raw, 0, 12) . chr(ord($raw[12]) ^ 1) . substr($raw, 13));
same('E7. a flipped tag bit: null', null, piiEmailDecrypt($tagFlip, $encKey));
same('E8. truncated: null', null, piiEmailDecrypt('v1:' . base64_encode(substr($raw, 0, 28)), $encKey));
same('E9. not base64: null', null, piiEmailDecrypt('v1:%%%', $encKey));
same('E10. unknown format (no prefix): null', null, piiEmailDecrypt(base64_encode($raw), $encKey));
same('E11. null/empty stored value: null', [null, null], [piiEmailDecrypt(null, $encKey), piiEmailDecrypt('', $encKey)]);
$gcmNoAad = (function () use ($encKey) {
    $nonce = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt('alice@example.com', 'aes-256-gcm', hash('sha256', $encKey, true), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return 'v1:' . base64_encode($nonce . $tag . $c);
})();
same('E12. a GCM value made for another purpose (no AAD) does not open', null, piiEmailDecrypt($gcmNoAad, $encKey));
same('E13. no key: encrypt gives null', null, piiEmailEncrypt('alice@example.com'));
same('E14. no key: decrypt gives null', null, piiEmailDecrypt($enc));
same('E15. an empty address is not encrypted', null, piiEmailEncrypt('', $encKey));
same('E16. a key shorter than 32 characters: null', null, piiEmailEncrypt('alice@example.com', str_repeat('e', 31)));

$fields = piiEmailFields('alice@example.com', $encKey, $idxKey);
check('F1. piiEmailFields gives both columns, consistent with each other',
    is_array($fields) && $fields['email_hash'] === $hash && piiEmailDecrypt($fields['email_enc'], $encKey) === 'alice@example.com');
same('F2. only the encryption key: null (never half a pair)', null, piiEmailFields('alice@example.com', $encKey, ''));
same('F3. only the index key: null', null, piiEmailFields('alice@example.com', '', $idxKey));
same('F4. keys from the environment are the default', true, (function () use ($encKey, $idxKey) {
    putenv('PII_ENCRYPTION_KEY=' . $encKey);
    putenv('PII_INDEX_KEY=' . $idxKey);
    $f = piiEmailFields('alice@example.com');
    putenv('PII_ENCRYPTION_KEY');
    putenv('PII_INDEX_KEY');
    return is_array($f) && $f['email_hash'] === piiEmailHash('alice@example.com', $idxKey) && piiEmailDecrypt($f['email_enc'], $encKey) === 'alice@example.com';
})());

$logged = [];
$collector = function ($level, $message, $context) use (&$logged) {
    $logged[] = [$level, $message, $context];
};
piiEmailWarnMissingKeys('pro_users', $collector);
piiEmailWarnMissingKeys('paddle_customers', $collector);
check('W1. the missing-key WARNING is logged once per request', count($logged) === 1 && $logged[0][0] === 'WARNING', json_encode($logged));

// ---------------------------------------------------------------------------
// 3. pro_auth.php write paths, run for real as a subprocess
// ---------------------------------------------------------------------------

function probeStubConfig(): string
{
    return <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/pii_crypto_test.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');

$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => 'http://localhost:8085/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => 60, 'hash_key' => '', 'claim_retention_days' => 1825],
];

/** MySQL-only syntax the exercised paths use, rewritten for SQLite. */
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(['DATE_SUB(NOW(), INTERVAL ? MINUTE)', ' FOR UPDATE'], ['PROBE_MINUTES_AGO(?)', ''], $query);
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
function isDisposableEmailDomain(string $email): bool { return false; }

$request = json_decode((string) getenv('PROBE_REQUEST'), true);
$_SERVER['REQUEST_METHOD'] = $request['method'];
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];
PHP;
}

$probe = sys_get_temp_dir() . '/ms_pii_crypto_' . bin2hex(random_bytes(6));
mkdir($probe);
foreach (['pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'login_tokens.php', 'email_log_ref.php', 'pii_crypto.php'] as $file) {
    copy($repoRoot . '/' . $file, $probe . '/' . $file);
}
file_put_contents($probe . '/config.php', probeStubConfig());
$db = $probe . '/probe.sqlite';
$log = $probe . '/probe.log';
$mailLog = $probe . '/mail.log';

function probeDb(string $path): PDO
{
    @unlink($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE pro_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NULL,
        account_type TEXT NOT NULL DEFAULT 'regular',
        email_verified_at TEXT NULL,
        address_ttl_days INTEGER NOT NULL DEFAULT 1,
        pro_expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    // What migrate_email_encryption.php added in production (phase A).
    $pdo->exec('ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL');
    $pdo->exec('ALTER TABLE pro_users ADD COLUMN email_hash CHAR(64) NULL');
    $pdo->exec('CREATE UNIQUE INDEX uniq_pro_users_email_hash ON pro_users (email_hash)');
    $pdo->exec("CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending')");
    $pdo->exec('CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE registration_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('ALTER TABLE pro_users ADD COLUMN password_changed_at TEXT NULL');
    $pdo->exec('CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE pending_profile_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, action TEXT NOT NULL, data TEXT, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, expires_at TEXT NULL, max_uses INTEGER NULL, current_uses INTEGER NOT NULL DEFAULT 0, duration_days INTEGER NULL)');
    $pdo->exec('CREATE TABLE redemption_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, voucher_id INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO vouchers (code, duration_days) VALUES ('GOODCODE', 30)");
    return $pdo;
}

/** Runs one request against the copied pro_auth.php; returns stdout. */
function probeRequest(string $probe, string $db, string $log, array $keys, array $request): string
{
    $env = ['PROBE_SQLITE' => $db, 'PROBE_LOG' => $log, 'PROBE_REQUEST' => json_encode($request), 'PATH' => (string) getenv('PATH')] + $keys;
    // Every mail() is appended to mail.log, so recipients can be asserted.
    $sendmail = 'sendmail_path=cat >> ' . escapeshellarg($probe . '/mail.log');
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', $sendmail, '-d', 'session.save_path=' . $probe, $probe . '/pro_auth.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return (string) $out . ($err !== '' ? "\n[stderr] " . $err : '');
}

function register(string $email, string $plan = 'regular'): array
{
    $post = ['action' => 'register_account', 'plan' => $plan, 'email' => $email, 'password' => 'correct-horse', 'password_confirm' => 'correct-horse'];
    if ($plan === 'pro') {
        $post['code'] = 'GOODCODE';
    }
    return ['method' => 'POST', 'post' => $post];
}

/** Stores a pending change and returns the GET request that confirms (or undoes) it. */
function pendingRequest(PDO $pdo, int $userId, string $action, array $data, string $param): array
{
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $action, json_encode($data), hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
    return ['method' => 'GET', 'get' => [$param => $token]];
}

function userRow(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pro_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function pairMatches(?array $row, string $email, string $encKey, string $idxKey): bool
{
    return $row !== null
        && $row['email_hash'] === piiEmailHash($email, $idxKey)
        && piiEmailDecrypt($row['email_enc'], $encKey) === $email;
}

function warningCount(string $log): int
{
    return substr_count((string) @file_get_contents($log), 'PII_INDEX_KEY missing');
}

$withKeys = ['PII_ENCRYPTION_KEY' => $encKey, 'PII_INDEX_KEY' => $idxKey];

function logCount(string $log, string $needle): int
{
    return substr_count((string) @file_get_contents($log), $needle);
}

/** Adds an account the way phase A leaves it: plaintext plus a consistent pair. */
function seedUser(PDO $pdo, string $email, string $encKey, string $idxKey, string $password = 'correct-horse'): int
{
    $pdo->prepare("INSERT INTO pro_users (email, email_enc, email_hash, password_hash, email_verified_at) VALUES (?, ?, ?, ?, '2026-01-01 00:00:00')")
        ->execute([$email, piiEmailEncrypt($email, $encKey), piiEmailHash($email, $idxKey), password_hash($password, PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
}

/** Recipients (To: headers) of every mail the subprocesses handed to sendmail. */
function mailRecipients(string $mailLog): array
{
    preg_match_all('/^To: (.+)$/mi', (string) @file_get_contents($mailLog), $m);
    return array_map('trim', $m[1]);
}

// 3a. Keys configured: every write path still writes plaintext plus a consistent pair.
$pdo = probeDb($db);
@unlink($log);
$out = probeRequest($probe, $db, $log, $withKeys, register('reg@example.com'));
$reg = userRow($pdo, 'reg@example.com');
check('P1. registration (Regular) still writes the plaintext', $reg !== null && $reg['account_type'] === 'regular', $out);
check('P2. registration writes email_enc/email_hash for that address', pairMatches($reg, 'reg@example.com', $encKey, $idxKey), json_encode($reg));

$out = probeRequest($probe, $db, $log, $withKeys, register('pro@example.com', 'pro'));
$pro = userRow($pdo, 'pro@example.com');
check('P3. registration with a voucher creates the Pro account', $pro !== null && $pro['account_type'] === 'pro', $out);
check('P4. the voucher-created account has a consistent pair', pairMatches($pro, 'pro@example.com', $encKey, $idxKey), json_encode($pro));

$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, (int) $reg['id'], 'update_email', ['new_email' => 'new@example.com'], 'confirm_profile_change'));
$changed = userRow($pdo, 'new@example.com');
check('P5. a confirmed email change updates the plaintext', $changed !== null && (int) $changed['id'] === (int) $reg['id'], $out);
check('P6. ... and the pair now describes the new address', pairMatches($changed, 'new@example.com', $encKey, $idxKey), json_encode($changed));

$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, (int) $reg['id'], 'undo_update_email', ['old_email' => 'reg@example.com', 'new_email' => 'new@example.com'], 'undo_profile_change'));
$reverted = userRow($pdo, 'reg@example.com');
check('P7. undo restores the old address and its pair', pairMatches($reverted, 'reg@example.com', $encKey, $idxKey), $out . json_encode($reverted));
$allLog = (string) @file_get_contents($log);
check('P8. with keys: no key WARNING or ERROR', logCount($log, 'PII_INDEX_KEY missing') === 0 && logCount($log, 'email_enc') === 0, $allLog);
check('P9. nothing logged carries an address or a stored pair',
    stripos($allLog, '@example.com') === false && stripos($allLog, (string) $reverted['email_hash']) === false, $allLog);

// 3b. Reads go through email_hash / email_enc only. The plaintext column is
// made to lie (a different address) and must not be consulted.
$pdo = probeDb($db);
@unlink($log);
@unlink($mailLog);
$uid = seedUser($pdo, 'alice@example.com', $encKey, $idxKey);
$pdo->exec("UPDATE pro_users SET email = 'decoy@example.net' WHERE id = {$uid}");
$out = probeRequest($probe, $db, $log, $withKeys, ['method' => 'POST', 'post' => ['action' => 'password_login', 'email' => 'alice@example.com', 'password' => 'correct-horse']]);
check('R1. password login finds the account by email_hash', str_contains($out, '"success":true'), $out);
$out = probeRequest($probe, $db, $log, $withKeys, ['method' => 'POST', 'post' => ['action' => 'password_login', 'email' => '  ALICE@Example.com ', 'password' => 'correct-horse']]);
check('R2. ... case and surrounding whitespace do not matter (as with the old collation)', str_contains($out, '"success":true'), $out);
$out = probeRequest($probe, $db, $log, $withKeys, ['method' => 'POST', 'post' => ['action' => 'password_login', 'email' => 'decoy@example.net', 'password' => 'correct-horse']]);
check('R3. the plaintext column is not a login any more', !str_contains($out, '"success":true'), $out);
probeRequest($probe, $db, $log, $withKeys, ['method' => 'POST', 'post' => ['action' => 'request_login_link', 'email' => 'Alice@example.com']]);
same('R4. a magic link is issued for the account found by email_hash', 1, (int) $pdo->query("SELECT COUNT(*) FROM login_tokens WHERE user_id = {$uid}")->fetchColumn());
probeRequest($probe, $db, $log, $withKeys, ['method' => 'POST', 'post' => ['action' => 'request_login_link', 'email' => 'decoy@example.net']]);
same('R5. ... and none for the plaintext decoy', 1, (int) $pdo->query("SELECT COUNT(*) FROM login_tokens")->fetchColumn());

// Registering the same address again finds the existing account (no new row).
probeRequest($probe, $db, $log, $withKeys, register('ALICE@example.com'));
same('R6. registration finds an existing account by email_hash', 1, (int) $pdo->query('SELECT COUNT(*) FROM pro_users')->fetchColumn());

// An email change to an address another account holds is refused via the hash.
$bob = seedUser($pdo, 'bob@example.com', $encKey, $idxKey);
$pdo->exec("UPDATE pro_users SET email = 'decoy-bob@example.net' WHERE id = {$bob}");
$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, $uid, 'update_email', ['new_email' => 'bob@example.com'], 'confirm_profile_change'));
check('R7. an email change to a taken address is refused (found by email_hash)', str_contains($out, 'already taken'), $out);

// The change notice goes to the decrypted old address, not the plaintext column.
@unlink($mailLog);
$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, $uid, 'update_email', ['new_email' => 'alice2@example.com'], 'confirm_profile_change'));
check('R8. email change applied', str_contains($out, 'Email change confirmed'), $out);
same('R9. the old-address notice goes to the decrypted address', ['alice@example.com'], mailRecipients($mailLog));

// A value that does not decrypt: no mail at all, never to a garbage address.
@unlink($mailLog);
@unlink($log);
$pdo->exec("UPDATE pro_users SET email_enc = 'v1:AAAA' WHERE id = {$bob}");
$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, $bob, 'set_password', ['password_hash' => password_hash('x-new-password', PASSWORD_DEFAULT)], 'confirm_profile_change'));
check('R10. an undecryptable address: the action still completes', str_contains($out, 'Password change confirmed'), $out);
same('R11. ... and no notice is mailed anywhere', [], mailRecipients($mailLog));
check('R12. ... and an ERROR names the user, not an address', logCount($log, 'could not be decrypted') >= 1 && str_contains((string) file_get_contents($log), '"user_id":' . $bob));

// 3c. Keys missing: every lookup fails closed, nothing is created, errors are logged.
$pdo = probeDb($db);
@unlink($log);
$uid = seedUser($pdo, 'alice@example.com', $encKey, $idxKey);
$out = probeRequest($probe, $db, $log, [], ['method' => 'POST', 'post' => ['action' => 'password_login', 'email' => 'alice@example.com', 'password' => 'correct-horse']]);
check('K1. no keys: password login finds nothing (fail closed)', !str_contains($out, '"success":true') && str_contains($out, 'Incorrect email or password'), $out);
check('K2. ... with an ERROR logged', logCount($log, 'lookup by email address finds nothing') === 1, (string) @file_get_contents($log));
probeRequest($probe, $db, $log, [], ['method' => 'POST', 'post' => ['action' => 'request_login_link', 'email' => 'alice@example.com']]);
same('K3. no keys: no magic link is issued', 0, (int) $pdo->query('SELECT COUNT(*) FROM login_tokens')->fetchColumn());
$out = probeRequest($probe, $db, $log, [], register('newbie@example.com'));
check('K4. no keys: registration is refused, nothing created', str_contains($out, 'temporarily unavailable') && userRow($pdo, 'newbie@example.com') === null, $out);
$out = probeRequest($probe, $db, $log, [], register('alice@example.com', 'pro'));
check('K5. no keys: voucher registration is refused too', str_contains($out, 'temporarily unavailable') && (int) $pdo->query('SELECT current_uses FROM vouchers')->fetchColumn() === 0, $out);
$out = probeRequest($probe, $db, $log, [], pendingRequest($pdo, $uid, 'update_email', ['new_email' => 'elsewhere@example.com'], 'confirm_profile_change'));
$row = userRow($pdo, 'alice@example.com');
check('K6. no keys: an email change is refused and the account keeps its address and pair',
    !str_contains($out, 'confirmed') && pairMatches($row, 'alice@example.com', $encKey, $idxKey), $out . json_encode($row));
check('K7. no keys: each refusal is an ERROR', logCount($log, 'refused') >= 3, (string) @file_get_contents($log));

// 3d. Keys configured, a failed follow-up write does not break registration.
$pdo = probeDb($db);
@unlink($log);
$pdo->exec("CREATE TRIGGER fail_pair BEFORE UPDATE OF email_hash ON pro_users BEGIN SELECT RAISE(ABORT, 'forced'); END");
$out = probeRequest($probe, $db, $log, $withKeys, register('trigger@example.com'));
$row = userRow($pdo, 'trigger@example.com');
check('T1. a failing pair write is fail-open: the account is still created', $row !== null && str_contains($out, '"success":true') && $row['email_hash'] === null, $out);
check('T2. ... and logged as an ERROR with the user id', str_contains((string) @file_get_contents($log), 'Could not store email_enc'));

// ---------------------------------------------------------------------------
// 4. Repository scans: who writes and who reads pro_users.email
// ---------------------------------------------------------------------------

/** Every string literal of every non-test PHP file, with its file. */
function phpStringLiterals(string $repoRoot): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $rel = substr($path, strlen($repoRoot) + 1);
        if (substr($path, -4) !== '.php' || preg_match('#^(vendor|tests|\.claude|\.git)/#', $rel)) {
            continue;
        }
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = [$rel, $token[1], $token[2]];
            }
        }
    }
    return $out;
}

$literals = phpStringLiterals($repoRoot);

$writers = [];
foreach ($literals as [$rel, $text]) {
    if (preg_match('/INSERT\s+INTO\s+pro_users\s*\([^)]*\bemail\b|UPDATE\s+pro_users\s+SET\s+email\s*=/i', $text)) {
        $writers[$rel] = ($writers[$rel] ?? 0) + 1;
    }
}
ksort($writers);
same('S1. pro_users.email is written only in the audited places (pro_auth.php: 3 register + 2 voucher + 1 getOrCreateProUser INSERTs, confirm + undo UPDATEs)',
    ['pro_auth.php' => 8], $writers);
$authText = (string) file_get_contents($repoRoot . '/pro_auth.php');
same('S2. every INSERT path is followed by proUserStoreEmailPii() (getOrCreateProUser, voucher, register)', 3, substr_count($authText, 'proUserStoreEmailPii('));
same('S3. both email UPDATEs write the pair in the same statement', 2, substr_count($authText, '", email_enc = ?, email_hash = ?"'));

// A read of the plaintext column: an SQL literal naming pro_users (or its
// "pu" alias) together with the bare column "email", other than the audited
// writes above. The migration and the audit script compare the pair against
// the plaintext on purpose and are the only exceptions.
$readExceptions = ['migrate_email_encryption.php', 'check_email_encryption.php'];
$readers = [];
foreach ($literals as [$rel, $text, $line]) {
    if (in_array($rel, $readExceptions, true)) {
        continue;
    }
    $namesTable = preg_match('/\bpro_users\b/i', $text) === 1 || preg_match('/\bpu\.email\b/i', $text) === 1;
    $isSql = preg_match('/\b(SELECT|WHERE|UPDATE|INSERT|DELETE|JOIN)\b/i', $text) === 1;
    if (!$namesTable || !$isSql) {
        continue;
    }
    // Remove the quotes and the audited write prefixes, then look for the bare column.
    $sql = trim($text, "\"'");
    $rest = preg_replace('/^\s*(INSERT\s+INTO\s+pro_users\s*\([^)]*\)\s*VALUES\s*\(.*\)\s*$|UPDATE\s+pro_users\s+SET\s+email\s*=\s*\?)/is', '', $sql);
    if (preg_match('/(?<![\w.])(pu\.)?email\b(?!_)/i', (string) $rest) === 1) {
        $readers[] = "{$rel}:{$line}";
    }
}
same('S4. no SQL reads pro_users.email any more (lookups use email_hash, the address comes from email_enc)', [], $readers);
check('S5. the read scan does catch a plaintext read (self-test)',
    preg_match('/(?<![\w.])(pu\.)?email\b(?!_)/i', 'SELECT id FROM pro_users WHERE email = ?') === 1
    && preg_match('/(?<![\w.])(pu\.)?email\b(?!_)/i', 'SELECT lt.id, pu.email FROM login_tokens lt JOIN pro_users pu') === 1
    && preg_match('/(?<![\w.])(pu\.)?email\b(?!_)/i', 'SELECT id FROM pro_users WHERE email_hash = ?') === 0);

// Clean up.
foreach (glob($probe . '/*') as $file) {
    @unlink($file);
}
@rmdir($probe);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
