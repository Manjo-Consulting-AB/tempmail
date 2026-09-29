<?php

declare(strict_types=1);

/**
 * OAuth 2.1 token endpoint (epic #331 step 2/6) — where a client exchanges the
 * authorization code a user approved for an access token, and later refreshes
 * it. Users' clients are given `<base_url>/oauth/token`, which .htaccess
 * rewrites here; the route follows RFC 6749 §5.2 and OAuth 2.1 §4.1.3/§4.3.
 *
 * Request: POST only, `application/x-www-form-urlencoded`, at most 8 KB. The
 * two grant types are authorization_code (code, redirect_uri, client_id,
 * code_verifier, and resource when the authorization bound one) and
 * refresh_token (refresh_token, client_id, optionally a narrower scope). Both
 * are public PKCE clients — no client secret exists, so there is no client
 * authentication to perform and client_id is only an identity, never a proof.
 * None of it is cached: `Cache-Control: no-store` and `Pragma: no-cache` on
 * every answer, because the body carries credentials.
 *
 * The exchange itself lives in oauth_server.php; this file is the transport.
 * It validates the request shape, rate limits it, calls the grant function and
 * turns the result into the RFC's JSON. An OAuth access token is an ordinary
 * mcp_access_tokens row, so mcp.php serves it with no change of its own.
 *
 * Fail closed: until migrate_oauth.php has run, oauthAvailable() is false and
 * the endpoint answers 503 rather than half-working. No authorization code can
 * exist yet either — the authorization endpoint is step 3/6 — so after the
 * migration every well-formed request answers invalid_grant, which is what
 * makes this step inert once deployed.
 *
 * Abuse limits go through abuse_guard.php, fail-open: per-IP exchanges per hour
 * and per day, over either being 429 with Retry-After. The subject is
 * oauthIpHash()'s keyed hash of the visitor's IP — the raw address is never
 * stored or logged.
 *
 * Logging: one INFO line per exchange with the account id, the token id, the
 * client id, the grant type and the outcome, plus a WARNING with the visitor's
 * IP when a code or a refresh token was presented twice. The token, the code,
 * the verifier and the refresh token are never logged — not even a prefix of
 * one.
 */

define('TEMPMAIL_APP', true);

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "oauth_token.php is an HTTP endpoint; it is not run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth_server.php';
require_once __DIR__ . '/abuse_guard.php';

/** Largest token request accepted, in bytes. A form body is small. */
const OAUTH_TOKEN_MAX_BODY_BYTES = 8192;

/** The two grants this endpoint serves. */
const OAUTH_TOKEN_GRANTS = ['authorization_code', 'refresh_token'];

/** Send one answer and stop. */
function oauthTokenRespond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** An RFC 6749 §5.2 error body. */
function oauthTokenError(string $code, string $description): array
{
    return ['error' => $code, 'error_description' => $description];
}

/**
 * The HTTP status the RFC asks for: 401 for invalid_client (there is no
 * WWW-Authenticate challenge to send — the client is public and presents no
 * credentials), 500 for our own failure, 400 for everything else.
 */
function oauthTokenStatusFor(string $error): int
{
    if ($error === 'invalid_client') {
        return 401;
    }
    if ($error === 'server_error') {
        return 500;
    }
    if ($error === 'temporarily_unavailable') {
        return 503;
    }
    return 400;
}

header('Cache-Control: no-store');
header('Pragma: no-cache');

// ---------------------------------------------------------------------
// 1. The migration has to have run.
// ---------------------------------------------------------------------

if (!oauthAvailable($pdo)) {
    oauthTokenRespond(503, oauthTokenError('temporarily_unavailable', 'The OAuth token endpoint is not available yet.'));
}

// ---------------------------------------------------------------------
// 2. Method and Content-Type
// ---------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    oauthTokenRespond(405, oauthTokenError('invalid_request', 'This endpoint accepts POST only.'));
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/x-www-form-urlencoded') {
    oauthTokenRespond(400, oauthTokenError('invalid_request', 'Content-Type must be application/x-www-form-urlencoded.'));
}

// ---------------------------------------------------------------------
// 3. The body
// ---------------------------------------------------------------------

$rawBody = (string) file_get_contents('php://input');
if (strlen($rawBody) > OAUTH_TOKEN_MAX_BODY_BYTES) {
    oauthTokenRespond(400, oauthTokenError('invalid_request', 'Request body too large.'));
}

$params = [];
parse_str($rawBody, $params);

// ---------------------------------------------------------------------
// 4. Abuse limits, fail-open
// ---------------------------------------------------------------------

$ipHash = oauthIpHash(getVisitorIp());
try {
    if ($ipHash !== null && abuseGuardAvailable()) {
        $verdict = abuseOauthTokenRateLimit($pdo, $ipHash, time(), abuseGuardSettings());
        if ($verdict['limited']) {
            logMessage('WARNING', 'OAuth token request rejected: rate limit', [
                'ip' => getVisitorIp(),
                'rule' => $verdict['rule'],
            ]);
            header('Retry-After: ' . (int) $verdict['retry_after']);
            oauthTokenRespond(429, oauthTokenError('temporarily_unavailable', 'Too many token requests; retry later.'));
        }
    }
} catch (Throwable $e) {
    logMessage('WARNING', 'OAuth token rate limit unavailable', ['error' => $e->getMessage()]);
}

// ---------------------------------------------------------------------
// 5. Dispatch on the grant type
// ---------------------------------------------------------------------

$grantType = $params['grant_type'] ?? null;
if (!is_string($grantType) || $grantType === '') {
    oauthTokenRespond(400, oauthTokenError('invalid_request', 'The grant_type parameter is required.'));
}
if (!in_array($grantType, OAUTH_TOKEN_GRANTS, true)) {
    oauthTokenRespond(400, oauthTokenError('unsupported_grant_type', 'This endpoint supports authorization_code and refresh_token.'));
}

try {
    $result = $grantType === 'authorization_code'
        ? oauthAuthorizationCodeExchange($pdo, $params)
        : oauthRefreshTokenExchange($pdo, $params);
} catch (Throwable $e) {
    logMessage('ERROR', 'OAuth token exchange failed', ['grant_type' => $grantType, 'error' => $e->getMessage()]);
    oauthTokenRespond(500, oauthTokenError('server_error', 'The token could not be issued.'));
}

// Everything this endpoint logs is one of these four values. The access token,
// the refresh token, the code and the verifier are never among them — the
// outcome and the ids are enough to follow an exchange.
$outcome = (string) ($result['reason'] ?? ($result['ok'] ? 'ok' : (string) ($result['error'] ?? 'error')));
$logUserId = (int) ($result['user_id'] ?? 0);
$logTokenId = (int) ($result['token_id'] ?? 0);
$logClientId = (string) ($result['client_id'] ?? '');

// A credential that should have been single-use was presented twice: the
// grant is already revoked by the library, and this is the line an operator
// needs to see. The IP is logged; the credential is not.
if ($outcome === 'code_reuse' || $outcome === 'refresh_reuse') {
    logMessage('WARNING', 'OAuth credential presented twice; the grant was revoked', [
        'grant_type' => $grantType,
        'outcome' => $outcome,
        'client_id' => $logClientId,
        'ip' => getVisitorIp(),
    ]);
}

logMessage('INFO', 'OAuth token exchange', [
    'grant_type' => $grantType,
    'outcome' => $outcome,
    'user_id' => $logUserId,
    'token_id' => $logTokenId,
    'client_id' => $logClientId,
]);

if (!$result['ok']) {
    oauthTokenRespond(
        oauthTokenStatusFor((string) $result['error']),
        oauthTokenError((string) $result['error'], (string) $result['error_description'])
    );
}

oauthClientTouch($pdo, (string) $result['client_id']);

oauthTokenRespond(200, [
    'access_token' => (string) $result['access_token'],
    'token_type' => (string) $result['token_type'],
    'expires_in' => (int) $result['expires_in'],
    'refresh_token' => (string) $result['refresh_token'],
    'scope' => (string) $result['scope'],
]);
