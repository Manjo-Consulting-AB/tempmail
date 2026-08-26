<?php

declare(strict_types=1);

/**
 * migrate_account_types.php — CLI migration: adds the account-type columns
 * to pro_users and backfills existing rows.
 *
 * pro_users today has no notion of account type: every row is implicitly
 * "Pro" (see documentaion/ACCOUNT_TIERS.md §2). This adds:
 *   - account_type          ENUM('regular','pro') NOT NULL DEFAULT 'regular'
 *   - email_verified_at     DATETIME NULL
 *   - last_login_at         DATETIME NULL
 *   - inactivity_warned_at  DATETIME NULL
 *
 * Backfill (only runs the moment a column is created, so a later manual
 * downgrade of a user is never undone by a second run of this script):
 *   - account_type of every existing row is set to 'pro'
 *   - email_verified_at of every existing row is set to NOW()
 *
 * Idempotent: checks `SHOW COLUMNS ... LIKE` before each ALTER TABLE, same
 * pattern as pro_auth.php's password_changed_at handling. Running this
 * script a second time does nothing and exits 0.
 *
 * CLI-only, same guard as parse.php.
 *
 * Usage: php migrate_account_types.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

/**
 * Checks for a column the same way pro_auth.php:740 does: SHOW COLUMNS ...
 * LIKE with the column name interpolated into the query string, not bound
 * as a parameter. MariaDB throws a syntax error on `SHOW COLUMNS ... LIKE ?`
 * (fixed once already in this repo, see commit d86a210), and the column
 * names here are fixed string literals defined in this file, not user input.
 */
function migrationColumnExists(PDO $pdo, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM pro_users LIKE '{$column}'");
    return $stmt->rowCount() > 0;
}

$columns = [
    'account_type' => [
        'ddl' => "ALTER TABLE pro_users ADD COLUMN account_type ENUM('regular','pro') NOT NULL DEFAULT 'regular'",
        'backfill' => "UPDATE pro_users SET account_type = 'pro' WHERE account_type <> 'pro'",
    ],
    'email_verified_at' => [
        'ddl' => "ALTER TABLE pro_users ADD COLUMN email_verified_at DATETIME NULL",
        'backfill' => "UPDATE pro_users SET email_verified_at = NOW() WHERE email_verified_at IS NULL",
    ],
    'last_login_at' => [
        'ddl' => "ALTER TABLE pro_users ADD COLUMN last_login_at DATETIME NULL",
        'backfill' => null,
    ],
    'inactivity_warned_at' => [
        'ddl' => "ALTER TABLE pro_users ADD COLUMN inactivity_warned_at DATETIME NULL",
        'backfill' => null,
    ],
];

$didWork = false;

foreach ($columns as $column => $spec) {
    if (migrationColumnExists($pdo, $column)) {
        echo "[skip] {$column}: already exists\n";
        continue;
    }

    $pdo->exec($spec['ddl']);
    echo "[done] {$column}: column added\n";
    $didWork = true;

    if ($spec['backfill'] !== null) {
        $affected = $pdo->exec($spec['backfill']);
        echo "[done] {$column}: backfilled {$affected} row(s)\n";
    }
}

if (!$didWork) {
    echo "Nothing to do.\n";
}

exit(0);
