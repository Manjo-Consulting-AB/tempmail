<?php
/**
 * The plans shown on pricing.php — edit this file to change them.
 *
 * Shape of each plan (the PHP counterpart of a TypeScript interface):
 *
 *   'key'         string   'pro' / 'lifetime': the plan's id in the catalogs
 *   'name'        string   card heading — a product name, never translated
 *   'description' string   catalog key (lang/<locale>.php) of the line under the name
 *   'features'    array    the bullet list, as [catalog key, params] pairs;
 *                          pricing.php turns each into text with t($key, $params)
 *   'featured'    bool     marked as the recommended card
 *   'priceId'     one entry per Paddle environment, each holding either
 *                 ['month' => 'pri_…', 'year' => 'pri_…']   subscription,
 *                                                            follows the toggle
 *                 or ['once' => 'pri_…']                     one-time purchase
 *
 * Both catalogs sit side by side and PADDLE_ENVIRONMENT picks one:
 * paddleTiersForEnvironment() in paddle_sync.php resolves the entry into the
 * flat ['month' => …, 'year' => …] / ['once' => …] form before anything reads
 * it. Switching to production — and rolling back — is therefore one env
 * change, never a code change that can get out of step with the client token
 * and the webhook's own environment: a pri_… from one environment does not
 * exist in the other, and a payment on an unknown price grants nothing.
 *
 * Sandbox and production both hold the product "Mail Shield Pro", created
 * 2026-09-30. The amounts live in Paddle, not here — pricing.php shows
 * whatever Paddle returns for the visitor's country.
 *
 * This file carries catalog keys, not user-facing copy, so it still loads
 * without i18n.php (paddle_webhook.php, migrate_paddle_billing.php and
 * tests/paddle_sync_test.php require it). The English wording is in lang/en.php.
 *
 * Copy rule (CLAUDE.md, brief §9): every feature listed must be one the code
 * has. These are the Pro features from partials/landing/plans.php.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

// The retention-hold bullet's two numbers come from $config (epic #369), never
// written into the string. The fallbacks match config.php's defaults and only
// apply when this file is loaded without config.php.
$holdDays = max(1, (int) ($config['retention_hold']['days'] ?? 30));
$holdMax  = max(1, (int) ($config['retention_hold']['max_per_year'] ?? 4));

$proFeatures = [
    ['pricing.feature.sticky_max', ['max' => 10]],
    ['pricing.feature.timed_range', ['min' => 1, 'max' => 7]],
    ['pricing.feature.hold', ['days' => $holdDays, 'times' => $holdMax]],
    ['pricing.feature.automation', []],
    ['pricing.feature.external', []],
    ['pricing.feature.support', []],
];

return [
    [
        'key'         => 'pro',
        'name'        => 'Pro',
        'description' => 'pricing.tier.pro.description',
        'features'    => $proFeatures,
        'featured'    => true,
        'priceId'     => [
            'sandbox'    => [
                'month' => 'pri_01m3a6bdv26jf8dw0z8b8xp81k',
                'year'  => 'pri_01m3a6bdzwq8enav6qbvdme5db',
            ],
            'production' => [
                'month' => 'pri_01m3s15f9tbhrvd3zrff8h1ybg',
                'year'  => 'pri_01m3s14qntdr6zy9pwa5jw2f21',
            ],
        ],
    ],
    [
        'key'         => 'lifetime',
        'name'        => 'Lifetime',
        'description' => 'pricing.tier.lifetime.description',
        'features'    => [
            ['pricing.feature.everything_pro', []],
            ['pricing.feature.one_payment', []],
        ],
        'featured'    => false,
        'priceId'     => [
            'sandbox'    => [
                'once' => 'pri_01m3a6j2gy3f6bkqaf6jpysdrc',
            ],
            'production' => [
                'once' => 'pri_01m3s13tx4qxqnvccz2ef5wqef',
            ],
        ],
    ],
];
