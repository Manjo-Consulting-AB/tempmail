<?php

declare(strict_types=1);

// Use safe local debug logger to avoid DB/network calls during IMAP processing
@require_once __DIR__ . '/debug_logger.php';

/**
 * MailParser: centralizes MIME parsing and attachment extraction.
 * Uses ZBateson MailMimeParser when available; otherwise returns no attachments
 * and leaves fallback to LegacyImapFallback.
 */
final class MailParser
{
    private PDO $pdo;
    private array $config;
    private bool $debug;

    public function __construct(PDO $pdo, array $config = [], bool $debug = false)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->debug = $debug;
    }

    /**
     * Normalize a content-id for reliable matching.
     * Removes angle brackets, whitespace, and lowercases the string.
     */
    public static function normalizeCid(string $cid): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $cid) ?? '');
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
            // matching the format ImapProcessor builds from IMAP headers.
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

                    $filename = $this->extractFilename($part);
                    if ($filename === null) {
                        continue;
                    }

                    $data = $this->extractContent($part);
                    if ($data === null) {
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

    /**
     * Save attachments array to disk and insert DB rows.
     *
     * @param array<int, array{filename?: string, data?: string, mime_type?: ?string, content_id?: ?string}> $attachments
     * @return array{count: int, mapping: array<string, int>}
     */
    public function saveAttachments(array $attachments, int $emailId): array
    {
        $saved = 0;
        $cidMap = [];

        if (empty($attachments)) {
            return ['count' => 0, 'mapping' => $cidMap];
        }

        $attachmentsDir = __DIR__ . '/attachments';
        if (!is_dir($attachmentsDir) && !@mkdir($attachmentsDir, 0755, true) && !is_dir($attachmentsDir)) {
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'MailParser failed to create attachments directory', ['path' => $attachmentsDir]);
            } else {
                error_log('[MailParser] Failed to create attachments directory: ' . $attachmentsDir);
            }
            return ['count' => 0, 'mapping' => $cidMap];
        }

        $hasContentIdColumn = $this->ensureContentIdColumn();

        foreach ($attachments as $a) {
            $filename = $a['filename'] ?? 'attachment.bin';
            $data = $a['data'] ?? '';
            $ctype = $a['mime_type'] ?? 'application/octet-stream';
            $contentId = $a['content_id'] ?? null;

            // Sanitize filename
            $san = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'attachment';
            $unique = time() . '_' . bin2hex(random_bytes(4));
            $finalName = $unique . '_' . $san;
            $fullPath = $attachmentsDir . '/' . $finalName;

            if ($this->debug) {
                if (function_exists('logMessage')) {
                    logMessage('DEBUG', 'MailParser writing attachment to file', ['email_id' => $emailId, 'file_path' => $fullPath, 'filename' => $san]);
                } else {
                    error_log("[MailParser] Writing attachment to {$fullPath} (email_id={$emailId}, filename={$san})");
                }
            }

            $written = @file_put_contents($fullPath, $data);
            if ($written === false) {
                $msg = "[MailParser] Failed to write attachment file: {$fullPath} (email_id={$emailId})";
                if (function_exists('logMessage')) {
                    logMessage('ERROR', 'MailParser failed to write attachment file', ['email_id' => $emailId, 'file_path' => $fullPath, 'filename' => $san]);
                } else {
                    error_log($msg);
                }
                if (function_exists('safeDebugLog')) {
                    safeDebugLog('ERROR', $msg, ['email_id' => $emailId, 'file_path' => $fullPath, 'filename' => $san]);
                }
                continue;
            }

            if ($this->debug) {
                if (function_exists('logMessage')) {
                    logMessage('DEBUG', 'MailParser wrote attachment file', ['email_id' => $emailId, 'file_path' => $fullPath, 'bytes' => $written]);
                } else {
                    error_log("[MailParser] Wrote {$written} bytes to {$fullPath}");
                }
            }

            // Log successful write to DB-backed system_logs for debugging visibility
            if (function_exists('safeDebugLog')) {
                try {
                    safeDebugLog('INFO', '[MailParser] Wrote attachment file', [
                        'email_id' => $emailId,
                        'file_path' => $fullPath,
                        'relative_path' => $relative ?? ('attachments/' . $finalName),
                        'filename' => $san,
                        'file_size' => $written,
                    ]);
                } catch (\Throwable $_) {
                    if (function_exists('logMessage')) {
                        logMessage('WARNING', 'MailParser safeDebugLog failed for write success', ['email_id' => $emailId]);
                    } else {
                        error_log('[MailParser] safeDebugLog failed for write success');
                    }
                }
            }

            $relative = 'attachments/' . $finalName;
            $fileSize = is_string($data) ? strlen($data) : 0;

            try {
                // Direct file log for debugging email_id issue -> use safeDebugLog instead
                if (function_exists('safeDebugLog')) {
                    safeDebugLog('DEBUG', '[MailParser] INSERT', ['email_id' => (int)$emailId, 'filename' => $san]);
                }

                if ($hasContentIdColumn) {
                    $sql = 'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at, content_id) VALUES (?, ?, ?, ?, ?, ?, ?)';
                    $stmt = $this->pdo->prepare($sql);
                    // Bind explicitly to avoid type/coercion issues
                    $stmt->bindValue(1, (int)$emailId, PDO::PARAM_INT);
                    $stmt->bindValue(2, (string)$san, PDO::PARAM_STR);
                    $stmt->bindValue(3, (string)$relative, PDO::PARAM_STR);
                    $stmt->bindValue(4, (string)$ctype, PDO::PARAM_STR);
                    $stmt->bindValue(5, (int)$fileSize, PDO::PARAM_INT);
                    $stmt->bindValue(6, date('Y-m-d H:i:s'), PDO::PARAM_STR);
                    $stmt->bindValue(7, $contentId, PDO::PARAM_STR);
                    if (function_exists('safeDebugLog')) safeDebugLog('DEBUG', '[MailParser] Executing attachment INSERT', ['email_id' => (int)$emailId, 'filename' => $san, 'file_path' => $relative]);
                    $result = $stmt->execute();
                } else {
                    $sql = 'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?)';
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->bindValue(1, (int)$emailId, PDO::PARAM_INT);
                    $stmt->bindValue(2, (string)$san, PDO::PARAM_STR);
                    $stmt->bindValue(3, (string)$relative, PDO::PARAM_STR);
                    $stmt->bindValue(4, (string)$ctype, PDO::PARAM_STR);
                    $stmt->bindValue(5, (int)$fileSize, PDO::PARAM_INT);
                    $stmt->bindValue(6, date('Y-m-d H:i:s'), PDO::PARAM_STR);
                    if (function_exists('safeDebugLog')) safeDebugLog('DEBUG', '[MailParser] Executing attachment INSERT', ['email_id' => (int)$emailId, 'filename' => $san, 'file_path' => $relative]);
                    $result = $stmt->execute();
                }

                if (!$result) {
                    $err = $stmt->errorInfo();
                    $msg = "[MailParser] DB execute failed for {$relative}: " . implode(', ', $err);
                    if (function_exists('logMessage')) {
                        logMessage('ERROR', 'MailParser DB execute failed', ['email_id' => $emailId, 'file_path' => $relative, 'pdo_error' => $err]);
                    } else {
                        error_log($msg);
                    }
                    if (function_exists('safeDebugLog')) {
                        safeDebugLog('ERROR', $msg, [
                            'email_id' => $emailId,
                            'file_path' => $relative,
                            'filename' => $san,
                            'mime_type' => $ctype,
                            'file_size' => $fileSize,
                            'pdo_error' => $err,
                            'query' => $stmt->queryString ?? null,
                            'params' => [$emailId, $san, $relative, $ctype, $fileSize, date('Y-m-d H:i:s'), $contentId ?? null]
                        ]);
                    }
                    continue;
                }

                $saved++;
                $aid = (int)$this->pdo->lastInsertId();

                // Always log successful inserts for debugging
                if (function_exists('logMessage')) {
                    logMessage('INFO', 'MailParser inserted attachment', ['email_id' => $emailId, 'attachment_id' => $aid, 'file' => $relative]);
                } else {
                    error_log("[MailParser] Inserted attachment id={$aid} for email_id={$emailId} (file={$relative})");
                }

                // Also record successful insert in local debug log so we can audit without touching DB
                if (function_exists('safeDebugLog')) {
                    try {
                        safeDebugLog('INFO', '[MailParser] Inserted attachment record', [
                            'email_id' => $emailId,
                            'attachment_id' => $aid,
                            'file_path' => $relative,
                            'filename' => $san,
                            'mime_type' => $ctype,
                            'file_size' => $fileSize,
                        ]);
                    } catch (\Throwable $_) {
                        if (function_exists('logMessage')) {
                            logMessage('WARNING', 'MailParser safeDebugLog failed for insert success', ['email_id' => $emailId, 'attachment_id' => $aid]);
                        } else {
                            error_log('[MailParser] safeDebugLog failed for insert success');
                        }
                    }
                }

                // Map content-id to attachment id (both original and normalized)
                if ($contentId !== null && $contentId !== '') {
                    $cidClean = trim($contentId, "<> \t\n\r");
                    if ($cidClean !== '') {
                        $cidMap[$cidClean] = $aid;
                        $normalized = self::normalizeCid($cidClean);
                        if ($normalized !== '' && $normalized !== $cidClean) {
                            $cidMap[$normalized] = $aid;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $msg = "[MailParser] DB insert failed for attachment {$relative}: " . $e->getMessage();
                if (function_exists('logMessage')) {
                    logMessage('ERROR', 'MailParser DB insert failed', [
                        'email_id' => $emailId,
                        'file_path' => $relative,
                        'filename' => $san,
                        'mime_type' => $ctype,
                        'file_size' => $fileSize,
                        'exception' => $e->getMessage()
                    ]);
                } else {
                    error_log($msg);
                }
            }
        }

        return ['count' => $saved, 'mapping' => $cidMap];
    }

    /**
     * Check if a table has a specific column.
     */
    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $_) {
            return false;
        }
    }

    /**
     * Ensure the email_attachments.content_id column exists, creating it if
     * necessary. Without this column, embedded/inline images referenced via
     * "cid:" in HTML email bodies can never be resolved to a downloadable
     * URL, so this self-heals older schemas instead of silently degrading.
     */
    private function ensureContentIdColumn(): bool
    {
        if ($this->tableHasColumn('email_attachments', 'content_id')) {
            return true;
        }
        try {
            $this->pdo->exec("ALTER TABLE email_attachments ADD COLUMN IF NOT EXISTS content_id VARCHAR(255) NULL AFTER mime_type");
        } catch (\Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'MailParser could not ensure content_id column exists', ['error' => $e->getMessage()]);
            } else {
                error_log('[MailParser] Could not ensure content_id column exists: ' . $e->getMessage());
            }
            return false;
        }
        return $this->tableHasColumn('email_attachments', 'content_id');
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
