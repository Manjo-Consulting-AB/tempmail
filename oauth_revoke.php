<?php

declare(strict_types=1);

/**
 * OAuth 2.1 token revocation (epic #331 step 2/6, RFC 7009) — where a client
 * tells the server that a credential it holds is finished with. Users' clients
 * are given `<base_url>/oauth/revoke`, which .htaccess rewrites here.
 *
 * Request: POST only, `application/x-www-form-urlencoded`, a `token` and the
 * `client_id` that owns it (`token_type_hint` is accepted and ignored, as the
 * RFC allows). The grant is revoked when the token is that client's access or
 * refresh token; either one revokes the whole grant, because one row of
 * mcp_access_tokens holds both. A manual token — every token the profile's
 * "Connected apps" card created, all with oauth_client_id NULL — is
 * unreachable from here, so this endpoint can only ever revoke a grant that an
 * OAuth client created for itself.
 *
 * The answer is always 200 with an empty body, whatever happened: RFC 7009
 * §2.2 requires it, and it is what keeps the endpoint from becoming an oracle
 * for which tokens exist. A malformed request (another method, another
 * Content-Type) is still told apart, because that says nothing about a token.
 *
 * Nothing about the token is logged — not the value, not a prefix of it.
 */

define('TEMPMAIL_APP', true);

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "oauth_revoke.php is an HTTP endpoint; it is not run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth_server.php';

/** Largest revocation request accepted, in bytes. */
const OAUTH_REVOKE_MAX_BODY_BYTES = 4096;

/** Send the one answer this endpoint ever sends, and stop. */
function oauthRevokeRespond(int $status, ?array $payload = null): void
{
    http_response_code($status);
    if ($payload !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

header('Cache-Control: no-store');
header('Pragma: no-cache');

// A malformed request is the one thing told apart from the rest; it says
// nothing about any token, so it is not an oracle.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    oauthRevokeRespond(405, ['error' => 'invalid_request', 'error_description' => 'This endpoint accepts POST only.']);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/x-www-form-urlencoded') {
    oauthRevokeRespond(400, ['error' => 'invalid_request', 'error_description' => 'Content-Type must be application/x-www-form-urlencoded.']);
}

$rawBody = (string) file_get_contents('php://input');
if (strlen($rawBody) > OAUTH_REVOKE_MAX_BODY_BYTES) {
    oauthRevokeRespond(400, ['error' => 'invalid_request', 'error_description' => 'Request body too large.']);
}

$params = [];
parse_str($rawBody, $params);

// The client id is the only thing logged, and only when a grant was really
// revoked. The presented credential is never logged, in any form.
$revokeClientId = isset($params['client_id']) && is_string($params['client_id']) ? $params['client_id'] : null;

try {
    $revoked = oauthTokenRevoke(
        $pdo,
        isset($params['token']) && is_string($params['token']) ? $params['token'] : null,
        $revokeClientId
    );
    if ($revoked) {
        logMessage('INFO', 'OAuth grant revoked', [
            'client_id' => (string) $revokeClientId,
        ]);
    }
} catch (Throwable $e) {
    logMessage('WARNING', 'OAuth revocation failed', ['error' => $e->getMessage()]);
}

oauthRevokeRespond(200);
