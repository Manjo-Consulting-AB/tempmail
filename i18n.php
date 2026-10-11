<?php
/**
 * Mail Shield — interface languages (documentaion/I18N.md).
 *
 * English is the source language and the fallback for every missing string.
 * Other languages are switched on per environment with LOCALES_ENABLED
 * ($config['i18n']['enabled']), and a public page is offered in a language only
 * once it is listed in i18nTranslatedPages() — so a half-translated language can
 * sit in the repository, switched on in development, without a crawler or a
 * visitor ever seeing an English page under a /sv/ URL.
 *
 * How the language of a request is decided:
 *   - public pages: by the URL alone. /terms.php is English, /sv/terms.php is
 *     Swedish. No cookie, no Accept-Language, no redirect based on either — a
 *     URL always renders the same language, which is what lets each version be
 *     indexed (brief §14). .htaccess maps /<locale>/<page>.php onto the same
 *     file; i18nBootstrap() reads the prefix from REQUEST_URI.
 *   - app pages and JSON endpoints: the account's saved choice, then the
 *     browser's Accept-Language, then English; an endpoint called from a page
 *     follows that page's X-MS-Locale header. No cookie. See i18nAppLocale()
 *     and documentaion/I18N.md §2.1.
 *
 * Catalogs are plain PHP arrays in lang/<locale>.php — no gettext, so nothing
 * needs compiling, no system locale has to exist on the host, and OPcache
 * caches them like any other file. t() returns plain text: the caller escapes
 * it like any other value (te() is the escaped shortcut).
 *
 * Like the other libraries here: no config.php, no session, no database, every
 * function guarded with function_exists so a repeat require cannot redeclare.
 */

if (!function_exists('i18nSupportedLocales')) {

    /**
     * Every language the code knows, source language first. A locale must be
     * here AND have a catalog in lang/ to be switched on. The value is the
     * language's own name, shown in the language switcher.
     *
     * @return array<string,string>
     */
    function i18nSupportedLocales(): array
    {
        return [
            'en' => 'English',
            'sv' => 'Svenska',
        ];
    }

    /** The source language: the fallback, and the one without a URL prefix. */
    function i18nDefaultLocale(): string
    {
        return 'en';
    }

    /**
     * The switched-on languages, source language first, from a comma-separated
     * LOCALES_ENABLED value. Unknown codes are dropped; English is always on.
     *
     * @return list<string>
     */
    function i18nParseEnabled(string $raw): array
    {
        $supported = i18nSupportedLocales();
        $out = [i18nDefaultLocale()];
        foreach (explode(',', strtolower($raw)) as $code) {
            $code = trim($code);
            if ($code !== '' && isset($supported[$code]) && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Public pages and the languages each is translated into, by URL path.
     * A page is listed in a language here only once its copy is translated
     * and reviewed: this list is what the canonical/hreflang tags, the
     * sitemap and the /<locale>/ routes follow. Phase 2 adds 'sv' page by page.
     *
     * A key starting with '@' is a pseudo-entry, not a URL: '@app' lists the
     * languages the signed-in app area (inbox, profile, contact, consent
     * page) is translated into. It is a gate like the others and stays at
     * ['en'] until every app page is translated; sitemap, hreflang and the
     * /<locale>/ routes never see it.
     *
     * $override replaces the list for the rest of the request; only
     * tests/i18n_test.php passes it.
     *
     * @param array<string,list<string>>|null $override
     * @return array<string,list<string>>
     */
    function i18nTranslatedPages(?array $override = null): array
    {
        static $pages = null;
        if ($override !== null) {
            $pages = $override;
        }
        return $pages ?? [
            '@app'                 => ['en'],
            '/'                    => ['en', 'sv'],
            '/temporary-email.php' => ['en', 'sv'],
            '/pricing.php'         => ['en', 'sv'],
            '/faq.php'             => ['en', 'sv'],
            '/blog.php'            => ['en', 'sv'],
            '/register.php'        => ['en'],
            '/pro_login.php'       => ['en'],
            '/privacy.php'         => ['en', 'sv'],
            '/refund-policy.php'   => ['en', 'sv'],
            '/terms.php'           => ['en', 'sv'],
        ];
    }

    /**
     * The languages a page can be shown in right now: translated AND enabled,
     * source language first. An unlisted page is English only.
     *
     * @param list<string> $enabled
     * @return list<string>
     */
    function i18nPageLocales(string $path, array $enabled): array
    {
        if ($path === '' || $path[0] !== '@') {
            $path = i18nNormalisePath($path);
        }
        $translated = i18nTranslatedPages()[$path] ?? [i18nDefaultLocale()];
        $out = [];
        foreach ($enabled as $code) {
            if (in_array($code, $translated, true)) {
                $out[] = $code;
            }
        }
        return $out === [] ? [i18nDefaultLocale()] : $out;
    }

    /** '' and 'index.php' are '/'; every path gets exactly one leading slash. */
    function i18nNormalisePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return ($path === '/index.php') ? '/' : $path;
    }

    /**
     * Splits a request path into [locale prefix or null, path without it].
     * Only a two-letter segment the code supports counts as a prefix, so
     * nothing else on the site can be mistaken for one.
     *
     * @return array{0:?string,1:string}
     */
    function i18nSplitPath(string $requestPath): array
    {
        $requestPath = '/' . ltrim($requestPath, '/');
        if (preg_match('#^/([a-z]{2})(?:/(.*))?$#', $requestPath, $m)
            && isset(i18nSupportedLocales()[$m[1]])
            && $m[1] !== i18nDefaultLocale()) {
            return [$m[1], i18nNormalisePath($m[2] ?? '')];
        }
        return [null, i18nNormalisePath($requestPath)];
    }

    /** Sets the language of this request. An unknown code means English. */
    function i18nSetLocale(string $locale): void
    {
        $GLOBALS['__ms_locale'] = isset(i18nSupportedLocales()[$locale]) ? $locale : i18nDefaultLocale();
    }

    /** The language of this request. */
    function i18nLocale(): string
    {
        $locale = $GLOBALS['__ms_locale'] ?? null;
        return is_string($locale) ? $locale : i18nDefaultLocale();
    }

    /**
     * Decides the language of a web request from its URL and returns null, or
     * the URL to redirect to when the URL names a language this page is not
     * offered in (not switched on, or not translated yet). The caller sends the
     * redirect; this function only decides, so it can be tested.
     *
     * @param list<string> $enabled
     */
    function i18nResolveRequest(array $server, array $enabled): ?string
    {
        i18nSetLocale(i18nDefaultLocale());

        $uri = (string) ($server['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return null; // CLI, cron, the mail pipe
        }

        $path  = (string) parse_url($uri, PHP_URL_PATH);
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        [$prefix, $rest] = i18nSplitPath($path);
        if ($prefix === null) {
            return null;
        }

        if (in_array($prefix, i18nPageLocales($rest, $enabled), true)) {
            i18nSetLocale($prefix);
            return null;
        }

        return $rest . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Applies i18nResolveRequest() to the current request: sets the language,
     * or answers a 302 to the page in the source language and stops.
     *
     * @param array<string,mixed> $i18nConfig $config['i18n']
     */
    function i18nBootstrap(array $i18nConfig, array $server): void
    {
        $enabled = $i18nConfig['enabled'] ?? [i18nDefaultLocale()];
        $redirect = i18nResolveRequest($server, is_array($enabled) ? $enabled : [i18nDefaultLocale()]);
        if ($redirect !== null && !headers_sent()) {
            header('Location: ' . $redirect, true, 302);
            exit;
        }
    }

    /**
     * The best candidate for an Accept-Language header, or null. Entries are
     * ordered by q-value, highest first, header order on a tie; the primary
     * subtag is matched case-insensitively (sv-SE matches sv). q=0 and
     * malformed entries (including "*") are ignored. Pure.
     *
     * @param list<string> $candidates
     */
    function i18nAcceptLanguage(string $header, array $candidates): ?string
    {
        $entries = [];
        foreach (explode(',', $header) as $index => $part) {
            $bits = explode(';', trim($part));
            $tag = trim($bits[0]);
            if (!preg_match('/^([A-Za-z]{1,8})(?:-[A-Za-z0-9]{1,8})*$/', $tag, $m)) {
                continue;
            }
            $q = 1.0;
            if (isset($bits[1])) {
                if (!preg_match('/^\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\s*$/i', $bits[1], $qm)) {
                    continue;
                }
                $q = (float) $qm[1];
            }
            if ($q <= 0.0) {
                continue;
            }
            $entries[] = [$q, $index, strtolower($m[1])];
        }
        usort($entries, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        foreach ($entries as [, , $primary]) {
            foreach ($candidates as $candidate) {
                if (strtolower($candidate) === $primary) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * The language of an app page or JSON endpoint (documentaion/I18N.md
     * §2.1). Candidates are the enabled languages the '@app' gate offers.
     * In order: for an endpoint, the X-MS-Locale header when that language is
     * enabled (checked against $enabled only, because a public /sv/ page
     * sends it); the account's saved language; Accept-Language; English.
     * Pure.
     *
     * @param array<string,mixed> $server
     * @param list<string>        $enabled
     */
    function i18nAppLocale(array $server, ?string $accountLocale, array $enabled, bool $isApi): string
    {
        $candidates = array_values(array_intersect($enabled, i18nPageLocales('@app', $enabled)));

        if ($isApi) {
            $header = $server['HTTP_X_MS_LOCALE'] ?? null;
            if (is_string($header) && in_array($header, $enabled, true)) {
                return $header;
            }
        }
        if ($accountLocale !== null && in_array($accountLocale, $candidates, true)) {
            return $accountLocale;
        }
        $accept = i18nAcceptLanguage((string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? ''), $candidates);
        if ($accept !== null) {
            return $accept;
        }
        return i18nDefaultLocale();
    }

    /**
     * pro_users.locale for an account, or null when none is chosen, the value
     * is not a supported language, the column does not exist yet or anything
     * fails (fail-open; a WARNING with user_id at most).
     */
    function i18nAccountLocale(PDO $pdo, int $userId): ?string
    {
        try {
            $stmt = $pdo->prepare('SELECT locale FROM pro_users WHERE id = ?');
            $stmt->execute([$userId]);
            $value = $stmt->fetchColumn();
            return (is_string($value) && isset(i18nSupportedLocales()[$value])) ? $value : null;
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Could not read the account language', ['user_id' => $userId]);
            }
            return null;
        }
    }

    /**
     * Stores an account's language. Never throws; false for an unsupported
     * language, a missing column or any error. With $onlyIfEmpty the row is
     * changed only while its locale is still NULL.
     */
    function proUserSetLocale(PDO $pdo, int $userId, string $locale, bool $onlyIfEmpty): bool
    {
        if (!isset(i18nSupportedLocales()[$locale])) {
            return false;
        }
        try {
            $sql = 'UPDATE pro_users SET locale = ? WHERE id = ?' . ($onlyIfEmpty ? ' AND locale IS NULL' : '');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$locale, $userId]);
            return true;
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Could not store the account language', ['user_id' => $userId]);
            }
            return false;
        }
    }

    /**
     * Sets this request's language for an app page or endpoint and tells
     * caches about it (Vary, Content-Language) while headers can still be
     * sent. $userId is the signed-in account or null.
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $i18nConfig $config['i18n']
     */
    function i18nUseAppLocale(PDO $pdo, ?int $userId, array $server, array $i18nConfig, bool $isApi = false): void
    {
        $enabled = $i18nConfig['enabled'] ?? [i18nDefaultLocale()];
        $enabled = is_array($enabled) ? array_values($enabled) : [i18nDefaultLocale()];
        $account = ($userId !== null && $userId > 0) ? i18nAccountLocale($pdo, $userId) : null;
        i18nSetLocale(i18nAppLocale($server, $account, $enabled, $isApi));
        if (!headers_sent()) {
            header('Vary: Accept-Language, X-MS-Locale', false);
            header('Content-Language: ' . i18nLocale());
        }
    }

    /**
     * The message for a service result at the web edge: the translated
     * error_key (with error_params) when the service gave one, else its
     * English error, else $fallback. MCP never calls this.
     *
     * @param array<string,mixed> $result
     */
    function i18nServiceError(array $result, string $fallback): string
    {
        $key = $result['error_key'] ?? null;
        if (is_string($key) && $key !== '') {
            return t($key, (array) ($result['error_params'] ?? []));
        }
        return (string) ($result['error'] ?? $fallback);
    }

    /**
     * A site path in the given language (default: this request's). Only a page
     * offered in that language gets the prefix; anything else — an app page,
     * a page not translated yet — keeps its plain path, so a link can never
     * lead to a redirect. Query and fragment are kept.
     *
     * @param list<string>|null $enabled defaults to $GLOBALS['config']['i18n']['enabled']
     */
    function i18nUrl(string $path, ?string $locale = null, ?array $enabled = null): string
    {
        $locale = $locale ?? i18nLocale();
        if ($enabled === null) {
            $enabled = $GLOBALS['config']['i18n']['enabled'] ?? [i18nDefaultLocale()];
        }

        $suffix = '';
        $cut = strcspn($path, '?#');
        if ($cut < strlen($path)) {
            $suffix = substr($path, $cut);
            $path = substr($path, 0, $cut);
        }
        $path = i18nNormalisePath($path);

        if ($locale === i18nDefaultLocale() || !in_array($locale, i18nPageLocales($path, $enabled), true)) {
            return $path . $suffix;
        }
        return '/' . $locale . ($path === '/' ? '/' : $path) . $suffix;
    }

    /**
     * hreflang alternates for a public page: one per language it is offered
     * in plus x-default (the source language). Empty when the page exists in
     * one language only, so a monolingual site emits no hreflang at all.
     *
     * @param list<string> $enabled
     * @return list<array{hreflang:string,href:string}>
     */
    function i18nAlternates(string $path, array $enabled, string $origin): array
    {
        $locales = i18nPageLocales($path, $enabled);
        if (count($locales) < 2) {
            return [];
        }
        $origin = rtrim($origin, '/');
        $out = [];
        foreach ($locales as $code) {
            $out[] = ['hreflang' => $code, 'href' => $origin . i18nUrl($path, $code, $enabled)];
        }
        $out[] = ['hreflang' => 'x-default', 'href' => $origin . i18nUrl($path, i18nDefaultLocale(), $enabled)];
        return $out;
    }

    /**
     * The catalog for a language: lang/<locale>.php, read once per request.
     *
     * @return array<string,string|array<string,string>>
     */
    function i18nCatalog(string $locale): array
    {
        static $cache = [];
        if (!isset(i18nSupportedLocales()[$locale])) {
            return [];
        }
        if (!isset($cache[$locale])) {
            $file = __DIR__ . '/lang/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            $cache[$locale] = is_array($data) ? $data : [];
        }
        return $cache[$locale];
    }

    /** Fills {name} placeholders. Values are inserted as given (plain text). */
    function i18nFormat(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }
        $map = [];
        foreach ($params as $name => $value) {
            $map['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $map);
    }

    /**
     * The string for $key in this request's language, falling back to English
     * and then to the key itself (so a missing string is visible, never empty).
     * Returns plain text — escape it like any other value, or use te().
     */
    function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? i18nLocale();
        $value = i18nCatalog($locale)[$key] ?? i18nCatalog(i18nDefaultLocale())[$key] ?? $key;
        if (is_array($value)) {
            $value = $value['other'] ?? reset($value);
        }
        return i18nFormat((string) $value, $params);
    }

    /**
     * Plural form of $key for $count: the catalog value is
     * ['one' => '…', 'other' => '…']. English and Swedish share the rule
     * "one for exactly 1"; a language with other forms adds its rule here.
     * {count} is always available as a placeholder.
     */
    function tp(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? i18nLocale();
        $value = i18nCatalog($locale)[$key] ?? i18nCatalog(i18nDefaultLocale())[$key] ?? $key;
        if (is_array($value)) {
            $form = ($count === 1) ? 'one' : 'other';
            $value = $value[$form] ?? $value['other'] ?? reset($value);
        }
        return i18nFormat((string) $value, $params + ['count' => $count]);
    }

    /** t(), escaped for HTML text and attributes. */
    function te(string $key, array $params = []): string
    {
        return htmlspecialchars(t($key, $params), ENT_QUOTES, 'UTF-8');
    }

    /**
     * t() with markup. The translation is looked up as t() does (fallback and
     * all) and $params are filled in as plain text; the whole result is then
     * escaped, and only afterwards is each {name} in $html replaced by its raw
     * value. Paired markup uses {name} and {name_end}:
     *
     *   tHtml($loginKey, [], ['login' => '<a href="' . $url . '">', 'login_end' => '</a>'])
     *   // 'Already have an account? {login}Log in{login_end}'
     *
     * $html values are markup the calling code builds from literals and
     * already-escaped values. They NEVER come from a request, the database or
     * a catalog — they are inserted unescaped.
     *
     * @param array<string,scalar> $params plain text
     * @param array<string,string> $html   trusted markup
     * @throws InvalidArgumentException when a key is in both arrays
     */
    function tHtml(string $key, array $params = [], array $html = []): string
    {
        $both = array_intersect_key($params, $html);
        if ($both !== []) {
            throw new InvalidArgumentException('tHtml(): "' . (string) array_key_first($both) . '" is in both $params and $html');
        }
        $text = htmlspecialchars(t($key, $params), ENT_QUOTES, 'UTF-8');
        if ($html === []) {
            return $text;
        }
        $map = [];
        foreach ($html as $name => $markup) {
            $map['{' . $name . '}'] = (string) $markup;
        }
        return strtr($text, $map);
    }

    /**
     * A number in this request's language: a no-break space (U+00A0) between
     * thousands in every language, "." as the decimal separator in English and
     * "," in Swedish.
     */
    function i18nNumber(int|float $n, int $decimals = 0, ?string $locale = null): string
    {
        $locale = $locale ?? i18nLocale();
        $decimalSeparator = ($locale === 'sv') ? ',' : '.';
        return number_format($n, max(0, $decimals), $decimalSeparator, "\u{00A0}");
    }

    /**
     * A date as "j F Y" in this request's language ("5 March 2026",
     * "5 mars 2026"), with the month names from the catalog keys
     * date.month.1 … date.month.12. English equals date('j F Y', $timestamp).
     */
    function i18nDate(int $timestamp, ?string $locale = null): string
    {
        $locale = $locale ?? i18nLocale();
        $monthKey = 'date.month.' . (int) date('n', $timestamp);
        return date('j', $timestamp) . ' ' . t($monthKey, [], $locale) . ' ' . date('Y', $timestamp);
    }

    /**
     * Every English key that starts with one of $prefixes, with its value in
     * $locale (default: this request's), falling back to English. Plural
     * arrays are passed through unchanged. Feeds partials/i18n_js.php.
     *
     * @param list<string> $prefixes
     * @return array<string,string|array<string,string>>
     */
    function i18nCatalogSubset(array $prefixes, ?string $locale = null): array
    {
        $locale = $locale ?? i18nLocale();
        $own = i18nCatalog($locale);
        $out = [];
        foreach (i18nCatalog(i18nDefaultLocale()) as $key => $value) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && strncmp((string) $key, $prefix, strlen($prefix)) === 0) {
                    $out[$key] = $own[$key] ?? $value;
                    break;
                }
            }
        }
        return $out;
    }
}
