<?php

declare(strict_types=1);

// Internal component, not a page: a direct HTTP request must produce nothing.
if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

@require_once __DIR__ . '/EmailAttachment.php';

/**
 * Persists one attachment: the bytes in `attachments/` and the matching
 * `email_attachments` row, as a single all-or-nothing step.
 *
 * Part of the Email Storage service (epic #169, step 3/10, #191). It exists
 * because the parser's previous array-in-one-call helper could not report which
 * entry failed, so a DB failure there left the file it had just written
 * orphaned on disk — which the storage contract forbids ("a failure leaves no
 * DB row and no file for that attachment"). This helper therefore owns write +
 * insert for one attachment and cleans up after itself.
 *
 * Since #199 it is the only writer of `email_attachments`: the parser's helper
 * had no caller left after #192-#195 and was removed by the storage-boundary
 * audit, so the `content_id` self-heal below is also the only place that can
 * add that column.
 *
 * It reuses the existing on-disk scheme unchanged: the same generated
 * `<unixtime>_<8 hex>_<sanitized filename>` name, the same `attachments/...`
 * relative path in `file_path` (what files.php /
 * cron/cleanup.php resolve against). No schema change and no layout change.
 */
final class AttachmentStorage
{
    private PDO $pdo;
    private string $directory;
    private string $relativePrefix;
    private bool $debug;

    /** Probed lazily, once per instance; null means "not probed yet". */
    private ?bool $hasContentIdColumn = null;

    /**
     * @param string $directory Absolute path of the directory holding the files.
     * @param string $relativePrefix Prefix stored in `email_attachments.file_path`.
     */
    public function __construct(PDO $pdo, string $directory, string $relativePrefix = 'attachments', bool $debug = false)
    {
        $this->pdo = $pdo;
        $this->directory = rtrim($directory, '/');
        $this->relativePrefix = trim($relativePrefix, '/');
        $this->debug = $debug;
    }

    /**
     * Write the attachment's file and its `email_attachments` row. The row's
     * `email_id` is what ties the attachment to its email.
     *
     * On failure the file is removed and the row is rolled back, so nothing of
     * this attachment remains; the caller keeps the email either way and turns
     * the exception into a warning.
     *
     * @throws RuntimeException when the attachment could not be stored completely.
     */
    public function save(EmailAttachment $attachment, int $emailId): void
    {
        if ($emailId <= 0) {
            throw new RuntimeException('refusing to store an attachment without an email id');
        }

        $diskName = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment->filename) ?? 'attachment';
        if ($diskName === '') {
            $diskName = 'attachment';
        }
        $storedName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $diskName;
        $fullPath = $this->directory . '/' . $storedName;
        $relativePath = $this->relativePrefix . '/' . $storedName;
        $mimeType = $attachment->mimeType ?? 'application/octet-stream';
        $fileSize = strlen($attachment->data);

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            $this->log('ERROR', 'AttachmentStorage could not create the attachments directory', ['directory' => $this->directory, 'email_id' => $emailId]);
            throw new RuntimeException('attachments directory is not writable');
        }

        // Probed before the transaction opens, deliberately: the self-heal is
        // DDL, and MySQL commits an open transaction implicitly on DDL, which
        // would silently break the atomicity this class exists to provide.
        $hasContentId = $this->ensureContentIdColumn();

        $written = @file_put_contents($fullPath, $attachment->data);
        if ($written === false) {
            @unlink($fullPath);
            $this->log('ERROR', 'AttachmentStorage could not write the attachment file', ['file_path' => $relativePath, 'email_id' => $emailId, 'filename' => $diskName]);
            throw new RuntimeException('could not write the attachment file');
        }

        if ($this->debug) {
            $this->log('DEBUG', 'AttachmentStorage wrote the attachment file', ['file_path' => $relativePath, 'email_id' => $emailId, 'bytes' => $written]);
        }

        // A caller may already hold a transaction open around the whole store()
        // call; in that case this attachment gets a savepoint inside it instead
        // of a transaction of its own, so its rollback still undoes only its own
        // row. The email row is committed before attachments run either way.
        $useSavepoint = $this->pdo->inTransaction();
        $savepoint = 'ms_attachment';

        try {
            if ($useSavepoint) {
                $this->pdo->exec('SAVEPOINT ' . $savepoint);
            } else {
                $this->pdo->beginTransaction();
            }

            if ($hasContentId) {
                $sql = 'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at, content_id) VALUES (?, ?, ?, ?, ?, ?, ?)';
            } else {
                $sql = 'INSERT INTO email_attachments (email_id, filename, file_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?)';
            }

            $stmt = $this->pdo->prepare($sql);
            // Explicit binds, as the parser's removed helper did: a NULL
            // content_id bound through execute() has bitten this codebase
            // before, and the column list differs between the two variants.
            $stmt->bindValue(1, $emailId, PDO::PARAM_INT);
            // The stored name is the sender's own, not the lossy disk name: it
            // is what the inbox shows and what files.php sends back.
            $stmt->bindValue(2, $this->displayName($attachment->filename), PDO::PARAM_STR);
            $stmt->bindValue(3, $relativePath, PDO::PARAM_STR);
            $stmt->bindValue(4, $mimeType, PDO::PARAM_STR);
            $stmt->bindValue(5, $fileSize, PDO::PARAM_INT);
            $stmt->bindValue(6, date('Y-m-d H:i:s'), PDO::PARAM_STR);
            if ($hasContentId) {
                $stmt->bindValue(7, $attachment->contentId, $attachment->contentId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }

            if ($stmt->execute() === false) {
                $info = $stmt->errorInfo();
                throw new RuntimeException('email_attachments INSERT returned false: ' . implode(', ', is_array($info) ? $info : []));
            }

            if ($useSavepoint) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    if ($useSavepoint) {
                        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    } else {
                        $this->pdo->rollBack();
                    }
                }
            } catch (\Throwable $rollbackError) {
                $this->log('ERROR', 'AttachmentStorage could not roll back an attachment row', ['email_id' => $emailId, 'file_path' => $relativePath, 'error' => $rollbackError->getMessage()]);
            }

            @unlink($fullPath);
            $this->log('ERROR', 'AttachmentStorage could not store an attachment', ['email_id' => $emailId, 'file_path' => $relativePath, 'filename' => $diskName, 'error' => $e->getMessage()]);

            throw new RuntimeException('attachment could not be stored: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The name the user sees: the sender's own, with only what is unsafe for
     * display or for a header removed.
     *
     * Deliberately separate from the disk name. That one has to be safe for a
     * filesystem and is therefore lossy — `Offert ÅÄÖ.pdf` becomes
     * `_Offert_______.pdf`, one underscore per byte — while this one becomes
     * the `email_attachments.filename` the inbox lists and files.php
     * downloads, so the sender's spelling has to survive.
     */
    private function displayName(string $original): string
    {
        $name = mb_scrub($original, 'UTF-8');
        // Drop any path the sender supplied, in either separator convention.
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        // Control characters, CR/LF/NUL included, would break a header.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment';
        }

        return mb_strcut($name, 0, 255, 'UTF-8');
    }

    /**
     * Whether `email_attachments.content_id` exists, adding it if it does not.
     *
     * The self-heal the parser's removed helper used to perform, and since #199
     * the only implementation left: without the column, inline `cid:` images
     * lose their link to the attachment they reference.
     */
    private function ensureContentIdColumn(): bool
    {
        if ($this->hasContentIdColumn !== null) {
            return $this->hasContentIdColumn;
        }

        $this->hasContentIdColumn = $this->tableHasColumn('email_attachments', 'content_id');
        if ($this->hasContentIdColumn) {
            return true;
        }

        try {
            // No "IF NOT EXISTS": that clause needs MySQL 8.0.29+ / recent
            // MariaDB and fails with a syntax error on the older shared-hosting
            // MySQL, which would defeat the self-heal silently. The probe above
            // is what guards against re-adding an existing column.
            $this->pdo->exec('ALTER TABLE email_attachments ADD COLUMN content_id VARCHAR(255) NULL AFTER mime_type');
        } catch (\Throwable $e) {
            $this->log('WARNING', 'AttachmentStorage could not add the content_id column', ['error' => $e->getMessage()]);
            $this->hasContentIdColumn = false;
            return false;
        }

        $this->hasContentIdColumn = $this->tableHasColumn('email_attachments', 'content_id');
        return $this->hasContentIdColumn;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            // Not "SHOW COLUMNS ... LIKE ?": MariaDB rejects a bound placeholder
            // there with a hard syntax error, which a catch would turn into a
            // permanent false negative (confirmed live against production).
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $_) {
            return false;
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('logMessage')) {
            logMessage($level, $message, $context);
            return;
        }
        error_log('[AttachmentStorage][' . $level . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}
