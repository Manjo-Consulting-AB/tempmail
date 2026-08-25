<?php

declare(strict_types=1);

// Use safe local debug logger to avoid DB/network calls during IMAP processing
@require_once __DIR__ . '/debug_logger.php';

/**
 * Legacy IMAP parts-based attachment extractor.
 * This class exposes a static method compatible with previous behavior and
 * requires the caller to provide a PDO instance for DB inserts.
 */
final class LegacyImapFallback
{
    /**
     * Normalize a content-id for reliable matching.
     */
    public static function normalizeCid(string $cid): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $cid) ?? '');
    }

    /**
     * Recursively save IMAP message parts as attachments.
     *
     * @param PDO $pdo Database connection
     * @param resource $imapConnection IMAP connection resource
     * @param int $messageNumber IMAP message number
     * @param array $parts Array of IMAP part structures
     * @param string $prefix Part number prefix for nested parts
     * @param int $emailId Database email ID
     * @param string $attachmentsDir Path to attachments directory
     * @param int &$savedCount Counter for saved attachments (passed by reference)
     * @param array<string, int>|null &$cidMap Content-ID to attachment ID mapping (passed by reference)
     */
    public static function savePartsRecursive(
        PDO $pdo,
        $imapConnection,
        int $messageNumber,
        array $parts,
        string $prefix,
        int $emailId,
        string $attachmentsDir,
        int &$savedCount,
        ?array &$cidMap = null
    ): void {
        // Check content_id column existence once per call stack
        static $hasContentIdColumn = null;
        if ($hasContentIdColumn === null) {
            $hasContentIdColumn = self::ensureContentIdColumn($pdo);
        }

        foreach ($parts as $index => $part) {
            $partNumber = $prefix === '' ? (string)($index + 1) : ($prefix . '.' . ($index + 1));

            // Recurse into sub-parts (pass cidMap to nested calls)
            if (isset($part->parts) && is_array($part->parts) && count($part->parts) > 0) {
                self::savePartsRecursive(
                    $pdo,
                    $imapConnection,
                    $messageNumber,
                    $part->parts,
                    $partNumber,
                    $emailId,
                    $attachmentsDir,
                    $savedCount,
                    $cidMap
                );
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

            // Sanitize filename
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'attachment';
            $unique = time() . '_' . bin2hex(random_bytes(4));
            $finalName = $unique . '_' . $filename;
            $fullPath = rtrim($attachmentsDir, '/') . '/' . $finalName;

            // Write file
            $written = @file_put_contents($fullPath, $data);
            if ($written === false) {
                $msg = "[LegacyImapFallback] Failed to write attachment: {$fullPath} (email_id={$emailId})";
                    if (function_exists('logMessage')) {
                        logMessage('ERROR', 'LegacyImapFallback failed to write attachment', ['email_id' => $emailId, 'file_path' => $fullPath, 'filename' => $filename]);
                    } else {
                        error_log($msg);
                    }
                if (function_exists('safeDebugLog')) {
                    safeDebugLog('ERROR', $msg, ['email_id' => $emailId, 'file_path' => $fullPath, 'filename' => $filename]);
                }
                continue;
            }

            $relativePath = 'attachments/' . $finalName;
            $ctype = self::determineMimeType($part);
            $contentId = self::extractContentId($part);
            $fileSize = is_string($data) ? strlen($data) : 0;

            // Insert into database
            try {
                // Direct file log for debugging email_id issue -> use safeDebugLog instead
                if (function_exists('safeDebugLog')) {
                    safeDebugLog('DEBUG', '[LegacyImapFallback] INSERT', ['email_id' => (int)$emailId, 'filename' => $filename]);
                }

                if ($hasContentIdColumn) {
                    $sql = 'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at, content_id) VALUES (?, ?, ?, ?, ?, ?, ?)';
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(1, (int)$emailId, PDO::PARAM_INT);
                    $stmt->bindValue(2, (string)$filename, PDO::PARAM_STR);
                    $stmt->bindValue(3, (string)$relativePath, PDO::PARAM_STR);
                    $stmt->bindValue(4, (string)$ctype, PDO::PARAM_STR);
                    $stmt->bindValue(5, (int)$fileSize, PDO::PARAM_INT);
                    $stmt->bindValue(6, date('Y-m-d H:i:s'), PDO::PARAM_STR);
                    $stmt->bindValue(7, $contentId, PDO::PARAM_STR);
                    if (function_exists('safeDebugLog')) safeDebugLog('DEBUG', '[LegacyImapFallback] Executing attachment INSERT', ['email_id' => (int)$emailId, 'filename' => $filename, 'file_path' => $relativePath]);
                    $result = $stmt->execute();
                } else {
                    $sql = 'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?)';
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(1, (int)$emailId, PDO::PARAM_INT);
                    $stmt->bindValue(2, (string)$filename, PDO::PARAM_STR);
                    $stmt->bindValue(3, (string)$relativePath, PDO::PARAM_STR);
                    $stmt->bindValue(4, (string)$ctype, PDO::PARAM_STR);
                    $stmt->bindValue(5, (int)$fileSize, PDO::PARAM_INT);
                    $stmt->bindValue(6, date('Y-m-d H:i:s'), PDO::PARAM_STR);
                    if (function_exists('safeDebugLog')) safeDebugLog('DEBUG', '[LegacyImapFallback] Executing attachment INSERT', ['email_id' => (int)$emailId, 'filename' => $filename, 'file_path' => $relativePath]);
                    $result = $stmt->execute();
                }

                if (!$result) {
                    $err = $stmt->errorInfo();
                    $msg = "[LegacyImapFallback] DB execute failed for {$relativePath}: " . implode(', ', $err);
                        if (function_exists('logMessage')) {
                            logMessage('ERROR', 'LegacyImapFallback DB execute failed', ['email_id' => $emailId, 'file_path' => $relativePath, 'pdo_error' => $err]);
                        } else {
                            error_log($msg);
                        }
                    if (function_exists('safeDebugLog')) {
                        safeDebugLog('ERROR', $msg, [
                            'email_id' => $emailId,
                            'file_path' => $relativePath,
                            'filename' => $filename,
                            'mime_type' => $ctype,
                            'file_size' => $fileSize,
                            'pdo_error' => $err,
                            'query' => $stmt->queryString ?? null
                        ]);
                    }
                    continue;
                }

                $savedCount++;
                $aid = (int)$pdo->lastInsertId();
                    if (function_exists('logMessage')) {
                        logMessage('INFO', 'LegacyImapFallback inserted attachment', ['email_id' => $emailId, 'attachment_id' => $aid, 'file' => $relative]);
                    } else {
                        error_log("[LegacyImapFallback] Inserted attachment id={$aid} for email_id={$emailId} file={$relativePath}");
                    }

                // Build content-id mapping (original and normalized)
                if ($cidMap !== null && $contentId !== null && $contentId !== '') {
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
                $msg = "[LegacyImapFallback] DB insert failed for {$relativePath}: " . $e->getMessage();
                if (function_exists('logMessage')) {
                    logMessage('ERROR', 'LegacyImapFallback DB insert failed', ['email_id' => $emailId, 'file_path' => $relativePath, 'exception' => $e->getMessage()]);
                } else {
                    error_log($msg);
                }
                if (function_exists('safeDebugLog')) {
                    safeDebugLog('ERROR', $msg, [
                        'email_id' => $emailId,
                        'file_path' => $relativePath,
                        'filename' => $filename,
                        'mime_type' => $ctype,
                        'file_size' => $fileSize,
                        'exception' => $e->getMessage()
                    ]);
                }
            }
        }
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

    /**
     * Check if a table has a specific column.
     */
    private static function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
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
    private static function ensureContentIdColumn(PDO $pdo): bool
    {
        if (self::tableHasColumn($pdo, 'email_attachments', 'content_id')) {
            return true;
        }
        try {
            $pdo->exec("ALTER TABLE email_attachments ADD COLUMN IF NOT EXISTS content_id VARCHAR(255) NULL AFTER mime_type");
        } catch (\Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'LegacyImapFallback could not ensure content_id column exists', ['error' => $e->getMessage()]);
            } else {
                error_log('[LegacyImapFallback] Could not ensure content_id column exists: ' . $e->getMessage());
            }
            return false;
        }
        return self::tableHasColumn($pdo, 'email_attachments', 'content_id');
    }
}
