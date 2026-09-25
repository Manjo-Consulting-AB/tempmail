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
