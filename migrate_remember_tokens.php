<?php

declare(strict_types=1);

/**
 * migrate_remember_tokens.php — CLI migration: "Stay signed in" (see
 * pro_remember.php).
 *
 * Adds pro_remember_tokens: one row per device an account chose to keep
 * signed in — the selector the `ms_stay` cookie names, sha256 of its current
 * validator (and of the previous one, accepted briefly after a rotation), the
 * chosen period in days, a browser/OS label and the created / last-used /
 * expiry times. No foreign key, like pro_trusted_devices: the account deletion
 * paths remove an account's rows themselves, and cron/cleanup.php removes the
 * expired ones.
 *
 * Until this has run, sign-in works exactly as before and the "Stay signed
 * in" choices simply have no effect.
 *
 * Idempotent: guarded by the existence of pro_remember_tokens.selector, so
 * running this script a second time does nothing and exits 0.
 *
 * Usage: php migrate_remember_tokens.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

try {
    if (tableHasColumn('pro_remember_tokens', 'selector')) {
        echo "[skip] pro_remember_tokens: table already exists\n";
        echo "Nothing to do.\n";
    } else {
        $pdo->exec("CREATE TABLE pro_remember_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            selector CHAR(32) NOT NULL,
            validator_hash CHAR(64) NOT NULL,
            prev_validator_hash CHAR(64) NULL,
            rotated_at DATETIME NULL,
            days SMALLINT UNSIGNED NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pro_remember_tokens_selector (selector),
            KEY idx_pro_remember_tokens_user (user_id, last_used_at),
            KEY idx_pro_remember_tokens_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] pro_remember_tokens: table created\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
