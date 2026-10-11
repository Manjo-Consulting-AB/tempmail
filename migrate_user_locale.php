<?php

declare(strict_types=1);

/**
 * migrate_user_locale.php — CLI migration for the account language
 * (interface languages, phase 3, epic #428 step 1/8).
 *
 * Adds pro_users.locale VARCHAR(8) NULL DEFAULT NULL: the language an account
 * chose or was created in. No backfill — NULL means "not chosen", and the app
 * then follows the browser's Accept-Language. Nothing needs the column to
 * exist (i18nAccountLocale() and proUserSetLocale() are fail-open), so deploy
 * order does not matter. Idempotent: a second run prints only a [skip] line.
 *
 * Usage: php migrate_user_locale.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

try {
    if (tableHasColumn('pro_users', 'locale')) {
        echo "[skip] pro_users.locale: already exists\n";
    } else {
        $pdo->exec("ALTER TABLE pro_users ADD COLUMN locale VARCHAR(8) NULL DEFAULT NULL");
        echo "[done] pro_users.locale: column added\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
