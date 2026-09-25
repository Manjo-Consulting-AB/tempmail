<?php
/**
 * Mail Shield — terms of service. Rendered by partials/legal_page.php; the facts
 * it quotes (company, contact address, trial length, quota, retention) come from
 * partials/legal_facts.php so they follow $config instead of being typed here.
 *
 * Paddle requires this page before it approves the domain, and requires it to
 * name Paddle as Merchant of Record: section "Payments" carries that wording.
 * Every feature and retention period named here is one the code enforces —
 * the same rule as the rest of the copy (CLAUDE.md, brief §9).
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$f = require __DIR__ . '/partials/legal_facts.php';

$trialLine = $f['trial_days'] > 0
    ? '<p>Every new account starts with a free Pro trial of ' . $f['trial_days'] . ' days, once per email address. No payment details are needed for the trial, and it does not turn into a paid plan by itself: when it ends, the account continues as a free Regular account unless you buy Pro.</p>'
    : '';

$msLegalDoc = [
    'title'       => 'Terms of service',
    'lede'        => 'These terms are the agreement between you and us when you use Mail Shield. They are written to be read, so they are short where they can be.',
    'description' => 'The terms that apply when you use Mail Shield, including Pro plans sold through Paddle as Merchant of Record.',
    'path'        => '/terms.php',
    'updated'     => '25 September 2026',
    'sections'    => [
        [
            'id'      => 'who',
            'heading' => 'Who we are',
            'html'    => '<p>Mail Shield is operated by ' . $f['company_full'] . ' ("we", "us"). You can reach us at ' . $f['contact'] . '.</p>'
                . '<p>By creating an account or using the service you accept these terms. If you do not accept them, please do not use Mail Shield.</p>',
        ],
        [
            'id'      => 'service',
            'heading' => 'The service',
            'html'    => '<p>Mail Shield is a secondary email environment on the domain ' . $f['domain'] . '. It receives email for you; it is not a service for sending email. Depending on your plan you can:</p>'
                . '<ul>'
                . '<li>create timed addresses that receive mail for ' . $f['free_hours'] . ' hours on a free account, or for 1 to 7 days on Pro;</li>'
                . '<li>keep up to 10 sticky addresses (Pro only);</li>'
                . '<li>forward what arrives to other systems — RSS, webhooks, Pushover and digest emails (Pro only).</li>'
                . '</ul>'
                . '<p>Each account has a storage quota of ' . $f['quota_mb'] . ' MB. When the quota is reached, the oldest messages are deleted automatically to make room for new ones.</p>'
                . '<p>Mail is deleted automatically when an address expires or is removed. Mail Shield is not an archive: keep a copy elsewhere of anything you need to keep.</p>',
        ],
        [
            'id'      => 'account',
            'heading' => 'Your account',
            'html'    => '<p>You need an account to use Mail Shield. You must give a real email address you control, keep your password to yourself, and tell us if you think someone else has access to your account. You are responsible for what happens under your account.</p>'
                . '<p>You must be at least 16 years old, or the age of digital consent where you live if that is higher.</p>'
                . '<p>A free Regular account that nobody has signed in to for ' . $f['delete_days'] . ' days is deleted, together with its addresses and mail. We email a warning ' . ($f['delete_days'] - $f['warn_days']) . ' days before that happens. Pro accounts are not deleted for inactivity.</p>'
                . '<p>You can delete your account at any time from your profile. Deletion removes your addresses and your mail.</p>',
        ],
        [
            'id'      => 'acceptable-use',
            'heading' => 'Acceptable use',
            'html'    => '<p>You may not use Mail Shield to:</p>'
                . '<ul>'
                . '<li>break the law, or help someone else break it;</li>'
                . '<li>commit fraud, impersonate others, or evade bans, limits or verification in ways that harm the service you use the address with;</li>'
                . '<li>receive material that is illegal to possess, including child sexual abuse material;</li>'
                . '<li>attack, probe or overload Mail Shield or its infrastructure, or get round its limits;</li>'
                . '<li>resell or give access to the service as your own product without our written permission.</li>'
                . '</ul>'
                . '<p>We may suspend or close an account that breaks these rules, and remove content connected to it. Where the law requires, we will cooperate with the authorities.</p>',
        ],
        [
            'id'      => 'suitability',
            'heading' => 'What Mail Shield is not for',
            'html'    => '<p>Do not use a timed address for anything you need to keep or recover — banking, government services, or an account whose password reset would go to it. Once the address expires, so does your access to that mail.</p>'
                . '<p>A timed address reached through a shared link is readable by anyone who has the link. Treat it as somewhere to receive mail, not somewhere to keep a secret.</p>'
                . '<p>We cannot guarantee that every message sent to you arrives. Senders can delay, reject or filter mail before it reaches us.</p>',
        ],
        [
            'id'      => 'plans',
            'heading' => 'Plans, trial and vouchers',
            'html'    => '<p>Mail Shield has a free Regular plan and a paid Pro plan. Pro can be bought as a monthly or yearly subscription, or once as Lifetime. The Pro features are the ones described on the <a href="/pricing.php">pricing page</a> at the time you buy.</p>'
                . $trialLine
                . '<p>Pro time can also be granted with a voucher code. A voucher has no cash value and cannot be exchanged for money.</p>'
                . '<p>"Lifetime" means for as long as we operate Mail Shield, not for your lifetime or ours. If we ever close the service, we will tell you in advance as described under <a href="#termination">Ending the service</a>.</p>'
                . '<p>When Pro ends, the account becomes a free Regular account. Its sticky addresses are deleted 7 days later, with their mail, and its integrations stop.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Payments and Paddle',
            'html'    => '<p>Our order process is conducted by our online reseller Paddle.com. Paddle.com is the Merchant of Record for all our orders. Paddle provides all customer service inquiries and handles returns.</p>'
                . '<p>That means the purchase itself is a contract between you and Paddle, on <a href="https://www.paddle.com/legal/checkout-buyer-terms" rel="noopener">Paddle\'s buyer terms</a>. Paddle takes the payment, charges and pays any sales tax or VAT that applies where you are, and sends you the receipt. Prices are shown in your local currency, including that tax.</p>'
                . '<h3>Subscriptions</h3>'
                . '<p>A monthly or yearly subscription renews automatically at the end of each period, at the price then in force, until you cancel it. You can cancel at any time with <strong>Manage billing</strong> on your profile or through Paddle. After cancelling you keep Pro until the end of the period you have paid for; there is no charge after that.</p>'
                . '<p>If we change the price of a subscription, we tell you by email before the change applies to your next renewal, so you can cancel first.</p>'
                . '<h3>Refunds</h3>'
                . '<p>You can get your money back within 30 days of a payment. The details are in our <a href="/refund-policy.php">refund policy</a>.</p>',
        ],
        [
            'id'      => 'termination',
            'heading' => 'Ending the service',
            'html'    => '<p>You can stop using Mail Shield and delete your account at any time. Cancelling a subscription is a separate step, done with Manage billing; deleting the account does not cancel it by itself.</p>'
                . '<p>We may suspend or close your account if you break these terms. If we do so for a reason other than a breach, or if we close Mail Shield altogether, we will give at least 30 days\' notice by email and refund the unused part of any subscription period you have paid for. For a Lifetime purchase made less than two years before the service closes, we refund a share of the price in proportion to the part of those two years that is left.</p>',
        ],
        [
            'id'      => 'liability',
            'heading' => 'Warranty and liability',
            'html'    => '<p>We work to keep Mail Shield available and your mail safe, but the service is provided as it is, and we do not promise that it will be uninterrupted or free of errors.</p>'
                . '<p>To the extent the law allows, we are not liable for indirect or consequential loss, such as lost profits, lost data or lost access to an account elsewhere, and our total liability to you is limited to what you have paid for Mail Shield in the 12 months before the claim arose.</p>'
                . '<p>Nothing in these terms limits rights you have as a consumer under mandatory law, or our liability for gross negligence or intent.</p>',
        ],
        [
            'id'      => 'privacy',
            'heading' => 'Privacy',
            'html'    => '<p>How we handle personal data, including the mail you receive, is described in our <a href="/privacy.php">privacy policy</a>.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Changes to these terms',
            'html'    => '<p>We may update these terms. The date at the top shows when they last changed. If a change affects your rights in a material way, we will email you at least 30 days before it takes effect. If you continue to use Mail Shield after that, the new terms apply; if you do not accept them, you can delete your account and cancel your subscription before then.</p>',
        ],
        [
            'id'      => 'law',
            'heading' => 'Governing law and disputes',
            'html'    => '<p>These terms are governed by Swedish law. Disputes are settled by the Swedish courts, with the Stockholm District Court as the first instance, unless mandatory consumer law gives you the right to bring a claim where you live.</p>'
                . '<p>If you are a consumer in the EU, you can also turn to the Swedish National Board for Consumer Disputes (Allmänna reklamationsnämnden, <a href="https://www.arn.se" rel="noopener">arn.se</a>). Please contact us at ' . $f['contact'] . ' first — most things are quicker to sort out directly.</p>',
        ],
    ],
];

require __DIR__ . '/partials/legal_page.php';
