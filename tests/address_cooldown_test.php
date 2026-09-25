<?php

declare(strict_types=1);

/**
 * Regression coverage for the cool-off list of released personal addresses
 * (address_cooldown.php, table address_cooldowns).
 *
 * Run with:  php tests/address_cooldown_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 * part A runs the library on an in-memory SQLite database; part B drives the
 * real index.php actions (delete_personal, create_personal, list_personal,
 * generate) through the throwaway docroot from tests/lib/pushover_harness.php;
 * part C scans that every path deleting personal addresses reserves them.
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

require __DIR__ . '/lib/pushover_harness.php';
require $msRepoRoot . '/address_cooldown.php';

/** SQLite version of the table migrate_address_cooldowns.php creates. */
function ms_cd_schema(): string {
    return <<<'SQL'
CREATE TABLE address_cooldowns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    local_part TEXT NOT NULL UNIQUE,
    pro_user_id INTEGER NOT NULL,
    released_at TEXT NOT NULL,
    blocked_until TEXT NOT NULL
);
SQL;
}

function ms_cd_count(PDO $pdo, ?int $userId = null): int {
    if ($userId === null) {
        return (int) $pdo->query('SELECT COUNT(*) FROM address_cooldowns')->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM address_cooldowns WHERE pro_user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// =====================================================================
ms_test_section('A. address_cooldown.php on SQLite');
// =====================================================================

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(ms_cd_schema());
$t0 = strtotime('2026-01-15 12:00:00');

addressCooldownAdd($db, 1, 'alice', 6, 30, $t0);
ms_test_same('A1. a released address is held by its owner', 1, addressCooldownHolder($db, 'alice', $t0 + 60));
ms_test_same('A2. another local part is free', null, addressCooldownHolder($db, 'bob', $t0 + 60));
ms_test_same('A3. still held just before six months', 1, addressCooldownHolder($db, 'alice', strtotime('2026-07-15 11:59:00')));
ms_test_same('A4. free once six months have passed', null, addressCooldownHolder($db, 'alice', strtotime('2026-07-15 12:00:00')));

// An expired reservation of someone else is replaced by the new release.
addressCooldownAdd($db, 2, 'alice', 6, 30, strtotime('2026-08-01 00:00:00'));
ms_test_same('A5. an expired row is replaced by a new owner\'s release', 2, addressCooldownHolder($db, 'alice', strtotime('2026-08-02 00:00:00')));
ms_test_same('A6. one row per local part', 1, ms_cd_count($db));

addressCooldownClear($db, 1, 'alice');
ms_test_same('A7. clear only ends the caller\'s own reservation', 2, addressCooldownHolder($db, 'alice', strtotime('2026-08-02 00:00:00')));
addressCooldownClear($db, 2, 'alice');
ms_test_same('A8. clear by the owner frees it', null, addressCooldownHolder($db, 'alice', strtotime('2026-08-02 00:00:00')));

// The cap: 31 releases, one minute apart, keep the newest 30.
$db->exec('DELETE FROM address_cooldowns');
addressCooldownAdd($db, 9, 'other-user', 6, 30, $t0);
for ($i = 1; $i <= 31; $i++) {
    addressCooldownAdd($db, 1, 'addr' . $i, 6, 30, $t0 + $i * 60);
}
$tAfter = $t0 + 40 * 60;
ms_test_same('A9. at most 30 reservations per owner', 30, ms_cd_count($db, 1));
ms_test_same('A10. the oldest fell off the list and is free', null, addressCooldownHolder($db, 'addr1', $tAfter));
ms_test_same('A11. the second oldest is still held', 1, addressCooldownHolder($db, 'addr2', $tAfter));
ms_test_same('A12. the newest is held', 1, addressCooldownHolder($db, 'addr31', $tAfter));
ms_test_same('A13. the cap never touches another owner', 9, addressCooldownHolder($db, 'other-user', $tAfter));

$list = addressCooldownList($db, 1, $tAfter);
ms_test_same('A14. the list holds the owner\'s active rows', 30, count($list));
ms_test_same('A15. the list is newest first', 'addr31', $list[0]['address'] ?? null);
ms_test_check('A16. the list carries blocked_until', isset($list[0]['blocked_until']) && strpos($list[0]['blocked_until'], '2026-07-15') === 0);

// Re-releasing one already on the list moves it to the top, not a second row.
addressCooldownAdd($db, 1, 'addr2', 6, 30, $t0 + 50 * 60);
ms_test_same('A17. a re-release keeps 30 rows', 30, ms_cd_count($db, 1));
ms_test_same('A18. and puts it first', 'addr2', addressCooldownList($db, 1, $t0 + 51 * 60)[0]['address'] ?? null);

ms_test_same('A19. purge removes nothing before expiry', 0, addressCooldownPurgeExpired($db, $tAfter));
ms_test_same('A20. purge removes every expired row', 31, addressCooldownPurgeExpired($db, strtotime('2027-01-01 00:00:00')));

$cfgBackup = $GLOBALS['config'] ?? null;
unset($GLOBALS['config']);
ms_test_same('A21. settings default to 6 months / 30', [6, 30], addressCooldownSettings());
$GLOBALS['config'] = ['address_cooldown' => ['months' => 3, 'max_per_user' => 5]];
ms_test_same('A22. settings follow $config', [3, 5], addressCooldownSettings());
$GLOBALS['config'] = $cfgBackup;

// =====================================================================
ms_test_section('B. index.php actions through the probe docroot');
// =====================================================================

$msProbe = ms_test_probe_build($msRepoRoot);
$msSqlite = ms_test_probe_sqlite($msProbe);
// create_personal needs sanitizeLocalPart() and generate saveNewAddress(),
// which the shared stub config leaves out.
file_put_contents($msProbe . '/config.php', <<<'PHP'

function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = sanitizeString($local, $maxLength, true);
    if ($s === null || strlen($s) < $minLength || !preg_match('/^[a-zA-Z0-9._-]+$/', $s)) return null;
    return strtolower($s);
}

function saveNewAddress($address, $proUserId = null, $isPersonal = 0) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$address, $proUserId, $isPersonal ? 1 : 0, $GLOBALS['__custom_expires_at'] ?? date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s')]);
}
PHP, FILE_APPEND);
$GLOBALS['MS_TEST_SQLITE'] = $msSqlite;
require $msProbe . '/config.php';
$pdo = ms_test_db($msSqlite);
$pdo->exec(ms_cd_schema());

$msAction = function (string $action, array $fields, int $userId) use ($msProbe): array {
    return ms_test_request($msProbe, [
        'page' => 'index.php',
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'post' => array_merge(['action' => $action], $fields),
    ]);
};
$addressRow = function (string $local) use ($pdo): ?array {
    $stmt = $pdo->prepare('SELECT id, pro_user_id FROM temp_emails WHERE unique_address = ?');
    $stmt->execute([$local]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$alice = ms_test_seed_user($pdo, 'alice@example.com');
$bob = ms_test_seed_user($pdo, 'bob@example.com');
$aliceAddr = ms_test_seed_address($pdo, 'alice.box', ['pro_user_id' => $alice, 'is_personal' => 1]);

$res = $msAction('delete_personal', ['id' => $aliceAddr], $alice);
ms_test_no_php_errors('B1. delete_personal raises no PHP error', $res);
$json = ms_test_json('B2. delete_personal answers JSON', $res);
ms_test_same('B3. delete_personal succeeds', true, $json['success'] ?? null);
ms_test_same('B4. the address row is gone', null, $addressRow('alice.box'));
ms_test_same('B5. the address is reserved for its owner', $alice, addressCooldownHolder($pdo, 'alice.box'));

$json = ms_test_json('B6. list_personal answers JSON', $msAction('list_personal', [], $alice));
$cooldown = $json['cooldown'] ?? [];
ms_test_same('B7. list_personal returns the reservation', 'alice.box', $cooldown[0]['address'] ?? null);
ms_test_same('B8. with the full address', 'alice.box@manjo.me', $cooldown[0]['full_address'] ?? null);
$json = ms_test_json('B9. list_personal answers JSON for another user', $msAction('list_personal', [], $bob));
ms_test_same('B10. another user sees no reservations', [], $json['cooldown'] ?? null);

$res = $msAction('create_personal', ['local' => 'Alice.Box'], $bob);
ms_test_no_php_errors('B11. create_personal by someone else raises no PHP error', $res);
$json = ms_test_json('B12. create_personal by someone else answers JSON', $res);
ms_test_same('B13. someone else is refused', false, $json['success'] ?? null);
ms_test_same('B14. with the same message as a live address', 'Address already taken', $json['error'] ?? null);
ms_test_same('B15. no row was created', null, $addressRow('alice.box'));
ms_test_same('B16. the reservation stands', $alice, addressCooldownHolder($pdo, 'alice.box'));

$res = $msAction('create_personal', ['local' => 'alice.box'], $alice);
ms_test_no_php_errors('B17. create_personal by the owner raises no PHP error', $res);
$json = ms_test_json('B18. create_personal by the owner answers JSON', $res);
ms_test_same('B19. the owner takes it back', true, $json['success'] ?? null);
ms_test_same('B20. owned by the owner again', $alice, (int) ($addressRow('alice.box')['pro_user_id'] ?? 0));
ms_test_same('B21. the reservation ended', null, addressCooldownHolder($pdo, 'alice.box'));

// An expired reservation blocks nobody.
$pdo->prepare('INSERT INTO address_cooldowns (local_part, pro_user_id, released_at, blocked_until) VALUES (?, ?, ?, ?)')
    ->execute(['old.box', $alice, '2020-01-01 00:00:00', '2020-07-01 00:00:00']);
$json = ms_test_json('B22. create_personal over an expired reservation answers JSON', $msAction('create_personal', ['local' => 'old.box'], $bob));
ms_test_same('B23. an expired reservation is free for anyone', true, $json['success'] ?? null);

// generate runs the cool-off lookup on every random address it draws.
$json = ms_test_json('B24. generate answers JSON with the table present', $msAction('generate', [], $bob));
ms_test_same('B25. generate still works', true, $json['success'] ?? null);

ms_test_cleanup($msProbe);

// =====================================================================
ms_test_section('C. every personal-address deletion path reserves the address');
// =====================================================================

foreach ([
    'index.php' => 1,           // delete_personal
    'pro_auth.php' => 1,        // delete_account
    'cron/cleanup.php' => 2,    // grace-period removal, inactive account removal
] as $file => $expected) {
    $src = (string) file_get_contents($msRepoRoot . '/' . $file);
    ms_test_same("C. {$file} calls addressCooldownAdd() {$expected}x", $expected, substr_count($src, 'addressCooldownAdd('));
}

exit(ms_test_summary());
