<?php

declare(strict_types=1);

/**
 * Pure helpers for the 60-day Pro trial (epic #267): normalising an email
 * address into its canonical form and turning that form into the one-way
 * hash stored in pro_trial_claims. This file has no side effects — it only
 * defines functions, each guarded with function_exists() the same way
 * partials/brand.php is, so a repeat require cannot redeclare them.
 *
 * Deliberately does not require config.php, so tests/pro_trial_test.php can
 * load it with no database. No runtime file calls these functions yet
 * (that is epic #267 step 2) — this file changes no behaviour by itself.
 *
 * Summary of the decisions these functions implement (see epic #267):
 *   1. The stored value is hash_hmac('sha256', normalized, key), 64 lowercase
 *      hex characters, and the key must never change once set.
 *   2. Normalisation: trim, lowercase, split on the last '@', cut the local
 *      part at the first '+', remove every '.' from the local part, rejoin.
 *      This is a canonical form only — it need not be a deliverable address,
 *      and no domain-specific or IDN handling is applied.
 *   3. A trial is granted only on an address' first verification, so both
 *      functions here are pure and side-effect free; recording and granting
 *      happen elsewhere.
 */

if (!function_exists('proTrialNormalizeEmail')) {
    /**
     * Canonical form of an email address for trial-claim hashing. Returns
     * null when the input cannot be split into a non-empty local part and
     * a non-empty domain.
     */
    function proTrialNormalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if ($local === '' || $domain === '') {
            return null;
        }

        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        $local = str_replace('.', '', $local);

        if ($local === '') {
            return null;
        }

        return $local . '@' . $domain;
    }
}

if (!function_exists('proTrialEmailHash')) {
    /**
     * HMAC-SHA256 of the normalised address, 64 lowercase hex characters.
     * Returns null (fail-closed, decision 1) when the key is shorter than
     * 32 characters or the address does not normalise.
     */
    function proTrialEmailHash(string $email, string $key): ?string
    {
        if (strlen($key) < 32) {
            return null;
        }

        $normalized = proTrialNormalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        return hash_hmac('sha256', $normalized, $key);
    }
}
