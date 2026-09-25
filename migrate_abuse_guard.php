<?php

declare(strict_types=1);

/**
 * migrate_abuse_guard.php — CLI migration: the tables of the abuse guard
 * (abuse_guard.php, documentaion/ABUSE_PROTECTION.md).
 *
 *  - abuse_counters: per-subject buckets of hits, bytes and strikes (mail per
 *    address, address creation per IP/account, deliveries per webhook).
 *    Rows older than two days are swept by cron/abuse-guard.php.
 *  - address_quarantines: the addresses whose forwarder is (to be) removed,
 *    one row per local part; a NULL quarantined_until means closed.
 *  - abuse_events: what happened and whether its notice has been mailed.
 *    Swept after 90 days.
 *  - pro_users.suspended_at: set only when an admin confirms a suspension.
 *
 * No foreign keys, like address_cooldowns: a quarantine must outlive nothing
 * and be removable on its own, and the events are a log.
 *
 * Until this has run, every caller skips the guard (abuseGuardAvailable()
 * is false) and behaves exactly as before.
 *
 * Idempotent: each object is created only when it is missing, so a second
 * run does nothing and exits 0.
 *
 * Usage: php migrate_abuse_guard.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$steps = [
    ['abuse_counters', 'subject', "CREATE TABLE abuse_counters (
        scope VARCHAR(16) NOT NULL,
        subject VARCHAR(96) NOT NULL,
        window_start DATETIME NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        strikes INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (scope, subject, window_start),
        KEY idx_abuse_counters_window (window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['address_quarantines', 'local_part', "CREATE TABLE address_quarantines (
        local_part VARCHAR(64) NOT NULL,
        temp_email_id INT NOT NULL,
        pro_user_id INT NULL,
        reason VARCHAR(40) NOT NULL,
        quarantined_at DATETIME NOT NULL,
        quarantined_until DATETIME NULL,
        forwarder_removed TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (local_part),
        KEY idx_address_quarantines_until (quarantined_until),
        KEY idx_address_quarantines_user (pro_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['abuse_events', 'subject', "CREATE TABLE abuse_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        kind VARCHAR(40) NOT NULL,
        subject VARCHAR(96) NULL,
        pro_user_id INT NULL,
        detail TEXT NULL,
        notify TINYINT(1) NOT NULL DEFAULT 0,
        notified_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_abuse_events_kind (kind, subject, created_at),
        KEY idx_abuse_events_user (pro_user_id, kind, created_at),
        KEY idx_abuse_events_notify (notify, notified_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['pro_users', 'suspended_at', "ALTER TABLE pro_users ADD COLUMN suspended_at DATETIME NULL DEFAULT NULL"],
];

try {
    $changed = 0;
    foreach ($steps as [$table, $column, $sql]) {
        if (tableHasColumn($table, $column)) {
            echo "[skip] {$table}.{$column}: already exists\n";
            continue;
        }
        $pdo->exec($sql);
        echo "[done] {$table}.{$column}: created\n";
        $changed++;
    }
    echo $changed === 0 ? "Nothing to do.\n" : "Done. Schedule cron/abuse-guard.php every minute (see documentaion/ABUSE_PROTECTION.md).\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
