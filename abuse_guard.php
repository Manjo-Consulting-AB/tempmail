<?php

declare(strict_types=1);

/**
 * Abuse guard: measuring intake and use, and quarantining what is abused
 * (documentaion/ABUSE_PROTECTION.md is the design; migrate_abuse_guard.php
 * creates the tables).
 *
 * Two tracks, kept apart on purpose:
 *
 *  - An address under attack (its owner is the victim). parse.php counts
 *    every message per address in abuse_counters; over a threshold the
 *    address is quarantined: its DirectAdmin forwarder is removed, so Exim
 *    rejects mail to it at RCPT time and nothing reaches PHP at all. It is
 *    reopened automatically (cron/abuse-guard.php recreates the forwarder)
 *    after a period that grows with every quarantine inside 24 hours; one
 *    trigger past the last step closes the address until its owner deletes
 *    it or an admin reopens it. The owner is warned, then told.
 *
 *  - An account abusing the service (the account is the perpetrator):
 *    address creation over the rate limits. First a warning, then every
 *    address of the account is quarantined for a while, and on repetition a
 *    suspension is *proposed* — an admin confirms or dismisses it in
 *    abuse_admin.php. Being spammed never counts against an account.
 *
 * State:
 *  - abuse_counters: (scope, subject, window_start) buckets of hits, bytes
 *    and strikes. Scopes: 'addr' (a local part, 5-minute buckets), and the
 *    rate limits 'gen_ip', 'gen_user', 'personal_user', 'hook' (1-hour).
 *  - address_quarantines: one row per quarantined local part. A NULL
 *    quarantined_until means closed until someone acts. forwarder_removed
 *    says whether DirectAdmin has actually dropped the forwarder yet.
 *  - abuse_events: an append-only log of what happened (warnings,
 *    quarantines, releases, proposals); rows with notify = 1 are mailed by
 *    cron/abuse-guard.php and stamped with notified_at.
 *  - pro_users.suspended_at: set only by an admin's confirmation.
 *
 * Every function takes the PDO and the clock explicitly and needs nothing
 * from config.php, so tests/abuse_guard_test.php runs them on SQLite (times
 * are computed in PHP rather than with NOW() for the same reason). Database
 * errors are thrown; the callers on the mail path catch them and fail open,
 * because a broken counter must never lose or refuse mail.
 */

if (!function_exists('abuseGuardSettings')) {
    /**
     * $config['abuse'] with every default filled in, so a caller never has
     * to know which keys are optional.
     */
    function abuseGuardSettings(?array $cfg = null): array
    {
        $cfg = $cfg ?? ($GLOBALS['config']['abuse'] ?? []);
        $int = static fn(string $key, int $default, int $min = 0): int => max($min, (int)($cfg[$key] ?? $default));

        $steps = $cfg['quarantine_steps_minutes'] ?? [30, 120, 720];
        if (is_string($steps)) {
            $steps = explode(',', $steps);
        }
        $steps = array_values(array_filter(array_map('intval', (array)$steps), static fn(int $m): bool => $m > 0));
        if ($steps === []) {
            $steps = [30, 120, 720];
        }

        return [
            'enabled' => !array_key_exists('enabled', $cfg) || (bool)$cfg['enabled'],
            // An address: messages per 5 minutes, per hour, bytes per hour,
            // and attachment-limit strikes per hour.
            'address_max_5min' => $int('address_max_5min', 30, 1),
            'address_max_hour' => $int('address_max_hour', 150, 1),
            'address_max_bytes_hour' => $int('address_max_bytes_hour', 52428800, 1),
            'address_max_strikes_hour' => $int('address_max_strikes_hour', 5, 1),
            // Share of any address limit at which the owner is warned.
            'warn_ratio' => min(0.95, max(0.1, (float)($cfg['warn_ratio'] ?? 0.5))),
            // Quarantine lengths for the 1st, 2nd, ... quarantine inside 24 h.
            // A trigger after the last step closes the address.
            'quarantine_steps_minutes' => $steps,
            // Per message: real attachments, and inline images (a Content-ID
            // and an image/* type) — newsletters carry dozens of the latter.
            'max_attachments' => $int('max_attachments', 10, 1),
            'max_inline_images' => $int('max_inline_images', 50, 1),
            // Deliveries queued per webhook per hour.
            'webhook_max_hour' => $int('webhook_max_hour', 60, 1),
            // Address creation.
            'generate_ip_hour' => $int('generate_ip_hour', 10, 1),
            'generate_ip_day' => $int('generate_ip_day', 30, 1),
            'generate_user_day' => $int('generate_user_day', 30, 1),
            'personal_user_day' => $int('personal_user_day', 20, 1),
            // Account track: rate-limit overflows in 24 h before every address
            // of the account is quarantined, for how long, and how many such
            // quarantines in 7 days before a suspension is proposed.
            'account_strikes_day' => $int('account_strikes_day', 3, 1),
            'account_quarantine_minutes' => $int('account_quarantine_minutes', 360, 1),
            'account_quarantines_week' => $int('account_quarantines_week', 2, 1),
        ];
    }
}

if (!function_exists('abuseGuardAvailable')) {
    /**
     * Are the tables of migrate_abuse_guard.php in place? Needs config.php's
     * tableHasColumn(); without it (or without the tables) every caller skips
     * the guard and behaves exactly as before. Cached per process.
     */
    function abuseGuardAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = function_exists('tableHasColumn')
                && tableHasColumn('abuse_counters', 'subject')
                && tableHasColumn('address_quarantines', 'local_part')
                && tableHasColumn('abuse_events', 'subject');
        }
        return $available && abuseGuardSettings()['enabled'];
    }
}

if (!function_exists('abuseTime')) {
    function abuseTime(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }
}

// ---------------------------------------------------------------------
// Counters
// ---------------------------------------------------------------------

if (!function_exists('abuseCounterAdd')) {
    /**
     * Add to the bucket of ($scope, $subject) that $now falls in. Buckets are
     * aligned to $windowSeconds. Portable upsert (MySQL and SQLite): update,
     * and insert when there was nothing to update; a concurrent insert that
     * wins the race turns into one more update.
     */
    function abuseCounterAdd(PDO $pdo, string $scope, string $subject, int $windowSeconds, int $hits, int $bytes, int $strikes, int $now): void
    {
        $window = abuseTime($now - ($now % max(1, $windowSeconds)));
        $update = $pdo->prepare('UPDATE abuse_counters SET hits = hits + ?, bytes = bytes + ?, strikes = strikes + ? WHERE scope = ? AND subject = ? AND window_start = ?');
        $update->execute([$hits, $bytes, $strikes, $scope, $subject, $window]);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $pdo->prepare('INSERT INTO abuse_counters (scope, subject, window_start, hits, bytes, strikes) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$scope, $subject, $window, $hits, $bytes, $strikes]);
        } catch (PDOException $e) {
            $update->execute([$hits, $bytes, $strikes, $scope, $subject, $window]);
        }
    }
}

if (!function_exists('abuseCounterSum')) {
    /**
     * Totals of the buckets of ($scope, $subject) that started after $sinceTs.
     *
     * @return array{hits:int, bytes:int, strikes:int}
     */
    function abuseCounterSum(PDO $pdo, string $scope, string $subject, int $sinceTs): array
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(hits), 0), COALESCE(SUM(bytes), 0), COALESCE(SUM(strikes), 0) FROM abuse_counters WHERE scope = ? AND subject = ? AND window_start > ?');
        $stmt->execute([$scope, $subject, abuseTime($sinceTs)]);
        $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        return ['hits' => (int)$row[0], 'bytes' => (int)$row[1], 'strikes' => (int)$row[2]];
    }
}

if (!function_exists('abuseCounterPurge')) {
    /** Drop buckets that started before $beforeTs; returns the count. */
    function abuseCounterPurge(PDO $pdo, int $beforeTs): int
    {
        $stmt = $pdo->prepare('DELETE FROM abuse_counters WHERE window_start < ?');
        $stmt->execute([abuseTime($beforeTs)]);
        return $stmt->rowCount();
    }
}

// ---------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------

if (!function_exists('abuseEventAdd')) {
    function abuseEventAdd(PDO $pdo, string $kind, ?string $subject, ?int $userId, array $detail, bool $notify, int $now): int
    {
        $pdo->prepare('INSERT INTO abuse_events (created_at, kind, subject, pro_user_id, detail, notify) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([abuseTime($now), $kind, $subject, $userId, $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES) : null, $notify ? 1 : 0]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('abuseEventCount')) {
    /** Events of $kind since $sinceTs, for a subject and/or an account. */
    function abuseEventCount(PDO $pdo, string $kind, ?string $subject, ?int $userId, int $sinceTs): int
    {
        $sql = 'SELECT COUNT(*) FROM abuse_events WHERE kind = ? AND created_at > ?';
        $params = [$kind, abuseTime($sinceTs)];
        if ($subject !== null) {
            $sql .= ' AND subject = ?';
            $params[] = $subject;
        }
        if ($userId !== null) {
            $sql .= ' AND pro_user_id = ?';
            $params[] = $userId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('abuseEventPurge')) {
    function abuseEventPurge(PDO $pdo, int $beforeTs): int
    {
        $stmt = $pdo->prepare('DELETE FROM abuse_events WHERE created_at < ?');
        $stmt->execute([abuseTime($beforeTs)]);
        return $stmt->rowCount();
    }
}

// ---------------------------------------------------------------------
// Address track
// ---------------------------------------------------------------------

if (!function_exists('abuseAddressVerdict')) {
    /**
     * Pure decision over an address' totals: 'quarantine' when any limit is
     * reached, 'warn' when any total has reached warn_ratio of its limit,
     * else 'ok'. The reason names the first limit that decided it.
     *
     * @param array{hits:int, bytes:int, strikes:int} $fiveMin
     * @param array{hits:int, bytes:int, strikes:int} $hour
     * @return array{level:string, reason:?string}
     */
    function abuseAddressVerdict(array $fiveMin, array $hour, array $settings): array
    {
        $checks = [
            'messages_5min' => [$fiveMin['hits'], $settings['address_max_5min']],
            'messages_hour' => [$hour['hits'], $settings['address_max_hour']],
            'bytes_hour' => [$hour['bytes'], $settings['address_max_bytes_hour']],
            'strikes_hour' => [$hour['strikes'], $settings['address_max_strikes_hour']],
        ];
        foreach ($checks as $reason => [$value, $limit]) {
            if ($value >= $limit) {
                return ['level' => 'quarantine', 'reason' => $reason];
            }
        }
        foreach ($checks as $reason => [$value, $limit]) {
            if ($value >= $limit * $settings['warn_ratio']) {
                return ['level' => 'warn', 'reason' => $reason];
            }
        }
        return ['level' => 'ok', 'reason' => null];
    }
}

if (!function_exists('abuseAddressRecord')) {
    /**
     * Count one incoming message (and its strikes) for $local, then judge
     * the address. Called by parse.php for every message to a known
     * address, the oversize ones included.
     *
     * @return array{level:string, reason:?string}
     */
    function abuseAddressRecord(PDO $pdo, string $local, int $bytes, int $strikes, int $now, array $settings): array
    {
        abuseCounterAdd($pdo, 'addr', $local, 300, 1, $bytes, $strikes, $now);
        return abuseAddressVerdict(
            abuseCounterSum($pdo, 'addr', $local, $now - ($now % 300) - 1),
            abuseCounterSum($pdo, 'addr', $local, $now - 3600),
            $settings
        );
    }
}

if (!function_exists('abuseAddressStrike')) {
    /**
     * A strike without a message (the message was already counted): an
     * attachment limit hit. Returns the new verdict.
     *
     * @return array{level:string, reason:?string}
     */
    function abuseAddressStrike(PDO $pdo, string $local, int $now, array $settings): array
    {
        abuseCounterAdd($pdo, 'addr', $local, 300, 0, 0, 1, $now);
        return abuseAddressVerdict(
            abuseCounterSum($pdo, 'addr', $local, $now - ($now % 300) - 1),
            abuseCounterSum($pdo, 'addr', $local, $now - 3600),
            $settings
        );
    }
}

if (!function_exists('abuseAddressWarnOnce')) {
    /**
     * Record (and queue a notice for) a warning, at most once per address
     * per 24 hours. Returns whether a new warning was recorded.
     */
    function abuseAddressWarnOnce(PDO $pdo, string $local, ?int $userId, string $reason, int $now): bool
    {
        if (abuseEventCount($pdo, 'address_warning', $local, null, $now - 86400) > 0) {
            return false;
        }
        abuseEventAdd($pdo, 'address_warning', $local, $userId, ['reason' => $reason], $userId !== null, $now);
        return true;
    }
}

if (!function_exists('abuseQuarantineGet')) {
    /** The active quarantine row of $local, or null. */
    function abuseQuarantineGet(PDO $pdo, string $local, int $now): ?array
    {
        $stmt = $pdo->prepare('SELECT local_part, temp_email_id, pro_user_id, reason, quarantined_at, quarantined_until, forwarder_removed FROM address_quarantines WHERE local_part = ? AND (quarantined_until IS NULL OR quarantined_until > ?) LIMIT 1');
        $stmt->execute([$local, abuseTime($now)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('abuseQuarantineAddress')) {
    /**
     * Quarantine $local because of its own traffic. The length follows the
     * number of quarantines of the same address inside 24 hours
     * (quarantine_steps_minutes); past the last step the address is closed
     * (quarantined_until NULL). The owner is notified.
     *
     * $minutes forces a length and $close forces a close: that is the
     * account track and a suspension putting an address on hold. Such a hold
     * does not count as one of the address' own quarantines and sends no
     * notice of its own (the account-level event does).
     *
     * A trigger of the address' own while it is already quarantined changes
     * nothing (a flood crosses the limit in many processes at once). A hold
     * only ever extends an active quarantine, never shortens it, and a
     * forwarder already removed stays counted as removed. The forwarder
     * itself is removed by the caller, see abuseRemoveForwarder().
     *
     * @return array{closed:bool, until:?string, minutes:?int}
     */
    function abuseQuarantineAddress(PDO $pdo, string $local, int $tempEmailId, ?int $userId, string $reason, int $now, array $settings, ?int $minutes = null, bool $close = false): array
    {
        $hold = $close || $minutes !== null;

        $existing = $pdo->prepare('SELECT quarantined_until FROM address_quarantines WHERE local_part = ?');
        $existing->execute([$local]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $existing->closeCursor();
        $active = $row && ($row['quarantined_until'] === null || strtotime((string)$row['quarantined_until']) > $now);

        // Idempotent for the address' own traffic: during a flood several
        // pipe processes cross the limit at the same moment, and each of them
        // must not count as one more step up the ladder. What is already
        // quarantined stays exactly as it is.
        if (!$hold && $active) {
            return ['closed' => $row['quarantined_until'] === null, 'until' => $row['quarantined_until'], 'minutes' => null];
        }

        if (!$hold) {
            $previous = abuseEventCount($pdo, 'address_quarantined', $local, null, $now - 86400);
            $steps = $settings['quarantine_steps_minutes'];
            if ($previous >= count($steps)) {
                $close = true;
            } else {
                $minutes = $steps[$previous];
            }
        }
        $until = $close ? null : abuseTime($now + 60 * (int)$minutes);

        if ($row) {
            // A hold on top of an active quarantine keeps the longer of the
            // two; an expired row not yet released by cron is simply renewed.
            $current = $row['quarantined_until'];
            if ($active && $current === null) {
                $until = null;
            } elseif ($active && $until !== null && strtotime((string)$current) > strtotime($until)) {
                $until = (string)$current;
            }
            $pdo->prepare('UPDATE address_quarantines SET temp_email_id = ?, pro_user_id = ?, reason = ?, quarantined_at = ?, quarantined_until = ? WHERE local_part = ?')
                ->execute([$tempEmailId, $userId, $reason, abuseTime($now), $until, $local]);
        } else {
            try {
                $pdo->prepare('INSERT INTO address_quarantines (local_part, temp_email_id, pro_user_id, reason, quarantined_at, quarantined_until, forwarder_removed) VALUES (?, ?, ?, ?, ?, ?, 0)')
                    ->execute([$local, $tempEmailId, $userId, $reason, abuseTime($now), $until]);
            } catch (PDOException $e) {
                // Another process quarantined it a moment ago: that one won,
                // and this trigger is the same flood.
                $again = abuseQuarantineGet($pdo, $local, $now);
                if ($again === null) {
                    throw $e;
                }
                return ['closed' => $again['quarantined_until'] === null, 'until' => $again['quarantined_until'], 'minutes' => null];
            }
        }

        if ($hold) {
            abuseEventAdd($pdo, 'address_hold', $local, $userId, ['reason' => $reason, 'until' => $until], false, $now);
        } else {
            // A close is also recorded as a quarantine, so the next trigger
            // inside 24 hours still reads as "past the last step".
            abuseEventAdd($pdo, 'address_quarantined', $local, $userId, ['reason' => $reason, 'until' => $until, 'minutes' => $close ? null : $minutes], !$close && $until !== null && $userId !== null, $now);
            if ($close) {
                abuseEventAdd($pdo, 'address_closed', $local, $userId, ['reason' => $reason], $userId !== null, $now);
            }
        }

        return ['closed' => $until === null, 'until' => $until, 'minutes' => $until === null ? null : $minutes];
    }
}

if (!function_exists('abuseRemoveForwarder')) {
    /**
     * Ask DirectAdmin to drop the forwarder of a quarantined address and
     * record the outcome. $deleter is function(string $alias): bool. A
     * failure leaves forwarder_removed = 0 for cron/abuse-guard.php to
     * retry; until then parse.php discards what still arrives.
     */
    function abuseRemoveForwarder(PDO $pdo, string $local, callable $deleter): bool
    {
        $ok = (bool)$deleter($local);
        if ($ok) {
            $pdo->prepare('UPDATE address_quarantines SET forwarder_removed = 1 WHERE local_part = ?')->execute([$local]);
        }
        return $ok;
    }
}

if (!function_exists('abuseRetryForwarderRemovals')) {
    /** Retry every active quarantine whose forwarder is still there. */
    function abuseRetryForwarderRemovals(PDO $pdo, callable $deleter, int $now): array
    {
        $stmt = $pdo->prepare('SELECT local_part FROM address_quarantines WHERE forwarder_removed = 0 AND (quarantined_until IS NULL OR quarantined_until > ?)');
        $stmt->execute([abuseTime($now)]);
        $out = ['removed' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $local) {
            abuseRemoveForwarder($pdo, (string)$local, $deleter) ? $out['removed']++ : $out['failed']++;
        }
        return $out;
    }
}

if (!function_exists('abuseReleaseDue')) {
    /**
     * Reopen every quarantine whose time is up. $creator is
     * function(string $alias): bool. An address that no longer exists, has
     * expired or now belongs to another row just loses its quarantine; a
     * live one gets its forwarder back, and keeps its quarantine (to be
     * retried next run) when that fails. $onlyUserId limits the run to one
     * account (an admin lifting a suspension).
     *
     * @return array{released:int, dropped:int, failed:int}
     */
    function abuseReleaseDue(PDO $pdo, callable $creator, int $now, ?int $onlyUserId = null): array
    {
        $sql = 'SELECT local_part, temp_email_id, pro_user_id, forwarder_removed FROM address_quarantines WHERE quarantined_until IS NOT NULL AND quarantined_until <= ?';
        $params = [abuseTime($now)];
        if ($onlyUserId !== null) {
            $sql .= ' AND pro_user_id = ?';
            $params[] = $onlyUserId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = ['released' => 0, 'dropped' => 0, 'failed' => 0];
        $addr = $pdo->prepare('SELECT id, expires_at FROM temp_emails WHERE unique_address = ? LIMIT 1');
        $delete = $pdo->prepare('DELETE FROM address_quarantines WHERE local_part = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $local = (string)$row['local_part'];
            $addr->execute([$local]);
            $live = $addr->fetch(PDO::FETCH_ASSOC);
            $addr->closeCursor();
            $alive = $live
                && (int)$live['id'] === (int)$row['temp_email_id']
                && ($live['expires_at'] === null || strtotime((string)$live['expires_at']) > $now);

            if (!$alive || (int)$row['forwarder_removed'] !== 1) {
                // Nothing to give back: the address is gone, or DirectAdmin
                // never dropped its forwarder in the first place.
                $delete->execute([$local]);
                if ($alive) {
                    abuseEventAdd($pdo, 'address_released', $local, $row['pro_user_id'] !== null ? (int)$row['pro_user_id'] : null, [], false, $now);
                    $out['released']++;
                } else {
                    $out['dropped']++;
                }
                continue;
            }
            if ((bool)$creator($local)) {
                $delete->execute([$local]);
                abuseEventAdd($pdo, 'address_released', $local, $row['pro_user_id'] !== null ? (int)$row['pro_user_id'] : null, [], false, $now);
                $out['released']++;
            } else {
                $out['failed']++;
            }
        }
        return $out;
    }
}

if (!function_exists('abuseQuarantineForget')) {
    /**
     * The address is being deleted: drop its quarantine. Returns true when
     * its forwarder is already gone, so the caller need not ask DirectAdmin
     * to delete it a second time.
     */
    function abuseQuarantineForget(PDO $pdo, string $local): bool
    {
        $stmt = $pdo->prepare('SELECT forwarder_removed FROM address_quarantines WHERE local_part = ?');
        $stmt->execute([$local]);
        $removed = $stmt->fetchColumn();
        if ($removed === false) {
            return false;
        }
        $pdo->prepare('DELETE FROM address_quarantines WHERE local_part = ?')->execute([$local]);
        return (int)$removed === 1;
    }
}

if (!function_exists('abuseQuarantineReopen')) {
    /** Make a quarantine due now (an admin reopening it). */
    function abuseQuarantineReopen(PDO $pdo, string $local, int $now): bool
    {
        $stmt = $pdo->prepare('UPDATE address_quarantines SET quarantined_until = ? WHERE local_part = ?');
        $stmt->execute([abuseTime($now), $local]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('abuseQuarantinesForUser')) {
    /**
     * The account's active quarantines keyed by local part:
     * ['until' => ?string, 'closed' => bool].
     */
    function abuseQuarantinesForUser(PDO $pdo, int $userId, int $now): array
    {
        $stmt = $pdo->prepare('SELECT local_part, quarantined_until FROM address_quarantines WHERE pro_user_id = ? AND (quarantined_until IS NULL OR quarantined_until > ?)');
        $stmt->execute([$userId, abuseTime($now)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['local_part']] = [
                'until' => $row['quarantined_until'] !== null ? (string)$row['quarantined_until'] : null,
                'closed' => $row['quarantined_until'] === null,
            ];
        }
        return $out;
    }
}

// ---------------------------------------------------------------------
// Attachments
// ---------------------------------------------------------------------

if (!function_exists('abuseLimitAttachments')) {
    /**
     * Keep at most $maxRegular real attachments and $maxInline inline images
     * (a Content-ID and an image/* type), in message order. Works on
     * MailParser's attachment arrays.
     *
     * @return array{0:array, 1:int, 2:int} [kept, dropped regular, dropped inline]
     */
    function abuseLimitAttachments(array $attachments, int $maxRegular, int $maxInline): array
    {
        $kept = [];
        $regular = 0;
        $inline = 0;
        $droppedRegular = 0;
        $droppedInline = 0;
        foreach ($attachments as $attachment) {
            $cid = is_array($attachment) ? trim((string)($attachment['content_id'] ?? '')) : '';
            $mime = is_array($attachment) ? strtolower((string)($attachment['mime_type'] ?? '')) : '';
            if ($cid !== '' && str_starts_with($mime, 'image/')) {
                if ($inline >= $maxInline) {
                    $droppedInline++;
                    continue;
                }
                $inline++;
            } else {
                if ($regular >= $maxRegular) {
                    $droppedRegular++;
                    continue;
                }
                $regular++;
            }
            $kept[] = $attachment;
        }
        return [$kept, $droppedRegular, $droppedInline];
    }
}

// ---------------------------------------------------------------------
// Rate limits (address creation, webhooks)
// ---------------------------------------------------------------------

if (!function_exists('abuseRateLimit')) {
    /**
     * Check one attempt against $rules, each [scope, subject, limit,
     * periodSeconds] counted in 1-hour buckets (a period of 3600 is the
     * current clock hour, 86400 the last 24 buckets). Over any rule: the
     * attempt is refused and counted as a strike on that rule's bucket.
     * Otherwise it is counted as a hit on every rule.
     *
     * @return array{limited:bool, first:bool, rule:?array}
     *         first is true for the first refused attempt of the bucket, so
     *         a caller escalates once per hour rather than per request.
     */
    function abuseRateLimit(PDO $pdo, array $rules, int $now): array
    {
        foreach ($rules as $rule) {
            [$scope, $subject, $limit, $period] = $rule;
            $sum = abuseCounterSum($pdo, (string)$scope, (string)$subject, $period <= 3600 ? $now - ($now % 3600) - 1 : $now - (int)$period);
            if ($sum['hits'] >= (int)$limit) {
                abuseCounterAdd($pdo, (string)$scope, (string)$subject, 3600, 0, 0, 1, $now);
                $strikes = abuseCounterSum($pdo, (string)$scope, (string)$subject, $now - ($now % 3600) - 1)['strikes'];
                return ['limited' => true, 'first' => $strikes === 1, 'rule' => $rule];
            }
        }
        $seen = [];
        foreach ($rules as [$scope, $subject]) {
            $key = $scope . "\0" . $subject;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            abuseCounterAdd($pdo, (string)$scope, (string)$subject, 3600, 1, 0, 0, $now);
        }
        return ['limited' => false, 'first' => false, 'rule' => null];
    }
}

// ---------------------------------------------------------------------
// Account track
// ---------------------------------------------------------------------

if (!function_exists('abuseAccountStrike')) {
    /**
     * An account went over a rate limit (once per bucket, see
     * abuseRateLimit()'s `first`). Escalates:
     *  1. the first strike inside 24 h: a warning to the account;
     *  2. account_strikes_day strikes inside 24 h: every address of the
     *     account quarantined for account_quarantine_minutes (the forwarders
     *     are removed by cron/abuse-guard.php; parse.php discards meanwhile);
     *  3. account_quarantines_week such quarantines inside 7 days: a
     *     suspension proposal for an admin — never a suspension by itself.
     *
     * @return string 'warned' | 'strike' | 'quarantined' | 'proposed'
     */
    function abuseAccountStrike(PDO $pdo, int $userId, string $rule, int $now, array $settings): string
    {
        $strikesBefore = abuseEventCount($pdo, 'account_strike', null, $userId, $now - 86400);
        abuseEventAdd($pdo, 'account_strike', 'user:' . $userId, $userId, ['rule' => $rule], false, $now);

        if ($strikesBefore === 0) {
            abuseEventAdd($pdo, 'account_warning', 'user:' . $userId, $userId, ['rule' => $rule], true, $now);
            return 'warned';
        }
        if ($strikesBefore + 1 < $settings['account_strikes_day']
            || abuseEventCount($pdo, 'account_quarantined', null, $userId, $now - 86400) > 0) {
            return 'strike';
        }

        $stmt = $pdo->prepare('SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ?');
        $stmt->execute([$userId]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($addresses as $a) {
            abuseQuarantineAddress($pdo, (string)$a['unique_address'], (int)$a['id'], $userId, 'account_rate_limit', $now, $settings, $settings['account_quarantine_minutes']);
        }
        abuseEventAdd($pdo, 'account_quarantined', 'user:' . $userId, $userId, [
            'rule' => $rule,
            'addresses' => count($addresses),
            'minutes' => $settings['account_quarantine_minutes'],
        ], true, $now);

        if (abuseEventCount($pdo, 'account_quarantined', null, $userId, $now - 7 * 86400) >= $settings['account_quarantines_week']
            && abuseSuspensionPending($pdo, $userId) === null) {
            abuseEventAdd($pdo, 'account_suspend_proposed', 'user:' . $userId, $userId, ['rule' => $rule, 'user_id' => $userId], true, $now);
            return 'proposed';
        }
        return 'quarantined';
    }
}

if (!function_exists('abuseSuspensionPending')) {
    /**
     * The open suspension proposal of $userId (an account_suspend_proposed
     * event with no later decision), or null.
     */
    function abuseSuspensionPending(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, created_at, detail FROM abuse_events WHERE kind = 'account_suspend_proposed' AND pro_user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$proposal) {
            return null;
        }
        $decided = $pdo->prepare("SELECT COUNT(*) FROM abuse_events WHERE kind IN ('account_suspended', 'account_suspend_dismissed') AND pro_user_id = ? AND id > ?");
        $decided->execute([$userId, (int)$proposal['id']]);
        return (int)$decided->fetchColumn() > 0 ? null : $proposal;
    }
}

if (!function_exists('abuseSuspensionProposals')) {
    /** Every open proposal, oldest first: [['user_id', 'id', 'created_at', 'detail'], ...]. */
    function abuseSuspensionProposals(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT DISTINCT pro_user_id FROM abuse_events WHERE kind = 'account_suspend_proposed'");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $pending = abuseSuspensionPending($pdo, (int)$userId);
            if ($pending !== null) {
                $out[] = ['user_id' => (int)$userId] + $pending;
            }
        }
        usort($out, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        return $out;
    }
}

if (!function_exists('abuseAccountSuspended')) {
    function abuseAccountSuspended(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null;
    }
}

if (!function_exists('abuseSuspendAccount')) {
    /**
     * An admin's confirmed suspension: pro_users.suspended_at is set and
     * every address of the account is closed. The forwarders are removed by
     * the caller (abuseRetryForwarderRemovals) or by the next cron run.
     * Returns the number of addresses closed.
     */
    function abuseSuspendAccount(PDO $pdo, int $userId, int $adminId, int $now, array $settings): int
    {
        $pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')->execute([abuseTime($now), $userId]);
        $stmt = $pdo->prepare('SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ?');
        $stmt->execute([$userId]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($addresses as $a) {
            abuseQuarantineAddress($pdo, (string)$a['unique_address'], (int)$a['id'], $userId, 'account_suspended', $now, $settings, null, true);
        }
        abuseEventAdd($pdo, 'account_suspended', 'user:' . $userId, $userId, ['admin_id' => $adminId, 'addresses' => count($addresses)], false, $now);
        return count($addresses);
    }
}

if (!function_exists('abuseUnsuspendAccount')) {
    /**
     * Lift a suspension: suspended_at is cleared and every quarantine of the
     * account is made due, so abuseReleaseDue() gives the forwarders back.
     */
    function abuseUnsuspendAccount(PDO $pdo, int $userId, int $adminId, int $now): void
    {
        $pdo->prepare('UPDATE pro_users SET suspended_at = NULL WHERE id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE address_quarantines SET quarantined_until = ? WHERE pro_user_id = ?')->execute([abuseTime($now), $userId]);
        abuseEventAdd($pdo, 'account_unsuspended', 'user:' . $userId, $userId, ['admin_id' => $adminId], false, $now);
    }
}

if (!function_exists('abuseDismissProposal')) {
    function abuseDismissProposal(PDO $pdo, int $userId, int $adminId, int $now): void
    {
        abuseEventAdd($pdo, 'account_suspend_dismissed', 'user:' . $userId, $userId, ['admin_id' => $adminId], false, $now);
    }
}

// ---------------------------------------------------------------------
// Notices
// ---------------------------------------------------------------------

if (!function_exists('abuseNoticeText')) {
    /**
     * Subject and body of the mail an event sends, or null for a kind that
     * mails nobody. $address is the full address the event is about.
     * $forAdmin selects the admin wording of a suspension proposal.
     *
     * @return array{subject:string, body:string}|null
     */
    function abuseNoticeText(string $kind, ?string $address, array $detail, string $baseUrl, bool $forAdmin = false): ?array
    {
        $sign = "\n\nRegards,\nThe Mail Shield Team";
        $profile = rtrim($baseUrl, '/') . '/pro_profile_page.php';
        switch ($kind) {
            case 'address_warning':
                return [
                    'subject' => 'Unusual amount of mail to ' . $address,
                    'body' => "Hello,\n\n" . $address . " is receiving an unusual amount of mail right now.\n\n"
                        . "If it goes on, we will pause the address for a while to protect it and our service. "
                        . "While it is paused, mail to it is refused and returned to the sender.\n\n"
                        . "Nothing needs doing on your part. If you no longer need the address, you can delete it here:\n" . $profile . $sign,
                ];
            case 'address_quarantined':
                return [
                    'subject' => $address . ' is paused',
                    'body' => "Hello,\n\n" . $address . " received far more mail than normal, so we have paused it"
                        . " until " . ($detail['until'] ?? 'later') . " (server time).\n\n"
                        . "While it is paused, mail to the address is refused and returned to the sender. "
                        . "It opens again by itself. Mail it already received is untouched.\n\n"
                        . "Your addresses: " . $profile . $sign,
                ];
            case 'address_closed':
                return [
                    'subject' => $address . ' has been closed',
                    'body' => "Hello,\n\n" . $address . " has been paused several times in a short period because of the amount of mail sent to it,"
                        . " so it is now closed and refuses all mail.\n\n"
                        . "Mail it already received is untouched. We recommend deleting the address and creating a new one: " . $profile
                        . "\n\nIf you need this exact address back, reply to this message." . $sign,
                ];
            case 'account_warning':
                return [
                    'subject' => 'Your Mail Shield account is creating addresses unusually fast',
                    'body' => "Hello,\n\nYour account has reached a limit on how many addresses can be created in a short time.\n\n"
                        . "If this continues, the addresses of the account will be paused for a while."
                        . " If you did not do this yourself, change your password: " . $profile . $sign,
                ];
            case 'account_quarantined':
                return [
                    'subject' => 'The addresses of your Mail Shield account are paused',
                    'body' => "Hello,\n\nYour account has repeatedly gone over the limits for creating addresses,"
                        . " so all of its addresses are paused for " . (int)($detail['minutes'] ?? 0) . " minutes."
                        . " Mail to them is refused and returned to the sender meanwhile; they open again by themselves.\n\n"
                        . "If you did not do this yourself, change your password: " . $profile . $sign,
                ];
            case 'webhook_capped':
                return [
                    'subject' => 'A webhook reached its hourly limit',
                    'body' => "Hello,\n\nYour webhook \"" . ($detail['name'] ?? 'webhook') . "\" reached its limit of "
                        . (int)($detail['limit'] ?? 0) . " notifications per hour. Further notifications are skipped until the hour is over."
                        . " Mail is still received and stored as usual.\n\nYour webhooks: " . $profile . $sign,
                ];
            case 'account_suspend_proposed':
                if (!$forAdmin) {
                    return null;
                }
                return [
                    'subject' => 'Mail Shield: account suspension proposed',
                    'body' => "Account #" . (int)($detail['user_id'] ?? 0) . " has had its addresses quarantined repeatedly for going over the rate limits"
                        . " (last rule: " . ($detail['rule'] ?? '?') . ").\n\nConfirm or dismiss the suspension: " . rtrim($baseUrl, '/') . '/abuse_admin.php',
                ];
        }
        return null;
    }
}
