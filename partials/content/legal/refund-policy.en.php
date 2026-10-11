<?php
/**
 * The refund policy in English: the whole document as data, rendered by
 * partials/legal_page.php. Loaded by refund-policy.php for the English page,
 * and as the fallback when a language has no
 * partials/content/legal/refund-policy.<locale>.php. The Swedish file mirrors
 * this one: the same section ids in the same order, the same conditional
 * branches and the same facts; only the words differ. Both carry the same
 * 'updated' date (ISO Y-m-d; legal_page.php renders it with i18nDate()).
 *
 * Variables refund-policy.php defines for this file:
 *   $f     array    the legal facts (partials/legal_facts.php), already escaped
 *   $ref   array    the invite programme's settings (referralSettings())
 *   $msU   closure  a public page path -> its escaped, language-aware URL
 * and defines $guaranteeDays and $graceDays itself.
 *
 * 'html' holds trusted markup authored here with every interpolated value
 * escaped, never anything a visitor supplies. Never write a number into the
 * text: it comes from $f or $ref.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// How long the money-back guarantee lasts, and the grace before a lapsed Pro
// account's Sticky addresses are deleted (cron/cleanup.php). Fixed by the code,
// not by $config; named here so no number is typed into the prose.
$guaranteeDays = 30;
$graceDays = 7;

return [
    'title'       => 'Refund policy',
    'lede'        => 'If Mail Shield Pro is not right for you, you can have your money back within ' . $guaranteeDays . ' days of paying. No questions asked.',
    'description' => 'Mail Shield offers a ' . $guaranteeDays . '-day money-back guarantee on Pro subscriptions and Lifetime purchases. Refunds are issued by Paddle, our reseller.',
    'path'        => '/refund-policy.php',
    'updated'     => '2026-10-10',
    'sections'    => [
        [
            'id'      => 'guarantee',
            'heading' => $guaranteeDays . '-day money-back guarantee',
            'html'    => '<p>Every payment for Mail Shield Pro can be refunded in full if you ask within ' . $guaranteeDays . ' days of the date you were charged. That applies to:</p>'
                . '<ul>'
                . '<li>the first payment for a monthly or yearly subscription;</li>'
                . '<li>each renewal of a subscription, counted from the date of that renewal;</li>'
                . '<li>a one-time Lifetime purchase.</li>'
                . '</ul>'
                . '<p>You do not need to give a reason. The refund is for the full amount you paid, including any tax.</p>'
                . '<p>This guarantee comes in addition to your statutory rights as a consumer, including any right of withdrawal, which Paddle handles as Merchant of Record under its buyer terms. It does not replace or limit them.</p>',
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
            'html'    => '<p>A refund ends the Pro time that the payment paid for, and a refunded subscription is cancelled so that it does not renew. Your account stays, as a free Regular account. As with any Pro plan that ends, sticky addresses are deleted ' . $graceDays . ' days later, with their mail, so move anything you want to keep before then.</p>'
                . '<p>A refunded Lifetime purchase ends Pro on the date of the refund. After that the same ' . $graceDays . ' days apply before sticky addresses are deleted.</p>'
                . '<p>Any Pro time you had before the purchase — the free trial, or a voucher — is not affected by the refund.</p>'
                . '<p>If a payment made through an <a href="' . $msU('/terms.php#referrals') . '">invite</a> is refunded or charged back before the inviter\'s reward is granted, which is ' . $ref['hold_days'] . ' days after the payment, the inviter\'s bonus is not granted, and the friend\'s bonus months end with the refunded subscription. A refund after the inviter has received their reward does not take that reward back.</p>'
                . '<p>A refund is not a request to delete your data. Apart from Pro ending, your account and its data follow the ordinary retention rules in our <a href="' . $msU('/privacy.php#retention') . '">privacy policy</a>. To delete them, delete your account.</p>',
        ],
        [
            'id'      => 'cancel',
            'heading' => 'Cancelling without a refund',
            'html'    => '<p>You can cancel a subscription at any time with <strong>Manage billing</strong> on your profile. Cancelling stops future renewals; you keep Pro until the end of the period you have paid for. If you are still within ' . $guaranteeDays . ' days of the last payment and want your money back as well, ask for a refund as described above.</p>',
        ],
        [
            'id'      => 'after-30',
            'heading' => 'After ' . $guaranteeDays . ' days',
            'html'    => '<p>After ' . $guaranteeDays . ' days, payments are not normally refunded. Your rights under mandatory consumer law are not affected, and if something went wrong on our side — a double charge, or a service that did not work as described — contact us at ' . $f['contact'] . ' and we will put it right.</p>'
                . '<p>If we close Mail Shield or end your account for a reason other than a breach of our <a href="' . $msU('/terms.php') . '">terms of service</a>, we refund as described there.</p>',
        ],
    ],
];
