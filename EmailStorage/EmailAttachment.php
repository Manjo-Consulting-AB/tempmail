<?php

declare(strict_types=1);

/**
 * One attachment of an incoming email, in normalized form.
 *
 * Part of the Email Storage API contract (epic #169, step 2/10). This is a value
 * object only: it holds bytes and metadata and knows nothing about where an
 * attachment is written or how it is recorded. See
 * documentaion/EMAIL_STORAGE_API.md.
 *
 * The property names are the camelCase spelling of the keys
 * MailParser::parseRawMessage() returns, and fromArray()/toArray() convert
 * between the two shapes so an ingestion adapter can map 1:1 without renaming
 * anything.
 */
final class EmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $data,
        public readonly ?string $mimeType = null,
        public readonly ?string $contentId = null
    ) {
    }

    /**
     * Build from one entry of the `attachments` array MailParser returns.
     *
     * Missing filename/data fall back to the same values
     * MailParser::saveAttachments() itself uses, so a malformed part round-trips
     * to the same persistence result it produces today. An empty mime_type or
     * content_id is normalized to null ("absent"), which keeps the field
     * nullable rather than empty-stringly.
     *
     * @param array{filename?: mixed, data?: mixed, mime_type?: mixed, content_id?: mixed} $attachment
     */
    public static function fromArray(array $attachment): self
    {
        $filename = $attachment['filename'] ?? null;
        $data = $attachment['data'] ?? null;
        $mimeType = $attachment['mime_type'] ?? null;
        $contentId = $attachment['content_id'] ?? null;

        return new self(
            is_string($filename) && $filename !== '' ? $filename : 'attachment.bin',
            is_string($data) ? $data : '',
            is_string($mimeType) && $mimeType !== '' ? $mimeType : null,
            is_string($contentId) && $contentId !== '' ? $contentId : null
        );
    }

    /**
     * The shape MailParser::saveAttachments() accepts, unchanged.
     *
     * @return array{filename: string, data: string, mime_type: ?string, content_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'data' => $this->data,
            'mime_type' => $this->mimeType,
            'content_id' => $this->contentId,
        ];
    }
}
