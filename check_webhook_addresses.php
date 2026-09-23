<?php

declare(strict_types=1);

/**
 * CLI diagnostics for the per-hook address routing schema (epic #251, step 2),
 * in the same spirit as check_address_pushover.php / check_address_feeds.php.
 *
 * Reports whether pro_webhook_addresses and the two columns it ships with
 * exist, how much is in them, and the two states that are never legitimate:
 * links pointing at a webhook or a personal address that is gone, and links
 * whose webhook belongs to a different user than the address does.
 *
 * Read-only: writes nothing.
 *
 * Exit codes, so a cron/monitor can tell "migrated and consistent" apart from
 * "half-migrated" and "inconsistent":
 *   0  table present, both columns present, no orphan and no cross-user link
 *   1  a column is missing, or there are orphan or cross-user links
 *   2  pro_webhook_addresses is missing — the migration has not run
 *
 * Usage: php check_webhook_addresses.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$failures = 0;

function check(string $label, bool $condition): void {
    global $failures;
    if ($condition) {
        echo "[OK] $label\n";
    } else {
        echo "[FEL] $label\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------
// Schema presence
// ---------------------------------------------------------------------

$hasTable = tableHasColumn('pro_webhook_addresses', 'webhook_id');
$hasIncludeTemporary = tableHasColumn('pro_webhooks', 'include_temporary');
$hasHooksPaused = tableHasColumn('temp_emails', 'hooks_paused');

if (!$hasTable) {
    fwrite(STDERR, "pro_webhook_addresses is missing - run php migrate_webhook_addresses.php\n");
    exit(2);
}

echo "[OK] pro_webhook_addresses exists\n";
check('pro_webhooks.include_temporary exists', $hasIncludeTemporary);
check('temp_emails.hooks_paused exists', $hasHooksPaused);

// The columns are part of what "migrated" means, so a missing one is a failure
// like the two link checks below: the dispatcher cannot read a switch that is
// not there, and reporting the database as clean would be a lie. A half-migrated
// database is the state the migration's ordering exists to avoid, and re-running
// it fixes both columns.
if (!$hasIncludeTemporary || !$hasHooksPaused) {
    echo "[FEL] schema is half-migrated: re-run php migrate_webhook_addresses.php\n";
    $failures++;
}

// ---------------------------------------------------------------------
// Contents
// ---------------------------------------------------------------------

$stmt = $pdo->query("SELECT COUNT(*) FROM pro_webhook_addresses");
$links = (int)$stmt->fetchColumn();
echo "[OK] pro_webhook_addresses rows: {$links}\n";

if ($hasIncludeTemporary) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM pro_webhooks WHERE include_temporary = 1");
    echo "[OK] hooks with temporary addresses enabled: " . (int)$stmt->fetchColumn() . "\n";
} else {
    echo "[FEL] hooks with temporary addresses enabled: column missing, skipped\n";
}

if ($hasHooksPaused) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE hooks_paused = 1");
    echo "[OK] addresses with hooks paused: " . (int)$stmt->fetchColumn() . "\n";
} else {
    echo "[FEL] addresses with hooks paused: column missing, skipped\n";
}

// ---------------------------------------------------------------------
// Integrity
// ---------------------------------------------------------------------

// A link whose webhook row is gone, or whose address row is gone or is not
// personal (the table holds personal addresses only). The LEFT JOIN on
// is_personal = 1 folds "no row" and "no longer personal" into one count.
$stmt = $pdo->query("SELECT COUNT(*) FROM pro_webhook_addresses a
    LEFT JOIN pro_webhooks w ON w.id = a.webhook_id
    LEFT JOIN temp_emails t ON t.id = a.temp_email_id AND t.is_personal = 1
    WHERE w.id IS NULL OR t.id IS NULL");
$orphans = (int)$stmt->fetchColumn();
check('no orphan links', $orphans === 0);
if ($orphans > 0) {
    echo "     orphan links: {$orphans} (a webhook or personal address is gone)\n";
}

// A hook may only be linked to its own owner's addresses. This must be 0.
$stmt = $pdo->query("SELECT COUNT(*) FROM pro_webhook_addresses a
    JOIN pro_webhooks w ON w.id = a.webhook_id
    JOIN temp_emails t ON t.id = a.temp_email_id
    WHERE w.user_id <> t.pro_user_id");
$crossUser = (int)$stmt->fetchColumn();
check('no cross-user links', $crossUser === 0);
if ($crossUser > 0) {
    echo "     cross-user links: {$crossUser} (webhook owner <> address owner)\n";
}

echo "\n" . ($failures === 0 ? "Clean.\n" : "$failures problem(s) found.\n");
exit($failures === 0 ? 0 : 1);
