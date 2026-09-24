<?php
/**
 * Paddle Billing → pro_users sync. Pure functions over an explicit PDO, so
 * paddle_webhook.php (MySQL) and tests/paddle_sync_test.php (SQLite) run the
 * same code. No config.php, no globals, no network — Paddle's signed webhook
 * payloads carry everything needed, so no server-side API key is involved.
 *
 * Entitlement model (documentaion/ACCOUNT_TIERS.md §2.1): Pro is
 * account_type = 'pro' AND pro_expires_at NULL-or-future, and
 * cron/cleanup.php degrades accounts whose pro_expires_at has passed. This
 * file therefore only ever writes pro_expires_at (and account_type = 'pro'
 * when it grants). What it writes per source:
 *
 *   subscription active/trialing   end of the paid billing period — so a
 *                                  cancel scheduled for period end keeps Pro
 *                                  until then, with nothing extra to do
 *   subscription past_due          7 days of grace from the failed renewal,
 *                                  never past the period end
 *   subscription paused/canceled   the moment it stopped (i.e. the past)
 *   one-time "lifetime" price      NULL (no end)
 *
 * A user's Paddle target is lifetime if any completed lifetime purchase is
 * linked to them, else the latest granted_until over their subscriptions.
 *
 * Coexisting with the Pro trial, vouchers and Buy Me a Coffee — paid time is
 * stacked ON TOP of the Pro time the account already had. When Paddle takes
 * over an account, the time left on its existing expiry (a trial's remaining
 * days, a voucher, BMAC) is stored as bonus_seconds, and what gets written is
 *
 *     pro_expires_at = Paddle target + bonus_seconds
 *
 * so a buyer on day 1 of a 60-day trial who pays for a month keeps Pro for a
 * month plus 60 days. Renewals move the target, never re-add the bonus.
 * After a cancellation the bonus runs from the end of the paid time. The bonus
 * is consumed once paid coverage has ended (no subscription active, trialing
 * or past_due): when a new subscription then moves the target, only what is
 * left of the bonus (last written expiry - now) carries over, so it cannot be
 * re-granted by re-subscribing. Renewals of a running subscription never
 * consume it, however late the renewal event arrives. If something else extends pro_expires_at after Paddle wrote
 * it (a voucher redeemed later), that added time joins the bonus. Paddle
 * also never writes below the expiry it found at takeover (baseline), and a
 * NULL (unlimited) is never touched unless a Paddle lifetime purchase is what
 * makes it NULL. State per user lives in paddle_entitlements.
 *
 * Linking a payment to an account: custom_data.pro_user_id (set by
 * assets/js/pricing.js for a signed-in buyer) when it names an existing row,
 * else the Paddle customer's email matched against pro_users.email. A payment
 * that matches neither is stored unlinked and logged; a later customer.created
 * / customer.updated event re-tries the email match and applies it then.
 *
 * Ordering: Paddle does not deliver in order. Every row stores the
 * occurred_at of the event that last wrote it, and an older event is ignored,
 * so retries and reordering converge on the latest state.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

const PADDLE_PAST_DUE_GRACE_DAYS = 7;
const PADDLE_SIGNATURE_TOLERANCE = 300;   // seconds; retries are re-signed

/**
 * Verify a Paddle-Signature header ("ts=…;h1=…", possibly several h1 during a
 * secret rotation) against the raw request body.
 */
function paddleVerifySignature(string $rawBody, string $header, string $secret, ?int $now = null, int $tolerance = PADDLE_SIGNATURE_TOLERANCE): bool
{
    if ($rawBody === '' || $header === '' || $secret === '') {
        return false;
    }
    $ts = null;
    $hashes = [];
    foreach (explode(';', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 'ts' && ctype_digit($kv[1])) {
            $ts = (int) $kv[1];
        } elseif ($kv[0] === 'h1') {
            $hashes[] = strtolower($kv[1]);
        }
    }
    if ($ts === null || !$hashes) {
        return false;
    }
    if (abs(($now ?? time()) - $ts) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts . ':' . $rawBody, $secret);
    foreach ($hashes as $hash) {
        if (hash_equals($expected, $hash)) {
            return true;
        }
    }
    return false;
}

/**
 * Which price IDs grant Pro, from the plans in pricing_tiers.php:
 * ['subscription' => [...month/year ids], 'lifetime' => [...once ids]].
 * A price outside both lists is stored but grants nothing.
 */
function paddlePlanPriceIds(array $tiers): array
{
    $ids = ['subscription' => [], 'lifetime' => []];
    foreach ($tiers as $tier) {
        foreach (($tier['priceId'] ?? []) as $kind => $priceId) {
            $ids[$kind === 'once' ? 'lifetime' : 'subscription'][] = (string) $priceId;
        }
    }
    return $ids;
}

/** CREATE TABLE statements for the mirror, for 'mysql' or 'sqlite'. */
function paddleSchemaStatements(string $driver): array
{
    $mysql = $driver === 'mysql';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $id = $mysql ? 'VARCHAR(64)' : 'TEXT';
    $str = $mysql ? 'VARCHAR(255)' : 'TEXT';
    $dt = $mysql ? 'DATETIME' : 'TEXT';
    $int = $mysql ? 'INT' : 'INTEGER';
    $bool = $mysql ? 'TINYINT(1)' : 'INTEGER';

    return [
        "CREATE TABLE IF NOT EXISTS paddle_customers (
            customer_id {$id} NOT NULL PRIMARY KEY,
            email {$str} NULL,
            occurred_at {$id} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$suffix}",
        "CREATE TABLE IF NOT EXISTS paddle_subscriptions (
            subscription_id {$id} NOT NULL PRIMARY KEY,
            customer_id {$id} NOT NULL,
            pro_user_id {$int} NULL,
            status {$id} NOT NULL,
            price_id {$id} NULL,
            period_ends_at {$dt} NULL,
            scheduled_change_action {$id} NULL,
            scheduled_change_at {$dt} NULL,
            granted_until {$dt} NULL,
            occurred_at {$id} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$suffix}",
        "CREATE TABLE IF NOT EXISTS paddle_transactions (
            transaction_id {$id} NOT NULL PRIMARY KEY,
            customer_id {$id} NOT NULL,
            pro_user_id {$int} NULL,
            price_id {$id} NULL,
            grants_lifetime {$bool} NOT NULL DEFAULT 0,
            occurred_at {$id} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$suffix}",
        "CREATE TABLE IF NOT EXISTS paddle_entitlements (
            pro_user_id {$int} NOT NULL PRIMARY KEY,
            applied_expires_at {$dt} NULL,
            baseline_expires_at {$dt} NULL,
            bonus_seconds {$int} NOT NULL DEFAULT 0,
            last_target {$dt} NULL,
            coverage_ended {$bool} NOT NULL DEFAULT 0,
            applied_lifetime {$bool} NOT NULL DEFAULT 0,
            updated_at {$dt} NOT NULL
        ){$suffix}",
    ];
}

/** RFC 3339 from Paddle → local 'Y-m-d H:i:s' (the format of pro_expires_at), or null. */
function paddleLocalTime(?string $rfc3339): ?string
{
    if ($rfc3339 === null || $rfc3339 === '') {
        return null;
    }
    $ts = strtotime($rfc3339);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** occurred_at normalised to a lexicographically sortable UTC string with microseconds. */
function paddleEventOrderKey(string $occurredAt): string
{
    try {
        $dt = new DateTimeImmutable($occurredAt);
    } catch (Exception $e) {
        return '';
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}

/**
 * Apply one verified webhook event. Returns a short outcome string for the log.
 * Throws on database errors so the endpoint answers non-2xx and Paddle retries.
 *
 * $options: 'prices' (paddlePlanPriceIds()), 'log' (callable level, msg, ctx),
 * 'has_account_type' (bool, whether pro_users.account_type exists), 'now' (int).
 */
function paddleHandleEvent(PDO $pdo, array $event, array $options): string
{
    $type = (string) ($event['event_type'] ?? '');
    $data = $event['data'] ?? null;
    $order = paddleEventOrderKey((string) ($event['occurred_at'] ?? ''));
    if (!is_array($data) || $order === '') {
        throw new InvalidArgumentException('Event without data or occurred_at');
    }

    if (strpos($type, 'subscription.') === 0) {
        return paddleSyncSubscription($pdo, $data, $order, (string) $event['occurred_at'], $options);
    }
    if ($type === 'transaction.completed') {
        return paddleSyncTransaction($pdo, $data, $order, $options);
    }
    if ($type === 'customer.created' || $type === 'customer.updated') {
        return paddleSyncCustomer($pdo, $data, $order, $options);
    }
    return 'ignored: ' . $type;
}

function paddleSyncSubscription(PDO $pdo, array $sub, string $order, string $occurredAt, array $options): string
{
    $id = (string) ($sub['id'] ?? '');
    $customerId = (string) ($sub['customer_id'] ?? '');
    if ($id === '' || $customerId === '') {
        throw new InvalidArgumentException('Subscription event without id/customer_id');
    }
    $now = $options['now'] ?? time();

    $pdo->beginTransaction();
    try {
        $existing = paddleFetch($pdo, 'SELECT occurred_at, pro_user_id FROM paddle_subscriptions WHERE subscription_id = ?', [$id]);
        if ($existing && strcmp((string) $existing['occurred_at'], $order) > 0) {
            $pdo->commit();
            return 'stale subscription event ignored';
        }

        $status = (string) ($sub['status'] ?? '');
        $priceId = (string) ($sub['items'][0]['price']['id'] ?? '');
        $periodEnd = paddleLocalTime($sub['current_billing_period']['ends_at'] ?? null);
        $scheduled = $sub['scheduled_change'] ?? null;

        $grants = in_array($priceId, $options['prices']['subscription'] ?? [], true);
        $grantedUntil = null;
        if ($grants) {
            switch ($status) {
                case 'active':
                case 'trialing':
                    $grantedUntil = $periodEnd;
                    break;
                case 'past_due':
                    $grace = date('Y-m-d H:i:s', strtotime($occurredAt) + PADDLE_PAST_DUE_GRACE_DAYS * 86400);
                    $grantedUntil = ($periodEnd !== null && $periodEnd < $grace) ? $periodEnd : $grace;
                    break;
                case 'paused':
                case 'canceled':
                    $grantedUntil = paddleLocalTime($sub['canceled_at'] ?? null)
                        ?? paddleLocalTime($sub['paused_at'] ?? null)
                        ?? paddleLocalTime($occurredAt);
                    break;
            }
        }

        $userId = paddleResolveUser($pdo, $sub['custom_data'] ?? null, $customerId)
            ?? (($existing && $existing['pro_user_id'] !== null) ? (int) $existing['pro_user_id'] : null);

        $row = [
            'customer_id' => $customerId,
            'pro_user_id' => $userId,
            'status' => $status,
            'price_id' => $priceId !== '' ? $priceId : null,
            'period_ends_at' => $periodEnd,
            'scheduled_change_action' => is_array($scheduled) ? ($scheduled['action'] ?? null) : null,
            'scheduled_change_at' => is_array($scheduled) ? paddleLocalTime($scheduled['effective_at'] ?? null) : null,
            'granted_until' => $grantedUntil,
            'occurred_at' => $order,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ];
        paddleUpsert($pdo, 'paddle_subscriptions', 'subscription_id', $id, $row, (bool) $existing);

        $outcome = "subscription {$id} {$status}";
        if (!$grants) {
            paddleLog($options, 'WARNING', 'Paddle subscription for a price that grants nothing', ['subscription_id' => $id, 'price_id' => $priceId]);
            $outcome .= ' (price grants nothing)';
        }
        if ($userId === null) {
            paddleLog($options, 'WARNING', 'Paddle subscription not linked to an account yet', ['subscription_id' => $id, 'customer_id' => $customerId]);
            $outcome .= ' (unlinked)';
        } else {
            $outcome .= '; ' . paddleApplyEntitlement($pdo, $userId, $options);
        }

        $pdo->commit();
        return $outcome;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function paddleSyncTransaction(PDO $pdo, array $txn, string $order, array $options): string
{
    // Subscription payments arrive as subscription.* events with the new
    // billing period; only one-time purchases are handled here.
    if (!empty($txn['subscription_id'])) {
        return 'subscription transaction ignored (handled via subscription events)';
    }
    $id = (string) ($txn['id'] ?? '');
    $customerId = (string) ($txn['customer_id'] ?? '');
    if ($id === '' || $customerId === '') {
        throw new InvalidArgumentException('Transaction event without id/customer_id');
    }
    $now = $options['now'] ?? time();

    $priceId = '';
    $lifetime = false;
    foreach (($txn['items'] ?? []) as $item) {
        $candidate = (string) ($item['price']['id'] ?? '');
        if ($priceId === '') {
            $priceId = $candidate;
        }
        if (in_array($candidate, $options['prices']['lifetime'] ?? [], true)) {
            $priceId = $candidate;
            $lifetime = true;
        }
    }

    $pdo->beginTransaction();
    try {
        $existing = paddleFetch($pdo, 'SELECT occurred_at, pro_user_id FROM paddle_transactions WHERE transaction_id = ?', [$id]);
        $userId = paddleResolveUser($pdo, $txn['custom_data'] ?? null, $customerId)
            ?? (($existing && $existing['pro_user_id'] !== null) ? (int) $existing['pro_user_id'] : null);

        paddleUpsert($pdo, 'paddle_transactions', 'transaction_id', $id, [
            'customer_id' => $customerId,
            'pro_user_id' => $userId,
            'price_id' => $priceId !== '' ? $priceId : null,
            'grants_lifetime' => $lifetime ? 1 : 0,
            'occurred_at' => $order,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ], (bool) $existing);

        $outcome = "transaction {$id}" . ($lifetime ? ' lifetime' : ' (price grants nothing)');
        if (!$lifetime) {
            paddleLog($options, 'WARNING', 'Paddle one-time purchase for a price that grants nothing', ['transaction_id' => $id, 'price_id' => $priceId]);
        }
        if ($userId === null) {
            paddleLog($options, 'WARNING', 'Paddle purchase not linked to an account yet', ['transaction_id' => $id, 'customer_id' => $customerId]);
            $outcome .= ' (unlinked)';
        } elseif ($lifetime) {
            $outcome .= '; ' . paddleApplyEntitlement($pdo, $userId, $options);
        }

        $pdo->commit();
        return $outcome;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function paddleSyncCustomer(PDO $pdo, array $customer, string $order, array $options): string
{
    $id = (string) ($customer['id'] ?? '');
    if ($id === '') {
        throw new InvalidArgumentException('Customer event without id');
    }
    $now = $options['now'] ?? time();
    $email = trim((string) ($customer['email'] ?? ''));

    $pdo->beginTransaction();
    try {
        $existing = paddleFetch($pdo, 'SELECT occurred_at FROM paddle_customers WHERE customer_id = ?', [$id]);
        if ($existing && strcmp((string) $existing['occurred_at'], $order) > 0) {
            $pdo->commit();
            return 'stale customer event ignored';
        }
        paddleUpsert($pdo, 'paddle_customers', 'customer_id', $id, [
            'email' => $email !== '' ? $email : null,
            'occurred_at' => $order,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ], (bool) $existing);

        // Payments that arrived before this customer's email was known.
        $outcome = "customer {$id}";
        $userId = paddleResolveUser($pdo, null, $id);
        if ($userId !== null) {
            $linked = 0;
            foreach (['paddle_subscriptions', 'paddle_transactions'] as $table) {
                $stmt = $pdo->prepare("UPDATE {$table} SET pro_user_id = ? WHERE customer_id = ? AND pro_user_id IS NULL");
                $stmt->execute([$userId, $id]);
                $linked += $stmt->rowCount();
            }
            if ($linked > 0) {
                $outcome .= "; linked {$linked} payment(s) to user {$userId}; " . paddleApplyEntitlement($pdo, $userId, $options);
            }
        }

        $pdo->commit();
        return $outcome;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** pro_users.id for a payment: custom_data.pro_user_id if it exists, else by customer email. */
function paddleResolveUser(PDO $pdo, $customData, string $customerId): ?int
{
    $claimed = is_array($customData) ? (string) ($customData['pro_user_id'] ?? '') : '';
    if ($claimed !== '' && ctype_digit($claimed)) {
        $row = paddleFetch($pdo, 'SELECT id FROM pro_users WHERE id = ?', [(int) $claimed]);
        if ($row) {
            return (int) $row['id'];
        }
    }
    $customer = paddleFetch($pdo, 'SELECT email FROM paddle_customers WHERE customer_id = ?', [$customerId]);
    if ($customer && !empty($customer['email'])) {
        $row = paddleFetch($pdo, 'SELECT id FROM pro_users WHERE LOWER(email) = LOWER(?)', [$customer['email']]);
        if ($row) {
            return (int) $row['id'];
        }
    }
    return null;
}

/**
 * Recompute the user's Paddle target and write it to pro_users under the
 * ownership rule in the file header. Must run inside the caller's transaction.
 */
function paddleApplyEntitlement(PDO $pdo, int $userId, array $options): string
{
    $now = $options['now'] ?? time();
    $user = paddleFetch($pdo, 'SELECT pro_expires_at FROM pro_users WHERE id = ?', [$userId]);
    if (!$user) {
        return 'user gone';
    }

    $lifetime = (bool) paddleFetch($pdo, 'SELECT 1 FROM paddle_transactions WHERE pro_user_id = ? AND grants_lifetime = 1', [$userId]);
    $target = null;
    if (!$lifetime) {
        $max = paddleFetch($pdo, 'SELECT MAX(granted_until) AS m FROM paddle_subscriptions WHERE pro_user_id = ? AND granted_until IS NOT NULL', [$userId]);
        $target = $max['m'] ?? null;
        if ($target === null) {
            return 'no Paddle entitlement';
        }
    }

    $current = $user['pro_expires_at'];
    $applied = paddleFetch($pdo, 'SELECT applied_expires_at, baseline_expires_at, bonus_seconds, last_target, coverage_ended, applied_lifetime FROM paddle_entitlements WHERE pro_user_id = ?', [$userId]);
    // Paid coverage is running while any subscription is still billing.
    $running = (bool) paddleFetch($pdo, "SELECT 1 FROM paddle_subscriptions WHERE pro_user_id = ? AND status IN ('active', 'trialing', 'past_due')", [$userId]);

    $bonus = 0;
    if ($lifetime) {
        $new = null;
        $baseline = null;
    } elseif ($current === null) {
        // Unlimited already. Only a Paddle lifetime could have set it, and a
        // lifetime purchase is not revoked here, so nothing to do either way.
        return 'already unlimited';
    } else {
        $bonus = $applied ? (int) $applied['bonus_seconds'] : 0;
        $lastTarget = $applied['last_target'] ?? null;
        $lastApplied = $applied['applied_expires_at'] ?? null;

        // Consumption: paid coverage had ended (every subscription canceled
        // or paused), the bonus has been running since, and now the target
        // moves (a new subscription) — only what is left of the bonus stays.
        // A plain renewal never gets here: its subscription stayed active.
        if ($applied && !empty($applied['coverage_ended']) && $lastTarget !== null
            && $target !== $lastTarget && strtotime($lastTarget) < $now && $lastApplied !== null) {
            $bonus = max(0, strtotime($lastApplied) - $now);
        }

        $owned = $applied && !$applied['applied_lifetime'] && $lastApplied === $current;
        if ($owned) {
            $baseline = $applied['baseline_expires_at'];
        } else {
            // Takeover, or something else changed the expiry since Paddle
            // wrote it: the time it adds beyond what Paddle had written (or
            // beyond now) joins the bonus, and becomes the new floor.
            $from = max($now, $lastApplied !== null ? strtotime($lastApplied) : 0);
            $bonus += max(0, strtotime($current) - $from);
            $baseline = $current;
        }

        $stacked = date('Y-m-d H:i:s', strtotime($target) + $bonus);
        $new = $baseline !== null ? max($baseline, $stacked) : $stacked;
    }

    $grants = $new === null || strtotime($new) >= $now;
    if ($new !== $current || $grants) {
        $sql = 'UPDATE pro_users SET pro_expires_at = ?' . ($grants && !empty($options['has_account_type']) ? ", account_type = 'pro'" : '') . ' WHERE id = ?';
        $pdo->prepare($sql)->execute([$new, $userId]);
    }

    paddleUpsert($pdo, 'paddle_entitlements', 'pro_user_id', $userId, [
        'applied_expires_at' => $new,
        'baseline_expires_at' => $baseline,
        'bonus_seconds' => $bonus,
        'last_target' => $lifetime ? null : $target,
        'coverage_ended' => $running ? 0 : 1,
        'applied_lifetime' => $lifetime ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s', $now),
    ], (bool) $applied);

    paddleLog($options, 'INFO', 'Paddle entitlement applied', [
        'user_id' => $userId, 'old_expires' => $current, 'new_expires' => $new, 'lifetime' => $lifetime, 'bonus_seconds' => $bonus,
    ]);
    return 'pro_expires_at ' . ($current ?? 'NULL') . ' -> ' . ($new ?? 'NULL');
}

function paddleFetch(PDO $pdo, string $sql, array $params): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Portable upsert (MySQL and SQLite): the caller already knows whether the row exists. */
function paddleUpsert(PDO $pdo, string $table, string $keyColumn, $key, array $row, bool $exists): void
{
    $columns = array_keys($row);
    if ($exists) {
        $set = implode(', ', array_map(function ($c) { return "{$c} = ?"; }, $columns));
        $pdo->prepare("UPDATE {$table} SET {$set} WHERE {$keyColumn} = ?")->execute(array_merge(array_values($row), [$key]));
    } else {
        $cols = implode(', ', array_merge([$keyColumn], $columns));
        $marks = implode(', ', array_fill(0, count($columns) + 1, '?'));
        $pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$marks})")->execute(array_merge([$key], array_values($row)));
    }
}

function paddleLog(array $options, string $level, string $message, array $context): void
{
    if (isset($options['log']) && is_callable($options['log'])) {
        ($options['log'])($level, $message, $context);
    }
}

/**
 * The Paddle customer and subscriptions to open the customer portal for, for
 * one pro_users row — resolved only from rows the webhook linked to that
 * account, never from anything the browser sends. Null when the account has
 * no Paddle payment (nothing to manage yet).
 *
 * Returns ['customer_id' => 'ctm_…', 'subscription_ids' => ['sub_…', …]]:
 * the customer of the most recently updated linked payment, and that
 * customer's subscriptions that are not canceled (the portal returns a
 * cancel / update-payment deep link per id).
 */
function paddlePortalTarget(PDO $pdo, int $userId): ?array
{
    $latest = paddleFetch($pdo,
        'SELECT customer_id FROM (
             SELECT customer_id, updated_at FROM paddle_subscriptions WHERE pro_user_id = ?
             UNION ALL
             SELECT customer_id, updated_at FROM paddle_transactions WHERE pro_user_id = ?
         ) linked ORDER BY updated_at DESC LIMIT 1',
        [$userId, $userId]);
    if (!$latest || empty($latest['customer_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT subscription_id FROM paddle_subscriptions WHERE pro_user_id = ? AND customer_id = ? AND status <> 'canceled' ORDER BY updated_at DESC");
    $stmt->execute([$userId, $latest['customer_id']]);
    return [
        'customer_id' => (string) $latest['customer_id'],
        'subscription_ids' => array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
    ];
}
