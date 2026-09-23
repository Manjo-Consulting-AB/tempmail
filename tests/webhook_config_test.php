<?php

declare(strict_types=1);

/**
 * Regression coverage for how a hook's config JSON shapes what is sent:
 * ImapProcessor::pushoverPostFields(), genericWebhookBody() and
 * webhookHeaders() - the helpers shared by the real delivery and the test
 * send on creation.
 *
 * Run with:  php tests/webhook_config_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Pure functions only: no
 * database, no network, no docroot.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../php_imap_processor.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** Runs webhookHeaders() and returns the exception message, or null when it accepted the config. */
function headersError(array $cfg): ?string
{
    try {
        ImapProcessor::webhookHeaders($cfg);
        return null;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Pushover fields
// ---------------------------------------------------------------------------

same('P1. defaults are the mail and the recipient',
    ['message' => 'MAIL', 'title' => 'to@x', 'token' => 't', 'user' => 'u'],
    ImapProcessor::pushoverPostFields(['token' => 't', 'user' => 'u'], 'MAIL', 'to@x'));

$fields = ImapProcessor::pushoverPostFields(
    ['token' => 't', 'user' => 'u', 'device' => 'Tony_iphone', 'priority' => 1, 'html' => true,
     'message' => 'Own text', 'title' => 'Own title', 'nested' => ['a' => 1]],
    'MAIL', 'to@x');
same('P2. device is passed on', 'Tony_iphone', $fields['device'] ?? null);
same('P3. numbers are passed on', 1, $fields['priority'] ?? null);
same('P4. booleans become 1/0', 1, $fields['html'] ?? null);
same('P5. the config may override message', 'Own text', $fields['message'] ?? null);
same('P6. the config may override title', 'Own title', $fields['title'] ?? null);
check('P7. nested values are dropped', !array_key_exists('nested', $fields));

// ---------------------------------------------------------------------------
// Generic body
// ---------------------------------------------------------------------------

$payload = ['to' => 'x@y', 'subject' => 'Orig', 'body' => 'b'];
same('G1. no config leaves the payload as is', $payload, ImapProcessor::genericWebhookBody([], $payload));

$body = ImapProcessor::genericWebhookBody(
    ['subject' => 'Override', 'channel' => '#mail', 'meta' => ['a' => 1], 'headers' => ['X-A' => '1']],
    $payload);
same('G2. a config key replaces ours', 'Override', $body['subject'] ?? null);
same('G3. new keys are added', '#mail', $body['channel'] ?? null);
same('G4. nested values are kept', ['a' => 1], $body['meta'] ?? null);
check('G5. the headers key never reaches the body', !array_key_exists('headers', $body));
same('G6. untouched keys stay', 'b', $body['body'] ?? null);

// ---------------------------------------------------------------------------
// Generic headers
// ---------------------------------------------------------------------------

same('H1. no config: only Content-Type',
    ['Content-Type: application/json'], ImapProcessor::webhookHeaders([]));

same('H2. custom headers follow Content-Type',
    ['Content-Type: application/json', 'Authorization: Bearer abc', 'X-Count: 5'],
    ImapProcessor::webhookHeaders(['headers' => ['Authorization' => 'Bearer abc', 'X-Count' => 5]]));

same('H3. a custom Content-Type replaces ours, case-insensitively',
    ['content-type: application/vnd.api+json'],
    ImapProcessor::webhookHeaders(['headers' => ['content-type' => 'application/vnd.api+json']]));

same('H4. the signature comes last',
    ['Content-Type: application/json', 'X-A: 1', 'X-TempMail-Signature: sha256=abc'],
    ImapProcessor::webhookHeaders(['headers' => ['X-A' => '1']], 'sha256=abc'));

same('H5. an empty headers object is fine', ['Content-Type: application/json'],
    ImapProcessor::webhookHeaders(['headers' => []]));

check('H6. CR/LF in a value is refused (header injection)',
    headersError(['headers' => ['X-A' => "1\r\nX-Evil: 1"]]) !== null);
check('H7. a bare LF in a value is refused', headersError(['headers' => ['X-A' => "1\nX"]]) !== null);
check('H8. a NUL in a value is refused', headersError(['headers' => ['X-A' => "1\0"]]) !== null);
check('H8b. a trailing LF is refused, not trimmed away', headersError(['headers' => ['X-A' => "1\n"]]) !== null);
check('H9. a colon or space in a name is refused', headersError(['headers' => ['X-A: b' => '1']]) !== null);
check('H10. an empty name is refused', headersError(['headers' => ['' => '1']]) !== null);

foreach (['Host', 'content-length', 'Transfer-Encoding', 'Connection', 'Expect', 'Upgrade',
          'Proxy-Authorization', 'Proxy-Anything', 'X-TempMail-Signature', 'x-tempmail-signature'] as $name) {
    check("H11. reserved header refused: {$name}", headersError(['headers' => [$name => 'v']]) !== null);
}

check('H12. headers as a list is refused', headersError(['headers' => ['Authorization: x']]) !== null);
check('H13. headers as a string is refused', headersError(['headers' => 'Authorization: x']) !== null);
check('H14. a nested value is refused', headersError(['headers' => ['X-A' => ['b']]]) !== null);
check('H15. a boolean value is refused', headersError(['headers' => ['X-A' => true]]) !== null);
check('H16. an over-long value is refused', headersError(['headers' => ['X-A' => str_repeat('a', 2049)]]) !== null);

$many = [];
for ($i = 0; $i < 21; $i++) $many['X-H' . $i] = 'v';
check('H17. more than 20 headers is refused', headersError(['headers' => $many]) !== null);
check('H18. a tab inside a value is allowed', headersError(['headers' => ['X-A' => "a\tb"]]) === null);

echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
