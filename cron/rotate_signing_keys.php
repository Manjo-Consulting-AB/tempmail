<?php
/**
 * Cron script to rotate client signing keys for pro users.
 * Usage: php rotate_signing_keys.php [--days=N] [--limit=N]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../client/backend/bootstrap.php';

$cronHttpSecret = $_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null);
$days = 30;
$limit = 0;
$force = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--days=') === 0) {
        $days = max(1, (int) substr($arg, 7));
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(0, (int) substr($arg, 8));
    }
    if ($arg === '--force') {
        $force = true;
    }
}

if (php_sapi_name() !== 'cli') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote !== '127.0.0.1') {
        $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['key'] ?? null);
        if (empty($cronHttpSecret) || empty($provided) || !function_exists('hash_equals') || !hash_equals((string) $cronHttpSecret, (string) $provided)) {
            http_response_code(403);
            die('Access denied - only CLI, localhost or authorized HTTP clients allowed');
        }
    }
}

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database unavailable\n");
    exit(1);
}

if (!clientBackendHasDbTable('pro_users')) {
    fwrite(STDOUT, "pro_users table not available\n");
    exit(0);
}

if (!clientBackendHasDbTable('client_scripts')) {
    fwrite(STDOUT, "client_scripts table not available - no client-agent adopters to rotate keys for\n");
    exit(0);
}

// Only rotate for pro_users who actually own a client-agent script - most
// pro_users have never installed the client agent, so scanning all of them
// generated a "KEK not configured" ERROR per user, every run.
$sql = 'SELECT DISTINCT pu.id FROM pro_users pu
        INNER JOIN client_scripts cs ON cs.owner_pro_user_id = pu.id
        WHERE pu.id IS NOT NULL';
if ($limit > 0) {
    $sql .= ' ORDER BY pu.id ASC LIMIT ' . (int) $limit;
}
$stmt = $pdo->query($sql);
$userIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
$rotated = 0;
$skipped = 0;
$failed = 0;

foreach ($userIds as $userIdValue) {
    $userId = (int) $userIdValue;
    try {
        $select = $pdo->prepare('SELECT agent_signing_updated_at FROM pro_users WHERE id = ? LIMIT 1');
        $select->execute([$userId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $failed++;
            continue;
        }

        $updatedAt = $row['agent_signing_updated_at'] ?? null;
        if (!$force && $updatedAt !== null && $updatedAt !== '' && strtotime((string) $updatedAt) !== false) {
            if ((string) $updatedAt > $cutoff) {
                $skipped++;
                continue;
            }
        }

        $keys = clientBackendRotateUserSigningKeys($userId);
        if (is_array($keys)) {
            $rotated++;
        } else {
            $failed++;
        }
    } catch (Throwable $e) {
        logMessage('ERROR', 'Signing key rotation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        $failed++;
    }
}

echo json_encode([
    'status' => 'ok',
    'days' => $days,
    'force' => $force,
    'rotated' => $rotated,
    'skipped' => $skipped,
    'failed' => $failed,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
