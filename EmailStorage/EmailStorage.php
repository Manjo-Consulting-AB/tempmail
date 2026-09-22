<?php

declare(strict_types=1);

// Internal component, not a page: a direct HTTP request must produce nothing.
if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

@require_once __DIR__ . '/IncomingEmail.php';
@require_once __DIR__ . '/StorageResult.php';
@require_once __DIR__ . '/AttachmentStorage.php';

/**
 * The single internal Email Storage service (epic #169, step 3/10, #191).
 *
 * One entrypoint — `store(IncomingEmail $email, array $options = [])` — owns
 * everything that turns a normalized incoming email into `stored_emails` and
 * `email_attachments` rows. It replaces, for callers migrated in #192–#195, the
 * three inline inserts inventoried in documentaion/EMAIL_STORAGE_ARCHITECTURE.md
 * §3. Nothing calls it yet: this step implements the service only.
 *
 * What it owns: recipient validation, the `temp_emails` / `pro_users.address_ttl_days`
 * ownership and retention lookup, the opt-in duplicate rule, the transactional
 * `stored_emails` insert, attachment persistence, the `emails_processed` /
 * `attachments_processed` statistics that belong to persistence, and a
 * normalized StorageResult.
 *
 * What it deliberately does not do: no webhook or network call of any kind
 * (callers dispatch webhooks after `store()` returns, which is what keeps them
 * out of the storage transaction), no mailbox housekeeping, no MIME parsing and
 * no user-facing output. It is not an HTTP endpoint and is guarded against being
 * requested as one.
 *
 * See documentaion/EMAIL_STORAGE_API.md §7 for the option list, the duplicate
 * rule and the failure state machine.
 */
final class EmailStorage
{
    /**
     * Duplicate detection, off unless the caller asks for it. When on, the rule
     * is exactly the Python fallback's (`python_imap_fallback.py`): same
     * `from_address`, `to_address` and `subject` with `received_at` within ±5
     * minutes. Off by default because the IMAP path and `parse.php` do no
     * duplicate check today and must keep behaving that way (epic #169,
     * backwards compatibility); the Python bridge (#194) turns it on.
     *
     * `stored_emails` has no Message-ID column, so this remains a heuristic; a
     * Message-ID-based rule would need a separate, approved schema change.
     */
    public const OPTION_DETECT_DUPLICATES = 'detect_duplicates';

    /**
     * Refuse the message (`rejected`) unless the recipient resolves to a live,
     * unexpired `temp_emails` row — the DirectAdmin pipe's permanent-bounce
     * behaviour. Off by default: the IMAP path and the Python fallback both
     * store the email anyway when the address lookup comes up empty, leaving
     * `temp_email_id` NULL, and that has to keep working.
     */
    public const OPTION_REJECT_UNKNOWN_RECIPIENT = 'reject_unknown_recipient';

    /**
     * The eight columns every path writes, in the order paths A and B use.
     * `lastInsertId()` is read straight after this statement, before any
     * attachment insert can move it.
     */
    private const INSERT_SQL = 'INSERT INTO stored_emails (to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    /** Upper clamp on `pro_users.address_ttl_days`, applied identically by all three paths. */
    private const MAX_RETENTION_DAYS = 18250; // 365 * 50

    /** Local part of a recipient address: the charset `sanitizeLocalPart()` allows. */
    private const LOCAL_PART_PATTERN = '/^[a-z0-9._-]+$/';

    private PDO $pdo;
    private bool $debug;
    private AttachmentStorage $attachmentStorage;

    public function __construct(PDO $pdo, bool $debug = false)
    {
        $this->pdo = $pdo;
        $this->debug = $debug;
        $this->attachmentStorage = new AttachmentStorage($pdo, dirname(__DIR__) . '/attachments', 'attachments', $debug);
    }

    /**
     * Store one normalized incoming email.
     *
     * @param array{detect_duplicates?: bool, reject_unknown_recipient?: bool} $options
     *        Both default to off; see OPTION_DETECT_DUPLICATES and
     *        OPTION_REJECT_UNKNOWN_RECIPIENT.
     */
    public function store(IncomingEmail $email, array $options = []): StorageResult
    {
        $detectDuplicates = !empty($options[self::OPTION_DETECT_DUPLICATES]);
        $rejectUnknownRecipient = !empty($options[self::OPTION_REJECT_UNKNOWN_RECIPIENT]);

        $toAddress = trim($email->toAddress);
        $localPart = $this->localPartOf($toAddress);
        if ($localPart === null) {
            $this->log('WARNING', 'EmailStorage rejected an email: unusable recipient address', ['to_address' => $toAddress]);
            return StorageResult::rejected('Recipient is not a usable address');
        }

        try {
            $ownership = $this->resolveOwnership($localPart);
        } catch (\Throwable $e) {
            // Not `rejected`: rejected is a permanent verdict the DirectAdmin
            // pipe turns into a bounce. An unusable database is not a verdict
            // about the message, and the insert that follows would fail anyway.
            $this->log('ERROR', 'EmailStorage could not resolve the recipient address', ['to_address' => $toAddress, 'error' => $e->getMessage()]);
            return StorageResult::failed('Could not resolve the recipient address');
        }

        // The freshly resolved row is authoritative. The DTO's ownership fields
        // are the fallback for an adapter that already looked the address up and
        // whose row has since been deleted, so no context is lost either way.
        if ($ownership !== null) {
            $tempEmailId = $ownership['id'] !== null ? (int)$ownership['id'] : null;
            $proUserId = $ownership['pro_user_id'] !== null ? (int)$ownership['pro_user_id'] : null;
        } else {
            $tempEmailId = $email->tempEmailId;
            $proUserId = $email->proUserId;
        }

        if ($rejectUnknownRecipient) {
            if ($ownership === null) {
                $this->log('WARNING', 'EmailStorage rejected an email: no address row for the recipient', ['to_address' => $toAddress]);
                return StorageResult::rejected('No address row for the recipient');
            }
            if ($this->hasExpired($ownership['expires_at'] ?? null)) {
                $this->log('WARNING', 'EmailStorage rejected an email: the recipient address has expired', ['to_address' => $toAddress, 'expires_at' => $ownership['expires_at']]);
                return StorageResult::rejected('Recipient address has expired');
            }
        }

        // Persistence-time normalization, so the value checked for duplicates is
        // the value the row receives. The `(no subject)` fallback is the pipe's
        // (a null *or* empty subject); an absent sender becomes '' as on both
        // PHP paths. Bodies are stored exactly as the adapter sanitized them.
        $fromAddress = $email->fromAddress ?? '';
        $subject = ($email->subject !== null && $email->subject !== '') ? $email->subject : '(no subject)';
        $receivedAt = $email->receivedAt->format('Y-m-d H:i:s');
        $expiresAt = $this->resolveExpiresAt($email, $ownership, $proUserId);

        if ($detectDuplicates) {
            try {
                $alreadyStored = $this->isDuplicate($fromAddress, $toAddress, $subject, $receivedAt);
            } catch (\Throwable $e) {
                // Asked to check and unable to answer: fail closed. Writing here
                // would silently defeat the opt-in, and the callers that ask for
                // it can retry the message (the Python path leaves it on the
                // server).
                $this->log('ERROR', 'EmailStorage could not complete the duplicate check', ['to_address' => $toAddress, 'error' => $e->getMessage()]);
                return StorageResult::failed('Could not complete the duplicate check');
            }

            if ($alreadyStored) {
                $this->log('INFO', 'EmailStorage skipped an already stored email', ['to_address' => $toAddress, 'subject' => $subject]);
                return StorageResult::duplicate('An identical message was stored within the last 5 minutes');
            }
        }

        $emailId = $this->insertEmail($email, $toAddress, $fromAddress, $subject, $receivedAt, $expiresAt, $tempEmailId);
        if ($emailId === null) {
            return StorageResult::failed('Could not write the stored_emails row');
        }

        $this->bumpStat('emails_processed', 1);

        // Outside the email's transaction, and after it: an attachment is
        // allowed to fail without undoing the email, which is what both intake
        // paths do today (architecture doc §5).
        [$warnings, $saved] = $this->persistAttachments($email, $emailId);
        if ($saved > 0) {
            $this->bumpStat('attachments_processed', $saved);
        }

        return StorageResult::stored($emailId, $warnings, $saved > 0 ? 'Stored with ' . $saved . ' attachment(s)' : '');
    }

    /**
     * Insert the `stored_emails` row inside a transaction: either it is written
     * completely or nothing is written at all.
     *
     * @return ?int the new row id, or null when the insert failed.
     */
    private function insertEmail(
        IncomingEmail $email,
        string $toAddress,
        string $fromAddress,
        string $subject,
        string $receivedAt,
        ?DateTimeImmutable $expiresAt,
        ?int $tempEmailId
    ): ?int {
        // A caller already holding a transaction open owns the commit; opening a
        // second one would throw, and rolling back the outer one from here would
        // discard the caller's own work.
        $ownsTransaction = !$this->pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }

            $stmt = $this->pdo->prepare(self::INSERT_SQL);
            $inserted = $stmt->execute([
                $toAddress,
                $fromAddress,
                $subject,
                $email->bodyText,
                $email->bodyHtml,
                $receivedAt,
                $expiresAt !== null ? $expiresAt->format('Y-m-d H:i:s') : null,
                $tempEmailId,
            ]);

            if ($inserted === false) {
                $info = $stmt->errorInfo();
                throw new RuntimeException('stored_emails INSERT returned false: ' . implode(', ', is_array($info) ? $info : []));
            }

            // Read immediately: an attachment insert would move it.
            $emailId = (int)$this->pdo->lastInsertId();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (\Throwable $rollbackError) {
                    $this->log('ERROR', 'EmailStorage could not roll back a failed stored_emails insert', ['to_address' => $toAddress, 'error' => $rollbackError->getMessage()]);
                }
            }
            $this->log('ERROR', 'EmailStorage could not write stored_emails', ['to_address' => $toAddress, 'subject' => $subject, 'error' => $e->getMessage()]);
            return null;
        }

        return $emailId;
    }

    /**
     * Persist every attachment of the email, keeping whatever succeeds.
     *
     * @return array{0: list<string>, 1: int} warnings and the number stored.
     */
    private function persistAttachments(IncomingEmail $email, int $emailId): array
    {
        $warnings = [];
        $saved = 0;

        foreach ($email->attachments as $attachment) {
            if (!$attachment instanceof EmailAttachment) {
                $warnings[] = 'An attachment was skipped: not an EmailAttachment';
                $this->log('WARNING', 'EmailStorage skipped an attachment that is not an EmailAttachment', ['email_id' => $emailId]);
                continue;
            }

            try {
                $this->attachmentStorage->save($attachment, $emailId);
                $saved++;
            } catch (\Throwable $e) {
                // The attachment left no row and no file behind; the email stays.
                $warnings[] = 'Attachment "' . $attachment->filename . '" was not stored';
                $this->log('WARNING', 'EmailStorage kept an email whose attachment failed', ['email_id' => $emailId, 'filename' => $attachment->filename, 'error' => $e->getMessage()]);
            }
        }

        return [$warnings, $saved];
    }

    /**
     * The `temp_emails` row for a recipient, or null when there is none.
     *
     * @return ?array{id: mixed, expires_at: mixed, pro_user_id: mixed}
     * @throws \Throwable when the lookup itself fails, so the caller can tell
     *         "no such address" (a verdict) from "the database is unusable".
     */
    private function resolveOwnership(string $localPart): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1');
        $stmt->execute([$localPart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The retention the row is written with, computed exactly as all three paths
     * compute it: a Pro-owned address gets `pro_users.address_ttl_days` counted
     * from the message's own `received_at`; otherwise the address' own expiry.
     * A failed TTL lookup falls back rather than failing the store, as today.
     */
    private function resolveExpiresAt(IncomingEmail $email, ?array $ownership, ?int $proUserId): ?DateTimeImmutable
    {
        if ($proUserId !== null) {
            $ttlDays = $this->proAddressTtlDays($proUserId);
            if ($ttlDays !== null) {
                return $email->receivedAt->modify('+' . max(1, min(self::MAX_RETENTION_DAYS, $ttlDays)) . ' days');
            }
        }

        if ($ownership !== null && !empty($ownership['expires_at'])) {
            try {
                return new DateTimeImmutable((string)$ownership['expires_at']);
            } catch (\Throwable $e) {
                $this->log('WARNING', 'EmailStorage could not read the address expiry', ['expires_at' => $ownership['expires_at'], 'error' => $e->getMessage()]);
            }
        }

        return $email->expiresAt;
    }

    /**
     * @return ?int `pro_users.address_ttl_days`, or null when there is no row or
     *         the lookup failed — both fall back to the address' own expiry.
     */
    private function proAddressTtlDays(int $proUserId): ?int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1');
            $stmt->execute([$proUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->log('WARNING', 'EmailStorage could not read the Pro retention window', ['pro_user_id' => $proUserId, 'error' => $e->getMessage()]);
            return null;
        }

        return is_array($row) && $row['ttl_days'] !== null ? (int)$row['ttl_days'] : null;
    }

    /**
     * The Python fallback's duplicate rule, unchanged: same sender, same
     * recipient, same subject, and a `received_at` within ±5 minutes. It runs
     * against the values this call would write.
     */
    private function isDuplicate(string $fromAddress, string $toAddress, string $subject, string $receivedAt): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM stored_emails WHERE from_address = ? AND to_address = ? AND subject = ? AND ABS(TIMESTAMPDIFF(MINUTE, received_at, ?)) < 5'
        );
        $stmt->execute([$fromAddress, $toAddress, $subject, $receivedAt]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Whether an address row's expiry has passed. An unreadable or missing
     * `expires_at` counts as expired, matching the pipe's
     * `strtotime($row['expires_at']) < time()`.
     *
     * @param mixed $expiresAt The raw `temp_emails.expires_at` value.
     */
    private function hasExpired($expiresAt): bool
    {
        $timestamp = strtotime((string)$expiresAt);

        return $timestamp === false || $timestamp < time();
    }

    /**
     * The address' local part, lowercased, or null when the recipient is not a
     * full `local@domain` address with a local part the rest of the codebase
     * would accept (`sanitizeLocalPart()`'s charset and 64-character ceiling).
     */
    private function localPartOf(string $toAddress): ?string
    {
        $parts = explode('@', $toAddress);
        if (count($parts) !== 2) {
            return null;
        }

        $localPart = strtolower($parts[0]);
        if ($localPart === '' || strlen($localPart) > 64 || $parts[1] === '') {
            return null;
        }

        return preg_match(self::LOCAL_PART_PATTERN, $localPart) === 1 ? $localPart : null;
    }

    /**
     * The `email_stats` counters that belong to persistence. Non-blocking: a
     * failure is logged and never changes the result, as on every path today.
     * `emails_total` is not written here — it counts messages *examined*, which
     * is intake, and stays with the IMAP path.
     */
    private function bumpStat(string $statName, int $increment): void
    {
        if (!function_exists('updateStat')) {
            return;
        }

        try {
            updateStat($statName, $increment);
        } catch (\Throwable $e) {
            $this->log('WARNING', 'EmailStorage could not update ' . $statName, ['increment' => $increment, 'error' => $e->getMessage()]);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('logMessage')) {
            logMessage($level, $message, $context);
            return;
        }
        error_log('[EmailStorage][' . $level . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}
