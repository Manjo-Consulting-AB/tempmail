<?php
/**
 * The plans shown on pricing.php — edit this file to change them.
 *
 * Shape of each plan (the PHP counterpart of a TypeScript interface):
 *
 *   'name'        string   card heading
 *   'description' string   one line under the name
 *   'features'    string[] the bullet list
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
    'Up to 10 sticky addresses',
    'Timed address lifetime configurable from 1 to 7 days',
    'Keep mail for ' . $holdDays . ' days, up to ' . $holdMax . ' times a year',
    'RSS, webhooks, Pushover and digest emails',
    'External mailboxes and the Agent',
    'Priority support',
];

return [
    [
        'name'        => 'Pro',
        'description' => 'Your second inbox, with the wiring.',
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
        'name'        => 'Lifetime',
        'description' => 'Pro, paid once.',
        'features'    => [
            'Everything in Pro',
            'One payment — no subscription to renew',
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
