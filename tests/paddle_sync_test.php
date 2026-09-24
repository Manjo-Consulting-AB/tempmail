<?php

declare(strict_types=1);

/**
 * Regression coverage for the Paddle Billing → pro_users sync (paddle_sync.php).
 *
 * Run with:  php tests/paddle_sync_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Needs pdo_sqlite and nothing
 * else — no MySQL, no network, no Paddle credentials. The mirror tables come
 * from paddleSchemaStatements('sqlite'), the same DDL migrate_paddle_billing.php
 * runs against MySQL, and every event goes through the real paddleHandleEvent().
 * The payloads have the shape of real sandbox objects (subscription, one-time
 * transaction, customer) trimmed to the fields the sync reads.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
date_default_timezone_set('Europe/Stockholm');   // as config.php
require dirname(__DIR__) . '/paddle_sync.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  [pass] ' : '  [FAIL] ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
}

const PRICE_MONTH = 'pri_month';
const PRICE_YEAR = 'pri_year';
const PRICE_LIFETIME = 'pri_lifetime';
const NOW = 1790000000;   // fixed clock: 2026-09-21

$prices = paddlePlanPriceIds([
    ['priceId' => ['month' => PRICE_MONTH, 'year' => PRICE_YEAR]],
    ['priceId' => ['once' => PRICE_LIFETIME]],
]);

function freshDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, account_type TEXT NOT NULL DEFAULT 'regular', pro_expires_at TEXT NULL)");
    foreach (paddleSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}

function addUser(PDO $pdo, int $id, string $email, string $type = 'regular', ?string $expires = '2020-01-01 00:00:00'): void
{
    $pdo->prepare('INSERT INTO pro_users (id, email, account_type, pro_expires_at) VALUES (?, ?, ?, ?)')->execute([$id, $email, $type, $expires]);
}

function user(PDO $pdo, int $id): array
{
    return paddleFetch($pdo, 'SELECT account_type, pro_expires_at FROM pro_users WHERE id = ?', [$id]);
}

function iso(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s', $ts) . '.000000Z';
}

function local(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

function subEvent(string $status, array $over = [], int $occurred = NOW): array
{
    $data = array_replace([
        'id' => 'sub_1',
        'status' => $status,
        'customer_id' => 'ctm_1',
        'custom_data' => ['pro_user_id' => '42'],
        'current_billing_period' => ['starts_at' => iso(NOW), 'ends_at' => iso(NOW + 365 * 86400)],
        'scheduled_change' => null,
        'canceled_at' => null,
        'paused_at' => null,
        'items' => [['status' => 'active', 'quantity' => 1, 'price' => ['id' => PRICE_YEAR, 'billing_cycle' => ['interval' => 'year', 'frequency' => 1]]]],
    ], $over);
    return ['event_id' => 'evt_' . md5(json_encode([$status, $over, $occurred])), 'event_type' => 'subscription.updated', 'occurred_at' => iso($occurred), 'data' => $data];
}

function lifetimeEvent(array $over = []): array
{
    return ['event_id' => 'evt_txn', 'event_type' => 'transaction.completed', 'occurred_at' => iso(NOW), 'data' => array_replace([
        'id' => 'txn_1', 'status' => 'completed', 'customer_id' => 'ctm_1', 'subscription_id' => null,
        'custom_data' => ['pro_user_id' => '42'],
        'items' => [['quantity' => 1, 'price' => ['id' => PRICE_LIFETIME, 'billing_cycle' => null]]],
    ], $over)];
}

function run(PDO $pdo, array $event, array $prices, int $now = NOW): string
{
    return paddleHandleEvent($pdo, $event, ['prices' => $prices, 'has_account_type' => true, 'now' => $now]);
}

$periodEnd = local(NOW + 365 * 86400);

echo "Mail Shield — Paddle Billing sync\n";

// ---------------------------------------------------------------------
echo "\n1. Signature verification\n";
$body = '{"event_type":"subscription.created"}';
$secret = 'pdl_ntfset_test_secret';
$ts = NOW;
$good = hash_hmac('sha256', $ts . ':' . $body, $secret);
check('valid signature accepted', paddleVerifySignature($body, "ts={$ts};h1={$good}", $secret, NOW));
check('tampered body rejected', !paddleVerifySignature($body . ' ', "ts={$ts};h1={$good}", $secret, NOW));
check('wrong secret rejected', !paddleVerifySignature($body, "ts={$ts};h1={$good}", 'other', NOW));
check('timestamp older than tolerance rejected', !paddleVerifySignature($body, "ts={$ts};h1={$good}", $secret, NOW + 301));
check('one of several h1 (secret rotation) accepted', paddleVerifySignature($body, "ts={$ts};h1=deadbeef;h1={$good}", $secret, NOW));
check('missing ts rejected', !paddleVerifySignature($body, "h1={$good}", $secret, NOW));

// ---------------------------------------------------------------------
echo "\n2. New yearly subscription grants Pro to the end of the period\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active'), $prices);
$u = user($pdo, 42);
check('account_type = pro', $u['account_type'] === 'pro', $u['account_type']);
check('pro_expires_at = period end', $u['pro_expires_at'] === $periodEnd, (string) $u['pro_expires_at']);

echo "\n3. Re-delivery of the same event is idempotent\n";
run($pdo, subEvent('active'), $prices);
$u2 = user($pdo, 42);
check('unchanged after duplicate', $u2 === $u, json_encode($u2));

echo "\n4. Cancel scheduled for period end keeps Pro until then\n";
run($pdo, subEvent('active', ['scheduled_change' => ['action' => 'cancel', 'effective_at' => iso(NOW + 365 * 86400)]], NOW + 60), $prices);
check('still period end', user($pdo, 42)['pro_expires_at'] === $periodEnd);
$row = paddleFetch($pdo, 'SELECT scheduled_change_action FROM paddle_subscriptions WHERE subscription_id = ?', ['sub_1']);
check('scheduled change mirrored', $row['scheduled_change_action'] === 'cancel');

echo "\n5. An older event arriving late is ignored\n";
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW - 10)], NOW - 10), $prices);
check('expiry untouched by stale cancel', user($pdo, 42)['pro_expires_at'] === $periodEnd);

echo "\n6. Cancellation takes effect: Paddle shortens the expiry it wrote\n";
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW + 120)], NOW + 120), $prices);
check('pro_expires_at = canceled_at', user($pdo, 42)['pro_expires_at'] === local(NOW + 120), (string) user($pdo, 42)['pro_expires_at']);
check('account_type left for cron to degrade', user($pdo, 42)['account_type'] === 'pro');

// ---------------------------------------------------------------------
echo "\n7. A voucher extension after the purchase is never shortened by a cancel\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active'), $prices);
$voucherUntil = local(NOW + 500 * 86400);
$pdo->prepare('UPDATE pro_users SET pro_expires_at = ? WHERE id = 42')->execute([$voucherUntil]);   // redeemVoucherForEmail()
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW + 60)], NOW + 60), $prices);
check('voucher expiry kept', user($pdo, 42)['pro_expires_at'] === $voucherUntil, (string) user($pdo, 42)['pro_expires_at']);

echo "\n8. A subscription extends an existing shorter voucher expiry\n";
$pdo = freshDb();
$short = local(NOW + 10 * 86400);
addUser($pdo, 42, 'buyer@example.com', 'pro', $short);
run($pdo, subEvent('active'), $prices);
check('extended to period end', user($pdo, 42)['pro_expires_at'] === $periodEnd);
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW + 60)], NOW + 60), $prices);
check('cancel falls back to the voucher expiry, not below it', user($pdo, 42)['pro_expires_at'] === $short, (string) user($pdo, 42)['pro_expires_at']);

echo "\n9. Unlimited voucher/BMAC account (NULL) is never touched\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com', 'pro', null);
run($pdo, subEvent('active'), $prices);
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW + 60)], NOW + 60), $prices);
check('still NULL', user($pdo, 42)['pro_expires_at'] === null);

// ---------------------------------------------------------------------
echo "\n10. Lifetime purchase → unlimited, and survives a later subscription cancel\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active'), $prices);
run($pdo, lifetimeEvent(), $prices);
check('pro_expires_at NULL', user($pdo, 42)['pro_expires_at'] === null);
check('account_type pro', user($pdo, 42)['account_type'] === 'pro');
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW + 60)], NOW + 60), $prices);
check('still NULL after cancel', user($pdo, 42)['pro_expires_at'] === null);
run($pdo, lifetimeEvent(), $prices);
check('lifetime re-delivery idempotent', user($pdo, 42)['pro_expires_at'] === null);

echo "\n11. Subscription renewal payments are left to subscription events\n";
$out = run($pdo, lifetimeEvent(['id' => 'txn_renewal', 'subscription_id' => 'sub_1', 'items' => [['price' => ['id' => PRICE_YEAR]]]]), $prices);
check('ignored', strpos($out, 'ignored') !== false, $out);

// ---------------------------------------------------------------------
echo "\n12. past_due gets 7 days of grace, not the whole new period\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('past_due', [], NOW), $prices);
check('expires occurred_at + 7 days', user($pdo, 42)['pro_expires_at'] === local(NOW + 7 * 86400), (string) user($pdo, 42)['pro_expires_at']);
run($pdo, subEvent('active', [], NOW + 3600), $prices);
check('recovered payment → period end', user($pdo, 42)['pro_expires_at'] === $periodEnd);

echo "\n13. A price that is not in pricing_tiers.php grants nothing\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active', ['items' => [['price' => ['id' => 'pri_other']]]]), $prices);
check('still expired regular', user($pdo, 42) === ['account_type' => 'regular', 'pro_expires_at' => '2020-01-01 00:00:00'], json_encode(user($pdo, 42)));

// ---------------------------------------------------------------------
echo "\n14. Guest checkout: linked by email once the customer event arrives\n";
$pdo = freshDb();
addUser($pdo, 7, 'Guest@Example.com');
$out = run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_guest']), $prices);
check('stored unlinked first', strpos($out, 'unlinked') !== false, $out);
check('no Pro yet', user($pdo, 7)['account_type'] === 'regular');
run($pdo, ['event_id' => 'evt_c', 'event_type' => 'customer.created', 'occurred_at' => iso(NOW + 1), 'data' => ['id' => 'ctm_guest', 'email' => 'guest@example.com']], $prices);
check('linked case-insensitively and granted', user($pdo, 7) === ['account_type' => 'pro', 'pro_expires_at' => $periodEnd], json_encode(user($pdo, 7)));

echo "\n15. Customer event first, then the subscription: linked by email directly\n";
$pdo = freshDb();
addUser($pdo, 7, 'guest@example.com');
run($pdo, ['event_id' => 'evt_c', 'event_type' => 'customer.created', 'occurred_at' => iso(NOW), 'data' => ['id' => 'ctm_guest', 'email' => 'guest@example.com']], $prices);
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_guest']), $prices);
check('granted', user($pdo, 7)['pro_expires_at'] === $periodEnd);

echo "\n16. custom_data naming a non-existent user falls back to email\n";
$pdo = freshDb();
addUser($pdo, 7, 'guest@example.com');
run($pdo, ['event_id' => 'evt_c', 'event_type' => 'customer.created', 'occurred_at' => iso(NOW), 'data' => ['id' => 'ctm_guest', 'email' => 'guest@example.com']], $prices);
run($pdo, subEvent('active', ['custom_data' => ['pro_user_id' => '999'], 'customer_id' => 'ctm_guest']), $prices);
check('granted to the email match', user($pdo, 7)['pro_expires_at'] === $periodEnd);

echo "\n17. Unknown event types are acknowledged, not failed\n";
$out = run($pdo, ['event_id' => 'evt_x', 'event_type' => 'payout.paid', 'occurred_at' => iso(NOW), 'data' => ['id' => 'x']], $prices);
check('ignored', strpos($out, 'ignored') === 0, $out);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
