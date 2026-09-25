<?php

declare(strict_types=1);

/**
 * "Stay signed in": a signed-in session that survives the browser being
 * closed (table pro_remember_tokens, created by migrate_remember_tokens.php).
 *
 * Why it exists: the PHP session cookie is a browser-session cookie and the
 * session file is swept by the host's session.gc_maxlifetime (24 minutes by
 * default, and on shared hosting often by a system job we do not control).
 * Mobile browsers are routinely killed in the background when the user
 * switches app, so a signed-in visitor came back to a signed-out page. The
 * PHP session is left exactly as it was; next to it, a device the user chose
 * to keep signed in holds a long-lived `ms_stay` cookie, and when a request
 * arrives with no signed-in session but a valid cookie, the session is
 * re-established from it (proRememberRestoreSession(), called from
 * proSessionEndIfSuspended() in config.php, i.e. right after session_start()
 * on every page that trusts the session).
 *
 * The user picks how long: 1, 7 or 30 days (0 = only this visit, no cookie).
 * The period is sliding — every restore moves the expiry to now + that
 * period — but never past PRO_REMEMBER_MAX_AGE_DAYS after the sign-in that
 * created the token, after which the user signs in again.
 *
 * Token format and storage follow the 2FA trusted-device cookie
 * (TwoFactorAuth.php): the cookie is `selector.validator`, both from
 * random_bytes(); the database stores the selector and sha256(validator)
 * only. The validator is rotated on every restore. Because a page on a
 * resumed phone often fires several requests at once, the previous hash stays
 * valid for PRO_REMEMBER_ROTATION_GRACE seconds; a request that matches it is
 * let in without being handed a new cookie (the request that rotated already
 * set it). Rotation is a compare-and-set on the old hash, so two concurrent
 * restores cannot both rotate.
 *
 * A token is only ever created after a complete sign-in (after the 2FA code
 * when 2FA is on). Every token of an account is removed when its password is
 * changed or reset by an undo link, when its email address changes, and when
 * the account is deleted; the token of the current device is removed on
 * sign-out. A suspended or deleted account is never restored.
 *
 * The data functions take the PDO explicitly, compute times in PHP rather
 * than with NOW(), and do not require config.php, so
 * tests/remember_session_test.php runs them on SQLite. Everything that talks
 * HTTP (cookies, the session) uses the globals and is fail-open: without the
 * table, or on any database error, sign-in works exactly as before, only
 * without "stay signed in".
 */

if (!defined('PRO_REMEMBER_COOKIE')) {
    define('PRO_REMEMBER_COOKIE', 'ms_stay');
    // Hard ceiling from the sign-in that created a token, whatever the choice.
    define('PRO_REMEMBER_MAX_AGE_DAYS', 90);
    // How long the hash replaced by a rotation is still accepted.
    define('PRO_REMEMBER_ROTATION_GRACE', 120);
    // Tokens kept per account; creating one more drops the least recently used.
    define('PRO_REMEMBER_MAX_PER_USER', 20);
}

if (!function_exists('proRememberAllowedDays')) {
    /** The periods a user can choose, in days. 0 (only this visit) is not listed. */
    function proRememberAllowedDays(): array
    {
        return [1, 7, 30];
    }
}

if (!function_exists('proRememberNormaliseDays')) {
    /** Any submitted value to one of proRememberAllowedDays(), or 0. */
    function proRememberNormaliseDays($value): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return 0;
        }
        $days = (int) $value;
        return in_array($days, proRememberAllowedDays(), true) ? $days : 0;
    }
}

if (!function_exists('proRememberParseCookie')) {
    /** [selector, validator] from a cookie value, or null when malformed. */
    function proRememberParseCookie(string $value): ?array
    {
        if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $value, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }
}

if (!function_exists('proRememberAvailable')) {
    /** Is the table there? Cached per PDO for the life of the process. */
    function proRememberAvailable(PDO $pdo): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $cache)) {
            try {
                $pdo->query('SELECT id FROM pro_remember_tokens WHERE 1 = 0');
                $cache[$key] = true;
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

if (!function_exists('proRememberCreate')) {
    /**
     * Stores a new token for $userId, valid for $days, and returns
     * ['id', 'cookie_value', 'expires_at']. The cookie value is never stored
     * or logged. Afterwards the account's tokens beyond
     * PRO_REMEMBER_MAX_PER_USER, least recently used first, are removed.
     */
    function proRememberCreate(PDO $pdo, int $userId, int $days, string $label, ?int $now = null): array
    {
        $now = $now ?? time();
        $days = max(1, $days);
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $nowStr = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + $days * 86400);

        $pdo->prepare('INSERT INTO pro_remember_tokens (user_id, selector, validator_hash, days, label, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $selector, hash('sha256', $validator), $days, mb_substr($label, 0, 255), $nowStr, $nowStr, $expiresAt]);
        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT id FROM pro_remember_tokens WHERE user_id = ? ORDER BY last_used_at DESC, id DESC');
        $stmt->execute([$userId]);
        $overflow = array_slice($stmt->fetchAll(PDO::FETCH_COLUMN), PRO_REMEMBER_MAX_PER_USER);
        if ($overflow) {
            $del = $pdo->prepare('DELETE FROM pro_remember_tokens WHERE id = ?');
            foreach ($overflow as $oldId) {
                $del->execute([(int) $oldId]);
            }
        }

        return ['id' => $id, 'cookie_value' => $selector . '.' . $validator, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('proRememberVerify')) {
    /**
     * Checks a cookie value and, when it is the current one, rotates it and
     * slides the expiry. Returns ['id', 'user_id', 'days', 'cookie_value',
     * 'expires_at'] — cookie_value is the new value to set, or null when the
     * request matched the grace-period hash of a rotation another request
     * already made — or null when the cookie is malformed, unknown, expired
     * or wrong (never says which).
     */
    function proRememberVerify(PDO $pdo, string $cookieValue, ?int $now = null): ?array
    {
        $now = $now ?? time();
        $parts = proRememberParseCookie($cookieValue);
        if ($parts === null) {
            return null;
        }
        [$selector, $validator] = $parts;
        $nowStr = date('Y-m-d H:i:s', $now);

        $stmt = $pdo->prepare('SELECT id, user_id, validator_hash, prev_validator_hash, rotated_at, days, created_at, expires_at FROM pro_remember_tokens WHERE selector = ? AND expires_at > ? LIMIT 1');
        $stmt->execute([$selector, $nowStr]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $hash = hash('sha256', $validator);
        $result = [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'days' => (int) $row['days'],
            'cookie_value' => null,
            'expires_at' => (string) $row['expires_at'],
        ];

        $inGrace = function (array $r) use ($hash, $now): bool {
            return !empty($r['prev_validator_hash'])
                && !empty($r['rotated_at'])
                && hash_equals((string) $r['prev_validator_hash'], $hash)
                && $now - (int) strtotime((string) $r['rotated_at']) <= PRO_REMEMBER_ROTATION_GRACE;
        };

        if (!hash_equals((string) $row['validator_hash'], $hash)) {
            return $inGrace($row) ? $result : null;
        }

        $cap = (int) strtotime((string) $row['created_at']) + PRO_REMEMBER_MAX_AGE_DAYS * 86400;
        $expiresAt = date('Y-m-d H:i:s', min($now + max(1, (int) $row['days']) * 86400, $cap));
        $newValidator = bin2hex(random_bytes(32));
        $upd = $pdo->prepare('UPDATE pro_remember_tokens SET validator_hash = ?, prev_validator_hash = ?, rotated_at = ?, last_used_at = ?, expires_at = ? WHERE id = ? AND validator_hash = ?');
        $upd->execute([hash('sha256', $newValidator), $hash, $nowStr, $nowStr, $expiresAt, $row['id'], $hash]);
        if ($upd->rowCount() === 1) {
            $result['cookie_value'] = $selector . '.' . $newValidator;
            $result['expires_at'] = $expiresAt;
            return $result;
        }

        // A concurrent request rotated first; it made our hash the previous one.
        $stmt->execute([$selector, $nowStr]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && $inGrace($row)) ? $result : null;
    }
}

if (!function_exists('proRememberFindBySelector')) {
    /** The unexpired row a well-formed cookie names (not verified), or null. */
    function proRememberFindBySelector(PDO $pdo, string $cookieValue, ?int $now = null): ?array
    {
        $parts = proRememberParseCookie($cookieValue);
        if ($parts === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, user_id, days, expires_at FROM pro_remember_tokens WHERE selector = ? AND expires_at > ? LIMIT 1');
        $stmt->execute([$parts[0], date('Y-m-d H:i:s', $now ?? time())]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('proRememberDeleteBySelector')) {
    /** Removes the token a cookie names, whoever it belongs to (sign-out). */
    function proRememberDeleteBySelector(PDO $pdo, string $cookieValue): void
    {
        $parts = proRememberParseCookie($cookieValue);
        if ($parts !== null) {
            $pdo->prepare('DELETE FROM pro_remember_tokens WHERE selector = ?')->execute([$parts[0]]);
        }
    }
}

if (!function_exists('proRememberList')) {
    /** An account's unexpired tokens, most recently used first. No secrets. */
    function proRememberList(PDO $pdo, int $userId, ?int $now = null): array
    {
        $stmt = $pdo->prepare('SELECT id, label, days, created_at, last_used_at, expires_at FROM pro_remember_tokens WHERE user_id = ? AND expires_at > ? ORDER BY last_used_at DESC, id DESC');
        $stmt->execute([$userId, date('Y-m-d H:i:s', $now ?? time())]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('proRememberRevoke')) {
    /** Removes one of the account's own tokens. True when one was removed. */
    function proRememberRevoke(PDO $pdo, int $userId, int $tokenId): bool
    {
        $stmt = $pdo->prepare('DELETE FROM pro_remember_tokens WHERE id = ? AND user_id = ?');
        $stmt->execute([$tokenId, $userId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('proRememberRevokeAll')) {
    /** Removes every token of the account, except $keepId when given. */
    function proRememberRevokeAll(PDO $pdo, int $userId, ?int $keepId = null): int
    {
        if ($keepId !== null) {
            $stmt = $pdo->prepare('DELETE FROM pro_remember_tokens WHERE user_id = ? AND id <> ?');
            $stmt->execute([$userId, $keepId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM pro_remember_tokens WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
        return $stmt->rowCount();
    }
}

if (!function_exists('proRememberCleanup')) {
    /** Removes expired tokens. Called from cron/cleanup.php. */
    function proRememberCleanup(PDO $pdo, ?int $now = null): int
    {
        $stmt = $pdo->prepare('DELETE FROM pro_remember_tokens WHERE expires_at <= ?');
        $stmt->execute([date('Y-m-d H:i:s', $now ?? time())]);
        return $stmt->rowCount();
    }
}

// ---------------------------------------------------------------------
// HTTP side: cookie and session. Fail-open, uses the global $pdo.
// ---------------------------------------------------------------------

if (!function_exists('proRememberSetCookie')) {
    function proRememberSetCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(PRO_REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => function_exists('appCookieSecure') ? appCookieSecure() : true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[PRO_REMEMBER_COOKIE] = $value;
    }
}

if (!function_exists('proRememberClearCookie')) {
    function proRememberClearCookie(): void
    {
        if (isset($_COOKIE[PRO_REMEMBER_COOKIE])) {
            proRememberSetCookie('', time() - 42000);
            unset($_COOKIE[PRO_REMEMBER_COOKIE]);
        }
    }
}

if (!function_exists('proRememberCookieValue')) {
    function proRememberCookieValue(): string
    {
        $value = $_COOKIE[PRO_REMEMBER_COOKIE] ?? '';
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('proRememberCurrentToken')) {
    /** The unexpired token this browser's cookie names, when it is $userId's. */
    function proRememberCurrentToken(int $userId): ?array
    {
        global $pdo;
        $cookie = proRememberCookieValue();
        if ($cookie === '' || !($pdo instanceof PDO) || !proRememberAvailable($pdo)) {
            return null;
        }
        try {
            $row = proRememberFindBySelector($pdo, $cookie);
            return ($row && (int) $row['user_id'] === $userId) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('proRememberForgetCurrent')) {
    /** Sign-out on this device: removes the token its cookie names, and the cookie. */
    function proRememberForgetCurrent(): void
    {
        global $pdo;
        $cookie = proRememberCookieValue();
        if ($cookie === '') {
            return;
        }
        try {
            if ($pdo instanceof PDO && proRememberAvailable($pdo)) {
                proRememberDeleteBySelector($pdo, $cookie);
            }
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Could not remove stay-signed-in token', ['error' => $e->getMessage()]);
            }
        }
        proRememberClearCookie();
    }
}

if (!function_exists('proRememberIssue')) {
    /**
     * Called right after a complete sign-in, or from the profile when the
     * user changes the period for this device. Replaces whatever token this
     * browser held with one for $userId valid for $days; $days = 0 (only this
     * visit) leaves no token and no cookie. Returns the token id, or null.
     */
    function proRememberIssue(int $userId, int $days): ?int
    {
        global $pdo;
        proRememberForgetCurrent();
        $days = proRememberNormaliseDays($days);
        if ($days === 0 || $userId <= 0 || !($pdo instanceof PDO) || !proRememberAvailable($pdo)) {
            return null;
        }
        try {
            if (!class_exists('TwoFactorAuth')) {
                require_once __DIR__ . '/TwoFactorAuth.php';
            }
            $label = TwoFactorAuth::summarizeUserAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $token = proRememberCreate($pdo, $userId, $days, $label);
            proRememberSetCookie($token['cookie_value'], (int) strtotime($token['expires_at']));
            if (function_exists('logMessage')) {
                logMessage('INFO', 'Stay-signed-in token created', ['user_id' => $userId, 'days' => $days]);
            }
            return $token['id'];
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Could not create stay-signed-in token', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return null;
        }
    }
}

if (!function_exists('proRememberRevokeAllFor')) {
    /**
     * Every token of the account (password or email change, account
     * deletion). Fail-open; returns how many were removed.
     */
    function proRememberRevokeAllFor(int $userId): int
    {
        global $pdo;
        if ($userId <= 0 || !($pdo instanceof PDO) || !proRememberAvailable($pdo)) {
            return 0;
        }
        try {
            $count = proRememberRevokeAll($pdo, $userId);
            if ($count > 0 && function_exists('logMessage')) {
                logMessage('INFO', 'Stay-signed-in tokens revoked', ['user_id' => $userId, 'count' => $count]);
            }
            return $count;
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Could not revoke stay-signed-in tokens', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return 0;
        }
    }
}

if (!function_exists('proRememberRestoreSession')) {
    /**
     * Re-establishes a signed-in session from the `ms_stay` cookie when the
     * session has none. Needs an active session and unsent headers (it
     * regenerates the session id and may set the rotated cookie), so it does
     * nothing when called after output. Returns true when it signed the
     * visitor in.
     */
    function proRememberRestoreSession(): bool
    {
        global $pdo;
        if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['pro_user_id']) || headers_sent()) {
            return false;
        }
        $cookie = proRememberCookieValue();
        if ($cookie === '' || !($pdo instanceof PDO) || !proRememberAvailable($pdo)) {
            return false;
        }

        try {
            $token = proRememberVerify($pdo, $cookie);
            if ($token === null) {
                proRememberClearCookie();
                return false;
            }
            $userId = $token['user_id'];

            $exists = $pdo->prepare('SELECT id FROM pro_users WHERE id = ? LIMIT 1');
            $exists->execute([$userId]);
            $suspended = function_exists('proUserIsSuspended') && proUserIsSuspended($userId);
            if (!$exists->fetchColumn() || $suspended) {
                proRememberRevoke($pdo, $userId, $token['id']);
                proRememberClearCookie();
                if (function_exists('logMessage')) {
                    logMessage('INFO', 'Stay-signed-in token refused: account ' . ($suspended ? 'suspended' : 'gone'), ['user_id' => $userId]);
                }
                return false;
            }

            if ($token['cookie_value'] !== null) {
                proRememberSetCookie($token['cookie_value'], (int) strtotime($token['expires_at']));
            }

            session_regenerate_id(true);
            unset($_SESSION['pending_2fa']);
            $_SESSION['pro_user_id'] = $userId;
            $_SESSION['pro_user_email'] = function_exists('proUserEmail') ? (string) (proUserEmail($pdo, $userId) ?? '') : '';
            $_SESSION['pro_login_method'] = 'remember';

            // Same bookkeeping as recordProUserLogin() in pro_auth.php (which is
            // not loaded here): a device kept signed in is an active account.
            try {
                if (function_exists('tableHasColumn') && tableHasColumn('pro_users', 'last_login_at')) {
                    $sql = tableHasColumn('pro_users', 'inactivity_warned_at')
                        ? 'UPDATE pro_users SET last_login_at = NOW(), inactivity_warned_at = NULL WHERE id = ?'
                        : 'UPDATE pro_users SET last_login_at = NOW() WHERE id = ?';
                    $pdo->prepare($sql)->execute([$userId]);
                }
            } catch (Throwable $e) {
                // fail-open, as in recordProUserLogin()
            }

            if (function_exists('logMessage')) {
                logMessage('INFO', 'Session restored from stay-signed-in token', ['user_id' => $userId]);
            }
            return true;
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Stay-signed-in restore failed', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }
}
