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
same('resolve: untranslated /sv/ page redirects to the plain URL', '/client_agent_manage.php', i18nResolveRequest(['REQUEST_URI' => '/sv/client_agent_manage.php'], $both));
same('resolve: ...and stays English', 'en', i18nLocale());
same('resolve: query string survives the redirect', '/client_agent_manage.php?plan=pro', i18nResolveRequest(['REQUEST_URI' => '/sv/client_agent_manage.php?plan=pro'], $both));
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
$svRequiredPrefixes = ['nav.', 'footer.', 'consent.', 'landing.', 'pricing.', 'date.', 'faq.', 'legal.', 'appnav.', 'blog.', 'auth.', 'voucher.', 'service.mailbox.', 'app.', 'profile.section.', 'profile.common.', 'profile.error.', 'profile.message.', 'profile.account.', 'profile.addresses.', 'profile.hold.', 'service.hold.', 'profile.automation.', 'profile.webhooks.', 'profile.feeds.', 'profile.digest.', 'profile.security.', 'profile.apps.', 'profile.twofa.', 'profile.password.', 'profile.agent.', 'profile.delete.', 'contact.', 'welcome.', 'oauth.', 'mail.'];
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
    // Step 7/8: Paddle sends the buyer back to the success page in the buyer's language.
    same("pricing sv {$name}: successUrl is the Swedish welcome page, English stays plain", ['https://example.test/sv/welcome.php', 'https://example.test/welcome.php'], [$cfg['successUrl'] ?? null, $cfgEn['successUrl'] ?? null]);
    unset($cfg['perMonth'], $cfg['perYear'], $cfg['locale'], $cfg['successUrl'], $cfgEn['perMonth'], $cfgEn['perYear'], $cfgEn['locale'], $cfgEn['successUrl']);
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
same("pages: '@app' offers Swedish now that every app page is translated (step 7/8)", ['en', 'sv'], $realPages['@app'] ?? null);
same("pages: '@app' is a pseudo-entry, i18nPageLocales() reads it as given", ['en', 'sv'], i18nPageLocales('@app', $both));
same("pages: '@app' still yields to LOCALES_ENABLED=en", ['en'], i18nPageLocales('@app', $onlyEn));
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
i18nTranslatedPages(['@app' => ['en']]); // the closed gate, explicitly (the shipped list changes in step 7/8)
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
function ms_mail_probe_script(): string
{
    return <<<'PHP'
<?php
// Calls one mail function of the real pro_auth.php. Never deployed.
require __DIR__ . '/config.php';
require __DIR__ . '/pro_auth.php';
$a = json_decode((string) $argv[1], true);
if (!empty($a['locale'])) {
    i18nSetLocale($a['locale']);
}
switch ($a['kind']) {
    case 'login': sendLoginEmail($a['email'], $a['token'], $a['user_id'], (int) $a['stay']); break;
    case 'verify': sendVerificationEmail($a['email'], $a['token'], $a['user_id']); break;
    case 'already': sendAlreadyRegisteredEmail($a['email'], $a['user_id']); break;
    case 'recovery': send_2fa_recovery_code_used_email($a['user_id']); break;
    case 'admin': sendAdminRegistrationNotification($a['email'], $a['user_id']); break;
}
PHP;
}

/** The whole message mail() handed to sendmail, with the token and the PHP version masked. */
function ms_mail_mask(string $raw): string
{
    $raw = preg_replace('/[a-f0-9]{48}/', '<TOKEN>', $raw);
    $raw = preg_replace('/X-Mailer: PHP\/\S+/', 'X-Mailer: PHP/<VERSION>', $raw);
    return str_replace("\r\n", "\n", (string) $raw);
}

/**
 * Sends one mail through the probe and returns the captured, masked message.
 * $kind: login | login_stay | verify | already | recovery | admin | email_changed | password_changed.
 */
function ms_mail_capture(string $root, string $kind, array $opts = []): string
{
    $locales = $opts['locales'] ?? 'en';
    $account = $opts['account_locale'] ?? null;
    $env = !empty($opts['mail_sv']) ? ['PROBE_MAIL_SV' => '1'] : [];
    $mailLog = $root . '/mail.log';
    @unlink($mailLog);
    $token = str_repeat('ab12', 12);
    if ($kind === 'email_changed' || $kind === 'password_changed') {
        [$db] = ms_auth_probe_db($root, function (PDO $pdo, array $ids) use ($kind, $token, $account) {
            if ($account !== null) {
                $pdo->prepare('UPDATE pro_users SET locale = ? WHERE id = ?')->execute([$account, $ids['alice']]);
            }
            $pdo->prepare('INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$ids['alice'], $kind === 'email_changed' ? 'update_email' : 'set_password',
                    json_encode($kind === 'email_changed' ? ['new_email' => 'alice.new@example.com'] : ['password_hash' => password_hash('new-password-1', PASSWORD_DEFAULT)]),
                    $token, date('Y-m-d H:i:s', time() + 3600)]);
        });
        ms_auth_probe_run($root, 'pro_auth.php', ['method' => 'GET', 'uri' => '/pro_auth.php', 'get' => ['confirm_profile_change' => $token], 'locales' => $locales, 'base_url' => 'https://example.test/'], $db, true, $env);
    } else {
        [$db, $ids] = ms_auth_probe_db($root, function (PDO $pdo, array $ids) use ($account) {
            if ($account !== null) {
                $pdo->prepare('UPDATE pro_users SET locale = ? WHERE id = ?')->execute([$account, $ids['alice']]);
            }
        });
        $args = ['kind' => preg_replace('/_stay$/', '', $kind), 'email' => 'alice@example.com', 'token' => $token, 'user_id' => $ids['alice'], 'stay' => str_ends_with($kind, '_stay') ? 7 : 0, 'locale' => $opts['request_locale'] ?? null];
        $run = ms_auth_probe_run($root, 'mail_probe.php', ['uri' => '/pro_auth.php', 'locales' => $locales, 'base_url' => 'https://example.test/'], $db, true, $env, [json_encode($args)]);
        if ($run['err'] !== '') {
            fwrite(STDERR, $run['err']);
        }
    }
    @unlink($db);
    @unlink($db . '.log');
    return ms_mail_mask((string) @file_get_contents($mailLog));
}

// --- Account mail, step 1/3 (epic #437, #438): helpers and the pro_auth.php mails --------
// mailLocaleFor(), mailEncodeSubject(), mailUrl() are pure; the seven mails of pro_auth.php run
// as subprocesses of the auth probe with sendmail_path=cat >> mail.log, as tests/pii_crypto_test.php
// does. The English fixtures (tests/fixtures/i18n/mail.*.en.txt) were captured from the code as it
// was before these mails went through t(); the link token and the PHP version in X-Mailer are masked.

// mailLocaleFor(): the account's language, then the request's, then English; '@mail' and
// LOCALES_ENABLED both have to allow it.
$mailPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mailPdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, locale TEXT NULL)');
$mailPdo->exec("INSERT INTO pro_users (id, locale) VALUES (1, 'sv'), (2, NULL), (3, 'en'), (4, 'xx')");
$mailPdoNoColumn = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mailPdoNoColumn->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY)');
$mailPdoNoColumn->exec('INSERT INTO pro_users (id) VALUES (1)');
$mailCfg = ['enabled' => ['en', 'sv']];
$realMailPages = i18nTranslatedPages();
same('mail gate: the shipped list has @mail at English only', ['en'], $realMailPages['@mail'] ?? null);
same('mailLocaleFor: @mail at en is always English (account sv)', 'en', mailLocaleFor($mailPdo, 1, $mailCfg, 'sv'));
i18nTranslatedPages(array_merge($realMailPages, ['@mail' => ['en', 'sv']]));
same('mailLocaleFor: account sv', 'sv', mailLocaleFor($mailPdo, 1, $mailCfg, null));
same('mailLocaleFor: the account beats the request (account en, request sv)', 'en', mailLocaleFor($mailPdo, 3, $mailCfg, 'sv'));
same('mailLocaleFor: no stored language falls to the request', 'sv', mailLocaleFor($mailPdo, 2, $mailCfg, 'sv'));
same('mailLocaleFor: no stored language and no request is English', 'en', mailLocaleFor($mailPdo, 2, $mailCfg, null));
same('mailLocaleFor: an unsupported stored language is ignored', 'en', mailLocaleFor($mailPdo, 4, $mailCfg, null));
same('mailLocaleFor: a request language that is not a candidate is ignored', 'en', mailLocaleFor($mailPdo, 2, $mailCfg, 'de'));
same('mailLocaleFor: a missing user is English', 'en', mailLocaleFor($mailPdo, 99, $mailCfg, null));
same('mailLocaleFor: no user id uses the request', 'sv', mailLocaleFor($mailPdo, null, $mailCfg, 'sv'));
same('mailLocaleFor: no PDO uses the request', 'sv', mailLocaleFor(null, 1, $mailCfg, 'sv'));
same('mailLocaleFor: no PDO and no request is English', 'en', mailLocaleFor(null, 1, $mailCfg, null));
same('mailLocaleFor: the column absent falls to the request', 'sv', mailLocaleFor($mailPdoNoColumn, 1, $mailCfg, 'sv'));
same('mailLocaleFor: the column absent and no request is English', 'en', mailLocaleFor($mailPdoNoColumn, 1, $mailCfg, null));
same('mailLocaleFor: LOCALES_ENABLED=en is English', 'en', mailLocaleFor($mailPdo, 1, ['enabled' => ['en']], 'sv'));
same('mailLocaleFor: no i18n config is English', 'en', mailLocaleFor($mailPdo, 1, [], 'sv'));
same('mailLocaleFor: a broken config is English', 'en', mailLocaleFor($mailPdo, 1, ['enabled' => 'sv'], 'sv'));
i18nTranslatedPages($realMailPages);

// mailEncodeSubject()
same('mailEncodeSubject: ASCII is unchanged', 'Your login link for Mail Shield', mailEncodeSubject('Your login link for Mail Shield'));
$svSubject = 'Din inloggningslänk för Mail Shield';
$encoded = mailEncodeSubject($svSubject);
check('mailEncodeSubject: non-ASCII is an RFC 2047 base64 UTF-8 word', (bool) preg_match('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $encoded), $encoded);
same('mailEncodeSubject: Swedish round-trips through mb_decode_mimeheader()', $svSubject, mb_decode_mimeheader($encoded));
$longSubject = str_repeat('Återställningskod för tvåfaktorsautentisering ', 4);
$encodedLong = mailEncodeSubject($longSubject);
$words = explode("\r\n ", $encodedLong);
check('mailEncodeSubject: a long subject is folded into several words', count($words) > 1);
check('mailEncodeSubject: no encoded word is over 75 characters', max(array_map('strlen', $words)) <= 75, (string) max(array_map('strlen', $words)));
same('mailEncodeSubject: a long subject round-trips', trim($longSubject), trim(mb_decode_mimeheader($encodedLong)));
$words2 = array_map(fn($w) => base64_decode(substr($w, 10, -2), true), $words);
check('mailEncodeSubject: no word splits a multi-byte character', !in_array(false, $words2, true) && !in_array(false, array_map(fn($w) => mb_check_encoding((string) $w, 'UTF-8'), $words2), true));
check('mailEncodeSubject: CR and LF are stripped from ASCII', !preg_match('/[\r\n]/', mailEncodeSubject("Hello\r\nBcc: x@example.com")));
same('mailEncodeSubject: the stripped ASCII subject', 'HelloBcc: x@example.com', mailEncodeSubject("Hello\r\nBcc: x@example.com"));
$injected = mailEncodeSubject("Hej\r\nBcc: x@example.com å");
check('mailEncodeSubject: CR and LF are stripped before encoding', !preg_match('/[\r\n](?! )/', $injected) && strpos(mb_decode_mimeheader($injected), "\n") === false);

// mailUrl(): English is today's string, Swedish carries /sv/.
$urlConfig = ['email' => ['base_url' => 'https://example.test/'], 'i18n' => ['enabled' => ['en', 'sv']]];
$tok = str_repeat('ab12', 12);
same('mailUrl: English equals the previous concatenation', $urlConfig['email']['base_url'] . 'pro_login.php?token=' . urlencode($tok), mailUrl($urlConfig, 'pro_login.php?token=' . urlencode($tok), 'en'));
same('mailUrl: English without a query equals the previous concatenation', $urlConfig['email']['base_url'] . 'pro_login.php', mailUrl($urlConfig, 'pro_login.php', 'en'));
same('mailUrl: Swedish is <origin>/sv/pro_login.php?token=…', 'https://example.test/sv/pro_login.php?token=' . $tok, mailUrl($urlConfig, 'pro_login.php?token=' . $tok, 'sv'));
same('mailUrl: Swedish with LOCALES_ENABLED=en stays English', 'https://example.test/pro_login.php', mailUrl(['email' => $urlConfig['email'], 'i18n' => ['enabled' => ['en']]], 'pro_login.php', 'sv'));

// The seven mails. The probe's config gets one extra line so a case can open the '@mail' gate.
file_put_contents($authProbe . '/config.php', "\nif (getenv('PROBE_MAIL_SV')) {\n    i18nTranslatedPages(array_merge(i18nTranslatedPages(), ['@mail' => ['en', 'sv']]));\n}\n", FILE_APPEND);
file_put_contents($authProbe . '/mail_probe.php', ms_mail_probe_script());
$mailKinds = ['login', 'login_stay', 'verify', 'already', 'recovery', 'email_changed', 'password_changed'];
$mailKeys = ['login' => 'mail.login', 'login_stay' => 'mail.login', 'verify' => 'mail.verify', 'already' => 'mail.already_registered', 'recovery' => 'mail.recovery_used', 'email_changed' => 'mail.email_changed', 'password_changed' => 'mail.password_changed'];
$mailLinkPath = ['login' => 'pro_login.php?token=' . $tok, 'login_stay' => 'pro_login.php?token=' . $tok . '&stay=7', 'verify' => 'pro_login.php?token=' . $tok, 'already' => 'pro_login.php'];
$mailParams = static function (string $kind, string $locale) use ($mailLinkPath, $urlConfig): array {
    return match ($kind) {
        'login', 'login_stay', 'verify' => ['link' => mailUrl($urlConfig, $mailLinkPath[$kind], $locale), 'minutes' => 30],
        'already' => ['link' => mailUrl($urlConfig, $mailLinkPath[$kind], $locale)],
        'email_changed' => ['old' => 'alice@example.com', 'new' => 'alice.new@example.com'],
        'password_changed' => ['email' => 'alice@example.com'],
        default => [],
    };
};
$mailSplit = static function (string $message): array {
    $parts = explode("\n\n", $message, 2);
    return [$parts[0], $parts[1] ?? ''];
};
$mailSubject = static function (string $headers): string {
    preg_match('/^Subject: (.*(?:\n .*)*)$/m', $headers, $m);
    return mb_decode_mimeheader(str_replace("\n ", "\n ", $m[1] ?? ''));
};

foreach ($mailKinds as $kind) {
    $fixture = (string) file_get_contents($fx . "mail.{$kind}.en.txt");
    same("mail {$kind}: English is byte-identical to its fixture", $fixture, ms_mail_capture($authProbe, $kind));
    same("mail {$kind}: with Swedish on but @mail closed it is still English (account sv)", $fixture, ms_mail_capture($authProbe, $kind, ['locales' => 'en,sv', 'account_locale' => 'sv', 'request_locale' => 'sv']));
    same("mail {$kind}: with @mail open but LOCALES_ENABLED=en it is English", $fixture, ms_mail_capture($authProbe, $kind, ['locales' => 'en', 'account_locale' => 'sv', 'request_locale' => 'sv', 'mail_sv' => true]));
    same("mail {$kind}: an account with locale en stays English with everything open", $fixture, ms_mail_capture($authProbe, $kind, ['locales' => 'en,sv', 'account_locale' => 'en', 'request_locale' => 'sv', 'mail_sv' => true]));

    $svMail = ms_mail_capture($authProbe, $kind, ['locales' => 'en,sv', 'account_locale' => 'sv', 'mail_sv' => true]);
    [$svHeaders, $svBody] = $mailSplit($svMail);
    $mailKey0 = $mailKeys[$kind];
    $svSubjectIsAscii = !preg_match('/[^\x00-\x7F]/', t($mailKey0 . '.subject', [], 'sv'));
    check("mail {$kind}: Swedish subject is encoded exactly when it is not ASCII", $svSubjectIsAscii !== (bool) preg_match('/^Subject: =\?UTF-8\?B\?/m', $svHeaders), $svHeaders);
    same("mail {$kind}: Swedish subject decodes to the catalog string", t($mailKey0 . '.subject', [], 'sv'), $mailSubject($svHeaders));
    same("mail {$kind}: Swedish body is the catalog string", str_replace($tok, '<TOKEN>', rtrim(t($mailKey0 . '.body', $mailParams($kind, 'sv'), 'sv'))), rtrim($svBody));
    check("mail {$kind}: Swedish body differs from English", t($mailKey0 . '.body', [], 'sv') !== t($mailKey0 . '.body', [], 'en'));
    check("mail {$kind}: Swedish headers keep From, charset and no raw non-ASCII", strpos($svHeaders, 'From: Mail Shield <noreply@manjo.me>') !== false && strpos($svHeaders, 'Content-Type: text/plain; charset=UTF-8') !== false && !preg_match('/[^\x00-\x7F]/', $svHeaders));
    if (isset($mailLinkPath[$kind])) {
        check("mail {$kind}: the link carries /sv/", strpos($svBody, 'https://example.test/sv/' . str_replace($tok, '<TOKEN>', $mailLinkPath[$kind])) !== false);
    }
    // The English headers are exactly the fixture's headers.
    [$enHeaders] = $mailSplit($fixture);
    foreach (['From: ', 'Reply-To: ', 'MIME-Version: ', 'Content-Type: ', 'X-Mailer: '] as $h) {
        same("mail {$kind}: header {$h} is unchanged in Swedish", (bool) preg_match('/^' . preg_quote($h, '/') . '.*$/m', $enHeaders, $a) ? $a[0] : null, (bool) preg_match('/^' . preg_quote($h, '/') . '.*$/m', $svHeaders, $b) ? $b[0] : null);
    }
}

// The language of the request counts for the three mails the recipient asks for, and only for them.
$enLogin = (string) file_get_contents($fx . 'mail.login.en.txt');
foreach (['login', 'verify', 'already'] as $kind) {
    $mailRun = ms_mail_capture($authProbe, $kind, ['locales' => 'en,sv', 'request_locale' => 'sv', 'mail_sv' => true]);
    check("mail {$kind}: no stored language falls to the Swedish request", strpos($mailRun, "\nSubject: " . (mailEncodeSubject(t('mail.' . ($kind === 'already' ? 'already_registered' : $kind) . '.subject', [], 'sv'))) . "\n") !== false);
    $mailRun = ms_mail_capture($authProbe, $kind, ['locales' => 'en,sv', 'request_locale' => 'sv', 'mail_sv' => true, 'account_locale' => 'en']);
    same("mail {$kind}: the stored language wins over the request", (string) file_get_contents($fx . "mail.{$kind}.en.txt"), $mailRun);
}
same('mail recovery: the request language is not used (no stored language)', (string) file_get_contents($fx . 'mail.recovery.en.txt'), ms_mail_capture($authProbe, 'recovery', ['locales' => 'en,sv', 'request_locale' => 'sv', 'mail_sv' => true]));

// The operator notice stays English whatever the operator account says.
$admin = ms_mail_capture($authProbe, 'admin', ['locales' => 'en,sv', 'account_locale' => 'sv', 'request_locale' => 'sv', 'mail_sv' => true]);
check('operator notice: still English with locale=sv and @mail open', strpos($admin, 'Subject: New Mail Shield registration verified') !== false && strpos($admin, 'A new user just registered and verified their email address') !== false && strpos($admin, '=?UTF-8?B?') === false, $admin);
$authPhpMail = (string) file_get_contents($root . '/pro_auth.php');
check('scan: the operator notice is not built from t()', (bool) preg_match('/function sendAdminRegistrationNotification.*?\n}\n/s', $authPhpMail, $adminFn) && !preg_match('/\bt\(/', $adminFn[0]));

// Catalog hygiene for the mail keys.
$enMail = i18nCatalog('en');
foreach (array_filter(array_keys($enMail), fn($k) => strncmp($k, 'mail.', 5) === 0) as $mailKey) {
    check("catalog: {$mailKey} has no markup", strip_tags((string) $enMail[$mailKey]) === (string) $enMail[$mailKey]);
    same("catalog: {$mailKey} has the same placeholders in sv", placeholders($enMail[$mailKey]), placeholders(i18nCatalog('sv')[$mailKey] ?? ''));
}
check('catalog: no mail body writes the link lifetime into the text', strpos($enMail['mail.login.body'], '30') === false && strpos($enMail['mail.verify.body'], '30') === false && strpos(i18nCatalog('sv')['mail.login.body'], '30') === false);
check('scan: the mail functions pass every subject through mailEncodeSubject()', substr_count($authPhpMail, 'mailEncodeSubject(t(') === 6);
check('scan: no mail link is built from base_url by hand in pro_auth.php', strpos($authPhpMail, "\$config['email']['base_url'] . \"pro_login.php") === false);
check('scan: the debug login_url keeps using the English link', strpos($authPhpMail, "\$response['login_url'] = proLoginLinkUrl(\$token, \$stayDays);") !== false);
check('scan: no mail log line carries a locale beside an address', !preg_match('/logMessage\([^;]*locale[^;]*email/i', $authPhpMail));

ms_auth_probe_remove($authProbe);

// --- Inbox actions (epic #428, step 3/8, part A) --------------------------------
// index.php's JSON actions: every answer carrying an `error` or a `message` goes through
// the catalog (t('app.error.*') / t('app.message.*'), or i18nServiceError() for a
// mailbox_service.php result). The English fixtures were rendered from the code as it
// was; Swedish is asked for with X-MS-Locale (and Accept-Language) once '@app' lists it.
require_once $root . '/tests/lib/app_actions_probe.php';
require_once $root . '/tests/lib/profile_probe.php';
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

// --- Inbox pages (epic #428, step 3/8, part B): inbox.php, pro.php, app.js ----------
// The real pages run as subprocesses on the sign-in probe docroot (tests/lib/auth_probe.php,
// ms_app_page()). The English fixtures were rendered from the code as it was before the
// pages went through te()/tHtml(); inline scripts are compared apart, key by key below.
// A page takes the account's language, so Swedish is asked for with Accept-Language once
// '@app' lists it.
$appProbe = ms_auth_probe_build($root);
// PHP drops the newline after a closing tag, so runs of whitespace are compared as one space.
$appNorm = static fn(string $html): string => preg_replace('/\s+/', ' ', authNorm($html));
$appKeyRe = '/\bapp\.(?:common|inbox|pro|js|error|message)\.[a-z_0-9]+/';
foreach (ms_app_page_cases() as $name => [$script, $in, $pro, $get, $pend]) {
    $fixture = $appNorm((string) file_get_contents($fx . 'app_pages.' . $name . '.en.html'));
    foreach ([false => 'English only', true => 'sv offered but not chosen'] as $svOn => $label) {
        $r = ms_app_page($appProbe, $script, $in, $pro, $get, $pend, null, (bool) $svOn);
        check("app page {$name} ({$label}): rendered without PHP errors", $r['html'] !== '' && $r['err'] === '', $r['err']);
        same("fixture: app page {$name}, {$label}", $fixture, $appNorm($r['html']));
    }
    $enPage = ms_app_page($appProbe, $script, $in, $pro, $get, $pend);
    // Scripts: the four expiry texts reach the inline script in English, and the language JSON is on the page.
    $pageHasExpiry = strpos($enPage['html'], '"free":"Free"') !== false && strpos($enPage['html'], '"lifetime":"Pro: Lifetime"') !== false
        && strpos($enPage['html'], '"expired":"Pro expired on {date}"') !== false && strpos($enPage['html'], '"expires":"Pro expires: {date}"') !== false;
    check("app page {$name}: the expiry line texts are in the script, in English", !$in || $pageHasExpiry);
    check("app page {$name}: ms-i18n precedes app.js", preg_match('#id="ms-i18n">.*?</script>\s*(?:<script>[^<]*window\.tempMailConfig[^<]*</script>\s*)?<script src="assets/js/app\.js#s', $enPage['html']) === 1);
    preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $enPage['html'], $jm);
    $jsonCatalog = json_decode($jm[1] ?? '', true);
    check("app page {$name}: ms-i18n carries the app. keys", is_array($jsonCatalog) && ($jsonCatalog['app.js.generated'] ?? null) === 'New email address generated!' && ($jsonCatalog['app.common.close'] ?? null) === 'Close');
    // Swedish.
    $svPage = ms_app_page($appProbe, $script, $in, $pro, $get, $pend, null, true, 'sv-SE,sv;q=0.9');
    $svNoJson = preg_replace(["#<script type=\"application/json\" id=\"ms-i18n\">.*?</script>#s", "/msT\\('app\\.[a-z_.0-9]+'/"], ['', "msT('"], $svPage['html']);
    check("sv app page {$name}: rendered without PHP errors", $svPage['html'] !== '' && $svPage['err'] === '', $svPage['err']);
    check("sv app page {$name}: <html lang=\"sv\">", strpos($svPage['html'], '<html lang="sv">') !== false);
    check("sv app page {$name}: no raw app. key anywhere but the language JSON", !preg_match($appKeyRe, $svNoJson), (string) (preg_match($appKeyRe, $svNoJson, $km) ? $km[0] : ''));
    $svJson = null;
    if (preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $svPage['html'], $sm)) {
        $svJson = json_decode($sm[1], true);
    }
    check("sv app page {$name}: ms-i18n is Swedish", is_array($svJson) && ($svJson['app.js.generated'] ?? null) === 'Ny e-postadress skapad!' && ($svJson['app.common.close'] ?? null) === 'Stäng');
    check("sv app page {$name}: the title is Swedish", strpos($svPage['html'], '<title>Din inkorg · Mail Shield</title>') !== false);
    check("sv app page {$name}: footer and modal are Swedish", strpos($svPage['html'], 'Drivs av Manjo Consulting AB') !== false && strpos($svPage['html'], '>Bilagor</h6>') !== false && strpos($svPage['html'], 'aria-label="Stäng"') !== false);
    check("sv app page {$name}: the expiry texts are Swedish in the script", !$in || (strpos($svPage['html'], '"free":"Gratis"') !== false && strpos($svPage['html'], '"expired":"Pro löpte ut {date}"') !== false));
    check("sv app page {$name}: the list bar is Swedish", strpos($svPage['html'], '>meddelanden</span>') !== false && strpos($svPage['html'], 'Senast uppdaterad: <span id="lastUpdate">Aldrig</span>') !== false);
    check("sv app page {$name}: ids the scripts and styles depend on survive", strpos($svPage['html'], 'id="proExpiryLine"') !== false && strpos($svPage['html'], 'id="emailList"') !== false && strpos($svPage['html'], 'id="statsStorage"') !== false || !$in);
}
$svInbox = ms_app_page($appProbe, 'inbox.php', true, true, [], false, null, true, 'sv');
check('sv inbox (signed in): the empty state and the lead are Swedish', strpos($svInbox['html'], 'Klicka för att få din Timed-adress') !== false && strpos($svInbox['html'], 'Timed-adresser för Pro-användare.') !== false && strpos($svInbox['html'], 'Hämta e-postadress') !== false);
check('sv inbox (signed in): the statistics labels are Swedish', strpos($svInbox['html'], 'Meddelanden i din inkorg') !== false && strpos($svInbox['html'], 'Använt lagringsutrymme') !== false);
$svVisitor = ms_app_page($appProbe, 'inbox.php', false, false, ['address' => 'abcdef0123456789'], false, null, true, 'sv');
check('sv inbox (signed out): the sign-up prompt is Swedish and keeps its links', strpos($svVisitor['html'], 'Du behöver ett konto för att skapa en Timed-adress') !== false
    && strpos($svVisitor['html'], 'href="/register.php"') !== false && strpos($svVisitor['html'], '>Har du redan ett konto? Logga in</a>') !== false && strpos($svVisitor['html'], 'Timed-adresser som raderas efter 24 timmar') !== false);
$svPro = ms_app_page($appProbe, 'pro.php', true, true, [], true, null, true, 'sv');
check('sv pro.php: pending changes in Swedish with count, times and the unchanged data', strpos($svPro['html'], 'Väntande profiländringar') !== false && strpos($svPro['html'], '>2 väntande</span>') !== false
    && strpos($svPro['html'], 'Ny e-postadress: new@example.com') !== false && strpos($svPro['html'], 'Begärd: 2099-01-01 10:00:00') !== false && strpos($svPro['html'], 'Skicka bekräftelsemejlet igen') !== false && strpos($svPro['html'], '>update_email</strong>') !== false);
check('sv pro.php: the rail, the lifetime plurals and the settings link', strpos($svPro['html'], 'Hantera dina adresser under <a href="pro_profile_page.php">Inställningar</a>.') !== false
    && strpos($svPro['html'], '>1 dag</option>') !== false && strpos($svPro['html'], '>2 dagar</option>') !== false && strpos($svPro['html'], 'Gäller nya Timed-adresser.') !== false);
check('sv pro.php: the filter bar keeps its label element', strpos($svPro['html'], 'Filtrerad på: <strong id="addressFilterLabel"></strong>') !== false && strpos($svPro['html'], '>Rensa filter</button>') !== false);
check('sv pro.php: the inline script strings come from the catalog (msT)', strpos($svPro['html'], "msT('app.pro.ttl_saving'") !== false && strpos($svPro['html'], "msT('app.pro.rail_empty'") !== false);
$svPlain = ms_app_page($appProbe, 'pro.php', true, false, [], false, null, true, 'sv');
check('sv pro.php (Regular): the expiry text is "Gratis"', strpos($svPlain['html'], '"free":"Gratis"') !== false);
// Without a language choice and without Swedish enabled the app stays English even if the browser asks for Swedish.
$enAsked = ms_app_page($appProbe, 'pro.php', true, true, [], false, null, false, 'sv');
check('pro.php: Accept-Language sv is ignored while the app area is English only', strpos($enAsked['html'], '<html lang="en">') !== false && strpos($enAsked['html'], 'Din inkorg') === false && strpos($enAsked['html'], 'Your inbox') !== false);
ms_auth_probe_remove($appProbe);

// Scans: the pages, app.js and the catalog agree.
$inboxSrc = (string) file_get_contents($root . '/inbox.php');
$proSrc = (string) file_get_contents($root . '/pro.php');
$appJs = (string) file_get_contents($root . '/assets/js/app.js');
foreach (['inbox.php' => $inboxSrc, 'pro.php' => $proSrc] as $f => $src) {
    check("scan: {$f} sets \$msI18nJs = ['app.'] and requires partials/i18n_js.php before app.js", (bool) preg_match("#\\\$msI18nJs = \['app\.'\];\s*require __DIR__ \. '/partials/i18n_js\.php';.*?assets/js/app\.js#s", $src));
    preg_match_all("/\b(?:te|t|tp|tHtml)\('(app\.[a-z_.0-9]+)'/", $src, $pm);
    $used = array_unique($pm[1]);
    check("scan: {$f} uses catalog keys", count($used) >= 20, (string) count($used));
    check("scan: every key {$f} uses exists in lang/en.php and lang/sv.php", array_diff($used, array_keys($en)) === [] && array_diff($used, array_keys($sv)) === [], implode(', ', array_merge(array_diff($used, array_keys($en)), array_diff($used, array_keys($sv)))));
    check("scan: {$f} has no lang=\"en\" literal and no Swedish/English text left in visible attributes", !preg_match('/\b(?:title|aria-label|placeholder)="[A-Za-z]/', $src));
}
check('scan: assets/js/app.js is covered by the msT() key scan', isset($jsKeys['app.js.generated']) && isset($jsKeys['app.error.generate']) && isset($jsKeys['app.pro.pending_badge']));
check('scan: app.js defines msT() once, exactly as I18N.md documents it', substr_count($appJs, 'function msT(key, fallback, params)') === 1
    && strpos($appJs, "JSON.parse(document.getElementById('ms-i18n').textContent)") !== false
    && strpos($appJs, "return text.replace(/\\{(\\w+)\\}/g, function (m, name) {") !== false);
check('scan: every escapeHtml() call of app.js is kept (11 before, more now)', substr_count($appJs, 'escapeHtml(') >= 11, (string) substr_count($appJs, 'escapeHtml('));
check('scan: the sv-SE number and date formatting is untouched', substr_count($appJs, "toLocaleString('sv-SE'") === 2 && substr_count($appJs, "toLocaleDateString('sv-SE'") === 1);
check('scan: app.js has no showNotification / alert / confirm with a literal text', !preg_match("/(?:showNotification|alert|confirm)\(\s*['\"`]/", $appJs));
foreach ([[$appJs, 'app.js'], [$proSrc, 'pro.php'], [(string) file_get_contents($root . '/pro_profile_page.php'), 'pro_profile_page.php']] as [$source, $file]) {
    preg_match_all("/(?<![\\w.\$])msT\\('([^']+)',\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $mm, PREG_SET_ORDER);
    check("{$file}: msT() fallbacks found", count($mm) >= 3, (string) count($mm));
    foreach ($mm as [, $key, $fallback]) {
        $value = $en[$key] ?? null;
        same("{$file}: msT fallback for {$key} is the English catalog value", is_string($value) ? $value : null, stripslashes($fallback));
    }
}
check('scan: assets/js/sw.js is not part of this change (no msT, no app. key)', stripos((string) file_get_contents($root . '/assets/js/sw.js'), 'msT') === false);

// --- Profile A (epic #428, step 4/8): pro_profile_page.php shell, Account, Addresses -------------
// The real page and the real pro_profile.php run as subprocesses on the profile probe
// (tests/lib/profile_probe.php). The English fixtures were rendered from the code as it was
// before the page went through te()/tHtml(); inline scripts are checked by the scans below.
// The account's language, then Accept-Language, picks the page language once '@app' lists
// Swedish; an endpoint takes X-MS-Locale.
$pp = ms_pp_build($root);
$ppNorm = static fn(string $html): string => trim(preg_replace('/\s+/', ' ', authNorm($html)));
$ppKeyRe = '/\bprofile\.(?:section|common|account|addresses|hold|error|message)\.[a-z_0-9]+/';
$ppNoPhpError = static fn(array $r): bool => !preg_match('/^PHP (Warning|Notice|Fatal|Parse|Deprecated)/m', (string) $r['res']['stderr']);
foreach (array_keys(ms_pp_page_cases()) as $ppName) {
    $fixture = $ppNorm((string) file_get_contents($fx . "profile.page.{$ppName}.en.html"));
    $r = ms_pp_page($pp, $ppName);
    check("profile page {$ppName}: rendered without PHP errors", $r['body'] !== '' && $ppNoPhpError($r), (string) $r['res']['stderr']);
    same("fixture: profile page {$ppName}, English only", $fixture, $ppNorm($r['body']));
    // LOCALES_ENABLED has Swedish but the app area is still English: the page is unchanged, no Language card.
    $rOff = ms_pp_page($pp, $ppName, false, 'sv-SE,sv;q=0.9', 'sv', 'en,sv');
    same("fixture: profile page {$ppName}, sv enabled but '@app' English only", $fixture, $ppNorm($rOff['body']));
    check("profile page {$ppName}: no Language card while '@app' is English only", strpos($rOff['body'], 'id="languageCard"') === false && strpos($rOff['body'], 'id="saveLanguageBtn"') === false);
    check("profile page {$ppName}: the English page carries the language JSON with profile. keys", (bool) preg_match('#id="ms-i18n">\{[^<]*"profile\.hold\.started":"Hold started"#', $r['body']) && strpos($r['body'], '"app.common.expiry_free":"Free"') !== false);
    // Swedish.
    $svPg = ms_pp_page($pp, $ppName, true, 'sv-SE,sv;q=0.9');
    $svNoJson = preg_replace('#<script type="application/json" id="ms-i18n">.*?</script>#s', '', $svPg['body']);
    $svNoJson = preg_replace("/msT\\('(?:profile|app\\.common)\\.[a-z_.0-9]+'/", "msT('", $svNoJson);
    check("sv profile page {$ppName}: rendered without PHP errors", $svPg['body'] !== '' && $ppNoPhpError($svPg), (string) $svPg['res']['stderr']);
    check("sv profile page {$ppName}: <html lang=\"sv\">", strpos($svPg['body'], '<html lang="sv">') !== false);
    check("sv profile page {$ppName}: no raw profile. key anywhere but the language JSON", !preg_match($ppKeyRe, $svNoJson), (string) (preg_match($ppKeyRe, $svNoJson, $km) ? $km[0] : ''));
    check("sv profile page {$ppName}: the title, heading, rail and section titles are Swedish",
        strpos($svPg['body'], '<title>Inställningar · Mail Shield</title>') !== false && strpos($svPg['body'], '<h1 class="ms-settings__title">Inställningar</h1>') !== false
        && strpos($svPg['body'], 'aria-label="Inställningsavsnitt"') !== false && strpos($svPg['body'], 'href="#settings-account" aria-current="location">Konto</a>') !== false
        && strpos($svPg['body'], '>Säkerhet</a>') !== false && strpos($svPg['body'], '<h2 class="ms-settings__section-title">Radera konto</h2>') !== false);
    check("sv profile page {$ppName}: the Account and Addresses cards are Swedish",
        strpos($svPg['body'], '>Timed-adresser är gratis.') !== false && strpos($svPg['body'], '>E-postadress</h3>') !== false && strpos($svPg['body'], '>Spara e-postadress</button>') !== false
        && strpos($svPg['body'], 'Skapa upp till 10 Sticky-adresser och hantera dem från en och samma inkorg.') !== false && strpos($svPg['body'], 'placeholder="dittnamn"') !== false
        && strpos($svPg['body'], 'aria-label="adressnamn"') !== false && strpos($svPg['body'], '>Nyligen raderade</h4>') !== false && strpos($svPg['body'], 'reserverad för dig i 6 månader') !== false
        && strpos($svPg['body'], '>Standardlivslängd</h3>') !== false && strpos($svPg['body'], 'Välj 30 dagar för att behålla e-posten på dina Sticky-adresser i 30 dagar, upp till 4 gånger per år.') !== false
        && strpos($svPg['body'], 'Regular-konton har en fast livslängd på 24 timmar') !== false && strpos($svPg['body'], '>Starta</button>') !== false && strpos($svPg['body'], '>Avbryt</button>') !== false);
    check("sv profile page {$ppName}: ids the scripts depend on survive",
        strpos($svPg['body'], 'id="proProfileAlert"') !== false && strpos($svPg['body'], 'id="saveProfileEmailBtn"') !== false && strpos($svPg['body'], 'id="personalCounterLabel"') !== false
        && strpos($svPg['body'], 'id="ttlHoldStartBtn"') !== false && strpos($svPg['body'], 'id="ttlProNote"') !== false && strpos($svPg['body'], 'id="upgradeToProSection"') !== false);
    $svJson = preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $svPg['body'], $sm) ? json_decode($sm[1], true) : null;
    check("sv profile page {$ppName}: ms-i18n is Swedish and carries the expiry texts", is_array($svJson) && ($svJson['profile.hold.started'] ?? null) === 'Pausen har startat'
        && ($svJson['app.common.expiry_free'] ?? null) === 'Gratis' && ($svJson['profile.addresses.created'] ?? null) === 'Skapad: {address}');
    check("sv profile page {$ppName}: the Language card is offered, 'follow my browser' selected", strpos($svPg['body'], 'id="languageCard"') !== false && strpos($svPg['body'], '>Följ min webbläsare</option>') !== false
        && strpos($svPg['body'], '>English</option>') !== false && strpos($svPg['body'], '>Svenska</option>') !== false && strpos($svPg['body'], '<option value="" selected>') !== false);
}
$svPro = ms_pp_page($pp, 'pro', true, 'sv');
check('sv profile page: the Plan card (date in Swedish, billing, link to the Swedish pricing page)', strpos($svPro['body'], 'Du har Pro till 15 juni 2099.') !== false && strpos($svPro['body'], '>Hantera betalning</button>') !== false
    && strpos($svPro['body'], 'Avsluta din prenumeration, byt betalningssätt eller ladda ner fakturor hos Paddle') !== false
    && strpos($svPro['body'], 'Behåll Pro: välj en <a href="/sv/pricing.php">plan</a> eller lös in en värdekod.') !== false
    && strpos($svPro['body'], 'placeholder="Värdekod"') !== false && strpos($svPro['body'], '>Förläng</button>') !== false);
$svReg = ms_pp_page($pp, 'regular', true, 'sv');
check('sv profile page (Regular): free plan and upgrade wording', strpos($svReg['body'], 'Du har den kostnadsfria planen.') !== false && strpos($svReg['body'], 'Byt till Pro: välj en <a href="/sv/pricing.php">plan</a> eller lös in en värdekod.') !== false && strpos($svReg['body'], '>Uppgradera</button>') !== false);
$svLife = ms_pp_page($pp, 'lifetime', true, 'sv');
$svLifeNoJson = preg_replace('#<script type="application/json" id="ms-i18n">.*?</script>#s', '', $svLife['body']);
check('sv profile page (Lifetime): "Du har Pro."', strpos($svLifeNoJson, 'Du har Pro.') !== false && strpos($svLifeNoJson, 'Du har Pro till') === false);
$svRef = ms_pp_page($pp, 'referral', true, 'sv');
check('sv profile page (referral): the Invite friends card, the invited note and the bonus line, numbers from config', strpos($svRef['body'], '>Bjud in vänner</h3>') !== false
    && strpos($svRef['body'], 'Inbjuden av en vän: köp en plan på 12 månader före 31 december 2099 och få 15 månader.') !== false
    && strpos($svRef['body'], 'Ge 3 månader. Få 3 månader. När en vän registrerar sig via din länk och köper en Pro-plan på 12 månader inom 120 dagar får vännen 15 månader Pro – och du får 3 extra månader.') !== false
    && strpos($svRef['body'], 'aria-label="Din inbjudningslänk"') !== false && strpos($svRef['body'], '>Kopiera länk</button>') !== false
    && strpos($svRef['body'], 'Registrerade: 0 · Väntar: 0 · Belönade: 0') !== false && strpos($svRef['body'], 'En belöning läggs till 30 dagar efter din väns betalning.') !== false
    && strpos($svRef['body'], 'Du har 3 bonusadresser från inbjudningar.') !== false);
$svRefLife = ms_pp_page($pp, 'referral_lifetime', true, 'sv');
check('sv profile page (referral, Lifetime): the Lifetime wording', strpos($svRefLife['body'], 'du får 3 fler Sticky-adresser, för alltid. Ingen gräns.') !== false);
// The Language card: the account's own choice is selected.
$svAcct = ms_pp_page($pp, 'pro', true, '', 'sv');
check('profile page: an account with locale sv gets Swedish without Accept-Language, and sv is selected', strpos($svAcct['body'], '<html lang="sv">') !== false && strpos($svAcct['body'], '<option value="sv" selected>Svenska</option>') !== false && strpos($svAcct['body'], '<option value="" selected>') === false);
$enAcct = ms_pp_page($pp, 'pro', true, '', 'en');
check('profile page: an account that chose English gets English with the card, en selected', strpos($enAcct['body'], '<html lang="en">') !== false && strpos($enAcct['body'], '<option value="en" selected>English</option>') !== false && strpos($enAcct['body'], '>Follow my browser</option>') !== false && strpos($enAcct['body'], '>Save language</button>') !== false);
$cardOnly = ms_pp_page($pp, 'regular', true, 'en');
check('profile page: with the app offered in Swedish the English page is the fixture plus the Language card', $ppNorm($cardOnly['body']) !== $ppNorm((string) file_get_contents($fx . 'profile.page.regular.en.html'))
    && strpos($cardOnly['body'], 'id="languageCard"') !== false && strpos($cardOnly['body'], '<option value="" selected>Follow my browser</option>') !== false
    && strpos($cardOnly['body'], '>Language</h3>') !== false && strpos($cardOnly['body'], '>The language Mail Shield is shown in.</p>') !== false);
$noColumn = ms_pp_run($pp, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user' => 'trial'], static function (PDO $pdo, array $ids): void {
    $pdo->exec('ALTER TABLE pro_users DROP COLUMN locale');
}, ['server' => ['HTTP_ACCEPT_LANGUAGE' => 'sv'], 'locales' => 'en,sv', 'app_sv' => true]);
check('profile page: without the locale column there is no Language card (fail-open) and the page still renders', $noColumn['body'] !== '' && strpos($noColumn['body'], 'id="languageCard"') === false && strpos($noColumn['body'], 'id="saveProfileEmailBtn"') !== false);

// pro_profile.php answers.
$ppParams = [['max' => 4], ['domain' => 'manjo.me'], ['min' => 1, 'max' => 7]];
$ppSwedish = function (string $text) use ($en, $ppParams): ?string {
    foreach ($en as $key => $value) {
        if (!is_string($value) || (strncmp($key, 'profile.error.', 14) !== 0 && strncmp($key, 'profile.message.', 16) !== 0 && strncmp($key, 'voucher.error.', 14) !== 0 && strncmp($key, 'service.hold.', 13) !== 0)) {
            continue;
        }
        foreach ($ppParams as $params) {
            if (t($key, $params, 'en') === $text) {
                return t($key, $params, 'sv');
            }
        }
    }
    return null;
};
foreach (array_keys(ms_pp_json_cases()) as $ppName) {
    $fixture = trim((string) file_get_contents($fx . "profile.{$ppName}.en.json"));
    $r = ms_pp_json($pp, $ppName);
    same("profile/{$ppName}: the English answer equals its fixture", $fixture, $r['body']);
    check("profile/{$ppName}: no PHP error", $ppNoPhpError($r), (string) $r['res']['stderr']);
    $enAnswer = json_decode($fixture, true);
    $rSv = ms_pp_json($pp, $ppName, 'sv');
    $svAnswer = $rSv['json'];
    check("profile/{$ppName}: X-MS-Locale sv answers JSON", is_array($svAnswer), $rSv['body']);
    if (!is_array($svAnswer) || !is_array($enAnswer)) {
        continue;
    }
    $text = $enAnswer['error'] ?? $enAnswer['message'] ?? null;
    $expected = is_string($text) ? $ppSwedish($text) : null;
    check("profile/{$ppName}: the English text has a catalog key", $expected !== null, (string) $text);
    same("profile/{$ppName}: Swedish with X-MS-Locale: sv", $expected, $svAnswer['error'] ?? $svAnswer['message'] ?? null);
    unset($enAnswer['error'], $enAnswer['message'], $svAnswer['error'], $svAnswer['message']);
    same("profile/{$ppName}: the rest of the answer is language independent", $enAnswer, $svAnswer);
}
same('profile: the hold limit carries its number in Swedish', 'Du har använt alla 4 pauser i år', ms_pp_json($pp, 'retention_hold_start.used_up', 'sv')['json']['error'] ?? null);
same('profile: the own-domain error carries the domain in Swedish', 'Adresser på manjo.me får inte användas för konton', ms_pp_json($pp, 'update_email.own_domain', 'sv')['json']['error'] ?? null);
same('profile: the TTL error carries both limits in Swedish', 'Livslängden måste vara mellan 1 och 7', ms_pp_json($pp, 'update_ttl.range', 'sv')['json']['error'] ?? null);
same('profile: a voucher error is the voucher.error text in Swedish (step 2)', t('voucher.error.invalid_or_expired_code', [], 'sv'), ms_pp_json($pp, 'upgrade_with_voucher.invalid_or_expired_code', 'sv')['json']['error'] ?? null);
same('profile: X-MS-Locale en keeps an endpoint in English even with Swedish offered', 'Pro required', ms_pp_run($pp, ['page' => 'pro_profile.php', 'method' => 'POST', 'user' => 'regular', 'post' => ['action' => 'update_ttl', 'ttl' => '2']], null,
    ['server' => ['HTTP_X_MS_LOCALE' => 'en'], 'locales' => 'en,sv', 'app_sv' => true])['json']['error'] ?? null);

// set_locale.
$ppLocaleDb = static function (string $user) use ($pp) {
    $pdo = ms_test_refresh_db(ms_test_probe_sqlite($pp['root']));
    $stmt = $pdo->prepare('SELECT locale FROM pro_users WHERE id = ?');
    $stmt->execute([$pp['ids'][$user]]);
    $v = $stmt->fetchColumn();
    return $v === false ? 'ROW MISSING' : $v;
};
$ppSetLocale = static function (string $user, $locale, array $extra = [], ?callable $setup = null, array $request = []) use ($pp): array {
    return ms_pp_run($pp, $request + ['page' => 'pro_profile.php', 'method' => 'POST', 'user' => $user, 'post' => ['action' => 'set_locale', 'locale' => $locale]], $setup,
        $extra + ['server' => [], 'locales' => 'en,sv', 'app_sv' => false]);
};
$dropColumn = static fn(PDO $pdo, array $ids) => $pdo->exec('ALTER TABLE pro_users DROP COLUMN locale');
$r = $ppSetLocale('trial', 'sv');
same('set_locale: sv is stored and answered', ['success' => true, 'locale' => 'sv'], $r['json']);
same('set_locale: sv is in the database', 'sv', $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', '', [], static fn(PDO $pdo, array $ids) => $pdo->exec("UPDATE pro_users SET locale = 'sv' WHERE id = " . (int) $ids['trial']));
same('set_locale: the empty value is NULL ("follow my browser")', ['success' => true, 'locale' => null], $r['json']);
same('set_locale: NULL in the database', null, $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', 'en');
same('set_locale: en is stored when the account chooses it', ['success' => true, 'locale' => 'en'], $r['json']);
same('set_locale: en in the database', 'en', $ppLocaleDb('trial'));
$r = $ppSetLocale('regular', 'sv');
same('set_locale: works for a Regular account (not Pro-gated)', ['success' => true, 'locale' => 'sv'], $r['json']);
same('set_locale: sv stored for the Regular account', 'sv', $ppLocaleDb('regular'));
$r = $ppSetLocale('trial', 'xx');
same('set_locale: an unknown language is refused', ['success' => false, 'error' => 'Invalid language'], $r['json']);
same('set_locale: ... and nothing is stored', null, $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', 'sv', ['locales' => 'en']);
same('set_locale: a language that is not switched on is refused', ['success' => false, 'error' => 'Invalid language'], $r['json']);
same('set_locale: ... and nothing is stored (disabled)', null, $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', ['sv']);
same('set_locale: an array value is refused', ['success' => false, 'error' => 'Invalid language'], $r['json']);
$r = $ppSetLocale('trial', 'sv', [], null, ['origin' => 'http://evil.test']);
same('set_locale: a cross-origin request is refused by the gate', ['success' => false, 'error' => 'Forbidden'], $r['json']);
same('set_locale: ... and nothing is stored (cross-origin)', null, $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', 'sv', ['origin_flip' => true]);
same('set_locale: its own origin check refuses too', ['success' => false, 'error' => 'Invalid request origin'], $r['json']);
same('set_locale: ... and nothing is stored (own check)', null, $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', 'sv', [], null, ['method' => 'GET', 'post' => [], 'get' => ['action' => 'set_locale', 'locale' => 'sv']]);
same('set_locale: a GET is refused', ['success' => false, 'error' => 'Method not allowed'], $r['json']);
same('set_locale: ... and nothing is stored (GET)', null, $ppLocaleDb('trial'));
$r = $ppSetLocale('trial', 'sv', ['server' => ['HTTP_X_MS_LOCALE' => 'sv'], 'app_sv' => true]);
same('set_locale: the success answer is the same in any language', ['success' => true, 'locale' => 'sv'], $r['json']);
$r = $ppSetLocale('trial', 'sv', ['server' => ['HTTP_X_MS_LOCALE' => 'sv'], 'app_sv' => true], $dropColumn);
same('set_locale: without the locale column it answers "not available yet", translated', ['success' => false, 'error' => 'Det går inte att byta språk ännu'], $r['json']);
$r = $ppSetLocale('trial', 'sv', [], $dropColumn);
same('set_locale: ... and in English without a header', ['success' => false, 'error' => 'Changing the language is not available yet'], $r['json']);
$r = ms_pp_run($pp, ['page' => 'pro_profile.php', 'method' => 'POST', 'user' => null, 'post' => ['action' => 'set_locale', 'locale' => 'sv']], null, ['server' => [], 'locales' => 'en,sv', 'app_sv' => false]);
same('set_locale: a signed-out request is refused', ['success' => false, 'error' => 'Not authenticated'], $r['json']);

// Scans.
$profilePageSrc = (string) file_get_contents($root . '/pro_profile_page.php');
$profileSrc = (string) file_get_contents($root . '/pro_profile.php');
check('set_locale: the log carries the user id and the language, never an address', (function () use ($profileSrc): bool {
    $block = substr($profileSrc, (int) strpos($profileSrc, "case 'set_locale':"), 2200);
    return strpos($block, "logMessage('INFO', 'Account language changed', ['user_id' => \$userId, 'locale' => \$storedLocale])") !== false && stripos($block, 'email') === false;
})());
check('scan: pro_profile_page.php requires partials/i18n_js.php with the profile. and expiry prefixes before its first inline script',
    (bool) preg_match("#\\\$msI18nJs = \[[^\]]*'profile\.common\.'[^\]]*'app\.common\.expiry_',\s*\];\s*require __DIR__ \. '/partials/i18n_js\.php';\s*\?>\s*<script>\s*// msT\(key#s", $profilePageSrc)
    && strpos($profilePageSrc, 'partials/app_locale_js.php') !== false);
check('scan: pro_profile_page.php defines msT() once, exactly as I18N.md documents it', substr_count($profilePageSrc, 'function msT(key, fallback, params)') === 1
    && strpos($profilePageSrc, "JSON.parse(document.getElementById('ms-i18n').textContent)") !== false
    && strpos($profilePageSrc, "return text.replace(/\\{(\\w+)\\}/g, function (m, name) {") !== false);
preg_match_all("/(?<![\\w.\$])msT\\('([^']+)'/", $profilePageSrc, $pk);
// A key built at run time ('profile.webhooks.status_' + word) is checked by its own scan further down.
$pageMsKeys = array_values(array_filter(array_unique($pk[1]), static fn(string $k): bool => substr($k, -1) !== '_'));
check('scan: pro_profile_page.php uses msT() keys', count($pageMsKeys) >= 45, (string) count($pageMsKeys));
check('scan: every msT() key of pro_profile_page.php exists in both catalogs and is in a prefix the page hands over',
    array_diff($pageMsKeys, array_keys($en)) === [] && array_diff($pageMsKeys, array_keys($sv)) === []
    && array_filter($pageMsKeys, fn($k) => strncmp($k, 'profile.', 8) !== 0 && strncmp($k, 'app.common.expiry_', 18) !== 0) === [],
    implode(', ', array_merge(array_diff($pageMsKeys, array_keys($en)), array_diff($pageMsKeys, array_keys($sv)))));
preg_match_all("/\b(?:te|t|tp|tHtml)\('((?:profile|service\.hold)\.[a-z_.0-9]+)'/", $profilePageSrc . $profileSrc, $pm);
$ppUsed = array_unique($pm[1]);
check('scan: pro_profile_page.php and pro_profile.php use many profile. keys, all in both catalogs', count($ppUsed) >= 80 && array_diff($ppUsed, array_keys($en)) === [] && array_diff($ppUsed, array_keys($sv)) === [], count($ppUsed) . ' ' . implode(', ', array_diff($ppUsed, array_keys($sv))));
// No literal text left in the Account and Addresses markup (outside PHP tags), nor in visible attributes.
$cardsMarkup = substr($profilePageSrc, (int) strpos($profilePageSrc, '<section class="ms-settings__section" id="settings-account">'));
$cardsMarkup = substr($cardsMarkup, 0, (int) strpos($cardsMarkup, '<section class="ms-settings__section" id="settings-automation">'));
// The only text outside PHP tags is not copy: the "@" before the domain and the counter's initial "0/10" (script-updated).
$cardsText = trim(str_replace(['@', '0/10'], '', preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<\?php.*?\?>/s', ' ', $cardsMarkup)))));
check('scan: the Account and Addresses markup has no literal text outside PHP tags', $cardsText === '', $cardsText);
check('scan: ... nor a literal title/aria-label/placeholder', !preg_match('/\b(?:title|aria-label|placeholder)="[A-Za-z]/', preg_replace('/<\?php.*?\?>/s', '', $cardsMarkup)));
$headMarkup = substr($profilePageSrc, (int) strpos($profilePageSrc, '<title>'));
$headMarkup = substr($headMarkup, 0, (int) strpos($headMarkup, 'id="settings-account"'));
check('scan: the title, heading and rail have no literal text', trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace(['/<\?php.*?\?>/s', '/<!--.*?-->/s', '/<style>.*?<\/style>/s'], ' ', $headMarkup)))) === '');
check("scan: the dates are rendered with i18nDate(), not date('j F Y')", substr_count($profilePageSrc, "date('j F Y'") === 0 && substr_count($profilePageSrc, 'i18nDate(') === 2);
// JS of the cards of this step: messages go in as text; the address list escapes what it writes into HTML.
$jsStart = (int) strpos($profilePageSrc, "\$('#redeemVoucherBtn').on('click'");
$jsMine = substr($profilePageSrc, $jsStart, (int) strpos($profilePageSrc, "\$('#saveProfilePasswordBtn')") - $jsStart);
check('scan: the voucher, email and language handlers put messages in as text (no .html())', strpos($jsMine, '.html(') === false && substr_count($jsMine, 'profileAlert(') >= 8);
$jsBilling = substr($profilePageSrc, (int) strpos($profilePageSrc, "\$('#billingPortalBtn').on('click'"), 1200);
check('scan: the billing handler puts messages in as text', strpos($jsBilling, '.html(') === false && strpos($jsBilling, 'profileAlert(') !== false);
$jsList = substr($profilePageSrc, (int) strpos($profilePageSrc, 'function renderPersonalList('), (int) strpos($profilePageSrc, '// --- Per-address feeds') - (int) strpos($profilePageSrc, 'function renderPersonalList('));
$unescaped = 0;
foreach (explode("\n", $jsList) as $line) {
    // A msT() result concatenated into row HTML must be escaped; .text() / confirm() / alert() calls are not HTML.
    $rest = preg_replace('/escapeHtml\((?:[^()]|\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))*\)/', '', $line);
    if (strpos($rest, 'msT(') !== false && (strpos($rest, "'<") !== false || strpos($rest, '.html(') !== false) && !preg_match('/\.(?:text|attr|val|prop)\(/', $rest)) {
        $unescaped++;
    }
}
check('scan: every msT() text that goes into row HTML in the address list is escaped', $unescaped === 0 && substr_count($jsList, 'escapeHtml(msT(') >= 7, $unescaped . ' ' . substr_count($jsList, 'escapeHtml(msT('));
// pro_profile.php: the shared errors and the actions of this step have no literal user-facing text.
$ppCaseBlock = static function (string $src, string $name): string {
    $start = (int) strpos($src, "case '{$name}':");
    preg_match("/\\n\\s*case '[a-z_]+':\\n/", $src, $mm, PREG_OFFSET_CAPTURE, $start + 5);
    return substr($src, $start, isset($mm[0][1]) ? $mm[0][1] - $start : 4000);
};
foreach (['get_profile', 'update_email', 'resend_pending_change', 'cancel_pending_change', 'upgrade_with_voucher', 'billing_portal', 'update_ttl', 'retention_hold_start', 'retention_hold_end', 'set_locale', 'get_ttl', 'retention_hold_status'] as $action) {
    $block = preg_replace('/logMessage\([^;]*;/s', '', $ppCaseBlock($profileSrc, $action));
    check("scan: pro_profile.php action {$action} has no literal 'error' / 'message' text", $block !== '' && !preg_match("/'(?:error|message)' => ['\"]/", $block), $action);
}
$profileTop = substr($profileSrc, 0, (int) strpos($profileSrc, 'function proProfileRetentionStatus'));
check("scan: pro_profile.php's shared errors at the top are translated", !preg_match("/'error' => '(?:Not authenticated|Forbidden|Pro required)'/", $profileTop) && substr_count($profileTop, "t('profile.error.") >= 4);
check("scan: pro_profile.php's default case is translated", strpos($profileSrc, "'error' => t('profile.error.unknown_action')") !== false);
check('scan: upgrade_with_voucher uses t(voucherRedemptionErrorKey(...))', strpos($ppCaseBlock($profileSrc, 'upgrade_with_voucher'), "t(voucherRedemptionErrorKey(\$result['error_code']))") !== false);
check('scan: the retention-hold actions use i18nServiceError()', substr_count($ppCaseBlock($profileSrc, 'retention_hold_start') . $ppCaseBlock($profileSrc, 'retention_hold_end'), 'i18nServiceError(') === 2);
check('scan: set_locale is not Pro-gated and not in the read-only list', strpos($ppCaseBlock($profileSrc, 'set_locale'), 'require_pro') === false && !preg_match("/proProfileReadOnlyActions = \[[^\]]*set_locale/s", $profileSrc));

// retention_hold.php: every returned user-facing error has an error_key whose English text is the literal.
$rhLines = file($root . '/retention_hold.php', FILE_IGNORE_NEW_LINES) ?: [];
$rhReturns = 0;
foreach ($rhLines as $n => $line) {
    if (strpos($line, "'ok' => false") === false || strpos($line, "'error' =>") === false || strpos($line, 'logMessage') !== false || ltrim($line)[0] === '*') {
        continue;
    }
    $rhReturns++;
    $label = 'scan: retention_hold.php line ' . ($n + 1);
    if (!preg_match("/'error_key' => '(service\.hold\.[a-z_]+)'/", $line, $km)) {
        check($label . ' has an error_key', false, trim($line));
        continue;
    }
    check($label . ' has an error_key that exists in lang/en.php and lang/sv.php', isset($en[$km[1]]) && isset($sv[$km[1]]), $km[1]);
    if (preg_match("/'error' => '([^']*)'(?:,|\])/", $line, $lm)) {
        same($label . ': t(error_key, [], en) equals the literal', $lm[1], t($km[1], [], 'en'));
    } elseif (preg_match('/\'error\' => "([^"]*)"/', $line, $lm)) {
        check($label . ' (a composed message) carries error_params', strpos($line, "'error_params' => ['max' => \$max]") !== false, trim($line));
        same($label . ': t(error_key, error_params, en) equals the composed literal', str_replace('{$max}', '4', $lm[1]), t($km[1], ['max' => 4], 'en'));
    }
}
check('scan: retention_hold.php returns 5 user-facing errors', $rhReturns === 5, (string) $rhReturns);
// The service itself, for real: the errors keep their English text and gain their key.
$rhPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$rhPdo->exec('CREATE TABLE retention_holds (id INTEGER PRIMARY KEY AUTOINCREMENT, pro_user_id INTEGER NOT NULL, started_at TEXT NOT NULL, ends_at TEXT NOT NULL, ended_at TEXT NULL)');
$rhPdo->exec('CREATE TABLE temp_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_address TEXT, pro_user_id INTEGER, is_personal INTEGER, expires_at TEXT)');
$rhPdo->exec('CREATE TABLE stored_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, to_address TEXT, temp_email_id INTEGER, received_at TEXT, expires_at TEXT)');
require_once $root . '/retention_hold.php';
retentionHoldStart($rhPdo, 7);
$second = retentionHoldStart($rhPdo, 7);
same('service: a second hold keeps its English error', 'A hold is already active', $second['error'] ?? null);
same('service: ... and carries its key', 'service.hold.already_active', $second['error_key'] ?? null);
same('service: no params on a message without a value', false, isset($second['error_params']));
$noneEnded = retentionHoldEnd($rhPdo, 99);
same('service: ending when none is active keeps its English error and key', ['No active hold', 'service.hold.none_active'], [$noneEnded['error'] ?? null, $noneEnded['error_key'] ?? null]);
i18nSetLocale('sv');
same('service: i18nServiceError() translates the hold error', 'En paus är redan aktiv', i18nServiceError($second, 'x'));
i18nSetLocale('en');
// --- Profile B (epic #428, step 5/8): the Automation section ---------------------------------------
// The Webhooks, RSS feed and Digest emails cards, the per-address RSS and hooks buttons, and the
// pro_profile.php actions behind them. The English fixtures were rendered (tests/lib/profile_probe.php)
// from the code as it was before the cards went through te()/tHtml(); the page's inline script is
// checked by the scans below. Credentials (a webhook secret, a Pushover token and user key, a custom
// header value, a feed token) never reach a catalog string, a placeholder or a log line.
$autoIds = ['whName', 'whKind', 'whUrl', 'whConfig', 'whSecret', 'whCreateBtn', 'whProNote', 'whMsg', 'webhookList', 'feedUrlInput', 'copyFeedToken', 'openFeedBtn', 'regenFeedToken', 'feedProNote', 'digestEnabled', 'digestFrequency', 'digestHour', 'digestTz', 'digestNote'];
$autoKeyRe = '/\bprofile\.(?:automation|webhooks|feeds|digest)\.[a-z_0-9]+/';
$autoSections = [];
foreach (array_keys(ms_pp_automation_page_cases()) as $apName) {
    $fixture = $ppNorm((string) file_get_contents($fx . "profile.automation.{$apName}.en.html"));
    $r = ms_pp_automation_page($pp, $apName);
    check("automation {$apName}: rendered without PHP errors", $r['section'] !== '' && $ppNoPhpError($r), (string) $r['res']['stderr']);
    same("fixture: Automation cards ({$apName}), English only", $fixture, $ppNorm($r['section']));
    $svA = ms_pp_automation_page($pp, $apName, true, 'sv');
    $autoSections[$apName] = $svA['section'];
    check("sv automation {$apName}: rendered without PHP errors", $svA['section'] !== '' && $ppNoPhpError($svA), (string) $svA['res']['stderr']);
    check("sv automation {$apName}: no raw profile. key in the cards", !preg_match($autoKeyRe, $svA['section']), (string) (preg_match($autoKeyRe, $svA['section'], $km) ? $km[0] : ''));
    $svNoJson = preg_replace('#<script type="application/json" id="ms-i18n">.*?</script>#s', '', $svA['body']);
    $svNoJson = preg_replace("/msT\\('(?:profile|app\\.common)\\.[a-z_.0-9]+'/", "msT('", $svNoJson);
    check("sv automation {$apName}: no raw profile. key anywhere but the language JSON", !preg_match($autoKeyRe, $svNoJson), (string) (preg_match($autoKeyRe, $svNoJson, $km) ? $km[0] : ''));
    $sec = $svA['section'];
    check("sv automation {$apName}: the Webhooks card is Swedish",
        strpos($sec, '<h3 class="ms-card__title">Webhooks</h3>') !== false
        && strpos($sec, 'Skicka varje inkommande meddelande som JSON med POST till din egen endpoint, eller skicka det till din telefon med Pushover.') !== false
        && strpos($sec, '>Namn (valfritt)</label>') !== false && strpos($sec, 'placeholder="Min Pushover"') !== false && strpos($sec, '>Typ</label>') !== false
        && strpos($sec, '>Generell (POST JSON)</option>') !== false && strpos($sec, '>URL / endpoint</label>') !== false && strpos($sec, '>Konfiguration (JSON)</label>') !== false
        && strpos($sec, '<code>token</code> och <code>user</code> krävs') !== false && strpos($sec, '<code>{"headers":{"Authorization":"Bearer ..."}}</code>') !== false
        && strpos($sec, '>Hemlighet (valfri)</label>') !== false && strpos($sec, 'placeholder="Valfri signeringshemlighet"') !== false
        && strpos($sec, 'huvudet <code>X-TempMail-Signature</code> med sha256-HMAC.') !== false
        && strpos($sec, '>Skapa webhook</button>') !== false && strpos($sec, '>Webhooks kräver ett Pro-konto.</div>') !== false && strpos($sec, '>Dina webhooks</h4>') !== false);
    check("sv automation {$apName}: the RSS feed card is Swedish",
        strpos($sec, '<h3 class="ms-card__title">RSS-flöde</h3>') !== false && strpos($sec, 'omfattar <strong>alla</strong> dina adresser') !== false
        && strpos($sec, 'placeholder="(läser in...)"') !== false && strpos($sec, 'aria-label="Flödesåtgärder"') !== false
        && strpos($sec, '>Kopiera</button>') !== false && strpos($sec, '>Öppna</button>') !== false && strpos($sec, '>Skapa ny</button>') !== false
        && strpos($sec, 'Håll token-delen av webbadressen hemlig.') !== false && strpos($sec, '>RSS-flödet kräver ett Pro-konto.</div>') !== false);
    check("sv automation {$apName}: the Digest emails card is Swedish, numbers from the call",
        strpos($sec, '<h3 class="ms-card__title">Sammanfattningsmejl</h3>') !== false && strpos($sec, '>Aktivera sammanfattningsmejl</label>') !== false
        && strpos($sec, '>Frekvens (dagar)</label>') !== false && strpos($sec, '>Sändningstid (timme)</label>') !== false && strpos($sec, '>Tidszon</label>') !== false
        && strpos($sec, 'Sammanfattningar skickas högst en gång per 24 timmar och inte oftare än 1 dag. Frekvensen kan inte vara högre än adressens livslängd minus 1 dag.') !== false
        && strpos($sec, 'lägg till <strong>noreply@manjo.me</strong> i din adressbok') !== false);
    check("sv automation {$apName}: the lede is Swedish", strpos($sec, 'Skicka din e-post vidare när den kommer, eller läs den någon helt annanstans.') !== false);
    $missingIds = array_filter($autoIds, static fn(string $id): bool => strpos($sec, 'id="' . $id . '"') === false);
    check("sv automation {$apName}: ids the scripts depend on survive", $missingIds === [], implode(', ', $missingIds));
    $svJsonA = preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $svA['body'], $sm) ? json_decode($sm[1], true) : null;
    check("sv automation {$apName}: ms-i18n carries the script's Swedish texts",
        is_array($svJsonA) && ($svJsonA['profile.webhooks.badge_paused'] ?? null) === 'Pausad' && ($svJsonA['profile.webhooks.delivery_line'] ?? null) === '[{status}] försök={attempts} skapad={created}'
        && ($svJsonA['profile.feeds.row_rss_on'] ?? null) === 'RSS på' && ($svJsonA['profile.automation.hooks_paused'] ?? null) === 'Hooks pausade'
        && ($svJsonA['profile.digest.pro_required'] ?? null) === 'Sammanfattningsmejl kräver ett Pro-konto.');
}
same('automation: the hook list is drawn by the script, so the cards are the same with and without hooks (Swedish)', $ppNorm($autoSections['none']), $ppNorm($autoSections['hooks']));
same('fixtures: the two Automation fixtures are the same markup', $ppNorm((string) file_get_contents($fx . 'profile.automation.none.en.html')), $ppNorm((string) file_get_contents($fx . 'profile.automation.hooks.en.html')));
check('automation: no webhook secret, Pushover token or user key, or header value reaches the page', (function () use ($pp): bool {
    $r = ms_pp_automation_page($pp, 'hooks', true, 'sv');
    return strpos($r['body'], MS_TEST_FAKE_TOKEN) === false && strpos($r['body'], MS_TEST_FAKE_USER_KEY) === false;
})());

// pro_profile.php answers of the Automation actions.
$autoPrefixes = ['profile.automation.', 'profile.webhooks.', 'profile.feeds.', 'profile.digest.', 'profile.error.'];
$autoParams = [[], ['days' => 1], ['max' => 100], ['max' => 2048], ['max' => '4KB']];
$autoSwedish = static function (string $text) use ($en, $autoPrefixes, $autoParams): array {
    $found = [];
    foreach ($en as $key => $value) {
        if (!is_string($value)) {
            continue;
        }
        foreach ($autoPrefixes as $prefix) {
            if (strncmp($key, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            foreach ($autoParams as $params) {
                if (t($key, $params, 'en') === $text) {
                    $found[t($key, $params, 'sv')] = $key;
                }
            }
        }
    }
    return $found;
};
// Messages the code relays from php_imap_processor.php's header validation: English, naming only a header name.
$autoUntranslated = ['webhook_create.header_reserved', 'webhook_create.header_name', 'webhook_create.headers_not_object'];
foreach (array_keys(ms_pp_automation_json_cases()) as $apName) {
    $fixture = trim((string) file_get_contents($fx . "profile.{$apName}.en.json"));
    $r = ms_pp_json($pp, $apName);
    same("profile/{$apName}: the English answer equals its fixture", $fixture, $r['body']);
    check("profile/{$apName}: no PHP error", $ppNoPhpError($r), (string) $r['res']['stderr']);
    $enAnswer = json_decode($fixture, true);
    $rSv = ms_pp_json($pp, $apName, 'sv');
    $svAnswer = $rSv['json'];
    check("profile/{$apName}: X-MS-Locale sv answers JSON", is_array($svAnswer), $rSv['body']);
    $text = $enAnswer['error'] ?? $enAnswer['message'] ?? null;
    if (in_array($apName, $autoUntranslated, true)) {
        same("profile/{$apName}: a validation message from the webhook headers stays English", $text, $svAnswer['error'] ?? null);
    } else {
        $candidates = $autoSwedish((string) $text);
        check("profile/{$apName}: the English text has a catalog key", $candidates !== [], (string) $text);
        check("profile/{$apName}: the Swedish answer is the catalog text, and it is Swedish", isset($candidates[$svAnswer['error'] ?? '']) && count($candidates) === 1 && array_key_first($candidates) !== $text, json_encode($candidates) . ' vs ' . ($svAnswer['error'] ?? ''));
    }
    $enRest = $enAnswer;
    $svRest = $svAnswer;
    unset($enRest['error'], $svRest['error']);
    same("profile/{$apName}: the rest of the answer is language independent", $enRest, $svRest);
}
same('profile: the digest TTL error carries its number in Swedish', 'Sammanfattningar tillåts inte när adressens livslängd är 1 dag', ms_pp_json($pp, 'update_digest_settings.ttl_one', 'sv')['json']['error'] ?? null);
same('profile: the signing-secret error carries its limit in Swedish', 'Signeringshemligheten är för lång (högst 100 tecken)', ms_pp_json($pp, 'webhook_create.secret_too_long', 'sv')['json']['error'] ?? null);
same('profile: the config error carries its limit in Swedish', 'Konfigurationen är för stor (högst 4KB)', ms_pp_json($pp, 'webhook_create.config_too_large', 'sv')['json']['error'] ?? null);
same('profile: the per-action origin error is the shared text in Swedish', t('profile.error.invalid_origin', [], 'sv'), ms_pp_json($pp, 'address_hooks_pause.invalid_origin', 'sv')['json']['error'] ?? null);
check('profile: no answer of these actions carries a secret, token or key (the failed create keeps the text only)', (function () use ($pp): bool {
    $body = ms_pp_json($pp, 'webhook_create.credentials_unavailable', 'sv')['body'] . ms_pp_json($pp, 'feed_get_token.undecryptable', 'sv')['body'];
    return strpos($body, 'not-a-valid-ciphertext') === false && strpos($body, '"token"') === false;
})());

// Scans.
$autoMsKeys = array_values(array_filter($pageMsKeys, static fn(string $k): bool => preg_match('/^profile\.(?:automation|webhooks|feeds|digest)\./', $k) === 1));
check('scan: the Automation script uses many msT() keys', count($autoMsKeys) >= 45, (string) count($autoMsKeys));
// Every msT() key with its English fallback: the fallback is the English catalog text.
preg_match_all("/(?<![\\w.\$])msT\\('(profile\\.(?:automation|webhooks|feeds|digest)\\.[a-z_0-9]+)', '((?:[^'\\\\]|\\\\.)*)'/", $profilePageSrc, $fb, PREG_SET_ORDER);
$badFallback = [];
foreach ($fb as [$all, $fbKey, $fbText]) {
    if (!isset($en[$fbKey]) || $en[$fbKey] !== stripslashes($fbText)) {
        $badFallback[] = $fbKey;
    }
}
check('scan: every msT() fallback of the Automation script is the English catalog text', count($fb) >= 45 && $badFallback === [], implode(', ', $badFallback));
check('scan: the delivery-status words are catalog keys equal to the stored words', array_filter(['pending', 'succeeded', 'success', 'failed'], static fn(string $w): bool => ($en['profile.webhooks.status_' . $w] ?? null) !== $w) === []
    && strpos($profilePageSrc, "msT('profile.webhooks.status_' + word, word)") !== false && strpos($profilePageSrc, 'function deliveryStatusLabel(status)') !== false);
// The Automation markup: no literal text outside PHP tags, nor a literal title/aria-label/placeholder (the URL example is not copy).
$autoMarkup = substr($profilePageSrc, (int) strpos($profilePageSrc, '<section class="ms-settings__section" id="settings-automation">'));
$autoMarkup = substr($autoMarkup, 0, (int) strpos($autoMarkup, '</section>'));
$autoText = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<\?php.*?\?>/s', ' ', $autoMarkup))));
check('scan: the Automation markup has no literal text outside PHP tags', $autoText === '', $autoText);
check('scan: ... nor a literal title/aria-label/placeholder', !preg_match('/\b(?:title|aria-label|placeholder)="[A-Za-z]/', str_replace('placeholder="https://example.com/webhook"', '', preg_replace('/<\?php.*?\?>/s', '', $autoMarkup))));
check('scan: ... and the card titles, descriptions and notes use the catalog keys', substr_count($autoMarkup, "te('profile.") + substr_count($autoMarkup, "tHtml('profile.") >= 35);
// A msT() text that goes into HTML is escaped; everything else is put in with .text(), alert() or confirm().
$unescapedHtml = [];
foreach (explode("\n", $profilePageSrc) as $i => $line) {
    if (strpos($line, 'msT(') === false || (strpos($line, '.html(') === false && strpos($line, "'<") === false)) {
        continue;
    }
    // Take away every escapeHtml(...) call (three levels of nested parentheses are enough here); a msT() left over is raw.
    $rest = preg_replace('/escapeHtml\((?:[^()]|\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))*\)/', '', $line);
    if (strpos($rest, 'msT(') !== false && preg_match('/\.html\(|\'<|"<|\+\s*\'</', $rest) && !preg_match('/\.(?:text|attr|val|prop)\(/', $rest)) {
        $unescapedHtml[] = ($i + 1) . ': ' . trim($line);
    }
}
check('scan: no msT() text is concatenated into HTML without escapeHtml()', $unescapedHtml === [], implode(' | ', array_slice($unescapedHtml, 0, 3)));
check('scan: the webhook form puts its messages in as text', substr_count($profilePageSrc, 'function whSay(cls, text)') === 1 && strpos($profilePageSrc, "\$('#whMsg').html(") === false && substr_count($profilePageSrc, 'whSay(') >= 6);
$deliveriesJs = substr($profilePageSrc, (int) strpos($profilePageSrc, "\$(document).on('click', '.wh-deliveries'"), 2600);
check('scan: the delivery log escapes the status, the attempts, the date and the error', substr_count($deliveriesJs, 'escapeHtml(msT(') >= 4 && strpos($deliveriesJs, "escapeHtml(d.status)") === false);
check('scan: applyProGating() takes the digest note from the catalog', strpos($profilePageSrc, "\$('#digestNote').text('Digest emails require") === false && strpos($profilePageSrc, "msT('profile.digest.pro_required'") !== false);
check('scan: the per-address RSS and hooks button texts exist only as msT() fallbacks', substr_count($profilePageSrc, "'Hooks paused'") === substr_count($profilePageSrc, "msT('profile.automation.hooks_paused', 'Hooks paused')")
    && substr_count($profilePageSrc, "'RSS on'") === substr_count($profilePageSrc, "msT('profile.feeds.row_rss_on', 'RSS on')") && substr_count($profilePageSrc, "msT('profile.feeds.row_rss_on'") >= 2);
check('scan: no confirm() or alert() in the Automation script has a literal English text', !preg_match("/(?:confirm|alert)\\('[A-Za-z]/", $deliveriesJs . substr($profilePageSrc, (int) strpos($profilePageSrc, '// --- Per-address feeds'), (int) strpos($profilePageSrc, '// Delete account handler') - (int) strpos($profilePageSrc, '// --- Per-address feeds'))));

// pro_profile.php: no literal user-facing text in the listed actions.
$autoActions = ['webhooks_list', 'webhook_create', 'webhook_delete', 'webhook_set_filter_mode', 'webhook_set_addresses', 'webhook_deliveries', 'feed_get_token', 'feed_regenerate', 'address_feed_get_token', 'address_feed_regenerate', 'address_feed_disable', 'address_hooks_resume', 'get_digest_settings', 'update_digest_settings'];
foreach ($autoActions as $action) {
    // The test delivery's payload message is sent to the user's endpoint, not shown to the user.
    $block = preg_replace(['/logMessage\([^;]*;/s', "/'message' => 'This is a test delivery for your newly created webhook\\.',/"], '', $ppCaseBlock($profileSrc, $action));
    check("scan: pro_profile.php action {$action} has no literal 'error' / 'message' text", $block !== '' && !preg_match("/'(?:error|message)' => ['\"]/", $block), $action);
}
check('scan: address_hooks_pause and address_hooks_resume share one translated block', strpos($profileSrc, "case 'address_hooks_pause':\n        case 'address_hooks_resume':") !== false);
check('scan: the webhook action errors use the profile.webhooks. / profile.feeds. / profile.digest. / profile.automation. keys',
    substr_count($profileSrc, "t('profile.webhooks.") >= 22 && substr_count($profileSrc, "t('profile.feeds.") >= 9 && substr_count($profileSrc, "t('profile.digest.") >= 4 && substr_count($profileSrc, "t('profile.automation.") >= 3);
// Credentials: no t(), te() or tHtml() call in either file is given a parameter named secret, token, user_key or headers.
$credentialCalls = 0;
$credentialHits = [];
foreach ([['pro_profile.php', $profileSrc], ['pro_profile_page.php', $profilePageSrc]] as [$file, $src]) {
    preg_match_all('/(?<![\w>$:.])(?:tHtml|te|t|tp)\(/', $src, $cm, PREG_OFFSET_CAPTURE);
    foreach ($cm[0] as [$call, $offset]) {
        $depth = 0;
        $quote = '';
        $end = $offset;
        for ($pos = $offset + strlen($call) - 1, $len = strlen($src); $pos < $len; $pos++) {
            $ch = $src[$pos];
            if ($quote !== '') {
                if ($ch === '\\') { $pos++; } elseif ($ch === $quote) { $quote = ''; }
                continue;
            }
            if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
            if ($ch === '(') { $depth++; }
            if ($ch === ')' && --$depth === 0) { $end = $pos; break; }
        }
        $text = substr($src, $offset, $end - $offset + 1);
        $credentialCalls++;
        if (preg_match('/[\'"](?:secret|token|user_key|headers)[\'"]\s*=>/', $text)) {
            $credentialHits[] = $file . ': ' . substr($text, 0, 80);
        }
    }
}
check('scan: no t(), te() or tHtml() call in pro_profile.php or pro_profile_page.php is given a secret, token, user_key or headers parameter', $credentialCalls > 150 && $credentialHits === [], $credentialCalls . ' ' . implode(' | ', $credentialHits));
check('scan: no catalog string of the Automation cards holds a credential placeholder', (function () use ($en): bool {
    foreach ($en as $key => $value) {
        if (is_string($value) && preg_match('/^profile\.(?:automation|webhooks|feeds|digest)\./', $key) && preg_match('/\{(?:secret|token|user_key|headers|user|key)\}/', $value)) {
            return false;
        }
    }
    return true;
})());

// --- Profile C (epic #428, step 6/8): Security, Client Agent and Delete account ----------------------
// Stay signed in, Connected apps, Two-factor authentication, Password, Client signing keys and Delete
// account, and the pro_profile.php actions behind them. The English fixtures were rendered
// (tests/lib/profile_probe.php) from the code as it was before the cards went through te()/tHtml(). The
// one-time token, the recovery codes, the TOTP secret and QR data and any password are separate fields of
// the JSON and are never a parameter of a catalog string; an app's own name stays data put in with .text().
$secIds = ['staySignedInCard', 'staySignedInAlert', 'staySignedInUnavailable', 'staySignedInControls', 'staySignedInSelect', 'staySignedInSaveBtn', 'staySignedInEmpty', 'staySignedInList', 'staySignedInRevokeOthersBtn',
    'twoFactorSection', 'tfaStatusLine', 'tfaNoPasswordNote', 'tfaAlert', 'tfaDisabledState', 'tfaEnableBtn', 'tfaEnrollStep', 'tfaQrContainer', 'tfaManualKey', 'tfaCopyKeyBtn', 'tfaCodeInput', 'tfaConfirmBtn', 'tfaCancelEnrollBtn',
    'tfaRecoveryCodesBox', 'tfaRecoveryCodesList', 'tfaCopyRecoveryBtn', 'tfaDownloadRecoveryBtn', 'tfaSavedCodesCheck', 'tfaCloseRecoveryBtn', 'tfaEnabledState', 'tfaLowCodesWarning', 'tfaCodesLeftCount', 'tfaRegenBtn', 'tfaDisableBtn',
    'tfaTrustedDevicesSection', 'tfaTrustedDevicesAlert', 'tfaTrustedDevicesEmpty', 'tfaTrustedDevicesList', 'tfaRevokeAllDevicesBtn', 'tfaRegenForm', 'tfaRegenCodeInput', 'tfaRegenConfirmBtn', 'tfaRegenCancelBtn',
    'tfaDisableForm', 'tfaDisablePasswordField', 'tfaDisablePasswordInput', 'tfaDisableConfirmBtn', 'tfaDisableCancelBtn', 'proPassword', 'proPasswordConfirm', 'saveProfilePasswordBtn', 'rotateSigningKeysBtn', 'signingKeysProNote', 'deleteAccountBtn'];
$secAppsIds = ['connectedAppsCard', 'connectedAppsAlert', 'connectedAppsGrantsEmpty', 'connectedAppsGrantList', 'connectedAppsName', 'connectedAppsScope', 'connectedAppsExpiry', 'connectedAppsCreateBtn', 'connectedAppsProNote',
    'connectedAppsNewToken', 'connectedAppsNewTokenValue', 'connectedAppsCopyBtn', 'connectedAppsEmpty', 'connectedAppsList'];
$secKeyRe = '/\bprofile\.(?:security|apps|twofa|password|agent|delete)\.[a-z_0-9]+/';
$secSections = [];
$secFixtures = [];
foreach (array_keys(ms_pp_security_page_cases()) as $spName) {
    $fixtureRaw = (string) file_get_contents($fx . "profile.security.{$spName}.en.html");
    $secFixtures[$spName] = $ppNorm($fixtureRaw);
    $r = ms_pp_security_page($pp, $spName);
    check("security {$spName}: rendered without PHP errors", $r['section'] !== '' && $ppNoPhpError($r), (string) $r['res']['stderr']);
    same("fixture: Security, Client Agent and Delete sections ({$spName}), English only", $secFixtures[$spName], $ppNorm($r['section']));
    $oauthOn = strncmp($spName, 'oauth_on', 8) === 0;
    $unavailable = $spName === 'tokens_unavailable';
    $enCard = substr($r['section'], (int) strpos($r['section'], 'id="connectedAppsCard"'));
    $enCard = preg_replace('/<!--.*?-->/s', '', substr($enCard, 0, (int) strpos($enCard, 'Two-factor authentication</h3>')));
    check("security {$spName}: the English Connected apps card " . ($oauthOn ? 'leads with the sign-in' : 'says tokens and nothing else'),
        $oauthOn ? strpos($enCard, 'in your app and sign in when it asks') !== false : (preg_match('/sign.?in/i', $enCard) === 0 && ($unavailable || strpos($enCard, 'Point your app at') !== false)));
    // Swedish.
    $svS = ms_pp_security_page($pp, $spName, true, 'sv');
    $secSections[$spName] = $svS['section'];
    $sec = $svS['section'];
    check("sv security {$spName}: rendered without PHP errors", $sec !== '' && $ppNoPhpError($svS), (string) $svS['res']['stderr']);
    check("sv security {$spName}: no raw profile. key in the sections", !preg_match($secKeyRe, $sec), (string) (preg_match($secKeyRe, $sec, $km) ? $km[0] : ''));
    $svNoJson = preg_replace('#<script type="application/json" id="ms-i18n">.*?</script>#s', '', $svS['body']);
    $svNoJson = preg_replace("/msT\\('(?:profile|app\\.common)\\.[a-z_.0-9]+'/", "msT('", $svNoJson);
    check("sv security {$spName}: no raw profile. key anywhere but the language JSON", !preg_match($secKeyRe, $svNoJson), (string) (preg_match($secKeyRe, $svNoJson, $km) ? $km[0] : ''));
    check("sv security {$spName}: the lede and the Stay signed in card are Swedish",
        strpos($sec, 'Koder, enheter och hur en inloggning med lösenord verifieras.') !== false && strpos($sec, '<h3 class="ms-card__title">Förbli inloggad</h3>') !== false
        && strpos($sec, 'upp till 90 dagar efter att du loggade in.') !== false && strpos($sec, '>Håll den här enheten inloggad</label>') !== false
        && strpos($sec, '<option value="0">Tills jag stänger webbläsaren</option>') !== false && strpos($sec, '<option value="1">I 1 dag</option>') !== false
        && strpos($sec, '<option value="7">I 7 dagar</option>') !== false && strpos($sec, '<option value="30">I 30 dagar</option>') !== false
        && strpos($sec, 'Välj bara en period på en enhet som är din.') !== false && strpos($sec, '>Spara</button>') !== false
        && strpos($sec, '>Enheter som hålls inloggade</h4>') !== false && strpos($sec, '>Inga enheter hålls inloggade.</p>') !== false && strpos($sec, '>Ta bort alla andra enheter</button>') !== false);
    $svCard = substr($sec, (int) strpos($sec, 'id="connectedAppsCard"'));
    $svCard = preg_replace('/<!--.*?-->/s', '', substr($svCard, 0, (int) strpos($svCard, 'Tvåfaktorsautentisering</h3>')));
    if ($unavailable) {
        check("sv security {$spName}: the Connected apps card says it is not available yet", strpos($svCard, '<p class="text-muted" id="connectedAppsUnavailable">Inte tillgängligt ännu.</p>') !== false && strpos($svCard, '>Anslutna appar</h3>') !== false && strpos($svCard, 'id="connectedAppsCreateBtn"') === false);
    } else {
        check("sv security {$spName}: the Connected apps card is Swedish",
            strpos($svCard, '>Anslutna appar</h3>') !== false && strpos($svCard, '>Åtkomsttoken</h4>') !== false && strpos($svCard, 'placeholder="Till exempel: Claude Desktop"') !== false
            && strpos($svCard, '>Namn</label>') !== false && strpos($svCard, '>Åtkomst</label>') !== false && strpos($svCard, '<option value="read" selected>Endast läsning</option>') !== false
            && strpos($svCard, '<option value="read,write">Läsa och ändra</option>') !== false && strpos($svCard, '>Går ut</label>') !== false && strpos($svCard, '<option value="0" selected>Aldrig</option>') !== false
            && strpos($svCard, '<option value="30">Efter 30 dagar</option>') !== false && strpos($svCard, '<option value="365">Efter 365 dagar</option>') !== false
            && strpos($svCard, '>Skapa åtkomsttoken</button>') !== false && strpos($svCard, '>Du behöver ett Pro-konto för att skapa åtkomsttoken.</div>') !== false
            && strpos($svCard, '<strong>Kopiera den här åtkomsttoken nu.</strong> Den visas bara den här gången') !== false && strpos($svCard, '>Inga åtkomsttoken ännu.</p>') !== false
            && strpos($svCard, '>Så ansluter du</h4>') !== false && strpos($svCard, '<p class="form-text mb-1">Claude Code</p>') !== false
            && strpos($svCard, 'lägg till i <code>claude_desktop_config.json</code> (kräver Node.js)') !== false
            && strpos($svCard, 'Ersätt <code>&lt;token&gt;</code> med den token du fick när du skapade den.') !== false
            && strpos($svCard, 'claude mcp add --transport http mailshield http://localhost:8085/mcp --header "Authorization: Bearer &lt;token&gt;"') !== false);
        if ($oauthOn) {
            check("sv security {$spName}: OAuth on, the card leads with the sign-in and keeps the token route",
                strpos($svCard, 'Lägg till <code>http://localhost:8085/mcp</code> i din app och logga in när den ber om det') !== false
                && strpos($svCard, 'För en klient som bara accepterar ett HTTP-huvud ger du den i stället en av dina åtkomsttoken:') !== false
                && strpos($svCard, 'De du anslutit med en inloggning och de åtkomsttoken du skapat själv visas båda här') !== false
                && strpos($svCard, 'Inga appar är anslutna ännu. En app med en knapp för att ansluta eller logga in visas här när du godkänt den.') !== false);
        } else {
            check("sv security {$spName}: OAuth off, the card says tokens and nothing else",
                strpos($svCard, 'Peka din app mot <code>http://localhost:8085/mcp</code> och ge den en av dina åtkomsttoken som bearer-token.') !== false
                && strpos($svCard, 'De åtkomsttoken du skapat själv visas här, var och en begränsad till den åtkomst du tillät.') !== false
                && strpos($svCard, '>Inga appar är anslutna ännu.</p>') !== false
                && preg_match('/logga in|inloggning|logg in/iu', $svCard) === 0 && strpos($svCard, 'HTTP-huvud') === false);
        }
        $missingApps = array_filter($secAppsIds, static fn(string $id): bool => strpos($svCard, 'id="' . $id . '"') === false);
        check("sv security {$spName}: Connected apps ids the script depends on survive", $missingApps === [], implode(', ', $missingApps));
    }
    check("sv security {$spName}: the Two-factor authentication and Password cards are Swedish",
        strpos($sec, '<h3 class="ms-card__title">Tvåfaktorsautentisering</h3>') !== false && strpos($sec, 'En kod från din autentiseringsapp, utöver ditt lösenord.') !== false
        && strpos($sec, '>Läser in status...</p>') !== false && strpos($sec, '<a href="#proPassword">ange ett nedan</a> för att aktivera skyddet efter registreringen.') !== false
        && strpos($sec, '>Aktivera 2FA</button>') !== false && strpos($sec, 'Skanna med 1Password, Authy, Google Authenticator eller en liknande app.') !== false
        && strpos($sec, '>Kan du inte skanna?</summary>') !== false && strpos($sec, '>Ange den sexsiffriga koden från din app</label>') !== false
        && strpos($sec, '>Bekräfta och aktivera</button>') !== false && strpos($sec, '>Avbryt</button>') !== false
        && strpos($sec, '<strong>Spara de här återställningskoderna nu.</strong> De visas bara den här gången.') !== false
        && strpos($sec, '>Kopiera alla</button>') !== false && strpos($sec, '>Ladda ner som .txt</button>') !== false && strpos($sec, '>Jag har sparat mina koder</label>') !== false && strpos($sec, '>Klart</button>') !== false
        && strpos($sec, 'Du har <span id="tfaCodesLeftCount"></span> återställningskoder kvar.') !== false && strpos($sec, '>Skapa nya koder</button>') !== false && strpos($sec, '>Inaktivera 2FA</button>') !== false
        && strpos($sec, '>Betrodda enheter</h4>') !== false && strpos($sec, 'hoppar över koden i 30 dagar.') !== false && strpos($sec, '>Inga betrodda enheter.</p>') !== false && strpos($sec, '>Återkalla alla</button>') !== false
        && strpos($sec, '>Ange en aktuell kod för att skapa nya återställningskoder</label>') !== false && strpos($sec, '>Ange ditt lösenord för att bekräfta</label>') !== false
        && substr_count($sec, 'aria-label="Visa lösenord"') === 3
        && strpos($sec, '<h3 class="ms-card__title">Lösenord</h3>') !== false && strpos($sec, '>Nytt lösenord</label>') !== false && strpos($sec, '>Bekräfta lösenord</label>') !== false && strpos($sec, '>Ange lösenord</button>') !== false);
    check("sv security {$spName}: the Client Agent and Delete account sections are Swedish",
        strpos($sec, 'Nycklar för agenten du kör på din egen e-postserver.') !== false && strpos($sec, '<h3 class="ms-card__title">Klientens signeringsnycklar</h3>') !== false
        && strpos($sec, '>Rotera signeringsnycklar</button>') !== false && strpos($sec, '>Klientens signeringsnycklar kräver ett Pro-konto.</div>') !== false
        && strpos($sec, '<h2 class="ms-settings__section-title">Radera konto</h2>') !== false && strpos($sec, 'Radera ditt konto och alla tillhörande Pro-inställningar permanent.') !== false
        && strpos($sec, 'Ett bekräftelsemejl med en raderingslänk skickas först till din adress') !== false && strpos($sec, '>Radera konto</button>') !== false);
    $missingIds = array_filter($secIds, static fn(string $id): bool => strpos($sec, 'id="' . $id . '"') === false);
    check("sv security {$spName}: ids the scripts depend on survive", $missingIds === [], implode(', ', $missingIds));
    $svJsonS = preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $svS['body'], $sm) ? json_decode($sm[1], true) : null;
    check("sv security {$spName}: ms-i18n carries the script's Swedish texts",
        is_array($svJsonS) && ($svJsonS['profile.apps.revoke'] ?? null) === 'Återkalla' && ($svJsonS['profile.password.hide'] ?? null) === 'Dölj lösenord'
        && ($svJsonS['profile.security.stay_meta'] ?? null) === '{period} — senast använd {used} — till {until}' && ($svJsonS['profile.twofa.codes_other'] ?? null) === '{count} återställningskoder kvar'
        && ($svJsonS['profile.agent.rotating'] ?? null) === 'Roterar signeringsnycklarna...' && ($svJsonS['profile.delete.sent'] ?? null) !== null
        && ($svJsonS['profile.apps.revoke_confirm_grant'] ?? null) === 'Återkalla den här anslutningen? Appen förlorar åtkomsten direkt och måste godkännas igen.');
    // What the script draws from the database (an app's own name, a token prefix) is not in the server markup.
    check("sv security {$spName}: no app name, token prefix or token reaches the markup", strpos($svS['body'], 'Probe <b>App</b>') === false && strpos($svS['body'], 'msk_aaaaaaaa') === false && strpos($svS['body'], 'JBSWY3DPEHPK3PXP') === false);
}
same('security: OAuth on, the cards are the same with and without 2FA, a grant and a token (English fixtures)', $secFixtures['oauth_on'], $secFixtures['oauth_on_2fa_tokens']);
same('security: OAuth off, the cards are the same with and without 2FA, a grant and a token (English fixtures)', $secFixtures['oauth_off'], $secFixtures['oauth_off_2fa_tokens']);
same('security: OAuth on, the Swedish cards are the same with and without 2FA, a grant and a token', $ppNorm($secSections['oauth_on']), $ppNorm($secSections['oauth_on_2fa_tokens']));
same('security: OAuth off, the Swedish cards are the same with and without 2FA, a grant and a token', $ppNorm($secSections['oauth_off']), $ppNorm($secSections['oauth_off_2fa_tokens']));
check('security: the two OAuth states differ in the English fixtures (the copy follows the switch)', $secFixtures['oauth_on'] !== $secFixtures['oauth_off']);

// pro_profile.php answers of the Security actions.
$secPrefixes = ['profile.security.', 'profile.apps.', 'profile.twofa.', 'profile.password.', 'profile.agent.', 'profile.delete.', 'profile.error.'];
$secParams = [[], ['min' => 8], ['max' => 256], ['max' => 10]];
$secSwedish = static function (string $text) use ($en, $secPrefixes, $secParams): array {
    $found = [];
    foreach ($en as $key => $value) {
        if (!is_string($value)) {
            continue;
        }
        foreach ($secPrefixes as $prefix) {
            if (strncmp($key, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            foreach ($secParams as $params) {
                if (t($key, $params, 'en') === $text) {
                    $found[t($key, $params, 'sv')] = $key;
                }
            }
        }
    }
    return $found;
};
foreach (array_keys(ms_pp_security_json_cases()) as $spName) {
    $fixture = trim((string) file_get_contents($fx . "profile.{$spName}.en.json"));
    $r = ms_pp_json($pp, $spName);
    same("profile/{$spName}: the English answer equals its fixture", $fixture, $r['body']);
    check("profile/{$spName}: no PHP error", $ppNoPhpError($r), (string) $r['res']['stderr']);
    $enAnswer = json_decode($fixture, true);
    $rSv = ms_pp_json($pp, $spName, 'sv');
    $svAnswer = $rSv['json'];
    check("profile/{$spName}: X-MS-Locale sv answers JSON", is_array($svAnswer) && is_array($enAnswer), $rSv['body']);
    if (!is_array($svAnswer) || !is_array($enAnswer)) {
        continue;
    }
    $field = isset($enAnswer['error']) ? 'error' : 'message';
    $text = $enAnswer[$field] ?? null;
    $candidates = $secSwedish((string) $text);
    check("profile/{$spName}: the English text has a catalog key", $candidates !== [], (string) $text);
    check("profile/{$spName}: the Swedish answer is the catalog text, and it is Swedish", isset($candidates[$svAnswer[$field] ?? '']) && count($candidates) === 1 && array_key_first($candidates) !== $text, json_encode($candidates) . ' vs ' . ($svAnswer[$field] ?? ''));
    $enRest = $enAnswer;
    $svRest = $svAnswer;
    unset($enRest[$field], $svRest[$field]);
    same("profile/{$spName}: the rest of the answer is language independent", $enRest, $svRest);
    check("profile/{$spName}: no password, secret, token or recovery code in the answer", !preg_match('/abcdefgh1|probe-password|wrong-password|JBSWY3DPEHPK3PXP|msk_|recovery_codes|manual_key|qr_svg/', $r['body'] . $rSv['body']));
}
same('profile: the password length error carries its numbers in Swedish', ['Lösenordet måste vara minst 8 tecken', 'Lösenordet är för långt (högst 256 tecken)'], [ms_pp_json($pp, 'set_password.short', 'sv')['json']['error'] ?? null, ms_pp_json($pp, 'set_password.long', 'sv')['json']['error'] ?? null]);
same('profile: the access token cap carries its number in Swedish', 'Du har redan 10 åtkomsttoken. Återkalla en för att skapa en ny.', ms_pp_json($pp, 'mcp_token_create.too_many', 'sv')['json']['error'] ?? null);
same('profile: the per-action origin error is the shared text in Swedish', t('profile.error.invalid_origin', [], 'sv'), ms_pp_json($pp, 'totp_disable.invalid_origin', 'sv')['json']['error'] ?? null);
same('profile: the access token name error is the shared invalid-request text in Swedish', t('profile.error.invalid_request', [], 'sv'), ms_pp_json($pp, 'mcp_token_create.suspicious', 'sv')['json']['error'] ?? null);
same('profile: the confirmation failure reuses the shared text in Swedish (set_password and delete_account)', [t('profile.error.confirm_failed', [], 'sv'), t('profile.error.confirm_failed', [], 'sv')], [ms_pp_json($pp, 'set_password.failed', 'sv')['json']['error'] ?? null, ms_pp_json($pp, 'delete_account.failed', 'sv')['json']['error'] ?? null]);
same('profile: the link-sent message is the same for the password change and the deletion in Swedish', ['Bekräftelselänk skickad till din e-postadress', 'Bekräftelselänk skickad till din e-postadress'], [ms_pp_json($pp, 'set_password.sent', 'sv')['json']['message'] ?? null, ms_pp_json($pp, 'delete_account.sent', 'sv')['json']['message'] ?? null]);

// Scans.
$secMsKeys = array_values(array_filter($pageMsKeys, static fn(string $k): bool => preg_match('/^profile\.(?:security|apps|twofa|password|agent|delete)\./', $k) === 1));
check('scan: the Security script uses many msT() keys', count($secMsKeys) >= 70, (string) count($secMsKeys));
preg_match_all("/(?<![\\w.\$])msT\\('(profile\\.(?:security|apps|twofa|password|agent|delete)\\.[a-z_0-9]+)', '((?:[^'\\\\]|\\\\.)*)'/", $profilePageSrc, $fb, PREG_SET_ORDER);
$badFallback = [];
foreach ($fb as [$all, $fbKey, $fbText]) {
    if (!isset($en[$fbKey]) || $en[$fbKey] !== stripslashes($fbText)) {
        $badFallback[] = $fbKey;
    }
}
check('scan: every msT() fallback of the Security script is the English catalog text', count($fb) >= 70 && $badFallback === [], implode(', ', $badFallback));
// The Security, Client Agent and Delete account markup: no literal text outside PHP tags (a command in a <pre> is code, not copy).
$secMarkup = substr($profilePageSrc, (int) strpos($profilePageSrc, '<section class="ms-settings__section" id="settings-security">'));
$secMarkup = substr($secMarkup, 0, (int) strpos($secMarkup, '</form>'));
$secText = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace(['/<\?php.*?\?>/s', '/<!--.*?-->/s', '/<pre\b.*?<\/pre>/s'], ' ', $secMarkup))));
check('scan: the Security, Client Agent and Delete markup has no literal text outside PHP tags', $secText === '', $secText);
check('scan: ... nor a literal title/aria-label/placeholder', !preg_match('/\b(?:title|aria-label|placeholder)="[A-Za-z]/', preg_replace('/<\?php.*?\?>/s', '', $secMarkup)));
check('scan: ... and the cards use the catalog keys', substr_count($secMarkup, "te('profile.") + substr_count($secMarkup, "tHtml('profile.") >= 80);
check('scan: the endpoint is a placeholder of the sentence, in both OAuth branches', substr_count($secMarkup, "['endpoint' => \$mcpEndpoint]") === 2 && strpos($secMarkup, "\$mcpEndpoint = ") === false);
check('scan: no catalog value holds the MCP endpoint, a URL or a number of days', (function () use ($en, $sv): bool {
    foreach ([$en, $sv] as $catalog) {
        foreach ($catalog as $key => $value) {
            if (is_string($value) && preg_match('/^profile\.(?:security|apps|twofa|password|agent|delete)\./', $key) && (stripos($value, '/mcp') !== false || preg_match('~https?://~i', $value) || preg_match('/\d\s*-?\s*(?:day|dag|hour|timm|månad|month)/iu', $value))) {
                return false;
            }
        }
    }
    return true;
})());
check('scan: the periods in the options come from the helpers, not from the markup', strpos($secMarkup, 'proRememberAllowedDays()') !== false && strpos($secMarkup, 'mcpTokenExpiryChoices()') !== false && strpos($secMarkup, 'PRO_REMEMBER_MAX_AGE_DAYS') !== false);
// The script: server text goes in as text, never as HTML.
$secRegion = static function (string $from, string $to) use ($profilePageSrc): string {
    $start = (int) strpos($profilePageSrc, $from);
    return substr($profilePageSrc, $start, (int) strpos($profilePageSrc, $to, $start) - $start);
};
$secJs = $secRegion("\$('#saveProfilePasswordBtn').on('click'", "\$('#billingPortalBtn').on('click'")
    . $secRegion("\$('#rotateSigningKeysBtn').on('click'", "\$.post('pro_profile.php', { action: 'get_profile' }")
    . $secRegion('// Delete account handler', '// Load token initially');
$htmlLines = [];
foreach (explode("\n", $secJs) as $line) {
    if (strpos($line, '.html(') !== false && !preg_match('/\.html\(\'\'\)|\.html\(res\.qr_svg\)/', $line)) {
        $htmlLines[] = trim($line);
    }
}
check('scan: the Security script uses .html() only for the server-rendered QR code and to clear a box', $htmlLines === [], implode(' | ', array_slice($htmlLines, 0, 3)));
check('scan: ... the password, signing-key and deletion handlers write their messages with profileAlert()', substr_count($secJs, 'profileAlert(') >= 10 && strpos($secJs, "'<div class=\"alert alert-") === false);
check('scan: an app\'s own name is put in with .text(), once for the grant row', strpos($secJs, "\$('<div>').text(t.oauth_client_name || t.name || msT('profile.apps.connected_app'") !== false && substr_count($secJs, 't.oauth_client_name') === 1);
check('scan: the one-time access token is shown with .text() from the create response only', preg_match_all('/\bres\.token\b/', $secJs) === 1 && strpos($secJs, "\$('#connectedAppsNewTokenValue').text(res.token || '')") !== false);
check('scan: the recovery codes are shown as text nodes and the manual key with .text()', strpos($secJs, "document.createTextNode(c + '\\n')") !== false && strpos($secJs, "\$('#tfaManualKey').text(res.manual_key || '')") !== false && strpos($secJs, '$(\'#tfaQrContainer\').html(res.qr_svg)') !== false);
// No msT() call gets a token, secret, recovery code, code list, password or the 2FA key as a parameter.
$jsCalls = [];
preg_match_all('/(?<![\w.$])msT\(/', $profilePageSrc, $jm, PREG_OFFSET_CAPTURE);
foreach ($jm[0] as [$call, $offset]) {
    $depth = 0;
    $quote = '';
    $end = $offset;
    for ($pos = $offset + strlen($call) - 1, $len = strlen($profilePageSrc); $pos < $len; $pos++) {
        $ch = $profilePageSrc[$pos];
        if ($quote !== '') {
            if ($ch === '\\') { $pos++; } elseif ($ch === $quote) { $quote = ''; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
        if ($ch === '(') { $depth++; }
        if ($ch === ')' && --$depth === 0) { $end = $pos; break; }
    }
    $jsCalls[] = substr($profilePageSrc, $offset, $end - $offset + 1);
}
$jsBad = array_filter($jsCalls, static fn(string $c): bool => (bool) preg_match('/\b(?:token|secret|recovery|codes|password|manual_key)\s*:|res\.token\b|recovery_codes|manual_key|qr_svg|#proPassword|#tfaDisablePasswordInput|#tfaManualKey|#connectedAppsNewTokenValue|\.val\(\)/', $c));
check('scan: no msT() call is passed a token, secret, recovery code, code list or password', count($jsCalls) > 200 && $jsBad === [], count($jsCalls) . ' ' . implode(' | ', array_map(static fn(string $c): string => substr($c, 0, 80), array_slice($jsBad, 0, 3))));
// pro_profile.php: no literal user-facing text in the listed actions, and no credential in a t() call.
$secActions = ['totp_status', 'totp_begin_enroll', 'totp_confirm_enroll', 'totp_disable', 'totp_recovery_regenerate', 'trusted_devices_list', 'trusted_devices_revoke', 'stay_signed_in_list', 'stay_signed_in_set', 'stay_signed_in_revoke', 'mcp_token_list', 'mcp_token_create', 'mcp_token_revoke', 'set_password', 'rotate_signing_keys', 'delete_account'];
$secCredentialHits = [];
$secTCalls = 0;
foreach ($secActions as $action) {
    // The mails of set_password and delete_account are phase 4 and are not user-interface text.
    $block = preg_replace('/logMessage\([^;]*;/s', '', $ppCaseBlock($profileSrc, $action));
    check("scan: pro_profile.php action {$action} has no literal 'error' / 'message' text", $block !== '' && !preg_match("/'(?:error|message)' => ['\"]/", $block), $action);
    preg_match_all('/(?<![\w>$:.])(?:tHtml|te|t|tp)\(([^;]*?)\)\s*[\],;]/', $block, $tm);
    foreach ($tm[0] as $tCall) {
        $secTCalls++;
        if (preg_match('/[\'"](?:secret|token|recovery|codes|password|manual_key|qr_svg|otpauth_uri)[\'"]\s*=>|\$(?:token|password|recoveryCodes|manualKey|secret|code)\b/', $tCall)) {
            $secCredentialHits[] = $action . ': ' . substr($tCall, 0, 80);
        }
    }
}
check('scan: no t(), te() or tHtml() call in the Security actions is passed a token, secret, recovery code, password or key', $secTCalls >= 55 && $secCredentialHits === [], $secTCalls . ' ' . implode(' | ', $secCredentialHits));
$enrol = $ppCaseBlock($profileSrc, 'totp_begin_enroll');
$confirmBlock = $ppCaseBlock($profileSrc, 'totp_confirm_enroll');
$tokenBlock = $ppCaseBlock($profileSrc, 'mcp_token_create');
check('scan: the TOTP secret, QR data, recovery codes and the access token stay separate fields of the JSON',
    strpos($enrol, "'manual_key' => \$manualKey") !== false && strpos($enrol, "'qr_svg' => TwoFactorAuth::renderQrSvg(\$otpauthUri)") !== false && strpos($enrol, "'otpauth_uri' => \$otpauthUri") !== false
    && strpos($confirmBlock, "send_json(['success' => true, 'recovery_codes' => \$recoveryCodes])") !== false
    && strpos($ppCaseBlock($profileSrc, 'totp_recovery_regenerate'), "send_json(['success' => true, 'recovery_codes' => \$recoveryCodes])") !== false
    && strpos($tokenBlock, "'token' => \$created['token']") !== false);
check('scan: the actions use the Security keys', substr_count($profileSrc, "t('profile.twofa.") >= 14 && substr_count($profileSrc, "t('profile.apps.") >= 9 && substr_count($profileSrc, "t('profile.security.") >= 8 && substr_count($profileSrc, "t('profile.password.") >= 6 && substr_count($profileSrc, "t('profile.agent.") >= 2);
check('scan: the TOTP issuer and account label are not translated (TwoFactorAuth.php has no t() call)', !preg_match("/(?<![\\w>\$:.])t[eH]?\\w*\\('/", (string) file_get_contents($root . '/TwoFactorAuth.php')));
check('scan: client_agent_manage.php is not translated (epic decision 7)', !preg_match("/(?<![\\w>\$:.])t[eH]?\\w*\\('|i18n/", (string) file_get_contents($root . '/client_agent_manage.php')));
// The grants test keeps checking that an app's markup name is data: run next to this one.
check('scan: tests/oauth_grants_test.php still checks the markup name is inserted with .text()', strpos((string) file_get_contents($root . '/tests/oauth_grants_test.php'), '.text(') !== false);

// What the page hands its scripts: every msT() key is covered, and the OAuth sign-in sentences are not among them.
preg_match('/\$msI18nJs = \[(.*?)\];/s', $profilePageSrc, $jsm);
preg_match_all("/'([^']+)'/", $jsm[1] ?? '', $jsp);
$handed = $jsp[1];
$uncovered = array_filter($pageMsKeys, static fn(string $k): bool => array_filter($handed, static fn(string $p): bool => strncmp($k, $p, strlen($p)) === 0) === []);
check('scan: every msT() key of the page is covered by a prefix the page hands to its scripts', count($handed) >= 20 && $uncovered === [], implode(', ', $uncovered));
$handedKeys = array_keys(i18nCatalogSubset($handed, 'en'));
check('scan: the language JSON does not carry the sign-in sentences of the Connected apps card', array_intersect($handedKeys, ['profile.apps.connect_oauth', 'profile.apps.connect_header', 'profile.apps.desc_oauth', 'profile.apps.grants_empty_oauth', 'profile.apps.connect_tokens']) === []
    && in_array('profile.apps.revoke', $handedKeys, true) && in_array('profile.apps.grant_meta', $handedKeys, true) && in_array('profile.security.stay_meta', $handedKeys, true));
foreach (['en' => [false, 'sign in when it asks'], 'sv' => [true, 'logga in när den ber om det']] as $lang => [$svFlag, $needle]) {
    $off = ms_pp_security_page($pp, 'oauth_off', $svFlag, $svFlag ? 'sv' : '');
    $on = ms_pp_security_page($pp, 'oauth_on', $svFlag, $svFlag ? 'sv' : '');
    check("security ({$lang}): with OAuth off the sign-in sentence is nowhere on the page, the language JSON included", strpos($off['body'], $needle) === false && strpos($on['body'], $needle) !== false);
}

// =============================================================================
// Step 7/8 (epic #428): pro_contact.php, welcome.php and the OAuth consent page
// =============================================================================
// The contact page and welcome.php run as subprocesses on the sign-in probe docroot, the
// consent page on PHP's built-in server (it refuses the CLI), all through
// tests/lib/last_pages_probe.php. The English fixtures were rendered from the code as it was
// before the pages went through t()/te()/tHtml(). Each probe states the '@app' gate it runs
// under (open or closed), so these checks do not depend on the list the site ships.
require_once $root . '/tests/lib/last_pages_probe.php';
$lp = ms_auth_probe_build($root);
$lpNorm = static fn(string $html): string => trim(preg_replace('/\s+/', ' ', authNorm($html)));
$lpKeyRe = '/\b(?:contact|welcome|oauth)\.(?:meta|title|lede|reply_to|category|message|chars|back|send|sending|sent|failed|network|error|heading|body|cta|consent)[a-z_.0-9]*/';
$lpSv = 'sv-SE,sv;q=0.9';

// --- pro_contact.php: the form ------------------------------------------------------
$contactFx = $lpNorm((string) file_get_contents($fx . 'contact.form.en.html'));
$cEn = ms_app_page($lp, 'pro_contact.php', true, true);
check('contact page (en): rendered without PHP errors', $cEn['html'] !== '' && $cEn['err'] === '', $cEn['err']);
same('fixture: contact form, English only', $contactFx, $lpNorm($cEn['html']));
$cGateOpenEnOnly = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, true, $lpSv, 'en');
same("fixture: contact form, '@app' open but LOCALES_ENABLED=en, browser asks for Swedish", $contactFx, $lpNorm($cGateOpenEnOnly['html']));
$cGateClosed = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, false, $lpSv, 'en,sv');
same("fixture: contact form, sv enabled but '@app' closed, browser asks for Swedish", $contactFx, $lpNorm($cGateClosed['html']));
$cSv = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, true, $lpSv);
$cSvNoJson = preg_replace(['#<script type="application/json" id="ms-i18n">.*?</script>#s', "/msT\\('contact\\.[a-z_.0-9]+'/"], ['', "msT('"], $cSv['html']);
check('sv contact page: rendered without PHP errors', $cSv['html'] !== '' && $cSv['err'] === '', $cSv['err']);
check('sv contact page: <html lang="sv"> and the Swedish title', strpos($cSv['html'], '<html lang="sv">') !== false && strpos($cSv['html'], '<title>Kontakta supporten · Mail Shield</title>') !== false);
check('sv contact page: no raw key anywhere but the language JSON', !preg_match($lpKeyRe, $cSvNoJson), (string) (preg_match($lpKeyRe, $cSvNoJson, $km) ? $km[0] : ''));
$cSvText = authVisible($cSv['html']);
foreach (['Kontakta supporten', 'Meddelanden går direkt till teamet.', 'Svaret skickas till', 'Kategori', 'Välj en kategori...', 'Fråga', 'Förslag', 'Felrapport', 'Meddelande', '0 / 5000 tecken', 'Tillbaka till översikten', 'Skicka meddelande'] as $needle) {
    check("sv contact page: shows \"{$needle}\"", strpos($cSvText, $needle) !== false);
}
check('sv contact page: the placeholder is Swedish', strpos($cSv['html'], 'placeholder="Beskriv din fråga, ditt förslag eller felet du stötte på..."') !== false);
check('sv contact page: the category values the server validates stay English', strpos($cSv['html'], '<option value="Question">') !== false && strpos($cSv['html'], '<option value="Suggestion">') !== false && strpos($cSv['html'], '<option value="Bug report">') !== false);
check('sv contact page: ids the script and the nav depend on survive', count(array_filter(['contactForm', 'category', 'message', 'charCount', 'submitBtn', 'contactAlert', 'proExpiryLine'], fn($id) => strpos($cSv['html'], 'id="' . $id . '"') !== false)) === 7);
check('sv contact page: the nav expiry texts reach the script in Swedish', strpos($cSv['html'], '"free":"Gratis"') !== false);
preg_match('#<script type="application/json" id="ms-i18n">(.*?)</script>#s', $cSv['html'], $cjm);
$cSvJson = json_decode($cjm[1] ?? '', true);
check('sv contact page: the language JSON carries the script strings in Swedish', is_array($cSvJson) && ($cSvJson['contact.sending'] ?? null) === 'Skickar...' && ($cSvJson['contact.network'] ?? null) === 'Nätverksfel. Försök igen.' && ($cSvJson['contact.failed'] ?? null) === 'Det gick inte att skicka meddelandet');
check('contact page (en): the language JSON is English', (bool) preg_match('#id="ms-i18n">\{[^<]*"contact\.sending":"Sending\.\.\."#', $cEn['html']));
// Who picks the language: the account first, then the browser.
$cAccSv = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, true, '', 'en,sv', 'sv');
check('contact page: an account with locale sv gets Swedish with no Accept-Language', strpos($cAccSv['html'], '<html lang="sv">') !== false);
$cAccEn = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, true, $lpSv, 'en,sv', 'en');
check('contact page: an account with locale en gets English although the browser asks for Swedish', strpos($cAccEn['html'], '<html lang="en">') !== false && $lpNorm($cAccEn['html']) === $contactFx);
$cAccNull = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, true, $lpSv, 'en,sv');
check('contact page: an account with no choice (NULL) follows Accept-Language', strpos($cAccNull['html'], '<html lang="sv">') !== false);
$cAccNullNone = ms_app_page($lp, 'pro_contact.php', true, true, [], false, null, true, '', 'en,sv');
check('contact page: an account with no choice and no browser language gets English', strpos($cAccNullNone['html'], '<html lang="en">') !== false);
$cRegular = ms_app_page($lp, 'pro_contact.php', true, false, [], false, null, true, $lpSv);
check('sv contact page (Regular account): the script gets "Gratis"', strpos($cRegular['html'], '"free":"Gratis"') !== false);
$cOut = ms_app_page($lp, 'pro_contact.php', false, false);
check('contact page: a signed-out visitor is sent to the sign-in page', $cOut['html'] === '' || strpos($cOut['html'], '<form id="contactForm"') === false);

// --- pro_contact.php: the answers ---------------------------------------------------
foreach (ms_contact_json_cases() as $cname => [$cpost, $corigin]) {
    $cr = ms_contact_json($lp, $cpost, $corigin);
    check("contact answer {$cname}: no PHP error and valid JSON", $cr['json'] !== null && $cr['err'] === '', $cr['err'] . $cr['body']);
    same("fixture: contact.{$cname}.en.json", trim((string) file_get_contents($fx . "contact.{$cname}.en.json")), $cr['body']);
    $csv = ms_contact_json($lp, $cpost, $corigin, 'sv', true);
    $cText = (string) ($csv['json']['error'] ?? $csv['json']['message'] ?? '');
    check("contact answer {$cname} (X-MS-Locale sv): not the English text and no raw key", $cText !== '' && $cText !== (string) ($cr['json']['error'] ?? $cr['json']['message'] ?? '') && strpos($cText, 'contact.') === false, $cText);
    check("contact answer {$cname} (X-MS-Locale sv): success flag unchanged", ($csv['json']['success'] ?? null) === ($cr['json']['success'] ?? null));
}
$cSvAnswers = [];
foreach (ms_contact_json_cases() as $cname => [$cpost, $corigin]) {
    $cSvAnswers[$cname] = ms_contact_json($lp, $cpost, $corigin, 'sv', true)['json'];
}
same('contact answers (sv): forbidden', 'Förbjudet', $cSvAnswers['forbidden']['error'] ?? null);
same('contact answers (sv): invalid category', 'Ogiltig kategori vald', $cSvAnswers['invalid_category']['error'] ?? null);
same('contact answers (sv): message required', 'Du måste skriva ett meddelande', $cSvAnswers['message_required']['error'] ?? null);
same('contact answers (sv): too long carries its number', 'Meddelandet är för långt (högst 5000 tecken)', $cSvAnswers['too_long']['error'] ?? null);
same('contact answers (sv): sent', 'Tack! Ditt meddelande har skickats.', $cSvAnswers['sent']['message'] ?? null);
$cAcct = ms_contact_json($lp, ['category' => 'Nope', 'message' => 'x'], 'ok', null, true, '', 'sv');
same('contact answer: the account language applies to the endpoint too', 'Ogiltig kategori vald', $cAcct['json']['error'] ?? null);
$cHdrWins = ms_contact_json($lp, ['category' => 'Nope', 'message' => 'x'], 'ok', 'en', true, $lpSv, 'sv');
same('contact answer: X-MS-Locale en wins over the account and the browser', 'Invalid category selected', $cHdrWins['json']['error'] ?? null);
$cGateEn = ms_contact_json($lp, ['category' => 'Nope', 'message' => 'x'], 'ok', null, false, $lpSv);
same("contact answer: with '@app' closed the browser's Swedish is ignored", 'Invalid category selected', $cGateEn['json']['error'] ?? null);
// The mail to the operator is an internal message: English, whatever language the page was in.
$cMailEn = ms_contact_json($lp, ['category' => 'Bug report', 'message' => 'Det går inte att logga in'], 'ok');
$cMailSv = ms_contact_json($lp, ['category' => 'Bug report', 'message' => 'Det går inte att logga in'], 'ok', 'sv', true, $lpSv, 'sv');
check('contact mail (en): sent', ($cMailEn['json']['success'] ?? false) === true && strpos($cMailEn['mail'], 'Subject: Mail Shield contact form: Bug report') !== false, $cMailEn['mail']);
check('contact mail (sv page): subject, labels and category stay English', strpos($cMailSv['mail'], 'Subject: Mail Shield contact form: Bug report') !== false && strpos($cMailSv['mail'], 'Category: Bug report') !== false && strpos($cMailSv['mail'], 'From: alice@example.com (user_id ') !== false, $cMailSv['mail']);
check('contact mail (sv page): the message is passed on as written', strpos($cMailSv['mail'], 'Det går inte att logga in') !== false);
check('contact mail (sv page): no Swedish template text and no catalog text', strpos($cMailSv['mail'], 'Kategori') === false && strpos($cMailSv['mail'], 'Felrapport') === false && strpos($cMailSv['mail'], 'contact.') === false);
// Scans.
$contactSrc = (string) file_get_contents($root . '/pro_contact.php');
$mailBlock = substr($contactSrc, (int) strpos($contactSrc, '$supportTo'), (int) strpos($contactSrc, "echo json_encode(['success' => true") - (int) strpos($contactSrc, '$supportTo'));
check("scan: the operator mail block of pro_contact.php has no t(), te() or tHtml() call", $mailBlock !== '' && !preg_match('/(?<![\w>$:.])t[eH]?\w*\(/', preg_replace('/logMessage\([^;]*;/s', '', $mailBlock)) && strpos($mailBlock, "'Mail Shield contact form: ' . \$category") !== false);
check("scan: pro_contact.php has no literal 'error' / 'message' answer", !preg_match("/'(?:error|message)' => '/", preg_replace('/logMessage\([^;]*;/s', '', $contactSrc)));
check('scan: pro_contact.php passes the POST flag as $isApi to i18nUseAppLocale()', strpos($contactSrc, "\$config['i18n'] ?? [], \$_SERVER['REQUEST_METHOD'] === 'POST');") !== false);
$contactScript = substr($contactSrc, (int) strrpos($contactSrc, 'function msT('));
check('scan: pro_contact.php inserts nothing with .html() or innerHTML (the server text goes in as text)', !preg_match('/\.html\(|innerHTML|insertAdjacentHTML/', $contactScript));
check('scan: pro_contact.php hands its script the contact. strings', strpos($contactSrc, "\$msI18nJs = ['contact.'];") !== false);
preg_match_all("/(?<![\\w.\$])msT\\('([^']+)',\\s*'((?:[^'\\\\]|\\\\.)*)'/", $contactSrc, $cmm, PREG_SET_ORDER);
check('scan: pro_contact.php msT() calls found', count($cmm) === 4, (string) count($cmm));
foreach ($cmm as [, $ckey, $cfallback]) {
    same("pro_contact.php: msT fallback for {$ckey} is the English catalog value", $en[$ckey] ?? null, stripslashes($cfallback));
    check("pro_contact.php: {$ckey} is covered by the contact. prefix", strncmp($ckey, 'contact.', 8) === 0);
}
check('scan: pro_contact.php has no visible attribute or text left as a literal', !preg_match('/\b(?:title|aria-label|placeholder)="[A-Za-z]/', $contactSrc) && !preg_match('#>\s*[A-Z][a-z]+ [a-z ]+\s*<#', preg_replace('#<script.*?</script>#s', '', preg_replace('#<\?php.*?\?>#s', '', $contactSrc))));

// --- welcome.php ---------------------------------------------------------------------
foreach (['signed_out' => false, 'signed_in' => true] as $wname => $win) {
    $wfx = $lpNorm((string) file_get_contents($fx . "welcome.{$wname}.en.html"));
    $w = ms_welcome_page($lp, '/welcome.php', $win);
    check("welcome ({$wname}): rendered without PHP errors", $w['html'] !== '' && $w['err'] === '', $w['err']);
    same("fixture: welcome.{$wname}.en.html, English only", $wfx, $lpNorm($w['html']));
    $wBoth = ms_welcome_page($lp, '/welcome.php', $win, 'en,sv');
    same("fixture: welcome.{$wname}.en.html, Swedish enabled but not chosen (hreflang and the language switcher apart)", $wfx, $lpNorm(preg_replace('#<nav class="ms-footer__langs".*?</nav>#s', '', $wBoth['html'])));
    $wSv = ms_welcome_page($lp, '/sv/welcome.php', $win, 'en,sv');
    $wSvText = authVisible($wSv['html']);
    check("sv welcome ({$wname}): rendered without PHP errors, lang=\"sv\"", $wSv['html'] !== '' && $wSv['err'] === '' && strpos($wSv['html'], '<html lang="sv">') !== false, $wSv['err']);
    check("sv welcome ({$wname}): no raw key", !preg_match('/\bwelcome\.(?:meta|heading|body|cta)[a-z_.0-9]*/', $wSv['html']));
    check("sv welcome ({$wname}): the title, description, heading and body are Swedish", strpos($wSv['html'], '<title>Tack · Mail Shield</title>') !== false && strpos($wSv['html'], 'content="Din betalning till Mail Shield gick igenom."') !== false
        && strpos($wSvText, 'Tack för ditt köp') !== false && strpos($wSvText, 'Ett kvitto är på väg till din e-post från Paddle') !== false);
    if ($win) {
        check('sv welcome (signed in): the link goes to the inbox', strpos($wSv['html'], 'href="/pro.php">Gå till din inkorg</a>') !== false);
    } else {
        check('sv welcome (signed out): the sign-in and sign-up links carry /sv/', strpos($wSv['html'], 'href="/sv/pro_login.php">Logga in</a>') !== false && strpos($wSv['html'], 'href="/sv/register.php?plan=regular">Skapa din inkorg</a>') !== false);
        check('welcome (signed out, en): the links stay plain', strpos($w['html'], 'href="/pro_login.php">Log in</a>') !== false && strpos($w['html'], 'href="/register.php?plan=regular">Create your inbox</a>') !== false);
    }
    check("sv welcome ({$wname}): still noindex, with no analytics and no consent notice", strpos($wSv['html'], 'noindex, nofollow') !== false && !preg_match('/gtag|googletagmanager|ms-i18n-consent|consent\.js/', $wSv['html']));
    check("sv welcome ({$wname}): canonical and alternates name the Swedish page", strpos($wSv['html'], '<link rel="canonical" href="https://example.test/sv/welcome.php">') !== false && strpos($wSv['html'], 'hreflang="x-default" href="https://example.test/welcome.php"') !== false);
}
same('pages: welcome.php is offered in Swedish', ['en', 'sv'], $realPages['/welcome.php'] ?? null);
same('resolve: /sv/welcome.php is served once Swedish is on', null, i18nResolveRequest(['REQUEST_URI' => '/sv/welcome.php'], $both));
same('resolve: ...and redirects while only English is on', '/welcome.php', i18nResolveRequest(['REQUEST_URI' => '/sv/welcome.php'], $onlyEn));
i18nSetLocale('en');
check('scan: sitemap.php does not list welcome.php (it is noindex)', stripos((string) file_get_contents($root . '/sitemap.php'), 'welcome') === false);
check("scan: pricing.php hands Paddle the success page in the buyer's language", strpos((string) file_get_contents($root . '/pricing.php'), "'successUrl'      => \$origin . i18nUrl('/welcome.php'),") !== false);
check('scan: welcome.php is a public-shell page (URL language), not an app page', stripos((string) file_get_contents($root . '/welcome.php'), 'i18nUseAppLocale') === false && strpos((string) file_get_contents($root . '/welcome.php'), "'analytics'   => false") !== false);

// --- The OAuth consent page ----------------------------------------------------------
$oc = ms_oc_build($root);
$ocServers = ['en' => ms_oc_start($oc['root'], 'en', false), 'enOpen' => ms_oc_start($oc['root'], 'en', true), 'sv' => ms_oc_start($oc['root'], 'en,sv', true)];
$ocPort = static fn(string $which): int => (int) $ocServers[$which][1];
$ocHdr = ['Accept-Language' => $lpSv];
foreach (ms_oc_cases() as $oname => [$okind, $oquery, $ouser]) {
    $ofx = ms_oc_norm((string) file_get_contents($fx . "consent.{$oname}.en.html"));
    $oe = ms_oc_render($oc, $ocPort('en'), $oname);
    same("fixture: consent.{$oname}.en.html, English only", $ofx, ms_oc_norm($oe['body']));
    $oe2 = ms_oc_render($oc, $ocPort('enOpen'), $oname, $ocHdr);
    same("fixture: consent.{$oname}.en.html, '@app' open but LOCALES_ENABLED=en, browser asks for Swedish", $ofx, ms_oc_norm($oe2['body']));
    $oe3 = ms_oc_render($oc, $ocPort('en'), $oname, $ocHdr);
    same("fixture: consent.{$oname}.en.html, '@app' closed, browser asks for Swedish", $ofx, ms_oc_norm($oe3['body']));
    // Swedish.
    $os = ms_oc_render($oc, $ocPort('sv'), $oname, $ocHdr);
    $osText = authVisible($os['body']);
    check("sv consent {$oname}: same status as English ({$oe['status']})", $os['status'] === $oe['status'], (string) $os['status']);
    check("sv consent {$oname}: <html lang=\"sv\">, no raw oauth. key", strpos($os['body'], '<html lang="sv">') !== false && !preg_match('/\boauth\.(?:consent|error)\.[a-z_]+/', $os['body']));
    check("sv consent {$oname}: X-Frame-Options DENY and frame-ancestors 'none' unchanged", ($os['headers']['x-frame-options'] ?? '') === 'DENY' && ($os['headers']['content-security-policy'] ?? '') === "frame-ancestors 'none'");
    check("sv consent {$oname}: no-store, noindex and no analytics", strpos($os['headers']['cache-control'] ?? '', 'no-store') !== false && strpos($os['body'], '<meta name="robots" content="noindex, nofollow">') !== false && !preg_match('/gtag|googletagmanager|ms-i18n-consent|consent\.js|analytics/i', $os['body']));
    check("sv consent {$oname}: differs from English", ms_oc_norm($os['body']) !== $ofx);
    check("sv consent {$oname}: the footer link and the close/back texts are Swedish", strpos($osText, 'Tillbaka till Mail Shield') !== false);
}
$ocRead = ms_oc_render($oc, $ocPort('sv'), 'read', $ocHdr);
$ocWrite = ms_oc_render($oc, $ocPort('sv'), 'write', $ocHdr);
$ocReadText = authVisible($ocRead['body']);
foreach (['Ansluta den här appen?', 'En app på din dator eller telefon vill använda ditt Mail Shield-konto.', '”Example App”', 'Det här är namnet som appen ger sig själv. Mail Shield har inte kontrollerat vem den är.', 'Du skickas tillbaka till example.com när du är klar.', 'Vad appen får göra', 'Endast läsning', 'Se dina adresser och läsa dina meddelanden.', 'Allt som den här appen läser skickas till den som driver den, utanför Mail Shield. Anslut bara en app du litar på.', 'Avbryt', 'Tillåt'] as $needle) {
    check("sv consent (read): shows \"{$needle}\"", strpos($ocReadText, $needle) !== false);
}
check('sv consent (read): the title is Swedish', strpos($ocRead['body'], '<title>Ansluta den här appen? · Mail Shield</title>') !== false);
check('sv consent (read): only the read choice is offered, and it starts checked', strpos($ocRead['body'], 'value="mcp:read" checked') !== false && strpos($ocRead['body'], 'Läsa och ändra') === false && strpos($ocRead['body'], 'mcp:write') === false);
check('sv consent (write): both choices, the narrower one checked', strpos($ocWrite['body'], 'value="mcp:read" checked') !== false && strpos($ocWrite['body'], 'value="mcp:read mcp:write"') !== false && strpos($ocWrite['body'], 'value="mcp:read mcp:write" checked') === false
    && strpos(authVisible($ocWrite['body']), 'Läsa och ändra') !== false && strpos(authVisible($ocWrite['body']), 'Även skapa och radera dina adresser.') !== false);
check('sv consent: the form values the server reads are protocol values and stay as they are', strpos($ocRead['body'], 'name="decision" value="cancel"') !== false && strpos($ocRead['body'], 'name="decision" value="allow"') !== false && preg_match('/name="csrf" value="[0-9a-f]{64}"/', $ocRead['body']) === 1 && strpos($ocRead['body'], 'name="client_id"') === false && strpos($ocRead['body'], 'name="redirect_uri"') === false);
check('sv consent: the host is in its own <code> element', strpos($ocRead['body'], '<code>example.com</code>') !== false);
$ocErr = [
    'error.unknown_client' => ['Länken kan inte användas', 'Appen är inte registrerad, så den kan inte anslutas.'],
    'error.redirect_mismatch' => ['Länken kan inte användas', 'Den begärda omdirigeringsadressen stämmer inte med den som appen registrerade.'],
    'error.not_pro' => ['Att ansluta en app kräver Pro', 'Att ansluta en app ingår i Mail Shield Pro. Ditt konto har gratisplanen, så ingen åtkomst gavs.'],
    'error.method' => ['Metoden tillåts inte', 'Den här sidan svarar bara på GET och POST.'],
    'error.expired' => ['Sidan har gått ut', 'Börja om från appen du ville ansluta.'],
];
foreach ($ocErr as $oname => [$otitle, $obody]) {
    $or = ms_oc_render($oc, $ocPort('sv'), $oname, $ocHdr);
    $orText = authVisible($or['body']);
    check("sv consent {$oname}: the title and the sentence are Swedish", strpos($orText, $otitle) !== false && strpos($orText, $obody) !== false);
}
check('sv consent error.not_pro: the action link is Swedish and keeps its target', strpos(ms_oc_render($oc, $ocPort('sv'), 'error.not_pro', $ocHdr)['body'], 'href="pro_profile_page.php">Se din plan</a>') !== false);
check('sv consent error.expired: the close link is Swedish', strpos(ms_oc_render($oc, $ocPort('sv'), 'error.expired', $ocHdr)['body'], 'href="/">Stäng</a>') !== false);
check('consent error.method: Allow header unchanged', (ms_oc_render($oc, $ocPort('sv'), 'error.method', $ocHdr)['headers']['allow'] ?? '') === 'GET, POST');
// Who picks the language on the consent page: the account first, then the browser.
check('consent: an account with locale sv gets Swedish with no Accept-Language', strpos(ms_oc_render($oc, $ocPort('sv'), 'read', [], 'svAccount')['body'], '<html lang="sv">') !== false);
check('consent: an account with locale en gets English although the browser asks for Swedish', strpos(ms_oc_render($oc, $ocPort('sv'), 'read', $ocHdr, 'enAccount')['body'], '<html lang="en">') !== false);
check('consent: an account with no choice and no browser language gets English', strpos(ms_oc_render($oc, $ocPort('sv'), 'read', [])['body'], '<html lang="en">') !== false);
// The app's name is attacker-controlled: shown escaped and literal in both languages.
$ocPdo = ms_test_db(ms_test_probe_sqlite($oc['root']));
$hostile = ms_oc_add_client($ocPdo, '<b>Bold</b> {x} {name} &amp; "q"');
foreach (['en' => ['en', '“'], 'sv' => ['sv', '”']] as $hlang => [$hsrv, $hquote]) {
    $hr = ms_oc_http($oc['root'], $ocPort($hsrv === 'sv' ? 'sv' : 'en'), 'GET', ms_oc_path($hostile), $oc['pro'], $hsrv === 'sv' ? $ocHdr : []);
    check("consent ({$hlang}): an app name with markup, braces and an entity is escaped and literal", strpos($hr['body'], '&lt;b&gt;Bold&lt;/b&gt; {x} {name} &amp;amp; &quot;q&quot;') !== false && strpos($hr['body'], '<b>Bold</b>') === false, substr($hr['body'], (int) strpos($hr['body'], 'ms-grant__app-name'), 160));
    check("consent ({$hlang}): the name sits in quotes of that language in its own element", strpos($hr['body'], '<p class="ms-grant__app-name">' . $hquote . '&lt;b&gt;') !== false);
    check("consent ({$hlang}): the label saying the app named itself is still there", strpos($hr['body'], 'class="ms-grant__app-note"') !== false);
}
// The decision, in Swedish: protocol values in the redirect stay English.
$ocSid = ms_oc_session($oc['root'], $oc['pro']);
$ocPage = ms_oc_http($oc['root'], $ocPort('sv'), 'GET', ms_oc_path($oc['client'], ['scope' => 'mcp:read mcp:write']), null, $ocHdr, null, $ocSid);
preg_match('/name="csrf" value="([0-9a-f]{64})"/', $ocPage['body'], $ocCsrf);
$ocCancel = ms_oc_http($oc['root'], $ocPort('sv'), 'POST', '/oauth_authorize.php', null, $ocHdr, ['csrf' => $ocCsrf[1] ?? '', 'decision' => 'cancel'], $ocSid);
check('sv consent: Cancel redirects with access_denied and the English description', $ocCancel['status'] === 302 && strpos($ocCancel['headers']['location'] ?? '', 'error=access_denied') !== false && strpos($ocCancel['headers']['location'] ?? '', 'error_description=The%20user%20refused%20the%20request.') !== false && strpos($ocCancel['headers']['location'] ?? '', 'state=st-abc-123') !== false);
$ocSid2 = ms_oc_session($oc['root'], $oc['pro']);
$ocPage2 = ms_oc_http($oc['root'], $ocPort('sv'), 'GET', ms_oc_path($oc['client']), null, $ocHdr, null, $ocSid2);
preg_match('/name="csrf" value="([0-9a-f]{64})"/', $ocPage2['body'], $ocCsrf2);
$ocBadCsrf = ms_oc_http($oc['root'], $ocPort('sv'), 'POST', '/oauth_authorize.php', null, $ocHdr, ['csrf' => str_repeat('0', 64), 'decision' => 'allow'], $ocSid2);
check('sv consent: a wrong CSRF token is refused (403) with the Swedish page, no redirect', $ocBadCsrf['status'] === 403 && !isset($ocBadCsrf['headers']['location']) && strpos(authVisible($ocBadCsrf['body']), 'Sidan har gått ut') !== false);
$ocAllow = ms_oc_http($oc['root'], $ocPort('sv'), 'POST', '/oauth_authorize.php', null, $ocHdr, ['csrf' => $ocCsrf2[1] ?? '', 'decision' => 'allow', 'scope' => 'mcp:read'], $ocSid2);
check('sv consent: a refused token does not burn the request: the right one then answers a code', $ocAllow['status'] === 302 && strpos($ocAllow['headers']['location'] ?? '', 'code=') !== false, (string) $ocAllow['status']);
$ocSid3 = ms_oc_session($oc['root'], $oc['pro']);
$ocPage3 = ms_oc_http($oc['root'], $ocPort('sv'), 'GET', ms_oc_path($oc['client']), null, $ocHdr, null, $ocSid3);
preg_match('/name="csrf" value="([0-9a-f]{64})"/', $ocPage3['body'], $ocCsrf3);
$ocAllow3 = ms_oc_http($oc['root'], $ocPort('sv'), 'POST', '/oauth_authorize.php', null, $ocHdr, ['csrf' => $ocCsrf3[1] ?? '', 'decision' => 'allow', 'scope' => 'mcp:read'], $ocSid3);
check('sv consent: Allow redirects to the registered address with a code, the state and iss', $ocAllow3['status'] === 302 && preg_match('#^https://example\.com/cb\?code=[0-9a-f]{64}&state=st-abc-123&iss=#', $ocAllow3['headers']['location'] ?? '') === 1, $ocAllow3['headers']['location'] ?? '');
$ocBadScope = ms_oc_http($oc['root'], $ocPort('sv'), 'GET', ms_oc_path($oc['client'], ['response_type' => 'token']), $oc['pro'], $ocHdr);
check('sv consent: a parameter error still redirects to the proven address with protocol values in English', $ocBadScope['status'] === 302 && strpos($ocBadScope['headers']['location'] ?? '', 'error=unsupported_response_type') !== false && strpos($ocBadScope['headers']['location'] ?? '', 'error_description=') !== false && !preg_match('/Ogiltig|stöds|Sidan|appen/u', rawurldecode($ocBadScope['headers']['location'] ?? '')));
foreach ($ocServers as $ocServer) {
    ms_oc_stop($ocServer);
}
$ocSrc = (string) file_get_contents($root . '/oauth_authorize.php');
$ocCode = preg_replace('#/\*.*?\*/#s', '', $ocSrc);
check('scan: oauth_authorize.php still sends X-Frame-Options DENY and frame-ancestors none', strpos($ocSrc, "header('X-Frame-Options: DENY');") !== false && strpos($ocSrc, "header(\"Content-Security-Policy: frame-ancestors 'none'\");") !== false);
check('scan: oauth_authorize.php has no analytics partial', stripos($ocCode, 'analytics') === false && stripos($ocCode, 'gtag') === false);
check('scan: no notice or refusal of oauth_authorize.php is given a literal title or sentence', !preg_match("/oauthAuthorize(?:NoticePage|RefusePost)\(\s*(?:\d+,\s*)?'/", $ocCode) && !preg_match("/'title' => '|'message' => '/", $ocCode));
check('scan: the page markup of oauth_authorize.php has no literal visible text left', !preg_match('#>[A-Z][a-z]+(?: [a-z]+)+[.?]?<#', $ocCode) && !preg_match("#(?:class=\"btn[^\"]*\"|ms-grant__[a-z-]+\")>[A-Za-z]#", $ocCode));
check('scan: the protocol values stay literals (access_denied, the refusal description, iss)', strpos($ocSrc, "'error' => 'access_denied'") !== false && strpos($ocSrc, "'error_description' => 'The user refused the request.'") !== false && strpos($ocSrc, "'iss' => oauthAuthorizeIssuer()") !== false);
check('scan: oauth_authorize.php escapes the app name and the host as parameters of te()/tHtml()', strpos($ocSrc, "te('oauth.consent.app_name', ['name' => \$appName])") !== false && strpos($ocSrc, "tHtml('oauth.consent.app_host', ['host' => \$appHost]") !== false);
check('scan: the validation order, the CSRF check and the rate limit are where they were', strpos($ocSrc, 'oauthAuthorizeRequestValidate($pdo, $_GET)') !== false && strpos($ocSrc, 'hash_equals(oauthAuthorizeCsrfToken($pendingParams), $postedCsrf)') !== false && strpos($ocSrc, 'oauthAuthorizeRateLimit($pdo)') !== false);
$ocKeys = array_filter(array_keys($en), static fn(string $k): bool => strncmp($k, 'oauth.', 6) === 0);
check('scan: 37 oauth. keys, each in both languages', count($ocKeys) === 37&& array_diff($ocKeys, array_keys($sv)) === [], (string) count($ocKeys));
$ocLeaks = [];
foreach ($ocKeys as $ocKey) {
    foreach ([$en[$ocKey], $sv[$ocKey]] as $ocVal) {
        if (preg_match('/https?:|example|\b(?:access_denied|invalid_request|invalid_scope|invalid_client|unsupported_response_type|mcp:read|mcp:write)\b/', (string) $ocVal)) {
            $ocLeaks[] = $ocKey;
        }
    }
}
check('scan: no oauth. catalog value holds a URL, a host, a client name or an OAuth protocol value', $ocLeaks === [], implode(', ', $ocLeaks));
check('scan: the two attacker-controlled values are placeholders only', substr_count($en['oauth.consent.app_name'], '{name}') === 1 && substr_count($sv['oauth.consent.app_name'], '{name}') === 1 && substr_count($en['oauth.consent.app_host'], '{host}') === 1 && substr_count($sv['oauth.consent.app_host'], '{host}') === 1);
check('scan: each oauth. key in the Swedish catalog has the same placeholders as English', array_filter($ocKeys, static fn(string $k): bool => placeholders($en[$k]) !== placeholders($sv[$k])) === []);
check('scan: tests/oauth_authorize_test.php is not part of this change', strpos((string) file_get_contents($root . '/tests/oauth_authorize_test.php'), 'This link cannot be used') !== false);
ms_test_cleanup($oc['root']);

// --- Every app page in English while Swedish is not enabled --------------------------
// '@app' open, LOCALES_ENABLED=en: the gate on the languages wins whatever the account or the browser says.
foreach (['inbox.php' => ['inbox.php', true, true], 'pro.php' => ['pro.php', true, true], 'pro_contact.php' => ['pro_contact.php', true, true]] as $gname => [$gscript, $gin, $gpro]) {
    $g = ms_app_page($lp, $gscript, $gin, $gpro, [], false, null, true, $lpSv, 'en', 'sv');
    check("app page {$gname}: '@app' open and LOCALES_ENABLED=en still renders English (account sv, browser sv)", strpos($g['html'], '<html lang="en">') !== false && strpos($g['html'], 'Kontakta') === false && strpos($g['html'], 'Din inkorg') === false, $g['err']);
}
$gp = ms_pp_page($pp, 'pro', true, $lpSv, 'sv', 'en');
check("app page pro_profile_page.php: '@app' open and LOCALES_ENABLED=en still renders English (account sv, browser sv)", strpos($gp['body'], '<html lang="en">') !== false && strpos($gp['body'], 'id="languageCard"') === false);
// LOCALES_ENABLED=en,sv with the gate open: the account decides, then the browser.
foreach (['inbox.php' => ['inbox.php', 'Din inkorg'], 'pro.php' => ['pro.php', 'Din inkorg'], 'pro_contact.php' => ['pro_contact.php', 'Kontakta supporten']] as $gname => [$gscript, $gneedle]) {
    $a = ms_app_page($lp, $gscript, true, true, [], false, null, true, '', 'en,sv', 'sv');
    $b = ms_app_page($lp, $gscript, true, true, [], false, null, true, $lpSv, 'en,sv', 'en');
    $c = ms_app_page($lp, $gscript, true, true, [], false, null, true, $lpSv, 'en,sv');
    $d = ms_app_page($lp, $gscript, true, true, [], false, null, true, '', 'en,sv');
    check("app page {$gname}: account sv gives Swedish, account en gives English, NULL follows Accept-Language", strpos($a['html'], '<html lang="sv">') !== false && strpos($a['html'], $gneedle) !== false && strpos($b['html'], '<html lang="en">') !== false && strpos($c['html'], '<html lang="sv">') !== false && strpos($d['html'], '<html lang="en">') !== false);
}
// The list the site ships (no probe forces the gate): Swedish where LOCALES_ENABLED has it, English otherwise.
foreach (['inbox.php', 'pro.php', 'pro_contact.php'] as $gscript) {
    $real = ms_app_page($lp, $gscript, true, true, [], false, null, null, $lpSv, 'en,sv');
    $realEn = ms_app_page($lp, $gscript, true, true, [], false, null, null, $lpSv, 'en');
    $realAcc = ms_app_page($lp, $gscript, true, true, [], false, null, null, '', 'en,sv', 'sv');
    check("app page {$gscript}: with the shipped '@app' and LOCALES_ENABLED=en,sv the browser's Swedish applies", strpos($real['html'], '<html lang="sv">') !== false, $real['err']);
    check("app page {$gscript}: with the shipped '@app' and LOCALES_ENABLED=en it is English", strpos($realEn['html'], '<html lang="en">') !== false);
    check("app page {$gscript}: with the shipped '@app' an account with locale sv gets Swedish", strpos($realAcc['html'], '<html lang="sv">') !== false);
}
ms_auth_probe_remove($lp);

// --- Account mail, step 2/3 (epic #437, #439): the pro_profile.php mails -----------------------
// The real pro_profile.php runs in the profile probe with sendmail_path=cat >> mail.log, as the
// pro_auth.php mails do. ms_test_request() in tests/lib/pushover_harness.php cannot set that ini
// value, so this runs the same probe runner with it added.

/** The TOTP secret ms_pp_totp_on() stores, and the one a pending enrolment is seeded with. */
const MS_PF_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

/** The 6-digit TOTP (RFC 6238, SHA-1, 30 s) of a base32 secret for now. */
function ms_pf_totp_now(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($b32)) as $c) {
        $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $key .= chr(bindec($byte));
        }
    }
    $hash = hash_hmac('sha1', pack('J', intdiv(time(), 30)), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $n = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** One scripted action per mail: [post fields (a closure, so a TOTP code is computed when it is sent), setup]. */
function ms_pf_mail_cases(): array
{
    $pending = static fn(string $action, string $data): callable => static function (PDO $pdo, array $ids) use ($action, $data): void {
        $pdo->prepare("INSERT INTO pending_profile_changes (id, user_id, action, data, token, expires_at, created_at, used) VALUES (5, ?, ?, ?, 't5', '2099-01-02 10:00:00', '2099-01-01 10:00:00', 0)")
            ->execute([$ids['trial'], $action, $data]);
    };
    return [
        'twofa_enabled' => [static fn(): array => ['action' => 'totp_confirm_enroll', 'code' => ms_pf_totp_now(MS_PF_TOTP_SECRET)], ms_pp_with_security(static function (PDO $pdo, array $ids): void {
            $pdo->prepare("INSERT INTO pro_user_totp (user_id, secret_enc, status) VALUES (?, ?, 'pending')")->execute([$ids['trial'], ms_pp_totp_secret_enc(MS_PF_TOTP_SECRET)]);
        })],
        'twofa_disabled' => [static fn(): array => ['action' => 'totp_disable'], ms_pp_with_security(static fn(PDO $pdo, array $ids) => ms_pp_totp_on($pdo, $ids['trial']))],
        'confirm_email' => [static fn(): array => ['action' => 'update_email', 'email' => 'fresh@example.org'], null],
        'confirm_password' => [static fn(): array => ['action' => 'set_password', 'password' => 'abcdefgh1', 'confirm' => 'abcdefgh1'], null],
        'confirm_delete' => [static fn(): array => ['action' => 'delete_account'], null],
        'resend_email' => [static fn(): array => ['action' => 'resend_pending_change', 'id' => '5'], $pending('update_email', json_encode(['new_email' => 'n@example.org']))],
        'resend_password' => [static fn(): array => ['action' => 'resend_pending_change', 'id' => '5'], $pending('set_password', '{}')],
        'resend_delete' => [static fn(): array => ['action' => 'resend_pending_change', 'id' => '5'], $pending('delete_account', '{}')],
    ];
}

/**
 * Runs one of ms_pf_mail_cases() as the account 'trial' and returns the captured, masked message.
 * $opts: account_locale (pro_users.locale), request_locale (X-MS-Locale), locales (LOCALES_ENABLED),
 * app_sv ('@app' open), mail_sv ('@mail' open).
 */
function ms_pf_mail_capture(array $probe, string $kind, array $opts = []): string
{
    [$post, $setup] = ms_pf_mail_cases()[$kind];
    $root = $probe['root'];
    $mailLog = $root . '/mail.log';
    @unlink($mailLog);
    ms_pp_reset($probe, static function (PDO $pdo, array $ids) use ($setup, $opts): void {
        if (isset($opts['account_locale'])) {
            $pdo->prepare('UPDATE pro_users SET locale = ? WHERE id = ?')->execute([$opts['account_locale'], $ids['trial']]);
        }
        if ($setup !== null) {
            $setup($pdo, $ids);
        }
    });
    $server = isset($opts['request_locale']) ? ['HTTP_X_MS_LOCALE' => $opts['request_locale']] : [];
    file_put_contents($root . '/probe_extra.json', json_encode(['server' => $server, 'locales' => $opts['locales'] ?? 'en', 'app_sv' => !empty($opts['app_sv']), 'mail_sv' => !empty($opts['mail_sv'])]));
    $env = [
        'PROBE_PAGE' => 'pro_profile.php', 'PROBE_METHOD' => 'POST', 'PROBE_USER_ID' => (string) $probe['ids']['trial'], 'PROBE_USER_EMAIL' => 'trial@example.com',
        'PROBE_POST' => (string) json_encode($post()), 'PROBE_GET' => '[]', 'PROBE_ORIGIN' => MS_TEST_ORIGIN,
        'PROBE_SQLITE' => ms_test_probe_sqlite($root), 'PROBE_SESSION_ID' => 'msprobe1', 'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    // The trailing '#' comments out the -f envelope option mail() appends.
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'sendmail_path=cat >> ' . escapeshellarg($mailLog) . ' #', $root . '/ms_probe_runner.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return ms_mail_mask((string) @file_get_contents($mailLog));
}

// Same section, the tests (step 2/3). '@mail' is opened per request by a line in the probe's config.
file_put_contents($pp['root'] . '/config.php', "\nif (!empty(\$MS_EXTRA['mail_sv'])) {\n    i18nTranslatedPages(array_merge(i18nTranslatedPages(), ['@mail' => ['en', 'sv']]));\n}\n", FILE_APPEND);
$pfLink = 'http://localhost:8085/pro_auth.php?confirm_profile_change=<TOKEN>';
// kind => [subject key, body key, body placeholders]
$pfKeys = [
    'twofa_enabled' => ['mail.twofa_enabled.subject', 'mail.twofa_enabled.body', []],
    'twofa_disabled' => ['mail.twofa_disabled.subject', 'mail.twofa_disabled.body', []],
    'confirm_email' => ['mail.confirm_email.subject', 'mail.confirm_email.body', ['link' => $pfLink]],
    'confirm_password' => ['mail.confirm_password.subject', 'mail.confirm_password.body', ['link' => $pfLink]],
    'confirm_delete' => ['mail.confirm_delete.subject', 'mail.confirm_delete.body', ['link' => $pfLink, 'hours' => 2]],
    'resend_email' => ['mail.confirm_email.subject', 'mail.confirm_email_resend.body', ['link' => $pfLink]],
    'resend_password' => ['mail.confirm_password.subject', 'mail.confirm_password_resend.body', ['link' => $pfLink]],
    'resend_delete' => ['mail.confirm_delete_resend.subject', 'mail.confirm_delete_resend.body', ['link' => $pfLink]],
];
$pfOpen = ['locales' => 'en,sv', 'app_sv' => true, 'mail_sv' => true];
$pfSvMails = [];
foreach (array_keys(ms_pf_mail_cases()) as $kind) {
    $fixture = (string) file_get_contents($fx . "mail.{$kind}.en.txt");
    check("pro_profile mail {$kind}: the fixture exists", $fixture !== '');
    same("pro_profile mail {$kind}: English is byte-identical to its fixture", $fixture, ms_pf_mail_capture($pp, $kind));
    same("pro_profile mail {$kind}: Swedish on but @mail closed is still English (account sv, page sv)", $fixture, ms_pf_mail_capture($pp, $kind, ['locales' => 'en,sv', 'app_sv' => true, 'account_locale' => 'sv', 'request_locale' => 'sv']));
    same("pro_profile mail {$kind}: @mail open but LOCALES_ENABLED=en is English", $fixture, ms_pf_mail_capture($pp, $kind, ['locales' => 'en', 'mail_sv' => true, 'account_locale' => 'sv', 'request_locale' => 'sv']));
    same("pro_profile mail {$kind}: an account with locale en stays English with everything open, even on a Swedish page", $fixture, ms_pf_mail_capture($pp, $kind, $pfOpen + ['account_locale' => 'en', 'request_locale' => 'sv']));
    same("pro_profile mail {$kind}: no stored language and an English page is English", $fixture, ms_pf_mail_capture($pp, $kind, $pfOpen + ['request_locale' => 'en']));

    $svMail = ms_pf_mail_capture($pp, $kind, $pfOpen + ['account_locale' => 'sv']);
    $pfSvMails[$kind] = $svMail;
    [$svHeaders, $svBody] = $mailSplit($svMail);
    [$subjectKey, $bodyKey, $params] = $pfKeys[$kind];
    check("pro_profile mail {$kind}: a Swedish mail was sent", $svMail !== '' && strpos($svMail, 'Hej,') !== false, $svMail);
    $svSubjectIsAscii = !preg_match('/[^\x00-\x7F]/', t($subjectKey, [], 'sv'));
    check("pro_profile mail {$kind}: Swedish subject is encoded exactly when it is not ASCII", $svSubjectIsAscii !== (bool) preg_match('/^Subject: =\?UTF-8\?B\?/m', $svHeaders), $svHeaders);
    same("pro_profile mail {$kind}: Swedish subject decodes to the catalog string", t($subjectKey, [], 'sv'), $mailSubject($svHeaders));
    same("pro_profile mail {$kind}: Swedish body is the catalog string", rtrim(t($bodyKey, $params, 'sv')), rtrim($svBody));
    check("pro_profile mail {$kind}: Swedish body differs from English", t($bodyKey, $params, 'sv') !== t($bodyKey, $params, 'en'));
    check("pro_profile mail {$kind}: Swedish headers keep From, MIME and charset and carry no raw non-ASCII", strpos($svHeaders, 'From: Mail Shield <noreply@manjo.me>') !== false && strpos($svHeaders, 'MIME-Version: 1.0') !== false && strpos($svHeaders, 'Content-Type: text/plain; charset=UTF-8') !== false && !preg_match('/[^\x00-\x7F]/', $svHeaders));
    [$enHeaders] = $mailSplit($fixture);
    same("pro_profile mail {$kind}: the recipient is the same in Swedish", (preg_match('/^To: .*$/m', $enHeaders, $a) ? $a[0] : null), (preg_match('/^To: .*$/m', $svHeaders, $b) ? $b[0] : null));
    if (isset($params['link'])) {
        check("pro_profile mail {$kind}: the link is the endpoint's, with no /sv/ in it", strpos($svBody, $pfLink) !== false && strpos($svBody, '/sv/') === false);
    }
    // Only the page's language (no stored one): the account holder is the one asking.
    $pageOnly = ms_pf_mail_capture($pp, $kind, $pfOpen + ['request_locale' => 'sv']);
    same("pro_profile mail {$kind}: with no stored language the Swedish page decides", $svMail, $pageOnly);
    // The stored language wins over the page.
    same("pro_profile mail {$kind}: the stored language wins over the page (account sv, page en)", $svMail, ms_pf_mail_capture($pp, $kind, $pfOpen + ['account_locale' => 'sv', 'request_locale' => 'en']));
}
// The email-change confirmation goes to an address with no account: it follows the requesting account.
[$h] = $mailSplit($pfSvMails['confirm_email']);
check('pro_profile mail confirm_email: sent to the new address, in the account\'s Swedish', strpos($h, 'To: fresh@example.org') !== false && strpos($pfSvMails['confirm_email'], 'Hej,') !== false);
same('pro_profile mail confirm_email: the account\'s English wins over a Swedish page', (string) file_get_contents($fx . 'mail.confirm_email.en.txt'), ms_pf_mail_capture($pp, 'confirm_email', $pfOpen + ['account_locale' => 'en', 'request_locale' => 'sv']));
// The resend reuses the original mail's subject (email and password); the deletion pair is worded differently in English.
foreach (['email', 'password'] as $what) {
    $a = $mailSplit($pfSvMails["confirm_{$what}"])[0];
    $b = $mailSplit($pfSvMails["resend_{$what}"])[0];
    preg_match('/^Subject: .*(?:\n .*)*$/m', $a, $sa);
    preg_match('/^Subject: .*(?:\n .*)*$/m', $b, $sb);
    check("pro_profile mail resend_{$what}: the subject is the original mail's, byte for byte, in Swedish", $sa !== [] && $sa === $sb);
    $enA = $mailSplit((string) file_get_contents($fx . "mail.confirm_{$what}.en.txt"))[0];
    $enB = $mailSplit((string) file_get_contents($fx . "mail.resend_{$what}.en.txt"))[0];
    preg_match('/^Subject: .*$/m', $enA, $ea);
    preg_match('/^Subject: .*$/m', $enB, $eb);
    check("pro_profile mail resend_{$what}: and in English", $ea !== [] && $ea === $eb);
}
check('pro_profile mail resend_delete: the English subjects differ today, so the resend has its own key', t('mail.confirm_delete.subject', [], 'en') !== t('mail.confirm_delete_resend.subject', [], 'en'));
// Catalog: the placeholders are the same, the 2-hour validity is a placeholder and not part of the copy.
foreach (['mail.confirm_delete.body'] as $k) {
    check("catalog: {$k} takes the hours as a placeholder in both languages", strpos(i18nCatalog('en')[$k], '{hours}') !== false && strpos(i18nCatalog('sv')[$k], '{hours}') !== false && strpos(i18nCatalog('en')[$k], '2 hours') === false);
}
// Scans of pro_profile.php.
$profilePhpMail = (string) file_get_contents($root . '/pro_profile.php');
check('scan: every pro_profile.php mail subject goes through mailEncodeSubject()', substr_count($profilePhpMail, 'mailEncodeSubject(t(') === 8 && substr_count($profilePhpMail, '@mail(') === 7);
check('scan: no pro_profile.php mail subject or body is a literal any more', !preg_match('/\$subject = \'/', $profilePhpMail) && strpos($profilePhpMail, 'Hello,\n\n') === false && strpos($profilePhpMail, 'Regards,') === false);
check('scan: no pro_profile.php mail link is built from base_url by hand', strpos($profilePhpMail, "\$config['email']['base_url'] ?? '') . \"pro_auth.php") === false && substr_count($profilePhpMail, "mailUrl(\$config, 'pro_auth.php?confirm_profile_change=' . urlencode(\$token), \$locale)") === 4);
check('scan: every pro_profile.php mail locale is resolved with the page language as the fallback', substr_count($profilePhpMail, 'mailLocaleFor($pdo, $userId, $config[\'i18n\'] ?? [], i18nLocale())') === 5);
check('scan: no pro_profile.php log line carries a locale beside an address', !preg_match('/logMessage\([^;]*locale[^;]*(?:email|\$to|\$newEmail)/i', $profilePhpMail));

ms_test_cleanup($pp['root']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
