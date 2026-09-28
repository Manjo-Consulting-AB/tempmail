<?php

declare(strict_types=1);

/**
 * Mail Shield's MCP endpoint (epic #318, #321) — the one URL an MCP client is
 * given. Users are handed `<base_url>/mcp`, which .htaccess rewrites here.
 *
 * Transport: Streamable HTTP, restricted to what shared PHP hosting can
 * serve. POST only — every other method, GET included, answers 405, because
 * there is no SSE stream to open and no session to end. The body of the POST
 * is one JSON-RPC 2.0 request or notification and the answer is a single
 * application/json body; nothing is held open between requests and no
 * Mcp-Session-Id is ever minted, echoed or stored. A notification is answered
 * 202 with no body, as the specification requires. Batches are refused: they
 * are the one shape that would need the server to hold several answers
 * together.
 *
 * Protocol revision: MCP_PROTOCOL_VERSION below is the revision this endpoint
 * speaks, and it is deliberately not the newest one. MCP removed the
 * `initialize` handshake in 2026-07-28: that revision carries the version in
 * every request's `_meta` instead, requires the MCP-Protocol-Version,
 * Mcp-Method and Mcp-Name headers to be validated against the body, and
 * requires a `server/discover` RPC. This endpoint is the handshake shape the
 * issue describes — `initialize`, then `notifications/initialized`, then
 * requests — which is 2025-11-25, the last revision that has it.
 *
 * That is also what makes a current client work. Claude Code opens with a
 * `server/discover` probe and treats any answer that is not a DiscoverResult
 * — this endpoint's -32601 included — as evidence of a legacy server, then
 * falls back to `initialize` asking for 2025-11-25, the first entry in its
 * own supported list. Answering a modern version here while speaking the
 * handshake would advertise something this endpoint does not implement, and
 * the client would have nothing to fall back to. A request that carries a
 * different MCP-Protocol-Version is therefore refused with 400 rather than
 * served under the wrong rules.
 *
 * Authentication: `Authorization: Bearer msk_…` from mcp_tokens.php (#320),
 * resolved by mcpTokenResolve() — the same access decision as everywhere
 * else, so a revoked, expired, no-longer-Pro or suspended account is refused
 * here too. Its reason argument chooses between 401 (the credential is the
 * problem) and 403 (the credential is fine, the account is not entitled).
 * There is no session, no cookie and no CSRF token on this path, so none is
 * read or set.
 *
 * The check order is the one in the issue, and it stops at the first failure:
 * method and Content-Type, protocol version, Origin, Bearer token, Pro
 * entitlement, rate limit, body size and JSON, then dispatch. The Origin
 * check is the specification's DNS-rebinding defence and runs before the
 * token, so a page on another origin cannot use this endpoint to probe
 * credentials at all.
 *
 * Tools live in mcp_tools.php; #321 ships the registry, the scope filter and
 * the dispatch with nothing registered, and #322/#323 fill it.
 *
 * Logging: one INFO line per tool call (account id, token id, tool name,
 * outcome), and a WARNING for a rejected Origin, a malformed credential and a
 * rate-limit hit, each with the visitor's IP. The token itself, the request
 * body and message content are never logged — the body is not even kept in a
 * variable that outlives the dispatch.
 */

define('TEMPMAIL_APP', true);

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "mcp.php is an HTTP endpoint; it is not run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mcp_tokens.php';
require_once __DIR__ . '/mcp_tools.php';
require_once __DIR__ . '/abuse_guard.php';

/** The protocol revision this endpoint speaks; see the file header. */
const MCP_PROTOCOL_VERSION = '2025-11-25';
/** Largest request body accepted, in bytes. A JSON-RPC request is small. */
const MCP_MAX_BODY_BYTES = 65536;
/** The server identity reported by initialize. */
const MCP_SERVER_NAME = 'Mail Shield';

// JSON-RPC 2.0 error codes. -32000 is the reserved server-error range, used
// for the one condition JSON-RPC has no code for: being asked to slow down.
const MCP_ERR_PARSE = -32700;
const MCP_ERR_REQUEST = -32600;
const MCP_ERR_METHOD = -32601;
const MCP_ERR_PARAMS = -32602;
const MCP_ERR_INTERNAL = -32603;
const MCP_ERR_RATE_LIMIT = -32000;

// ---------------------------------------------------------------------
// Responses and request headers
// ---------------------------------------------------------------------

/** Send one answer and stop. A null payload sends a bodyless response. */
function mcpRespond(int $status, ?array $payload = null): void
{
    http_response_code($status);
    if ($payload !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/** A JSON-RPC result envelope. $result may be an object when it must encode as {}. */
function mcpResult($id, $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** A JSON-RPC error envelope. */
function mcpError($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

/** A request header, trimmed, or null when it is absent or empty. */
function mcpHeader(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$key] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

/**
 * The request's Authorization header. Apache hands it to PHP as
 * HTTP_AUTHORIZATION when .htaccess copies it there (CGI strips it before the
 * script starts), and as REDIRECT_HTTP_AUTHORIZATION when the copy went
 * through an internal redirect. Both are read; neither is assumed.
 */
function mcpAuthorizationHeader(): ?string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        $value = $_SERVER[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return null;
}

/** The Bearer credential in that header, or null when there is none. */
function mcpBearerToken(): ?string
{
    $header = mcpAuthorizationHeader();
    if ($header === null) {
        return null;
    }
    return preg_match('/^Bearer[ \t]+(\S+)$/i', $header, $matches) === 1 ? $matches[1] : null;
}

/**
 * Is $origin this site's own origin? Compared scheme, host and port against
 * $config['email']['base_url'] — never against the request's Host header,
 * which is what an attacker controls in a DNS-rebinding attempt.
 */
function mcpOriginAllowed(string $origin, array $config): bool
{
    $expected = parse_url((string) ($config['email']['base_url'] ?? ''));
    $actual = parse_url($origin);
    if (!is_array($expected) || !is_array($actual)) {
        return false;
    }
    $schemeOf = static fn(array $url): string => strtolower((string) ($url['scheme'] ?? ''));
    $portOf = static fn(array $url): int => (int) ($url['port'] ?? ($schemeOf($url) === 'https' ? 443 : 80));
    // parse_url() omits 'host' entirely for '' and for the literal 'null' an
    // opaque origin arrives as, so this is an isset, not a null comparison.
    if (!isset($expected['host'], $actual['host'])) {
        return false;
    }
    return $schemeOf($expected) === $schemeOf($actual)
        && strcasecmp((string) $expected['host'], (string) $actual['host']) === 0
        && $portOf($expected) === $portOf($actual);
}

/** One INFO line per tool call: who, which token, which tool, how it went. */
function mcpLogToolCall(array $tokenRow, string $toolName, string $outcome): void
{
    logMessage('INFO', 'MCP tool call', [
        'user_id' => (int) $tokenRow['user_id'],
        'token_id' => (int) $tokenRow['id'],
        'tool' => $toolName,
        'outcome' => $outcome,
    ]);
}

// ---------------------------------------------------------------------
// 1. Method and Content-Type
// ---------------------------------------------------------------------

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    mcpRespond(405, mcpError(null, MCP_ERR_REQUEST, 'This endpoint accepts POST only.'));
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    mcpRespond(415, mcpError(null, MCP_ERR_REQUEST, 'Content-Type must be application/json.'));
}

// The revision the client believes it is speaking. Absent is allowed — a
// client that has not been told yet sends nothing, and 2025-03-26-era clients
// never send it — but a version we do not speak is refused outright rather
// than served under rules that are not its own.
$requestedVersion = mcpHeader('MCP-Protocol-Version');
if ($requestedVersion !== null && $requestedVersion !== MCP_PROTOCOL_VERSION) {
    mcpRespond(400, mcpError(null, MCP_ERR_REQUEST, 'Unsupported MCP-Protocol-Version; this server speaks ' . MCP_PROTOCOL_VERSION . '.'));
}

// ---------------------------------------------------------------------
// 2. Origin (the specification's DNS-rebinding defence)
// ---------------------------------------------------------------------

$origin = mcpHeader('Origin');
if ($origin !== null && !mcpOriginAllowed($origin, $config)) {
    logMessage('WARNING', 'MCP request rejected: foreign Origin', [
        'origin' => mb_substr($origin, 0, 120),
        'ip' => getVisitorIp(),
    ]);
    mcpRespond(403, mcpError(null, MCP_ERR_REQUEST, 'Forbidden origin.'));
}

// ---------------------------------------------------------------------
// 3. Bearer token, and 4. the account behind it
// ---------------------------------------------------------------------

if (mcpAuthorizationHeader() === null) {
    header('WWW-Authenticate: Bearer realm="Mail Shield"');
    mcpRespond(401, mcpError(null, MCP_ERR_REQUEST, 'A Bearer token is required.'));
}

$bearer = mcpBearerToken();
if ($bearer === null) {
    // The header is there but is not a Bearer credential. The value is never
    // logged — it may well be somebody's key for something else.
    logMessage('WARNING', 'MCP request rejected: malformed Authorization header', ['ip' => getVisitorIp()]);
    header('WWW-Authenticate: Bearer realm="Mail Shield"');
    mcpRespond(401, mcpError(null, MCP_ERR_REQUEST, 'A Bearer token is required.'));
}

$rejection = null;
$tokenRow = mcpTokenResolve($pdo, $bearer, null, $rejection);
if ($tokenRow === null) {
    // Only a token that is real, live and unrevoked can be 'not_pro' or
    // 'suspended', so this branch tells the token's own holder something
    // useful without telling anyone else anything at all: every other
    // rejection is answered as the same credential failure.
    if ($rejection === 'not_pro' || $rejection === 'suspended') {
        mcpRespond(403, mcpError(null, MCP_ERR_REQUEST, 'This account does not have an active Mail Shield Pro subscription.'));
    }
    if ($rejection === 'malformed') {
        logMessage('WARNING', 'MCP request rejected: malformed token', ['ip' => getVisitorIp()]);
    }
    header('WWW-Authenticate: Bearer realm="Mail Shield"');
    mcpRespond(401, mcpError(null, MCP_ERR_REQUEST, 'The Bearer token is not valid.'));
}

// ---------------------------------------------------------------------
// 5. Rate limit, per token
// ---------------------------------------------------------------------

// Fail-open, like every other guard call on a live path: a counter that
// cannot be read must never refuse a request that would otherwise be served.
try {
    if (abuseGuardAvailable()) {
        $verdict = abuseMcpTokenRateLimit($pdo, (int) $tokenRow['id'], time(), abuseGuardSettings());
        if ($verdict['limited']) {
            logMessage('WARNING', 'MCP request rejected: rate limit', [
                'user_id' => (int) $tokenRow['user_id'],
                'token_id' => (int) $tokenRow['id'],
                'rule' => $verdict['rule'],
                'ip' => getVisitorIp(),
            ]);
            header('Retry-After: ' . (int) $verdict['retry_after']);
            mcpRespond(429, mcpError(null, MCP_ERR_RATE_LIMIT, 'Too many requests; retry later.'));
        }
    }
} catch (Throwable $e) {
    logMessage('WARNING', 'MCP rate limit unavailable', ['error' => $e->getMessage()]);
}

// ---------------------------------------------------------------------
// 6. The body: one JSON-RPC message, size-capped
// ---------------------------------------------------------------------

$rawBody = (string) file_get_contents('php://input');
if (strlen($rawBody) > MCP_MAX_BODY_BYTES) {
    mcpRespond(413, mcpError(null, MCP_ERR_REQUEST, 'Request body too large.'));
}

// Decoded once without associative arrays, so a JSON object (one message) can
// be told from a JSON array (a batch, which this endpoint does not serve) the
// way the wire format itself distinguishes them.
$shape = json_decode($rawBody);
if (json_last_error() !== JSON_ERROR_NONE) {
    mcpRespond(400, mcpError(null, MCP_ERR_PARSE, 'Parse error.'));
}
if (is_array($shape)) {
    mcpRespond(400, mcpError(null, MCP_ERR_REQUEST, 'JSON-RPC batches are not supported.'));
}
if (!is_object($shape)) {
    mcpRespond(400, mcpError(null, MCP_ERR_REQUEST, 'Invalid Request.'));
}

$message = json_decode($rawBody, true);
$method = is_array($message) ? ($message['method'] ?? null) : null;
if (!is_string($method) || $method === '') {
    mcpRespond(400, mcpError(null, MCP_ERR_REQUEST, 'Invalid Request.'));
}

$hasId = array_key_exists('id', $message);
$id = $hasId ? $message['id'] : null;
if ($hasId && $id !== null && !is_string($id) && !is_int($id) && !is_float($id)) {
    mcpRespond(400, mcpError(null, MCP_ERR_REQUEST, 'Invalid Request.'));
}
$params = is_array($message['params'] ?? null) ? $message['params'] : [];

// A notification has no id and is never answered — not even to say the method
// is unknown, which is why this comes before the dispatch.
if (!$hasId) {
    mcpRespond(202);
}

// ---------------------------------------------------------------------
// 7. Dispatch
// ---------------------------------------------------------------------

if ($method === 'initialize') {
    mcpRespond(200, mcpResult($id, [
        'protocolVersion' => MCP_PROTOCOL_VERSION,
        'capabilities' => ['tools' => ['listChanged' => false]],
        'serverInfo' => [
            'name' => MCP_SERVER_NAME,
            'version' => (string) ($config['app']['version'] ?? '1.0.0'),
        ],
    ]));
}

if ($method === 'ping') {
    // An empty JSON object, not an empty array: json_encode([]) would send
    // [], which is not a PingResult.
    mcpRespond(200, mcpResult($id, new stdClass()));
}

if ($method === 'tools/list') {
    mcpRespond(200, mcpResult($id, [
        'tools' => mcpToolsForScopes(mcpToolRegistry(), (string) $tokenRow['scopes']),
    ]));
}

if ($method === 'tools/call') {
    $name = $params['name'] ?? null;
    if (!is_string($name) || $name === '') {
        mcpRespond(200, mcpError($id, MCP_ERR_PARAMS, 'A tool name is required.'));
    }
    $tool = mcpToolFind(mcpToolRegistry(), $name);
    if ($tool === null) {
        mcpRespond(200, mcpError($id, MCP_ERR_PARAMS, 'Unknown tool.'));
    }
    // The scope is checked again here, not just in tools/list: a client that
    // kept a name from a wider-scoped token must not be able to call it.
    if (!mcpTokenAllowsScope((string) $tokenRow['scopes'], (string) ($tool['scope'] ?? ''))) {
        mcpLogToolCall($tokenRow, (string) $tool['name'], 'denied');
        mcpRespond(200, mcpError($id, MCP_ERR_PARAMS, 'This token is not allowed to call this tool.'));
    }
    $handler = $tool['handler'] ?? null;
    if (!is_callable($handler)) {
        mcpLogToolCall($tokenRow, (string) $tool['name'], 'unavailable');
        mcpRespond(200, mcpError($id, MCP_ERR_INTERNAL, 'The tool is not available.'));
    }

    $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    try {
        $outcome = $handler($args, $tokenRow, $pdo);
    } catch (Throwable $e) {
        mcpLogToolCall($tokenRow, (string) $tool['name'], 'failed');
        logMessage('ERROR', 'MCP tool call threw', [
            'user_id' => (int) $tokenRow['user_id'],
            'token_id' => (int) $tokenRow['id'],
            'tool' => (string) $tool['name'],
            'error' => $e->getMessage(),
        ]);
        mcpRespond(200, mcpError($id, MCP_ERR_INTERNAL, 'The tool failed.'));
    }

    // A handler that could not do what was asked is an ordinary result with
    // isError: true, not a protocol error — the model reads it and reacts.
    $result = mcpToolResult(is_array($outcome) ? $outcome : []);
    mcpLogToolCall($tokenRow, (string) $tool['name'], $result['isError'] ? 'error' : 'ok');
    mcpRespond(200, mcpResult($id, $result));
}

mcpRespond(200, mcpError($id, MCP_ERR_METHOD, 'Method not found.'));
