<?php

declare(strict_types=1);

/**
 * migrate_vouchers.php — CLI migration: the voucher schema (epic #359,
 * step 1/4; see check_vouchers.php for the read-only audit).
 *
 * vouchers and redemption_log exist in production but no script in the
 * repository created them, so this script brings both the missing tables and
 * the columns nothing has recorded so far into line:
 *
 *  - vouchers: the columns redeemVoucherForEmail() already reads (code,
 *    is_active, expires_at, max_uses, current_uses, duration_days) plus
 *    created_at, source, created_by_user_id, issuer_id, external_ref,
 *    batch_id and note. source tells a hand-inserted row ('legacy', the
 *    default every existing row gets) from one an admin made ('admin') or a
 *    future seller API issued ('issuer'); created_by_user_id is the admin's
 *    pro_users.id when source = 'admin' and issuer_id / external_ref are
 *    reserved for that seller API, always NULL in this epic. batch_id groups
 *    codes created together (step 4) and note is an internal admin note.
 *
 *  - redemption_log: user_id, voucher_id and redeemed_at. A fresh table gets
 *    redeemed_at NOT NULL DEFAULT CURRENT_TIMESTAMP; on an existing table it
 *    is added NULL, because a row already there has no recorded time and a
 *    made-up one would be worse than none.
 *
 * created_at is deliberately left NULL for existing rows (no fake backfill).
 * No column is ever dropped and no foreign key is added, like the rest of
 * the schema: the deletion paths handle the links themselves.
 *
 * Idempotent: every step is guarded by the table/column/index it creates, so
 * a second run reports [skip] everywhere and exits 0. Nothing reads the new
 * columns yet, so this step is inert once deployed, and
 * redeemVoucherForEmail() is untouched.
 *
 * If vouchers.code (or redemption_log(user_id, voucher_id)) already holds
 * duplicates the UNIQUE index cannot be created. That is not a half-done
 * migration: the duplicated row count (never a code) is printed, that one
 * index is skipped, everything else still runs, and the script exits 1 so
 * the operator can resolve the duplicates and re-run it.
 *
 * Prints counts and DDL outcomes only — never a voucher code.
 *
 * Usage: php migrate_vouchers.php [--dry-run]
 *   --dry-run  no CREATE, no ALTER: reports what would be done.
 *
 * Exit codes: 0 done (or nothing to do), 1 an error, or duplicate values
 * blocking a UNIQUE index.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$dryRun = in_array('--dry-run', $argv, true);
$failures = 0;

if (!function_exists('voucherMigrationIndexExists')) {
    /**
     * Whether $index exists on $table, via information_schema with bound
     * parameters, mirroring tableHasColumn() in config.php.
     */
    function voucherMigrationIndexExists(PDO $pdo, string $table, string $index): bool
    {
        global $config;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->execute([$config['db']['name'], $table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('voucherMigrationDuplicateCount')) {
    /**
     * How many rows share a value in the column(s) a UNIQUE index would cover
     * — the number of distinct duplicated keys, not of rows. Never selects the
     * value itself.
     */
    function voucherMigrationDuplicateCount(PDO $pdo, string $table, array $columns): int
    {
        $group = implode(', ', $columns);
        $stmt = $pdo->query("SELECT COUNT(*) FROM (SELECT {$group} FROM {$table} GROUP BY {$group} HAVING COUNT(*) > 1) dup");
        return (int) $stmt->fetchColumn();
    }
}

/**
 * Create one index unless it already exists. $uniqueColumns, when given, is
 * the column list a duplicate check runs over first; duplicates skip the
 * index (and count as a failure) but never abort the rest of the migration.
 */
function voucherMigrationAddIndex(PDO $pdo, string $table, string $index, string $ddl, array $uniqueColumns, bool $dryRun): void
{
    global $failures;

    if (voucherMigrationIndexExists($pdo, $table, $index)) {
        echo "[skip] {$table}.{$index}: index already exists\n";
        return;
    }

    if ($uniqueColumns !== []) {
        $duplicates = voucherMigrationDuplicateCount($pdo, $table, $uniqueColumns);
        if ($duplicates > 0) {
            echo "[varning] {$table}.{$index}: {$duplicates} duplicated key(s) — unique index skipped\n";
            echo "          Resolve the duplicates (ids in check_vouchers.php) and run this script again.\n";
            $failures++;
            return;
        }
    }

    if ($dryRun) {
        echo "[dry-run] {$table}.{$index}: would be added\n";
        return;
    }

    $pdo->exec($ddl);
    echo "[done] {$table}.{$index}: index added\n";
}

try {
    // -----------------------------------------------------------------
    // vouchers — the code redeemVoucherForEmail() looks up.
    // -----------------------------------------------------------------
    if (tableHasColumn('vouchers', 'id')) {
        echo "[skip] vouchers: table already exists\n";
    } elseif ($dryRun) {
        echo "[dry-run] vouchers: would be created\n";
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vouchers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            max_uses INT NULL,
            current_uses INT NOT NULL DEFAULT 0,
            duration_days INT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] vouchers: table created\n";
    }

    // -----------------------------------------------------------------
    // redemption_log — one row per redemption.
    // -----------------------------------------------------------------
    if (tableHasColumn('redemption_log', 'id')) {
        echo "[skip] redemption_log: table already exists\n";
    } elseif ($dryRun) {
        echo "[dry-run] redemption_log: would be created\n";
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS redemption_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            voucher_id INT NOT NULL,
            redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] redemption_log: table created\n";
    }

    // -----------------------------------------------------------------
    // vouchers: the columns nothing has recorded so far. Existing rows get
    // source = 'legacy' from the column default, as the epic requires.
    // -----------------------------------------------------------------
    if (tableHasColumn('vouchers', 'id')) {
        foreach ([
            'created_at' => 'DATETIME NULL',
            'source' => "VARCHAR(16) NOT NULL DEFAULT 'legacy'",
            'created_by_user_id' => 'INT NULL',
            'issuer_id' => 'INT NULL',
            'external_ref' => 'VARCHAR(128) NULL',
            'batch_id' => 'CHAR(16) NULL',
            'note' => 'VARCHAR(255) NULL',
        ] as $column => $definition) {
            if (tableHasColumn('vouchers', $column)) {
                echo "[skip] vouchers.{$column}: column already exists\n";
                continue;
            }
            if ($dryRun) {
                echo "[dry-run] vouchers.{$column}: would be added\n";
                continue;
            }
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN {$column} {$definition}");
            echo "[done] vouchers.{$column}: column added\n";
        }
    }

    // -----------------------------------------------------------------
    // redemption_log.redeemed_at — nullable when added to an existing table:
    // rows already there have no recorded time.
    // -----------------------------------------------------------------
    if (tableHasColumn('redemption_log', 'id') && !tableHasColumn('redemption_log', 'redeemed_at')) {
        if ($dryRun) {
            echo "[dry-run] redemption_log.redeemed_at: would be added\n";
        } else {
            $pdo->exec("ALTER TABLE redemption_log ADD COLUMN redeemed_at DATETIME NULL");
            echo "[done] redemption_log.redeemed_at: column added\n";
        }
    } elseif (tableHasColumn('redemption_log', 'redeemed_at')) {
        echo "[skip] redemption_log.redeemed_at: column already exists\n";
    }

    // -----------------------------------------------------------------
    // Indexes. The two UNIQUE ones check for duplicates first.
    // -----------------------------------------------------------------
    if (tableHasColumn('vouchers', 'id')) {
        voucherMigrationAddIndex(
            $pdo,
            'vouchers',
            'uniq_vouchers_code',
            'ALTER TABLE vouchers ADD UNIQUE KEY uniq_vouchers_code (code)',
            ['code'],
            $dryRun
        );
        voucherMigrationAddIndex(
            $pdo,
            'vouchers',
            'uniq_vouchers_issuer_ref',
            'ALTER TABLE vouchers ADD UNIQUE KEY uniq_vouchers_issuer_ref (issuer_id, external_ref)',
            [],
            $dryRun
        );
        voucherMigrationAddIndex(
            $pdo,
            'vouchers',
            'idx_vouchers_batch',
            'ALTER TABLE vouchers ADD KEY idx_vouchers_batch (batch_id)',
            [],
            $dryRun
        );
    }

    if (tableHasColumn('redemption_log', 'id')) {
        voucherMigrationAddIndex(
            $pdo,
            'redemption_log',
            'idx_redemption_log_voucher',
            'ALTER TABLE redemption_log ADD KEY idx_redemption_log_voucher (voucher_id)',
            [],
            $dryRun
        );
        voucherMigrationAddIndex(
            $pdo,
            'redemption_log',
            'uniq_redemption_log_user_voucher',
            'ALTER TABLE redemption_log ADD UNIQUE KEY uniq_redemption_log_user_voucher (user_id, voucher_id)',
            ['user_id', 'voucher_id'],
            $dryRun
        );
    }
} catch (Exception $e) {
    // A MySQL error can quote the offending value — "Duplicate entry 'CODE'
    // for key ..." — so the quoted part is masked: this script must never
    // print a voucher code.
    $message = preg_replace("/'[^']*'/", "'…'", $e->getMessage()) ?? $e->getMessage();
    fwrite(STDERR, "ERROR: " . $message . "\n");
    exit(1);
}

if ($failures > 0) {
    fwrite(STDERR, "Done with {$failures} unresolved problem(s) — see above.\n");
    exit(1);
}

echo $dryRun ? "Dry run complete: nothing was written.\n" : "Nothing else to do.\n";
exit(0);
