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

// The invite programme's numbers (epic #387) come from config, like $f.
require_once __DIR__ . '/referrals.php';
$ref = referralSettings($config['referral'] ?? []);

// A link to a public page, in this request's language and escaped for HTML. The
// content files in partials/content/legal/ use it for every public page.
$msU = function (string $path): string {
    return htmlspecialchars(i18nUrl($path), ENT_QUOTES, 'UTF-8');
};

// The copy: one content file per language (documentaion/I18N.md §4), English
// when a language has none. The file reads $f, $ref and $msU (listed in
// refund-policy.en.php's docblock) and returns the document as data.
$msLocale = i18nLocale();
$msLegalFile = __DIR__ . '/partials/content/legal/refund-policy.' . $msLocale . '.php';
if (!preg_match('/^[a-z]{2}$/', $msLocale) || !is_file($msLegalFile)) {
    $msLegalFile = __DIR__ . '/partials/content/legal/refund-policy.en.php';
}
$msLegalDoc = require $msLegalFile;

require __DIR__ . '/partials/legal_page.php';
