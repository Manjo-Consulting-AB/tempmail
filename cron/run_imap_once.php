<?php
// CLI wrapper to run the IMAP processor once (for cron). Runs in non-dry-run mode.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php_imap_processor.php';

// Determine runtime context and authorization
$isCli = php_sapi_name() === 'cli';
$cronHttpSecret = $_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null);

if (!$isCli) {
    // If called via HTTP, require secret header or ?key= parameter (keeps localhost allowed)
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote === '127.0.0.1') {
        // allow
    } else {
        $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['key'] ?? null);
        if (empty($cronHttpSecret) || empty($provided) || !function_exists('hash_equals') || !hash_equals((string)$cronHttpSecret, (string)$provided)) {
            // unauthorized via HTTP
            // respond with 403 and a brief message (do not leak secrets)
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Access denied\n";
            exit(1);
        }
    }
}

$debug = getenv('IMAP_DEBUG') === '1' || (isset($argv) && in_array('--debug', $argv, true));
$processor = new ImapProcessor($config, $pdo, $debug);
// Ensure we run for real in cron context
$processor->setDryRun(false);

// Run and print JSON summary
try {
    $res = $processor->processEmails();
    // If HTTP, return JSON with proper header
    if (!$isCli) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($isCli) {
        $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($stderr, "IMAP processor failed: " . $e->getMessage() . "\n");
        if (!defined('STDERR')) fclose($stderr);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "IMAP processor failed\n";
    }
    exit(2);
}
