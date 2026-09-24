<?php
/**
 * Buy Me a Coffee Webhook Handler
 * 
 * Receives webhook events from Buy Me a Coffee and grants PRO access.
 * Verifies signature using HMAC-SHA256.
 *
 * Only the payment events in bmacGrantEventTypes() (bmac_logic.php) with a
 * positive amount, a paid status and - in production - live_mode=true grant
 * Pro; every other signed event is answered 200 {"status":"ignored"}.
 *
 * Webhook URL: https://manjo.me/bmac_handler.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro_trial.php';
require_once __DIR__ . '/bmac_logic.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get raw payload
$payload = file_get_contents('php://input');
if (empty($payload)) {
    logMessage('WARNING', 'BMAC webhook received empty payload');
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// Get signature from header
$signature = $_SERVER['HTTP_X_SIGNATURE_SHA256'] ?? '';
if (empty($signature)) {
    logMessage('WARNING', 'BMAC webhook missing signature header');
    http_response_code(401);
    echo json_encode(['error' => 'Missing signature']);
    exit;
}

// Get secret from environment
$secret = $config['bmac']['webhook_secret'] ?? getenv('BMAC_WEBHOOK_SECRET') ?: '';
if (empty($secret)) {
    logMessage('ERROR', 'BMAC webhook secret not configured');
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

// Verify HMAC-SHA256 signature
$expectedSignature = hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expectedSignature, $signature)) {
    logMessage('WARNING', 'BMAC webhook signature mismatch', [
        'received' => substr($signature, 0, 16) . '...',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Decode JSON payload
$data = json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage('WARNING', 'BMAC webhook invalid JSON', ['error' => json_last_error_msg()]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

if (!is_array($data)) {
    logMessage('WARNING', 'BMAC webhook payload is not a JSON object');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Read the event out of the envelope (fields nested under "data", per the
// Buy Me a Coffee webhook spec) or the legacy flat shape - see bmac_logic.php.
$event = bmacParseEvent($data, $payload);
$eventType = $event['type'];

logMessage('INFO', 'BMAC webhook received', [
    'type' => $eventType !== '' ? $eventType : 'unknown',
    'live_mode' => $event['live_mode'],
    'nested' => $event['nested'],
    'supporter_email' => $event['email'] !== '' ? $event['email'] : 'none'
]);

// Strict allowlist: only paid, live, positive-amount payment events grant Pro.
// Refunds, cancellations, pauses, updates, test events and unknown types are
// acknowledged with 200 (so BMAC does not retry them) and never grant.
$decision = bmacClassifyEvent($event, (string) ($environment ?? ''));
if ($decision['action'] === 'ignore') {
    logMessage('INFO', 'BMAC webhook ignored', [
        'type' => $eventType,
        'reason' => $decision['reason'],
        'bmac_id' => $event['bmac_id'],
        'amount' => $event['amount'],
        'currency' => $event['currency'],
        'live_mode' => $event['live_mode']
    ]);
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => $decision['reason']]);
    exit;
}
if ($decision['action'] !== 'grant') {
    logMessage('WARNING', 'BMAC webhook missing or invalid email', ['type' => $eventType, 'bmac_id' => $event['bmac_id']]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing email']);
    exit;
}

// Supporter email, already trimmed and lowercased by bmacNormalizeEmail().
$email = $event['email'];

// PRO duration to add (30 days)
$durationDays = 30;

// Replay protection: dedupe on the event type + payment/subscription id (falling
// back to a hash of the payload if the event has no id, see bmacParseEvent()) so
// neither a BMAC retry nor a captured valid (payload, signature) pair can be
// resent to repeatedly grant free PRO time.
$eventId = $event['dedupe_key'];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bmac_webhook_events (
        event_id VARCHAR(191) NOT NULL PRIMARY KEY,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    logMessage('ERROR', 'Failed to ensure bmac_webhook_events table exists', ['error' => $e->getMessage()]);
}

try {
    $pdo->beginTransaction();

    try {
        $dedupeStmt = $pdo->prepare("INSERT INTO bmac_webhook_events (event_id) VALUES (?)");
        $dedupeStmt->execute([$eventId]);
    } catch (PDOException $e) {
        // Duplicate primary key = this event was already processed. Roll back and
        // acknowledge with 200 so BMAC doesn't keep retrying it as a failure.
        $pdo->rollBack();
        logMessage('WARNING', 'BMAC webhook replay detected, ignoring', ['event_id' => $eventId]);
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'duplicate event']);
        exit;
    }

    // Lock or fetch user
    $stmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE LOWER(email) = ? FOR UPDATE");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $now = time();
    $userId = null;
    $created = false;
    $oldExpires = null;
    $newExpires = null;

    if ($user) {
        // Existing user - apply stacking logic
        $userId = $user['id'];
        $oldExpires = $user['pro_expires_at'];

        if (is_null($oldExpires)) {
            // User has lifetime PRO - no change needed, but we still log the purchase
            $newExpires = null;
            logMessage('INFO', 'BMAC purchase for lifetime PRO user', ['email' => $email, 'user_id' => $userId]);
        } elseif (strtotime($oldExpires) > $now) {
            // User has active PRO - stack 30 days on top of current expiration
            $newExpires = date('Y-m-d H:i:s', strtotime($oldExpires) + $durationDays * 86400);
        } else {
            // User has expired PRO - start fresh from now
            $newExpires = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
        }

        // Update expiration
        if (tableHasColumn('pro_users', 'account_type')) {
            $updateStmt = $pdo->prepare("UPDATE pro_users SET pro_expires_at = ?, account_type = 'pro' WHERE id = ?");
        } else {
            $updateStmt = $pdo->prepare("UPDATE pro_users SET pro_expires_at = ? WHERE id = ?");
        }
        $updateStmt->execute([$newExpires, $userId]);

    } else {
        // New user - create pro account
        // Disallow creating accounts using the service domain
        global $config;
        $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
        $parts = explode('@', $email);
        $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
        if ($domainPart === $forbiddenDomain) {
            // Do not create the account. Roll back so nothing - not even the
            // dedupe row - is kept (a retry reaches this same answer), and
            // acknowledge with 200 so BMAC does not keep retrying.
            $pdo->rollBack();
            logMessage('WARNING', 'Attempt to create PRO account using forbidden domain', ['email' => $email]);
            http_response_code(200);
            echo json_encode(['status' => 'ignored', 'reason' => 'forbidden domain']);
            exit;
        }

        $newExpires = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
        $defaultTtl = 1; // Default TTL for new addresses (1 day)

        if (tableHasColumn('pro_users', 'account_type')) {
            $insertStmt = $pdo->prepare("INSERT INTO pro_users (email, pro_expires_at, address_ttl_days, account_type, email_verified_at) VALUES (?, ?, ?, 'pro', NOW())");
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO pro_users (email, pro_expires_at, address_ttl_days) VALUES (?, ?, ?)");
        }
        $insertStmt->execute([$email, $newExpires, $defaultTtl]);
        $userId = $pdo->lastInsertId();
        $created = true;

        logMessage('INFO', 'BMAC created new PRO user', ['email' => $email, 'user_id' => $userId]);
    }

    // Log the purchase in system_logs
    logMessage('INFO', 'BMAC PRO granted', [
        'email' => $email,
        'user_id' => $userId,
        'duration_days' => $durationDays,
        'old_expires' => $oldExpires,
        'new_expires' => $newExpires,
        'type' => $eventType,
        'amount' => $event['amount'],
        'currency' => $event['currency'],
        'supporter_name' => $event['supporter_name'],
        'bmac_id' => $event['bmac_id']
    ]);

    $pdo->commit();

    if ($created) {
        // #267: a BMAC-created account is verified on creation - record the address.
        proTrialRecordClaim($pdo, $email, (string)($config['trial']['hash_key'] ?? ''));
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'status' => 'granted']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logMessage('ERROR', 'BMAC webhook processing failed', [
        'error' => $e->getMessage(),
        'email' => $email,
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}
