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
require __DIR__ . '/../paddle_api.php';
date_default_timezone_set('Europe/Stockholm');

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

// 11. referralRunRewards() (epic #387 step 7)
$rrSettings = referralSettings(['enabled' => true]);
$rrNow = strtotime('2027-01-14 12:00:00');

/** One in-memory Paddle: subscriptions by id, and every call recorded. */
final class FakePaddle
{
    public array $subs = [];
    public array $calls = [];
    public int $patchFailures = 0;      // PATCH throws this many times before working
    public bool $throwAfterApply = false; // PATCH is applied, then throws once

    public function callables(): array
    {
        return [
            'get' => function (string $id): array {
                $this->calls[] = ['GET', $id];
                if (!isset($this->subs[$id])) {
                    throw new RuntimeException('no such subscription');
                }
                return $this->subs[$id];
            },
            'patch' => function (string $id, string $utc): array {
                $this->calls[] = ['PATCH', $id, $utc];
                if ($this->patchFailures > 0) {
                    $this->patchFailures--;
                    throw new RuntimeException('Paddle is down');
                }
                $this->subs[$id]['next_billed_at'] = $utc;
                if ($this->throwAfterApply) {
                    $this->throwAfterApply = false;
                    throw new RuntimeException('connection lost after the PATCH');
                }
                return $this->subs[$id];
            },
        ];
    }

    public function patches(): int
    {
        return count(array_filter($this->calls, static function ($c) { return $c[0] === 'PATCH'; }));
    }
}

function rrFresh(): PDO
{
    global $pdo;
    $GLOBALS['testLogs'] = [];
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (paddleSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT, account_type TEXT NOT NULL DEFAULT 'regular', pro_expires_at TEXT NULL, bonus_sticky_slots INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("INSERT INTO pro_users (id, email, account_type, pro_expires_at) VALUES (1, 'r@x.com', 'pro', '2027-06-01 00:00:00'), (2, 'f@y.com', 'pro', '2027-12-01 00:00:00')");
    return $pdo;
}
/** A qualified referral 1 (referrer 1, referee 2). */
function rrAddRow(PDO $pdo, array $o = []): void
{
    $o += ['plan' => 'year', 'sub' => 'sub_ref', 'due' => '2027-01-10 00:00:00', 'refereeDone' => null, 'status' => 'qualified', 'window' => '2027-03-01 00:00:00'];
    $pdo->prepare("INSERT INTO referrals (id, referrer_id, referee_id, status, created_at, window_ends_at, plan, subscription_id, qualified_at, referee_reward_at, referrer_reward_due_at, attempts, updated_at)
        VALUES (?, 1, ?, ?, '2026-12-01 00:00:00', ?, ?, ?, '2026-12-15 00:00:00', ?, ?, 0, '2026-12-15 00:00:00')")
        ->execute([$o['id'] ?? 1, $o['referee'] ?? 2, $o['status'], $o['window'], $o['plan'], $o['plan'] === 'year' ? $o['sub'] : null, $o['refereeDone'], $o['due']]);
}
function rrAddBilling(PDO $pdo, string $subId, int $userId, string $status = 'active', ?string $action = null): void
{
    $pdo->prepare("INSERT INTO paddle_subscriptions (subscription_id, customer_id, pro_user_id, status, scheduled_change_action, occurred_at, updated_at) VALUES (?, 'ctm_x', ?, ?, ?, '2026-12-01T00:00:00Z', '2026-12-01 00:00:00')")
        ->execute([$subId, $userId, $status, $action]);
}
function rrRow(PDO $pdo, int $id = 1): array
{
    $stmt = $pdo->prepare('SELECT * FROM referrals WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function rrRun(PDO $pdo, FakePaddle $fake, bool $dry = false, ?array $settings = null, ?int $now = null): array
{
    global $rrSettings, $rrNow;
    return referralRunRewards($pdo, $settings ?? $rrSettings, $fake->callables(), $now ?? $rrNow, $dry);
}
function rrLogCount(string $level, string $message): int
{
    return count(array_filter($GLOBALS['testLogs'], static function ($l) use ($level, $message) { return $l[0] === $level && $l[1] === $message; }));
}

// 11.1 Referee year push, not yet due for the referrer
$pdo = rrFresh();
rrAddRow($pdo, ['due' => '2027-02-10 00:00:00']);
$fake = new FakePaddle();
$fake->subs['sub_ref'] = ['next_billed_at' => '2027-12-15T10:00:00Z'];
$c = rrRun($pdo, $fake);
same('referee push: next_billed_at moved 3 months', '2028-03-15T10:00:00.000000Z', $fake->subs['sub_ref']['next_billed_at']);
same('referee push: one PATCH', 1, $fake->patches());
check('referee push: referee_reward_at set', rrRow($pdo)['referee_reward_at'] === '2027-01-14 12:00:00');
same('referee push: target stored in local time', '2028-03-15 11:00:00', rrRow($pdo)['referee_target_billed_at']);
same('referee push: counters', 1, $c['referee_rewarded']);
same('referee push: referrer not yet due', 'qualified', rrRow($pdo)['status']);

// 11.2 Second run: no PATCH
$before = count($fake->calls);
rrRun($pdo, $fake);
same('second run: no further calls at all', $before, count($fake->calls));

// 11.3 PATCH applied in Paddle but the run fails afterwards
$pdo = rrFresh();
rrAddRow($pdo, ['due' => '2027-02-10 00:00:00']);
$fake = new FakePaddle();
$fake->subs['sub_ref'] = ['next_billed_at' => '2027-12-15T10:00:00Z'];
$fake->throwAfterApply = true;
rrRun($pdo, $fake);
same('lost PATCH answer: one PATCH', 1, $fake->patches());
same('lost PATCH answer: attempt counted', 1, (int)rrRow($pdo)['attempts']);
check('lost PATCH answer: referee not marked done', rrRow($pdo)['referee_reward_at'] === null);
$c = rrRun($pdo, $fake);
same('retry: still only one PATCH', 1, $fake->patches());
check('retry: marked done', rrRow($pdo)['referee_reward_at'] !== null);
same('retry: counted as retried', 1, $c['retried']);

// 11.4 Referrer due with a billing subscription
$pdo = rrFresh();
rrAddRow($pdo, ['refereeDone' => '2026-12-15 00:00:00']);
rrAddBilling($pdo, 'sub_anna', 1);
$fake = new FakePaddle();
$fake->subs['sub_anna'] = ['next_billed_at' => '2027-04-12T08:00:00Z'];
$c = rrRun($pdo, $fake);
same('referrer billing: moved 3 months', '2027-07-12T08:00:00.000000Z', $fake->subs['sub_anna']['next_billed_at']);
$row = rrRow($pdo);
same('referrer billing: rewarded', 'rewarded', $row['status']);
same('referrer billing: kind', 'billing', $row['referrer_reward_kind']);
same('referrer billing: counter', 1, $c['referrer_rewarded']);
same('referrer billing: pro_expires_at untouched', '2027-06-01 00:00:00', $pdo->query('SELECT pro_expires_at FROM pro_users WHERE id = 1')->fetchColumn());

// 11.4b A subscription scheduled to cancel or past_due is not a billing subscription
$pdo = rrFresh();
rrAddRow($pdo, ['refereeDone' => '2026-12-15 00:00:00']);
rrAddBilling($pdo, 'sub_a', 1, 'active', 'cancel');
rrAddBilling($pdo, 'sub_b', 1, 'past_due');
$fake = new FakePaddle();
rrRun($pdo, $fake);
same('no eligible billing subscription: months', 'months', rrRow($pdo)['referrer_reward_kind']);
same('no eligible billing subscription: no Paddle call', 0, count($fake->calls));

// 11.5 Lifetime referrer: sticky slots
$pdo = rrFresh();
$pdo->exec('UPDATE pro_users SET pro_expires_at = NULL WHERE id = 1');
rrAddRow($pdo, ['refereeDone' => '2026-12-15 00:00:00']);
$fake = new FakePaddle();
rrRun($pdo, $fake);
same('sticky: slots 0 to 3', 3, (int)$pdo->query('SELECT bonus_sticky_slots FROM pro_users WHERE id = 1')->fetchColumn());
same('sticky: kind', 'sticky', rrRow($pdo)['referrer_reward_kind']);
same('sticky: no Paddle call', 0, count($fake->calls));

// 11.6 Months: trial still running, then lapsed
$pdo = rrFresh();
$pdo->exec("UPDATE pro_users SET pro_expires_at = '2027-01-20 00:00:00' WHERE id = 1");
rrAddRow($pdo, ['refereeDone' => '2026-12-15 00:00:00']);
rrRun($pdo, new FakePaddle());
same('months: trial end plus 3 months', '2027-04-20 00:00:00', $pdo->query('SELECT pro_expires_at FROM pro_users WHERE id = 1')->fetchColumn());
same('months: kind', 'months', rrRow($pdo)['referrer_reward_kind']);
$pdo = rrFresh();
$pdo->exec("UPDATE pro_users SET pro_expires_at = '2026-10-01 00:00:00', account_type = 'regular' WHERE id = 1");
rrAddRow($pdo, ['refereeDone' => '2026-12-15 00:00:00']);
rrRun($pdo, new FakePaddle());
same('months, lapsed: now plus 3 months', '2027-04-14 12:00:00', $pdo->query('SELECT pro_expires_at FROM pro_users WHERE id = 1')->fetchColumn());
same('months, lapsed: account_type pro', 'pro', $pdo->query('SELECT account_type FROM pro_users WHERE id = 1')->fetchColumn());
// A Regular account with no expiry at all is not Lifetime: months, never sticky (decision 7).
$pdo = rrFresh();
$pdo->exec("UPDATE pro_users SET pro_expires_at = NULL, account_type = 'regular' WHERE id = 1");
rrAddRow($pdo, ['refereeDone' => '2026-12-15 00:00:00']);
rrRun($pdo, new FakePaddle());
same('regular, no expiry: kind months', 'months', rrRow($pdo)['referrer_reward_kind']);
same('regular, no expiry: now plus 3 months', '2027-04-14 12:00:00', $pdo->query('SELECT pro_expires_at FROM pro_users WHERE id = 1')->fetchColumn());
same('regular, no expiry: account_type pro', 'pro', $pdo->query('SELECT account_type FROM pro_users WHERE id = 1')->fetchColumn());
same('regular, no expiry: no sticky slots', 0, (int)$pdo->query('SELECT bonus_sticky_slots FROM pro_users WHERE id = 1')->fetchColumn());

// 11.7 Lifetime referee
$pdo = rrFresh();
rrAddRow($pdo, ['plan' => 'lifetime']);
$pdo->exec("UPDATE pro_users SET pro_expires_at = NULL WHERE id = 1");
$fake = new FakePaddle();
$c = rrRun($pdo, $fake);
same('lifetime referee: no Paddle call', 0, count($fake->calls));
same('lifetime referee: counted', 1, $c['referee_rewarded']);
same('lifetime referee: referrer rewarded when due', 'rewarded', rrRow($pdo)['status']);

// 11.8 Referee subscription with no next_billed_at
$pdo = rrFresh();
rrAddRow($pdo, ['due' => '2027-02-10 00:00:00']);
$fake = new FakePaddle();
$fake->subs['sub_ref'] = ['next_billed_at' => null];
rrRun($pdo, $fake);
same('referee cancel scheduled: months granted', '2028-03-01 00:00:00', $pdo->query('SELECT pro_expires_at FROM pro_users WHERE id = 2')->fetchColumn());
same('referee cancel scheduled: no PATCH', 0, $fake->patches());
check('referee cancel scheduled: row moves on', rrRow($pdo)['referee_reward_at'] !== null);

// 11.9 Five failed runs
$pdo = rrFresh();
rrAddRow($pdo, ['due' => '2027-02-10 00:00:00']);
$fake = new FakePaddle();
$fake->subs['sub_ref'] = ['next_billed_at' => '2027-12-15T10:00:00Z'];
$fake->patchFailures = 100;
$stuck = 0;
for ($i = 0; $i < 5; $i++) {
    $stuck += rrRun($pdo, $fake)['stuck'];
}
same('five failures: status stuck', 'stuck', rrRow($pdo)['status']);
same('five failures: stuck counted once', 1, $stuck);
same('five failures: ERROR logged', 1, rrLogCount('ERROR', 'Referral reward stuck, grant by hand'));
$patches = $fake->patches();
rrRun($pdo, $fake);
same('sixth run: no further PATCH', $patches, $fake->patches());
$logged = json_encode($GLOBALS['testLogs']);
check('logs carry no address', strpos($logged, '@') === false);

// 11.10 Housekeeping
$pdo = rrFresh();
rrAddRow($pdo, ['id' => 1, 'referee' => 2, 'status' => 'joined', 'plan' => 'year', 'window' => '2027-01-01 00:00:00']);
rrAddRow($pdo, ['id' => 2, 'referee' => 3, 'status' => 'joined', 'plan' => 'year', 'window' => '2027-03-01 00:00:00']);
$pdo->exec("INSERT INTO referrals (id, referrer_id, referee_id, status, created_at, window_ends_at, plan, subscription_id, referrer_reward_due_at, attempts, updated_at) VALUES (3, 99, 4, 'qualified', '2026-12-01 00:00:00', '2027-03-01 00:00:00', 'year', 'sub_z', '2027-03-01 00:00:00', 0, '2026-12-01 00:00:00')");
$c = rrRun($pdo, new FakePaddle());
same('housekeeping: window over is expired', 'expired', rrRow($pdo, 1)['status']);
same('housekeeping: window open stays joined', 'joined', rrRow($pdo, 2)['status']);
same('housekeeping: deleted referrer voided', 'void', rrRow($pdo, 3)['status']);
same('housekeeping: void reason', 'referrer_deleted', rrRow($pdo, 3)['void_reason']);
same('housekeeping: counters', [1, 1], [$c['expired'], $c['voided']]);

// 11.11 Volume warning
$pdo = rrFresh();
for ($i = 1; $i <= 6; $i++) {
    rrAddRow($pdo, ['id' => $i, 'referee' => 10 + $i, 'status' => 'rewarded']);
    $pdo->exec("UPDATE referrals SET referrer_reward_at = '2027-01-05 00:00:00' WHERE id = {$i}");
}
rrRun($pdo, new FakePaddle());
same('volume: exactly one WARNING for 6 rewards', 1, rrLogCount('WARNING', 'Referral volume high'));
$pdo->exec("UPDATE referrals SET referrer_reward_at = '2026-10-01 00:00:00' WHERE id = 1");
$GLOBALS['testLogs'] = [];
rrRun($pdo, new FakePaddle());
same('volume: 5 within 30 days is quiet', 0, rrLogCount('WARNING', 'Referral volume high'));

// 11.12 Dry run and disabled
$pdo = rrFresh();
rrAddRow($pdo, ['id' => 1, 'referee' => 2]);
rrAddRow($pdo, ['id' => 2, 'referee' => 3, 'status' => 'joined', 'window' => '2027-01-01 00:00:00']);
rrAddRow($pdo, ['id' => 3, 'referee' => 4, 'plan' => 'lifetime']);
$dump = static function (PDO $p): string {
    return json_encode([$p->query('SELECT * FROM referrals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $p->query('SELECT * FROM pro_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]);
};
$fake = new FakePaddle();
$fake->subs['sub_ref'] = ['next_billed_at' => '2027-12-15T10:00:00Z'];
$before = $dump($pdo);
$c = rrRun($pdo, $fake, true);
check('dry run: counters non-zero', $c['referee_rewarded'] > 0 && $c['expired'] > 0);
same('dry run: tables unchanged', $before, $dump($pdo));
same('dry run: no PATCH', 0, $fake->patches());
$c = rrRun($pdo, $fake, false, referralSettings(['enabled' => false]));
same('disabled: all zeros', array_fill_keys(array_keys($c), 0), $c);
same('disabled: tables unchanged', $before, $dump($pdo));
$pdo->exec('DROP TABLE referrals');
$threw = false;
try {
    $c = rrRun($pdo, $fake);
} catch (Throwable $e) {
    $threw = true;
}
check('missing table: no throw, zeros', !$threw && array_sum($c) === 0);

// 11. referralEnsureCode / referralSummaryFor (epic #387 step 8)
function ensureFresh(): PDO
{
    global $pdo;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT, referral_code TEXT, bonus_sticky_slots INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE UNIQUE INDEX uq_pu_referral_code ON pro_users (referral_code)');
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("INSERT INTO pro_users (id, email) VALUES (1, 'a@x.com'), (2, 'b@x.com'), (3, 'c@x.com')");
    return $pdo;
}
$pdo = ensureFresh();
$code1 = referralEnsureCode($pdo, 1);
check('ensureCode: creates a valid code', $code1 !== null && referralIsValidCode($code1));
same('ensureCode: second call returns the same code', $code1, referralEnsureCode($pdo, 1));
same('ensureCode: stored in the column', $code1, (string)$pdo->query('SELECT referral_code FROM pro_users WHERE id = 1')->fetchColumn());

// Forced collision: the first two draws are account 1's code, the third is free.
$draws = [$code1, $code1, 'bbbbbbbb'];
$i = 0;
$gen = static function () use (&$draws, &$i): string {
    return $draws[min($i++, count($draws) - 1)];
};
same('ensureCode: retries after collisions and succeeds', 'bbbbbbbb', referralEnsureCode($pdo, 2, $gen));
same('ensureCode: three draws were used', 3, $i);

// Every draw collides: gives up after 5 attempts and returns null without throwing.
$i = 0;
$always = static function () use (&$i, $code1): string {
    $i++;
    return $code1;
};
$GLOBALS['testLogs'] = [];
$threw = false;
$res = 'unset';
try {
    $res = referralEnsureCode($pdo, 3, $always);
} catch (Throwable $e) {
    $threw = true;
}
check('ensureCode: persistent collision returns null, no throw', !$threw && $res === null);
same('ensureCode: at most 5 attempts', 5, $i);
$pdo->exec('DROP TABLE pro_users');
check('ensureCode: missing table returns null, no throw', referralEnsureCode($pdo, 1) === null);

// Summary
$pdo = ensureFresh();
$now = strtotime('2026-11-01 12:00:00');
$ins = $pdo->prepare('INSERT INTO referrals (referrer_id, referee_id, status, created_at, window_ends_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
$mk = static function (int $referrer, int $referee, string $status, string $ends) use ($ins): void {
    $ins->execute([$referrer, $referee, $status, '2026-10-01 00:00:00', $ends, '2026-10-01 00:00:00']);
};
$mk(1, 10, 'joined', '2027-01-01 00:00:00');
$mk(1, 11, 'joined', '2027-01-01 00:00:00');
$mk(1, 12, 'qualified', '2027-01-01 00:00:00');
$mk(1, 13, 'stuck', '2027-01-01 00:00:00');
$mk(1, 14, 'rewarded', '2027-01-01 00:00:00');
$mk(1, 15, 'void', '2027-01-01 00:00:00');
$mk(1, 16, 'expired', '2027-01-01 00:00:00');
$mk(2, 1, 'joined', '2026-11-01 12:00:00');
$mk(3, 2, 'joined', '2026-11-01 11:59:59');
$mk(3, 3, 'qualified', '2027-01-01 00:00:00');
$sum = referralSummaryFor($pdo, 1, $now);
same('summary: joined', 2, $sum['joined']);
same('summary: pending counts qualified and stuck', 2, $sum['pending']);
same('summary: rewarded', 1, $sum['rewarded']);
same('summary: own is the referee row inside the window (boundary inclusive)', ['window_ends_at' => '2026-11-01 12:00:00'], $sum['own']);
same('summary: only the expected keys', ['joined', 'pending', 'rewarded', 'own'], array_keys($sum));
same('summary: own is null after the window', null, referralSummaryFor($pdo, 2, $now)['own']);
same('summary: own is null when the referee row is not joined', null, referralSummaryFor($pdo, 3, $now)['own']);
same('summary: no rows gives zeros', ['joined' => 0, 'pending' => 0, 'rewarded' => 0, 'own' => null], referralSummaryFor($pdo, 99, $now));
$pdo->exec('DROP TABLE referrals');
same('summary: missing table gives zeros', ['joined' => 0, 'pending' => 0, 'rewarded' => 0, 'own' => null], referralSummaryFor($pdo, 1, $now));

// 12. referralDigestCollect() / referralDigestBody() (the admin digest, cron/referrals-digest.php)
function dgFresh(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}
function dgAdd(PDO $pdo, int $id, string $status, array $o = []): void
{
    $o += ['created' => '2026-12-01 00:00:00', 'qualified' => null, 'rewarded' => null, 'kind' => null, 'reason' => null, 'updated' => '2026-12-01 00:00:00'];
    $pdo->prepare("INSERT INTO referrals (id, referrer_id, referee_id, status, created_at, window_ends_at, qualified_at, referrer_reward_at, referrer_reward_kind, void_reason, attempts, updated_at)
        VALUES (?, 1, ?, ?, ?, '2027-06-01 00:00:00', ?, ?, ?, ?, 0, ?)")
        ->execute([$id, 100 + $id, $status, $o['created'], $o['qualified'], $o['rewarded'], $o['kind'], $o['reason'], $o['updated']]);
}
$dgSince = strtotime('2027-01-10 08:00:00');
$dgUntil = strtotime('2027-01-11 07:59:00');

$pdo = dgFresh();
$d = referralDigestCollect($pdo, $dgSince, $dgUntil);
same('digest: an empty table collects nothing', ['joined' => [], 'qualified' => [], 'rewarded' => [], 'rewarded_kinds' => [], 'voided' => [], 'stuck_new' => [], 'stuck_all' => [], 'expired' => 0, 'by_status' => []], $d);
same('digest: nothing to report gives no mail', null, referralDigestBody($d, $dgSince, $dgUntil));

// Window edges: the lower bound is exclusive, the upper inclusive.
$pdo = dgFresh();
dgAdd($pdo, 1, 'joined', ['created' => '2027-01-10 08:00:00']);
dgAdd($pdo, 2, 'joined', ['created' => '2027-01-10 08:00:01']);
dgAdd($pdo, 3, 'joined', ['created' => '2027-01-11 07:59:00']);
dgAdd($pdo, 4, 'joined', ['created' => '2027-01-11 07:59:01']);
$d = referralDigestCollect($pdo, $dgSince, $dgUntil);
same('digest: lower bound exclusive, upper inclusive', [2, 3], $d['joined']);

// Each event reads its own timestamp; old rows stay out of the lists but not out of the totals.
$pdo = dgFresh();
dgAdd($pdo, 10, 'qualified', ['qualified' => '2027-01-10 12:00:00']);
dgAdd($pdo, 11, 'rewarded', ['qualified' => '2026-12-01 00:00:00', 'rewarded' => '2027-01-10 13:00:00', 'kind' => 'months']);
dgAdd($pdo, 12, 'rewarded', ['qualified' => '2026-12-01 00:00:00', 'rewarded' => '2027-01-10 14:00:00', 'kind' => 'sticky']);
dgAdd($pdo, 13, 'rewarded', ['qualified' => '2026-12-01 00:00:00', 'rewarded' => '2027-01-10 15:00:00', 'kind' => 'months']);
dgAdd($pdo, 14, 'rewarded', ['qualified' => '2026-11-01 00:00:00', 'rewarded' => '2026-12-20 00:00:00', 'kind' => 'billing']);
dgAdd($pdo, 15, 'void', ['reason' => 'refunded', 'updated' => '2027-01-10 16:00:00']);
dgAdd($pdo, 16, 'void', ['reason' => 'same_customer', 'updated' => '2027-01-10 17:00:00']);
dgAdd($pdo, 17, 'void', ['reason' => 'refunded', 'updated' => '2026-12-05 00:00:00']);
dgAdd($pdo, 18, 'expired', ['updated' => '2027-01-10 18:00:00']);
dgAdd($pdo, 19, 'expired', ['updated' => '2026-12-05 00:00:00']);
$d = referralDigestCollect($pdo, $dgSince, $dgUntil);
same('digest: qualified in the window', [10], $d['qualified']);
same('digest: rewarded in the window', [11, 12, 13], $d['rewarded']);
same('digest: rewarded by kind', ['months' => 2, 'sticky' => 1], $d['rewarded_kinds']);
same('digest: voided grouped by reason, old ones left out', ['refunded' => [15], 'same_customer' => [16]], $d['voided']);
same('digest: expired counted inside the window only', 1, $d['expired']);
same('digest: totals cover every row', ['expired' => 2, 'qualified' => 1, 'rewarded' => 4, 'void' => 3], $d['by_status']);
$mail = referralDigestBody($d, $dgSince, $dgUntil);
check('digest: a mail is built', $mail !== null);
[$subject, $body] = $mail;
same('digest: subject lists the counts', 'Mail Shield referrals: 1 qualified, 3 rewarded, 2 void', $subject);
check('digest: body names the reward kinds', strpos($body, 'Inviter rewards granted: 3 (months 2, sticky 1) (referral 11, 12, 13)') !== false);
check('digest: body names each void reason', strpos($body, 'Void, refunded: 1 (referral 15)') !== false && strpos($body, 'Void, same_customer: 1 (referral 16)') !== false);
check('digest: body counts the expiry too', strpos($body, 'Expired without a purchase: 1') !== false);
check('digest: body carries the status totals', strpos($body, 'All referrals now: expired 2, qualified 1, rewarded 4, void 3') !== false);
check('digest: body has no address in it', strpos($body, '@') === false && strpos($subject, '@') === false);
check('digest: no stuck warning when none is stuck', strpos($body, 'NEEDS ACTION') === false);

// Expiries alone are routine and send nothing.
$pdo = dgFresh();
dgAdd($pdo, 1, 'expired', ['updated' => '2027-01-10 18:00:00']);
same('digest: expiries alone give no mail', null, referralDigestBody(referralDigestCollect($pdo, $dgSince, $dgUntil), $dgSince, $dgUntil));

// A reward that has just become stuck triggers a mail and lists every stuck one; an old stuck one alone does not.
$pdo = dgFresh();
dgAdd($pdo, 1, 'stuck', ['updated' => '2026-12-20 00:00:00']);
same('digest: an old stuck reward alone gives no mail', null, referralDigestBody(referralDigestCollect($pdo, $dgSince, $dgUntil), $dgSince, $dgUntil));
dgAdd($pdo, 2, 'stuck', ['updated' => '2027-01-10 20:00:00']);
$d = referralDigestCollect($pdo, $dgSince, $dgUntil);
same('digest: stuck_new only holds the new one', [2], $d['stuck_new']);
same('digest: stuck_all holds both', [1, 2], $d['stuck_all']);
[$subject, $body] = referralDigestBody($d, $dgSince, $dgUntil);
same('digest: a stuck reward leads the subject', 'Mail Shield referrals: 1 stuck', $subject);
check('digest: body asks for action and lists both', strpos($body, 'NEEDS ACTION: reward stuck after repeated failures, grant by hand: referral 1, 2.') !== false);

// A burst never makes the mail huge.
$pdo = dgFresh();
for ($i = 1; $i <= 30; $i++) {
    dgAdd($pdo, $i, 'joined', ['created' => '2027-01-10 12:00:00']);
}
[$subject, $body] = referralDigestBody(referralDigestCollect($pdo, $dgSince, $dgUntil), $dgSince, $dgUntil);
same('digest: 30 new invites in the subject', 'Mail Shield referrals: 30 new', $subject);
check('digest: ids are capped at 20 with a remainder', strpos($body, 'New invites bound: 30 (referral 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 and 10 more)') !== false);

// Missing table: collect reports failure instead of throwing.
$pdo = dgFresh();
$pdo->exec('DROP TABLE referrals');
same('digest: a missing table gives null', null, referralDigestCollect($pdo, $dgSince, $dgUntil));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
