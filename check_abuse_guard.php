<?php

declare(strict_types=1);

/**
 * Read-only CLI diagnostics for the abuse guard (abuse_guard.php,
 * documentaion/ABUSE_PROTECTION.md): schema status, the effective limits,
 * the busiest addresses of the last hour, active quarantines, open
 * suspension proposals, suspended accounts, and the two signs that
 * cron/abuse-guard.php is not running (forwarders still waiting to be
 * removed and notices still unsent after ten minutes).
 *
 * Prints account ids and service-domain addresses only, never a user's
 * email address. Changes nothing.
 *
 * Exit codes:
 *   0 — schema in place, nothing stuck
 *   1 — part of the schema missing, or something stuck (see output)
 *   2 — none of the tables exist (run migrate_abuse_guard.php)
 *
 * Usage: php check_abuse_guard.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/abuse_guard.php';

$problems = 0;
$objects = [
    'abuse_counters.subject' => tableHasColumn('abuse_counters', 'subject'),
    'address_quarantines.local_part' => tableHasColumn('address_quarantines', 'local_part'),
    'abuse_events.subject' => tableHasColumn('abuse_events', 'subject'),
    'pro_users.suspended_at' => tableHasColumn('pro_users', 'suspended_at'),
];
echo "Schema\n";
foreach ($objects as $name => $ok) {
    echo '  ' . ($ok ? '[OK]  ' : '[FEL] ') . $name . "\n";
}
if (!in_array(true, $objects, true)) {
    echo "\nNothing is in place: run php migrate_abuse_guard.php\n";
    exit(2);
}
if (in_array(false, $objects, true)) {
    echo "\nPart of the schema is missing: run php migrate_abuse_guard.php\n";
    exit(1);
}

$settings = abuseGuardSettings();
echo "\nSettings" . ($settings['enabled'] ? '' : ' (DISABLED by ABUSE_GUARD_ENABLED)') . "\n";
foreach ($settings as $key => $value) {
    if ($key === 'enabled') continue;
    echo '  ' . str_pad($key, 28) . (is_array($value) ? implode(',', $value) : (string)$value) . "\n";
}

$now = time();
$domain = (string)($config['email']['domain'] ?? 'manjo.me');

echo "\nBusiest addresses, last hour\n";
$stmt = $pdo->prepare("SELECT subject, SUM(hits) AS hits, SUM(bytes) AS bytes, SUM(strikes) AS strikes FROM abuse_counters WHERE scope = 'addr' AND window_start > ? GROUP BY subject ORDER BY hits DESC LIMIT 10");
$stmt->execute([abuseTime($now - 3600)]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) echo "  (no mail counted)\n";
foreach ($rows as $row) {
    printf("  %-40s %6d msg %10d bytes %3d strikes\n", $row['subject'] . '@' . $domain, $row['hits'], $row['bytes'], $row['strikes']);
}

echo "\nActive quarantines\n";
$stmt = $pdo->prepare('SELECT local_part, pro_user_id, reason, quarantined_at, quarantined_until, forwarder_removed FROM address_quarantines ORDER BY quarantined_at DESC');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) echo "  (none)\n";
$stuckRemovals = 0;
$stuckReleases = 0;
foreach ($rows as $row) {
    $until = $row['quarantined_until'] ?? 'closed';
    printf("  %-40s %-10s %-18s since %s until %s forwarder %s\n",
        $row['local_part'] . '@' . $domain,
        $row['pro_user_id'] !== null ? '#' . $row['pro_user_id'] : 'anonymous',
        $row['reason'], $row['quarantined_at'], $until,
        (int)$row['forwarder_removed'] === 1 ? 'removed' : 'PENDING');
    if ((int)$row['forwarder_removed'] !== 1 && strtotime((string)$row['quarantined_at']) < $now - 600
        && ($row['quarantined_until'] === null || strtotime((string)$row['quarantined_until']) > $now)) {
        $stuckRemovals++;
    }
    if ($row['quarantined_until'] !== null && strtotime((string)$row['quarantined_until']) < $now - 600) {
        $stuckReleases++;
    }
}

echo "\nOpen suspension proposals (abuse_admin.php)\n";
$proposals = abuseSuspensionProposals($pdo);
if ($proposals === []) echo "  (none)\n";
foreach ($proposals as $p) {
    echo "  account #{$p['user_id']} since {$p['created_at']}\n";
}

echo "\nSuspended accounts\n";
$rows = $pdo->query('SELECT id, suspended_at FROM pro_users WHERE suspended_at IS NOT NULL ORDER BY suspended_at')->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) echo "  (none)\n";
foreach ($rows as $row) {
    echo "  account #{$row['id']} since {$row['suspended_at']}\n";
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM abuse_events WHERE notify = 1 AND notified_at IS NULL AND created_at < ?');
$stmt->execute([abuseTime($now - 600)]);
$stuckNotices = (int)$stmt->fetchColumn();

echo "\nWorker (cron/abuse-guard.php)\n";
foreach ([
    'forwarder removals pending > 10 min' => $stuckRemovals,
    'quarantines overdue for release > 10 min' => $stuckReleases,
    'notices unsent > 10 min' => $stuckNotices,
] as $label => $count) {
    echo '  ' . ($count === 0 ? '[OK]  ' : '[FEL] ') . $label . ': ' . $count . "\n";
    $problems += $count > 0 ? 1 : 0;
}
if ($problems > 0) {
    echo "\nIs cron/abuse-guard.php scheduled every minute? Check its output and the DirectAdmin credentials.\n";
    exit(1);
}

echo "\nResult: OK\n";
exit(0);
