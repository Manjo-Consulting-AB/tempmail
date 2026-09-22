<?php

declare(strict_types=1);

/**
 * migrate_address_pushover_state.php — CLI migration: adds the per-address
 * Pushover flag to temp_emails.
 *
 * Pushover today is one kind of Pro webhook (pro_webhooks.kind = 'pushover',
 * dispatched by ImapProcessor::dispatchWebhooks()): the credentials live in
 * the webhook's config JSON and every incoming message for every address is
 * pushed. This adds the address-level state needed to let a user opt a single
 * personal address in or out of that, without duplicating the Pushover token
 * or user key onto the address.
 *
 * Adds:
 *   - pushover_enabled  TINYINT(1) NOT NULL DEFAULT 0
 *
 * The flag lives on the address row on purpose, exactly like feed_token: every
 * path that removes an address already deletes that row (index.php's
 * delete_personal, the grace-period cleanup and cleanupExpiredAddresses() in
 * cron/cleanup.php), so "delete the address, delete its Pushover state" is
 * automatic with no cascade assumptions and no orphan rows.
 *
 * NOT NULL DEFAULT 0 rather than a nullable flag: ADD COLUMN backfills every
 * existing row with 0, and both address-creation paths (saveNewAddress() in
 * config.php and create_personal in index.php) omit the column from their
 * INSERT, so new addresses are disabled too. No backfill UPDATE is therefore
 * needed, and there is no third "unset" state for the dispatcher to interpret.
 * This is a preference, not a credential — unlike feed_token there is nothing
 * here to null out when a Pro account degrades.
 *
 * Idempotent: checks the column before the ALTER, so running this script a
 * second time does nothing and exits 0.
 *
 * CLI-only, same guard as parse.php / migrate_account_types.php.
 *
 * Usage: php migrate_address_pushover_state.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$didWork = false;

if (tableHasColumn('temp_emails', 'pushover_enabled')) {
    echo "[skip] temp_emails.pushover_enabled: column already exists\n";
} else {
    $pdo->exec("ALTER TABLE temp_emails ADD COLUMN pushover_enabled TINYINT(1) NOT NULL DEFAULT 0");
    echo "[done] temp_emails.pushover_enabled: column added\n";
    $didWork = true;
}

if (!$didWork) {
    echo "Nothing to do.\n";
}

exit(0);
