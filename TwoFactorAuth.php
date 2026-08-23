<?php

declare(strict_types=1);

if (!defined('TEMPMAIL_APP')) {
    die('Direct access not permitted');
}

/**
 * TwoFactorAuth: TOTP (RFC 6238) core library for Pro password login.
 *
 * Covers secret generation/encryption, code verification, recovery codes and
 * rate-limit bookkeeping. No endpoints/UI live here — see
 * documentaion/2FA_DESIGN.md for the full design and the flows that consume
 * this class (§4-§6).
 *
 * Never log the encryption key, a TOTP secret, a generated/submitted code, or
 * a recovery code — only outcomes and identifiers (user_id, ip).
 */
final class TwoFactorAuth
{
    private const RECOVERY_CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const TOTP_PERIOD = 30;
    private const TOTP_DIGITS = 6;

    private static bool $schemaEnsured = false;

    /**
     * Idempotently creates the tables this class needs. Safe to call from
     * every public method that touches them.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pro_user_totp (
                user_id INT NOT NULL PRIMARY KEY,
                secret_enc TEXT NOT NULL,
                status ENUM('pending','active') NOT NULL DEFAULT 'pending',
                last_used_step BIGINT NULL DEFAULT NULL,
                confirmed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS pro_user_recovery_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS two_factor_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL DEFAULT NULL,
                ip VARCHAR(45) NOT NULL,
                success TINYINT(1) NOT NULL,
                attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_time (ip, attempt_at),
                INDEX idx_user_time (user_id, attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            self::$schemaEnsured = true;
        } catch (PDOException $e) {
            logMessage('ERROR', 'Failed to ensure 2FA schema: ' . $e->getMessage());
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Base32 (RFC 4648, no padding)
    // ---------------------------------------------------------------------

    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0; $i < strlen($binary); $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    /**
     * Tolerant of lowercase input, embedded whitespace and '=' padding.
     * Returns '' for an empty input or as soon as an invalid character is seen.
     */
    public static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $clean = strtoupper(preg_replace('/[\s=]+/', '', $b32) ?? '');
        if ($clean === '') {
            return '';
        }

        $bits = '';
        for ($i = 0; $i < strlen($clean); $i++) {
            $pos = strpos($alphabet, $clean[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue; // trailing padding bits from a non-multiple-of-8 length
            }
            $output .= chr(bindec($byte));
        }

        return $output;
    }

    // ---------------------------------------------------------------------
    // TOTP (RFC 6238) / HOTP (RFC 4226)
    // ---------------------------------------------------------------------

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    private static function hotp(string $secretBinary, int $counter): string
    {
        $counterBytes = pack('J', $counter); // 64-bit unsigned, big-endian
        $hash = hash_hmac('sha1', $counterBytes, $secretBinary, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $otp = $binary % (10 ** self::TOTP_DIGITS);
        return str_pad((string) $otp, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies a submitted code against a base32 TOTP secret within a step
     * window (default ±1, i.e. ±30s). Returns the matched time step (usable
     * as last_used_step for replay protection), or null if no step matched.
     */
    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::TOTP_DIGITS . '}$/', $normalized)) {
            return null;
        }

        $secretBinary = self::base32Decode($secret);
        if ($secretBinary === '') {
            return null;
        }

        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::TOTP_PERIOD);

        for ($delta = -abs($window); $delta <= abs($window); $delta++) {
            $step = $counter + $delta;
            if ($step < 0) {
                continue;
            }
            $expected = self::hotp($secretBinary, $step);
            if (hash_equals($expected, $normalized)) {
                return $step;
            }
        }

        return null;
    }

    public static function otpauthUri(string $secret, string $accountEmail): string
    {
        $issuer = 'TempMail (manjo.me)';
        $label = $issuer . ':' . $accountEmail;

        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::TOTP_DIGITS . '&period=' . self::TOTP_PERIOD;
    }

    // ---------------------------------------------------------------------
    // Encryption at rest (AES-256-GCM, no plaintext fallback)
    // ---------------------------------------------------------------------

    /**
     * @throws RuntimeException if TOTP_ENCRYPTION_KEY is missing or invalid.
     *         Never include the key or the secret in the exception message.
     */
    private static function getEncryptionKey(): string
    {
        $keyB64 = $_ENV['TOTP_ENCRYPTION_KEY'] ?? null;
        if (empty($keyB64) || !is_string($keyB64)) {
            logMessage('ERROR', 'TOTP_ENCRYPTION_KEY is not configured; refusing to encrypt/decrypt a TOTP secret');
            throw new RuntimeException('TOTP encryption key is not configured');
        }

        $key = base64_decode($keyB64, true);
        if ($key === false || strlen($key) !== 32) {
            logMessage('ERROR', 'TOTP_ENCRYPTION_KEY is not valid base64-encoded 32 bytes');
            throw new RuntimeException('TOTP encryption key is invalid');
        }

        return $key;
    }

    /**
     * @throws RuntimeException if the key is missing/invalid or encryption fails.
     */
    public static function encryptSecret(string $plain): string
    {
        $key = self::getEncryptionKey();
        $ivLen = openssl_cipher_iv_length('aes-256-gcm');
        $iv = random_bytes($ivLen);
        $tag = '';

        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            logMessage('ERROR', 'TOTP secret encryption failed');
            throw new RuntimeException('Failed to encrypt TOTP secret');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * Fails closed: returns null on any error (missing key, malformed input,
     * bad tag) instead of ever falling back to an unencrypted comparison.
     */
    public static function decryptSecret(string $stored): ?string
    {
        try {
            $key = self::getEncryptionKey();
        } catch (RuntimeException $e) {
            return null;
        }

        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return null;
        }

        $ivLen = openssl_cipher_iv_length('aes-256-gcm');
        $tagLen = 16;
        if (strlen($raw) < $ivLen + $tagLen) {
            return null;
        }

        $iv = substr($raw, 0, $ivLen);
        $tag = substr($raw, $ivLen, $tagLen);
        $ciphertext = substr($raw, $ivLen + $tagLen);

        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    // ---------------------------------------------------------------------
    // Per-user enrollment / verification state (pro_user_totp)
    // ---------------------------------------------------------------------

    public static function isEnabledFor(int $userId): bool
    {
        global $pdo;
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare("SELECT 1 FROM pro_user_totp WHERE user_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function getState(int $userId): ?array
    {
        global $pdo;
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT status, confirmed_at, created_at, updated_at FROM pro_user_totp WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Creates or replaces the pending enrollment row for a user and returns
     * the plaintext secret plus its otpauth:// URI. Neither is persisted in
     * plaintext or logged.
     *
     * @throws RuntimeException if the user is unknown or the encryption key
     *         is missing/invalid.
     */
    public static function startEnrollment(int $userId): array
    {
        global $pdo;
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT email FROM pro_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        if (!$email) {
            throw new RuntimeException('Unknown pro user');
        }

        $secret = self::generateSecret();
        $secretEnc = self::encryptSecret($secret);

        $ins = $pdo->prepare('
            INSERT INTO pro_user_totp (user_id, secret_enc, status, last_used_step, confirmed_at, updated_at)
            VALUES (?, ?, ?, NULL, NULL, NOW())
            ON DUPLICATE KEY UPDATE
                secret_enc = VALUES(secret_enc),
                status = VALUES(status),
                last_used_step = NULL,
                confirmed_at = NULL,
                updated_at = NOW()
        ');
        $ins->execute([$userId, $secretEnc, 'pending']);

        logMessage('INFO', '2fa_enroll_started', ['user_id' => $userId]);

        return [
            'secret' => $secret,
            'otpauth_uri' => self::otpauthUri($secret, (string) $email),
        ];
    }

    /**
     * Confirms a pending enrollment with a submitted code. On success the row
     * becomes active and last_used_step is set to the matched step, so the
     * same code can never be replayed.
     */
    public static function activate(int $userId, string $code): bool
    {
        global $pdo;
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare("SELECT secret_enc, last_used_step FROM pro_user_totp WHERE user_id = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $secret = self::decryptSecret($row['secret_enc']);
        if ($secret === null) {
            return false;
        }

        $matchedStep = self::verifyCode($secret, $code);
        if ($matchedStep === null) {
            return false;
        }

        $lastUsedStep = $row['last_used_step'] !== null ? (int) $row['last_used_step'] : null;
        if ($lastUsedStep !== null && $matchedStep <= $lastUsedStep) {
            return false;
        }

        $upd = $pdo->prepare("UPDATE pro_user_totp SET status = 'active', confirmed_at = NOW(), last_used_step = ?, updated_at = NOW() WHERE user_id = ? AND status = 'pending'");
        $upd->execute([$matchedStep, $userId]);
        $activated = $upd->rowCount() > 0;

        if ($activated) {
            logMessage('INFO', '2fa_enabled', ['user_id' => $userId]);
        }

        return $activated;
    }

    /**
     * Verifies a code against a user's already-active 2FA (the login
     * challenge). Replay-protected the same way as activate(): a time step
     * that already matched is never accepted again.
     */
    public static function verifyForUser(int $userId, string $code): bool
    {
        global $pdo;
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare("SELECT secret_enc, last_used_step FROM pro_user_totp WHERE user_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $secret = self::decryptSecret($row['secret_enc']);
        if ($secret === null) {
            return false;
        }

        $matchedStep = self::verifyCode($secret, $code);
        if ($matchedStep === null) {
            return false;
        }

        $lastUsedStep = $row['last_used_step'] !== null ? (int) $row['last_used_step'] : null;
        if ($lastUsedStep !== null && $matchedStep <= $lastUsedStep) {
            return false;
        }

        $upd = $pdo->prepare("UPDATE pro_user_totp SET last_used_step = ?, updated_at = NOW() WHERE user_id = ? AND status = 'active'");
        $upd->execute([$matchedStep, $userId]);

        return true;
    }

    /**
     * Removes 2FA entirely for a user: the TOTP row and all recovery codes.
     * Trusted devices are out of scope for this class.
     */
    public static function disable(int $userId): void
    {
        global $pdo;
        self::ensureSchema($pdo);

        $pdo->prepare('DELETE FROM pro_user_totp WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM pro_user_recovery_codes WHERE user_id = ?')->execute([$userId]);

        logMessage('INFO', '2fa_disabled', ['user_id' => $userId]);
    }

    // ---------------------------------------------------------------------
    // Recovery codes (pro_user_recovery_codes)
    // ---------------------------------------------------------------------

    /** Pure, DB-free: a single raw (unformatted, unhashed) recovery code. */
    public static function generateRecoveryCode(): string
    {
        $alphabet = self::RECOVERY_CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    public static function formatRecoveryCode(string $raw): string
    {
        return substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }

    /** Strips hyphens/whitespace and uppercases, for comparison against a stored hash. */
    public static function normalizeRecoveryCode(string $code): string
    {
        $stripped = str_replace('-', '', $code);
        $stripped = preg_replace('/\s+/', '', $stripped) ?? '';
        return strtoupper($stripped);
    }

    /**
     * Generates $count fresh recovery codes for a user, replacing any
     * existing ones. Returns the plaintext, hyphenated codes exactly once —
     * only their password_hash() is persisted.
     */
    public static function generateRecoveryCodes(int $userId, int $count = 10): array
    {
        global $pdo;
        self::ensureSchema($pdo);

        $plainCodes = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = self::generateRecoveryCode();
            $plainCodes[] = self::formatRecoveryCode($raw);
            $hashes[] = password_hash($raw, PASSWORD_DEFAULT);
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM pro_user_recovery_codes WHERE user_id = ?');
            $del->execute([$userId]);

            $ins = $pdo->prepare('INSERT INTO pro_user_recovery_codes (user_id, code_hash) VALUES (?, ?)');
            foreach ($hashes as $hash) {
                $ins->execute([$userId, $hash]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logMessage('ERROR', 'Failed to store 2FA recovery codes', ['user_id' => $userId]);
            throw $e;
        }

        logMessage('INFO', '2fa_recovery_codes_regenerated', ['user_id' => $userId]);

        return $plainCodes;
    }

    /** Consumes (marks used) one matching, unused recovery code for a user. */
    public static function consumeRecoveryCode(int $userId, string $code): bool
    {
        global $pdo;
        self::ensureSchema($pdo);

        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        $stmt = $pdo->prepare('SELECT id, code_hash FROM pro_user_recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (password_verify($normalized, $row['code_hash'])) {
                $upd = $pdo->prepare('UPDATE pro_user_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
                $upd->execute([$row['id']]);
                if ($upd->rowCount() > 0) {
                    logMessage('INFO', '2fa_recovery_code_used', ['user_id' => $userId]);
                    return true;
                }
                return false;
            }
        }

        return false;
    }

    public static function countUnusedRecoveryCodes(int $userId): int
    {
        global $pdo;
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pro_user_recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------------
    // Brute-force protection (two_factor_attempts)
    // ---------------------------------------------------------------------

    public static function recordAttempt(?int $userId, string $ip, bool $success): void
    {
        global $pdo;
        self::ensureSchema($pdo);

        try {
            $stmt = $pdo->prepare('INSERT INTO two_factor_attempts (user_id, ip, success) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $ip, $success ? 1 : 0]);
        } catch (Exception $e) {
            logMessage('ERROR', 'Failed to record 2FA attempt', ['error' => $e->getMessage()]);
            return;
        }

        if (!$success) {
            logMessage('INFO', '2fa_challenge_failed', ['user_id' => $userId, 'ip' => $ip]);
        }
    }

    /**
     * 5 failures / 15 min per user, 15 failures / 15 min per IP (§6 of the
     * design doc). Fails open (returns false) if the check itself errors, so
     * a DB hiccup can't lock everyone out.
     */
    public static function isChallengeBlocked(?int $userId, string $ip): bool
    {
        global $pdo;
        self::ensureSchema($pdo);

        $windowMinutes = 15;
        $maxUserFails = 5;
        $maxIpFails = 15;

        try {
            if ($userId !== null) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM two_factor_attempts WHERE user_id = ? AND success = 0 AND attempt_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
                $stmt->execute([$userId, $windowMinutes]);
                if ((int) $stmt->fetchColumn() >= $maxUserFails) {
                    logMessage('WARNING', '2fa_challenge_locked', ['user_id' => $userId, 'scope' => 'user']);
                    return true;
                }
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM two_factor_attempts WHERE ip = ? AND success = 0 AND attempt_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
            $stmt->execute([$ip, $windowMinutes]);
            if ((int) $stmt->fetchColumn() >= $maxIpFails) {
                logMessage('WARNING', '2fa_challenge_locked', ['ip' => $ip, 'scope' => 'ip']);
                if (function_exists('flagMaliciousActivity')) {
                    flagMaliciousActivity($ip, '2FA challenge rate limit exceeded');
                }
                return true;
            }
        } catch (Exception $e) {
            logMessage('ERROR', '2FA rate-limit check failed', ['error' => $e->getMessage()]);
            return false;
        }

        return false;
    }
}
