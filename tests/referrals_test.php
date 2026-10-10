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
require __DIR__ . '/../referrals.php';

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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
