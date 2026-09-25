<?php
/**
 * Paddle Billing webhook — mirrors payments into pro_users.
 *
 * Webhook URL: <BASE_URL>paddle_webhook.php, registered as a notification
 * destination in Paddle (Developer tools > Notifications) for:
 *   subscription.created, subscription.updated, subscription.canceled,
 *   subscription.past_due, subscription.paused, subscription.resumed,
 *   subscription.activated, transaction.completed,
 *   customer.created, customer.updated
 *
 * All the logic is in paddle_sync.php; this file is the HTTP contract:
 *
 *   - 2xx is the only answer Paddle treats as delivered; anything else is
 *     retried (sandbox ~15 min, live ~3 days), so every failure — missing
 *     secret, bad signature, database error — answers non-2xx and nothing is
 *     lost. A bad signature cannot be told apart from a rotated secret that
 *     has not been deployed yet, which is why that is retryable too (401).
 *   - The signature is checked over the raw body before anything is parsed.
 *   - Handling is a few indexed queries, well inside Paddle's 5-second limit.
 *
 * Requires migrate_paddle_billing.php to have run (the paddle_* tables).
 */

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paddle_sync.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$secret = trim((string) ($config['paddle']['webhook_secret'] ?? ''));
if ($secret === '') {
    logMessage('ERROR', 'Paddle webhook secret not configured (PADDLE_WEBHOOK_SECRET)');
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

$payload = file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '');

if (!is_string($payload) || $payload === '' || $signature === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing signature or body']);
    exit;
}

if (!paddleVerifySignature($payload, $signature, $secret)) {
    logMessage('WARNING', 'Paddle webhook signature rejected', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

try {
    $outcome = paddleHandleEvent($pdo, $event, [
        'prices' => paddlePlanPriceIds(require __DIR__ . '/pricing_tiers.php'),
        'has_account_type' => tableHasColumn('pro_users', 'account_type'),
        'customer_email_pii' => piiEmailColumnsExist('paddle_customers'),
        'log' => function (string $level, string $message, array $context): void {
            logMessage($level, $message, $context);
        },
    ]);

    logMessage('INFO', 'Paddle webhook processed', [
        'event_id' => $event['event_id'] ?? null,
        'event_type' => $event['event_type'] ?? null,
        'outcome' => $outcome,
    ]);
    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    logMessage('ERROR', 'Paddle webhook processing failed', [
        'event_id' => $event['event_id'] ?? null,
        'event_type' => $event['event_type'] ?? null,
        'error' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}
