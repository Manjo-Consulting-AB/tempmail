<?php

declare(strict_types=1);

/**
 * OAuth 2.1 authorization endpoint and consent page (epic #331 step 3/6) —
 * where the user is sent by the app they are connecting, sees who is asking
 * and for what, and answers. It is the only step in the flow where a human
 * decides anything, and the only one that issues an authorization code.
 *
 * `/oauth/authorize` (which .htaccess rewrites here) answers GET with the
 * consent page and POST with the decision; anything else is 405. The request
 * is never carried by the form: GET validates it, stores the validated form of
 * it in the session, and the POST re-validates that same stored request. The
 * browser posts a CSRF token and the scope the user picked, and nothing else —
 * there is no hidden client_id or redirect_uri to tamper with, because there
 * is nothing here that trusts one.
 *
 * Validation order, and where a failure goes (oauthAuthorizeRequestValidate()
 * in oauth_server.php):
 *
 *  1. An unknown client_id, or a redirect_uri that is missing or not an exact
 *     match of one the client registered, renders an error page on our own
 *     origin and never redirects (RFC 6749 §4.1.2.1). Redirecting those would
 *     hand anyone a redirect gadget.
 *  2. From there on, an error goes back to the proven redirect_uri as
 *     `error=…&state=…&iss=…`: invalid_request, unsupported_response_type,
 *     invalid_scope or access_denied. `iss` (RFC 9207) is our own origin.
 *  3. response_type=code only; PKCE S256 mandatory (plain refused, not
 *     downgraded); scope a subset of the two MCP scopes; `resource`, if named,
 *     exactly the canonical `<base_url>/mcp`; `state` opaque, passed through,
 *     capped at 512 characters.
 *
 * Who may approve: the session must hold an account (a signed-out visitor is
 * sent to pro_login.php through the after-login mechanism in after_login.php,
 * which stores this validated request server-side and resumes it — the user
 * carries no URL they could edit), the account must be Pro, and it must have
 * room for one more grant. A suspended account is signed out before any of
 * this, exactly as everywhere else (proSessionEndIfSuspended()).
 *
 * On Allow the code is minted by oauthAuthorizationCodeCreate(): 32 random
 * bytes as hex, 60 seconds, only its sha256 stored — an ordinary row in
 * oauth_authorization_codes, which the token endpoint built in step 2/6
 * already knows how to exchange. On Cancel the answer is `access_denied`.
 *
 * The consent page is in the app style group (Bootstrap and the two Mail
 * Shield layers, brief §11) and is `noindex, nofollow` with no analytics
 * partial. It refuses framing — `X-Frame-Options: DENY` and CSP
 * `frame-ancestors 'none'` — so the two answers cannot be clickjacked. The
 * app's name is attacker-controlled, so it is escaped, quoted, and explicitly
 * labelled as the name the app gives itself.
 *
 * Abuse limits go through abuse_guard.php, fail-open: per-IP and, once signed
 * in, per-account requests per hour and per day, over either being 429 with
 * Retry-After. The per-IP subject is oauthIpHash()'s keyed hash — never a raw
 * address — and the per-account one is the account id, never an address and
 * never a client_id.
 *
 * Logging: one INFO line per decision with the account id, the client id and
 * the granted scopes, plus a WARNING with the visitor's IP for a rate-limit
 * hit and for a rejected CSRF token. The authorization code and the redirect
 * query are never logged, and neither is the app's name.
 */

define('TEMPMAIL_APP', true);

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "oauth_authorize.php is an HTTP endpoint; it is not run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth_server.php';
require_once __DIR__ . '/abuse_guard.php';
require_once __DIR__ . '/after_login.php';
require_once __DIR__ . '/partials/brand.php';

/**
 * How long the consent page stays answerable. Long enough to read it and
 * decide, short enough that a tab left open overnight cannot be submitted by
 * whoever picks the device up next. Matches after_login.php's own window.
 */
const OAUTH_AUTHORIZE_PENDING_TTL_SECONDS = 900;

/** The session key holding the validated request waiting for a decision. */
const OAUTH_AUTHORIZE_PENDING_KEY = 'oauth_authorize_pending';

/** The session key holding the per-session CSRF secret. */
const OAUTH_AUTHORIZE_SECRET_KEY = 'oauth_authorize_secret';

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------

/** Our own origin, from $config and never a literal (brief §14). */
function oauthAuthorizeIssuer(): string
{
    $config = $GLOBALS['config'] ?? null;
    $baseUrl = is_array($config) ? (string) ($config['email']['base_url'] ?? '') : '';
    return rtrim($baseUrl, '/');
}

/** Where the user is sent back to, as a host (or the whole URI when there is none). */
function oauthAuthorizeRedirectHost(string $redirectUri): string
{
    $host = parse_url($redirectUri, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return $host;
    }
    return mb_substr($redirectUri, 0, 120);
}

/**
 * The sentence a refused request is explained with on our own page. The two fatal
 * answers of oauthAuthorizeRequestValidate() (an unknown client, a redirect_uri that
 * is not one the client registered) are told in the visitor's language, picked by
 * their OAuth error code; any other description is protocol text from
 * oauth_server.php and is shown as it is, never translated.
 *
 * @param array<string,mixed> $validated
 */
function oauthAuthorizeFatalMessage(array $validated): string
{
    if (!empty($validated['fatal'])) {
        if (($validated['error'] ?? '') === 'invalid_client') {
            return t('oauth.error.unknown_client');
        }
        if (($validated['error'] ?? '') === 'invalid_request') {
            return t('oauth.error.redirect_mismatch');
        }
    }
    return (string) ($validated['error_description'] ?? '');
}

/**
 * Send the user-agent back to the client, carrying the RFC's parameters. The
 * redirect_uri is one that was proven to be this client's own registered
 * string — nothing here is built from anything else.
 *
 * @param array<string,string> $params
 */
function oauthAuthorizeRedirectTo(string $redirectUri, array $params): void
{
    $params = array_filter($params, static fn($value): bool => is_string($value) && $value !== '');
    $separator = str_contains($redirectUri, '?') ? '&' : '?';
    $location = $params === []
        ? $redirectUri
        : $redirectUri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $location, true, 302);
    exit;
}

/**
 * The session's CSRF secret. Generated once per session: the token below is
 * an HMAC over this request under it, so a token is only ever valid for this
 * session and this exact request.
 */
function oauthAuthorizeCsrfSecret(): string
{
    $secret = $_SESSION[OAUTH_AUTHORIZE_SECRET_KEY] ?? null;
    if (!is_string($secret) || $secret === '') {
        $secret = bin2hex(random_bytes(32));
        $_SESSION[OAUTH_AUTHORIZE_SECRET_KEY] = $secret;
    }
    return $secret;
}

/**
 * The stable signature of a validated request. Every field that decides what
 * a code would grant or where it would be sent is in it, so a token minted
 * for one request cannot be replayed against another.
 *
 * @param array<string,string> $params the stored, validated request parameters
 */
function oauthAuthorizeRequestSignature(array $params): string
{
    $parts = [];
    foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'resource', 'state'] as $key) {
        $parts[] = $key . '=' . (isset($params[$key]) && is_string($params[$key]) ? $params[$key] : '');
    }
    return implode("\n", $parts);
}

/** The CSRF token bound to this session and to this exact request. */
function oauthAuthorizeCsrfToken(array $params): string
{
    return hash_hmac('sha256', oauthAuthorizeRequestSignature($params), oauthAuthorizeCsrfSecret());
}

// ---------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------

/**
 * The page shell: the auth layout (one centred card, brand bar, footer) with
 * the app style group's stylesheets (brief §11) and no analytics partial.
 * $body is already-escaped markup.
 */
function oauthAuthorizePage(string $title, string $body): void
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(i18nLocale(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FAFAF9">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $escape($title); ?> · Mail Shield</title>
<?php require __DIR__ . '/partials/favicons.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="ms-auth">
        <header class="ms-auth__bar">
            <div class="ms-auth__bar-inner">
                <?php echo ms_logo(['href' => '/']); ?>
            </div>
        </header>

        <main class="ms-auth__main">
            <div class="ms-card ms-auth__card">
                <?php echo $body; ?>
            </div>
        </main>

        <footer class="ms-auth__foot">
            <p><a href="/">&larr; <?php echo $escape(t('oauth.consent.back')); ?></a><span class="ms-auth__foot-sep" aria-hidden="true">&middot;</span><span>&copy; <?php echo date('Y'); ?> Manjo Consulting AB</span></p>
        </footer>
    </div>
<?php require __DIR__ . '/partials/app_locale_js.php'; ?>
</body>
</html>
    <?php
}

/**
 * A page that says no, on our own origin. $title and $message are escaped;
 * $actionHtml is markup the caller built (a link, or nothing).
 */
function oauthAuthorizeNoticePage(int $status, string $title, string $message, string $actionHtml = ''): void
{
    http_response_code($status);
    $body = '<h1 class="ms-auth__title">' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</h1>'
        . '<p class="ms-auth__sub">' . htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
        . ($actionHtml !== '' ? '<div class="ms-grant__actions">' . $actionHtml . '</div>' : '');
    oauthAuthorizePage($title, $body);
    exit;
}

// ---------------------------------------------------------------------
// Headers
// ---------------------------------------------------------------------

// Nothing here may be cached or framed. The CSP is the load-bearing half:
// .htaccess sets `X-Frame-Options: SAMEORIGIN` for the whole tree, and this
// page's own DENY is what the response carries when there is no .htaccess
// (the test servers) — while `frame-ancestors` is what a modern browser
// enforces either way.
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

// ---------------------------------------------------------------------
// 1. The migration has to have run; then something must answer
// ---------------------------------------------------------------------

if (!oauthAvailable($pdo)) {
    // No session yet, so only the browser's language can apply (I18N.md 2.1).
    if (function_exists('i18nUseAppLocale')) {
        i18nUseAppLocale($pdo, null, $_SERVER, $config['i18n'] ?? []);
    }
    oauthAuthorizeNoticePage(503, t('oauth.error.not_available_title'), t('oauth.error.not_available'));
}

session_start();
// A suspended account is signed out before anything trusts the session.
proSessionEndIfSuspended();
if (function_exists('i18nUseAppLocale')) {
    i18nUseAppLocale($pdo, isset($_SESSION['pro_user_id']) ? (int) $_SESSION['pro_user_id'] : null, $_SERVER, $config['i18n'] ?? []);
}

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    oauthAuthorizeNoticePage(405, t('oauth.error.method_title'), t('oauth.error.method'));
}

// ---------------------------------------------------------------------
// 2. Abuse limits, fail-open
// ---------------------------------------------------------------------

/** The verdict this request must be refused with, or null to go ahead. */
function oauthAuthorizeRateLimit(PDO $pdo): ?array
{
    try {
        if (!abuseGuardAvailable()) {
            return null;
        }
        $settings = abuseGuardSettings();
        $now = time();

        $ipHash = oauthIpHash(getVisitorIp());
        if ($ipHash !== null) {
            $ipVerdict = abuseOauthAuthorizeIpRateLimit($pdo, $ipHash, $now, $settings);
            if ($ipVerdict['limited']) {
                return $ipVerdict;
            }
        }

        $userId = (int) ($_SESSION['pro_user_id'] ?? 0);
        if ($userId > 0) {
            $userVerdict = abuseOauthAuthorizeUserRateLimit($pdo, $userId, $now, $settings);
            if ($userVerdict['limited']) {
                return $userVerdict;
            }
        }
    } catch (Throwable $e) {
        logMessage('WARNING', 'OAuth authorize rate limit unavailable', ['error' => $e->getMessage()]);
    }
    return null;
}

$limited = oauthAuthorizeRateLimit($pdo);
if ($limited !== null) {
    logMessage('WARNING', 'OAuth authorization rejected: rate limit', [
        'ip' => getVisitorIp(),
        'rule' => $limited['rule'],
    ]);
    header('Retry-After: ' . (int) $limited['retry_after']);
    oauthAuthorizeNoticePage(429, t('oauth.error.rate_title'), t('oauth.error.rate'));
}

// ---------------------------------------------------------------------
// 3. Is this account allowed to approve anything at all right now?
// ---------------------------------------------------------------------

/**
 * The reasons an otherwise valid request still cannot be granted, checked the
 * same way on GET and on POST so a session that changed between the two is
 * caught. Returns null when nothing blocks it.
 *
 * @param array<string,mixed> $authRequest
 * @return array{status:int,title:string,message:string,action:string}|null
 */
function oauthAuthorizeBlocked(PDO $pdo, array $authRequest, int $userId): ?array
{
    if (!proUserIsPro($userId)) {
        return [
            'status' => 403,
            'title' => t('oauth.error.need_pro_title'),
            'message' => t('oauth.error.need_pro'),
            'action' => '<a class="btn btn-primary" href="pro_profile_page.php">' . te('oauth.error.need_pro_action') . '</a>',
        ];
    }
    // One grant per (user, client): authorising a client the account already
    // connected replaces that grant and is always allowed. Only a genuinely
    // new app can run into the cap.
    if (!oauthGrantExists($pdo, (string) $authRequest['client_id'], $userId)
        && oauthGrantCount($pdo, $userId) >= OAUTH_GRANT_MAX_PER_USER) {
        return [
            'status' => 403,
            'title' => t('oauth.error.too_many_title'),
            'message' => t('oauth.error.too_many'),
            'action' => '<a class="btn btn-primary" href="pro_profile_page.php#settings-security">' . te('oauth.error.too_many_action') . '</a>',
        ];
    }
    return null;
}

// ---------------------------------------------------------------------
// 4. GET: validate, then show the page (or send the user to sign in)
// ---------------------------------------------------------------------

if ($requestMethod === 'GET') {
    $validated = oauthAuthorizeRequestValidate($pdo, $_GET);

    if (!$validated['ok']) {
        if ($validated['fatal']) {
            // Never redirect: the client or the redirect_uri is unproven.
            oauthAuthorizeNoticePage(400, t('oauth.error.link_title'), oauthAuthorizeFatalMessage($validated));
        }
        oauthAuthorizeRedirectTo(
            (string) $validated['redirect_uri'],
            [
                'error' => (string) $validated['error'],
                'error_description' => (string) $validated['error_description'],
                'state' => isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '',
                'iss' => oauthAuthorizeIssuer(),
            ]
        );
    }

    /** @var array<string,mixed> $authRequest */
    $authRequest = $validated['request'];

    // The parameters a resume replays. Built from the validated request, not
    // from $_GET: an unknown parameter the caller invented has no way in.
    $resumeParams = [
        'client_id' => (string) $authRequest['client_id'],
        'redirect_uri' => (string) $authRequest['redirect_uri'],
        'response_type' => 'code',
        'code_challenge' => (string) $authRequest['code_challenge'],
        'code_challenge_method' => 'S256',
        'scope' => (string) $authRequest['scope'],
    ];
    if ($authRequest['resource'] !== '') {
        $resumeParams['resource'] = (string) $authRequest['resource'];
    }
    if ($authRequest['state'] !== '') {
        $resumeParams['state'] = (string) $authRequest['state'];
    }

    $userId = (int) ($_SESSION['pro_user_id'] ?? 0);
    if ($userId <= 0) {
        // Sign in first. The request waits in the session — the user carries
        // no URL parameter they could edit, and only /oauth/authorize can be
        // resumed (after_login.php).
        afterLoginSet('oauth_authorize', $resumeParams);
        header('Location: pro_login.php');
        exit;
    }

    $blocked = oauthAuthorizeBlocked($pdo, $authRequest, $userId);
    if ($blocked !== null) {
        oauthAuthorizeNoticePage((int) $blocked['status'], (string) $blocked['title'], (string) $blocked['message'], (string) $blocked['action']);
    }

    // The request waits for the decision here; the form carries no part of it.
    $_SESSION[OAUTH_AUTHORIZE_PENDING_KEY] = [
        'params' => $resumeParams,
        'created_at' => time(),
    ];
    $csrfToken = oauthAuthorizeCsrfToken($resumeParams);

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $appName = (string) $authRequest['client_name'];
    $appHost = oauthAuthorizeRedirectHost((string) $authRequest['redirect_uri']);
    $wantsWrite = in_array('write', $authRequest['scopes'], true);

    $scopesHtml = '<label class="ms-grant__scope">'
        . '<input type="radio" name="scope" value="' . $escape(OAUTH_SCOPE_READ) . '" checked>'
        . '<span><span class="ms-grant__scope-title">' . te('oauth.consent.scope_read_title') . '</span>'
        . '<span class="ms-grant__scope-detail">' . te('oauth.consent.scope_read_detail') . '</span></span>'
        . '</label>';
    if ($wantsWrite) {
        // The broader grant is offered, never assumed: the narrower radio is
        // the one that starts checked, so a careless click grants less.
        $scopesHtml .= '<label class="ms-grant__scope">'
            . '<input type="radio" name="scope" value="' . $escape(OAUTH_SCOPE_READ . ' ' . OAUTH_SCOPE_WRITE) . '">'
            . '<span><span class="ms-grant__scope-title">' . te('oauth.consent.scope_write_title') . '</span>'
            . '<span class="ms-grant__scope-detail">' . te('oauth.consent.scope_write_detail') . '</span></span>'
            . '</label>';
    }

    // The app's name and the redirect host are attacker-controlled values: they go in as
    // parameters (te()) or as the tHtml() text parameter, so they are escaped and shown
    // literally, and never appear in a catalog value. The sentences around them are translated.
    $body = '<h1 class="ms-auth__title">' . te('oauth.consent.title') . '</h1>'
        . '<p class="ms-auth__sub">' . te('oauth.consent.sub') . '</p>'
        . '<div class="ms-grant__app">'
        . '<p class="ms-grant__app-name">' . te('oauth.consent.app_name', ['name' => $appName]) . '</p>'
        . '<p class="ms-grant__app-note">' . te('oauth.consent.app_note') . '</p>'
        . '<p class="ms-grant__app-host">' . tHtml('oauth.consent.app_host', ['host' => $appHost], ['code' => '<code>', 'code_end' => '</code>']) . '</p>'
        . '</div>'
        . '<form method="post" action="">'
        . '<input type="hidden" name="csrf" value="' . $escape($csrfToken) . '">'
        . '<fieldset class="ms-grant__scopes"><legend class="ms-visually-hidden">' . te('oauth.consent.scopes_legend') . '</legend>' . $scopesHtml . '</fieldset>'
        . '<p class="ms-grant__notice">' . te('oauth.consent.notice') . '</p>'
        . '<div class="ms-grant__actions">'
        // Cancel first in the DOM on purpose: a stray Enter picks "Cancel",
        // so nothing is granted by accident.
        . '<button type="submit" name="decision" value="cancel" class="btn btn-outline-secondary">' . te('oauth.consent.cancel') . '</button>'
        . '<button type="submit" name="decision" value="allow" class="btn btn-primary">' . te('oauth.consent.allow') . '</button>'
        . '</div>'
        . '</form>';

    oauthAuthorizePage(t('oauth.consent.title'), $body);
    exit;
}

// ---------------------------------------------------------------------
// 5. POST: the decision
// ---------------------------------------------------------------------

/**
 * A POST that cannot be trusted answers on our own origin — never with a
 * redirect, because a redirect at this point would be a redirect to a URI
 * nothing has proven.
 */
function oauthAuthorizeRefusePost(string $title, string $message, int $status = 400): void
{
    oauthAuthorizeNoticePage($status, $title, $message, '<a class="btn btn-secondary" href="/">' . te('oauth.error.close') . '</a>', true);
}

$pending = $_SESSION[OAUTH_AUTHORIZE_PENDING_KEY] ?? null;
if (!is_array($pending) || !is_array($pending['params'] ?? null)
    || (int) ($pending['created_at'] ?? 0) < time() - OAUTH_AUTHORIZE_PENDING_TTL_SECONDS) {
    unset($_SESSION[OAUTH_AUTHORIZE_PENDING_KEY]);
    oauthAuthorizeRefusePost(t('oauth.error.expired_title'), t('oauth.error.restart'));
}

/** @var array<string,mixed> $pendingParams */
$pendingParams = $pending['params'];

// Re-validate everything from the stored request: the form's own fields are
// never the source of truth for who is asking or where the answer goes.
$revalidated = oauthAuthorizeRequestValidate($pdo, $pendingParams);
if (!$revalidated['ok']) {
    unset($_SESSION[OAUTH_AUTHORIZE_PENDING_KEY]);
    oauthAuthorizeRefusePost(t('oauth.error.invalid_title'), oauthAuthorizeFatalMessage($revalidated));
}
/** @var array<string,mixed> $authRequest */
$authRequest = $revalidated['request'];

$userId = (int) ($_SESSION['pro_user_id'] ?? 0);
if ($userId <= 0) {
    unset($_SESSION[OAUTH_AUTHORIZE_PENDING_KEY]);
    afterLoginSet('oauth_authorize', $pendingParams);
    header('Location: pro_login.php');
    exit;
}

$blocked = oauthAuthorizeBlocked($pdo, $authRequest, $userId);
if ($blocked !== null) {
    unset($_SESSION[OAUTH_AUTHORIZE_PENDING_KEY]);
    oauthAuthorizeNoticePage((int) $blocked['status'], (string) $blocked['title'], (string) $blocked['message'], (string) $blocked['action']);
}

// The CSRF token is an HMAC of this exact stored request under the session
// secret, so a token from another request — or another session — cannot be
// replayed here, and neither can the form be posted twice: the pending entry
// below is consumed by the first answer.
$postedCsrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
if ($postedCsrf === '' || !hash_equals(oauthAuthorizeCsrfToken($pendingParams), $postedCsrf)) {
    logMessage('WARNING', 'OAuth authorization rejected: bad CSRF token', [
        'user_id' => $userId,
        'ip' => getVisitorIp(),
    ]);
    oauthAuthorizeRefusePost(t('oauth.error.expired_title'), t('oauth.error.restart'), 403);
}

$decision = isset($_POST['decision']) && is_string($_POST['decision']) ? $_POST['decision'] : '';
if ($decision !== 'allow' && $decision !== 'cancel') {
    oauthAuthorizeRefusePost(t('oauth.error.none_chosen_title'), t('oauth.error.restart'));
}

unset($_SESSION[OAUTH_AUTHORIZE_PENDING_KEY]);

if ($decision === 'cancel') {
    logMessage('INFO', 'OAuth authorization denied', [
        'user_id' => $userId,
        'client_id' => (string) $authRequest['client_id'],
    ]);
    oauthAuthorizeRedirectTo((string) $authRequest['redirect_uri'], [
        'error' => 'access_denied',
        'error_description' => 'The user refused the request.',
        'state' => (string) $authRequest['state'],
        'iss' => oauthAuthorizeIssuer(),
    ]);
}

// The scope the user picked. It may only narrow what was asked for: an
// unknown scope, or one the request never named, is refused rather than
// trimmed, so the grant is always exactly one of the two answers on the page.
$chosenRaw = isset($_POST['scope']) && is_string($_POST['scope']) ? $_POST['scope'] : '';
$chosenSet = oauthScopeSetFromExternal($chosenRaw);
if ($chosenSet === null || array_diff($chosenSet, $authRequest['scopes']) !== []) {
    oauthAuthorizeRefusePost(t('oauth.error.not_offered_title'), t('oauth.error.restart'));
}
if ($chosenSet === []) {
    $chosenSet = ['read'];
}

try {
    $code = oauthAuthorizationCodeCreate(
        $pdo,
        (string) $authRequest['client_id'],
        $userId,
        (string) $authRequest['redirect_uri'],
        oauthScopeExternalFromSet($chosenSet),
        (string) $authRequest['code_challenge'],
        (string) $authRequest['resource']
    );
} catch (Throwable $e) {
    logMessage('ERROR', 'OAuth authorization code could not be written', [
        'user_id' => $userId,
        'client_id' => (string) $authRequest['client_id'],
        'error' => $e->getMessage(),
    ]);
    oauthAuthorizeNoticePage(500, t('oauth.error.went_wrong_title'), t('oauth.error.went_wrong'));
}

if ($code === null) {
    oauthAuthorizeNoticePage(500, t('oauth.error.went_wrong_title'), t('oauth.error.went_wrong'));
}

// The log line carries the decision and who made it. The code itself and the
// redirect query are never logged.
logMessage('INFO', 'OAuth authorization approved', [
    'user_id' => $userId,
    'client_id' => (string) $authRequest['client_id'],
    'scopes' => oauthScopeExternalFromSet($chosenSet),
]);

oauthAuthorizeRedirectTo((string) $authRequest['redirect_uri'], [
    'code' => $code,
    'state' => (string) $authRequest['state'],
    'iss' => oauthAuthorizeIssuer(),
]);
