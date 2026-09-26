<?php

declare(strict_types=1);

/**
 * migrate_feed_token_encryption.php — CLI migration: RSS feed tokens at
 * rest, #315 (see feed_token.php for the design).
 *
 * Adds, next to each table's plaintext feed_token column:
 *   pro_users     feed_token_hash CHAR(64) NULL (ascii_bin: hex only, exact
 *                 byte comparison), UNIQUE KEY uniq_pro_users_feed_token_hash
 *   temp_emails   the same two columns,
 *                 UNIQUE KEY uniq_temp_emails_feed_token_hash
 *
 * then fills them from the plaintext column. Every row with a non-NULL
 * feed_token is checked, not only those with feed_token_hash IS NULL: a row
 * whose pair is missing, does not match its current token, or does not
 * decrypt is (re)written, so a re-run also repairs anything a failed
 * dual-write left behind, and a re-run on a consistent table writes nothing.
 * Every new pair is decrypted again and compared with the token before it is
 * written.
 *
 * --null-plaintext (run separately, after the new code is verified in
 * production): sets feed_token = NULL wherever the hash/enc pair is present
 * and consistent. Until that run a rollback of the code still works, because
 * the plaintext column is still there.
 *
 * Refuses to run (exit 2) without WEBHOOKS_KEY. Unlike migrate_email_encryption.php
 * there is no plausible hash collision to guard against before writing: the
 * token itself is already 256 bits of random entropy (feedTokenHash() is
 * unkeyed on purpose, see feed_token.php), so two distinct tokens colliding
 * is not a realistic migration-time risk the way two people's addresses
 * normalising to the same string is. Prints row ids and counts only, never a
 * token.
 *
 * Usage: php migrate_feed_token_encryption.php [--dry-run] [--null-plaintext]
 *   --dry-run         no ALTER, no UPDATE: reports what would be done.
 *   --null-plaintext  a separate pass that nulls out the plaintext column
 *                     wherever the hash/enc pair already covers it. Combine
 *                     with --dry-run to preview it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed_token.php';

$dryRun = in_array('--dry-run', $argv, true);
$nullPlaintext = in_array('--null-plaintext', $argv, true);

if (webhookSecretKey() === null) {
    fwrite(STDERR, "WEBHOOKS_KEY must be set. Set it first.\n");
    exit(2);
}

/**
 * SQLite has no information_schema, so tests/feed_token_test.php can run
 * this same script's schema step against it - this and the two functions
 * below branch on the driver rather than assuming MySQL, unlike the rest of
 * the codebase's check_*.php/migrate_*.php scripts, which only ever run
 * against the real (MySQL) database.
 */
function feedTokenMigrationIsSqlite(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function feedTokenMigrationTableExists(PDO $pdo, string $table): bool
{
    if (feedTokenMigrationIsSqlite($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }
    global $config;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$config['db']['name'], $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function feedTokenMigrationIndexExists(PDO $pdo, string $table, string $index): bool
{
    if (feedTokenMigrationIsSqlite($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?");
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
    global $config;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Adds the columns and the unique index; returns whether the columns exist afterwards (false only in a dry run). */
function feedTokenMigrationSchema(PDO $pdo, string $table, string $indexName, bool $dryRun): bool
{
    $isSqlite = feedTokenMigrationIsSqlite($pdo);
    $ddl = [
        'feed_token_hash' => $isSqlite
            ? "ALTER TABLE {$table} ADD COLUMN feed_token_hash TEXT NULL DEFAULT NULL"
            : "ALTER TABLE {$table} ADD COLUMN feed_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL",
        'feed_token_enc' => "ALTER TABLE {$table} ADD COLUMN feed_token_enc TEXT NULL DEFAULT NULL",
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

    if (feedTokenMigrationIndexExists($pdo, $table, $indexName)) {
        echo "[skip] {$indexName}: index already exists\n";
    } elseif ($dryRun) {
        echo "[dry-run] {$indexName}: would add unique key\n";
    } else {
        $pdo->exec($isSqlite
            ? "CREATE UNIQUE INDEX {$indexName} ON {$table} (feed_token_hash)"
            : "ALTER TABLE {$table} ADD UNIQUE KEY {$indexName} (feed_token_hash)");
        echo "[done] {$indexName}: unique key added\n";
    }
    return $haveColumns;
}

/** Fills feed_token_hash/feed_token_enc for every row that needs it. */
function feedTokenMigrationBackfill(PDO $pdo, string $table, string $idColumn, bool $haveColumns, bool $dryRun): void
{
    $select = $haveColumns
        ? "SELECT {$idColumn} AS id, feed_token, feed_token_hash, feed_token_enc FROM {$table} WHERE feed_token IS NOT NULL"
        : "SELECT {$idColumn} AS id, feed_token, NULL AS feed_token_hash, NULL AS feed_token_enc FROM {$table} WHERE feed_token IS NOT NULL";
    $rows = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);

    // Pass 1: decide, checking every new pair decrypts back before any write.
    $plan = [];
    $ok = 0;
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        $token = (string) $row['feed_token'];
        $expected = feedTokenHash($token);
        if ($row['feed_token_hash'] === $expected && webhookSecretDecrypt($row['feed_token_enc']) === $token) {
            $ok++;
            continue;
        }
        $enc = webhookSecretEncrypt($token);
        // Never write a value that would not open again.
        if ($enc === null || webhookSecretDecrypt($enc) !== $token) {
            fwrite(STDERR, "{$table} {$id}: round-trip check failed - stopping, nothing written\n");
            exit(1);
        }
        $plan[] = [$id, $token, $expected, $enc];
    }

    // Pass 2: write. "AND feed_token = ?" skips a row whose token changed since pass 1.
    $written = 0;
    $changed = 0;
    $update = $dryRun ? null : $pdo->prepare("UPDATE {$table} SET feed_token_hash = ?, feed_token_enc = ? WHERE {$idColumn} = ? AND feed_token = ?");
    foreach ($plan as [$id, $token, $hash, $enc]) {
        if ($update !== null) {
            $update->execute([$hash, $enc, $id, $token]);
            if ($update->rowCount() === 0) {
                echo "{$table} {$id}: token changed meanwhile - skipped, re-run to cover it\n";
                $changed++;
                continue;
            }
        }
        echo "{$table} {$id}: " . ($dryRun ? 'would be filled' : 'filled') . "\n";
        $written++;
    }

    printf("%s: %d row(s) with a token: %d %s, %d already consistent%s.\n",
        $table, count($rows), $written, $dryRun ? 'to fill (dry run)' : 'filled', $ok,
        $changed > 0 ? ", {$changed} skipped (changed meanwhile)" : '');
}

/**
 * --null-plaintext pass: nulls feed_token wherever feed_token_hash/feed_token_enc
 * already cover it consistently. Never nulls a row whose pair does not verify -
 * that row is left for a re-run of the backfill above to repair first.
 *
 * $haveColumns is passed in (rather than checked here via tableHasColumn())
 * so this function has no dependency beyond PDO and feed_token.php - the same
 * shape as feedTokenMigrationBackfill() above, and what lets
 * tests/feed_token_test.php exercise it on SQLite without config.php's
 * MySQL-only tableHasColumn().
 */
function feedTokenMigrationNullPlaintext(PDO $pdo, string $table, string $idColumn, bool $haveColumns, bool $dryRun): void
{
    if (!$haveColumns) {
        echo "[skip] {$table}: hash/enc columns missing, nothing to null\n";
        return;
    }
    $rows = $pdo->query(
        "SELECT {$idColumn} AS id, feed_token, feed_token_hash, feed_token_enc FROM {$table} WHERE feed_token IS NOT NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    $cleared = 0;
    $skipped = 0;
    $update = $dryRun ? null : $pdo->prepare("UPDATE {$table} SET feed_token = NULL WHERE {$idColumn} = ? AND feed_token = ?");
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        $token = (string) $row['feed_token'];
        $consistent = $row['feed_token_hash'] === feedTokenHash($token)
            && webhookSecretDecrypt($row['feed_token_enc']) === $token;
        if (!$consistent) {
            echo "{$table} {$id}: pair missing or inconsistent - left in place, re-run the backfill first\n";
            $skipped++;
            continue;
        }
        if ($update !== null) {
            $update->execute([$id, $token]);
            if ($update->rowCount() === 0) {
                echo "{$table} {$id}: token changed meanwhile - skipped\n";
                $skipped++;
                continue;
            }
        }
        echo "{$table} {$id}: " . ($dryRun ? 'would be nulled' : 'plaintext nulled') . "\n";
        $cleared++;
    }

    printf("%s: %d row(s) with a token: %d %s, %d left in place.\n",
        $table, count($rows), $cleared, $dryRun ? 'to null (dry run)' : 'nulled', $skipped);
}

// Table, id column, index name.
$targets = [
    ['pro_users', 'id', 'uniq_pro_users_feed_token_hash'],
    ['temp_emails', 'id', 'uniq_temp_emails_feed_token_hash'],
];

foreach ($targets as [$table, $idColumn, $indexName]) {
    if (!feedTokenMigrationTableExists($pdo, $table)) {
        echo "[skip] {$table}: table does not exist\n";
        continue;
    }
    if ($nullPlaintext) {
        feedTokenMigrationNullPlaintext($pdo, $table, $idColumn, tableHasColumn($table, 'feed_token_hash') && tableHasColumn($table, 'feed_token_enc'), $dryRun);
        continue;
    }
    $haveColumns = feedTokenMigrationSchema($pdo, $table, $indexName, $dryRun);
    feedTokenMigrationBackfill($pdo, $table, $idColumn, $haveColumns, $dryRun);
}

exit(0);
