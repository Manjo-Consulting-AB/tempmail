<?php

declare(strict_types=1);

/**
 * migrate_message_id.php — CLI migration: adds stored_emails.message_id and a
 * (to_address, message_id) index for Message-ID-based duplicate detection.
 *
 * The intake stores every message it accepts, and parse.php exits 75 on a
 * temporary failure (#215) so Exim redelivers the same message. Capturing the
 * Message-ID header lets the storage service recognise those redeliveries as
 * exact duplicates of a message already stored for the same recipient, instead
 * of storing a second copy (#212).
 *
 * Adds:
 *   - message_id  VARCHAR(255) NULL DEFAULT NULL
 *   - KEY idx_stored_emails_to_message_id (to_address(191), message_id(191))
 *
 * The column is NULL for mail stored before #212 and for messages that carry no
 * Message-ID header — a NULL simply means "no value to compare on", which is
 * why the column is nullable rather than defaulted.
 *
 * The index is deliberately NOT unique: the same Message-ID legitimately
 * reaches several of our addresses (one message to two recipients, or a
 * mailing-list copy), and those are separate messages for separate inboxes.
 * Uniqueness is only ever meaningful together with to_address, which is what
 * the composite index expresses.
 *
 * Nothing else needs cleaning up: the rows live in stored_emails, and every
 * path that removes mail already deletes those rows (delete_personal and the
 * address cleanup in cron/cleanup.php, plus the quota listener), so the column
 * disappears with the mail it describes.
 *
 * The prefix lengths (191) keep the key within the index size limit for
 * utf8mb4 columns, which is why to_address's type is checked before the ALTER:
 * a prefix index is only valid on a string column.
 *
 * Idempotent: checks the column before the ALTER and the index before adding
 * it, so running this script a second time does nothing and exits 0.
 *
 * CLI-only, same guard as parse.php / migrate_address_feed_tokens.php.
 *
 * Usage: php migrate_message_id.php
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

/**
 * Returns a column's DATA_TYPE, or null when the column does not exist.
 */
function migrationColumnDataType(PDO $pdo, string $table, string $column): ?string {
    global $config;
    $stmt = $pdo->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $column]);
    $type = $stmt->fetchColumn();
    return $type === false ? null : (string)$type;
}

// The prefix index is only valid on a string column, so this check gates both
// ALTERs: bail out before touching anything rather than half-migrating.
$toAddressType = migrationColumnDataType($pdo, 'stored_emails', 'to_address');
if ($toAddressType === null) {
    fwrite(STDERR, "ERROR: stored_emails.to_address does not exist; cannot create the prefix index. Nothing was altered.\n");
    exit(1);
}
if (!in_array(strtolower($toAddressType), ['varchar', 'text'], true)) {
    fwrite(STDERR, "ERROR: stored_emails.to_address is {$toAddressType}, not VARCHAR or TEXT; the prefix index on (to_address(191), message_id(191)) is invalid. Nothing was altered.\n");
    exit(1);
}

$didWork = false;

if (tableHasColumn('stored_emails', 'message_id')) {
    echo "[skip] stored_emails.message_id: column already exists\n";
} else {
    $pdo->exec("ALTER TABLE stored_emails ADD COLUMN message_id VARCHAR(255) NULL DEFAULT NULL");
    echo "[done] stored_emails.message_id: column added\n";
    $didWork = true;
}

if (migrationIndexExists($pdo, 'stored_emails', 'idx_stored_emails_to_message_id')) {
    echo "[skip] idx_stored_emails_to_message_id: index already exists\n";
} else {
    $pdo->exec("ALTER TABLE stored_emails ADD KEY idx_stored_emails_to_message_id (to_address(191), message_id(191))");
    echo "[done] idx_stored_emails_to_message_id: index added\n";
    $didWork = true;
}

if (!$didWork) {
    echo "Nothing to do.\n";
}

exit(0);
