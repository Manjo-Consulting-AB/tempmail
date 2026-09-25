<?php

declare(strict_types=1);

/**
 * One-time e-mailed tokens: magic-link / verification tokens (login_tokens)
 * and the confirmation tokens for pending profile changes
 * (pending_profile_changes: email change, password change, account deletion).
 *
 * Two properties this file owns, so every writer and reader goes through it:
 *
 *   1. Tokens are hashed at rest. The e-mail carries the raw token; the token
 *      column holds hash('sha256', raw) (64 lowercase hex characters). A read
 *      of the table (backup leak, log viewer, SQL injection elsewhere) no
 *      longer yields working links.
 *
 *   2. Consumption is atomic. A token is claimed with
 *      UPDATE ... SET used = 1 WHERE id = ? AND used = 0 and counts as
 *      consumed only when that UPDATE changed exactly one row, so two
 *      concurrent requests with the same token cannot both succeed.
 *      Expiry stays a PHP-side comparison because expires_at is written with
 *      PHP date(); comparing it against MySQL NOW() would mix two clocks.
 *
 * Transition (remove after deploy + 2 hours): rows written before this change
 * hold the raw token. The longest-lived token is 2 hours (pending profile
 * changes; magic links are 30 minutes), so after deploy + 2h no legacy row can
 * still be valid, and the raw fallback in authTokenLookupValues() can be
 * dropped. The fallback matches only 48-character input, the length every
 * legacy token has, so submitting a 64-character stored hash as if it were a
 * token never matches that hash row.
 *
 * Deliberately does not require config.php: every function takes an explicit
 * PDO so tests/login_token_test.php runs it on SQLite. Every function is
 * function_exists-guarded so a repeat require cannot redeclare it.
 * pii_crypto.php (equally dependency-free) decrypts the account address.
 */

require_once __DIR__ . '/pii_crypto.php';

if (!function_exists('authTokenIsValidFormat')) {
    /** Raw token format: hex string, 48-96 characters. */
    function authTokenIsValidFormat(string $token): bool
    {
        return strlen($token) >= 48 && strlen($token) <= 96
            && preg_match('/^[a-f0-9]+$/i', $token) === 1;
    }
}

if (!function_exists('authTokenHash')) {
    /** The value stored at rest for a raw token. */
    function authTokenHash(string $token): string
    {
        return hash('sha256', strtolower($token));
    }
}

if (!function_exists('authTokenGenerate')) {
    /** A fresh raw token: $length hex characters (default 48). */
    function authTokenGenerate(int $length = 48): string
    {
        return bin2hex(random_bytes(intdiv($length, 2)));
    }
}

if (!function_exists('authTokenColumnHoldsHash')) {
    /**
     * Whether $table.token can hold a 64-character hash. The schema is not in
     * the repository, so this is checked rather than assumed: on MySQL a
     * column narrower than 64 characters would reject (strict mode) or
     * silently truncate (non-strict) the hash and break every link. When the
     * width cannot be established the answer is false, and the raw token is
     * stored as before - lookups accept both, so either way links work.
     * SQLite does not enforce VARCHAR widths, so there the answer is true.
     */
    function authTokenColumnHoldsHash(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (!in_array($table, ['login_tokens', 'pending_profile_changes'], true)) {
            return false;
        }
        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $holds = false;
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $holds = true;
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare(
                    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'token'"
                );
                $stmt->execute([$table]);
                $width = $stmt->fetchColumn();
                $holds = $width !== false && $width !== null && (int) $width >= 64;
            }
        } catch (Throwable $e) {
            $holds = false;
        }
        $cache[$key] = $holds;
        return $holds;
    }
}

if (!function_exists('authTokenStoredValue')) {
    /** What to write into $table.token for a freshly generated raw token. */
    function authTokenStoredValue(PDO $pdo, string $table, string $token): string
    {
        return authTokenColumnHoldsHash($pdo, $table) ? authTokenHash($token) : $token;
    }
}

if (!function_exists('authTokenLookupValues')) {
    /**
     * The two values to match the token column against (WHERE token IN (?, ?)):
     * the hash, and - for 48-character input only - the raw token, which is how
     * legacy rows and rows in a column too narrow for a hash are stored. For any
     * other length both values are the hash, so a stored hash (64 characters)
     * submitted as a token cannot match itself.
     */
    function authTokenLookupValues(string $token): array
    {
        $hash = authTokenHash($token);
        return [$hash, strlen($token) === 48 ? $token : $hash];
    }
}

if (!function_exists('authTokenIsExpired')) {
    /** expires_at is written with PHP date(), so it is compared in PHP time. */
    function authTokenIsExpired($expiresAt): bool
    {
        $ts = strtotime((string) $expiresAt);
        return $ts === false || $ts < time();
    }
}

if (!function_exists('loginTokenCreate')) {
    /** Create a magic-link / verification token; returns the raw token for the e-mail. */
    function loginTokenCreate(PDO $pdo, int $userId, int $validMinutes = 30): string
    {
        $token = authTokenGenerate();
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validMinutes} minutes"));
        $stmt = $pdo->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, authTokenStoredValue($pdo, 'login_tokens', $token), $expiresAt]);
        return $token;
    }
}

if (!function_exists('loginTokenConsume')) {
    /**
     * Verify and consume a magic-link token in one step. Returns
     * ['user_id' => ..., 'email' => ...] when this call claimed a valid,
     * unused, unexpired token, otherwise null.
     */
    function loginTokenConsume(PDO $pdo, string $token): ?array
    {
        if (!authTokenIsValidFormat($token)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT lt.id, lt.user_id, lt.expires_at, lt.used, pu.email_enc
               FROM login_tokens lt JOIN pro_users pu ON lt.user_id = pu.id
              WHERE lt.token IN (?, ?) LIMIT 1"
        );
        $stmt->execute(authTokenLookupValues($token));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['used'] || authTokenIsExpired($row['expires_at'])) {
            return null;
        }
        // Atomic claim: only the request whose UPDATE flips used 0 -> 1 wins.
        $upd = $pdo->prepare("UPDATE login_tokens SET used = 1 WHERE id = ? AND used = 0");
        $upd->execute([$row['id']]);
        if ($upd->rowCount() !== 1) {
            return null;
        }
        // The address is only session state (display, the To: of account
        // mails). A token proves inbox access, so the login stands even if
        // the address cannot be decrypted; 'email' is then '' (ERROR logged).
        $email = piiEmailOpen($row['email_enc'] === null ? null : (string) $row['email_enc'], ['user_id' => (int) $row['user_id']]);
        return ['user_id' => $row['user_id'], 'email' => $email ?? ''];
    }
}

if (!function_exists('pendingChangeFindByToken')) {
    /**
     * The pending_profile_changes row for a raw token, or null for a malformed
     * or unknown token. Does not check used/expiry - callers report those
     * separately - and does not claim; see pendingChangeClaim().
     */
    function pendingChangeFindByToken(PDO $pdo, string $token): ?array
    {
        if (!authTokenIsValidFormat($token)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT id, user_id, action, data, expires_at, used
               FROM pending_profile_changes WHERE token IN (?, ?) LIMIT 1"
        );
        $stmt->execute(authTokenLookupValues($token));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('pendingChangeClaim')) {
    /** Atomically mark a pending change used; true only for the request that claimed it. */
    function pendingChangeClaim(PDO $pdo, int $id): bool
    {
        $claim = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ? AND used = 0");
        $claim->execute([$id]);
        return $claim->rowCount() === 1;
    }
}

if (!function_exists('pendingChangeStoredToken')) {
    /** What to write into pending_profile_changes.token for a raw token. */
    function pendingChangeStoredToken(PDO $pdo, string $token): string
    {
        return authTokenStoredValue($pdo, 'pending_profile_changes', $token);
    }
}
