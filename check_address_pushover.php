<?php

declare(strict_types=1);

/**
 * CLI smoke test for the per-address Pushover state (#170,
 * migrate_address_pushover_state.php), in the same spirit as
 * check_account_tiers.php / check_address_feeds.php.
 *
 * Reports whether temp_emails.pushover_enabled exists and how many personal
 * addresses have it enabled. Read-only: writes nothing.
 *
 * Usage: php check_address_pushover.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$failures = 0;
$total = 0;

function check(string $label, bool $condition): void {
    global $failures, $total;
    $total++;
    if ($condition) {
        echo "[OK] $label\n";
    } else {
        echo "[FEL] $label\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------
// Column presence
// ---------------------------------------------------------------------

$hasColumn = tableHasColumn('temp_emails', 'pushover_enabled');
check('temp_emails.pushover_enabled exists', $hasColumn);

// ---------------------------------------------------------------------
// No Pushover credential may live on the address row
// ---------------------------------------------------------------------

$credentialColumns = [];
if ($hasColumn) {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'temp_emails' AND (COLUMN_NAME LIKE '%pushover%' OR COLUMN_NAME LIKE '%token%') AND COLUMN_NAME <> 'pushover_enabled' AND COLUMN_NAME <> 'feed_token'");
    $stmt->execute([$config['db']['name']]);
    $credentialColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
check('no Pushover credential column on temp_emails', $credentialColumns === []);

// ---------------------------------------------------------------------
// Enabled personal addresses
// ---------------------------------------------------------------------

if ($hasColumn) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1 AND pushover_enabled = 1");
    $enabled = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1");
    $personal = (int)$stmt->fetchColumn();

    echo "[OK] personal addresses with Pushover enabled: {$enabled} of {$personal}\n";
} else {
    echo "[FEL] personal addresses with Pushover enabled: column missing, skipped\n";
    $failures++;
    $total++;
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
