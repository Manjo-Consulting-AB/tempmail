<?php

declare(strict_types=1);

/**
 * Regression coverage for the Buy Me a Coffee webhook decision logic
 * (bmac_logic.php, used by bmac_handler.php): the strict grant allowlist,
 * the nested-envelope vs legacy flat payload shapes, email normalisation,
 * the amount/currency/status checks, live_mode=false test events and the
 * replay-protection key.
 *
 * Run with:  php tests/bmac_handler_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Pure functions only: no
 * database, no network. The nested payloads follow the published Buy Me a
 * Coffee webhook spec (EventEnvelope with the event fields under "data").
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bmac_logic.php';

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

/** A spec-shaped envelope; $data overrides/extends the default donation fields. */
function envelope(string $type, array $data = [], array $top = []): array
{
    return array_merge([
        'event_id' => 1234,
        'type' => $type,
        'live_mode' => true,
        'created' => 1719825600,
        'attempt' => 1,
        'data' => array_merge([
            'id' => 98765,
            'object' => 'payment',
            'status' => 'succeeded',
            'refunded' => 'false',
            'amount' => 15,
            'currency' => 'USD',
            'supporter_name' => 'Alex',
            'supporter_email' => 'alex@example.com',
            'refunded_at' => null,
        ], $data),
    ], $top);
}

function decide(array $payload, string $env = 'production'): array
{
    $event = bmacParseEvent($payload, (string) json_encode($payload));
    return bmacClassifyEvent($event, $env);
}

// ---------------------------------------------------------------------------
// Allowlist
// ---------------------------------------------------------------------------

foreach (['donation.created', 'membership.started', 'recurring_donation.started'] as $type) {
    same("A1. {$type} grants", 'grant', decide(envelope($type, ['status' => 'active']))['action']);
}
foreach (['payment.completed', 'one_time_support'] as $type) {
    same("A2. legacy {$type} grants", 'grant', decide(envelope($type))['action']);
}

foreach ([
    'donation.refunded', 'extra_purchase.created', 'extra_purchase.refunded',
    'commission_order.refunded', 'wishlist_payment.refunded',
    'membership.updated', 'membership.cancelled', 'membership.paused',
    'recurring_donation.updated', 'recurring_donation.cancelled',
    'something.new', '',
] as $type) {
    $d = decide(envelope($type));
    same("A3. '{$type}' with supporter_email is ignored", 'ignore', $d['action']);
}

// The old handler granted any event carrying supporter_email, typed or not.
$flatUntyped = ['supporter_email' => 'alex@example.com', 'amount' => 5, 'id' => 1];
same('A4. untyped flat payload with supporter_email is ignored', 'ignore', decide($flatUntyped)['action']);
same('A5. flat refund with supporter_email is ignored', 'ignore',
    decide(['type' => 'refund', 'supporter_email' => 'alex@example.com', 'amount' => 5])['action']);

// ---------------------------------------------------------------------------
// Payload shapes
// ---------------------------------------------------------------------------

$nested = bmacParseEvent(envelope('donation.created'), '');
same('S1. nested: email read from data', 'alex@example.com', $nested['email']);
same('S2. nested: amount read from data', 15.0, $nested['amount']);
same('S3. nested: currency read from data', 'USD', $nested['currency']);
same('S4. nested: id read from data', '98765', $nested['bmac_id']);
same('S5. nested flag', true, $nested['nested']);

$flat = ['type' => 'payment.completed', 'supporter_email' => 'bob@example.com', 'total_amount' => '3.50', 'currency' => 'eur', 'id' => 'p_1'];
$flatEvent = bmacParseEvent($flat, (string) json_encode($flat));
same('S6. flat: email read from the top level', 'bob@example.com', $flatEvent['email']);
same('S7. flat: total_amount accepted', 3.5, $flatEvent['amount']);
same('S8. flat: currency uppercased', 'EUR', $flatEvent['currency']);
same('S9. flat payload grants', 'grant', bmacClassifyEvent($flatEvent, 'production')['action']);
same('S10. flat: email fallback field', 'carol@example.com',
    bmacParseEvent(['type' => 'payment.completed', 'email' => 'carol@example.com'], '')['email']);
same('S11. nested wins over a stray top-level email', 'alex@example.com',
    bmacParseEvent(envelope('donation.created', [], ['supporter_email' => 'evil@example.com']), '')['email']);

// ---------------------------------------------------------------------------
// Email normalisation
// ---------------------------------------------------------------------------

same('E1. mixed case and whitespace', 'john.doe+tag@example.com', bmacNormalizeEmail("  John.Doe+Tag@Example.COM \n"));
same('E2. invalid address is empty', '', bmacNormalizeEmail('not-an-email'));
same('E3. non-string is empty', '', bmacNormalizeEmail(['a@b.c']));
same('E4. mixed-case nested email is normalised', 'alex@example.com',
    bmacParseEvent(envelope('donation.created', ['supporter_email' => ' ALEX@Example.com']), '')['email']);
$noEmail = decide(envelope('donation.created', ['supporter_email' => 'nope']));
same('E5. grantable event with a bad email is invalid (400)', 'invalid', $noEmail['action']);
same('E6. ignored event with a bad email stays ignored (200)', 'ignore',
    decide(envelope('donation.refunded', ['supporter_email' => '']))['action']);

// ---------------------------------------------------------------------------
// Amount, currency, status
// ---------------------------------------------------------------------------

same('M1. zero amount is ignored', 'ignore', decide(envelope('donation.created', ['amount' => 0]))['action']);
same('M2. negative amount is ignored', 'ignore', decide(envelope('donation.created', ['amount' => -5]))['action']);
$noAmount = envelope('donation.created');
unset($noAmount['data']['amount']);
same('M3. missing amount is ignored', 'ignore', decide($noAmount)['action']);
same('M4. non-numeric amount is ignored', 'ignore', decide(envelope('donation.created', ['amount' => 'lots']))['action']);
$chargedOnly = envelope('donation.created');
unset($chargedOnly['data']['amount']);
$chargedOnly['data']['total_amount_charged'] = 15;
same('M5. total_amount_charged is used when amount is absent', 'grant', decide($chargedOnly)['action']);
same('M6. garbage currency is ignored', 'ignore', decide(envelope('donation.created', ['currency' => 'US Dollars']))['action']);
$noCurrency = envelope('donation.created');
unset($noCurrency['data']['currency']);
same('M7. missing currency is tolerated', 'grant', decide($noCurrency)['action']);
same('M8. refunded="true" on a created event is ignored', 'ignore',
    decide(envelope('donation.created', ['refunded' => 'true']))['action']);
same('M9. status=refunded is ignored', 'ignore', decide(envelope('donation.created', ['status' => 'refunded']))['action']);
same('M10. membership.started with status=canceled is ignored', 'ignore',
    decide(envelope('membership.started', ['status' => 'canceled']))['action']);
same('M11. refunded_at set is ignored', 'ignore', decide(envelope('donation.created', ['refunded_at' => 1719825700]))['action']);

// ---------------------------------------------------------------------------
// live_mode
// ---------------------------------------------------------------------------

$test = envelope('donation.created', [], ['live_mode' => false]);
$d = decide($test, 'production');
same('L1. live_mode=false is ignored in production', 'ignore', $d['action']);
same('L2. ... with reason "test event"', 'test event', $d['reason'] ?? null);
same('L3. live_mode=false grants outside production', 'grant', decide($test, 'development')['action']);
same('L4. live_mode="false" string is treated as false', 'ignore',
    decide(envelope('donation.created', [], ['live_mode' => 'false']), 'production')['action']);
same('L5. absent live_mode (legacy) is not treated as a test', 'grant',
    decide(['type' => 'payment.completed', 'supporter_email' => 'a@example.com', 'amount' => 5], 'production')['action']);

// ---------------------------------------------------------------------------
// Replay protection key
// ---------------------------------------------------------------------------

$first = bmacParseEvent(envelope('donation.created'), 'x')['dedupe_key'];
$retry = bmacParseEvent(envelope('donation.created', [], ['event_id' => 5555, 'attempt' => 2]), 'y')['dedupe_key'];
same('D1. nested key is type:id', 'donation.created:98765', $first);
same('D2. a retry (new attempt/event_id) keeps the same key', $first, $retry);
check('D3. a different event type on the same id gets a different key',
    $first !== bmacParseEvent(envelope('membership.started'), '')['dedupe_key']);
$noIdA = envelope('donation.created');
unset($noIdA['data']['id']);
$noIdB = $noIdA;
$noIdB['attempt'] = 3;
same('D4. nested without id: key ignores attempt', bmacParseEvent($noIdA, 'a')['dedupe_key'], bmacParseEvent($noIdB, 'b')['dedupe_key']);
same('D5. legacy flat key is the id, as before', 'p_1', $flatEvent['dedupe_key']);
same('D6. legacy flat without id hashes the raw body, as before', hash('sha256', 'RAW'),
    bmacParseEvent(['type' => 'payment.completed'], 'RAW')['dedupe_key']);
check('D7. key fits VARCHAR(191)',
    strlen(bmacParseEvent(envelope('donation.created', ['id' => str_repeat('9', 400)]), '')['dedupe_key']) <= 191);

// ---------------------------------------------------------------------------
// Handler wiring (static checks on bmac_handler.php)
// ---------------------------------------------------------------------------

$handler = (string) file_get_contents(__DIR__ . '/../bmac_handler.php');
check('H1. handler loads bmac_logic.php', strpos($handler, "require_once __DIR__ . '/bmac_logic.php';") !== false);
check('H2. handler classifies through bmacClassifyEvent()', strpos($handler, 'bmacClassifyEvent(') !== false);
check('H3. handler has no bare return that would leave the transaction open', preg_match('/^\s*return\s*;/m', $handler) !== 1);
check('H4. response no longer echoes the email', strpos($handler, "'email' => \$email,\n        'pro_expires_at'") === false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
