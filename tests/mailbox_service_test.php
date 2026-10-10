<?php

declare(strict_types=1);

/**
 * Regression coverage for the address and mailbox service (mailbox_service.php,
 * #319 - part of epic #318, the MCP server for Pro accounts).
 *
 * Run with:  php tests/mailbox_service_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 *  A. every mailbox_service.php function on an in-memory SQLite database -
 *     ownership (an owner, another user, a missing id/address all answer
 *     the same "not found" so nothing is revealed), the checks
 *     mailboxCreateSticky runs in order, the Timed-address replacement
 *     transaction, and a failed forwarder rolling a creation back;
 *  B. the address-creation rate limit shares its buckets between a direct
 *     call and the real index.php, on the probe docroot from
 *     tests/lib/pushover_harness.php.
 *
 * See also tests/address_cooldown_test.php (index.php's create_personal /
 * list_personal / delete_personal / generate through the probe, unchanged
 * by this file existing) and tests/abuse_guard_test.php,
 * tests/webhook_routing_test.php, tests/email_storage_test.php, which this
 * suite does not duplicate.
 */

$msRepoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

require __DIR__ . '/lib/email_storage_harness.php';

if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
require $msRepoRoot . '/mailbox_service.php';

// ---------------------------------------------------------------------
// Stand-ins for config.php's globals and infrastructure helpers, in the
// same spirit as tests/lib/pushover_harness.php's stub config.php: the
// helpers that take part in the behaviour under test (tableHasColumn,
// proUserIsPro, saveNewAddressWithExpiry) mirror their real bodies; the
// rest (logMessage, the DirectAdmin forwarder calls) are recorded rather
// than performed.
// ---------------------------------------------------------------------

$GLOBALS['ms_test_logs'] = [];
$GLOBALS['config'] = [
    'email' => ['domain' => MS_TEST_EMAIL_DOMAIN, 'base_url' => MS_TEST_ORIGIN . '/'],
    'app' => ['cleanup_hours' => 24],
];
$GLOBALS['ms_mbx_forwarder_fails'] = false;
$GLOBALS['ms_mbx_deleted_forwarders'] = [];

function tableHasColumn($table, $column) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
        $stmt->execute([(string) $table, (string) $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Mirrors config.php's isAdminUser(): no admin account is configured here. */
function isAdminUser(int $userId): bool {
    return false;
}

/** Mirrors config.php's stickyLimitFor() (epic #387); the scan in A14f keeps them in step. */
function stickyLimitFor(int $userId): ?int {
    if (isAdminUser($userId)) {
        return null;
    }
    try {
        if (!tableHasColumn('pro_users', 'bonus_sticky_slots')) {
            return 10;
        }
        global $pdo;
        $stmt = $pdo->prepare("SELECT bonus_sticky_slots FROM pro_users WHERE id = ?");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return 10 + max(0, (int) $value);
    } catch (Exception $e) {
        logMessage('WARNING', 'stickyLimitFor lookup failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return 10;
    }
}

function logMessage($level, $message, $context = null) {
    $GLOBALS['ms_test_logs'][] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    return true;
}

function detectSuspiciousPatterns(string $input): array {
    return [];
}

function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = is_string($local) ? strtolower(trim($local)) : '';
    if ($s === '' || strlen($s) < $minLength || strlen($s) > $maxLength) {
        return null;
    }
    return preg_match('/^[a-z0-9._-]+$/', $s) ? $s : null;
}

function createDirectAdminForwarder(string $alias): bool {
    return !($GLOBALS['ms_mbx_forwarder_fails'] ?? false);
}

function deleteDirectAdminForwarder(string $alias): void {
    $GLOBALS['ms_mbx_deleted_forwarders'][] = $alias;
}

function generateUniqueString($length = null) {
    return bin2hex(random_bytes((int) ($length ?: 10) / 2));
}

function generateSignedAttachmentUrl(int $attachmentId, $ttlSeconds = null, $baseTime = null): string {
    return 'https://example.invalid/files.php?id=' . $attachmentId . '&sig=test';
}

function getVisitorIp() {
    return '203.0.113.7';
}

function flagMaliciousActivity(...$args) {
    return true;
}

function feedTokenColumnsExist(string $table): bool {
    return tableHasColumn($table, 'feed_token_hash');
}

/** Mirrors proUserIsPro() from config.php. */
function proUserIsPro(int $userId): bool {
    global $pdo;
    $stmt = $pdo->prepare('SELECT account_type, pro_expires_at FROM pro_users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return $row['account_type'] === 'pro'
        && (is_null($row['pro_expires_at']) || strtotime((string) $row['pro_expires_at']) >= time());
}

/** Mirrors deleteStoredEmailsForTempEmail() from config.php. */
function deleteStoredEmailsForTempEmail(PDO $pdo, int $tempEmailId): array {
    $stmt = $pdo->prepare('SELECT ea.file_path FROM email_attachments ea JOIN stored_emails se ON se.id = ea.email_id WHERE se.temp_email_id = ?');
    $stmt->execute([$tempEmailId]);
    $filePaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM stored_emails WHERE temp_email_id = ?)')->execute([$tempEmailId]);
    $pdo->prepare('DELETE FROM stored_emails WHERE temp_email_id = ?')->execute([$tempEmailId]);
    return $filePaths;
}

function unlinkAttachmentFiles(array $paths): void {
}

/** Mirrors config.php's saveNewAddressWithExpiry(). */
function saveNewAddressWithExpiry($address, $proUserId, $isPersonal, string $expiresAt) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
    $result = $stmt->execute([$address, $proUserId, $isPersonal ? 1 : 0, $expiresAt, date('Y-m-d H:i:s')]);
    if ($result && !createDirectAdminForwarder((string) $address)) {
        $pdo->prepare('DELETE FROM temp_emails WHERE unique_address = ?')->execute([$address]);
        return false;
    }
    return $result;
}

// ---------------------------------------------------------------------
// SQLite schema: the shared base (pro_users, temp_emails, ...) plus the
// storage tables, the cool-off table and the abuse guard tables.
// ---------------------------------------------------------------------

function ms_mbx_extra_schema(): string {
    return <<<'SQL'
CREATE TABLE address_cooldowns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    local_part TEXT NOT NULL UNIQUE,
    pro_user_id INTEGER NOT NULL,
    released_at TEXT NOT NULL,
    blocked_until TEXT NOT NULL
);

CREATE TABLE abuse_counters (
    scope TEXT NOT NULL,
    subject TEXT NOT NULL,
    window_start TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    bytes INTEGER NOT NULL DEFAULT 0,
    strikes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject, window_start)
);

CREATE TABLE address_quarantines (
    local_part TEXT PRIMARY KEY,
    temp_email_id INTEGER NOT NULL,
    pro_user_id INTEGER NULL,
    reason TEXT NOT NULL,
    quarantined_at TEXT NOT NULL,
    quarantined_until TEXT NULL,
    forwarder_removed INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE abuse_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    subject TEXT NULL,
    pro_user_id INTEGER NULL,
    detail TEXT NULL,
    notify INTEGER NOT NULL DEFAULT 0,
    notified_at TEXT NULL
);
SQL;
}

function ms_mbx_address_row(PDO $pdo, string $local): ?array {
    $stmt = $pdo->prepare('SELECT id, pro_user_id, is_personal, expires_at FROM temp_emails WHERE unique_address = ?');
    $stmt->execute([$local]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ms_mbx_seed_message(PDO $pdo, int $tempEmailId, string $toAddress, array $overrides = []): int {
    $stmt = $pdo->prepare('INSERT INTO stored_emails (to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $toAddress,
        $overrides['from_address'] ?? 'sender@example.com',
        $overrides['subject'] ?? 'Hello',
        $overrides['body_text'] ?? 'Hello there',
        $overrides['body_html'] ?? null,
        $overrides['received_at'] ?? date('Y-m-d H:i:s'),
        $overrides['expires_at'] ?? null,
        $tempEmailId,
    ]);
    return (int) $pdo->lastInsertId();
}

// =====================================================================
ms_test_section('A. mailbox_service.php on SQLite');
// =====================================================================

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(ms_test_schema());
$pdo->exec(ms_test_storage_extra_schema());
$pdo->exec(ms_mbx_extra_schema());

$alice = ms_test_seed_user($pdo, 'alice@example.com', 'pro');
$bob = ms_test_seed_user($pdo, 'bob@example.com', 'pro');
$regular = ms_test_seed_user($pdo, 'reg@example.com', 'regular');

// --- mailboxCreateSticky ------------------------------------------------

$r = mailboxCreateSticky($pdo, $alice, 'Alice.Sticky');
ms_test_same('A1. create: succeeds', true, $r['ok'] ?? null);
ms_test_same('A2. create: lowercases the local part', 'alice.sticky', $r['address'] ?? null);
ms_test_same('A3. create: full_address carries the domain', 'alice.sticky@' . MS_TEST_EMAIL_DOMAIN, $r['full_address'] ?? null);
ms_test_check('A4. create: the row exists, owned by alice', (int) (ms_mbx_address_row($pdo, 'alice.sticky')['pro_user_id'] ?? 0) === $alice);

$r = mailboxCreateSticky($pdo, $bob, 'postmaster');
ms_test_same('A5. create: a reserved local part is refused', ['ok' => false, 'error' => 'This local part is not allowed'], $r);
ms_test_same('A6. create: no row for it', null, ms_mbx_address_row($pdo, 'postmaster'));

$r = mailboxCreateSticky($pdo, $bob, '.leading-dot');
ms_test_same('A7. create: invalid syntax is refused', ['ok' => false, 'error' => 'Invalid local part'], $r);

$r = mailboxCreateSticky($pdo, $bob, 'alice.sticky');
ms_test_same('A8. create: a taken address is refused', ['ok' => false, 'error' => 'Address already taken'], $r);

// Cool-off: bob may not take an address alice just released; alice may.
$r = mailboxDeleteSticky($pdo, $alice, (int) ms_mbx_address_row($pdo, 'alice.sticky')['id']);
ms_test_same('A9. delete: succeeds', true, $r['ok'] ?? null);
$r = mailboxCreateSticky($pdo, $bob, 'alice.sticky');
ms_test_same('A10. create: someone else\'s cool-off answers the same "taken"', ['ok' => false, 'error' => 'Address already taken'], $r);
$r = mailboxCreateSticky($pdo, $alice, 'alice.sticky');
ms_test_same('A11. create: the owner takes its own reservation back', true, $r['ok'] ?? null);

// The cap of 10.
for ($i = 1; $i <= 9; $i++) {
    $r = mailboxCreateSticky($pdo, $alice, 'alice.cap' . $i);
    ms_test_check("A12.$i. create: address " . ($i + 1) . " of 10 succeeds", $r['ok'] ?? false);
}
$r = mailboxCreateSticky($pdo, $alice, 'alice.cap10');
ms_test_same('A13. create: the 11th sticky address is refused', ['ok' => false, 'error' => 'Maximum of 10 sticky addresses allowed'], $r);
ms_test_same('A14. create: and creates no row', null, ms_mbx_address_row($pdo, 'alice.cap10'));

// Referral bonus slots (epic #387): the cap is 10 plus pro_users.bonus_sticky_slots.
// Before the column exists (A13 above) the cap is 10; with it, an account
// with 3 slots reaches 13 and an account with 0 is still refused at its 11th.
$pdo->exec('ALTER TABLE pro_users ADD COLUMN bonus_sticky_slots INTEGER NOT NULL DEFAULT 0');
$carol = ms_test_seed_user($pdo, 'carol@example.com', 'pro');
$pdo->prepare('UPDATE pro_users SET bonus_sticky_slots = 3 WHERE id = ?')->execute([$carol]);
ms_test_same('A14a. stickyLimitFor: 10 plus 3 bonus slots', 13, stickyLimitFor($carol));
ms_test_same('A14b. stickyLimitFor: 10 with no bonus slots', 10, stickyLimitFor($alice));
for ($i = 1; $i <= 13; $i++) {
    $r = mailboxCreateSticky($pdo, $carol, 'carol.cap' . $i);
    ms_test_check("A14c.$i. create: address $i of 13 succeeds with 3 bonus slots", $r['ok'] ?? false);
}
$r = mailboxCreateSticky($pdo, $carol, 'carol.cap14');
ms_test_same('A14d. create: the 14th is refused', ['ok' => false, 'error' => 'Maximum of 13 sticky addresses allowed'], $r);
$r = mailboxCreateSticky($pdo, $alice, 'alice.cap10');
ms_test_same('A14e. create: an account with 0 bonus slots is still refused at its 11th', ['ok' => false, 'error' => 'Maximum of 10 sticky addresses allowed'], $r);

// The mirror above must match the shipped function body in config.php.
$msNorm = static function (string $src): ?string {
    if (!preg_match('/^function stickyLimitFor\(.*?^}$/ms', $src, $m)) {
        return null;
    }
    return preg_replace('/\s+/', ' ', $m[0]);
};
$msShipped = $msNorm((string) file_get_contents($msRepoRoot . '/config.php'));
ms_test_check('A14f. config.php defines stickyLimitFor() identically to the mirror',
    $msShipped !== null && $msShipped === $msNorm((string) file_get_contents(__FILE__)));

// A failed forwarder rolls the creation back.
$GLOBALS['ms_mbx_forwarder_fails'] = true;
$r = mailboxCreateSticky($pdo, $bob, 'bob.nowhere');
ms_test_same('A15. create: a failed forwarder is refused', ['ok' => false, 'error' => 'Mail delivery could not be set up for this address, so it was not created. Please try again in a moment.'], $r);
ms_test_same('A16. create: and leaves no row', null, ms_mbx_address_row($pdo, 'bob.nowhere'));
$GLOBALS['ms_mbx_forwarder_fails'] = false;

// --- mailboxDeleteSticky --------------------------------------------------

$bobSticky = mailboxCreateSticky($pdo, $bob, 'bob.sticky');
$bobStickyId = (int) ms_mbx_address_row($pdo, 'bob.sticky')['id'];

$r = mailboxDeleteSticky($pdo, $alice, $bobStickyId);
ms_test_same('A17. delete: another user\'s id answers "not found"', ['ok' => false, 'error' => 'Address not found or not owned by user'], $r);
ms_test_check('A18. delete: and the row survives', ms_mbx_address_row($pdo, 'bob.sticky') !== null);

$r = mailboxDeleteSticky($pdo, $bob, 999999);
ms_test_same('A19. delete: a missing id answers "not found"', ['ok' => false, 'error' => 'Address not found or not owned by user'], $r);

$r = mailboxDeleteSticky($pdo, $bob, 0);
ms_test_same('A20. delete: id 0 is refused before any lookup', ['ok' => false, 'error' => 'Invalid id'], $r);

$r = mailboxDeleteSticky($pdo, $bob, $bobStickyId);
ms_test_same('A21. delete: the owner succeeds', ['ok' => true, 'deleted_address' => 'bob.sticky'], $r);
ms_test_same('A22. delete: the address is reserved for its owner', $bob, addressCooldownHolder($pdo, 'bob.sticky'));

// --- mailboxListAddresses --------------------------------------------------

$r = mailboxListAddresses($pdo, $alice);
ms_test_same('A23. list: succeeds', true, $r['ok'] ?? null);
ms_test_same('A24. list: 10 sticky addresses', 10, count($r['personal'] ?? []));
ms_test_check('A25. list: carries full_address', str_ends_with((string) ($r['personal'][0]['full_address'] ?? ''), '@' . MS_TEST_EMAIL_DOMAIN));
ms_test_same('A26. list: no cooldown yet for alice', [], $r['cooldown'] ?? null);
ms_test_same('A27. list: no Timed address yet', null, $r['temporary']);

$r = mailboxListAddresses($pdo, $bob);
ms_test_same('A28. list: bob sees his cool-off reservation', 'bob.sticky', $r['cooldown'][0]['address'] ?? null);

// --- mailboxCreateTimed ------------------------------------------------

$r = mailboxCreateTimed($pdo, $alice);
ms_test_same('A29. timed: succeeds', true, $r['ok'] ?? null);
$aliceTimedRow = $pdo->query("SELECT expires_at FROM temp_emails WHERE pro_user_id = " . $alice . " AND is_personal = 0")->fetch(PDO::FETCH_ASSOC);
$ttlDays = round((strtotime((string) $aliceTimedRow['expires_at']) - time()) / 86400);
ms_test_check('A30. timed: a Pro account with the default ttl gets ~1 day', $ttlDays === 1.0 || $ttlDays === 0.0);

$pdo->prepare('UPDATE pro_users SET address_ttl_days = 5 WHERE id = ?')->execute([$alice]);
$oldTimedAddress = $r['address'];
$oldTimedId = (int) ms_mbx_address_row($pdo, $oldTimedAddress)['id'];
ms_mbx_seed_message($pdo, $oldTimedId, $oldTimedAddress . '@' . MS_TEST_EMAIL_DOMAIN);

$r2 = mailboxCreateTimed($pdo, $alice);
ms_test_same('A31. timed: replacing succeeds', true, $r2['ok'] ?? null);
ms_test_check('A32. timed: a Pro account\'s ttl_days is honoured (clamped 1-7)', abs(round((strtotime((string) $r2['expires_at']) - time()) / 86400) - 5) <= 1);
ms_test_same('A33. timed: the old address is gone', null, ms_mbx_address_row($pdo, $oldTimedAddress));
ms_test_same('A34. timed: the old address\' mail is gone too', 0, (int) $pdo->query("SELECT COUNT(*) FROM stored_emails WHERE to_address = '{$oldTimedAddress}@" . MS_TEST_EMAIL_DOMAIN . "'")->fetchColumn());
ms_test_same('A35. timed: only one Timed address remains for the account', 1, (int) $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE pro_user_id = {$alice} AND is_personal = 0")->fetchColumn());
ms_test_check('A36. timed: the old forwarder was removed', in_array($oldTimedAddress, $GLOBALS['ms_mbx_deleted_forwarders'], true));

$r = mailboxCreateTimed($pdo, $regular);
ms_test_same('A37. timed: a non-Pro account gets the default 24h, not its ttl_days preference', true, abs(round((strtotime((string) $r['expires_at']) - time()) / 3600) - 24) <= 1);

// A failed forwarder on replacement keeps the previous address.
$currentTimed = mailboxListAddresses($pdo, $bob);
$r = mailboxCreateTimed($pdo, $bob);
ms_test_same('A38. timed: bob\'s first Timed address is created', true, $r['ok'] ?? null);
$bobTimedAddress = $r['address'];
$GLOBALS['ms_mbx_forwarder_fails'] = true;
$r = mailboxCreateTimed($pdo, $bob);
$GLOBALS['ms_mbx_forwarder_fails'] = false;
ms_test_same('A39. timed: a failed forwarder on replacement is refused', false, $r['ok'] ?? null);
ms_test_check('A40. timed: ... and the previous Timed address is kept', ms_mbx_address_row($pdo, $bobTimedAddress) !== null);
ms_test_same('A41. timed: ... and still exactly one Timed address for the account', 1, (int) $pdo->query("SELECT COUNT(*) FROM temp_emails WHERE pro_user_id = {$bob} AND is_personal = 0")->fetchColumn());

// --- mailboxCreationLimited ------------------------------------------------

$r = mailboxCreationLimited($pdo, 'generate', 999888, '203.0.113.9');
ms_test_same('A42. limited: a fresh account is not limited', ['ok' => true], $r);

for ($i = 0; $i < 30; $i++) {
    mailboxCreationLimited($pdo, 'generate', 777666, '203.0.113.10');
}
$r = mailboxCreationLimited($pdo, 'generate', 777666, '203.0.113.10');
ms_test_same('A43. limited: the 31st attempt in a day is refused', false, $r['ok'] ?? null);
ms_test_same('A44. limited: with rate_limited and the site\'s message', [true, 'Too many new addresses in a short time. Please try again later.'], [$r['rate_limited'] ?? null, $r['error'] ?? null]);

$pdoNoAbuseTables = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoNoAbuseTables->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY)');
$r = mailboxCreationLimited($pdoNoAbuseTables, 'generate', 1, '203.0.113.11');
ms_test_same('A45. limited: fail-open without the abuse guard tables', ['ok' => true], $r);

// --- mailboxListMessages / mailboxGetMessage ------------------------------
// A fresh account (alice is already at her 10-address cap above).

$carol = ms_test_seed_user($pdo, 'carol@example.com', 'pro');
mailboxCreateSticky($pdo, $carol, 'carol.mail');
$carolMailId = (int) ms_mbx_address_row($pdo, 'carol.mail')['id'];
$msgId = ms_mbx_seed_message($pdo, $carolMailId, 'carol.mail@' . MS_TEST_EMAIL_DOMAIN, ['subject' => 'Ownership test', 'body_html' => '<p>Hi</p>']);
$pdo->prepare('INSERT INTO email_attachments (email_id, filename, file_path, mime_type, created_at, content_id) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$msgId, 'a.txt', '123_abc_a.txt', 'text/plain', date('Y-m-d H:i:s'), null]);

$r = mailboxListMessages($pdo, $carol, 'carol.mail');
ms_test_same('A46. list messages: the owner sees the message', true, $r['ok'] ?? null);
ms_test_same('A47. list messages: one message', 1, count($r['emails'] ?? []));
ms_test_same('A48. list messages: the list carries no raw body_html', null, $r['emails'][0]['body_html']);

$r = mailboxListMessages($pdo, $bob, 'carol.mail');
ms_test_same('A49. list messages: another user\'s address answers "not found"', ['ok' => false, 'error' => 'Address not found'], $r);

$r = mailboxListMessages($pdo, $carol, 'doesnotexist');
ms_test_same('A50. list messages: a missing address answers the same "not found"', ['ok' => false, 'error' => 'Address not found'], $r);

$r = mailboxListMessages($pdo, $carol, 'not valid!!');
ms_test_same('A51. list messages: invalid syntax is refused before any lookup', ['ok' => false, 'error' => 'Invalid address'], $r);

$r = mailboxGetMessage($pdo, $carol, $msgId);
ms_test_same('A52. get message: the owner sees it', true, $r['ok'] ?? null);
ms_test_same('A53. get message: with its attachment', 1, count($r['attachments'] ?? []));
ms_test_check('A54. get message: the attachment carries a signed download_url', !empty($r['attachments'][0]['download_url'] ?? null));
ms_test_check('A55. get message: body_html is purified or dropped, never the raw markup', ($r['email']['body_html'] ?? null) !== '<p>Hi</p>' || $r['email']['body_html'] === null);

$r = mailboxGetMessage($pdo, $bob, $msgId);
ms_test_same('A56. get message: another user\'s message answers "not found"', ['ok' => false, 'error' => 'Message not found'], $r);

$r = mailboxGetMessage($pdo, $carol, 999999);
ms_test_same('A57. get message: a missing id answers the same "not found"', ['ok' => false, 'error' => 'Message not found'], $r);

$r = mailboxGetMessage($pdo, $carol, 0);
ms_test_same('A58. get message: id 0 is refused before any lookup', ['ok' => false, 'error' => 'Message not found'], $r);

$expiredId = ms_mbx_seed_message($pdo, $carolMailId, 'carol.mail@' . MS_TEST_EMAIL_DOMAIN, [
    'subject' => 'Old',
    'expires_at' => date('Y-m-d H:i:s', time() - 3600),
]);
$r = mailboxGetMessage($pdo, $carol, $expiredId);
ms_test_same('A59. get message: an expired message is refused', ['ok' => false, 'error' => 'Message expired'], $r);

// =====================================================================
ms_test_section('B. the creation rate limit shares its buckets with index.php');
// =====================================================================

$msProbe = ms_test_probe_build($msRepoRoot);
$msSqlite = ms_test_probe_sqlite($msProbe);
$probePdo = ms_test_db($msSqlite);
// ms_test_probe_build()'s base schema has no abuse guard tables (most
// suites that use it never need them); add them so the rate limit is
// actually active on both sides of this scenario.
$probePdo->exec(ms_mbx_extra_schema());
$probeUser = ms_test_seed_user($probePdo, 'shared@example.com', 'pro');

for ($i = 0; $i < 30; $i++) {
    $probeResult = mailboxCreationLimited($probePdo, 'generate', $probeUser, '203.0.113.20');
    if (!($probeResult['ok'] ?? false)) {
        ms_test_check('B0. the 30 direct calls stayed under the limit', false, "limited early at i={$i}");
        break;
    }
}

$res = ms_test_request($msProbe, [
    'page' => 'index.php',
    'method' => 'POST',
    'user_id' => $probeUser,
    'user_email' => 'shared@example.com',
    'post' => ['action' => 'generate'],
]);
ms_test_no_php_errors('B1. the 31st attempt, through real index.php, raises no PHP error', $res);
$json = ms_test_json('B2. ... and answers JSON', $res);
ms_test_same('B3. ... refused: the direct calls and index.php share the same bucket', [false, true], [$json['success'] ?? null, $json['rate_limited'] ?? null]);

ms_test_cleanup($msProbe);

exit(ms_test_summary());
