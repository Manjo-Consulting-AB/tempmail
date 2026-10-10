<?php
/**
 * Referral reward worker (epic #387). Intended to run from cron every 15
 * minutes; the crontab line is added by the operator. Usage:
 *
 *   php cron/referrals.php [--dry-run]
 *
 * --dry-run counts what a run would do and writes nothing and PATCHes
 * nothing (Paddle GET calls are still made).
 */
define('TEMPMAIL_APP', true);
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config.php';

cronRequireAccess($_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null));

require_once __DIR__ . '/../referrals.php';
require_once __DIR__ . '/../paddle_api.php';

$dryRun = PHP_SAPI === 'cli' && in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$lock = fopen(sys_get_temp_dir() . '/tempmail_referrals.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "already running\n";
    exit(0);
}

$paddle = [
    'get' => static function (string $subscriptionId): array {
        throw new RuntimeException('Paddle is not configured');
    },
    'patch' => static function (string $subscriptionId, string $nextBilledAt): array {
        throw new RuntimeException('Paddle is not configured');
    },
];
try {
    $paddleSettings = paddleServerSettings($config);
    $paddle = [
        'get' => static function (string $subscriptionId) use ($paddleSettings): array {
            return paddleGetSubscription($paddleSettings, $subscriptionId);
        },
        'patch' => static function (string $subscriptionId, string $nextBilledAt) use ($paddleSettings): array {
            return paddleRescheduleSubscription($paddleSettings, $subscriptionId, $nextBilledAt);
        },
    ];
} catch (Throwable $e) {
    // The phases that need no Paddle call still run; billing pushes count as failed attempts.
    if (!$dryRun) {
        logMessage('ERROR', 'Referral cron: Paddle is not configured', ['error' => $e->getMessage()]);
    }
}

$counts = referralRunRewards($pdo, referralSettings($config['referral'] ?? []), $paddle, time(), $dryRun);

$parts = [];
foreach ($counts as $name => $n) {
    $parts[] = "{$name}={$n}";
}
echo ($dryRun ? '[dry-run] ' : '') . implode(' ', $parts) . "\n";
if (!$dryRun && array_sum($counts) > 0) {
    logMessage('INFO', 'Referral cron run', $counts);
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
