<?php

declare(strict_types=1);

/**
 * check_paddle_reschedule.php — sandbox-only CLI for the referral reward push
 * (epic #387, paddle_api.php): reads one subscription, computes next_billed_at
 * + N calendar months and, with --apply, moves it with
 * PATCH /subscriptions/{id}, then reads it again. It exists to show whether
 * current_billing_period.ends_at moves together with next_billed_at.
 *
 * Usage: php check_paddle_reschedule.php --subscription=sub_... [--months=3] [--apply]
 *
 * Without --apply it is a dry run (no write). Refuses to run unless the
 * effective Paddle environment is sandbox. Needs PADDLE_API_KEY with
 * "Subscriptions: read" and, for --apply, "Subscriptions: write". The key is
 * never printed.
 *
 * Exit codes: 0 done, 1 refused, bad usage or any error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/referrals.php';
require_once __DIR__ . '/paddle_api.php';

$opts = getopt('', ['subscription:', 'months::', 'apply']);
$subscriptionId = (string) ($opts['subscription'] ?? '');
$months = isset($opts['months']) ? (int) $opts['months'] : 3;
$apply = array_key_exists('apply', $opts);

$environment = strtolower(trim((string) ($config['paddle']['environment'] ?? '')));
if ($environment !== 'sandbox') {
    fwrite(STDERR, 'Refusing to run: PADDLE_ENVIRONMENT is "' . $environment . '", this script only runs against sandbox.' . "\n");
    exit(1);
}
if ($subscriptionId === '' || $months < 1) {
    fwrite(STDERR, "Usage: php check_paddle_reschedule.php --subscription=sub_... [--months=3] [--apply]\n");
    exit(1);
}

function reschedulePrint(string $title, array $sub): void
{
    $period = $sub['current_billing_period'] ?? [];
    echo $title . "\n";
    echo '  status:                              ' . ($sub['status'] ?? '-') . "\n";
    echo '  next_billed_at:                      ' . ($sub['next_billed_at'] ?? '-') . "\n";
    echo '  current_billing_period.starts_at:    ' . ($period['starts_at'] ?? '-') . "\n";
    echo '  current_billing_period.ends_at:      ' . ($period['ends_at'] ?? '-') . "\n";
    echo '  scheduled_change:                    ' . json_encode($sub['scheduled_change'] ?? null) . "\n";
}

try {
    $settings = paddleServerSettings($config);
    $before = paddleGetSubscription($settings, $subscriptionId);
    reschedulePrint('Before:', $before);

    if (empty($before['next_billed_at'])) {
        throw new RuntimeException('The subscription has no next_billed_at');
    }
    $targetLocal = referralAddMonths(paddleUtcToLocal((string) $before['next_billed_at']), $months);
    $targetUtc = paddleRfc3339ToUtc($targetLocal);
    echo "Target (+{$months} months):\n";
    echo "  local: {$targetLocal}\n";
    echo "  UTC:   {$targetUtc}\n";

    if (!$apply) {
        echo "Dry run, nothing written. Add --apply to PATCH.\n";
        exit(0);
    }

    $patched = paddleRescheduleSubscription($settings, $subscriptionId, $targetUtc);
    reschedulePrint('PATCH response:', $patched);

    $after = paddleGetSubscription($settings, $subscriptionId);
    reschedulePrint('After (GET again):', $after);

    $endBefore = $before['current_billing_period']['ends_at'] ?? null;
    $endAfter = $after['current_billing_period']['ends_at'] ?? null;
    $periodMoved = $endBefore !== null && $endAfter !== null && strtotime((string) $endAfter) !== strtotime((string) $endBefore);
    $matches = !empty($after['next_billed_at']) && strtotime((string) $after['next_billed_at']) === strtotime($targetUtc);

    echo 'RESULT: period_end_moved=' . ($periodMoved ? 'yes' : 'no')
        . ' next_billed_at_matches_target=' . ($matches ? 'yes' : 'no') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
