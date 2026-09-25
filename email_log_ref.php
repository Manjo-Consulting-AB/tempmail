<?php

declare(strict_types=1);

/**
 * Keyed log references for users' email addresses.
 *
 * A registered email address (pro_users.email) must not be copied in
 * plaintext into system_logs or login_attempts. Where an account id is
 * known, log that instead. Where there is none (an unknown address asking
 * for a magic link, a registration attempt, a failed password login), log
 * emailLogRef() instead: a short keyed reference that lets an operator see
 * that repeated attempts concern the same address without storing it.
 *
 * The reference is the first 16 hex characters of
 * HMAC-SHA256(normalised address, sub-key), where the sub-key is
 * HMAC-SHA256('email-log-ref', PRO_TRIAL_HASH_KEY). It has to be keyed: an
 * unkeyed hash of an email address is reversible by hashing a list of
 * candidate addresses. Deriving a sub-key from the existing trial key means
 * no new required env var, and a reference can never be compared against a
 * pro_trial_claims hash. When no key (at least 32 characters, the same rule
 * as pro_trial.php) is configured, there is no reference at all - callers
 * then log nothing about the address, never the address itself.
 *
 * Normalisation is trim + lowercase only. pro_trial.php's canonical form
 * (drop +tags and dots) deliberately merges distinct addresses to stop
 * repeat trials; here that would make two different accounts share one log
 * reference and one per-account login limit, so it is not reused.
 *
 * Pure functions, no config.php require, each function_exists-guarded like
 * pro_trial.php, so tests can load this file with no database.
 */

if (!function_exists('emailLogRefNormalize')) {
    /** Trimmed, lowercased address; null when nothing is left. */
    function emailLogRefNormalize(string $email): ?string
    {
        $normalized = strtolower(trim($email));
        return $normalized === '' ? null : $normalized;
    }
}

if (!function_exists('emailLogRefBaseKey')) {
    /** The configured base key ($config['trial']['hash_key']), or ''. */
    function emailLogRefBaseKey(): string
    {
        $config = $GLOBALS['config'] ?? null;
        if (is_array($config) && isset($config['trial']['hash_key'])) {
            return (string) $config['trial']['hash_key'];
        }
        return '';
    }
}

if (!function_exists('emailLogRef')) {
    /**
     * 16 lowercase hex characters identifying $email in logs, or null when
     * no key of at least 32 characters is configured (or the address is
     * empty). $baseKey defaults to the configured PRO_TRIAL_HASH_KEY.
     */
    function emailLogRef(string $email, ?string $baseKey = null): ?string
    {
        $baseKey = $baseKey ?? emailLogRefBaseKey();
        if (strlen($baseKey) < 32) {
            return null;
        }

        $normalized = emailLogRefNormalize($email);
        if ($normalized === null) {
            return null;
        }

        $subKey = hash_hmac('sha256', 'email-log-ref', $baseKey);
        return substr(hash_hmac('sha256', $normalized, $subKey), 0, 16);
    }
}

if (!function_exists('emailLogContext')) {
    /**
     * Log context identifying whose address a log line is about: the user
     * id when known, else ['email_ref' => ...], else nothing. Never the
     * address. $field names the reference key, e.g. 'new_email_ref'.
     */
    function emailLogContext(string $email, $userId = null, string $field = 'email_ref', ?string $baseKey = null): array
    {
        if ($userId !== null && $userId !== '' && (int) $userId > 0) {
            return ['user_id' => (int) $userId];
        }

        $ref = emailLogRef($email, $baseKey);
        return $ref === null ? [] : [$field => $ref];
    }
}
