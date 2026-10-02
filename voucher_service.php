<?php

declare(strict_types=1);

/**
 * voucher_service.php — the voucher business logic (epic #359, step 2/4;
 * the schema is migrate_vouchers.php, the read-only audit check_vouchers.php).
 *
 * The one place a voucher is created, listed, (de)activated and its
 * redemptions read. The admin page (step 3) and a future seller API both call
 * these functions rather than writing their own INSERT, so their rules —
 * which durations and use counts are allowed, what an actor may touch, never
 * logging a code — cannot drift apart.
 *
 * Like mailbox_service.php: every function takes PDO $pdo explicitly, never
 * reads $_SESSION and never echoes. The caller decides who is acting and
 * passes that in as an $actor; every function returns either
 * ['ok' => true, ...] or ['ok' => false, 'error' => '<message>'].
 *
 * Actor: ['type' => 'admin', 'id' => int] is stored as source = 'admin' with
 * created_by_user_id = id, ['type' => 'issuer', 'id' => int] as
 * source = 'issuer' with issuer_id = id. Anything else is refused. Nothing
 * calls this with an issuer actor yet — that is the future seller API — but
 * the rules it needs (external_ref, idempotent retries, an issuer only
 * touching its own codes) are implemented and tested here so that API is an
 * entry point and not a second set of rules.
 *
 * Row shape: a voucher is returned as id, code, is_active (bool),
 * current_uses, max_uses, duration_days (int or null), expires_at, source,
 * note, created_at and batch_id (string or null).
 *
 * A voucher code is a credential: it is never logged, only ever stored.
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

const VOUCHER_SOURCE_ADMIN = 'admin';
const VOUCHER_SOURCE_ISSUER = 'issuer';

/** Code shape: MS-XXXX-XXXX-XXXX, the alphabet without 0/O/1/I/L. */
const VOUCHER_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const VOUCHER_CODE_GROUPS = 3;
const VOUCHER_CODE_GROUP_LENGTH = 4;
/** How many generated codes are tried before giving up on a collision. */
const VOUCHER_CODE_ATTEMPTS = 5;
/** The same rule redeemVoucherForEmail() applies to a code it is handed. */
const VOUCHER_CODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

const VOUCHER_DURATION_MIN_DAYS = 1;
const VOUCHER_DURATION_MAX_DAYS = 3650;
const VOUCHER_MAX_USES_MIN = 1;
const VOUCHER_MAX_USES_MAX = 10000;
const VOUCHER_NOTE_MAX_LENGTH = 255;
const VOUCHER_EXTERNAL_REF_MAX_LENGTH = 128;
const VOUCHER_LIST_MAX_LIMIT = 500;

/**
 * A fresh voucher code (voucherGenerateCode()), e.g. MS-7K4P-QW2M-XR9T. Each
 * character is drawn with random_int() from an alphabet without 0/O/1/I/L so
 * a code read aloud or off a screen cannot be mistyped into another valid
 * one. The result always matches VOUCHER_CODE_PATTERN and is 17 characters.
 */
function voucherGenerateCode(): string
{
    $groups = [];
    $max = strlen(VOUCHER_CODE_ALPHABET) - 1;
    for ($g = 0; $g < VOUCHER_CODE_GROUPS; $g++) {
        $part = '';
        for ($i = 0; $i < VOUCHER_CODE_GROUP_LENGTH; $i++) {
            $part .= VOUCHER_CODE_ALPHABET[random_int(0, $max)];
        }
        $groups[] = $part;
    }
    return 'MS-' . implode('-', $groups);
}

/**
 * The stored values an actor maps to, or null when it is not one of the two
 * accepted shapes. Internal to this file.
 */
function voucherActorResolve(array $actor): ?array
{
    $type = $actor['type'] ?? null;
    $id = $actor['id'] ?? null;
    if (!is_int($id) || $id <= 0) {
        return null;
    }
    if ($type === VOUCHER_SOURCE_ADMIN) {
        return ['source' => VOUCHER_SOURCE_ADMIN, 'created_by_user_id' => $id, 'issuer_id' => null];
    }
    if ($type === VOUCHER_SOURCE_ISSUER) {
        return ['source' => VOUCHER_SOURCE_ISSUER, 'created_by_user_id' => null, 'issuer_id' => $id];
    }
    return null;
}

/** The actor's own id, whichever kind it is. Internal to this file. */
function voucherActorId(array $actorRow): int
{
    return (int) ($actorRow['created_by_user_id'] ?? $actorRow['issuer_id']);
}

/** The actor's id and source, for a log line that may never carry a code. */
function voucherActorLogContext(array $actorRow): array
{
    return ['source' => $actorRow['source'], 'actor_id' => voucherActorId($actorRow)];
}

/** Cut a string to $max characters (bytes when mbstring is unavailable). */
function voucherTruncate(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

/**
 * A note as it is stored: trimmed, control characters (newlines included)
 * removed, cut to 255 characters. null for an absent or empty note; false
 * when the caller passed something that is not a string at all, which the
 * caller has to tell apart from "no note".
 *
 * @return string|null|false
 */
function voucherSanitizeNote($raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_string($raw)) {
        return false;
    }
    $note = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw));
    if ($note === '') {
        return null;
    }
    return voucherTruncate($note, VOUCHER_NOTE_MAX_LENGTH);
}

/**
 * A 'Y-m-d H:i:s' timestamp in the future, or null when it is malformed or
 * already past. This is the last moment the code may be redeemed, not the
 * end of the Pro time it grants.
 */
function voucherValidExpiry($raw): ?string
{
    if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw) !== 1) {
        return null;
    }
    $timestamp = strtotime($raw);
    if ($timestamp === false || $timestamp <= time()) {
        return null;
    }
    return $raw;
}

/** Whether a UNIQUE-key violation, the only failure the retry loop handles. */
function voucherIsDuplicateError(PDOException $e): bool
{
    return (string) $e->getCode() === '23000'
        || stripos($e->getMessage(), 'unique') !== false
        || stripos($e->getMessage(), 'duplicate') !== false;
}

/** One row shaped for the caller, or null when there is no such row. */
function voucherFetch(PDO $pdo, int $voucherId): ?array
{
    if ($voucherId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ?');
        $stmt->execute([$voucherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logMessage('ERROR', 'Voucher lookup failed', ['error' => $e->getMessage(), 'voucher_id' => $voucherId]);
        return null;
    }
    return is_array($row) ? voucherRowShape($row) : null;
}

/** The public shape of a vouchers row. */
function voucherRowShape(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'code' => (string) $row['code'],
        'is_active' => (bool) (int) $row['is_active'],
        'current_uses' => (int) $row['current_uses'],
        'max_uses' => $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
        'duration_days' => $row['duration_days'] !== null ? (int) $row['duration_days'] : null,
        'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
        'source' => (string) ($row['source'] ?? ''),
        'note' => isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
        'created_at' => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
        'batch_id' => isset($row['batch_id']) && $row['batch_id'] !== null ? (string) $row['batch_id'] : null,
    ];
}

/** Whether a code is already taken. Codes are compared whole, case-sensitively. */
function voucherCodeExists(PDO $pdo, string $code): bool
{
    $stmt = $pdo->prepare('SELECT id FROM vouchers WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

/**
 * The one INSERT. Always active and unused, created now, with a NULL
 * batch_id (a batch is assigned by the batch action, step 4).
 */
function voucherInsert(PDO $pdo, string $code, ?int $durationDays, int $maxUses, ?string $expiresAt, ?string $note, array $actorRow, ?string $externalRef): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO vouchers (code, is_active, expires_at, max_uses, current_uses, duration_days, created_at, source, created_by_user_id, issuer_id, external_ref, note)
         VALUES (?, 1, ?, ?, 0, ?, NOW(), ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $expiresAt,
        $maxUses,
        $durationDays,
        $actorRow['source'],
        $actorRow['created_by_user_id'],
        $actorRow['issuer_id'],
        $externalRef,
        $note,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Create one voucher.
 *
 * $spec:
 *   duration_days  required key; int 1-3650, or null for lifetime Pro. A
 *                  missing key is refused — lifetime is never chosen by
 *                  accident.
 *   max_uses       required; int 1-10000, how many different accounts may
 *                  redeem the code (each at most once). No unlimited codes
 *                  come from this function.
 *   expires_at     optional 'Y-m-d H:i:s' in the future.
 *   code           optional custom code, VOUCHER_CODE_PATTERN.
 *   note           optional internal note, trimmed, control characters
 *                  removed, at most 255 characters.
 *   external_ref   optional, issuers only, at most 128 printable characters.
 *                  With one, a second call carrying the same
 *                  (issuer_id, external_ref) returns the row the first call
 *                  created, with 'existing' => true, instead of a duplicate:
 *                  the idempotent retry a seller's API needs.
 *
 * A generated code that collides with a UNIQUE key is retried up to
 * VOUCHER_CODE_ATTEMPTS times; a custom code that is taken is refused.
 *
 * @return array{ok:bool, voucher?:array<string,mixed>, existing?:bool, error?:string}
 */
function voucherCreate(PDO $pdo, array $spec, array $actor): array
{
    $actorRow = voucherActorResolve($actor);
    if ($actorRow === null) {
        return ['ok' => false, 'error' => 'Unknown actor'];
    }

    if (!array_key_exists('duration_days', $spec)) {
        return ['ok' => false, 'error' => 'duration_days is required'];
    }
    $durationDays = $spec['duration_days'];
    if ($durationDays !== null
        && (!is_int($durationDays) || $durationDays < VOUCHER_DURATION_MIN_DAYS || $durationDays > VOUCHER_DURATION_MAX_DAYS)) {
        return ['ok' => false, 'error' => 'duration_days must be an integer between 1 and 3650, or null for lifetime'];
    }

    if (!array_key_exists('max_uses', $spec)) {
        return ['ok' => false, 'error' => 'max_uses is required'];
    }
    $maxUses = $spec['max_uses'];
    if (!is_int($maxUses) || $maxUses < VOUCHER_MAX_USES_MIN || $maxUses > VOUCHER_MAX_USES_MAX) {
        return ['ok' => false, 'error' => 'max_uses must be an integer between 1 and 10000'];
    }

    $expiresAt = null;
    if (array_key_exists('expires_at', $spec) && $spec['expires_at'] !== null) {
        $expiresAt = voucherValidExpiry($spec['expires_at']);
        if ($expiresAt === null) {
            return ['ok' => false, 'error' => 'expires_at must be a future Y-m-d H:i:s timestamp'];
        }
    }

    $note = voucherSanitizeNote($spec['note'] ?? null);
    if ($note === false) {
        return ['ok' => false, 'error' => 'Invalid note'];
    }

    // external_ref is the seller API's own identity for a code. An admin has
    // no such id to dedupe on, so it is refused there rather than ignored.
    $externalRef = null;
    if (array_key_exists('external_ref', $spec) && $spec['external_ref'] !== null && $spec['external_ref'] !== '') {
        $rawRef = $spec['external_ref'];
        if ($actorRow['source'] !== VOUCHER_SOURCE_ISSUER) {
            return ['ok' => false, 'error' => 'external_ref is only allowed for an issuer'];
        }
        if (!is_string($rawRef) || strlen($rawRef) > VOUCHER_EXTERNAL_REF_MAX_LENGTH || preg_match('/^[\x20-\x7E]+$/', $rawRef) !== 1) {
            return ['ok' => false, 'error' => 'external_ref must be at most 128 printable characters'];
        }
        $externalRef = $rawRef;

        $existing = voucherFetchByExternalRef($pdo, (int) $actorRow['issuer_id'], $externalRef);
        if ($existing !== null) {
            return ['ok' => true, 'voucher' => $existing, 'existing' => true];
        }
    }

    $customCode = null;
    if (array_key_exists('code', $spec) && $spec['code'] !== null && $spec['code'] !== '') {
        if (!is_string($spec['code']) || preg_match(VOUCHER_CODE_PATTERN, $spec['code']) !== 1) {
            return ['ok' => false, 'error' => 'Invalid voucher code'];
        }
        $customCode = $spec['code'];
    }

    if ($customCode !== null) {
        if (voucherCodeExists($pdo, $customCode)) {
            return ['ok' => false, 'error' => 'That code already exists'];
        }
        try {
            $voucherId = voucherInsert($pdo, $customCode, $durationDays, $maxUses, $expiresAt, $note, $actorRow, $externalRef);
        } catch (PDOException $e) {
            if (voucherIsDuplicateError($e)) {
                return ['ok' => false, 'error' => 'That code already exists'];
            }
            return voucherCreateFailed($e, $actorRow);
        }
    } else {
        $voucherId = null;
        for ($attempt = 0; $attempt < VOUCHER_CODE_ATTEMPTS; $attempt++) {
            $code = voucherGenerateCode();
            try {
                if (voucherCodeExists($pdo, $code)) {
                    continue;
                }
                $voucherId = voucherInsert($pdo, $code, $durationDays, $maxUses, $expiresAt, $note, $actorRow, $externalRef);
                break;
            } catch (PDOException $e) {
                if (voucherIsDuplicateError($e)) {
                    continue;
                }
                return voucherCreateFailed($e, $actorRow);
            }
        }
        if ($voucherId === null) {
            logMessage('ERROR', 'Could not generate a unique voucher code', voucherActorLogContext($actorRow));
            return ['ok' => false, 'error' => 'Could not generate a unique voucher code'];
        }
    }

    $voucher = voucherFetch($pdo, $voucherId);
    if ($voucher === null) {
        return ['ok' => false, 'error' => 'Could not create voucher'];
    }

    logMessage('INFO', 'Voucher created', [
        'voucher_id' => $voucher['id'],
        'duration_days' => $durationDays,
        'max_uses' => $maxUses,
    ] + voucherActorLogContext($actorRow));

    return ['ok' => true, 'voucher' => $voucher];
}

/** A failed INSERT that is not a code collision. Never logs the code. */
function voucherCreateFailed(PDOException $e, array $actorRow): array
{
    logMessage('ERROR', 'Voucher creation failed', ['error' => $e->getMessage()] + voucherActorLogContext($actorRow));
    return ['ok' => false, 'error' => 'Could not create voucher'];
}

/** The issuer's voucher for one external_ref, or null. Internal. */
function voucherFetchByExternalRef(PDO $pdo, int $issuerId, string $externalRef): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE issuer_id = ? AND external_ref = ? LIMIT 1');
        $stmt->execute([$issuerId, $externalRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logMessage('ERROR', 'Voucher idempotency lookup failed', ['error' => $e->getMessage(), 'issuer_id' => $issuerId]);
        return null;
    }
    return is_array($row) ? voucherRowShape($row) : null;
}

/**
 * Vouchers, newest first.
 *
 * $filter: 'status' ('active' = redeemable now, 'inactive', 'expired',
 * 'used_up'), 'source' and 'batch_id'; an unknown status is refused rather
 * than silently ignored.
 *
 * @return array{ok:bool, vouchers?:list<array<string,mixed>>, error?:string}
 */
function voucherList(PDO $pdo, array $filter = [], int $limit = 50, int $offset = 0): array
{
    $where = [];
    $params = [];
    $now = date('Y-m-d H:i:s');

    $status = $filter['status'] ?? null;
    if ($status !== null && $status !== '') {
        if ($status === 'active') {
            $where[] = '(is_active = 1 AND (expires_at IS NULL OR expires_at > ?) AND (max_uses IS NULL OR current_uses < max_uses))';
            $params[] = $now;
        } elseif ($status === 'inactive') {
            $where[] = 'is_active = 0';
        } elseif ($status === 'expired') {
            $where[] = '(expires_at IS NOT NULL AND expires_at <= ?)';
            $params[] = $now;
        } elseif ($status === 'used_up') {
            $where[] = '(max_uses IS NOT NULL AND current_uses >= max_uses)';
        } else {
            return ['ok' => false, 'error' => 'Unknown status filter'];
        }
    }
    if (isset($filter['source']) && $filter['source'] !== '') {
        $where[] = 'source = ?';
        $params[] = (string) $filter['source'];
    }
    if (isset($filter['batch_id']) && $filter['batch_id'] !== '') {
        $where[] = 'batch_id = ?';
        $params[] = (string) $filter['batch_id'];
    }

    $sql = 'SELECT * FROM vouchers';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    // created_at is NULL on rows that predate migrate_vouchers.php, and MySQL
    // sorts NULL last under DESC; id breaks ties between same-second rows.
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
    $params[] = max(1, min($limit, VOUCHER_LIST_MAX_LIMIT));
    $params[] = max(0, $offset);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logMessage('ERROR', 'Voucher list failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Could not list vouchers'];
    }

    return ['ok' => true, 'vouchers' => array_map('voucherRowShape', $rows)];
}

/**
 * Turn a voucher on or off. An issuer may only touch a voucher it issued; an
 * admin may touch any. A missing id, someone else's voucher and an id that
 * is not a positive integer all answer the same 'Voucher not found', so an
 * issuer cannot enumerate an admin's codes.
 *
 * @return array{ok:bool, voucher?:array<string,mixed>, error?:string}
 */
function voucherSetActive(PDO $pdo, int $voucherId, bool $active, array $actor): array
{
    $actorRow = voucherActorResolve($actor);
    if ($actorRow === null) {
        return ['ok' => false, 'error' => 'Unknown actor'];
    }
    if ($voucherId <= 0) {
        return ['ok' => false, 'error' => 'Voucher not found'];
    }

    // Read first: an UPDATE that changes nothing (deactivating an inactive
    // voucher) reports zero affected rows on MySQL, which would otherwise be
    // indistinguishable from a missing row.
    $voucher = voucherFetchScoped($pdo, $voucherId, $actorRow);
    if ($voucher === null) {
        return ['ok' => false, 'error' => 'Voucher not found'];
    }

    try {
        if ($actorRow['source'] === VOUCHER_SOURCE_ISSUER) {
            $stmt = $pdo->prepare('UPDATE vouchers SET is_active = ? WHERE id = ? AND issuer_id = ?');
            $stmt->execute([$active ? 1 : 0, $voucher['id'], $actorRow['issuer_id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE vouchers SET is_active = ? WHERE id = ?');
            $stmt->execute([$active ? 1 : 0, $voucher['id']]);
        }
    } catch (Exception $e) {
        logMessage('ERROR', 'Could not change voucher state', ['error' => $e->getMessage(), 'voucher_id' => $voucher['id']] + voucherActorLogContext($actorRow));
        return ['ok' => false, 'error' => 'Could not update voucher'];
    }

    logMessage('INFO', $active ? 'Voucher activated' : 'Voucher deactivated', [
        'voucher_id' => $voucher['id'],
        'duration_days' => $voucher['duration_days'],
        'max_uses' => $voucher['max_uses'],
    ] + voucherActorLogContext($actorRow));

    return ['ok' => true, 'voucher' => voucherFetch($pdo, (int) $voucher['id']) ?? $voucher];
}

/** A voucher the actor may touch, or null when it does not exist or is not theirs. */
function voucherFetchScoped(PDO $pdo, int $voucherId, array $actorRow): ?array
{
    try {
        if ($actorRow['source'] === VOUCHER_SOURCE_ISSUER) {
            $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ? AND issuer_id = ? LIMIT 1');
            $stmt->execute([$voucherId, $actorRow['issuer_id']]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ? LIMIT 1');
            $stmt->execute([$voucherId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logMessage('ERROR', 'Voucher lookup failed', ['error' => $e->getMessage(), 'voucher_id' => $voucherId]);
        return null;
    }
    return is_array($row) ? voucherRowShape($row) : null;
}

/**
 * Who redeemed a voucher, oldest first. Account ids and timestamps only —
 * never an email address, which the admin page has no use for.
 *
 * @return array{ok:bool, redemptions?:list<array{user_id:int, redeemed_at:?string}>, error?:string}
 */
function voucherRedemptions(PDO $pdo, int $voucherId): array
{
    if ($voucherId <= 0) {
        return ['ok' => true, 'redemptions' => []];
    }
    try {
        $stmt = $pdo->prepare('SELECT user_id, redeemed_at FROM redemption_log WHERE voucher_id = ? ORDER BY redeemed_at ASC, id ASC');
        $stmt->execute([$voucherId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logMessage('ERROR', 'Could not list voucher redemptions', ['error' => $e->getMessage(), 'voucher_id' => $voucherId]);
        return ['ok' => false, 'error' => 'Could not list redemptions'];
    }

    $redemptions = [];
    foreach ($rows as $row) {
        $redemptions[] = [
            'user_id' => (int) $row['user_id'],
            'redeemed_at' => $row['redeemed_at'] !== null ? (string) $row['redeemed_at'] : null,
        ];
    }
    return ['ok' => true, 'redemptions' => $redemptions];
}
