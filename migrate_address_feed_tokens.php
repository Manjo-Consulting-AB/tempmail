<?php

declare(strict_types=1);

/**
 * migrate_address_feed_tokens.php — CLI migration: adds the per-address RSS
 * feed token column to temp_emails.
 *
 * The account-wide feed lives on pro_users.feed_token. Per-address feeds
 * (#160) add a second, address-scoped token so a user can subscribe to
 * invoices@<domain> and newsletters@<domain> in separate reader folders.
 *
 * Adds:
 *   - feed_token  VARCHAR(128) NULL DEFAULT NULL
 *   - UNIQUE KEY uniq_temp_emails_feed_token (feed_token)
 *
 * The token lives on the address row on purpose: every path that removes an
 * address already deletes that row (index.php's delete_personal, the
 * grace-period cleanup and cleanupExpiredAddresses() in cron/cleanup.php), so
 * "delete the address, delete the feed" is automatic with no cascade
 * assumptions. MySQL allows multiple NULLs in a UNIQUE key, so NULL simply
 * means "this address has no feed".
 *
 * Idempotent: checks the column before the ALTER and the index before adding
 * it, so running this script a second time does nothing and exits 0.
 *
 * CLI-only, same guard as parse.php / migrate_account_types.php.
 *
 * Usage: php migrate_address_feed_tokens.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

/**
 * Checks for an index by name via information_schema, mirroring
 * tableHasColumn()'s approach in config.php (bound parameters rather than
 * string interpolation).
 */
function migrationIndexExists(PDO $pdo, string $table, string $index): bool {
    global $config;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

$didWork = false;

if (tableHasColumn('temp_emails', 'feed_token')) {
    echo "[skip] temp_emails.feed_token: column already exists\n";
} else {
    $pdo->exec("ALTER TABLE temp_emails ADD COLUMN feed_token VARCHAR(128) NULL DEFAULT NULL");
    echo "[done] temp_emails.feed_token: column added\n";
    $didWork = true;
}

if (migrationIndexExists($pdo, 'temp_emails', 'uniq_temp_emails_feed_token')) {
    echo "[skip] uniq_temp_emails_feed_token: index already exists\n";
} else {
    $pdo->exec("ALTER TABLE temp_emails ADD UNIQUE KEY uniq_temp_emails_feed_token (feed_token)");
    echo "[done] uniq_temp_emails_feed_token: unique key added\n";
    $didWork = true;
}

if (!$didWork) {
    echo "Nothing to do.\n";
}

exit(0);
