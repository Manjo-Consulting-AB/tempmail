<?php

declare(strict_types=1);

/**
 * Regression coverage for the per-address Pushover routing (#176, parent #167).
 *
 * Run with:  php tests/pushover_routing_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database, no network and no
 * credentials are needed: the harness in tests/lib/pushover_harness.php brings
 * a SQLite schema and a throwaway docroot, and the Pushover credentials it
 * fixtures are self-describing fakes.
 *
 * What is actually under test is the shipped code, not a restatement of it:
 *
 *   - scenarios 3–7 drive the real pro_profile.php actions through a real
 *     request (seeded session, faked $_POST, same origin), so the ownership and
 *     Pro-gating decisions are the page's own SQL and guards;
 *   - scenarios 8–12 call the real ImapProcessor::dispatchWebhooks() against a
 *     real PDO;
 *   - scenario 13 and the regression block after it cover address deletion and
 *     the per-address RSS feed (#160).
 *
 * This suite deliberately runs the *pre-routing* path: it never applies
 * ms_test_webhook_address_schema(), so pushover_enabled is still the gate that
 * decides whether a Pushover hook is queued. Its complement is
 * tests/webhook_routing_test.php, which applies that schema and exercises the
 * per-hook routing instead — between the two, both branches of routing
 * availability are covered.
 *
 * The PHP→JS contract that used to be asserted here — the server-rendered flag
 * that gated the per-address Pushover control — went with the control itself
 * (#251 step 5). pro_profile_page.php no longer renders it, so there is nothing
 * left of that contract to assert; the page's own render coverage now lives in
 * tests/webhook_routing_test.php.
 */

$msRepoRoot = dirname(__DIR__);

// Same guard the check_*.php scripts use, plus the one extension this suite
// cannot do without: it brings its own SQLite database so that it can run
// without MySQL, but there is no second choice of driver to fall back on.
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension (it brings its own SQLite database so it needs no MySQL).\n");
    exit(1);
}

require __DIR__ . '/lib/pushover_harness.php';
require $msRepoRoot . '/php_imap_processor.php';

$msProbe = ms_test_probe_build($msRepoRoot);
$msSqlite = ms_test_probe_sqlite($msProbe);

// Global scope on purpose: the stub config.php's $config/$pdo have to land in
// the global scope, exactly as the real config.php's do when a page requires it.
$GLOBALS['MS_TEST_SQLITE'] = $msSqlite;
require $msProbe . '/config.php';
$pdo = ms_test_db($msSqlite);

$msBodies = [];
/** Run one probe request and remember its body for the credential sweep below. */
$msRun = function (array $request) use ($msProbe, &$msBodies): array {
    $response = ms_test_request($msProbe, $request);
    $msBodies[] = $response['stdout'];
    return $response;
};
/** POST a JSON action to one of the real pages as $userId. */
$msAction = function (string $page, string $action, array $fields, int $userId) use ($msRun): array {
    return $msRun([
        'page' => $page,
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'post' => array_merge(['action' => $action], $fields),
    ]);
};

echo "Mail Shield — per-address Pushover routing (#176)\n";
echo "probe docroot: {$msProbe}\n";

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

$userA = ms_test_seed_user($pdo, 'alice@example.com', 'pro');
$userB = ms_test_seed_user($pdo, 'bob@example.com', 'pro');

$addrOff   = ms_test_seed_address($pdo, 'a11ce001', ['pro_user_id' => $userA, 'pushover_enabled' => 0]);
$addrOn    = ms_test_seed_address($pdo, 'a11ce002', ['pro_user_id' => $userA, 'pushover_enabled' => 1, 'feed_token' => 'feed-fixture-token-0001']);
$addrTemp  = ms_test_seed_address($pdo, 'a11ce003', ['pro_user_id' => $userA, 'is_personal' => 0, 'pushover_enabled' => 0]);
$addrQueueOff = ms_test_seed_address($pdo, 'a11ce004', ['pro_user_id' => $userA, 'pushover_enabled' => 0]);
$addrQueueOn  = ms_test_seed_address($pdo, 'a11ce005', ['pro_user_id' => $userA, 'pushover_enabled' => 1]);
$addrBob      = ms_test_seed_address($pdo, 'b0b00001', ['pro_user_id' => $userB, 'pushover_enabled' => 0]);

// A paused Pushover hook from the start. It was seeded by the retired scenario
// 2, whose subject was the removed per-address PO control; scenario 10 still
// needs it, because a hook's own paused state is a separate rule from the
// address' opt-in.
$pausedHook = ms_test_seed_webhook($pdo, $userA, 'pushover', 'paused', 'Paused Pushover');

$msPayload = static fn(string $localPart): array => [
    'to' => $localPart . '@' . MS_TEST_EMAIL_DOMAIN,
    'subject' => 'Routing fixture',
    'body' => 'Body of the routing fixture.',
];

// ---------------------------------------------------------------------
// 3. Existing enabled/disabled state renders correctly
// ---------------------------------------------------------------------

ms_test_section('3. Existing enabled/disabled state renders correctly');

$status = ms_test_json('3a. address_pushover_status answers for the disabled address', $msAction('pro_profile.php', 'address_pushover_status', ['id' => $addrOff], $userA));
ms_test_same('3b. disabled address reports pushover_enabled=false', false, $status['pushover_enabled'] ?? null);

$status = ms_test_json('3c. address_pushover_status answers for the enabled address', $msAction('pro_profile.php', 'address_pushover_status', ['id' => $addrOn], $userA));
ms_test_same('3d. enabled address reports pushover_enabled=true', true, $status['pushover_enabled'] ?? null);

$list = ms_test_json('3e. list_personal answers', $msAction('index.php', 'list_personal', [], $userA));
$byId = [];
foreach (($list['personal'] ?? []) as $row) {
    $byId[(int) $row['id']] = $row;
}
ms_test_same('3f. list_personal reports the disabled address as pushover_enabled=false', false, $byId[$addrOff]['pushover_enabled'] ?? null);
ms_test_same('3g. list_personal reports the enabled address as pushover_enabled=true', true, $byId[$addrOn]['pushover_enabled'] ?? null);

// ---------------------------------------------------------------------
// 4. Enable persists
// ---------------------------------------------------------------------

ms_test_section('4. Enable persists');

ms_test_same('4a. the address starts disabled', 0, ms_test_pushover_flag($pdo, $addrOff));

$result = ms_test_json('4b. address_pushover_enable answers', $msAction('pro_profile.php', 'address_pushover_enable', ['id' => $addrOff], $userA));
ms_test_same('4c. the response reports success', true, $result['success'] ?? null);
ms_test_same('4d. the response reports the new state', true, $result['pushover_enabled'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('4e. the flag is on in the database after the write', 1, ms_test_pushover_flag($pdo, $addrOff));

$status = ms_test_json('4f. a later request sees the persisted state', $msAction('pro_profile.php', 'address_pushover_status', ['id' => $addrOff], $userA));
ms_test_same('4g. the persisted state reads back as enabled', true, $status['pushover_enabled'] ?? null);

// ---------------------------------------------------------------------
// 5. Disable persists
// ---------------------------------------------------------------------

ms_test_section('5. Disable persists');

$result = ms_test_json('5a. address_pushover_disable answers', $msAction('pro_profile.php', 'address_pushover_disable', ['id' => $addrOn], $userA));
ms_test_same('5b. the response reports success', true, $result['success'] ?? null);
ms_test_same('5c. the response reports the new state', false, $result['pushover_enabled'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('5d. the flag is off in the database after the write', 0, ms_test_pushover_flag($pdo, $addrOn));

$status = ms_test_json('5e. a later request sees the persisted state', $msAction('pro_profile.php', 'address_pushover_status', ['id' => $addrOn], $userA));
ms_test_same('5f. the persisted state reads back as disabled', false, $status['pushover_enabled'] ?? null);

// ---------------------------------------------------------------------
// 6. User A cannot modify User B's address
// ---------------------------------------------------------------------

ms_test_section("6. User A cannot modify User B's address");

$result = ms_test_json('6a. A cannot enable PO on B\'s address', $msAction('pro_profile.php', 'address_pushover_enable', ['id' => $addrBob], $userA));
ms_test_same('6b. the write is refused', false, $result['success'] ?? null);
ms_test_same('6c. the refusal does not disclose the address', 'Address not found', $result['error'] ?? null);

$result = ms_test_json('6d. A cannot disable PO on B\'s address', $msAction('pro_profile.php', 'address_pushover_disable', ['id' => $addrBob], $userA));
ms_test_same('6e. the write is refused', false, $result['success'] ?? null);

$result = ms_test_json('6f. A cannot read B\'s address state', $msAction('pro_profile.php', 'address_pushover_status', ['id' => $addrBob], $userA));
ms_test_same('6g. the read is refused', false, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same("6h. B's address flag is untouched", 0, ms_test_pushover_flag($pdo, $addrBob));

// The reverse direction must hold too, or this would only prove that one id
// happens to be missing.
$result = ms_test_json('6i. B cannot enable PO on A\'s address', $msAction('pro_profile.php', 'address_pushover_enable', ['id' => $addrOff], $userB));
ms_test_same('6j. the write is refused', false, $result['success'] ?? null);
ms_test_same("6k. A's address flag is untouched", 1, ms_test_pushover_flag($pdo, $addrOff));

// ---------------------------------------------------------------------
// 7. Temporary address cannot use the personal Pushover action
// ---------------------------------------------------------------------

ms_test_section('7. Temporary address cannot use the personal Pushover action');

$result = ms_test_json('7a. the personal PO action refuses a temporary address', $msAction('pro_profile.php', 'address_pushover_enable', ['id' => $addrTemp], $userA));
ms_test_same('7b. the write is refused', false, $result['success'] ?? null);
ms_test_same('7c. the refusal reads as not found', 'Address not found', $result['error'] ?? null);

$result = ms_test_json('7d. the status action refuses a temporary address', $msAction('pro_profile.php', 'address_pushover_status', ['id' => $addrTemp], $userA));
ms_test_same('7e. the read is refused', false, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('7f. the temporary address flag is untouched', 0, ms_test_pushover_flag($pdo, $addrTemp));

// ---------------------------------------------------------------------
// 8. PO-off address does not queue Pushover
// ---------------------------------------------------------------------

ms_test_section('8. PO-off address does not queue Pushover');

$hookActive = ms_test_seed_webhook($pdo, $userA, 'pushover', 'all', 'Active Pushover');

ms_test_forget_logs();
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce004'));

$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('8a. nothing is queued for the Pushover webhook', 0, $deliveries[$hookActive] ?? 0);
ms_test_check(
    '8b. the skip is the documented "destination address has Pushover disabled", not a lookup failure',
    ms_test_logged('Skipping Pushover webhook: destination address has Pushover disabled'),
    'expected skip reason was not logged'
);

// A generic webhook on the same account is unaffected by the address flag.
$genericHook = ms_test_seed_webhook($pdo, $userA, 'generic', 'all', 'Active generic');
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce004'));
$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('8c. the generic webhook is still queued', 1, $deliveries[$genericHook] ?? 0);

// ---------------------------------------------------------------------
// 9. PO-on address queues Pushover
// ---------------------------------------------------------------------

ms_test_section('9. PO-on address queues Pushover');

$before = ms_test_deliveries($pdo, $userA)[$hookActive] ?? 0;
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce005'));

$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('9a. the Pushover webhook gains one delivery', $before + 1, $deliveries[$hookActive] ?? 0);

$payloads = ms_test_delivery_payloads($pdo, $hookActive);
$last = $payloads[count($payloads) - 1] ?? [];
ms_test_same('9b. the queued payload is the message routed to that address', 'a11ce005@' . MS_TEST_EMAIL_DOMAIN, $last['to'] ?? null);

// ---------------------------------------------------------------------
// 10. Paused webhook does not deliver
// ---------------------------------------------------------------------

ms_test_section('10. Paused webhook does not deliver');

$pausedBefore = ms_test_deliveries($pdo, $userA)[$pausedHook] ?? 0;
$activeBefore = ms_test_deliveries($pdo, $userA)[$hookActive] ?? 0;
$pdo = ms_test_refresh_db($msSqlite);
// Same enabled destination as scenario 9 — only the webhook's filter_mode differs.
ms_test_dispatch($userA, $msPayload('a11ce005'));

$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('10a. the paused webhook gains no delivery', $pausedBefore, $deliveries[$pausedHook] ?? 0);
ms_test_same('10b. the active webhook on the same account still gains one', $activeBefore + 1, $deliveries[$hookActive] ?? 0);

// ---------------------------------------------------------------------
// 11. Generic webhook behaviour is unchanged
// ---------------------------------------------------------------------

ms_test_section('11. Generic webhook behaviour is unchanged');

$before = ms_test_deliveries($pdo, $userA)[$genericHook] ?? 0;
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce004'));  // Pushover off for this address

$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('11a. a generic webhook is queued regardless of the address PO flag', $before + 1, $deliveries[$genericHook] ?? 0);

// ---------------------------------------------------------------------
// 12. Multiple Pushover webhooks follow the documented all-active rule
// ---------------------------------------------------------------------

ms_test_section('12. Multiple Pushover webhooks follow the all-active rule');

$hookSecond = ms_test_seed_webhook($pdo, $userA, 'pushover', 'all', 'Second active Pushover');
$pdo = ms_test_refresh_db($msSqlite);

$first = ms_test_deliveries($pdo, $userA)[$hookActive] ?? 0;
$second = ms_test_deliveries($pdo, $userA)[$hookSecond] ?? 0;
ms_test_dispatch($userA, $msPayload('a11ce005'));  // enabled address

$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('12a. one enabled address enables the first Pushover webhook', $first + 1, $deliveries[$hookActive] ?? 0);
ms_test_same('12b. one enabled address enables the second Pushover webhook', $second + 1, $deliveries[$hookSecond] ?? 0);

// Now flip the only enabled destination off: both must go quiet together.
$result = ms_test_json('12c. the address can be disabled again', $msAction('pro_profile.php', 'address_pushover_disable', ['id' => $addrQueueOn], $userA));
ms_test_same('12d. the disable succeeded', true, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
$first = ms_test_deliveries($pdo, $userA)[$hookActive] ?? 0;
$second = ms_test_deliveries($pdo, $userA)[$hookSecond] ?? 0;
ms_test_dispatch($userA, $msPayload('a11ce005'));

$deliveries = ms_test_deliveries($pdo, $userA);
ms_test_same('12e. no Pushover webhook is queued once the last enabled address is off', $first, $deliveries[$hookActive] ?? 0);
ms_test_same('12f. the second Pushover webhook is quiet too', $second, $deliveries[$hookSecond] ?? 0);

// ---------------------------------------------------------------------
// 13. Address deletion removes the state
// ---------------------------------------------------------------------

ms_test_section('13. Address deletion removes the state');

$doomed = ms_test_seed_address($pdo, 'a11ce006', ['pro_user_id' => $userA, 'pushover_enabled' => 1, 'feed_token' => 'feed-fixture-token-0002']);
ms_test_same('13a. the address starts enabled with a feed token', 1, ms_test_pushover_flag($pdo, $doomed));

$result = ms_test_json('13b. delete_personal answers', $msAction('index.php', 'delete_personal', ['id' => $doomed], $userA));
ms_test_same('13c. the delete succeeded', true, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_check('13d. the address row is gone, taking its Pushover flag with it', !ms_test_address_exists($pdo, $doomed));

$list = ms_test_json('13e. list_personal still answers after the delete', $msAction('index.php', 'list_personal', [], $userA));
$ids = array_map(static fn(array $row): int => (int) $row['id'], $list['personal'] ?? []);
ms_test_check('13f. the deleted address is no longer listed', !in_array($doomed, $ids, true));
ms_test_check('13g. the surviving address is still listed', in_array($addrOff, $ids, true));
ms_test_check("13h. the other user's address was never in this list", !in_array($addrBob, $ids, true));

// Bob's own list is unaffected by Alice's delete.
$listB = ms_test_json('13i. list_personal answers for the other user', $msAction('index.php', 'list_personal', [], $userB));
$idsB = array_map(static fn(array $row): int => (int) $row['id'], $listB['personal'] ?? []);
ms_test_check("13j. the other user's address survives", in_array($addrBob, $idsB, true));

// ---------------------------------------------------------------------
// Regression: the per-address RSS feed still behaves (#160/#161)
// ---------------------------------------------------------------------

ms_test_section('Regression: per-address RSS feed is unaffected');

$list = ms_test_json('R1. list_personal answers', $msAction('index.php', 'list_personal', [], $userA));
$byId = [];
foreach (($list['personal'] ?? []) as $row) {
    $byId[(int) $row['id']] = $row;
}
ms_test_same('R2. a feed-enabled address still reports feed_enabled=true', true, $byId[$addrOn]['feed_enabled'] ?? null);
ms_test_same('R3. an address without a feed still reports feed_enabled=false', false, $byId[$addrOff]['feed_enabled'] ?? null);
ms_test_check(
    'R4. list_personal still never returns the feed credential itself',
    !array_key_exists('feed_token', $byId[$addrOn] ?? []) && !str_contains($list === null ? '' : (string) json_encode($list), 'feed-fixture-token-0001'),
    'the credential leaked into the address list'
);

$feed = ms_test_json('R5. address_feed_get_token still returns the existing token', $msAction('pro_profile.php', 'address_feed_get_token', ['id' => $addrOn], $userA));
ms_test_same('R6. the stored token is returned unchanged', 'feed-fixture-token-0001', $feed['token'] ?? null);

$feed = ms_test_json('R7. address_feed_regenerate still answers', $msAction('pro_profile.php', 'address_feed_regenerate', ['id' => $addrOn], $userA));
ms_test_check('R8. regeneration returns a new token', is_string($feed['token'] ?? null) && $feed['token'] !== 'feed-fixture-token-0001');

$feed = ms_test_json('R9. address_feed_disable still answers', $msAction('pro_profile.php', 'address_feed_disable', ['id' => $addrOn], $userA));
ms_test_same('R10. disabling the feed succeeded', true, $feed['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
$stmt = $pdo->prepare('SELECT feed_token FROM temp_emails WHERE id = ?');
$stmt->execute([$addrOn]);
ms_test_same('R11. the feed token is cleared', null, $stmt->fetchColumn() ?: null);
ms_test_same('R12. clearing the feed left the Pushover flag alone', 0, ms_test_pushover_flag($pdo, $addrOn));

// ---------------------------------------------------------------------
// The Pushover credential never leaves the server
// ---------------------------------------------------------------------

ms_test_section('Credential handling');

$leaked = [];
foreach ($msBodies as $index => $body) {
    if (str_contains($body, MS_TEST_FAKE_TOKEN) || str_contains($body, MS_TEST_FAKE_USER_KEY)) {
        $leaked[] = $index;
    }
}
ms_test_check(
    'C1. no probed response ever contains the Pushover token or user key',
    $leaked === [],
    'leaked in response(s) ' . implode(', ', $leaked)
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($msProbe);
} else {
    echo "Probe docroot left in place for inspection: {$msProbe}\n";
}
exit($exitCode);
