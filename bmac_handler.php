<?php
/**
 * Buy Me a Coffee Webhook Handler
 * 
 * Receives webhook events from Buy Me a Coffee and grants PRO access.
 * Verifies signature using HMAC-SHA256.
 * 
 * Webhook URL: https://manjo.me/bmac_handler.php
 */

require_once __DIR__ . '/config.php';

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

// Log incoming webhook for debugging
logMessage('INFO', 'BMAC webhook received', [
    'type' => $data['type'] ?? 'unknown',
    'supporter_email' => $data['supporter_email'] ?? 'none'
]);

// Only process successful payment events
// Buy Me a Coffee sends events like: payment.completed, membership.started, etc.
$eventType = $data['type'] ?? '';
$validEvents = ['payment.completed', 'one_time_support', 'membership.started'];

if (!in_array($eventType, $validEvents) && !isset($data['supporter_email'])) {
    // Some webhook formats just have supporter_email without explicit type
    // Accept if we have required fields
    if (empty($data['supporter_email'])) {
        logMessage('INFO', 'BMAC webhook ignored - unsupported event type', ['type' => $eventType]);
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'unsupported event type']);
        exit;
    }
}

// Extract supporter email
$email = trim($data['supporter_email'] ?? $data['email'] ?? '');
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    logMessage('WARNING', 'BMAC webhook missing or invalid email', ['data' => $data]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing email']);
    exit;
}

// PRO duration to add (30 days)
$durationDays = 30;

try {
    $pdo->beginTransaction();

    // Lock or fetch user
    $stmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE email = ? FOR UPDATE");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $now = time();
    $userId = null;
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
        $updateStmt = $pdo->prepare("UPDATE pro_users SET pro_expires_at = ? WHERE id = ?");
        $updateStmt->execute([$newExpires, $userId]);

    } else {
        // New user - create pro account
        // Disallow creating accounts using the service domain
        global $config;
        $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
        $parts = explode('@', $email);
        $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
        if ($domainPart === $forbiddenDomain) {
            logMessage('WARNING', 'Attempt to create PRO account using forbidden domain', ['email' => $email]);
            // Do not create account; return early
            return;
        }

        $newExpires = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
        $defaultTtl = 1; // Default TTL for new addresses (1 day)

        $insertStmt = $pdo->prepare("INSERT INTO pro_users (email, pro_expires_at, address_ttl_days) VALUES (?, ?, ?)");
        $insertStmt->execute([$email, $newExpires, $defaultTtl]);
        $userId = $pdo->lastInsertId();

        logMessage('INFO', 'BMAC created new PRO user', ['email' => $email, 'user_id' => $userId]);
    }

    // Log the purchase in system_logs
    logMessage('INFO', 'BMAC PRO granted', [
        'email' => $email,
        'user_id' => $userId,
        'duration_days' => $durationDays,
        'old_expires' => $oldExpires,
        'new_expires' => $newExpires,
        'amount' => $data['amount'] ?? $data['total_amount'] ?? null,
        'currency' => $data['currency'] ?? null,
        'supporter_name' => $data['supporter_name'] ?? $data['name'] ?? null,
        'bmac_id' => $data['id'] ?? $data['payment_id'] ?? null
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'email' => $email,
        'pro_expires_at' => $newExpires,
        'duration_added' => $durationDays
    ]);

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
