<?php

declare(strict_types=1);

/**
 * check_referrals.php — read-only CLI diagnostics for the referral schema
 * (epic #387, migrate_referrals.php).
 *
 * Reports whether the pro_users columns, the unique key and the referrals
 * table with its indexes exist, the referrals per status, the accounts with
 * bonus Sticky slots and the effective referral settings, then the states
 * that are never legitimate (ids only): a rewarded row without
 * referrer_reward_at, a qualified row without qualified_at, and a
 * referral_code that is not a valid code. Never prints an email address.
 *
 * Usage: php check_referrals.php
 *
 * Exit codes: 0 clean, 1 something is missing or inconsistent.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/referrals.php';

$failures = 0;

function referralCheck(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "[OK] $label\n";
    } else {
        echo "[FEL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failures++;
    }
}

function referralCheckIndexExists(PDO $pdo, array $config, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    $hasCode = tableHasColumn('pro_users', 'referral_code');
    $hasTable = tableHasColumn('referrals', 'referee_id');

    referralCheck('pro_users.referral_code exists', $hasCode);
    referralCheck('pro_users.referral_code unique key uq_pu_referral_code', referralCheckIndexExists($pdo, $config, 'pro_users', 'uq_pu_referral_code'));
    referralCheck('pro_users.referral_pending_code exists', tableHasColumn('pro_users', 'referral_pending_code'));
    $hasSlots = tableHasColumn('pro_users', 'bonus_sticky_slots');
    referralCheck('pro_users.bonus_sticky_slots exists', $hasSlots);
    referralCheck('referrals table exists', $hasTable);

    if ($hasTable) {
        foreach (['uq_ref_referee', 'idx_ref_referrer', 'idx_ref_due', 'idx_ref_window'] as $index) {
            referralCheck("referrals index {$index}", referralCheckIndexExists($pdo, $config, 'referrals', $index));
        }

        echo "\nReferrals per status:\n";
        $rows = $pdo->query("SELECT status, COUNT(*) AS n FROM referrals GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "  (none)\n";
        }
        foreach ($rows as $r) {
            echo '  ' . $r['status'] . ': ' . (int) $r['n'] . "\n";
        }

        $bad = $pdo->query("SELECT id FROM referrals WHERE status = 'rewarded' AND referrer_reward_at IS NULL LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
        referralCheck('no rewarded row without referrer_reward_at', !$bad, 'referral id: ' . implode(', ', array_map('intval', $bad)));

        $bad = $pdo->query("SELECT id FROM referrals WHERE status = 'qualified' AND qualified_at IS NULL LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
        referralCheck('no qualified row without qualified_at', !$bad, 'referral id: ' . implode(', ', array_map('intval', $bad)));
    }

    if ($hasSlots) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM pro_users WHERE bonus_sticky_slots > 0")->fetchColumn();
        echo "\nAccounts with bonus Sticky slots: {$n}\n";
    }

    if ($hasCode) {
        $badIds = [];
        foreach ($pdo->query("SELECT id, referral_code FROM pro_users WHERE referral_code IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!referralIsValidCode((string) $r['referral_code'])) {
                $badIds[] = (int) $r['id'];
            }
        }
        referralCheck('every referral_code is valid', !$badIds, 'user id: ' . implode(', ', array_slice($badIds, 0, 50)));
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nSettings:\n";
foreach (referralSettings($config['referral'] ?? []) as $k => $v) {
    echo "  {$k} = " . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
}

exit($failures > 0 ? 1 : 0);
