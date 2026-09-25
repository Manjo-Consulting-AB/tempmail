<?php

declare(strict_types=1);

/**
 * check_email_encryption.php — read-only CLI audit of users' email addresses
 * at rest (pii_crypto.php).
 *
 * For pro_users and paddle_customers it checks that the email_enc/email_hash
 * columns and their index exist, and that every row's pair is present and
 * consistent:
 *   - email_enc decrypts (under PII_ENCRYPTION_KEY),
 *   - email_hash is the blind index of that decrypted address,
 *   - and, while the plaintext column is still filled, both describe it.
 * A paddle_customers row without an address must have no pair either. Every
 * pro_users row must have one: since phase B1 an account without a pair can
 * no longer be found by its address (login, registration, voucher, Paddle
 * linking).
 *
 * Writes nothing. Prints row ids and counts only, never an address or a
 * stored value. Run migrate_email_encryption.php to repair what it reports.
 *
 * Exit codes: 0 every row consistent; 1 anything else (keys missing, a
 * column or index missing, or at least one bad row).
 *
 * Usage: php check_email_encryption.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pii_crypto.php';

if (!piiKeysConfigured()) {
    fwrite(STDERR, "PII_ENCRYPTION_KEY and PII_INDEX_KEY must both be set (at least 32 characters each).\n");
    exit(1);
}

function checkEmailEncIndexExists(PDO $pdo, string $table, string $index): bool
{
    global $config;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** One row's problem, or null when it is consistent. */
function checkEmailEncRow(?string $email, ?string $enc, ?string $hash, bool $addressRequired): ?string
{
    $email = $email === null ? null : (string) $email;
    if (($enc === null || $enc === '') && ($hash === null || $hash === '')) {
        if ($email === null || $email === '') {
            return $addressRequired ? 'no address and no pair' : null;
        }
        return 'pair missing';
    }
    if ($enc === null || $enc === '' || $hash === null || $hash === '') {
        return 'half a pair';
    }
    $plain = piiEmailDecrypt($enc);
    if ($plain === null) {
        return 'email_enc does not decrypt';
    }
    if (!hash_equals((string) piiEmailHash($plain), $hash)) {
        return 'email_hash does not match email_enc';
    }
    if ($email !== null && $email !== '' && $plain !== $email) {
        return 'pair does not match the plaintext column';
    }
    return null;
}

// Table, id column, index name, is every row required to carry an address?
$targets = [
    ['pro_users', 'id', 'uniq_pro_users_email_hash', true],
    ['paddle_customers', 'customer_id', 'idx_paddle_customers_email_hash', false],
];

$problems = 0;
foreach ($targets as [$table, $idColumn, $indexName, $required]) {
    if (!tableHasColumn($table, $idColumn)) {
        echo "[skip] {$table}: table does not exist\n";
        continue;
    }
    if (!tableHasColumn($table, 'email_enc') || !tableHasColumn($table, 'email_hash')) {
        echo "[FAIL] {$table}: email_enc/email_hash missing - run migrate_email_encryption.php\n";
        $problems++;
        continue;
    }
    if (!checkEmailEncIndexExists($pdo, $table, $indexName)) {
        echo "[FAIL] {$table}: index {$indexName} missing - run migrate_email_encryption.php\n";
        $problems++;
    }

    $hasPlain = tableHasColumn($table, 'email');
    // Identifiers come from the fixed list above, never from input.
    $rows = $pdo->query("SELECT {$idColumn} AS id, " . ($hasPlain ? 'email' : 'NULL AS email') . ", email_enc, email_hash FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    $bad = 0;
    $seen = [];
    foreach ($rows as $row) {
        $problem = checkEmailEncRow($row['email'], $row['email_enc'], $row['email_hash'], $required);
        if ($problem === null && $required && $row['email_hash'] !== null) {
            if (isset($seen[$row['email_hash']])) {
                $problem = 'same email_hash as ' . $table . ' ' . $seen[$row['email_hash']];
            }
            $seen[$row['email_hash']] = $row['id'];
        }
        if ($problem !== null) {
            echo "[FAIL] {$table} {$row['id']}: {$problem}\n";
            $bad++;
        }
    }
    printf("[%s] %s: %d row(s), %d consistent, %d with problems\n", $bad === 0 ? 'ok' : 'FAIL', $table, count($rows), count($rows) - $bad, $bad);
    $problems += $bad;
}

if ($problems > 0) {
    echo "{$problems} problem(s). Run php migrate_email_encryption.php --dry-run, then without --dry-run.\n";
    exit(1);
}
echo "All email pairs are present and consistent.\n";
exit(0);
