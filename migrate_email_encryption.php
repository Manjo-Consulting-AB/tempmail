<?php

declare(strict_types=1);

/**
 * migrate_email_encryption.php — CLI migration: users' registered email
 * addresses at rest, phase A (see pii_crypto.php).
 *
 * Adds, next to the plaintext email column:
 *   pro_users         email_enc TEXT NULL, email_hash CHAR(64) NULL
 *                     (ascii_bin: hex only, exact byte comparison),
 *                     UNIQUE KEY uniq_pro_users_email_hash (email_hash)
 *   paddle_customers  the same two columns,
 *                     KEY idx_paddle_customers_email_hash (email_hash)
 *                     (not unique: the mirror is keyed on Paddle's customer
 *                     id, and nothing guarantees one customer per address)
 *
 * then fills them from the plaintext column. Every row is checked, not only
 * those with email_hash IS NULL: a row whose pair is missing, does not match
 * its current address or does not decrypt is (re)written, so a re-run also
 * repairs anything a failed dual-write left behind, and a re-run on a
 * consistent table writes nothing. Every new pair is decrypted again and
 * compared with the plaintext before it is written. Nothing reads the new
 * columns yet, so this can run before or after the dual-write code is
 * deployed; run it (again) after, so rows created in between are covered.
 *
 * Refuses to run (exit 2) without PII_ENCRYPTION_KEY and PII_INDEX_KEY (each
 * at least 32 characters). Those keys must never be rotated without a
 * re-encryption script. Before writing anything it refuses (exit 1) when two
 * pro_users rows would get the same email_hash (the unique key would fail
 * half-way). Prints row ids and counts only, never an address.
 *
 * Usage: php migrate_email_encryption.php [--dry-run]
 *   --dry-run  no ALTER, no UPDATE: reports what would be done.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pii_crypto.php';

$dryRun = in_array('--dry-run', $argv, true);

if (!piiKeysConfigured()) {
    fwrite(STDERR, "PII_ENCRYPTION_KEY and PII_INDEX_KEY must both be set (at least 32 characters each). Set them first.\n");
    exit(2);
}

function emailEncTableExists(PDO $pdo, string $table): bool
{
    global $config;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$config['db']['name'], $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function emailEncIndexExists(PDO $pdo, string $table, string $index): bool
{
    global $config;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Adds the columns and the index; returns whether the columns exist afterwards (false only in a dry run). */
function emailEncSchema(PDO $pdo, string $table, string $indexName, bool $unique, bool $dryRun): bool
{
    // Identifiers come from the fixed list at the bottom of this file, never from input.
    $ddl = [
        'email_enc' => "ALTER TABLE {$table} ADD COLUMN email_enc TEXT NULL DEFAULT NULL",
        'email_hash' => "ALTER TABLE {$table} ADD COLUMN email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL",
    ];
    $haveColumns = true;
    foreach ($ddl as $column => $sql) {
        if (tableHasColumn($table, $column)) {
            echo "[skip] {$table}.{$column}: column already exists\n";
            continue;
        }
        if ($dryRun) {
            echo "[dry-run] {$table}.{$column}: would add column\n";
            $haveColumns = false;
            continue;
        }
        $pdo->exec($sql);
        echo "[done] {$table}.{$column}: column added\n";
    }

    if (emailEncIndexExists($pdo, $table, $indexName)) {
        echo "[skip] {$indexName}: index already exists\n";
    } elseif ($dryRun) {
        echo "[dry-run] {$indexName}: would add " . ($unique ? 'unique ' : '') . "key\n";
    } else {
        $pdo->exec("ALTER TABLE {$table} ADD " . ($unique ? 'UNIQUE KEY' : 'KEY') . " {$indexName} (email_hash)");
        echo "[done] {$indexName}: " . ($unique ? 'unique ' : '') . "key added\n";
    }
    return $haveColumns;
}

/** Fills email_enc/email_hash for every row that needs it. Exits 1 on a failed check. */
function emailEncBackfill(PDO $pdo, string $table, string $idColumn, bool $unique, bool $haveColumns, bool $dryRun): void
{
    $select = $haveColumns
        ? "SELECT {$idColumn} AS id, email, email_enc, email_hash FROM {$table}"
        : "SELECT {$idColumn} AS id, email, NULL AS email_enc, NULL AS email_hash FROM {$table}";
    $rows = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);

    // Pass 1: decide, and check the unique key would hold, before any write.
    $plan = [];
    $seen = [];
    $ok = 0;
    $empty = 0;
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        $email = (string) ($row['email'] ?? '');
        if ($email === '') {
            $empty++;
            continue;
        }
        $expected = piiEmailHash($email);
        if ($expected === null) {
            fwrite(STDERR, "{$table} {$id}: could not compute email_hash - stopping, nothing written\n");
            exit(1);
        }
        if ($unique) {
            if (isset($seen[$expected])) {
                fwrite(STDERR, "{$table} {$id} and {$seen[$expected]}: same address after trim+lowercase - the unique key would fail. Stopping, nothing written.\n");
                exit(1);
            }
            $seen[$expected] = $id;
        }
        if ($row['email_hash'] === $expected && piiEmailDecrypt($row['email_enc']) === $email) {
            $ok++;
            continue;
        }
        $fields = piiEmailFields($email);
        // Never write a value that would not open again.
        if ($fields === null || $fields['email_hash'] !== $expected || piiEmailDecrypt($fields['email_enc']) !== $email) {
            fwrite(STDERR, "{$table} {$id}: round-trip check failed - stopping, nothing written\n");
            exit(1);
        }
        $plan[] = [$id, $email, $fields];
    }

    // Pass 2: write. "AND email = ?" skips a row whose address changed since pass 1.
    $written = 0;
    $changed = 0;
    $update = $dryRun ? null : $pdo->prepare("UPDATE {$table} SET email_enc = ?, email_hash = ? WHERE {$idColumn} = ? AND email = ?");
    foreach ($plan as [$id, $email, $fields]) {
        if ($update !== null) {
            $update->execute([$fields['email_enc'], $fields['email_hash'], $id, $email]);
            if ($update->rowCount() === 0) {
                echo "{$table} {$id}: address changed meanwhile - skipped, re-run to cover it\n";
                $changed++;
                continue;
            }
        }
        echo "{$table} {$id}: " . ($dryRun ? 'would be filled' : 'filled') . "\n";
        $written++;
    }

    printf("%s: %d row(s): %d %s, %d already consistent, %d without an address%s.\n",
        $table, count($rows), $written, $dryRun ? 'to fill (dry run)' : 'filled', $ok, $empty,
        $changed > 0 ? ", {$changed} skipped (changed meanwhile)" : '');
}

// Table, id column, index name, unique?
$targets = [
    ['pro_users', 'id', 'uniq_pro_users_email_hash', true],
    ['paddle_customers', 'customer_id', 'idx_paddle_customers_email_hash', false],
];

foreach ($targets as [$table, $idColumn, $indexName, $unique]) {
    if (!emailEncTableExists($pdo, $table)) {
        echo "[skip] {$table}: table does not exist\n";
        continue;
    }
    $haveColumns = emailEncSchema($pdo, $table, $indexName, $unique, $dryRun);
    emailEncBackfill($pdo, $table, $idColumn, $unique, $haveColumns, $dryRun);
}

exit(0);
