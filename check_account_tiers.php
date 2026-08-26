<?php

declare(strict_types=1);

/**
 * CLI smoke test for the account-type data model (migrate_account_types.php,
 * proUserAccountType() / proUserIsPro() in config.php), in the same spirit
 * as check_totp.php / check_directadmin_client.php.
 *
 * Usage: php check_account_tiers.php
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

$expectedColumns = ['account_type', 'email_verified_at', 'last_login_at', 'inactivity_warned_at'];
foreach ($expectedColumns as $column) {
    check("pro_users.{$column} exists", tableHasColumn('pro_users', $column));
}

// ---------------------------------------------------------------------
// Row counts per account_type
// ---------------------------------------------------------------------

if (tableHasColumn('pro_users', 'account_type')) {
    $stmt = $pdo->query("SELECT account_type, COUNT(*) AS c FROM pro_users GROUP BY account_type");
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $regularCount = (int)($counts['regular'] ?? 0);
    $proCount = (int)($counts['pro'] ?? 0);
    echo "[OK] account_type counts: pro={$proCount}, regular={$regularCount}\n";
} else {
    echo "[FEL] account_type counts: column missing, skipped\n";
    $failures++;
    $total++;
}

// ---------------------------------------------------------------------
// email_verified_at NULL count
// ---------------------------------------------------------------------

if (tableHasColumn('pro_users', 'email_verified_at')) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM pro_users WHERE email_verified_at IS NULL");
    $unverified = (int)$stmt->fetchColumn();
    echo "[OK] email_verified_at IS NULL: {$unverified} row(s)\n";
} else {
    echo "[FEL] email_verified_at IS NULL: column missing, skipped\n";
    $failures++;
    $total++;
}

// ---------------------------------------------------------------------
// proUserIsPro() / proUserAccountType() callable against a real row
// ---------------------------------------------------------------------

$stmt = $pdo->query("SELECT MIN(id) FROM pro_users");
$minId = $stmt->fetchColumn();

if ($minId === false || $minId === null) {
    echo "[OK] proUserIsPro()/proUserAccountType(): no pro_users rows, skipped\n";
} else {
    $userId = (int)$minId;
    $isPro = proUserIsPro($userId);
    $accountType = proUserAccountType($userId);
    check(
        "proUserIsPro({$userId}) and proUserAccountType({$userId}) are callable (isPro=" . ($isPro ? 'true' : 'false') . ", accountType={$accountType})",
        is_bool($isPro) && in_array($accountType, ['pro', 'regular'], true)
    );
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
