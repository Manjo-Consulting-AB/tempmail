<?php
/**
 * Referral digest for the admin (epic #387). Mails what happened to the
 * referrals since the last run, and nothing at all on a quiet day. Intended
 * to run from cron once a day; the crontab line is added by the operator:
 *
 *   30 8 * * * php /path/to/cron/referrals-digest.php
 *
 * Usage: php cron/referrals-digest.php [--dry-run]
 *
 * --dry-run prints the mail it would send and neither sends it nor moves the
 * window, so it is safe to run at any time.
 *
 * A mail goes out when an invite was bound, qualified, rewarded or voided in
 * the window, or a reward has just become stuck (it then also lists every
 * stuck reward, which is the one case that needs a person). It carries
 * referral ids and counts only, never an address. The recipient is
 * ADMIN_NOTIFICATION_EMAIL, the same as the registration notice.
 *
 * "Since the last run" is one unix time in a small state file next to the
 * lock files. It moves only after the mail was accepted, so a failed send is
 * retried by the next run; if the file is gone the window falls back to the
 * last 24 hours, and it never reaches back further than 7 days. The window
 * ends a minute before now, so a row written while the run was reading is
 * picked up by the next one instead of being missed.
 */
define('TEMPMAIL_APP', true);
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config.php';

cronRequireAccess($_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null));

require_once __DIR__ . '/../referrals.php';

$dryRun = PHP_SAPI === 'cli' && in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$lock = fopen(sys_get_temp_dir() . '/tempmail_referrals_digest.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "already running\n";
    exit(0);
}

if (!tableHasColumn('referrals', 'status')) {
    echo "Referrals are not available (run migrate_referrals.php).\n";
    exit(0);
}

$stateFile = sys_get_temp_dir() . '/tempmail_referrals_digest.state';
$until = time() - 60;
$since = $until - 86400;
if (is_file($stateFile)) {
    $saved = trim((string) @file_get_contents($stateFile));
    if (ctype_digit($saved) && (int) $saved > 0) {
        $since = (int) $saved;
    }
}
$since = max($since, $until - 7 * 86400);
if ($since >= $until) {
    echo "Nothing to do yet.\n";
    exit(0);
}

$digest = referralDigestCollect($pdo, $since, $until);
if ($digest === null) {
    logMessage('WARNING', 'Referral digest: could not read the referrals');
    echo "Could not read the referrals.\n";
    exit(0);
}

$mail = referralDigestBody($digest, $since, $until);
if ($mail === null) {
    echo "Nothing to report.\n";
    if (!$dryRun) {
        @file_put_contents($stateFile, (string) $until, LOCK_EX);
    }
    exit(0);
}
[$subject, $body] = $mail;

if ($dryRun) {
    echo "[dry-run] Subject: {$subject}\n\n{$body}";
    exit(0);
}

$to = $_ENV['ADMIN_NOTIFICATION_EMAIL'] ?? 'tony@manjo.me';
$from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
$headers = implode("\r\n", [
    'From: Mail Shield <' . $from . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
]);

$sent = false;
try {
    $sent = mail($to, $subject, $body, $headers, '-f' . $from) === true;
} catch (Throwable $e) {
    $sent = false;
}

$context = [
    'joined' => count($digest['joined']),
    'qualified' => count($digest['qualified']),
    'rewarded' => count($digest['rewarded']),
    'stuck' => count($digest['stuck_new']),
];
if (!$sent) {
    // The window is kept, so the next run reports the same rows again.
    logMessage('ERROR', 'Referral digest: mail could not be sent', $context);
    echo "The mail could not be sent.\n";
    exit(1);
}

if (@file_put_contents($stateFile, (string) $until, LOCK_EX) === false) {
    logMessage('WARNING', 'Referral digest: could not save the window, the next mail may repeat rows');
}
logMessage('INFO', 'Referral digest sent', $context);
echo "Digest sent: {$subject}\n";
exit(0);
