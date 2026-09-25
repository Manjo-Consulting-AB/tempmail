<?php

declare(strict_types=1);

/**
 * Users' registered email addresses at rest (pro_users.email,
 * paddle_customers.email).
 *
 * Two derived values are stored next to the address:
 *
 *   email_enc   AES-256-GCM ciphertext of the address exactly as written to
 *               the plaintext column, "v1:" . base64(nonce(12) . tag(16) .
 *               ciphertext), key = sha256(PII_ENCRYPTION_KEY). GCM is
 *               authenticated, so a tampered or truncated value fails to
 *               decrypt instead of producing a wrong address. Decrypted only
 *               to send mail to the address or show it to its owner/admin.
 *   email_hash  a blind index: HMAC-SHA256 (64 lowercase hex) of the
 *               normalised address (trim + lowercase) under a sub-key of
 *               PII_INDEX_KEY. Every lookup by address will go through it.
 *
 * Both keys must be at least 32 characters. NEITHER KEY MAY EVER BE ROTATED
 * without a re-encryption script: a new PII_ENCRYPTION_KEY makes every
 * email_enc undecryptable, a new PII_INDEX_KEY makes every email_hash miss,
 * so nobody could log in or be mailed.
 *
 * Phase A: the columns are dual-written next to the plaintext column.
 * Phase B1 (now): every lookup by address goes through email_hash
 * (piiEmailLookupHash()) and every place that needs the address decrypts
 * email_enc (proUserEmail() / piiEmailOpen()). Nothing reads the plaintext
 * column any more, but it is still written, so reverting B1 restores the
 * old reads. Reads fail closed: without keys a lookup finds nothing and a
 * decryption gives null, each with a logged ERROR, so no mail is ever sent
 * to a garbage address. Writes still store the plaintext when keys are
 * missing (piiEmailWriteFields()), but account creation refuses
 * (piiEmailRequireKeys()), since such an account could never be found.
 *
 * Normalisation is trim + lowercase only, like email_log_ref.php. The trial's
 * canonical form (pro_trial.php: drop +tags and dots) deliberately merges
 * distinct addresses and must not be reused here, where the index has to
 * identify exactly one account.
 *
 * Dependency-free (no config.php, no DB connection of its own) so tests can
 * load it on its own; every function is function_exists-guarded. The two
 * DB-facing helpers at the end use tableHasColumn()/logMessage() only when
 * they exist.
 */

if (!function_exists('piiRawKey')) {
    /** The raw key from the environment ('' when unset). */
    function piiRawKey(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('piiEncryptionKey')) {
    /** 32-byte AES key derived from PII_ENCRYPTION_KEY, or null when it is missing or shorter than 32 characters. */
    function piiEncryptionKey(?string $rawKey = null): ?string
    {
        $rawKey = $rawKey ?? piiRawKey('PII_ENCRYPTION_KEY');
        return strlen($rawKey) >= 32 ? hash('sha256', $rawKey, true) : null;
    }
}

if (!function_exists('piiIndexKey')) {
    /**
     * HMAC key for the blind index, derived from PII_INDEX_KEY, or null when
     * it is missing or shorter than 32 characters. A sub-key, so an index
     * value can never equal a pro_trial_claims hash or an emailLogRef() even
     * if an operator reuses the same raw key for all three.
     */
    function piiIndexKey(?string $rawKey = null): ?string
    {
        $rawKey = $rawKey ?? piiRawKey('PII_INDEX_KEY');
        return strlen($rawKey) >= 32 ? hash_hmac('sha256', 'pii-email-index', $rawKey, true) : null;
    }
}

if (!function_exists('piiKeysConfigured')) {
    /** True when both keys are usable. */
    function piiKeysConfigured(?string $encRawKey = null, ?string $indexRawKey = null): bool
    {
        return piiEncryptionKey($encRawKey) !== null && piiIndexKey($indexRawKey) !== null;
    }
}

if (!function_exists('piiEmailNormalize')) {
    /** Trimmed, lowercased address; null when nothing is left. */
    function piiEmailNormalize(string $email): ?string
    {
        $normalized = strtolower(trim($email));
        return $normalized === '' ? null : $normalized;
    }
}

if (!function_exists('piiEmailHash')) {
    /** Blind index of $email (64 lowercase hex), or null with no key or an empty address. */
    function piiEmailHash(string $email, ?string $rawKey = null): ?string
    {
        $key = piiIndexKey($rawKey);
        $normalized = piiEmailNormalize($email);
        if ($key === null || $normalized === null) {
            return null;
        }
        return hash_hmac('sha256', $normalized, $key);
    }
}

if (!function_exists('piiEmailEncrypt')) {
    /** Encrypted form of $email ("v1:..."), or null with no key, an empty address or a failure. */
    function piiEmailEncrypt(string $email, ?string $rawKey = null): ?string
    {
        $key = piiEncryptionKey($rawKey);
        if ($key === null || $email === '') {
            return null;
        }
        $nonce = random_bytes(12);
        $tag = '';
        // The AAD binds a value to its purpose: an email_enc cannot be
        // replayed as some other encrypted field under the same key.
        $cipher = openssl_encrypt($email, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'pii-email', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            return null;
        }
        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }
}

if (!function_exists('piiEmailDecrypt')) {
    /** The address, or null with no key or a value that does not decrypt (tampered, wrong key, unknown format). */
    function piiEmailDecrypt(?string $stored, ?string $rawKey = null): ?string
    {
        $key = piiEncryptionKey($rawKey);
        if ($key === null || $stored === null || strncmp($stored, 'v1:', 3) !== 0) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'pii-email');
        return $plain === false ? null : $plain;
    }
}

if (!function_exists('piiEmailFields')) {
    /**
     * ['email_enc' => ..., 'email_hash' => ...] for $email, or null when
     * either key is missing, the address is empty, or the ciphertext does not
     * decrypt back to $email (never write a value that would not open again).
     */
    function piiEmailFields(string $email, ?string $encRawKey = null, ?string $indexRawKey = null): ?array
    {
        $hash = piiEmailHash($email, $indexRawKey);
        $enc = piiEmailEncrypt($email, $encRawKey);
        if ($hash === null || $enc === null || piiEmailDecrypt($enc, $encRawKey) !== $email) {
            return null;
        }
        return ['email_enc' => $enc, 'email_hash' => $hash];
    }
}

if (!function_exists('piiEmailWarnMissingKeys')) {
    /**
     * Logs, once per request, that an address was stored without its
     * encrypted form because the keys are not configured. $log receives
     * (level, message, context); it defaults to logMessage() when defined.
     */
    function piiEmailWarnMissingKeys(string $table, ?callable $log = null): void
    {
        static $warned = false;
        if ($warned) {
            return;
        }
        $warned = true;
        if ($log === null && function_exists('logMessage')) {
            $log = 'logMessage';
        }
        if ($log !== null) {
            $log('WARNING', 'PII_ENCRYPTION_KEY / PII_INDEX_KEY missing or shorter than 32 characters: email stored without email_enc/email_hash', ['table' => $table]);
        }
    }
}

if (!function_exists('piiEmailColumnsExist')) {
    /**
     * Whether $table has both email_enc and email_hash, via the codebase's
     * tableHasColumn() (false when that is not defined). Cached per table,
     * so a request asks information_schema once.
     */
    function piiEmailColumnsExist(string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = function_exists('tableHasColumn')
                && tableHasColumn($table, 'email_enc')
                && tableHasColumn($table, 'email_hash');
        }
        return $cache[$table];
    }
}

if (!function_exists('piiEmailWriteFields')) {
    /**
     * The extra columns to write alongside $table.email = $email:
     *   - [] when the columns do not exist yet (deploy order does not matter);
     *   - the encrypted pair when they exist and the keys are configured;
     *   - both set to null when they exist but no pair could be made (keys
     *     missing: a WARNING once per request). Null, not "leave as is": on an
     *     UPDATE of the address the old pair would otherwise still describe
     *     the old address. migrate_email_encryption.php backfills null rows.
     * A missing key therefore never breaks a registration or an email change.
     */
    function piiEmailWriteFields(string $table, string $email): array
    {
        if (!piiEmailColumnsExist($table)) {
            return [];
        }
        $fields = piiEmailFields($email);
        if ($fields === null) {
            if (!piiKeysConfigured()) {
                piiEmailWarnMissingKeys($table);
            }
            return ['email_enc' => null, 'email_hash' => null];
        }
        return $fields;
    }
}

if (!function_exists('proUserStoreEmailPii')) {
    /**
     * Writes email_enc/email_hash for one pro_users row whose email was just
     * written as $email (used right after an INSERT of that row, in the
     * same request). Fail-open: an error here is logged and the caller
     * carries on; such a row cannot be found by address until
     * migrate_email_encryption.php fills it, which check_email_encryption.php
     * reports.
     */
    function proUserStoreEmailPii(PDO $pdo, int $userId, string $email): bool
    {
        $fields = piiEmailWriteFields('pro_users', $email);
        if (($fields['email_hash'] ?? null) === null) {
            return false;   // no columns, or no keys: a fresh row's pair is already null
        }
        try {
            $stmt = $pdo->prepare('UPDATE pro_users SET email_enc = ?, email_hash = ? WHERE id = ?');
            $stmt->execute([$fields['email_enc'], $fields['email_hash'], $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'Could not store email_enc/email_hash', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }
}

if (!function_exists('piiLogError')) {
    /** ERROR through logMessage() when defined, else error_log(). Never pass an address in $context. */
    function piiLogError(string $message, array $context = []): void
    {
        if (function_exists('logMessage')) {
            logMessage('ERROR', $message, $context);
        } else {
            error_log($message . ($context ? ' ' . json_encode($context) : ''));
        }
    }
}

if (!function_exists('piiEmailRequireKeys')) {
    /**
     * True when both keys are configured; otherwise logs an ERROR naming
     * $context and returns false. For paths that create an account, which
     * without the blind index could never be found again.
     */
    function piiEmailRequireKeys(string $context): bool
    {
        if (piiKeysConfigured()) {
            return true;
        }
        piiLogError('PII_ENCRYPTION_KEY / PII_INDEX_KEY missing: ' . $context . ' refused');
        return false;
    }
}

if (!function_exists('piiEmailLookupHash')) {
    /**
     * The email_hash value to look $email up by, or null. Fail closed: bound
     * as a parameter, null matches no row ("email_hash = NULL" is never
     * true), so without PII_INDEX_KEY every lookup by address finds nothing,
     * and an ERROR is logged once per request. An empty address is just null.
     */
    function piiEmailLookupHash(string $email): ?string
    {
        static $logged = false;
        if (piiEmailNormalize($email) === null) {
            return null;
        }
        $hash = piiEmailHash($email);
        if ($hash === null && !$logged) {
            $logged = true;
            piiLogError('PII_INDEX_KEY missing or shorter than 32 characters: lookup by email address finds nothing');
        }
        return $hash;
    }
}

if (!function_exists('piiEmailOpen')) {
    /**
     * The address in $stored (an email_enc value), or null with an ERROR
     * carrying $logContext (a user_id or customer_id, never an address) when
     * it is missing or does not decrypt. Callers must treat null as "no
     * address": never mail it, never substitute something else.
     */
    function piiEmailOpen(?string $stored, array $logContext = []): ?string
    {
        $email = piiEmailDecrypt($stored);
        if ($email === null || $email === '') {
            piiLogError($stored === null || $stored === ''
                ? 'email_enc missing: address unavailable'
                : 'email_enc could not be decrypted (PII_ENCRYPTION_KEY missing or wrong, or the value is damaged): address unavailable', $logContext);
            return null;
        }
        return $email;
    }
}

if (!function_exists('proUserEmail')) {
    /**
     * The registered address of pro_users row $userId, decrypted from
     * email_enc; null when there is no such row (not logged) or the value
     * cannot be decrypted (ERROR, see piiEmailOpen()).
     */
    function proUserEmail(PDO $pdo, int $userId): ?string
    {
        $stmt = $pdo->prepare('SELECT email_enc FROM pro_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false) {
            return null;
        }
        return piiEmailOpen($stored === null ? null : (string) $stored, ['user_id' => $userId]);
    }
}
