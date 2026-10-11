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

// The invite programme's numbers (epic #387) come from config, like $f.
require_once __DIR__ . '/referrals.php';
$ref = referralSettings($config['referral'] ?? []);

// The copy: one content file per language (documentaion/I18N.md §4), English
// when a language has none. The file reads $f, $ref and $msU (listed in
// terms.en.php's docblock) and returns the document as data.
$msU = function (string $path): string {
    return htmlspecialchars(i18nUrl($path), ENT_QUOTES, 'UTF-8');
};

$msLocale = i18nLocale();
$msLegalFile = __DIR__ . '/partials/content/legal/terms.' . $msLocale . '.php';
if (!preg_match('/^[a-z]{2}$/', $msLocale) || !is_file($msLegalFile)) {
    $msLegalFile = __DIR__ . '/partials/content/legal/terms.en.php';
}
$msLegalDoc = require $msLegalFile;

require __DIR__ . '/partials/legal_page.php';
