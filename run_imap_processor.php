<?php
// Ensure CLI invocation on production servers uses production env by default
if (php_sapi_name() === 'cli' && (getenv('DOCKER_ENV') === false || getenv('DOCKER_ENV') === '')) {
    putenv('DOCKER_ENV=production');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/php_imap_processor.php';

// Debug: show which environment was chosen and key env values (helps CLI detection)
echo "[DEBUG] detectEnvironment result: " . (isset($environment) ? $environment : '(unset)') . "\n";
echo "[DEBUG] getenv DOCKER_ENV=" . (getenv('DOCKER_ENV') ?: '(none)') . " APP_ENV=" . (getenv('APP_ENV') ?: '(none)') . "\n";
echo "[DEBUG] loaded env vars: DB_HOST=" . ($_ENV['DB_HOST'] ?? '(unset)') . " IMAP_SERVER=" . ($_ENV['IMAP_SERVER'] ?? '(unset)') . "\n\n";

// Make debug output visible in CLI
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Running IMAP processor (CLI) - verbose debug enabled\n";

// Print a snapshot of temp_emails (recent addresses)
try {
    echo "\n-- Temp Emails (recent) --\n";
    $stmt = $pdo->prepare("SELECT id, unique_address, created_at, pro_user_id, expires_at FROM temp_emails ORDER BY created_at DESC LIMIT 50");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "(no rows in temp_emails)\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("id=%s unique=%s pro_user_id=%s created_at=%s expires_at=%s\n", $r['id'], $r['unique_address'], $r['pro_user_id'] ?? 'NULL', $r['created_at'], $r['expires_at'] ?? 'NULL');
        }
    }
} catch (Exception $e) {
    echo "Could not query temp_emails: " . $e->getMessage() . "\n";
}

// Show IMAP mailbox summary and headers for debugging
try {
    echo "\n-- IMAP Mailbox Snapshot --\n";
    $imapServer = $config['imap']['server'] ?? '';
    echo "Connecting to IMAP: {$imapServer}\n";
    $imap = @imap_open($imapServer, $config['imap']['user'], $config['imap']['password']);
    if (!$imap) {
        echo "IMAP connect failed: " . imap_last_error() . "\n";
    } else {
        $count = imap_num_msg($imap);
        echo "Message count in mailbox: {$count}\n";
        $max = min(10, $count);
        for ($i = 1; $i <= $max; $i++) {
            $h = @imap_headerinfo($imap, $i);
            if (!$h) continue;
            $toList = [];
            if (isset($h->to)) {
                foreach ($h->to as $t) {
                    if (isset($t->mailbox) && isset($t->host)) $toList[] = strtolower($t->mailbox . '@' . $t->host);
                }
            }
            $subject = isset($h->subject) ? $h->subject : '(no subject)';
            echo "--- Message #{$i} --- Subject: {$subject}\n";
            echo "To: " . implode(', ', $toList) . "\n";
            $raw = @imap_fetchheader($imap, $i);
            $snippet = substr(preg_replace('/\s+/', ' ', $raw), 0, 800);
            echo "Raw headers (snippet): " . $snippet . "\n";
        }
        imap_close($imap);
    }
} catch (Exception $e) {
    echo "IMAP debug failed: " . $e->getMessage() . "\n";
}

try {
    $processor = new ImapProcessor($config, $pdo, true);
    // Run in dry-run mode so messages are not deleted during debugging
    $processor->setDryRun(true);
    $res = $processor->processEmails();
    echo "\nResult: " . var_export($res, true) . "\n";
} catch (Exception $e) {
    echo "Processor exception: " . $e->getMessage() . "\n";
}

echo "Done.\n";
