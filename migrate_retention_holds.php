<?php

declare(strict_types=1);

/**
 * migrate_retention_holds.php — CLI migration: the retention-hold list
 * (epic #369, see config.php's 'retention_hold').
 *
 * Adds retention_holds: one row per hold a Pro account has started on its
 * own incoming-mail retention. started_at is when the hold began, ends_at is
 * started_at + RETENTION_HOLD_DAYS and is never changed once written, and
 * ended_at is set when the account ends the hold early. A hold is active
 * while ended_at IS NULL AND ends_at > NOW(); every row counts toward the
 * per-account yearly limit, one ended early included.
 *
 * The table deliberately has NO foreign key to pro_users, the same
 * convention as address_cooldowns: a hold must be countable even after the
 * account that started it is gone, and an orphaned row is reported by
 * check_retention_holds.php rather than cascaded away.
 *
 * Nothing reads this table yet; it is inert once deployed.
 *
 * Idempotent: guarded by the existence of retention_holds.pro_user_id, so
 * running this script a second time does nothing and exits 0.
 *
 * Usage: php migrate_retention_holds.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

try {
    if (tableHasColumn('retention_holds', 'pro_user_id')) {
        echo "[skip] retention_holds: table already exists\n";
        echo "Nothing to do.\n";
    } else {
        $pdo->exec("CREATE TABLE retention_holds (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            pro_user_id INT NOT NULL,
            started_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_retention_holds_user (pro_user_id, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] retention_holds: table created\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
