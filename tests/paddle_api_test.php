<?php

declare(strict_types=1);

/**
 * Regression coverage for the Paddle API calls in paddle_api.php (epic #387
 * step 5): GET and PATCH /subscriptions/{id}, the error handling and the two
 * local/UTC converters. A fake transport stands in for curl, so no network,
 * no database and no Paddle credentials are needed.
 *
 * Run with:  php tests/paddle_api_test.php
 *
 * Exits 0 when every check passes, 1 otherwise.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
date_default_timezone_set('Europe/Stockholm');   // as config.php
require dirname(__DIR__) . '/paddle_api.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  [pass] ' : '  [FAIL] ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
}

const TEST_KEY = 'pdl_sdbx_apikey_TESTKEYTESTKEYTESTKEY';
const SUB_ID = 'sub_01abcdefghijklmnopqrstuvwx';
$settings = ['api_key' => TEST_KEY, 'base_url' => 'https://sandbox-api.paddle.com'];

$calls = [];
$fake = function (int $status, array $json) use (&$calls): callable {
    return function (string $method, string $url, array $headers, ?string $body) use (&$calls, $status, $json): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return ['status' => $status, 'body' => json_encode($json)];
    };
};

echo "1. GET\n";
$sub = $fake(200, ['data' => ['id' => SUB_ID, 'status' => 'active', 'next_billed_at' => '2027-04-12T08:00:00Z']]);
$data = paddleGetSubscription($settings, SUB_ID, $sub);
check('one call', count($calls) === 1);
check('method GET', $calls[0]['method'] === 'GET');
check('url', $calls[0]['url'] === 'https://sandbox-api.paddle.com/subscriptions/' . SUB_ID);
check('bearer header', in_array('Authorization: Bearer ' . TEST_KEY, $calls[0]['headers'], true));
check('no body', $calls[0]['body'] === null);
check('returns data', ($data['status'] ?? '') === 'active');

echo "2. PATCH\n";
$calls = [];
$when = '2027-07-12T08:00:00.000000Z';
$data = paddleRescheduleSubscription($settings, SUB_ID, $when, $fake(200, ['data' => ['id' => SUB_ID, 'next_billed_at' => $when]]));
$sent = json_decode((string) $calls[0]['body'], true);
check('method PATCH', $calls[0]['method'] === 'PATCH');
check('url', $calls[0]['url'] === 'https://sandbox-api.paddle.com/subscriptions/' . SUB_ID);
check('exactly two body keys', is_array($sent) && array_keys($sent) === ['next_billed_at', 'proration_billing_mode']);
check('next_billed_at sent', ($sent['next_billed_at'] ?? '') === $when);
check('do_not_bill', ($sent['proration_billing_mode'] ?? '') === 'do_not_bill');
check('returns data', ($data['next_billed_at'] ?? '') === $when);
$data = paddleRescheduleSubscription($settings, SUB_ID, '2027-07-12T08:00:00Z', $fake(200, ['data' => ['id' => SUB_ID]]));
check('date without fraction accepted', ($data['id'] ?? '') === SUB_ID);

echo "3. Validation never reaches the transport\n";
$calls = [];
$never = $fake(200, ['data' => []]);
$cases = [
    'bad id (get)' => function () use ($settings, $never) { paddleGetSubscription($settings, 'sub_x', $never); },
    'bad id (patch)' => function () use ($settings, $never) { paddleRescheduleSubscription($settings, 'ctm_01abcdefghijklmnopqrstuvwx', '2027-07-12T08:00:00Z', $never); },
    'uppercase id' => function () use ($settings, $never) { paddleGetSubscription($settings, 'sub_01ABCDEFGHIJKLMNOPQRSTUVWX', $never); },
    'local date' => function () use ($settings, $never) { paddleRescheduleSubscription($settings, SUB_ID, '2027-07-12 10:00:00', $never); },
    'offset date' => function () use ($settings, $never) { paddleRescheduleSubscription($settings, SUB_ID, '2027-07-12T10:00:00+02:00', $never); },
];
foreach ($cases as $label => $run) {
    $thrown = null;
    try {
        $run();
    } catch (Throwable $e) {
        $thrown = $e;
    }
    check($label . ' throws InvalidArgumentException', $thrown instanceof InvalidArgumentException);
}
check('transport never called', count($calls) === 0);

echo "4. Errors\n";
$thrown = null;
try {
    paddleGetSubscription($settings, SUB_ID, $fake(404, ['error' => ['code' => 'not_found', 'detail' => 'nope']]));
} catch (Throwable $e) {
    $thrown = $e;
}
check('404 throws RuntimeException', $thrown instanceof RuntimeException && !($thrown instanceof InvalidArgumentException));
check('message has status and code', $thrown !== null && strpos($thrown->getMessage(), '404') !== false && strpos($thrown->getMessage(), 'not_found') !== false);
check('message has no key', $thrown !== null && strpos($thrown->getMessage(), TEST_KEY) === false);
$thrown = null;
try {
    paddleRescheduleSubscription($settings, SUB_ID, '2027-07-12T08:00:00Z', $fake(400, ['error' => ['code' => 'invalid_field']]));
} catch (Throwable $e) {
    $thrown = $e;
}
check('PATCH 400 carries status and code', $thrown instanceof RuntimeException && strpos($thrown->getMessage(), '400') !== false && strpos($thrown->getMessage(), 'invalid_field') !== false);
$thrown = null;
try {
    paddleGetSubscription($settings, SUB_ID, $fake(200, ['meta' => []]));
} catch (Throwable $e) {
    $thrown = $e;
}
check('2xx without data throws', $thrown instanceof RuntimeException && strpos($thrown->getMessage(), 'unknown') !== false);

echo "5. Converters\n";
check('summer local to UTC', paddleRfc3339ToUtc('2027-07-12 10:00:00') === '2027-07-12T08:00:00.000000Z');
check('summer round trip', paddleUtcToLocal(paddleRfc3339ToUtc('2027-07-12 10:00:00')) === '2027-07-12 10:00:00');
check('winter local to UTC', paddleRfc3339ToUtc('2027-01-14 10:00:00') === '2027-01-14T09:00:00.000000Z');
check('winter round trip', paddleUtcToLocal(paddleRfc3339ToUtc('2027-01-14 10:00:00')) === '2027-01-14 10:00:00');
check('agrees with paddleLocalTime()', paddleUtcToLocal('2027-07-12T08:00:00.000000Z') === paddleLocalTime('2027-07-12T08:00:00.000000Z'));
$thrown = null;
try {
    paddleUtcToLocal('not a date');
} catch (Throwable $e) {
    $thrown = $e;
}
check('bad timestamp throws', $thrown instanceof InvalidArgumentException);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
