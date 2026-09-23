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

if (!function_exists('proTrialRecordClaim')) {
    /**
     * Records an address' first-seen time in pro_trial_claims, once per
     * address, ever (epic #267 decisions 1-3). Never updates an existing
     * row. Calls the global tableHasColumn()/logMessage() from config.php
     * at runtime; this file itself still does not require config.php.
     *
     * Returns the stored first_seen_at on success, or null when nothing
     * could be recorded (fail-closed: a missing/short key, a missing
     * table, or a database error). Never logs the email or its hash.
     */
    function proTrialRecordClaim(PDO $pdo, string $email, string $key): ?string
    {
        $hash = proTrialEmailHash($email, $key);
        if ($hash === null) {
            if (strlen($key) < 32) {
                logMessage('ERROR', 'Pro trial: PRO_TRIAL_HASH_KEY missing or shorter than 32 characters, address not recorded');
            }
            return null;
        }

        if (!tableHasColumn('pro_trial_claims', 'email_hash')) {
            logMessage('WARNING', 'Pro trial: pro_trial_claims table missing or outdated, run migrate_trial_claims.php');
            return null;
        }

        try {
            $selectStmt = $pdo->prepare('SELECT first_seen_at FROM pro_trial_claims WHERE email_hash = ?');
            $selectStmt->execute([$hash]);
            $existing = $selectStmt->fetchColumn();
            if ($existing !== false) {
                return (string) $existing;
            }

            try {
                $insertStmt = $pdo->prepare('INSERT INTO pro_trial_claims (email_hash, first_seen_at) VALUES (?, NOW())');
                $insertStmt->execute([$hash]);
            } catch (PDOException $e) {
                // A concurrent insert raced us and hit the primary key first;
                // fall through to the re-select below rather than treating
                // this as a failure.
            }

            $selectStmt->execute([$hash]);
            $stored = $selectStmt->fetchColumn();
            return $stored === false ? null : (string) $stored;
        } catch (Exception $e) {
            logMessage('ERROR', 'Pro trial: could not record claim - ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('proTrialGrantOnVerification')) {
    /**
     * Grants the 60-day Pro trial on an address' first verification (epic
     * #267 decision 4). $trial is $config['trial']. Returns the new
     * pro_expires_at when a trial was granted, otherwise null. Must never
     * throw (fail-closed on the trial, fail-open on login/verification).
     */
    function proTrialGrantOnVerification(PDO $pdo, int $userId, string $email, array $trial): ?string
    {
        try {
            $firstSeen = proTrialRecordClaim($pdo, $email, (string) ($trial['hash_key'] ?? ''));
            if ($firstSeen === null) {
                return null;
            }

            $days = (int) ($trial['days'] ?? 0);
            if ($days <= 0) {
                return null;
            }

            if (!tableHasColumn('pro_users', 'account_type')) {
                return null;
            }

            $end = strtotime($firstSeen) + $days * 86400;
            if ($end <= time()) {
                return null;
            }

            $expiresAt = date('Y-m-d H:i:s', $end);
            $updateStmt = $pdo->prepare("UPDATE pro_users SET account_type = 'pro', pro_expires_at = ? WHERE id = ? AND account_type = 'regular'");
            $updateStmt->execute([$expiresAt, $userId]);

            if ($updateStmt->rowCount() === 1) {
                logMessage('INFO', 'Pro trial granted', ['user_id' => $userId, 'trial_ends_at' => $expiresAt]);
                return $expiresAt;
            }

            return null;
        } catch (Exception $e) {
            logMessage('ERROR', 'Pro trial: could not grant trial - ' . $e->getMessage());
            return null;
        }
    }
}
