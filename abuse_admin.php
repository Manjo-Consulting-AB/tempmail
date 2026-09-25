<?php
/**
 * Abuse guard admin (abuse_guard.php, documentaion/ABUSE_PROTECTION.md).
 *
 * The one place an account is suspended: the abuse guard only *proposes* a
 * suspension, and an admin confirms or dismisses it here. Also lists the
 * addresses in quarantine (with a way to reopen one early), the suspended
 * accounts (with a way to lift a suspension) and the latest events.
 *
 * Access: the account ids in ADMIN_USER_IDS, exactly like log_viewer.php.
 * State changes are same-origin POSTs followed by a redirect.
 */

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/abuse_guard.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}
$adminId = (int) ($_SESSION['pro_user_id'] ?? 0);
if (!isAdminUser($adminId)) {
    error_log(sprintf('abuse_admin.php access denied: logged_in=%s', $adminId > 0 ? 'yes' : 'no'));
    http_response_code(403);
    echo "Access denied\n";
    exit;
}

header('X-Robots-Tag: noindex, nofollow');

$available = abuseGuardAvailable();
$settings = abuseGuardSettings();
$domain = (string) ($config['email']['domain'] ?? 'manjo.me');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notice = 'Nothing done.';
    if (!requireSameOriginRequest()) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    if (!$available) {
        $notice = 'The abuse guard is not available (run migrate_abuse_guard.php).';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) ($_POST['user_id'] ?? 0);
        $local = strtolower(trim((string) ($_POST['local_part'] ?? '')));
        $now = time();
        try {
            switch ($action) {
                case 'suspend':
                    if ($userId <= 0 || isAdminUser($userId)) {
                        $notice = 'Invalid account.';
                        break;
                    }
                    $closed = abuseSuspendAccount($pdo, $userId, $adminId, $now, $settings);
                    $removals = abuseRetryForwarderRemovals($pdo, 'directAdminRemoveForwarder', $now);
                    logMessage('WARNING', 'Account suspended by an admin', ['user_id' => $userId, 'admin_id' => $adminId, 'addresses' => $closed, 'forwarders_failed' => $removals['failed']]);
                    $notice = "Account #{$userId} suspended; {$closed} address(es) closed"
                        . ($removals['failed'] > 0 ? ", {$removals['failed']} forwarder(s) left for the cron retry." : '.');
                    break;
                case 'dismiss':
                    if ($userId <= 0 || abuseSuspensionPending($pdo, $userId) === null) {
                        $notice = 'No open proposal for that account.';
                        break;
                    }
                    abuseDismissProposal($pdo, $userId, $adminId, $now);
                    logMessage('INFO', 'Suspension proposal dismissed by an admin', ['user_id' => $userId, 'admin_id' => $adminId]);
                    $notice = "Proposal for account #{$userId} dismissed.";
                    break;
                case 'unsuspend':
                    if ($userId <= 0) {
                        $notice = 'Invalid account.';
                        break;
                    }
                    abuseUnsuspendAccount($pdo, $userId, $adminId, $now);
                    $release = abuseReleaseDue($pdo, 'createDirectAdminForwarder', $now, $userId);
                    logMessage('WARNING', 'Account suspension lifted by an admin', ['user_id' => $userId, 'admin_id' => $adminId] + $release);
                    $notice = "Account #{$userId} reopened; {$release['released']} address(es) reopened"
                        . ($release['failed'] > 0 ? ", {$release['failed']} left for the cron retry." : '.');
                    break;
                case 'reopen':
                    if ($local === '' || sanitizeLocalPart($local, 1, 64) === null || !abuseQuarantineReopen($pdo, $local, $now)) {
                        $notice = 'No such quarantine.';
                        break;
                    }
                    $release = abuseReleaseDue($pdo, 'createDirectAdminForwarder', $now);
                    logMessage('INFO', 'Quarantine lifted by an admin', ['local_part' => $local, 'admin_id' => $adminId] + $release);
                    $notice = $release['failed'] > 0
                        ? 'Reopen requested; DirectAdmin failed, the cron job will retry.'
                        : "{$local}@{$domain} reopened.";
                    break;
            }
        } catch (Throwable $e) {
            logMessage('ERROR', 'abuse_admin.php action failed', ['action' => $action, 'error' => $e->getMessage()]);
            $notice = 'The action failed; see the log.';
        }
    }
    $_SESSION['abuse_admin_notice'] = $notice;
    header('Location: abuse_admin.php', true, 303);
    exit;
}

$notice = (string) ($_SESSION['abuse_admin_notice'] ?? '');
unset($_SESSION['abuse_admin_notice']);

$proposals = [];
$quarantines = [];
$suspended = [];
$events = [];
if ($available) {
    $now = time();
    foreach (abuseSuspensionProposals($pdo) as $p) {
        $p['strikes_week'] = abuseEventCount($pdo, 'account_strike', null, (int) $p['user_id'], $now - 7 * 86400);
        $p['quarantines_week'] = abuseEventCount($pdo, 'account_quarantined', null, (int) $p['user_id'], $now - 7 * 86400);
        $proposals[] = $p;
    }
    $quarantines = $pdo->query('SELECT local_part, pro_user_id, reason, quarantined_at, quarantined_until, forwarder_removed FROM address_quarantines ORDER BY quarantined_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    $suspended = $pdo->query('SELECT id, suspended_at FROM pro_users WHERE suspended_at IS NOT NULL ORDER BY suspended_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $events = $pdo->query("SELECT created_at, kind, subject, pro_user_id, detail, notified_at FROM abuse_events WHERE kind <> 'hourly_report' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$button = static function (string $action, string $label, array $fields, string $class = 'ms-btn ms-btn--secondary') use ($h): string {
    $html = '<form method="post" class="ms-admin__inline"><input type="hidden" name="action" value="' . $h($action) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $h($name) . '" value="' . $h($value) . '">';
    }
    return $html . '<button type="submit" class="' . $h($class) . '">' . $h($label) . '</button></form>';
};
$msAdminTab = 'abuse';
$msAdminTitle = 'Abuse guard';
require __DIR__ . '/partials/admin_head.php';
?>
    <h1 class="ms-admin__title">Abuse guard</h1>
    <p class="ms-admin__lede">
        Limits: <?php echo (int) $settings['address_max_5min']; ?> messages per 5 min,
        <?php echo (int) $settings['address_max_hour']; ?> per hour,
        <?php echo $h(round($settings['address_max_bytes_hour'] / 1048576)); ?> MB per hour per address.
        Quarantine steps: <?php echo $h(implode(' / ', $settings['quarantine_steps_minutes'])); ?> min, then closed.
    </p>
<?php if ($notice !== ''): ?>
    <p class="ms-admin__notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
<?php if (!$available): ?>
    <p class="ms-admin__notice">The abuse guard tables are missing: run <code>php migrate_abuse_guard.php</code>.</p>
<?php else: ?>

    <section class="ms-admin__section">
        <h2 class="ms-admin__heading">Suspension proposals</h2>
<?php if ($proposals === []): ?>
        <p class="ms-admin__empty">None open.</p>
<?php else: ?>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Account</th><th>Proposed</th><th>Rate-limit strikes (7 d)</th><th>Account quarantines (7 d)</th><th>Decision</th></tr></thead>
                <tbody>
<?php foreach ($proposals as $p): ?>
                    <tr>
                        <td>#<?php echo (int) $p['user_id']; ?></td>
                        <td><?php echo $h($p['created_at']); ?></td>
                        <td><?php echo (int) $p['strikes_week']; ?></td>
                        <td><?php echo (int) $p['quarantines_week']; ?></td>
                        <td>
                            <?php echo $button('suspend', 'Suspend account', ['user_id' => (int) $p['user_id']], 'ms-btn ms-btn--primary'); ?>
                            <?php echo $button('dismiss', 'Dismiss', ['user_id' => (int) $p['user_id']]); ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </section>

    <section class="ms-admin__section">
        <h2 class="ms-admin__heading">Suspended accounts</h2>
<?php if ($suspended === []): ?>
        <p class="ms-admin__empty">None.</p>
<?php else: ?>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Account</th><th>Suspended</th><th></th></tr></thead>
                <tbody>
<?php foreach ($suspended as $s): ?>
                    <tr>
                        <td>#<?php echo (int) $s['id']; ?></td>
                        <td><?php echo $h($s['suspended_at']); ?></td>
                        <td><?php echo $button('unsuspend', 'Lift suspension', ['user_id' => (int) $s['id']]); ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
        <form method="post" class="ms-admin__manual">
            <input type="hidden" name="action" value="suspend">
            <label for="manualSuspend">Suspend an account by id</label>
            <input id="manualSuspend" class="ms-admin__input" type="number" name="user_id" min="1" required>
            <button type="submit" class="ms-btn ms-btn--secondary">Suspend</button>
        </form>
    </section>

    <section class="ms-admin__section">
        <h2 class="ms-admin__heading">Addresses in quarantine</h2>
<?php if ($quarantines === []): ?>
        <p class="ms-admin__empty">None.</p>
<?php else: ?>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Address</th><th>Account</th><th>Reason</th><th>Since</th><th>Until</th><th>Forwarder removed</th><th></th></tr></thead>
                <tbody>
<?php foreach ($quarantines as $q): ?>
                    <tr>
                        <td><?php echo $h($q['local_part'] . '@' . $domain); ?></td>
                        <td><?php echo $q['pro_user_id'] !== null ? '#' . (int) $q['pro_user_id'] : 'anonymous'; ?></td>
                        <td><?php echo $h($q['reason']); ?></td>
                        <td><?php echo $h($q['quarantined_at']); ?></td>
                        <td><?php echo $q['quarantined_until'] === null ? 'closed' : $h($q['quarantined_until']); ?></td>
                        <td><?php echo (int) $q['forwarder_removed'] === 1 ? 'yes' : 'pending'; ?></td>
                        <td><?php echo $button('reopen', 'Reopen now', ['local_part' => (string) $q['local_part']]); ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </section>

    <section class="ms-admin__section">
        <h2 class="ms-admin__heading">Latest events</h2>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Time</th><th>Event</th><th>Subject</th><th>Account</th><th>Detail</th><th>Notified</th></tr></thead>
                <tbody>
<?php foreach ($events as $e): ?>
                    <tr>
                        <td><?php echo $h($e['created_at']); ?></td>
                        <td><?php echo $h($e['kind']); ?></td>
                        <td><?php echo $h($e['subject'] ?? ''); ?></td>
                        <td><?php echo $e['pro_user_id'] !== null ? '#' . (int) $e['pro_user_id'] : ''; ?></td>
                        <td class="ms-admin__mono"><?php echo $h($e['detail'] ?? ''); ?></td>
                        <td><?php echo $h($e['notified_at'] ?? ''); ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/partials/admin_foot.php'; ?>
