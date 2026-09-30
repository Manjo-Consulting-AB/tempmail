<?php
/**
 * Pricing helpers shared by pricing.php and the landing page's plans card
 * (partials/landing/plans.php).
 *
 *   pricingDetectCountry()    the visitor's country from the CDN headers
 *   pricingLandingConfig()    what the landing page needs to show "From €x"
 *
 * Both pages price the same way — Paddle.PricePreview() in the browser, the
 * country handed over when a CDN header names one and left out otherwise so
 * Paddle geolocates by IP — which is why the country lookup lives here once.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/paddle_sync.php';

if (!function_exists('pricingDetectCountry')) {
    /**
     * ISO 3166-1 alpha-2 country from the CDN in front of the site, or null.
     * Vercel sends x-vercel-ip-country, Cloudflare cf-ipcountry (with XX for
     * "unknown" and T1 for Tor, neither of which is a country).
     */
    function pricingDetectCountry(): ?string
    {
        foreach (['HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_CF_IPCOUNTRY'] as $header) {
            $value = strtoupper(trim((string) ($_SERVER[$header] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $value) && $value !== 'XX' && $value !== 'T1') {
                return $value;
            }
        }
        return null;
    }
}

if (!function_exists('pricingLandingConfig')) {
    /**
     * The data assets/js/landing-price.js needs to show the monthly Pro price
     * on the landing page, or null when the page should keep its price-free
     * wording (the plans card then reads exactly as it did before).
     *
     * Null on anything but production: a sandbox catalog holds test prices,
     * and the landing page is indexable. Null too when Paddle is not configured
     * or no plan has a monthly price — silently, because pricing.php already
     * reports a broken Paddle setup and this runs on every landing request.
     *
     * The price shown is the first plan with a monthly price, which is Pro in
     * pricing_tiers.php.
     */
    function pricingLandingConfig(array $config): ?array
    {
        try {
            $paddle = paddleClientSettings($config);
            if ($paddle['environment'] !== 'production') {
                return null;
            }
            $tiers = paddleTiersForEnvironment(require __DIR__ . '/pricing_tiers.php', $paddle['environment']);
        } catch (Throwable $e) {
            return null;
        }

        foreach ($tiers as $tier) {
            if (!empty($tier['priceId']['month'])) {
                return [
                    'environment' => $paddle['environment'],
                    'token'       => $paddle['token'],
                    'country'     => pricingDetectCountry(),
                    'priceId'     => $tier['priceId']['month'],
                ];
            }
        }
        return null;
    }
}
