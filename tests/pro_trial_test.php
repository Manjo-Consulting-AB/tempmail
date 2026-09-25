<?php

declare(strict_types=1);

/**
 * Regression coverage for the pure Pro-trial helpers in pro_trial.php:
 * proTrialNormalizeEmail() and proTrialEmailHash() (epic #267, step 1/4).
 *
 * Run with:  php tests/pro_trial_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Pure functions only: no
 * database, no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../pro_trial.php';

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

// ---------------------------------------------------------------------------
// proTrialNormalizeEmail()
// ---------------------------------------------------------------------------

same('N1. trim, lowercase, drop +tag, remove dots',
    'johndoe@example.com',
    proTrialNormalizeEmail('  John.Doe+news@Example.COM '));

same('N2. dots removed from the local part', 'john@gmail.com',
    proTrialNormalizeEmail('j.o.h.n@gmail.com'));

same('N3. only the first + cuts the local part', 'john@gmail.com',
    proTrialNormalizeEmail('john+a+b@gmail.com'));

same('N4. splits on the last @ only', '"a@b"@example.com',
    proTrialNormalizeEmail('"a@b"@example.com'));

same('N5. an empty local part after the tag cut is null', null,
    proTrialNormalizeEmail('+tag@example.com'));

same('N6. an empty local part after removing dots is null', null,
    proTrialNormalizeEmail('...@example.com'));

same('N7. an empty domain is null', null, proTrialNormalizeEmail('nodomain@'));

same('N8. no @ at all is null', null, proTrialNormalizeEmail('noat'));

same('N9. an empty string is null', null, proTrialNormalizeEmail(''));

// ---------------------------------------------------------------------------
// proTrialEmailHash()
// ---------------------------------------------------------------------------

$key = str_repeat('k', 32);

same('H1. normalisation happens before hashing',
    proTrialEmailHash('John.Doe+x@example.com', $key),
    proTrialEmailHash('johndoe@EXAMPLE.com', $key));

$hash = proTrialEmailHash('john@example.com', $key);
check('H2. the hash is 64 lowercase hex characters',
    is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
    var_export($hash, true));

check('H3. a different key gives a different hash',
    proTrialEmailHash('john@example.com', str_repeat('x', 32)) !== $hash);

same('H4. a 31-character key returns null', null,
    proTrialEmailHash('john@example.com', str_repeat('k', 31)));

check('H5. a 32-character key returns a hash',
    proTrialEmailHash('john@example.com', str_repeat('k', 32)) !== null);

check('H6. the hash does not contain the normalised address',
    is_string($hash) && strpos($hash, 'john@example.com') === false);

// ---------------------------------------------------------------------------
// proTrialRecordClaim() / proTrialGrantOnVerification() (SQLite-backed)
// ---------------------------------------------------------------------------

$GLOBALS['ms_trial_test_logs'] = [];

function logMessage($level, $message, $context = null): void
{
    $GLOBALS['ms_trial_test_logs'][] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
}

function tableHasColumn($table, $column): bool
{
    global $ms_trial_test_pdo;
    try {
        $stmt = $ms_trial_test_pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
        $stmt->execute([(string) $table, (string) $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ms_trial_test_logged(string $level, string $needle): bool
{
    foreach ($GLOBALS['ms_trial_test_logs'] as $entry) {
        if ($entry['level'] === $level && str_contains($entry['message'], $needle)) {
            return true;
        }
    }
    return false;
}

function ms_trial_test_forget_logs(): void
{
    $GLOBALS['ms_trial_test_logs'] = [];
}

function ms_trial_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $pdo->exec("CREATE TABLE pro_users (
        id INTEGER PRIMARY KEY,
        email TEXT,
        account_type TEXT,
        pro_expires_at TEXT NULL,
        email_verified_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE pro_trial_claims (
        email_hash TEXT PRIMARY KEY,
        first_seen_at TEXT NOT NULL
    )");
    return $pdo;
}

function ms_trial_test_seed_user(PDO $pdo, string $email, string $accountType = 'regular', ?string $proExpiresAt = null): int
{
    $stmt = $pdo->prepare('INSERT INTO pro_users (email, account_type, pro_expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$email, $accountType, $proExpiresAt]);
    return (int) $pdo->lastInsertId();
}

function ms_trial_test_seed_claim(PDO $pdo, string $email, string $key, int $daysAgo): void
{
    $hash = proTrialEmailHash($email, $key);
    $firstSeen = date('Y-m-d H:i:s', time() - $daysAgo * 86400);
    $stmt = $pdo->prepare('INSERT INTO pro_trial_claims (email_hash, first_seen_at) VALUES (?, ?)');
    $stmt->execute([$hash, $firstSeen]);
}

$ms_trial_test_key = str_repeat('t', 32);
$ms_trial_test_trial = ['days' => 60, 'hash_key' => $ms_trial_test_key, 'claim_retention_days' => 1825];

// 1. New Regular user: trial granted.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'new@example.com');
ms_trial_test_forget_logs();
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'new@example.com', $ms_trial_test_trial);
check('T1a. trial granted for a new Regular user', $expiresAt !== null, var_export($expiresAt, true));
$row = $ms_trial_test_pdo->query("SELECT account_type, pro_expires_at FROM pro_users WHERE id = {$userId}")->fetch();
same('T1b. account_type is pro', 'pro', $row['account_type'] ?? null);
$expectedEnd = time() + 60 * 86400;
check('T1c. pro_expires_at is within 60s of now + 60 days',
    $row['pro_expires_at'] !== null && abs(strtotime($row['pro_expires_at']) - $expectedEnd) <= 60,
    (string) ($row['pro_expires_at'] ?? 'null'));
$claimCount = (int) $ms_trial_test_pdo->query('SELECT COUNT(*) FROM pro_trial_claims')->fetchColumn();
same('T1d. a single claim row exists', 1, $claimCount);

// 2. A claim seeded 20 days ago: end = first_seen_at + 60 days (~40 days left).
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'seeded20@example.com');
ms_trial_test_seed_claim($ms_trial_test_pdo, 'seeded20@example.com', $ms_trial_test_key, 20);
$claimBefore = $ms_trial_test_pdo->query('SELECT first_seen_at FROM pro_trial_claims')->fetchColumn();
ms_trial_test_forget_logs();
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'seeded20@example.com', $ms_trial_test_trial);
check('T2a. trial granted from the seeded first_seen_at', $expiresAt !== null, var_export($expiresAt, true));
$expectedEnd = strtotime($claimBefore) + 60 * 86400;
check('T2b. pro_expires_at = first_seen_at + 60 days',
    $expiresAt !== null && abs(strtotime($expiresAt) - $expectedEnd) <= 60);
$daysLeft = $expiresAt !== null ? round((strtotime($expiresAt) - time()) / 86400) : null;
check('T2c. about 40 days left', $daysLeft !== null && abs($daysLeft - 40) <= 1, var_export($daysLeft, true));
$claimAfter = $ms_trial_test_pdo->query('SELECT first_seen_at FROM pro_trial_claims')->fetchColumn();
same('T2d. first_seen_at is unchanged', $claimBefore, $claimAfter);

// 3. A claim seeded 70 days ago: no grant, account stays regular.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'seeded70@example.com');
ms_trial_test_seed_claim($ms_trial_test_pdo, 'seeded70@example.com', $ms_trial_test_key, 70);
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'seeded70@example.com', $ms_trial_test_trial);
same('T3a. no grant when the trial window has already passed', null, $expiresAt);
$row = $ms_trial_test_pdo->query("SELECT account_type, pro_expires_at FROM pro_users WHERE id = {$userId}")->fetch();
same('T3b. account stays regular', 'regular', $row['account_type'] ?? null);
same('T3c. pro_expires_at stays unset', null, $row['pro_expires_at'] ?? null);

// 4. A claim seeded 70 days ago for a.b@x.com; verification of ab+new@X.com normalises to the same claim.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'ab+new@X.com');
ms_trial_test_seed_claim($ms_trial_test_pdo, 'a.b@x.com', $ms_trial_test_key, 70);
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'ab+new@X.com', $ms_trial_test_trial);
same('T4. no trial when the normalised address already claimed 70 days ago', null, $expiresAt);

// 5. A user already pro with pro_expires_at = NULL: unchanged, claim row still created.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'alreadypro@example.com', 'pro', null);
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'alreadypro@example.com', $ms_trial_test_trial);
same('T5a. no grant for an already-Pro account', null, $expiresAt);
$row = $ms_trial_test_pdo->query("SELECT account_type, pro_expires_at FROM pro_users WHERE id = {$userId}")->fetch();
same('T5b. account_type stays pro', 'pro', $row['account_type'] ?? null);
same('T5c. pro_expires_at stays NULL', null, $row['pro_expires_at'] ?? null);
$claimCount = (int) $ms_trial_test_pdo->query('SELECT COUNT(*) FROM pro_trial_claims')->fetchColumn();
same('T5d. the claim row is still created', 1, $claimCount);

// 6. days = 0: claim row created, no grant.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'zerodays@example.com');
$trialZeroDays = ['days' => 0, 'hash_key' => $ms_trial_test_key, 'claim_retention_days' => 1825];
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'zerodays@example.com', $trialZeroDays);
same('T6a. no grant when days = 0', null, $expiresAt);
$claimCount = (int) $ms_trial_test_pdo->query('SELECT COUNT(*) FROM pro_trial_claims')->fetchColumn();
same('T6b. the claim row is still created when days = 0', 1, $claimCount);

// 7. A 10-character key: no claim row, no grant, an ERROR is logged.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'shortkey@example.com');
$shortKeyTrial = ['days' => 60, 'hash_key' => str_repeat('k', 10), 'claim_retention_days' => 1825];
ms_trial_test_forget_logs();
$expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'shortkey@example.com', $shortKeyTrial);
same('T7a. no grant with a 10-character key', null, $expiresAt);
$claimCount = (int) $ms_trial_test_pdo->query('SELECT COUNT(*) FROM pro_trial_claims')->fetchColumn();
same('T7b. no claim row with a 10-character key', 0, $claimCount);
check('T7c. an ERROR is logged for a too-short key', ms_trial_test_logged('ERROR', 'PRO_TRIAL_HASH_KEY'));

// 8. With pro_trial_claims dropped: no grant, a WARNING naming migrate_trial_claims.php.
$ms_trial_test_pdo = ms_trial_test_db();
$userId = ms_trial_test_seed_user($ms_trial_test_pdo, 'notable@example.com');
$ms_trial_test_pdo->exec('DROP TABLE pro_trial_claims');
ms_trial_test_forget_logs();
$threw = false;
try {
    $expiresAt = proTrialGrantOnVerification($ms_trial_test_pdo, $userId, 'notable@example.com', $ms_trial_test_trial);
} catch (Exception $e) {
    $threw = true;
    $expiresAt = null;
}
check('T8a. no exception when the table is missing', !$threw);
same('T8b. no grant when the table is missing', null, $expiresAt);
check('T8c. a WARNING naming migrate_trial_claims.php is logged', ms_trial_test_logged('WARNING', 'migrate_trial_claims.php'));

// 9. Calling proTrialRecordClaim() twice returns the same first_seen_at.
$ms_trial_test_pdo = ms_trial_test_db();
$first = proTrialRecordClaim($ms_trial_test_pdo, 'twice@example.com', $ms_trial_test_key);
$second = proTrialRecordClaim($ms_trial_test_pdo, 'twice@example.com', $ms_trial_test_key);
check('T9. proTrialRecordClaim() called twice returns the same first_seen_at',
    $first !== null && $first === $second, var_export([$first, $second], true));

// ---------------------------------------------------------------------------
// Static scan: the other recording paths (epic #267, step 3/4). These only
// record the address and must never grant a trial.
// ---------------------------------------------------------------------------

$proAuthSrc = file_get_contents(__DIR__ . '/../pro_auth.php');

same('S1. pro_auth.php calls proTrialRecordClaim() exactly twice',
    2, substr_count((string) $proAuthSrc, 'proTrialRecordClaim('));

check('S3. pro_auth.php never calls proTrialGrantOnVerification()',
    strpos((string) $proAuthSrc, 'proTrialGrantOnVerification(') === false);

echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
