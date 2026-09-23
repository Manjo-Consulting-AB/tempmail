<?php

declare(strict_types=1);

/**
 * Regression coverage for the per-hook address routing (epic #251, step 3).
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
 * The complement of this suite is tests/pushover_routing_test.php, which runs
 * the *pre-routing* path: it deliberately leaves the routing schema off, so
 * between the two suites both branches of hookRoutingAvailable() are exercised.
 *
 * The one honest limit: the routing rules the epic decides are asserted here
 * through dispatch only. The API that writes the links is #251 step 4 and the
 * UI that shows them is step 5; nothing here covers those.
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

function ms_wr_set_pushover_enabled(PDO $pdo, int $addressId, int $value): void {
    $stmt = $pdo->prepare('UPDATE temp_emails SET pushover_enabled = ? WHERE id = ?');
    $stmt->execute([$value, $addressId]);
}

/** Deliveries one webhook has queued, 0 when it has none. */
function ms_wr_count(PDO $pdo, int $userId, int $webhookId): int {
    return ms_test_deliveries($pdo, $userId)[$webhookId] ?? 0;
}

echo "Mail Shield — per-hook address routing (#251 step 3)\n";
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
// 8. pushover_enabled has no effect once routing is available
// ---------------------------------------------------------------------

ms_test_section('8. pushover_enabled has no effect once routing is available');

ms_wr_link($pdo, $hookP, $addrD);
ms_wr_set_pushover_enabled($pdo, $addrD, 0);

$pdo = ms_test_refresh_db($msSqlite);
ms_test_same('8a. the address really is opted out of the retired flag', 0, ms_test_pushover_flag($pdo, $addrD));

$pBefore = ms_wr_count($pdo, $userA, $hookP);
ms_test_forget_logs();
ms_test_dispatch($userA, $msPayload('a11ce104'));

ms_test_same('8b. the linked Pushover hook is queued anyway', $pBefore + 1, ms_wr_count($pdo, $userA, $hookP));
ms_test_check(
    '8c. no kind-specific Pushover gate runs on the routing path',
    !ms_test_logged('destination address has Pushover disabled'),
    'the retired per-address gate was still consulted'
);
ms_test_same('8d. an unlinked hook on the same dispatch stays quiet', 0, ms_wr_count($pdo, $userA, $hookH));

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
