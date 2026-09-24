<?php

declare(strict_types=1);

/**
 * Regression coverage for login_tokens.php: magic-link tokens (login_tokens)
 * and pending profile change tokens (pending_profile_changes) are hashed at
 * rest and consumed atomically, and rows written before the change (raw
 * token) still work exactly once during the transition.
 *
 * Run with:  php tests/login_token_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. SQLite in memory: no MySQL,
 * no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../login_tokens.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function freshDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE login_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token VARCHAR(128) NOT NULL,
        expires_at TEXT NOT NULL,
        used INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE pending_profile_changes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        data TEXT NULL,
        token VARCHAR(128) NOT NULL,
        expires_at TEXT NOT NULL,
        used INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("INSERT INTO pro_users (email) VALUES ('user@example.com')");
    return $pdo;
}

// ---------------------------------------------------------------------------
// Format and hashing helpers
// ---------------------------------------------------------------------------

same('F1. 48 hex characters is valid', true, authTokenIsValidFormat(str_repeat('a', 48)));
same('F2. 47 characters is too short', false, authTokenIsValidFormat(str_repeat('a', 47)));
same('F3. 97 characters is too long', false, authTokenIsValidFormat(str_repeat('a', 97)));
same('F4. non-hex is rejected', false, authTokenIsValidFormat(str_repeat('g', 48)));
same('F5. generated token is 48 lowercase hex', 1, preg_match('/^[a-f0-9]{48}$/', authTokenGenerate()));
same('F6. hash is 64 hex characters', 1, preg_match('/^[a-f0-9]{64}$/', authTokenHash(authTokenGenerate())));
$h = authTokenHash(str_repeat('b', 64));
same('F7. non-48 input never looks up its raw value', [$h, $h], authTokenLookupValues(str_repeat('b', 64)));

// ---------------------------------------------------------------------------
// login_tokens
// ---------------------------------------------------------------------------

$pdo = freshDb();
$raw = loginTokenCreate($pdo, 1);
$stored = $pdo->query("SELECT token FROM login_tokens WHERE id = 1")->fetchColumn();
same('L1. the hash is stored', authTokenHash($raw), $stored);
check('L2. the raw token is not stored', $stored !== $raw
    && (int) scalar($pdo, "SELECT COUNT(*) FROM login_tokens WHERE token = ?", [$raw]) === 0);

$first = loginTokenConsume($pdo, $raw);
same('L3. first consume succeeds', ['user_id' => 1, 'email' => 'user@example.com'],
    $first === null ? null : ['user_id' => (int) $first['user_id'], 'email' => $first['email']]);
same('L4. second consume fails', null, loginTokenConsume($pdo, $raw));

$raw2 = loginTokenCreate($pdo, 1);
same('L5. the stored hash submitted as a token does not log in', null, loginTokenConsume($pdo, authTokenHash($raw2)));
same('L6. an uppercased raw token still matches (as with the old collation)', 1,
    loginTokenConsume($pdo, strtoupper($raw2)) === null ? 0 : 1);

$raw3 = authTokenGenerate();
$pdo->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (1, ?, ?)")
    ->execute([authTokenHash($raw3), date('Y-m-d H:i:s', time() - 60)]);
same('L7. expired token fails', null, loginTokenConsume($pdo, $raw3));
same('L8. expired token is left unused', 0,
    (int) scalar($pdo, "SELECT used FROM login_tokens WHERE token = ?", [authTokenHash($raw3)]));

$legacy = authTokenGenerate();
$pdo->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (1, ?, ?)")
    ->execute([$legacy, date('Y-m-d H:i:s', time() + 600)]);
check('L9. legacy plaintext row is consumable', loginTokenConsume($pdo, $legacy) !== null);
same('L10. legacy plaintext row only once', null, loginTokenConsume($pdo, $legacy));

same('L11. malformed token fails before any lookup', null, loginTokenConsume($pdo, "' OR 1=1 --"));
same('L12. unknown token fails', null, loginTokenConsume($pdo, authTokenGenerate()));

// Race: a second request read the row before the first one claimed it. The
// claim must refuse the loser even though its SELECT saw used = 0.
$raw4 = loginTokenCreate($pdo, 1);
$id4 = (int) scalar($pdo, "SELECT id FROM login_tokens WHERE token = ?", [authTokenHash($raw4)]);
$pdo->prepare("UPDATE login_tokens SET used = 1 WHERE id = ?")->execute([$id4]); // winner's claim
$loser = $pdo->prepare("UPDATE login_tokens SET used = 1 WHERE id = ? AND used = 0");
$loser->execute([$id4]);
same('L13. the claim UPDATE changes no row for the loser of a race', 0, $loser->rowCount());
same('L14. consume after a concurrent claim fails', null, loginTokenConsume($pdo, $raw4));

// ---------------------------------------------------------------------------
// pending_profile_changes
// ---------------------------------------------------------------------------

$pdo = freshDb();
$praw = authTokenGenerate();
$pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (1, 'set_password', '{}', ?, ?)")
    ->execute([pendingChangeStoredToken($pdo, $praw), date('Y-m-d H:i:s', time() + 7200)]);
same('P1. the hash is stored', authTokenHash($praw),
    $pdo->query("SELECT token FROM pending_profile_changes WHERE id = 1")->fetchColumn());
$prow = pendingChangeFindByToken($pdo, $praw);
same('P2. the raw token finds the row', 1, $prow === null ? null : (int) $prow['id']);
same('P3. first claim succeeds', true, pendingChangeClaim($pdo, 1));
same('P4. second claim fails', false, pendingChangeClaim($pdo, 1));
same('P5. the stored hash does not find the row', null, pendingChangeFindByToken($pdo, authTokenHash($praw)));
same('P6. malformed token finds nothing', null, pendingChangeFindByToken($pdo, 'abc'));

$plegacy = authTokenGenerate();
$pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (1, 'delete_account', '{}', ?, ?)")
    ->execute([$plegacy, date('Y-m-d H:i:s', time() + 7200)]);
$lrow = pendingChangeFindByToken($pdo, $plegacy);
check('P7. legacy plaintext row is found', $lrow !== null);
same('P8. legacy plaintext row claimed once', [true, false],
    [pendingChangeClaim($pdo, (int) $lrow['id']), pendingChangeClaim($pdo, (int) $lrow['id'])]);
same('P9. expiry is compared in PHP time', [true, false],
    [authTokenIsExpired(date('Y-m-d H:i:s', time() - 1)), authTokenIsExpired(date('Y-m-d H:i:s', time() + 60))]);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
