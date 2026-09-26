<?php

declare(strict_types=1);

/**
 * CLI smoke test for the per-address RSS feed data model (#160,
 * migrate_address_feed_tokens.php), in the same spirit as
 * check_account_tiers.php / check_totp.php.
 *
 * Reports whether temp_emails.feed_token exists, whether its unique key is
 * present, and how many personal addresses currently have a feed enabled.
 *
 * Extended for #315 (migrate_feed_token_encryption.php): also reports the
 * feed_token_hash/feed_token_enc columns and their unique keys on both
 * pro_users and temp_emails, rows whose token has no hash/enc pair yet (or
 * whose pair does not verify), and rows still holding a plaintext feed_token
 * once the pair covers it. Read-only: writes nothing, prints counts and ids
 * only, never a token.
 *
 * Usage: php check_address_feeds.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed_token.php';

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

// Since #315 feed_enabled follows feed_token_hash once it exists (see
// index.php's list_personal), so this count is taken the same way - it stays
// accurate through the --null-plaintext run, when feed_token itself goes NULL.
$hasHashColTemp = tableHasColumn('temp_emails', 'feed_token_hash');
$enabledCondition = $hasHashColTemp ? 'feed_token_hash IS NOT NULL' : 'feed_token IS NOT NULL';

if ($hasFeedColumn || $hasHashColTemp) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1 AND {$enabledCondition}");
    $enabled = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1");
    $personal = (int)$stmt->fetchColumn();

    echo "[OK] personal addresses with a feed enabled: {$enabled} of {$personal}\n";
} else {
    echo "[FEL] personal addresses with a feed enabled: column missing, skipped\n";
    $failures++;
    $total++;
}

// ---------------------------------------------------------------------
// #315: feed_token_hash / feed_token_enc on both tables
// ---------------------------------------------------------------------

/** Whether $table.$index exists, via information_schema (bound parameters). */
function feedTokenCheckIndexExists(PDO $pdo, array $config, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

foreach ([
    ['pro_users', 'id', 'uniq_pro_users_feed_token_hash'],
    ['temp_emails', 'id', 'uniq_temp_emails_feed_token_hash'],
] as [$table, $idColumn, $indexName]) {
    echo "\n-- {$table} --\n";

    $hasHash = tableHasColumn($table, 'feed_token_hash');
    $hasEnc = tableHasColumn($table, 'feed_token_enc');
    check("{$table}.feed_token_hash exists", $hasHash);
    check("{$table}.feed_token_enc exists", $hasEnc);

    try {
        check("{$indexName} exists", feedTokenCheckIndexExists($pdo, $config, $table, $indexName));
    } catch (Exception $e) {
        echo "[FEL] {$indexName} lookup failed: " . $e->getMessage() . "\n";
        $failures++;
        $total++;
    }

    if (!$hasHash || !$hasEnc) {
        echo "[info] {$table}: columns missing, skipping row-level checks\n";
        continue;
    }

    // Rows with a token but no pair yet, or a pair that does not verify -
    // exactly what migrate_feed_token_encryption.php's backfill fixes.
    $stmt = $pdo->query("SELECT {$idColumn} AS id, feed_token, feed_token_hash, feed_token_enc FROM {$table} WHERE feed_token IS NOT NULL");
    $missingPair = [];
    $stillPlaintext = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $token = (string)$row['feed_token'];
        $consistent = $row['feed_token_hash'] === feedTokenHash($token)
            && webhookSecretDecrypt($row['feed_token_enc']) === $token;
        if (!$consistent) {
            $missingPair[] = (int)$row['id'];
        } else {
            // The pair verifies but the plaintext is still there - fine before
            // --null-plaintext has run, worth flagging once it should have.
            $stillPlaintext[] = (int)$row['id'];
        }
    }
    check(
        "{$table}: every plaintext feed_token has a matching hash/enc pair",
        $missingPair === [],
        $missingPair === [] ? '' : count($missingPair) . ' row id(s) need migrate_feed_token_encryption.php: ' . implode(',', array_slice($missingPair, 0, 20))
    );
    if ($stillPlaintext !== []) {
        echo "[info] {$table}: " . count($stillPlaintext) . " row(s) still hold a plaintext feed_token whose pair already verifies - run migrate_feed_token_encryption.php --null-plaintext once the new code is verified\n";
    }
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
