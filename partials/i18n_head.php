<?php
/**
 * hreflang alternates and og:locale for a public page (i18n.php,
 * documentaion/I18N.md). Shared by partials/public_head.php and by pages that
 * write their own <head> (faq.php).
 *
 * Inputs: $msPath (the page's path, e.g. '/terms.php') and $config. Emits
 * nothing while the page exists in one language only, so a monolingual site
 * has no hreflang and no og:locale. The canonical is not here: each page's
 * head writes its own.
 */

if (!isset($config) || !is_array($config) || !isset($msPath)) {
    http_response_code(500);
    exit;
}

$msI18nHeadOn      = $config['i18n']['enabled'] ?? ['en'];
$msI18nHeadOrigin  = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msI18nHeadAlts    = function_exists('i18nAlternates') ? i18nAlternates((string) $msPath, $msI18nHeadOn, $msI18nHeadOrigin) : [];
$msI18nHeadLocale  = function_exists('i18nLocale') ? i18nLocale() : 'en';
$msI18nHeadOg      = ['en' => 'en_GB', 'sv' => 'sv_SE', 'de' => 'de_DE', 'es' => 'es_ES', 'fr' => 'fr_FR'];
$msI18nHeadEsc     = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

foreach ($msI18nHeadAlts as $msI18nHeadAlt) : ?>
    <link rel="alternate" hreflang="<?php echo $msI18nHeadEsc($msI18nHeadAlt['hreflang']); ?>" href="<?php echo $msI18nHeadEsc($msI18nHeadAlt['href']); ?>">
<?php endforeach; ?>
<?php if (count($msI18nHeadAlts) > 0) : ?>
    <meta property="og:locale" content="<?php echo $msI18nHeadEsc($msI18nHeadOg[$msI18nHeadLocale] ?? $msI18nHeadLocale); ?>">
<?php endif; ?>
