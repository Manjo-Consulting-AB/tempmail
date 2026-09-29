<?php

declare(strict_types=1);

/**
 * OAuth 2.1 for the MCP endpoint — the shared library (epic #331, steps 1/6
 * and 2/6; tables created by migrate_oauth.php).
 *
 * Shape: like mailbox_service.php and mcp_tokens.php, every function takes an
 * explicit PDO, never reads $_SESSION and never echoes. A failure is a return
 * value, not an exception the caller has to remember to catch. The functions
 * compute times in PHP rather than with NOW(), so the regression suites run
 * them on SQLite.
 *
 * What lives here (step 1/6):
 *  - oauthAvailable(): is the OAuth schema in place? Everything fails closed
 *    ("not available") until the migration has run.
 *  - oauthClientRegister() / oauthClientFind() / oauthClientCount(): dynamic
 *    client registration (RFC 7591) and lookup.
 *  - redirect-URI validation: the exact-match allow-list a later step checks a
 *    client's requested redirect_uri against.
 *
 * What lives here (step 3/6, the authorization endpoint and consent page in
 * oauth_authorize.php):
 *  - oauthCanonicalResource(): the one resource identifier (RFC 8707) this
 *    server accepts, built from base_url and never from a literal.
 *  - oauthAuthorizeRequestValidate(): the authorization request, checked in
 *    the order RFC 6749 §4.1.2.1 requires — and, crucially, the two failures
 *    that must never redirect (an unknown client, a redirect_uri that is not
 *    an exact registered match) are told apart from the ones that must.
 *  - oauthAuthorizationCodeCreate(): mints the single-use code, stored only as
 *    its sha256, exactly as the token endpoint already expects to find it.
 *
 * What lives here (step 2/6, the token endpoint in oauth_token.php and the
 * revocation endpoint in oauth_revoke.php):
 *  - oauthCodeGenerate() / oauthRefreshTokenGenerate() and their validators:
 *    the two credential formats, validated before any query like every other
 *    credential in this codebase.
 *  - oauthCodeChallengeMatches(): the PKCE S256 check (RFC 7636).
 *  - the scope helpers: this library speaks the wire form (`mcp:read`,
 *    `mcp:write`, space-separated) and mcp_tokens.php speaks the stored form
 *    (`read`, `read,write`); oauthScopeSetFromExternal() and friends are the
 *    only translation, so the two can never drift apart.
 *  - oauthAuthorizationCodeExchange() and oauthRefreshTokenExchange(): the two
 *    grants, returning an RFC 6749 §5.2 error or the token response fields.
 *  - oauthTokenRevoke(): RFC 7009 revocation, by access or refresh token.
 *  - oauthClientTouch(): oauth_clients.last_used_at, at most once an hour.
 *
 * What lives here (step 5/6, the discovery documents in oauth_metadata.php and
 * the 401 challenge in mcp.php):
 *  - oauthEnabled() / oauthDiscoveryEnabled(): the env kill switch and the
 *    schema check together decide whether anything is advertised at all.
 *  - oauthIssuer() / oauthResourceMetadataUrl(): the origin and the
 *    metadata URL, both built from base_url.
 *  - oauthProtectedResourceMetadata() / oauthAuthorizationServerMetadata():
 *    the two documents themselves.
 *  - oauthWwwAuthenticate(): the challenge a 401 carries, unchanged from
 *    before this step while discovery is off.
 *
 * An OAuth access token is an ordinary mcp_access_tokens row — minted with
 * mcpTokenGenerate() and stored with mcpTokenHash(), never a fork of that
 * code — so mcpTokenResolve() decides on it with no change at all. The OAuth
 * columns on that row (oauth_client_id, refresh_token_hash, refresh_expires_at,
 * grant_created_at, rotated_refresh_hash, rotated_at) are what make the row a
 * grant as well as a token. A manual token leaves them NULL and is never
 * touched here: every statement that revokes by token also requires
 * oauth_client_id to match the caller's client, so this endpoint cannot reach a
 * token the profile created.
 *
 * No secret is ever issued: token_endpoint_auth_method is "none" only, the
 * shape a public PKCE client (Claude.ai, Claude Code) uses.
 *
 * Nothing here reads a client secret, a code, a verifier or a token into a
 * log, and nothing here logs at all: the callers log client_id, user_id,
 * token_id and the outcome. The registering IP is never stored bare —
 * oauthIpHash() is the keyed sha256 the schema keeps.
 */

require_once __DIR__ . '/mcp_tokens.php';

if (!defined('OAUTH_CLIENT_ID_BYTES')) {
    // client_id = 32 hex characters from this many random bytes.
    define('OAUTH_CLIENT_ID_BYTES', 16);
    // The PKCE challenge is an unpadded base64url sha256: always 43 characters.
    define('OAUTH_CODE_CHALLENGE_LENGTH', 43);
    // Longest accepted state parameter, matching what a redirect can carry.
    define('OAUTH_STATE_MAX_LENGTH', 512);
    // A client may register between 1 and this many redirect URIs.
    define('OAUTH_REDIRECT_URI_MAX_COUNT', 5);
    // Longest accepted redirect URI, matching the column's width.
    define('OAUTH_REDIRECT_URI_MAX_LENGTH', 512);
    // client_name is trimmed and cut to this many characters.
    define('OAUTH_CLIENT_NAME_MAX_LENGTH', 64);
    // Shown when no usable client_name was sent.
    define('OAUTH_CLIENT_NAME_DEFAULT', 'Unnamed app');
    // Authorization-code lifetime, in seconds.
    define('OAUTH_CODE_TTL_SECONDS', 60);
    // Access-token lifetime, in seconds — the expires_in the token endpoint
    // reports. Refresh is what keeps a client connected past it.
    define('OAUTH_ACCESS_TOKEN_TTL_SECONDS', 3600);
    // Refresh-token lifetime, in seconds, sliding from every rotation.
    define('OAUTH_REFRESH_TOKEN_TTL_SECONDS', 90 * 86400);
    // The refresh token's own prefix, so a leak is recognisable in a log.
    define('OAUTH_REFRESH_PREFIX', 'msr_');
    // Live grants per account. The 11th authorisation is refused at consent
    // time (step 3/6) and again here; manual tokens have their own cap.
    define('OAUTH_GRANT_MAX_PER_USER', 10);
    // oauth_clients.last_used_at is written at most this often, in seconds.
    define('OAUTH_CLIENT_TOUCH_SECONDS', 3600);
    // A spent or expired authorization code is swept once it is this old.
    define('OAUTH_CODE_SWEEP_SECONDS', 86400);
    // A client with no token row and no activity this long is swept.
    define('OAUTH_CLIENT_IDLE_SECONDS', 30 * 86400);
    // Scope names on the wire, and the two internal scopes they map to.
    define('OAUTH_SCOPE_READ', 'mcp:read');
    define('OAUTH_SCOPE_WRITE', 'mcp:write');
}

if (!function_exists('oauthAvailable')) {
    /**
     * Is the OAuth schema there? Cached per PDO for the life of the process.
     * False without oauth_clients: the endpoint then answers "not available"
     * rather than half-working.
     */
    function oauthAvailable(PDO $pdo): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $cache)) {
            try {
                $pdo->query('SELECT id FROM oauth_clients WHERE 1 = 0');
                $cache[$key] = true;
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

// ---------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------

if (!function_exists('oauthHash')) {
    /** sha256 hex of a credential kept at rest (a code, a refresh token). */
    function oauthHash(string $value): string
    {
        return hash('sha256', $value);
    }
}

if (!function_exists('oauthRandomHex')) {
    /** $bytes random bytes as lowercase hex — the form every credential here uses. */
    function oauthRandomHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }
}

if (!function_exists('oauthCanonicalResource')) {
    /**
     * The one resource identifier (RFC 8707) this server serves: the MCP
     * endpoint, built from $config['email']['base_url'] and never from a
     * literal, so it follows BASE_URL like every other URL the site emits. A
     * request that names a different resource is refused rather than quietly
     * ignored — an access token is only good for the thing it was asked for.
     */
    function oauthCanonicalResource(?string $baseUrl = null): string
    {
        if ($baseUrl === null) {
            $config = $GLOBALS['config'] ?? null;
            $baseUrl = is_array($config) ? (string) ($config['email']['base_url'] ?? '') : '';
        }
        return rtrim($baseUrl, '/') . '/mcp';
    }
}

// ---------------------------------------------------------------------
// Discovery (step 5/6): the metadata documents and the 401 challenge
// ---------------------------------------------------------------------

if (!defined('OAUTH_PROTECTED_RESOURCE_PATH')) {
    // Where a client is told to look for the resource's metadata (RFC 9728),
    // and the path-specific form for the /mcp resource.
    define('OAUTH_PROTECTED_RESOURCE_PATH', '/.well-known/oauth-protected-resource');
    // The authorization server's own document (RFC 8414), plus the
    // OpenID-Connect-style alias some clients probe first.
    define('OAUTH_AUTHZ_SERVER_PATH', '/.well-known/oauth-authorization-server');
    define('OAUTH_OPENID_CONFIG_PATH', '/.well-known/openid-configuration');
    // How long a client may cache the public documents, in seconds.
    define('OAUTH_METADATA_MAX_AGE', 3600);
}

if (!function_exists('oauthEnabled')) {
    /**
     * The operator's kill switch, $config['oauth']['enabled'] (env
     * OAUTH_ENABLED), off by default. It gates discovery only — the metadata
     * documents and the 401 challenge — never the endpoints themselves, which
     * stay gated on oauthAvailable() exactly as the earlier steps left them.
     */
    function oauthEnabled(?array $config = null): bool
    {
        if ($config === null) {
            $config = $GLOBALS['config'] ?? [];
        }
        return is_array($config)
            && filter_var($config['oauth']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists('oauthIssuer')) {
    /**
     * The issuer / authorization-server identifier: the *origin* of
     * $config['email']['base_url'], with any path dropped. RFC 8414 wants an
     * origin and RFC 9728 an authorization-server identifier, and every
     * endpoint below is built from this, so it follows BASE_URL and is never a
     * literal.
     */
    function oauthIssuer(?string $baseUrl = null): string
    {
        if ($baseUrl === null) {
            $config = $GLOBALS['config'] ?? null;
            $baseUrl = is_array($config) ? (string) ($config['email']['base_url'] ?? '') : '';
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            // Not a parseable absolute URL; strip the trailing slash and trust
            // the operator rather than emit an empty issuer.
            return rtrim($baseUrl, '/');
        }
        $issuer = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $issuer .= ':' . (int) $parts['port'];
        }
        return $issuer;
    }
}

if (!function_exists('oauthResourceMetadataUrl')) {
    /** The absolute URL of the protected-resource metadata document. */
    function oauthResourceMetadataUrl(?string $baseUrl = null): string
    {
        return oauthIssuer($baseUrl) . OAUTH_PROTECTED_RESOURCE_PATH;
    }
}

if (!function_exists('oauthDiscoveryEnabled')) {
    /**
     * Should OAuth be advertised? Both halves must hold: the operator turned
     * OAUTH_ENABLED on, and migrate_oauth.php has run. Without a PDO the
     * schema cannot be checked, so the answer is no — fail closed, so a
     * half-migrated deploy never points a client at a dead end.
     */
    function oauthDiscoveryEnabled(?PDO $pdo = null): bool
    {
        if (!oauthEnabled() || !$pdo instanceof PDO) {
            return false;
        }
        return oauthAvailable($pdo);
    }
}

if (!function_exists('oauthWwwAuthenticate')) {
    /**
     * The WWW-Authenticate challenge a 401 carries. With discovery on it
     * points the client at the resource metadata (RFC 9728 §5.1); otherwise it
     * is the bare challenge mcp.php has always sent, byte for byte, so an
     * existing token user sees no change.
     */
    function oauthWwwAuthenticate(?PDO $pdo = null): string
    {
        $challenge = 'Bearer realm="Mail Shield"';
        if (oauthDiscoveryEnabled($pdo)) {
            $challenge .= ', resource_metadata="' . oauthResourceMetadataUrl() . '"';
        }
        return $challenge;
    }
}

if (!function_exists('oauthProtectedResourceMetadata')) {
    /**
     * The RFC 9728 protected-resource metadata document for the MCP endpoint.
     * `resource` is the canonical identifier the authorization endpoint
     * validates a request's `resource` against, so the two can never disagree.
     *
     * @return array<string,mixed>
     */
    function oauthProtectedResourceMetadata(?string $baseUrl = null): array
    {
        return [
            'resource' => oauthCanonicalResource($baseUrl),
            'authorization_servers' => [oauthIssuer($baseUrl)],
            'scopes_supported' => [OAUTH_SCOPE_READ, OAUTH_SCOPE_WRITE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Mail Shield',
        ];
    }
}

if (!function_exists('oauthAuthorizationServerMetadata')) {
    /**
     * The RFC 8414 authorization-server metadata document. Every endpoint is
     * built from the issuer, so nothing here is a literal and everything
     * follows BASE_URL.
     *
     * @return array<string,mixed>
     */
    function oauthAuthorizationServerMetadata(?string $baseUrl = null): array
    {
        $issuer = oauthIssuer($baseUrl);
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [OAUTH_SCOPE_READ, OAUTH_SCOPE_WRITE],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }
}

if (!function_exists('oauthClientIdGenerate')) {
    /** A fresh public client id: 32 lowercase hex characters. */
    function oauthClientIdGenerate(): string
    {
        return oauthRandomHex(OAUTH_CLIENT_ID_BYTES);
    }
}

if (!function_exists('oauthClientIdValid')) {
    /**
     * $clientId if it is a well-formed client id, else null. Validate-then-
     * look-up: a malformed id is rejected before any query, so it cannot be
     * used to probe the table.
     */
    function oauthClientIdValid(?string $clientId): ?string
    {
        if (!is_string($clientId)) {
            return null;
        }
        return preg_match('/^[a-f0-9]{32}$/', $clientId) === 1 ? $clientId : null;
    }
}

if (!function_exists('oauthIpHash')) {
    /**
     * The keyed sha256 of an IP that oauth_clients.registered_ip_hash holds
     * and the per-IP rate limits count under — never the raw IP. The sub-key
     * is derived from PRO_TRIAL_HASH_KEY exactly as email_log_ref.php derives
     * its reference key, so there is no new required env var and this can
     * never be compared against a trial-claim hash. Returns null, meaning
     * "not storable and not countable", when no key of at least 32 characters
     * is configured: the caller then leaves the column NULL and skips the
     * per-IP limit (fail-open, like every guard path).
     */
    function oauthIpHash(string $ip, ?string $baseKey = null): ?string
    {
        if ($baseKey === null) {
            $config = $GLOBALS['config'] ?? null;
            $baseKey = is_array($config) ? (string) ($config['trial']['hash_key'] ?? '') : '';
        }
        if (strlen($baseKey) < 32 || $ip === '') {
            return null;
        }
        $subKey = hash_hmac('sha256', 'oauth-register-ip', $baseKey);
        return hash_hmac('sha256', $ip, $subKey);
    }
}

// ---------------------------------------------------------------------
// Redirect URIs
// ---------------------------------------------------------------------

if (!function_exists('oauthRedirectUriValid')) {
    /**
     * May a client register this exact redirect URI? The rules are deliberately
     * strict because a later step compares a requested redirect_uri against the
     * registered one by exact string:
     *
     *  - absolute (a scheme), no fragment, no userinfo, 1..512 characters, no
     *    control characters or spaces;
     *  - http(s) needs a host; https is fine for any host, http only for the
     *    loopback hosts 127.0.0.1, [::1] and localhost (any port) — a native
     *    client listening on the machine the user is on;
     *  - any other scheme (cursor://, vscode://, com.example.app:/…) needs a
     *    non-empty host or path, and javascript:, data:, file: and http(s)
     *    look-alikes are refused outright.
     */
    function oauthRedirectUriValid(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > OAUTH_REDIRECT_URI_MAX_LENGTH) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme']) || $parts['scheme'] === '') {
            return false;
        }
        if (array_key_exists('fragment', $parts)) {
            return false;
        }
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : null;
        $path = isset($parts['path']) ? (string) $parts['path'] : null;

        if ($scheme === 'http' || $scheme === 'https') {
            if ($host === null || $host === '') {
                return false;
            }
            if ($scheme === 'http' && !in_array($host, ['127.0.0.1', '[::1]', '::1', 'localhost'], true)) {
                return false;
            }
            return true;
        }

        if (in_array($scheme, ['javascript', 'data', 'file'], true)) {
            return false;
        }
        // A scheme that merely looks like http/https (httpx://, httpsomething://)
        // is refused rather than treated as a harmless custom scheme.
        if (str_starts_with($scheme, 'http')) {
            return false;
        }

        return ($host !== null && $host !== '') || ($path !== null && $path !== '');
    }
}

// ---------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------

if (!function_exists('oauthClientNameSanitise')) {
    /**
     * A client_name safe to store and display: trimmed, control characters
     * stripped, cut to OAUTH_CLIENT_NAME_MAX_LENGTH characters. Anything that
     * leaves nothing, or a value that is not a string at all, becomes
     * "Unnamed app".
     */
    function oauthClientNameSanitise($value): string
    {
        if (!is_string($value)) {
            return OAUTH_CLIENT_NAME_DEFAULT;
        }
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $name = trim(is_string($name) ? $name : '');
        if ($name === '') {
            return OAUTH_CLIENT_NAME_DEFAULT;
        }
        return mb_substr($name, 0, OAUTH_CLIENT_NAME_MAX_LENGTH);
    }
}

if (!function_exists('oauthError')) {
    /**
     * An RFC 7591 registration failure: the RFC 6749 §5.2 error code and a
     * short, non-sensitive description. invalid_redirect_uri and
     * invalid_client_metadata are the only two codes registration defines.
     */
    function oauthError(string $code, string $description): array
    {
        return ['ok' => false, 'error' => $code, 'error_description' => $description];
    }
}

if (!function_exists('oauthClientRegister')) {
    /**
     * Validate one RFC 7591 client-metadata document and store it.
     *
     * On success returns ['ok' => true, 'client' => [...the response fields...]]:
     * client_id, client_id_issued_at (unix seconds), redirect_uris,
     * client_name, grant_types, response_types and
     * token_endpoint_auth_method ("none"). No secret is issued.
     *
     * On failure returns ['ok' => false, 'error' => 'invalid_redirect_uri' |
     * 'invalid_client_metadata', 'error_description' => ...].
     *
     * Unknown fields are ignored, never stored; only the known columns are
     * written. $ipHash is oauthIpHash()'s value or null; it is never the raw
     * IP. This function does not rate limit — the endpoint calls
     * abuseOauthRegisterRateLimit() before reaching here.
     */
    function oauthClientRegister(PDO $pdo, array $metadata, ?string $ipHash = null, ?int $now = null): array
    {
        $now = $now ?? time();

        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > OAUTH_REDIRECT_URI_MAX_COUNT) {
            return oauthError('invalid_redirect_uri', 'redirect_uris must be an array of 1 to ' . OAUTH_REDIRECT_URI_MAX_COUNT . ' absolute URIs.');
        }
        $cleanUris = [];
        foreach ($uris as $uri) {
            if (!is_string($uri) || !oauthRedirectUriValid($uri)) {
                return oauthError('invalid_redirect_uri', 'One or more redirect_uris are not acceptable.');
            }
            $cleanUris[] = $uri;
        }

        $grantTypes = ['authorization_code'];
        if (array_key_exists('grant_types', $metadata)) {
            $raw = $metadata['grant_types'];
            if (!is_array($raw) || $raw === []) {
                return oauthError('invalid_client_metadata', 'grant_types must be a non-empty array.');
            }
            $grantTypes = [];
            foreach ($raw as $grant) {
                if (!is_string($grant) || !in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                    return oauthError('invalid_client_metadata', 'Unsupported grant type.');
                }
                if (!in_array($grant, $grantTypes, true)) {
                    $grantTypes[] = $grant;
                }
            }
        }

        $responseTypes = ['code'];
        if (array_key_exists('response_types', $metadata)) {
            $raw = $metadata['response_types'];
            if (!is_array($raw) || $raw === [] || array_diff($raw, ['code']) !== []) {
                return oauthError('invalid_client_metadata', 'Only the "code" response type is supported.');
            }
        }

        if (array_key_exists('token_endpoint_auth_method', $metadata)) {
            $method = $metadata['token_endpoint_auth_method'];
            if (!is_string($method) || $method !== 'none') {
                return oauthError('invalid_client_metadata', 'Only token_endpoint_auth_method "none" is supported.');
            }
        }

        $clientId = oauthClientIdGenerate();
        $name = oauthClientNameSanitise($metadata['client_name'] ?? null);
        $createdAt = date('Y-m-d H:i:s', $now);

        $pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at, last_used_at, registered_ip_hash) VALUES (?, ?, ?, ?, NULL, ?)')
            ->execute([$clientId, $name, (string) json_encode($cleanUris, JSON_UNESCAPED_SLASHES), $createdAt, $ipHash]);

        return [
            'ok' => true,
            'client' => [
                'client_id' => $clientId,
                'client_id_issued_at' => $now,
                'redirect_uris' => $cleanUris,
                'client_name' => $name,
                'grant_types' => $grantTypes,
                'response_types' => $responseTypes,
                'token_endpoint_auth_method' => 'none',
            ],
        ];
    }
}

if (!function_exists('oauthClientFind')) {
    /**
     * The registered client $clientId names, or null. Null covers an unknown
     * client and a malformed id alike, and the id is validated before any
     * query. redirect_uris comes back decoded; no secret is returned because
     * none is ever stored.
     */
    function oauthClientFind(PDO $pdo, string $clientId): ?array
    {
        $valid = oauthClientIdValid($clientId);
        if ($valid === null) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT client_id, client_name, redirect_uris, created_at, last_used_at FROM oauth_clients WHERE client_id = ? LIMIT 1');
            $stmt->execute([$valid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $decoded = json_decode((string) $row['redirect_uris'], true);
            return [
                'client_id' => (string) $row['client_id'],
                'client_name' => (string) $row['client_name'],
                'redirect_uris' => is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('oauthClientCount')) {
    /** How many clients are stored — the global cap in the endpoint counts these. */
    function oauthClientCount(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM oauth_clients')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('oauthClientTouch')) {
    /**
     * Records that the client just completed a token exchange, at most once
     * per OAUTH_CLIENT_TOUCH_SECONDS so a refreshing client does not turn
     * every request into a write. Never throws: the timestamp is bookkeeping.
     */
    function oauthClientTouch(PDO $pdo, string $clientId, ?int $now = null): void
    {
        $valid = oauthClientIdValid($clientId);
        if ($valid === null) {
            return;
        }
        $now = $now ?? time();
        try {
            $pdo->prepare('UPDATE oauth_clients SET last_used_at = ? WHERE client_id = ? AND (last_used_at IS NULL OR last_used_at <= ?)')
                ->execute([date('Y-m-d H:i:s', $now), $valid, date('Y-m-d H:i:s', $now - OAUTH_CLIENT_TOUCH_SECONDS)]);
        } catch (Throwable $e) {
            // Bookkeeping only; a failed touch must not fail the exchange.
        }
    }
}

// ---------------------------------------------------------------------
// Credentials: the authorization code, the refresh token, PKCE
// ---------------------------------------------------------------------

if (!function_exists('oauthCodeGenerate')) {
    /**
     * A fresh authorization code: 64 lowercase hex characters, no prefix. The
     * authorization endpoint (step 3/6) mints these; only oauthHash() of it is
     * ever stored, exactly like an access token.
     */
    function oauthCodeGenerate(): string
    {
        return oauthRandomHex(32);
    }
}

if (!function_exists('oauthCodeValidate')) {
    /**
     * $code if it is a well-formed authorization code, else null. Validate-
     * then-look-up: a malformed code never reaches a query.
     */
    function oauthCodeValidate(?string $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        return preg_match('/^[a-f0-9]{64}$/', $code) === 1 ? $code : null;
    }
}

if (!function_exists('oauthRefreshTokenGenerate')) {
    /** A fresh refresh token: `msr_` + 64 lowercase hex characters. */
    function oauthRefreshTokenGenerate(): string
    {
        return OAUTH_REFRESH_PREFIX . oauthRandomHex(32);
    }
}

if (!function_exists('oauthRefreshTokenValidate')) {
    /** $token if it is a well-formed refresh token, else null. */
    function oauthRefreshTokenValidate(?string $token): ?string
    {
        if (!is_string($token)) {
            return null;
        }
        return preg_match('/^msr_[a-f0-9]{64}$/', $token) === 1 ? $token : null;
    }
}

if (!function_exists('oauthCodeChallengeMatches')) {
    /**
     * Does $verifier hash to $challenge? The only PKCE method this server
     * speaks is S256 (the code_challenge column is 43 characters, the length
     * of an unpadded base64url sha256), so the comparison is
     * base64url(sha256(verifier)) via hash_equals — constant time, and the
     * challenge comes from the database, not from the request.
     *
     * A verifier outside RFC 7636's 43–128 characters of [A-Za-z0-9-._~] is
     * not a verifier at all and simply does not match; the caller answers the
     * same invalid_grant it answers for a wrong one, so the two are not
     * distinguishable.
     */
    function oauthCodeChallengeMatches(string $verifier, string $challenge): bool
    {
        if (strlen($verifier) < 43 || strlen($verifier) > 128) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) !== 1) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }
}

// ---------------------------------------------------------------------
// Scopes: the wire form (mcp:read, mcp:write) and the stored form
// ---------------------------------------------------------------------

if (!function_exists('oauthScopeSetFromExternal')) {
    /**
     * A space-separated wire scope as a list of internal scopes: [] for an
     * empty string (nothing was asked for), otherwise ['read'], ['read',
     * 'write'] or ['write']. Null means a scope this server does not know,
     * which the caller must refuse rather than ignore — silently dropping an
     * unknown scope would grant something other than what was asked for.
     *
     * These are the only two scopes the MCP surface has (mcpToolScopes()), so
     * "write" always implies "read": mcpTokenAllowsScope() is asked per tool,
     * and a token that may call a write tool implicitly sees the read tools it
     * depends on.
     */
    function oauthScopeSetFromExternal(?string $scope): ?array
    {
        if (!is_string($scope)) {
            return [];
        }
        $set = [];
        foreach (preg_split('/\s+/', trim($scope)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if ($part === OAUTH_SCOPE_READ) {
                $set['read'] = true;
            } elseif ($part === OAUTH_SCOPE_WRITE) {
                $set['write'] = true;
            } else {
                return null;
            }
        }
        return array_keys($set);
    }
}

if (!function_exists('oauthScopeInternalFromSet')) {
    /** The mcp_access_tokens.scopes value for a scope set — 'read' or 'read,write'. */
    function oauthScopeInternalFromSet(array $set): string
    {
        return in_array('write', $set, true) ? 'read,write' : 'read';
    }
}

if (!function_exists('oauthScopeExternalFromSet')) {
    /** The wire form of a scope set, as the token response reports it. */
    function oauthScopeExternalFromSet(array $set): string
    {
        return in_array('write', $set, true)
            ? OAUTH_SCOPE_READ . ' ' . OAUTH_SCOPE_WRITE
            : OAUTH_SCOPE_READ;
    }
}

if (!function_exists('oauthScopeSetFromInternal')) {
    /** The scope set behind a stored scopes value, for the refresh narrowing check. */
    function oauthScopeSetFromInternal(string $internal): array
    {
        $set = [];
        foreach (explode(',', strtolower($internal)) as $part) {
            $part = trim($part);
            if ($part === 'read' || $part === 'write') {
                $set[$part] = true;
            }
        }
        return array_keys($set);
    }
}

// ---------------------------------------------------------------------
// The authorization request (step 3/6)
// ---------------------------------------------------------------------

if (!function_exists('oauthAuthorizeRequestValidate')) {
    /**
     * Validate one authorization request (RFC 6749 §4.1.2, OAuth 2.1 §4.1.1)
     * and, on success, hand back exactly what a code needs to be minted from.
     *
     * $params is the parsed query (GET) or form body (POST). The checks run in
     * the order the issue gives, and the *kind* of failure matters as much as
     * the failure itself:
     *
     *   - 'fatal' => true means the request must NOT be redirected. Only two
     *     things are fatal, and both are the cases §4.1.2.1 names: a client_id
     *     that names no registered client (including a malformed one, refused
     *     before any query), and a redirect_uri that is missing or is not an
     *     **exact** string match of one the client registered. Redirecting
     *     either would hand an attacker a redirect gadget; the caller renders
     *     an error on our own origin instead.
     *   - 'fatal' => false means the redirect_uri is proven, so the error goes
     *     back to it as `error=…&state=…&iss=…`, as §4.1.2.1 requires.
     *
     * The redirect_uri is never normalised, decoded or compared case-
     * insensitively: the registered string is what comes back, so a URI that
     * merely looks close to a registered one is a different URI.
     *
     * On success, 'request' carries the validated request — the values a code
     * row is written from, and the values the consent page renders. Nothing in
     * it comes from the caller without having been checked: the scope set is
     * one of the two this server has, the challenge is 43 base64url
     * characters, and the state is opaque and length-capped.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    function oauthAuthorizeRequestValidate(PDO $pdo, array $params, ?string $baseUrl = null): array
    {
        // 1. The client, and its redirect_uri. Both failures are fatal.
        $clientId = oauthClientIdValid(isset($params['client_id']) && is_string($params['client_id']) ? $params['client_id'] : null);
        $client = $clientId !== null ? oauthClientFind($pdo, $clientId) : null;
        if ($client === null) {
            return [
                'ok' => false,
                'fatal' => true,
                'error' => 'invalid_client',
                'error_description' => 'This application is not registered, so it cannot be connected.',
            ];
        }

        $redirectUri = isset($params['redirect_uri']) && is_string($params['redirect_uri']) ? $params['redirect_uri'] : null;
        if ($redirectUri === null || !in_array($redirectUri, $client['redirect_uris'], true)) {
            return [
                'ok' => false,
                'fatal' => true,
                'error' => 'invalid_request',
                'error_description' => 'The requested redirect address does not match the one this application registered.',
            ];
        }

        // 2. Everything below this line answers at the redirect_uri.
        $responseType = isset($params['response_type']) && is_string($params['response_type']) ? $params['response_type'] : null;
        if ($responseType === null || $responseType === '') {
            return oauthAuthorizeError($redirectUri, 'invalid_request', 'The response_type parameter is required.');
        }
        if ($responseType !== 'code') {
            return oauthAuthorizeError($redirectUri, 'unsupported_response_type', 'Only the "code" response type is supported.');
        }

        // PKCE is mandatory, and only S256 is spoken: "plain" is refused
        // outright rather than downgraded, because accepting it would let a
        // code be replayed by anyone who saw the redirect.
        $challenge = isset($params['code_challenge']) && is_string($params['code_challenge']) ? $params['code_challenge'] : null;
        if ($challenge === null || preg_match('/^[A-Za-z0-9_-]{' . OAUTH_CODE_CHALLENGE_LENGTH . '}$/', $challenge) !== 1) {
            return oauthAuthorizeError($redirectUri, 'invalid_request', 'A code_challenge of ' . OAUTH_CODE_CHALLENGE_LENGTH . ' characters is required.');
        }
        $method = isset($params['code_challenge_method']) && is_string($params['code_challenge_method']) ? $params['code_challenge_method'] : null;
        if ($method !== 'S256') {
            return oauthAuthorizeError($redirectUri, 'invalid_request', 'Only the S256 code_challenge_method is supported.');
        }

        // Scope: what was asked for, or read alone when nothing was. An
        // unknown scope is refused rather than ignored — dropping it silently
        // would grant something other than what was asked for.
        $scopeParam = isset($params['scope']) && is_string($params['scope']) ? $params['scope'] : '';
        $scopeSet = oauthScopeSetFromExternal($scopeParam);
        if ($scopeSet === null) {
            return oauthAuthorizeError($redirectUri, 'invalid_scope', 'Unknown scope.');
        }
        if ($scopeSet === []) {
            $scopeSet = ['read'];
        }

        // RFC 8707: a named resource must be the one this server serves.
        $resource = isset($params['resource']) && is_string($params['resource']) ? $params['resource'] : '';
        if ($resource !== '' && !hash_equals(oauthCanonicalResource($baseUrl), $resource)) {
            return oauthAuthorizeError($redirectUri, 'invalid_request', 'The requested resource is not served here.');
        }

        // Opaque and passed through untouched, but not unbounded: it is
        // echoed into a Location header.
        $state = isset($params['state']) && is_string($params['state']) ? $params['state'] : '';
        if (strlen($state) > OAUTH_STATE_MAX_LENGTH) {
            return oauthAuthorizeError($redirectUri, 'invalid_request', 'The state parameter is too long.');
        }

        return [
            'ok' => true,
            'request' => [
                'client_id' => (string) $client['client_id'],
                'client_name' => (string) $client['client_name'],
                'redirect_uri' => $redirectUri,
                'code_challenge' => $challenge,
                'scopes' => $scopeSet,
                'scope' => oauthScopeExternalFromSet($scopeSet),
                'resource' => $resource,
                'state' => $state,
            ],
        ];
    }
}

if (!function_exists('oauthAuthorizeError')) {
    /**
     * A non-fatal authorization error — one the caller sends back to the
     * proven redirect_uri with `error=…&state=…&iss=…`. The URI rides along
     * because by the time one of these is returned it has already been matched
     * exactly against the client's registered list, so the caller does not
     * have to re-read the request to find it.
     */
    function oauthAuthorizeError(string $redirectUri, string $code, string $description): array
    {
        return [
            'ok' => false,
            'fatal' => false,
            'error' => $code,
            'error_description' => $description,
            'redirect_uri' => $redirectUri,
        ];
    }
}

if (!function_exists('oauthAuthorizationCodeCreate')) {
    /**
     * Mint the single-use authorization code a consenting user just approved,
     * and store it — only its sha256, never the code itself.
     *
     * Returns the plaintext code (the one moment it exists outside the
     * redirect) or null when $clientId is malformed. $scopeExternal is the
     * wire form the token endpoint parses back with
     * oauthScopeSetFromExternal(); $resource is '' when the authorization
     * named none, and the token endpoint then ignores the parameter.
     *
     * $now is passed in so the suites can run this on SQLite, where NOW() is
     * not the clock the rest of the row uses.
     */
    function oauthAuthorizationCodeCreate(
        PDO $pdo,
        string $clientId,
        int $userId,
        string $redirectUri,
        string $scopeExternal,
        string $codeChallenge,
        string $resource = '',
        ?int $now = null
    ): ?string {
        $validClient = oauthClientIdValid($clientId);
        if ($validClient === null || $userId <= 0) {
            return null;
        }
        $now = $now ?? time();
        $code = oauthCodeGenerate();
        $pdo->prepare('INSERT INTO oauth_authorization_codes (code_hash, client_id, pro_user_id, redirect_uri, scopes, code_challenge, resource, expires_at, used_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)')
            ->execute([
                oauthHash($code),
                $validClient,
                $userId,
                $redirectUri,
                $scopeExternal,
                $codeChallenge,
                $resource,
                date('Y-m-d H:i:s', $now + OAUTH_CODE_TTL_SECONDS),
            ]);
        return $code;
    }
}

// ---------------------------------------------------------------------
// Grants
// ---------------------------------------------------------------------

if (!function_exists('oauthGrantCount')) {
    /** The account's live OAuth grants — manual tokens have oauth_client_id NULL. */
    function oauthGrantCount(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mcp_access_tokens WHERE pro_user_id = ? AND oauth_client_id IS NOT NULL AND revoked_at IS NULL');
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        return $count;
    }
}

if (!function_exists('oauthGrantExists')) {
    /**
     * Does this account already hold a grant for this client? One grant per
     * (user, client), so this is what tells the consent page's cap check
     * "authorising again would replace this one" from "this would be an
     * eleventh app". Revoked rows do not count: a re-authorisation replaces
     * them (oauthAuthorizationCodeExchange() deletes the pair's rows).
     */
    function oauthGrantExists(PDO $pdo, string $clientId, int $userId): bool
    {
        $validClient = oauthClientIdValid($clientId);
        if ($validClient === null || $userId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM mcp_access_tokens WHERE oauth_client_id = ? AND pro_user_id = ? AND revoked_at IS NULL');
            $stmt->execute([$validClient, $userId]);
            $count = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
            return $count > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('oauthGrantRevoke')) {
    /**
     * Revokes every live grant of ($clientId, $userId) — the whole grant, not
     * just one of its two tokens, because the row holds both. Returns how many
     * rows were revoked. Used for the code-replay and refresh-reuse rules,
     * where a credential that should have been single-use was presented twice.
     */
    function oauthGrantRevoke(PDO $pdo, string $clientId, int $userId, ?int $now = null): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = ? WHERE oauth_client_id = ? AND pro_user_id = ? AND revoked_at IS NULL');
        $stmt->execute([date('Y-m-d H:i:s', $now ?? time()), $clientId, $userId]);
        return $stmt->rowCount();
    }
}

if (!function_exists('oauthAuthorizationCodeExchange')) {
    /**
     * grant_type=authorization_code (RFC 6749 §4.1.3, OAuth 2.1 §4.1.3).
     *
     * $params is the parsed form body. The checks run in the issue's order and
     * everything that could distinguish one bad code from another — unknown,
     * already used, expired, another client's, a different redirect_uri, a
     * wrong verifier, an account that is no longer Pro or is suspended —
     * answers the same invalid_grant, so a client cannot use the answers to
     * probe which codes or clients exist. Only a missing parameter
     * (invalid_request) and an unknown client (invalid_client) are told apart,
     * and neither says anything about a code.
     *
     * The code is consumed by the same atomic UPDATE that claims it, inside
     * the transaction that creates the grant, so two concurrent exchanges
     * cannot both win and a failure never burns the code. A second use of an
     * already-claimed code revokes whatever the code granted, as RFC 6749
     * §4.1.2 requires.
     *
     * On success the returned array carries both credentials in plaintext —
     * the only moment they exist outside the client — plus 'user_id',
     * 'token_id' and 'client_id' for the caller's log line.
     *
     * @return array<string,mixed>
     */
    function oauthAuthorizationCodeExchange(PDO $pdo, array $params, ?int $now = null): array
    {
        $now = $now ?? time();

        foreach (['code', 'redirect_uri', 'client_id', 'code_verifier'] as $required) {
            if (!isset($params[$required]) || !is_string($params[$required]) || $params[$required] === '') {
                return oauthError('invalid_request', 'The ' . $required . ' parameter is required.');
            }
        }

        $clientId = oauthClientIdValid($params['client_id']);
        $client = $clientId !== null ? oauthClientFind($pdo, $clientId) : null;
        if ($client === null) {
            return oauthError('invalid_client', 'Unknown client.');
        }

        $code = oauthCodeValidate($params['code']);
        if ($code === null) {
            return oauthError('invalid_grant', 'Invalid authorization code.');
        }

        try {
            $stmt = $pdo->prepare('SELECT id, code_hash, client_id, pro_user_id, redirect_uri, scopes, code_challenge, resource, expires_at, used_at FROM oauth_authorization_codes WHERE code_hash = ? LIMIT 1');
            $stmt->execute([oauthHash($code)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Closed before the transaction below: a statement left open holds
            // a read snapshot, and a write on top of a stale one is refused
            // outright on SQLite (BUSY_SNAPSHOT), which is what the suites run.
            $stmt->closeCursor();
            if (!$row) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }

            // Presented twice. The tokens the code already produced must go —
            // and under one-grant-per-(user, client) they are exactly the live
            // grant for the pair the code names.
            if ($row['used_at'] !== null) {
                oauthGrantRevoke($pdo, (string) $row['client_id'], (int) $row['pro_user_id'], $now);
                return [
                    'ok' => false,
                    'error' => 'invalid_grant',
                    'error_description' => 'Invalid authorization code.',
                    'reason' => 'code_reuse',
                    'user_id' => (int) $row['pro_user_id'],
                    'client_id' => (string) $row['client_id'],
                ];
            }

            if (strtotime((string) $row['expires_at']) <= $now) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }
            if (!hash_equals((string) $row['client_id'], $clientId)) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }
            if (!hash_equals((string) $row['redirect_uri'], $params['redirect_uri'])) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }

            // RFC 8707: a resource bound at authorization must be the one
            // asked for here. A resource the authorization never named is
            // ignored — it was never bound to anything.
            $resource = (string) $row['resource'];
            if ($resource !== '') {
                if (!isset($params['resource']) || !is_string($params['resource']) || !hash_equals($resource, $params['resource'])) {
                    return oauthError('invalid_grant', 'Invalid authorization code.');
                }
            }

            if (!oauthCodeChallengeMatches($params['code_verifier'], (string) $row['code_challenge'])) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }

            $userId = (int) $row['pro_user_id'];
            if (!function_exists('proUserIsPro') || !proUserIsPro($userId)) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }
            if (function_exists('proUserIsSuspended') && proUserIsSuspended($userId)) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }

            $set = oauthScopeSetFromExternal((string) $row['scopes']);
            if ($set === null) {
                return oauthError('invalid_grant', 'Invalid authorization code.');
            }
            if ($set === []) {
                // Nothing was asked for at authorization; the narrowest scope
                // is the only safe reading of that.
                $set = ['read'];
            }

            $pdo->beginTransaction();

            $claim = $pdo->prepare('UPDATE oauth_authorization_codes SET used_at = ? WHERE id = ? AND used_at IS NULL');
            $claim->execute([date('Y-m-d H:i:s', $now), (int) $row['id']]);
            if ($claim->rowCount() === 0) {
                // Lost the race to a concurrent exchange of the same code.
                $pdo->rollBack();
                oauthGrantRevoke($pdo, (string) $row['client_id'], $userId, $now);
                return [
                    'ok' => false,
                    'error' => 'invalid_grant',
                    'error_description' => 'Invalid authorization code.',
                    'reason' => 'code_reuse',
                    'user_id' => $userId,
                    'client_id' => (string) $row['client_id'],
                ];
            }

            // One grant per (user, client): authorising again replaces the
            // rows the pair already had, revoked ones included, so the grant
            // count below only ever counts other apps.
            $pdo->prepare('DELETE FROM mcp_access_tokens WHERE oauth_client_id = ? AND pro_user_id = ?')
                ->execute([$clientId, $userId]);

            if (oauthGrantCount($pdo, $userId) >= OAUTH_GRANT_MAX_PER_USER) {
                // Refused at consent time in step 3/6 too. The code is left
                // unconsumed, so the client can retry once the user has
                // removed a grant.
                $pdo->rollBack();
                return oauthError('invalid_grant', 'This account already has as many connected apps as it can hold.');
            }

            $accessToken = mcpTokenGenerate();
            $refreshToken = oauthRefreshTokenGenerate();
            $expiresAt = date('Y-m-d H:i:s', $now + OAUTH_ACCESS_TOKEN_TTL_SECONDS);
            $refreshExpiresAt = date('Y-m-d H:i:s', $now + OAUTH_REFRESH_TOKEN_TTL_SECONDS);
            $pdo->prepare('INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at, oauth_client_id, refresh_token_hash, refresh_expires_at, grant_created_at, rotated_refresh_hash, rotated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, NULL, NULL)')
                ->execute([
                    $userId,
                    (string) $client['client_name'],
                    mcpTokenHash($accessToken),
                    mcpTokenPrefixOf($accessToken),
                    oauthScopeInternalFromSet($set),
                    date('Y-m-d H:i:s', $now),
                    $expiresAt,
                    $clientId,
                    oauthHash($refreshToken),
                    $refreshExpiresAt,
                    date('Y-m-d H:i:s', $now),
                ]);
            $tokenId = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return oauthError('server_error', 'The token could not be issued.');
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'user_id' => $userId,
            'token_id' => $tokenId,
            'client_id' => $clientId,
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => OAUTH_ACCESS_TOKEN_TTL_SECONDS,
            'refresh_token' => $refreshToken,
            'scope' => oauthScopeExternalFromSet($set),
        ];
    }
}

if (!function_exists('oauthRefreshTokenExchange')) {
    /**
     * grant_type=refresh_token (RFC 6749 §6, OAuth 2.1 §4.3). $params is the
     * parsed form body.
     *
     * Rotation is unconditional: a new access token and a new refresh token
     * replace the old ones on the same row, and the previous refresh hash is
     * kept in rotated_refresh_hash. Presenting that previous token — a copy of
     * a credential the client has already spent — revokes the whole grant and
     * is reported as 'refresh_reuse', so the caller can raise a WARNING. That
     * is the detection OAuth 2.1 asks for and the only reason an old refresh
     * hash is kept at all.
     *
     * Scope can narrow and never widen: a request for a scope the grant does
     * not hold answers invalid_scope. An account that has lost Pro, or that is
     * suspended, and a revoked or expired grant all answer invalid_grant.
     *
     * @return array<string,mixed>
     */
    function oauthRefreshTokenExchange(PDO $pdo, array $params, ?int $now = null): array
    {
        $now = $now ?? time();

        foreach (['refresh_token', 'client_id'] as $required) {
            if (!isset($params[$required]) || !is_string($params[$required]) || $params[$required] === '') {
                return oauthError('invalid_request', 'The ' . $required . ' parameter is required.');
            }
        }

        $clientId = oauthClientIdValid($params['client_id']);
        $client = $clientId !== null ? oauthClientFind($pdo, $clientId) : null;
        if ($client === null) {
            return oauthError('invalid_client', 'Unknown client.');
        }

        $refreshToken = oauthRefreshTokenValidate($params['refresh_token']);
        if ($refreshToken === null) {
            return oauthError('invalid_grant', 'Invalid refresh token.');
        }

        try {
            $stmt = $pdo->prepare('SELECT id, pro_user_id, scopes, expires_at, revoked_at, oauth_client_id, refresh_expires_at FROM mcp_access_tokens WHERE refresh_token_hash = ? LIMIT 1');
            $stmt->execute([oauthHash($refreshToken)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$row) {
                // Not the live refresh token. If it is the one this grant
                // rotated away from, it has been replayed — revoke the grant.
                $stale = $pdo->prepare('SELECT id, pro_user_id, oauth_client_id FROM mcp_access_tokens WHERE rotated_refresh_hash = ? LIMIT 1');
                $stale->execute([oauthHash($refreshToken)]);
                $previous = $stale->fetch(PDO::FETCH_ASSOC);
                $stale->closeCursor();
                if ($previous) {
                    $revoked = oauthGrantRevoke($pdo, (string) $previous['oauth_client_id'], (int) $previous['pro_user_id'], $now);
                    return [
                        'ok' => false,
                        'error' => 'invalid_grant',
                        'error_description' => 'Invalid refresh token.',
                        'reason' => 'refresh_reuse',
                        'user_id' => (int) $previous['pro_user_id'],
                        'client_id' => (string) $previous['oauth_client_id'],
                        'revoked' => $revoked,
                    ];
                }
                return oauthError('invalid_grant', 'Invalid refresh token.');
            }

            if ($row['revoked_at'] !== null) {
                return oauthError('invalid_grant', 'Invalid refresh token.');
            }
            if ($row['refresh_expires_at'] === null || strtotime((string) $row['refresh_expires_at']) <= $now) {
                return oauthError('invalid_grant', 'Invalid refresh token.');
            }
            if (!hash_equals((string) $row['oauth_client_id'], $clientId)) {
                return oauthError('invalid_grant', 'Invalid refresh token.');
            }

            $userId = (int) $row['pro_user_id'];
            if (!function_exists('proUserIsPro') || !proUserIsPro($userId)) {
                return oauthError('invalid_grant', 'Invalid refresh token.');
            }
            if (function_exists('proUserIsSuspended') && proUserIsSuspended($userId)) {
                return oauthError('invalid_grant', 'Invalid refresh token.');
            }

            $granted = oauthScopeSetFromInternal((string) $row['scopes']);
            $set = $granted;
            if (isset($params['scope']) && is_string($params['scope']) && trim($params['scope']) !== '') {
                $requested = oauthScopeSetFromExternal($params['scope']);
                if ($requested === null) {
                    return oauthError('invalid_scope', 'Unknown scope.');
                }
                if (array_diff($requested, $granted) !== []) {
                    return oauthError('invalid_scope', 'The requested scope is broader than the one granted.');
                }
                $set = $requested;
            }

            $accessToken = mcpTokenGenerate();
            $newRefreshToken = oauthRefreshTokenGenerate();
            $expiresAt = date('Y-m-d H:i:s', $now + OAUTH_ACCESS_TOKEN_TTL_SECONDS);
            $refreshExpiresAt = date('Y-m-d H:i:s', $now + OAUTH_REFRESH_TOKEN_TTL_SECONDS);

            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE mcp_access_tokens SET token_hash = ?, token_prefix = ?, scopes = ?, last_used_at = NULL, expires_at = ?, refresh_token_hash = ?, refresh_expires_at = ?, rotated_refresh_hash = ?, rotated_at = ? WHERE id = ? AND revoked_at IS NULL AND refresh_token_hash = ?');
            $update->execute([
                mcpTokenHash($accessToken),
                mcpTokenPrefixOf($accessToken),
                oauthScopeInternalFromSet($set),
                $expiresAt,
                oauthHash($newRefreshToken),
                $refreshExpiresAt,
                oauthHash($refreshToken),
                date('Y-m-d H:i:s', $now),
                (int) $row['id'],
                oauthHash($refreshToken),
            ]);
            if ($update->rowCount() === 0) {
                // A concurrent refresh of the same token won; this one is
                // holding a credential that has already been spent.
                $pdo->rollBack();
                oauthGrantRevoke($pdo, $clientId, $userId, $now);
                return [
                    'ok' => false,
                    'error' => 'invalid_grant',
                    'error_description' => 'Invalid refresh token.',
                    'reason' => 'refresh_reuse',
                    'user_id' => $userId,
                    'client_id' => $clientId,
                ];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return oauthError('server_error', 'The token could not be issued.');
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'user_id' => $userId,
            'token_id' => (int) $row['id'],
            'client_id' => $clientId,
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => OAUTH_ACCESS_TOKEN_TTL_SECONDS,
            'refresh_token' => $newRefreshToken,
            'scope' => oauthScopeExternalFromSet($set),
        ];
    }
}

if (!function_exists('oauthTokenRevoke')) {
    /**
     * RFC 7009 revocation: revoke the grant when $token is that client's
     * access or refresh token. True when a grant was revoked.
     *
     * Only a row that carries $clientId as its oauth_client_id is reachable,
     * so a manual token — every token the profile's "Connected apps" card
     * created, all of them with oauth_client_id NULL — can never be revoked
     * through this endpoint, and neither can another client's token. Both the
     * access token and the live refresh token are accepted, and either one
     * revokes the whole grant (the row holds both credentials).
     *
     * Returns false for everything else — an unknown token, a malformed one, a
     * token of another client, a missing table — so the caller can answer 200
     * to all of them alike and the endpoint stays free of an oracle.
     */
    function oauthTokenRevoke(PDO $pdo, ?string $token, ?string $clientId, ?int $now = null): bool
    {
        $now = $now ?? time();
        $clientId = oauthClientIdValid($clientId);
        if ($clientId === null || !is_string($token) || $token === '') {
            return false;
        }

        if (mcpTokenValidate($token) !== null) {
            $column = 'token_hash';
        } elseif (oauthRefreshTokenValidate($token) !== null) {
            $column = 'refresh_token_hash';
        } else {
            return false;
        }

        try {
            if (!oauthAvailable($pdo)) {
                return false;
            }
            $stmt = $pdo->prepare('SELECT id FROM mcp_access_tokens WHERE ' . $column . ' = ? AND oauth_client_id = ? AND revoked_at IS NULL LIMIT 1');
            $stmt->execute([oauthHash($token), $clientId]);
            $id = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($id === false) {
                return false;
            }
            $update = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
            $update->execute([date('Y-m-d H:i:s', $now), (int) $id]);
            return $update->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

// ---------------------------------------------------------------------
// Cleanup (cron/cleanup.php, step 4/6)
// ---------------------------------------------------------------------

if (!function_exists('oauthCodeCleanup')) {
    /**
     * Removes authorization codes that are past their usefulness: a code lives
     * for OAUTH_CODE_TTL_SECONDS and every exchange refuses an expired or spent
     * one, so anything older than OAUTH_CODE_SWEEP_SECONDS is kept only for the
     * audit. All of it goes, used or not. Fail-open: a missing table (the
     * migration has not run) removes nothing rather than failing the cron.
     */
    function oauthCodeCleanup(PDO $pdo, ?int $now = null): int
    {
        $now = $now ?? time();
        $cutoff = date('Y-m-d H:i:s', $now - OAUTH_CODE_SWEEP_SECONDS);
        try {
            $stmt = $pdo->prepare('DELETE FROM oauth_authorization_codes WHERE expires_at <= ?');
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('oauthClientCleanup')) {
    /**
     * Removes registered clients nothing points at any more: no token row
     * carries the client_id (so no grant exists, live or revoked-but-unswept)
     * and the client has been idle for OAUTH_CLIENT_IDLE_SECONDS, where
     * "idle" is COALESCE(last_used_at, created_at) — a client that registered
     * and was never used ages out from its creation.
     *
     * The no-token-row condition is not a nicety: deleting a client a token
     * still links to leaves exactly the orphan check_oauth.php reports. Since
     * mcpTokenCleanup() removes revoked rows first, a client whose grant is
     * gone becomes sweepable on a later run. Fail-open.
     */
    function oauthClientCleanup(PDO $pdo, ?int $now = null): int
    {
        $now = $now ?? time();
        $cutoff = date('Y-m-d H:i:s', $now - OAUTH_CLIENT_IDLE_SECONDS);
        try {
            $stmt = $pdo->prepare('DELETE FROM oauth_clients WHERE client_id NOT IN (SELECT oauth_client_id FROM mcp_access_tokens WHERE oauth_client_id IS NOT NULL) AND COALESCE(last_used_at, created_at) <= ?');
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
