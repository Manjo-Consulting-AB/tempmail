<?php

declare(strict_types=1);

@require_once __DIR__ . '/EmailAttachment.php';

/**
 * A normalized incoming email, as handed to the Email Storage service.
 *
 * Part of the Email Storage API contract (epic #169, step 2/10). It carries
 * everything the intake (the DirectAdmin pipe in parse.php) already resolves,
 * and nothing about how it is persisted: no PDO
 * handle, no SQL, no table names. Persistence and business validation belong to
 * the service (#191), which decides the retention window, the duplicate rule and
 * the attachment storage layout. See documentaion/EMAIL_STORAGE_API.md.
 *
 * Field names mirror the `stored_emails` columns the service will write, and
 * the two timestamps are DateTimeImmutable so the adapter does not have to pick
 * a string format ("Y-m-d H:i:s" is the service's choice).
 */
final class IncomingEmail
{
    /**
     * @param string $toAddress Recipient, as the full address (`local@domain`).
     * @param DateTimeImmutable $receivedAt When the message was received. The
     *        pipe sets this from its own clock, so it is never absent.
     * @param list<EmailAttachment> $attachments
     */
    public function __construct(
        public readonly string $toAddress,
        public readonly DateTimeImmutable $receivedAt,
        public readonly ?string $fromAddress = null,
        public readonly ?string $subject = null,
        public readonly ?string $bodyText = null,
        public readonly ?string $bodyHtml = null,
        public readonly ?int $tempEmailId = null,
        public readonly ?int $proUserId = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?string $messageId = null,
        public readonly array $attachments = [],
        public readonly ?string $rawMessage = null
    ) {
    }
}
