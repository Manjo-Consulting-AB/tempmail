<?php

declare(strict_types=1);

/**
 * parse.php — STDIN entrypoint for the DirectAdmin pipe-forwarder mail intake.
 *
 * This replaces IMAP polling (php_imap_processor.php / cron/run_imap_once.php)
 * for addresses whose DirectAdmin forwarder has been switched over: instead of
 * this app fetching mail from a catch-all inbox, DirectAdmin pipes each
 * incoming message directly to `php parse.php`, which reads the raw RFC822
 * message from php://stdin, parses it with MailParser and hands it to the Email
 * Storage service (EmailStorage::store(), epic #169) — the same persistence
 * entrypoint the IMAP path goes through since #192. Intake stays here: reading
 * stdin, deriving and validating the recipient, the MIME parse and the body
 * sanitizing. IMAP polling is kept running in parallel as a fallback until #35
 * verifies this path end-to-end in production; DA_FORWARDER_ENABLED gates
 * whether any forwarder actually points here (see DirectAdminClient.php /
 * config.php).
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

// MailParser::parseRawMessage() needs ZBateson\MailMimeParser, which only
// exists via Composer's autoloader. Nothing else in this script's require
// chain loads it — the IMAP path (php_imap_processor.php) never needs it for
// headers/body because it parses those itself via ext/imap, only relying on
// MailParser for attachments. Confirmed live in #35: without this require,
// class_exists() inside parseRawMessage() silently returns the empty-stub
// result (from/subject/body_text/body_html all null), so the pipe delivered
// successfully but every field except to_address/received_at was blank.
// Same pattern already used by pro_feed.php / TwoFactorAuth.php.
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

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

// The strict ^[a-f0-9]{8,16}$ regex used elsewhere (index.php, CLAUDE.md) only
// matches auto-generated addresses (generateUniqueString()) — it rejects real
// personal aliases like "tony" or "crew-1", which are validated on creation
// with the broader sanitizeLocalPart() charset instead (index.php's
// create_personal). Found live in #35: a real inbound email to a personal
// alias was rejected here even though the address exists. The temp_emails
// lookup right after this is the actual authority on whether the alias is
// valid, so widen this to the same charset the rest of the app already
// accepts rather than the narrower auto-generated-only pattern.
if (sanitizeLocalPart($localPart, 1, 64) === null) {
    parseReject('Recipient local part failed validation', ['local_part' => $localPart, 'source' => $source]);
}

logMessage('DEBUG', 'parse.php: derived recipient local part', ['local_part' => $localPart, 'source' => $source]);

// --- 3. Look up the receiving temp_emails row --------------------------------
//
// This lookup is the authority on whether the recipient is deliverable at all:
// a missing or expired row is a permanent rejection here, *before* the message
// is parsed, which is the behavior this path has always had. The ownership
// context it resolves also travels in the DTO below as the service's fallback
// (Email Storage API §7.3).

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
    $parser = new MailParser($config, !empty($config['app']['debug_mode']));
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
// storage so embedded images/attachments (persisted separately by the service,
// below) don't get duplicated as base64 bloat in the stored body.
$stripDataUris = static function (?string $body): ?string {
    if ($body === null || $body === '') {
        return $body;
    }
    return preg_replace('/data:[^;\"]+;base64,[A-Za-z0-9+\/=\r\n]+/i', '[attachment removed]', $body);
};
$bodyHtml = $stripDataUris($bodyHtml);
$bodyText = $stripDataUris($bodyText);

// --- 5. Store through the Email Storage service ------------------------------
//
// The service (epic #169, #191) now owns everything that turns this message
// into rows: the ownership lookup, the Pro retention calculation, the
// transactional stored_emails insert, attachment persistence and the
// emails_processed / attachments_processed counters. What stays here is intake
// — the stdin read, the recipient derivation and validation, the MIME parse and
// the body sanitizing above. Pro webhooks are downstream of storage and run
// from inside the service, through the consumer registered below (#196).

require_once __DIR__ . '/EmailStorage/IncomingEmail.php';
require_once __DIR__ . '/EmailStorage/StorageResult.php';
require_once __DIR__ . '/EmailStorage/EmailStorage.php';

// `received_at` on this path is the pipe's own clock, not the message's Date:
// header — unchanged from before the migration (architecture doc §2.2).
$receivedAt = new DateTimeImmutable();

// Ownership context resolved by step 3, passed to the DTO as the service's
// fallback. $tempEmail['id'] is an int already: config.php opens PDO with
// ATTR_EMULATE_PREPARES => false, so native types come back from the fetch.
$tempEmailId = (int)$tempEmail['id'];
$proUserId = !empty($tempEmail['pro_user_id']) ? (int)$tempEmail['pro_user_id'] : null;
$addressExpiresAt = new DateTimeImmutable((string)$tempEmail['expires_at']);

// MailParser's decoded attachments travel in the DTO; the service persists
// them with the email and reports a per-attachment failure as a warning rather
// than failing the store.
$attachments = [];
foreach ($parsed['attachments'] as $attachment) {
    if (is_array($attachment)) {
        $attachments[] = EmailAttachment::fromArray($attachment);
    }
}

$emailStorage = new EmailStorage($pdo, !empty($config['app']['debug_mode']));

// Post-storage processing (#196): the Pro webhook consumer is registered on the
// service, so it runs from inside store() once the email is committed, instead
// of being dispatched by this script afterwards.
require_once __DIR__ . '/EmailStorage/PostStorageWebhooks.php';
PostStorageWebhooks::attach($emailStorage, $config, $pdo, !empty($config['app']['debug_mode']));

try {
    $result = $emailStorage->store(
        new IncomingEmail(
            toAddress: $toAddress,
            receivedAt: $receivedAt,
            fromAddress: $fromAddress,
            subject: $subject,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            tempEmailId: $tempEmailId,
            proUserId: $proUserId,
            expiresAt: $addressExpiresAt,
            attachments: $attachments
        ),
        // The pipe's permanent-bounce behavior: the service refuses (and we
        // exit 1) unless the recipient still resolves to a live, unexpired
        // address row. Step 3 already enforced this; passing it here keeps the
        // two verdicts in agreement if the row disappears in between.
        [EmailStorage::OPTION_REJECT_UNKNOWN_RECIPIENT => true]
    );
} catch (Throwable $e) {
    parseFail('EmailStorage::store() threw', ['error' => $e->getMessage(), 'to' => $toAddress]);
    exit(2); // unreachable, keeps static analysis happy
}

logMessage('DEBUG', 'parse.php: EmailStorage store result', [
    'status' => $result->status,
    'message' => $result->message,
    'stored_email_id' => $result->storedEmailId,
    'to' => $toAddress,
]);

switch ($result->status) {
    case StorageResult::STATUS_STORED:
        break;

    case StorageResult::STATUS_REJECTED:
        parseReject('Email Storage rejected the message: ' . ($result->message ?? 'unspecified'), ['to' => $toAddress]);
        break; // unreachable

    case StorageResult::STATUS_DUPLICATE:
        // This path passes no duplicate detection (#191 §7.4), so the service
        // cannot return this today. Nothing was written and nothing failed, so
        // it is not a bounce: log it and exit 0.
        logMessage('WARNING', 'parse.php: Email Storage reported a duplicate, nothing was written', ['to' => $toAddress]);
        exit(0);

    case StorageResult::STATUS_FAILED:
    default:
        parseFail('Email Storage could not store the message: ' . ($result->message ?? 'unspecified'), ['to' => $toAddress]);
        break; // unreachable
}

$emailId = (int)$result->storedEmailId;

// Attachment failures are warnings, exactly as before: the email is already
// stored and must not bounce. The service counted whatever it did store.
if ($result->hasAttachmentWarnings()) {
    logMessage('WARNING', 'parse.php: attachment(s) not stored', [
        'email_id' => $emailId,
        'to' => $toAddress,
        'warnings' => $result->attachmentWarnings,
    ]);
}
$attachmentsSaved = count($attachments) - count($result->attachmentWarnings);

// Pro webhooks are dispatched by the service's post-storage listener, which was
// registered above and runs after the commit (#196). This path used to build
// that payload itself — the call #34 left out, which took every Pro webhook
// down for switched-over addresses until #188 added it back.

logMessage('INFO', 'parse.php: saved incoming email', [
    'email_id' => $emailId,
    'to' => $toAddress,
    'attachments' => $attachmentsSaved,
]);

exit(0);
