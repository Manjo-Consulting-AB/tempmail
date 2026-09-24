<?php

declare(strict_types=1);

/**
 * Regression coverage for the per-hook address routing (epic #251, steps 3–4).
 *
 * Run with:  php tests/webhook_routing_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database, no network and no
 * credentials are needed: the harness in tests/lib/pushover_harness.php brings
 * a SQLite schema and a throwaway docroot, and the Pushover credentials it
 * fixtures are self-describing fakes.
 *
 * What is under test is the shipped code, not a restatement of it: every
 * scenario calls the real ImapProcessor::dispatchWebhooks() against a real PDO,
 * over the schema migrate_webhook_addresses.php creates (applied here on top of
 * ms_test_schema() by ms_test_webhook_address_schema()).
 *
 * This is the only routing suite since #251 step 6: its complement,
 * tests/pushover_routing_test.php, existed to keep the pre-routing path honest,
 * and that path is gone — dispatch now queues nothing without this schema. The
 * per-address RSS feed and credential sections it used to end with were moved
 * here, fixtures adapted and assertions kept.
 *
 * Scenarios 0–7 assert the routing rules through dispatch alone. From scenario
 * 9 on they also drive the real pro_profile.php / index.php actions over a real
 * request, so the origin check, the Pro gate, the ownership queries and the
 * transaction under test are the pages' own code. Scenario 16 renders the real
 * pro_profile_page.php and asserts the PHP→JS contract the routing UI is built
 * from (#251 step 5): the "has any webhook" and "routing available" flags, and
 * that the retired per-address Pushover control left nothing behind. The UI
 * itself is assembled in the browser and this suite has no JS engine, so the
 * rendered flags are as far as it can honestly go.
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

// The routing schema, on top of ms_test_schema() — this is what makes this
// suite exercise the per-hook path and the Pushover suite the legacy one.
$pdo->exec(ms_test_webhook_address_schema());

// ---------------------------------------------------------------------
// Seeding helpers for the routing schema
// ---------------------------------------------------------------------

/** Link one hook to one personal address, the way #251 step 4's API will. */
function ms_wr_link(PDO $pdo, int $webhookId, int $addressId): void {
    $stmt = $pdo->prepare('INSERT INTO pro_webhook_addresses (webhook_id, temp_email_id, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$webhookId, $addressId, date('Y-m-d H:i:s')]);
}

/** Turn one hook's "Temporary addresses" switch on. */
function ms_wr_include_temporary(PDO $pdo, int $webhookId): void {
    $stmt = $pdo->prepare('UPDATE pro_webhooks SET include_temporary = 1 WHERE id = ?');
    $stmt->execute([$webhookId]);
}

/** Pause one address' hooks, keeping its links. */
function ms_wr_pause_address(PDO $pdo, int $addressId): void {
    $stmt = $pdo->prepare('UPDATE temp_emails SET hooks_paused = 1 WHERE id = ?');
    $stmt->execute([$addressId]);
}

/** How many addresses one hook is linked to. */
function ms_wr_link_count(PDO $pdo, int $webhookId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pro_webhook_addresses WHERE webhook_id = ?');
    $stmt->execute([$webhookId]);
    return (int) $stmt->fetchColumn();
}

/** How many hooks one address is linked to, whoever owns them. */
function ms_wr_address_link_count(PDO $pdo, int $addressId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pro_webhook_addresses WHERE temp_email_id = ?');
    $stmt->execute([$addressId]);
    return (int) $stmt->fetchColumn();
}

function ms_wr_hook_exists(PDO $pdo, int $webhookId): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pro_webhooks WHERE id = ?');
    $stmt->execute([$webhookId]);
    return (int) $stmt->fetchColumn() > 0;
}

/** One address' hooks_paused column, or null when the row is gone. */
function ms_wr_hooks_paused(PDO $pdo, int $addressId): ?int {
    $stmt = $pdo->prepare('SELECT hooks_paused FROM temp_emails WHERE id = ?');
    $stmt->execute([$addressId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

/** Deliveries one webhook has queued, 0 when it has none. */
function ms_wr_count(PDO $pdo, int $userId, int $webhookId): int {
    return ms_test_deliveries($pdo, $userId)[$webhookId] ?? 0;
}

echo "Mail Shield — per-hook address routing (#251 steps 3-4)\n";
echo "probe docroot: {$msProbe}\n";

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

$userA    = ms_test_seed_user($pdo, 'alice@example.com', 'pro');
$userB    = ms_test_seed_user($pdo, 'bob@example.com', 'pro');
$userFree = ms_test_seed_user($pdo, 'carol@example.com', 'regular');

$addrA    = ms_test_seed_address($pdo, 'a11ce101', ['pro_user_id' => $userA]);                          // personal, linked to G and P
$addrB    = ms_test_seed_address($pdo, 'a11ce102', ['pro_user_id' => $userA]);                          // personal, no links
$addrC    = ms_test_seed_address($pdo, 'a11ce103', ['pro_user_id' => $userA]);                          // personal, linked, then paused
$addrD    = ms_test_seed_address($pdo, 'a11ce104', ['pro_user_id' => $userA]);                          // personal, linked to P only
$addrT    = ms_test_seed_address($pdo, 'a11ce105', ['pro_user_id' => $userA, 'is_personal' => 0]);      // temporary
$addrBob  = ms_test_seed_address($pdo, 'b0b00001', ['pro_user_id' => $userB]);                          // B's personal address
$addrFree = ms_test_seed_address($pdo, 'ca401001', ['pro_user_id' => $userFree]);                       // non-Pro user's address

$hookG      = ms_test_seed_webhook($pdo, $userA, 'generic', 'all', 'Generic G');
$hookH      = ms_test_seed_webhook($pdo, $userA, 'generic', 'all', 'Generic H');
$hookP      = ms_test_seed_webhook($pdo, $userA, 'pushover', 'all', 'Pushover P');
$hookPaused = ms_test_seed_webhook($pdo, $userA, 'generic', 'paused', 'Paused hook');
$hookT      = ms_test_seed_webhook($pdo, $userA, 'generic', 'all', 'Temporary-enabled generic');
$hookFree   = ms_test_seed_webhook($pdo, $userFree, 'generic', 'all', 'Non-Pro user hook');

$msPayload = static fn(string $localPart): array => [
    'to' => $localPart . '@' . MS_TEST_EMAIL_DOMAIN,
    'subject' => 'Routing fixture',
    'body' => 'Body of the routing fixture.',
];

// ---------------------------------------------------------------------
// 0. The routing schema is in place
// ---------------------------------------------------------------------

ms_test_section('0. The routing schema is in place');

// Without this the suite would silently be testing the legacy path and every
// "nothing is queued" assertion below would pass for the wrong reason.
ms_test_check('0a. pro_webhook_addresses exists', tableHasColumn('pro_webhook_addresses', 'webhook_id'));
ms_test_check('0b. pro_webhooks.include_temporary exists', tableHasColumn('pro_webhooks', 'include_temporary'));
ms_test_check('0c. temp_emails.hooks_paused exists', tableHasColumn('temp_emails', 'hooks_paused'));

// ---------------------------------------------------------------------
// 1. A personal address queues exactly the hooks linked under it
// ---------------------------------------------------------------------

ms_test_section('1. A personal address queues exactly the hooks linked under it');

ms_wr_link($pdo, $hookG, $addrA);
ms_wr_link($pdo, $hookP, $addrA);
// hookH is deliberately left unlinked; hookPaused is not linked either.

$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce101'));

ms_test_same('1a. the linked generic hook is queued', 1, ms_wr_count($pdo, $userA, $hookG));
ms_test_same('1b. the linked Pushover hook is queued', 1, ms_wr_count($pdo, $userA, $hookP));
ms_test_same('1c. the unlinked generic hook is not queued', 0, ms_wr_count($pdo, $userA, $hookH));

$payloads = ms_test_delivery_payloads($pdo, $hookG);
ms_test_same('1d. the queued payload is the message routed to that address', 'a11ce101@' . MS_TEST_EMAIL_DOMAIN, $payloads[0]['to'] ?? null);

// ---------------------------------------------------------------------
// 2. An unlinked personal address queues nothing
// ---------------------------------------------------------------------

ms_test_section('2. An unlinked personal address queues nothing');

$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce102'));

ms_test_same('2a. the generic hook gains nothing', 1, ms_wr_count($pdo, $userA, $hookG));
ms_test_same('2b. the Pushover hook gains nothing', 1, ms_wr_count($pdo, $userA, $hookP));
ms_test_same('2c. the other generic hook stays quiet', 0, ms_wr_count($pdo, $userA, $hookH));

// ---------------------------------------------------------------------
// 3. A paused address queues nothing, whatever is linked to it
// ---------------------------------------------------------------------

ms_test_section('3. A paused address queues nothing, whatever is linked to it');

ms_wr_link($pdo, $hookG, $addrB);
ms_wr_pause_address($pdo, $addrB);

ms_test_forget_logs();
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce102'));

ms_test_same('3a. the linked generic hook is still quiet', 1, ms_wr_count($pdo, $userA, $hookG));
ms_test_same('3b. the Pushover hook is quiet too', 1, ms_wr_count($pdo, $userA, $hookP));
ms_test_check(
    '3c. the pause is the documented reason, not a lookup failure',
    ms_test_logged('Skipping webhooks: hooks are paused for this address'),
    'expected pause reason was not logged'
);

// Pausing is per address, so the unpaused address keeps working.
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce101'));
ms_test_same('3d. the unpaused address still queues its linked hook', 2, ms_wr_count($pdo, $userA, $hookG));

// ---------------------------------------------------------------------
// 4. A paused webhook is not queued even when the address is linked
// ---------------------------------------------------------------------

ms_test_section('4. A paused webhook is not queued even when the address is linked');

ms_wr_link($pdo, $hookPaused, $addrC);
ms_wr_link($pdo, $hookG, $addrC);

$pdo = ms_test_refresh_db($msSqlite);
$gBefore = ms_wr_count($pdo, $userA, $hookG);
ms_test_dispatch($userA, $msPayload('a11ce103'));

ms_test_same('4a. the paused hook gains no delivery', 0, ms_wr_count($pdo, $userA, $hookPaused));
ms_test_same('4b. the active hook linked to the same address gains one', $gBefore + 1, ms_wr_count($pdo, $userA, $hookG));

// ---------------------------------------------------------------------
// 5. A temporary address queues the include_temporary hooks only
// ---------------------------------------------------------------------

ms_test_section('5. A temporary address queues the include_temporary hooks only');

ms_wr_include_temporary($pdo, $hookT);
ms_wr_include_temporary($pdo, $hookP);

$pdo = ms_test_refresh_db($msSqlite);
$gBefore      = ms_wr_count($pdo, $userA, $hookG);
$hBefore      = ms_wr_count($pdo, $userA, $hookH);
$pausedBefore = ms_wr_count($pdo, $userA, $hookPaused);
$tBefore      = ms_wr_count($pdo, $userA, $hookT);
$pBefore      = ms_wr_count($pdo, $userA, $hookP);

ms_test_dispatch($userA, $msPayload('a11ce105'));

ms_test_same('5a. the include_temporary generic hook gains one', $tBefore + 1, ms_wr_count($pdo, $userA, $hookT));
ms_test_same('5b. the include_temporary Pushover hook gains one too (kind gets no special case)', $pBefore + 1, ms_wr_count($pdo, $userA, $hookP));
ms_test_same('5c. a generic hook without include_temporary stays quiet', $gBefore, ms_wr_count($pdo, $userA, $hookG));
ms_test_same('5d. the other generic hook stays quiet', $hBefore, ms_wr_count($pdo, $userA, $hookH));
ms_test_same('5e. the paused hook stays quiet', $pausedBefore, ms_wr_count($pdo, $userA, $hookPaused));

// The switch covers temporary addresses only: the same hook fires for none of
// the personal addresses, even the linked and unpaused one.
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userA, $msPayload('a11ce101'));
ms_test_same('5f. the temporary switch does not leak onto personal addresses', $tBefore + 1, ms_wr_count($pdo, $userA, $hookT));

// ---------------------------------------------------------------------
// 6. Another user's address queues nothing, linked or not
// ---------------------------------------------------------------------

ms_test_section("6. Another user's address queues nothing, linked or not");

// A's own hook is linked to B's address id: the link alone must not be enough,
// because dispatch scopes the address lookup to the user being dispatched for.
ms_wr_link($pdo, $hookG, $addrBob);
ms_wr_link($pdo, $hookP, $addrBob);

$pdo = ms_test_refresh_db($msSqlite);
$gBefore = ms_wr_count($pdo, $userA, $hookG);
$pBefore = ms_wr_count($pdo, $userA, $hookP);

ms_test_forget_logs();
ms_test_dispatch($userA, $msPayload('b0b00001'));

ms_test_same("6a. A's generic hook gains nothing for B's address", $gBefore, ms_wr_count($pdo, $userA, $hookG));
ms_test_same("6b. A's Pushover hook gains nothing for B's address", $pBefore, ms_wr_count($pdo, $userA, $hookP));
ms_test_check(
    '6c. the refusal reads as "not owned by this user", not as a lookup failure',
    ms_test_logged('No webhook routing: destination address not owned by this user'),
    'expected ownership reason was not logged'
);

// B is Pro and owns the address, so B's own dispatch is unaffected by any of it.
$hookB = ms_test_seed_webhook($pdo, $userB, 'generic', 'all', 'B hook');
ms_wr_link($pdo, $hookB, $addrBob);
$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userB, $msPayload('b0b00001'));
ms_test_same("6d. the owner's own linked hook is queued", 1, ms_wr_count($pdo, $userB, $hookB));

// ---------------------------------------------------------------------
// 7. A non-Pro account queues nothing even with a link
// ---------------------------------------------------------------------

ms_test_section('7. A non-Pro account queues nothing even with a link');

ms_wr_link($pdo, $hookFree, $addrFree);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_dispatch($userFree, $msPayload('ca401001'));

ms_test_same('7a. the linked hook of a non-Pro account is not queued', 0, ms_wr_count($pdo, $userFree, $hookFree));

// ---------------------------------------------------------------------
// 8. (removed)
//
// This section proved that a linked Pushover hook was queued even with the
// per-address Pushover flag off — i.e. that the routing path consulted no
// kind-specific gate. #251 step 6 deleted that gate, and the column with it,
// so a linked Pushover hook is simply one of the hooks in section 1 and there
// is no longer a second rule to tell apart. The numbering is left as it was so
// the remaining scenario labels keep matching the ones #254 and #255 shipped.
// ---------------------------------------------------------------------

// =====================================================================
// #251 step 4: the routing API
//
// Everything below drives the real pages through a real request, the way
// tests/pushover_routing_test.php does, so the origin check, the Pro gate, the
// ownership queries and the transaction under test are the pages' own code.
//
// The fixtures are new rather than the ones above: those sections deliberately
// left link rows, an include_temporary switch and a pause flag behind, and
// reusing them would make "nothing was written" unprovable.
// =====================================================================

$msBodies = [];
/** Run one probe request and remember its body. */
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
/** webhooks_list rows for one user, keyed by hook id. */
$msHooks = function (int $userId) use ($msAction): array {
    $response = $msAction('pro_profile.php', 'webhooks_list', [], $userId);
    $rows = [];
    foreach (($response['json']['webhooks'] ?? []) as $row) {
        $rows[(int) $row['id']] = $row;
    }
    return $rows;
};
/** list_personal rows for one user, keyed by address id. */
$msPersonal = function (int $userId) use ($msAction): array {
    $response = $msAction('index.php', 'list_personal', [], $userId);
    $rows = [];
    foreach (($response['json']['personal'] ?? []) as $row) {
        $rows[(int) $row['id']] = $row;
    }
    return $rows;
};

$apUser  = ms_test_seed_user($pdo, 'dora@example.com', 'pro');
$apOther = ms_test_seed_user($pdo, 'erik@example.com', 'pro');
$apFree  = ms_test_seed_user($pdo, 'frida@example.com', 'regular');

$apAddr1  = ms_test_seed_address($pdo, 'd0a00001', ['pro_user_id' => $apUser]);
$apAddr2  = ms_test_seed_address($pdo, 'd0a00002', ['pro_user_id' => $apUser]);
$apAddr3  = ms_test_seed_address($pdo, 'd0a00003', ['pro_user_id' => $apUser]);
$apTemp   = ms_test_seed_address($pdo, 'd0a00004', ['pro_user_id' => $apUser, 'is_personal' => 0]);
$apOtherA = ms_test_seed_address($pdo, 'e01c0001', ['pro_user_id' => $apOther]);
$apFreeA  = ms_test_seed_address($pdo, 'f01e0001', ['pro_user_id' => $apFree]);

$apHookA   = ms_test_seed_webhook($pdo, $apUser, 'generic', 'all', 'API hook A');
$apHookB   = ms_test_seed_webhook($pdo, $apUser, 'generic', 'all', 'API hook B');
$apHookOth = ms_test_seed_webhook($pdo, $apOther, 'generic', 'all', 'Other user hook');
$apHookFre = ms_test_seed_webhook($pdo, $apFree, 'generic', 'all', 'Non-Pro hook');

// ---------------------------------------------------------------------
// 9. webhooks_list reports the routing state
// ---------------------------------------------------------------------

ms_test_section('9. webhooks_list reports the routing state');

$list = ms_test_json('9a. webhooks_list answers', $msAction('pro_profile.php', 'webhooks_list', [], $apUser));
ms_test_same('9b. routing_available is true now that the schema is in place', true, $list['routing_available'] ?? null);

$hooks = $msHooks($apUser);
ms_test_same('9c. an unlinked hook reports an empty address set', [], $hooks[$apHookA]['address_ids'] ?? null);
ms_test_same('9d. and reports include_temporary=false', false, $hooks[$apHookA]['include_temporary'] ?? null);
ms_test_check(
    '9e. the secret is still never listed',
    !array_key_exists('secret', $hooks[$apHookA] ?? []),
    'the secret key survived the list'
);

// ---------------------------------------------------------------------
// 10. webhook_set_addresses replaces the whole set
// ---------------------------------------------------------------------

ms_test_section('10. webhook_set_addresses replaces the whole set');

// Sent out of order on purpose: the response must come back sorted, because the
// UI renders it directly and the list query has no ORDER BY of its own.
$result = ms_test_json('10a. the call answers', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookA, 'address_ids' => [$apAddr2, $apAddr1]], $apUser));
ms_test_same('10b. the write succeeded', true, $result['success'] ?? null);
ms_test_same('10c. the response lists the ids sorted', [$apAddr1, $apAddr2], $result['address_ids'] ?? null);
ms_test_same('10d. include_temporary is false when the field is omitted', false, $result['include_temporary'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('10e. exactly two link rows are stored', 2, ms_wr_link_count($pdo, $apHookA));

$hooks = $msHooks($apUser);
ms_test_same('10f. webhooks_list shows the new set', [$apAddr1, $apAddr2], $hooks[$apHookA]['address_ids'] ?? null);
ms_test_same("10g. the user's other hook is untouched", [], $hooks[$apHookB]['address_ids'] ?? null);

// A replacement, not a merge.
$result = ms_test_json('10h. a second call answers', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookA, 'address_ids' => [$apAddr3], 'include_temporary' => '1'], $apUser));
ms_test_same('10i. the second write succeeded', true, $result['success'] ?? null);
ms_test_same('10j. the response reports the new single id', [$apAddr3], $result['address_ids'] ?? null);
ms_test_same('10k. include_temporary is on when the field is "1"', true, $result['include_temporary'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('10l. the previous links are gone, not merged', 1, ms_wr_link_count($pdo, $apHookA));

$hooks = $msHooks($apUser);
ms_test_same('10m. the list shows the set was replaced', [$apAddr3], $hooks[$apHookA]['address_ids'] ?? null);
ms_test_same('10n. the list shows include_temporary persisted', true, $hooks[$apHookA]['include_temporary'] ?? null);

// An empty list clears the hook.
$result = ms_test_json('10o. an empty set answers', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookA, 'address_ids' => []], $apUser));
ms_test_same('10p. clearing succeeded', true, $result['success'] ?? null);
ms_test_same('10q. the response reports no addresses', [], $result['address_ids'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('10r. no link rows remain', 0, ms_wr_link_count($pdo, $apHookA));

$hooks = $msHooks($apUser);
ms_test_same('10s. the list agrees the hook is unlinked', [], $hooks[$apHookA]['address_ids'] ?? null);
ms_test_same('10t. include_temporary is off again, the field having defaulted to false', false, $hooks[$apHookA]['include_temporary'] ?? null);

// Malformed and oversized input is refused before anything is written.
$result = ms_test_json('10u. a non-array address_ids is refused', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookA, 'address_ids' => 'nope'], $apUser));
ms_test_same('10v. the refusal is "Invalid request"', 'Invalid request', $result['error'] ?? null);

$result = ms_test_json('10w. more than 50 addresses is refused', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookA, 'address_ids' => range(100000, 100050)], $apUser));
ms_test_same('10x. the refusal is "Too many addresses"', 'Too many addresses', $result['error'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('10y. neither refusal wrote a link row', 0, ms_wr_link_count($pdo, $apHookA));

// ---------------------------------------------------------------------
// 11. Ownership of the hook and of every address is enforced
// ---------------------------------------------------------------------

ms_test_section('11. Ownership of the hook and of every address is enforced');

$result = ms_test_json("11a. A cannot set addresses on B's hook", $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookOth, 'address_ids' => [$apOtherA]], $apUser));
ms_test_same('11b. the write is refused', false, $result['success'] ?? null);
ms_test_same('11c. and does not disclose whether the hook exists', 'Not found', $result['error'] ?? null);

$result = ms_test_json("11d. A cannot link B's address to A's own hook", $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookB, 'address_ids' => [$apOtherA]], $apUser));
ms_test_same('11e. the write is refused', false, $result['success'] ?? null);
ms_test_same('11f. and reads as "Address not found"', 'Address not found', $result['error'] ?? null);

// A mixed list is refused whole: the one id the user does own must not be
// written either, or the hook would be left in a state nobody asked for.
$result = ms_test_json('11g. a mixed list of owned and unowned ids is refused', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookB, 'address_ids' => [$apAddr1, $apOtherA]], $apUser));
ms_test_same('11h. the write is refused', false, $result['success'] ?? null);
ms_test_same('11i. and reads as "Address not found"', 'Address not found', $result['error'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same("11j. nothing was written for the other user's hook", 0, ms_wr_link_count($pdo, $apHookOth));
ms_test_same("11k. nothing was written for A's own hook, not even the owned id", 0, ms_wr_link_count($pdo, $apHookB));

// ---------------------------------------------------------------------
// 12. A temporary address cannot be linked
// ---------------------------------------------------------------------

ms_test_section('12. A temporary address cannot be linked');

$result = ms_test_json('12a. A cannot link its own temporary address', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookB, 'address_ids' => [$apTemp]], $apUser));
ms_test_same('12b. the write is refused', false, $result['success'] ?? null);
ms_test_same('12c. the refusal reads as "Address not found"', 'Address not found', $result['error'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('12d. nothing was written', 0, ms_wr_link_count($pdo, $apHookB));

// ---------------------------------------------------------------------
// 13. A non-Pro account is refused by the Pro gate
// ---------------------------------------------------------------------

ms_test_section('13. A non-Pro account is refused by the Pro gate');

$result = ms_test_json('13a. a non-Pro owner is refused', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookFre, 'address_ids' => [$apFreeA]], $apFree));
ms_test_same('13b. the write is refused', false, $result['success'] ?? null);
ms_test_same('13c. the refusal is the Pro gate', 'Pro required', $result['error'] ?? null);
ms_test_same('13d. and is flagged pro_required', true, $result['pro_required'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('13e. nothing was written', 0, ms_wr_link_count($pdo, $apHookFre));

// ---------------------------------------------------------------------
// 14. Per-address hook pause and resume
// ---------------------------------------------------------------------

ms_test_section('14. Per-address hook pause and resume');

$personal = $msPersonal($apUser);
ms_test_same('14a. the address starts unpaused', false, $personal[$apAddr1]['hooks_paused'] ?? null);

$result = ms_test_json('14b. address_hooks_pause answers', $msAction('pro_profile.php', 'address_hooks_pause', ['id' => $apAddr1], $apUser));
ms_test_same('14c. the write succeeded', true, $result['success'] ?? null);
ms_test_same('14d. the response reports the new state', true, $result['hooks_paused'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('14e. the flag is on in the database', 1, ms_wr_hooks_paused($pdo, $apAddr1));

$personal = $msPersonal($apUser);
ms_test_same('14f. list_personal reports the pause', true, $personal[$apAddr1]['hooks_paused'] ?? null);
ms_test_same('14g. the neighbouring address is still unpaused', false, $personal[$apAddr2]['hooks_paused'] ?? null);

$result = ms_test_json('14h. address_hooks_resume answers', $msAction('pro_profile.php', 'address_hooks_resume', ['id' => $apAddr1], $apUser));
ms_test_same('14i. the write succeeded', true, $result['success'] ?? null);
ms_test_same('14j. the response reports the new state', false, $result['hooks_paused'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('14k. the flag is off in the database', 0, ms_wr_hooks_paused($pdo, $apAddr1));

$personal = $msPersonal($apUser);
ms_test_same('14l. list_personal agrees the pause is cleared', false, $personal[$apAddr1]['hooks_paused'] ?? null);

// Ownership and the Pro gate hold for the pause pair too.
$result = ms_test_json("14m. another user cannot pause A's address", $msAction('pro_profile.php', 'address_hooks_pause', ['id' => $apAddr1], $apOther));
ms_test_same('14n. the write is refused', false, $result['success'] ?? null);
ms_test_same('14o. and reads as "Address not found"', 'Address not found', $result['error'] ?? null);

$result = ms_test_json('14p. a temporary address cannot be paused', $msAction('pro_profile.php', 'address_hooks_pause', ['id' => $apTemp], $apUser));
ms_test_same('14q. the write is refused', false, $result['success'] ?? null);
ms_test_same('14r. and reads as "Address not found"', 'Address not found', $result['error'] ?? null);

$result = ms_test_json('14s. a non-Pro account cannot pause its own address', $msAction('pro_profile.php', 'address_hooks_pause', ['id' => $apFreeA], $apFree));
ms_test_same('14t. the write is refused', false, $result['success'] ?? null);
ms_test_same('14u. and is refused by the Pro gate', 'Pro required', $result['error'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('14v. no refusal moved a flag', 0, ms_wr_hooks_paused($pdo, $apAddr1) + ms_wr_hooks_paused($pdo, $apTemp) + ms_wr_hooks_paused($pdo, $apFreeA));

// ---------------------------------------------------------------------
// 15. Deleting a hook or an address leaves no link rows
// ---------------------------------------------------------------------

ms_test_section('15. Deleting a hook or an address leaves no link rows');

$result = ms_test_json('15a. the address is linked through the API', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookB, 'address_ids' => [$apAddr3]], $apUser));
ms_test_same('15b. the write succeeded', true, $result['success'] ?? null);

// A second link from *another user's* hook, written straight into the table
// because the API rightly refuses to create that state. It is the shape a
// hand-written or pre-migration row would have, and it must be cleaned up too.
$pdo = ms_test_refresh_db($msSqlite);
ms_wr_link($pdo, $apHookOth, $apAddr3);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('15c. the address now has two link rows', 2, ms_wr_address_link_count($pdo, $apAddr3));

$result = ms_test_json('15d. delete_personal answers', $msAction('index.php', 'delete_personal', ['id' => $apAddr3], $apUser));
ms_test_same('15e. the delete succeeded', true, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_check('15f. the address row is gone', !ms_test_address_exists($pdo, $apAddr3));
ms_test_same('15g. its link rows are gone, including the one the API would not create', 0, ms_wr_address_link_count($pdo, $apAddr3));
ms_test_same("15h. A's surviving hook lost its link to it", 0, ms_wr_link_count($pdo, $apHookB));

// Deleting the hook removes its links — and only its own.
$result = ms_test_json('15i. the hook is linked again', $msAction('pro_profile.php', 'webhook_set_addresses', ['id' => $apHookA, 'address_ids' => [$apAddr1, $apAddr2]], $apUser));
ms_test_same('15j. the write succeeded', true, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('15k. two link rows are stored', 2, ms_wr_link_count($pdo, $apHookA));

$result = ms_test_json('15l. webhook_delete answers', $msAction('pro_profile.php', 'webhook_delete', ['id' => $apHookA], $apUser));
ms_test_same('15m. the delete succeeded', true, $result['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_check('15n. the hook row is gone', !ms_wr_hook_exists($pdo, $apHookA));
ms_test_same('15o. its link rows are gone too', 0, ms_wr_link_count($pdo, $apHookA));
ms_test_check(
    '15p. the addresses themselves survive their hook',
    ms_test_address_exists($pdo, $apAddr1) && ms_test_address_exists($pdo, $apAddr2),
    'deleting a hook took an address with it'
);

// ---------------------------------------------------------------------
// 16. pro_profile_page.php renders the routing UI
// ---------------------------------------------------------------------

ms_test_section('16. pro_profile_page.php renders the routing UI');

// The page's PHP→JS contract, as the two removed scenarios of
// tests/pushover_routing_test.php asserted it before the per-address Pushover
// control was replaced by the routing UI (#251 step 5). The page's control is
// assembled in the browser and this suite has no JS engine, so what is asserted
// is the rendered flag the script consumes, not a DOM node.
//
// A separate user, because "no webhooks" has to be provable and every fixture
// above already owns one.
$renderUser = ms_test_seed_user($pdo, 'greta@example.com', 'pro');

$render = $msRun(['page' => 'pro_profile_page.php', 'method' => 'GET', 'user_id' => $renderUser, 'user_email' => 'greta@example.com']);
ms_test_no_php_errors('16a. pro_profile_page.php renders without PHP errors', $render);
ms_test_check(
    '16b. hasAnyWebhook is false with no webhooks at all',
    str_contains($render['stdout'], 'var hasAnyWebhook = false;'),
    'rendered flag not found'
);
ms_test_check(
    '16c. hookRoutingAvailable is true now that the schema is in place',
    str_contains($render['stdout'], 'var hookRoutingAvailable = true;'),
    'the page did not see the routing schema this suite applied'
);

// Configured but paused, and generic rather than Pushover: the gate is "has any
// webhook", so neither the paused state nor the kind may be consulted.
ms_test_seed_webhook($pdo, $renderUser, 'generic', 'paused', 'Paused generic only');

$render = $msRun(['page' => 'pro_profile_page.php', 'method' => 'GET', 'user_id' => $renderUser, 'user_email' => 'greta@example.com']);
ms_test_no_php_errors('16d. pro_profile_page.php still renders cleanly', $render);
ms_test_check(
    '16e. a single paused generic webhook is enough for hasAnyWebhook to be true',
    str_contains($render['stdout'], 'var hasAnyWebhook = true;'),
    'a paused generic webhook did not make the address control available'
);

// The retired per-address Pushover control and its server-side flag must be
// gone from the page entirely, not merely hidden. The per-address Pushover
// actions themselves went with #251 step 6; what is asserted here is the
// rendered page, not the actions.
$leaked = [];
foreach (['hasPushoverWebhook'] as $needle) {
    if (str_contains($render['stdout'], $needle)) {
        $leaked[] = $needle;
    }
}
ms_test_check(
    '16f. the page does not carry the removed flag',
    $leaked === [],
    'still present: ' . implode(', ', $leaked)
);

// ---------------------------------------------------------------------
// Regression: the per-address RSS feed still behaves (#160/#161)
//
// Moved here from tests/pushover_routing_test.php when #251 step 6 retired the
// per-address Pushover opt-in. The feed and the routing are independent
// per-address features, and these checks outlived the suite that hosted them;
// the fixtures are this suite's own, the assertions are the ones that shipped.
// ---------------------------------------------------------------------

ms_test_section('Regression: per-address RSS feed is unaffected');

$rfUser    = ms_test_seed_user($pdo, 'hanna@example.com', 'pro');
$rfAddrOn  = ms_test_seed_address($pdo, 'feed0001', ['pro_user_id' => $rfUser, 'feed_token' => 'feed-fixture-token-0001']);
$rfAddrOff = ms_test_seed_address($pdo, 'feed0002', ['pro_user_id' => $rfUser]);

// R12 asserts that a feed change leaves the address' other per-address state
// alone, so that state is set first — otherwise "untouched" would hold for a
// value that was never anything else. The retired per-address Pushover flag
// used to play this role; temp_emails.hooks_paused is what the model has now.
ms_wr_pause_address($pdo, $rfAddrOn);

$list = ms_test_json('R1. list_personal answers', $msAction('index.php', 'list_personal', [], $rfUser));
$byId = [];
foreach (($list['personal'] ?? []) as $row) {
    $byId[(int) $row['id']] = $row;
}
ms_test_same('R2. a feed-enabled address still reports feed_enabled=true', true, $byId[$rfAddrOn]['feed_enabled'] ?? null);
ms_test_same('R3. an address without a feed still reports feed_enabled=false', false, $byId[$rfAddrOff]['feed_enabled'] ?? null);
ms_test_check(
    'R4. list_personal still never returns the feed credential itself',
    !array_key_exists('feed_token', $byId[$rfAddrOn] ?? []) && !str_contains($list === null ? '' : (string) json_encode($list), 'feed-fixture-token-0001'),
    'the credential leaked into the address list'
);

$feed = ms_test_json('R5. address_feed_get_token still returns the existing token', $msAction('pro_profile.php', 'address_feed_get_token', ['id' => $rfAddrOn], $rfUser));
ms_test_same('R6. the stored token is returned unchanged', 'feed-fixture-token-0001', $feed['token'] ?? null);

$feed = ms_test_json('R7. address_feed_regenerate still answers', $msAction('pro_profile.php', 'address_feed_regenerate', ['id' => $rfAddrOn], $rfUser));
ms_test_check('R8. regeneration returns a new token', is_string($feed['token'] ?? null) && $feed['token'] !== 'feed-fixture-token-0001');

$feed = ms_test_json('R9. address_feed_disable still answers', $msAction('pro_profile.php', 'address_feed_disable', ['id' => $rfAddrOn], $rfUser));
ms_test_same('R10. disabling the feed succeeded', true, $feed['success'] ?? null);

$pdo = ms_test_refresh_db($msSqlite);
$stmt = $pdo->prepare('SELECT feed_token FROM temp_emails WHERE id = ?');
$stmt->execute([$rfAddrOn]);
ms_test_same('R11. the feed token is cleared', null, $stmt->fetchColumn() ?: null);
ms_test_same("R12. clearing the feed left the address' hook pause alone", 1, ms_wr_hooks_paused($pdo, $rfAddrOn));

// ---------------------------------------------------------------------
// CSRF: cross-origin and GET requests never reach a mutating action
//
// pro_profile.php lets only its pure reads through without a same-origin
// POST; index.php requires a same-origin POST for every action. The
// fixtures are the R-section's: $rfAddrOff has no feed token and is a live
// personal address, so "nothing was written" is directly observable.
// ---------------------------------------------------------------------

ms_test_section('CSRF gates');

/** One probe request as $userId with an explicit method and Origin. */
$msForged = function (string $page, string $method, ?string $origin, array $fields, int $userId) use ($msRun): array {
    $request = [
        'page' => $page,
        'method' => $method,
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'origin' => $origin,
    ];
    if ($method === 'GET') {
        $request['get'] = $fields;
    } else {
        $request['post'] = $fields;
    }
    return $msRun($request);
};
$msFeedToken = function (int $addressId) use ($msSqlite): ?string {
    $pdo = ms_test_refresh_db($msSqlite);
    $stmt = $pdo->prepare('SELECT feed_token FROM temp_emails WHERE id = ?');
    $stmt->execute([$addressId]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null || $value === '' ? null : (string) $value;
};
$msHookCount = function (int $userId) use ($msSqlite): int {
    $pdo = ms_test_refresh_db($msSqlite);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pro_webhooks WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
};
$msAddressExists = function (int $addressId) use ($msSqlite): bool {
    $pdo = ms_test_refresh_db($msSqlite);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM temp_emails WHERE id = ?');
    $stmt->execute([$addressId]);
    return (int) $stmt->fetchColumn() === 1;
};

$regen = ['action' => 'address_feed_regenerate', 'id' => $rfAddrOff];

$res = ms_test_json('X1. a cross-origin address_feed_regenerate answers', $msForged('pro_profile.php', 'POST', 'https://evil.example', $regen, $rfUser));
ms_test_same('X2. ... and is refused', 'Forbidden', $res['error'] ?? null);
ms_test_same('X3. ... and mints no token', null, $msFeedToken($rfAddrOff));

$res = ms_test_json('X4. an address_feed_regenerate without Origin answers', $msForged('pro_profile.php', 'POST', null, $regen, $rfUser));
ms_test_same('X5. ... and is refused', 'Forbidden', $res['error'] ?? null);

$res = ms_test_json('X6. an address_feed_regenerate with Origin: null answers', $msForged('pro_profile.php', 'POST', 'null', $regen, $rfUser));
ms_test_same('X7. ... and is refused', 'Forbidden', $res['error'] ?? null);

$res = ms_test_json('X8. a same-origin GET address_feed_regenerate answers', $msForged('pro_profile.php', 'GET', MS_TEST_ORIGIN, $regen, $rfUser));
ms_test_same('X9. ... and is refused as the wrong method', 'Method not allowed', $res['error'] ?? null);
ms_test_same('X10. no forged request minted a token', null, $msFeedToken($rfAddrOff));

$res = ms_test_json('X11. a GET feed_get_token answers', $msForged('pro_profile.php', 'GET', MS_TEST_ORIGIN, ['action' => 'feed_get_token'], $rfUser));
ms_test_same('X12. ... and is refused, since it can mint a token', 'Method not allowed', $res['error'] ?? null);

$hooksBefore = $msHookCount($rfUser);
$res = ms_test_json('X13. a cross-origin webhook_create answers', $msForged('pro_profile.php', 'POST', 'https://evil.example', [
    'action' => 'webhook_create', 'name' => 'forged', 'url' => 'https://evil.example/hook', 'kind' => 'generic',
], $rfUser));
ms_test_same('X14. ... and is refused', 'Forbidden', $res['error'] ?? null);
ms_test_same('X15. ... and creates no webhook', $hooksBefore, $msHookCount($rfUser));

$res = ms_test_json('X16. a same-origin GET webhooks_list (a pure read) still answers', $msForged('pro_profile.php', 'GET', MS_TEST_ORIGIN, ['action' => 'webhooks_list'], $rfUser));
ms_test_same('X17. ... successfully', true, $res['success'] ?? null);

$res = ms_test_json('X18. a same-origin POST address_feed_regenerate still answers', $msForged('pro_profile.php', 'POST', MS_TEST_ORIGIN, $regen, $rfUser));
ms_test_same('X19. ... successfully', true, $res['success'] ?? null);
ms_test_check('X20. ... and mints a token', $msFeedToken($rfAddrOff) !== null);

$res = ms_test_json('X21. a cross-origin index.php delete_personal answers', $msForged('index.php', 'POST', 'https://evil.example', ['action' => 'delete_personal', 'id' => $rfAddrOff], $rfUser));
ms_test_same('X22. ... and is refused', 'Forbidden', $res['error'] ?? null);
ms_test_check('X23. ... and the address still exists', $msAddressExists($rfAddrOff));

$res = ms_test_json('X24. a cross-origin index.php generate answers', $msForged('index.php', 'POST', 'https://manjo.me.evil.example', ['action' => 'generate'], $rfUser));
ms_test_same('X25. ... and is refused (host must match exactly)', 'Forbidden', $res['error'] ?? null);

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
