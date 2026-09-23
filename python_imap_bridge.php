<?php

declare(strict_types=1);

/**
 * python_imap_bridge.php — the storage boundary for the Python IMAP fallback
 * (#194, epic #169).
 *
 * The fallback (python_imap_fallback.py) runs only where the PHP `imap`
 * extension is missing, and it keeps doing the IMAP work itself in Python. It
 * no longer opens a database connection of its own: it hands one normalized
 * email to this script as JSON on stdin, and this script builds the
 * IncomingEmail DTO and calls EmailStorage::store() — the same persistence
 * entrypoint the IMAP path goes through since #192 and the DirectAdmin pipe
 * since #193. Every `stored_emails` statement for this path therefore lives in
 * the service, once, instead of being duplicated in a second language.
 *
 * Duplicate detection is enabled (EmailStorage::OPTION_DETECT_DUPLICATES): that
 * is the Python fallback's own rule, unchanged — same sender, recipient and
 * subject with `received_at` within ±5 minutes (documentaion/EMAIL_STORAGE_API.md
 * §7.4). OPTION_REJECT_UNKNOWN_RECIPIENT stays OFF, because like the IMAP path
 * this path stores a message whose address lookup comes up empty and leaves
 * `temp_email_id` NULL. Attachments and webhooks are deliberately not part of
 * this path here: the fallback has never stored attachments, and whether this
 * path should dispatch webhooks is #196's decision.
 *
 * The caller is Python, not the mail system, so this is not an MTA pipe target
 * — but it is CLI-only for the same reason parse.php is: an HTTP request must
 * not be able to feed a forged "incoming mail" body into any address of the
 * attacker's choosing. It is also listed in robots.txt with the other private
 * entrypoints.
 *
 *   stdin  : one JSON object — `to_address` and `received_at` required;
 *            `from_address`, `subject`, `body_text`, `body_html` optional.
 *   stdout : exactly one JSON object —
 *            `{"status": ..., "stored_email_id": ..., "message": ...}` — where
 *            `status` is a StorageResult::STATUS_* value. Nothing else is ever
 *            written to stdout, because the caller parses it.
 *   exit   : 0 when the storage service produced a verdict (any status), 2 when
 *            the bridge itself could not produce one (unusable stdin/JSON or
 *            store() threw). The caller branches on `status`, never on this
 *            code.
 */

// The Python caller parses stdout as JSON, so PHP's own warnings must never
// reach it. error_log still works and goes to stderr, which the caller ignores.
ini_set('display_errors', '0');

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit(1);
}

require_once __DIR__ . '/EmailStorage/IncomingEmail.php';
require_once __DIR__ . '/EmailStorage/StorageResult.php';
require_once __DIR__ . '/EmailStorage/EmailStorage.php';

/** Write the one JSON object the caller expects, then stop. */
function bridgeRespond(string $status, ?int $storedEmailId, string $message, int $exitCode): never
{
    echo json_encode([
        'status' => $status,
        'stored_email_id' => $storedEmailId,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit($exitCode);
}

/**
 * The bridge itself could not produce a storage verdict: report it as `failed`
 * and exit 2, so the caller leaves the source message on the server. This is
 * deliberately not reached for a `rejected`/`duplicate` service verdict — those
 * are answers, not bridge errors, and they still exit 0.
 */
function bridgeFail(string $reason, array $context = []): never
{
    logMessage('ERROR', 'python_imap_bridge.php: ' . $reason, $context);
    bridgeRespond(StorageResult::STATUS_FAILED, null, $reason, 2);
}

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    bridgeFail('Empty message on stdin');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    bridgeFail('stdin is not a JSON object', ['json_error' => json_last_error_msg()]);
}

$toAddress = trim((string) ($data['to_address'] ?? ''));
if ($toAddress === '') {
    bridgeFail('Missing to_address');
}

// The fallback always supplies a formatted timestamp, so a missing or malformed
// one is a caller bug worth surfacing rather than silently replacing with now().
$receivedAtRaw = trim((string) ($data['received_at'] ?? ''));
if ($receivedAtRaw === '') {
    bridgeFail('Missing received_at', ['to_address' => $toAddress]);
}
try {
    $receivedAt = new DateTimeImmutable($receivedAtRaw);
} catch (Throwable $e) {
    bridgeFail('received_at is not a valid timestamp', ['to_address' => $toAddress, 'received_at' => $receivedAtRaw]);
}

/** An optional string field: absent or null stays null, the service normalizes. */
$optional = static function (array $data, string $key): ?string {
    return array_key_exists($key, $data) && $data[$key] !== null ? (string) $data[$key] : null;
};

try {
    $emailStorage = new EmailStorage($pdo, !empty($config['app']['debug_mode']));
    $result = $emailStorage->store(
        new IncomingEmail(
            toAddress: $toAddress,
            receivedAt: $receivedAt,
            fromAddress: $optional($data, 'from_address'),
            subject: $optional($data, 'subject'),
            bodyText: $optional($data, 'body_text'),
            bodyHtml: $optional($data, 'body_html')
        ),
        // The Python fallback's duplicate rule, and only that: the recipient
        // gate stays open, exactly as it is on the IMAP path (API doc §7.2).
        [EmailStorage::OPTION_DETECT_DUPLICATES => true]
    );
} catch (Throwable $e) {
    bridgeFail('EmailStorage::store() threw', ['to_address' => $toAddress, 'error' => $e->getMessage()]);
}

logMessage('DEBUG', 'python_imap_bridge.php: store result', [
    'status' => $result->status,
    'stored_email_id' => $result->storedEmailId,
    'to_address' => $toAddress,
    'message' => $result->message,
]);

bridgeRespond($result->status, $result->storedEmailId, (string) ($result->message ?? ''), 0);
