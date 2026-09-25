<?php

declare(strict_types=1);

/**
 * migrate_paddle_billing.php — CLI migration: creates the Paddle Billing
 * mirror tables that paddle_webhook.php writes (see paddle_sync.php for what
 * each one is for):
 *
 *   paddle_customers      customer id → email, for linking by email
 *   paddle_subscriptions  latest state per subscription + granted_until
 *   paddle_transactions   one-time purchases (the lifetime price)
 *   paddle_entitlements   per user: the pro_expires_at Paddle last wrote, and
 *                         the voucher baseline it may never go below
 *
 * No pro_users columns are added — entitlement stays pro_expires_at +
 * account_type, decided by proUserIsPro() as before.
 *
 * Upgrade step (stacking paid time on top of the trial): adds
 * paddle_entitlements.bonus_seconds, .last_target and .coverage_ended to a
 * table created by
 * the first version, backfills them for existing rows — bonus = the time the
 * account had left when Paddle took over (baseline_expires_at - updated_at),
 * last_target = the user's current Paddle target — and re-applies each of
 * those users so pro_expires_at reflects the stacked time.
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS, column checks). The DDL lives in
 * paddleSchemaStatements() so tests/paddle_sync_test.php builds the same
 * tables in SQLite.
 *
 * Usage: php migrate_paddle_billing.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paddle_sync.php';

foreach (paddleSchemaStatements('mysql') as $sql) {
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
    $pdo->exec($sql);
    echo "[ok] {$m[1]}\n";
}

if (tableHasColumn('paddle_entitlements', 'bonus_seconds')) {
    echo "[skip] paddle_entitlements.bonus_seconds/last_target/coverage_ended: already present\n";
    exit(0);
}

$pdo->exec("ALTER TABLE paddle_entitlements
    ADD COLUMN bonus_seconds INT NOT NULL DEFAULT 0,
    ADD COLUMN last_target DATETIME NULL,
    ADD COLUMN coverage_ended TINYINT(1) NOT NULL DEFAULT 0");
echo "[done] paddle_entitlements.bonus_seconds/last_target/coverage_ended: columns added\n";

$options = [
    'prices' => paddlePlanPriceIds(require __DIR__ . '/pricing_tiers.php'),
    'has_account_type' => tableHasColumn('pro_users', 'account_type'),
    'log' => function (string $level, string $message, array $context): void {
        logMessage($level, $message, $context);
    },
];

$rows = $pdo->query("SELECT pro_user_id, baseline_expires_at, updated_at FROM paddle_entitlements WHERE applied_lifetime = 0")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $userId = (int) $row['pro_user_id'];
    $bonus = $row['baseline_expires_at'] !== null
        ? max(0, strtotime($row['baseline_expires_at']) - strtotime($row['updated_at']))
        : 0;
    $target = paddleFetch($pdo, 'SELECT MAX(granted_until) AS m FROM paddle_subscriptions WHERE pro_user_id = ? AND granted_until IS NOT NULL', [$userId]);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE paddle_entitlements SET bonus_seconds = ?, last_target = ? WHERE pro_user_id = ?")
        ->execute([$bonus, $target['m'] ?? null, $userId]);
    $outcome = paddleApplyEntitlement($pdo, $userId, $options);
    $pdo->commit();
    echo "[backfill] user {$userId}: bonus {$bonus}s; {$outcome}\n";
}

exit(0);
