<?php

declare(strict_types=1);

/**
 * "Return here once you have signed in" (epic #331 step 3/6) — the small,
 * generic mechanism the OAuth authorization endpoint needs, because signing in
 * happens on pro_login.php and every sign-in path there ends at the inbox.
 *
 * The shape is deliberately not "?next=<url>". A URL parameter is the user's
 * to edit, and any code that redirects to one is an open redirect waiting to be
 * reported. Instead the *destination is a route name*, checked against the
 * allow-list below, and the whole thing lives in the session: the user never
 * sees or carries it. A session is identified by its cookie, so what comes back
 * after sign-in is what this server stored, not what the user typed.
 *
 * Resuming is what a route names, and nothing else:
 *
 *  - afterLoginSet($route, $query) stores the destination. An unknown route
 *    name is refused outright (returns false) — the allow-list is the only
 *    thing that can ever become a Location header.
 *  - afterLoginTake() returns the relative URL once and removes it, so a
 *    reload of the landing page cannot replay it. That is what every sign-in
 *    path in pro_auth.php and pro_login.php calls instead of the hardcoded
 *    'pro.php'.
 *  - afterLoginUrl() is the read-only form, for tests and diagnostics.
 *
 * The query is rebuilt from scratch with http_build_query(), so a value can
 * never smuggle a CR/LF into a header, and the path never comes from the
 * request. The parameters themselves are already validated by the caller (the
 * authorization endpoint validates its whole request before it stores it); the
 * filter here is the second lock — strings only, control characters refused.
 *
 * Nothing here reads the request, and nothing here logs.
 */

if (!defined('AFTER_LOGIN_TTL_SECONDS')) {
    // Long enough to fetch a magic link out of an inbox, short enough that a
    // forgotten tab does not resume a stale request an hour later.
    define('AFTER_LOGIN_TTL_SECONDS', 900);
    // The one session key this mechanism uses. One pending destination per
    // session: a second request replaces the first, which is what a user who
    // starts a second authorisation expects.
    define('AFTER_LOGIN_SESSION_KEY', 'after_login');
}

if (!function_exists('afterLoginRoutes')) {
    /**
     * The allow-list: route name => the site-relative path it resumes. Only
     * entries here can ever become a redirect target, so adding a route is the
     * one place an open redirect could be introduced — and a name that is not
     * here simply cannot be stored.
     *
     * @return array<string,string>
     */
    function afterLoginRoutes(): array
    {
        return [
            // The OAuth consent page. Its query is the already-validated
            // authorization request (see oauth_server.php); it carries no
            // redirect target of its own that this list does not name.
            'oauth_authorize' => '/oauth/authorize',
        ];
    }
}

if (!function_exists('afterLoginQuery')) {
    /**
     * Only the string values of $query, and only those free of control
     * characters. A non-string (an array sent as ?scope[]=…) is dropped rather
     * than rejected, because the caller's own validation already refused the
     * shapes that matter.
     *
     * @param array<mixed> $query
     * @return array<string,string>
     */
    function afterLoginQuery(array $query): array
    {
        $clean = [];
        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }
}

if (!function_exists('afterLoginSet')) {
    /**
     * Remember where to send the user once they are signed in. False when
     * $route is not in the allow-list, in which case nothing is stored.
     *
     * @param array<mixed> $query
     */
    function afterLoginSet(string $route, array $query = []): bool
    {
        if (!array_key_exists($route, afterLoginRoutes())) {
            return false;
        }
        $_SESSION[AFTER_LOGIN_SESSION_KEY] = [
            'route' => $route,
            'query' => afterLoginQuery($query),
            'created_at' => time(),
        ];
        return true;
    }
}

if (!function_exists('afterLoginUrl')) {
    /**
     * The relative URL to resume, or null when there is nothing stored, the
     * stored route is no longer in the allow-list, or the entry has expired.
     * What it returns is always '/'-rooted and same-origin: the path is the
     * allow-list's own, and the query only ever holds the caller's validated
     * parameters, re-encoded.
     */
    function afterLoginUrl(): ?string
    {
        $stored = $_SESSION[AFTER_LOGIN_SESSION_KEY] ?? null;
        if (!is_array($stored)) {
            return null;
        }
        $routes = afterLoginRoutes();
        $route = $stored['route'] ?? null;
        if (!is_string($route) || !array_key_exists($route, $routes)) {
            unset($_SESSION[AFTER_LOGIN_SESSION_KEY]);
            return null;
        }
        if ((int) ($stored['created_at'] ?? 0) < time() - AFTER_LOGIN_TTL_SECONDS) {
            unset($_SESSION[AFTER_LOGIN_SESSION_KEY]);
            return null;
        }
        $query = afterLoginQuery(is_array($stored['query'] ?? null) ? $stored['query'] : []);
        $url = $routes[$route];
        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('afterLoginTake')) {
    /**
     * The destination, consumed: the next call returns null. Every sign-in
     * path calls this; a path that forgets to leaves the entry for the next
     * sign-in on this device, which the TTL above then bounds.
     */
    function afterLoginTake(): ?string
    {
        $url = afterLoginUrl();
        unset($_SESSION[AFTER_LOGIN_SESSION_KEY]);
        return $url;
    }
}

if (!function_exists('afterLoginClear')) {
    /** Drop a stored destination without using it (a cancelled sign-in). */
    function afterLoginClear(): void
    {
        unset($_SESSION[AFTER_LOGIN_SESSION_KEY]);
    }
}
