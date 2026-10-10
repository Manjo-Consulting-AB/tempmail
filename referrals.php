<?php

declare(strict_types=1);

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

/**
 * Pure helpers for referrals (epic #387): invite-code generation and
 * validation, calendar-month and window arithmetic, reward-kind selection
 * and the shared DDL for the referrals ledger. This file only defines
 * functions, each guarded with function_exists() like pro_trial.php, and
 * does not require config.php, so tests/referrals_test.php can load it with
 * no database. No runtime file calls these functions yet (epic #387 step 1)
 * — this file changes no behaviour by itself.
 *
 * Summary of the epic:
 *   - Every account has an invite code (8 characters from an unambiguous
 *     alphabet). A friend who signs up through it and buys 12 months of Pro
 *     (or Lifetime) within the window gets bonus months; the referrer is
 *     rewarded after the money-back window has closed without a refund.
 *   - The referrer's reward is months, a billing-date push, or extra Sticky
 *     slots for Lifetime accounts — see referralRewardKind().
 *   - referrals is the ledger: one row per referee, ever.
 */

if (!function_exists('referralCodeAlphabet')) {
    function referralCodeAlphabet(): string
    {
        return '23456789abcdefghjkmnpqrstuvwxyz';
    }
}

if (!function_exists('referralGenerateCode')) {
    /** A fresh 8-character invite code drawn with random_int(). */
    function referralGenerateCode(): string
    {
        $alphabet = referralCodeAlphabet();
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }
}

if (!function_exists('referralIsValidCode')) {
    /** True only for exactly 8 characters from the alphabet (lowercase). */
    function referralIsValidCode(string $code): bool
    {
        return preg_match('/^[' . referralCodeAlphabet() . ']{8}$/', $code) === 1;
    }
}

if (!function_exists('referralAddMonths')) {
    /**
     * Add calendar months to a 'Y-m-d H:i:s' value, keeping the time of day
     * and clamping to the last day of a shorter target month (31 Jan + 1
     * month = end of Feb, never overflowing into March).
     */
    function referralAddMonths(string $datetime, int $months): string
    {
        $d = new DateTimeImmutable($datetime);
        $day = (int)$d->format('j');
        $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $last = (int)$first->format('t');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $last))
            ->format('Y-m-d H:i:s');
    }
}

if (!function_exists('referralWindowEnd')) {
    /** End of the attribution window: $createdAt plus $days * 86400 seconds. */
    function referralWindowEnd(string $createdAt, int $days): string
    {
        return (new DateTimeImmutable($createdAt))
            ->setTimestamp((new DateTimeImmutable($createdAt))->getTimestamp() + $days * 86400)
            ->format('Y-m-d H:i:s');
    }
}

if (!function_exists('referralRewardKind')) {
    /**
     * How the referrer is rewarded, from their state at grant time:
     * 'sticky' when pro_expires_at is null (Lifetime/unlimited), 'billing'
     * with an active Paddle subscription, otherwise 'months'.
     *
     * @param array{pro_expires_at?: ?string, has_billing_subscription?: bool} $state
     */
    function referralRewardKind(array $state): string
    {
        if (($state['pro_expires_at'] ?? null) === null) {
            return 'sticky';
        }
        if (!empty($state['has_billing_subscription'])) {
            return 'billing';
        }
        return 'months';
    }
}

if (!function_exists('referralSettings')) {
    /**
     * Every key of $config['referral'] with its default filled in and cast.
     * Never throws.
     *
     * @return array{enabled: bool, bonus_months: int, sticky_bonus: int, window_days: int, hold_days: int, cookie_days: int}
     */
    function referralSettings(array $config): array
    {
        $int = static function ($v, int $default, int $min): int {
            return is_numeric($v) ? max($min, (int)$v) : $default;
        };
        $enabled = $config['enabled'] ?? false;
        if (is_string($enabled)) {
            $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }
        return [
            'enabled'      => (bool)$enabled,
            'bonus_months' => $int($config['bonus_months'] ?? null, 3, 1),
            'sticky_bonus' => $int($config['sticky_bonus'] ?? null, 3, 1),
            'window_days'  => $int($config['window_days'] ?? null, 120, 1),
            'hold_days'    => $int($config['hold_days'] ?? null, 30, 0),
            'cookie_days'  => $int($config['cookie_days'] ?? null, 30, 1),
        ];
    }
}

if (!function_exists('referralSchemaStatements')) {
    /**
     * DDL for the referrals ledger ('mysql' or 'sqlite'), as a list of
     * statements. Does not touch pro_users.
     *
     * @return string[]
     */
    function referralSchemaStatements(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS referrals (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    referrer_id INT NOT NULL,
                    referee_id INT NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    created_at DATETIME NOT NULL,
                    window_ends_at DATETIME NOT NULL,
                    plan VARCHAR(16) NULL,
                    subscription_id VARCHAR(64) NULL,
                    transaction_id VARCHAR(64) NULL,
                    qualified_at DATETIME NULL,
                    referee_reward_at DATETIME NULL,
                    referee_target_billed_at DATETIME NULL,
                    referrer_reward_due_at DATETIME NULL,
                    referrer_reward_kind VARCHAR(16) NULL,
                    referrer_target_billed_at DATETIME NULL,
                    referrer_reward_at DATETIME NULL,
                    void_reason VARCHAR(32) NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_ref_referee (referee_id),
                    KEY idx_ref_referrer (referrer_id, status),
                    KEY idx_ref_due (status, referrer_reward_due_at),
                    KEY idx_ref_window (status, window_ends_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS referrals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_id INTEGER NOT NULL,
                referee_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL,
                window_ends_at TEXT NOT NULL,
                plan TEXT NULL,
                subscription_id TEXT NULL,
                transaction_id TEXT NULL,
                qualified_at TEXT NULL,
                referee_reward_at TEXT NULL,
                referee_target_billed_at TEXT NULL,
                referrer_reward_due_at TEXT NULL,
                referrer_reward_kind TEXT NULL,
                referrer_target_billed_at TEXT NULL,
                referrer_reward_at TEXT NULL,
                void_reason TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_ref_referee ON referrals (referee_id)",
            "CREATE INDEX IF NOT EXISTS idx_ref_referrer ON referrals (referrer_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_ref_due ON referrals (status, referrer_reward_due_at)",
            "CREATE INDEX IF NOT EXISTS idx_ref_window ON referrals (status, window_ends_at)",
        ];
    }
}
