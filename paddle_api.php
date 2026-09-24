<?php
/**
 * The one server-side Paddle API call this app makes: minting a customer
 * portal session (POST /customers/{id}/portal-sessions), so a buyer can cancel,
 * change payment method or download invoices on Paddle's hosted portal.
 *
 * Needs PADDLE_API_KEY — a server-side key with only the "Customer portal
 * sessions: write" permission. It is used here and nowhere else: never printed
 * into a page and never sent to the browser (pro_profile.php returns only the
 * resulting URL). The webhook sync (paddle_sync.php) needs no key at all.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

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
