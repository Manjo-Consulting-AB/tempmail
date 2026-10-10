<?php
/**
 * The server-side Paddle API calls this app makes:
 *
 *   - paddleCreatePortalUrl()        POST  /customers/{id}/portal-sessions, so a
 *                                    buyer can cancel, change payment method or
 *                                    download invoices on Paddle's hosted portal;
 *   - paddleGetSubscription()        GET   /subscriptions/{id};
 *   - paddleRescheduleSubscription() PATCH /subscriptions/{id}, moving
 *                                    next_billed_at (referral rewards, epic #387).
 *
 * The last two are not called at runtime yet; only check_paddle_reschedule.php
 * (sandbox) and tests/paddle_api_test.php use them.
 *
 * Needs PADDLE_API_KEY, a server-side key with the permissions "Customer portal
 * sessions: write", "Subscriptions: read" and "Subscriptions: write". It is used
 * here and nowhere else: never printed into a page, never logged, never put in
 * an exception message and never sent to the browser (pro_profile.php returns
 * only the resulting URL). The webhook sync (paddle_sync.php) needs no key.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

// paddleLocalTime(): only function and constant declarations at top level, no
// config.php, no connection, so it is safe to reuse here.
require_once __DIR__ . '/paddle_sync.php';

/**
 * Validated server-side settings, or a RuntimeException naming what is wrong.
 * Same no-default rule as paddleClientSettings(): the environment must be set,
 * and the key must belong to it (pdl_sdbx_apikey_ = sandbox,
 * pdl_live_apikey_ = production).
 */
function paddleServerSettings(array $config): array
{
    $environment = paddleClientSettings($config)['environment'];
    $key = trim((string) ($config['paddle']['api_key'] ?? ''));
    $prefixes = ['sandbox' => 'pdl_sdbx_apikey_', 'production' => 'pdl_live_apikey_'];

    if ($key === '') {
        throw new RuntimeException('PADDLE_API_KEY is not set');
    }
    if (strpos($key, $prefixes[$environment]) !== 0) {
        throw new RuntimeException('PADDLE_API_KEY is not a ' . $environment . ' API key (must start with ' . $prefixes[$environment] . ')');
    }

    return [
        'api_key' => $key,
        'base_url' => $environment === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com',
    ];
}

/**
 * Authenticated portal URL (urls.general.overview) for one customer. One-time
 * and short-lived: mint it per click, never cache it. Throws on any failure.
 */
function paddleCreatePortalUrl(array $settings, string $customerId, array $subscriptionIds): string
{
    if (!preg_match('/^ctm_[a-z0-9]{26}$/', $customerId)) {
        throw new InvalidArgumentException('Invalid Paddle customer id');
    }
    $subscriptionIds = array_values(array_filter($subscriptionIds, function ($id) {
        return is_string($id) && preg_match('/^sub_[a-z0-9]{26}$/', $id);
    }));

    $ch = curl_init($settings['base_url'] . '/customers/' . $customerId . '/portal-sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $settings['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['subscription_ids' => $subscriptionIds]),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Paddle portal request failed: ' . $error);
    }
    $json = json_decode((string) $body, true);
    $url = $json['data']['urls']['general']['overview'] ?? null;
    if ($status < 200 || $status >= 300 || !is_string($url) || strpos($url, 'https://') !== 0) {
        $code = $json['error']['code'] ?? 'unknown';
        throw new RuntimeException("Paddle portal request returned HTTP {$status} ({$code})");
    }
    return $url;
}

/**
 * One Paddle API request. Returns ['status' => int, 'json' => ?array] and throws
 * RuntimeException on a transport error. $transport, when given, replaces curl:
 * it is called as $transport($method, $url, $headers, $jsonBody) and returns
 * ['status' => int, 'body' => string] (the test runs without a network).
 */
function paddleApiRequest(array $settings, string $method, string $path, ?array $body = null, ?callable $transport = null): array
{
    $url = $settings['base_url'] . $path;
    $headers = [
        'Authorization: Bearer ' . $settings['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $jsonBody = $body === null ? null : json_encode($body);

    if ($transport !== null) {
        $res = $transport($method, $url, $headers, $jsonBody);
        $rawBody = (string) ($res['body'] ?? '');
        $status = (int) ($res['status'] ?? 0);
    } else {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new RuntimeException('Paddle request failed: ' . $error);
        }
        $rawBody = (string) $result;
    }

    $json = json_decode($rawBody, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null];
}

/** Throws InvalidArgumentException unless $id looks like a Paddle subscription id. */
function paddleAssertSubscriptionId(string $id): void
{
    if (!preg_match('/^sub_[a-z0-9]{26}$/', $id)) {
        throw new InvalidArgumentException('Invalid Paddle subscription id');
    }
}

/** data of a 2xx subscription answer, else a RuntimeException with status and error code. */
function paddleSubscriptionData(array $res): array
{
    $status = $res['status'];
    $data = $res['json']['data'] ?? null;
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        $code = $res['json']['error']['code'] ?? 'unknown';
        throw new RuntimeException("Paddle subscription request returned HTTP {$status} ({$code})");
    }
    return $data;
}

/** GET /subscriptions/{id}: the subscription object (json.data). Throws on any failure. */
function paddleGetSubscription(array $settings, string $subscriptionId, ?callable $transport = null): array
{
    paddleAssertSubscriptionId($subscriptionId);
    return paddleSubscriptionData(paddleApiRequest($settings, 'GET', '/subscriptions/' . $subscriptionId, null, $transport));
}

/**
 * PATCH /subscriptions/{id}: moves the next charge to $nextBilledAt (RFC 3339,
 * UTC) without prorating. Returns the updated subscription (json.data).
 */
function paddleRescheduleSubscription(array $settings, string $subscriptionId, string $nextBilledAt, ?callable $transport = null): array
{
    paddleAssertSubscriptionId($subscriptionId);
    if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z$/', $nextBilledAt)) {
        throw new InvalidArgumentException('next_billed_at must be RFC 3339 in UTC');
    }
    $body = ['next_billed_at' => $nextBilledAt, 'proration_billing_mode' => 'do_not_bill'];
    return paddleSubscriptionData(paddleApiRequest($settings, 'PATCH', '/subscriptions/' . $subscriptionId, $body, $transport));
}

/** The app's local 'Y-m-d H:i:s' (default time zone, see config.php) as Paddle's UTC 'Y-m-d\TH:i:s.000000\Z'. */
function paddleRfc3339ToUtc(string $localDatetime): string
{
    try {
        $dt = new DateTimeImmutable($localDatetime);
    } catch (Exception $e) {
        throw new InvalidArgumentException('Invalid local datetime');
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000000\Z');
}

/** Paddle's RFC 3339 timestamp as the app's local 'Y-m-d H:i:s'; same conversion as paddleLocalTime(). */
function paddleUtcToLocal(string $rfc3339): string
{
    $local = paddleLocalTime($rfc3339);
    if ($local === null) {
        throw new InvalidArgumentException('Invalid RFC 3339 timestamp');
    }
    return $local;
}
