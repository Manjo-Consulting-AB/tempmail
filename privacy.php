<?php
/**
 * Mail Shield — privacy policy. Rendered by partials/legal_page.php; the facts
 * it quotes come from partials/legal_facts.php and follow $config.
 *
 * Every data flow described here is one the code has, and the list has to be
 * kept in step with it: the account address (encrypted at rest, pii_crypto.php),
 * received mail and its retention (cron/cleanup.php, MailboxQuota), system logs
 * with IP addresses (LOG_RETENTION_DAYS), the hashed trial claim
 * (pro_trial.php, five years), the Paddle mirror tables (paddle_sync.php), the
 * integrations a user configures themselves, Google Analytics in the public
 * head (partials/public_head.php, register.php), and the session and 2FA
 * trusted-device cookies (TwoFactorAuth::TRUSTED_DEVICE_COOKIE, 30 days).
 * Adding a processor or a tracker means updating this page in the same change.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$f = require __DIR__ . '/partials/legal_facts.php';

$msLegalDoc = [
    'title'       => 'Privacy policy',
    'lede'        => 'Mail Shield exists to keep your primary address out of places it does not need to be. This page says what we collect to do that, why, how long we keep it, and who else sees it.',
    'description' => 'What personal data Mail Shield collects, why, how long it is kept, and who processes it — including payments handled by Paddle.',
    'path'        => '/privacy.php',
    'updated'     => '25 September 2026',
    'sections'    => [
        [
            'id'      => 'controller',
            'heading' => 'Who is responsible',
            'html'    => '<p>The data controller is ' . $f['company_full'] . ', which operates Mail Shield. Questions about your data, and requests to use the rights described below, go to ' . $f['contact'] . '.</p>'
                . '<p>For purchases, Paddle is the Merchant of Record and is the controller of the payment data it collects; see <a href="#payments">Payments</a>.</p>',
        ],
        [
            'id'      => 'data',
            'heading' => 'What we collect',
            'html'    => '<h3>Your account</h3>'
                . '<p>The email address you register with, and a hash of your password — never the password itself. If you turn on two-factor authentication, we store its secret encrypted, and a record of browsers you choose to trust. We record when the account was created and when you last signed in, and your settings: plan, address lifetime, digests and integrations.</p>'
                . '<p>Your registered email address is stored encrypted, and looked up through a keyed hash rather than in plain text.</p>'
                . '<h3>The mail you receive</h3>'
                . '<p>Messages sent to your Mail Shield addresses — headers, content and attachments — are stored so that you can read them. We do not read your mail. It is processed automatically to store it, show it to you and pass it to the integrations you have set up, and it is not used for advertising or profiling.</p>'
                . '<h3>Technical data</h3>'
                . '<p>Our server logs record events such as sign-ins, errors and suspicious requests, with the IP address involved. Sign-in attempts are recorded to stop password guessing. We do not write your registered email address into the logs.</p>'
                . '<h3>Trial record</h3>'
                . '<p>To give each email address the free Pro trial only once, we keep a keyed hash of the normalised address. It is not linked to your account and cannot be turned back into the address.</p>'
                . '<h3>Payments</h3>'
                . '<p>When you buy Pro, Paddle tells us your customer ID, the email address you paid with, and the status of your subscription and transactions, so that we can give your account Pro. We never receive or store your card details.</p>'
                . '<h3>Support</h3>'
                . '<p>If you contact us, we keep the conversation so that we can answer you.</p>',
        ],
        [
            'id'      => 'purposes',
            'heading' => 'Why, and on what legal basis',
            'html'    => '<ul>'
                . '<li><strong>Running the service you signed up for</strong> — your account, your addresses, your mail, your integrations and your plan. Legal basis: performance of our contract with you (GDPR article 6.1 b).</li>'
                . '<li><strong>Keeping the service secure</strong> — logs, sign-in limits, abuse detection and the trial record. Legal basis: our legitimate interest in protecting Mail Shield and its users from misuse (article 6.1 f).</li>'
                . '<li><strong>Accounting</strong> — payment records. Legal basis: our legal obligation under Swedish bookkeeping law (article 6.1 c).</li>'
                . '<li><strong>Understanding how the public pages are used</strong> — Google Analytics. Legal basis: our legitimate interest in improving the site, and your consent where the law requires it for cookies.</li>'
                . '<li><strong>Telling you about your account</strong> — verification, sign-in links, security notices, inactivity warnings and changes to our terms. These are service messages, not marketing; we do not send newsletters.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'retention',
            'heading' => 'How long we keep it',
            'html'    => '<ul>'
                . '<li><strong>Temporary addresses and their mail</strong> — ' . $f['free_hours'] . ' hours on a free account, or the 1 to 7 days you choose on Pro. Then they are deleted automatically.</li>'
                . '<li><strong>Personal addresses</strong> — until you remove them, or 7 days after your Pro plan ends. Their mail is deleted with them, and sooner if your storage quota of ' . $f['quota_mb'] . ' MB fills up, oldest first.</li>'
                . '<li><strong>Your account</strong> — until you delete it. A free Regular account nobody signs in to for ' . $f['delete_days'] . ' days is deleted, after an email warning.</li>'
                . '<li><strong>Server logs</strong> — ' . $f['log_days'] . ' days.</li>'
                . '<li><strong>Trusted browsers for two-factor authentication</strong> — 30 days, or until you remove them.</li>'
                . '<li><strong>Trial record</strong> — five years from the first time the address was used.</li>'
                . '<li><strong>Payment records</strong> — seven years, as Swedish bookkeeping law requires.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'sharing',
            'heading' => 'Who else processes your data',
            'html'    => '<p>We do not sell your data, and we do not share it with advertisers. It is processed by these parties, and only for the reasons given:</p>'
                . '<ul>'
                . '<li><strong>Our hosting provider</strong>, which runs the servers that receive, store and serve your mail, under a data processing agreement with us.</li>'
                . '<li><strong>Paddle</strong> (Paddle.com Market Ltd), for payments — see below.</li>'
                . '<li><strong>Google</strong> (Google Ireland Ltd), for Google Analytics on our public pages.</li>'
                . '<li><strong>The destinations you choose.</strong> If you set up a webhook, Pushover, an RSS feed or a digest, we send your mail, or information about it, to that destination. Those services are chosen by you and handle the data under their own terms.</li>'
                . '<li><strong>Authorities</strong>, where the law requires us to disclose data.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Payments are processed by Paddle',
            'html'    => '<p>Our order process is conducted by our online reseller Paddle.com. Paddle.com is the Merchant of Record for all our orders. When you check out, you give your payment details, name, country and email address to Paddle, not to us, and Paddle processes them as an independent controller under <a href="https://www.paddle.com/legal/privacy" rel="noopener">Paddle\'s privacy policy</a>.</p>'
                . '<p>The pricing page loads Paddle\'s script to show prices in your currency and to open the checkout; Paddle may set its own cookies there.</p>',
        ],
        [
            'id'      => 'cookies',
            'heading' => 'Cookies',
            'html'    => '<ul>'
                . '<li><strong>A session cookie</strong>, which keeps you signed in. It is necessary for the service and ends when you sign out or close the session.</li>'
                . '<li><strong>A trusted-browser cookie</strong>, set only if you choose to have a browser remembered for two-factor authentication. It lasts 30 days.</li>'
                . '<li><strong>Google Analytics cookies</strong> on the public pages, which measure visits in aggregate.</li>'
                . '<li><strong>Paddle cookies</strong> on the pricing page and in the checkout.</li>'
                . '</ul>'
                . '<p>You can block or delete cookies in your browser. Blocking the session cookie means you cannot sign in.</p>',
        ],
        [
            'id'      => 'transfers',
            'heading' => 'Transfers outside the EU',
            'html'    => '<p>Google and Paddle may process data outside the EU and EEA, including in the United States and the United Kingdom. Where they do, the transfer is covered by an adequacy decision — such as the EU–US Data Privacy Framework or the decision for the United Kingdom — or by the EU\'s standard contractual clauses.</p>',
        ],
        [
            'id'      => 'security',
            'heading' => 'How we protect it',
            'html'    => '<p>Connections to Mail Shield are encrypted. Your registered address, two-factor secrets and integration credentials are encrypted at rest; passwords are hashed. Attachments are only served through signed, expiring links. Access to production data is limited to the people who run the service.</p>'
                . '<p>Remember that email itself is usually not end-to-end encrypted, and that a shared inbox link can be read by anyone who has it.</p>',
        ],
        [
            'id'      => 'rights',
            'heading' => 'Your rights',
            'html'    => '<p>Under the GDPR you have the right to access the personal data we hold about you, to have it corrected or deleted, to restrict or object to its processing, and to receive it in a portable format. Where we rely on your consent, you can withdraw it at any time.</p>'
                . '<p>Most of this you can do yourself: your profile shows your account data, and deleting your account removes it, except what we must keep for accounting. For anything else, email ' . $f['contact'] . '. We answer within one month.</p>'
                . '<p>If you think we handle your data wrongly, you can complain to the Swedish Authority for Privacy Protection (Integritetsskyddsmyndigheten, <a href="https://www.imy.se" rel="noopener">imy.se</a>) or to the authority where you live.</p>',
        ],
        [
            'id'      => 'children',
            'heading' => 'Children',
            'html'    => '<p>Mail Shield is not meant for children under 16, and we do not knowingly collect data from them.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Changes to this policy',
            'html'    => '<p>When this policy changes, we update the date at the top. If a change is material, we tell you by email before it takes effect.</p>',
        ],
    ],
];

require __DIR__ . '/partials/legal_page.php';
