<?php

declare(strict_types=1);

/**
 * CLI smoke test for the per-address RSS feed data model (#160,
 * migrate_address_feed_tokens.php), in the same spirit as
 * check_account_tiers.php / check_totp.php.
 *
 * Reports whether temp_emails.feed_token exists, whether its unique key is
 * present, and how many personal addresses currently have a feed enabled.
 * Read-only: writes nothing.
 *
 * Usage: php check_address_feeds.php
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

$hasFeedColumn = tableHasColumn('temp_emails', 'feed_token');
check('temp_emails.feed_token exists', $hasFeedColumn);

// ---------------------------------------------------------------------
// Unique key presence
// ---------------------------------------------------------------------

$hasUniqueKey = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'temp_emails' AND INDEX_NAME = 'uniq_temp_emails_feed_token'");
    $stmt->execute([$config['db']['name']]);
    $hasUniqueKey = (int)$stmt->fetchColumn() > 0;
} catch (Exception $e) {
    echo "[FEL] uniq_temp_emails_feed_token lookup failed: " . $e->getMessage() . "\n";
    $failures++;
    $total++;
}
check('uniq_temp_emails_feed_token exists', $hasUniqueKey);

// ---------------------------------------------------------------------
// Enabled personal feeds
// ---------------------------------------------------------------------

if ($hasFeedColumn) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1 AND feed_token IS NOT NULL");
    $enabled = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1");
    $personal = (int)$stmt->fetchColumn();

    echo "[OK] personal addresses with a feed enabled: {$enabled} of {$personal}\n";
} else {
    echo "[FEL] personal addresses with a feed enabled: column missing, skipped\n";
    $failures++;
    $total++;
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
