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

// --- tHtml() ------------------------------------------------------------------
i18nSetLocale('en');
same('tHtml: no markup is the escaped t()', 'Log in', tHtml('nav.login'));
same('tHtml: $params are escaped', 'Mail Shield is operated by &lt;b&gt;A&amp;B&lt;/b&gt;.', tHtml('footer.trust', ['company' => '<b>A&B</b>']));
same('tHtml: $html values are inserted raw', 'Mail Shield is operated by <b>X</b>.', tHtml('footer.trust', [], ['company' => '<b>X</b>']));
same('tHtml: a missing key is shown escaped, never raw', 'a&lt;b', tHtml('a<b'));
same('tHtml: paired markup', '<a href="/x">a&lt;b</a>', tHtml('{l}a<b{l_end}', [], ['l' => '<a href="/x">', 'l_end' => '</a>']));
$threw = false;
try {
    tHtml('nav.login', ['a' => 1], ['a' => '<i>']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('tHtml: a key in both arrays throws', $threw);


// --- i18nNumber() -------------------------------------------------------------
$nb = "\u{00A0}";
same('number: thousands, en', "1{$nb}234{$nb}567", i18nNumber(1234567, 0, 'en'));
same('number: thousands, sv', "1{$nb}234{$nb}567", i18nNumber(1234567, 0, 'sv'));
same('number: decimals, en', "1{$nb}234.5", i18nNumber(1234.5, 1, 'en'));
same('number: decimals, sv', "1{$nb}234,5", i18nNumber(1234.5, 1, 'sv'));
i18nSetLocale('sv');
same('number: defaults to this request\'s language', '1,5', i18nNumber(1.5, 1));
i18nSetLocale('en');

// --- i18nCatalogSubset() ------------------------------------------------------
$sub = i18nCatalogSubset(['nav.'], 'sv');
check('subset: only the prefixed keys', $sub !== [] && count(array_filter(array_keys($sub), fn($k) => strncmp($k, 'nav.', 4) !== 0)) === 0);
same('subset: the Swedish value where one exists', 'Priser', $sub['nav.pricing'] ?? null);
same('subset: every English key of the prefix is present', count(array_filter(array_keys($en), fn($k) => strncmp($k, 'nav.', 4) === 0)), count($sub));
same('subset: no prefix, no keys', [], i18nCatalogSubset([]));
same('subset: English locale gives English', 'Pricing', i18nCatalogSubset(['nav.'], 'en')['nav.pricing'] ?? null);

// --- Key scan -----------------------------------------------------------------
$scanKeys = static function (string $dir, array $regex, array $skip): array {
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        $rel = substr($path, strlen($dir) + 1);
        foreach ($skip as $s) {
            if (strncmp($rel, $s, strlen($s)) === 0) {
                continue 2;
            }
        }
        if (!preg_match($regex[0], $path)) {
            continue;
        }
        if (preg_match_all($regex[1], (string) file_get_contents($path), $m)) {
            foreach ($m[1] as $k) {
                $found[$k][] = $rel;
            }
        }
    }
    return $found;
};
$phpKeys = $scanKeys($root, ['/\.php$/', '/(?<![\w>$:\\\\])(?:t|te|tp|tHtml)\(\s*\'([^\'$]+)\'/'], ['vendor/', 'tests/', '.git/', 'node_modules/']);
$missingPhp = array_diff(array_keys($phpKeys), array_keys($en));
check('scan: every literal t()/te()/tp()/tHtml() key exists in lang/en.php', $missingPhp === [], implode(', ', array_map(fn($k) => $k . ' (' . $phpKeys[$k][0] . ')', $missingPhp)));
check('scan: the scan found the nav and footer keys', isset($phpKeys['nav.pricing'], $phpKeys['footer.terms']));
$jsKeys = $scanKeys($root . '/assets/js', ['/\.js$/', '/(?<![\w.$])msT\(\s*[\'"]([^\'"]+)[\'"]/'], []);
// consent.privacy_url is not a catalog key: partials/analytics.php adds it to the JSON (a URL, not copy).
$missingJs = array_diff(array_keys($jsKeys), array_keys($en), ['consent.privacy_url']);
check('scan: every literal msT() key exists in lang/en.php', $missingJs === [], implode(', ', $missingJs));

// --- Swedish completeness by prefix -------------------------------------------
$svRequiredPrefixes = ['nav.', 'footer.', 'consent.', 'landing.', 'pricing.', 'date.', 'faq.'];
$sv = i18nCatalog('sv');
foreach ($svRequiredPrefixes as $prefix) {
    $need = array_filter(array_keys($en), fn($k) => strncmp($k, $prefix, strlen($prefix)) === 0);
    $lack = array_diff($need, array_keys($sv));
    check("sv complete: every {$prefix} key is translated", $need !== [] && $lack === [], implode(', ', $lack));
}

// --- Nav and footer: English fixtures, Swedish render -------------------------
function renderPartial(string $file, bool $signedIn, string $locale, array $enabled): string
{
    $GLOBALS['config'] = [
        'email' => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
        'app' => ['version' => 'test'],
        'i18n' => ['enabled' => $enabled],
    ];
    $config = $GLOBALS['config'];
    $_SESSION = $signedIn ? ['pro_user_id' => 1, 'pro_user_email' => 'user@example.test'] : [];
    $msPage = ['path' => '/'];
    i18nSetLocale($locale);
    ob_start();
    require dirname(__DIR__) . '/partials/' . $file;
    // The year is stored as {year} so a fixture does not expire.
    return str_replace('&copy; ' . date('Y') . ' ', '&copy; {year} ', (string) ob_get_clean());
}

$fx = $root . '/tests/fixtures/i18n/';
same('fixture: public_nav, signed out, English', (string) file_get_contents($fx . 'public_nav.signed_out.en.html'), renderPartial('public_nav.php', false, 'en', $onlyEn));
same('fixture: public_nav, signed in, English', (string) file_get_contents($fx . 'public_nav.signed_in.en.html'), renderPartial('public_nav.php', true, 'en', $onlyEn));
same('fixture: public_footer, English', (string) file_get_contents($fx . 'public_footer.en.html'), renderPartial('public_footer.php', false, 'en', $onlyEn));

i18nTranslatedPages(['/' => ['en', 'sv'], '/pricing.php' => ['en', 'sv']]);
foreach ([['public_nav.php', false], ['public_nav.php', true], ['public_footer.php', false]] as [$file, $in]) {
    $out = renderPartial($file, $in, 'sv', $both);
    $outNoScript = preg_replace('#<script\b.*?</script>#s', '', $out); // inline JS has nav.contains()
    check("sv render {$file}" . ($in ? ' (signed in)' : '') . ': no raw key', !preg_match('/\b(nav|footer)\.[a-z_.]+\b/', $outNoScript));
    check("sv render {$file}" . ($in ? ' (signed in)' : '') . ': /faq.php stays plain', strpos($out, 'href="/sv/faq.php"') === false);
}
$navSv = renderPartial('public_nav.php', false, 'sv', $both);
check('sv nav: logo points at /sv/', strpos($navSv, 'href="/sv/"') !== false);
check('sv nav: pricing points at /sv/pricing.php', strpos($navSv, 'href="/sv/pricing.php"') !== false);
check('sv nav: faq link is plain', strpos($navSv, 'href="/faq.php"') !== false);
check('sv nav: Swedish text', strpos($navSv, '>Priser</a>') !== false && strpos($navSv, 'aria-label="Meny"') !== false);
$footSv = renderPartial('public_footer.php', false, 'sv', $both);
check('sv footer: company name stays literal', strpos($footSv, 'Mail Shield drivs av Manjo Consulting AB.') !== false);
check('sv footer: pricing points at /sv/pricing.php', strpos($footSv, 'href="/sv/pricing.php"') !== false);
i18nTranslatedPages($realPages);
i18nSetLocale('en');

// --- i18n_js.php --------------------------------------------------------------
function renderJs(array $prefixes): string
{
    $msI18nJs = $prefixes;
    i18nSetLocale('sv');
    ob_start();
    require dirname(__DIR__) . '/partials/i18n_js.php';
    return (string) ob_get_clean();
}
same('i18n_js: empty list emits nothing', '', renderJs([]));
$js = renderJs(['nav.']);
check('i18n_js: emits the element', strpos($js, '<script type="application/json" id="ms-i18n">') === 0);
$decoded = json_decode(substr($js, strpos($js, '>') + 1, strrpos($js, '</script>') - strpos($js, '>') - 1), true);
same('i18n_js: carries the Swedish value', 'Priser', $decoded['nav.pricing'] ?? null);
check('i18n_js: only the requested prefix', !isset($decoded['footer.terms']));
i18nSetLocale('en');

// --- analytics.php: the consent strings ---------------------------------------
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
function renderAnalytics(string $locale, array $enabled): string
{
    $GLOBALS['config'] = [
        'email' => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
        'i18n' => ['enabled' => $enabled],
    ];
    $_SERVER['REQUEST_URI'] = '/';
    i18nSetLocale($locale);
    ob_start();
    require dirname(__DIR__) . '/partials/analytics.php';
    return (string) ob_get_clean();
}
$consentRe = '#<script type="application/json" id="ms-i18n-consent">(.*?)</script>\n#s';
$normMtime = static fn(string $h): string => preg_replace('/consent\.js\?v=\d+/', 'consent.js?v={mtime}', $h);
$anEn = renderAnalytics('en', $onlyEn);
check('analytics en: exactly one consent element', preg_match_all($consentRe, $anEn) === 1);
same('fixture: analytics.php, English, minus the consent block', (string) file_get_contents($fx . 'analytics.en.html'), $normMtime(preg_replace($consentRe, '', $anEn)));
preg_match($consentRe, $anEn, $m);
$consentEn = json_decode($m[1] ?? '', true);
check('analytics en: JSON parses', is_array($consentEn));
same('analytics en: accept value', 'Accept', $consentEn['consent.accept'] ?? null);
same('analytics en: privacy url', '/privacy.php#cookies', $consentEn['consent.privacy_url'] ?? null);
same('analytics en: text is today\'s literal', 'We use Google Analytics on our public pages to understand how Mail Shield is used.', $consentEn['consent.text'] ?? null);
i18nTranslatedPages(['/' => ['en', 'sv'], '/privacy.php' => ['en', 'sv']]);
preg_match($consentRe, renderAnalytics('sv', $both), $m);
$consentSv = json_decode($m[1] ?? '', true);
check('analytics sv: JSON parses', is_array($consentSv));
same('analytics sv: accept value', 'Godkänn', $consentSv['consent.accept'] ?? null);
same('analytics sv: privacy url follows the translated page', '/sv/privacy.php#cookies', $consentSv['consent.privacy_url'] ?? null);
i18nTranslatedPages($realPages);
preg_match($consentRe, renderAnalytics('sv', $both), $m);
$consentSv = json_decode($m[1] ?? '', true);
same('analytics sv: privacy url stays English until privacy is translated', '/privacy.php#cookies', $consentSv['consent.privacy_url'] ?? null);
i18nSetLocale('en');
$consentJs = (string) file_get_contents($root . '/assets/js/consent.js');
check('scan: consent.js has no innerHTML built from msT()', !preg_match('/innerHTML[^;]*msT\(/s', $consentJs) && strpos($consentJs, 'innerHTML') === false);
check('scan: consent.js reads #ms-i18n-consent', strpos($consentJs, 'ms-i18n-consent') !== false);

// --- .htaccess hides lang/ ----------------------------------------------------
check('scan: .htaccess blocks lang/ in the existing rule', strpos($htaccess, 'RewriteRule ^(?:vendor|tests|documentaion|client|lang)(?:/|$) - [F,L]') !== false);

// --- Landing page partials: English fixtures, Swedish render --------------------
// Each variant is [signed in, trial days, MCP sign-in offered]. The fixtures were
// rendered from the code as it was before the landing page went through t().
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
if (!function_exists('getStats')) {
    // The query getStats() in config.php runs, against the test's own $pdo.
    function getStats()
    {
        global $pdo;
        $stats = [];
        foreach ($pdo->query('SELECT stat_name, stat_value FROM email_stats') as $row) {
            $stats[$row['stat_name']] = (int) $row['stat_value'];
        }
        return $stats;
    }
}

function landingVariants(): array
{
    return [
        'hero'         => ['signed_out' => [false, 60, false], 'signed_in' => [true, 60, false], 'signed_out_notrial' => [false, 0, false]],
        'problem'      => ['' => [false, 60, false]],
        'how_it_works' => ['' => [false, 60, false]],
        'addresses'    => ['signed_out' => [false, 60, false], 'signed_out_notrial' => [false, 0, false]],
        'temporary'    => ['' => [false, 60, false]],
        'automation'   => ['signin' => [false, 60, true], 'token' => [false, 60, false], 'token_notrial' => [false, 0, false]],
        'cleanup'      => ['' => [false, 60, false]],
        'external'     => ['' => [false, 60, false]],
        'stats'        => ['' => [false, 60, false]],
        'plans'        => ['signed_out' => [false, 60, false], 'signed_out_signin' => [false, 60, true], 'signed_in' => [true, 60, false], 'signed_out_notrial' => [false, 0, false]],
        'cta'          => ['signed_out' => [false, 60, false], 'signed_in' => [true, 60, false], 'signed_out_notrial' => [false, 0, false]],
    ];
}

function landingStatsPdo(array $rows): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE email_stats (stat_name TEXT PRIMARY KEY, stat_value INTEGER NOT NULL DEFAULT 0)');
    foreach ($rows as $name => $value) {
        $pdo->prepare('INSERT INTO email_stats (stat_name, stat_value) VALUES (?, ?)')->execute([$name, $value]);
    }
    return $pdo;
}

function renderLanding(string $section, array $variant, string $locale, array $enabled, ?array $statsRows = null): string
{
    [$signedIn, $trialDays, $mcpSignIn] = $variant;
    $GLOBALS['config'] = [
        'email' => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
        'trial' => ['days' => $trialDays],
        'i18n' => ['enabled' => $enabled],
    ];
    $config = $GLOBALS['config'];
    $domain = 'example.test';
    $msMcpSignIn = $mcpSignIn;
    $GLOBALS['pdo'] = landingStatsPdo($statsRows ?? ['emails_processed' => 1234567, 'emails_created' => 89012, 'attachments_processed' => 345]);
    $_SESSION = $signedIn ? ['pro_user_id' => 1] : [];
    i18nSetLocale($locale);
    ob_start();
    require dirname(__DIR__) . '/partials/landing/' . $section . '.php';
    return (string) ob_get_clean();
}

/**
 * The spellings the catalog changed and the browser reads the same: te() escapes
 * an apostrophe as &#039; where the old markup had a bare one, and a catalog
 * value holds the character where the old markup had &mdash; or &times;.
 * Everything else must match byte for byte.
 */
function landingNorm(string $html): string
{
    return strtr($html, ['&#039;' => "'", '&mdash;' => '—', '&times;' => '×']);
}

function landingFixtureName(string $section, string $variant): string
{
    return 'landing.' . $section . ($variant === '' ? '' : '.' . $variant) . '.en.html';
}

i18nTranslatedPages($realPages);
foreach (landingVariants() as $section => $variants) {
    foreach ($variants as $name => $v) {
        $file = landingFixtureName($section, $name);
        same("fixture: {$file}", landingNorm((string) file_get_contents($fx . $file)), landingNorm(renderLanding($section, $v, 'en', $onlyEn)));
    }
}
i18nSetLocale('en');

// --- Landing page in Swedish ----------------------------------------------------
same('pages: the landing page is offered in Swedish', ['en', 'sv'], $realPages['/'] ?? null);
same('resolve: /sv/ is served once Swedish is on', null, i18nResolveRequest(['REQUEST_URI' => '/sv/'], $both));
same('resolve: ...and sets Swedish', 'sv', i18nLocale());
same('resolve: /sv/ redirects to / while only English is on', '/', i18nResolveRequest(['REQUEST_URI' => '/sv/'], $onlyEn));
i18nSetLocale('en');

$svAll = '';
foreach (landingVariants() as $section => $variants) {
    foreach ($variants as $name => $v) {
        $label = "landing {$section}" . ($name === '' ? '' : " ({$name})");
        $svOut = renderLanding($section, $v, 'sv', $both);
        $enOut = renderLanding($section, $v, 'en', $both);
        $svAll .= $svOut;
        check("sv render {$label}: no raw key", !preg_match('/\blanding\.[a-z_.]+\b/', $svOut));
        check("sv render {$label}: no unfilled placeholder", !preg_match('/\{[a-z_]+\}/', $svOut));
        check("sv render {$label}: differs from English", $svOut !== $enOut);
        preg_match_all('/[A-Za-z0-9._-]+@([A-Za-z0-9.-]+)/', $svOut, $mm);
        check("sv render {$label}: every example address is on the configured domain", array_unique($mm[1]) === [] || array_unique($mm[1]) === ['example.test'], implode(',', array_unique($mm[1])));
        check("sv render {$label}: no hard-coded manjo.me", stripos($svOut, 'manjo.me') === false);
        check("sv render {$label}: Swedish links stay on pages that exist in Swedish", strpos($svOut, 'href="/sv/register.php') === false);
    }
}
check('sv render: the lang attribute is not hard-coded in a partial', strpos($svAll, 'lang="en"') === false);
check('sv render: Swedish copy is present', strpos($svAll, 'Din inkorg för allt annat.') !== false && strpos($svAll, 'Sticky-adresser') !== false && strpos($svAll, 'Timed-adresser') !== false);
check('sv render: product name stays Mail Shield', strpos($svAll, 'Mail Shield') !== false);
check('sv render: numbers come from $config (60 days, 10 sticky, 24 hours)', strpos($svAll, '60 dagar') !== false && strpos($svAll, 'upp till 10 Sticky') !== false && strpos($svAll, '24 timmar') !== false);
$statsSv = renderLanding('stats', [false, 60, false], 'sv', $both);
check('sv stats: numbers through i18nNumber (no-break space)', strpos($statsSv, "1\u{00A0}234\u{00A0}567") !== false);
same('stats: nothing at all when every figure is zero', '', renderLanding('stats', [false, 60, false], 'en', $onlyEn, ['emails_processed' => 0]));
$planSv = renderLanding('plans', [false, 60, false], 'sv', $both);
check('sv plans: pricing is translated so its link has the prefix; register stays plain until it is', strpos($planSv, 'href="/sv/pricing.php"') !== false && strpos($planSv, 'href="/register.php?plan=pro"') !== false);
i18nTranslatedPages(['/' => ['en', 'sv'], '/pricing.php' => ['en', 'sv'], '/register.php' => ['en', 'sv'], '/temporary-email.php' => ['en', 'sv']]);
$planSv = renderLanding('plans', [false, 60, false], 'sv', $both);
check('sv plans: ...and follow them once translated', strpos($planSv, 'href="/sv/pricing.php"') !== false && strpos($planSv, 'href="/sv/register.php?plan=pro"') !== false);
check('sv hero: sign-up link follows the translated register page', strpos(renderLanding('hero', [false, 60, false], 'sv', $both), 'href="/sv/register.php?plan=regular"') !== false);
check('sv how it works: temporary-email link follows the translated page', strpos(renderLanding('how_it_works', [false, 60, false], 'sv', $both), 'href="/sv/temporary-email.php"') !== false);
check('hero: the in-page anchor is kept', strpos(renderLanding('hero', [false, 60, false], 'sv', $both), 'href="#how-it-works"') !== false);
i18nTranslatedPages($realPages);
i18nSetLocale('en');

// The "From {price}" wording: PHP puts it in the price config, the script fills it.
$plansSrc = (string) file_get_contents($root . '/partials/landing/plans.php');
$priceJs = (string) file_get_contents($root . '/assets/js/landing-price.js');
check('scan: plans.php adds fromTemplate (t of landing.plans.from_price) to the price config', preg_match("/\\['fromTemplate'\\]\s*=\s*t\('landing\.plans\.from_price'\)/", $plansSrc) === 1);
check('scan: landing-price.js uses config.fromTemplate and falls back to "From "', strpos($priceJs, 'config.fromTemplate') !== false && strpos($priceJs, "'From ' + total") !== false);
check('scan: landing-price.js never uses innerHTML', stripos($priceJs, 'innerHTML') === false);
same('catalog: English from_price', 'From {price}', $en['landing.plans.from_price'] ?? null);

// index.php: title, description and the JSON-LD WebSite node.
$indexSrc = (string) file_get_contents($root . '/index.php');
check('scan: index.php title and description come from the catalog', strpos($indexSrc, "'title'        => t('landing.meta.title')") !== false && strpos($indexSrc, "'description'  => t('landing.meta.description')") !== false);
check('scan: index.php WebSite url follows the language and carries inLanguage', strpos($indexSrc, "'url'   => \$msOrigin . i18nUrl('/')") !== false && strpos($indexSrc, "'inLanguage' => i18nLocale()") !== false);
same('catalog: English meta title is today\'s literal', 'Mail Shield — Your inbox for everything else', $en['landing.meta.title'] ?? null);

// No catalog value carries a number or the domain (they are placeholders).
$numberInCopy = [];
foreach ($en as $key => $value) {
    if (strncmp($key, 'landing.', 8) !== 0) {
        continue;
    }
    $texts = is_array($value) ? $value : [$value];
    foreach ($texts as $text) {
        $bare = preg_replace('/\{[a-z_]+\}/', '', (string) $text);
        if (preg_match('/\d/', $bare) || stripos($bare, 'manjo') !== false || stripos($bare, '.me') !== false) {
            $numberInCopy[] = $key;
        }
    }
}
check('catalog: no landing value writes a number or the domain', $numberInCopy === [], implode(', ', $numberInCopy));
$unused = [];
$landingSrc = '';
foreach (glob($root . '/partials/landing/*.php') as $f) {
    $landingSrc .= file_get_contents($f);
}
$landingSrc .= $plansSrc . $indexSrc;
foreach (array_keys($en) as $key) {
    if (strncmp($key, 'landing.', 8) === 0 && strpos($landingSrc, "'" . $key . "'") === false) {
        $unused[] = $key;
    }
}
check('catalog: every landing key is used by a partial or index.php', $unused === [], implode(', ', $unused));

// No English text node left outside te()/tHtml()/tp(): strip the PHP, then the tags.
foreach (glob($root . '/partials/landing/*.php') as $f) {
    $src = (string) file_get_contents($f);
    $markup = preg_replace('/<\?(?:php|=).*?(?:\?>|\z)/s', '', $src);
    $attrs = [];
    preg_match_all('/\b(?:alt|aria-label|title)="([^"]*)"/', $markup, $am);
    foreach ($am[1] as $a) {
        if (preg_match('/[A-Za-z]{2}/', $a)) {
            $attrs[] = $a;
        }
    }
    $text = html_entity_decode(strip_tags($markup), ENT_QUOTES, 'UTF-8');
    // Product, company and the real brands the mockup shows are names, not copy; the
    // one-letter avatars are not words.
    $text = str_replace(['Mail Shield', 'Manjo Consulting AB', 'Amazon', 'GitHub', 'Spotify', 'Reddit'], '', $text);
    $leftover = preg_match('/[A-Za-z’\']{2,}(?:\s+[A-Za-z’\']{2,}){2,}/', $text, $lm) ? trim($lm[0]) : '';
    check('scan: ' . basename($f) . ' has no English text node outside t()/tHtml()/tp()', $leftover === '', $leftover);
    check('scan: ' . basename($f) . ' has no literal alt/aria-label/title', $attrs === [], implode(' | ', $attrs));
}

/**
 * Renders the real pricing.php in a probe docroot (a copy of the page and what
 * it requires, plus a stub config.php) as a CLI subprocess.
 * Returns ['main' => <main>…</main>, 'config' => decoded JSON, 'raw' => page].
 */
function pricingRender(string $root, string $locale, array $enabled, bool $signedIn, bool $checkout, ?string $uri = null, bool $production = false): array
{
    static $probe = null;
    if ($probe === null) {
        $probe = sys_get_temp_dir() . '/ms_pricing_probe_' . getmypid();
        @mkdir($probe . '/assets/js', 0777, true);
        foreach (['pricing.php', 'pricing_tiers.php', 'pricing_helpers.php', 'paddle_sync.php', 'pii_crypto.php', 'i18n.php', 'referrals.php', 'assets/js/pricing.js'] as $f) {
            copy($root . '/' . $f, $probe . '/' . $f);
        }
        foreach (['partials', 'lang'] as $d) {
            @mkdir($probe . '/' . $d, 0777, true);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $rel = substr($item->getPathname(), strlen($root) + 1);
                if ($item->isDir()) {
                    @mkdir($probe . '/' . $rel, 0777, true);
                } else {
                    copy($item->getPathname(), $probe . '/' . $rel);
                }
            }
        }
        file_put_contents($probe . '/config.php', <<<'PHP'
<?php
// Stub config.php for tests/i18n_test.php: just what pricing.php and the shell read.
$config = [
    'email'  => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
    'app'    => ['version' => 'test'],
    'trial'  => ['days' => 60],
    'i18n'   => ['enabled' => array_values(array_filter(explode(',', (string) getenv('PROBE_ENABLED'))))],
    'paddle' => [
        'environment'      => getenv('PROBE_PRODUCTION') === '1' ? 'production' : 'sandbox',
        'client_token'      => getenv('PROBE_PRODUCTION') === '1' ? 'live_probe_token' : 'test_probe_token',
        'sandbox_user_ids' => [7],
    ],
    'retention_hold' => ['days' => 30, 'max_per_year' => 4],
    'referral' => ['enabled' => false],
];
$_SERVER['REQUEST_URI'] = (string) getenv('PROBE_URI');
require_once __DIR__ . '/i18n.php';
i18nBootstrap($config['i18n'], $_SERVER);
session_start();
$_SESSION = getenv('PROBE_SIGNED_IN') === '1' ? ['pro_user_id' => (int) getenv('PROBE_USER_ID'), 'pro_user_email' => 'user@example.test'] : [];
function logMessage($level, $message, $context = []) {}
function tableHasColumn($table, $column) { return false; }
PHP);
        // The real paddleClientSettings(), lifted from config.php so the probe
        // validates the environment exactly as production does.
        if (preg_match('/^function paddleClientSettings\(.*?^}\n/ms', (string) file_get_contents($root . '/config.php'), $fm)) {
            file_put_contents($probe . '/config.php', "\n" . $fm[0], FILE_APPEND);
        }
        register_shutdown_function(static function () use ($probe) {
            exec('rm -rf ' . escapeshellarg($probe));
        });
    }
    $env = [
        'PROBE_ENABLED' => implode(',', $enabled),
        'PROBE_URI' => $uri ?? (($locale === 'sv' ? '/sv' : '') . '/pricing.php'),
        'PROBE_SIGNED_IN' => $signedIn ? '1' : '0',
        // 7 is on the stub's sandbox allow-list, 1 is not
        'PROBE_USER_ID' => $checkout ? '7' : '1',
        'PROBE_PRODUCTION' => $production ? '1' : '0',
        'PATH' => (string) getenv('PATH'),
    ];
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), 'pricing.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($proc);
    preg_match('#<main id="main">.*?</main>#s', $out, $mm);
    preg_match('#<script type="application/json" id="ms-pricing-config">(.*?)</script>#s', $out, $cm);
    return ['main' => $mm[0] ?? '', 'config' => json_decode($cm[1] ?? '', true), 'raw' => $out, 'err' => $err];
}


// --- Pricing page: English fixtures, Swedish render ---------------------------
// The fixtures were rendered from pricing.php before it went through t(). The page
// runs for real in a probe docroot (a copy of it and what it requires, plus a stub
// config.php) as a CLI subprocess. The config JSON gained perMonth, perYear and
// locale afterwards; those three are removed before the comparison.
i18nTranslatedPages($realPages);
$pricingVariants = [
    'signed_out'        => [false, false, false],
    'signed_out_open'   => [false, true, true],
    'signed_in_open'    => [true, true, false],
    'signed_in_closed'  => [true, false, false],
];
foreach ($pricingVariants as $name => [$in, $co, $prod]) {
    $r = pricingRender($root, 'en', $onlyEn, $in, $co, null, $prod);
    same("fixture: pricing.main.{$name}.en.html", (string) file_get_contents($fx . "pricing.main.{$name}.en.html"), $r['main'] . "\n");
    $cfg = is_array($r['config']) ? $r['config'] : [];
    same("pricing en {$name}: config gained perMonth, perYear and locale", ['/ month', '/ year', 'en'], [$cfg['perMonth'] ?? null, $cfg['perYear'] ?? null, $cfg['locale'] ?? null]);
    unset($cfg['perMonth'], $cfg['perYear'], $cfg['locale']);
    same("fixture: pricing.config.{$name}.en.json", (string) file_get_contents($fx . "pricing.config.{$name}.en.json"), json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

same('pages: pricing is offered in Swedish', ['en', 'sv'], $realPages['/pricing.php'] ?? null);
same('resolve: /sv/pricing.php is served once Swedish is on', null, i18nResolveRequest(['REQUEST_URI' => '/sv/pricing.php'], $both));
same('resolve: ...and redirects while only English is on', '/pricing.php', i18nResolveRequest(['REQUEST_URI' => '/sv/pricing.php'], $onlyEn));
i18nSetLocale('en');

foreach ($pricingVariants as $name => [$in, $co, $prod]) {
    $r = pricingRender($root, 'sv', $both, $in, $co, null, $prod);
    $enR = pricingRender($root, 'en', $both, $in, $co, null, $prod);
    check("pricing sv {$name}: the page rendered", $r['main'] !== '', $r['err']);
    check("pricing sv {$name}: no raw key", !preg_match('/\b(?:pricing|date)\.[a-z_.0-9]+\b/', $r['main']));
    check("pricing sv {$name}: no unfilled placeholder", !preg_match('/\{[a-z_]+\}/', $r['main']));
    check("pricing sv {$name}: differs from English", $r['main'] !== $enR['main']);
    check("pricing sv {$name}: <html lang=\"sv\">", strpos($r['raw'], '<html lang="sv">') !== false);
    check("pricing sv {$name}: plan names stay Pro and Lifetime", strpos($r['main'], '>Pro</h2>') !== false && strpos($r['main'], '>Lifetime</h2>') !== false);
    check("pricing sv {$name}: numbers still come from the config (10, 1 to 7, 30 days, 4 times)", strpos($r['main'], 'Upp till 10 Sticky-adresser') !== false && strpos($r['main'], 'från 1 till 7 dagar') !== false && strpos($r['main'], 'i 30 dagar, upp till 4 gånger per år') !== false);
    check("pricing sv {$name}: the money-back days are the page's 30", strpos($r['main'], '30 dagars pengarna-tillbaka-garanti') !== false);
    check("pricing sv {$name}: links to pages not yet in Swedish stay plain", strpos($r['main'], 'href="/terms.php"') !== false && strpos($r['main'], 'href="/privacy.php"') !== false && strpos($r['main'], 'href="/refund-policy.php"') !== false && strpos($r['main'], '/sv/terms.php') === false);
    $cfg = is_array($r['config']) ? $r['config'] : [];
    same("pricing sv {$name}: config perMonth, perYear, locale", ['/ månad', '/ år', 'sv'], [$cfg['perMonth'] ?? null, $cfg['perYear'] ?? null, $cfg['locale'] ?? null]);
    $cfgEn = is_array($enR['config']) ? $enR['config'] : [];
    unset($cfg['perMonth'], $cfg['perYear'], $cfg['locale'], $cfgEn['perMonth'], $cfgEn['perYear'], $cfgEn['locale']);
    same("pricing sv {$name}: prices, ids and the checkout gate are not language-dependent", $cfgEn, $cfg);
    check("pricing sv {$name}: canonical and hreflang", strpos($r['raw'], '<link rel="canonical" href="https://example.test/sv/pricing.php">') !== false && strpos($r['raw'], 'hreflang="x-default" href="https://example.test/pricing.php"') !== false);
}
$svOpen = pricingRender($root, 'sv', $both, false, true, null, true);
check('pricing sv: Swedish headings and controls', strpos($svOpen['main'], '>Planer och priser</h1>') !== false && strpos($svOpen['main'], 'aria-label="Faktureringsperiod"') !== false && strpos($svOpen['main'], '>Månadsvis</button>') !== false && strpos($svOpen['main'], '>Logga in för att prenumerera</a>') !== false);
check('pricing sv: the one-time label', strpos($svOpen['main'], '>engångsbelopp</span>') !== false);
$svClosed = pricingRender($root, 'sv', $both, false, false);
check('pricing sv: the closed-checkout note', strpos($svClosed['main'], 'Betalning online öppnar snart.') !== false && strpos($svClosed['main'], 'Subscribe') === false);
$svHtml = pricingRender($root, 'sv', $both, false, false);
check('pricing sv: <title> and description are Swedish', strpos($svHtml['raw'], '<title>Priser · Mail Shield</title>') !== false && strpos($svHtml['raw'], 'Mail Shields planer och priser') !== false);
check('pricing sv: robots rule unchanged (sandbox noindex)', strpos($svHtml['raw'], 'noindex, nofollow') !== false);
check('pricing sv production: robots rule unchanged (index)', strpos($svOpen['raw'], 'index, follow') !== false);

// i18nDate()
$monthStamps = [];
for ($m = 1; $m <= 12; $m++) {
    $monthStamps[] = gmmktime(12, 0, 0, $m, 5 + $m, 2026);
}
$prevTz = date_default_timezone_get();
date_default_timezone_set('UTC');
$dateOk = true;
foreach ($monthStamps as $ts) {
    $dateOk = $dateOk && i18nDate($ts, 'en') === date('j F Y', $ts);
}
check('i18nDate: English equals date(\'j F Y\') for all twelve months', $dateOk);
same('i18nDate: Swedish', '5 mars 2026', i18nDate(gmmktime(12, 0, 0, 3, 5, 2026), 'sv'));
same('i18nDate: Swedish month names are lowercase', 'maj', t('date.month.5', [], 'sv'));
i18nSetLocale('sv');
same('i18nDate: defaults to this request\'s language', '31 december 2026', i18nDate(gmmktime(12, 0, 0, 12, 31, 2026)));
i18nSetLocale('en');
date_default_timezone_set($prevTz);
same('referral note: both languages fill {date} and {months}', ['Invited by a friend: buy a 12-month plan before 5 March 2026 and get 15 months.', 'Inbjuden av en vän: köp en 12-månadersplan före 5 mars 2026 och få 15 månader.'], [t('pricing.referral', ['date' => '5 March 2026', 'months' => 15], 'en'), t('pricing.referral', ['date' => '5 mars 2026', 'months' => 15], 'sv')]);

// pricing_tiers.php carries catalog keys, not copy, and loads without i18n.php.
$tiersFile = $root . '/pricing_tiers.php';
$tiersOut = (static function () use ($tiersFile) { $config = ['retention_hold' => ['days' => 30, 'max_per_year' => 4]]; return require $tiersFile; })();
$tierKeys = [];
foreach ($tiersOut as $tier) {
    $tierKeys[] = $tier['description'];
    foreach ($tier['features'] as $pair) {
        $tierKeys[] = $pair[0];
        check('tiers: feature ' . $pair[0] . ' is a [key, params] pair', count($pair) === 2 && is_array($pair[1]));
    }
}
check('tiers: every description and feature key exists in lang/en.php', array_diff($tierKeys, array_keys($en)) === [], implode(', ', array_diff($tierKeys, array_keys($en))));
same('tiers: plan keys and names', [['pro', 'Pro'], ['lifetime', 'Lifetime']], array_map(fn($t) => [$t['key'], $t['name']], $tiersOut));
same('tiers: the retention numbers still come from $config', ['days' => 30, 'times' => 4], $tiersOut[0]['features'][2][1]);
check('tiers: pricing_tiers.php never calls t() and so needs no i18n.php', !preg_match('/\bt\(|\bte\(|i18n/', preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($tiersFile))));

/**
 * Renders the real faq.php in a probe docroot (a copy of the page and what it
 * requires, plus a stub config.php) as a CLI subprocess and returns the page.
 * $signIn switches the OAuth discovery answer ($msMcpSignIn), $trial is the
 * trial length in days.
 */
function faqRender(string $root, string $locale, array $enabled, bool $signIn, int $trial, bool $signedIn = false): array
{
    static $probe = null;
    if ($probe === null) {
        $probe = sys_get_temp_dir() . '/ms_faq_probe_' . getmypid();
        @mkdir($probe, 0777, true);
        foreach (['faq.php', 'i18n.php', 'oauth_server.php', 'mcp_tokens.php'] as $f) {
            copy($root . '/' . $f, $probe . '/' . $f);
        }
        foreach (['partials', 'lang'] as $d) {
            @mkdir($probe . '/' . $d, 0777, true);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $rel = substr($item->getPathname(), strlen($root) + 1);
                if ($item->isDir()) {
                    @mkdir($probe . '/' . $rel, 0777, true);
                } else {
                    copy($item->getPathname(), $probe . '/' . $rel);
                }
            }
        }
        file_put_contents($probe . '/config.php', <<<'PHP'
<?php
// Stub config.php for tests/i18n_test.php: just what faq.php and its partials read.
$config = [
    'email'  => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
    'app'    => ['version' => 'test'],
    'trial'  => ['days' => (int) getenv('PROBE_TRIAL')],
    'i18n'   => ['enabled' => array_values(array_filter(explode(',', (string) getenv('PROBE_ENABLED'))))],
    'oauth'  => ['enabled' => getenv('PROBE_SIGNIN') === '1'],
    'retention_hold' => ['days' => 30, 'max_per_year' => 4],
];
$_SERVER['REQUEST_URI'] = (string) getenv('PROBE_URI');
require_once __DIR__ . '/i18n.php';
i18nBootstrap($config['i18n'], $_SERVER);
// oauthDiscoveryEnabled() asks the database whether oauth_clients exists.
$pdo = new PDO('sqlite::memory:');
if (getenv('PROBE_SIGNIN') === '1') {
    $pdo->exec('CREATE TABLE oauth_clients (id INTEGER)');
}
session_start();
$_SESSION = getenv('PROBE_SIGNED_IN') === '1' ? ['pro_user_id' => 1] : [];
function logMessage($level, $message, $context = []) {}
PHP);
        register_shutdown_function(static function () use ($probe) {
            exec('rm -rf ' . escapeshellarg($probe));
        });
    }
    $env = [
        'PROBE_ENABLED' => implode(',', $enabled),
        'PROBE_URI' => ($locale === 'sv' ? '/sv' : '') . '/faq.php',
        'PROBE_SIGNIN' => $signIn ? '1' : '0',
        'PROBE_TRIAL' => (string) $trial,
        'PROBE_SIGNED_IN' => $signedIn ? '1' : '0',
        'PATH' => (string) getenv('PATH'),
    ];
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), 'faq.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($proc);
    return ['raw' => $out, 'err' => $err];
}

/**
 * The copyright year changes every January; the fixtures must not. The
 * FAQPage JSON-LD gained "inLanguage" after the fixtures were rendered, so it is
 * removed before the English page is compared.
 */
function faqNorm(string $html): string
{
    $html = preg_replace('/&copy; \d{4}/', '&copy; YYYY', $html);
    return str_replace('"inLanguage":"en",', '', $html);
}

// --- FAQ page: English fixtures -----------------------------------------------
// The fixtures were rendered from faq.php before it went through t() and the data
// files: signed out, with and without the OAuth sign-in wording, with and without
// the trial.
$faqVariants = [
    'signin_trial'      => [true, 60],
    'token_trial'       => [false, 60],
    'signin_notrial'    => [true, 0],
    'token_notrial'     => [false, 0],
];
foreach ($faqVariants as $name => [$signIn, $trial]) {
    $r = faqRender($root, 'en', $onlyEn, $signIn, $trial);
    check("faq en {$name}: the page rendered", $r['raw'] !== '', $r['err']);
    check("faq en {$name}: the page carries inLanguage en", strpos($r['raw'], '"inLanguage":"en"') !== false);
    same("fixture: faq.{$name}.en.html", faqNorm((string) file_get_contents($fx . "faq.{$name}.en.html")), faqNorm($r['raw']));
}

same('pages: the FAQ is offered in Swedish', ['en', 'sv'], $realPages['/faq.php'] ?? null);
i18nTranslatedPages($realPages);

// The two data files: same groups, same number of questions per group, same
// variables interpolated. Loaded the way faq.php does, once per branch of the
// conditions (trial on/off, sign-in wording on/off).
$faqLoad = static function (string $file, bool $signIn, int $trial) use ($root): array {
    $msU = static fn(string $path): string => $path;
    $msT = $trial;
    $msDomain = 'example.test';
    $msMcpUrl = 'https://example.test/mcp';
    $msMcpSignIn = $signIn;
    $msHoldDays = 30;
    $msHoldMax = 4;
    return require $root . '/partials/content/' . $file;
};
$faqVars = static function (string $file): array {
    $src = (string) file_get_contents($file);
    preg_match_all('/\{?\$(ms[A-Za-z]+)/', preg_replace('#/\*.*?\*/#s', '', $src), $m);
    $names = array_values(array_unique($m[1]));
    sort($names);
    return $names;
};
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
foreach ([[true, 60], [false, 60], [true, 0], [false, 0]] as [$signIn, $trial]) {
    $label = ($signIn ? 'signin' : 'token') . ($trial > 0 ? '+trial' : '+notrial');
    $enData = $faqLoad('faq.en.php', $signIn, $trial);
    $svData = $faqLoad('faq.sv.php', $signIn, $trial);
    same("faq data {$label}: same group keys, same order", array_keys($enData), array_keys($svData));
    $counts = static fn(array $d): array => array_map(fn($g) => count($g['items']), $d);
    same("faq data {$label}: same number of questions per group", $counts($enData), $counts($svData));
    $bad = [];
    foreach ($svData as $gid => $g) {
        foreach ($g['items'] as $i => $item) {
            if (trim((string) ($item['q'] ?? '')) === '' || trim((string) ($item['a'] ?? '')) === '') {
                $bad[] = "{$gid}[{$i}]";
            }
            if (($enData[$gid]['items'][$i]['q'] ?? '') === $item['q']) {
                $bad[] = "{$gid}[{$i}] question not translated";
            }
        }
    }
    check("faq data {$label}: every Swedish question and answer is filled in and translated", $bad === [], implode(', ', $bad));
}
same('faq data: the same variables are interpolated', $faqVars($root . '/partials/content/faq.en.php'), $faqVars($root . '/partials/content/faq.sv.php'));
foreach (['en', 'sv'] as $code) {
    $src = (string) file_get_contents($root . "/partials/content/faq.{$code}.php");
    check("faq data {$code}: guarded like partials/landing", strpos($src, "if (!defined('TEMPMAIL_APP'))") !== false);
    check("faq data {$code}: links to public pages go through \$msU", !preg_match('#href="/(?:pricing|temporary-email|blog|faq|terms|privacy|refund-policy)(?:\.php)?[^"]*"|href="/"#', $src));
}


// --- FAQ page: Swedish render -------------------------------------------------
foreach ($faqVariants as $name => [$signIn, $trial]) {
    $r = faqRender($root, 'sv', $both, $signIn, $trial);
    $enR = faqRender($root, 'en', $both, $signIn, $trial);
    $raw = $r['raw'];
    check("faq sv {$name}: the page rendered", $raw !== '', $r['err']);
    check("faq sv {$name}: no raw key", !preg_match('/(?<![\/\w])faq\.(?!php\b)[a-z_.0-9]+\b/', $raw));
    check("faq sv {$name}: no unfilled placeholder", !preg_match('/\{[a-z_]+\}/', $raw));
    check("faq sv {$name}: differs from English", $raw !== $enR['raw']);
    check("faq sv {$name}: <html lang=\"sv\">", strpos($raw, '<html lang="sv">') !== false);
    check("faq sv {$name}: canonical and hreflang", strpos($raw, '<link rel="canonical" href="https://example.test/sv/faq.php">') !== false
        && strpos($raw, 'hreflang="sv" href="https://example.test/sv/faq.php"') !== false
        && strpos($raw, 'hreflang="en" href="https://example.test/faq.php"') !== false
        && strpos($raw, 'hreflang="x-default" href="https://example.test/faq.php"') !== false
        && strpos($raw, '<meta property="og:locale" content="sv_SE">') !== false);
    check("faq sv {$name}: Swedish title and description", strpos($raw, '<title>Vanliga frågor · Mail Shield</title>') !== false && strpos($raw, 'Korta svar om Mail Shield') !== false);
    check("faq sv {$name}: stylesheets resolve from the root, not from /sv/", strpos($raw, 'href="/assets/css/style.css"') !== false && strpos($raw, 'href="/assets/css/mailshield.css?v=') !== false && strpos($raw, 'href="assets/') === false);
    check("faq sv {$name}: section anchors stay English", strpos($raw, 'id="accounts"') !== false && strpos($raw, 'href="#automation"') !== false && strpos($raw, 'id="troubleshooting"') !== false);
    check("faq sv {$name}: the pricing link is the Swedish page, app pages stay plain", strpos($raw, 'href="/sv/pricing.php"') !== false && strpos($raw, 'href="/pro_login.php"') !== false && strpos($raw, '/sv/pro_login.php') === false);
    check("faq sv {$name}: pages not yet in Swedish keep their plain URL, translated ones link to /sv/", strpos($raw, 'href="/sv/temporary-email.php"') !== false && strpos($raw, 'href="/temporary-email.php"') === false && strpos($raw, 'href="/blog.php"') !== false && strpos($raw, '/sv/blog.php') === false);
    check("faq sv {$name}: header and footer links go to the Swedish page", strpos($raw, '<li><a href="/sv/">Startsida</a></li>') !== false && strpos($raw, 'href="/sv/faq.php">Vanliga frågor</a>') !== false);
    check("faq sv {$name}: numbers and the domain are the config's (10, 1 to 7, 30 days, 4 times)", strpos($raw, 'upp till 10 Sticky-adresser') !== false && strpos($raw, '1 till 7') !== false && strpos($raw, 'i 30 dagar, upp till 4 gånger per år') !== false && strpos($raw, 'shopping@example.test') !== false);
    check("faq sv {$name}: the trial days are the config's", $trial > 0 ? strpos($raw, "{$trial} dagar Pro") !== false : strpos($raw, 'provperioden') === false);
    check("faq sv {$name}: the AI answer follows the sign-in switch", $signIn ? strpos($raw, 'logga in när den ber om det') !== false : strpos($raw, 'Du skapar en åtkomsttoken') !== false);
    check("faq sv {$name}: no English page chrome left", strpos($raw, 'Skip to content') === false && strpos($raw, 'Jump to') === false && strpos($raw, 'Cookie settings') === false && strpos($raw, 'Frequently asked') === false);
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $raw, $lm);
    $ld = json_decode($lm[1] ?? '', true);
    check("faq sv {$name}: the JSON-LD parses as a Swedish FAQPage", is_array($ld) && ($ld['@type'] ?? '') === 'FAQPage' && ($ld['inLanguage'] ?? '') === 'sv' && count($ld['mainEntity'] ?? []) > 20);
    $names = array_map(fn($q) => $q['name'] ?? '', $ld['mainEntity'] ?? []);
    check("faq sv {$name}: the JSON-LD question names are Swedish", in_array('Vad är Mail Shield?', $names, true) && !in_array('What is Mail Shield?', $names, true));
    $enLd = json_decode((preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $enR['raw'], $em) ? $em[1] : ''), true);
    same("faq sv {$name}: as many JSON-LD questions as English", count($enLd['mainEntity'] ?? []), count($ld['mainEntity'] ?? []));
    check("faq sv {$name}: every JSON-LD answer is in the visible page", (function () use ($ld, $raw) {
        $visible = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#</?(?:p|li|ul|br)\b[^>]*>#i', ' ', $raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ($ld['mainEntity'] ?? [] as $q) {
            if (strpos($visible, mb_substr((string) $q['acceptedAnswer']['text'], 0, 60)) === false) {
                return false;
            }
        }
        return true;
    })());
}
// With Swedish switched off the Swedish URL is not offered and the English page carries no hreflang.
$offHtml = faqRender($root, 'en', $onlyEn, false, 60)['raw'];
check('faq en-only: no hreflang, no og:locale', strpos($offHtml, 'hreflang') === false && strpos($offHtml, 'og:locale') === false);
same('resolve: /sv/faq.php is served once Swedish is on', null, i18nResolveRequest(['REQUEST_URI' => '/sv/faq.php'], $both));
same('resolve: ...and redirects while only English is on', '/faq.php', i18nResolveRequest(['REQUEST_URI' => '/sv/faq.php'], $onlyEn));
i18nSetLocale('en');

/**
 * Renders the real temporary-email.php in a probe docroot (a copy of the page and
 * what it requires, plus a stub config.php) as a CLI subprocess and returns the
 * page. $trial is the trial length in days, $signedIn the session flag.
 */
function teRender(string $root, string $locale, array $enabled, int $trial, bool $signedIn = false): array
{
    static $probe = null;
    if ($probe === null) {
        $probe = sys_get_temp_dir() . '/ms_te_probe_' . getmypid();
        @mkdir($probe, 0777, true);
        foreach (['temporary-email.php', 'i18n.php'] as $f) {
            copy($root . '/' . $f, $probe . '/' . $f);
        }
        foreach (['partials', 'lang'] as $d) {
            @mkdir($probe . '/' . $d, 0777, true);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $rel = substr($item->getPathname(), strlen($root) + 1);
                if ($item->isDir()) {
                    @mkdir($probe . '/' . $rel, 0777, true);
                } else {
                    copy($item->getPathname(), $probe . '/' . $rel);
                }
            }
        }
        file_put_contents($probe . '/config.php', <<<'PHP'
<?php
// Stub config.php for tests/i18n_test.php: just what temporary-email.php and its partials read.
$config = [
    'email'  => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
    'app'    => ['version' => 'test'],
    'trial'  => ['days' => (int) getenv('PROBE_TRIAL')],
    'i18n'   => ['enabled' => array_values(array_filter(explode(',', (string) getenv('PROBE_ENABLED'))))],
    'retention_hold' => ['days' => 30, 'max_per_year' => 4],
];
$_SERVER['REQUEST_URI'] = (string) getenv('PROBE_URI');
require_once __DIR__ . '/i18n.php';
i18nBootstrap($config['i18n'], $_SERVER);
$pdo = new PDO('sqlite::memory:');
session_start();
$_SESSION = getenv('PROBE_SIGNED_IN') === '1' ? ['pro_user_id' => 1] : [];
function logMessage($level, $message, $context = []) {}
PHP);
        register_shutdown_function(static function () use ($probe) {
            exec('rm -rf ' . escapeshellarg($probe));
        });
    }
    $env = [
        'PROBE_ENABLED' => implode(',', $enabled),
        'PROBE_URI' => ($locale === 'sv' ? '/sv' : '') . '/temporary-email.php',
        'PROBE_TRIAL' => (string) $trial,
        'PROBE_SIGNED_IN' => $signedIn ? '1' : '0',
        'PATH' => (string) getenv('PATH'),
    ];
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), 'temporary-email.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($proc);
    return ['raw' => $out, 'err' => $err];
}

/**
 * The copyright year changes every January and the Article dates follow the
 * file's mtime; the fixtures must not. "inLanguage" was added to the JSON-LD
 * after the fixtures were rendered, so it is removed before the English page is
 * compared.
 */
function teNorm(string $html): string
{
    $html = preg_replace('/&copy; \d{4}/', '&copy; YYYY', $html);
    $html = preg_replace('/"date(Published|Modified)":"[^"]*"/', '"date$1":"DATE"', $html);
    return str_replace('"inLanguage":"en",', '', $html);
}

// --- temporary-email.php: English fixtures ------------------------------------
// Rendered from temporary-email.php before it went through the data file: signed
// out with the trial, signed out without it, and signed in.
$teVariants = [
    'signed_out'         => [60, false],
    'signed_out_notrial' => [0, false],
    'signed_in'          => [60, true],
];
foreach ($teVariants as $name => [$trial, $signedIn]) {
    $r = teRender($root, 'en', $onlyEn, $trial, $signedIn);
    check("temporary-email en {$name}: the page rendered", $r['raw'] !== '', $r['err']);
    same("fixture: temporary-email.{$name}.en.html", teNorm((string) file_get_contents($fx . "temporary-email.{$name}.en.html")), teNorm($r['raw']));
}

same('pages: temporary-email.php is offered in Swedish', ['en', 'sv'], $realPages['/temporary-email.php'] ?? null);
i18nTranslatedPages($realPages);

// The two data files: the same section ids in the same order, the same variables
// interpolated, the same shape. Loaded the way temporary-email.php does, once per
// trial branch.
$teLoad = static function (string $file, int $trial) use ($root): array {
    $msU = static fn(string $path): string => $path;
    $msDocDomain = 'example.test';
    $msDocTrialDays = $trial;
    $msDocFreeHours = 24;
    $msDocProMinDays = 1;
    $msDocProMaxDays = 7;
    $msDocStickyMax = 10;
    return require $root . '/partials/content/' . $file;
};
$teVars = static function (string $file): array {
    $src = (string) file_get_contents($file);
    preg_match_all('/\{?\$(ms[A-Za-z]+)/', preg_replace('#/\*.*?\*/#s', '', $src), $m);
    $names = array_values(array_unique($m[1]));
    sort($names);
    return $names;
};
foreach ([60, 0] as $trial) {
    $label = $trial > 0 ? 'trial' : 'notrial';
    $enData = $teLoad('temporary-email.en.php', $trial);
    $svData = $teLoad('temporary-email.sv.php', $trial);
    same("temporary-email data {$label}: same keys", array_keys($enData), array_keys($svData));
    same("temporary-email data {$label}: same section ids, same order", array_column($enData['sections'], 'id'), array_column($svData['sections'], 'id'));
    same("temporary-email data {$label}: the CTA sits after the same section", $enData['cta']['after'], $svData['cta']['after']);
    $bad = [];
    foreach (['title', 'description', 'headline', 'lede', 'toc_label', 'toc_eyebrow'] as $k) {
        if (trim((string) $svData[$k]) === '' || $svData[$k] === $enData[$k]) {
            $bad[] = $k;
        }
    }
    foreach ($svData['sections'] as $i => $sec) {
        if (trim($sec['heading']) === '' || trim($sec['html']) === '' || $sec['heading'] === $enData['sections'][$i]['heading'] || $sec['html'] === $enData['sections'][$i]['html']) {
            $bad[] = "section {$sec['id']}";
        }
    }
    foreach (['line', 'end_line'] as $k) {
        if ($svData['cta'][$k] === '' || $svData['cta'][$k] === $enData['cta'][$k]) {
            $bad[] = "cta {$k}";
        }
    }
    check("temporary-email data {$label}: every Swedish field is filled in and translated", $bad === [], implode(', ', $bad));
    // The same markup skeleton: the same tags in the same order, so the Swedish
    // page cannot add a list item, a link or a section the English page lacks.
    $tags = static fn(string $h): array => preg_match_all('/<\/?[a-z]+[^>]*>/', $h, $m) ? array_map(fn($t) => preg_replace('/\s+href="[^"]*"/', '', $t), $m[0]) : [];
    foreach ($enData['sections'] as $i => $sec) {
        same("temporary-email data {$label}: section {$sec['id']} has the same markup structure", $tags($sec['html']), $tags($svData['sections'][$i]['html']));
    }
}
same('temporary-email data: the same variables are interpolated', $teVars($root . '/partials/content/temporary-email.en.php'), $teVars($root . '/partials/content/temporary-email.sv.php'));
foreach (['en', 'sv'] as $code) {
    $file = $root . "/partials/content/temporary-email.{$code}.php";
    $src = (string) file_get_contents($file);
    check("temporary-email data {$code}: guarded like partials/landing", strpos($src, "if (!defined('TEMPMAIL_APP'))") !== false);
    check("temporary-email data {$code}: links to public pages go through \$msU", !preg_match('#href="/(?:pricing|temporary-email|blog|faq|terms|privacy|refund-policy)(?:\.php)?[^"]*"|href="/"#', $src));
    // No number of days or hours is written into the copy: the digit next to
    // dag/day/tim/hour must be a variable. The docblock and comments are dropped first.
    $code_only = preg_replace('#/\*.*?\*/#s', '', $src);
    check("temporary-email data {$code}: no digit next to a day/hour word", !preg_match('/\d\s*(?:&ndash;|–|-)?\s*(?:dag|day|tim|hour)/iu', $code_only) && !preg_match('/(?:dag|day|tim|hour)\w*\s*\d/iu', $code_only));
    check("temporary-email data {$code}: the domain is never written into the copy", stripos($code_only, 'manjo.me') === false && !preg_match('/@[a-z0-9-]+\.[a-z]{2,}/i', preg_replace('/@\{\$msDocDomain\}/', '', $code_only)));
}
$teCatalogBad = [];
foreach (['en' => $en, 'sv' => $sv] as $code => $cat) {
    foreach ($cat as $key => $value) {
        if (is_string($value) && preg_match('/\d\s*(?:&ndash;|–|-)?\s*(?:dag|day|tim|hour)/iu', $value)) {
            $teCatalogBad[] = "{$code}:{$key}";
        }
    }
}
check('catalogs: no digit next to a day/hour word in any value', $teCatalogBad === [], implode(', ', $teCatalogBad));

// --- temporary-email.php: Swedish render ---------------------------------------
foreach ($teVariants as $name => [$trial, $signedIn]) {
    $r = teRender($root, 'sv', $both, $trial, $signedIn);
    $enR = teRender($root, 'en', $both, $trial, $signedIn);
    $raw = $r['raw'];
    check("temporary-email sv {$name}: the page rendered", $raw !== '', $r['err']);
    check("temporary-email sv {$name}: no raw key, no unfilled placeholder", !preg_match('/(?<![\/\w])(?:nav|footer|landing)\.(?!contains\b)[a-z_.0-9]+\b/', $raw) && !preg_match('/\{[a-z_]+\}/', $raw));
    check("temporary-email sv {$name}: <html lang=\"sv\">", strpos($raw, '<html lang="sv">') !== false);
    check("temporary-email sv {$name}: canonical and hreflang", strpos($raw, '<link rel="canonical" href="https://example.test/sv/temporary-email.php">') !== false
        && strpos($raw, 'hreflang="sv" href="https://example.test/sv/temporary-email.php"') !== false
        && strpos($raw, 'hreflang="en" href="https://example.test/temporary-email.php"') !== false
        && strpos($raw, 'hreflang="x-default" href="https://example.test/temporary-email.php"') !== false);
    check("temporary-email sv {$name}: the primary keyword is in the title, the h1 and the first paragraph", strpos($raw, '<title>Tillfällig e-postadress – ') !== false
        && strpos($raw, '<h1 class="ms-doc__title">Tillfällig e-postadress</h1>') !== false
        && preg_match('#<p class="ms-lede ms-doc__lede">[^<]*tillfällig e-postadress#u', $raw) === 1);
    check("temporary-email sv {$name}: stylesheets resolve from the root, not from /sv/", strpos($raw, 'href="/assets/css/mailshield.css?v=') !== false && strpos($raw, 'href="assets/') === false);
    check("temporary-email sv {$name}: section anchors stay English", strpos($raw, 'id="what"') !== false && strpos($raw, 'href="#when-not-to-use"') !== false && strpos($raw, 'id="permanent"') !== false);
    preg_match_all('/<section class="ms-doc__section" id="([^"]+)"/', $raw, $svIds);
    preg_match_all('/<section class="ms-doc__section" id="([^"]+)"/', $enR['raw'], $enIds);
    same("temporary-email sv {$name}: the same section ids as English", $enIds[1], $svIds[1]);
    same("temporary-email sv {$name}: as many links, lists and buttons as English", [substr_count($enR['raw'], '<a '), substr_count($enR['raw'], '<li>'), substr_count($enR['raw'], 'ms-btn ')], [substr_count($raw, '<a '), substr_count($raw, '<li>'), substr_count($raw, 'ms-btn ')]);
    check("temporary-email sv {$name}: numbers and the domain are the config's (24, 1 to 7, 10)", strpos($raw, '24 timmar') !== false && strpos($raw, '1&ndash;7 dagar') !== false && strpos($raw, '1 till 7 dagar') !== false && strpos($raw, 'upp till 10 Sticky-adresser') !== false && strpos($raw, 'shopping@example.test') !== false);
    check("temporary-email sv {$name}: the trial branch follows the config", $trial > 0 && !$signedIn ? strpos($raw, "dina första {$trial} dagar ingår Pro, gratis.") !== false : strpos($raw, 'Det tar ungefär en minut, och det är gratis.') !== false);
    check("temporary-email sv {$name}: the CTA follows the session", $signedIn ? strpos($raw, 'href="/pro.php"') !== false && strpos($raw, 'Gå till din inkorg') !== false : strpos($raw, 'href="/register.php?plan=regular"') !== false && strpos($raw, 'Skapa din inkorg') !== false);
    check("temporary-email sv {$name}: the landing link goes to the Swedish page", strpos($raw, '<a href="/sv/">Startsidan visar hela bilden</a>') !== false);
    check("temporary-email sv {$name}: header and footer links go to the Swedish page", strpos($raw, 'href="/sv/temporary-email.php"') !== false && strpos($raw, 'href="/sv/faq.php"') !== false);
    check("temporary-email sv {$name}: no English page chrome left", strpos($raw, 'Skip to content') === false && strpos($raw, 'On this page') === false && strpos($raw, 'Create your inbox') === false);
    check("temporary-email sv {$name}: no claim the English page lacks (no comparison, no TempMail)", stripos($raw, 'TempMail') === false && stripos($raw, 'alternativ till') === false);
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $raw, $lm);
    $ld = json_decode($lm[1] ?? '', true);
    check("temporary-email sv {$name}: the JSON-LD parses as a Swedish Article", is_array($ld) && ($ld['@type'] ?? '') === 'Article' && ($ld['inLanguage'] ?? '') === 'sv' && ($ld['headline'] ?? '') === 'Tillfällig e-postadress');
    check("temporary-email sv {$name}: mainEntityOfPage is the Swedish URL", ($ld['mainEntityOfPage'] ?? '') === 'https://example.test/sv/temporary-email.php');
}
$teEnLd = json_decode((preg_match('#<script type="application/ld\+json">(.*?)</script>#s', teRender($root, 'en', $both, 60)['raw'], $em) ? $em[1] : ''), true);
check('temporary-email en: the JSON-LD carries inLanguage en and the English URL', ($teEnLd['inLanguage'] ?? '') === 'en' && ($teEnLd['mainEntityOfPage'] ?? '') === 'https://example.test/temporary-email.php');
// With Swedish switched off the Swedish URL is not offered and the English page carries no hreflang.
check('temporary-email en-only: no hreflang, no og:locale', (function () use ($root, $onlyEn) {
    $h = teRender($root, 'en', $onlyEn, 60)['raw'];
    return $h !== '' && strpos($h, 'hreflang') === false && strpos($h, 'og:locale') === false;
})());
same('resolve: /sv/temporary-email.php is served once Swedish is on', null, i18nResolveRequest(['REQUEST_URI' => '/sv/temporary-email.php'], $both));
same('resolve: ...and redirects while only English is on', '/temporary-email.php', i18nResolveRequest(['REQUEST_URI' => '/sv/temporary-email.php'], $onlyEn));
i18nSetLocale('en');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
