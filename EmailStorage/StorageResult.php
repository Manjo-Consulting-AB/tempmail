<?php

declare(strict_types=1);

/**
 * The outcome of one Email Storage service call (#191).
 *
 * Part of the Email Storage API contract (epic #169, step 2/10). It is a plain
 * value: status, the id of the row that was written when one was, the non-fatal
 * problems hit while saving attachments, and a diagnostic message. It
 * deliberately carries no PDO objects and no exceptions — a failure is reported
 * as STATUS_FAILED plus text, so a caller can never accidentally propagate a
 * driver-level object across the storage boundary. See
 * documentaion/EMAIL_STORAGE_API.md.
 */
final class StorageResult
{
    /** A new `stored_emails` row was written. */
    public const STATUS_STORED = 'stored';

    /** The message was already stored; nothing was written. */
    public const STATUS_DUPLICATE = 'duplicate';

    /**
     * The message was refused and must not be retried (the recipient is unknown
     * or expired). On the DirectAdmin pipe this is the `exit(1)` case, i.e. a
     * permanent MTA bounce.
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * Storage did not complete because of an internal error. Retrying the same
     * message may succeed.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @param string $status One of the STATUS_* constants.
     * @param ?int $storedEmailId The new `stored_emails.id`, set only for
     *        STATUS_STORED.
     * @param list<string> $attachmentWarnings Non-fatal attachment problems. An
     *        entry means that attachment was skipped or partially written while
     *        the email itself was still stored.
     * @param ?string $message Diagnostic text for STATUS_REJECTED /
     *        STATUS_FAILED, and an optional note otherwise. Free text — callers
     *        must branch on $status, never on this string.
     */
    private function __construct(
        public readonly string $status,
        public readonly ?int $storedEmailId = null,
        public readonly array $attachmentWarnings = [],
        public readonly ?string $message = null
    ) {
    }

    /**
     * @param list<string> $attachmentWarnings
     */
    public static function stored(int $emailId, array $attachmentWarnings = [], string $message = ''): self
    {
        return new self(
            self::STATUS_STORED,
            $emailId,
            $attachmentWarnings,
            $message !== '' ? $message : null
        );
    }

    public static function duplicate(string $message = ''): self
    {
        return new self(self::STATUS_DUPLICATE, null, [], $message !== '' ? $message : null);
    }

    public static function rejected(string $message = ''): self
    {
        return new self(self::STATUS_REJECTED, null, [], $message !== '' ? $message : null);
    }

    public static function failed(string $message = ''): self
    {
        return new self(self::STATUS_FAILED, null, [], $message !== '' ? $message : null);
    }

    public function isStored(): bool
    {
        return $this->status === self::STATUS_STORED;
    }

    public function hasAttachmentWarnings(): bool
    {
        return $this->attachmentWarnings !== [];
    }
}
