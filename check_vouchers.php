<?php

declare(strict_types=1);

/**
 * check_vouchers.php — CLI diagnostics for the voucher schema (epic #359,
 * migrate_vouchers.php), in the same spirit as check_oauth.php /
 * check_mcp_tokens.php.
 *
 * Reports whether vouchers and redemption_log and every column and index the
 * migration adds are present, counts the vouchers by source and by state
 * (active / inactive / past expires_at / fully used / lifetime), and lists the
 * two states that are never legitimate: a voucher whose current_uses differs
 * from the number of redemption_log rows for it, and a redemption_log row
 * pointing at a voucher that does not exist.
 *
 * Read-only: writes nothing, prints counts and ids only — never a voucher
 * code. Tolerates a database the migration has not run on: both tables
 * missing exits 2 without an exception.
 *
 * Usage: php check_vouchers.php
 *
 * Exit codes: 0 clean, 1 the schema is partial or the counts disagree,
 * 2 the tables are missing (run the migration).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$failures = 0;
$total = 0;

function voucherCheck(string $label, bool $condition, string $detail = ''): void
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

/** Whether $index exists on $table, via information_schema (bound parameters). */
function voucherCheckIndexExists(PDO $pdo, array $config, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Print up to $limit ids from a read-only id query, then how many were left
 * out. Ids only — never a voucher code.
 */
function voucherCheckPrintIds(PDO $pdo, string $sql, int $limit = 50): void
{
    $ids = $pdo->query($sql . " LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
    echo '      id: ' . implode(', ', array_map('intval', $ids)) . "\n";

    $more = (int) $pdo->query("SELECT COUNT(*) FROM ({$sql}) more")->fetchColumn() - count($ids);
    if ($more > 0) {
        echo "      … and {$more} more\n";
    }
}

$hasVouchers = tableHasColumn('vouchers', 'id');
$hasRedemptions = tableHasColumn('redemption_log', 'id');

if (!$hasVouchers && !$hasRedemptions) {
    echo "[FEL] vouchers / redemption_log exist — both are missing\n";
    echo "\nRun: php migrate_vouchers.php\n";
    exit(2);
}

// ---------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------

echo "-- schema --\n";

voucherCheck('vouchers exists', $hasVouchers);
voucherCheck('redemption_log exists', $hasRedemptions);

if ($hasVouchers) {
    foreach ([
        'id', 'code', 'is_active', 'expires_at', 'max_uses', 'current_uses', 'duration_days',
        'created_at', 'source', 'created_by_user_id', 'issuer_id', 'external_ref', 'batch_id', 'note',
    ] as $column) {
        voucherCheck("vouchers.{$column} exists", tableHasColumn('vouchers', $column));
    }
    foreach (['uniq_vouchers_code', 'uniq_vouchers_issuer_ref', 'idx_vouchers_batch'] as $index) {
        voucherCheck("vouchers.{$index} exists", voucherCheckIndexExists($pdo, $config, 'vouchers', $index));
    }
}

if ($hasRedemptions) {
    foreach (['id', 'user_id', 'voucher_id', 'redeemed_at'] as $column) {
        voucherCheck("redemption_log.{$column} exists", tableHasColumn('redemption_log', $column));
    }
    foreach (['idx_redemption_log_voucher', 'uniq_redemption_log_user_voucher'] as $index) {
        voucherCheck("redemption_log.{$index} exists", voucherCheckIndexExists($pdo, $config, 'redemption_log', $index));
    }
}

// ---------------------------------------------------------------------
// Counts
// ---------------------------------------------------------------------

echo "\n-- vouchers --\n";

if ($hasVouchers) {
    $stored = (int) $pdo->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
    echo "[OK] vouchers stored: {$stored}\n";

    if (tableHasColumn('vouchers', 'source')) {
        $stmt = $pdo->query('SELECT source, COUNT(*) AS n FROM vouchers GROUP BY source ORDER BY source');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "[OK] source " . ($row['source'] === null ? '(null)' : $row['source']) . ": " . (int) $row['n'] . "\n";
        }
    }

    $nowStr = date('Y-m-d H:i:s');

    $active = tableHasColumn('vouchers', 'is_active')
        ? (int) $pdo->query('SELECT COUNT(*) FROM vouchers WHERE is_active = 1')->fetchColumn()
        : null;
    $inactive = tableHasColumn('vouchers', 'is_active')
        ? (int) $pdo->query('SELECT COUNT(*) FROM vouchers WHERE is_active = 0')->fetchColumn()
        : null;

    $expired = null;
    if (tableHasColumn('vouchers', 'expires_at')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM vouchers WHERE expires_at IS NOT NULL AND expires_at <= ?');
        $stmt->execute([$nowStr]);
        $expired = (int) $stmt->fetchColumn();
    }

    $fullyUsed = null;
    if (tableHasColumn('vouchers', 'max_uses') && tableHasColumn('vouchers', 'current_uses')) {
        $fullyUsed = (int) $pdo->query('SELECT COUNT(*) FROM vouchers WHERE max_uses IS NOT NULL AND current_uses >= max_uses')->fetchColumn();
    }

    $lifetime = tableHasColumn('vouchers', 'duration_days')
        ? (int) $pdo->query('SELECT COUNT(*) FROM vouchers WHERE duration_days IS NULL')->fetchColumn()
        : null;

    echo '[OK] active: ' . ($active ?? '?') . ', inactive: ' . ($inactive ?? '?')
        . ', past expires_at: ' . ($expired ?? '?')
        . ', fully used: ' . ($fullyUsed ?? '?')
        . ', lifetime (duration_days IS NULL): ' . ($lifetime ?? '?') . "\n";
}

// ---------------------------------------------------------------------
// Consistency: the two states that are never legitimate.
// ---------------------------------------------------------------------

echo "\n-- consistency --\n";

if ($hasVouchers && $hasRedemptions
    && tableHasColumn('vouchers', 'id') && tableHasColumn('vouchers', 'current_uses')
    && tableHasColumn('redemption_log', 'voucher_id')) {
    $mismatched = (int) $pdo->query('SELECT COUNT(*) FROM vouchers v
        LEFT JOIN (SELECT voucher_id, COUNT(*) AS n FROM redemption_log GROUP BY voucher_id) r ON r.voucher_id = v.id
        WHERE v.current_uses <> COALESCE(r.n, 0)')->fetchColumn();
    voucherCheck(
        'every voucher\'s current_uses matches its redemption_log rows',
        $mismatched === 0,
        $mismatched . ' voucher(s) disagree'
    );
    if ($mismatched > 0) {
        voucherCheckPrintIds($pdo, 'SELECT v.id FROM vouchers v
            LEFT JOIN (SELECT voucher_id, COUNT(*) AS n FROM redemption_log GROUP BY voucher_id) r ON r.voucher_id = v.id
            WHERE v.current_uses <> COALESCE(r.n, 0)
            ORDER BY v.id');
    }
}

if ($hasVouchers && $hasRedemptions && tableHasColumn('redemption_log', 'voucher_id')) {
    $orphans = (int) $pdo->query('SELECT COUNT(*) FROM redemption_log WHERE voucher_id NOT IN (SELECT id FROM vouchers)')->fetchColumn();
    voucherCheck(
        'every redemption_log row points at a stored voucher',
        $orphans === 0,
        $orphans . ' row(s) point at a missing voucher'
    );
    if ($orphans > 0) {
        voucherCheckPrintIds($pdo, 'SELECT id FROM redemption_log WHERE voucher_id NOT IN (SELECT id FROM vouchers) ORDER BY id');
    }
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
