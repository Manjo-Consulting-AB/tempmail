<?php

declare(strict_types=1);

/**
 * Regression coverage for the voucher service (voucher_service.php, epic #359
 * step 2/4) and for the lifetime-Pro fix in redeemVoucherForEmail()
 * (pro_auth.php).
 *
 * Run with:  php tests/voucher_service_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 *  A. voucherGenerateCode(): shape, alphabet, 1 000 distinct codes;
 *  B. voucherCreate(): every $spec rule, actor validation, issuer
 *     idempotency, what actually lands in the row;
 *  C. voucherList(): newest first, each filter, limit/offset;
 *  D. voucherSetActive(): an issuer may only touch its own, an admin any;
 *  E. voucherRedemptions(): account ids and timestamps, never an address;
 *  F. the real redeemVoucherForEmail() (pro_auth.php, run as its own process
 *     against the same SQLite file): a voucher the service created is
 *     redeemable, max_uses is a hard cap, and a timed voucher cannot shorten
 *     lifetime Pro;
 *  G. repository scans: only voucher_service.php INSERTs into vouchers, and
 *     no logMessage() in voucher_service.php / pro_auth.php carries a code;
 *  H. voucherCreateBatch(): the count bounds, the refused keys, the shared
 *     validation, one transaction (a forced failure leaves zero rows), a
 *     forced max_uses of 1, and a batch code redeemed exactly once by the
 *     real redeemVoucherForEmail();
 *  I. voucherBatchCsv(): the header, the row content, the formula-injection
 *     guard, the issuer scoping and a malformed id reaching no query.
 *
 * See also tests/pii_crypto_test.php (the address-at-rest harness this file's
 * subprocess probe is modelled on) and tests/mailbox_service_test.php (the
 * service-suite style).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__);
require __DIR__ . '/lib/pushover_harness.php';

if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
require $repoRoot . '/voucher_service.php';
require $repoRoot . '/pii_crypto.php';

// Keys for the blind index the redemption path looks accounts up by, in this
// process and (handed through the environment) in the probe subprocess.
$encKey = str_repeat('e', 32);
$idxKey = str_repeat('i', 32);
$trialKey = str_repeat('t', 32);
putenv('PII_ENCRYPTION_KEY=' . $encKey);
putenv('PII_INDEX_KEY=' . $idxKey);
$_ENV['PII_ENCRYPTION_KEY'] = $encKey;
$_ENV['PII_INDEX_KEY'] = $idxKey;
$piiEnv = ['PII_ENCRYPTION_KEY' => $encKey, 'PII_INDEX_KEY' => $idxKey];

// voucher_service.php logs through config.php's logMessage(). Recorded here,
// so the suite can assert on the log lines the service writes.
$GLOBALS['voucher_test_logs'] = [];
function logMessage($level, $message, $context = null)
{
    $GLOBALS['voucher_test_logs'][] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    return true;
}

function voucherTestLogs(string $needle): array
{
    $out = [];
    foreach ($GLOBALS['voucher_test_logs'] as $entry) {
        if ($entry['message'] === $needle) {
            $out[] = $entry;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------
// Schema: the voucher tables as migrate_vouchers.php leaves them, plus the
// pro_users columns the redemption path reads.
// ---------------------------------------------------------------------

function voucherTestSchema(): string
{
    return <<<'SQL'
CREATE TABLE pro_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    email_enc TEXT NULL,
    email_hash TEXT NULL,
    password_hash TEXT NULL,
    account_type TEXT NOT NULL DEFAULT 'regular',
    email_verified_at TEXT NULL,
    address_ttl_days INTEGER NOT NULL DEFAULT 1,
    pro_expires_at TEXT NULL,
    created_at TEXT NULL
);

CREATE UNIQUE INDEX uniq_pro_users_email_hash ON pro_users (email_hash);

CREATE TABLE pro_trial_claims (
    email_hash TEXT PRIMARY KEY,
    first_seen_at TEXT NOT NULL
);

CREATE TABLE vouchers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT NULL,
    max_uses INTEGER NULL,
    current_uses INTEGER NOT NULL DEFAULT 0,
    duration_days INTEGER NULL,
    created_at TEXT NULL,
    source TEXT NOT NULL DEFAULT 'legacy',
    created_by_user_id INTEGER NULL,
    issuer_id INTEGER NULL,
    external_ref TEXT NULL,
    batch_id TEXT NULL,
    note TEXT NULL
);

CREATE UNIQUE INDEX uniq_vouchers_code ON vouchers (code);
CREATE UNIQUE INDEX uniq_vouchers_issuer_ref ON vouchers (issuer_id, external_ref);
CREATE INDEX idx_vouchers_batch ON vouchers (batch_id);

-- redeemed_at as migrate_vouchers.php creates it on a fresh table: the
-- redemption path writes only user_id and voucher_id, so the database clock
-- supplies the time.
CREATE TABLE redemption_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    voucher_id INTEGER NOT NULL,
    redeemed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX uniq_redemption_log_user_voucher ON redemption_log (user_id, voucher_id);
SQL;
}

// ---------------------------------------------------------------------
// The probe docroot: pro_auth.php and its dependencies + a stub config.php
// backed by the same SQLite file, exactly as tests/pii_crypto_test.php does.
// ---------------------------------------------------------------------

function voucherTestStubConfig(): string
{
    return <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/voucher_service_test.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');

$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => 'http://localhost:8085/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => 60, 'hash_key' => str_repeat('t', 32), 'claim_retention_days' => 1825],
];

/** MySQL-only syntax the redemption path uses, rewritten for SQLite. */
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(' FOR UPDATE', '', $query);
        return parent::prepare($query, $options);
    }

    /** MySQL self-healing DDL: the probe schema already has it. */
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
PHP;
}

/**
 * The test-only driver: loads pro_auth.php without running its endpoint
 * dispatch (the guard near the top of that file compares SCRIPT_FILENAME with
 * its own __FILE__, and this file is neither) and calls
 * redeemVoucherForEmail() directly — so the suite asserts on the real
 * error_code rather than the message it maps to.
 */
function voucherTestCaller(): string
{
    return <<<'PHP'
<?php
require __DIR__ . '/config.php';
require __DIR__ . '/pro_auth.php';

$request = json_decode((string) getenv('VOUCHER_PROBE_REQUEST'), true);
$result = redeemVoucherForEmail(
    (string) ($request['email'] ?? ''),
    (string) ($request['code'] ?? '')
);
file_put_contents((string) getenv('VOUCHER_PROBE_OUT'), (string) json_encode($result));
PHP;
}

function voucherTestProbeBuild(string $repoRoot, string $probe): void
{
    if (!mkdir($probe, 0700, true) && !is_dir($probe)) {
        throw new RuntimeException("Could not create the probe docroot at {$probe}");
    }
    foreach ([
        'pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'referrals.php', 'login_tokens.php',
        'email_log_ref.php', 'pii_crypto.php', 'pro_remember.php', 'mcp_tokens.php',
        'after_login.php',
    ] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }
    file_put_contents($probe . '/config.php', voucherTestStubConfig());
    file_put_contents($probe . '/probe_caller.php', voucherTestCaller());
}

/**
 * Call the real redeemVoucherForEmail() in its own process, against the same
 * SQLite file, and return its array (or ['_error' => ...] when the probe fell
 * over, so a broken probe can never look like a refusal).
 */
function voucherTestRedeem(string $probe, string $db, string $email, string $code, array $piiEnv): array
{
    $outFile = $probe . '/probe_out.json';
    @unlink($outFile);
    $env = [
        'PROBE_SQLITE' => $db,
        'PROBE_LOG' => $probe . '/probe.log',
        'VOUCHER_PROBE_REQUEST' => (string) json_encode(['email' => $email, 'code' => $code]),
        'VOUCHER_PROBE_OUT' => $outFile,
        'PATH' => (string) getenv('PATH'),
    ] + $piiEnv;

    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', $probe . '/probe_caller.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $probe,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the voucher probe');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $decoded = json_decode((string) @file_get_contents($outFile), true);
    if (!is_array($decoded)) {
        return ['_error' => trim($stdout . "\n" . $stderr)];
    }
    return $decoded;
}

// ---------------------------------------------------------------------
// Fixtures / readers
// ---------------------------------------------------------------------

function voucherTestRow(PDO $pdo, int $voucherId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ?');
    $stmt->execute([$voucherId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function voucherTestUserRow(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pro_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Keys of a voucherList() answer, in order — the codes it returned. */
function voucherTestCodes(array $result): array
{
    return array_map(static fn(array $v): string => (string) $v['code'], $result['vouchers'] ?? []);
}

function voucherTestSeedUser(PDO $pdo, string $email, string $accountType, ?string $proExpiresAt, string $encKey, string $idxKey): int
{
    $pdo->prepare('INSERT INTO pro_users (email, email_enc, email_hash, account_type, pro_expires_at, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, piiEmailEncrypt($email, $encKey), piiEmailHash($email, $idxKey), $accountType, $proExpiresAt, '2026-01-01 00:00:00', date('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
}

$admin = ['type' => 'admin', 'id' => 1];
$issuer = ['type' => 'issuer', 'id' => 7];

$probe = sys_get_temp_dir() . '/ms-voucher-' . bin2hex(random_bytes(4));
voucherTestProbeBuild($repoRoot, $probe);
$db = $probe . '/vouchers.sqlite';
$pdo = ms_test_db($db);
$pdo->exec(voucherTestSchema());

// =====================================================================
ms_test_section('A. voucherGenerateCode()');
// =====================================================================

$code = voucherGenerateCode();
ms_test_check('A1. the shape is MS-XXXX-XXXX-XXXX', preg_match('/^MS-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) === 1, $code);
ms_test_check('A2. it matches the redemption regex and is at most 64 characters',
    preg_match('/^[A-Za-z0-9_-]+$/', $code) === 1 && strlen($code) <= 64, $code);
ms_test_check('A3. no 0, O, 1, I or L (nothing mistakable read aloud)', preg_match('/[0O1IL]/', $code) === 0, $code);

$codes = [];
$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
$alphabetOnly = true;
for ($i = 0; $i < 1000; $i++) {
    $codes[] = voucherGenerateCode();
}
foreach ($codes as $generated) {
    $body = str_replace(['MS-', '-'], '', $generated);
    for ($i = 0, $n = strlen($body); $i < $n; $i++) {
        if (strpos($alphabet, $body[$i]) === false) {
            $alphabetOnly = false;
        }
    }
}
ms_test_same('A4. 1 000 generated codes are all distinct', 1000, count(array_unique($codes)));
ms_test_check('A5. every character comes from the documented alphabet', $alphabetOnly);

// =====================================================================
ms_test_section('B. voucherCreate()');
// =====================================================================

// --- the two required keys ------------------------------------------------

$r = voucherCreate($pdo, ['max_uses' => 1], $admin);
ms_test_same('B1. a missing duration_days is refused (lifetime is never chosen by accident)',
    ['ok' => false, 'error' => 'duration_days is required'], $r);

$r = voucherCreate($pdo, ['duration_days' => 30], $admin);
ms_test_same('B2. a missing max_uses is refused', ['ok' => false, 'error' => 'max_uses is required'], $r);

// --- duration bounds ------------------------------------------------------

$badDurations = [0, -1, 3651, 99999, '30', 30.0, true];
$durationOk = true;
foreach ($badDurations as $bad) {
    $res = voucherCreate($pdo, ['duration_days' => $bad, 'max_uses' => 1], $admin);
    if (($res['ok'] ?? true) !== false) {
        $durationOk = false;
    }
}
ms_test_check('B3. duration_days outside 1-3650 (and non-integers) is refused', $durationOk);

foreach ([1, 3650] as $edge) {
    $res = voucherCreate($pdo, ['duration_days' => $edge, 'max_uses' => 1], $admin);
    ms_test_check("B4. duration_days = {$edge} (the bound itself) is accepted", ($res['ok'] ?? false) === true);
}

$r = voucherCreate($pdo, ['duration_days' => null, 'max_uses' => 1], $admin);
ms_test_same('B5. an explicit null duration_days means lifetime', true, $r['ok'] ?? null);
ms_test_same('B6. ... and the row has no duration', null, $r['voucher']['duration_days']);
$lifetimeVoucherId = (int) $r['voucher']['id'];

// --- max_uses bounds ------------------------------------------------------

$badMaxUses = [0, -3, 10001, '2', 2.0, null];
$maxUsesOk = true;
foreach ($badMaxUses as $bad) {
    $res = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => $bad], $admin);
    if (($res['ok'] ?? true) !== false) {
        $maxUsesOk = false;
    }
}
ms_test_check('B7. max_uses outside 1-10000 (and non-integers, and null) is refused', $maxUsesOk);

foreach ([1, 10000] as $edge) {
    $res = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => $edge], $admin);
    ms_test_check("B8. max_uses = {$edge} (the bound itself) is accepted", ($res['ok'] ?? false) === true);
}

// --- expires_at -----------------------------------------------------------

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'expires_at' => date('Y-m-d H:i:s', time() - 3600)], $admin);
ms_test_check('B9. a past expires_at is refused', ($r['ok'] ?? true) === false);

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'expires_at' => 'next tuesday'], $admin);
ms_test_check('B10. a malformed expires_at is refused', ($r['ok'] ?? true) === false);

$future = date('Y-m-d H:i:s', time() + 86400);
$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'expires_at' => $future], $admin);
ms_test_same('B11. a future expires_at is accepted and stored', [$future], [$r['voucher']['expires_at'] ?? null]);

// --- custom code ----------------------------------------------------------

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'code' => 'MY-custom_Code1'], $admin);
ms_test_same('B12. a custom code is stored exactly as given', 'MY-custom_Code1', $r['voucher']['code'] ?? null);

foreach (['has space', 'has!bang', str_repeat('A', 65), ''] as $badCode) {
    $res = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'code' => $badCode], $admin);
    if ($badCode === '') {
        ms_test_check('B13. an empty code means "generate one"', ($res['ok'] ?? false) === true && $res['voucher']['code'] !== '');
        continue;
    }
    ms_test_check('B14. a custom code outside the pattern is refused: ' . $badCode, ($res['ok'] ?? true) === false);
}

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'code' => 'MY-custom_Code1'], $admin);
ms_test_same('B15. a taken custom code is refused with the documented message',
    ['ok' => false, 'error' => 'That code already exists'], $r);

// --- note -----------------------------------------------------------------

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'note' => "  Hello\x00\x01 world  "], $admin);
ms_test_same('B16. a note is trimmed and has its control characters removed', 'Hello world', $r['voucher']['note'] ?? null);

$longNote = str_repeat('n', 300);
$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'note' => $longNote], $admin);
ms_test_same('B17. an over-long note is cut to 255 characters', 255, strlen((string) ($r['voucher']['note'] ?? '')));

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'note' => ['not' => 'a string']], $admin);
ms_test_check('B18. a non-string note is refused', ($r['ok'] ?? true) === false);

// --- actor ----------------------------------------------------------------

foreach ([
    'no type' => ['id' => 1],
    'unknown type' => ['type' => 'seller', 'id' => 1],
    'no id' => ['type' => 'admin'],
    'id zero' => ['type' => 'admin', 'id' => 0],
    'negative id' => ['type' => 'admin', 'id' => -4],
    'id as a string' => ['type' => 'admin', 'id' => '1'],
    'id as a float' => ['type' => 'admin', 'id' => 1.0],
] as $label => $badActor) {
    $res = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1], $badActor);
    ms_test_check("B19. an invalid actor is refused ({$label})", ($res['ok'] ?? true) === false);
}

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 4], ['type' => 'admin', 'id' => 42]);
$row = voucherTestRow($pdo, (int) $r['voucher']['id']);
ms_test_same('B20. an admin actor is stored as source = admin with created_by_user_id',
    ['admin', 42, null], [$row['source'], (int) $row['created_by_user_id'], $row['issuer_id']]);
ms_test_same('B21. a new voucher is active and unused, with created_at set',
    [1, 0, true], [(int) $row['is_active'], (int) $row['current_uses'], $row['created_at'] !== null]);
ms_test_same('B22. the returned row carries the code', true, ($r['voucher']['code'] ?? '') !== '');
ms_test_same('B23. the returned is_active is a boolean', true, $r['voucher']['is_active']);

// --- external_ref ---------------------------------------------------------

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => 'admin-ref'], $admin);
ms_test_check('B24. external_ref is refused for an admin actor', ($r['ok'] ?? true) === false);

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => 'order-1001'], $issuer);
ms_test_same('B25. an issuer voucher is accepted', true, $r['ok'] ?? null);
$issuerRow = voucherTestRow($pdo, (int) $r['voucher']['id']);
ms_test_same('B26. an issuer actor is stored as source = issuer with issuer_id',
    ['issuer', null, 7], [$issuerRow['source'], $issuerRow['created_by_user_id'], (int) $issuerRow['issuer_id']]);
ms_test_same('B27. it carries the external_ref', 'order-1001', $issuerRow['external_ref']);

$again = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => 'order-1001'], $issuer);
ms_test_same('B28. the same (issuer_id, external_ref) returns the same voucher', (int) $r['voucher']['id'], (int) ($again['voucher']['id'] ?? 0));
ms_test_same('B29. ... flagged existing, not created again', true, $again['existing'] ?? null);
ms_test_same('B30. ... and only one row exists for that ref', 1,
    (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE issuer_id = 7 AND external_ref = 'order-1001'")->fetchColumn());

$otherIssuer = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => 'order-1001'], ['type' => 'issuer', 'id' => 8]);
ms_test_check('B31. the same external_ref under another issuer is a different voucher',
    ($otherIssuer['ok'] ?? false) === true && ($otherIssuer['existing'] ?? false) !== true);

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => str_repeat('x', 129)], $issuer);
ms_test_check('B32. an over-long external_ref is refused', ($r['ok'] ?? true) === false);

$r = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => "line\nbreak"], $issuer);
ms_test_check('B33. a non-printable external_ref is refused', ($r['ok'] ?? true) === false);

// --- logging --------------------------------------------------------------

$createdLines = voucherTestLogs('Voucher created');
ms_test_check('B34. creating logs one INFO line per voucher', count($createdLines) > 0);
$lastCreated = end($createdLines);
ms_test_same('B35. the line carries voucher_id, source, actor_id, duration_days and max_uses',
    ['INFO', true, true],
    [
        $lastCreated['level'],
        isset($lastCreated['context']['voucher_id'], $lastCreated['context']['source'], $lastCreated['context']['actor_id'], $lastCreated['context']['max_uses']),
        array_key_exists('duration_days', $lastCreated['context']),
    ]);

// =====================================================================
ms_test_section('C. voucherList()');
// =====================================================================

$marker = str_repeat('C', 8);
$first = voucherCreate($pdo, ['duration_days' => 10, 'max_uses' => 5, 'note' => $marker . '-one'], $admin);
$second = voucherCreate($pdo, ['duration_days' => null, 'max_uses' => 1, 'note' => $marker . '-two'], $admin);
$firstId = (int) $first['voucher']['id'];
$secondId = (int) $second['voucher']['id'];

$list = voucherList($pdo, [], 500);
ms_test_same('C1. listing succeeds', true, $list['ok'] ?? null);
ms_test_check('C2. newest first (the second create is above the first)',
    array_search($second['voucher']['code'], voucherTestCodes($list), true) < array_search($first['voucher']['code'], voucherTestCodes($list), true));
$row = null;
foreach ($list['vouchers'] as $candidate) {
    if ($candidate['code'] === $second['voucher']['code']) {
        $row = $candidate;
    }
}
ms_test_same('C3. a listed row carries the documented fields', true,
    is_array($row) && array_keys($row) === ['id', 'code', 'is_active', 'current_uses', 'max_uses', 'duration_days', 'expires_at', 'source', 'note', 'created_at', 'batch_id']);

// status = inactive
voucherSetActive($pdo, $firstId, false, $admin);
$active = voucherTestCodes(voucherList($pdo, ['status' => 'active'], 500));
$inactive = voucherTestCodes(voucherList($pdo, ['status' => 'inactive'], 500));
ms_test_check('C4. a deactivated voucher leaves status=active', !in_array($first['voucher']['code'], $active, true));
ms_test_check('C5. ... and enters status=inactive', in_array($first['voucher']['code'], $inactive, true));

// status = expired
$pdo->prepare('UPDATE vouchers SET expires_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $firstId]);
$expired = voucherTestCodes(voucherList($pdo, ['status' => 'expired'], 500));
ms_test_check('C6. a voucher past expires_at is in status=expired', in_array($first['voucher']['code'], $expired, true));

// status = used_up
$pdo->prepare('UPDATE vouchers SET current_uses = max_uses WHERE id = ?')->execute([$secondId]);
$usedUp = voucherTestCodes(voucherList($pdo, ['status' => 'used_up'], 500));
ms_test_check('C7. a fully redeemed voucher is in status=used_up', in_array($second['voucher']['code'], $usedUp, true));
$active = voucherTestCodes(voucherList($pdo, ['status' => 'active'], 500));
ms_test_check('C8. ... and not in status=active', !in_array($second['voucher']['code'], $active, true));

// source and batch_id
$issuerCodes = voucherTestCodes(voucherList($pdo, ['source' => 'issuer'], 500));
ms_test_check('C9. source filters', in_array($issuerRow['code'], $issuerCodes, true) && !in_array($first['voucher']['code'], $issuerCodes, true));
$pdo->prepare("UPDATE vouchers SET batch_id = 'batch-1' WHERE id = ?")->execute([$secondId]);
$batchCodes = voucherTestCodes(voucherList($pdo, ['batch_id' => 'batch-1'], 500));
ms_test_same('C10. batch_id filters', [$second['voucher']['code']], $batchCodes);

$r = voucherList($pdo, ['status' => 'nonsense']);
ms_test_check('C11. an unknown status is refused rather than ignored', ($r['ok'] ?? true) === false);

$limited = voucherList($pdo, [], 1, 0);
$offset1 = voucherList($pdo, [], 1, 1);
ms_test_same('C12. limit is honoured', 1, count($limited['vouchers'] ?? []));
ms_test_check('C13. offset skips the first row', ($offset1['vouchers'][0]['code'] ?? null) !== ($limited['vouchers'][0]['code'] ?? null));

// =====================================================================
ms_test_section('D. voucherSetActive()');
// =====================================================================

$adminVoucher = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1], $admin);
$issuerVoucher = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1, 'external_ref' => 'set-active'], $issuer);
$adminVoucherId = (int) $adminVoucher['voucher']['id'];
$issuerVoucherId = (int) $issuerVoucher['voucher']['id'];

$r = voucherSetActive($pdo, $adminVoucherId, false, $admin);
ms_test_same('D1. an admin deactivates any voucher', [true, false], [$r['ok'] ?? null, $r['voucher']['is_active'] ?? null]);
ms_test_same('D2. ... and the row agrees', 0, (int) voucherTestRow($pdo, $adminVoucherId)['is_active']);

$r = voucherSetActive($pdo, $adminVoucherId, true, $admin);
ms_test_same('D3. an admin reactivates it', [true, true], [$r['ok'] ?? null, $r['voucher']['is_active'] ?? null]);

$r = voucherSetActive($pdo, $issuerVoucherId, false, $issuer);
ms_test_same('D4. an issuer deactivates its own voucher', true, $r['ok'] ?? null);

$r = voucherSetActive($pdo, $adminVoucherId, false, $issuer);
ms_test_same('D5. an issuer may not touch an admin\'s voucher', ['ok' => false, 'error' => 'Voucher not found'], $r);
ms_test_same('D6. ... which is left alone', 1, (int) voucherTestRow($pdo, $adminVoucherId)['is_active']);

$r = voucherSetActive($pdo, 999999, false, $admin);
ms_test_same('D7. a missing id answers "Voucher not found"', ['ok' => false, 'error' => 'Voucher not found'], $r);
$r = voucherSetActive($pdo, 999999, false, $issuer);
ms_test_same('D8. ... the same for an issuer (no enumeration)', ['ok' => false, 'error' => 'Voucher not found'], $r);
$r = voucherSetActive($pdo, 0, false, $admin);
ms_test_same('D9. id 0 is refused before any query', ['ok' => false, 'error' => 'Voucher not found'], $r);
$r = voucherSetActive($pdo, $issuerVoucherId, false, ['type' => 'nobody', 'id' => 1]);
ms_test_check('D10. an invalid actor is refused', ($r['ok'] ?? true) === false);

$r = voucherSetActive($pdo, $issuerVoucherId, true, $admin);
ms_test_same('D11. an admin may reactivate an issuer\'s voucher', true, $r['ok'] ?? null);

$deactivated = voucherTestLogs('Voucher deactivated');
ms_test_check('D12. deactivation is logged as INFO "Voucher deactivated"', count($deactivated) > 0 && $deactivated[0]['level'] === 'INFO');

// =====================================================================
ms_test_section('E. voucherRedemptions()');
// =====================================================================

$r = voucherRedemptions($pdo, (int) $adminVoucher['voucher']['id']);
ms_test_same('E1. a never-redeemed voucher has no redemptions', ['ok' => true, 'redemptions' => []], $r);

$r = voucherRedemptions($pdo, 0);
ms_test_same('E2. id 0 answers an empty list', ['ok' => true, 'redemptions' => []], $r);

// =====================================================================
ms_test_section('F. the real redeemVoucherForEmail()');
// =====================================================================

$now = time();
$soon = date('Y-m-d H:i:s', $now + 10 * 86400);
$aliceId = voucherTestSeedUser($pdo, 'alice@example.com', 'pro', $soon, $encKey, $idxKey);
$bobId = voucherTestSeedUser($pdo, 'bob@example.com', 'regular', null, $encKey, $idxKey);
$daveId = voucherTestSeedUser($pdo, 'dave@example.com', 'regular', null, $encKey, $idxKey);
$erinId = voucherTestSeedUser($pdo, 'erin@example.com', 'pro', date('Y-m-d H:i:s', $now + 5 * 86400), $encKey, $idxKey);
$frankId = voucherTestSeedUser($pdo, 'frank@example.com', 'pro', null, $encKey, $idxKey);
$graceId = voucherTestSeedUser($pdo, 'grace@example.com', 'regular', null, $encKey, $idxKey);

// --- a voucher the service created is redeemable --------------------------

$v1 = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 2, 'note' => 'F1'], $admin);
$v1Id = (int) $v1['voucher']['id'];
$v1Code = (string) $v1['voucher']['code'];

$res = voucherTestRedeem($probe, $db, 'alice@example.com', $v1Code, $piiEnv);
ms_test_same('F1. a voucher created by the service redeems for a time-limited account', true, $res['success'] ?? null);
$alice = voucherTestUserRow($pdo, 'alice@example.com');
$stackedDays = round((strtotime((string) $alice['pro_expires_at']) - $now) / 86400);
ms_test_check('F2. ... and its 30 days stack on the 10 the account already had', abs($stackedDays - 40) <= 1);
ms_test_same('F3. ... current_uses is 1 of 2', 1, (int) voucherTestRow($pdo, $v1Id)['current_uses']);

$res = voucherTestRedeem($probe, $db, 'bob@example.com', $v1Code, $piiEnv);
ms_test_same('F4. a second, different account may redeem it (max_uses = 2)', true, $res['success'] ?? null);
ms_test_same('F5. ... and it is now fully redeemed', 2, (int) voucherTestRow($pdo, $v1Id)['current_uses']);
ms_test_check('F6. ... a regular account that redeems becomes Pro', voucherTestUserRow($pdo, 'bob@example.com')['account_type'] === 'pro');
$bobDays = round((strtotime((string) voucherTestUserRow($pdo, 'bob@example.com')['pro_expires_at']) - $now) / 86400);
ms_test_check('F7. ... from now, not stacked on anything (it had no Pro time)', abs($bobDays - 30) <= 1);

$res = voucherTestRedeem($probe, $db, 'carol@example.com', $v1Code, $piiEnv);
ms_test_same('F8. a third account is refused: the code is fully redeemed', 'code_fully_redeemed', $res['error_code'] ?? null);
ms_test_same('F9. ... current_uses stays at 2', 2, (int) voucherTestRow($pdo, $v1Id)['current_uses']);
ms_test_same('F10. ... and no account was created for the third address', null, voucherTestUserRow($pdo, 'carol@example.com'));

// --- the same account cannot redeem twice ---------------------------------

$v2 = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 2], $admin);
$v2Id = (int) $v2['voucher']['id'];
$v2Code = (string) $v2['voucher']['code'];

$res = voucherTestRedeem($probe, $db, 'dave@example.com', $v2Code, $piiEnv);
ms_test_same('F11. the first redemption succeeds', true, $res['success'] ?? null);
$res = voucherTestRedeem($probe, $db, 'dave@example.com', $v2Code, $piiEnv);
ms_test_same('F12. the same account redeeming again is refused (already_redeemed, not fully_redeemed)', 'already_redeemed', $res['error_code'] ?? null);
ms_test_same('F13. ... and the use count is unchanged', 1, (int) voucherTestRow($pdo, $v2Id)['current_uses']);

// --- a lifetime voucher ---------------------------------------------------

$v3 = voucherCreate($pdo, ['duration_days' => null, 'max_uses' => 1], $admin);
$v3Id = (int) $v3['voucher']['id'];
$res = voucherTestRedeem($probe, $db, 'erin@example.com', (string) $v3['voucher']['code'], $piiEnv);
ms_test_same('F14. a lifetime voucher redeems', true, $res['success'] ?? null);
ms_test_same('F15. ... and sets pro_expires_at to NULL', null, voucherTestUserRow($pdo, 'erin@example.com')['pro_expires_at']);
ms_test_same('F16. ... recorded as one use', 1, (int) voucherTestRow($pdo, $v3Id)['current_uses']);

// --- the lifetime fix -----------------------------------------------------

$v4 = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1], $admin);
$v4Id = (int) $v4['voucher']['id'];
$res = voucherTestRedeem($probe, $db, 'frank@example.com', (string) $v4['voucher']['code'], $piiEnv);
ms_test_same('F17. a timed voucher on a lifetime-Pro account is refused', 'already_lifetime', $res['error_code'] ?? null);
ms_test_same('F18. ... and the code is not used up', 0, (int) voucherTestRow($pdo, $v4Id)['current_uses']);
ms_test_same('F19. ... and the account keeps lifetime Pro', null, voucherTestUserRow($pdo, 'frank@example.com')['pro_expires_at']);
ms_test_same('F20. ... nothing was logged to redemption_log either', 0,
    (int) $pdo->query('SELECT COUNT(*) FROM redemption_log WHERE voucher_id = ' . $v4Id)->fetchColumn());

$v5 = voucherCreate($pdo, ['duration_days' => null, 'max_uses' => 1], $admin);
$res = voucherTestRedeem($probe, $db, 'frank@example.com', (string) $v5['voucher']['code'], $piiEnv);
ms_test_same('F21. a lifetime voucher on a lifetime account keeps the existing behaviour', true, $res['success'] ?? null);
ms_test_same('F22. ... pro_expires_at is still NULL', null, voucherTestUserRow($pdo, 'frank@example.com')['pro_expires_at']);

$res = voucherTestRedeem($probe, $db, 'grace@example.com', (string) $v4['voucher']['code'], $piiEnv);
ms_test_same('F23. the refused code still works for a regular account with no Pro at all', true, $res['success'] ?? null);
$grace = voucherTestUserRow($pdo, 'grace@example.com');
ms_test_check('F24. ... which is upgraded rather than treated as lifetime',
    $grace['account_type'] === 'pro' && $grace['pro_expires_at'] !== null
    && abs(round((strtotime((string) $grace['pro_expires_at']) - $now) / 86400) - 30) <= 1);

// --- a brand-new account is created by the redemption ---------------------

$v6 = voucherCreate($pdo, ['duration_days' => 30, 'max_uses' => 1], $admin);
$v6Id = (int) $v6['voucher']['id'];
$res = voucherTestRedeem($probe, $db, 'newbie@example.com', (string) $v6['voucher']['code'], $piiEnv);
ms_test_same('F29. a voucher redeems for an address with no account at all', true, $res['success'] ?? null);
$newbie = voucherTestUserRow($pdo, 'newbie@example.com');
ms_test_check('F30. ... the account is created, Pro, with the address pair written',
    $newbie !== null && $newbie['account_type'] === 'pro'
    && $newbie['email_hash'] === piiEmailHash('newbie@example.com', $idxKey)
    && piiEmailDecrypt((string) $newbie['email_enc'], $encKey) === 'newbie@example.com');
ms_test_check('F31. ... with thirty days of Pro', abs(round((strtotime((string) $newbie['pro_expires_at']) - $now) / 86400) - 30) <= 1);
ms_test_same('F32. ... and the voucher is used up', 1, (int) voucherTestRow($pdo, $v6Id)['current_uses']);

// --- what the redemptions view shows --------------------------------------

$r = voucherRedemptions($pdo, $v1Id);
ms_test_same('F25. two redemptions are listed', 2, count($r['redemptions'] ?? []));
ms_test_same('F26. each row is exactly user_id + redeemed_at (never an address)',
    ['user_id', 'redeemed_at'], array_keys($r['redemptions'][0]));
ms_test_check('F27. ... and the account ids are the redeemers',
    in_array($aliceId, array_column($r['redemptions'], 'user_id'), true)
    && in_array($bobId, array_column($r['redemptions'], 'user_id'), true));
ms_test_check('F28. ... with a timestamp', ($r['redemptions'][0]['redeemed_at'] ?? null) !== null);

// =====================================================================
ms_test_section('G. repository scans');
// =====================================================================

/** Every non-test PHP file in the repo, keyed by its repo-relative path. */
function voucherTestPhpSources(string $repoRoot): array
{
    $sources = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $rel = substr($path, strlen($repoRoot) + 1);
        if (substr($path, -4) !== '.php' || preg_match('#^(vendor|tests|\.claude|\.git)/#', $rel)) {
            continue;
        }
        $sources[$rel] = (string) file_get_contents($path);
    }
    return $sources;
}

/** How many INSERT INTO vouchers string literals a PHP source contains. */
function voucherTestInsertLiterals(string $php): int
{
    $count = 0;
    foreach (token_get_all($php) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/INSERT\s+INTO\s+vouchers\b/i', $token[1]) === 1) {
            $count++;
        }
    }
    return $count;
}

/**
 * Every logMessage() call in a PHP source: how many there are, and the
 * argument text of the ones that pass a 'code' key. Token-based, so a
 * parenthesis inside a message string cannot throw the scan off.
 *
 * @return array{calls:int, offending:list<string>}
 */
function voucherTestLogCalls(string $php): array
{
    $tokens = token_get_all($php);
    $count = count($tokens);
    $calls = 0;
    $found = [];
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'logmessage') {
            continue;
        }
        $calls++;
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }
        $depth = 0;
        $args = '';
        for (; $j < $count; $j++) {
            $current = $tokens[$j];
            $text = is_array($current) ? $current[1] : $current;
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if (is_array($current) && $current[0] === T_COMMENT) {
                continue;
            }
            $args .= $text;
        }
        if (preg_match('/[\'"]code[\'"]\s*=>/', $args) === 1) {
            $found[] = trim((string) preg_replace('/\s+/', ' ', substr($args, 0, 160)));
        }
    }
    return ['calls' => $calls, 'offending' => $found];
}

$sources = voucherTestPhpSources($repoRoot);

// tests/ is excluded (this suite and tests/pii_crypto_test.php both seed
// vouchers themselves); every deployed file is not.
$inserters = [];
foreach ($sources as $rel => $source) {
    $n = voucherTestInsertLiterals($source);
    if ($n > 0) {
        $inserters[$rel] = $n;
    }
}
ms_test_same('G1. no deployed PHP file inserts into vouchers except voucher_service.php',
    ['voucher_service.php' => 1], $inserters);
ms_test_same('G2. the insert scan does catch a planted INSERT (self-test)', 1,
    voucherTestInsertLiterals("<?php \$pdo->prepare(\"INSERT INTO vouchers (code) VALUES ('X')\");"));
ms_test_same('G3. ... and does not fire on a read', 0,
    voucherTestInsertLiterals('<?php $pdo->prepare("SELECT code FROM vouchers WHERE id = ?");'));

$codeKeyCalls = [];
$scannedCalls = [];
foreach (['voucher_service.php', 'pro_auth.php'] as $file) {
    $scan = voucherTestLogCalls($sources[$file] ?? '');
    foreach ($scan['offending'] as $offending) {
        $codeKeyCalls[] = $file . ': ' . $offending;
    }
    $scannedCalls[$file] = $scan['calls'];
}
ms_test_same('G4. no logMessage() in voucher_service.php / pro_auth.php passes a code key', [], $codeKeyCalls);
ms_test_same('G5. the code-key scan does catch a planted call (self-test)',
    ["('INFO', 'x', ['code' => \$code]"], voucherTestLogCalls("<?php logMessage('INFO', 'x', ['code' => \$code]);")['offending']);
ms_test_check('G6. the scan really walks the log calls it clears (voucher_service.php and pro_auth.php both have many)',
    $scannedCalls['voucher_service.php'] > 0 && $scannedCalls['pro_auth.php'] > 30,
    'voucher_service.php: ' . $scannedCalls['voucher_service.php'] . ', pro_auth.php: ' . $scannedCalls['pro_auth.php']);

// =====================================================================
ms_test_section('H. voucherCreateBatch()');
// =====================================================================

/** A PDO that fails the Nth INSERT into vouchers, to prove a batch rolls back. */
final class VoucherFailMidBatchPdo extends PDO
{
    public int $failAfter = 0;
    private int $inserts = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failAfter > 0 && stripos($query, 'INSERT INTO vouchers') !== false) {
            $this->inserts++;
            if ($this->inserts > $this->failAfter) {
                throw new PDOException('forced mid-batch failure');
            }
        }
        return parent::prepare($query, $options);
    }
}

/** A PDO that refuses every query, to prove a malformed batch id reaches none. */
final class VoucherNoQueryPdo extends PDO
{
    public int $prepared = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared++;
        throw new RuntimeException('no query expected');
    }
}

/** CSV text as rows, header first, parsed the way a spreadsheet would. */
function voucherTestCsvRows(string $csv): array
{
    $rows = [];
    foreach (explode("\n", trim($csv)) as $line) {
        if ($line === '') {
            continue;
        }
        $rows[] = str_getcsv($line, ',', '"', '');
    }
    return $rows;
}

$voucherCount = static fn(PDO $conn, string $where = ''): int =>
    (int) $conn->query('SELECT COUNT(*) FROM vouchers' . ($where === '' ? '' : ' WHERE ' . $where))->fetchColumn();
$createdLogsBefore = count(voucherTestLogs('Voucher created'));

// --- the count bounds -----------------------------------------------------

$b1 = voucherCreateBatch($pdo, ['duration_days' => 30], 1, $admin);
ms_test_same('H1. a batch of one is accepted', true, $b1['ok'] ?? null);
ms_test_check('H2. ... with a 16-character lowercase hex batch_id',
    preg_match('/^[a-f0-9]{16}$/', (string) ($b1['batch_id'] ?? '')) === 1, (string) ($b1['batch_id'] ?? ''));
ms_test_same('H3. ... and one voucher', 1, count($b1['vouchers'] ?? []));
$b1Row = voucherTestRow($pdo, (int) $b1['vouchers'][0]['id']);
ms_test_same('H4. the row carries that batch_id, max_uses 1 and source admin',
    [$b1['batch_id'], 1, 'admin'], [$b1Row['batch_id'], (int) $b1Row['max_uses'], $b1Row['source']]);
ms_test_same('H5. ... and is active and unused', [1, 0], [(int) $b1Row['is_active'], (int) $b1Row['current_uses']]);
ms_test_same('H6. the voucher it returns is the row it stored', true, $b1['vouchers'][0]['code'] === $b1Row['code']);

$b500 = voucherCreateBatch($pdo, ['duration_days' => 7], 500, $admin);
ms_test_same('H7. a batch of 500 is accepted', true, $b500['ok'] ?? null);
ms_test_same('H8. ... with 500 vouchers', 500, count($b500['vouchers'] ?? []));
ms_test_same('H9. ... every one single-use', 500,
    count(array_filter($b500['vouchers'], static fn(array $v): bool => $v['max_uses'] === 1)));
ms_test_same('H10. ... all 500 codes distinct', 500,
    count(array_unique(array_map(static fn(array $v): string => (string) $v['code'], $b500['vouchers']))));
ms_test_same('H11. ... and all under the one batch_id', 1, count(array_unique(array_column($b500['vouchers'], 'batch_id'))));

$beforeRefused = $voucherCount($pdo);
foreach ([0, 501, -1] as $badCount) {
    ms_test_same("H12. count = {$badCount} is refused",
        ['ok' => false, 'error' => 'count must be an integer between 1 and 500'],
        voucherCreateBatch($pdo, ['duration_days' => 30], $badCount, $admin));
}
ms_test_same('H13. ... and none of them created a row', $beforeRefused, $voucherCount($pdo));

// --- the refused keys and the shared validation ---------------------------

ms_test_same('H14. a code in a batch spec is refused',
    ['ok' => false, 'error' => 'code is not allowed in a batch'],
    voucherCreateBatch($pdo, ['duration_days' => 30, 'code' => 'X'], 2, $admin));
ms_test_same('H15. an external_ref is refused for an admin',
    ['ok' => false, 'error' => 'external_ref is not allowed in a batch'],
    voucherCreateBatch($pdo, ['duration_days' => 30, 'external_ref' => 'ref-1'], 2, $admin));
ms_test_same('H16. ... and for an issuer too',
    ['ok' => false, 'error' => 'external_ref is not allowed in a batch'],
    voucherCreateBatch($pdo, ['duration_days' => 30, 'external_ref' => 'ref-1'], 2, $issuer));

ms_test_same('H17. a missing duration_days is refused by the shared rule',
    ['ok' => false, 'error' => 'duration_days is required'], voucherCreateBatch($pdo, [], 2, $admin));
ms_test_check('H18. a duration outside 1-3650 is refused',
    (voucherCreateBatch($pdo, ['duration_days' => 0], 2, $admin)['ok'] ?? true) === false);
ms_test_check('H19. an invalid actor is refused',
    (voucherCreateBatch($pdo, ['duration_days' => 30], 2, ['type' => 'nobody', 'id' => 1])['ok'] ?? true) === false);

// --- a forced max_uses ----------------------------------------------------

$bMax = voucherCreateBatch($pdo, ['duration_days' => 30, 'max_uses' => 5], 2, $admin);
ms_test_same('H20. a max_uses in the spec is ignored — a batch is single-use',
    [1, 1], array_map(static fn(array $v): int => (int) $v['max_uses'], $bMax['vouchers']));

// --- a lifetime batch -----------------------------------------------------

$bLife = voucherCreateBatch($pdo, ['duration_days' => null], 2, $admin);
ms_test_same('H21. a lifetime batch is accepted', true, $bLife['ok'] ?? null);
ms_test_same('H22. ... and every code is lifetime (no duration)',
    [true, true], array_map(static fn(array $v): bool => $v['duration_days'] === null, $bLife['vouchers']));

// --- an issuer batch ------------------------------------------------------

$bIssuer = voucherCreateBatch($pdo, ['duration_days' => 30], 2, $issuer);
$bIssuerRow = voucherTestRow($pdo, (int) $bIssuer['vouchers'][0]['id']);
ms_test_same('H23. an issuer batch is stored as source issuer with issuer_id',
    ['issuer', 7], [$bIssuerRow['source'], (int) $bIssuerRow['issuer_id']]);

// --- one transaction: a failure part-way leaves no rows -------------------

$beforeFail = $voucherCount($pdo);
$batchedBefore = $voucherCount($pdo, 'batch_id IS NOT NULL');
$failPdo = new VoucherFailMidBatchPdo('sqlite:' . $db, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$failPdo->exec('PRAGMA busy_timeout = 5000');
$failPdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$failPdo->failAfter = 5;
$failedBatch = voucherCreateBatch($failPdo, ['duration_days' => 30], 20, $admin);
ms_test_check('H24. a failure part-way through a batch is reported as a failure', ($failedBatch['ok'] ?? true) === false);
ms_test_same('H25. ... and leaves no rows at all (no half batch)', $beforeFail, $voucherCount($pdo));
ms_test_same('H26. ... and no new batch row exists', $batchedBefore, $voucherCount($pdo, 'batch_id IS NOT NULL'));

// --- logging: one line per batch, never per code --------------------------

$batchLogs = voucherTestLogs('Voucher batch created');
ms_test_check('H27. creating a batch logs INFO "Voucher batch created"',
    $batchLogs !== [] && $batchLogs[0]['level'] === 'INFO' && count($batchLogs) === 5);
ms_test_same('H28. ... carrying batch_id, count, duration_days, source and actor_id',
    [true, true, true],
    [
        preg_match('/^[a-f0-9]{16}$/', (string) $batchLogs[0]['context']['batch_id']) === 1,
        $batchLogs[0]['context']['count'] === 1 && $batchLogs[0]['context']['duration_days'] === 30,
        $batchLogs[0]['context']['source'] === 'admin' && $batchLogs[0]['context']['actor_id'] === 1,
    ]);
ms_test_check('H29. ... and never a code',
    strpos((string) json_encode($batchLogs[0]['context']), 'MS-') === false);
ms_test_same('H30. a batch writes no per-code "Voucher created" line',
    $createdLogsBefore, count(voucherTestLogs('Voucher created')));

// --- a batch code is redeemable exactly once ------------------------------

voucherTestSeedUser($pdo, 'batch-one@example.com', 'regular', null, $encKey, $idxKey);
voucherTestSeedUser($pdo, 'batch-two@example.com', 'regular', null, $encKey, $idxKey);
$bRedeem = voucherCreateBatch($pdo, ['duration_days' => 30], 1, $admin);
$bRedeemId = (int) $bRedeem['vouchers'][0]['id'];
$bRedeemCode = (string) $bRedeem['vouchers'][0]['code'];

$res = voucherTestRedeem($probe, $db, 'batch-one@example.com', $bRedeemCode, $piiEnv);
ms_test_same('H31. a batch code redeems for the first account', true, $res['success'] ?? null);
$res = voucherTestRedeem($probe, $db, 'batch-two@example.com', $bRedeemCode, $piiEnv);
ms_test_same('H32. ... and is then refused for a second account', 'code_fully_redeemed', $res['error_code'] ?? null);
ms_test_same('H33. ... the row records the one use', 1, (int) voucherTestRow($pdo, $bRedeemId)['current_uses']);

// =====================================================================
ms_test_section('I. voucherBatchCsv()');
// =====================================================================

$contentBatch = 'aa11bb22cc33dd44';
$formulaBatch = '00112233445566aa';
$issuerBatch = 'ffeeddccbbaa9988';
$csvExpiry = date('Y-m-d H:i:s', time() + 5 * 86400);

$insertVoucher = $pdo->prepare(
    'INSERT INTO vouchers (code, is_active, expires_at, max_uses, current_uses, duration_days, created_at, source, created_by_user_id, issuer_id, external_ref, batch_id, note)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$insertVoucher->execute(['MS-CONT-ENT1-AAAA', 1, null, 1, 0, 30, '2026-01-01 00:00:00', 'admin', 1, null, null, $contentBatch, 'internal note one']);
$insertVoucher->execute(['MS-CONT-ENT2-BBBB', 1, null, 1, 0, null, '2026-01-02 00:00:00', 'admin', 1, null, null, $contentBatch, null]);
$insertVoucher->execute(['MS-CONT-ENT3-CCCC', 1, null, 1, 1, 30, '2026-01-03 00:00:00', 'admin', 1, null, null, $contentBatch, null]);
$insertVoucher->execute(['MS-CONT-ENT4-DDDD', 1, $csvExpiry, 1, 0, 30, '2026-01-04 00:00:00', 'admin', 1, null, null, $contentBatch, null]);

$csv = voucherBatchCsv($pdo, $contentBatch, $admin);
ms_test_check('I1. a batch exports a string', is_string($csv) && $csv !== '');
$csvRows = voucherTestCsvRows((string) $csv);
ms_test_same('I2. the header row is exactly as documented',
    ['code', 'pro_time', 'redeemable_until', 'status', 'redeemed'], $csvRows[0]);
ms_test_same('I3. ... with one row per code', 5, count($csvRows));
ms_test_same('I4. the first code is there with its days of Pro',
    ['MS-CONT-ENT1-AAAA', '30 days'], [$csvRows[1][0], $csvRows[1][1]]);
ms_test_same('I5. a lifetime code exports Lifetime', 'Lifetime', $csvRows[2][1]);
ms_test_same('I6. an empty redeemable_until is an empty cell', '', $csvRows[1][2]);
ms_test_same('I7. a set redeemable_until is exported as stored', $csvExpiry, $csvRows[4][2]);
ms_test_same('I8. an unused, live code reads Active / no', ['Active', 'no'], [$csvRows[1][3], $csvRows[1][4]]);
ms_test_same('I9. a fully redeemed code reads Used up / yes', ['Used up', 'yes'], [$csvRows[3][3], $csvRows[3][4]]);
ms_test_check('I10. the note is never exported', strpos((string) $csv, 'internal note one') === false);

// --- formula injection ----------------------------------------------------

foreach (['=1+1', '+1+1', '-1+1', '@SUM(1)'] as $tricky) {
    $insertVoucher->execute([$tricky, 1, null, 1, 0, 30, '2026-01-05 00:00:00', 'admin', 1, null, null, $formulaBatch, null]);
}
$formulaRows = voucherTestCsvRows((string) voucherBatchCsv($pdo, $formulaBatch, $admin));
ms_test_same('I11. a cell starting with = is prefixed with an apostrophe', "'=1+1", $formulaRows[1][0]);
ms_test_same('I12. ... and likewise for +, - and @',
    ["'+1+1", "'-1+1", "'@SUM(1)"], [$formulaRows[2][0], $formulaRows[3][0], $formulaRows[4][0]]);

// --- scoping and refusals -------------------------------------------------

$insertVoucher->execute(['MS-ISSU-ER01-AAAA', 1, null, 1, 0, 30, '2026-01-06 00:00:00', 'issuer', null, 7, null, $issuerBatch, null]);
ms_test_check('I13. an issuer can export its own batch', is_string(voucherBatchCsv($pdo, $issuerBatch, ['type' => 'issuer', 'id' => 7])));
ms_test_same('I14. ... but not another issuer\'s (not found and not yours are the same null)',
    null, voucherBatchCsv($pdo, $issuerBatch, ['type' => 'issuer', 'id' => 8]));
ms_test_same('I15. ... and an admin can export any batch',
    true, is_string(voucherBatchCsv($pdo, $issuerBatch, $admin)));
ms_test_same('I16. an unknown batch id answers null', null, voucherBatchCsv($pdo, 'aaaaaaaaaaaaaaaa', $admin));
ms_test_same('I17. an invalid actor answers null', null, voucherBatchCsv($pdo, $contentBatch, ['type' => 'nobody', 'id' => 1]));
ms_test_same('I18. a malformed batch id answers null', null, voucherBatchCsv($pdo, 'NOT-HEX', $admin));

$noQueryPdo = new VoucherNoQueryPdo('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ms_test_same('I19. ... and reaches no query at all before refusing',
    [null, 0], [voucherBatchCsv($noQueryPdo, '../../etc/passwd', $admin), $noQueryPdo->prepared]);

// Clean up.
ms_test_rrmdir($probe);

exit(ms_test_summary());
