<?php

declare(strict_types=1);

/**
 * Pure decision logic for the Buy Me a Coffee webhook (bmac_handler.php):
 * reading the event out of a decoded payload, deciding whether it may grant
 * Pro, and the replay-protection key. No config.php, no database, no
 * globals, so tests/bmac_handler_test.php can load it on its own. Every
 * function is function_exists()-guarded like pro_trial.php.
 *
 * Payload shape. The published Buy Me a Coffee webhook spec (OpenAPI 3.1,
 * "Buy Me a Coffee — Webhook Events 1.0.0") wraps every event in an
 * envelope:
 *
 *   {"event_id": 1234, "type": "donation.created", "live_mode": true,
 *    "created": 1719825600, "attempt": 1,
 *    "data": {"id": 98765, "supporter_email": "...", "amount": 15,
 *             "currency": "USD", "status": "succeeded", ...}}
 *
 * so supporter_email, amount, currency and the payment/subscription id live
 * under "data". The handler originally read them at the top level; that
 * older flat shape is still accepted when there is no "data" object.
 *
 * event_id is documented as unique per delivery attempt (and always 1 for
 * dashboard test events), so it is not a usable dedupe key: a retry would
 * look like a new event. The key is the event type plus the payment or
 * subscription id from "data" instead.
 */

if (!function_exists('bmacGrantEventTypes')) {
    /**
     * The only event types that may grant Pro. Everything else — refunds,
     * cancellations, pauses, updates, shop purchases, unknown types — is
     * acknowledged and ignored.
     *
     * donation.created / membership.started / recurring_donation.started are
     * the documented payment events; payment.completed and one_time_support
     * are the legacy names the handler accepted before and are kept so an
     * older-format sender keeps working.
     *
     * @return list<string>
     */
    function bmacGrantEventTypes(): array
    {
        return [
            'donation.created',
            'membership.started',
            'recurring_donation.started',
            'payment.completed',
            'one_time_support',
        ];
    }
}

if (!function_exists('bmacNormalizeEmail')) {
    /**
     * Trim and lowercase, the same case folding paddle_sync.php and
     * pro_trial.php apply before matching an address. Returns '' for
     * anything that is not a valid address afterwards.
     */
    function bmacNormalizeEmail($email): string
    {
        if (!is_string($email)) {
            return '';
        }
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }
        return $email;
    }
}

if (!function_exists('bmacParseEvent')) {
    /**
     * Normalise a decoded webhook payload (nested envelope or legacy flat
     * shape) into the fields the handler uses.
     *
     * @param array  $payload    json_decode($raw, true)
     * @param string $rawPayload the raw body, for the last-resort dedupe key
     * @return array{type:string, live_mode:?bool, nested:bool, email:string,
     *               amount:?float, currency:?string, status:?string,
     *               refunded:bool, bmac_id:?string, supporter_name:?string,
     *               dedupe_key:string}
     */
    function bmacParseEvent(array $payload, string $rawPayload): array
    {
        $nested = isset($payload['data']) && is_array($payload['data']);
        $fields = $nested ? $payload['data'] : $payload;

        $type = is_string($payload['type'] ?? null) ? trim($payload['type']) : '';

        $liveMode = null;
        if (array_key_exists('live_mode', $payload)) {
            $liveMode = filter_var($payload['live_mode'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $amount = null;
        foreach (['amount', 'total_amount_charged', 'total_amount'] as $key) {
            if (isset($fields[$key]) && is_numeric($fields[$key])) {
                $amount = (float) $fields[$key];
                break;
            }
        }

        $currency = null;
        if (isset($fields['currency']) && is_string($fields['currency']) && trim($fields['currency']) !== '') {
            $currency = strtoupper(trim($fields['currency']));
        }

        $status = isset($fields['status']) && is_string($fields['status']) ? strtolower(trim($fields['status'])) : null;
        $refunded = filter_var($fields['refunded'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || !empty($fields['refunded_at']);

        $id = $fields['id'] ?? $fields['payment_id'] ?? null;
        $bmacId = (is_string($id) || is_int($id)) && (string) $id !== '' ? (string) $id : null;

        $name = $fields['supporter_name'] ?? $fields['name'] ?? null;

        if ($nested) {
            // Type + payment/subscription id: stable across retries (which
            // bump "attempt" and, per the spec, event_id). Without an id,
            // hash "data" alone so a retry still collides.
            $dedupeKey = $bmacId !== null
                ? $type . ':' . $bmacId
                : 'data:' . hash('sha256', (string) json_encode($fields));
        } else {
            // Legacy flat shape: unchanged from the original handler.
            $dedupeKey = $bmacId ?? hash('sha256', $rawPayload);
        }

        return [
            'type' => $type,
            'live_mode' => $liveMode,
            'nested' => $nested,
            'email' => bmacNormalizeEmail($fields['supporter_email'] ?? $fields['email'] ?? null),
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'refunded' => $refunded,
            'bmac_id' => $bmacId,
            'supporter_name' => is_string($name) ? $name : null,
            // The column is VARCHAR(191).
            'dedupe_key' => substr($dedupeKey, 0, 191),
        ];
    }
}

if (!function_exists('bmacClassifyEvent')) {
    /**
     * Decide whether a parsed event grants Pro.
     *
     * Returns ['action' => 'grant'] or ['action' => 'ignore', 'reason' => …].
     * A missing/invalid email on an otherwise grantable event is 'invalid'
     * (the handler answers 400), everything else that does not grant is
     * 'ignore' (200, so Buy Me a Coffee does not retry and eventually
     * disable the webhook).
     *
     * @param array  $event       bmacParseEvent() output
     * @param string $environment detectEnvironment() value
     * @return array{action:string, reason?:string}
     */
    function bmacClassifyEvent(array $event, string $environment): array
    {
        if (!in_array($event['type'], bmacGrantEventTypes(), true)) {
            return ['action' => 'ignore', 'reason' => 'unsupported event type'];
        }
        // live_mode=false is the dashboard "Send test event" button.
        if ($event['live_mode'] === false && $environment === 'production') {
            return ['action' => 'ignore', 'reason' => 'test event'];
        }
        if ($event['refunded'] || in_array($event['status'], ['refunded', 'canceled', 'cancelled', 'paused', 'failed'], true)) {
            return ['action' => 'ignore', 'reason' => 'payment not in a paid state'];
        }
        if ($event['amount'] === null || !($event['amount'] > 0)) {
            return ['action' => 'ignore', 'reason' => 'missing or non-positive amount'];
        }
        if ($event['currency'] !== null && preg_match('/^[A-Z]{3}$/', $event['currency']) !== 1) {
            return ['action' => 'ignore', 'reason' => 'invalid currency'];
        }
        if ($event['email'] === '') {
            return ['action' => 'invalid', 'reason' => 'invalid or missing email'];
        }
        return ['action' => 'grant'];
    }
}
