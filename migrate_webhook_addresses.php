<?php

declare(strict_types=1);

/**
 * migrate_webhook_addresses.php — CLI migration: per-hook address routing for
 * Pro webhooks (epic #251, step 2).
 *
 * Today a Pro user's webhooks are routed by kind alone: generic hooks fire for
 * every address of the user (temporary ones included) and Pushover hooks fire
 * for the personal addresses that have temp_emails.pushover_enabled = 1. The
 * new model gives every hook its own set of addresses, plus one "temporary
 * addresses" switch — see the epic's data-model section. This script adds that
 * schema and backfills it so that nothing any user receives changes on deploy
 * day.
 *
 * Adds:
 *   - pro_webhook_addresses (webhook_id, temp_email_id) — the per-hook address
 *     links, primary key on the pair, index on temp_email_id
 *   - pro_webhooks.include_temporary  TINYINT(1) NOT NULL DEFAULT 0
 *   - temp_emails.hooks_paused        TINYINT(1) NOT NULL DEFAULT 0
 *
 * No runtime code reads any of this yet: dispatch is #251 step 3 and the API
 * is step 4, so this migration changes no behaviour by itself.
 *
 * NO FOREIGN KEYS on pro_webhook_addresses, deliberately. This schema has no
 * guaranteed cascade — the address and webhook tables are created and altered
 * outside any migration DDL in the repository — so "delete the webhook" and
 * "delete the address" will delete their links explicitly (the API step, #251
 * step 4), and an orphan sweep in cron picks up whatever a failed delete left
 * behind (#251 step 6). A FK would either fail to apply on a schema that does
 * not match its expectations or silently change deletion behaviour on the way
 * in, which is exactly what this migration must not do.
 *
 * The backfill is guarded by the *existence of pro_webhooks.include_temporary*
 * rather than by a separate bookkeeping flag, because the two ship together:
 * once the column exists the backfill has already run, and running it again
 * would re-check addresses the user has since unchecked (and re-enable
 * "temporary addresses" for hooks they have since switched off). INSERT IGNORE
 * is what makes a re-run safe in the one window where the guard cannot help —
 * if the script died after the INSERTs but before the ALTER.
 *
 * The steps run in a fixed order — the table, then the backfill + ALTER, then
 * hooks_paused — because the backfill needs the table and the backfill is only
 * allowed to run while the column is missing. Pushover is treated as a hook
 * kind, not as a special case, but its backfill stays narrower than the
 * generic one: temp_emails.pushover_enabled is the only per-address opt-in
 * signal that exists on the day of the deploy. That column is retired in #251
 * step 6 and is neither read nor written here.
 *
 * Idempotent: every step checks for its table/column first, so running this
 * script a second time does nothing and exits 0.
 *
 * CLI-only, same guard as parse.php / migrate_address_pushover_state.php.
 *
 * Usage: php migrate_webhook_addresses.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$didWork = false;

try {
    // -----------------------------------------------------------------
    // 1. The link table
    // -----------------------------------------------------------------

    if (tableHasColumn('pro_webhook_addresses', 'webhook_id')) {
        echo "[skip] pro_webhook_addresses: table already exists\n";
    } else {
        $pdo->exec("CREATE TABLE pro_webhook_addresses (
            webhook_id INT NOT NULL,
            temp_email_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (webhook_id, temp_email_id),
            KEY idx_pwa_temp_email (temp_email_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] pro_webhook_addresses: table created\n";
        $didWork = true;
    }

    // -----------------------------------------------------------------
    // 2. Backfill, then include_temporary
    // -----------------------------------------------------------------

    if (tableHasColumn('pro_webhooks', 'include_temporary')) {
        echo "[skip] pro_webhooks.include_temporary: column already exists (backfill already ran)\n";
    } else {
        // Generic hooks keep firing for every personal address of their owner,
        // which is what they did before this migration.
        $linkGeneric = $pdo->prepare("INSERT IGNORE INTO pro_webhook_addresses (webhook_id, temp_email_id)
            SELECT w.id, t.id FROM pro_webhooks w
            JOIN temp_emails t ON t.pro_user_id = w.user_id AND t.is_personal = 1
            WHERE w.kind <> 'pushover'");
        $linkGeneric->execute();
        echo "[done] generic hooks linked to personal addresses: " . $linkGeneric->rowCount() . " link(s)\n";

        // Pushover hooks keep firing for the addresses the user opted in on.
        if (tableHasColumn('temp_emails', 'pushover_enabled')) {
            $linkPushover = $pdo->prepare("INSERT IGNORE INTO pro_webhook_addresses (webhook_id, temp_email_id)
                SELECT w.id, t.id FROM pro_webhooks w
                JOIN temp_emails t ON t.pro_user_id = w.user_id AND t.is_personal = 1 AND t.pushover_enabled = 1
                WHERE w.kind = 'pushover'");
            $linkPushover->execute();
            echo "[done] Pushover hooks linked to opted-in addresses: " . $linkPushover->rowCount() . " link(s)\n";
        } else {
            echo "[skip] temp_emails.pushover_enabled: column missing, no address can have opted in\n";
        }

        $pdo->exec("ALTER TABLE pro_webhooks ADD COLUMN include_temporary TINYINT(1) NOT NULL DEFAULT 0");
        echo "[done] pro_webhooks.include_temporary: column added\n";

        // Generic hooks fired for temporary addresses before this migration
        // too, so their switch starts on. Pushover hooks never did, so the
        // column's DEFAULT 0 is already the correct value for them.
        $temporary = $pdo->prepare("UPDATE pro_webhooks SET include_temporary = 1 WHERE kind <> 'pushover'");
        $temporary->execute();
        echo "[done] pro_webhooks.include_temporary: enabled for " . $temporary->rowCount() . " non-Pushover hook(s)\n";

        $didWork = true;
    }

    // -----------------------------------------------------------------
    // 3. Per-address pause
    // -----------------------------------------------------------------

    if (tableHasColumn('temp_emails', 'hooks_paused')) {
        echo "[skip] temp_emails.hooks_paused: column already exists\n";
    } else {
        $pdo->exec("ALTER TABLE temp_emails ADD COLUMN hooks_paused TINYINT(1) NOT NULL DEFAULT 0");
        echo "[done] temp_emails.hooks_paused: column added\n";
        $didWork = true;
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$didWork) {
    echo "Nothing to do.\n";
}

exit(0);
