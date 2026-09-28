<?php

declare(strict_types=1);

/**
 * Personal access tokens for the MCP server (epic #318, #320) — table
 * mcp_access_tokens, created by migrate_mcp_tokens.php.
 *
 * Why they exist: every other way into an account is a browser session (a
 * magic link, a password with optional 2FA, or the `ms_stay` cookie). An MCP
 * client is not a browser: it needs a credential it can put in an
 * `Authorization: Bearer …` header, that the user can name and revoke, and
 * that is limited to what the user allowed — that is what this table holds.
 *
 * Token format: `msk_` + 64 lowercase hex characters (256 bits from
 * random_bytes()). Only hash('sha256', $token) is stored — unkeyed, exactly
 * like feed_token_hash in feed_token.php: the token is already 256 random
 * bits, so there is nothing a key would strengthen, and no key means lookup
 * keeps working whatever happens to the encryption keys. The token itself is
 * shown once, at creation, and never again: unlike an RSS feed URL nothing
 * here can re-display it, so a lost token is regenerated, not recovered.
 *
 * Everything that decides access lives in mcpTokenResolve(): the format is
 * validated before any query, then a revoked or expired row, an account that
 * is no longer Pro (proUserIsPro()) or a suspended one (proUserIsSuspended())
 * all resolve to nothing. last_used_at is written at most once a minute, so a
 * busy client does not turn every request into a write.
 *
 * Removal: an access token is revoked (revoked_at set) rather than deleted,
 * so the audit can still count it; the row is swept 30 days later by
 * mcpTokenCleanup() from cron/cleanup.php, which also removes rows whose
 * account no longer exists. Every password or email change (and their undo
 * links), an account deletion and an admin suspension revoke all of an
 * account's tokens through mcpTokensRevokeAll() — the same places
 * pro_remember.php clears the devices it kept signed in.
 *
 * Fail closed: without the table nothing resolves — mcpTokenAvailable() is
 * checked before every lookup, and every function lets a throwable through as
 * "no token" rather than as an error. The data functions take the PDO
 * explicitly and compute times in PHP rather than with NOW(), so
 * tests/mcp_tokens_test.php runs them on SQLite; mcpTokensRevokeAllFor() is
 * the fail-open global-$pdo wrapper the removal paths call, so a missing table
 * can never undo a password change or an account deletion.
 */

if (!defined('MCP_TOKEN_PREFIX')) {
    // The token's own prefix, so a leak is recognisable in a log or a paste.
    define('MCP_TOKEN_PREFIX', 'msk_');
    // How many chars of the token are kept (and shown) as an identifier.
    define('MCP_TOKEN_PREFIX_LENGTH', 12);
    // Active tokens per account; the 11th is refused, never silently dropped.
    define('MCP_TOKEN_MAX_PER_USER', 10);
    // How long a revoked or expired row is kept before cron/cleanup.php sweeps it.
    define('MCP_TOKEN_SWEEP_DAYS', 30);
    // last_used_at is written at most this often, in seconds.
    define('MCP_TOKEN_TOUCH_SECONDS', 60);
}

if (!function_exists('mcpTokenScopes')) {
    /** The two scope strings the profile offers, narrowest first. */
    function mcpTokenScopes(): array
    {
        return ['read', 'read,write'];
    }
}

if (!function_exists('mcpTokenExpiryChoices')) {
    /** The periods the profile offers, in days; 0 means the token never expires. */
    function mcpTokenExpiryChoices(): array
    {
        return [0, 30, 90, 365];
    }
}

if (!function_exists('mcpTokenGenerate')) {
    /** A fresh access token: `msk_` + 64 lowercase hex characters. */
    function mcpTokenGenerate(): string
    {
        return MCP_TOKEN_PREFIX . bin2hex(random_bytes(32));
    }
}

if (!function_exists('mcpTokenValidate')) {
    /**
     * $raw if it is a well-formed token, else null. Validate-then-look-up:
     * callers must reject before touching the database, exactly like the
     * address regex in config.php — a malformed credential never reaches a
     * query, so it cannot be used to probe the table.
     */
    function mcpTokenValidate(?string $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        return preg_match('/^msk_[a-f0-9]{64}$/', $raw) === 1 ? $raw : null;
    }
}

if (!function_exists('mcpTokenHash')) {
    /** The lookup index for $token: plain SHA-256 hex (see the file header). */
    function mcpTokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}

if (!function_exists('mcpTokenPrefixOf')) {
    /** The 12 characters kept in token_prefix: `msk_` plus 8 hex characters. */
    function mcpTokenPrefixOf(string $token): string
    {
        return substr($token, 0, MCP_TOKEN_PREFIX_LENGTH);
    }
}

if (!function_exists('mcpTokenNormaliseScope')) {
    /** One of mcpTokenScopes(), or null for anything else. */
    function mcpTokenNormaliseScope($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return in_array($value, mcpTokenScopes(), true) ? $value : null;
    }
}

if (!function_exists('mcpTokenNormaliseExpiryDays')) {
    /** One of mcpTokenExpiryChoices() (0 = never expires), or null for anything else. */
    function mcpTokenNormaliseExpiryDays($value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }
        $days = (int) $value;
        return in_array($days, mcpTokenExpiryChoices(), true) ? $days : null;
    }
}

if (!function_exists('mcpTokenAvailable')) {
    /** Is the table there? Cached per PDO for the life of the process. */
    function mcpTokenAvailable(PDO $pdo): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $cache)) {
            try {
                $pdo->query('SELECT id FROM mcp_access_tokens WHERE 1 = 0');
                $cache[$key] = true;
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

if (!function_exists('mcpTokenActiveCount')) {
    /**
     * How many of the account's tokens still work: not revoked and not
     * expired. Expired rows are only swept later, so they must not count
     * against the cap in the meantime.
     */
    function mcpTokenActiveCount(PDO $pdo, int $userId, ?int $now = null): int
    {
        $now = $now ?? time();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mcp_access_tokens WHERE pro_user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)');
        $stmt->execute([$userId, date('Y-m-d H:i:s', $now)]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('mcpTokenCreate')) {
    /**
     * Stores a new token for $userId and returns it as
     * ['id', 'token', 'token_prefix', 'name', 'scopes', 'created_at',
     * 'expires_at'] — or null when the account already has
     * MCP_TOKEN_MAX_PER_USER active tokens. Nothing is dropped to make room:
     * refusing is the only outcome the user can see, and silently revoking an
     * old token could break a working integration.
     *
     * 'token' is the only time the plaintext exists outside the caller's
     * response; it is never stored and never logged.
     */
    function mcpTokenCreate(PDO $pdo, int $userId, string $name, string $scopes, ?int $expiresAt = null, ?int $now = null): ?array
    {
        $now = $now ?? time();
        if ($userId <= 0) {
            return null;
        }
        $scope = mcpTokenNormaliseScope($scopes);
        if ($scope === null) {
            return null;
        }
        if (mcpTokenActiveCount($pdo, $userId, $now) >= MCP_TOKEN_MAX_PER_USER) {
            return null;
        }

        $name = mb_substr(trim($name), 0, 64);
        $token = mcpTokenGenerate();
        $nowStr = date('Y-m-d H:i:s', $now);
        $expiresStr = $expiresAt !== null ? date('Y-m-d H:i:s', $expiresAt) : null;

        $pdo->prepare('INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NULL)')
            ->execute([$userId, $name, mcpTokenHash($token), mcpTokenPrefixOf($token), $scope, $nowStr, $expiresStr]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'token' => $token,
            'token_prefix' => mcpTokenPrefixOf($token),
            'name' => $name,
            'scopes' => $scope,
            'created_at' => $nowStr,
            'expires_at' => $expiresStr,
        ];
    }
}

if (!function_exists('mcpTokenResolve')) {
    /**
     * The token a Bearer credential names, or null. Null covers every
     * rejection alike — malformed, unknown, revoked, expired, an account that
     * is no longer Pro, a suspended account, or the table simply not being
     * there — so a caller cannot tell them apart and neither can an attacker.
     *
     * The format is checked first, before any query. A successful resolve
     * touches last_used_at, at most once per MCP_TOKEN_TOUCH_SECONDS.
     */
    function mcpTokenResolve(PDO $pdo, ?string $token, ?int $now = null): ?array
    {
        $now = $now ?? time();
        $valid = mcpTokenValidate($token);
        if ($valid === null) {
            return null;
        }
        try {
            if (!mcpTokenAvailable($pdo)) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT id, pro_user_id, name, scopes, created_at, last_used_at, expires_at, revoked_at FROM mcp_access_tokens WHERE token_hash = ? LIMIT 1');
            $stmt->execute([mcpTokenHash($valid)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['revoked_at'] !== null) {
                return null;
            }
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= $now) {
                return null;
            }

            $userId = (int) $row['pro_user_id'];
            // Both live in config.php; a caller that has not loaded it cannot
            // be answered "yes" about entitlement, so it fails closed.
            if (!function_exists('proUserIsPro') || !proUserIsPro($userId)) {
                return null;
            }
            if (function_exists('proUserIsSuspended') && proUserIsSuspended($userId)) {
                return null;
            }

            $touched = $row['last_used_at'] === null || strtotime((string) $row['last_used_at']) <= $now - MCP_TOKEN_TOUCH_SECONDS;
            if ($touched) {
                $nowStr = date('Y-m-d H:i:s', $now);
                $pdo->prepare('UPDATE mcp_access_tokens SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at <= ?)')
                    ->execute([$nowStr, (int) $row['id'], date('Y-m-d H:i:s', $now - MCP_TOKEN_TOUCH_SECONDS)]);
                $row['last_used_at'] = $nowStr;
            }

            return [
                'id' => (int) $row['id'],
                'user_id' => $userId,
                'name' => (string) $row['name'],
                'scopes' => (string) $row['scopes'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
                'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            ];
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'MCP access token lookup failed', ['error' => $e->getMessage()]);
            }
            return null;
        }
    }
}

if (!function_exists('mcpTokenList')) {
    /**
     * An account's tokens, newest first, each with an 'expired' flag. No
     * secrets: token_hash is never returned, and token_prefix is the 12
     * characters the user already sees in the list. Revoked tokens are left
     * out — they are kept only for the audit and the 30-day sweep.
     */
    function mcpTokenList(PDO $pdo, int $userId, ?int $now = null): array
    {
        $now = $now ?? time();
        $stmt = $pdo->prepare('SELECT id, name, token_prefix, scopes, created_at, last_used_at, expires_at FROM mcp_access_tokens WHERE pro_user_id = ? AND revoked_at IS NULL ORDER BY id DESC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['expired'] = $row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= $now;
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('mcpTokenRevoke')) {
    /**
     * Revokes one of the account's own tokens. True when one was revoked;
     * false for someone else's id, an unknown id and an already-revoked token
     * alike, so nothing is revealed either way.
     */
    function mcpTokenRevoke(PDO $pdo, int $userId, int $tokenId, ?int $now = null): bool
    {
        if ($tokenId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = ? WHERE id = ? AND pro_user_id = ? AND revoked_at IS NULL');
        $stmt->execute([date('Y-m-d H:i:s', $now ?? time()), $tokenId, $userId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('mcpTokenRevokeAll')) {
    /**
     * Revokes every token of the account (password or email change, account
     * deletion, an admin suspension). Returns how many were revoked. Rows are
     * marked rather than deleted so the audit can still count them; the
     * 30-day sweep in cron/cleanup.php removes them.
     */
    function mcpTokenRevokeAll(PDO $pdo, int $userId, ?int $now = null): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = ? WHERE pro_user_id = ? AND revoked_at IS NULL');
        $stmt->execute([date('Y-m-d H:i:s', $now ?? time()), $userId]);
        return $stmt->rowCount();
    }
}

if (!function_exists('mcpTokenCleanup')) {
    /**
     * Removes revoked and expired rows older than the grace period, and rows
     * whose account no longer exists (whatever their state — an orphan can
     * never resolve, so there is nothing to wait for). Called from
     * cron/cleanup.php. Returns how many rows were removed.
     */
    function mcpTokenCleanup(PDO $pdo, ?int $now = null): int
    {
        $now = $now ?? time();
        $deadline = date('Y-m-d H:i:s', $now - MCP_TOKEN_SWEEP_DAYS * 86400);
        $stmt = $pdo->prepare('DELETE FROM mcp_access_tokens WHERE pro_user_id NOT IN (SELECT id FROM pro_users) OR (revoked_at IS NOT NULL AND revoked_at <= ?) OR (expires_at IS NOT NULL AND expires_at <= ?)');
        $stmt->execute([$deadline, $deadline]);
        return $stmt->rowCount();
    }
}

// ---------------------------------------------------------------------
// Fail-open wrapper for the removal paths. Uses the global $pdo.
// ---------------------------------------------------------------------

if (!function_exists('mcpTokensRevokeAllFor')) {
    /**
     * Every token of the account, called from pro_auth.php (password change,
     * email change, both undo links, account deletion), abuse_admin.php
     * (suspension) and cron/cleanup.php (an inactive account). Fail-open: a
     * missing table, or any database error, must never undo the password
     * change or the deletion that got us here. Returns how many were revoked.
     */
    function mcpTokensRevokeAllFor(int $userId): int
    {
        global $pdo;
        if ($userId <= 0 || !($pdo instanceof PDO) || !mcpTokenAvailable($pdo)) {
            return 0;
        }
        try {
            $count = mcpTokenRevokeAll($pdo, $userId);
            if ($count > 0 && function_exists('logMessage')) {
                logMessage('INFO', 'MCP access tokens revoked', ['user_id' => $userId, 'count' => $count]);
            }
            return $count;
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Could not revoke MCP access tokens', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return 0;
        }
    }
}
