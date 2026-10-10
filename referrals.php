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
 * no database. The DB-backed functions (referralCodeExists() and the ones
 * after it, epic #387 step 3) take a PDO explicitly and call the globals
 * tableHasColumn(), logMessage() and proUserEmail() at runtime, the same way
 * proTrialRecordClaim() does; the pure helpers above them need none of that.
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

if (!function_exists('referralCodeExists')) {
    /** True when some account owns this invite code. Any error is false. */
    function referralCodeExists(PDO $pdo, string $code): bool
    {
        if (!tableHasColumn('pro_users', 'referral_code')) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM pro_users WHERE referral_code = ? LIMIT 1');
            $stmt->execute([$code]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('referralCookieCode')) {
    /** The invite code in the ms_ref cookie, lowercased, or null when absent or malformed. */
    function referralCookieCode(): ?string
    {
        $raw = $_COOKIE['ms_ref'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $code = strtolower($raw);
        return referralIsValidCode($code) ? $code : null;
    }
}

if (!function_exists('referralStorePendingCode')) {
    /**
     * Remembers the invite code on the account at registration (the
     * verification link is often opened on another device, where the cookie
     * is missing). A null code is written too, so an overwritten stale
     * unverified account loses an old code. Never throws.
     */
    function referralStorePendingCode(PDO $pdo, int $userId, ?string $code, array $settings): void
    {
        if (empty($settings['enabled']) || !tableHasColumn('pro_users', 'referral_pending_code')) {
            return;
        }
        try {
            $stmt = $pdo->prepare('UPDATE pro_users SET referral_pending_code = ? WHERE id = ?');
            $stmt->execute([$code, $userId]);
        } catch (Throwable $e) {
            logMessage('WARNING', 'Referral: could not store pending code', ['user_id' => $userId]);
        }
    }
}

if (!function_exists('referralAddressIsNew')) {
    /**
     * True only when the address has never been proven before: no row in
     * pro_trial_claims for its canonical hash. Must run before
     * proTrialGrantOnVerification() records the claim. Fail-closed: a short
     * key, an unusable address, a missing table or an error all answer false.
     */
    function referralAddressIsNew(PDO $pdo, string $email, string $trialHashKey): bool
    {
        try {
            $hash = proTrialEmailHash($email, $trialHashKey);
            if ($hash === null || !tableHasColumn('pro_trial_claims', 'email_hash')) {
                return false;
            }
            $stmt = $pdo->prepare('SELECT 1 FROM pro_trial_claims WHERE email_hash = ?');
            $stmt->execute([$hash]);
            return $stmt->fetchColumn() === false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('referralBindOnVerification')) {
    /**
     * Creates the referrals row at an account's first verification (epic
     * #387 decision 3) and returns its id, or null when nothing was bound.
     * Never throws and never logs an address or a code.
     */
    function referralBindOnVerification(PDO $pdo, int $userId, string $email, bool $addressIsNew, array $settings, int $now): ?int
    {
        try {
            if (empty($settings['enabled'])
                || !tableHasColumn('referrals', 'status')
                || !tableHasColumn('pro_users', 'referral_pending_code')) {
                return null;
            }

            $stmt = $pdo->prepare('SELECT referral_pending_code FROM pro_users WHERE id = ?');
            $stmt->execute([$userId]);
            $pending = $stmt->fetchColumn();
            $code = is_string($pending) && referralIsValidCode(strtolower($pending)) ? strtolower($pending) : referralCookieCode();

            // Whatever happens next, the code has been used up.
            $pdo->prepare('UPDATE pro_users SET referral_pending_code = NULL WHERE id = ?')->execute([$userId]);
            if (isset($_COOKIE['ms_ref'])) {
                unset($_COOKIE['ms_ref']);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    setcookie('ms_ref', '', ['expires' => time() - 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
                }
            }

            if ($code === null) {
                return null;
            }
            if (!$addressIsNew) {
                logMessage('INFO', 'Referral not bound: address seen before', ['user_id' => $userId]);
                return null;
            }

            $stmt = $pdo->prepare('SELECT id FROM pro_users WHERE referral_code = ?');
            $stmt->execute([$code]);
            $referrerId = $stmt->fetchColumn();
            if ($referrerId === false) {
                return null;
            }
            $referrerId = (int)$referrerId;

            if ($referrerId === $userId) {
                logMessage('INFO', 'Referral not bound: self-referral', ['user_id' => $userId, 'referrer_id' => $referrerId]);
                return null;
            }
            $own = proTrialNormalizeEmail($email);
            $theirs = proTrialNormalizeEmail(proUserEmail($pdo, $referrerId) ?? '');
            if ($own !== null && $own === $theirs) {
                logMessage('INFO', 'Referral not bound: same canonical address', ['user_id' => $userId, 'referrer_id' => $referrerId]);
                return null;
            }

            $createdAt = date('Y-m-d H:i:s', $now);
            $windowEnd = referralWindowEnd($createdAt, (int)($settings['window_days'] ?? 120));
            try {
                $ins = $pdo->prepare("INSERT INTO referrals (referrer_id, referee_id, status, created_at, window_ends_at, attempts, updated_at) VALUES (?, ?, 'joined', ?, ?, 0, ?)");
                $ins->execute([$referrerId, $userId, $createdAt, $windowEnd, $createdAt]);
            } catch (PDOException $e) {
                // Unique key on referee_id: already bound, nothing more to do.
                if ((int)($e->errorInfo[1] ?? 0) === 1062 || stripos($e->getMessage(), 'UNIQUE') !== false) {
                    return null;
                }
                throw $e;
            }
            $referralId = (int)$pdo->lastInsertId();
            logMessage('INFO', 'Referral bound', ['referral_id' => $referralId, 'referrer_id' => $referrerId, 'referee_id' => $userId]);
            return $referralId;
        } catch (Throwable $e) {
            logMessage('WARNING', 'Referral: binding failed', ['user_id' => $userId]);
            return null;
        }
    }
}

if (!function_exists('referralQualify')) {
    /**
     * Marks a referee's first qualifying payment (epic #387 decision 5):
     * joined -> qualified inside the window, or joined -> void when the paying
     * Paddle customer is already linked to the referrer. Marks only: no
     * Paddle call, no entitlement change. Runs inside the caller's
     * transaction and never throws. Returns an outcome suffix or ''.
     */
    function referralQualify(PDO $pdo, int $refereeId, string $plan, ?string $subscriptionId, ?string $transactionId, string $customerId, array $options): string
    {
        $log = static function (string $level, string $message, array $context) use ($options): void {
            if (function_exists('paddleLog')) {
                paddleLog($options, $level, $message, $context);
            }
        };
        try {
            $stmt = $pdo->prepare("SELECT id, referrer_id, window_ends_at FROM referrals WHERE referee_id = ? AND status = 'joined'");
            $stmt->execute([$refereeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return '';
            }
            $now = (int)($options['now'] ?? time());
            $windowEnd = strtotime((string)$row['window_ends_at']);
            if ($windowEnd === false || $now > $windowEnd) {
                return '';
            }
            $referralId = (int)$row['id'];
            $referrerId = (int)$row['referrer_id'];
            $stamp = date('Y-m-d H:i:s', $now);

            $stmt = $pdo->prepare(
                'SELECT 1 FROM paddle_subscriptions WHERE customer_id = ? AND pro_user_id = ?
                 UNION SELECT 1 FROM paddle_transactions WHERE customer_id = ? AND pro_user_id = ?'
            );
            $stmt->execute([$customerId, $referrerId, $customerId, $referrerId]);
            if ($stmt->fetchColumn() !== false) {
                $pdo->prepare("UPDATE referrals SET status = 'void', void_reason = 'same_customer', updated_at = ? WHERE id = ? AND status = 'joined'")
                    ->execute([$stamp, $referralId]);
                $log('WARNING', 'Referral voided: same Paddle customer as referrer', [
                    'referral_id' => $referralId, 'referrer_id' => $referrerId, 'referee_id' => $refereeId,
                ]);
                return "; referral {$referralId} voided";
            }

            $holdDays = (int)($options['referral_settings']['hold_days'] ?? 30);
            $dueAt = date('Y-m-d H:i:s', $now + $holdDays * 86400);
            $upd = $pdo->prepare(
                "UPDATE referrals SET status = 'qualified', plan = ?, subscription_id = ?, transaction_id = ?,
                    qualified_at = ?, referrer_reward_due_at = ?, updated_at = ?
                 WHERE id = ? AND status = 'joined'"
            );
            $upd->execute([$plan, $subscriptionId, $transactionId, $stamp, $dueAt, $stamp, $referralId]);
            if ($upd->rowCount() < 1) {
                return '';
            }
            $log('INFO', 'Referral qualified', [
                'referral_id' => $referralId, 'plan' => $plan, 'paddle_id' => $subscriptionId ?? $transactionId,
            ]);
            return "; referral {$referralId} qualified";
        } catch (Throwable $e) {
            $log('WARNING', 'Referral: qualification failed', ['referee_id' => $refereeId]);
            return '';
        }
    }
}

if (!function_exists('referralVoidOnRefund')) {
    /**
     * A revoking adjustment voids a referral whose qualifying payment it
     * matches, as long as the referrer has not been rewarded (decision 8).
     * After the reward nothing changes and only a WARNING is logged. Runs
     * inside the caller's transaction and never throws. Returns an outcome
     * suffix or ''.
     */
    function referralVoidOnRefund(PDO $pdo, ?string $subscriptionId, string $transactionId, array $options): string
    {
        $log = static function (string $level, string $message, array $context) use ($options): void {
            if (function_exists('paddleLog')) {
                paddleLog($options, $level, $message, $context);
            }
        };
        try {
            // A null subscription id binds a value that cannot match.
            $sub = $subscriptionId ?? '';
            $match = "((plan = 'year' AND subscription_id = ? AND ? <> '') OR (plan = 'lifetime' AND transaction_id = ?))";
            $params = [$sub, $sub, $transactionId];
            $stamp = date('Y-m-d H:i:s', (int)($options['now'] ?? time()));

            $upd = $pdo->prepare(
                "UPDATE referrals SET status = 'void', void_reason = 'refunded', updated_at = ?
                 WHERE status IN ('qualified','stuck') AND referrer_reward_at IS NULL AND {$match}"
            );
            $upd->execute(array_merge([$stamp], $params));
            $changed = $upd->rowCount();
            if ($changed > 0) {
                $log('INFO', 'Referral voided: qualifying payment refunded', ['count' => $changed]);
            }

            $sel = $pdo->prepare("SELECT id FROM referrals WHERE status = 'rewarded' AND {$match}");
            $sel->execute($params);
            foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $referralId) {
                $log('WARNING', 'Refund after referral reward, no clawback', [
                    'referral_id' => (int)$referralId, 'subscription_id' => $subscriptionId, 'transaction_id' => $transactionId,
                ]);
            }
            return $changed > 0 ? "; {$changed} referral(s) voided" : '';
        } catch (Throwable $e) {
            $log('WARNING', 'Referral: refund handling failed', ['transaction_id' => $transactionId]);
            return '';
        }
    }
}

if (!function_exists('referralRunTx')) {
    /** Runs $fn in one transaction on $pdo; rolls back and rethrows on any error. */
    function referralRunTx(PDO $pdo, callable $fn)
    {
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('referralRunPush')) {
    /**
     * The idempotent next_billed_at push shared by both sides (epic #387
     * decision 9). $storedTarget is the row's *_target_billed_at. Returns
     * 'pushed', 'already' (Paddle already shows the target) or
     * 'no_next_billed_at' (nothing to push, only possible before a target is
     * stored). Throws on any Paddle or data error. Never inside a DB
     * transaction. With $dryRun nothing is stored and nothing is PATCHed.
     */
    function referralRunPush(PDO $pdo, int $referralId, string $side, ?string $storedTarget, string $subscriptionId, array $settings, array $paddle, int $now, bool $dryRun): string
    {
        $target = $storedTarget;
        if ($target === null) {
            $sub = $paddle['get']($subscriptionId);
            $nextBilled = $sub['next_billed_at'] ?? null;
            if (!is_string($nextBilled) || $nextBilled === '') {
                return 'no_next_billed_at';
            }
            $target = referralAddMonths(paddleUtcToLocal($nextBilled), (int)$settings['bonus_months']);
            if (!$dryRun) {
                // Stored before the PATCH, so a retry can tell whether it already happened.
                $stamp = date('Y-m-d H:i:s', $now);
                if ($side === 'referee') {
                    $pdo->prepare('UPDATE referrals SET referee_target_billed_at = ?, updated_at = ? WHERE id = ?')->execute([$target, $stamp, $referralId]);
                } else {
                    $pdo->prepare('UPDATE referrals SET referrer_target_billed_at = ?, updated_at = ? WHERE id = ?')->execute([$target, $stamp, $referralId]);
                }
            }
        }

        $sub = $paddle['get']($subscriptionId);
        $nextBilled = $sub['next_billed_at'] ?? null;
        if (!is_string($nextBilled) || $nextBilled === '') {
            throw new RuntimeException('Subscription has no next_billed_at, cannot confirm the push');
        }
        if (paddleUtcToLocal($nextBilled) >= $target) {
            return 'already';
        }
        if (!$dryRun) {
            $paddle['patch']($subscriptionId, paddleRfc3339ToUtc($target));
        }
        return 'pushed';
    }
}

if (!function_exists('referralRunFailure')) {
    /**
     * Counts a failed attempt on one row and marks it 'stuck' at 5. Never
     * throws; does nothing on a dry run.
     */
    function referralRunFailure(PDO $pdo, int $referralId, string $side, ?string $subscriptionId, Throwable $e, int $now, bool $dryRun, array &$counts): void
    {
        if ($dryRun) {
            return;
        }
        try {
            $stamp = date('Y-m-d H:i:s', $now);
            $pdo->prepare('UPDATE referrals SET attempts = attempts + 1, updated_at = ? WHERE id = ?')->execute([$stamp, $referralId]);
            $stmt = $pdo->prepare('SELECT attempts FROM referrals WHERE id = ?');
            $stmt->execute([$referralId]);
            $attempts = (int)$stmt->fetchColumn();
            logMessage('WARNING', 'Referral reward attempt failed', [
                'referral_id' => $referralId, 'side' => $side, 'attempts' => $attempts, 'error' => $e->getMessage(),
            ]);
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE referrals SET status = 'stuck', updated_at = ? WHERE id = ? AND status = 'qualified'")->execute([$stamp, $referralId]);
                $counts['stuck']++;
                logMessage('ERROR', 'Referral reward stuck, grant by hand', [
                    'referral_id' => $referralId, 'side' => $side, 'subscription_id' => $subscriptionId,
                ]);
            }
        } catch (Throwable $inner) {
            // Nothing more can be done here; the row is picked up again next run.
        }
    }
}

if (!function_exists('referralRunRewards')) {
    /**
     * One reward run (epic #387). $paddle = ['get' => callable(string $subId): array,
     * 'patch' => callable(string $subId, string $nextBilledAtUtc): array], each
     * returning Paddle's subscription `data`. Never throws; returns counters.
     * $settings is referralSettings(); $now a Unix timestamp.
     */
    function referralRunRewards(PDO $pdo, array $settings, array $paddle, int $now, bool $dryRun = false): array
    {
        $counts = ['referee_rewarded' => 0, 'referrer_rewarded' => 0, 'expired' => 0, 'voided' => 0, 'retried' => 0, 'stuck' => 0];
        $log = static function (string $level, string $message, array $context = []) use ($dryRun): void {
            if (!$dryRun && function_exists('logMessage')) {
                logMessage($level, $message, $context);
            }
        };

        try {
            if (empty($settings['enabled'])) {
                return $counts;
            }
            try {
                $pdo->query('SELECT 1 FROM referrals WHERE 1 = 0');
            } catch (Throwable $e) {
                return $counts;
            }

            $nowStr = date('Y-m-d H:i:s', $now);
            $months = (int)$settings['bonus_months'];

            // Housekeeping: joined rows past their window.
            $stmt = $pdo->prepare("SELECT id FROM referrals WHERE status = 'joined' AND window_ends_at < ? ORDER BY id LIMIT 100");
            $stmt->execute([$nowStr]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    if (!$dryRun) {
                        $upd = $pdo->prepare("UPDATE referrals SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'joined'");
                        $upd->execute([$nowStr, (int)$id]);
                        if ($upd->rowCount() < 1) {
                            continue;
                        }
                    }
                    $counts['expired']++;
                } catch (Throwable $e) {
                    $log('WARNING', 'Referral expiry failed', ['referral_id' => (int)$id]);
                }
            }

            // Housekeeping: qualified rows whose referrer is gone.
            $stmt = $pdo->query("SELECT id FROM referrals WHERE status = 'qualified' AND NOT EXISTS (SELECT 1 FROM pro_users WHERE pro_users.id = referrals.referrer_id) ORDER BY id LIMIT 100");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    if (!$dryRun) {
                        $upd = $pdo->prepare("UPDATE referrals SET status = 'void', void_reason = 'referrer_deleted', updated_at = ? WHERE id = ? AND status = 'qualified'");
                        $upd->execute([$nowStr, (int)$id]);
                        if ($upd->rowCount() < 1) {
                            continue;
                        }
                    }
                    $counts['voided']++;
                } catch (Throwable $e) {
                    $log('WARNING', 'Referral void failed', ['referral_id' => (int)$id]);
                }
            }

            // Referee reward: year plans, due at once.
            $stmt = $pdo->query("SELECT * FROM referrals WHERE status = 'qualified' AND referee_reward_at IS NULL ORDER BY id LIMIT 100");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $referralId = (int)$row['id'];
                $subForLog = $row['subscription_id'] !== null ? (string)$row['subscription_id'] : null;
                try {
                    if ((int)$row['attempts'] > 0) {
                        $counts['retried']++;
                    }
                    $markDone = static function () use ($pdo, $referralId, $nowStr, $dryRun): void {
                        if ($dryRun) {
                            return;
                        }
                        referralRunTx($pdo, static function () use ($pdo, $referralId, $nowStr): void {
                            $pdo->prepare("UPDATE referrals SET referee_reward_at = ?, updated_at = ? WHERE id = ? AND status = 'qualified' AND referee_reward_at IS NULL")
                                ->execute([$nowStr, $nowStr, $referralId]);
                        });
                    };
                    if ($row['plan'] !== 'year') {
                        // Lifetime: nothing can be extended, the row just moves on.
                        $markDone();
                        $counts['referee_rewarded']++;
                        continue;
                    }
                    if ($subForLog === null || $subForLog === '') {
                        throw new RuntimeException('Year referral has no subscription id');
                    }
                    $outcome = referralRunPush($pdo, $referralId, 'referee', $row['referee_target_billed_at'] !== null ? (string)$row['referee_target_billed_at'] : null, $subForLog, $settings, $paddle, $now, $dryRun);
                    if ($outcome === 'no_next_billed_at') {
                        // A cancel is scheduled: grant the months on the account instead.
                        if (!$dryRun) {
                            referralRunTx($pdo, static function () use ($pdo, $row, $referralId, $nowStr, $months): void {
                                $mark = $pdo->prepare("UPDATE referrals SET referee_reward_at = ?, updated_at = ? WHERE id = ? AND status = 'qualified' AND referee_reward_at IS NULL");
                                $mark->execute([$nowStr, $nowStr, $referralId]);
                                if ($mark->rowCount() < 1) {
                                    return;
                                }
                                $cur = $pdo->prepare('SELECT pro_expires_at FROM pro_users WHERE id = ?');
                                $cur->execute([(int)$row['referee_id']]);
                                $expires = $cur->fetchColumn();
                                if (is_string($expires) && $expires !== '') {
                                    $base = max($nowStr, $expires);
                                    $pdo->prepare('UPDATE pro_users SET pro_expires_at = ? WHERE id = ?')
                                        ->execute([referralAddMonths($base, $months), (int)$row['referee_id']]);
                                }
                            });
                        }
                    } else {
                        $markDone();
                    }
                    $counts['referee_rewarded']++;
                    $log('INFO', 'Referee reward granted', ['referral_id' => $referralId]);
                } catch (Throwable $e) {
                    referralRunFailure($pdo, $referralId, 'referee', $subForLog, $e, $now, $dryRun, $counts);
                }
            }

            // Referrer reward: due, and for a year plan only after the referee side is done.
            $stmt = $pdo->prepare(
                "SELECT * FROM referrals WHERE status = 'qualified' AND referrer_reward_due_at IS NOT NULL AND referrer_reward_due_at <= ?
                   AND (plan = 'lifetime' OR referee_reward_at IS NOT NULL) ORDER BY id LIMIT 100"
            );
            $stmt->execute([$nowStr]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $referralId = (int)$row['id'];
                $referrerId = (int)$row['referrer_id'];
                $subForLog = null;
                try {
                    if ((int)$row['attempts'] > 0) {
                        $counts['retried']++;
                    }
                    $cur = $pdo->prepare('SELECT * FROM pro_users WHERE id = ?');
                    $cur->execute([$referrerId]);
                    $referrer = $cur->fetch(PDO::FETCH_ASSOC);
                    if ($referrer === false) {
                        throw new RuntimeException('Referrer not found');
                    }
                    $expires = is_string($referrer['pro_expires_at'] ?? null) && $referrer['pro_expires_at'] !== '' ? (string)$referrer['pro_expires_at'] : null;
                    // A Regular account with no expiry is not Lifetime: it gets months
                    // (decision 7), so it must not reach referralRewardKind() as null.
                    $isRegular = ($referrer['account_type'] ?? null) === 'regular';

                    $billing = $pdo->prepare(
                        "SELECT subscription_id FROM paddle_subscriptions WHERE pro_user_id = ? AND status IN ('active','trialing') AND scheduled_change_action IS NULL
                         ORDER BY updated_at DESC, subscription_id DESC LIMIT 1"
                    );
                    $billing->execute([$referrerId]);
                    $billingSub = $billing->fetchColumn();
                    $billingSub = $billingSub === false ? null : (string)$billingSub;
                    $subForLog = $billingSub;

                    $kind = referralRewardKind(['pro_expires_at' => ($expires === null && $isRegular) ? $nowStr : $expires, 'has_billing_subscription' => $billingSub !== null]);
                    if ($row['referrer_target_billed_at'] !== null) {
                        // A target is already stored: the billing push was started, finish it.
                        $kind = 'billing';
                    }

                    if ($kind === 'billing') {
                        if ($billingSub === null) {
                            throw new RuntimeException('Referrer has no billing subscription to finish the push');
                        }
                        $outcome = referralRunPush($pdo, $referralId, 'referrer', $row['referrer_target_billed_at'] !== null ? (string)$row['referrer_target_billed_at'] : null, $billingSub, $settings, $paddle, $now, $dryRun);
                        if ($outcome === 'no_next_billed_at') {
                            throw new RuntimeException('Billing subscription has no next_billed_at');
                        }
                    }

                    if (!$dryRun) {
                        referralRunTx($pdo, static function () use ($pdo, $row, $referralId, $referrerId, $kind, $expires, $nowStr, $months, $settings): void {
                            $mark = $pdo->prepare("UPDATE referrals SET status = 'rewarded', referrer_reward_kind = ?, referrer_reward_at = ?, updated_at = ? WHERE id = ? AND status = 'qualified'");
                            $mark->execute([$kind, $nowStr, $nowStr, $referralId]);
                            if ($mark->rowCount() < 1) {
                                return;
                            }
                            if ($kind === 'sticky') {
                                $pdo->prepare('UPDATE pro_users SET bonus_sticky_slots = bonus_sticky_slots + ? WHERE id = ?')
                                    ->execute([(int)$settings['sticky_bonus'], $referrerId]);
                            } elseif ($kind === 'months') {
                                $base = $expires !== null ? max($nowStr, $expires) : $nowStr;
                                $pdo->prepare("UPDATE pro_users SET pro_expires_at = ?, account_type = 'pro' WHERE id = ?")
                                    ->execute([referralAddMonths($base, $months), $referrerId]);
                            }
                        });
                        $log('INFO', 'Referrer reward granted', ['referral_id' => $referralId, 'kind' => $kind]);
                    }
                    $counts['referrer_rewarded']++;
                } catch (Throwable $e) {
                    referralRunFailure($pdo, $referralId, 'referrer', $subForLog, $e, $now, $dryRun, $counts);
                }
            }

            // Logging only, no cap (decision 10).
            try {
                $since = date('Y-m-d H:i:s', $now - 30 * 86400);
                $stmt = $pdo->prepare("SELECT referrer_id, COUNT(*) AS n FROM referrals WHERE status = 'rewarded' AND referrer_reward_at >= ? GROUP BY referrer_id HAVING COUNT(*) > 5");
                $stmt->execute([$since]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $log('WARNING', 'Referral volume high', ['referrer_id' => (int)$r['referrer_id'], 'count' => (int)$r['n']]);
                }
            } catch (Throwable $e) {
                // Logging only.
            }
        } catch (Throwable $e) {
            $log('ERROR', 'Referral reward run failed', ['error' => $e->getMessage()]);
        }
        return $counts;
    }
}

if (!function_exists('referralEnsureCode')) {
    /**
     * The account's invite code, created on first need (epic #387 step 8).
     * Returns the existing referral_code, else generates one and stores it
     * with a guarded UPDATE (only while the column is still NULL), retrying
     * with a new code on a unique-key collision, up to 5 times. The column is
     * re-read at the end so a concurrent request that won the race is
     * honoured. Any other failure logs a WARNING (user_id only) and returns
     * null. Never throws. $generator is a seam for tests; production callers
     * leave it null and get referralGenerateCode().
     */
    function referralEnsureCode(PDO $pdo, int $userId, ?callable $generator = null): ?string
    {
        try {
            if (!tableHasColumn('pro_users', 'referral_code')) {
                return null;
            }
            $read = static function () use ($pdo, $userId): ?string {
                $stmt = $pdo->prepare('SELECT referral_code FROM pro_users WHERE id = ?');
                $stmt->execute([$userId]);
                $value = $stmt->fetchColumn();
                return is_string($value) && referralIsValidCode($value) ? $value : null;
            };
            $existing = $read();
            if ($existing !== null) {
                return $existing;
            }
            $generate = $generator ?? 'referralGenerateCode';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $stmt = $pdo->prepare('UPDATE pro_users SET referral_code = ? WHERE id = ? AND referral_code IS NULL');
                    $stmt->execute([(string)$generate(), $userId]);
                    break;
                } catch (PDOException $e) {
                    // 23000 is a unique-key collision: draw another code.
                    if ((string)$e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
            return $read();
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Referral: could not ensure invite code', ['user_id' => $userId]);
            }
            return null;
        }
    }
}

if (!function_exists('referralSummaryFor')) {
    /**
     * Counts for the Invite friends card and the referred-account notes.
     * joined = status joined; pending = qualified + stuck; rewarded = rewarded.
     * 'own' is this user's own referee row, only while it is still joined and
     * $now is inside its window: ['window_ends_at' => 'Y-m-d H:i:s'].
     * Counts only: never a referee id, address or date (decision 11).
     * Failure returns zeros and null. Never throws.
     *
     * @return array{joined: int, pending: int, rewarded: int, own: ?array{window_ends_at: string}}
     */
    function referralSummaryFor(PDO $pdo, int $userId, int $now): array
    {
        $summary = ['joined' => 0, 'pending' => 0, 'rewarded' => 0, 'own' => null];
        try {
            $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM referrals WHERE referrer_id = ? GROUP BY status');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $n = (int)$row['n'];
                switch ((string)$row['status']) {
                    case 'joined':
                        $summary['joined'] += $n;
                        break;
                    case 'qualified':
                    case 'stuck':
                        $summary['pending'] += $n;
                        break;
                    case 'rewarded':
                        $summary['rewarded'] += $n;
                        break;
                }
            }
            $stmt = $pdo->prepare("SELECT window_ends_at FROM referrals WHERE referee_id = ? AND status = 'joined' LIMIT 1");
            $stmt->execute([$userId]);
            $ends = $stmt->fetchColumn();
            if (is_string($ends) && $ends !== '' && date('Y-m-d H:i:s', $now) <= $ends) {
                $summary['own'] = ['window_ends_at' => $ends];
            }
            return $summary;
        } catch (Throwable $e) {
            return ['joined' => 0, 'pending' => 0, 'rewarded' => 0, 'own' => null];
        }
    }
}

if (!function_exists('referralDigestCollect')) {
    /**
     * What happened to the referrals in the window (since, until], for the
     * admin digest (cron/referrals-digest.php). Both bounds are unix times;
     * the lower one is exclusive and the upper inclusive, so consecutive
     * windows never overlap or leave a gap. Ids and counts only, never an
     * address. Returns null when the table is missing or the lookup fails.
     *
     * @return array{
     *   joined: int[], qualified: int[], rewarded: int[], rewarded_kinds: array<string,int>,
     *   voided: array<string,int[]>, stuck_new: int[], stuck_all: int[], expired: int,
     *   by_status: array<string,int>
     * }|null
     */
    function referralDigestCollect(PDO $pdo, int $since, int $until): ?array
    {
        try {
            $from = date('Y-m-d H:i:s', $since);
            $to = date('Y-m-d H:i:s', $until);
            $ids = static function (string $sql, array $params = []) use ($pdo): array {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            };

            $out = [
                'joined' => $ids('SELECT id FROM referrals WHERE created_at > ? AND created_at <= ? ORDER BY id', [$from, $to]),
                'qualified' => $ids('SELECT id FROM referrals WHERE qualified_at > ? AND qualified_at <= ? ORDER BY id', [$from, $to]),
                'rewarded' => $ids('SELECT id FROM referrals WHERE referrer_reward_at > ? AND referrer_reward_at <= ? ORDER BY id', [$from, $to]),
                'rewarded_kinds' => [],
                'voided' => [],
                'stuck_new' => $ids("SELECT id FROM referrals WHERE status = 'stuck' AND updated_at > ? AND updated_at <= ? ORDER BY id", [$from, $to]),
                'stuck_all' => $ids("SELECT id FROM referrals WHERE status = 'stuck' ORDER BY id"),
                'expired' => count($ids("SELECT id FROM referrals WHERE status = 'expired' AND updated_at > ? AND updated_at <= ?", [$from, $to])),
                'by_status' => [],
            ];

            $stmt = $pdo->prepare('SELECT COALESCE(referrer_reward_kind, \'unknown\') AS kind, COUNT(*) AS n FROM referrals WHERE referrer_reward_at > ? AND referrer_reward_at <= ? GROUP BY kind ORDER BY kind');
            $stmt->execute([$from, $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['rewarded_kinds'][(string)$row['kind']] = (int)$row['n'];
            }

            $stmt = $pdo->prepare("SELECT id, COALESCE(void_reason, 'unknown') AS reason FROM referrals WHERE status = 'void' AND updated_at > ? AND updated_at <= ? ORDER BY id");
            $stmt->execute([$from, $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['voided'][(string)$row['reason']][] = (int)$row['id'];
            }
            ksort($out['voided']);

            foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM referrals GROUP BY status ORDER BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['by_status'][(string)$row['status']] = (int)$row['n'];
            }
            return $out;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('referralDigestBody')) {
    /**
     * The admin digest as [subject, body], or null when nothing in the window
     * calls for a mail: a new invite, a qualified or rewarded one, a void, or
     * a reward that has just become stuck. Expiries alone never trigger one
     * (they are routine), but are counted when a mail goes out anyway. Pure:
     * takes the result of referralDigestCollect() and the window bounds.
     *
     * @return array{0: string, 1: string}|null
     */
    function referralDigestBody(array $d, int $since, int $until): ?array
    {
        $voidedTotal = 0;
        foreach ($d['voided'] as $list) {
            $voidedTotal += count($list);
        }
        if (!$d['joined'] && !$d['qualified'] && !$d['rewarded'] && !$voidedTotal && !$d['stuck_new']) {
            return null;
        }

        // At most 20 ids per line, so a burst never makes the mail huge.
        $list = static function (array $ids): string {
            $shown = array_slice($ids, 0, 20);
            $more = count($ids) - count($shown);
            return implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '');
        };

        $subject = [];
        if ($d['stuck_new']) {
            $subject[] = count($d['stuck_new']) . ' stuck';
        }
        if ($d['joined']) {
            $subject[] = count($d['joined']) . ' new';
        }
        if ($d['qualified']) {
            $subject[] = count($d['qualified']) . ' qualified';
        }
        if ($d['rewarded']) {
            $subject[] = count($d['rewarded']) . ' rewarded';
        }
        if ($voidedTotal) {
            $subject[] = $voidedTotal . ' void';
        }

        $lines = ['Mail Shield referrals, ' . date('Y-m-d H:i', $since) . ' to ' . date('Y-m-d H:i', $until), ''];
        if ($d['stuck_all']) {
            $lines[] = 'NEEDS ACTION: reward stuck after repeated failures, grant by hand: referral ' . $list($d['stuck_all']) . '.';
            $lines[] = '';
        }
        if ($d['joined']) {
            $lines[] = 'New invites bound: ' . count($d['joined']) . ' (referral ' . $list($d['joined']) . ')';
        }
        if ($d['qualified']) {
            $lines[] = 'Qualified (friend bought a year plan or Lifetime): ' . count($d['qualified']) . ' (referral ' . $list($d['qualified']) . ')';
        }
        if ($d['rewarded']) {
            $kinds = [];
            foreach ($d['rewarded_kinds'] as $kind => $n) {
                $kinds[] = "{$kind} {$n}";
            }
            $lines[] = 'Inviter rewards granted: ' . count($d['rewarded']) . ($kinds ? ' (' . implode(', ', $kinds) . ')' : '') . ' (referral ' . $list($d['rewarded']) . ')';
        }
        foreach ($d['voided'] as $reason => $ids) {
            $lines[] = 'Void, ' . $reason . ': ' . count($ids) . ' (referral ' . $list($ids) . ')';
        }
        if ($d['expired']) {
            $lines[] = 'Expired without a purchase: ' . $d['expired'];
        }

        $status = [];
        foreach ($d['by_status'] as $name => $n) {
            $status[] = "{$name} {$n}";
        }
        $lines[] = '';
        $lines[] = 'All referrals now: ' . ($status ? implode(', ', $status) : 'none');
        $lines[] = 'Details: php check_referrals.php on the server. The ids above are referrals.id; no addresses are included.';

        return ['Mail Shield referrals: ' . implode(', ', $subject), implode("\n", $lines) . "\n"];
    }
}
