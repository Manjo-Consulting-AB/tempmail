<?php

declare(strict_types=1);

/**
 * Regression coverage for the pure referral helpers in referrals.php
 * (epic #387, step 1/9).
 *
 * Run with:  php tests/referrals_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL, no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('TEMPMAIL_APP', true);
require __DIR__ . '/../pro_trial.php';
require __DIR__ . '/../referrals.php';

// Stubs for the globals the DB-backed functions call (config.php / pii_crypto.php).
$GLOBALS['testLogs'] = [];
function logMessage($level, $message, $context = []) { $GLOBALS['testLogs'][] = [$level, $message, $context]; }
function tableHasColumn($table, $column)
{
    global $pdo;
    try {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return true;
            }
        }
    } catch (Throwable $e) {
    }
    return false;
}
function proUserEmail(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare('SELECT email FROM pro_users WHERE id = ?');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

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

// 1. Generation
$alphabet = referralCodeAlphabet();
same('alphabet', '23456789abcdefghjkmnpqrstuvwxyz', $alphabet);
$codes = [];
$allValid = true;
for ($i = 0; $i < 500; $i++) {
    $c = referralGenerateCode();
    $codes[$c] = true;
    if (strlen($c) !== 8 || !referralIsValidCode($c) || strspn($c, $alphabet) !== 8) {
        $allValid = false;
    }
}
check('500 generated codes are 8 chars from the alphabet and valid', $allValid);
check('at least 495 of 500 codes unique', count($codes) >= 495, 'unique: ' . count($codes));

// 2. Validation
foreach (['' => 'empty', 'abc' => 'short', 'ABCDEFGH' => 'uppercase', 'abcdefg1' => 'digit 1', 'abcdefgl' => 'letter l', 'abcdefgo' => 'letter o', 'abcdefghj' => '9 chars'] as $bad => $why) {
    same("invalid code ({$why})", false, referralIsValidCode($bad));
}
same('valid code', true, referralIsValidCode('abcd2345'));

// 3. Calendar months
same('Jan 31 + 1 (2027)', '2027-02-28 10:00:00', referralAddMonths('2027-01-31 10:00:00', 1));
same('Jan 31 + 1 (2028 leap)', '2028-02-29 10:00:00', referralAddMonths('2028-01-31 10:00:00', 1));
same('Apr 12 + 3', '2027-07-12 08:30:15', referralAddMonths('2027-04-12 08:30:15', 3));
same('Nov 15 + 3 crosses year', '2028-02-15 23:59:59', referralAddMonths('2027-11-15 23:59:59', 3));
same('+9 from 2027-04-12', '2028-01-12 00:00:00', referralAddMonths('2027-04-12 00:00:00', 9));

// window
same('window end', '2027-03-01 12:00:00', referralWindowEnd('2026-11-01 12:00:00', 120));

// 4. Reward kind
same('null expiry is sticky', 'sticky', referralRewardKind(['pro_expires_at' => null, 'has_billing_subscription' => false]));
same('null expiry beats billing', 'sticky', referralRewardKind(['pro_expires_at' => null, 'has_billing_subscription' => true]));
same('billing with date', 'billing', referralRewardKind(['pro_expires_at' => '2027-01-01 00:00:00', 'has_billing_subscription' => true]));
same('date only is months', 'months', referralRewardKind(['pro_expires_at' => '2027-01-01 00:00:00', 'has_billing_subscription' => false]));

// 5. Settings
same('defaults', ['enabled' => false, 'bonus_months' => 3, 'sticky_bonus' => 3, 'window_days' => 120, 'hold_days' => 30, 'cookie_days' => 30], referralSettings([]));
same('string inputs cast', ['enabled' => true, 'bonus_months' => 2, 'sticky_bonus' => 4, 'window_days' => 90, 'hold_days' => 0, 'cookie_days' => 7],
    referralSettings(['enabled' => '1', 'bonus_months' => '2', 'sticky_bonus' => '4', 'window_days' => '90', 'hold_days' => '0', 'cookie_days' => '7']));

// 6. Schema on SQLite
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ok = true;
try {
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql); // idempotent
    }
} catch (Throwable $e) {
    $ok = false;
}
check('sqlite schema runs cleanly (twice)', $ok);
$ins = $pdo->prepare("INSERT INTO referrals (referrer_id, referee_id, status, created_at, window_ends_at, updated_at) VALUES (1, ?, 'joined', '2026-01-01 00:00:00', '2026-05-01 00:00:00', '2026-01-01 00:00:00')");
$ins->execute([7]);
$dup = false;
try {
    $ins->execute([7]);
} catch (PDOException $e) {
    $dup = true;
}
check('second row for the same referee_id fails', $dup);
check('mysql statements mention the unique key', strpos(implode(' ', referralSchemaStatements('mysql')), 'uq_ref_referee') !== false);

// 7. DB-backed binding (epic #387 step 3) on SQLite
$key = str_repeat('k', 32);
$on = referralSettings(['enabled' => true]);
$off = referralSettings(['enabled' => false]);
$now = strtotime('2026-11-01 12:00:00 UTC');

function bindFresh(): PDO
{
    global $pdo;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT, referral_code TEXT, referral_pending_code TEXT, bonus_sticky_slots INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE pro_trial_claims (email_hash TEXT PRIMARY KEY, first_seen_at TEXT)');
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("INSERT INTO pro_users (id, email, referral_code) VALUES (1, 'ref@x.com', 'abcdefgh')");
    return $pdo;
}
function addReferee(PDO $pdo, string $email, ?string $pending): void
{
    $pdo->prepare('INSERT INTO pro_users (id, email, referral_pending_code) VALUES (2, ?, ?)')->execute([$email, $pending]);
}
function pendingOf(PDO $pdo): ?string
{
    $v = $pdo->query('SELECT referral_pending_code FROM pro_users WHERE id = 2')->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
}
function refCount(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
}

// referralCodeExists / referralCookieCode
$pdo = bindFresh();
same('code exists', true, referralCodeExists($pdo, 'abcdefgh'));
same('code does not exist', false, referralCodeExists($pdo, 'zzzzzzzz'));
$_COOKIE['ms_ref'] = 'ABCDEFGH';
same('cookie code lowercased', 'abcdefgh', referralCookieCode());
$_COOKIE['ms_ref'] = 'nope';
same('malformed cookie code is null', null, referralCookieCode());
unset($_COOKIE['ms_ref']);
same('no cookie is null', null, referralCookieCode());

// referralStorePendingCode
referralStorePendingCode($pdo, 1, 'abcdefgh', $off);
same('pending code not stored while disabled', null, $pdo->query('SELECT referral_pending_code FROM pro_users WHERE id = 1')->fetchColumn() ?: null);
referralStorePendingCode($pdo, 1, 'abcdefgh', $on);
same('pending code stored', 'abcdefgh', $pdo->query('SELECT referral_pending_code FROM pro_users WHERE id = 1')->fetchColumn());
referralStorePendingCode($pdo, 1, null, $on);
same('null pending code overwrites', null, $pdo->query('SELECT referral_pending_code FROM pro_users WHERE id = 1')->fetchColumn() ?: null);

// 1. Happy path
$pdo = bindFresh();
addReferee($pdo, 'friend@y.com', 'abcdefgh');
$id = referralBindOnVerification($pdo, 2, 'friend@y.com', true, $on, $now);
check('happy path returns an id', is_int($id) && $id > 0);
same('one row', 1, refCount($pdo));
$row = $pdo->query('SELECT * FROM referrals')->fetch(PDO::FETCH_ASSOC);
same('status joined', 'joined', $row['status']);
same('referrer id', 1, (int)$row['referrer_id']);
same('window is 120 days after created_at', referralWindowEnd($row['created_at'], 120), $row['window_ends_at']);
same('pending code cleared', null, pendingOf($pdo));
$logged = json_encode($GLOBALS['testLogs']);
check('bind log carries no address or code', strpos($logged, 'friend@') === false && strpos($logged, 'abcdefgh') === false);

// 2. Address seen before
$pdo = bindFresh();
addReferee($pdo, 'a.b+x@x.com', 'abcdefgh');
$pdo->prepare('INSERT INTO pro_trial_claims (email_hash, first_seen_at) VALUES (?, ?)')->execute([proTrialEmailHash('ab@x.com', $key), '2026-01-01 00:00:00']);
$isNew = referralAddressIsNew($pdo, 'a.b+x@x.com', $key);
same('canonical claim makes the address not new', false, $isNew);
same('unclaimed address is new', true, referralAddressIsNew($pdo, 'other@x.com', $key));
same('not new: no bind', null, referralBindOnVerification($pdo, 2, 'a.b+x@x.com', $isNew, $on, $now));
same('not new: no row', 0, refCount($pdo));
same('not new: pending still cleared', null, pendingOf($pdo));

// 3. Self-referral
$pdo = bindFresh();
$pdo->exec("INSERT INTO pro_users (id, email, referral_code, referral_pending_code) VALUES (2, 'me@y.com', 'mmmmmmmm', 'mmmmmmmm')");
same('self-referral: no bind', null, referralBindOnVerification($pdo, 2, 'me@y.com', true, $on, $now));
same('self-referral: no row', 0, refCount($pdo));

// 4. Canonical duplicate
$pdo = bindFresh();
$pdo->exec("UPDATE pro_users SET email = 'ab@x.com' WHERE id = 1");
addReferee($pdo, 'a.b+1@X.com', 'abcdefgh');
same('canonical duplicate: no bind', null, referralBindOnVerification($pdo, 2, 'a.b+1@X.com', true, $on, $now));
same('canonical duplicate: no row', 0, refCount($pdo));

// 5. Unknown code
$pdo = bindFresh();
addReferee($pdo, 'friend@y.com', 'qqqqqqqq');
same('unknown code: no bind', null, referralBindOnVerification($pdo, 2, 'friend@y.com', true, $on, $now));
same('unknown code: no row', 0, refCount($pdo));

// 6. Disabled
$pdo = bindFresh();
addReferee($pdo, 'friend@y.com', 'abcdefgh');
same('disabled: no bind', null, referralBindOnVerification($pdo, 2, 'friend@y.com', true, $off, $now));
same('disabled: no row', 0, refCount($pdo));
same('disabled: pending not cleared', 'abcdefgh', pendingOf($pdo));

// 7. Twice
$pdo = bindFresh();
addReferee($pdo, 'friend@y.com', 'abcdefgh');
referralBindOnVerification($pdo, 2, 'friend@y.com', true, $on, $now);
$pdo->exec("UPDATE pro_users SET referral_pending_code = 'abcdefgh' WHERE id = 2");
$threw = false;
$second = 'unset';
try {
    $second = referralBindOnVerification($pdo, 2, 'friend@y.com', true, $on, $now);
} catch (Throwable $e) {
    $threw = true;
}
check('second bind does not throw', !$threw);
same('second bind returns null', null, $second);
same('still one row', 1, refCount($pdo));

// 7b. Cookie fallback when the account has no pending code
$pdo = bindFresh();
addReferee($pdo, 'friend@y.com', null);
$_COOKIE['ms_ref'] = 'abcdefgh';
check('cookie fallback binds', referralBindOnVerification($pdo, 2, 'friend@y.com', true, $on, $now) !== null);
check('cookie unset after use', !isset($_COOKIE['ms_ref']));

// 8. Table dropped
$pdo = bindFresh();
addReferee($pdo, 'friend@y.com', 'abcdefgh');
$pdo->exec('DROP TABLE referrals');
$threw = false;
$res = 'unset';
try {
    $res = referralBindOnVerification($pdo, 2, 'friend@y.com', true, $on, $now);
} catch (Throwable $e) {
    $threw = true;
}
check('missing referrals table: no throw', !$threw);
same('missing referrals table: null', null, $res);

// 9. Short key
same('10-character key is never new', false, referralAddressIsNew($pdo, 'friend@y.com', str_repeat('k', 10)));

// 10. referralQualify() / referralVoidOnRefund() called directly (epic #387 step 4)
function qualifyFresh(): PDO
{
    global $pdo;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec('CREATE TABLE paddle_subscriptions (subscription_id TEXT PRIMARY KEY, customer_id TEXT, pro_user_id INTEGER)');
    $pdo->exec('CREATE TABLE paddle_transactions (transaction_id TEXT PRIMARY KEY, customer_id TEXT, pro_user_id INTEGER)');
    $pdo->exec("INSERT INTO referrals (referrer_id, referee_id, status, created_at, window_ends_at, attempts, updated_at) VALUES (1, 2, 'joined', '2026-01-01 00:00:00', '2026-05-01 00:00:00', 0, '2026-01-01 00:00:00')");
    return $pdo;
}
$qNow = strtotime('2026-02-01 12:00:00');
$qOpts = ['now' => $qNow, 'referral_settings' => ['hold_days' => 30]];

$pdo = qualifyFresh();
same('qualify: no row for another referee', '', referralQualify($pdo, 99, 'year', 'sub_1', null, 'ctm_1', $qOpts));
$out = referralQualify($pdo, 2, 'year', 'sub_1', null, 'ctm_1', $qOpts);
same('qualify: suffix', '; referral 1 qualified', $out);
$row = $pdo->query('SELECT * FROM referrals WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
same('qualify: status', 'qualified', $row['status']);
same('qualify: qualified_at in local time', '2026-02-01 12:00:00', $row['qualified_at']);
same('qualify: due after hold_days', '2026-03-03 12:00:00', $row['referrer_reward_due_at']);
same('qualify: a second call finds no joined row', '', referralQualify($pdo, 2, 'year', 'sub_1', null, 'ctm_1', $qOpts));

$pdo = qualifyFresh();
same('qualify: window over returns empty', '', referralQualify($pdo, 2, 'year', 'sub_1', null, 'ctm_1', ['now' => strtotime('2026-05-02 00:00:00')] + $qOpts));
same('qualify: window over leaves joined', 'joined', $pdo->query('SELECT status FROM referrals')->fetchColumn());

$pdo = qualifyFresh();
$pdo->exec("INSERT INTO paddle_transactions VALUES ('txn_r', 'ctm_1', 1)");
referralQualify($pdo, 2, 'lifetime', null, 'txn_1', 'ctm_1', $qOpts);
same('qualify: same customer voids', 'same_customer', $pdo->query('SELECT void_reason FROM referrals')->fetchColumn());

$pdo = qualifyFresh();
$pdo->exec('DROP TABLE referrals');
$threw = false;
$res = 'unset';
try {
    $res = referralQualify($pdo, 2, 'year', 'sub_1', null, 'ctm_1', $qOpts);
} catch (Throwable $e) {
    $threw = true;
}
check('qualify: missing table does not throw', !$threw);
same('qualify: missing table returns empty', '', $res);

$pdo = qualifyFresh();
referralQualify($pdo, 2, 'year', 'sub_1', null, 'ctm_1', $qOpts);
same('refund: null subscription id and other transaction match nothing', '', referralVoidOnRefund($pdo, null, 'txn_x', $qOpts));
same('refund: still qualified', 'qualified', $pdo->query('SELECT status FROM referrals')->fetchColumn());
same('refund: year matched on subscription id', '; 1 referral(s) voided', referralVoidOnRefund($pdo, 'sub_1', 'txn_x', $qOpts));
same('refund: void reason', 'refunded', $pdo->query('SELECT void_reason FROM referrals')->fetchColumn());

$pdo = qualifyFresh();
referralQualify($pdo, 2, 'lifetime', null, 'txn_1', 'ctm_1', $qOpts);
referralVoidOnRefund($pdo, null, 'txn_1', $qOpts);
same('refund: lifetime matched on transaction id', 'void', $pdo->query('SELECT status FROM referrals')->fetchColumn());

$pdo = qualifyFresh();
$pdo->exec("UPDATE referrals SET status = 'rewarded', plan = 'year', subscription_id = 'sub_1', referrer_reward_at = '2026-03-04 00:00:00'");
same('refund: rewarded row is not voided', '', referralVoidOnRefund($pdo, 'sub_1', 'txn_x', $qOpts));
same('refund: rewarded row unchanged', 'rewarded', $pdo->query('SELECT status FROM referrals')->fetchColumn());

$pdo = qualifyFresh();
$pdo->exec('DROP TABLE referrals');
$threw = false;
try {
    referralVoidOnRefund($pdo, 'sub_1', 'txn_x', $qOpts);
} catch (Throwable $e) {
    $threw = true;
}
check('refund: missing table does not throw', !$threw);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
