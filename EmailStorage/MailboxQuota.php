<?php

declare(strict_types=1);

// Internal component, not a page: a direct HTTP request must produce nothing.
if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

@require_once __DIR__ . '/EmailStorage.php';

/**
 * The stored-mail quota, as a post-storage listener on the Email Storage
 * service (epic #212, step 15/18, #227).
 *
 * The rules, decided by the owner and the architect:
 *
 *  - 100 MB (104857600 bytes) of stored mail by default, per scope.
 *  - The scope is the user account when the recipient address belongs to one
 *    (`temp_emails.pro_user_id` is not NULL) — every address of that account,
 *    personal and temporary, Regular and Pro alike. An anonymous address
 *    (`pro_user_id` NULL) is its own scope.
 *  - One stored email weighs `LENGTH(subject) + LENGTH(body_text) +
 *    LENGTH(body_html)` plus the `file_size` of each of its attachments.
 *  - Mail is never refused for quota reasons. Once a new message has been
 *    stored, the oldest messages in the same scope — `ORDER BY received_at
 *    ASC, id ASC` — are deleted until usage is at or below the quota. The
 *    message just stored is never one of them. Nobody is notified.
 *
 * It is registered as a listener through `attach()`, never called directly by an
 * ingestion path: the new email is committed before any listener runs, every
 * listener is caught on its own, and this class never rethrows — so a failure
 * here can never lose or refuse the mail that triggered it. Deleting rows is a
 * documented non-ingestion exception to the storage boundary
 * (documentaion/EMAIL_STORAGE_ARCHITECTURE.md §3.1); this class inserts nothing.
 */
final class MailboxQuota
{
    /** Hard cap on deletions per call, so a bad sum can never loop forever. */
    private const MAX_DELETIONS_PER_RUN = 500;

    /** What one stored email weighs before its attachments, in bytes. */
    private const BODY_BYTES_SQL = 'COALESCE(LENGTH(se.subject), 0) + COALESCE(LENGTH(se.body_text), 0) + COALESCE(LENGTH(se.body_html), 0)';

    /** 100 MB: the default, and the fallback for a nonsensical configuration. */
    private const DEFAULT_QUOTA_BYTES = 104857600;

    private PDO $pdo;
    private string $attachmentsDirectory;
    private int $quotaBytes;

    /**
     * @param string $attachmentsDirectory Absolute path of the directory holding
     *        the attachment files — the same one the storage service writes to,
     *        because the files of a deleted row have to go with it.
     */
    public function __construct(PDO $pdo, string $attachmentsDirectory, int $quotaBytes)
    {
        $this->pdo = $pdo;
        $this->attachmentsDirectory = rtrim($attachmentsDirectory, '/');
        $this->quotaBytes = $quotaBytes > 0 ? $quotaBytes : self::DEFAULT_QUOTA_BYTES;
    }

    /**
     * Register enforce() as a post-storage listener on $storage. Call it once,
     * right after the service is constructed for an ingestion path, alongside
     * the other consumers.
     *
     * Registration order is the run order: parse.php registers the webhook
     * consumer first, so every webhook is queued before any quota cleanup
     * deletes rows.
     */
    public static function attach(EmailStorage $storage, PDO $pdo, string $attachmentsDirectory, int $quotaBytes): void
    {
        $quota = new self($pdo, $attachmentsDirectory, $quotaBytes);
        $storage->onStored(static fn (array $c) => $quota->enforce($c));
    }

    /**
     * Delete the oldest mail in the stored email's scope until the scope is at
     * or below the quota. Never throws: this is a listener, so the email that
     * triggered it is already committed and its own outcome must not change.
     *
     * @param array<string, mixed> $context the onStored() context
     * @return int number of stored emails deleted
     */
    public function enforce(array $context): int
    {
        $currentId = (int) ($context['stored_email_id'] ?? 0);
        if ($currentId <= 0) {
            return 0;
        }

        $proUserId = $context['pro_user_id'] ?? null;
        $tempEmailId = $context['temp_email_id'] ?? null;

        // The scope fragment and its bound value: the user account when the
        // address belongs to one, otherwise the anonymous address itself. Both
        // are literals; only the value is bound.
        if ($proUserId !== null) {
            $scopeSql = 'se.temp_email_id IN (SELECT id FROM temp_emails WHERE pro_user_id = ?)';
            $scopeParams = [(int) $proUserId];
            $scopeLabel = ['scope' => 'user', 'pro_user_id' => (int) $proUserId];
        } elseif ($tempEmailId !== null) {
            $scopeSql = 'se.temp_email_id = ?';
            $scopeParams = [(int) $tempEmailId];
            $scopeLabel = ['scope' => 'address', 'temp_email_id' => (int) $tempEmailId];
        } else {
            // No ownership at all: nothing to weigh, and no scope to clean.
            return 0;
        }

        $usageBefore = 0;
        $usage = 0;
        $deleted = 0;

        try {
            $usage = $this->usage($scopeSql, $scopeParams);
            $usageBefore = $usage;

            while ($usage > $this->quotaBytes && $deleted < self::MAX_DELETIONS_PER_RUN) {
                $oldest = $this->oldestRow($scopeSql, $scopeParams, $currentId);
                if ($oldest === null) {
                    // Only the message just stored is left in scope.
                    break;
                }

                $attachments = $this->attachmentsOf($oldest['id']);

                $this->pdo->beginTransaction();
                try {
                    $deleteAttachments = $this->pdo->prepare('DELETE FROM email_attachments WHERE email_id = ?');
                    $deleteAttachments->execute([$oldest['id']]);

                    $deleteEmail = $this->pdo->prepare('DELETE FROM stored_emails WHERE id = ?');
                    $deleteEmail->execute([$oldest['id']]);

                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }

                $deleted++;

                // Only after the commit: a rolled-back row must keep its files.
                $removedBytes = $oldest['bytes'];
                foreach ($attachments as $attachment) {
                    $removedBytes += $attachment['file_size'];
                    $this->unlinkAttachment($attachment['file_path']);
                }

                $usage -= $removedBytes;
            }
        } catch (\Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (\Throwable $rollbackError) {
                $this->log('ERROR', 'MailboxQuota could not roll back a failed deletion', [
                    'error' => $rollbackError->getMessage(),
                ]);
            }

            $this->log('ERROR', 'MailboxQuota could not enforce the quota', $scopeLabel + [
                'stored_email_id' => $currentId,
                'deleted' => $deleted,
                'error' => $e->getMessage(),
            ]);

            return $deleted;
        }

        if ($deleted > 0) {
            $this->log('INFO', 'MailboxQuota deleted oldest mail', $scopeLabel + [
                'deleted' => $deleted,
                'usage_before' => $usageBefore,
                'usage_after' => $usage,
                'quota_bytes' => $this->quotaBytes,
            ]);
        }

        return $deleted;
    }

    /**
     * Bytes stored in the scope: every row's body weight plus its attachments'
     * declared sizes.
     *
     * @param list<int> $scopeParams
     */
    private function usage(string $scopeSql, array $scopeParams): int
    {
        $bodyStmt = $this->pdo->prepare('SELECT COALESCE(SUM(' . self::BODY_BYTES_SQL . '), 0) FROM stored_emails se WHERE ' . $scopeSql);
        $bodyStmt->execute($scopeParams);

        $attachmentStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(ea.file_size), 0) FROM email_attachments ea JOIN stored_emails se ON se.id = ea.email_id WHERE ' . $scopeSql
        );
        $attachmentStmt->execute($scopeParams);

        return (int) $bodyStmt->fetchColumn() + (int) $attachmentStmt->fetchColumn();
    }

    /**
     * The oldest row in the scope, never the one just stored.
     *
     * @param list<int> $scopeParams
     * @return ?array{id: int, bytes: int}
     */
    private function oldestRow(string $scopeSql, array $scopeParams, int $currentId): ?array
    {
        $sql = 'SELECT se.id AS id, (' . self::BODY_BYTES_SQL . ') AS body_bytes FROM stored_emails se'
            . ' WHERE ' . $scopeSql . ' AND se.id <> ?'
            . ' ORDER BY se.received_at ASC, se.id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$scopeParams, $currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'bytes' => (int) $row['body_bytes']];
    }

    /**
     * The files and sizes of one email's attachments, read before the row is
     * deleted.
     *
     * @return list<array{file_path: string, file_size: int}>
     */
    private function attachmentsOf(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT file_path, file_size FROM email_attachments WHERE email_id = ?');
        $stmt->execute([$emailId]);

        $attachments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $attachments[] = [
                'file_path' => (string) ($row['file_path'] ?? ''),
                'file_size' => (int) ($row['file_size'] ?? 0),
            ];
        }

        return $attachments;
    }

    /**
     * Remove one attachment file, resolved the way files.php and cron/cleanup.php
     * resolve it: the stored relative path's basename inside the attachments
     * directory. A file already gone is not an error.
     */
    private function unlinkAttachment(string $filePath): void
    {
        if ($filePath === '') {
            return;
        }

        $fullPath = $this->attachmentsDirectory . '/' . basename($filePath);
        if (!is_file($fullPath)) {
            return;
        }

        if (!@unlink($fullPath)) {
            $this->log('WARNING', 'MailboxQuota could not remove an attachment file', ['file_path' => $filePath]);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('logMessage')) {
            logMessage($level, $message, $context);
            return;
        }
        error_log('[MailboxQuota][' . $level . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}
