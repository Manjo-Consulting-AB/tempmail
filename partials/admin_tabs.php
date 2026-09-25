<?php
/**
 * Sub-navigation shared by the admin pages (log_viewer.php, abuse_admin.php).
 *
 * Expects $msAdminTab to name the current tab: 'overview', 'logs', 'types' or
 * 'abuse'. The pages themselves enforce the ADMIN_USER_IDS gate; this partial
 * only renders links.
 */
if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

$msAdminTabs = [
    'overview' => ['Overview', 'log_viewer.php'],
    'logs' => ['Logs', 'log_viewer.php?view=logs'],
    'types' => ['Log types', 'log_viewer.php?view=types'],
    'abuse' => ['Abuse guard', 'abuse_admin.php'],
];
$msAdminCurrent = (string) ($msAdminTab ?? '');
?>
<nav class="ms-admin__tabs" aria-label="Admin">
<?php foreach ($msAdminTabs as $msKey => [$msLabel, $msHref]): ?>
    <a href="<?php echo htmlspecialchars($msHref, ENT_QUOTES, 'UTF-8'); ?>"
       class="ms-admin__tab<?php echo $msKey === $msAdminCurrent ? ' is-active' : ''; ?>"<?php echo $msKey === $msAdminCurrent ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($msLabel, ENT_QUOTES, 'UTF-8'); ?></a>
<?php endforeach; ?>
</nav>
