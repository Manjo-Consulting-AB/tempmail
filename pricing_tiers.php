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
 *   'priceId'     either   ['month' => 'pri_…', 'year' => 'pri_…']  subscription,
 *                                                                  follows the toggle
 *                 or       ['once' => 'pri_…']                     one-time purchase
 *
 * The price IDs belong to ONE Paddle environment. The ones below are the
 * SANDBOX catalog (product "Mail Shield Pro"); a sandbox pri_… does not exist
 * in production, so switch these when PADDLE_ENVIRONMENT goes to production.
 * The amounts live in Paddle, not here — pricing.php shows whatever Paddle
 * returns for the visitor's country.
 *
 * Copy rule (CLAUDE.md, brief §9): every feature listed must be one the code
 * has. These are the Pro features from partials/landing/plans.php.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$proFeatures = [
    'Up to 10 sticky addresses',
    'Timed address lifetime configurable from 1 to 7 days',
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
            'month' => 'pri_01m3a6bdv26jf8dw0z8b8xp81k',
            'year'  => 'pri_01m3a6bdzwq8enav6qbvdme5db',
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
            'once' => 'pri_01m3a6j2gy3f6bkqaf6jpysdrc',
        ],
    ],
];
