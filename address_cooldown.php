<?php

declare(strict_types=1);

/**
 * Cool-off list for released personal addresses (table address_cooldowns,
 * created by migrate_address_cooldowns.php).
 *
 * When a personal address is removed — deleted by its owner, dropped after
 * the Pro grace period, or removed with the account — its local part is
 * reserved for the account that held it for $config['address_cooldown']
 * ['months'] (default 6). Nobody else can create it in that time, so mail
 * meant for the previous owner (password resets from services the address
 * was registered with) cannot be picked up by a stranger. The owner can
 * create it again at any time, which ends the reservation.
 *
 * Each account holds at most ['max_per_user'] (default 30) active
 * reservations; adding one more drops the oldest, which is then free for
 * anyone.
 *
 * Every function takes the PDO explicitly and does not require config.php,
 * so tests/address_cooldown_test.php runs them on SQLite. Times are
 * computed in PHP rather than with NOW() for the same reason. Callers check
 * tableHasColumn('address_cooldowns', 'local_part') first; the functions
 * themselves throw on any database error so the caller can fail closed.
 */

if (!function_exists('addressCooldownSettings')) {
    /**
     * [months, max_per_user] from $config['address_cooldown'], with the
     * defaults when config.php has not been loaded.
     */
    function addressCooldownSettings(): array
    {
        $cfg = $GLOBALS['config']['address_cooldown'] ?? [];
        $months = max(1, (int)($cfg['months'] ?? 6));
        $max = max(1, (int)($cfg['max_per_user'] ?? 30));
        return [$months, $max];
    }
}

if (!function_exists('addressCooldownAdd')) {
    /**
     * Reserve $local for $userId from now until now + $months. A previous
     * row for the same local part (an expired reservation, or the same
     * owner's) is replaced. Then the owner's active reservations beyond
     * $maxPerUser, oldest first, are removed, releasing those addresses.
     * Run it inside the caller's transaction so the reservation and the
     * address deletion commit together.
     */
    function addressCooldownAdd(PDO $pdo, int $userId, string $local, int $months, int $maxPerUser, ?int $now = null): void
    {
        $now = $now ?? time();
        $releasedAt = date('Y-m-d H:i:s', $now);
        $blockedUntil = date('Y-m-d H:i:s', (int)strtotime('+' . max(1, $months) . ' months', $now));

        $pdo->prepare("DELETE FROM address_cooldowns WHERE local_part = ?")->execute([$local]);
        $pdo->prepare("INSERT INTO address_cooldowns (local_part, pro_user_id, released_at, blocked_until) VALUES (?, ?, ?, ?)")
            ->execute([$local, $userId, $releasedAt, $blockedUntil]);

        $stmt = $pdo->prepare("SELECT id FROM address_cooldowns WHERE pro_user_id = ? AND blocked_until > ? ORDER BY released_at DESC, id DESC");
        $stmt->execute([$userId, $releasedAt]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $overflow = array_slice($ids, max(1, $maxPerUser));
        if ($overflow) {
            $del = $pdo->prepare("DELETE FROM address_cooldowns WHERE id = ?");
            foreach ($overflow as $id) {
                $del->execute([(int)$id]);
            }
        }
    }
}

if (!function_exists('addressCooldownHolder')) {
    /**
     * The account id holding an active reservation on $local, or null when
     * the local part is free. An expired row counts as free.
     */
    function addressCooldownHolder(PDO $pdo, string $local, ?int $now = null): ?int
    {
        $stmt = $pdo->prepare("SELECT pro_user_id FROM address_cooldowns WHERE local_part = ? AND blocked_until > ? LIMIT 1");
        $stmt->execute([$local, date('Y-m-d H:i:s', $now ?? time())]);
        $holder = $stmt->fetchColumn();
        return $holder === false ? null : (int)$holder;
    }
}

if (!function_exists('addressCooldownClear')) {
    /** End $userId's reservation on $local (the owner has taken it back). */
    function addressCooldownClear(PDO $pdo, int $userId, string $local): void
    {
        $pdo->prepare("DELETE FROM address_cooldowns WHERE local_part = ? AND pro_user_id = ?")->execute([$local, $userId]);
    }
}

if (!function_exists('addressCooldownList')) {
    /**
     * $userId's active reservations, newest first, as
     * [['address' => ..., 'released_at' => ..., 'blocked_until' => ...], ...].
     */
    function addressCooldownList(PDO $pdo, int $userId, ?int $now = null): array
    {
        $stmt = $pdo->prepare("SELECT local_part, released_at, blocked_until FROM address_cooldowns WHERE pro_user_id = ? AND blocked_until > ? ORDER BY released_at DESC, id DESC");
        $stmt->execute([$userId, date('Y-m-d H:i:s', $now ?? time())]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'address' => (string)$row['local_part'],
                'released_at' => (string)$row['released_at'],
                'blocked_until' => (string)$row['blocked_until'],
            ];
        }
        return $out;
    }
}

if (!function_exists('addressCooldownPurgeExpired')) {
    /** Delete every reservation whose blocked_until has passed; returns the count. */
    function addressCooldownPurgeExpired(PDO $pdo, ?int $now = null): int
    {
        $stmt = $pdo->prepare("DELETE FROM address_cooldowns WHERE blocked_until <= ?");
        $stmt->execute([date('Y-m-d H:i:s', $now ?? time())]);
        return $stmt->rowCount();
    }
}
