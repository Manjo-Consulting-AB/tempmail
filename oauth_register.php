<?php

declare(strict_types=1);

/**
 * OAuth 2.1 dynamic client registration (epic #331 step 1/6) — the endpoint
 * an OAuth client (Claude.ai, Claude Code) calls before it can send the user
 * to a consent page. Users are given `<base_url>/oauth/register`, which
 * .htaccess rewrites here; the route follows RFC 7591.
 *
 * Request: POST only, `application/json`, at most 8 KB. The body is one
 * client-metadata document. Unknown fields are ignored, never stored, and no
 * client secret is ever issued — this server registers public PKCE clients
 * only (token_endpoint_auth_method is fixed at "none").
 *
 * The accepted fields are client_name, redirect_uris (1–5, the exact strings a
 * later step matches a requested redirect against), grant_types (a subset of
 * authorization_code and refresh_token), response_types (only "code"),
 * token_endpoint_auth_method ("none" only) and scope (accepted, informational,
 * not stored). Every failure is an RFC 6749 §5.2 JSON error; the two
 * registration codes RFC 7591 defines are invalid_redirect_uri and
 * invalid_client_metadata.
 *
 * Fail closed: until migrate_oauth.php has run, oauthAvailable() is false and
 * the endpoint answers 503 rather than half-working. Nothing advertises this
 * route yet — no metadata document links to it and robots.txt disallows it.
 *
 * Abuse limits go through abuse_guard.php, fail-open: per-IP registrations per
 * hour and per day, and a global ceiling on stored clients. Over either is 429
 * with Retry-After. The per-IP subject is oauthIpHash(), a keyed hash of the
 * visitor's IP — the raw address is never stored or logged.
 *
 * Logging: an INFO line with the new client_id and client_name, and a WARNING
 * with the visitor IP when a limit is hit. A redirect URI — which may carry a
 * query string — is never logged.
 */

define('TEMPMAIL_APP', true);

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "oauth_register.php is an HTTP endpoint; it is not run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth_server.php';
require_once __DIR__ . '/abuse_guard.php';

/** Largest registration body accepted, in bytes. A metadata document is small. */
const OAUTH_REGISTER_MAX_BODY_BYTES = 8192;

/** Send one answer and stop. */
function oauthRegisterRespond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** An RFC 6749 §5.2 error body. */
function oauthRegisterError(string $code, string $description): array
{
    return ['error' => $code, 'error_description' => $description];
}

header('Cache-Control: no-store');

// ---------------------------------------------------------------------
// 1. The migration has to have run.
// ---------------------------------------------------------------------

if (!oauthAvailable($pdo)) {
    oauthRegisterRespond(503, oauthRegisterError('temporarily_unavailable', 'The OAuth client registration endpoint is not available yet.'));
}

// ---------------------------------------------------------------------
// 2. Method and Content-Type
// ---------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    oauthRegisterRespond(405, oauthRegisterError('invalid_request', 'This endpoint accepts POST only.'));
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    oauthRegisterRespond(415, oauthRegisterError('invalid_client_metadata', 'Content-Type must be application/json.'));
}

// ---------------------------------------------------------------------
// 3. The body: one JSON object, size-capped
// ---------------------------------------------------------------------

$rawBody = (string) file_get_contents('php://input');
if (strlen($rawBody) > OAUTH_REGISTER_MAX_BODY_BYTES) {
    oauthRegisterRespond(413, oauthRegisterError('invalid_client_metadata', 'Request body too large.'));
}

// Decoded once without associative arrays so a JSON object (one metadata
// document) can be told from a JSON array or scalar, which RFC 7591 does not
// define and this endpoint does not accept.
$shape = json_decode($rawBody);
if (json_last_error() !== JSON_ERROR_NONE || !is_object($shape)) {
    oauthRegisterRespond(400, oauthRegisterError('invalid_client_metadata', 'The body must be a JSON object.'));
}

$metadata = json_decode($rawBody, true);
if (!is_array($metadata)) {
    oauthRegisterRespond(400, oauthRegisterError('invalid_client_metadata', 'The body must be a JSON object.'));
}

// ---------------------------------------------------------------------
// 4. Abuse limits, fail-open
// ---------------------------------------------------------------------

$ipHash = oauthIpHash(getVisitorIp());
try {
    if (abuseGuardAvailable()) {
        $settings = abuseGuardSettings();

        if ($ipHash !== null) {
            $verdict = abuseOauthRegisterRateLimit($pdo, $ipHash, time(), $settings);
            if ($verdict['limited']) {
                logMessage('WARNING', 'OAuth client registration rejected: rate limit', [
                    'ip' => getVisitorIp(),
                    'rule' => $verdict['rule'],
                ]);
                header('Retry-After: ' . (int) $verdict['retry_after']);
                oauthRegisterRespond(429, oauthRegisterError('temporarily_unavailable', 'Too many client registrations; retry later.'));
            }
        }

        if (oauthClientCount($pdo) >= $settings['oauth_max_clients']) {
            logMessage('WARNING', 'OAuth client registration rejected: client cap reached', ['ip' => getVisitorIp()]);
            header('Retry-After: 3600');
            oauthRegisterRespond(429, oauthRegisterError('temporarily_unavailable', 'This server is not accepting new clients right now.'));
        }
    }
} catch (Throwable $e) {
    logMessage('WARNING', 'OAuth registration rate limit unavailable', ['error' => $e->getMessage()]);
}

// ---------------------------------------------------------------------
// 5. Validate and store
// ---------------------------------------------------------------------

try {
    $result = oauthClientRegister($pdo, $metadata, $ipHash);
} catch (Throwable $e) {
    logMessage('ERROR', 'OAuth client registration failed', ['error' => $e->getMessage()]);
    oauthRegisterRespond(500, oauthRegisterError('server_error', 'Could not register the client.'));
}

if (!$result['ok']) {
    oauthRegisterRespond(400, oauthRegisterError(
        (string) $result['error'],
        (string) $result['error_description']
    ));
}

$client = $result['client'];
logMessage('INFO', 'OAuth client registered', [
    'client_id' => (string) $client['client_id'],
    'client_name' => (string) $client['client_name'],
]);

oauthRegisterRespond(201, $client);
