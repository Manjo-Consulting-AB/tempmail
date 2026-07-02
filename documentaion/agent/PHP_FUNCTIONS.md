# PHP_FUNCTIONS.md - Kompletta Funktionerna

Detta dokument innehåller alla PHP-funktioner organiserade och redo att kopiera in i main-filen.

## Komplett mailfilter_*.php Script

Kopiera denna kod till `mailfilter_abc123xyz.php`:

```php
<?php
/**
 * Mailfilter - Autonomous Mail Management System
 * Script ID: {GENERATED_BY_MANJO_ME}
 * 
 * Auto-generated script. Manual changes will be lost.
 * Configuration stored in ~/.manjo/
 */

// ==============================================================================
// CONFIGURATION & CONSTANTS
// ==============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Determine execution mode
function get_execution_mode() {
    if (php_sapi_name() === 'cli') {
        return 'CLI';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
        return 'WEBHOOK';
    } else {
        return 'UNKNOWN';
    }
}

$execution_mode = get_execution_mode();
$script_filename = basename(__FILE__, '.php');
$script_id = str_replace('mailfilter_', '', $script_filename);

define('SCRIPT_ID', $script_id);
define('HOME_DIR', getenv('HOME') ?: '/root');
define('CONFIG_DIR', HOME_DIR . '/.manjo');
define('LOGS_DIR', CONFIG_DIR . '/logs');
define('CONFIG_FILE', CONFIG_DIR . '/mailfilter_' . SCRIPT_ID . '.json');
define('KEY_FILE', CONFIG_DIR . '/mailfilter_' . SCRIPT_ID . '.key');
define('EXECUTION_MODE', $execution_mode);

// Ensure directories exist
if (!is_dir(CONFIG_DIR)) {
    mkdir(CONFIG_DIR, 0700, true);
}
if (!is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0700, true);
}

// ==============================================================================
// LOGGING
// ==============================================================================

function get_log_file() {
    $current_month = date('Y-m');
    return LOGS_DIR . '/mailfilter_' . SCRIPT_ID . '_' . $current_month . '.log';
}

function log_event($message, $level = 'INFO') {
    static $settings = null;
    
    // Load settings once
    if ($settings === null) {
        try {
            $settings = load_settings();
        } catch (Exception $e) {
            // Settings not loaded yet, log anyway
            $settings = ['runtime' => ['logging_enabled' => true]];
        }
    }
    
    if (!isset($settings['runtime']['logging_enabled']) || 
        !$settings['runtime']['logging_enabled']) {
        return;
    }
    
    $timestamp = date('c');
    $log_entry = "[{$timestamp}] {$level}: {$message}";
    $log_file = get_log_file();
    
    file_put_contents($log_file, $log_entry . "\n", FILE_APPEND | LOCK_EX);
}

function log_info($message) {
    log_event($message, 'INFO');
}

function log_error($message) {
    log_event($message, 'ERROR');
}

function log_debug($message) {
    log_event($message, 'DEBUG');
}

// ==============================================================================
// SETTINGS MANAGEMENT
// ==============================================================================

function load_settings() {
    if (!file_exists(CONFIG_FILE)) {
        throw new Exception("Config file not found: " . CONFIG_FILE);
    }
    
    $json = file_get_contents(CONFIG_FILE);
    $settings = json_decode($json, true);
    
    if (!$settings) {
        throw new Exception("Invalid JSON in config file");
    }
    
    return $settings;
}

function save_settings($settings) {
    $json = json_encode(
        $settings,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    
    if (file_put_contents(CONFIG_FILE, $json) === false) {
        throw new Exception("Failed to write config file");
    }
    
    log_info("SETTINGS_SAVED: Config updated");
}

function get_imap_password() {
    $settings = load_settings();
    $password_data = $settings['imap']['password'];
    $is_encrypted = $settings['imap']['password_encrypted'];
    
    if (!$is_encrypted) {
        return $password_data;
    }
    
    // Encrypted password
    if (!file_exists(KEY_FILE)) {
        throw new Exception("Encryption key not found: " . KEY_FILE);
    }
    
    $secret_key = file_get_contents(KEY_FILE);
    $decoded = json_decode(base64_decode($password_data), true);
    
    if (!$decoded || !isset($decoded['cipher']) || !isset($decoded['iv'])) {
        throw new Exception("Invalid encrypted password format");
    }
    
    $cipher = $decoded['cipher'];
    $iv = base64_decode($decoded['iv']);
    
    $password = openssl_decrypt(
        $cipher,
        'aes-256-cbc',
        hash('sha256', $secret_key, true),
        0,
        $iv
    );
    
    if ($password === false) {
        throw new Exception("Failed to decrypt password");
    }
    
    return $password;
}

// ==============================================================================
// IMAP OPERATIONS
// ==============================================================================

function connect_imap() {
    $settings = load_settings();
    $email = $settings['imap']['user_email'];
    $host = $settings['imap']['host'];
    $port = $settings['imap']['port'];
    $mailbox = $settings['imap']['mailbox'];
    
    try {
        $password = get_imap_password();
    } catch (Exception $e) {
        throw new Exception("Failed to get password: " . $e->getMessage());
    }
    
    $imap_path = "{" . $host . ":" . $port . "/imap/ssl}" . $mailbox;
    
    log_info("IMAP_CONNECT: Connecting to {$host}:{$port}");
    
    $imap = @imap_open($imap_path, $email, $password);
    
    if (!$imap) {
        $error = imap_last_error();
        throw new Exception("IMAP connection failed: " . $error);
    }
    
    log_info("IMAP_CONNECT: OK");
    return $imap;
}

function fetch_unfoldered_messages($imap) {
    log_info("IMAP_FETCH: Searching for messages");
    
    $check = imap_mailboxmsginfo($imap);
    if (!$check) {
        throw new Exception("Failed to check mailbox");
    }
    
    $num_messages = $check->Nmsgs;
    log_info("IMAP_FETCH: Found {$num_messages} messages");
    
    $message_ids = imap_search($imap, 'ALL');
    
    if (!$message_ids) {
        log_info("IMAP_FETCH: No messages found");
        return [];
    }
    
    return $message_ids;
}

function get_message_details($imap, $msg_id) {
    $header = imap_headerinfo($imap, $msg_id);
    
    if (!$header) {
        log_error("IMAP_ERROR: Failed to get header for message {$msg_id}");
        return null;
    }
    
    $sender_email = '';
    if (isset($header->from[0]->mailbox) && isset($header->from[0]->host)) {
        $sender_email = $header->from[0]->mailbox . '@' . $header->from[0]->host;
    }
    
    $date = isset($header->date) ? strtotime($header->date) : time();
    
    return [
        'msg_id' => $msg_id,
        'sender_email' => strtolower($sender_email),
        'date' => $date,
        'subject' => $header->subject ?? '(no subject)'
    ];
}

function delete_message($imap, $msg_id) {
    imap_delete($imap, $msg_id);
}

function close_imap($imap) {
    imap_expunge($imap);
    imap_close($imap);
    log_info("IMAP_CLOSE: Connection closed");
}

// ==============================================================================
// FILTERING LOGIC
// ==============================================================================

function matches_pattern($sender_email, $pattern) {
    $sender_email = strtolower($sender_email);
    $pattern = strtolower($pattern);
    
    if (strpos($pattern, '@') === 0) {
        // Domain pattern: @example.com
        $domain = substr($sender_email, strrpos($sender_email, '@') + 1);
        $pattern_domain = substr($pattern, 1);
        return $domain === $pattern_domain;
    } elseif (strpos($pattern, '*') !== false) {
        // Wildcard pattern: manager@*.company.com
        $pattern = str_replace('*', '.*', preg_quote($pattern));
        return preg_match("/^{$pattern}$/", $sender_email);
    } else {
        // Exact match
        return $sender_email === $pattern;
    }
}

function check_whitelist($sender_email, $whitelist) {
    foreach ($whitelist as $pattern) {
        if (matches_pattern($sender_email, $pattern)) {
            return true;
        }
    }
    return false;
}

function check_blacklist($sender_email, $blacklist) {
    foreach ($blacklist as $pattern) {
        if (matches_pattern($sender_email, $pattern)) {
            return true;
        }
    }
    return false;
}

function should_delete_greylist($message_date, $greylist_days) {
    $age_seconds = time() - $message_date;
    $age_days = $age_seconds / (24 * 3600);
    return $age_days > $greylist_days;
}

function apply_filters($imap, $message, $settings) {
    $sender = $message['sender_email'];
    $whitelist = $settings['filters']['whitelist'];
    $blacklist = $settings['filters']['blacklist'];
    $greylist_days = $settings['greylist']['days'];
    
    // Check 1: Whitelist
    if (check_whitelist($sender, $whitelist)) {
        log_debug("FILTER_KEEP: Whitelisted - {$sender}");
        return 'KEEP';
    }
    
    // Check 2: Blacklist
    if (check_blacklist($sender, $blacklist)) {
        log_info("FILTER_DELETE: Blacklisted - {$sender}");
        delete_message($imap, $message['msg_id']);
        return 'DELETE';
    }
    
    // Check 3: Greylist
    if (should_delete_greylist($message['date'], $greylist_days)) {
        $age_days = floor((time() - $message['date']) / (24 * 3600));
        log_info("FILTER_DELETE: Greylist expired - {$sender} ({$age_days} days)");
        delete_message($imap, $message['msg_id']);
        return 'DELETE';
    }
    
    log_debug("FILTER_KEEP: Greylist (not yet expired) - {$sender}");
    return 'KEEP';
}

// ==============================================================================
// CRON MODE
// ==============================================================================

function run_cron() {
    log_info("CRON_RUN: Starting");
    
    try {
        $settings = load_settings();
        $imap = connect_imap();
        
        $message_ids = fetch_unfoldered_messages($imap);
        
        $kept = 0;
        $deleted = 0;
        
        foreach ($message_ids as $msg_id) {
            $message = get_message_details($imap, $msg_id);
            
            if (!$message) {
                continue;
            }
            
            $result = apply_filters($imap, $message, $settings);
            
            if ($result === 'DELETE') {
                $deleted++;
            } else {
                $kept++;
            }
        }
        
        close_imap($imap);
        
        // Update last_run
        $settings['runtime']['last_run'] = date('c');
        save_settings($settings);
        
        log_info("CRON_COMPLETE: Kept={$kept}, Deleted={$deleted}");
        exit(0);
        
    } catch (Exception $e) {
        log_error("CRON_FAILED: " . $e->getMessage());
        exit(1);
    }
}

// ==============================================================================
// WEBHOOK MODE - SIGNATURE VERIFICATION
// ==============================================================================

function verify_webhook_signature(&$payload) {
    $settings = load_settings();
    
    $received_signature = base64_decode($payload['signature']);
    unset($payload['signature']);
    
    $json_payload = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS
    );
    
    $public_key = $settings['security']['public_key'];
    
    $verified = openssl_verify(
        $json_payload,
        $received_signature,
        $public_key,
        'sha256WithRSAEncryption'
    );
    
    if ($verified !== 1) {
        http_response_code(401);
        log_error("WEBHOOK_SECURITY: Signature verification failed");
        throw new Exception("Signature verification failed");
    }
    
    log_info("WEBHOOK_SECURITY: Signature OK");
}

// ==============================================================================
// WEBHOOK MODE - TIMESTAMP VALIDATION
// ==============================================================================

function validate_webhook_timestamp($payload) {
    $settings = load_settings();
    
    $received_timestamp_str = $payload['timestamp'];
    $last_webhook_timestamp_str = $settings['runtime']['last_webhook_timestamp'] ?? '1970-01-01T00:00:00Z';
    
    $received_ts = strtotime($received_timestamp_str);
    $last_ts = strtotime($last_webhook_timestamp_str);
    $now = time();
    
    if ($received_ts === false) {
        http_response_code(400);
        throw new Exception("Invalid timestamp format");
    }
    
    // Check 1: Not older than 1 hour
    if ($now - $received_ts > 3600) {
        http_response_code(400);
        log_error("WEBHOOK_REPLAY: Timestamp too old");
        throw new Exception("Timestamp too old (> 1 hour)");
    }
    
    // Check 2: Not in the future
    if ($received_ts - $now > 300) {
        http_response_code(400);
        log_error("WEBHOOK_REPLAY: Timestamp in future");
        throw new Exception("Timestamp is in the future");
    }
    
    // Check 3: Not <= last webhook timestamp
    if ($received_ts <= $last_ts) {
        http_response_code(400);
        log_error("WEBHOOK_REPLAY: Same or older timestamp");
        throw new Exception("Replay attack detected");
    }
    
    log_info("WEBHOOK_VALIDATE: Timestamp OK");
}

// ==============================================================================
// WEBHOOK MODE - HANDLERS
// ==============================================================================

function handle_update_lists($payload) {
    // Validate required fields
    $required_fields = ['api_version', 'script_id', 'timestamp', 'action', 'data', 'signature'];
    foreach ($required_fields as $field) {
        if (empty($payload[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    // Check script_id
    if ($payload['script_id'] !== SCRIPT_ID) {
        http_response_code(403);
        throw new Exception("script_id mismatch");
    }
    
    log_info("WEBHOOK_VALIDATE: Checking signature");
    verify_webhook_signature($payload);
    
    log_info("WEBHOOK_VALIDATE: Checking timestamp");
    validate_webhook_timestamp($payload);
    
    log_info("WEBHOOK_UPDATE: Applying new lists");
    
    $settings = load_settings();
    
    $settings['filters']['whitelist'] = $payload['data']['whitelist'] ?? [];
    $settings['filters']['blacklist'] = $payload['data']['blacklist'] ?? [];
    $settings['greylist']['days'] = $payload['data']['greylist_days'] ?? 30;
    $settings['runtime']['last_webhook_timestamp'] = $payload['timestamp'];
    
    save_settings($settings);
    
    log_info("WEBHOOK_COMPLETE: Lists updated");
    
    http_response_code(200);
    echo json_encode([
        "status" => "ok",
        "message" => "Lists updated successfully",
        "details" => [
            "whitelist_count" => count($payload['data']['whitelist'] ?? []),
            "blacklist_count" => count($payload['data']['blacklist'] ?? []),
            "greylist_days" => $payload['data']['greylist_days'] ?? 30,
            "timestamp" => $payload['timestamp']
        ]
    ]);
}

function handle_webhook() {
    log_info("WEBHOOK_RECEIVED: " . ($_GET['action'] ?? 'unknown'));
    
    try {
        $raw_input = file_get_contents('php://input');
        $payload = json_decode($raw_input, true);
        
        if (!$payload) {
            throw new Exception("Invalid JSON");
        }
        
        $action = $_GET['action'] ?? null;
        
        if ($action === 'update_lists') {
            handle_update_lists($payload);
        } else {
            throw new Exception("Unknown action: {$action}");
        }
        
    } catch (Exception $e) {
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        log_error("WEBHOOK_ERROR: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
        exit(1);
    }
}

// ==============================================================================
// MAIN ENTRYPOINT
// ==============================================================================

try {
    if (EXECUTION_MODE === 'CLI') {
        run_cron();
    } elseif (EXECUTION_MODE === 'WEBHOOK') {
        handle_webhook();
    } else {
        die("Invalid execution mode\n");
    }
} catch (Exception $e) {
    log_error("FATAL_ERROR: " . $e->getMessage());
    if (EXECUTION_MODE === 'WEBHOOK') {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit(1);
}
?>
```

---

## Användarens Installation

1. **Ladda ned scriptfilen**: `mailfilter_abc123xyz.php`
2. **Ladda upp till**: `/home/user/public_html/mailfilter_abc123xyz.php`
3. **Sätt upp cron**: `*/30 * * * * php /home/user/public_html/mailfilter_abc123xyz.php`

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-29
