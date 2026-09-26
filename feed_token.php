<?php

declare(strict_types=1);

/**
 * RSS feed tokens at rest (#315), same idea as email_enc/email_hash in
 * pii_crypto.php: pro_users.feed_token / temp_emails.feed_token (bearer
 * credentials — whoever has the URL reads the mail) get two companion
 * columns instead of living in plaintext:
 *
 *   feed_token_hash  CHAR(64), plain SHA-256 hex of the token, UNIQUE.
 *                    Tokens are bin2hex(random_bytes(32)) — 256 bits of
 *                    entropy already — so an unkeyed hash is a sufficient
 *                    lookup index; there is nothing a key would strengthen
 *                    here, unlike pii_crypto.php's blind index over a small
 *                    address space. Every lookup by token goes through this.
 *   feed_token_enc   "v2:" ciphertext of the token, via
 *                    webhookSecretEncrypt()/webhookSecretDecrypt()
 *                    (webhook_secret.php) under WEBHOOKS_KEY — the
 *                    integration key already used for hook credentials, so
 *                    no new env var is needed. Decrypted only to show the
 *                    feed URL back to its owner.
 *
 * Fail closed: without WEBHOOKS_KEY, feedTokenNewPair() returns null and the
 * caller must refuse to create or regenerate a token — never fall back to
 * storing it in the plaintext feed_token column. Lookup by hash needs no key
 * at all, so existing feeds keep resolving even when the key is absent or
 * wrong; only *showing* the URL again requires it.
 *
 * Dependency-free (no config.php, no DB connection of its own — mirrors
 * pii_crypto.php/webhook_secret.php) so tests can load it on its own; every
 * function is function_exists-guarded. webhook_secret.php is required
 * because feedTokenNewPair()/feedTokenOpen() delegate encryption to it
 * rather than defining a second AES-GCM implementation.
 */

require_once __DIR__ . '/webhook_secret.php';

if (!function_exists('feedTokenGenerate')) {
    /** A fresh bearer token: 64 lowercase hex characters (256 bits). */
    function feedTokenGenerate(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('feedTokenValidate')) {
    /**
     * $raw if it is a well-formed token (^[a-f0-9]{64}$), else null.
     * Validate-then-look-up: callers must reject before touching the
     * database, exactly like the address regex in config.php.
     */
    function feedTokenValidate(?string $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1 ? $raw : null;
    }
}

if (!function_exists('feedTokenHash')) {
    /** The lookup index for $token: plain SHA-256 hex (see file header — no key needed). */
    function feedTokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}

if (!function_exists('feedTokenNewPair')) {
    /**
     * A fresh token plus the columns to store it as
     * ['token' => ..., 'feed_token_hash' => ..., 'feed_token_enc' => ...],
     * or null when WEBHOOKS_KEY is not configured (or encryption otherwise
     * fails). Callers must refuse to create/regenerate a token when this
     * returns null — fail closed, never store the plaintext instead.
     */
    function feedTokenNewPair(?string $rawKey = null): ?array
    {
        $token = feedTokenGenerate();
        $enc = webhookSecretEncrypt($token, $rawKey);
        if ($enc === null) {
            return null;
        }
        return [
            'token' => $token,
            'feed_token_hash' => feedTokenHash($token),
            'feed_token_enc' => $enc,
        ];
    }
}

if (!function_exists('feedTokenOpen')) {
    /**
     * The plaintext token from $enc (a feed_token_enc value), or null when it
     * is missing or cannot be decrypted (WEBHOOKS_KEY missing/wrong, or the
     * value is damaged). Callers must log an ERROR with ids only — never the
     * ciphertext or a token — and answer with success:false offering
     * Regenerate; never return the ciphertext itself.
     */
    function feedTokenOpen(?string $enc, ?string $rawKey = null): ?string
    {
        if ($enc === null || $enc === '') {
            return null;
        }
        return webhookSecretDecrypt($enc, $rawKey);
    }
}

if (!function_exists('feedTokenColumnsExist')) {
    /** Whether $table has both feed_token_hash and feed_token_enc, via tableHasColumn(). */
    function feedTokenColumnsExist(string $table): bool
    {
        return function_exists('tableHasColumn')
            && tableHasColumn($table, 'feed_token_hash')
            && tableHasColumn($table, 'feed_token_enc');
    }
}

if (!function_exists('feedTokenLogError')) {
    /** ERROR through logMessage() when defined, else error_log(). $context carries ids only, never a token. */
    function feedTokenLogError(string $message, array $context = []): void
    {
        if (function_exists('logMessage')) {
            logMessage('ERROR', $message, $context);
        } else {
            error_log($message . ($context ? ' ' . json_encode($context) : ''));
        }
    }
}
