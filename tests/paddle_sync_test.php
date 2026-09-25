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

echo "\n8. Remaining voucher time is stacked on top of the paid period\n";
$pdo = freshDb();
$short = local(NOW + 10 * 86400);
addUser($pdo, 42, 'buyer@example.com', 'pro', $short);
run($pdo, subEvent('active'), $prices);
check('period end + the 10 voucher days', user($pdo, 42)['pro_expires_at'] === local(NOW + 375 * 86400), (string) user($pdo, 42)['pro_expires_at']);
run($pdo, subEvent('canceled', ['canceled_at' => iso(NOW + 60)], NOW + 60), $prices);
check('after cancel the 10 days run from the end of paid time', user($pdo, 42)['pro_expires_at'] === local(NOW + 60 + 10 * 86400), (string) user($pdo, 42)['pro_expires_at']);

echo "\n9. Unlimited voucher account (NULL) is never touched\n";
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

// ---------------------------------------------------------------------
echo "\n18. Paid time is stacked on top of a running Pro trial\n";
$month = 30 * 86400;
$trialLeft = 60 * 86400;
$monthly = function (int $periodEnd, array $over = [], int $occurred = NOW) {
    return subEvent('active', array_replace([
        'custom_data' => ['pro_user_id' => '105'],
        'current_billing_period' => ['starts_at' => iso($periodEnd - 30 * 86400), 'ends_at' => iso($periodEnd)],
        'items' => [['price' => ['id' => PRICE_MONTH]]],
    ], $over), $occurred);
};
$canceled = function (int $at, string $id = 'sub_1') {
    return subEvent('canceled', ['id' => $id, 'custom_data' => ['pro_user_id' => '105'], 'canceled_at' => iso($at), 'items' => [['price' => ['id' => PRICE_MONTH]]]], $at);
};
$pdo = freshDb();
addUser($pdo, 105, 'trial@example.com', 'pro', local(NOW + $trialLeft));   // proTrialGrantOnVerification()
run($pdo, $monthly(NOW + $month), $prices);
check('month + 60 trial days', user($pdo, 105)['pro_expires_at'] === local(NOW + $month + $trialLeft), (string) user($pdo, 105)['pro_expires_at']);
run($pdo, $monthly(NOW + $month), $prices);
check('subscription.activated for the same period changes nothing', user($pdo, 105)['pro_expires_at'] === local(NOW + $month + $trialLeft));

echo "\n19. Renewals move the paid end; the trial days are not added twice\n";
run($pdo, $monthly(NOW + 2 * $month, [], NOW + $month + 5), $prices, NOW + $month + 5);
check('two months + 60 days', user($pdo, 105)['pro_expires_at'] === local(NOW + 2 * $month + $trialLeft), (string) user($pdo, 105)['pro_expires_at']);
run($pdo, $monthly(NOW + 3 * $month, [], NOW + 2 * $month + 5), $prices, NOW + 2 * $month + 5);
check('three months + 60 days', user($pdo, 105)['pro_expires_at'] === local(NOW + 3 * $month + $trialLeft), (string) user($pdo, 105)['pro_expires_at']);

echo "\n20. Cancel at period end: the trial days still follow the paid time\n";
$cancelAt = NOW + 3 * $month;
run($pdo, $canceled($cancelAt), $prices, $cancelAt);
check('paid end + 60 days', user($pdo, 105)['pro_expires_at'] === local($cancelAt + $trialLeft), (string) user($pdo, 105)['pro_expires_at']);
run($pdo, $canceled($cancelAt), $prices, $cancelAt + 10 * 86400);
check('re-delivered cancel 10 days later does not shrink it', user($pdo, 105)['pro_expires_at'] === local($cancelAt + $trialLeft), (string) user($pdo, 105)['pro_expires_at']);

echo "\n21. Re-subscribing inside the bonus window keeps only what is left of it\n";
$back = $cancelAt + 20 * 86400;   // 20 of the 60 bonus days used
run($pdo, $monthly($back + $month, ['id' => 'sub_2'], $back), $prices, $back);
check('new month + the 40 days left', user($pdo, 105)['pro_expires_at'] === local($back + $month + 40 * 86400), (string) user($pdo, 105)['pro_expires_at']);

echo "\n22. Once the bonus is used up, a later subscription gets no bonus again\n";
$pdo = freshDb();
addUser($pdo, 105, 'trial@example.com', 'pro', local(NOW + $trialLeft));
run($pdo, $monthly(NOW + $month), $prices);
run($pdo, $canceled(NOW + $month), $prices, NOW + $month);
$later = NOW + $month + $trialLeft + 30 * 86400;   // everything expired a month ago
run($pdo, $monthly($later + $month, ['id' => 'sub_3'], $later), $prices, $later);
check('just the new paid month', user($pdo, 105)['pro_expires_at'] === local($later + $month), (string) user($pdo, 105)['pro_expires_at']);

echo "\n23. An expired Regular account (no time left) gets exactly the paid period\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active'), $prices);
check('period end, no bonus', user($pdo, 42)['pro_expires_at'] === $periodEnd);

// ---------------------------------------------------------------------
echo "\n24. Customer portal target: only this account's own linked payments\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
addUser($pdo, 7, 'other@example.com');
check('no payments → null (no Manage billing button)', paddlePortalTarget($pdo, 42) === null);
run($pdo, subEvent('active', ['id' => 'sub_a', 'customer_id' => 'ctm_a']), $prices);
run($pdo, subEvent('active', ['id' => 'sub_other', 'customer_id' => 'ctm_other', 'custom_data' => ['pro_user_id' => '7']]), $prices);
$t = paddlePortalTarget($pdo, 42);
check('own customer and subscription', $t === ['customer_id' => 'ctm_a', 'subscription_ids' => ['sub_a']], json_encode($t));
check('the other account gets only its own', paddlePortalTarget($pdo, 7) === ['customer_id' => 'ctm_other', 'subscription_ids' => ['sub_other']]);
run($pdo, subEvent('canceled', ['id' => 'sub_a', 'customer_id' => 'ctm_a', 'canceled_at' => iso(NOW + 60)], NOW + 60), $prices, NOW + 60);
$t = paddlePortalTarget($pdo, 42);
check('canceled subscription: portal still opens (invoices), no deep link', $t === ['customer_id' => 'ctm_a', 'subscription_ids' => []], json_encode($t));

echo "\n25. Customer portal target for a lifetime-only buyer\n";
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, lifetimeEvent(['customer_id' => 'ctm_life']), $prices);
check('customer, no subscriptions', paddlePortalTarget($pdo, 42) === ['customer_id' => 'ctm_life', 'subscription_ids' => []]);
check('unlinked payments never surface', paddlePortalTarget($pdo, 99) === null);

echo "\n26. paddle_customers email_enc / email_hash dual-write (pii_crypto.php)\n";
$encKey = str_repeat('e', 32);
$idxKey = str_repeat('i', 32);
putenv('PII_ENCRYPTION_KEY');
putenv('PII_INDEX_KEY');
unset($_ENV['PII_ENCRYPTION_KEY'], $_ENV['PII_INDEX_KEY']);
function customerEvent(string $email, int $occurred = NOW): array
{
    return ['event_id' => 'evt_c' . $occurred, 'event_type' => 'customer.updated', 'occurred_at' => iso($occurred), 'data' => ['id' => 'ctm_pii', 'email' => $email]];
}
function customerRow(PDO $pdo): array
{
    return paddleFetch($pdo, 'SELECT * FROM paddle_customers WHERE customer_id = ?', ['ctm_pii']);
}
$piiLog = [];
$piiOptions = function (bool $columns, ?array $keys) use ($prices, &$piiLog): array {
    $options = ['prices' => $prices, 'has_account_type' => true, 'now' => NOW, 'customer_email_pii' => $columns,
        'log' => function ($level, $message, $context) use (&$piiLog) { $piiLog[] = [$level, $message]; }];
    if ($keys !== null) {
        $options['pii_encryption_key'] = $keys[0];
        $options['pii_index_key'] = $keys[1];
    }
    return $options;
};

// Columns absent (the default schema, migration not run): exactly today's row.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
paddleHandleEvent($pdo, customerEvent('Buyer@Example.com'), $piiOptions(false, [$encKey, $idxKey]));
$row = customerRow($pdo);
check('no columns: the plaintext row is written as before', $row['email'] === 'Buyer@Example.com' && !array_key_exists('email_hash', $row), json_encode($row));
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_pii']), $prices);
check('no columns: linking by email still works', user($pdo, 42)['account_type'] === 'pro');

// Columns present, keys configured.
$pdo = freshDb();
$pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_enc TEXT NULL');
$pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_hash CHAR(64) NULL');
addUser($pdo, 42, 'buyer@example.com');
paddleHandleEvent($pdo, customerEvent(' Buyer@Example.com '), $piiOptions(true, [$encKey, $idxKey]));
$row = customerRow($pdo);
check('with keys: the plaintext is still written (trimmed, as before)', $row['email'] === 'Buyer@Example.com', json_encode($row));
check('with keys: email_hash is the blind index of the address', $row['email_hash'] === piiEmailHash('buyer@example.com', $idxKey), json_encode($row));
check('with keys: email_enc decrypts to the stored plaintext', piiEmailDecrypt($row['email_enc'], $encKey) === 'Buyer@Example.com');
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_pii']), $prices);
check('with keys: linking by email is unchanged (still reads the plaintext)', user($pdo, 42)['account_type'] === 'pro');
paddleHandleEvent($pdo, customerEvent('other@example.com', NOW + 5), $piiOptions(true, [$encKey, $idxKey]));
$row = customerRow($pdo);
check('with keys: a changed customer email updates the pair', $row['email_hash'] === piiEmailHash('other@example.com', $idxKey) && piiEmailDecrypt($row['email_enc'], $encKey) === 'other@example.com');
paddleHandleEvent($pdo, customerEvent('', NOW + 10), $piiOptions(true, [$encKey, $idxKey]));
$row = customerRow($pdo);
check('with keys: an email removed at Paddle clears the pair too', $row['email'] === null && $row['email_enc'] === null && $row['email_hash'] === null, json_encode($row));
check('with keys: no WARNING', $piiLog === [] || !in_array('WARNING', array_column($piiLog, 0), true), json_encode($piiLog));

// Columns present, keys missing: plaintext, a null pair (never a stale one), one WARNING.
$pdo = freshDb();
$pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_enc TEXT NULL');
$pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_hash CHAR(64) NULL');
paddleHandleEvent($pdo, customerEvent('buyer@example.com'), $piiOptions(true, [$encKey, $idxKey]));
$piiLog = [];
paddleHandleEvent($pdo, customerEvent('changed@example.com', NOW + 5), $piiOptions(true, null));
$row = customerRow($pdo);
check('no keys: the event is still applied', $row['email'] === 'changed@example.com', json_encode($row));
check('no keys: the old pair is cleared, not left describing the old address', $row['email_enc'] === null && $row['email_hash'] === null, json_encode($row));
check('no keys: a WARNING through the log option', in_array('WARNING', array_column($piiLog, 0), true), json_encode($piiLog));

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
