<?php

declare(strict_types=1);

/**
 * migrate_address_cooldowns.php — CLI migration: the cool-off list for
 * released personal addresses (see address_cooldown.php).
 *
 * Adds address_cooldowns: one row per released personal address
 * (local_part, unique), the account that released it, when, and until when
 * nobody else may claim it. The table deliberately has NO foreign key to
 * pro_users: a reservation must outlive the account that held it, so a
 * deleted account's addresses stay blocked for the whole cool-off period.
 * Rows are removed only by address_cooldown.php (re-creation by the owner,
 * the per-owner cap) and by cleanupExpiredAddressCooldowns() in
 * cron/cleanup.php once blocked_until has passed.
 *
 * Idempotent: guarded by the existence of address_cooldowns.local_part, so
 * running this script a second time does nothing and exits 0.
 *
 * Usage: php migrate_address_cooldowns.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

try {
    if (tableHasColumn('address_cooldowns', 'local_part')) {
        echo "[skip] address_cooldowns: table already exists\n";
        echo "Nothing to do.\n";
    } else {
        $pdo->exec("CREATE TABLE address_cooldowns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            local_part VARCHAR(64) NOT NULL,
            pro_user_id INT NOT NULL,
            released_at DATETIME NOT NULL,
            blocked_until DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_address_cooldowns_local (local_part),
            KEY idx_address_cooldowns_user (pro_user_id, released_at),
            KEY idx_address_cooldowns_until (blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] address_cooldowns: table created\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
