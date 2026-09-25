<?php

declare(strict_types=1);

/**
 * Regression coverage for users' email addresses at rest, phase A:
 * pii_crypto.php (normalisation, blind index, AES-256-GCM round trip,
 * tamper/wrong-key/missing-key failures) and the dual-write in pro_auth.php
 * (registration, voucher account creation, confirmed email change, undo),
 * run for real as a subprocess on SQLite with and without the new columns
 * and with and without the keys. The Paddle customer dual-write is covered in
 * tests/paddle_sync_test.php.
 *
 * Run with:  php tests/pii_crypto_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. SQLite only: no MySQL, no
 * network, no mail (the subprocess runs with sendmail_path=/bin/true).
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

function probeDb(string $path, bool $piiColumns): PDO
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
    if ($piiColumns) {
        $pdo->exec('ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL');
        $pdo->exec('ALTER TABLE pro_users ADD COLUMN email_hash CHAR(64) NULL');
        $pdo->exec('CREATE UNIQUE INDEX uniq_pro_users_email_hash ON pro_users (email_hash)');
    }
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
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'sendmail_path=/bin/true', $probe . '/pro_auth.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
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

// 3a. Columns present, keys configured: every path writes a consistent pair.
$pdo = probeDb($db, true);
@unlink($log);
$out = probeRequest($probe, $db, $log, $withKeys, register('reg@example.com'));
$reg = userRow($pdo, 'reg@example.com');
check('P1. registration (Regular) writes the plaintext as before', $reg !== null && $reg['account_type'] === 'regular', $out);
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
same('P8. with keys: no missing-key WARNING', 0, warningCount($log));
$allLog = (string) @file_get_contents($log);
check('P9. nothing logged carries an address or a stored pair',
    stripos($allLog, '@example.com') === false && stripos($allLog, (string) $reverted['email_hash']) === false, $allLog);

// 3b. Columns present, keys missing: plaintext as today, pair null, a WARNING.
$pdo = probeDb($db, true);
@unlink($log);
$out = probeRequest($probe, $db, $log, [], register('nokey@example.com'));
$row = userRow($pdo, 'nokey@example.com');
check('K1. no keys: registration still succeeds', $row !== null && str_contains($out, '"success":true'), $out);
check('K2. no keys: the pair stays null', $row !== null && $row['email_enc'] === null && $row['email_hash'] === null, json_encode($row));
same('K3. no keys: one WARNING for the request', 1, warningCount($log));
probeRequest($probe, $db, $log, [], register('nokey-pro@example.com', 'pro'));
$row = userRow($pdo, 'nokey-pro@example.com');
check('K4. no keys: voucher registration still succeeds, pair null', $row !== null && $row['account_type'] === 'pro' && $row['email_hash'] === null, json_encode($row));
// A row that already has a pair (written while the keys were set) changes address without keys.
$seed = piiEmailFields('nokey@example.com', $encKey, $idxKey);
$pdo->prepare('UPDATE pro_users SET email_enc = ?, email_hash = ? WHERE email = ?')->execute([$seed['email_enc'], $seed['email_hash'], 'nokey@example.com']);
$uid = (int) userRow($pdo, 'nokey@example.com')['id'];
$out = probeRequest($probe, $db, $log, [], pendingRequest($pdo, $uid, 'update_email', ['new_email' => 'nokey-new@example.com'], 'confirm_profile_change'));
$row = userRow($pdo, 'nokey-new@example.com');
check('K5. no keys: the email change still goes through', $row !== null && (int) $row['id'] === $uid, $out);
check('K6. no keys: the old pair is cleared, never left describing the old address', $row !== null && $row['email_enc'] === null && $row['email_hash'] === null, json_encode($row));

// 3c. Columns absent (migration not run), keys configured: exactly today's behaviour.
$pdo = probeDb($db, false);
@unlink($log);
$out = probeRequest($probe, $db, $log, $withKeys, register('old@example.com'));
check('C1. no columns: registration succeeds', userRow($pdo, 'old@example.com') !== null && str_contains($out, '"success":true'), $out);
$out = probeRequest($probe, $db, $log, $withKeys, register('old-pro@example.com', 'pro'));
check('C2. no columns: voucher registration succeeds', (userRow($pdo, 'old-pro@example.com')['account_type'] ?? null) === 'pro', $out);
$uid = (int) userRow($pdo, 'old@example.com')['id'];
$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, $uid, 'update_email', ['new_email' => 'old-new@example.com'], 'confirm_profile_change'));
check('C3. no columns: the email change succeeds', userRow($pdo, 'old-new@example.com') !== null, $out);
$out = probeRequest($probe, $db, $log, $withKeys, pendingRequest($pdo, $uid, 'undo_update_email', ['old_email' => 'old@example.com'], 'undo_profile_change'));
check('C4. no columns: the undo succeeds', userRow($pdo, 'old@example.com') !== null, $out);
$logText = (string) @file_get_contents($log);
check('C5. no columns: no WARNING and no ERROR about the pair', warningCount($log) === 0 && stripos($logText, 'email_enc') === false, $logText);

// 3d. Keys configured, a failed follow-up write does not break registration.
$pdo = probeDb($db, true);
@unlink($log);
$pdo->exec("CREATE TRIGGER fail_pair BEFORE UPDATE OF email_hash ON pro_users BEGIN SELECT RAISE(ABORT, 'forced'); END");
$out = probeRequest($probe, $db, $log, $withKeys, register('trigger@example.com'));
$row = userRow($pdo, 'trigger@example.com');
check('T1. a failing pair write is fail-open: the account is still created', $row !== null && str_contains($out, '"success":true') && $row['email_hash'] === null, $out);
check('T2. ... and logged as an ERROR with the user id', str_contains((string) @file_get_contents($log), 'Could not store email_enc'));

// ---------------------------------------------------------------------------
// 4. No write path to pro_users.email was missed
// ---------------------------------------------------------------------------

$writers = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $rel = substr($path, strlen($repoRoot) + 1);
    if (substr($path, -4) !== '.php' || preg_match('#^(vendor|tests)/#', $rel)) {
        continue;
    }
    $text = (string) file_get_contents($path);
    if (preg_match_all('/INSERT\s+INTO\s+pro_users\s*\([^)]*\bemail\b|UPDATE\s+pro_users\s+SET\s+email\s*=/i', $text, $m)) {
        $writers[$rel] = count($m[0]);
    }
}
ksort($writers);
same('S1. pro_users.email is written only in the audited places (pro_auth.php: 3 register + 2 voucher + 1 getOrCreateProUser INSERTs, confirm + undo UPDATEs)',
    ['pro_auth.php' => 8], $writers);
$authText = (string) file_get_contents($repoRoot . '/pro_auth.php');
same('S2. every INSERT path is followed by proUserStoreEmailPii() (getOrCreateProUser, voucher, register)', 3, substr_count($authText, 'proUserStoreEmailPii('));
same('S3. both email UPDATEs write the pair in the same statement', 2, substr_count($authText, '", email_enc = ?, email_hash = ?"'));

// Clean up.
foreach (glob($probe . '/*') as $file) {
    @unlink($file);
}
@rmdir($probe);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
