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
 *   - app pages (a later phase): the account's saved choice, then a cookie.
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
            '/'                    => ['en'],
            '/temporary-email.php' => ['en'],
            '/pricing.php'         => ['en'],
            '/faq.php'             => ['en'],
            '/blog.php'            => ['en'],
            '/register.php'        => ['en'],
            '/pro_login.php'       => ['en'],
            '/terms.php'           => ['en'],
            '/privacy.php'         => ['en'],
            '/refund-policy.php'   => ['en'],
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
        $path = i18nNormalisePath($path);
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
}
