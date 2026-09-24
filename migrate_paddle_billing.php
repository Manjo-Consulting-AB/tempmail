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
 *                         the voucher/BMAC baseline it may never go below
 *
 * No pro_users columns are added — entitlement stays pro_expires_at +
 * account_type, decided by proUserIsPro() as before.
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). The DDL lives in
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

exit(0);
