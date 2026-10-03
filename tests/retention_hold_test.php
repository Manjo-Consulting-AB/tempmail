<?php

declare(strict_types=1);

/**
 * Regression coverage for the retention-hold service (retention_hold.php,
 * table retention_holds, epic #369 step 2/5).
 *
 * Run with:  php tests/retention_hold_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 * every function runs against an in-memory SQLite database (the Pushover
 * harness brings the schema and the reporting helpers).
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
require $msRepoRoot . '/retention_hold.php';

/** Recorded rather than written, so the suite can assert the library logs. */
$GLOBALS['ms_rh_logs'] = [];
function logMessage($level, $message, $context = null) {
    $GLOBALS['ms_rh_logs'][] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    return true;
}

/** SQLite version of the table migrate_retention_holds.php creates. */
function ms_rh_schema(): string {
    return <<<'SQL'
CREATE TABLE retention_holds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pro_user_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    ended_at TEXT NULL
);
SQL;
}

function ms_rh_db(string $schema): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(ms_test_schema());
    $pdo->exec(ms_test_storage_extra_schema());
    if ($schema !== '') {
        $pdo->exec($schema);
    }
    return $pdo;
}

function ms_rh_dt(string $at): DateTimeImmutable {
    return new DateTimeImmutable($at);
}

/** Insert a hold row directly (test setup, not the code under test). */
function ms_rh_insert_hold(PDO $pdo, int $userId, string $startedAt, int $days = 30, ?string $endedAt = null): int {
    $endsAt = ms_rh_dt($startedAt)->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO retention_holds (pro_user_id, started_at, ends_at, ended_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $startedAt, $endsAt, $endedAt]);
    return (int) $pdo->lastInsertId();
}

function ms_rh_seed_message(PDO $pdo, int $tempEmailId, string $receivedAt, ?string $expiresAt): int {
    $stmt = $pdo->prepare('INSERT INTO stored_emails (to_address, from_address, subject, body_text, received_at, expires_at, temp_email_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(['seed@manjo.me', 'sender@example.com', 'Hello', 'Body', $receivedAt, $expiresAt, $tempEmailId]);
    return (int) $pdo->lastInsertId();
}

function ms_rh_expiry(PDO $pdo, int $emailId): ?string {
    $stmt = $pdo->prepare('SELECT expires_at FROM stored_emails WHERE id = ?');
    $stmt->execute([$emailId]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? null : (string) $value;
}

function ms_rh_last_log(string $message): ?array {
    $found = null;
    foreach ((array) $GLOBALS['ms_rh_logs'] as $entry) {
        if ($entry['message'] === $message) {
            $found = $entry;
        }
    }
    return $found;
}

$db = ms_rh_db(ms_rh_schema());
$alice = ms_test_seed_user($db, 'alice@example.com', 'pro');
$bob = ms_test_seed_user($db, 'bob@example.com', 'pro');
$carol = ms_test_seed_user($db, 'carol@example.com', 'pro');
$dave = ms_test_seed_user($db, 'dave@example.com', 'pro');
$erin = ms_test_seed_user($db, 'erin@example.com', 'pro');

// =====================================================================
ms_test_section('A. status, start and end');
// =====================================================================

ms_test_same('A1. the feature is available with the table present', true, retentionHoldAvailable($db));

$st = retentionHoldStatus($db, $alice, ms_rh_dt('2026-01-01 00:00:00'));
ms_test_same('A2. status with no rows: available', true, $st['available']);
ms_test_same('A3. status with no rows: no active hold', null, $st['active']);
ms_test_same('A4. status with no rows: used is 0', 0, $st['used']);
ms_test_same('A5. status carries the allowance', 4, $st['max']);
ms_test_same('A6. status carries the length', 30, $st['days']);
ms_test_same('A7. status with no rows: nothing to wait for', null, $st['next_available_at']);

$res = retentionHoldStart($db, $alice, ms_rh_dt('2026-01-15 12:00:00'));
ms_test_same('A8. start succeeds', true, $res['ok']);
ms_test_same('A9. start extends no mail when there is none', 0, $res['extended']);
ms_test_same('A10. the hold ends 30 days after it started', '2026-02-14 12:00:00', $res['hold']['ends_at'] ?? null);
ms_test_same('A11. the hold is active right away', true, $res['hold']['id'] > 0);

$st = retentionHoldStatus($db, $alice, ms_rh_dt('2026-01-15 13:00:00'));
ms_test_same('A12. status now reports an active hold', true, $st['active'] !== null);
ms_test_same('A13. and one hold used', 1, $st['used']);
ms_test_same('A14. below the allowance, so nothing to wait for', null, $st['next_available_at']);

$active = retentionHoldActive($db, $alice, ms_rh_dt('2026-01-15 13:00:00'));
ms_test_same('A15. the active row carries started_at', '2026-01-15 12:00:00', $active['started_at'] ?? null);
ms_test_same('A16. the active row carries ends_at', '2026-02-14 12:00:00', $active['ends_at'] ?? null);

$res = retentionHoldStart($db, $alice, ms_rh_dt('2026-01-16 12:00:00'));
ms_test_same('A17. a second start while active is refused', false, $res['ok']);
ms_test_same('A18. with the active-hold message', 'A hold is already active', $res['error'] ?? null);
ms_test_same('A19. the refusal wrote no row', 1, (int) $db->query('SELECT COUNT(*) FROM retention_holds WHERE pro_user_id = ' . $alice)->fetchColumn());

$res = retentionHoldEnd($db, $alice, ms_rh_dt('2026-01-17 12:00:00'));
ms_test_same('A20. end succeeds', true, $res['ok']);
ms_test_same('A21. the hold is no longer active', null, retentionHoldActive($db, $alice, ms_rh_dt('2026-01-17 12:00:00')));
$st = retentionHoldStatus($db, $alice, ms_rh_dt('2026-01-17 12:00:00'));
ms_test_same('A22. an ended hold still counts as used', 1, $st['used']);

$res = retentionHoldEnd($db, $alice, ms_rh_dt('2026-01-17 12:00:00'));
ms_test_same('A23. end with no active hold is refused', false, $res['ok']);
ms_test_same('A24. with the no-active-hold message', 'No active hold', $res['error'] ?? null);

$started = ms_rh_last_log('Retention hold started');
ms_test_same('A25. start is logged with the hold id and count', true,
    $started !== null && isset($started['context']['hold_id'], $started['context']['extended']) && $started['level'] === 'INFO');
$ended = ms_rh_last_log('Retention hold ended');
ms_test_same('A26. end is logged with the hold id', true,
    $ended !== null && isset($ended['context']['hold_id']) && $ended['level'] === 'INFO');

// =====================================================================
ms_test_section('B. the yearly allowance');
// =====================================================================

foreach (['2026-01-01 12:00:00', '2026-02-01 12:00:00', '2026-03-01 12:00:00', '2026-04-01 12:00:00'] as $startedAt) {
    ms_rh_insert_hold($db, $bob, $startedAt, 30, $startedAt);
}

$st = retentionHoldStatus($db, $bob, ms_rh_dt('2026-06-01 12:00:00'));
ms_test_same('B1. four holds inside a year are all used', 4, $st['used']);
ms_test_same('B2. the allowance is spent, so the next date is set', '2027-01-01 12:00:00', $st['next_available_at']);
ms_test_same('B3. none of them is active', null, $st['active']);

$res = retentionHoldStart($db, $bob, ms_rh_dt('2026-06-01 12:00:00'));
ms_test_same('B4. the fifth start is refused', false, $res['ok']);
ms_test_same('B5. with the allowance message naming the number', 'You have used all 4 holds for this year', $res['error'] ?? null);
ms_test_same('B6. the refusal wrote no row', 4, (int) $db->query('SELECT COUNT(*) FROM retention_holds WHERE pro_user_id = ' . $bob)->fetchColumn());

$st = retentionHoldStatus($db, $bob, ms_rh_dt('2027-01-02 12:00:00'));
ms_test_same('B7. a hold started 366 days ago no longer counts', 3, $st['used']);
ms_test_same('B8. so there is nothing to wait for', null, $st['next_available_at']);
$res = retentionHoldStart($db, $bob, ms_rh_dt('2027-01-02 12:00:00'));
ms_test_same('B9. and a new hold can start', true, $res['ok']);

// =====================================================================
ms_test_section('C. an expired hold is not active');
// =====================================================================

$res = retentionHoldStart($db, $carol, ms_rh_dt('2026-01-01 00:00:00'));
ms_test_same('C1. the hold starts', true, $res['ok']);
ms_test_same('C2. it ends 30 days later', '2026-01-31 00:00:00', $res['hold']['ends_at'] ?? null);
ms_test_same('C3. it is active during its window', true, retentionHoldActive($db, $carol, ms_rh_dt('2026-01-15 00:00:00')) !== null);
ms_test_same('C4. it is not active once ends_at has passed', null, retentionHoldActive($db, $carol, ms_rh_dt('2026-02-05 00:00:00')));

$st = retentionHoldStatus($db, $carol, ms_rh_dt('2026-02-05 00:00:00'));
ms_test_same('C5. an expired hold reports no active hold', null, $st['active']);
ms_test_same('C6. but still counts as used', 1, $st['used']);

$res = retentionHoldStart($db, $carol, ms_rh_dt('2026-02-05 00:00:00'));
ms_test_same('C7. a new hold starts after the old one expired', true, $res['ok']);
ms_test_same('C8. it is active', true, retentionHoldActive($db, $carol, ms_rh_dt('2026-02-05 00:00:00')) !== null);

// =====================================================================
ms_test_section('D. starting a hold extends the account\'s Sticky mail');
// =====================================================================

$daveSticky = ms_test_seed_address($db, 'dave.box', ['pro_user_id' => $dave, 'is_personal' => 1]);
$daveTimed = ms_test_seed_address($db, 'abc12345', ['pro_user_id' => $dave, 'is_personal' => 0]);
$erinSticky = ms_test_seed_address($db, 'erin.box', ['pro_user_id' => $erin, 'is_personal' => 1]);

// Expires tomorrow relative to the hold: gets received_at + 30 days.
$mExpiring = ms_rh_seed_message($db, $daveSticky, '2026-01-10 00:00:00', '2026-01-16 00:00:00');
// Already lives past received_at + 30 days: unchanged (a hold never shortens).
$mLonger = ms_rh_seed_message($db, $daveSticky, '2026-01-01 00:00:00', '2026-06-01 00:00:00');
// Already expired: not touched.
$mExpired = ms_rh_seed_message($db, $daveSticky, '2025-12-01 00:00:00', '2025-12-15 00:00:00');
// A Timed address' mail is not covered by a hold.
$mTimed = ms_rh_seed_message($db, $daveTimed, '2026-01-10 00:00:00', '2026-01-16 00:00:00');
// Another account's Sticky mail is not touched.
$mOther = ms_rh_seed_message($db, $erinSticky, '2026-01-10 00:00:00', '2026-01-16 00:00:00');

$res = retentionHoldStart($db, $dave, ms_rh_dt('2026-01-15 12:00:00'));
ms_test_same('D1. the hold starts', true, $res['ok']);
ms_test_same('D2. exactly one message is extended', 1, $res['extended']);
ms_test_same('D3. the expiring message gets received_at + 30 days', '2026-02-09 00:00:00', ms_rh_expiry($db, $mExpiring));
ms_test_same('D4. a message already expiring later is unchanged', '2026-06-01 00:00:00', ms_rh_expiry($db, $mLonger));
ms_test_same('D5. an already-expired message is unchanged', '2025-12-15 00:00:00', ms_rh_expiry($db, $mExpired));
ms_test_same('D6. a Timed address\' message is unchanged', '2026-01-16 00:00:00', ms_rh_expiry($db, $mTimed));
ms_test_same('D7. another account\'s message is unchanged', '2026-01-16 00:00:00', ms_rh_expiry($db, $mOther));

$res = retentionHoldEnd($db, $dave, ms_rh_dt('2026-01-15 13:00:00'));
ms_test_same('D8. ending the hold succeeds', true, $res['ok']);
ms_test_same('D9. ending a hold leaves the extended date alone', '2026-02-09 00:00:00', ms_rh_expiry($db, $mExpiring));

// =====================================================================
ms_test_section('E. retentionHoldDaysFor() is fail-open');
// =====================================================================

ms_test_same('E1. an active hold reports its length', 30, retentionHoldDaysFor($db, $carol, ms_rh_dt('2026-02-05 00:00:00')));
ms_test_same('E2. no active hold reports null', null, retentionHoldDaysFor($db, $erin, ms_rh_dt('2026-01-15 13:00:00')));
ms_test_same('E3. an ended hold reports null', null, retentionHoldDaysFor($db, $alice, ms_rh_dt('2026-02-01 00:00:00')));

$noTable = ms_rh_db('');
ms_test_same('E4. available is false when the table is missing', false, retentionHoldAvailable($noTable));
ms_test_same('E5. status is available=false when the table is missing', false, retentionHoldStatus($noTable, 1)['available']);
ms_test_same('E6. daysFor is null when the table is missing', null, retentionHoldDaysFor($noTable, 1));
ms_test_same('E7. start fails cleanly when the table is missing', false, retentionHoldStart($noTable, 1)['ok']);
ms_test_same('E8. end fails cleanly when the table is missing', false, retentionHoldEnd($noTable, 1)['ok']);

// =====================================================================
ms_test_section('F. settings');
// =====================================================================

$cfgBackup = $GLOBALS['config'] ?? null;
unset($GLOBALS['config']);
ms_test_same('F1. defaults are 30 days / 4 per year', [30, 4], retentionHoldSettings());
$GLOBALS['config'] = ['retention_hold' => ['days' => 7, 'max_per_year' => 2]];
ms_test_same('F2. settings follow $config', [7, 2], retentionHoldSettings());
$GLOBALS['config'] = $cfgBackup;

// =====================================================================
ms_test_section('G. pro_profile.php actions through the probe docroot');
// =====================================================================

$msProbe = ms_test_probe_build($msRepoRoot);
$msSqlite = ms_test_probe_sqlite($msProbe);
$pdo = ms_test_db($msSqlite);
// The start path extends stored_emails through temp_emails, so both the
// storage tables and the hold table have to exist for the subprocess.
$pdo->exec(ms_test_storage_extra_schema());

$msAction = function (string $action, array $fields, int $userId, $origin = '__default') use ($msProbe): array {
    $request = [
        'page' => 'pro_profile.php',
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'post' => array_merge(['action' => $action], $fields),
    ];
    if ($origin !== '__default') {
        $request['origin'] = $origin;
    }
    return ms_test_request($msProbe, $request);
};

$proUser = ms_test_seed_user($pdo, 'pro@example.com', 'pro');
$regUser = ms_test_seed_user($pdo, 'reg@example.com', 'regular');

// --- Before the migration: the feature reports itself unavailable ------

$res = $msAction('retention_hold_status', [], $proUser);
ms_test_no_php_errors('G1. status without the table raises no PHP error', $res);
$json = ms_test_json('G2. status without the table answers JSON', $res);
ms_test_same('G3. ... with available false', false, $json['status']['available'] ?? null);
ms_test_same('G4. ... and the account\'s Pro flag beside it', true, $json['status']['is_pro'] ?? null);

$json = ms_test_json('G5. start without the table answers JSON', $msAction('retention_hold_start', [], $proUser));
ms_test_same('G6. start without the table is refused cleanly', false, $json['success'] ?? null);

// --- With the table in place -------------------------------------------

$pdo->exec(ms_rh_schema());

$stickyId = ms_test_seed_address($pdo, 'pro.box', ['pro_user_id' => $proUser, 'is_personal' => 1]);
$receivedAt = date('Y-m-d H:i:s', time() - 86400);
$expiringId = ms_rh_seed_message($pdo, $stickyId, $receivedAt, date('Y-m-d H:i:s', time() + 86400));

$res = $msAction('retention_hold_start', [], $proUser);
ms_test_no_php_errors('G7. start as Pro raises no PHP error', $res);
$json = ms_test_json('G8. start as Pro answers JSON', $res);
ms_test_same('G9. start as Pro succeeds', true, $json['success'] ?? null);
ms_test_same('G10. ... reports one hold used', 1, $json['status']['used'] ?? null);
ms_test_same('G11. ... reports an active hold', true, ($json['status']['active'] ?? null) !== null);
ms_test_check('G12. ... and an ISO ends_at for the browser',
    is_string($json['status']['active']['ends_at'] ?? null)
        && str_contains((string) $json['status']['active']['ends_at'], 'T'));
ms_test_same('G13. ... extending the message that would expire', 1, $json['extended'] ?? null);
ms_test_same('G14. ... to received_at + 30 days',
    (new DateTimeImmutable($receivedAt))->modify('+30 days')->format('Y-m-d H:i:s'),
    ms_rh_expiry($pdo, $expiringId));

$json = ms_test_json('G15. a second start answers JSON', $msAction('retention_hold_start', [], $proUser));
ms_test_same('G16. a second start while active is refused', false, $json['success'] ?? null);
ms_test_same('G17. ... with the library\'s own message', 'A hold is already active', $json['error'] ?? null);

$json = ms_test_json('G18. get_ttl answers JSON', $msAction('get_ttl', [], $proUser));
ms_test_same('G19. get_ttl carries the same status', true, ($json['retention']['active'] ?? null) !== null);
ms_test_same('G20. ... and the Pro flag', true, $json['retention']['is_pro'] ?? null);

$json = ms_test_json('G21. status as Pro answers JSON', $msAction('retention_hold_status', [], $proUser));
ms_test_same('G22. the status action reports the active hold', true, ($json['status']['active'] ?? null) !== null);

// --- Pro gating: start needs it, end does not --------------------------

$json = ms_test_json('G23. start as Regular answers JSON', $msAction('retention_hold_start', [], $regUser));
ms_test_same('G24. start as Regular is refused', false, $json['success'] ?? null);
ms_test_same('G25. ... as a Pro-required answer', true, $json['pro_required'] ?? null);
ms_test_same('G26. ... and wrote no row', 0, (int) $pdo->query('SELECT COUNT(*) FROM retention_holds WHERE pro_user_id = ' . $regUser)->fetchColumn());

ms_rh_insert_hold($pdo, $regUser, date('Y-m-d H:i:s'), 30);
$json = ms_test_json('G27. end as Regular answers JSON', $msAction('retention_hold_end', [], $regUser));
ms_test_same('G28. ending a hold is allowed without Pro', true, $json['success'] ?? null);
ms_test_same('G29. ... and clears the active hold', true,
    array_key_exists('active', $json['status'] ?? []) && $json['status']['active'] === null);
ms_test_same('G30. the row is ended, not deleted', 1, (int) $pdo->query('SELECT COUNT(*) FROM retention_holds WHERE pro_user_id = ' . $regUser . ' AND ended_at IS NOT NULL')->fetchColumn());

$json = ms_test_json('G31. ending with no active hold answers JSON', $msAction('retention_hold_end', [], $regUser));
ms_test_same('G32. ... and is refused with the library\'s message', 'No active hold', $json['error'] ?? null);

// --- Cross-origin writes are refused -----------------------------------

$json = ms_test_json('G33. a cross-origin start answers JSON', $msAction('retention_hold_start', [], $proUser, 'http://evil.example'));
ms_test_same('G34. a cross-origin start is refused', 'Forbidden', $json['error'] ?? null);
$json = ms_test_json('G35. a cross-origin end answers JSON', $msAction('retention_hold_end', [], $proUser, 'http://evil.example'));
ms_test_same('G36. a cross-origin end is refused', 'Forbidden', $json['error'] ?? null);
ms_test_same('G37. ... and the Pro account\'s hold is untouched', 1, (int) $pdo->query('SELECT COUNT(*) FROM retention_holds WHERE pro_user_id = ' . $proUser . ' AND ended_at IS NULL')->fetchColumn());

ms_test_cleanup($msProbe);

exit(ms_test_summary());
