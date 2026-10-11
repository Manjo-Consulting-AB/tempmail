<?php

declare(strict_types=1);

/**
 * Regression coverage for i18n.php and the catalogs in lang/
 * (documentaion/I18N.md): enabling languages, the /<locale>/ URL prefix and
 * the redirect for a page not offered in that language, i18nUrl(), hreflang
 * alternates, t()/tp() with fallback and placeholders, catalog parity (no key
 * outside English, identical placeholders and plural forms), the marketing
 * head and footer rendered in both languages, and scans that the .htaccess
 * rewrite, the dev router and i18nSupportedLocales() name the same prefixes
 * and that no public-shell <html> tag hardcodes lang="en" any more.
 *
 * Run with:  php tests/i18n_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database, no network,
 * no config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/i18n.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** The {name} placeholders in a catalog value, sorted, plural forms merged. */
function placeholders($value): array
{
    $text = is_array($value) ? implode(' ', $value) : (string) $value;
    preg_match_all('/\{([a-z_][a-z0-9_]*)\}/i', $text, $m);
    $names = array_values(array_unique($m[1]));
    sort($names);
    return $names;
}

$realPages = i18nTranslatedPages();
$both = ['en', 'sv'];
$onlyEn = ['en'];

// --- Enabling languages -------------------------------------------------------
same('enabled: default is English only', ['en'], i18nParseEnabled(''));
same('enabled: en,sv', ['en', 'sv'], i18nParseEnabled('en,sv'));
same('enabled: English is always on and first', ['en', 'sv'], i18nParseEnabled('sv'));
same('enabled: unknown codes, case and spaces', ['en', 'sv'], i18nParseEnabled(' SV , xx, sv,'));

// --- Path prefix --------------------------------------------------------------
same('split: plain path', [null, '/terms.php'], i18nSplitPath('/terms.php'));
same('split: /index.php is /', [null, '/'], i18nSplitPath('/index.php'));
same('split: /sv/', ['sv', '/'], i18nSplitPath('/sv/'));
same('split: /sv', ['sv', '/'], i18nSplitPath('/sv'));
same('split: /sv/terms.php', ['sv', '/terms.php'], i18nSplitPath('/sv/terms.php'));
same('split: /en/ is not a prefix (source language has none)', [null, '/en/terms.php'], i18nSplitPath('/en/terms.php'));
same('split: unsupported code is not a prefix', [null, '/xx/terms.php'], i18nSplitPath('/xx/terms.php'));
same('split: double slash cannot produce a protocol-relative path', ['sv', '/evil.example/x.php'], i18nSplitPath('/sv//evil.example/x.php'));

// --- Request resolution (nothing translated yet: the shipped list) -----------
same('resolve: CLI has no REQUEST_URI', null, i18nResolveRequest([], $both));
same('resolve: CLI is English', 'en', i18nLocale());
same('resolve: plain URL', null, i18nResolveRequest(['REQUEST_URI' => '/terms.php'], $both));
same('resolve: untranslated /sv/ page redirects to the plain URL', '/terms.php', i18nResolveRequest(['REQUEST_URI' => '/sv/terms.php'], $both));
same('resolve: ...and stays English', 'en', i18nLocale());
same('resolve: query string survives the redirect', '/blog.php?post=2026-10-05', i18nResolveRequest(['REQUEST_URI' => '/sv/blog.php?post=2026-10-05'], $both));
same('resolve: app page under /sv/ redirects', '/inbox.php?address=abc', i18nResolveRequest(['REQUEST_URI' => '/sv/inbox.php?address=abc'], $both));

// --- With Swedish translations (simulated) ------------------------------------
i18nTranslatedPages(['/' => ['en', 'sv'], '/terms.php' => ['en', 'sv'], '/faq.php' => ['en']]);

same('resolve: translated page in an enabled language', null, i18nResolveRequest(['REQUEST_URI' => '/sv/terms.php'], $both));
same('resolve: ...sets Swedish', 'sv', i18nLocale());
same('resolve: /sv/ is the Swedish landing page', null, i18nResolveRequest(['REQUEST_URI' => '/sv/'], $both));
same('resolve: translated but Swedish switched off redirects', '/terms.php', i18nResolveRequest(['REQUEST_URI' => '/sv/terms.php'], $onlyEn));
same('resolve: ...and resets to English', 'en', i18nLocale());
same('resolve: untranslated page still redirects', '/faq.php', i18nResolveRequest(['REQUEST_URI' => '/sv/faq.php'], $both));

same('url: English keeps the plain path', '/terms.php', i18nUrl('/terms.php', 'en', $both));
same('url: Swedish gets the prefix', '/sv/terms.php', i18nUrl('/terms.php', 'sv', $both));
same('url: Swedish landing page', '/sv/', i18nUrl('/', 'sv', $both));
same('url: index.php is the landing page', '/sv/', i18nUrl('index.php', 'sv', $both));
same('url: query and fragment kept', '/sv/terms.php?x=1#who', i18nUrl('/terms.php?x=1#who', 'sv', $both));
same('url: untranslated page keeps its plain path', '/faq.php', i18nUrl('/faq.php', 'sv', $both));
same('url: app page keeps its plain path', '/inbox.php', i18nUrl('/inbox.php', 'sv', $both));
same('url: Swedish switched off keeps the plain path', '/terms.php', i18nUrl('/terms.php', 'sv', $onlyEn));

same('alternates: en, sv and x-default', [
    ['hreflang' => 'en', 'href' => 'https://example.test/terms.php'],
    ['hreflang' => 'sv', 'href' => 'https://example.test/sv/terms.php'],
    ['hreflang' => 'x-default', 'href' => 'https://example.test/terms.php'],
], i18nAlternates('/terms.php', $both, 'https://example.test/'));
same('alternates: none for a one-language page', [], i18nAlternates('/faq.php', $both, 'https://example.test'));
same('alternates: none while Swedish is off', [], i18nAlternates('/terms.php', $onlyEn, 'https://example.test'));

// --- Strings ------------------------------------------------------------------
i18nSetLocale('sv');
same('t: Swedish string', 'Språk', t('lang.switcher.label'));
same('t: explicit locale', 'Language', t('lang.switcher.label', [], 'en'));
same('t: missing key shows the key', 'no.such.key', t('no.such.key'));
same('te: escapes', 'a&lt;b&gt;&quot;', te('a<b>"'));
same('format: placeholders', 'Pro for 60 days', i18nFormat('Pro for {days} days', ['days' => 60]));
same('format: unknown placeholder left as is', 'a {b}', i18nFormat('a {b}', ['c' => 1]));
i18nSetLocale('xx');
same('setLocale: unknown code means English', 'en', i18nLocale());

$en = i18nCatalog('en');
same('tp: missing key shows the key', 'no.such.plural', tp('no.such.plural', 3));

// --- Catalog parity -----------------------------------------------------------
check('catalog: English is not empty', count($en) > 0);
foreach (array_keys(i18nSupportedLocales()) as $code) {
    $file = $root . '/lang/' . $code . '.php';
    check("catalog {$code}: lang/{$code}.php exists", is_file($file));
    $cat = i18nCatalog($code);
    $extra = array_diff(array_keys($cat), array_keys($en));
    check("catalog {$code}: no key outside English", $extra === [], implode(', ', $extra));
    foreach ($cat as $key => $value) {
        if (!isset($en[$key])) {
            continue;
        }
        if (placeholders($value) !== placeholders($en[$key])) {
            check("catalog {$code}: placeholders of {$key}", false, implode(',', placeholders($value)) . ' vs ' . implode(',', placeholders($en[$key])));
        }
        if (is_array($en[$key]) !== is_array($value)) {
            check("catalog {$code}: plural shape of {$key}", false);
        }
        if (is_array($value) && !isset($value['other'])) {
            check("catalog {$code}: {$key} has an 'other' form", false);
        }
    }
    if ($code !== 'en') {
        $missing = count(array_diff(array_keys($en), array_keys($cat)));
        echo "      catalog {$code}: {$missing} of " . count($en) . " keys still fall back to English\n";
    }
}

// --- The marketing shell, rendered --------------------------------------------
function renderShell(string $locale, array $enabled): string
{
    $config = [
        'email' => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
        'app' => ['version' => 'test'],
        'i18n' => ['enabled' => $enabled],
    ];
    $GLOBALS['config'] = $config;
    $msPage = ['title' => 'T', 'path' => '/terms.php', 'analytics' => false];
    i18nSetLocale($locale);
    ob_start();
    require dirname(__DIR__) . '/partials/public_head.php';
    require dirname(__DIR__) . '/partials/public_footer.php';
    return (string) ob_get_clean();
}

$html = renderShell('sv', $both);
check('head sv: <html lang="sv">', strpos($html, '<html lang="sv">') !== false);
check('head sv: canonical is the Swedish URL', strpos($html, '<link rel="canonical" href="https://example.test/sv/terms.php">') !== false);
check('head sv: hreflang sv', strpos($html, 'hreflang="sv" href="https://example.test/sv/terms.php"') !== false);
check('head sv: hreflang x-default', strpos($html, 'hreflang="x-default" href="https://example.test/terms.php"') !== false);
check('head sv: og:locale', strpos($html, '<meta property="og:locale" content="sv_SE">') !== false);
check('footer sv: switcher labelled in Swedish', strpos($html, 'aria-label="Språk"') !== false);
check('footer sv: link to English', strpos($html, 'href="/terms.php"') !== false);
check('footer sv: current language marked', (bool) preg_match('#href="/sv/terms\.php"\s+hreflang="sv"\s+lang="sv" aria-current="true"#', $html));

$html = renderShell('en', $onlyEn);
check('head en-only: <html lang="en">', strpos($html, '<html lang="en">') !== false);
check('head en-only: canonical unchanged', strpos($html, '<link rel="canonical" href="https://example.test/terms.php">') !== false);
check('head en-only: no hreflang', strpos($html, 'hreflang') === false);
check('head en-only: no og:locale', strpos($html, 'og:locale') === false);
check('footer en-only: no switcher', strpos($html, 'ms-footer__langs') === false);

// --- Scans --------------------------------------------------------------------
$prefixes = array_values(array_diff(array_keys(i18nSupportedLocales()), [i18nDefaultLocale()]));
$group = '(' . implode('|', $prefixes) . ')';
$htaccess = (string) file_get_contents($root . '/.htaccess');
check('scan: .htaccess rewrites /<locale>/ to index.php', strpos($htaccess, 'RewriteRule ^' . $group . '/?$ index.php [L]') !== false);
check('scan: .htaccess rewrites /<locale>/<page>.php', strpos($htaccess, 'RewriteRule ^' . $group . '/([A-Za-z0-9_-]+\.php)$ $2 [L,QSA]') !== false);
$router = (string) file_get_contents($root . '/tests/lib/dev_router.php');
check('scan: dev router names the same prefixes', substr_count($router, '#^/' . $group . '/') === 2);
check('scan: public_head.php has no hardcoded lang="en"', strpos((string) file_get_contents($root . '/partials/public_head.php'), 'lang="en"') === false);
check('scan: sitemap.php lists alternates', strpos((string) file_get_contents($root . '/sitemap.php'), 'xhtml:link') !== false);
foreach (array_keys($realPages) as $path) {
    $file = $root . ($path === '/' ? '/index.php' : $path);
    check("scan: translated-pages entry {$path} is a real file", is_file($file));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
