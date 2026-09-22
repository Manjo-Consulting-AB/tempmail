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

$enabled = 0;
$personal = 0;

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

// ---------------------------------------------------------------------
// Lifecycle: the address flags against the global Pushover configuration (#175)
// ---------------------------------------------------------------------
//
// The flag and the webhook config are independent by design. Deleting the last
// Pushover webhook leaves every address flag exactly as it was — the flags go
// inert, because there is no hook left to queue — and a webhook created later
// picks the still-enabled addresses up again with no re-enabling. Pausing is
// likewise a change to pro_webhooks.filter_mode only. Every state below is a
// legitimate one, so this section reports instead of asserting; it exists so
// the lifecycle scenarios in #175 can be read off a live server without
// changing anything.

$pushoverHooks = ['all' => 0, 'paused' => 0];
$hookReadFailed = false;
try {
    $stmt = $pdo->query("SELECT filter_mode, COUNT(*) FROM pro_webhooks WHERE kind = 'pushover' GROUP BY filter_mode");
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
        $pushoverHooks[(string)$r[0]] = (int)$r[1];
    }
} catch (Exception $e) {
    $hookReadFailed = true;
}

check('read Pushover webhooks', !$hookReadFailed);

if (!$hookReadFailed) {
    echo "[OK] Pushover webhooks: {$pushoverHooks['all']} active, {$pushoverHooks['paused']} paused\n";
    if ($pushoverHooks['all'] === 0 && $pushoverHooks['paused'] === 0) {
        echo "[OK] no Pushover configuration: {$enabled} stored address flag(s), inert (#175)\n";
    } elseif ($pushoverHooks['all'] === 0) {
        echo "[OK] Pushover configuration paused: {$enabled} stored address flag(s), nothing dispatched (#175)\n";
    } else {
        echo "[OK] Pushover configuration active: enabled address flags are dispatched for (#175)\n";
    }
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
