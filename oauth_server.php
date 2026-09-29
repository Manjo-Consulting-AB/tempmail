<?php

declare(strict_types=1);

/**
 * OAuth 2.1 for the MCP endpoint — the shared library (epic #331, step 1/6;
 * tables created by migrate_oauth.php).
 *
 * Shape: like mailbox_service.php and mcp_tokens.php, every function takes an
 * explicit PDO, never reads $_SESSION and never echoes. A failure is a return
 * value, not an exception the caller has to remember to catch. The functions
 * compute times in PHP rather than with NOW(), so the regression suite runs
 * them on SQLite.
 *
 * What lives here so far (step 1/6):
 *  - oauthAvailable(): is the OAuth schema in place? Everything fails closed
 *    ("not available") until the migration has run.
 *  - oauthClientRegister() / oauthClientFind() / oauthClientCount(): dynamic
 *    client registration (RFC 7591) and lookup.
 *  - redirect-URI validation: the exact-match allow-list a later step checks a
 *    client's requested redirect_uri against.
 *  - the small helpers the later steps share: random ids and the sha256 at
 *    rest used for codes and refresh tokens (the same unkeyed hash
 *    mcp_tokens.php uses for access tokens — the values are already 256
 *    random bits, so a key would strengthen nothing).
 *
 * No secret is ever issued: token_endpoint_auth_method is "none" only, the
 * shape a public PKCE client (Claude.ai, Claude Code) uses.
 *
 * Nothing here reads a client secret, a code or a refresh token into a log;
 * callers log client_id and client_name only. The registering IP is never
 * stored bare — oauthIpHash() is the keyed sha256 the schema keeps.
 */

if (!defined('OAUTH_CLIENT_ID_BYTES')) {
    // client_id = 32 hex characters from this many random bytes.
    define('OAUTH_CLIENT_ID_BYTES', 16);
    // A client may register between 1 and this many redirect URIs.
    define('OAUTH_REDIRECT_URI_MAX_COUNT', 5);
    // Longest accepted redirect URI, matching the column's width.
    define('OAUTH_REDIRECT_URI_MAX_LENGTH', 512);
    // client_name is trimmed and cut to this many characters.
    define('OAUTH_CLIENT_NAME_MAX_LENGTH', 64);
    // Shown when no usable client_name was sent.
    define('OAUTH_CLIENT_NAME_DEFAULT', 'Unnamed app');
    // Authorization-code lifetime, in seconds (used from step 2/6 on).
    define('OAUTH_CODE_TTL_SECONDS', 60);
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
     * and the per-IP rate limit counts under — never the raw IP. The sub-key
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
