<?php

declare(strict_types=1);

/**
 * OAuth discovery documents (epic #331 step 5/6) — the metadata a client reads
 * before it can talk to /mcp: the protected-resource metadata (RFC 9728) and
 * the authorization-server metadata (RFC 8414). .htaccess rewrites
 * `/.well-known/oauth-protected-resource`, its `/mcp` form,
 * `/.well-known/oauth-authorization-server` and the OpenID-Connect alias
 * `/.well-known/openid-configuration` here; this one file answers all of them,
 * choosing the document from the request path.
 *
 * Every URL in both documents is built from $config['email']['base_url'] (see
 * oauthIssuer() / oauthCanonicalResource() in oauth_server.php) — never a
 * literal — so they follow BASE_URL like everything else the site emits. The
 * `resource` value is the same canonical identifier the authorization endpoint
 * validates a request's `resource` against, so the two can never disagree.
 *
 * Gating: the documents answer only when OAuth is switched on (env
 * OAUTH_ENABLED) *and* migrate_oauth.php has run (oauthDiscoveryEnabled()).
 * Otherwise every path answers 404, so a half-migrated deploy — or an operator
 * who has not turned the feature on yet — never advertises a flow that cannot
 * complete.
 *
 * These are public documents a browser-based MCP client reads cross-origin,
 * so they carry `Access-Control-Allow-Origin: *` and a one-hour cache. Nothing
 * here is user-specific, so there is nothing to leak.
 */

define('TEMPMAIL_APP', true);

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "oauth_metadata.php is an HTTP endpoint; it is not run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth_server.php';

/** Send one answer and stop. */
function oauthMetadataRespond(int $status, ?string $body, array $headers = []): void
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($body !== null) {
        echo $body;
    }
    exit;
}

/** The document this path names, or a 404. */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

if (!oauthDiscoveryEnabled($pdo)) {
    oauthMetadataRespond(404, "Not found\n", ['Content-Type' => 'text/plain; charset=utf-8']);
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    oauthMetadataRespond(405, "Method not allowed\n", [
        'Allow' => 'GET, HEAD',
        'Content-Type' => 'text/plain; charset=utf-8',
    ]);
}

$baseUrl = (string) ($config['email']['base_url'] ?? '');

$resourcePattern = '#^' . preg_quote(OAUTH_PROTECTED_RESOURCE_PATH, '#') . '(/mcp)?/?$#';
$serverPattern = '#^(' . preg_quote(OAUTH_AUTHZ_SERVER_PATH, '#') . '|' . preg_quote(OAUTH_OPENID_CONFIG_PATH, '#') . ')/?$#';

if (preg_match($resourcePattern, $path) === 1) {
    $document = oauthProtectedResourceMetadata($baseUrl);
} elseif (preg_match($serverPattern, $path) === 1) {
    $document = oauthAuthorizationServerMetadata($baseUrl);
} else {
    oauthMetadataRespond(404, "Not found\n", ['Content-Type' => 'text/plain; charset=utf-8']);
}

oauthMetadataRespond(
    200,
    (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'public, max-age=' . OAUTH_METADATA_MAX_AGE,
        'Access-Control-Allow-Origin' => '*',
    ]
);
