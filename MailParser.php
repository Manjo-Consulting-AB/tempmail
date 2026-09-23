<?php

declare(strict_types=1);

// Use safe local debug logger to avoid DB/network calls during mail processing
@require_once __DIR__ . '/debug_logger.php';

/**
 * MailParser: centralizes MIME parsing and attachment extraction.
 * Uses ZBateson MailMimeParser when available; otherwise it returns no
 * attachments.
 *
 * Parsing only. It writes no file and no database row: what it extracts is
 * handed to the Email Storage service, which owns every `stored_emails` and
 * `email_attachments` statement (epic #169). The `saveAttachments()` method
 * this class used to carry was the last direct writer outside that service and
 * was removed in #199, when the audit found it had had no caller since #192-#195.
 */
final class MailParser
{
    private array $config;
    private bool $debug;

    public function __construct(array $config = [], bool $debug = false)
    {
        $this->config = $config;
        $this->debug = $debug;
    }

    /**
     * Parse a raw RFC822 message string and extract attachments + basic metadata.
     *
     * @return array{subject: ?string, from: ?string, to: ?string, body_text: ?string, body_html: ?string, attachments: array<int, array{filename: string, data: string, mime_type: ?string, content_id: ?string}>}
     */
    public function parseRawMessage(string $raw): array
    {
        $result = [
            'subject' => null,
            'from' => null,
            'to' => null,
            'body_text' => null,
            'body_html' => null,
            'attachments' => [],
        ];

        if (!class_exists('ZBateson\\MailMimeParser\\MailMimeParser')) {
            return $result;
        }

        try {
            $parser = new \ZBateson\MailMimeParser\MailMimeParser();
            if ($this->debug) {
                if (function_exists('logMessage')) {
                    logMessage('DEBUG', 'MailParser: using ZBateson MailMimeParser');
                } else {
                    error_log('[DEBUG] MailParser: using ZBateson MailMimeParser');
                }
            }

            $message = $parser->parse($raw, false);

            // Extract subject
            if (method_exists($message, 'getHeaderValue')) {
                $result['subject'] = $message->getHeaderValue('subject');
            } elseif (method_exists($message, 'getHeader')) {
                $header = $message->getHeader('subject');
                $result['subject'] = $header !== null ? (string)$header : null;
            }

            // Extract from-address as a bare email (e.g. "user@example.com"),
            // the format the rest of the app expects.
            if (method_exists($message, 'getHeader')) {
                $fromHeader = $message->getHeader('from');
                if ($fromHeader !== null && method_exists($fromHeader, 'getEmail')) {
                    $result['from'] = $fromHeader->getEmail();
                } elseif ($fromHeader !== null) {
                    $result['from'] = (string)$fromHeader;
                }
            }
            if ($result['from'] === null && method_exists($message, 'getHeaderValue')) {
                $result['from'] = $message->getHeaderValue('from');
            }

            // Collect attachments
            $parts = [];
            if (method_exists($message, 'getAllAttachmentParts')) {
                $parts = $message->getAllAttachmentParts();
            } elseif (method_exists($message, 'getAttachmentParts')) {
                $parts = $message->getAttachmentParts();
            } elseif (method_exists($message, 'getAllParts')) {
                $parts = $message->getAllParts();
            }

            if (is_iterable($parts)) {
                foreach ($parts as $part) {
                    if (!is_object($part)) {
                        continue;
                    }

                    $contentId = null;
                    if (method_exists($part, 'getContentId')) {
                        $cid = $part->getContentId();
                        if ($cid !== null) {
                            $contentId = trim((string)$cid, "<> \t\n\r");
                        }
                    }

                    $ctype = $this->extractMimeType($part);

                    $filename = $this->extractFilename($part);
                    if ($filename === null) {
                        $normalizedType = strtolower((string)$ctype);
                        if ($contentId !== null && str_starts_with($normalizedType, 'image/')) {
                            // Inline image referenced by cid: with no filename — name it after its type
                            // so the attachment survives and the HTML body can resolve the reference.
                            $filename = 'inline-image' . $this->inlineImageExtension($normalizedType);
                        } else {
                            continue;
                        }
                    }

                    $data = $this->extractContent($part);
                    if ($data === null) {
                        continue;
                    }

                    $result['attachments'][] = [
                        'filename' => $filename,
                        'data' => $data,
                        'mime_type' => $ctype,
                        'content_id' => $contentId,
                    ];
                }
            }

            // Extract plain/text and html bodies
            if (method_exists($message, 'getTextContent')) {
                try {
                    $result['body_text'] = $message->getTextContent();
                } catch (\Throwable $_) {
                    // Ignore
                }
            }
            if (method_exists($message, 'getHtmlContent')) {
                try {
                    $result['body_html'] = $message->getHtmlContent();
                } catch (\Throwable $_) {
                    // Ignore
                }
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                if (function_exists('logMessage')) {
                    logMessage('WARNING', 'MailParser: ZBateson parse failed', ['error' => $e->getMessage()]);
                } else {
                    error_log('[WARNING] MailParser: ZBateson parse failed: ' . $e->getMessage());
                }
            }
        }

        return $result;
    }

    private function extractFilename(object $part): ?string
    {
        if (method_exists($part, 'getFilename')) {
            $fn = $part->getFilename();
            return $fn !== null ? (string)$fn : null;
        }
        if (method_exists($part, 'getFileName')) {
            $fn = $part->getFileName();
            return $fn !== null ? (string)$fn : null;
        }
        return null;
    }

    private function extractContent(object $part): ?string
    {
        // An attachment must keep the bytes the sender attached. getContent()
        // charset-converts a text/* part to UTF-8, so a latin-1 .txt/.csv/.ics
        // would be saved re-encoded; getBinaryContentStream() only undoes the
        // transfer encoding (base64/quoted-printable) and leaves the charset
        // alone, which is what a file on disk has to hold (#212).
        if (method_exists($part, 'getBinaryContentStream')) {
            $stream = $part->getBinaryContentStream();
            if ($stream !== null) {
                return $stream->getContents();
            }
        }
        if (method_exists($part, 'getContent')) {
            $data = $part->getContent();
            return is_string($data) ? $data : null;
        }
        if (method_exists($part, 'getDecodedContent')) {
            $data = $part->getDecodedContent();
            return is_string($data) ? $data : null;
        }
        if (method_exists($part, 'getStream')) {
            $s = $part->getStream();
            if (is_resource($s)) {
                return stream_get_contents($s) ?: null;
            }
            if (is_object($s) && method_exists($s, 'getContents')) {
                return $s->getContents();
            }
        }
        return null;
    }

    private function inlineImageExtension(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            default => '.bin',
        };
    }

    private function extractMimeType(object $part): ?string
    {
        if (method_exists($part, 'getContentType')) {
            return $part->getContentType();
        }
        if (method_exists($part, 'getMimeType')) {
            return $part->getMimeType();
        }
        return null;
    }
}
