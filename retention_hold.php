<?php

declare(strict_types=1);

/**
 * Retention holds (table retention_holds, created by
 * migrate_retention_holds.php) — epic #369.
 *
 * A Pro account may start a hold on the deletion of its own incoming mail:
 * while a hold is active, each of its Sticky addresses' messages that would
 * otherwise expire within the hold window is pushed out to
 * received_at + the hold length, and never shortened. A hold lasts
 * $config['retention_hold']['days'] (default 30) and an account may start at
 * most ['max_per_year'] (default 4) of them in any 365-day window, counted
 * whether or not they were ended early.
 *
 * Every function takes the PDO explicitly, does not require config.php and
 * never reads $_SESSION or echoes, so tests/retention_hold_test.php runs them
 * on SQLite. Times are computed in PHP (DateTimeImmutable) rather than with
 * NOW() for the same reason. Write functions return ['ok' => true, ...] or
 * ['ok' => false, 'error' => '<message>'].
 *
 * Pro entitlement is deliberately not checked here — the caller decides that
 * with proUserIsPro(), the same split as mailbox_service.php.
 */

if (!function_exists('retentionHoldSettings')) {
    /**
     * [days, max_per_year] from $config['retention_hold'], with the defaults
     * when config.php has not been loaded.
     */
    function retentionHoldSettings(): array
    {
        $cfg = $GLOBALS['config']['retention_hold'] ?? [];
        $days = max(1, (int)($cfg['days'] ?? 30));
        $max = max(1, (int)($cfg['max_per_year'] ?? 4));
        return [$days, $max];
    }
}

if (!function_exists('retentionHoldAvailable')) {
    /**
     * Whether the retention_holds table exists. Fail closed: any error means
     * false, so a database the migration has not run on simply offers no
     * hold.
     */
    function retentionHoldAvailable(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query('SELECT 1 FROM retention_holds LIMIT 1');
            return $stmt !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('retentionHoldActive')) {
    /**
     * The account's active hold as ['id', 'started_at', 'ends_at'], or null
     * when it has none. A hold is active while ended_at IS NULL AND
     * ends_at > $now. Throws on a database error, so the caller can decide
     * whether that is fatal or fail-open.
     */
    function retentionHoldActive(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): ?array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $stmt = $pdo->prepare(
            "SELECT id, started_at, ends_at FROM retention_holds
             WHERE pro_user_id = ? AND ended_at IS NULL AND ends_at > ?
             ORDER BY started_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $now->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'started_at' => (string)$row['started_at'],
            'ends_at' => (string)$row['ends_at'],
        ];
    }
}

if (!function_exists('retentionHoldWindow')) {
    /**
     * How many holds the account has started within the last 365 days
     * (`used`) and the oldest of those (`oldest`, 'Y-m-d H:i:s' or null),
     * which is what next_available_at is derived from.
     */
    function retentionHoldWindow(PDO $pdo, int $userId, DateTimeImmutable $now): array
    {
        $cutoff = $now->modify('-365 days')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS used, MIN(started_at) AS oldest
             FROM retention_holds WHERE pro_user_id = ? AND started_at > ?"
        );
        $stmt->execute([$userId, $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'used' => (int)($row['used'] ?? 0),
            'oldest' => isset($row['oldest']) && $row['oldest'] !== null ? (string)$row['oldest'] : null,
        ];
    }
}

if (!function_exists('retentionHoldStatus')) {
    /**
     * Everything a page needs to render the hold: whether the feature is
     * available at all, the active hold (or null), how many of the yearly
     * allowance are used, the allowance and length, and — only once the
     * allowance is spent — when the next hold becomes possible (the oldest
     * counted hold plus 365 days).
     */
    function retentionHoldStatus(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array
    {
        [$days, $max] = retentionHoldSettings();
        $now = $now ?? new DateTimeImmutable('now');

        if (!retentionHoldAvailable($pdo)) {
            return [
                'available' => false,
                'active' => null,
                'used' => 0,
                'max' => $max,
                'days' => $days,
                'next_available_at' => null,
            ];
        }

        $active = retentionHoldActive($pdo, $userId, $now);
        $window = retentionHoldWindow($pdo, $userId, $now);

        $nextAvailableAt = null;
        if ($window['used'] >= $max && $window['oldest'] !== null) {
            $nextAvailableAt = (new DateTimeImmutable($window['oldest']))
                ->modify('+365 days')
                ->format('Y-m-d H:i:s');
        }

        return [
            'available' => true,
            'active' => $active,
            'used' => $window['used'],
            'max' => $max,
            'days' => $days,
            'next_available_at' => $nextAvailableAt,
        ];
    }
}

if (!function_exists('retentionHoldExtendMail')) {
    /**
     * Push out the account's Sticky-address mail that would expire during the
     * hold: for every message with expires_at > $now, set it to
     * received_at + $days, but only where that is later than the expiry it
     * already has — a hold never shortens a message' life. Rows are decided
     * and rewritten one at a time in PHP rather than with a CASE, so the
     * same code runs on MySQL and SQLite. Returns the number of rows
     * updated. Run inside the caller's transaction.
     */
    function retentionHoldExtendMail(PDO $pdo, int $userId, int $days, DateTimeImmutable $now): int
    {
        $stmt = $pdo->prepare(
            "SELECT se.id, se.received_at, se.expires_at
             FROM stored_emails se
             JOIN temp_emails te ON te.id = se.temp_email_id
             WHERE te.pro_user_id = ? AND te.is_personal = 1
               AND se.expires_at IS NOT NULL AND se.expires_at > ?"
        );
        $stmt->execute([$userId, $now->format('Y-m-d H:i:s')]);

        $update = $pdo->prepare("UPDATE stored_emails SET expires_at = ? WHERE id = ?");
        $extended = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (empty($row['received_at'])) {
                continue;
            }
            try {
                $newExpiry = (new DateTimeImmutable((string)$row['received_at']))
                    ->modify('+' . $days . ' days')
                    ->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                continue;
            }
            if ($newExpiry > (string)$row['expires_at']) {
                $update->execute([$newExpiry, (int)$row['id']]);
                $extended++;
            }
        }
        return $extended;
    }
}

if (!function_exists('retentionHoldStart')) {
    /**
     * Start a hold for the account. In one transaction: refuse when a hold is
     * already active or when the yearly allowance is spent, insert the row
     * (ends_at = now + days) and push out the account's Sticky-address mail
     * that expires within the window. Returns
     * ['ok' => true, 'hold' => row, 'extended' => <rows updated>].
     */
    function retentionHoldStart(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array
    {
        [$days, $max] = retentionHoldSettings();
        $now = $now ?? new DateTimeImmutable('now');
        $nowStr = $now->format('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            if (retentionHoldActive($pdo, $userId, $now) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'A hold is already active'];
            }

            $window = retentionHoldWindow($pdo, $userId, $now);
            if ($window['used'] >= $max) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => "You have used all {$max} holds for this year"];
            }

            $endsAt = $now->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
            $ins = $pdo->prepare("INSERT INTO retention_holds (pro_user_id, started_at, ends_at, ended_at) VALUES (?, ?, ?, NULL)");
            $ins->execute([$userId, $nowStr, $endsAt]);
            $holdId = (int)$pdo->lastInsertId();

            $extended = retentionHoldExtendMail($pdo, $userId, $days, $now);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'Retention hold could not be started', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return ['ok' => false, 'error' => 'Could not start retention hold'];
        }

        if (function_exists('logMessage')) {
            logMessage('INFO', 'Retention hold started', ['user_id' => $userId, 'hold_id' => $holdId, 'extended' => $extended]);
        }

        return [
            'ok' => true,
            'hold' => ['id' => $holdId, 'started_at' => $nowStr, 'ends_at' => $endsAt, 'ended_at' => null],
            'extended' => $extended,
        ];
    }
}

if (!function_exists('retentionHoldEnd')) {
    /**
     * End the account's active hold early. Mail already extended keeps its
     * new expiry — this touches no stored_emails row. Returns
     * ['ok' => false, 'error' => 'No active hold'] when there is none.
     */
    function retentionHoldEnd(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        try {
            $active = retentionHoldActive($pdo, $userId, $now);
            if ($active === null) {
                return ['ok' => false, 'error' => 'No active hold'];
            }
            $endedAt = $now->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE retention_holds SET ended_at = ? WHERE id = ? AND ended_at IS NULL")
                ->execute([$endedAt, $active['id']]);
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'Retention hold could not be ended', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return ['ok' => false, 'error' => 'Could not end retention hold'];
        }

        if (function_exists('logMessage')) {
            logMessage('INFO', 'Retention hold ended', ['user_id' => $userId, 'hold_id' => $active['id']]);
        }

        $active['ended_at'] = $endedAt;
        return ['ok' => true, 'hold' => $active];
    }
}

if (!function_exists('retentionHoldDaysFor')) {
    /**
     * The hold length while the account has an active hold, else null. Never
     * throws: step 3 calls this on the mail path, which must be fail-open, so
     * a missing table, a database error or no active hold all answer null.
     */
    function retentionHoldDaysFor(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): ?int
    {
        try {
            if (!retentionHoldAvailable($pdo)) {
                return null;
            }
            if (retentionHoldActive($pdo, $userId, $now) === null) {
                return null;
            }
            [$days] = retentionHoldSettings();
            return $days;
        } catch (Throwable $e) {
            return null;
        }
    }
}
