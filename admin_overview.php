<?php
/**
 * System overview for the admin pages (log_viewer.php, abuse_admin.php).
 *
 * adminOverview() gathers one snapshot of what is going on in the system:
 * accounts, addresses, incoming mail, the webhook queue, the abuse guard,
 * the log and a handful of health checks. Every figure is read on its own,
 * inside its own try/catch, so a missing table or column (a migration that
 * has not run yet) blanks that one figure — null, shown as "—" — instead of
 * breaking the page. Nothing here writes, and nothing here returns a user's
 * email address or a secret: accounts are counted, keys are reported as
 * set / not set.
 *
 * The caller is responsible for the ADMIN_USER_IDS gate (isAdminUser()).
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/abuse_guard.php';
require_once __DIR__ . '/pii_crypto.php';
require_once __DIR__ . '/webhook_secret.php';

if (!function_exists('adminScalar')) {
    /** One scalar from a parameterised query, or null when the query fails. */
    function adminScalar(PDO $pdo, string $sql, array $params = []): ?int
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? 0 : (int)$value;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('adminRows')) {
    /** All rows of a parameterised query, or [] when the query fails. */
    function adminRows(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('adminAge')) {
    /**
     * "3 min", "2 h", "4 d" — the age of a database timestamp, or null without
     * one. Pass the database's NOW() as $dbNow: PHP's clock may be in another
     * time zone than the stored values.
     */
    function adminAge(?string $timestamp, ?string $dbNow = null): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return null;
        }
        $now = $dbNow !== null ? strtotime($dbNow) : false;
        $diff = max(0, ($now === false ? time() : $now) - $ts);
        if ($diff < 3600) {
            return max(1, (int)floor($diff / 60)) . ' min';
        }
        if ($diff < 172800) {
            return (int)floor($diff / 3600) . ' h';
        }
        return (int)floor($diff / 86400) . ' d';
    }
}

if (!function_exists('adminFormatBytes')) {
    function adminFormatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$value : number_format($value, 1)) . ' ' . $units[$i];
    }
}

if (!function_exists('adminOverview')) {
    /**
     * @return array{
     *   accounts: array, addresses: array, mail: array, webhooks: array,
     *   abuse: array, logs: array, health: array, alerts: list<array{level:string,text:string,href:?string}>
     * }
     */
    function adminOverview(PDO $pdo): array
    {
        global $config, $environment;

        $hasCol = static function (string $table, string $column): bool {
            return function_exists('tableHasColumn') && tableHasColumn($table, $column);
        };

        // The database clock: every "last 24 hours" below is NOW() there, and
        // the timestamps shown on the page are in that clock too.
        $dbNow = null;
        try {
            $dbNow = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        } catch (Throwable $e) {
            $dbNow = null;
        }

        // --- Accounts --------------------------------------------------
        $hasType = $hasCol('pro_users', 'account_type');
        $hasLogin = $hasCol('pro_users', 'last_login_at');
        $hasSuspended = $hasCol('pro_users', 'suspended_at');
        $proRule = $hasType
            ? "account_type = 'pro' AND (pro_expires_at IS NULL OR pro_expires_at > NOW())"
            : '(pro_expires_at IS NULL OR pro_expires_at > NOW())';
        $accounts = [
            'total' => adminScalar($pdo, 'SELECT COUNT(*) FROM pro_users'),
            'pro' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_users WHERE $proRule"),
            'new_7d' => adminScalar($pdo, 'SELECT COUNT(*) FROM pro_users WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)'),
            'active_24h' => $hasLogin ? adminScalar($pdo, 'SELECT COUNT(*) FROM pro_users WHERE last_login_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)') : null,
            'suspended' => $hasSuspended ? adminScalar($pdo, 'SELECT COUNT(*) FROM pro_users WHERE suspended_at IS NOT NULL') : null,
            'pro_expiring_7d' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_users WHERE $proRule AND pro_expires_at IS NOT NULL AND pro_expires_at < DATE_ADD(NOW(), INTERVAL 7 DAY)"),
        ];
        $accounts['regular'] = ($accounts['total'] !== null && $accounts['pro'] !== null)
            ? max(0, $accounts['total'] - $accounts['pro'])
            : null;

        // --- Addresses -------------------------------------------------
        $hasPersonal = $hasCol('temp_emails', 'is_personal');
        $addresses = [
            'active' => adminScalar($pdo, 'SELECT COUNT(*) FROM temp_emails WHERE expires_at > NOW()'),
            'sticky' => $hasPersonal ? adminScalar($pdo, 'SELECT COUNT(*) FROM temp_emails WHERE is_personal = 1 AND expires_at > NOW()') : null,
            'timed' => $hasPersonal ? adminScalar($pdo, 'SELECT COUNT(*) FROM temp_emails WHERE (is_personal = 0 OR is_personal IS NULL) AND expires_at > NOW()') : null,
            'created_24h' => adminScalar($pdo, 'SELECT COUNT(*) FROM temp_emails WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
            // An address more than two hours past its expiry should have been
            // removed by cron/cleanup.php — a growing number means it is not running.
            'overdue_cleanup' => adminScalar($pdo, 'SELECT COUNT(*) FROM temp_emails WHERE expires_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)'),
        ];

        // --- Incoming mail --------------------------------------------
        $mail = [
            'received_1h' => adminScalar($pdo, 'SELECT COUNT(*) FROM stored_emails WHERE received_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'),
            'received_24h' => adminScalar($pdo, 'SELECT COUNT(*) FROM stored_emails WHERE received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
            'received_7d' => adminScalar($pdo, 'SELECT COUNT(*) FROM stored_emails WHERE received_at > DATE_SUB(NOW(), INTERVAL 7 DAY)'),
            'stored' => adminScalar($pdo, 'SELECT COUNT(*) FROM stored_emails'),
            'attachments' => adminScalar($pdo, 'SELECT COUNT(*) FROM email_attachments'),
            'attachment_bytes' => adminScalar($pdo, 'SELECT COALESCE(SUM(file_size), 0) FROM email_attachments'),
            'last_received' => null,
            'hourly' => [],
        ];
        $last = adminRows($pdo, 'SELECT MAX(received_at) AS t FROM stored_emails');
        $mail['last_received'] = $last[0]['t'] ?? null;
        // Bucketed by hours ago in the database's own clock, so a PHP and a
        // MySQL time zone that differ cannot shift the series.
        $mail['hourly'] = adminHourlySeries(adminRows(
            $pdo,
            'SELECT TIMESTAMPDIFF(HOUR, received_at, NOW()) AS ago, COUNT(*) AS n
               FROM stored_emails WHERE received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              GROUP BY ago'
        ), $dbNow);

        // --- Webhooks -------------------------------------------------
        $webhooks = [
            'active_hooks' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_webhooks WHERE filter_mode = 'all'"),
            'pending' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_webhook_deliveries WHERE status = 'pending'"),
            // Due for more than 15 minutes and still pending: the retry worker
            // (cron/process-webhook-deliveries.php) is not keeping up.
            'overdue' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_webhook_deliveries WHERE status = 'pending' AND COALESCE(next_attempt_at, created_at) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"),
            'succeeded_24h' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_webhook_deliveries WHERE status = 'succeeded' AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'failed_24h' => adminScalar($pdo, "SELECT COUNT(*) FROM pro_webhook_deliveries WHERE status = 'failed' AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'recent_failures' => adminRows(
                $pdo,
                "SELECT id, webhook_id, user_id, attempts, response_code, last_error, updated_at
                   FROM pro_webhook_deliveries WHERE status = 'failed'
                  ORDER BY updated_at DESC LIMIT 5"
            ),
        ];

        // --- Abuse guard ----------------------------------------------
        $settings = abuseGuardSettings();
        $abuseAvailable = abuseGuardAvailable();
        $abuse = [
            'enabled' => (bool)$settings['enabled'],
            'available' => $abuseAvailable,
            'quarantined' => null,
            'closed' => null,
            'proposals' => null,
            'events_24h' => null,
            'last_report' => null,
        ];
        if ($abuseAvailable) {
            $abuse['quarantined'] = adminScalar($pdo, 'SELECT COUNT(*) FROM address_quarantines WHERE quarantined_until IS NOT NULL');
            $abuse['closed'] = adminScalar($pdo, 'SELECT COUNT(*) FROM address_quarantines WHERE quarantined_until IS NULL');
            $abuse['events_24h'] = adminScalar($pdo, "SELECT COUNT(*) FROM abuse_events WHERE kind <> 'hourly_report' AND created_at > ?", [abuseTime(time() - 86400)]);
            try {
                $abuse['proposals'] = count(abuseSuspensionProposals($pdo));
            } catch (Throwable $e) {
                $abuse['proposals'] = null;
            }
            $report = adminRows($pdo, "SELECT MAX(created_at) AS t FROM abuse_events WHERE kind = 'hourly_report'");
            $abuse['last_report'] = $report[0]['t'] ?? null;
        }

        // --- Log ------------------------------------------------------
        $levels = ['ERROR' => 0, 'WARNING' => 0, 'INFO' => 0, 'DEBUG' => 0];
        $levelRows = adminRows($pdo, 'SELECT log_level, COUNT(*) AS n FROM system_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY log_level');
        foreach ($levelRows as $row) {
            $levels[strtoupper((string)$row['log_level'])] = (int)$row['n'];
        }
        $logs = [
            'levels_24h' => $levels,
            'total' => adminScalar($pdo, 'SELECT COUNT(*) FROM system_logs'),
            'hidden_types' => adminScalar($pdo, 'SELECT COUNT(*) FROM hidden_log_types WHERE hidden = 1'),
            'top_problems' => adminRows(
                $pdo,
                "SELECT LEFT(message, 255) AS log_key, log_level, COUNT(*) AS n, MAX(created_at) AS last_seen
                   FROM system_logs
                  WHERE log_level IN ('ERROR', 'WARNING') AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  GROUP BY log_key, log_level ORDER BY n DESC LIMIT 8"
            ),
            'hourly_errors' => adminHourlySeries(adminRows(
                $pdo,
                "SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) AS ago, COUNT(*) AS n
                   FROM system_logs WHERE log_level = 'ERROR' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  GROUP BY ago"
            ), $dbNow),
        ];

        // --- Health ---------------------------------------------------
        $attachmentsDir = __DIR__ . '/attachments';
        $free = @disk_free_space($attachmentsDir);
        $dbVersion = null;
        try {
            $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable $e) {
            $dbVersion = null;
        }
        $health = [
            'environment' => (string)($environment ?? 'unknown'),
            'app_version' => (string)($config['app']['version'] ?? ''),
            'php_version' => PHP_VERSION,
            'db_version' => $dbVersion,
            'debug_mode' => !empty($config['app']['debug_mode']),
            'log_level' => strtoupper((string)($config['app']['log_level'] ?? 'info')),
            'log_retention_days' => (int)($config['cleanup']['log_retention_days'] ?? 30),
            'forwarders_enabled' => !empty($config['directadmin']['forwarder_enabled']),
            'pii_keys' => function_exists('piiKeysConfigured') && piiKeysConfigured(),
            'webhooks_key' => function_exists('webhookSecretKey') && webhookSecretKey() !== null,
            'trial_hash_key' => strlen((string)($config['trial']['hash_key'] ?? '')) >= 32,
            'attachments_writable' => is_dir($attachmentsDir) && is_writable($attachmentsDir),
            'disk_free' => $free === false ? null : (int)$free,
            'vendor' => is_file(__DIR__ . '/vendor/autoload.php'),
        ];

        // --- Alerts: the things an admin should look at first -----------
        $alerts = [];
        $add = static function (string $level, string $text, ?string $href = null) use (&$alerts): void {
            $alerts[] = ['level' => $level, 'text' => $text, 'href' => $href];
        };
        if ($levels['ERROR'] > 0) {
            $add('danger', $levels['ERROR'] . ' error(s) logged in the last 24 hours.', 'log_viewer.php?view=logs&level=ERROR');
        }
        if (($abuse['proposals'] ?? 0) > 0) {
            $add('warning', $abuse['proposals'] . ' suspension proposal(s) waiting for a decision.', 'abuse_admin.php');
        }
        if (($webhooks['overdue'] ?? 0) > 0) {
            $add('warning', $webhooks['overdue'] . ' webhook deliveries overdue by more than 15 minutes — is cron/process-webhook-deliveries.php running?');
        }
        if (($addresses['overdue_cleanup'] ?? 0) > 0) {
            $add('warning', $addresses['overdue_cleanup'] . ' expired address(es) not yet removed — is cron/cleanup.php running?');
        }
        if ($abuseAvailable && $abuse['last_report'] !== null && $dbNow !== null
            && strtotime((string)$abuse['last_report']) < strtotime($dbNow) - 2 * 3600) {
            $add('warning', 'The abuse guard has not reported for ' . adminAge((string)$abuse['last_report'], $dbNow) . ' — is cron/abuse-guard.php running?');
        }
        if ($abuse['enabled'] && !$abuseAvailable) {
            $add('warning', 'The abuse guard tables are missing: run php migrate_abuse_guard.php.');
        }
        if (!$health['pii_keys']) {
            $add('danger', 'PII_ENCRYPTION_KEY / PII_INDEX_KEY are not set: nobody can log in by email.');
        }
        if (!$health['attachments_writable']) {
            $add('danger', 'The attachments directory is not writable.');
        }
        if ($health['disk_free'] !== null && $health['disk_free'] < 1073741824) {
            $add('warning', 'Less than 1 GB of disk left (' . adminFormatBytes($health['disk_free']) . ').');
        }
        if ($health['environment'] === 'production' && $health['debug_mode']) {
            $add('warning', 'DEBUG_MODE is on in production.');
        }

        return [
            'db_now' => $dbNow,
            'accounts' => $accounts,
            'addresses' => $addresses,
            'mail' => $mail,
            'webhooks' => $webhooks,
            'abuse' => $abuse,
            'logs' => $logs,
            'health' => $health,
            'alerts' => $alerts,
        ];
    }
}

if (!function_exists('adminHourlySeries')) {
    /**
     * Turns grouped rows (ago = whole hours before the database's NOW(), n)
     * into 24 hourly points, oldest first and ending with the current hour;
     * hours without rows are 0. $dbNow (the database's NOW()) labels them.
     *
     * @return list<array{hour:string,label:string,count:int}>
     */
    function adminHourlySeries(array $rows, ?string $dbNow = null): array
    {
        $byAgo = [];
        foreach ($rows as $row) {
            $ago = (int)$row['ago'];
            if ($ago >= 0 && $ago < 24) {
                $byAgo[$ago] = ($byAgo[$ago] ?? 0) + (int)$row['n'];
            }
        }
        $now = ($dbNow !== null && strtotime($dbNow) !== false) ? strtotime($dbNow) : time();
        $series = [];
        for ($ago = 23; $ago >= 0; $ago--) {
            $ts = $now - $ago * 3600;
            $series[] = [
                'hour' => $ago === 0 ? 'Last hour' : date('Y-m-d H:i', $ts - 3600) . '–' . date('H:i', $ts),
                'label' => date('H:i', $ts - 3600),
                'count' => $byAgo[$ago] ?? 0,
            ];
        }
        return $series;
    }
}
