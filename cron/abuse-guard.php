<?php
/**
 * Abuse guard worker (abuse_guard.php, documentaion/ABUSE_PROTECTION.md).
 * Run it every minute:
 *
 *   * * * * * php /path/to/cron/abuse-guard.php
 *
 * Each run:
 *  1. removes the DirectAdmin forwarder of every active quarantine that
 *     still has one (parse.php tries once inline; this is the retry, and
 *     the only removal for the account track and for suspensions);
 *  2. reopens the quarantines whose time is up by recreating the forwarder;
 *  3. mails the queued notices (abuse_events.notify = 1) to the account
 *     owner, or to the admins for a suspension proposal;
 *  4. once an hour: logs the busiest addresses of the last hour (the
 *     monitoring the thresholds are tuned from) and sweeps counters older
 *     than two days and events older than 90 days.
 *
 * Idempotent and cheap when there is nothing to do.
 */
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config.php';

cronRequireAccess($_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null));

require_once __DIR__ . '/../abuse_guard.php';
require_once __DIR__ . '/../pii_crypto.php';

$isCli = php_sapi_name() === 'cli';
$out = static function (string $line) use ($isCli): void {
    if ($isCli) {
        echo $line . "\n";
    }
};

if (!abuseGuardAvailable()) {
    $out('Abuse guard is not available (run migrate_abuse_guard.php, or ABUSE_GUARD_ENABLED is off).');
    exit(0);
}

$settings = abuseGuardSettings();
$now = time();
$domain = (string)($config['email']['domain'] ?? 'manjo.me');
$baseUrl = (string)($config['email']['base_url'] ?? '');

/** Send one plain-text notice, the same way the other account mails are sent. */
function abuseGuardSendMail(string $to, string $subject, string $body): bool
{
    global $config;
    $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
    $headers = implode("\r\n", [
        'From: Mail Shield <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    try {
        return mail($to, $subject, $body, $headers, '-f' . $from) === true;
    } catch (Throwable $e) {
        return false;
    }
}

// 1. Forwarders still to be removed.
try {
    $removals = abuseRetryForwarderRemovals($pdo, 'directAdminRemoveForwarder', $now);
    if ($removals['removed'] + $removals['failed'] > 0) {
        logMessage($removals['failed'] > 0 ? 'WARNING' : 'INFO', 'Abuse guard: quarantine forwarders removed', $removals);
    }
    $out('Forwarders removed: ' . $removals['removed'] . ', failed: ' . $removals['failed']);
} catch (Throwable $e) {
    logMessage('ERROR', 'Abuse guard: forwarder removal failed', ['error' => $e->getMessage()]);
}

// 2. Quarantines whose time is up.
try {
    $release = abuseReleaseDue($pdo, 'createDirectAdminForwarder', $now);
    if ($release['released'] + $release['dropped'] + $release['failed'] > 0) {
        logMessage($release['failed'] > 0 ? 'WARNING' : 'INFO', 'Abuse guard: quarantines released', $release);
    }
    $out('Quarantines released: ' . $release['released'] . ', dropped: ' . $release['dropped'] . ', failed: ' . $release['failed']);
} catch (Throwable $e) {
    logMessage('ERROR', 'Abuse guard: releasing quarantines failed', ['error' => $e->getMessage()]);
}

// 3. Notices.
try {
    $stmt = $pdo->prepare('SELECT id, created_at, kind, subject, pro_user_id, detail FROM abuse_events WHERE notify = 1 AND notified_at IS NULL ORDER BY id LIMIT 50');
    $stmt->execute();
    $stamp = $pdo->prepare('UPDATE abuse_events SET notified_at = ? WHERE id = ?');
    $sent = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $detail = json_decode((string)($event['detail'] ?? ''), true);
        $detail = is_array($detail) ? $detail : [];
        $subject = (string)($event['subject'] ?? '');
        $address = ($subject !== '' && strpos($subject, ':') === false) ? $subject . '@' . $domain : null;
        $forAdmin = $event['kind'] === 'account_suspend_proposed';

        $recipients = [];
        if ($forAdmin) {
            foreach ($config['admin']['user_ids'] ?? [] as $adminId) {
                $recipients[] = (int)$adminId;
            }
        } elseif ($event['pro_user_id'] !== null) {
            $recipients[] = (int)$event['pro_user_id'];
        }

        $text = abuseNoticeText((string)$event['kind'], $address, $detail, $baseUrl, $forAdmin);
        $delivered = $text === null || $recipients === [];
        foreach ($text !== null ? $recipients : [] as $userId) {
            $to = proUserEmail($pdo, $userId);
            if ($to === null) {
                continue;
            }
            if (abuseGuardSendMail($to, $text['subject'], $text['body'])) {
                $delivered = true;
                $sent++;
            }
        }

        // A notice that cannot be sent is retried for a day, then dropped:
        // an old warning is worth less than a full queue.
        if ($delivered || strtotime((string)$event['created_at']) < $now - 86400) {
            $stamp->execute([abuseTime($now), (int)$event['id']]);
            if (!$delivered) {
                logMessage('WARNING', 'Abuse guard: notice dropped after a day of failed sends', ['event_id' => (int)$event['id'], 'kind' => $event['kind'], 'user_id' => $event['pro_user_id']]);
            }
        }
    }
    $out('Notices sent: ' . $sent);
} catch (Throwable $e) {
    logMessage('ERROR', 'Abuse guard: sending notices failed', ['error' => $e->getMessage()]);
}

// 4. Hourly: monitoring report and housekeeping.
try {
    if (abuseEventCount($pdo, 'hourly_report', null, null, $now - 3300) === 0) {
        $stmt = $pdo->prepare("SELECT subject, SUM(hits) AS hits, SUM(bytes) AS bytes, SUM(strikes) AS strikes FROM abuse_counters WHERE scope = 'addr' AND window_start > ? GROUP BY subject ORDER BY hits DESC LIMIT 10");
        $stmt->execute([abuseTime($now - 3600)]);
        $top = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $top[] = ['address' => $row['subject'], 'messages' => (int)$row['hits'], 'bytes' => (int)$row['bytes'], 'strikes' => (int)$row['strikes']];
        }
        $active = (int)$pdo->query('SELECT COUNT(*) FROM address_quarantines')->fetchColumn();
        $proposals = count(abuseSuspensionProposals($pdo));
        logMessage('INFO', 'Abuse guard: busiest addresses in the last hour', [
            'top' => $top,
            'quarantines' => $active,
            'open_suspension_proposals' => $proposals,
            'limits' => [
                'per_5min' => $settings['address_max_5min'],
                'per_hour' => $settings['address_max_hour'],
                'bytes_per_hour' => $settings['address_max_bytes_hour'],
            ],
        ]);
        $counters = abuseCounterPurge($pdo, $now - 2 * 86400);
        $events = abuseEventPurge($pdo, $now - 90 * 86400);
        abuseEventAdd($pdo, 'hourly_report', null, null, ['counters_purged' => $counters, 'events_purged' => $events], false, $now);
        $out('Hourly report logged; purged ' . $counters . ' counter row(s) and ' . $events . ' event(s).');
    }
} catch (Throwable $e) {
    logMessage('ERROR', 'Abuse guard: hourly report failed', ['error' => $e->getMessage()]);
}

exit(0);
