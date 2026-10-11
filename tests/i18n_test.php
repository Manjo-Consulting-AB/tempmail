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
$svRequiredPrefixes = ['nav.', 'footer.', 'consent.'];
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

function landingFixtureName(string $section, string $variant): string
{
    return 'landing.' . $section . ($variant === '' ? '' : '.' . $variant) . '.en.html';
}

i18nTranslatedPages($realPages);
foreach (landingVariants() as $section => $variants) {
    foreach ($variants as $name => $v) {
        $file = landingFixtureName($section, $name);
        same("fixture: {$file}", (string) file_get_contents($fx . $file), renderLanding($section, $v, 'en', $onlyEn));
    }
}
i18nSetLocale('en');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
