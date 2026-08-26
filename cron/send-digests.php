<?php
/**
 * Cron script to send digest emails to pro users.
 * Usage: php send-digests.php [--dry-run] [--limit=N]
 */
require_once __DIR__ . '/../config.php';

define('TEMPMAIL_APP', true);

// Allow authorized HTTP triggers using CRON_HTTP_SECRET (header X-Cron-Secret or ?key=)
$cronHttpSecret = $_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null);

// Simple CLI args parser
$dryRun = false;
$limit = 0;
foreach ($argv as $arg) {
    if ($arg === '--dry-run') $dryRun = true;
    if (strpos($arg, '--limit=') === 0) $limit = (int)substr($arg, 8);
}

// Only run from CLI unless authorized via HTTP secret or from localhost
if (php_sapi_name() !== 'cli') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote === '127.0.0.1') {
        // allow localhost
    } else {
        $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['key'] ?? null);
        if (empty($cronHttpSecret) || empty($provided) || !function_exists('hash_equals') || !hash_equals((string)$cronHttpSecret, (string)$provided)) {
            http_response_code(403);
            die('Access denied - only CLI, localhost or authorized HTTP clients allowed');
        }
        // authorized via secret key
    }
}

// logMessage('DEBUG', 'Starting digest run', ['dry_run' => $dryRun, 'limit' => $limit]);
echo "Starting digest run (dry_run=" . ($dryRun ? '1' : '0') . ", limit={$limit})\n";

// Batch size for selecting users
$batchSize = $limit > 0 ? $limit : 100;

// Throttling / SMTP quota protection (can be configured via env or config)
$maxPerRun = (int)($_ENV['SMTP_MAX_PER_RUN'] ?? ($config['cron']['smtp_max_per_run'] ?? 500));
$batchSendSize = (int)($_ENV['SMTP_BATCH_SIZE'] ?? ($config['cron']['smtp_batch_size'] ?? 50));
$smtpPerMinute = (int)($_ENV['SMTP_PER_MINUTE'] ?? ($config['cron']['smtp_per_minute'] ?? 200));
if ($smtpPerMinute < 1) $smtpPerMinute = 200;
$sleepSeconds = max(1, (int)ceil(60 * $batchSendSize / $smtpPerMinute));

$sentCount = 0;
// logMessage('INFO', 'Digest sender throttle config', ['max_per_run' => $maxPerRun, 'batch_send_size' => $batchSendSize, 'per_minute' => $smtpPerMinute, 'sleep_seconds' => $sleepSeconds]);

try {
    // Select users eligible: digest_enabled=1, address_ttl_days > 1
    // We'll filter by per-user scheduled hour/timezone in PHP to allow timezone math and staggering
    // Digests are a Pro entitlement: once the account_type migration has run, restrict the
    // selection to Pro accounts in SQL so Regular accounts are never picked up. Fall back to the
    // pre-migration query so this cron keeps working before the migration lands (deploy order
    // doesn't matter).
    if (tableHasColumn('pro_users', 'account_type')) {
        $stmt = $pdo->prepare(
            "SELECT id, email, digest_frequency_days, digest_last_sent, address_ttl_days, digest_hour, digest_tz
             FROM pro_users
             WHERE digest_enabled = 1 AND COALESCE(address_ttl_days,1) > 1
             AND account_type = 'pro'
             AND (pro_expires_at IS NULL OR pro_expires_at > NOW())
             LIMIT ?"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, email, digest_frequency_days, digest_last_sent, address_ttl_days, digest_hour, digest_tz
             FROM pro_users
             WHERE digest_enabled = 1 AND COALESCE(address_ttl_days,1) > 1
             LIMIT ?"
        );
    }
    $stmt->execute([$batchSize]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        // Respect overall max-per-run
        if ($maxPerRun > 0 && $sentCount >= $maxPerRun) {
            logMessage('INFO', 'Max digests per run reached, stopping early', ['sent' => $sentCount, 'max_per_run' => $maxPerRun]);
            break;
        }
        $userId = (int)$user['id'];
        $freqDays = max(1, (int)$user['digest_frequency_days']);
        $lastSent = $user['digest_last_sent'];

        // Per-user scheduling: determine scheduled UTC timestamp for today at user's chosen hour
        $userHour = isset($user['digest_hour']) && $user['digest_hour'] !== null ? (int)$user['digest_hour'] : 5;
        $userTz = !empty($user['digest_tz']) ? $user['digest_tz'] : 'UTC';
        // Compute scheduled time (timestamp) for today in user's timezone converted to UTC.
        // Use the user's local "now" to determine the correct local date (avoids off-by-one near midnight UTC).
        try {
            $userNow = new DateTime('now', new DateTimeZone($userTz));
            $todayStr = $userNow->format('Y-m-d');
            // Build DateTime in user's timezone for today at userHour:00
            $userDt = new DateTime($todayStr . ' ' . sprintf('%02d:00:00', $userHour), new DateTimeZone($userTz));
            // Convert to UTC timestamp
            $userDt->setTimezone(new DateTimeZone('UTC'));
            $scheduledTs = $userDt->getTimestamp();
        } catch (Exception $e) {
            // If timezone invalid or conversion fails, fallback to 05:00 UTC
            $scheduledTs = strtotime(date('Y-m-d') . ' 05:00:00 UTC');
        }

        // Window in seconds to consider a scheduled send (e.g., 10 minutes)
        $windowSeconds = 10 * 60; // 10 minutes
        // Stagger within the window deterministically per user to avoid bursts
        $staggerOffset = ($userId % $windowSeconds); // 0..windowSeconds-1
        $nowTs = time();
        $scheduledTsWithOffset = $scheduledTs + $staggerOffset;
        // If current time isn't within window, skip user for now
        if (abs($nowTs - $scheduledTsWithOffset) > $windowSeconds) {
            continue;
        }

        // Enforce minimum 24 hours since last send
        if ($lastSent) {
            $lastTimestamp = strtotime($lastSent);
            if (time() - $lastTimestamp < 24 * 3600) {
                // skip
                continue;
            }
        }

        // If last_sent exists, check frequency days
        if ($lastSent) {
            $nextAllowed = strtotime($lastSent) + ($freqDays * 24 * 3600);
            if (time() < $nextAllowed) {
                continue; // not time yet
            }
        }

        // Gather unread messages that have not been included in a digest
        $msgStmt = $pdo->prepare(
            "SELECT se.id, se.from_address, se.subject, se.received_at
             FROM stored_emails se
             JOIN temp_emails te ON se.temp_email_id = te.id
             WHERE te.pro_user_id = ? AND se.digest_included_at IS NULL
             ORDER BY se.received_at DESC
             LIMIT 50"
        );
        $msgStmt->execute([$userId]);
        $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($messages)) {
            // Nothing to send
            continue;
        }

        // Build plaintext message
        $count = count($messages);
        $lines = [];
        foreach ($messages as $m) {
            $from = $m['from_address'] ?? '(unknown)';
            $subject = $m['subject'] ?? '(no subject)';
            $received = date('Y-m-d H:i', strtotime($m['received_at'] ?? ''));
            $lines[] = "- {$from} | {$subject} | {$received}";
        }

        $loginUrl = rtrim($config['email']['base_url'], '/') . '/pro_login.php';
        $subjectLine = "TempMail: You have {$count} waiting message" . ($count === 1 ? '' : 's');
        $body = "Hello,\n\nYou have {$count} message" . ($count === 1 ? '' : 's') . " waiting in your TempMail Pro account.\n\nVisit your inbox to read them:\n{$loginUrl}\n\nMessages:\n" . implode("\n", $lines) . "\n\nThis is an automated summary from TempMail.\n";

        // Send email using similar logic to pro_auth::sendLoginEmail
        $to = $user['email'];
        $fromAddress = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
        $headers = [];
        $headers[] = 'From: TempMail <' . $fromAddress . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headersStr = implode("\r\n", $headers);

        $sent = false;
        if ($dryRun) {
            echo "DRY_RUN: Would send to {$to} with {$count} messages\n";
            $sent = true;
        } else {
            try {
                $envelope = '-f' . $fromAddress;
                $sent = mail($to, $subjectLine, $body, $headersStr, $envelope);
                if ($sent === false) {
                    logMessage('ERROR', 'mail() returned false when sending digest', ['to' => $to]);
                }
            } catch (Exception $e) {
                $sent = false;
                logMessage('ERROR', 'Digest send exception', ['error' => $e->getMessage(), 'to' => $to, 'user_id' => $userId]);
            }
        }
        
        // Record results
        $now = date('Y-m-d H:i:s');
        $messageIds = array_map(function($m){ return (int)$m['id']; }, $messages);
        if ($sent) {
            // Mark messages as included
            $inClause = implode(',', $messageIds);
            $pdo->prepare("UPDATE stored_emails SET digest_included_at = ? WHERE id IN ({$inClause})")->execute([$now]);
            // Update pro_users.digest_last_sent
            $pdo->prepare("UPDATE pro_users SET digest_last_sent = ? WHERE id = ?")->execute([$now, $userId]);
            // Insert log
            $lstmt = $pdo->prepare("INSERT INTO digest_logs (pro_user_id, scheduled_for, sent_at, message_ids, message_count, status) VALUES (?, ?, ?, ?, ?, 'sent')");
            $lstmt->execute([$userId, $now, $now, json_encode($messageIds), count($messageIds)]);
            // Metrics: increment digests_sent stat
            try { updateStat('digests_sent', 1); } catch (Exception $_) {}
            echo "Sent digest to {$to} ({$count} messages)\n";
            $sentCount++;
            // If we've sent a batch, sleep a little to respect per-minute rate
            if ($batchSendSize > 0 && ($sentCount % $batchSendSize) === 0) {
                logMessage('INFO', 'Throttling pause after batch', ['sent' => $sentCount, 'sleep_seconds' => $sleepSeconds]);
                sleep($sleepSeconds);
            }
        } else {
            $lstmt = $pdo->prepare("INSERT INTO digest_logs (pro_user_id, scheduled_for, sent_at, message_ids, message_count, status, error_text) VALUES (?, ?, NULL, ?, ?, 'failed', ?)");
            $lstmt->execute([$userId, $now, json_encode($messageIds), count($messageIds), 'Send failed']);
            echo "Failed to send digest to {$to}\n";
            // Failed sends still count toward attempts but not metrics; small pause to avoid hot-looping failures
            sleep(1);
        }
    }

    echo "Digest run complete.\n";
} catch (Exception $e) {
    echo "Error during digest run: " . $e->getMessage() . "\n";
    logMessage('ERROR', 'Digest run failed', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
