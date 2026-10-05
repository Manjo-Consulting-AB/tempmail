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

// Both tables carry the blind index that linking by email matches on
// (pii_crypto.php): fixed test keys, overridden per case in section 26.
const PII_ENC_KEY = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const PII_IDX_KEY = 'iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii';
putenv('PII_ENCRYPTION_KEY=' . PII_ENC_KEY);
putenv('PII_INDEX_KEY=' . PII_IDX_KEY);

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
    $pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, email_enc TEXT NULL, email_hash TEXT NULL UNIQUE, account_type TEXT NOT NULL DEFAULT 'regular', pro_expires_at TEXT NULL)");
    foreach (paddleSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    // What migrate_email_encryption.php adds in production.
    $pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_enc TEXT NULL');
    $pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_hash TEXT NULL');
    return $pdo;
}

function addUser(PDO $pdo, int $id, string $email, string $type = 'regular', ?string $expires = '2020-01-01 00:00:00'): void
{
    $pdo->prepare('INSERT INTO pro_users (id, email, email_enc, email_hash, account_type, pro_expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $email, piiEmailEncrypt($email), piiEmailHash($email), $type, $expires]);
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
    return paddleHandleEvent($pdo, $event, ['prices' => $prices, 'has_account_type' => true, 'has_adjustments' => true, 'now' => $now, 'customer_email_pii' => true]);
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

echo "\n26. paddle_customers email_enc / email_hash, and linking by the blind index (pii_crypto.php)\n";
function customerEvent(string $email, int $occurred = NOW, string $id = 'ctm_pii'): array
{
    return ['event_id' => 'evt_c' . $occurred, 'event_type' => 'customer.updated', 'occurred_at' => iso($occurred), 'data' => ['id' => $id, 'email' => $email]];
}
function customerRow(PDO $pdo): array
{
    return paddleFetch($pdo, 'SELECT * FROM paddle_customers WHERE customer_id = ?', ['ctm_pii']);
}
$piiLog = [];
$piiOptions = function (?array $keys) use ($prices, &$piiLog): array {
    $options = ['prices' => $prices, 'has_account_type' => true, 'now' => NOW, 'customer_email_pii' => true,
        'log' => function ($level, $message, $context) use (&$piiLog) { $piiLog[] = [$level, $message]; }];
    if ($keys !== null) {
        $options['pii_encryption_key'] = $keys[0];
        $options['pii_index_key'] = $keys[1];
    }
    return $options;
};

// Keys configured: the pair is written, and linking matches on it.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
paddleHandleEvent($pdo, customerEvent(' Buyer@Example.com '), $piiOptions(null));
$row = customerRow($pdo);
check('the plaintext is still written (trimmed, as before)', $row['email'] === 'Buyer@Example.com', json_encode($row));
check('email_hash is the blind index of the address', $row['email_hash'] === piiEmailHash('buyer@example.com', PII_IDX_KEY), json_encode($row));
check('email_enc decrypts to the stored plaintext', piiEmailDecrypt($row['email_enc'], PII_ENC_KEY) === 'Buyer@Example.com');
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_pii']), $prices);
check('linking by email matches on email_hash, case-insensitively as before', user($pdo, 42)['account_type'] === 'pro');

// Linking reads only the hash: a plaintext match with no hash does not link.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
$pdo->exec("UPDATE pro_users SET email_hash = NULL, email_enc = NULL WHERE id = 42");
paddleHandleEvent($pdo, customerEvent('buyer@example.com'), $piiOptions(null));
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_pii']), $prices);
check('an account without email_hash is not linked by its plaintext email', user($pdo, 42)['account_type'] === 'regular');

// The plaintext column no longer decides: a stale plaintext does not link, the hash does.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
$pdo->exec("UPDATE pro_users SET email = 'someone-else@example.com' WHERE id = 42");
paddleHandleEvent($pdo, customerEvent('buyer@example.com'), $piiOptions(null));
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_pii']), $prices);
check('linking follows email_hash, not the plaintext column', user($pdo, 42)['account_type'] === 'pro');

$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
paddleHandleEvent($pdo, customerEvent('buyer@example.com'), $piiOptions(null));
paddleHandleEvent($pdo, customerEvent('other@example.com', NOW + 5), $piiOptions(null));
$row = customerRow($pdo);
check('a changed customer email updates the pair', $row['email_hash'] === piiEmailHash('other@example.com', PII_IDX_KEY) && piiEmailDecrypt($row['email_enc'], PII_ENC_KEY) === 'other@example.com');
paddleHandleEvent($pdo, customerEvent('', NOW + 10), $piiOptions(null));
$row = customerRow($pdo);
check('an email removed at Paddle clears the pair too', $row['email'] === null && $row['email_enc'] === null && $row['email_hash'] === null, json_encode($row));
check('with keys: no WARNING', !in_array('WARNING', array_column($piiLog, 0), true), json_encode($piiLog));

// Keys missing: the event is applied, the pair is null (never stale), nothing links.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
paddleHandleEvent($pdo, customerEvent('old@example.com'), $piiOptions(null));
$piiLog = [];
paddleHandleEvent($pdo, customerEvent('buyer@example.com', NOW + 5), $piiOptions(['', '']));
$row = customerRow($pdo);
check('no keys: the event is still applied', $row['email'] === 'buyer@example.com', json_encode($row));
check('no keys: the old pair is cleared, not left describing the old address', $row['email_enc'] === null && $row['email_hash'] === null, json_encode($row));
check('no keys: a WARNING through the log option', in_array('WARNING', array_column($piiLog, 0), true), json_encode($piiLog));
run($pdo, subEvent('active', ['custom_data' => null, 'customer_id' => 'ctm_pii']), $prices);
check('no keys: fail closed, the payment is not linked by email', user($pdo, 42)['account_type'] === 'regular');

echo "\n27. Sandbox allowlist: only PADDLE_SANDBOX_USER_IDS may be granted Pro (#346)\n";
// The sandbox notification destination points at the live paddle_webhook.php,
// so a test-card checkout must not hand out real Pro time unless the account is
// on the list. Everything but pro_users is mirrored as usual.
function sandboxOptions(array $prices, array $userIds, string $environment, ?array &$log = null): array
{
    $options = [
        'prices' => $prices,
        'has_account_type' => true,
        'now' => NOW,
        'customer_email_pii' => true,
        'environment' => $environment,
        'sandbox_user_ids' => $userIds,
    ];
    if ($log !== null) {
        $options['log'] = function ($level, $message, $context) use (&$log) { $log[] = [$level, $message, $context]; };
    }
    return $options;
}

// On the list: granted exactly as before.
$pdo = freshDb();
addUser($pdo, 105, 'allowlisted@example.com');
$out = paddleHandleEvent($pdo, subEvent('active', ['custom_data' => ['pro_user_id' => '105']]), sandboxOptions($prices, [105], 'sandbox'));
check('sandbox + allowlisted → Pro granted', user($pdo, 105) === ['account_type' => 'pro', 'pro_expires_at' => $periodEnd], $out . ' ' . json_encode(user($pdo, 105)));

// Not on the list: pro_users untouched, the mirror written, the new outcome.
$pdo = freshDb();
addUser($pdo, 42, 'stranger@example.com');
$log = [];
$out = paddleHandleEvent($pdo, subEvent('active'), sandboxOptions($prices, [105], 'sandbox', $log));
check('sandbox + not allowlisted → the new outcome', strpos($out, 'sandbox user not allowed') !== false, $out);
check('sandbox + not allowlisted → pro_users untouched', user($pdo, 42) === ['account_type' => 'regular', 'pro_expires_at' => '2020-01-01 00:00:00'], json_encode(user($pdo, 42)));
$row = paddleFetch($pdo, 'SELECT pro_user_id, granted_until FROM paddle_subscriptions WHERE subscription_id = ?', ['sub_1']);
check('the subscription mirror row is still written', $row !== null && (int) $row['pro_user_id'] === 42 && $row['granted_until'] === $periodEnd, json_encode($row));
check('no paddle_entitlements row (nothing was applied)', paddleFetch($pdo, 'SELECT 1 FROM paddle_entitlements WHERE pro_user_id = ?', [42]) === null);
$warnings = array_values(array_filter($log, fn($l) => $l[0] === 'WARNING' && ($l[2]['user_id'] ?? null) === 42));
check('a WARNING with the user id through the log option', $warnings !== [], json_encode($log));

// A lifetime purchase is refused the same way.
$out = paddleHandleEvent($pdo, lifetimeEvent(), sandboxOptions($prices, [105], 'sandbox'));
check('sandbox + not allowlisted → a lifetime purchase grants nothing either', user($pdo, 42)['pro_expires_at'] === '2020-01-01 00:00:00' && strpos($out, 'sandbox user not allowed') !== false, $out);

// Empty list: nobody, not even an account that would otherwise link by email.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
$out = paddleHandleEvent($pdo, subEvent('active'), sandboxOptions($prices, [], 'sandbox'));
check('sandbox + empty list → nobody gets Pro', user($pdo, 42)['account_type'] === 'regular' && strpos($out, 'sandbox user not allowed') !== false, $out);

// Production ignores the list entirely.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
$out = paddleHandleEvent($pdo, subEvent('active'), sandboxOptions($prices, [], 'production'));
check('production + empty list → Pro granted', user($pdo, 42) === ['account_type' => 'pro', 'pro_expires_at' => $periodEnd], $out . ' ' . json_encode(user($pdo, 42)));

// No environment option at all (the pre-#346 callers): the list is not consulted.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active'), $prices);
check('no environment option → unchanged behaviour', user($pdo, 42)['account_type'] === 'pro');

// ---------------------------------------------------------------------
echo "\n28. pricing_tiers.php holds both catalogs and PADDLE_ENVIRONMENT picks one (#350)\n";
$tiers = require dirname(__DIR__) . '/pricing_tiers.php';
$sandboxTiers    = paddleTiersForEnvironment($tiers, 'sandbox');
$productionTiers = paddleTiersForEnvironment($tiers, 'production');

// The IDs as they were created in each Paddle account (2026-09-30) — a
// transposed digit would otherwise only surface as a payment that grants
// nothing.
check('sandbox resolves to the sandbox subscription prices',
    $sandboxTiers[0]['priceId'] === ['month' => 'pri_01m3a6bdv26jf8dw0z8b8xp81k', 'year' => 'pri_01m3a6bdzwq8enav6qbvdme5db'],
    json_encode($sandboxTiers[0]['priceId']));
check('sandbox resolves to the sandbox lifetime price',
    $sandboxTiers[1]['priceId'] === ['once' => 'pri_01m3a6j2gy3f6bkqaf6jpysdrc'],
    json_encode($sandboxTiers[1]['priceId']));
check('production resolves to the live subscription prices',
    $productionTiers[0]['priceId'] === ['month' => 'pri_01m3s15f9tbhrvd3zrff8h1ybg', 'year' => 'pri_01m3s14qntdr6zy9pwa5jw2f21'],
    json_encode($productionTiers[0]['priceId']));
check('production resolves to the live lifetime price',
    $productionTiers[1]['priceId'] === ['once' => 'pri_01m3s13tx4qxqnvccz2ef5wqef'],
    json_encode($productionTiers[1]['priceId']));
check('the rest of the plan is carried over unchanged',
    $sandboxTiers[0]['name'] === 'Pro' && $sandboxTiers[0]['featured'] === true && $sandboxTiers[1]['name'] === 'Lifetime');
check('the two catalogs have no price id in common',
    array_intersect(paddlePlanPriceIds($sandboxTiers)['subscription'], paddlePlanPriceIds($productionTiers)['subscription']) === []
    && array_intersect(paddlePlanPriceIds($sandboxTiers)['lifetime'], paddlePlanPriceIds($productionTiers)['lifetime']) === []);

$sandboxPrices    = paddlePlanPriceIds($sandboxTiers);
$productionPrices = paddlePlanPriceIds($productionTiers);
$liveMonth    = 'pri_01m3s15f9tbhrvd3zrff8h1ybg';
$liveLifetime = 'pri_01m3s13tx4qxqnvccz2ef5wqef';
$sandboxMonth = 'pri_01m3a6bdv26jf8dw0z8b8xp81k';

// The price a subscription actually carries still grants under its own catalog…
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active', ['items' => [['price' => ['id' => $sandboxMonth]]]]), $sandboxPrices);
check('a sandbox price grants while the environment is sandbox', user($pdo, 42)['account_type'] === 'pro');

// …and a price from the other environment grants nothing: it does not exist in
// the account the webhook was verified against, which is what this arrangement
// exists to make impossible to deploy by halves.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active', ['items' => [['price' => ['id' => $liveMonth]]]]), $sandboxPrices);
run($pdo, lifetimeEvent(['items' => [['price' => ['id' => $liveLifetime]]]]), $sandboxPrices);
check('a live price while the environment is sandbox grants nothing',
    user($pdo, 42) === ['account_type' => 'regular', 'pro_expires_at' => '2020-01-01 00:00:00'], json_encode(user($pdo, 42)));

$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active', ['items' => [['price' => ['id' => $sandboxMonth]]]]), $productionPrices);
run($pdo, lifetimeEvent(), $productionPrices);   // a sandbox lifetime id
check('a sandbox price while the environment is production grants nothing',
    user($pdo, 42) === ['account_type' => 'regular', 'pro_expires_at' => '2020-01-01 00:00:00'], json_encode(user($pdo, 42)));

$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active', ['items' => [['price' => ['id' => $liveMonth]]]]), $productionPrices);
check('the live price does grant under production', user($pdo, 42) === ['account_type' => 'pro', 'pro_expires_at' => $periodEnd], json_encode(user($pdo, 42)));

// An unknown environment throws rather than falling back to the other catalog.
foreach (['staging', '', 'Sandbox', ' sandbox'] as $bad) {
    $threw = false;
    try {
        paddleTiersForEnvironment($tiers, $bad);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check('environment ' . var_export($bad, true) . ' throws', $threw);
}

$threw = false;
try {
    paddleTiersForEnvironment([['name' => 'Pro', 'priceId' => ['sandbox' => ['month' => 'pri_x']]]], 'production');
} catch (RuntimeException $e) {
    $threw = true;
}
check('a plan with no IDs for the environment throws rather than falling back', $threw);

$threw = false;
try {
    paddleTiersForEnvironment([['name' => 'Pro', 'priceId' => ['production' => []]]], 'production');
} catch (RuntimeException $e) {
    $threw = true;
}
check('an empty price list for the environment throws', $threw);

// ---------------------------------------------------------------------
echo "\n29. Refunds and chargebacks (adjustment.*) revoke what they take back (#280 §7)\n";

function adjEvent(string $id, string $action, string $status, array $over = [], int $occurred = NOW, string $eventType = 'adjustment.created'): array
{
    return ['event_id' => 'evt_' . md5($id . $action . $status . $occurred), 'event_type' => $eventType, 'occurred_at' => iso($occurred), 'data' => array_replace([
        'id' => $id, 'action' => $action, 'type' => 'full', 'status' => $status,
        'transaction_id' => 'txn_1', 'subscription_id' => null, 'customer_id' => 'ctm_1',
        'created_at' => iso($occurred),
    ], $over)];
}

check('effect: approved full refund revokes', paddleAdjustmentEffect('refund', 'full', 'approved') === 1);
check('effect: partial refund changes nothing', paddleAdjustmentEffect('refund', 'partial', 'approved') === 0);
check('effect: pending refund changes nothing', paddleAdjustmentEffect('refund', 'full', 'pending_approval') === 0);
check('effect: rejected / reversed refund changes nothing', paddleAdjustmentEffect('refund', 'full', 'rejected') === 0 && paddleAdjustmentEffect('refund', 'full', 'reversed') === 0);
check('effect: chargeback revokes, chargeback_reverse undoes', paddleAdjustmentEffect('chargeback', 'full', 'approved') === 1 && paddleAdjustmentEffect('chargeback_reverse', 'full', 'approved') === -1);
// The shape of the first live refund (#280): made in the dashboard, the whole
// amount returned, yet the adjustment is 'partial' and its one item 'full'.
$dashboardItems = [['item_id' => 'txnitm_1', 'type' => 'full', 'amount' => '3379']];
check('effect: dashboard full refund (type partial, item full) revokes', paddleAdjustmentEffect('refund', 'partial', 'approved', $dashboardItems) === 1);
check('effect: item full plus a tax line still revokes', paddleAdjustmentEffect('refund', 'partial', 'approved', [['type' => 'full'], ['type' => 'tax']]) === 1);
check('effect: an item refunded in part changes nothing', paddleAdjustmentEffect('refund', 'partial', 'approved', [['type' => 'partial', 'amount' => '1000']]) === 0);
check('effect: one item full and one partial changes nothing', paddleAdjustmentEffect('refund', 'partial', 'approved', [['type' => 'full'], ['type' => 'partial']]) === 0);
check('effect: only a tax line changes nothing', paddleAdjustmentEffect('refund', 'partial', 'approved', [['type' => 'tax']]) === 0);
check('effect: a dashboard full refund still waits for approval', paddleAdjustmentEffect('refund', 'partial', 'pending_approval', $dashboardItems) === 0);
check('effect: credit and chargeback_warning change nothing', paddleAdjustmentEffect('credit', 'full', 'approved') === 0 && paddleAdjustmentEffect('chargeback_warning', 'full', 'approved') === 0);

// Lifetime bought on a trial with 30 days left, then refunded: back to the trial.
$pdo = freshDb();
$trialEnd = local(NOW + 30 * 86400);
addUser($pdo, 42, 'buyer@example.com', 'pro', $trialEnd);
run($pdo, lifetimeEvent(), $prices);
check('lifetime applied', user($pdo, 42)['pro_expires_at'] === null);
run($pdo, adjEvent('adj_1', 'refund', 'pending_approval'), $prices, NOW + 3600);
check('a pending refund leaves lifetime in place', user($pdo, 42)['pro_expires_at'] === null);
$out = run($pdo, adjEvent('adj_1', 'refund', 'approved', [], NOW + 7200, 'adjustment.updated'), $prices, NOW + 7200);
check('approved full refund revokes lifetime', strpos($out, 'lifetime revoked') !== false, $out);
check('…back to the trial it had before the purchase', user($pdo, 42) === ['account_type' => 'pro', 'pro_expires_at' => $trialEnd], json_encode(user($pdo, 42)));
run($pdo, adjEvent('adj_1', 'refund', 'approved', [], NOW + 7200, 'adjustment.updated'), $prices, NOW + 7300);
check('a redelivered refund changes nothing more', user($pdo, 42)['pro_expires_at'] === $trialEnd);
run($pdo, adjEvent('adj_1', 'refund', 'pending_approval'), $prices, NOW + 7400);
check('a stale pending event does not undo the refund', user($pdo, 42)['pro_expires_at'] === $trialEnd);

// Lifetime on an expired Regular account, charged back: ends now; reversed: lifetime again.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, lifetimeEvent(), $prices);
run($pdo, adjEvent('adj_cb', 'chargeback', 'approved'), $prices, NOW + 60);
check('chargeback with nothing to fall back on ends Pro now', user($pdo, 42)['pro_expires_at'] === local(NOW + 60), (string) user($pdo, 42)['pro_expires_at']);
run($pdo, adjEvent('adj_cbr', 'chargeback_reverse', 'approved', [], NOW + 120), $prices, NOW + 120);
check('chargeback_reverse restores lifetime', user($pdo, 42)['pro_expires_at'] === null);

// A partial refund of a lifetime purchase keeps it.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, lifetimeEvent(), $prices);
run($pdo, adjEvent('adj_p', 'refund', 'approved', ['type' => 'partial']), $prices);
check('partial refund keeps lifetime', user($pdo, 42)['pro_expires_at'] === null);

// A refund of another transaction leaves the lifetime purchase alone.
run($pdo, adjEvent('adj_o', 'refund', 'approved', ['transaction_id' => 'txn_other']), $prices);
check("another transaction's refund keeps lifetime", user($pdo, 42)['pro_expires_at'] === null);

// The live test purchase end to end: a monthly subscription on a trial account,
// refunded in the dashboard (type partial, item full), pending then approved.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com', 'pro', $trialEnd);
$monthEnd = NOW + 31 * 86400;
run($pdo, subEvent('active', ['current_billing_period' => ['starts_at' => iso(NOW), 'ends_at' => iso($monthEnd)],
    'items' => [['price' => ['id' => PRICE_MONTH]]]]), $prices);
check('live shape: month stacked on the trial', user($pdo, 42)['pro_expires_at'] === local($monthEnd + 30 * 86400), (string) user($pdo, 42)['pro_expires_at']);
$liveRefund = ['type' => 'partial', 'transaction_id' => 'txn_live', 'subscription_id' => 'sub_1', 'items' => $dashboardItems];
run($pdo, adjEvent('adj_live', 'refund', 'pending_approval', $liveRefund, NOW + 600), $prices, NOW + 600);
check('live shape: pending dashboard refund keeps the paid month', user($pdo, 42)['pro_expires_at'] === local($monthEnd + 30 * 86400));
run($pdo, adjEvent('adj_live', 'refund', 'approved', $liveRefund, NOW + 3600, 'adjustment.updated'), $prices, NOW + 3600);
// Paid time ends at the refund, and the trial time left at takeover runs from
// there — the same rule as a cancellation — so the trial end plus the hour
// between purchase and refund.
check('live shape: approved dashboard refund leaves the trial time, run from the refund', user($pdo, 42)['pro_expires_at'] === local(NOW + 3600 + 30 * 86400), (string) user($pdo, 42)['pro_expires_at']);

// Subscription payment refunded: the grant ends at the refund, for that period only.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, subEvent('active'), $prices);
check('subscription grants the period', user($pdo, 42)['pro_expires_at'] === $periodEnd);
run($pdo, adjEvent('adj_s', 'refund', 'approved', ['transaction_id' => 'txn_sub1', 'subscription_id' => 'sub_1'], NOW + 86400), $prices, NOW + 86400);
check('refunded subscription payment ends Pro at the refund', user($pdo, 42)['pro_expires_at'] === local(NOW + 86400), (string) user($pdo, 42)['pro_expires_at']);
run($pdo, subEvent('active', [], NOW + 2 * 86400), $prices, NOW + 2 * 86400);
check('a later event for the same period stays clamped', user($pdo, 42)['pro_expires_at'] === local(NOW + 86400), (string) user($pdo, 42)['pro_expires_at']);
$nextEnd = NOW + 730 * 86400;
run($pdo, subEvent('active', ['current_billing_period' => ['starts_at' => iso(NOW + 365 * 86400), 'ends_at' => iso($nextEnd)]], NOW + 365 * 86400), $prices, NOW + 365 * 86400);
check('a renewal the customer pays for grants again', user($pdo, 42)['pro_expires_at'] === local($nextEnd), (string) user($pdo, 42)['pro_expires_at']);

// Refunded subscription on a trial account keeps the trial time it had.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com', 'pro', $trialEnd);
run($pdo, subEvent('active'), $prices);
run($pdo, adjEvent('adj_t', 'refund', 'approved', ['transaction_id' => 'txn_sub1', 'subscription_id' => 'sub_1']), $prices);
check('refunded subscription falls back to the trial end', user($pdo, 42)['pro_expires_at'] === $trialEnd, (string) user($pdo, 42)['pro_expires_at']);

// A refund that arrives before its account is known, linked by a later customer event.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
run($pdo, lifetimeEvent(['customer_id' => 'ctm_9', 'custom_data' => null]), $prices);
$out = run($pdo, adjEvent('adj_u', 'refund', 'approved', ['customer_id' => 'ctm_9']), $prices);
check('unlinked refund is stored unlinked', strpos($out, '(unlinked)') !== false, $out);
run($pdo, ['event_id' => 'evt_c9', 'event_type' => 'customer.created', 'occurred_at' => iso(NOW + 10), 'data' => ['id' => 'ctm_9', 'email' => 'buyer@example.com']], $prices);
check('linking the customer applies the refund: no lifetime granted', user($pdo, 42)['pro_expires_at'] === '2020-01-01 00:00:00', json_encode(user($pdo, 42)));
check('…and the adjustment is linked', paddleFetch($pdo, 'SELECT pro_user_id FROM paddle_adjustments WHERE adjustment_id = ?', ['adj_u'])['pro_user_id'] == 42);

// Before the migration: an adjustment throws (non-2xx, Paddle retries), lifetime still counts.
$pdo = freshDb();
addUser($pdo, 42, 'buyer@example.com');
$threw = false;
try {
    paddleHandleEvent($pdo, adjEvent('adj_m', 'refund', 'approved'), ['prices' => $prices, 'has_account_type' => true, 'now' => NOW]);
} catch (RuntimeException $e) {
    $threw = true;
}
check('without paddle_adjustments an adjustment event throws so Paddle retries', $threw);
paddleHandleEvent($pdo, lifetimeEvent(), ['prices' => $prices, 'has_account_type' => true, 'now' => NOW]);
check('without paddle_adjustments lifetime is granted as before', user($pdo, 42)['pro_expires_at'] === null);

// ---------------------------------------------------------------------
echo "\n30. Concurrent deliveries: a primary-key collision is retried once (#280)\n";

// Stands in for losing the race: the first $collide INSERTs into the watched
// table fail like MySQL's 1062 (SQLSTATE 23000), which rolls the handler's
// transaction back. On MySQL the loser's INSERT waits for the winner's commit
// before failing, so the retry's fresh transaction sees the winner's row and
// updates it; here the retry simply inserts, which exercises the same path.
class CollidingPdo extends PDO
{
    public $collide = 0;
    public $error = '23000';
    public $table = 'paddle_subscriptions';

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->collide > 0 && stripos($query, 'INSERT INTO ' . $this->table) === 0) {
            $this->collide--;
            $e = new PDOException('SQLSTATE[' . $this->error . ']: simulated', 0);
            $e->errorInfo = [$this->error, 1062, 'simulated'];
            throw $e;
        }
        return parent::prepare($query, $options);
    }
}

function collidingDb(): CollidingPdo
{
    $pdo = new CollidingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, email_enc TEXT NULL, email_hash TEXT NULL UNIQUE, account_type TEXT NOT NULL DEFAULT 'regular', pro_expires_at TEXT NULL)");
    foreach (paddleSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_enc TEXT NULL');
    $pdo->exec('ALTER TABLE paddle_customers ADD COLUMN email_hash TEXT NULL');
    return $pdo;
}

check('duplicate key detected from errorInfo', paddleIsDuplicateKey((function () { $e = new PDOException('x'); $e->errorInfo = ['23000', 1062, 'dup']; return $e; })()));
check('another SQLSTATE is not a duplicate key', !paddleIsDuplicateKey((function () { $e = new PDOException('x'); $e->errorInfo = ['HY000', 2006, 'gone away']; return $e; })()));

$pdo = collidingDb();
addUser($pdo, 42, 'buyer@example.com');
$pdo->collide = 1;
$log = [];
$out = paddleHandleEvent($pdo, subEvent('active'), ['prices' => $prices, 'has_account_type' => true, 'has_adjustments' => true, 'now' => NOW, 'customer_email_pii' => true,
    'log' => function ($level, $message, $context) use (&$log) { $log[] = [$level, $message]; }]);
check('one collision is retried and the event applies', user($pdo, 42) === ['account_type' => 'pro', 'pro_expires_at' => $periodEnd], json_encode(user($pdo, 42)) . ' ' . $out);
check('…with one INFO line, no ERROR', count(array_filter($log, function ($l) { return $l[1] === 'Paddle event collided with a concurrent one; retrying once'; })) === 1
    && !array_filter($log, function ($l) { return $l[0] === 'ERROR'; }));
check('…and exactly one subscription row', (int) $pdo->query('SELECT COUNT(*) FROM paddle_subscriptions')->fetchColumn() === 1);

$pdo = collidingDb();
addUser($pdo, 42, 'buyer@example.com');
$pdo->collide = 2;
$threw = false;
try {
    run($pdo, subEvent('active'), $prices);
} catch (PDOException $e) {
    $threw = true;
}
check('a second collision is thrown, so Paddle retries', $threw);
check('…and nothing was half-written', user($pdo, 42)['pro_expires_at'] === '2020-01-01 00:00:00' && (int) $pdo->query('SELECT COUNT(*) FROM paddle_subscriptions')->fetchColumn() === 0);

$pdo = collidingDb();
addUser($pdo, 42, 'buyer@example.com');
$pdo->collide = 1;
$pdo->error = 'HY000';
$threw = false;
try {
    run($pdo, subEvent('active'), $prices);
} catch (PDOException $e) {
    $threw = true;
}
check('any other database error is not retried', $threw && $pdo->collide === 0 && (int) $pdo->query('SELECT COUNT(*) FROM paddle_subscriptions')->fetchColumn() === 0);

foreach (['paddle_customers' => ['event_id' => 'evt_c', 'event_type' => 'customer.created', 'occurred_at' => iso(NOW), 'data' => ['id' => 'ctm_1', 'email' => 'buyer@example.com']],
          'paddle_transactions' => lifetimeEvent(),
          'paddle_adjustments' => adjEvent('adj_r', 'refund', 'pending_approval')] as $table => $event) {
    $pdo = collidingDb();
    addUser($pdo, 42, 'buyer@example.com');
    $pdo->table = $table;
    $pdo->collide = 1;
    run($pdo, $event, $prices);
    check("a collision on {$table} is retried too", (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === 1);
}

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
