<?php
/**
 * Mail Shield - privacy policy. Rendered by partials/legal_page.php; the copy
 * lives in partials/content/legal/privacy.<locale>.php (documentaion/I18N.md
 * section 4) and the facts it quotes come from partials/legal_facts.php and
 * follow $config. The data-flow inventory the policy must keep in step with
 * the code is in privacy.en.php's docblock.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$f = require __DIR__ . '/partials/legal_facts.php';

// The invite cookie's lifetime (epic #387) comes from config, like $f.
require_once __DIR__ . '/referrals.php';
$ref = referralSettings($config['referral'] ?? []);

// The copy: one content file per language, English when a language has none.
// The file reads $f and $ref (listed in privacy.en.php's docblock) and returns
// the document as data.
$msLocale = i18nLocale();
$msLegalFile = __DIR__ . '/partials/content/legal/privacy.' . $msLocale . '.php';
if (!preg_match('/^[a-z]{2}$/', $msLocale) || !is_file($msLegalFile)) {
    $msLegalFile = __DIR__ . '/partials/content/legal/privacy.en.php';
}
$msLegalDoc = require $msLegalFile;

require __DIR__ . '/partials/legal_page.php';
