<?php
/**
 * Small utility to requeue a webhook delivery by id.
 * Usage: php requeue-webhook-delivery.php <delivery_id>
 *
 * Security: this script should be run locally or by an admin; it does not expose network endpoints.
 */

require_once __DIR__ . '/../config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php requeue-webhook-delivery.php <delivery_id>\n");
    exit(2);
}

$id = (int)$argv[1];
if ($id <= 0) {
    fwrite(STDERR, "Invalid delivery id\n");
    exit(2);
}

try {
    $stmt = $pdo->prepare("SELECT id, status, attempts, next_attempt_at, webhook_id FROM pro_webhook_deliveries WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "Delivery id {$id} not found\n");
        exit(3);
    }

    // Update to pending and reset next attempt
    $u = $pdo->prepare("UPDATE pro_webhook_deliveries SET status = 'pending', next_attempt_at = NOW(), attempts = 0, last_error = NULL, updated_at = NOW() WHERE id = ?");
    $u->execute([$id]);

    echo "Requeued delivery id {$id} (webhook_id={$row['webhook_id']}, previous_status={$row['status']}, attempts={$row['attempts']})\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error requeuing delivery id {$id}: " . $e->getMessage() . "\n");
    exit(1);
}
