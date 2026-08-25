<?php

declare(strict_types=1);

/**
 * parse.php — STDIN entrypoint for the DirectAdmin pipe-forwarder mail intake.
 *
 * This replaces IMAP polling (php_imap_processor.php / cron/run_imap_once.php)
 * for addresses whose DirectAdmin forwarder has been switched over: instead of
 * this app fetching mail from a catch-all inbox, DirectAdmin pipes each
 * incoming message directly to `php parse.php`, which reads the raw RFC822
 * message from php://stdin and saves it the same way ImapProcessor does today
 * (see php_imap_processor.php's saveEmail()/saveAttachmentsFromMessage()).
 * IMAP polling is kept running in parallel as a fallback until #35 verifies
 * this path end-to-end in production; DA_FORWARDER_ENABLED gates whether any
 * forwarder actually points here (see DirectAdminClient.php / config.php).
 *
 * Deriving the recipient: DirectAdmin's forwarder destination is configured
 * (config.php, $config['directadmin']['forwarder_destination']) as the static
 * string `|/usr/bin/php /home/s174280/domains/manjo.me/public_html/parse.php`
 * (path confirmed via SSH against the live server in #35) — no arguments
 * appended. Because the command line is static, the most likely way
 * the underlying MTA (Exim, on DirectAdmin/Inleed) exposes the envelope
 * recipient to a piped delivery is via environment variables it sets for pipe
 * transports (LOCAL_PART, DOMAIN, RECIPIENT, ...), not argv — but if a future
 * DA config change appends the alias as a literal argument instead, that
 * should still work here. This has NOT been confirmed against the live Exim
 * config on manjo.me (that kind of production verification belongs to #35,
 * which has SSH access as an explicit goal); until then this script tries
 * every plausible source, in priority order, and logs which one it used so a
 * real run's `system_logs` (or debug_logs/attachments_debug.log) entry can
 * confirm/correct this assumption:
 *   1. $argv[1]                        — first CLI argument, in case the pipe
 *                                         command is reconfigured to append it.
 *   2. LOCAL_PART (env var / $_SERVER) — Exim's conventional variable for the
 *                                         local part of a pipe-transport
 *                                         delivery's target address.
 *   3. RECIPIENT (env var / $_SERVER)  — Exim's full envelope-to address as a
 *                                         fallback; local part taken before
 *                                         the '@'.
 * Whatever is found is then validated with the same strict regex the rest of
 * the app uses for temp-address local parts (`^[a-f0-9]{8,16}$`, see
 * CLAUDE.md / index.php) before it's used in a DB lookup; anything that
 * doesn't validate is rejected rather than guessed at.
 *
 * Deployment note: DirectAdmin/Exim pipe-transport configurations can refuse
 * to invoke a non-executable pipe target even when the command line invokes
 * `php` explicitly, so this file must be `chmod 755` on the server. The
 * deploy workflow's rsync step runs with --no-perms (.github/workflows/
 * prod.yml), so that permission is NOT set automatically by deployment —
 * it must be set by hand over SSH after deploying (part of #35's rollout,
 * not automated here, per that issue's non-goals).
 */

// DirectAdmin/Exim's pipe transport is configured with return_output: ANY
// output on stdout/stderr — even with exit(0) — makes Exim treat the
// delivery as a permanent failure and bounce the message back to the
// sender, regardless of what this script actually did. Confirmed live in
// #35: the email was saved correctly, but config.php's error_log() calls
// (environment-detection banner, debug lines) go to stderr by default under
// the CLI SAPI when no error_log ini destination is set, and that alone
// triggered a bounce. Redirect PHP's error_log destination to a file before
// config.php runs so this pipe-delivery path stays completely silent.
ini_set('error_log', __DIR__ . '/debug_logs/parse_php_errors.log');

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/MailParser.php';

// This script only makes sense invoked as a subprocess of the mail pipe
// forwarder. Refuse to run over HTTP so an arbitrary POST body can't be fed
// in as forged "incoming mail" against any address of the attacker's choice.
if (php_sapi_name() === 'cli') {
    // continue
} else {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit(1);
}

/**
 * Log + exit(1): a permanent rejection (bad/unknown/expired recipient).
 * Non-zero so DirectAdmin/Exim can bounce the message if configured to.
 */
function parseReject(string $reason, array $context = []): void
{
    logMessage('WARNING', 'parse.php: ' . $reason, $context);
    fwrite(STDERR, $reason . "\n");
    exit(1);
}

/**
 * Log + exit(2): an internal failure (parsing/DB), distinct from a
 * permanent rejection so bounce behavior can be tuned differently later.
 */
function parseFail(string $reason, array $context = []): void
{
    logMessage('ERROR', 'parse.php: ' . $reason, $context);
    fwrite(STDERR, $reason . "\n");
    exit(2);
}

/** Read one env var, trying $_SERVER first (per the issue's example) then getenv(). */
function parseEnvOrServer(string $key): ?string
{
    if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
        return trim((string)$_SERVER[$key]);
    }
    $v = getenv($key);
    if ($v !== false && trim($v) !== '') {
        return trim($v);
    }
    return null;
}

// --- 1. Read the raw RFC822 message from stdin ------------------------------

$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') {
    parseReject('Empty or unreadable message on stdin');
}

// --- 2. Derive the recipient local part --------------------------------------

$localPart = null;
$source = null;

if (isset($argv[1]) && trim((string)$argv[1]) !== '') {
    $localPart = trim((string)$argv[1]);
    $source = 'argv[1]';
} elseif (($v = parseEnvOrServer('LOCAL_PART')) !== null) {
    $localPart = $v;
    $source = 'LOCAL_PART';
} elseif (($v = parseEnvOrServer('RECIPIENT')) !== null) {
    $localPart = strtolower(explode('@', $v)[0]);
    $source = 'RECIPIENT (local part)';
}

if ($localPart === null) {
    parseReject('Could not determine recipient local part from argv/env', [
        'argv' => $argv ?? null,
    ]);
}

$localPart = strtolower((string)$localPart);

// Same strict validation as the rest of the app (index.php, CLAUDE.md) before
// this value is used in a DB lookup.
if (!preg_match('/^[a-f0-9]{8,16}$/', $localPart)) {
    parseReject('Recipient local part failed validation', ['local_part' => $localPart, 'source' => $source]);
}

logMessage('DEBUG', 'parse.php: derived recipient local part', ['local_part' => $localPart, 'source' => $source]);

// --- 3. Look up the receiving temp_emails row --------------------------------

try {
    $stmt = $pdo->prepare('SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1');
    $stmt->execute([$localPart]);
    $tempEmail = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    parseFail('temp_emails lookup threw', ['error' => $e->getMessage(), 'local_part' => $localPart]);
    exit(2); // unreachable, keeps static analysis happy
}

if (!$tempEmail) {
    parseReject('No temp_emails row for recipient', ['local_part' => $localPart]);
}

if (strtotime((string)$tempEmail['expires_at']) < time()) {
    parseReject('Recipient address has expired', ['local_part' => $localPart, 'expires_at' => $tempEmail['expires_at']]);
}

$domain = $config['email']['domain'] ?? 'manjo.me';
$toAddress = $localPart . '@' . $domain;

// --- 4. Parse the MIME message (reuses MailParser, same as ImapProcessor) ---

try {
    $parser = new MailParser($pdo, $config, !empty($config['app']['debug_mode']));
    $parsed = $parser->parseRawMessage($raw);
} catch (Throwable $e) {
    parseFail('MailParser threw while parsing message', ['error' => $e->getMessage(), 'to' => $toAddress]);
    exit(2); // unreachable
}

$fromAddress = $parsed['from'] ?? '';
$subject = $parsed['subject'] !== null && $parsed['subject'] !== '' ? $parsed['subject'] : '(no subject)';
$bodyHtml = $parsed['body_html'] ?? null;
$bodyText = $parsed['body_text'] ?? null;
if ($bodyHtml === null && $bodyText === null) {
    $bodyText = '';
}

// Mirrors ImapProcessor::sanitizeSavedBody(): strip inline data: URIs before
// storage so embedded images/attachments (already saved separately, above)
// don't get duplicated as base64 bloat in the stored body.
$stripDataUris = static function (?string $body): ?string {
    if ($body === null || $body === '') {
        return $body;
    }
    return preg_replace('/data:[^;\"]+;base64,[A-Za-z0-9+\/=\r\n]+/i', '[attachment removed]', $body);
};
$bodyHtml = $stripDataUris($bodyHtml);
$bodyText = $stripDataUris($bodyText);

// --- 5. Save to stored_emails (+ email_attachments) --------------------------

$receivedAt = date('Y-m-d H:i:s');
$expiresAt = $tempEmail['expires_at'];
$proUserId = $tempEmail['pro_user_id'] ?? null;

// Pro users can have a longer retention window than the address's own
// expiry; same lookup ImapProcessor::saveEmail() does.
if (!empty($proUserId)) {
    try {
        $pstmt = $pdo->prepare('SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1');
        $pstmt->execute([(int)$proUserId]);
        $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
        if ($prow && isset($prow['ttl_days'])) {
            $ttl = max(1, min(365 * 50, (int)$prow['ttl_days']));
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttl} days", strtotime($receivedAt) ?: time()));
        }
    } catch (Throwable $e) {
        logMessage('WARNING', 'parse.php: pro_users TTL lookup failed', ['error' => $e->getMessage(), 'pro_user_id' => $proUserId]);
    }
}

try {
    $sql = 'INSERT INTO stored_emails (to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([$toAddress, $fromAddress, $subject, $bodyText, $bodyHtml, $receivedAt, $expiresAt, $tempEmail['id']]);
} catch (Throwable $e) {
    parseFail('stored_emails INSERT threw', ['error' => $e->getMessage(), 'to' => $toAddress]);
    exit(2); // unreachable
}

if (!$ok) {
    parseFail('stored_emails INSERT failed', ['pdo_error' => $stmt->errorInfo(), 'to' => $toAddress]);
}

$emailId = (int)$pdo->lastInsertId();
updateStat('emails_processed', 1);

$attachmentsSaved = 0;
if (!empty($parsed['attachments'])) {
    try {
        $result = $parser->saveAttachments($parsed['attachments'], $emailId);
        $attachmentsSaved = (int)($result['count'] ?? 0);
        if ($attachmentsSaved > 0) {
            updateStat('attachments_processed', $attachmentsSaved);
        }
    } catch (Throwable $e) {
        // Attachment failures don't invalidate the already-saved email.
        logMessage('WARNING', 'parse.php: saveAttachments failed', ['error' => $e->getMessage(), 'email_id' => $emailId]);
    }
}

// NOTE: Pro webhook dispatch (ImapProcessor::dispatchWebhooks()) is not
// ported to this path yet — out of scope for #34, which only covers saving
// to stored_emails/email_attachments. Not a live regression today since
// $config['directadmin']['forwarder_enabled'] defaults to off everywhere
// (see the DirectAdminClient.php entry in CLAUDE.md), but needs porting
// before this path fully replaces IMAP polling for Pro users.

logMessage('INFO', 'parse.php: saved incoming email', [
    'email_id' => $emailId,
    'to' => $toAddress,
    'attachments' => $attachmentsSaved,
]);

exit(0);
