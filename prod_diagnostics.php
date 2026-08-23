<?php
// Production diagnostics script — run via CLI: php src/prod_diagnostics.php
// This script prints environment detection, DB status, IMAP connectivity,
// attachments dir checks, vendor/autoload presence and runs a dry-run of the
// ImapProcessor. It is safe to run (processor runs in dry-run mode by default).

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "== TempMail Production Diagnostics ==\n";
echo "Run: php src/prod_diagnostics.php\n\n";

// Attempt to load config (this will perform environment detection)
echo "-- Loading config.php (will detect environment) --\n";
try {
    require_once __DIR__ . '/config.php';
    echo "[OK] config.php loaded.\n";
} catch (Throwable $e) {
    echo "[ERROR] config.php require failed: " . $e->getMessage() . "\n";
    exit(1);
}

$cwd = getcwd();
echo "Working dir: {$cwd}\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "Current user: " . get_current_user() . "\n\n";

// Show detected environment variable if available
echo "-- Environment --\n";
if (isset($environment)) {
    echo "detected environment variable: {$environment}\n";
} else {
    echo "detected environment: (not set)\n";
}
echo "getenv DOCKER_ENV='" . (getenv('DOCKER_ENV') ?: '') . "' APP_ENV='" . (getenv('APP_ENV') ?: '') . "'\n";
echo "Loaded env keys (selected): DB_HOST=" . ($_ENV['DB_HOST'] ?? '(unset)') . ", IMAP_SERVER=" . ($_ENV['IMAP_SERVER'] ?? '(unset)') . "\n\n";

// Config array summary
if (isset($config) && is_array($config)) {
    echo "-- Config Summary --\n";
    echo "DB host: " . ($config['db']['host'] ?? '(none)') . "\n";
    echo "DB name: " . ($config['db']['name'] ?? '(none)') . "\n";
    echo "IMAP server: " . ($config['imap']['server'] ?? '(none)') . "\n";
    echo "IMAP user: " . ($config['imap']['user'] ?? '(none)') . "\n\n";
}

// 1) Database checks
echo "-- Database checks --\n";
try {
    if (!isset($pdo) || !$pdo) {
        echo "[WARN] PDO not present after loading config.\n";
    } else {
        $ver = $pdo->query('SELECT VERSION() AS v')->fetchColumn();
        echo "MySQL version: " . ($ver ?: '(unknown)') . "\n";

        // Check presence of key tables using information_schema (parameterized safely)
        $tables = ['temp_emails','stored_emails','pro_webhooks','pro_webhook_deliveries','system_logs'];
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        foreach ($tables as $t) {
            try {
                $checkStmt->execute([$t]);
                $found = ((int)$checkStmt->fetchColumn()) > 0;
                echo sprintf("Table %-30s %s\n", $t, $found ? '[FOUND]' : '[MISSING]');
            } catch (Throwable $e) {
                echo sprintf("Table %-30s ERROR: %s\n", $t, $e->getMessage());
            }
        }

        // Row counts for critical tables (if present)
        try {
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM temp_emails')->fetchColumn();
            echo "temp_emails rows: {$cnt}\n";
        } catch (Throwable $e) { echo "temp_emails rows: ERROR ({$e->getMessage()})\n"; }
        try {
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM stored_emails')->fetchColumn();
            echo "stored_emails rows: {$cnt}\n";
        } catch (Throwable $e) { echo "stored_emails rows: ERROR ({$e->getMessage()})\n"; }
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM pro_webhook_deliveries WHERE status='pending'")->fetchColumn();
            echo "pending webhook deliveries: {$cnt}\n";
        } catch (Throwable $e) { echo "pending webhook deliveries: ERROR ({$e->getMessage()})\n"; }
    }
} catch (Throwable $e) {
    echo "[ERROR] Database checks failed: " . $e->getMessage() . "\n";
}

echo "\n-- IMAP check --\n";
// 2) IMAP connection check using config values
try {
    $imapServer = $config['imap']['server'] ?? ($_ENV['IMAP_SERVER'] ?? '');
    $imapUser = $config['imap']['user'] ?? ($_ENV['IMAP_USER'] ?? '');
    $imapPass = $config['imap']['password'] ?? ($_ENV['IMAP_PASSWORD'] ?? '');
    if (empty($imapServer) || empty($imapUser)) {
        echo "IMAP config incomplete: server or user missing.\n";
    } else {
        echo "Attempting imap_open to: {$imapServer} user={$imapUser}\n";
        $imap = @imap_open($imapServer, $imapUser, $imapPass);
        if ($imap) {
            echo "imap_open success. Message count: " . imap_num_msg($imap) . "\n";
            imap_close($imap);
        } else {
            echo "imap_open failed: " . imap_last_error() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "IMAP check exception: " . $e->getMessage() . "\n";
}

echo "\n-- Attachments dir check --\n";
$attachmentsDir = realpath(__DIR__ . '/attachments') ?: (__DIR__ . '/attachments');
echo "attachments path: {$attachmentsDir}\n";
if (is_dir($attachmentsDir)) {
    echo "exists: yes\n";
    echo "is_writable: " . (is_writable($attachmentsDir) ? 'yes' : 'no') . "\n";
} else {
    echo "exists: no (you can create it and set owner to PHP user)\n";
}

echo "\n-- Vendor / Autoload check --\n";
$vendorAutoload = realpath(__DIR__ . '/../vendor/autoload.php') ?: (__DIR__ . '/../vendor/autoload.php');
echo "expected vendor autoload: {$vendorAutoload}\n";
if (file_exists($vendorAutoload)) {
    echo "vendor autoload: present\n";
    try { require_once $vendorAutoload; echo "autoload require OK\n"; } catch (Throwable $e) { echo "autoload require error: " . $e->getMessage() . "\n"; }
} else {
    echo "vendor autoload: MISSING (run composer install or upload vendor/)\n";
}

echo "\n-- 2FA (TOTP) check --\n";
// Never print the key itself or any TOTP secret/code — only set/not-set and length.
try {
    $totpKey = $_ENV['TOTP_ENCRYPTION_KEY'] ?? null;
    if (empty($totpKey) || !is_string($totpKey)) {
        echo "TOTP_ENCRYPTION_KEY: NOT SET (2FA enrollment/verification will fail until this is set)\n";
    } else {
        $decoded = base64_decode($totpKey, true);
        $validLength = $decoded !== false && strlen($decoded) === 32;
        echo "TOTP_ENCRYPTION_KEY: set (base64, decodes to " . ($decoded === false ? 'invalid base64' : strlen($decoded) . ' bytes') . ")\n";
        echo "TOTP_ENCRYPTION_KEY length check: " . ($validLength ? '[OK] 32 bytes' : '[ERROR] expected 32 bytes after base64_decode') . "\n";
    }

    if (!isset($pdo) || !$pdo) {
        echo "2FA table checks: skipped (no PDO connection)\n";
    } else {
        $totpTables = ['pro_user_totp', 'pro_user_recovery_codes', 'two_factor_attempts', 'pro_trusted_devices'];
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        foreach ($totpTables as $t) {
            try {
                $checkStmt->execute([$t]);
                $found = ((int) $checkStmt->fetchColumn()) > 0;
                echo sprintf("Table %-30s %s\n", $t, $found ? '[FOUND]' : '[MISSING]');
            } catch (Throwable $e) {
                echo sprintf("Table %-30s ERROR: %s\n", $t, $e->getMessage());
            }
        }

        try {
            $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM pro_user_totp WHERE status = 'active'")->fetchColumn();
            echo "Accounts with 2FA active: {$activeCount}\n";
        } catch (Throwable $e) {
            echo "Accounts with 2FA active: ERROR ({$e->getMessage()})\n";
        }
    }
} catch (Throwable $e) {
    echo "2FA check exception: " . $e->getMessage() . "\n";
}

// 3) Dry-run processing test
echo "\n-- Dry-run IMAP processor test --\n";
try {
    require_once __DIR__ . '/php_imap_processor.php';
    $processor = new ImapProcessor($config, $pdo, true);
    $processor->setDryRun(true);
    $res = $processor->processEmails();
    echo "processEmails() returned:\n" . var_export($res, true) . "\n";
} catch (Throwable $e) {
    echo "Processor dry-run failed: " . $e->getMessage() . "\n";
}

echo "\n-- Diagnostics complete --\n";
echo "If anything above failed, paste the output back to the support chat. Mask any secrets.\n";

exit(0);
