<?php

declare(strict_types=1);

@require_once __DIR__ . '/EmailStorage/EmailAttachment.php';

/**
 * Legacy IMAP parts-based attachment extractor.
 *
 * Extraction only. It walks the MIME structure of a message over a live IMAP
 * connection, decodes each attachment part and returns them as value objects.
 * It writes no file and no database row: persistence belongs to the Email
 * Storage service (EmailStorage::persistAttachments()), which is the same path
 * the MailParser attachments of a message take (epic #169, #195).
 *
 * The live IMAP connection is what keeps this separate from MailParser: it
 * fetches each part with imap_fetchbody() instead of parsing a raw message.
 */
final class LegacyImapFallback
{
    /**
     * Decode every attachment of one message, recursing into nested parts.
     *
     * The content-id travels on each returned EmailAttachment: it is what the
     * service stores alongside the attachment, and what the inbox resolves the
     * body's inline `cid:` references from at display time. No content-id map
     * is built here because the attachment ids it would key on do not exist
     * until the service has persisted them.
     *
     * @param resource $imapConnection IMAP connection resource
     * @param int $messageNumber IMAP message number
     * @param array $parts Array of IMAP part structures
     * @param string $prefix Part number prefix for nested parts
     * @return list<EmailAttachment>
     */
    public static function extractAttachments(
        $imapConnection,
        int $messageNumber,
        array $parts,
        string $prefix = ''
    ): array {
        $attachments = [];

        foreach ($parts as $index => $part) {
            $partNumber = $prefix === '' ? (string)($index + 1) : ($prefix . '.' . ($index + 1));

            // Nested parts come before their parent, as on this path always.
            if (isset($part->parts) && is_array($part->parts) && count($part->parts) > 0) {
                foreach (self::extractAttachments($imapConnection, $messageNumber, $part->parts, $partNumber) as $nested) {
                    $attachments[] = $nested;
                }
            }

            // Detect filename from dparameters or parameters
            $filename = self::extractFilename($part);

            // Check if this is an attachment
            $isAttachment = !empty($part->ifdparameters) || !empty($part->dparameters);
            if (!$isAttachment && $filename === null) {
                continue;
            }

            // Fetch body data
            $data = @imap_fetchbody($imapConnection, $messageNumber, $partNumber);
            if ($data === false) {
                continue;
            }

            // Decode based on encoding
            $encoding = $part->encoding ?? 0;
            switch ($encoding) {
                case 3: // BASE64
                    $data = base64_decode($data, true) ?: $data;
                    break;
                case 4: // QUOTED-PRINTABLE
                    $data = quoted_printable_decode($data);
                    break;
            }

            // Generate filename if not present
            if ($filename === null) {
                $ext = isset($part->subtype) ? strtolower($part->subtype) : 'bin';
                $filename = sprintf('attachment_%d_%d.%s', $messageNumber, $index + 1, $ext);
            }

            $attachments[] = new EmailAttachment(
                $filename,
                $data,
                self::determineMimeType($part),
                self::extractContentId($part)
            );
        }

        return $attachments;
    }

    /**
     * Extract filename from IMAP part structure.
     */
    private static function extractFilename(object $part): ?string
    {
        // Check dparameters first
        if (!empty($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $p) {
                if (!empty($p->attribute) && in_array(strtolower($p->attribute), ['filename', 'name'], true)) {
                    return $p->value;
                }
            }
        }

        // Check parameters
        if (!empty($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (!empty($p->attribute) && in_array(strtolower($p->attribute), ['filename', 'name'], true)) {
                    return $p->value;
                }
            }
        }

        return null;
    }

    /**
     * Extract content-id from IMAP part structure.
     */
    private static function extractContentId(object $part): ?string
    {
        // Check part->id first (most common location)
        if (!empty($part->id)) {
            return trim($part->id, "<> \t\n\r");
        }

        // Check dparameters
        if (!empty($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $p) {
                if (!empty($p->attribute) && in_array(strtolower($p->attribute), ['content-id', 'id'], true)) {
                    return trim($p->value, "<> \t\n\r");
                }
            }
        }

        return null;
    }

    /**
     * Determine MIME type from IMAP part structure.
     */
    private static function determineMimeType(object $part): string
    {
        if (empty($part->subtype)) {
            return 'application/octet-stream';
        }

        $sub = strtolower($part->subtype);
        $major = $part->type ?? null;

        return match ($major) {
            0 => 'text/' . $sub,
            1 => 'multipart/' . $sub,
            2 => 'message/' . $sub,
            3 => 'application/' . $sub,
            4 => 'audio/' . $sub,
            5 => 'image/' . $sub,
            6 => 'video/' . $sub,
            default => 'application/octet-stream',
        };
    }
}
