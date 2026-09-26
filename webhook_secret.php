<?php
/**
 * Webhook signing secrets at rest (pro_webhooks.secret).
 *
 * Encrypted with AES-256-GCM under WEBHOOKS_KEY, stored as
 * "v2:" . base64(nonce(12) . tag(16) . ciphertext). GCM is authenticated, so
 * a tampered or truncated value fails to decrypt instead of producing a wrong
 * secret. Values written before this format (AES-256-CBC, base64(iv . ct),
 * no prefix) are still read.
 *
 * Fail closed: with no key, nothing is encrypted (the caller refuses to store
 * the secret) and nothing is decrypted (the caller must not sign). The old
 * behaviour - storing and using the plaintext when WEBHOOKS_KEY was missing -
 * is gone.
 *
 * Dependency-free (no config.php, no DB) so tests can load it on its own;
 * every function is function_exists-guarded.
 */

if (!function_exists('webhookSecretKey')) {
    /** 32-byte key derived from WEBHOOKS_KEY, or null when it is not set. */
    function webhookSecretKey(?string $rawKey = null): ?string
    {
        $rawKey = $rawKey ?? (string) ($_ENV['WEBHOOKS_KEY'] ?? getenv('WEBHOOKS_KEY') ?: '');
        return $rawKey !== '' ? hash('sha256', $rawKey, true) : null;
    }
}

if (!function_exists('webhookSecretEncrypt')) {
    /** Encrypted form of $plaintext, or null when there is no key or it fails. */
    function webhookSecretEncrypt(string $plaintext, ?string $rawKey = null): ?string
    {
        $key = webhookSecretKey($rawKey);
        if ($key === null || $plaintext === '') {
            return null;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            return null;
        }
        return 'v2:' . base64_encode($nonce . $tag . $cipher);
    }
}

if (!function_exists('webhookSecretDecrypt')) {
    /** Plaintext secret, or null when there is no key or it cannot be decrypted. */
    function webhookSecretDecrypt(?string $stored, ?string $rawKey = null): ?string
    {
        $key = webhookSecretKey($rawKey);
        if ($key === null || $stored === null || $stored === '') {
            return null;
        }
        if (strncmp($stored, 'v2:', 3) === 0) {
            $raw = base64_decode(substr($stored, 3), true);
            if ($raw === false || strlen($raw) <= 28) {
                return null;
            }
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $plain === false ? null : $plain;
        }
        // Legacy AES-256-CBC: base64(iv(16) . ciphertext).
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain === false ? null : $plain;
    }
}

/*
 * Credentials inside a hook's config JSON (pro_webhooks.config). A Pushover
 * hook's `token` (the application token) and `user` (the user key) let anyone
 * who holds them push notifications to the owner's devices, so they are stored
 * encrypted like the signing secret above, one value at a time, in the same
 * "v2:" format. The rest of the config (device, sound, priority, ...) stays
 * readable. Values written before this existed are plaintext and still work.
 *
 * A generic hook's `headers` object (ImapProcessor::CONFIG_HEADERS_KEY - the
 * string is duplicated here rather than depending on that class, since this
 * file is deliberately dependency-free) is the same kind of thing: its whole
 * reason to exist is credentials such as {"Authorization": "Bearer ..."}.
 * Header *names* stay readable (they are not secret and dispatch needs them
 * to build the request); each header *value* is sealed on its own, in the
 * same "v2:" format, same as a flat credential above.
 */

if (!function_exists('webhookConfigSensitiveKeys')) {
    /** Config keys that hold credentials for a hook of this kind. */
    function webhookConfigSensitiveKeys(string $kind): array
    {
        return $kind === 'pushover' ? ['token', 'user'] : [];
    }
}

if (!function_exists('WEBHOOK_CONFIG_HEADERS_KEY')) {
    /** Mirrors ImapProcessor::CONFIG_HEADERS_KEY without requiring that class. */
    function WEBHOOK_CONFIG_HEADERS_KEY(): string
    {
        return 'headers';
    }
}

if (!function_exists('webhookConfigIsSealed')) {
    function webhookConfigIsSealed($value): bool
    {
        return is_string($value) && strncmp($value, 'v2:', 3) === 0;
    }
}

if (!function_exists('webhookConfigSeal')) {
    /**
     * $cfg with its credentials encrypted, or null when a credential could not
     * be encrypted (no WEBHOOKS_KEY) - the caller must then refuse to store it.
     * Already-sealed values are left as they are, so this is idempotent.
     */
    function webhookConfigSeal(array $cfg, string $kind, ?string $rawKey = null): ?array
    {
        foreach (webhookConfigSensitiveKeys($kind) as $key) {
            if (!isset($cfg[$key]) || !is_scalar($cfg[$key]) || (string) $cfg[$key] === '' || webhookConfigIsSealed($cfg[$key])) {
                continue;
            }
            $sealed = webhookSecretEncrypt((string) $cfg[$key], $rawKey);
            if ($sealed === null) {
                return null;
            }
            $cfg[$key] = $sealed;
        }
        $headersKey = WEBHOOK_CONFIG_HEADERS_KEY();
        if (isset($cfg[$headersKey]) && is_array($cfg[$headersKey])) {
            foreach ($cfg[$headersKey] as $name => $value) {
                if (!is_scalar($value) || (string) $value === '' || webhookConfigIsSealed($value)) {
                    continue;
                }
                $sealed = webhookSecretEncrypt((string) $value, $rawKey);
                if ($sealed === null) {
                    return null;
                }
                $cfg[$headersKey][$name] = $sealed;
            }
        }
        return $cfg;
    }
}

if (!function_exists('webhookConfigOpen')) {
    /**
     * $cfg with its credentials decrypted for sending. Throws when a sealed
     * value cannot be decrypted: a delivery must fail (and be retried) rather
     * than send the ciphertext as the credential. Plaintext passes through.
     */
    function webhookConfigOpen(array $cfg, string $kind, ?string $rawKey = null): array
    {
        foreach (webhookConfigSensitiveKeys($kind) as $key) {
            if (!webhookConfigIsSealed($cfg[$key] ?? null)) {
                continue;
            }
            $plain = webhookSecretDecrypt($cfg[$key], $rawKey);
            if ($plain === null) {
                throw new RuntimeException('Webhook credential "' . $key . '" could not be decrypted');
            }
            $cfg[$key] = $plain;
        }
        $headersKey = WEBHOOK_CONFIG_HEADERS_KEY();
        if (isset($cfg[$headersKey]) && is_array($cfg[$headersKey])) {
            foreach ($cfg[$headersKey] as $name => $value) {
                if (!webhookConfigIsSealed($value)) {
                    continue;
                }
                $plain = webhookSecretDecrypt($value, $rawKey);
                if ($plain === null) {
                    throw new RuntimeException('Webhook header "' . (string) $name . '" could not be decrypted');
                }
                $cfg[$headersKey][$name] = $plain;
            }
        }
        return $cfg;
    }
}

if (!function_exists('webhookConfigMask')) {
    /**
     * $cfg for display: each credential becomes "••••" plus its last four
     * characters, so the owner can recognise it but it cannot be read back
     * out of the page (an XSS or a hijacked session gets no usable value).
     */
    function webhookConfigMask(array $cfg, string $kind, ?string $rawKey = null): array
    {
        foreach (webhookConfigSensitiveKeys($kind) as $key) {
            if (!isset($cfg[$key]) || !is_scalar($cfg[$key]) || (string) $cfg[$key] === '') {
                continue;
            }
            $plain = webhookConfigIsSealed($cfg[$key]) ? webhookSecretDecrypt($cfg[$key], $rawKey) : (string) $cfg[$key];
            $cfg[$key] = ($plain !== null && strlen($plain) > 8) ? '••••' . substr($plain, -4) : '••••';
        }
        $headersKey = WEBHOOK_CONFIG_HEADERS_KEY();
        if (isset($cfg[$headersKey]) && is_array($cfg[$headersKey])) {
            foreach ($cfg[$headersKey] as $name => $value) {
                if (!is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $plain = webhookConfigIsSealed($value) ? webhookSecretDecrypt($value, $rawKey) : (string) $value;
                $cfg[$headersKey][$name] = ($plain !== null && strlen($plain) > 8) ? '••••' . substr($plain, -4) : '••••';
            }
        }
        return $cfg;
    }
}
