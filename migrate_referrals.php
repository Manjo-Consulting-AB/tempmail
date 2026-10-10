<?php

declare(strict_types=1);

/**
 * migrate_referrals.php — CLI migration for referrals (epic #387, step 1/9).
 *
 * Adds pro_users.referral_code (+ unique key), pro_users.referral_pending_code
 * (the invite code held from registration until first verification, since the
 * verification link is often opened in a browser without the invite cookie),
 * pro_users.bonus_sticky_slots and the referrals ledger table.
 *
 * No backfill: codes are created lazily. Nothing reads these columns yet, so
 * this migration changes no behaviour by itself. Every part is checked
 * separately, so a second run prints only [skip] lines and exits 0.
 *
 * Usage: php migrate_referrals.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/referrals.php';

try {
    if (tableHasColumn('pro_users', 'referral_code')) {
        echo "[skip] pro_users.referral_code: already exists\n";
    } else {
        $pdo->exec("ALTER TABLE pro_users ADD COLUMN referral_code VARCHAR(16) NULL, ADD UNIQUE KEY uq_pu_referral_code (referral_code)");
        echo "[done] pro_users.referral_code: column and unique key added\n";
    }

    if (tableHasColumn('pro_users', 'referral_pending_code')) {
        echo "[skip] pro_users.referral_pending_code: already exists\n";
    } else {
        $pdo->exec("ALTER TABLE pro_users ADD COLUMN referral_pending_code VARCHAR(16) NULL");
        echo "[done] pro_users.referral_pending_code: column added\n";
    }

    if (tableHasColumn('pro_users', 'bonus_sticky_slots')) {
        echo "[skip] pro_users.bonus_sticky_slots: already exists\n";
    } else {
        $pdo->exec("ALTER TABLE pro_users ADD COLUMN bonus_sticky_slots INT NOT NULL DEFAULT 0");
        echo "[done] pro_users.bonus_sticky_slots: column added\n";
    }

    if (tableHasColumn('referrals', 'referee_id')) {
        echo "[skip] referrals: table already exists\n";
    } else {
        foreach (referralSchemaStatements('mysql') as $sql) {
            $pdo->exec($sql);
        }
        echo "[done] referrals: table created\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
