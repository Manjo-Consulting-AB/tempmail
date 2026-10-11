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
same('resolve: untranslated /sv/ page redirects to the plain URL', '/welcome.php', i18nResolveRequest(['REQUEST_URI' => '/sv/welcome.php'], $both));
same('resolve: ...and stays English', 'en', i18nLocale());
same('resolve: query string survives the redirect', '/welcome.php?plan=pro', i18nResolveRequest(['REQUEST_URI' => '/sv/welcome.php?plan=pro'], $both));
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
    if ($path[0] === '@') {
        continue; // a pseudo-entry such as '@app', not a URL
    }
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
$svRequiredPrefixes = ['nav.', 'footer.', 'consent.', 'landing.', 'pricing.', 'date.', 'faq.', 'legal.', 'appnav.', 'blog.', 'auth.', 'voucher.', 'service.mailbox.', 'app.error.', 'app.message.'];
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
same('analytics sv: privacy url follows the real translated-pages list (privacy is offered in Swedish)', '/sv/privacy.php#cookies', $consentSv['consent.privacy_url'] ?? null);
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
        check("sv render {$label}: Swedish links stay on pages that exist in Swedish", strpos($svOut, 'href="/sv/blog.php') === false && strpos($svOut, 'href="/sv/terms.php') === false);
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
check('sv plans: pricing and register are translated so their links have the prefix', strpos($planSv, 'href="/sv/pricing.php"') !== false && strpos($planSv, 'href="/sv/register.php?plan=pro"') !== false);
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
    check("pricing sv {$name}: the terms, privacy and refund policies go to /sv/", strpos($r['main'], 'href="/sv/terms.php"') !== false && strpos($r['main'], 'href="/sv/privacy.php"') !== false && strpos($r['main'], 'href="/sv/refund-policy.php"') !== false && strpos($r['main'], 'href="/terms.php"') === false && strpos($r['main'], 'href="/privacy.php"') === false);
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
    check("faq sv {$name}: translated pages link to /sv/", strpos($raw, 'href="/sv/temporary-email.php"') !== false && strpos($raw, 'href="/temporary-email.php"') === false && strpos($raw, 'href="/sv/blog.php"') !== false && strpos($raw, 'href="/blog.php"') === false);
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

// --- blog.php: English fixtures, Swedish posts ---------------------------------
// The fixtures were rendered from blog.php before it went through t(): the index,
// two posts (2026-10-01 has an image and a list, 2026-07-02 inline code and long
// prose) and the not-found view. No post uses a fenced code block, so none is
// covered by a fixture.

/**
 * Renders blog.php in a probe docroot (the real page, i18n.php, partials/, lang/
 * and the *.md posts, with a stub config.php). $extraPosts are written for this
 * one render only, as [path under the docroot => markdown].
 *
 * @return array{raw:string,err:string,status:int}
 */
function blogRender(string $root, string $locale, array $enabled, string $query = '', array $extraPosts = [], bool $signedIn = false): array
{
    static $probe = null;
    if ($probe === null) {
        $probe = sys_get_temp_dir() . '/ms_blog_probe_' . getmypid();
        @mkdir($probe, 0777, true);
        foreach (['blog.php', 'i18n.php'] as $f) {
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
        // Only the posts: the images are referenced, never read.
        foreach (['blog', 'blog/sv'] as $d) {
            if (!is_dir($root . '/' . $d)) {
                continue;
            }
            @mkdir($probe . '/' . $d, 0777, true);
            foreach (glob($root . '/' . $d . '/*.md') ?: [] as $post) {
                copy($post, $probe . '/' . $d . '/' . basename($post));
            }
        }
        file_put_contents($probe . '/config.php', <<<'PHP'
<?php
// Stub config.php for tests/i18n_test.php: just what blog.php and its partials read.
$config = [
    'email'  => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
    'app'    => ['version' => 'test'],
    'i18n'   => ['enabled' => array_values(array_filter(explode(',', (string) getenv('PROBE_ENABLED'))))],
];
// A CLI render is told its URI and query; under the built-in server both are the request's own.
if (getenv('PROBE_URI') !== false) {
    $_SERVER['REQUEST_URI'] = (string) getenv('PROBE_URI');
    parse_str((string) getenv('PROBE_QUERY'), $_GET);
}
require_once __DIR__ . '/i18n.php';
i18nBootstrap($config['i18n'], $_SERVER);
if (getenv('PROBE_STATUS_FILE') !== false) {
    register_shutdown_function(static function () {
        file_put_contents((string) getenv('PROBE_STATUS_FILE'), (string) http_response_code());
    });
}
session_start();
$_SESSION = getenv('PROBE_SIGNED_IN') === '1' ? ['pro_user_id' => 1] : [];
function logMessage($level, $message, $context = []) {}
PHP);
        register_shutdown_function(static function () use ($probe) {
            exec('rm -rf ' . escapeshellarg($probe));
        });
    }
    foreach ($extraPosts as $rel => $md) {
        @mkdir(dirname($probe . '/' . $rel), 0777, true);
        file_put_contents($probe . '/' . $rel, $md);
    }
    $statusFile = $probe . '/status.txt';
    @unlink($statusFile);
    $env = [
        'PROBE_ENABLED' => implode(',', $enabled),
        'PROBE_URI' => ($locale === 'sv' ? '/sv' : '') . '/blog.php' . ($query !== '' ? '?' . $query : ''),
        'PROBE_QUERY' => $query,
        'PROBE_SIGNED_IN' => $signedIn ? '1' : '0',
        'PROBE_STATUS_FILE' => $statusFile,
        'PATH' => (string) getenv('PATH'),
    ];
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), 'blog.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($proc);
    foreach (array_keys($extraPosts) as $rel) {
        @unlink($probe . '/' . $rel);
    }
    // PHP reports false (0 here) for a response nothing changed, which is a 200.
    $status = is_file($statusFile) ? (int) file_get_contents($statusFile) : 0;
    return ['raw' => $out, 'err' => $err, 'status' => $status ?: 200];
}

/**
 * What blog.php answers over HTTP, for the one thing the CLI cannot show: the
 * Location header (the CLI keeps no header list). The probe docroot is served by
 * PHP's built-in server with a router for the /sv/ rewrite, started on first use.
 *
 * @return array{status:int,location:?string,body:string}
 */
function blogHttp(string $root, string $uri, array $extraPosts = []): array
{
    static $server = null;
    // Reuse the CLI probe's docroot; the first render creates it.
    blogRender($root, 'en', ['en', 'sv']);
    $probe = sys_get_temp_dir() . '/ms_blog_probe_' . getmypid();
    if ($server === null) {
        file_put_contents($probe . '/router.php', <<<'PHP'
<?php
// Emulates the .htaccess rewrite ^(sv)/([A-Za-z0-9_-]+\.php)$ for the built-in server.
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/sv/([A-Za-z0-9_-]+\.php)$#', $path, $m)) {
    $_SERVER['SCRIPT_NAME'] = '/' . $m[1];
    require __DIR__ . '/' . $m[1];
    return true;
}
return false;
PHP);
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = $sock ? (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1) : 0;
        if ($sock) {
            fclose($sock);
        }
        $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $probe, $probe . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $probe, [
            'PROBE_ENABLED' => 'en,sv',
            'PATH' => (string) getenv('PATH'),
        ]);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($c) {
                fclose($c);
                break;
            }
            usleep(100000);
        }
        $server = ['proc' => $proc, 'port' => $port];
        register_shutdown_function(static function () use ($proc) {
            proc_terminate($proc);
        });
    }
    foreach ($extraPosts as $rel => $md) {
        @mkdir(dirname($probe . '/' . $rel), 0777, true);
        file_put_contents($probe . '/' . $rel, $md);
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 10]]);
    $body = (string) @file_get_contents('http://127.0.0.1:' . $server['port'] . $uri, false, $ctx);
    $headers = $http_response_header ?? [];
    foreach (array_keys($extraPosts) as $rel) {
        @unlink($probe . '/' . $rel);
    }
    $status = isset($headers[0]) && preg_match('#^HTTP/\S+ (\d+)#', $headers[0], $m) ? (int) $m[1] : 0;
    $location = null;
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
}

/** The copyright year changes every January; the fixtures must not. */
function blogNorm(string $html): string
{
    return preg_replace('/&copy; \d{4}/', '&copy; YYYY', $html);
}

$blogFixtures = [
    'index'           => ['blog.index.en.html', '', 200],
    'post 2026-10-01' => ['blog.post_2026-10-01.en.html', 'post=2026-10-01', 200],
    'post 2026-07-02' => ['blog.post_2026-07-02.en.html', 'post=2026-07-02', 200],
    'unknown post'    => ['blog.post_missing.en.html', 'post=2099-01-01', 404],
];
foreach ($blogFixtures as $name => [$file, $query, $status]) {
    $r = blogRender($root, 'en', $onlyEn, $query);
    check("blog en {$name}: the page rendered", $r['raw'] !== '', $r['err']);
    same("fixture: {$file}", blogNorm((string) file_get_contents($fx . $file)), blogNorm($r['raw']));
    check("blog en {$name}: status {$status}", $r['status'] === $status, (string) $r['status']);
    check("blog en {$name}: no hreflang and no og:locale while English is alone", strpos($r['raw'], 'hreflang') === false && strpos($r['raw'], 'og:locale') === false);
}

same('pages: the blog is offered in Swedish', ['en', 'sv'], $realPages['/blog.php'] ?? null);

// Every English post has a Swedish twin (same basename) and the other way round.
$blogEn = array_map(fn($f) => basename($f, '.md'), glob($root . '/blog/*.md') ?: []);
$blogSv = array_map(fn($f) => basename($f, '.md'), glob($root . '/blog/sv/*.md') ?: []);
sort($blogEn);
sort($blogSv);
check('blog: there are posts', count($blogEn) > 0);
same('blog: every blog/*.md has a blog/sv/ twin and there is no Swedish post without an English one', $blogEn, $blogSv);

// The same structure: title, headings, images (same files, same width), list items,
// links (same targets), code fences and paragraphs break the same way.
$blogShape = static function (string $md): array {
    $lines = preg_split('/\r?\n/', $md);
    $imgs = preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/', $md, $im) ? $im[1] : [];
    $links = preg_match_all('/(?<!!)\[[^\]]+\]\(([^)]+)\)/', $md, $lm) ? $lm[1] : [];
    return [
        'h1' => count(preg_grep('/^#\s/', $lines)),
        'headings' => array_map(fn($l) => strlen(strtok($l, ' ')), array_values(preg_grep('/^#{1,6}\s/', $lines))),
        'images' => $imgs,
        'links' => $links,
        'items' => count(preg_grep('/^(?:[-*]\s|\d+\.\s)/', $lines)),
        'fences' => substr_count($md, '```'),
        'blocks' => count(preg_split('/\n\s*\n/', trim($md))),
        'hard_breaks' => preg_match_all('/\\\\$/m', $md),
    ];
};
foreach ($blogEn as $base) {
    $enMd = (string) file_get_contents($root . "/blog/{$base}.md");
    $svMd = (string) file_get_contents($root . "/blog/sv/{$base}.md");
    same("blog sv {$base}: the same structure as the English post", $blogShape($enMd), $blogShape($svMd));
    check("blog sv {$base}: starts with a # title", preg_match('/^#\s+\S/', $svMd) === 1);
    check("blog sv {$base}: valid UTF-8, no unfilled draft marker", mb_check_encoding($svMd, 'UTF-8') && !preg_match('/TODO|FIXME|XXX/', $svMd));
}

// Swedish index: only the posts that have a Swedish file, newest first, in the Swedish chrome.
$svIndex = blogRender($root, 'sv', $both);
$raw = $svIndex['raw'];
check('blog sv index: the page rendered', $raw !== '', $svIndex['err']);
check('blog sv index: <html lang="sv">', strpos($raw, '<html lang="sv">') !== false);
check('blog sv index: no raw key, no unfilled placeholder', !preg_match('/(?<![\/\w])blog\.[a-z_.0-9]+\b/', $raw) && !preg_match('/\{[a-z_]+\}/', $raw));
check('blog sv index: canonical, hreflang and og:locale', strpos($raw, '<link rel="canonical" href="https://example.test/sv/blog.php">') !== false
    && strpos($raw, 'hreflang="sv" href="https://example.test/sv/blog.php"') !== false
    && strpos($raw, 'hreflang="en" href="https://example.test/blog.php"') !== false
    && strpos($raw, 'hreflang="x-default" href="https://example.test/blog.php"') !== false
    && strpos($raw, '<meta property="og:locale" content="sv_SE">') !== false);
check('blog sv index: the title, the h1 and the lede are Swedish', strpos($raw, '<title>Blogg · Mail Shield</title>') !== false
    && strpos($raw, '<h1 class="ms-faq__title">Blogg</h1>') !== false
    && strpos($raw, 'Nyheter om tjänsten och anteckningar från driften.') !== false);
check('blog sv index: stylesheets resolve from the root, not from /sv/', strpos($raw, 'href="/assets/css/mailshield.css?v=') !== false && strpos($raw, 'href="assets/') === false);
preg_match_all('#href="/sv/blog\.php\?post=([^"]+)"#', $raw, $listed);
$blogSvDesc = $blogSv;
rsort($blogSvDesc);
same('blog sv index: lists exactly the Swedish posts, newest first', $blogSvDesc, $listed[1]);
check('blog sv index: dates are Swedish', strpos($raw, '<time class="ms-blog__date" datetime="2025-12-01">1 december 2025</time>') !== false);
check('blog sv index: no English page chrome left', strpos($raw, 'Skip to content') === false && strpos($raw, 'Create your inbox') === false && strpos($raw, 'Cookie settings') === false);
check('blog sv index: header and footer links go to the Swedish pages', strpos($raw, 'href="/sv/faq.php"') !== false && strpos($raw, 'href="/sv/temporary-email.php"') !== false && strpos($raw, 'href="/sv/"') !== false);

// A post present in English only: a temporary fixture, so the result does not depend on which posts exist.
$tmpPost = ['blog/2099-01-01.md' => "# Only in English\n\nBody.\n"];
$r = blogRender($root, 'sv', $both, 'post=2099-01-01', $tmpPost);
check('blog sv: a post with no Swedish file answers 302', $r['status'] === 302, (string) $r['status']);
check('blog sv: ...with no body', trim($r['raw']) === '');
$h = blogHttp($root, '/sv/blog.php?post=2099-01-01', $tmpPost);
check('blog sv over HTTP: 302 with Location to the English post', $h['status'] === 302 && $h['location'] === '/blog.php?post=2099-01-01', $h['status'] . ' ' . var_export($h['location'], true));
$h = blogHttp($root, '/blog.php?post=2099-01-01', $tmpPost);
check('blog en over HTTP: the same post is served, no Location', $h['status'] === 200 && $h['location'] === null && strpos($h['body'], 'Only in English') !== false, $h['status'] . ' ' . var_export($h['location'], true));
$h = blogHttp($root, '/sv/blog.php?post=..%2Fconfig');
check('blog sv over HTTP: ?post=../config is a 404 with no Location', $h['status'] === 404 && $h['location'] === null, $h['status'] . ' ' . var_export($h['location'], true));
$h = blogHttp($root, '/sv/blog.php?post=2026-10-05');
check('blog sv over HTTP: a post with a Swedish file is served as it is', $h['status'] === 200 && $h['location'] === null && strpos($h['body'], '<html lang="sv">') !== false, $h['status'] . ' ' . var_export($h['location'], true));
$r = blogRender($root, 'sv', $both, '', $tmpPost);
check('blog sv index: a post with no Swedish file is not listed', strpos($r['raw'], '2099-01-01') === false && strpos($r['raw'], 'Only in English') === false);
$r = blogRender($root, 'en', $both, 'post=2099-01-01', $tmpPost);
check('blog en: the same post renders in English (no redirect)', strpos($r['raw'], '<h1 class="ms-blog__title">Only in English</h1>') !== false && $r['status'] === 200);
check('blog en: a post that exists in one language only carries no hreflang', strpos($r['raw'], 'hreflang') === false && strpos($r['raw'], 'og:locale') === false);

// ?post= is only ever looked up in the directory listing, never used as a path.
foreach (['../config', '..%2Fconfig', '..%2F..%2Fconfig.php', 'sv/2026-10-01', '2026-10-01.md', '2026-10-01%00', '/etc/passwd', 'blog/2026-10-01', '2026-10-0', '%2e%2e/%2e%2e/config'] as $evil) {
    foreach (['en', 'sv'] as $lc) {
        $r = blogRender($root, $lc, $both, 'post=' . $evil);
        check("blog {$lc}: ?post={$evil} finds nothing (a 404 page, nothing read)", $r['status'] === 404 && strpos($r['raw'], 'robots" content="noindex, nofollow"') !== false && stripos($r['raw'], '<?php') === false && stripos($r['raw'], 'DB_PASSWORD') === false);
    }
}
$r = blogRender($root, 'sv', $both, 'post[]=2026-10-01');
check('blog sv: an array ?post[]= is the index, not a post', strpos($r['raw'], 'class="ms-blog__list"') !== false && $r['status'] === 200);
$r = blogRender($root, 'sv', $both, 'post=2099-12-31');
check('blog sv: an unknown basename is a 404 in Swedish', $r['status'] === 404 && strpos($r['raw'], '<h1 class="ms-faq__title">Inlägget finns inte</h1>') !== false && strpos($r['raw'], '<a href="/sv/blog.php">bloggens startsida</a>') !== false);

// Every Swedish post, rendered.
foreach ($blogSv as $base) {
    $r = blogRender($root, 'sv', $both, 'post=' . $base);
    $raw = $r['raw'];
    $enPost = blogRender($root, 'en', $both, 'post=' . $base);
    check("blog sv {$base}: the page rendered", $raw !== '' && $r['status'] === 200, $r['err']);
    check("blog sv {$base}: <html lang=\"sv\"> and the Swedish canonical", strpos($raw, '<html lang="sv">') !== false && strpos($raw, '<link rel="canonical" href="https://example.test/sv/blog.php?post=' . $base . '">') !== false);
    check("blog sv {$base}: hreflang to the English twin, the Swedish page and x-default", strpos($raw, 'hreflang="en" href="https://example.test/blog.php?post=' . $base . '"') !== false
        && strpos($raw, 'hreflang="sv" href="https://example.test/sv/blog.php?post=' . $base . '"') !== false
        && strpos($raw, 'hreflang="x-default" href="https://example.test/blog.php?post=' . $base . '"') !== false
        && strpos($raw, '<meta property="og:locale" content="sv_SE">') !== false);
    check("blog en {$base}: the English post points to its Swedish twin", strpos($enPost['raw'], 'hreflang="sv" href="https://example.test/sv/blog.php?post=' . $base . '"') !== false && strpos($enPost['raw'], '<html lang="en">') !== false);
    check("blog sv {$base}: no image or link path is relative", !preg_match('#(?:src|href)="(?!https?:|mailto:|/|\#)#', preg_replace('#<(?:html|meta|link)\b[^>]*>#', '', $raw)));
    check("blog sv {$base}: images are root-relative", !preg_match('#<img [^>]*src="(?!/|https?:)#', $raw) && (strpos($raw, '<img ') === false || strpos($raw, 'src="/blog/images/') !== false));
    check("blog en {$base}: English output keeps the relative image path", strpos($enPost['raw'], '<img ') === false || strpos($enPost['raw'], 'src="blog/images/') !== false);
    check("blog sv {$base}: the date is Swedish", preg_match('#<time class="ms-blog__date" datetime="' . $base . '">\d{1,2} (?:januari|februari|mars|april|maj|juni|juli|augusti|september|oktober|november|december) \d{4}</time>#u', $raw) === 1);
    check("blog sv {$base}: the back links go to the Swedish pages", strpos($raw, '<a href="/sv/blog.php"><span aria-hidden="true">&larr;</span> Alla inlägg</a>') !== false && strpos($raw, 'Tillbaka till Mail Shield</a>') !== false);
    check("blog sv {$base}: no English page chrome left", strpos($raw, 'All posts') === false && strpos($raw, 'Back to Mail Shield') === false && strpos($raw, 'Skip to content') === false && strpos($raw, 'opens in new tab') === false);
    // Every link to another page of the site is a route that exists (a file, or the landing page).
    $linkBad = [];
    preg_match_all('/\b(?:href|src)="(\/[^"]*)"/', $raw, $hm);
    foreach (array_unique($hm[1]) as $href) {
        if (strncmp($href, '//', 2) === 0) {
            continue;
        }
        $path = (string) parse_url(html_entity_decode($href), PHP_URL_PATH);
        $path = preg_replace('#^/sv(?=/|$)#', '', $path);
        if ($path === '' || $path === '/') {
            continue;
        }
        if (!is_file($root . $path)) {
            $linkBad[] = $href;
        }
    }
    check("blog sv {$base}: every site link is a route that exists", $linkBad === [], implode(', ', $linkBad));
}
$r = blogRender($root, 'sv', $both, 'post=2026-10-01');
check('blog sv 2026-10-01: the image is root-relative and keeps its alt text', strpos($r['raw'], '<img src="/blog/images/mcp.gif" alt="Checking email"') !== false);
$r = blogRender($root, 'sv', $both, 'post=2026-10-05');
check('blog sv 2026-10-05: uses the confirmed glossary term for the retention hold', strpos($r['raw'], 'Pausa radering') !== false && strpos($r['raw'], 'Sticky-adresser') !== false);

// The renderer, through the page: a path it leaves alone, and one it moves to the root
// for another language. Temporary posts, so no real post has to contain every case.
$mdIn = "# Paths\n\n![a](blog/images/x.jpeg|200)\n\n[ext](https://example.test/a) [mail](mailto:a@example.test) [hash](#top) [root](/faq.php) [rel](blog/x)\n\n<script>alert(1)</script> ![x](javascript:alert(1))\n";
$mdPosts = ['blog/2099-01-02.md' => $mdIn, 'blog/sv/2099-01-02.md' => $mdIn];
$mdEn = blogRender($root, 'en', $both, 'post=2099-01-02', $mdPosts)['raw'];
$mdSv = blogRender($root, 'sv', $both, 'post=2099-01-02', $mdPosts)['raw'];
check('markdown: a relative image and link are unchanged for English', strpos($mdEn, 'src="blog/images/x.jpeg"') !== false && strpos($mdEn, 'href="blog/x"') !== false && strpos($mdEn, '(opens in new tab)') !== false);
check('markdown: a relative image and link get a leading slash for another language', strpos($mdSv, 'src="/blog/images/x.jpeg"') !== false && strpos($mdSv, 'href="/blog/x"') !== false && strpos($mdSv, 'style="width:200px;height:auto;"') !== false && strpos($mdSv, '(öppnas i ny flik)') !== false);
check('markdown: https:, mailto:, # and a root path are left as written', strpos($mdSv, 'href="https://example.test/a"') !== false && strpos($mdSv, 'href="mailto:a@example.test"') !== false && strpos($mdSv, 'href="#top"') !== false && strpos($mdSv, 'href="/faq.php"') !== false);
check('markdown: a script in a post is still escaped, in both languages', strpos($mdEn, '<script>alert') === false && strpos($mdSv, '<script>alert') === false && strpos($mdSv, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);

/**
 * Renders a real legal page (terms.php, privacy.php or refund-policy.php) in a
 * probe docroot (a copy of the page and what it requires, plus a stub
 * config.php) as a CLI subprocess and returns the page. $referral switches the
 * invite programme, $trial is the trial length in days.
 */
function legalRender(string $root, string $page, string $locale, array $enabled, bool $referral, int $trial): array
{
    static $probe = null;
    if ($probe === null) {
        $probe = sys_get_temp_dir() . '/ms_legal_probe_' . getmypid();
        @mkdir($probe, 0777, true);
        foreach (['terms.php', 'privacy.php', 'refund-policy.php', 'i18n.php', 'referrals.php'] as $f) {
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
// Stub config.php for tests/i18n_test.php: just what the legal pages and their partials read.
$config = [
    'email'  => ['base_url' => 'https://example.test/', 'domain' => 'example.test'],
    'app'    => ['version' => 'test', 'cleanup_hours' => 24],
    'trial'  => ['days' => (int) getenv('PROBE_TRIAL')],
    'i18n'   => ['enabled' => array_values(array_filter(explode(',', (string) getenv('PROBE_ENABLED'))))],
    'legal'  => ['company' => 'Example AB', 'org_number' => '556000-0000', 'vat_number' => 'SE556000000001', 'contact_email' => 'support@example.test'],
    'cleanup' => ['log_retention_days' => 30, 'regular_inactivity_warn_days' => 335, 'regular_inactivity_days' => 365],
    'address_cooldown' => ['months' => 6],
    'retention_hold' => ['days' => 30, 'max_per_year' => 4],
    'referral' => ['enabled' => getenv('PROBE_REFERRAL') === '1'],
];
$_SERVER['REQUEST_URI'] = (string) getenv('PROBE_URI');
require_once __DIR__ . '/i18n.php';
i18nBootstrap($config['i18n'], $_SERVER);
$pdo = new PDO('sqlite::memory:');
session_start();
$_SESSION = [];
function logMessage($level, $message, $context = []) {}
PHP);
        register_shutdown_function(static function () use ($probe) {
            exec('rm -rf ' . escapeshellarg($probe));
        });
    }
    $env = [
        'PROBE_ENABLED' => implode(',', $enabled),
        'PROBE_URI' => ($locale === 'sv' ? '/sv' : '') . '/' . $page,
        'PROBE_REFERRAL' => $referral ? '1' : '0',
        'PROBE_TRIAL' => (string) $trial,
        'PATH' => (string) getenv('PATH'),
    ];
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), $page], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($proc);
    return ['raw' => $out, 'err' => $err];
}

/** The copyright year changes every January; the fixtures must not. */
function legalNorm(string $html): string
{
    return preg_replace('/&copy; \d{4}/', '&copy; YYYY', $html);
}

// --- Legal pages: English fixtures, refund policy in Swedish -------------------
// The fixtures were rendered from terms.php, privacy.php and refund-policy.php
// before they went through the content files: the invite programme on and off,
// the trial on and off.
$legalVariants = [
    'ref_trial'     => [true, 60],
    'ref_notrial'   => [true, 0],
    'noref_trial'   => [false, 60],
    'noref_notrial' => [false, 0],
];
foreach (['terms', 'privacy', 'refund-policy'] as $legalPage) {
    foreach ($legalVariants as $name => [$referral, $trial]) {
        $r = legalRender($root, $legalPage . '.php', 'en', $onlyEn, $referral, $trial);
        check("legal en {$legalPage} {$name}: the page rendered", $r['raw'] !== '', $r['err']);
        same("fixture: legal.{$legalPage}.{$name}.en.html", legalNorm((string) file_get_contents($fx . "legal.{$legalPage}.{$name}.en.html")), legalNorm($r['raw']));
    }
}

same('pages: the refund policy is offered in Swedish', ['en', 'sv'], $realPages['/refund-policy.php'] ?? null);
same('pages: the terms are offered in Swedish', ['en', 'sv'], $realPages['/terms.php'] ?? null);
same('pages: the privacy policy is offered in Swedish', ['en', 'sv'], $realPages['/privacy.php'] ?? null);

// The two content files: the same section ids in the same order, the same
// 'updated', the same interpolated variables, and no number typed into the prose.
$legalLoad = static function (string $file) use ($root): array {
    $f = ['contact' => 'C', 'company_full' => 'CF', 'hosting' => 'H', 'domain' => 'd', 'trial_days' => 60, 'free_hours' => 24, 'hold_max' => 4, 'hold_days' => 30, 'quota_free_mb' => 10, 'quota_pro_mb' => 100, 'cooldown_months' => 6, 'delete_days' => 365, 'warn_days' => 335, 'log_days' => 30, 'grant_idle_days' => 90, 'code_seconds' => 60, 'client_idle_days' => 30];
    $ref = ['hold_days' => 30, 'cookie_days' => 30, 'window_days' => 120, 'bonus_months' => 3, 'sticky_bonus' => 3];
    $msU = static fn(string $path): string => $path;
    return require $root . '/partials/content/legal/' . $file;
};
$legalVars = static function (string $file): array {
    $src = (string) file_get_contents($file);
    preg_match_all('/\$(f|ref|msU|guaranteeDays|graceDays|ttlMinDays|ttlMaxDays|trustedDays|maxSignedInDays|stayDays|tokenSweepDays|pushoverChars|abuseEventDays|minAge|proTtlMinDays|proTtlMaxDays|noticeDays|yearPlanMonths|liabilityMonths)\b(?:\[\'([a-z_]+)\'\])?/', preg_replace('#/\*.*?\*/#s', '', $src), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $hit) {
        $out[] = $hit[1] . (isset($hit[2]) && $hit[2] !== '' ? '.' . $hit[2] : '');
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
};
$legalEn = $legalLoad('refund-policy.en.php');
$legalSv = $legalLoad('refund-policy.sv.php');
same('refund-policy data: the same section ids in the same order', array_column($legalEn['sections'], 'id'), array_column($legalSv['sections'], 'id'));
same('refund-policy data: the same updated date, and it is ISO', $legalEn['updated'], $legalSv['updated']);
check('refund-policy data: updated is a real Y-m-d date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $legalEn['updated']) && date('Y-m-d', strtotime($legalEn['updated'])) === $legalEn['updated']);
same('refund-policy data: the same path', $legalEn['path'], $legalSv['path']);
same('refund-policy data: the same interpolated variables', $legalVars($root . '/partials/content/legal/refund-policy.en.php'), $legalVars($root . '/partials/content/legal/refund-policy.sv.php'));
foreach (['en', 'sv'] as $code) {
    $src = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . "/partials/content/legal/refund-policy.{$code}.php"));
    check("refund-policy data {$code}: no number next to a day, month or hour unless it comes from a variable", !preg_match('/\d+[\s-]*(?:dag|day|månad|month|timm|hour)/iu', $src));
    check("refund-policy data {$code}: links to public pages go through \$msU", !preg_match('#href="/(?:pricing|temporary-email|blog|faq|terms|privacy|refund-policy)(?:\.php)?[^"]*"|href="/"#', $src));
    check("refund-policy data {$code}: no clause on which language prevails", !preg_match('/prevail|företräde|tolkningsföreträde|authentic/i', $src));
}

// The privacy policy (epic #441 step 3/4): the same checks, plus the language-preference
// statement (decision 5) in both languages, asserted on a distinctive phrase each.
$privEn = $legalLoad('privacy.en.php');
$privSv = $legalLoad('privacy.sv.php');
same('privacy data: the same section ids in the same order', array_column($privEn['sections'], 'id'), array_column($privSv['sections'], 'id'));
same('privacy data: the same updated date', $privEn['updated'], $privSv['updated']);
check('privacy data: updated is a real Y-m-d date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $privEn['updated']) && date('Y-m-d', strtotime($privEn['updated'])) === $privEn['updated']);
same('privacy data: the same path', $privEn['path'], $privSv['path']);
same('privacy data: the same interpolated variables', $legalVars($root . '/partials/content/legal/privacy.en.php'), $legalVars($root . '/partials/content/legal/privacy.sv.php'));
foreach (['en', 'sv'] as $code) {
    $src = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . "/partials/content/legal/privacy.{$code}.php"));
    check("privacy data {$code}: no number next to a day, month or hour unless it comes from a variable", !preg_match('/\d+[\s-]*(?:dag|day|månad|month|timm|hour)/iu', $src));
    check("privacy data {$code}: no clause on which language prevails", !preg_match('/prevail|företräde|tolkningsföreträde|authentic (?:version|text)|autentisk/i', $src));
}
$privAll = static function (array $doc): string {
    return implode("\n", array_column($doc['sections'], 'html'));
};
check('privacy data en: the account stores a language preference', strpos($privAll($privEn), 'the account stores that language preference') !== false);
check('privacy data sv: the account stores a language preference', strpos($privAll($privSv), 'sparar kontot den språkinställningen') !== false);
check('privacy data en: the browser language is read and not stored', strpos($privAll($privEn), 'language setting is read to choose the display language; it is not stored') !== false);
check('privacy data sv: the browser language is read and not stored', strpos($privAll($privSv), 'webbläsarens språkinställning för att välja visningsspråk; den sparas inte') !== false);
check('privacy data en: no cookie is used for language', strpos($privAll($privEn), 'No cookie is used for language.') !== false);
check('privacy data sv: no cookie is used for language', strpos($privAll($privSv), 'Ingen cookie används för språk.') !== false);
foreach ($legalVariants as $name => [$referral, $trial]) {
    $r = legalRender($root, 'privacy.php', 'sv', $both, $referral, $trial);
    $raw = $r['raw'];
    check("legal sv privacy {$name}: the page rendered", $raw !== '', $r['err']);
    check("legal sv privacy {$name}: lang sv", strpos($raw, '<html lang="sv">') !== false);
    check("legal sv privacy {$name}: the /sv/ canonical", strpos($raw, '<link rel="canonical" href="https://example.test/sv/privacy.php">') !== false);
    check("legal sv privacy {$name}: hreflang en, sv and x-default", strpos($raw, 'hreflang="en" href="https://example.test/privacy.php"') !== false && strpos($raw, 'hreflang="sv" href="https://example.test/sv/privacy.php"') !== false && strpos($raw, 'hreflang="x-default"') !== false);
    check("legal sv privacy {$name}: no raw catalog key", !preg_match('/\blegal\.(?:shell|fact)\./', $raw));
    check("legal sv privacy {$name}: the shell is Swedish", strpos($raw, 'Gäller från 11 oktober 2026') !== false && strpos($raw, 'På den här sidan') !== false && strpos($raw, 'Effective') === false && strpos($raw, 'On this page') === false);
    check("legal sv privacy {$name}: every section id is on the page", (function () use ($raw, $privSv) {
        foreach ($privSv['sections'] as $s) {
            if (strpos($raw, 'id="' . $s['id'] . '"') === false) {
                return false;
            }
        }
        return true;
    })());
    check("legal sv privacy {$name}: the facts follow the stub config", strpos($raw, 'support@example.test') !== false && strpos($raw, 'Example AB') !== false && strpos($raw, 'i 6 månader') !== false && strpos($raw, 'upp till 4 gånger per år') !== false);
    check("legal sv privacy {$name}: the invite cookie follows the referral switch only in the facts, not the structure", strpos($raw, 'ms_ref') !== false);
    check("legal sv privacy {$name}: the language-preference statement is on the page", strpos($raw, 'sparar kontot den språkinställningen') !== false && strpos($raw, 'Ingen cookie används för språk.') !== false);
}

// The Swedish page: language, canonical, hreflang, no raw key, no English chrome.
foreach ($legalVariants as $name => [$referral, $trial]) {
    $r = legalRender($root, 'refund-policy.php', 'sv', $both, $referral, $trial);
    $raw = $r['raw'];
    check("legal sv refund-policy {$name}: the page rendered", $raw !== '', $r['err']);
    check("legal sv refund-policy {$name}: lang sv", strpos($raw, '<html lang="sv">') !== false);
    check("legal sv refund-policy {$name}: the /sv/ canonical", strpos($raw, '<link rel="canonical" href="https://example.test/sv/refund-policy.php">') !== false);
    check("legal sv refund-policy {$name}: hreflang en, sv and x-default", strpos($raw, 'hreflang="en" href="https://example.test/refund-policy.php"') !== false && strpos($raw, 'hreflang="sv" href="https://example.test/sv/refund-policy.php"') !== false && strpos($raw, 'hreflang="x-default"') !== false);
    check("legal sv refund-policy {$name}: no raw catalog key", !preg_match('/\blegal\.(?:shell|fact)\./', $raw));
    check("legal sv refund-policy {$name}: the shell is Swedish", strpos($raw, 'Gäller från 10 oktober 2026') !== false && strpos($raw, 'På den här sidan') !== false && strpos($raw, 'Effective') === false && strpos($raw, 'On this page') === false);
    check("legal sv refund-policy {$name}: every section id is on the page", (function () use ($raw, $legalSv) {
        foreach ($legalSv['sections'] as $s) {
            if (strpos($raw, 'id="' . $s['id'] . '"') === false) {
                return false;
            }
        }
        return true;
    })());
    check("legal sv refund-policy {$name}: the facts follow the stub config", strpos($raw, 'support@example.test') !== false && strpos($raw, '30 dagars') !== false);
    check("legal sv refund-policy {$name}: Paddle is named as reseller and Merchant of Record", strpos($raw, 'Paddle.com är Merchant of Record') !== false && strpos($raw, 'återförsäljare') !== false);
}
$legalEn = $legalLoad('terms.en.php');
$legalSv = $legalLoad('terms.sv.php');
same('terms data: the same section ids in the same order', array_column($legalEn['sections'], 'id'), array_column($legalSv['sections'], 'id'));
same('terms data: the same updated date, and it is ISO', $legalEn['updated'], $legalSv['updated']);
check('terms data: updated is a real Y-m-d date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $legalEn['updated']) && date('Y-m-d', strtotime($legalEn['updated'])) === $legalEn['updated']);
same('terms data: the same path', $legalEn['path'], $legalSv['path']);
same('terms data: the same interpolated variables', $legalVars($root . '/partials/content/legal/terms.en.php'), $legalVars($root . '/partials/content/legal/terms.sv.php'));
foreach (['en', 'sv'] as $code) {
    $src = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . "/partials/content/legal/terms.{$code}.php"));
    check("terms data {$code}: no number next to a day, month or hour unless it comes from a variable", !preg_match('/\d+[\s-]*(?:dag|day|månad|month|timm|hour)/iu', $src));
    check("terms data {$code}: links to public pages go through \$msU", !preg_match('#href="/(?:pricing|temporary-email|blog|faq|terms|privacy|terms)(?:\.php)?[^"]*"|href="/"#', $src));
    check("terms data {$code}: no clause on which language prevails", !preg_match('/prevail|företräde|tolkningsföreträde|authentic/i', $src));
}

// The Swedish page: language, canonical, hreflang, no raw key, no English chrome.
foreach ($legalVariants as $name => [$referral, $trial]) {
    $r = legalRender($root, 'terms.php', 'sv', $both, $referral, $trial);
    $raw = $r['raw'];
    check("legal sv terms {$name}: the page rendered", $raw !== '', $r['err']);
    check("legal sv terms {$name}: lang sv", strpos($raw, '<html lang="sv">') !== false);
    check("legal sv terms {$name}: the /sv/ canonical", strpos($raw, '<link rel="canonical" href="https://example.test/sv/terms.php">') !== false);
    check("legal sv terms {$name}: hreflang en, sv and x-default", strpos($raw, 'hreflang="en" href="https://example.test/terms.php"') !== false && strpos($raw, 'hreflang="sv" href="https://example.test/sv/terms.php"') !== false && strpos($raw, 'hreflang="x-default"') !== false);
    check("legal sv terms {$name}: no raw catalog key", !preg_match('/\blegal\.(?:shell|fact)\./', $raw));
    check("legal sv terms {$name}: the shell is Swedish", strpos($raw, 'Gäller från 10 oktober 2026') !== false && strpos($raw, 'På den här sidan') !== false && strpos($raw, 'Effective') === false && strpos($raw, 'On this page') === false);
    check("legal sv terms {$name}: every section id is on the page", (function () use ($raw, $legalSv) {
        foreach ($legalSv['sections'] as $s) {
            if (strpos($raw, 'id="' . $s['id'] . '"') === false) {
                return false;
            }
        }
        return true;
    })());
    check("legal sv terms {$name}: the facts follow the stub config", strpos($raw, 'support@example.test') !== false && strpos($raw, 'Användarvillkor') !== false);
    check("legal sv terms {$name}: the trial paragraph follows the trial setting", ($trial > 0) === (strpos($raw, 'provperiod på ' . $trial . ' dagar') !== false));
    check("legal sv terms {$name}: the invite section's numbers come from the settings", strpos($raw, 'id="referrals"') !== false);
    check("legal sv terms {$name}: Paddle is named as reseller and Merchant of Record", strpos($raw, 'Paddle.com är Merchant of Record') !== false && strpos($raw, 'återförsäljare') !== false);
}
// Facts in Swedish: the company sentence and the hosting line come from the catalog.
$legalFactsSv = (static function () use ($root) {
    $config = ['legal' => ['company' => 'Example AB', 'org_number' => '556000-0000', 'vat_number' => '', 'contact_email' => 'a@example.test'], 'email' => ['domain' => 'example.test']];
    if (!defined('TEMPMAIL_APP')) {
        define('TEMPMAIL_APP', true);
    }
    i18nSetLocale('sv');
    return require $root . '/partials/legal_facts.php';
})();
same('legal facts sv: company_full carries the Swedish wording and only the numbers that are set', 'Example AB (organisationsnummer 556000-0000), ett bolag registrerat i Sverige', $legalFactsSv['company_full']);
same('legal facts sv: hosting', 'Inleed, ett varumärke som tillhör Yelles AB (Sverige)', $legalFactsSv['hosting']);
i18nSetLocale('en');

// Confirmed Swedish glossary (#416, I18N.md §5): the retired terms never come back.
$svTermFiles = array_merge([$root . '/lang/sv.php'], glob($root . '/partials/content/*.sv.php') ?: [], glob($root . '/blog/sv/*.md') ?: []);
foreach ($svTermFiles as $svTermFile) {
    $svTermRaw = (string) file_get_contents($svTermFile);
    check('glossary: no presentkod or lagringspaus in ' . basename($svTermFile), !preg_match('/presentkod|lagringspaus/i', $svTermRaw));
}
foreach ((require $root . '/lang/sv.php') as $svTermKey => $svTermValue) {
    check("glossary: no retired term in catalog value {$svTermKey}", !preg_match('/presentkod|lagringspaus/i', is_array($svTermValue) ? implode(' ', $svTermValue) : (string) $svTermValue));
}

// =============================================================================
// Phase 3 groundwork (epic #428 step 1/8): app locale resolver, account
// language, service error keys, app nav
// =============================================================================

// --- '@app' gate ---------------------------------------------------------------
same("pages: '@app' is English only until the app is translated", ['en'], $realPages['@app'] ?? null);
same("pages: '@app' is a pseudo-entry, i18nPageLocales() reads it as given", ['en'], i18nPageLocales('@app', $both));
i18nTranslatedPages(['@app' => ['en', 'sv']]);
same("pages: '@app' overridden to en+sv", ['en', 'sv'], i18nPageLocales('@app', $both));
same("pages: '@app' overridden, LOCALES_ENABLED=en still wins", ['en'], i18nPageLocales('@app', $onlyEn));
i18nTranslatedPages($realPages);

// --- i18nAcceptLanguage() ------------------------------------------------------
same('accept: highest q first', 'sv', i18nAcceptLanguage('en;q=0.5, sv;q=0.9', ['en', 'sv']));
same('accept: header order on a tie', 'en', i18nAcceptLanguage('en, sv', ['en', 'sv']));
same('accept: header order on a tie, other way', 'sv', i18nAcceptLanguage('sv, en', ['en', 'sv']));
same('accept: sv-SE matches sv', 'sv', i18nAcceptLanguage('sv-SE,en;q=0.8', ['en', 'sv']));
same('accept: primary subtag is case-insensitive', 'sv', i18nAcceptLanguage('SV-se', ['en', 'sv']));
same('accept: q=0 is ignored', 'en', i18nAcceptLanguage('sv;q=0, en;q=0.1', ['en', 'sv']));
same('accept: q=0 alone gives null', null, i18nAcceptLanguage('sv;q=0', ['en', 'sv']));
same('accept: garbage is ignored', 'sv', i18nAcceptLanguage('!!!, ;;;, sv;q=abc, sv;q=0.4', ['en', 'sv']));
same('accept: pure garbage gives null', null, i18nAcceptLanguage('<script>,;q=1,*', ['en', 'sv']));
same('accept: no match gives null', null, i18nAcceptLanguage('de, fr;q=0.8', ['en', 'sv']));
same('accept: a language that is not a candidate is skipped', 'en', i18nAcceptLanguage('sv, en;q=0.5', ['en']));
same('accept: empty header gives null', null, i18nAcceptLanguage('', ['en', 'sv']));
same('accept: returns the candidate as spelled', 'sv', i18nAcceptLanguage('sv', ['sv']));

// --- i18nAppLocale() -----------------------------------------------------------
$appSv = ['HTTP_ACCEPT_LANGUAGE' => 'sv-SE,sv;q=0.9', 'HTTP_X_MS_LOCALE' => 'sv'];
i18nTranslatedPages($realPages);
same("appLocale '@app'=en: Accept-Language sv still gives en", 'en', i18nAppLocale($appSv, null, $both, false));
same("appLocale '@app'=en: the account's sv gives en", 'en', i18nAppLocale([], 'sv', $both, false));
same("appLocale '@app'=en: X-MS-Locale ignored on a page", 'en', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'sv'], null, $both, false));
same("appLocale '@app'=en: X-MS-Locale honoured for an API when enabled (a public /sv/ page sent it)", 'sv', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'sv'], null, $both, true));
same("appLocale '@app'=en: X-MS-Locale honoured for an API before the account", 'sv', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'sv'], 'en', $both, true));
same("appLocale '@app'=en: X-MS-Locale for a language not enabled is ignored", 'en', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'sv'], null, $onlyEn, true));
same('appLocale: X-MS-Locale with an unknown value is ignored', 'en', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'xx'], null, $both, true));

i18nTranslatedPages(['@app' => ['en', 'sv']]);
same('appLocale open: default with no signal', 'en', i18nAppLocale([], null, $both, false));
same('appLocale open: Accept-Language sv', 'sv', i18nAppLocale(['HTTP_ACCEPT_LANGUAGE' => 'sv-SE,sv;q=0.9,en;q=0.8'], null, $both, false));
same('appLocale open: Accept-Language en beats nothing', 'en', i18nAppLocale(['HTTP_ACCEPT_LANGUAGE' => 'en-GB'], null, $both, false));
same('appLocale open: account en beats Accept-Language sv', 'en', i18nAppLocale(['HTTP_ACCEPT_LANGUAGE' => 'sv'], 'en', $both, false));
same('appLocale open: account sv beats Accept-Language en', 'sv', i18nAppLocale(['HTTP_ACCEPT_LANGUAGE' => 'en'], 'sv', $both, false));
same('appLocale open: header is not read for a page', 'en', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'sv'], null, $both, false));
same('appLocale open: header beats account for an API', 'en', i18nAppLocale(['HTTP_X_MS_LOCALE' => 'en'], 'sv', $both, true));
same('appLocale open: no header, an API falls to the account', 'sv', i18nAppLocale([], 'sv', $both, true));
same('appLocale open: LOCALES_ENABLED=en overrides the account', 'en', i18nAppLocale(['HTTP_ACCEPT_LANGUAGE' => 'sv'], 'sv', $onlyEn, false));
same('appLocale open: an unsupported account value is ignored', 'en', i18nAppLocale([], 'xx', $both, false));
same('appLocale open: only sv in Accept-Language, no match for de', 'en', i18nAppLocale(['HTTP_ACCEPT_LANGUAGE' => 'de'], null, $both, false));
i18nTranslatedPages($realPages);

// --- i18nUseAppLocale() --------------------------------------------------------
$useLocalePdo = new PDO('sqlite::memory:');
$useLocalePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$useLocalePdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, locale TEXT NULL)');
$useLocalePdo->exec("INSERT INTO pro_users (id, locale) VALUES (1, 'sv'), (2, NULL)");
i18nTranslatedPages(['@app' => ['en', 'sv']]);
i18nSetLocale('en');
i18nUseAppLocale($useLocalePdo, 1, [], ['enabled' => $both]);
same('useAppLocale: the signed-in account\'s language', 'sv', i18nLocale());
i18nUseAppLocale($useLocalePdo, 2, ['HTTP_ACCEPT_LANGUAGE' => 'sv'], ['enabled' => $both]);
same('useAppLocale: an account with no choice follows Accept-Language', 'sv', i18nLocale());
i18nUseAppLocale($useLocalePdo, null, [], ['enabled' => $both]);
same('useAppLocale: signed out, no signal, English', 'en', i18nLocale());
i18nUseAppLocale($useLocalePdo, 1, [], []);
same('useAppLocale: a missing i18n config means English only', 'en', i18nLocale());
i18nTranslatedPages($realPages);
i18nSetLocale('en');

// --- i18nAccountLocale() / proUserSetLocale() on SQLite -------------------------
$withCol = new PDO('sqlite::memory:');
$withCol->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$withCol->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, locale TEXT NULL DEFAULT NULL)');
$withCol->exec("INSERT INTO pro_users (id) VALUES (1), (2), (3)");
same('accountLocale: NULL is not chosen', null, i18nAccountLocale($withCol, 1));
same('accountLocale: a missing user is null', null, i18nAccountLocale($withCol, 99));
check('setLocale: stores sv', proUserSetLocale($withCol, 1, 'sv', false));
same('accountLocale: reads sv', 'sv', i18nAccountLocale($withCol, 1));
check('setLocale: replaces with en when not onlyIfEmpty', proUserSetLocale($withCol, 1, 'en', false));
same('accountLocale: reads en', 'en', i18nAccountLocale($withCol, 1));
proUserSetLocale($withCol, 1, 'sv', true);
same('setLocale: onlyIfEmpty leaves a chosen language alone', 'en', i18nAccountLocale($withCol, 1));
check('setLocale: onlyIfEmpty sets a NULL one', proUserSetLocale($withCol, 2, 'sv', true));
same('setLocale: onlyIfEmpty stored sv', 'sv', i18nAccountLocale($withCol, 2));
check('setLocale: an unsupported language is refused', proUserSetLocale($withCol, 3, 'de', false) === false);
check('setLocale: an empty language is refused', proUserSetLocale($withCol, 3, '', false) === false);
same('setLocale: nothing was stored for the refused ones', null, i18nAccountLocale($withCol, 3));
$withCol->exec("UPDATE pro_users SET locale = 'xx' WHERE id = 3");
same('accountLocale: an unsupported stored value is null', null, i18nAccountLocale($withCol, 3));

$noCol = new PDO('sqlite::memory:');
$noCol->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$noCol->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY)');
$noCol->exec('INSERT INTO pro_users (id) VALUES (1)');
$threwNoCol = false;
$readNoCol = 'unset';
$wroteNoCol = 'unset';
try {
    $readNoCol = i18nAccountLocale($noCol, 1);
    $wroteNoCol = proUserSetLocale($noCol, 1, 'sv', false);
} catch (Throwable $e) {
    $threwNoCol = true;
}
check('without the column: no exception', !$threwNoCol);
same('without the column: accountLocale is null', null, $readNoCol);
same('without the column: setLocale is false', false, $wroteNoCol);
$noTable = new PDO('sqlite::memory:');
$noTable->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
same('without the table: accountLocale is null', null, i18nAccountLocale($noTable, 1));
same('without the table: setLocale is false', false, proUserSetLocale($noTable, 1, 'sv', true));
i18nTranslatedPages(['@app' => ['en', 'sv']]);
i18nUseAppLocale($noCol, 1, ['HTTP_ACCEPT_LANGUAGE' => 'sv'], ['enabled' => $both]);
same('without the column: the page still resolves (Accept-Language)', 'sv', i18nLocale());
i18nTranslatedPages($realPages);
i18nSetLocale('en');

// --- i18nServiceError() --------------------------------------------------------
i18nSetLocale('en');
same('serviceError: a key is translated', 'Log in', i18nServiceError(['error' => 'x', 'error_key' => 'nav.login'], 'f'));
same('serviceError: a key with params', 'Mail Shield is operated by Acme.', i18nServiceError(['error' => 'x', 'error_key' => 'footer.trust', 'error_params' => ['company' => 'Acme']], 'f'));
same('serviceError: Swedish', 'Logga in', (function () {
    i18nSetLocale('sv');
    $r = i18nServiceError(['error_key' => 'nav.login'], 'f');
    i18nSetLocale('en');
    return $r;
})());
same('serviceError: no key gives the English error', 'Address not found', i18nServiceError(['error' => 'Address not found'], 'f'));
same('serviceError: an empty key gives the English error', 'Address not found', i18nServiceError(['error' => 'Address not found', 'error_key' => ''], 'f'));
same('serviceError: neither gives the fallback', 'Something went wrong', i18nServiceError([], 'Something went wrong'));
same('serviceError: a non-string key is ignored', 'e', i18nServiceError(['error' => 'e', 'error_key' => ['x']], 'f'));

// --- Scans: who calls the resolver and who reads Accept-Language ---------------
$appPages = ['inbox.php', 'pro.php', 'pro_profile_page.php', 'pro_contact.php', 'pro_logout.php', 'oauth_authorize.php'];
$endpoints = ['index.php', 'pro_auth.php', 'pro_profile.php'];
$publicAuth = ['register.php', 'pro_login.php'];
foreach (array_merge($appPages, $endpoints) as $f) {
    check("scan: {$f} calls i18nUseAppLocale(", strpos((string) file_get_contents($root . '/' . $f), 'i18nUseAppLocale(') !== false);
}
foreach ($endpoints as $f) {
    check("scan: {$f} calls it as an endpoint (\$isApi = true)", (bool) preg_match('/i18nUseAppLocale\([^;]*,\s*true\);/', (string) file_get_contents($root . '/' . $f)));
}
foreach ($appPages as $f) {
    check("scan: {$f} calls it as a page (no \$isApi)", !preg_match('/i18nUseAppLocale\([^;]*,\s*true\);/', (string) file_get_contents($root . '/' . $f)));
}
foreach ($publicAuth as $f) {
    check("scan: public {$f} does not call i18nUseAppLocale(", strpos((string) file_get_contents($root . '/' . $f), 'i18nUseAppLocale(') === false);
}
$indexSrc = (string) file_get_contents($root . '/index.php');
$callPos = strpos($indexSrc, 'i18nUseAppLocale(');
$postPos = strpos($indexSrc, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
check("scan: index.php's call is inside the action branch, after the POST check", $callPos !== false && $postPos !== false && $callPos > $postPos);
$adminPages = ['log_viewer.php', 'abuse_admin.php', 'voucher_admin.php', 'partials/admin_head.php', 'partials/admin_foot.php', 'partials/admin_tabs.php', 'client_agent_manage.php'];
foreach ($adminPages as $f) {
    check("scan: {$f} does not call i18nUseAppLocale(", strpos((string) file_get_contents($root . '/' . $f), 'i18nUseAppLocale(') === false);
}
// Accept-Language is read in i18nAppLocale() and nowhere else.
$acceptReaders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fileInfo) {
    $rel = substr($fileInfo->getPathname(), strlen($root) + 1);
    if (!preg_match('/\.php$/', $rel) || preg_match('#^(vendor|tests|\.git|node_modules)/#', $rel)) {
        continue;
    }
    if (strpos((string) file_get_contents($fileInfo->getPathname()), 'HTTP_ACCEPT_LANGUAGE') !== false) {
        $acceptReaders[] = $rel;
    }
}
same('scan: HTTP_ACCEPT_LANGUAGE is read only in i18n.php', ['i18n.php'], $acceptReaders);
$i18nSrc = (string) file_get_contents($root . '/i18n.php');
check('scan: in i18n.php only i18nAppLocale() reads it', substr_count($i18nSrc, 'HTTP_ACCEPT_LANGUAGE') === 1
    && (bool) preg_match('/function i18nAppLocale\(.*?HTTP_ACCEPT_LANGUAGE.*?\n    }\n/s', $i18nSrc));
foreach (['index.php', 'pricing.php', 'faq.php', 'temporary-email.php', 'partials/public_head.php', 'partials/public_nav.php', 'partials/public_footer.php'] as $f) {
    check("scan: public {$f} does not read Accept-Language", stripos((string) file_get_contents($root . '/' . $f), 'ACCEPT_LANGUAGE') === false);
}
check('scan: the language is never stored in a cookie by i18n.php', stripos($i18nSrc, 'setcookie') === false);

// --- Scans: the eight pages ----------------------------------------------------
foreach (array_merge($appPages, $publicAuth) as $f) {
    $src = (string) file_get_contents($root . '/' . $f);
    check("scan: {$f} includes partials/app_locale_js.php", strpos($src, "partials/app_locale_js.php'") !== false);
    check("scan: {$f} has no lang=\"en\" literal", strpos($src, 'lang="en"') === false);
    check("scan: {$f} sets <html lang> from i18nLocale()", strpos($src, '<html lang="<?php echo htmlspecialchars(i18nLocale(), ENT_QUOTES, \'UTF-8\'); ?>">') !== false);
}
foreach (array_merge($appPages, $publicAuth) as $f) {
    $src = (string) file_get_contents($root . '/' . $f);
    if (preg_match('#^([ \t]*)<script src="https://cdn\.jsdelivr\.net/npm/jquery@3\.7\.1/dist/jquery\.min\.js"></script>\n[ \t]*<\?php require __DIR__ \. \'/partials/app_locale_js\.php\'; \?>\n#m', $src)) {
        check("scan: {$f} includes it right after the jQuery tag", true);
    } else {
        check("scan: {$f} has no jQuery tag, so it includes it at the end of the body", strpos($src, 'jquery') === false);
    }
}
$jsPartial = (string) file_get_contents($root . '/partials/app_locale_js.php');
check('scan: app_locale_js.php sets window.msLocale from <html lang>', strpos($jsPartial, "window.msLocale = document.documentElement.lang || 'en'") !== false);
check('scan: app_locale_js.php sends X-MS-Locale with jQuery.ajaxSetup', strpos($jsPartial, "ajaxSetup({ headers: { 'X-MS-Locale': window.msLocale } })") !== false);
foreach (array_merge($appPages, $publicAuth) as $f) {
    $src = (string) file_get_contents($root . '/' . $f);
    preg_match_all('/(?<![\w>$:.-])fetch\(\s*([^)]*)/', $src, $fm);
    $own = array_filter($fm[1], static fn(string $arg): bool => !preg_match('#^[\'"`]\s*(https?:)?//#', $arg));
    foreach ($own as $arg) {
        check("scan: a fetch( in {$f} sends X-MS-Locale", strpos($src, "'X-MS-Locale': window.msLocale") !== false);
        break;
    }
}

// --- Scans: the migration ------------------------------------------------------
$migSrc = (string) file_get_contents($root . '/migrate_user_locale.php');
check('scan: migrate_user_locale.php adds pro_users.locale VARCHAR(8) NULL DEFAULT NULL', strpos($migSrc, 'ADD COLUMN locale VARCHAR(8) NULL DEFAULT NULL') !== false);
check('scan: migrate_user_locale.php is idempotent ([skip])', strpos($migSrc, "[skip] pro_users.locale: already exists") !== false);
check('scan: migrate_user_locale.php does not backfill', stripos($migSrc, 'UPDATE') === false);

// --- App nav: English fixtures, Swedish render ----------------------------------
$navScript = sys_get_temp_dir() . '/ms_i18n_render_nav_' . getmypid() . '.php';
file_put_contents($navScript, <<<'PHP'
<?php
// argv: root state locale enabled(csv). Rendered in its own process because
// partials/nav.php declares tm_nav_active() at the top level.
$root = $argv[1];
$state = $argv[2];
$locale = $argv[3];
$enabled = explode(',', $argv[4]);
define('TEMPMAIL_APP', true);
ini_set('session.save_path', sys_get_temp_dir());
ob_start();
require $root . '/i18n.php';
@session_start();
$_SERVER['SCRIPT_NAME'] = '/pro.php';
$GLOBALS['config'] = ['email' => ['base_url' => 'https://example.test/', 'domain' => 'example.test'], 'app' => ['version' => 'test'], 'i18n' => ['enabled' => $enabled]];
$config = $GLOBALS['config'];
$_SESSION = $state === 'signed_out' ? [] : ['pro_user_id' => 7, 'pro_user_email' => 'user@example.test'];
function proUserIsPro($id) { return true; }
function isAdminUser($id) { return $GLOBALS['navState'] === 'admin'; }
$navState = $state;
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE pending_profile_changes (user_id INT, used INT)');
if ($state === 'pending') {
    $pdo->exec('INSERT INTO pending_profile_changes VALUES (7,0),(7,0)');
}
i18nSetLocale($locale);
ob_clean();
require $root . '/partials/nav.php';
echo ob_get_clean();
PHP);
$renderNav = static function (string $state, string $locale, string $enabled) use ($navScript, $root): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($navScript) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($state) . ' ' . escapeshellarg($locale) . ' ' . escapeshellarg($enabled) . ' 2>&1';
    return (string) shell_exec($cmd);
};
foreach (['signed_out' => 'signed out', 'signed_in' => 'signed in', 'pending' => 'signed in with pending changes', 'admin' => 'an admin'] as $state => $label) {
    same("fixture: app nav, {$label}, English", (string) file_get_contents($fx . "app_nav.{$state}.en.html"), $renderNav($state, 'en', 'en'));
    same("fixture: app nav, {$label}, English with sv enabled but not chosen", (string) file_get_contents($fx . "app_nav.{$state}.en.html"), $renderNav($state, 'en', 'en,sv'));
    $svNav = $renderNav($state, 'sv', 'en,sv');
    $svNavNoScript = preg_replace('#<script\b.*?</script>#s', '', $svNav);
    check("sv render: app nav, {$label}: no raw appnav. key", $svNav !== '' && strpos($svNavNoScript, 'appnav.') === false);
    check("sv render: app nav, {$label}: no PHP error in the output", !preg_match('/Warning|Notice|Fatal|Deprecated/', $svNav));
}
$svOut = $renderNav('signed_out', 'sv', 'en,sv');
check('sv app nav, signed out: Swedish text', strpos($svOut, '>Skapa konto</a>') !== false && strpos($svOut, 'aria-label="Meny"') !== false && strpos($svOut, 'aria-label="Applikation"') !== false);
check('sv app nav, signed out: the logo points at the Swedish landing page', strpos($svOut, 'href="/sv/"') !== false);
check('sv app nav, signed out: the FAQ goes through i18nUrl()', strpos($svOut, 'href="/sv/faq.php"') !== false);
check('sv app nav, signed out: the blog goes through i18nUrl()', strpos($svOut, 'href="/sv/blog.php"') !== false);
$svIn = $renderNav('pending', 'sv', 'en,sv');
check('sv app nav, signed in: Swedish text', strpos($svIn, '>Inkorg</a>') !== false && strpos($svIn, '>Logga ut</a>') !== false && strpos($svIn, 'Inloggad som') !== false);
check('sv app nav, pending changes: the hidden text is Swedish', strpos($svIn, 'väntande ändringar') !== false);
check('sv app nav, signed in: the account type is Swedish-aware (Pro)', strpos($svIn, 'id="proExpiryLine" class="ms-appnav__status">Pro</span>') !== false);
check('sv app nav: #proExpiryLine, #hamburgerBtn and #navbarMenu survive', strpos($svIn, 'id="proExpiryLine"') !== false && strpos($svIn, 'id="hamburgerBtn"') !== false && strpos($svIn, 'id="navbarMenu"') !== false);
check('sv app nav, signed in: the active link keeps navbar-link active', strpos($svIn, 'navbar-link active') !== false);
@unlink($navScript);

// --- Sign-in area (epic #428, step 2/8): register, sign-in, sign-out, pro_auth.php --------
// The real pages and pro_auth.php run as subprocesses against a stub config.php on SQLite
// (tests/lib/auth_probe.php). The English fixtures were rendered from the code as it was
// before these pages went through t().
require_once $root . '/tests/lib/auth_probe.php';
require_once $root . '/TwoFactorAuth.php'; // base32Decode() and the trusted-device cookie name
$authProbe = ms_auth_probe_build($root);

/**
 * English HTML compared with a fixture: the stylesheet cache-busters, the year and the
 * language JSON element are neutralised, inline scripts are compared apart (their
 * strings are checked key by key below), entities are decoded so a catalog value holding
 * an em dash equals the old &mdash;, and the one relative link that became root-relative
 * (register.php's "sign in" link) is aligned.
 */
function authNorm(string $html): string
{
    $html = preg_replace('/\?v=\d+/', '?v=0', $html);
    $html = str_replace('&copy; ' . date('Y'), '&copy; {year}', $html);
    $html = preg_replace('#<script type="application/json" id="ms-i18n">.*?</script>\n?#s', '', $html);
    $html = preg_replace('#<script>.*?</script>#s', '<script></script>', $html);
    // With Swedish switched on the page is offered in two languages, so it gains its hreflang
    // alternates and og:locale (as every public page does); they are checked apart.
    $html = preg_replace('#^ *<link rel="alternate" hreflang="[^"]*" href="[^"]*">\n#m', '', $html);
    $html = preg_replace('#^ *<meta property="og:locale" content="[^"]*">\n#m', '', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return str_replace('href="pro_login.php"', 'href="/pro_login.php"', $html);
}

/** The visible text of a page: scripts, tags and entities removed. */
function authVisible(string $html): string
{
    return html_entity_decode(strip_tags(preg_replace('#<script\b.*?</script>#s', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$authSrc = static fn(string $file): string => (string) file_get_contents($root . '/' . $file);

// English pages, English only and with Swedish enabled but not chosen.
foreach (ms_auth_page_cases() as $name => [$script, $uri, $get, $trial]) {
    $fixture = authNorm((string) file_get_contents($fx . $name . '.en.html'));
    foreach (['en' => 'English only', 'en,sv' => 'sv enabled, English URL'] as $locales => $label) {
        $r = ms_auth_page($authProbe, $script, $uri, $get, $trial, $locales);
        check("auth page {$name} ({$label}): rendered without PHP errors", $r['html'] !== '' && $r['err'] === '', $r['err']);
        same("fixture: {$name}, {$label}", $fixture, authNorm($r['html']));
        if ($locales === 'en' && $script !== 'pro_logout.php') {
            check("auth page {$name}: English only emits no hreflang and no og:locale", strpos($r['html'], 'hreflang') === false && strpos($r['html'], 'og:locale') === false);
        }
    }
}

// The inline scripts' English strings are unchanged: every msT() fallback is the catalog
// value and appears in the fixture's script exactly as it was written there.
foreach (['register.php' => 'register.regular.trial', 'pro_login.php' => 'pro_login.plain.trial'] as $file => $fixtureName) {
    $source = $authSrc($file);
    $oldPage = (string) file_get_contents($fx . $fixtureName . '.en.html');
    preg_match_all("/msT\\('([^']+)',\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $m, PREG_SET_ORDER);
    check("{$file}: the scripts use msT()", count($m) >= 8, (string) count($m));
    foreach ($m as [, $key, $fallback]) {
        $fallback = stripslashes($fallback);
        same("{$file}: msT fallback for {$key} is the English catalog value", $en[$key] ?? null, $fallback);
        $old = $key === 'auth.register.js.too_short' ? "Password must be at least ' + MIN_PASSWORD_LENGTH + ' characters" : $fallback;
        check("{$file}: {$key} is in the unmodified script", strpos($oldPage, $old) !== false || strpos($oldPage, htmlspecialchars($old, ENT_QUOTES)) !== false, $old);
    }
}

// Swedish pages.
$svPages = [
    'register' => ['register.php', '/sv/register.php', ['plan' => 'regular'], 'Skapa din inkorg'],
    'register pro' => ['register.php', '/sv/register.php', ['plan' => 'pro'], 'Pro (värdekod)'],
    'pro_login' => ['pro_login.php', '/sv/pro_login.php', [], 'Förbli inloggad på den här enheten'],
    'pro_login error' => ['pro_login.php', '/sv/pro_login.php', ['token' => 'not-a-valid-token'], 'Ogiltig eller utgången länk.'],
];
foreach ($svPages as $label => [$script, $uri, $get, $needle]) {
    $r = ms_auth_page($authProbe, $script, $uri, $get, 60, 'en,sv');
    $html = $r['html'];
    $page = '/' . $script;
    check("sv page {$label}: rendered without PHP errors", $html !== '' && $r['err'] === '', $r['err']);
    check("sv page {$label}: lang=\"sv\"", strpos($html, '<html lang="sv">') !== false);
    check("sv page {$label}: Swedish text", strpos(authVisible($html), $needle) !== false, $needle);
    check("sv page {$label}: the /sv/ canonical", strpos($html, '<link rel="canonical" href="https://example.test/sv' . $page . '">') !== false);
    check("sv page {$label}: hreflang en, sv and x-default", strpos($html, 'hreflang="en" href="https://example.test' . $page . '"') !== false
        && strpos($html, 'hreflang="sv" href="https://example.test/sv' . $page . '"') !== false && strpos($html, 'hreflang="x-default"') !== false);
    check("sv page {$label}: og:locale sv_SE", strpos($html, '<meta property="og:locale" content="sv_SE">') !== false);
    check("sv page {$label}: still index, follow", strpos($html, '<meta name="robots" content="index, follow">') !== false);
    check("sv page {$label}: no raw key", !preg_match('/\bauth\.(register|login|twofa|logout)\.[a-z_.]+\b/', preg_replace('#<script\b.*?</script>#s', '', $html)));
    check("sv page {$label}: stylesheets and the endpoint are root-relative", strpos($html, 'href="assets/') === false && strpos($html, 'href="/assets/css/mailshield.css') !== false
        && strpos($html, '"\/pro_auth.php"') !== false);
    check("sv page {$label}: the language JSON carries the Swedish strings", preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $html, $jm) === 1
        && (json_decode($jm[1], true)['auth.' . ($script === 'register.php' ? 'register.js.network' : 'login.js.network')] ?? '') === 'Nätverksfel');
    check("sv page {$label}: links to public pages carry /sv/", strpos($html, 'href="/sv/"') !== false);
}
$svReg = ms_auth_page($authProbe, 'register.php', '/sv/register.php', ['plan' => 'pro'], 60, 'en,sv')['html'];
check('sv register: the pricing link carries /sv/', strpos($svReg, 'href="/sv/pricing.php"') !== false);
check('sv register: the sign-in link carries /sv/', strpos($svReg, 'href="/sv/pro_login.php"') !== false);
check('sv register: trial days are rendered from $config', strpos(authVisible($svReg), 'De första 60 dagarna får du Pro') !== false);
$svRegNo = ms_auth_page($authProbe, 'register.php', '/sv/register.php', [], 0, 'en,sv')['html'];
check('sv register, no trial: the pre-trial wording', strpos(authVisible($svRegNo), 'En Timed-adress i taget.') !== false && strpos($svRegNo, ' 0 dagar') === false);
$svLogin = ms_auth_page($authProbe, 'pro_login.php', '/sv/pro_login.php', [], 60, 'en,sv')['html'];
check('sv sign-in: the 2FA step is Swedish', strpos(authVisible($svLogin), 'Öppna din autentiseringsapp') !== false && strpos(authVisible($svLogin), 'Autentiseringskod') !== false);
check('sv sign-in: the "lost authenticator" link keeps its id inside the translated sentence', preg_match('#<a href="\#" id="lostAuthenticatorLink">Har du tappat bort din autentiserare\?#u', $svLogin) === 1);
check('sv sign-in: the create-inbox link carries /sv/ and its trial days', strpos($svLogin, 'href="/sv/register.php?plan=regular"') !== false && strpos(authVisible($svLogin), 'den börjar med 60 dagar Pro') !== false);
check('sv sign-in: every element id the script binds to survives', count(array_filter(['loginModeToggle', 'staySignedIn', 'loginRequestForm', 'passwordLoginForm', 'twoFactorForm', 'twoFactorCode', 'useRecoveryCodeLink', 'lostAuthenticatorLink', 'cancel2faLink'], fn($id) => strpos($svLogin, 'id="' . $id . '"') !== false)) === 9);
// Sign-out: an app page, so its language is the account's / the browser's, and only once '@app' lists Swedish.
$logout = static function (array $server, bool $appSv) use ($authProbe): string {
    [$db] = ms_auth_probe_db($authProbe);
    $run = ms_auth_probe_run($authProbe, 'pro_logout.php', ['method' => 'GET', 'uri' => '/pro_logout.php', 'locales' => 'en,sv', 'server' => $server, 'app_sv' => $appSv], $db);
    @unlink($db);
    return $run['out'];
};
$svLogout = $logout(['HTTP_ACCEPT_LANGUAGE' => 'sv-SE,sv;q=0.9'], true);
check('sv logout (app on, browser Swedish): lang="sv" and Swedish text', strpos($svLogout, '<html lang="sv">') !== false && strpos($svLogout, '<title>Utloggad</title>') !== false && strpos($svLogout, 'klicka här</a>') !== false);
check('sv logout: sends the visitor to the Swedish landing page', strpos($svLogout, 'url=/sv/') !== false && strpos($svLogout, 'href="/sv/"') !== false && strpos($svLogout, 'replace("\/sv\/")') !== false);
check('sv logout: no raw key', strpos($svLogout, 'auth.logout.') === false);
$enLogout = $logout(['HTTP_ACCEPT_LANGUAGE' => 'sv-SE,sv;q=0.9'], false);
check('logout with the app gate closed stays English whatever the browser says', strpos($enLogout, '<title>Logged out</title>') !== false && strpos($enLogout, 'href="index.php"') !== false);
// A /sv/ URL while Swedish is switched off answers a redirect, not a page.
check('/sv/register.php with only English enabled renders no page (302)', ms_auth_page($authProbe, 'register.php', '/sv/register.php', [], 60, 'en')['html'] === '');
check('/sv/pro_login.php with only English enabled renders no page (302)', ms_auth_page($authProbe, 'pro_login.php', '/sv/pro_login.php', [], 60, 'en')['html'] === '');

// pro_auth.php answers: English fixtures, then the Swedish string per case.
$authSvKey = [
    'login_link.invalid_email' => 'auth.error.invalid_email',
    'voucher.redeemed' => 'auth.message.voucher_redeemed',
    'voucher.invalid_email' => 'voucher.error.invalid_email',
    'voucher.invalid_code_format' => 'voucher.error.invalid_code_format',
    'voucher.invalid_or_expired_code' => 'voucher.error.invalid_or_expired_code',
    'voucher.code_not_active' => 'voucher.error.code_not_active',
    'voucher.code_expired' => 'voucher.error.code_expired',
    'voucher.code_fully_redeemed' => 'voucher.error.code_fully_redeemed',
    'voucher.domain_not_allowed' => 'voucher.error.domain_not_allowed',
    'voucher.already_redeemed' => 'voucher.error.already_redeemed',
    'voucher.already_lifetime' => 'voucher.error.already_lifetime',
    'voucher.redemption_failed' => 'voucher.error.redemption_failed',
    'register.suspicious' => 'auth.error.invalid_request',
    'register.invalid_email' => 'auth.error.invalid_email',
    'register.passwords_mismatch' => 'auth.error.passwords_mismatch',
    'register.password_short' => 'auth.error.password_short',
    'register.password_long' => 'auth.error.password_long',
    'register.voucher_required' => 'auth.error.voucher_required',
    'register.unavailable' => 'auth.error.registration_unavailable',
    'register.check_email' => 'auth.message.check_email',
    'register.pro_bad_code' => 'voucher.error.invalid_or_expired_code',
    'password.bad_origin' => 'auth.error.bad_origin',
    'password.invalid_request' => 'auth.error.invalid_request',
    'password.throttled_ip' => 'auth.error.throttled_ip',
    'password.throttled_account' => 'auth.error.throttled_account',
    'password.incorrect_unknown' => 'auth.error.bad_credentials',
    'password.incorrect_no_password' => 'auth.error.bad_credentials',
    'password.incorrect_wrong' => 'auth.error.bad_credentials',
    'password.incorrect_unverified' => 'auth.error.bad_credentials',
    'password.suspended' => 'auth.error.suspended',
    'twofa.bad_origin' => 'auth.error.bad_origin',
    'twofa.session_expired' => 'auth.error.session_expired',
    'twofa.suspended' => 'auth.error.suspended',
    'twofa.blocked' => 'auth.error.incorrect_code',
    'twofa.incorrect' => 'auth.error.incorrect_code',
    'twofa.cancel_bad_origin' => 'auth.error.bad_origin',
    'fallback.no_action' => 'auth.error.unknown_action',
];
$authCases = ms_auth_json_cases();
same('pro_auth answers: every case has its Swedish key', array_keys($authCases), array_keys($authSvKey));
$authBodies = ['en' => [], 'sv' => []];
foreach ($authCases as $name => $case) {
    $fixtureBody = trim((string) file_get_contents($fx . 'pro_auth.' . $name . '.en.json'));
    $r = ms_auth_json_run($authProbe, $case, null);
    same("pro_auth {$name}: English answer equals its fixture", $fixtureBody, $r['body']);
    check("pro_auth {$name}: no PHP error output", $r['run']['err'] === '', $r['run']['err']);
    $rEnHeader = ms_auth_json_run($authProbe, $case, 'en', 'en,sv');
    same("pro_auth {$name}: X-MS-Locale en is English", $fixtureBody, $rEnHeader['body']);
    $rOff = ms_auth_json_run($authProbe, $case, 'sv', 'en');
    same("pro_auth {$name}: X-MS-Locale sv while Swedish is off is English", $fixtureBody, $rOff['body']);
    $rSv = ms_auth_json_run($authProbe, $case, 'sv', 'en,sv');
    $expected = json_decode($fixtureBody, true);
    $svText = $sv[$authSvKey[$name]] ?? null;
    $field = isset($expected['error']) ? 'error' : 'message';
    $expected[$field] = $svText;
    same("pro_auth {$name}: X-MS-Locale sv carries the Swedish string", json_encode($expected), $rSv['body']);
    check("pro_auth {$name}: the Swedish string is not the English one", $name === 'fallback.no_action' || $svText !== ($en[$authSvKey[$name]] ?? null));
    $authBodies['en'][$name] = $r['body'];
    $authBodies['sv'][$name] = $rSv['body'];
}
foreach (['en', 'sv'] as $lang) {
    $same = array_unique(array_intersect_key($authBodies[$lang], array_flip(['password.incorrect_unknown', 'password.incorrect_no_password', 'password.incorrect_wrong', 'password.incorrect_unverified'])));
    check("pro_auth: 'Incorrect email or password' is the same answer in all four places ({$lang})", count($same) === 1);
}
same('pro_auth: it is one key, used four times', 4, substr_count($authSrc('pro_auth.php'), "t('auth.error.bad_credentials')"));
check('pro_auth: no hardcoded error or message string is left in a JSON answer', !preg_match("/'(error|message)' => '/", $authSrc('pro_auth.php')));

// voucherRedemptionErrorKey(): a key for every code, English text equal to the old table.
[$db] = ms_auth_probe_db($authProbe);
$vk = json_decode(ms_auth_probe_run($authProbe, 'voucher_keys.php', [], $db)['out'], true);
check('voucherRedemptionErrorKey: all ten codes, null and an unknown one were reported', is_array($vk) && count($vk) === 12, json_encode($vk));
foreach ((array) $vk as $code => [$key, $message]) {
    same("voucherRedemptionErrorKey({$code}): the English text is voucherRedemptionErrorMessage()", $message, $en[$key] ?? null);
    check("voucherRedemptionErrorKey({$code}): translated to Swedish", isset($sv[$key]));
}
same('voucherRedemptionErrorKey(null) is redemption_failed', 'voucher.error.redemption_failed', $vk['@null'][0] ?? null);
same('voucherRedemptionErrorKey(unknown) is redemption_failed', 'voucher.error.redemption_failed', $vk['@unknown'][0] ?? null);
same('voucherRedemptionErrorMessage() still answers English', 'Your account already has lifetime Pro', $vk['already_lifetime'][1] ?? null);
@unlink($db);

// Recording the language (epic decision 6).
$localeOf = static function (string $db, string $email): ?string {
    $pdo = new PDO('sqlite:' . $db);
    $stmt = $pdo->prepare('SELECT locale FROM pro_users WHERE email = ?');
    $stmt->execute([$email]);
    $v = $stmt->fetchColumn();
    return $v === false || $v === null ? null : (string) $v;
};
$authRequest = static function (string $script, array $request, string $db, ?string $header, bool $keys = true) use ($authProbe): array {
    $request += ['locales' => 'en,sv', 'uri' => '/' . $script, 'trial' => 60];
    if ($header !== null) {
        $request['server'] = ($request['server'] ?? []) + ['HTTP_X_MS_LOCALE' => $header];
    }
    return ms_auth_probe_run($authProbe, $script, $request, $db, $keys);
};
$regPost = ['action' => 'register_account', 'plan' => 'regular', 'email' => 'newbie@example.com', 'password' => 'long-enough-1', 'password_confirm' => 'long-enough-1'];
[$db] = ms_auth_probe_db($authProbe);
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => $regPost], $db, 'sv');
same('locale: an account registered with X-MS-Locale sv has locale sv', 'sv', $localeOf($db, 'newbie@example.com'));
[$db] = ms_auth_probe_db($authProbe);
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => $regPost], $db, null);
same('locale: one registered without the header has NULL', null, $localeOf($db, 'newbie@example.com'));
[$db] = ms_auth_probe_db($authProbe);
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => $regPost], $db, 'en');
same('locale: one registered with X-MS-Locale en has NULL (English is never recorded)', null, $localeOf($db, 'newbie@example.com'));
[$db] = ms_auth_probe_db($authProbe, function ($pdo) { $pdo->exec("INSERT INTO vouchers (code, duration_days, max_uses) VALUES ('GOODCODE', 30, 5)"); });
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => ['action' => 'register_account', 'plan' => 'pro', 'code' => 'GOODCODE'] + $regPost], $db, 'sv');
same('locale: an account created by a voucher from a Swedish page has locale sv', 'sv', $localeOf($db, 'newbie@example.com'));
[$db] = ms_auth_probe_db($authProbe, function ($pdo) { $pdo->exec("INSERT INTO vouchers (code, duration_days, max_uses) VALUES ('GOODCODE', 30, 5)"); });
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => ['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'GOODCODE']], $db, 'sv');
same('locale: redeem_voucher creating an account from a Swedish page has locale sv', 'sv', $localeOf($db, 'fresh@example.com'));

$loginPost = ['action' => 'password_login', 'email' => 'alice@example.com', 'password' => 'correct-horse'];
[$db] = ms_auth_probe_db($authProbe);
$r = $authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost], $db, 'sv');
check('locale: the password sign-in itself succeeded', strpos($r['out'], '"success":true') !== false, $r['out']);
same('locale: a password sign-in with sv sets sv on a NULL account', 'sv', $localeOf($db, 'alice@example.com'));
[$db] = ms_auth_probe_db($authProbe, fn($pdo, $ids) => $pdo->exec("UPDATE pro_users SET locale = 'en' WHERE id = {$ids['alice']}"));
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost], $db, 'sv');
same('locale: a sign-in with sv leaves an en account at en', 'en', $localeOf($db, 'alice@example.com'));
[$db] = ms_auth_probe_db($authProbe, fn($pdo, $ids) => $pdo->exec("UPDATE pro_users SET locale = 'sv' WHERE id = {$ids['alice']}"));
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost], $db, 'en');
same('locale: an English page never overwrites a stored sv', 'sv', $localeOf($db, 'alice@example.com'));
[$db] = ms_auth_probe_db($authProbe);
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost], $db, null);
same('locale: an English sign-in leaves NULL as NULL', null, $localeOf($db, 'alice@example.com'));
[$db] = ms_auth_probe_db($authProbe);
$authRequest('pro_auth.php', ['method' => 'POST', 'post' => ['password' => 'wrong-horse'] + $loginPost], $db, 'sv');
same('locale: a failed sign-in records nothing', null, $localeOf($db, 'alice@example.com'));

// Password + 2FA and the trusted device, with a real TOTP secret.
$totpSecret = 'JBSWY3DPEHPK3PXP';
$totpEncrypt = static function (string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', base64_decode(MS_AUTH_TOTP_KEY, true), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $cipher);
};
$totpNow = static function (string $secret): string {
    $key = TwoFactorAuth::base32Decode($secret);
    $hash = hash_hmac('sha1', pack('N*', 0, intdiv(time(), 30)), $key, true);
    $o = ord($hash[19]) & 0xf;
    $bin = ((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o + 1]) & 0xff) << 16) | ((ord($hash[$o + 2]) & 0xff) << 8) | (ord($hash[$o + 3]) & 0xff);
    return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
};
$withTotp = function ($pdo, $ids) use ($totpEncrypt, $totpSecret) {
    $pdo->prepare("INSERT INTO pro_user_totp (user_id, secret_enc, status) VALUES (?, ?, 'active')")->execute([$ids['alice'], $totpEncrypt($totpSecret)]);
};
[$db] = ms_auth_probe_db($authProbe, $withTotp);
$sid = 'probesess' . bin2hex(random_bytes(4));
$r1 = $authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost, 'session_id' => $sid], $db, 'sv');
check('locale/2FA: the password step asks for the code', strpos($r1['out'], '"requires_2fa":true') !== false, $r1['out']);
same('locale/2FA: nothing is recorded before the code is verified', null, $localeOf($db, 'alice@example.com'));
$r2 = $authRequest('pro_auth.php', ['method' => 'POST', 'post' => ['action' => 'verify_2fa', 'code' => $totpNow($totpSecret)], 'session_id' => $sid], $db, 'sv');
check('locale/2FA: the code step succeeded', strpos($r2['out'], '"success":true') !== false, $r2['out'] . $r2['err']);
same('locale/2FA: password + 2FA with sv sets sv on a NULL account', 'sv', $localeOf($db, 'alice@example.com'));
[$db, $ids] = ms_auth_probe_db($authProbe, $withTotp);
$selector = bin2hex(random_bytes(16));
$validator = bin2hex(random_bytes(32));
(new PDO('sqlite:' . $db))->prepare('INSERT INTO pro_trusted_devices (user_id, selector, validator_hash, label, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$ids['alice'], $selector, hash('sha256', $validator), 'probe', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 86400)]);
$r = $authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost, 'cookie' => [TwoFactorAuth::TRUSTED_DEVICE_COOKIE => $selector . '.' . $validator]], $db, 'sv');
check('locale/trusted device: the sign-in skipped the challenge', strpos($r['out'], '"success":true') !== false && strpos($r['out'], 'requires_2fa') === false, $r['out']);
same('locale/trusted device: a trusted-device sign-in with sv sets sv on a NULL account', 'sv', $localeOf($db, 'alice@example.com'));

// Magic link: the GET in pro_auth.php and the branch in pro_login.php.
$magicToken = static function (string $db, int $userId) use ($authProbe): string {
    return trim(ms_auth_probe_run($authProbe, 'mk_token.php', [], $db, true, [], [(string) $userId])['out']);
};
[$db, $ids] = ms_auth_probe_db($authProbe);
$token = $magicToken($db, $ids['alice']);
check('locale/magic link: a token was minted', preg_match('/^[A-Za-z0-9]{20,}$/', $token) === 1, $token);
$authRequest('pro_auth.php', ['method' => 'GET', 'get' => ['token' => $token]], $db, 'sv');
same('locale/magic link (pro_auth.php): sv sets sv on a NULL account', 'sv', $localeOf($db, 'alice@example.com'));
[$db, $ids] = ms_auth_probe_db($authProbe);
$token = $magicToken($db, $ids['alice']);
$authRequest('pro_login.php', ['method' => 'GET', 'get' => ['token' => $token], 'uri' => '/sv/pro_login.php'], $db, null);
same('locale/magic link (pro_login.php, /sv/ URL): sets sv on a NULL account', 'sv', $localeOf($db, 'alice@example.com'));
[$db, $ids] = ms_auth_probe_db($authProbe);
$token = $magicToken($db, $ids['alice']);
$authRequest('pro_login.php', ['method' => 'GET', 'get' => ['token' => $token], 'uri' => '/pro_login.php'], $db, null);
same('locale/magic link (pro_login.php, English URL): leaves NULL as NULL', null, $localeOf($db, 'alice@example.com'));
[$db, $ids] = ms_auth_probe_db($authProbe, fn($pdo, $i) => $pdo->exec("UPDATE pro_users SET locale = 'en' WHERE id = {$i['alice']}"));
$token = $magicToken($db, $ids['alice']);
$authRequest('pro_login.php', ['method' => 'GET', 'get' => ['token' => $token], 'uri' => '/sv/pro_login.php'], $db, null);
same('locale/magic link (pro_login.php): an en account stays en', 'en', $localeOf($db, 'alice@example.com'));

// No column: every path still works (fail-open) — the answer is the same, nothing throws.
[$db] = ms_auth_probe_db($authProbe);
(new PDO('sqlite:' . $db))->exec('ALTER TABLE pro_users DROP COLUMN locale');
$r = $authRequest('pro_auth.php', ['method' => 'POST', 'post' => $loginPost], $db, 'sv');
check('locale: without the pro_users.locale column a sign-in still succeeds', strpos($r['out'], '"success":true') !== false && $r['err'] === '', $r['out'] . $r['err']);
check('locale: the log holds no address next to a locale', strpos($r['log'], 'alice@example.com') === false);

// Scans.
$authPhp = $authSrc('pro_auth.php');
same('scan: proAuthRecordLocale() follows every INSERT INTO pro_users (3) and every sign-in path (4)', 7, substr_count($authPhp, 'proAuthRecordLocale(') - 1);
check('scan: proAuthRecordLocale() records only a non-default language, only onlyIfEmpty, never en', strpos($authPhp, 'proUserSetLocale($pdo, (int) $userId, $locale, true)') !== false
    && strpos($authPhp, '$locale === i18nDefaultLocale()') !== false && strpos($authPhp, "proUserSetLocale(\$pdo, (int) \$userId, 'en'") === false);
check('scan: pro_login.php records the language at its magic-link sign-in', strpos($authSrc('pro_login.php'), 'proAuthRecordLocale($result[\'user_id\']);') !== false);
foreach (['register.php', 'pro_login.php', 'pro_logout.php'] as $page) {
    $src = $authSrc($page);
    check("scan: {$page} has no <html lang=\"en\">", strpos($src, 'lang="en"') === false);
}
foreach (['register.php', 'pro_login.php'] as $page) {
    $src = $authSrc($page);
    check("scan: {$page} requires i18n_head.php and i18n_js.php", strpos($src, "partials/i18n_head.php") !== false && strpos($src, "partials/i18n_js.php") !== false);
    check("scan: {$page} builds its canonical with i18nUrl()", strpos($src, '$msOgUrl    = $msOgOrigin . i18nUrl($msPath);') !== false);
    check("scan: {$page} posts to a root-relative endpoint under /sv/", strpos($src, "\$msAssets . 'pro_auth.php'") !== false && strpos($src, "\$.post('pro_auth.php'") === false);
}
check('scan: the sign-in area logs no locale beside an address', !preg_match('/logMessage\([^;]*locale[^;]*email/i', $authPhp));
ms_auth_probe_remove($authProbe);

// --- Inbox actions (epic #428, step 3/8, part A) --------------------------------
// index.php's JSON actions: every answer carrying an `error` or a `message` goes through
// the catalog (t('app.error.*') / t('app.message.*'), or i18nServiceError() for a
// mailbox_service.php result). The English fixtures were rendered from the code as it
// was; Swedish is asked for with X-MS-Locale (and Accept-Language) once '@app' lists it.
require_once $root . '/tests/lib/app_actions_probe.php';
$aa = ms_aa_build($root);
$aaParams = ['max' => 10, 'count' => 3, 'reason' => 'boom'];
// The Swedish text of the catalog entry whose English text is $text.
$aaSwedish = function (string $text) use ($en, $aaParams): ?string {
    foreach ($en as $key => $value) {
        if (strncmp($key, 'app.error.', 10) !== 0 && strncmp($key, 'app.message.', 12) !== 0 && strncmp($key, 'service.mailbox.', 16) !== 0) {
            continue;
        }
        if (is_string($value) && t($key, $aaParams, 'en') === $text) {
            return t($key, $aaParams, 'sv');
        }
    }
    return null;
};
foreach (array_keys(ms_aa_cases()) as $aaName) {
    $fixture = (string) file_get_contents($fx . "app_actions.{$aaName}.en.json");
    $r = ms_aa_run($aa, $aaName);
    same("actions/{$aaName}: the English answer equals its fixture", trim($fixture), $r['body']);
    check("actions/{$aaName}: no PHP error", !preg_match('/^PHP (Warning|Notice|Fatal|Parse|Deprecated)/m', (string) $r['res']['stderr']), (string) $r['res']['stderr']);
    $enAnswer = json_decode($fixture, true);
    $rSv = ms_aa_run($aa, $aaName, 'sv');
    $svAnswer = $rSv['json'];
    check("actions/{$aaName}: X-MS-Locale sv answers JSON", is_array($svAnswer), $rSv['body']);
    if (!is_array($svAnswer) || !is_array($enAnswer)) {
        continue;
    }
    $text = $enAnswer['error'] ?? $enAnswer['message'] ?? null;
    $expected = is_string($text) ? $aaSwedish($text) : null;
    check("actions/{$aaName}: the English text has a catalog key", $expected !== null, (string) $text);
    same("actions/{$aaName}: Swedish with X-MS-Locale: sv", $expected, $svAnswer['error'] ?? $svAnswer['message'] ?? null);
    unset($enAnswer['error'], $enAnswer['message'], $svAnswer['error'], $svAnswer['message']);
    same("actions/{$aaName}: the rest of the answer is language independent", $enAnswer, $svAnswer);
}
$r = ms_aa_run($aa, 'get_stats.not_signed_in', null, 'sv-SE,sv;q=0.9');
same('actions: a signed-out visitor gets Swedish from Accept-Language', 'Du är inte inloggad', $r['json']['error'] ?? null);
$r = ms_aa_run($aa, 'create_personal.cap', 'sv');
same('actions: the cap message carries its number in Swedish', 'Högst 10 Sticky-adresser tillåts', $r['json']['error'] ?? null);
$r = ms_aa_run($aa, 'create_personal.cap', 'en');
same('actions: X-MS-Locale en answers English', 'Maximum of 10 sticky addresses allowed', $r['json']['error'] ?? null);

// The service itself: a real mailboxCreateSticky() that hits the cap, on SQLite.
file_put_contents($aa['root'] . '/svc_cap.php', '<?php chdir(__DIR__); require __DIR__ . "/config.php"; require __DIR__ . "/mailbox_service.php";'
    . ' echo json_encode(mailboxCreateSticky($pdo, (int) $argv[1], "onemore", "127.0.0.1"));');
$proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', $aa['root'] . '/svc_cap.php', (string) $aa['ids']['capper']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $aa['root'],
    ['PROBE_SQLITE' => ms_test_probe_sqlite($aa['root']), 'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]);
$svcOut = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
$cap = json_decode(trim($svcOut), true) ?: [];
same('service: the cap keeps its English error', 'Maximum of 10 sticky addresses allowed', $cap['error'] ?? null);
same('service: the cap carries its key', 'service.mailbox.sticky_cap', $cap['error_key'] ?? null);
same('service: the cap carries its params', ['max' => 10], $cap['error_params'] ?? null);
same('service: t(error_key, error_params, en) equals the English error', $cap['error'] ?? null, t((string) ($cap['error_key'] ?? ''), (array) ($cap['error_params'] ?? []), 'en'));
i18nSetLocale('sv');
same('service: i18nServiceError() translates the cap', 'Högst 10 Sticky-adresser tillåts', i18nServiceError($cap, 'x'));
i18nSetLocale('en');

// Scan: every returned `'error' => '<literal>'` in mailbox_service.php has an error_key whose English text is that literal.
$svcLines = file($root . '/mailbox_service.php', FILE_IGNORE_NEW_LINES) ?: [];
$svcReturns = 0;
foreach ($svcLines as $n => $line) {
    if (strpos($line, "'ok' => false") === false || strpos($line, "'error' =>") === false || strpos($line, 'logMessage') !== false || ltrim($line)[0] === '*') {
        continue;
    }
    $svcReturns++;
    $label = 'scan: mailbox_service.php line ' . ($n + 1);
    if (!preg_match("/'error_key' => '(service\.mailbox\.[a-z_]+)'/", $line, $km)) {
        check($label . ' has an error_key', false, trim($line));
        continue;
    }
    check($label . ' has an error_key that exists in lang/en.php', isset($en[$km[1]]), $km[1]);
    if (preg_match("/'error' => '([^']*)'(?:,|\])/", $line, $lm)) {
        same($label . ': t(error_key, [], en) equals the literal', $lm[1], t($km[1], [], 'en'));
    } else {
        check($label . ' (a composed message) carries error_params', strpos($line, "'error_params' =>") !== false, trim($line));
    }
}
check('scan: mailbox_service.php returns 28 user-facing errors', $svcReturns === 28, (string) $svcReturns);

// Scan: no literal user-facing text left in index.php's action branch.
$indexSrc = (string) file_get_contents($root . '/index.php');
$actionBranch = substr($indexSrc, (int) strpos($indexSrc, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')"));
$actionBranch = substr($actionBranch, 0, (int) strpos($actionBranch, '// Shared inbox links used to be read'));
check("scan: index.php's action branch has no literal 'error' => '...'", !preg_match("/'error' => ['\"]/", preg_replace('/logMessage\([^;]*;/s', '', $actionBranch)));
check("scan: index.php's action branch has no literal throw new Exception('...')", !preg_match("/throw new Exception\(['\"]/", $actionBranch));
check("scan: index.php's action branch has no literal 'message' => '...'", !preg_match("/'message' => ['\"]/", $actionBranch));
check("scan: index.php's action branch echoes no service error untranslated", !preg_match("/'error' => \\\$\w+Result\['error'\]/", $actionBranch));
preg_match_all("/\bt\('(app\.(?:error|message)\.[a-z_]+)'|\\\$appError\('(app\.error\.[a-z_]+)'\)/", $actionBranch, $am);
$aaUsed = array_unique(array_filter(array_merge($am[1], $am[2])));
check('scan: index.php uses 19 app.error.* / app.message.* keys (16 + 3)', count($aaUsed) === 19, (string) count($aaUsed));
check("scan: every app.* key index.php's action branch uses exists in lang/en.php and lang/sv.php", array_diff($aaUsed, array_keys($en)) === [] && array_diff($aaUsed, array_keys($sv)) === [], implode(', ', array_diff($aaUsed, array_keys($en))));
ms_test_cleanup($aa['root']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
