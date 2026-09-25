<?php
/**
 * Mail Shield — refund policy: a 30-day money-back guarantee on every payment.
 * Rendered by partials/legal_page.php; the facts it quotes come from
 * partials/legal_facts.php.
 *
 * Paddle, as Merchant of Record, issues the refund itself; this page says so,
 * and gives both ways to ask (Paddle directly, or us).
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$f = require __DIR__ . '/partials/legal_facts.php';

$msLegalDoc = [
    'title'       => 'Refund policy',
    'lede'        => 'If Mail Shield Pro is not right for you, you can have your money back within 30 days of paying. No questions asked.',
    'description' => 'Mail Shield offers a 30-day money-back guarantee on Pro subscriptions and Lifetime purchases. Refunds are issued by Paddle, our reseller.',
    'path'        => '/refund-policy.php',
    'updated'     => '25 September 2026',
    'sections'    => [
        [
            'id'      => 'guarantee',
            'heading' => '30-day money-back guarantee',
            'html'    => '<p>Every payment for Mail Shield Pro can be refunded in full if you ask within 30 days of the date you were charged. That applies to:</p>'
                . '<ul>'
                . '<li>the first payment for a monthly or yearly subscription;</li>'
                . '<li>each renewal of a subscription, counted from the date of that renewal;</li>'
                . '<li>a one-time Lifetime purchase.</li>'
                . '</ul>'
                . '<p>You do not need to give a reason. The refund is for the full amount you paid, including any tax.</p>',
        ],
        [
            'id'      => 'how',
            'heading' => 'How to ask for a refund',
            'html'    => '<p>Our order process is conducted by our online reseller Paddle.com. Paddle.com is the Merchant of Record for all our orders. Paddle provides all customer service inquiries and handles returns.</p>'
                . '<p>You can ask either way:</p>'
                . '<ul>'
                . '<li><strong>Through Paddle</strong> — follow the link in your receipt email, or look up your purchase at <a href="https://paddle.net" rel="noopener">paddle.net</a>.</li>'
                . '<li><strong>Through us</strong> — email ' . $f['contact'] . ' from your account\'s address, or from the address on the receipt, and we will pass the request to Paddle.</li>'
                . '</ul>'
                . '<p>Refunds go back to the payment method you used. Paddle usually processes them within a few working days; how soon the money shows up depends on your bank or card provider.</p>',
        ],
        [
            'id'      => 'after',
            'heading' => 'What happens to your account',
            'html'    => '<p>A refund ends the Pro time that the payment paid for, and a refunded subscription is cancelled so that it does not renew. Your account stays, as a free Regular account. As with any Pro plan that ends, sticky addresses are deleted 7 days later, with their mail, so move anything you want to keep before then.</p>'
                . '<p>Any Pro time you had before the purchase — the free trial, or a voucher — is not affected by the refund.</p>',
        ],
        [
            'id'      => 'cancel',
            'heading' => 'Cancelling without a refund',
            'html'    => '<p>You can cancel a subscription at any time with <strong>Manage billing</strong> on your profile. Cancelling stops future renewals; you keep Pro until the end of the period you have paid for. If you are still within 30 days of the last payment and want your money back as well, ask for a refund as described above.</p>',
        ],
        [
            'id'      => 'after-30',
            'heading' => 'After 30 days',
            'html'    => '<p>After 30 days, payments are not normally refunded. Your rights under mandatory consumer law are not affected, and if something went wrong on our side — a double charge, or a service that did not work as described — contact us at ' . $f['contact'] . ' and we will put it right.</p>'
                . '<p>If we close Mail Shield or end your account for a reason other than a breach of our <a href="/terms.php">terms of service</a>, we refund as described there.</p>',
        ],
    ],
];

require __DIR__ . '/partials/legal_page.php';
