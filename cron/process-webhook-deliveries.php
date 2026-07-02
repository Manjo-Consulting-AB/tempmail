<?php
/**
 * Worker script to process queued webhook deliveries.
 * Intended to be run from cron every minute or via a long-running supervisor.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php_imap_processor.php';

$processor = new ImapProcessor($config, $pdo, false);

// Number of deliveries to process per run
$limit = 50;

try {
    // MySQL does not always accept a bound parameter for LIMIT when emulation is disabled,
    // so inject the integer limit directly after casting to int for safety.
    $limitInt = (int)$limit;
    $sql = "SELECT id FROM pro_webhook_deliveries WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY next_attempt_at ASC, created_at ASC LIMIT $limitInt";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = count($rows);
        echo "Found pending deliveries: {$count}\n";
        if ($count > 0) {
            echo "IDs: " . implode(',', $rows) . "\n";
        }

        $processed = 0;
        foreach ($rows as $id) {
            $id = (int)$id;
            try {
                $ok = $processor->dispatchDelivery($id);
                if ($ok) {
                    echo "Delivered id {$id} OK\n";
                } else {
                    echo "Delivery id {$id} NOT OK (see DB for details)\n";
                }
            } catch (Exception $e) {
                echo "dispatchDelivery exception for id {$id}: " . $e->getMessage() . "\n";
            }
            $processed++;
        }

        echo "Processed: {$processed}\n";
} catch (Exception $e) {
    logMessage('ERROR', 'Webhook worker error', ['error' => $e->getMessage()]);
    echo 'Error: ' . $e->getMessage() . "\n";
}

?>
