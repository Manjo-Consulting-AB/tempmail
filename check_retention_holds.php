<?php

declare(strict_types=1);

/**
 * check_retention_holds.php — CLI diagnostics for the retention-hold table
 * (epic #369, migrate_retention_holds.php), in the same spirit as
 * check_address_feeds.php / check_vouchers.php.
 *
 * Reports whether retention_holds and its columns are present, how many
 * holds are active (ended_at IS NULL AND ends_at > NOW()), how many were
 * started in the last 365 days, and the two states that are never
 * legitimate: an account over RETENTION_HOLD_MAX_PER_YEAR holds in the last
 * 365 days, and an account with more than one active hold. Also counts
 * active holds whose account is no longer in pro_users.
 *
 * A hold is active while ended_at IS NULL AND ends_at > NOW(); every row
 * counts toward the yearly limit, including one ended early.
 *
 * Read-only: writes nothing, prints counts and ids only — never an address.
 * Tolerates a database the migration has not run on: the table missing exits
 * 2 without an exception.
 *
 * Usage: php check_retention_holds.php
 *
 * Exit codes: 0 clean, 1 inconsistent, 2 the table is missing (run the
 * migration).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$failures = 0;
$total = 0;

function retentionHoldCheck(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $total;
    $total++;
    if ($condition) {
        echo "[OK] $label\n";
    } else {
        echo "[FEL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failures++;
    }
}

/** Print up to $limit ids from a read-only id query, then how many were left out. */
function retentionHoldPrintIds(PDO $pdo, string $sql, int $limit = 50): void
{
    $ids = $pdo->query($sql . " LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
    echo '      id: ' . implode(', ', array_map('intval', $ids)) . "\n";

    $more = (int) $pdo->query("SELECT COUNT(*) FROM ({$sql}) more")->fetchColumn() - count($ids);
    if ($more > 0) {
        echo "      … and {$more} more\n";
    }
}

$hasTable = tableHasColumn('retention_holds', 'pro_user_id');

if (!$hasTable) {
    echo "[FEL] retention_holds exists — the table is missing\n";
    echo "\nRun: php migrate_retention_holds.php\n";
    exit(2);
}

$maxPerYear = (int) ($config['retention_hold']['max_per_year'] ?? 4);
$windowStart = date('Y-m-d H:i:s', strtotime('-365 days'));
$now = date('Y-m-d H:i:s');

// ---------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------

echo "-- schema --\n";

$columnsPresent = true;
foreach (['id', 'pro_user_id', 'started_at', 'ends_at', 'ended_at'] as $column) {
    $present = tableHasColumn('retention_holds', $column);
    retentionHoldCheck("retention_holds.{$column} exists", $present);
    $columnsPresent = $columnsPresent && $present;
}

// A partial table cannot be counted; report it as inconsistent rather than
// letting the queries below fail with a fatal error.
if (!$columnsPresent) {
    echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Counts
// ---------------------------------------------------------------------

echo "\n-- holds --\n";

$stmt = $pdo->prepare('SELECT COUNT(*) FROM retention_holds WHERE ended_at IS NULL AND ends_at > ?');
$stmt->execute([$now]);
$active = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM retention_holds WHERE started_at >= ?');
$stmt->execute([$windowStart]);
$lastYear = (int) $stmt->fetchColumn();

$totalRows = (int) $pdo->query('SELECT COUNT(*) FROM retention_holds')->fetchColumn();

echo "[OK] holds stored: {$totalRows}\n";
echo "[OK] active holds: {$active}\n";
echo "[OK] holds started in the last 365 days: {$lastYear}\n";

// ---------------------------------------------------------------------
// Consistency: the two states that are never legitimate.
// ---------------------------------------------------------------------

echo "\n-- consistency --\n";

$stmt = $pdo->prepare('SELECT COUNT(*) FROM (
    SELECT pro_user_id FROM retention_holds
    WHERE started_at >= ?
    GROUP BY pro_user_id
    HAVING COUNT(*) > ?
) over_limit');
$stmt->execute([$windowStart, $maxPerYear]);
$overLimit = (int) $stmt->fetchColumn();

retentionHoldCheck(
    "no account holds more than {$maxPerYear} holds in the last 365 days",
    $overLimit === 0,
    $overLimit . ' account(s) over the limit'
);
if ($overLimit > 0) {
    $stmt = $pdo->prepare('SELECT pro_user_id FROM retention_holds
        WHERE started_at >= ?
        GROUP BY pro_user_id
        HAVING COUNT(*) > ?
        ORDER BY pro_user_id');
    $stmt->execute([$windowStart, $maxPerYear]);
    echo '      pro_user_id: ' . implode(', ', array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))) . "\n";
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM (
    SELECT pro_user_id FROM retention_holds
    WHERE ended_at IS NULL AND ends_at > ?
    GROUP BY pro_user_id
    HAVING COUNT(*) > 1
) multi_active');
$stmt->execute([$now]);
$multiActive = (int) $stmt->fetchColumn();

retentionHoldCheck(
    'no account has more than one active hold',
    $multiActive === 0,
    $multiActive . ' account(s) with several active holds'
);
if ($multiActive > 0) {
    $stmt = $pdo->prepare('SELECT pro_user_id FROM retention_holds
        WHERE ended_at IS NULL AND ends_at > ?
        GROUP BY pro_user_id
        HAVING COUNT(*) > 1
        ORDER BY pro_user_id');
    $stmt->execute([$now]);
    echo '      pro_user_id: ' . implode(', ', array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))) . "\n";
}

// ---------------------------------------------------------------------
// Orphans: an active hold whose account is gone.
// ---------------------------------------------------------------------

if (tableHasColumn('pro_users', 'id')) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM retention_holds h
        LEFT JOIN pro_users u ON u.id = h.pro_user_id
        WHERE h.ended_at IS NULL AND h.ends_at > ? AND u.id IS NULL');
    $stmt->execute([$now]);
    $orphans = (int) $stmt->fetchColumn();

    echo "[OK] active holds whose account is gone: {$orphans}\n";
} else {
    // Fail-soft, like adminOverview(): a missing table blanks the figure
    // rather than aborting the audit.
    echo "[info] pro_users is missing, active holds whose account is gone: unknown\n";
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
