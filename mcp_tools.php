<?php

declare(strict_types=1);

/**
 * The MCP tool registry (epic #318, #321) — which tools the endpoint in
 * mcp.php offers, and which token scope each of them needs.
 *
 * The registry lives here rather than in mcp.php because it is data, not
 * transport: #322 (read tools) and #323 (write tools) add their entries to
 * mcpToolRegistry() without touching the endpoint's method checks, Bearer
 * authentication, rate limit or JSON-RPC dispatch. #321 ships it empty on
 * purpose — the registry, the scope filter and the dispatch are in place and
 * exercised, there is simply nothing registered yet, so `tools/list` answers
 * an empty list.
 *
 * A registry entry is:
 *   name        the tool name a client calls
 *   scope       'read' or 'write': the token scope needed to see *and* call it
 *   description what the tool does, shown to the model
 *   inputSchema a JSON Schema object describing the arguments
 *   handler     callable(array $args, array $tokenRow, PDO $pdo): array
 *
 * A handler follows mailbox_service.php's shape — `['ok' => true, ...]` when
 * it did what was asked, `['ok' => false, 'error' => '…']` when it could not.
 * The second case is an ordinary tool result carrying `isError: true`
 * (mcpToolResult()), which is what the MCP specification asks for: "Address
 * already taken" is something the model should read and react to, not a
 * protocol failure. Only a handler that throws is a protocol error, and that
 * is a bug.
 *
 * Scope: a token's scope string is 'read' or 'read,write' (mcpTokenScopes()
 * in mcp_tokens.php, #320). A 'read' tool is visible to both; a 'write' tool
 * only to 'read,write'. mcpTokenAllowsScope() is the one place that decides
 * it, and both `tools/list` and `tools/call` in mcp.php go through
 * mcpToolsForScopes() so the two can never drift apart.
 *
 * Everything here is pure — no PDO, no session, no output — so
 * tests/mcp_endpoint_test.php can exercise the scope filter directly, on its
 * own fixtures, without standing up the endpoint.
 */

if (!function_exists('mcpToolScopes')) {
    /** The scopes a single tool may require, narrowest first. */
    function mcpToolScopes(): array
    {
        return ['read', 'write'];
    }
}

if (!function_exists('mcpToolRegistry')) {
    /**
     * Every tool this server offers — empty until #322/#323 add theirs.
     *
     * @return list<array{name:string, scope:string, description:string, inputSchema:array, handler:callable}>
     */
    function mcpToolRegistry(): array
    {
        return [];
    }
}

if (!function_exists('mcpTokenAllowsScope')) {
    /**
     * Does a token's scope string cover $required? Anything that is not one of
     * mcpToolScopes() is refused, so a typo in a registry entry cannot open a
     * tool up to everybody.
     */
    function mcpTokenAllowsScope(string $tokenScopes, string $required): bool
    {
        if (!in_array($required, mcpToolScopes(), true)) {
            return false;
        }
        foreach (explode(',', strtolower($tokenScopes)) as $scope) {
            if (trim($scope) === $required) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('mcpToolsForScopes')) {
    /**
     * The tools a token may see and call, each as the `tools/list` entry a
     * client is given: name, description and inputSchema. The registry's own
     * `scope` and `handler` keys never leave the server — a client learns
     * nothing about what exists beyond what it is allowed to use.
     *
     * $tools is a parameter rather than a call to mcpToolRegistry() so the
     * filter can be tested on its own fixtures.
     */
    function mcpToolsForScopes(array $tools, string $tokenScopes): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !isset($tool['name'], $tool['scope'])) {
                continue;
            }
            if (!mcpTokenAllowsScope($tokenScopes, (string) $tool['scope'])) {
                continue;
            }
            $schema = $tool['inputSchema'] ?? null;
            $out[] = [
                'name' => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => is_array($schema) ? $schema : ['type' => 'object', 'properties' => new stdClass()],
            ];
        }
        return $out;
    }
}

if (!function_exists('mcpToolFind')) {
    /**
     * One registry entry by name, or null. Searched unfiltered on purpose:
     * the caller needs to tell "no such tool" from "not allowed for this
     * token", and only then decide what to answer.
     */
    function mcpToolFind(array $tools, string $name): ?array
    {
        foreach ($tools as $tool) {
            if (is_array($tool) && ($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }
        return null;
    }
}

if (!function_exists('mcpToolResult')) {
    /**
     * A handler's return value as the `tools/call` result:
     * `['content' => [...], 'isError' => bool]`. A handler failure is a
     * normal result (isError true) carrying the handler's own wording, never
     * a JSON-RPC error — that is what lets the model read "Address already
     * taken" and try something else.
     */
    function mcpToolResult(array $out): array
    {
        if (($out['ok'] ?? false) === true) {
            $text = $out['text'] ?? null;
            if (!is_string($text)) {
                $text = (string) json_encode(
                    $out['data'] ?? [],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            }
            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
        }
        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) ($out['error'] ?? 'The tool could not complete the request.'),
            ]],
            'isError' => true,
        ];
    }
}
