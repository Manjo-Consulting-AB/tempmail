<?php

declare(strict_types=1);

/**
 * migrate_trial_claims.php — CLI migration: the trial-claim table backing
 * the 60-day Pro trial (epic #267, step 1/4).
 *
 * Adds pro_trial_claims (email_hash CHAR(64) primary key, first_seen_at),
 * exactly the CREATE TABLE in the epic's Data model section. No runtime
 * code reads or writes this table yet (recording and granting are step 2),
 * so this migration changes no behaviour by itself.
 *
 * The table deliberately has NO foreign key and no other link to
 * pro_users: a trial claim must survive account deletion, because the
 * whole point of this feature is that deleting an account and signing up
 * again with the same address does not restart the trial. Nothing in this
 * repository's deletion paths (pro_auth.php account deletion,
 * cleanupInactiveRegularAccounts()) touches this table, and nothing ever
 * should.
 *
 * There is deliberately NO BACKFILL (decision 6): every existing account
 * is internal with lifetime Pro, so no claim rows are created for them.
 * Rows are only ever inserted going forward, on an address' first proven
 * verification.
 *
 * PRO_TRIAL_HASH_KEY must be set to at least 32 characters before step 2
 * is deployed, and must never be rotated afterwards — a new key makes
 * every stored hash unmatchable, silently letting every address claim a
 * trial again. This script does not check the key: it only creates the
 * table, so the schema can be deployed independently of the key being in
 * place (see the epic's Deploy order section).
 *
 * Idempotent: guarded by the existence of pro_trial_claims.email_hash, so
 * running this script a second time does nothing and exits 0.
 *
 * CLI-only, same guard as migrate_webhook_addresses.php.
 *
 * Usage: php migrate_trial_claims.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

try {
    if (tableHasColumn('pro_trial_claims', 'email_hash')) {
        echo "[skip] pro_trial_claims: table already exists\n";
        echo "Nothing to do.\n";
    } else {
        $pdo->exec("CREATE TABLE pro_trial_claims (
            email_hash CHAR(64) NOT NULL,
            first_seen_at DATETIME NOT NULL,
            PRIMARY KEY (email_hash),
            KEY idx_ptc_first_seen (first_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] pro_trial_claims: table created\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
