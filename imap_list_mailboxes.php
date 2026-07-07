<?php
require_once __DIR__ . '/config.php';

// This is a diagnostic script that opens live IMAP connections with production
// credentials and prints mailbox names/counts - restrict the same way as
// cron/run_imap_once.php (CLI, localhost, or a shared secret over HTTP).
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $cronHttpSecret = $_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null);
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote !== '127.0.0.1') {
        $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['key'] ?? null);
        if (empty($cronHttpSecret) || empty($provided) || !hash_equals((string)$cronHttpSecret, (string)$provided)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Access denied\n";
            exit(1);
        }
    }
}

if (!extension_loaded('imap')) {
    echo "PHP IMAP extension not loaded\n";
    exit(1);
}

$imapCfg = $config['imap'] ?? null;
if (empty($imapCfg) || empty($imapCfg['server'])) {
    echo "IMAP config missing in config.php\n";
    exit(1);
}

echo "Connecting to IMAP server: {$imapCfg['server']} as {$imapCfg['user']}\n";
$conn = @imap_open($imapCfg['server'], $imapCfg['user'], $imapCfg['password']);
if (!$conn) {
    echo "imap_open failed: " . imap_last_error() . "\n";
    exit(1);
}

echo "Listing mailboxes:\n";
$mailboxes = imap_list($conn, preg_replace('/}\w+$/', '}', $imapCfg['server']), '*');
if ($mailboxes === false) {
    echo "imap_list failed: " . imap_last_error() . "\n";
    imap_close($conn);
    exit(1);
}

foreach ($mailboxes as $mb) {
    // Normalize mailbox name
    $short = preg_replace('/^\{.*\}/', '', $mb);
    $count = @imap_num_msg(@imap_open($imapCfg['server'], $imapCfg['user'], $imapCfg['password'], 0, 1, $mb));
    if ($count === false) $count = 0;
    echo sprintf(" - %s : %d messages\n", $short, $count);
}

imap_close($conn);
echo "Done.\n";
