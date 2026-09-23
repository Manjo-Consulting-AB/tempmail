<?php

declare(strict_types=1);

/**
 * Regression coverage for the canonical Email Storage service and the ingestion
 * adapters migrated onto it (#197, epic #169 step 9/10).
 *
 * Run with:  php tests/email_storage_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database, no network, no
 * credentials and no IMAP server are needed: the harness in
 * tests/lib/email_storage_harness.php brings a SQLite database and a throwaway
 * docroot holding copies of the shipped code.
 *
 * What is under test is the shipped code, not a restatement of it:
 *
 *   - sections 1–7 call the real EmailStorage::store() (the probe docroot's
 *     copy, so its attachments/ is the throwaway one) against a real PDO;
 *   - section 8 drives the real ImapProcessor::saveEmail() with an IMAP header
 *     fixture — no server, no connection, which is exactly the part of that
 *     path that persistence owns;
 *   - sections 9–10 run parse.php and python_imap_bridge.php as the separate
 *     CLI processes production runs them as, and assert their exit codes and
 *     their streams: the pipe target must print nothing at all, because Exim
 *     turns any output into a bounce;
 *   - section 11 scans the repository itself and asserts that the two
 *     ingestion INSERTs exist in the Email Storage service and nowhere else,
 *     which is the invariant the #199 audit established.
 *
 * The two dialect stand-ins the harness installs (TIMESTAMPDIFF and the
 * information_schema probe) are documented there; the SQL that uses them is
 * shipped verbatim.
 */

$msRepoRoot = dirname(__DIR__);

// Same guard the check_*.php scripts use, plus the one extension this suite
// cannot do without: it brings its own SQLite database so that it can run
// without MySQL, but there is no second choice of driver to fall back on.
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension (it brings its own SQLite database so it needs no MySQL).\n");
    exit(1);
}

// The service files answer a direct HTTP request with a 403 unless this is
// defined — the guard that keeps them from being endpoints. Defining it is what
// a real entrypoint does before it loads them.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}

require __DIR__ . '/lib/email_storage_harness.php';

// What is in the repository's own attachments/ before anything runs. Asserted
// unchanged at the end: every file this suite creates belongs in the throwaway
// docroot, and the probe's copies exist precisely so that stays true.
$msRepoAttachmentsBefore = ms_test_storage_files($msRepoRoot);

$msProbe = ms_test_storage_probe_build($msRepoRoot);
$msSqlite = ms_test_storage_sqlite($msProbe);

// Global scope on purpose: the stub config.php's $config/$pdo have to land in
// the global scope, exactly as the real config.php's do when a page requires it.
// Everything the code under test reaches for as a global — logMessage,
// proUserIsPro, tableHasColumn, sanitizeLocalPart, updateStat, $config — is
// defined by it.
$GLOBALS['MS_TEST_SQLITE'] = $msSqlite;
require $msProbe . '/config.php';

// The suite's own connection to the same file, with the MySQL dialect the
// shipped SQL expects. Assigning it to $pdo is what points the globals above at
// this connection, as the Pushover suite does.
$pdo = ms_test_storage_db($msSqlite);

// The classes under test are the probe docroot's copies: EmailStorage resolves
// attachments/ from its own __DIR__, so loading the repository's own class would
// write this suite's attachment files into the repository.
require_once $msProbe . '/EmailStorage/EmailStorage.php';
require_once $msProbe . '/EmailStorage/PostStorageWebhooks.php';
require_once $msProbe . '/php_imap_processor.php';

// Even a fatal mid-suite leaves nothing behind in /tmp.
register_shutdown_function(static function () use ($msProbe): void {
    ms_test_cleanup($msProbe);
});

$msStorage = new EmailStorage($pdo, true);

/** Store one fixture email; $overrides are ms_test_incoming()'s fields. */
$msStore = static function (array $overrides = [], array $options = []) use ($msStorage): StorageResult {
    return $msStorage->store(ms_test_incoming($overrides), $options);
};

/** The row store() reported, as an array (empty when the id is unusable). */
$msRowOf = static function (?StorageResult $result) use ($pdo): array {
    if ($result === null || $result->storedEmailId === null) {
        return [];
    }
    return ms_test_stored_email($pdo, $result->storedEmailId) ?? [];
};

$msAddress = static fn(string $localPart): string => $localPart . '@' . MS_TEST_EMAIL_DOMAIN;

echo "Mail Shield — Email Storage service and ingestion adapters (#197)\n";
echo "probe docroot: {$msProbe}\n";
echo 'MIME parser staged: ' . (ms_test_has_mime_parser() ? 'yes (composer install found)' : 'no (composer install has not been run)') . "\n";

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

$msProUser = ms_test_seed_user($pdo, 'storer@example.com', 'pro');
ms_test_set_ttl($pdo, $msProUser, 3);

$msProLocal = 'store0001';
$msProAddress = ms_test_seed_address($pdo, $msProLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1]);

// A temporary address: no owner, so the retention comes from the address itself.
$msFreeLocal = 'store0002';
$msFreeAddress = ms_test_seed_address($pdo, $msFreeLocal, ['pro_user_id' => null, 'is_personal' => 0]);
$msFreeExpiry = ms_test_address_expiry($pdo, $msFreeAddress);

$msReceived = new DateTimeImmutable('2026-09-20 12:00:00');

// ---------------------------------------------------------------------
// 1. Successful persistence
// ---------------------------------------------------------------------

ms_test_section('1. Successful email persistence');

$msBefore = ms_test_count($pdo, 'stored_emails');
$msResult = $msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'fromAddress' => 'sender@example.com',
    'subject' => 'Stored fixture',
    'bodyText' => 'Plain body.',
    'bodyHtml' => '<p>Plain body.</p>',
    'tempEmailId' => $msProAddress,
    'proUserId' => $msProUser,
]);

ms_test_check('1a. store() reports the email as stored', $msResult->isStored(), 'status=' . $msResult->status);
ms_test_check(
    '1b. store() returns the id of the new row',
    is_int($msResult->storedEmailId) && $msResult->storedEmailId > 0,
    'storedEmailId=' . ms_test_dump($msResult->storedEmailId)
);
ms_test_check('1c. the call added exactly one stored_emails row', ms_test_count($pdo, 'stored_emails') === $msBefore + 1);
ms_test_check('1d. the reported row is readable', $msRowOf($msResult) !== []);

$msRow = $msRowOf($msResult);
ms_test_same('1e. recipient is stored as the full address', $msAddress($msProLocal), $msRow['to_address'] ?? null);
ms_test_same('1f. sender is stored as the adapter supplied it', 'sender@example.com', $msRow['from_address'] ?? null);
ms_test_same('1g. subject is stored', 'Stored fixture', $msRow['subject'] ?? null);
ms_test_same('1h. both bodies are stored as the adapter sanitized them', ['Plain body.', '<p>Plain body.</p>'], [$msRow['body_text'] ?? null, $msRow['body_html'] ?? null]);
ms_test_same('1i. received_at is formatted from the DTO timestamp', '2026-09-20 12:00:00', $msRow['received_at'] ?? null);
ms_test_same('1j. temp_email_id links the row to the address', $msProAddress, (int) ($msRow['temp_email_id'] ?? 0));
ms_test_same(
    '1k. a Pro address is retained for pro_users.address_ttl_days from received_at',
    '2026-09-23 12:00:00',
    $msRow['expires_at'] ?? null
);

// Persistence-time normalization: the value a duplicate check compares is the
// value the row receives.
$msRow = $msRowOf($msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'fromAddress' => null,
    'subject' => '',
    'bodyText' => null,
    'tempEmailId' => $msProAddress,
    'proUserId' => $msProUser,
]));
ms_test_same('1l. an absent sender is stored as an empty string', '', $msRow['from_address'] ?? null);
ms_test_same('1m. an empty subject becomes (no subject)', '(no subject)', $msRow['subject'] ?? null);
ms_test_same('1n. an absent body stays NULL rather than becoming empty', null, $msRow['body_text'] ?? null);

// Retention for an address no Pro account owns: the address' own expiry.
$msRow = $msRowOf($msStore([
    'toAddress' => $msAddress($msFreeLocal),
    'receivedAt' => $msReceived,
    'tempEmailId' => $msFreeAddress,
]));
ms_test_same('1o. a temporary address keeps its own expiry', $msFreeExpiry, $msRow['expires_at'] ?? null);
ms_test_same('1p. temp_email_id is still the address row', $msFreeAddress, (int) ($msRow['temp_email_id'] ?? 0));
ms_test_same('1q. emails_processed counts every stored email', 3, ms_test_stat($pdo, 'emails_processed'));

// ---------------------------------------------------------------------
// 2. Successful attachment persistence
// ---------------------------------------------------------------------

ms_test_section('2. Successful attachment persistence');

$msAttachmentBytes = 'attachment bytes';
$msResult = $msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'attachments' => [new EmailAttachment('report.txt', $msAttachmentBytes, 'text/plain', 'cid-1')],
]);
$msRow = $msRowOf($msResult);
$msAttachments = ms_test_attachment_rows($pdo, (int) $msResult->storedEmailId);

ms_test_same('2a. a stored attachment is not a warning', [], $msResult->attachmentWarnings);
ms_test_check('2b. the attachment has one email_attachments row', count($msAttachments) === 1, 'rows=' . count($msAttachments));
ms_test_same('2c. the filename is stored as supplied', 'report.txt', $msAttachments[0]['filename'] ?? null);
ms_test_same(
    '2d. file_path is the attachments/ relative path the download endpoints resolve',
    'attachments/' . basename((string) ($msAttachments[0]['file_path'] ?? '')),
    $msAttachments[0]['file_path'] ?? null
);
ms_test_check(
    '2e. the file exists at that path inside the docroot',
    is_file($msProbe . '/' . (string) ($msAttachments[0]['file_path'] ?? 'x')),
    'missing ' . (string) ($msAttachments[0]['file_path'] ?? '')
);
ms_test_same(
    '2f. the stored file holds the attachment bytes',
    $msAttachmentBytes,
    (string) @file_get_contents($msProbe . '/' . (string) ($msAttachments[0]['file_path'] ?? 'x'))
);
ms_test_same('2g. mime_type is stored', 'text/plain', $msAttachments[0]['mime_type'] ?? null);
ms_test_same('2h. file_size is the byte length of the data', strlen($msAttachmentBytes), (int) ($msAttachments[0]['file_size'] ?? -1));
ms_test_same('2i. content_id is stored, so an inline cid: reference still resolves', 'cid-1', $msAttachments[0]['content_id'] ?? null);
ms_test_same('2j. attachments_processed counts the stored attachment', 1, ms_test_stat($pdo, 'attachments_processed'));
ms_test_check('2k. the attachment is linked to its email row', $msRow !== [] && (int) ($msAttachments[0]['email_id'] ?? 0) === (int) $msResult->storedEmailId);

// A filename the storage layer has to sanitize for the filesystem.
$msResult = $msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'attachments' => [new EmailAttachment('we ird/na me.txt', 'x', null, null)],
]);
$msAttachments = ms_test_attachment_rows($pdo, (int) $msResult->storedEmailId);
ms_test_same('2l. an unsafe filename is sanitized before it reaches the disk', 'we_ird_na_me.txt', $msAttachments[0]['filename'] ?? null);

// ---------------------------------------------------------------------
// 3. The duplicate rule: opt-in, and off by default
// ---------------------------------------------------------------------

ms_test_section('3. Duplicate submission (#191 rule): opt-in, off by default');

$msNoCheck = [
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'fromAddress' => 'duplicate@example.com',
    'subject' => 'No-check fixture',
];
$msFirst = $msStore($msNoCheck);
$msSecond = $msStore($msNoCheck);
ms_test_check('3a. with the option off, the second identical message is stored too', $msFirst->isStored() && $msSecond->isStored(), 'statuses=' . $msFirst->status . '/' . $msSecond->status);
ms_test_same('3b. with the option off, both rows are there', 2, ms_test_count($pdo, 'stored_emails', 'subject = ?', ['No-check fixture']));

$msOptIn = [EmailStorage::OPTION_DETECT_DUPLICATES => true];
$msDuplicateSubject = 'Opt-in fixture';
$msFirst = $msStore(array_merge($msNoCheck, ['subject' => $msDuplicateSubject]), $msOptIn);
ms_test_check('3c. with the option on, the first message is stored', $msFirst->isStored(), 'status=' . $msFirst->status);

$msFilesBefore = count(ms_test_storage_files($msProbe));
$msStatsBefore = ms_test_stat($pdo, 'emails_processed');
$msSecond = $msStore(array_merge($msNoCheck, ['subject' => $msDuplicateSubject, 'attachments' => [new EmailAttachment('dupe.txt', 'x', null, null)]]), $msOptIn);
ms_test_same('3d. with the option on, the second identical message is a duplicate', StorageResult::STATUS_DUPLICATE, $msSecond->status);
ms_test_same('3e. a duplicate reports no stored row id', null, $msSecond->storedEmailId);
ms_test_same('3f. a duplicate writes no row', 1, ms_test_count($pdo, 'stored_emails', 'subject = ?', [$msDuplicateSubject]));
ms_test_same('3g. a duplicate writes no attachment file', $msFilesBefore, count(ms_test_storage_files($msProbe)));
ms_test_same('3h. a duplicate moves no statistic', $msStatsBefore, ms_test_stat($pdo, 'emails_processed'));

$msLater = $msStore(array_merge($msNoCheck, ['subject' => $msDuplicateSubject, 'receivedAt' => $msReceived->modify('+10 minutes')]), $msOptIn);
ms_test_check('3i. the rule is a five-minute window: the same message ten minutes later is stored', $msLater->isStored(), 'status=' . $msLater->status);
$msOtherSubject = $msStore(array_merge($msNoCheck, ['subject' => 'Opt-in fixture, other subject']), $msOptIn);
ms_test_check('3j. the rule keys on the subject: a different one is stored', $msOtherSubject->isStored(), 'status=' . $msOtherSubject->status);
$msOtherSender = $msStore(array_merge($msNoCheck, ['subject' => $msDuplicateSubject, 'fromAddress' => 'another@example.com']), $msOptIn);
ms_test_check('3k. the rule keys on the sender: a different one is stored', $msOtherSender->isStored(), 'status=' . $msOtherSender->status);

// ---------------------------------------------------------------------
// 4. Invalid / rejected input
// ---------------------------------------------------------------------

ms_test_section('4. Invalid and rejected input');

$msBefore = ms_test_count($pdo, 'stored_emails');
$msBadRecipients = [
    'not-an-address',
    'store0001@',
    'bad!part@manjo.me',
    str_repeat('a', 65) . '@manjo.me',
];
foreach ($msBadRecipients as $msBad) {
    $msResult = $msStore(['toAddress' => $msBad, 'receivedAt' => $msReceived]);
    ms_test_same('4a. rejected: ' . $msBad, StorageResult::STATUS_REJECTED, $msResult->status);
}
ms_test_same('4b. no row was written for any unusable recipient', $msBefore, ms_test_count($pdo, 'stored_emails'));
ms_test_check('4c. a rejection carries a diagnostic message', $msResult->message !== null && $msResult->message !== '', 'message=' . ms_test_dump($msResult->message));

// The recipient gate is opt-in: with it off, an unknown address still stores
// the message with no ownership, which is what the IMAP path and the Python
// fallback have always done.
$msUnknown = $msStore(['toAddress' => $msAddress('unknown99'), 'receivedAt' => $msReceived]);
ms_test_check('4d. with the gate off, an unknown recipient is stored anyway', $msUnknown->isStored(), 'status=' . $msUnknown->status);
$msRow = $msRowOf($msUnknown);
ms_test_same('4e. an unknown recipient leaves temp_email_id NULL', null, $msRow['temp_email_id'] ?? null);

$msGate = [EmailStorage::OPTION_REJECT_UNKNOWN_RECIPIENT => true];
$msUnknown = $msStore(['toAddress' => $msAddress('unknown99'), 'receivedAt' => $msReceived], $msGate);
ms_test_same('4f. with the gate on, an unknown recipient is rejected', StorageResult::STATUS_REJECTED, $msUnknown->status);

$msExpiredLocal = 'store0004';
$msExpiredAddress = ms_test_seed_address($pdo, $msExpiredLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1]);
ms_test_expire_address($pdo, $msExpiredAddress);
$msExpired = $msStore(['toAddress' => $msAddress($msExpiredLocal), 'receivedAt' => $msReceived], $msGate);
ms_test_same('4g. with the gate on, an expired recipient is rejected', StorageResult::STATUS_REJECTED, $msExpired->status);

$msLive = $msStore(['toAddress' => $msAddress($msProLocal), 'receivedAt' => $msReceived], $msGate);
ms_test_check('4h. with the gate on, a live recipient is still stored', $msLive->isStored(), 'status=' . $msLive->status);

// ---------------------------------------------------------------------
// 5. Database failure and rollback
// ---------------------------------------------------------------------

ms_test_section('5. Database failure and rollback');

$msFailTrigger = 'ms_test_fail_stored_emails';
$pdo->exec("CREATE TRIGGER {$msFailTrigger} BEFORE INSERT ON stored_emails BEGIN SELECT RAISE(ABORT, 'probe: forced stored_emails failure'); END");

$msBefore = ms_test_count($pdo, 'stored_emails');
$msStatsBefore = ms_test_stat($pdo, 'emails_processed');
$msFilesBefore = count(ms_test_storage_files($msProbe));

$msResult = $msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'attachments' => [new EmailAttachment('never.txt', 'x', null, null)],
]);

$msLeftTransactionOpen = $pdo->inTransaction();
if ($msLeftTransactionOpen) {
    // So the rest of the suite can still run; asserted below, not swallowed.
    $pdo->rollBack();
}
$pdo->exec("DROP TRIGGER {$msFailTrigger}");

ms_test_same('5a. a failed insert is reported as failed, not as a verdict about the message', StorageResult::STATUS_FAILED, $msResult->status);
ms_test_same('5b. a failed insert reports no stored row id', null, $msResult->storedEmailId);
ms_test_same('5c. a failed insert wrote no row', $msBefore, ms_test_count($pdo, 'stored_emails'));
ms_test_check('5d. the service rolled its own transaction back instead of leaving it open', $msLeftTransactionOpen === false);
ms_test_same('5e. a failed insert attempts no attachment', $msFilesBefore, count(ms_test_storage_files($msProbe)));
ms_test_same('5f. a failed insert moves no statistic', $msStatsBefore, ms_test_stat($pdo, 'emails_processed'));

$msResult = $msStore(['toAddress' => $msAddress($msProLocal), 'receivedAt' => $msReceived]);
ms_test_check('5g. storage works again once the database does', $msResult->isStored(), 'status=' . $msResult->status);

// ---------------------------------------------------------------------
// 6. Attachment failure: the email is kept, the attachment leaves nothing
// ---------------------------------------------------------------------

ms_test_section('6. Attachment persistence failure (#191 semantics)');

$msFailAttachment = 'ms_test_fail_attachment';
$pdo->exec("CREATE TRIGGER {$msFailAttachment} BEFORE INSERT ON email_attachments WHEN NEW.filename = 'broken.txt' BEGIN SELECT RAISE(ABORT, 'probe: forced attachment failure'); END");

$msFilesBefore = count(ms_test_storage_files($msProbe));
$msStatsBefore = ms_test_stat($pdo, 'attachments_processed');
ms_test_forget_logs();

$msResult = $msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'attachments' => [
        new EmailAttachment('good.txt', 'good bytes', 'text/plain', null),
        new EmailAttachment('broken.txt', 'broken bytes', 'text/plain', null),
    ],
]);

$pdo->exec("DROP TRIGGER {$msFailAttachment}");

ms_test_check('6a. the email is stored even though one attachment failed', $msResult->isStored(), 'status=' . $msResult->status);
ms_test_check('6b. the failing attachment is reported as a warning', count($msResult->attachmentWarnings) === 1, ms_test_dump($msResult->attachmentWarnings));
ms_test_check(
    '6c. the warning names the attachment that failed',
    str_contains((string) ($msResult->attachmentWarnings[0] ?? ''), 'broken.txt'),
    ms_test_dump($msResult->attachmentWarnings)
);
$msRow = $msRowOf($msResult);
ms_test_check('6d. the email row is there', $msRow !== []);
$msAttachments = ms_test_attachment_rows($pdo, (int) $msResult->storedEmailId);
ms_test_same('6e. only the attachment that succeeded has a row', ['good.txt'], array_column($msAttachments, 'filename'));
ms_test_same(
    '6f. the failed attachment left no file behind, and the good one did',
    $msFilesBefore + 1,
    count(ms_test_storage_files($msProbe))
);
ms_test_check(
    '6g. the surviving file is the successful attachment',
    (string) @file_get_contents($msProbe . '/' . (string) ($msAttachments[0]['file_path'] ?? 'x')) === 'good bytes'
);
ms_test_same('6h. only the stored attachment is counted', $msStatsBefore + 1, ms_test_stat($pdo, 'attachments_processed'));
ms_test_check('6i. the failure is logged as a warning', ms_test_logged('kept an email whose attachment failed'));

// The same thing with a transaction the *caller* opened: the service joins it
// instead of opening a second one, and the attachment still rolls back on its
// own without taking the email with it.
$pdo->exec("CREATE TRIGGER {$msFailAttachment} BEFORE INSERT ON email_attachments WHEN NEW.filename = 'broken.txt' BEGIN SELECT RAISE(ABORT, 'probe: forced attachment failure'); END");
$msFilesBefore = count(ms_test_storage_files($msProbe));

$pdo->beginTransaction();
$msResult = $msStore([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'subject' => 'Caller transaction fixture',
    'attachments' => [new EmailAttachment('broken.txt', 'broken bytes', 'text/plain', null)],
]);
$msStillInCallersTransaction = $pdo->inTransaction();
$pdo->commit();
$pdo->exec("DROP TRIGGER {$msFailAttachment}");

ms_test_check('6j. the service leaves the caller to commit its own transaction', $msStillInCallersTransaction && !$pdo->inTransaction());
ms_test_check('6k. the email row survives the commit made by the caller', $msRowOf($msResult) !== []);
ms_test_same(
    '6l. the failed attachment has no row even inside a caller transaction',
    [],
    ms_test_attachment_rows($pdo, (int) $msResult->storedEmailId)
);
ms_test_same('6m. and no file', $msFilesBefore, count(ms_test_storage_files($msProbe)));

// ---------------------------------------------------------------------
// 7. Post-storage listeners
// ---------------------------------------------------------------------

ms_test_section('7. Post-storage processing');

$msContexts = [];
$msListenerStorage = new EmailStorage($pdo, true);
$msListenerStorage->onStored(static function (array $stored): void {
    throw new RuntimeException('probe: forced listener failure');
});
$msListenerStorage->onStored(static function (array $stored) use (&$msContexts): void {
    $msContexts[] = $stored;
});

ms_test_forget_logs();
$msResult = $msListenerStorage->store(ms_test_incoming([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
    'subject' => 'Listener fixture',
]), []);

ms_test_check('7a. a throwing listener does not roll the stored email back', $msResult->isStored() && $msRowOf($msResult) !== [], 'status=' . $msResult->status);
ms_test_check('7b. the listener after the failing one still ran', count($msContexts) === 1, 'runs=' . count($msContexts));
ms_test_check('7c. the listener failure is logged as a warning', ms_test_logged('post-storage listener failed'));
ms_test_same(
    '7d. a listener receives the values the service wrote',
    [
        'to_address' => $msAddress($msProLocal),
        'subject' => 'Listener fixture',
        'temp_email_id' => $msProAddress,
        'pro_user_id' => $msProUser,
    ],
    array_intersect_key($msContexts[0] ?? [], array_flip(['to_address', 'subject', 'temp_email_id', 'pro_user_id']))
);

// The one consumer that exists: the Pro webhook listener, registered on the
// service, queuing for a Pro address the same way the paths always did.
$msHookId = ms_test_seed_webhook($pdo, $msProUser, 'pushover', 'all', 'Probe Pushover');
$msNotifyLocal = 'store0007';
ms_test_seed_address($pdo, $msNotifyLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1, 'pushover_enabled' => 1]);

$msConsumerStorage = new EmailStorage($pdo, true);
PostStorageWebhooks::attach($msConsumerStorage, $config, $pdo, true);
$msResult = $msConsumerStorage->store(ms_test_incoming([
    'toAddress' => $msAddress($msNotifyLocal),
    'receivedAt' => $msReceived,
]), []);

ms_test_check('7e. the webhook consumer runs through the service for a stored email', $msResult->isStored(), 'status=' . $msResult->status);
ms_test_same('7f. the Pushover webhook of an opted-in address is queued', 1, ms_test_deliveries($pdo, $msProUser)[$msHookId] ?? 0);

$msConsumerStorage->store(ms_test_incoming([
    'toAddress' => $msAddress($msProLocal),
    'receivedAt' => $msReceived,
]), []);
ms_test_same(
    '7g. Pushover stays opt-in per address through the service',
    1,
    ms_test_deliveries($pdo, $msProUser)[$msHookId] ?? 0
);

// ---------------------------------------------------------------------
// 8. PHP IMAP adapter (ImapProcessor save path)
// ---------------------------------------------------------------------

ms_test_section('8. PHP IMAP adapter (ImapProcessor::saveEmail)');

$msImapLocal = 'store0008';
$msImapAddress = ms_test_seed_address($pdo, $msImapLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1]);
$msProcessor = new ImapProcessor($config, $pdo, true);
$msSaveEmail = new ReflectionMethod(ImapProcessor::class, 'saveEmail');
$msSaveEmail->setAccessible(true);

/** An imap_headerinfo-shaped stdClass: no server, no connection. */
$msHeader = static fn(string $subject, int $timestamp): object => (object) [
    'from' => [(object) ['mailbox' => 'imap.sender', 'host' => 'example.com']],
    'subject' => $subject,
    'udate' => $timestamp,
];

$msImapTime = strtotime('2026-09-21 08:30:00');
$msStatsBefore = ms_test_stat($pdo, 'emails_processed');
$msSaved = $msSaveEmail->invoke($msProcessor, $msHeader('IMAP fixture', $msImapTime), '<p>Hello <b>IMAP</b>.</p>', $msAddress($msImapLocal), null, null);

ms_test_check('8a. the adapter reports the message saved', $msSaved === true);
$msRow = $pdo->query('SELECT * FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
ms_test_same('8b. the recipient is the message header address', $msAddress($msImapLocal), $msRow['to_address'] ?? null);
ms_test_same('8c. the From header is stored as a bare address', 'imap.sender@example.com', $msRow['from_address'] ?? null);
ms_test_same('8d. the subject is stored', 'IMAP fixture', $msRow['subject'] ?? null);
ms_test_same('8e. an HTML body is split into body_html and a text rendering', ['Hello IMAP.', '<p>Hello <b>IMAP</b>.</p>'], [$msRow['body_text'] ?? null, $msRow['body_html'] ?? null]);
ms_test_same('8f. received_at comes from the IMAP internal date, not the wall clock', date('Y-m-d H:i:s', $msImapTime), $msRow['received_at'] ?? null);
ms_test_same('8g. the Pro retention window is applied', date('Y-m-d H:i:s', $msImapTime + 3 * 86400), $msRow['expires_at'] ?? null);
ms_test_same('8h. ownership is resolved for the recipient', $msImapAddress, (int) ($msRow['temp_email_id'] ?? 0));
ms_test_same('8i. the adapter counts the stored email', $msStatsBefore + 1, ms_test_stat($pdo, 'emails_processed'));

// A plain-text body takes the other branch, and an inline data: URI is stripped
// before storage on this path.
$msSaveEmail->invoke($msProcessor, $msHeader('IMAP text fixture', $msImapTime), 'Just plain text.', $msAddress($msImapLocal), null, null);
$msRow = $pdo->query('SELECT * FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
ms_test_same('8j. a plain-text body is stored as body_text only', ['Just plain text.', null], [$msRow['body_text'] ?? null, $msRow['body_html'] ?? null]);

$msSaveEmail->invoke($msProcessor, $msHeader('IMAP inline fixture', $msImapTime), '<p>See this</p><img src="data:image/png;base64,iVBORw0KGgo=">', $msAddress($msImapLocal), null, null);
$msRow = $pdo->query('SELECT * FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
ms_test_check(
    '8k. an inline data: URI is stripped before it is stored',
    str_contains((string) ($msRow['body_html'] ?? ''), '[attachment removed]') && !str_contains((string) ($msRow['body_html'] ?? ''), 'base64,'),
    'stored=' . ms_test_dump($msRow['body_html'] ?? null)
);

// This path passes no options, so it keeps storing everything it sees: the
// duplicate rule is the Python bridge's, not the IMAP path's.
$msSaveEmail->invoke($msProcessor, $msHeader('IMAP repeat fixture', $msImapTime), 'Repeated body.', $msAddress($msImapLocal), null, null);
$msSaveEmail->invoke($msProcessor, $msHeader('IMAP repeat fixture', $msImapTime), 'Repeated body.', $msAddress($msImapLocal), null, null);
ms_test_same(
    '8l. the IMAP path performs no duplicate check, as before the migration',
    2,
    ms_test_count($pdo, 'stored_emails', 'subject = ?', ['IMAP repeat fixture'])
);

// ---------------------------------------------------------------------
// 9. DirectAdmin pipe adapter (parse.php)
// ---------------------------------------------------------------------

ms_test_section('9. DirectAdmin pipe adapter (parse.php)');

$msEnv = ms_test_storage_env($msProbe);
$msPipeLocal = 'store0009';
$msPipeAddress = ms_test_seed_address($pdo, $msPipeLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1]);

$msEml = static function (string $toAddress, string $subject, string $body): string {
    return implode("\r\n", [
        'Date: Wed, 23 Sep 2026 10:00:00 +0200',
        'From: Sender Name <sender@example.com>',
        'To: <' . $toAddress . '>',
        'Subject: ' . $subject,
        'Message-ID: <probe-' . md5($subject . $body) . '@example.com>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        $body,
        '',
    ]);
};

$msRun = ms_test_cli_php($msProbe, 'parse.php', $msEml($msAddress($msPipeLocal), 'Pipe fixture', 'Hello from the pipe.'), $msEnv + ['LOCAL_PART' => $msPipeLocal]);
ms_test_same('9a. a deliverable message exits 0', 0, $msRun['exit']);
ms_test_same('9b. the pipe target prints nothing on stdout', '', $msRun['stdout']);
ms_test_same('9c. the pipe target prints nothing on stderr (any output would bounce the mail)', '', $msRun['stderr']);

$msRow = $pdo->query('SELECT * FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
ms_test_same('9d. the message is filed under the recipient address', $msAddress($msPipeLocal), $msRow['to_address'] ?? null);
ms_test_same('9e. ownership is resolved for the recipient', $msPipeAddress, (int) ($msRow['temp_email_id'] ?? 0));
ms_test_same(
    '9f. received_at is the pipe clock and the retention is the Pro window',
    3 * 86400,
    strtotime((string) ($msRow['expires_at'] ?? '')) - strtotime((string) ($msRow['received_at'] ?? ''))
);

if (ms_test_has_mime_parser()) {
    ms_test_same('9g. the parsed From header is stored', 'sender@example.com', $msRow['from_address'] ?? null);
    ms_test_same('9h. the parsed subject is stored', 'Pipe fixture', $msRow['subject'] ?? null);
    // Trimmed: the parser keeps the message's trailing line break, which is
    // not what "the body reached the row" is about.
    ms_test_same('9i. the parsed body is stored', 'Hello from the pipe.', trim((string) ($msRow['body_text'] ?? '')));
} else {
    ms_test_skip('9g. the parsed From header is stored', 'run composer install so MailParser has its MIME parser');
    ms_test_skip('9h. the parsed subject is stored', 'run composer install so MailParser has its MIME parser');
    ms_test_skip('9i. the parsed body is stored', 'run composer install so MailParser has its MIME parser');
}

// A permanent rejection is exit 1 and no row: unknown recipient, expired
// recipient, an unusable local part, and an empty message.
$msBefore = ms_test_count($pdo, 'stored_emails');
foreach ([
    '9j. an unknown recipient is a permanent rejection' => ['LOCAL_PART' => 'unknown99'],
    '9k. an unusable local part is a permanent rejection' => ['LOCAL_PART' => 'BAD!PART'],
] as $msLabel => $msExtra) {
    $msRun = ms_test_cli_php($msProbe, 'parse.php', $msEml($msAddress($msPipeLocal), 'Pipe fixture', 'Body.'), $msEnv + $msExtra);
    ms_test_same($msLabel, 1, $msRun['exit']);
    ms_test_check($msLabel . ' (the reason is reported on stderr)', $msRun['stderr'] !== '');
}

$msExpiredPipeLocal = 'store0010';
$msExpiredPipeAddress = ms_test_seed_address($pdo, $msExpiredPipeLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1]);
ms_test_expire_address($pdo, $msExpiredPipeAddress);
$msRun = ms_test_cli_php($msProbe, 'parse.php', $msEml($msAddress($msExpiredPipeLocal), 'Pipe fixture', 'Body.'), $msEnv + ['LOCAL_PART' => $msExpiredPipeLocal]);
ms_test_same('9l. an expired recipient is a permanent rejection', 1, $msRun['exit']);

$msRun = ms_test_cli_php($msProbe, 'parse.php', '', $msEnv + ['LOCAL_PART' => $msPipeLocal]);
ms_test_same('9m. an empty message on stdin is a permanent rejection', 1, $msRun['exit']);

ms_test_same('9n. none of the rejected deliveries wrote a row', $msBefore, ms_test_count($pdo, 'stored_emails'));

// The recipient sources the script documents, in the priority order it uses.
$msArgvLocal = 'store0011';
ms_test_seed_address($pdo, $msArgvLocal, ['pro_user_id' => $msProUser, 'is_personal' => 1]);
$msRun = ms_test_cli_php($msProbe, 'parse.php', $msEml($msAddress($msArgvLocal), 'Pipe argv fixture', 'Body.'), $msEnv + ['LOCAL_PART' => 'unknown99'], [$msArgvLocal]);
ms_test_same('9o. argv[1] is used when the pipe command supplies it', 0, $msRun['exit']);
ms_test_same(
    '9p. and it wins over LOCAL_PART',
    $msAddress($msArgvLocal),
    (string) $pdo->query('SELECT to_address FROM stored_emails ORDER BY id DESC LIMIT 1')->fetchColumn()
);

$msRun = ms_test_cli_php($msProbe, 'parse.php', $msEml($msAddress($msPipeLocal), 'Pipe RECIPIENT fixture', 'Body.'), $msEnv + ['RECIPIENT' => $msAddress($msPipeLocal)]);
ms_test_same('9q. the full RECIPIENT address is accepted too', 0, $msRun['exit']);

// End to end: a message carrying an attachment is stored with it, and the file
// lands in the docroot's attachments/ (which the teardown removes).
if (ms_test_has_mime_parser()) {
    $msEmlWithAttachment = implode("\r\n", [
        'From: Sender Name <sender@example.com>',
        'To: <' . $msAddress($msPipeLocal) . '>',
        'Subject: Pipe attachment fixture',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="PROBE-BOUNDARY"',
        '',
        '--PROBE-BOUNDARY',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Body with an attachment.',
        '',
        '--PROBE-BOUNDARY',
        'Content-Type: application/octet-stream; name="probe.txt"',
        'Content-Disposition: attachment; filename="probe.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('probe attachment bytes'),
        '',
        '--PROBE-BOUNDARY--',
        '',
    ]);
    $msRun = ms_test_cli_php($msProbe, 'parse.php', $msEmlWithAttachment, $msEnv + ['LOCAL_PART' => $msPipeLocal]);
    ms_test_same('9r. a message with an attachment still exits 0', 0, $msRun['exit']);
    $msEmailId = (int) $pdo->query('SELECT id FROM stored_emails ORDER BY id DESC LIMIT 1')->fetchColumn();
    $msAttachments = ms_test_attachment_rows($pdo, $msEmailId);
    ms_test_same('9s. the attachment is stored with the email', ['probe.txt'], array_column($msAttachments, 'filename'));
    ms_test_check(
        '9t. its bytes are on disk in the docroot',
        (string) @file_get_contents($msProbe . '/' . (string) ($msAttachments[0]['file_path'] ?? 'x')) === 'probe attachment bytes'
    );
} else {
    ms_test_skip('9r–9t. an attachment survives the pipe end to end', 'run composer install so MailParser can decode the part');
}

// An inline image that carries no filename at all still survives, because the
// HTML body references it by Content-ID: dropping it would leave the <img
// src="cid:…"> in the stored body with nothing to resolve to (#217).
if (ms_test_has_mime_parser()) {
    $msEmlWithInlineImage = implode("\r\n", [
        'From: Sender Name <sender@example.com>',
        'To: <' . $msAddress($msPipeLocal) . '>',
        'Subject: Pipe inline image fixture',
        'MIME-Version: 1.0',
        'Content-Type: multipart/related; boundary="PROBE-INLINE-BOUNDARY"',
        '',
        '--PROBE-INLINE-BOUNDARY',
        'Content-Type: text/html; charset=utf-8',
        '',
        '<p>Body with an inline image <img src="cid:logo123"></p>',
        '',
        '--PROBE-INLINE-BOUNDARY',
        'Content-Type: image/png',
        'Content-ID: <logo123>',
        'Content-Disposition: inline',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('inline image bytes'),
        '',
        '--PROBE-INLINE-BOUNDARY--',
        '',
    ]);
    $msRun = ms_test_cli_php($msProbe, 'parse.php', $msEmlWithInlineImage, $msEnv + ['LOCAL_PART' => $msPipeLocal]);
    ms_test_same('9v. a message with a nameless inline image exits 0', 0, $msRun['exit']);
    $msInlineEmailId = (int) $pdo->query('SELECT id FROM stored_emails ORDER BY id DESC LIMIT 1')->fetchColumn();
    $msInlineAttachments = ms_test_attachment_rows($pdo, $msInlineEmailId);
    ms_test_same('9w. the inline image is stored as one attachment', 1, count($msInlineAttachments));
    ms_test_same('9x. it keeps the Content-ID the body refers to', 'logo123', (string) ($msInlineAttachments[0]['content_id'] ?? ''));
    ms_test_same('9y. it is named after its MIME type', 'inline-image.png', (string) ($msInlineAttachments[0]['filename'] ?? ''));
    ms_test_same('9z. and its MIME type is unchanged', 'image/png', (string) ($msInlineAttachments[0]['mime_type'] ?? ''));
} else {
    ms_test_skip('9v–9z. a nameless inline image survives the pipe end to end', 'run composer install so MailParser can decode the part');
}

// An attachment is the sender's bytes, not the parser's idea of text: a
// `text/*` part is charset-converted to UTF-8 by getContent(), so a latin-1
// .txt/.csv/.ics would be saved with different bytes than were attached (#212).
// The three bytes below are åäö in ISO-8859-1.
if (ms_test_has_mime_parser()) {
    $msLatin1Bytes = "\xE5\xE4\xF6";
    $msEmlWithLatin1Attachment = implode("\r\n", [
        'From: Sender Name <sender@example.com>',
        'To: <' . $msAddress($msPipeLocal) . '>',
        'Subject: Pipe latin-1 attachment fixture',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="PROBE-CHARSET-BOUNDARY"',
        '',
        '--PROBE-CHARSET-BOUNDARY',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Body with a latin-1 attachment.',
        '',
        '--PROBE-CHARSET-BOUNDARY',
        'Content-Type: text/plain; charset=ISO-8859-1; name="latin1.txt"',
        'Content-Disposition: attachment; filename="latin1.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode($msLatin1Bytes),
        '',
        '--PROBE-CHARSET-BOUNDARY--',
        '',
    ]);
    $msRun = ms_test_cli_php($msProbe, 'parse.php', $msEmlWithLatin1Attachment, $msEnv + ['LOCAL_PART' => $msPipeLocal]);
    ms_test_same('9aa. a message with a text/plain attachment exits 0', 0, $msRun['exit']);
    $msLatin1EmailId = (int) $pdo->query('SELECT id FROM stored_emails ORDER BY id DESC LIMIT 1')->fetchColumn();
    $msLatin1Attachments = ms_test_attachment_rows($pdo, $msLatin1EmailId);
    ms_test_same(
        '9ab. a text/plain attachment is saved byte-identical to the attached bytes, not charset-converted',
        $msLatin1Bytes,
        (string) @file_get_contents($msProbe . '/' . (string) ($msLatin1Attachments[0]['file_path'] ?? 'x'))
    );
} else {
    ms_test_skip('9aa–9ab. a text/plain attachment keeps the attached bytes', 'run composer install so MailParser can decode the part');
}

// A *temporary* failure must defer, not bounce: the pipe exits 75 (EX_TEMPFAIL)
// and still prints nothing at all, so the DirectAdmin/Exim pipe transport —
// which bounces on any output, whatever the exit code — retries the delivery
// later instead. Hiding the table the service writes to is how an outage looks
// from inside the script: the lookup in step 3 still resolves, the store fails.
$pdo->exec('ALTER TABLE stored_emails RENAME TO stored_emails_hidden');
try {
    $msRun = ms_test_cli_php(
        $msProbe,
        'parse.php',
        $msEml($msAddress($msPipeLocal), 'Pipe store failure fixture', 'Body.'),
        $msEnv + ['LOCAL_PART' => $msPipeLocal]
    );
} finally {
    // Put it back before any later check runs, even if the assertions fail.
    $pdo->exec('ALTER TABLE stored_emails_hidden RENAME TO stored_emails');
}
ms_test_same('9u. a store failure exits 75 so Exim defers the delivery', 75, $msRun['exit']);
ms_test_same('9u. (a deferred run prints nothing on stdout)', '', $msRun['stdout']);
ms_test_same('9u. (a deferred run prints nothing on stderr)', '', $msRun['stderr']);

// ---------------------------------------------------------------------
// 10. Python fallback handoff (python_imap_bridge.php)
// ---------------------------------------------------------------------

ms_test_section('10. Python fallback handoff (python_imap_bridge.php)');

$msBridgePayload = [
    'to_address' => $msAddress($msFreeLocal),
    'received_at' => '2026-09-22 09:00:00',
    'from_address' => 'fallback@example.com',
    'subject' => 'Bridge fixture',
    'body_text' => 'Stored by the bridge.',
];

$msRun = ms_test_cli_php($msProbe, 'python_imap_bridge.php', (string) json_encode($msBridgePayload), $msEnv);
ms_test_same('10a. a verdict is produced (exit 0)', 0, $msRun['exit']);
ms_test_same('10b. the bridge answers with one JSON object', StorageResult::STATUS_STORED, $msRun['json']['status'] ?? null);
ms_test_check('10c. the answer carries the stored row id', is_int($msRun['json']['stored_email_id'] ?? null) && $msRun['json']['stored_email_id'] > 0, ms_test_dump($msRun['json']));

$msRow = $pdo->query('SELECT * FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
ms_test_same('10d. the message is filed under the recipient address', $msAddress($msFreeLocal), $msRow['to_address'] ?? null);
ms_test_same('10e. the payload fields are stored', ['fallback@example.com', 'Bridge fixture', 'Stored by the bridge.'], [$msRow['from_address'] ?? null, $msRow['subject'] ?? null, $msRow['body_text'] ?? null]);
ms_test_same('10f. the fallback path stores no attachments, as before', [], ms_test_attachment_rows($pdo, (int) ($msRow['id'] ?? 0)));

// The bridge is the one caller that passes OPTION_DETECT_DUPLICATES, so its own
// duplicate rule is the one that has to hold here.
$msRun = ms_test_cli_php($msProbe, 'python_imap_bridge.php', (string) json_encode($msBridgePayload), $msEnv);
ms_test_same('10g. re-submitting the same message is reported as a duplicate', StorageResult::STATUS_DUPLICATE, $msRun['json']['status'] ?? null);
ms_test_same('10h. a duplicate still exits 0 — it is an answer, not a failure', 0, $msRun['exit']);
ms_test_same('10i. and it wrote no second row', 1, ms_test_count($pdo, 'stored_emails', 'subject = ?', ['Bridge fixture']));

$msRun = ms_test_cli_php($msProbe, 'python_imap_bridge.php', (string) json_encode(array_merge($msBridgePayload, ['subject' => 'Bridge fixture, later'])), $msEnv);
ms_test_same('10j. a different subject is stored', StorageResult::STATUS_STORED, $msRun['json']['status'] ?? null);

$msRun = ms_test_cli_php($msProbe, 'python_imap_bridge.php', (string) json_encode(['to_address' => $msAddress('unknown99'), 'received_at' => '2026-09-22 09:05:00']), $msEnv);
ms_test_same('10k. an unknown recipient is stored, because this path passes no recipient gate', StorageResult::STATUS_STORED, $msRun['json']['status'] ?? null);
$msRow = $pdo->query('SELECT * FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
ms_test_same(
    '10l. and it is filed under that recipient with no ownership',
    [$msAddress('unknown99'), null],
    [$msRow['to_address'] ?? null, $msRow['temp_email_id'] ?? null]
);

$msRun = ms_test_cli_php($msProbe, 'python_imap_bridge.php', (string) json_encode(['to_address' => 'nope', 'received_at' => '2026-09-22 09:05:00']), $msEnv);
ms_test_same('10m. an unusable recipient is a service verdict, not a bridge error', StorageResult::STATUS_REJECTED, $msRun['json']['status'] ?? null);
ms_test_same('10n. a service verdict exits 0', 0, $msRun['exit']);

// Bridge errors: the caller has to be able to tell them apart, because exit 2
// means it leaves the source message on the server.
foreach ([
    '10o. a missing to_address is a bridge failure' => (string) json_encode(['received_at' => '2026-09-22 09:05:00']),
    '10p. a missing received_at is a bridge failure' => (string) json_encode(['to_address' => $msAddress($msFreeLocal)]),
    '10q. a malformed received_at is a bridge failure' => (string) json_encode(['to_address' => $msAddress($msFreeLocal), 'received_at' => 'not a timestamp']),
    '10r. stdin that is not JSON is a bridge failure' => 'this is not JSON',
    '10s. empty stdin is a bridge failure' => '',
] as $msLabel => $msStdin) {
    $msRun = ms_test_cli_php($msProbe, 'python_imap_bridge.php', $msStdin, $msEnv);
    ms_test_same($msLabel, 2, $msRun['exit']);
    ms_test_same($msLabel . ' (reported as failed)', StorageResult::STATUS_FAILED, $msRun['json']['status'] ?? null);
}

// ---------------------------------------------------------------------
// 11. The storage boundary, repository-wide (#199)
// ---------------------------------------------------------------------

ms_test_section('11. The storage boundary');

/**
 * Every shipped file that could carry SQL: PHP and Python, minus the service
 * that is allowed to write and the documentation that is allowed to quote it.
 * vendor/ is not shipped code; tests/ is not shipped at all.
 *
 * @return array<string, string> relative path => absolute path, sorted.
 */
$msShippedSources = static function (string $root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (!in_array(strtolower($file->getExtension()), ['php', 'py'], true)) {
            continue;
        }
        foreach (['vendor/', 'EmailStorage/', 'documentaion/', 'tests/'] as $allowed) {
            if (str_starts_with($relative, $allowed)) {
                continue 2;
            }
        }
        $files[$relative] = $file->getPathname();
    }
    ksort($files);
    return $files;
};

$msSources = $msShippedSources($msRepoRoot);
ms_test_check('11a. the audit found files to scan', count($msSources) > 20, 'only ' . count($msSources) . ' shipped source files');

// The two statements the boundary is about. Everything below asserts that they
// exist in the service and nowhere else: the parser's own copy had no caller
// left and was removed by #199, and nothing has reintroduced one since.
foreach (['INSERT INTO stored_emails', 'INSERT INTO email_attachments'] as $msStatement) {
    $msOffenders = [];
    foreach ($msSources as $msRelative => $msPath) {
        if (str_contains((string) file_get_contents($msPath), $msStatement)) {
            $msOffenders[] = $msRelative;
        }
    }
    ms_test_same(
        '11b. "' . $msStatement . '" is written only by the Email Storage service',
        [],
        $msOffenders
    );
}

ms_test_check(
    '11c. and the service is where it is written',
    str_contains((string) file_get_contents($msRepoRoot . '/EmailStorage/EmailStorage.php'), 'INSERT INTO stored_emails')
        && str_contains((string) file_get_contents($msRepoRoot . '/EmailStorage/AttachmentStorage.php'), 'INSERT INTO email_attachments')
);

// The pair #199 retired: the parsers write nothing, so they must not be handed
// the connection they would need to. Matched as a type declaration ("PDO $pdo")
// so a comment may still say the word.
ms_test_check(
    '11d. MailParser takes no PDO',
    !str_contains((string) file_get_contents($msRepoRoot . '/MailParser.php'), 'PDO $')
);
ms_test_check(
    '11e. LegacyImapFallback takes no PDO either',
    !str_contains((string) file_get_contents($msRepoRoot . '/LegacyImapFallback.php'), 'PDO $')
);

// ---------------------------------------------------------------------
// 12. Teardown
// ---------------------------------------------------------------------

ms_test_section('12. Teardown');

ms_test_same(
    '12a. the suite wrote nothing into the repository\'s own attachments/ directory',
    $msRepoAttachmentsBefore,
    ms_test_storage_files($msRepoRoot)
);
ms_test_check('12b. the attachment files it did create live in the docroot', count(ms_test_storage_files($msProbe)) > 0, 'the suite stored no attachment file at all');

ms_test_cleanup($msProbe);
ms_test_check('12c. the throwaway docroot and its attachment files are gone', !is_dir($msProbe));

exit(ms_test_summary());
