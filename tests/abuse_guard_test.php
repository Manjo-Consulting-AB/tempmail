<?php

declare(strict_types=1);

/**
 * Regression coverage for the abuse guard (abuse_guard.php,
 * documentaion/ABUSE_PROTECTION.md).
 *
 * Run with:  php tests/abuse_guard_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 *  A. the library on an in-memory SQLite database, with an explicit clock;
 *  B. the real parse.php run as the pipe subprocess through the storage
 *     probe: counting, quarantine, dropping, the oversize path;
 *  C. the real index.php actions through the probe docroot: the creation
 *     rate limits, the account track, list_personal's quarantine fields and
 *     the sign-out of a suspended account (config.php's own functions are
 *     copied into the probe, not mirrored);
 *  D. the real ImapProcessor::dispatchWebhooks() hourly cap;
 *  E. scans: every page that trusts the session signs out a suspended
 *     account, every login path refuses one.
 */

$msRepoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

require __DIR__ . '/lib/email_storage_harness.php';
require $msRepoRoot . '/abuse_guard.php';

/** SQLite version of the objects migrate_abuse_guard.php creates. */
function ms_ag_schema(bool $withProUsersColumn = true): string {
    $sql = <<<'SQL'
CREATE TABLE abuse_counters (
    scope TEXT NOT NULL,
    subject TEXT NOT NULL,
    window_start TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    bytes INTEGER NOT NULL DEFAULT 0,
    strikes INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject, window_start)
);
CREATE TABLE address_quarantines (
    local_part TEXT PRIMARY KEY,
    temp_email_id INTEGER NOT NULL,
    pro_user_id INTEGER NULL,
    reason TEXT NOT NULL,
    quarantined_at TEXT NOT NULL,
    quarantined_until TEXT NULL,
    forwarder_removed INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE abuse_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    subject TEXT NULL,
    pro_user_id INTEGER NULL,
    detail TEXT NULL,
    notify INTEGER NOT NULL DEFAULT 0,
    notified_at TEXT NULL
);
SQL;
    return $withProUsersColumn ? $sql . "\nALTER TABLE pro_users ADD COLUMN suspended_at TEXT NULL;" : $sql;
}

function ms_ag_events(PDO $pdo, string $kind, ?string $subject = null): array {
    $sql = 'SELECT * FROM abuse_events WHERE kind = ?' . ($subject !== null ? ' AND subject = ?' : '') . ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($subject !== null ? [$kind, $subject] : [$kind]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ms_ag_quarantine(PDO $pdo, string $local): ?array {
    $stmt = $pdo->prepare('SELECT * FROM address_quarantines WHERE local_part = ?');
    $stmt->execute([$local]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// =====================================================================
ms_test_section('A. abuse_guard.php on SQLite');
// =====================================================================

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
$db->exec('CREATE TABLE temp_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_address TEXT NOT NULL, pro_user_id INTEGER NULL, is_personal INTEGER NOT NULL DEFAULT 0, expires_at TEXT NULL)');
$db->exec(ms_ag_schema());
$t0 = strtotime('2026-03-10 12:00:30');

// Settings
$s = abuseGuardSettings([]);
ms_test_same('A1. default quarantine steps', [30, 120, 720], $s['quarantine_steps_minutes']);
ms_test_same('A2. default attachment limits', [10, 50], [$s['max_attachments'], $s['max_inline_images']]);
ms_test_same('A3. steps are read from a comma list', [5, 10], abuseGuardSettings(['quarantine_steps_minutes' => '5, 10'])['quarantine_steps_minutes']);
ms_test_same('A4. nonsense steps fall back to the default', [30, 120, 720], abuseGuardSettings(['quarantine_steps_minutes' => 'x,0'])['quarantine_steps_minutes']);
ms_test_same('A5. a limit never drops below 1', 1, abuseGuardSettings(['address_max_hour' => '-4'])['address_max_hour']);
ms_test_same('A6. enabled by default', true, $s['enabled']);
ms_test_same('A7. can be switched off', false, abuseGuardSettings(['enabled' => false])['enabled']);

// Counters
abuseCounterAdd($db, 'addr', 'box1', 300, 1, 100, 0, $t0);
abuseCounterAdd($db, 'addr', 'box1', 300, 1, 50, 1, $t0 + 10);
ms_test_same('A8. two adds in one bucket are one row', 1, (int)$db->query("SELECT COUNT(*) FROM abuse_counters")->fetchColumn());
ms_test_same('A9. the bucket sums', ['hits' => 2, 'bytes' => 150, 'strikes' => 1], abuseCounterSum($db, 'addr', 'box1', $t0 - 3600));
abuseCounterAdd($db, 'addr', 'box1', 300, 1, 1, 0, $t0 + 300);
ms_test_same('A10. the next 5 minutes are a new bucket', 2, (int)$db->query("SELECT COUNT(*) FROM abuse_counters")->fetchColumn());
ms_test_same('A11. another subject is separate', ['hits' => 0, 'bytes' => 0, 'strikes' => 0], abuseCounterSum($db, 'addr', 'box2', $t0 - 3600));
ms_test_same('A12. purge drops old buckets only', 1, abuseCounterPurge($db, $t0 + 300 - ($t0 + 300) % 300));
$db->exec('DELETE FROM abuse_counters');

// Verdict
$lim = abuseGuardSettings(['address_max_5min' => 10, 'address_max_hour' => 100, 'address_max_bytes_hour' => 1000, 'address_max_strikes_hour' => 4, 'warn_ratio' => 0.5]);
$z = ['hits' => 0, 'bytes' => 0, 'strikes' => 0];
ms_test_same('A13. quiet is ok', 'ok', abuseAddressVerdict($z, $z, $lim)['level']);
ms_test_same('A14. half a limit warns', ['level' => 'warn', 'reason' => 'messages_5min'], abuseAddressVerdict(['hits' => 5] + $z, $z, $lim));
ms_test_same('A15. the 5-minute limit quarantines', ['level' => 'quarantine', 'reason' => 'messages_5min'], abuseAddressVerdict(['hits' => 10] + $z, ['hits' => 10] + $z, $lim));
ms_test_same('A16. bytes per hour quarantine', 'bytes_hour', abuseAddressVerdict($z, ['bytes' => 1000] + $z, $lim)['reason']);
ms_test_same('A17. strikes per hour quarantine', 'strikes_hour', abuseAddressVerdict($z, ['strikes' => 4] + $z, $lim)['reason']);
ms_test_same('A18. a quarantine outranks a warning', 'quarantine', abuseAddressVerdict(['hits' => 6] + $z, ['bytes' => 1000] + $z, $lim)['level']);

// Recording traffic
$levels = [];
for ($i = 0; $i < 10; $i++) {
    $levels[] = abuseAddressRecord($db, 'flood', 10, 0, $t0 + $i, $lim)['level'];
}
ms_test_same('A19. 1-4 ok, 5-9 warn, the 10th quarantines', array_merge(array_fill(0, 4, 'ok'), array_fill(0, 5, 'warn'), ['quarantine']), $levels);
abuseAddressStrike($db, 'x', $t0, $lim);
ms_test_same('A20. a strike on its own counts, with no message', ['hits' => 0, 'bytes' => 0, 'strikes' => 1], abuseCounterSum($db, 'addr', 'x', $t0 - 3600));

// Warn once a day
ms_test_same('A21. the first warning is recorded', true, abuseAddressWarnOnce($db, 'flood', 7, 'messages_5min', $t0));
ms_test_same('A22. a second one inside 24 h is not', false, abuseAddressWarnOnce($db, 'flood', 7, 'messages_5min', $t0 + 3600));
ms_test_same('A23. the warning is queued as a notice', 1, (int)(ms_ag_events($db, 'address_warning', 'flood')[0]['notify'] ?? 0));
ms_test_same('A24. an anonymous address gets no notice', false, abuseAddressWarnOnce($db, 'anon', null, 'x', $t0) && (int)(ms_ag_events($db, 'address_warning', 'anon')[0]['notify'] ?? 1) === 1);

// Quarantine escalation
$steps = abuseGuardSettings(['quarantine_steps_minutes' => '30,120,720']);
$db->exec("INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at) VALUES ('victim', 7, 1, '2076-01-01 00:00:00')");
$victimId = (int)$db->lastInsertId();
$r1 = abuseQuarantineAddress($db, 'victim', $victimId, 7, 'messages_5min', $t0, $steps);
ms_test_same('A25. the first quarantine is 30 minutes', ['closed' => false, 'until' => abuseTime($t0 + 1800), 'minutes' => 30], $r1);
ms_test_check('A26. it is active', abuseQuarantineGet($db, 'victim', $t0 + 60) !== null);
ms_test_same('A27. and over after its time', null, abuseQuarantineGet($db, 'victim', $t0 + 1801));
ms_test_same('A28. the forwarder is not yet counted as removed', 0, (int)ms_ag_quarantine($db, 'victim')['forwarder_removed']);
ms_test_same('A29. the owner is notified', 1, (int)(ms_ag_events($db, 'address_quarantined', 'victim')[0]['notify'] ?? 0));
ms_test_same('A30. the second inside 24 h is 2 hours', 120, abuseQuarantineAddress($db, 'victim', $victimId, 7, 'x', $t0 + 3600, $steps)['minutes']);
ms_test_same('A31. the third is 12 hours', 720, abuseQuarantineAddress($db, 'victim', $victimId, 7, 'x', $t0 + 3 * 3600, $steps)['minutes']);
$r4 = abuseQuarantineAddress($db, 'victim', $victimId, 7, 'x', $t0 + 16 * 3600, $steps);
ms_test_same('A32. the fourth closes the address', ['closed' => true, 'until' => null, 'minutes' => null], $r4);
ms_test_same('A33. a close is its own notice', 1, count(ms_ag_events($db, 'address_closed', 'victim')));
ms_test_check('A34. a closed address stays quarantined', abuseQuarantineGet($db, 'victim', $t0 + 400 * 86400) !== null);
ms_test_same('A35. a later quarantine never reopens a closed one', null, abuseQuarantineAddress($db, 'victim', $victimId, 7, 'x', $t0 + 3 * 86400, $steps)['until']);

// A flood crosses the limit in many pipe processes at once: only the first
// trigger counts as a step.
$db->exec("INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at) VALUES ('burst', 7, 1, '2076-01-01 00:00:00')");
$burstId = (int)$db->lastInsertId();
$burst = [];
for ($i = 0; $i < 5; $i++) {
    $burst[] = abuseQuarantineAddress($db, 'burst', $burstId, 7, 'messages_5min', $t0 + $i, $steps)['until'];
}
ms_test_same('A35b. repeated triggers during one quarantine keep its end', array_fill(0, 5, abuseTime($t0 + 1800)), $burst);
ms_test_same('A35c. and count as one step', 1, count(ms_ag_events($db, 'address_quarantined', 'burst')));
$after = abuseQuarantineAddress($db, 'burst', $burstId, 7, 'messages_5min', $t0 + 1900, $steps);
ms_test_same('A35d. once it has run out, the next trigger is the second step', 120, $after['minutes']);
abuseQuarantineForget($db, 'burst');

// Never shortened
$db->exec("INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at) VALUES ('long', 7, 1, '2076-01-01 00:00:00')");
$longId = (int)$db->lastInsertId();
abuseQuarantineAddress($db, 'long', $longId, 7, 'hold', $t0, $steps, 600);
$short = abuseQuarantineAddress($db, 'long', $longId, 7, 'messages_5min', $t0 + 60, $steps);
ms_test_same('A36. a trigger during a hold keeps the hold', abuseTime($t0 + 36000), $short['until']);
$shorterHold = abuseQuarantineAddress($db, 'long', $longId, 7, 'hold', $t0 + 120, $steps, 5);
ms_test_same('A36b. a shorter hold never shortens a longer one', abuseTime($t0 + 36000), $shorterHold['until']);
ms_test_same('A37. a hold is not one of the address\' own quarantines', 0, count(ms_ag_events($db, 'address_quarantined', 'long')));
ms_test_same('A38. a hold sends no notice', 0, (int)(ms_ag_events($db, 'address_hold', 'long')[0]['notify'] ?? 1));

// Forwarder removal
$calls = [];
$ok = static function (string $a) use (&$calls): bool { $calls[] = 'rm:' . $a; return true; };
$fail = static function (string $a) use (&$calls): bool { $calls[] = 'fail:' . $a; return false; };
ms_test_same('A39. a failed removal reports false', false, abuseRemoveForwarder($db, 'long', $fail));
ms_test_same('A40. and leaves forwarder_removed at 0', 0, (int)ms_ag_quarantine($db, 'long')['forwarder_removed']);
$retry = abuseRetryForwarderRemovals($db, $ok, $t0 + 120);
ms_test_same('A41. the retry removes every pending active forwarder', ['removed' => 2, 'failed' => 0], $retry);
ms_test_same('A42. and records it', 1, (int)ms_ag_quarantine($db, 'long')['forwarder_removed']);
ms_test_same('A43. nothing left to retry', ['removed' => 0, 'failed' => 0], abuseRetryForwarderRemovals($db, $ok, $t0 + 180));

// Release
$db->exec("INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at) VALUES ('back', 7, 1, '2076-01-01 00:00:00')");
$backId = (int)$db->lastInsertId();
abuseQuarantineAddress($db, 'back', $backId, 7, 'x', $t0, $steps);
abuseRemoveForwarder($db, 'back', $ok);
$calls = [];
ms_test_same('A44. nothing is released early', ['released' => 0, 'dropped' => 0, 'failed' => 0], abuseReleaseDue($db, $ok, $t0 + 60));
ms_test_same('A45. a failed re-creation keeps the quarantine', ['released' => 0, 'dropped' => 0, 'failed' => 2], abuseReleaseDue($db, $fail, $t0 + 86400));
ms_test_check('A46. (still there)', ms_ag_quarantine($db, 'back') !== null);
ms_test_same('A47. the next run gives the forwarder back', ['released' => 2, 'dropped' => 0, 'failed' => 0], abuseReleaseDue($db, $ok, $t0 + 86400 + 60));
ms_test_same('A48. the release recreated the forwarders', ['fail:long', 'fail:back', 'rm:long', 'rm:back'], $calls);
ms_test_same('A49. the row is gone', null, ms_ag_quarantine($db, 'back'));
ms_test_same('A50. a closed address is never released by time', true, ms_ag_quarantine($db, 'victim') !== null);

// A deleted or expired address is dropped, never re-created.
$db->exec("INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at) VALUES ('gone', NULL, 0, '2026-03-10 13:00:00')");
$goneId = (int)$db->lastInsertId();
abuseQuarantineAddress($db, 'gone', $goneId, null, 'x', $t0, $steps);
abuseRemoveForwarder($db, 'gone', $ok);
$calls = [];
ms_test_same('A51. an expired address is dropped', ['released' => 0, 'dropped' => 1, 'failed' => 0], abuseReleaseDue($db, $ok, $t0 + 86400 * 2));
ms_test_same('A52. without a DirectAdmin call', [], $calls);
ms_test_same('A53. an anonymous quarantine queues no notice', 0, (int)(ms_ag_events($db, 'address_quarantined', 'gone')[0]['notify'] ?? 1));

// Forget (the address is being deleted)
ms_test_same('A54. forgetting an unknown address', false, abuseQuarantineForget($db, 'nobody'));
abuseQuarantineAddress($db, 'forgetme', 99, 7, 'x', $t0, $steps);
ms_test_same('A55a. forgetting one whose forwarder is still there says so', false, abuseQuarantineForget($db, 'forgetme'));
abuseQuarantineAddress($db, 'forgetme', 99, 7, 'x', $t0, $steps);
abuseRemoveForwarder($db, 'forgetme', $ok);
ms_test_same('A55b. forgetting one whose forwarder is gone says so', true, abuseQuarantineForget($db, 'forgetme'));
ms_test_same('A56. and drops the row', null, ms_ag_quarantine($db, 'forgetme'));

// Attachments
$att = [];
for ($i = 0; $i < 12; $i++) $att[] = ['filename' => "f{$i}.pdf", 'mime_type' => 'application/pdf', 'content_id' => null, 'data' => 'x'];
for ($i = 0; $i < 60; $i++) $att[] = ['filename' => "i{$i}.png", 'mime_type' => 'image/png', 'content_id' => "c{$i}", 'data' => 'x'];
$att[] = ['filename' => 'logo.pdf', 'mime_type' => 'application/pdf', 'content_id' => 'c-pdf', 'data' => 'x'];
[$kept, $dropR, $dropI] = abuseLimitAttachments($att, 10, 50);
ms_test_same('A57. 10 attachments and 50 inline images kept', 60, count($kept));
ms_test_same('A58. counts of what was dropped', [3, 10], [$dropR, $dropI]);
ms_test_same('A59. a Content-ID on a non-image is a real attachment', 'f9.pdf', $kept[9]['filename']);
ms_test_same('A60. message order kept', 'i0.png', $kept[10]['filename']);
ms_test_same('A61. under the limits nothing changes', [array_slice($att, 0, 3), 0, 0], abuseLimitAttachments(array_slice($att, 0, 3), 10, 50));

// Rate limits
$rules = [['gen_ip', '203.0.113.9', 3, 3600], ['gen_ip', '203.0.113.9', 5, 86400]];
$hour = strtotime('2026-03-10 09:00:10');
$res = [];
for ($i = 0; $i < 5; $i++) $res[] = abuseRateLimit($db, $rules, $hour + $i);
ms_test_same('A62. three per hour pass, the fourth is refused', [false, false, false, true, true], array_column($res, 'limited'));
ms_test_same('A63. only the first refusal of the hour is "first"', [false, false, false, true, false], array_column($res, 'first'));
ms_test_same('A64. the refusal names the rule', 3600, $res[3]['rule'][3]);
ms_test_same('A65. a refused attempt is not a hit', 3, abuseCounterSum($db, 'gen_ip', '203.0.113.9', $hour - 3600)['hits']);
ms_test_same('A66. a new hour passes again', false, abuseRateLimit($db, $rules, $hour + 3600)['limited']);
ms_test_same('A67. the daily limit holds across hours', [false, true], [abuseRateLimit($db, $rules, $hour + 7200)['limited'], abuseRateLimit($db, $rules, $hour + 7201)['limited']]);
ms_test_same('A68. and is its own "first"', true, abuseRateLimit($db, $rules, $hour + 10800)['first']);

// Account track
$acc = abuseGuardSettings(['account_strikes_day' => 3, 'account_quarantine_minutes' => 60, 'account_quarantines_week' => 2]);
$db->exec("INSERT INTO pro_users (id, email) VALUES (42, 'x')");
$db->exec("INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at) VALUES ('acc.one', 42, 1, '2076-01-01 00:00:00'), ('acc2two', 42, 0, '2076-01-01 00:00:00')");
$day1 = strtotime('2026-04-01 10:00:00');
ms_test_same('A69. the first strike warns', 'warned', abuseAccountStrike($db, 42, 'gen_user/86400', $day1, $acc));
ms_test_same('A70. the warning is a notice', 1, (int)(ms_ag_events($db, 'account_warning')[0]['notify'] ?? 0));
ms_test_same('A71. the second is only counted', 'strike', abuseAccountStrike($db, 42, 'gen_user/86400', $day1 + 3600, $acc));
ms_test_same('A72. the third quarantines every address of the account', 'quarantined', abuseAccountStrike($db, 42, 'gen_user/86400', $day1 + 7200, $acc));
ms_test_same('A73. both addresses held', 2, (int)$db->query("SELECT COUNT(*) FROM address_quarantines WHERE pro_user_id = 42")->fetchColumn());
ms_test_same('A74. for account_quarantine_minutes', abuseTime($day1 + 7200 + 3600), ms_ag_quarantine($db, 'acc.one')['quarantined_until']);
ms_test_same('A75. one account-level notice, none per address', [1, 0], [count(ms_ag_events($db, 'account_quarantined')), count(ms_ag_events($db, 'address_quarantined', 'acc.one'))]);
ms_test_same('A76. no second account quarantine inside 24 h', 'strike', abuseAccountStrike($db, 42, 'gen_user/86400', $day1 + 9000, $acc));
ms_test_same('A77. no proposal yet', null, abuseSuspensionPending($db, 42));
$day3 = $day1 + 2 * 86400;
abuseAccountStrike($db, 42, 'gen_user/86400', $day3, $acc);
abuseAccountStrike($db, 42, 'gen_user/86400', $day3 + 60, $acc);
ms_test_same('A78. a second account quarantine in a week proposes a suspension', 'proposed', abuseAccountStrike($db, 42, 'gen_user/86400', $day3 + 120, $acc));
$pending = abuseSuspensionPending($db, 42);
ms_test_check('A79. the proposal is open', $pending !== null);
ms_test_same('A80. it is listed', [42], array_column(abuseSuspensionProposals($db), 'user_id'));
ms_test_same('A81. the account is NOT suspended by the guard itself', false, abuseAccountSuspended($db, 42));
abuseDismissProposal($db, 42, 1, $day3 + 200);
ms_test_same('A82. a dismissal closes the proposal', null, abuseSuspensionPending($db, 42));
ms_test_same('A83. and empties the list', [], abuseSuspensionProposals($db));

// Suspension by an admin
$closed = abuseSuspendAccount($db, 42, 1, $day3 + 300, $acc);
ms_test_same('A84. every address of the account is closed', 2, $closed);
ms_test_same('A85. closed means no end time', null, ms_ag_quarantine($db, 'acc2two')['quarantined_until']);
ms_test_same('A86. the account is suspended', true, abuseAccountSuspended($db, 42));
abuseRetryForwarderRemovals($db, $ok, $day3 + 301);
abuseUnsuspendAccount($db, 42, 1, $day3 + 400);
ms_test_same('A87. lifting it clears suspended_at', false, abuseAccountSuspended($db, 42));
$calls = [];
ms_test_same('A88. and makes every quarantine of the account due', ['released' => 2, 'dropped' => 0, 'failed' => 0], abuseReleaseDue($db, $ok, $day3 + 400, 42));
ms_test_same('A89. which gives the forwarders back', ['rm:acc.one', 'rm:acc2two'], $calls);

// Notices
foreach (['address_warning', 'address_quarantined', 'address_closed', 'account_warning', 'account_quarantined', 'webhook_capped'] as $kind) {
    $text = abuseNoticeText($kind, 'box@manjo.me', ['until' => '2026-01-01 10:00:00', 'minutes' => 30, 'name' => 'Hook', 'limit' => 60], 'https://manjo.me/');
    ms_test_check("A90. {$kind} has a subject and a body", is_array($text) && $text['subject'] !== '' && $text['body'] !== '');
}
ms_test_same('A91. a proposal mails nobody but admins', null, abuseNoticeText('account_suspend_proposed', null, [], 'https://manjo.me/'));
ms_test_check('A92. the admin mail links the admin page', str_contains((string)(abuseNoticeText('account_suspend_proposed', null, ['user_id' => 42], 'https://manjo.me/', true)['body'] ?? ''), 'https://manjo.me/abuse_admin.php'));
ms_test_same('A93. an internal event mails nobody', null, abuseNoticeText('address_hold', 'x@y', [], 'https://manjo.me/'));
ms_test_same('A94. events purge by age', count($db->query('SELECT id FROM abuse_events')->fetchAll()), abuseEventPurge($db, $t0 + 400 * 86400));

// =====================================================================
ms_test_section('B. parse.php through the storage probe');
// =====================================================================

$msProbe = ms_test_storage_probe_build($msRepoRoot);
$pdo = ms_test_storage_db(ms_test_storage_sqlite($msProbe));
$pdo->exec(ms_ag_schema());
file_put_contents($msProbe . '/config.php', <<<'PHP'

$config['abuse'] = ['address_max_5min' => 1000, 'address_max_hour' => 3, 'address_max_bytes_hour' => 1000000000, 'warn_ratio' => 0.5];
$config['email']['max_message_bytes'] = 4096;

/** Records the call instead of talking to DirectAdmin. */
function directAdminRemoveForwarder(string $alias): bool {
    file_put_contents(__DIR__ . '/da_calls.log', 'remove ' . $alias . "\n", FILE_APPEND);
    return true;
}
PHP, FILE_APPEND);

$msEnv = ms_test_storage_env($msProbe);
$msEml = static function (string $local, string $subject, int $pad = 0): string {
    return implode("\r\n", [
        'Date: Wed, 23 Sep 2026 10:00:00 +0200',
        'From: Sender <sender@example.com>',
        'To: <' . $local . '@manjo.me>',
        'Subject: ' . $subject,
        'Message-ID: <ag-' . md5($subject . microtime()) . '@example.com>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Body ' . str_repeat('a', $pad),
        '',
    ]);
};
$owner = ms_test_seed_user($pdo, 'owner@example.com');
ms_test_seed_address($pdo, 'floodbox', ['pro_user_id' => $owner, 'is_personal' => 1]);
ms_test_seed_address($pdo, 'otherbox', ['pro_user_id' => $owner, 'is_personal' => 1]);
$stored = static fn(string $local): int => ms_test_count($pdo, 'stored_emails', 'to_address = ?', [$local . '@manjo.me']);

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('floodbox', 'one'), $msEnv + ['LOCAL_PART' => 'floodbox']);
ms_test_same('B1. the first message is stored', [0, 1], [$run['exit'], $stored('floodbox')]);
ms_test_same('B2. and prints nothing', '', $run['stdout'] . $run['stderr']);
ms_test_same('B3. it was counted', 1, abuseCounterSum($pdo, 'addr', 'floodbox', time() - 3600)['hits']);
ms_test_same('B4. half the hourly limit (2 of 3 at 0.5) is not reached yet', 0, count(ms_ag_events($pdo, 'address_warning', 'floodbox')));

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('floodbox', 'two'), $msEnv + ['LOCAL_PART' => 'floodbox']);
ms_test_same('B5. the second is stored', [0, 2], [$run['exit'], $stored('floodbox')]);
ms_test_same('B6. and warns the owner', 1, count(ms_ag_events($pdo, 'address_warning', 'floodbox')));

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('floodbox', 'three'), $msEnv + ['LOCAL_PART' => 'floodbox']);
ms_test_same('B7. the third reaches the limit: accepted (exit 0), not stored', [0, 2], [$run['exit'], $stored('floodbox')]);
ms_test_same('B8. silently', '', $run['stdout'] . $run['stderr']);
$q = ms_ag_quarantine($pdo, 'floodbox');
ms_test_check('B9. the address is quarantined', $q !== null && $q['quarantined_until'] !== null);
ms_test_same('B10. its forwarder was removed', 1, (int)($q['forwarder_removed'] ?? 0));
ms_test_same('B11. through directAdminRemoveForwarder()', "remove floodbox\n", (string)@file_get_contents($msProbe . '/da_calls.log'));
ms_test_same('B12. the owner will be told', 1, (int)(ms_ag_events($pdo, 'address_quarantined', 'floodbox')[0]['notify'] ?? 0));

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('floodbox', 'four'), $msEnv + ['LOCAL_PART' => 'floodbox']);
ms_test_same('B13. what still arrives is dropped (exit 0)', [0, 2], [$run['exit'], $stored('floodbox')]);
ms_test_same('B14. but still counted', 4, abuseCounterSum($pdo, 'addr', 'floodbox', time() - 3600)['hits']);
ms_test_same('B15. no second quarantine for it', 1, count(ms_ag_events($pdo, 'address_quarantined', 'floodbox')));

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('otherbox', 'fine'), $msEnv + ['LOCAL_PART' => 'otherbox']);
ms_test_same('B16. another address of the same account is untouched', [0, 1], [$run['exit'], $stored('otherbox')]);

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('otherbox', 'big', 5000), $msEnv + ['LOCAL_PART' => 'otherbox']);
ms_test_same('B17. an oversize message is still a permanent rejection', 1, $run['exit']);
ms_test_check('B18. with the size reason', str_contains($run['stderr'], 'exceeds the maximum size'));
$sum = abuseCounterSum($pdo, 'addr', 'otherbox', time() - 3600);
ms_test_check('B19. and its bytes were counted against the address', $sum['hits'] === 2 && $sum['bytes'] > 5000);

$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('nosuchbox', 'x'), $msEnv + ['LOCAL_PART' => 'nosuchbox']);
ms_test_same('B20. an unknown address is rejected as before', 1, $run['exit']);
ms_test_same('B21. and never counted', 0, abuseCounterSum($pdo, 'addr', 'nosuchbox', time() - 3600)['hits']);

// Without the tables the pipe behaves exactly as before.
$pdo->exec('ALTER TABLE abuse_counters RENAME TO abuse_counters_hidden');
$run = ms_test_cli_php($msProbe, 'parse.php', $msEml('otherbox', 'no guard'), $msEnv + ['LOCAL_PART' => 'otherbox']);
ms_test_same('B22. no abuse tables: stored as usual', [0, 2], [$run['exit'], $stored('otherbox')]);
$pdo->exec('ALTER TABLE abuse_counters_hidden RENAME TO abuse_counters');

ms_test_cleanup($msProbe);

// =====================================================================
ms_test_section('C. index.php actions through the probe docroot');
// =====================================================================

$msProbe = ms_test_probe_build($msRepoRoot);
$msSqlite = ms_test_probe_sqlite($msProbe);

// config.php's own suspension functions, copied rather than mirrored.
$configSrc = (string)file_get_contents($msRepoRoot . '/config.php');
$from = strpos($configSrc, "/**\n * Is the account suspended");
$to = strpos($configSrc, "/**\n * Spara en ny temporär e-postadress");
ms_test_check('C0. found the suspension functions in config.php', $from !== false && $to !== false && $to > $from);
file_put_contents($msProbe . '/config.php', "\n" . substr($configSrc, (int)$from, (int)$to - (int)$from) . <<<'PHP'

$config['abuse'] = ['generate_ip_hour' => 100, 'generate_ip_day' => 2, 'generate_user_day' => 100, 'personal_user_day' => 1, 'account_strikes_day' => 3];

function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = sanitizeString($local, $maxLength, true);
    if ($s === null || strlen($s) < $minLength || !preg_match('/^[a-zA-Z0-9._-]+$/', $s)) return null;
    return strtolower($s);
}

function saveNewAddress($address, $proUserId = null, $isPersonal = 0) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO temp_emails (unique_address, pro_user_id, is_personal, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$address, $proUserId, $isPersonal ? 1 : 0, $GLOBALS['__custom_expires_at'] ?? date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s')]);
}
PHP, FILE_APPEND);
$GLOBALS['MS_TEST_SQLITE'] = $msSqlite;
require $msProbe . '/config.php';
$pdo = ms_test_db($msSqlite);
$pdo->exec(ms_ag_schema());

$act = static function (string $action, array $fields, int $userId, string $session = 'agprobe') use ($msProbe): array {
    return ms_test_request($msProbe, [
        'page' => 'index.php',
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => $userId > 0 ? 'user' . $userId . '@example.com' : '',
        'post' => array_merge(['action' => $action], $fields),
        'session' => $session,
    ]);
};

$j = [];
for ($i = 0; $i < 3; $i++) {
    $r = $act('generate', [], 0);
    ms_test_no_php_errors('C1.' . $i . ' anonymous generate raises no PHP error', $r);
    $j[] = $r['json'] ?? [];
}
ms_test_same('C2. two per day per IP pass, the third is refused', [true, true, false], array_map(static fn($x) => $x['success'] ?? null, $j));
ms_test_same('C3. the refusal says why', true, $j[2]['rate_limited'] ?? null);
ms_test_same('C4. only the two that passed made an address', 2, ms_test_count($pdo, 'temp_emails', 'pro_user_id IS NULL'));
ms_test_same('C5. the refusal is a strike on the IP bucket', 1, abuseCounterSum($pdo, 'gen_ip', '127.0.0.1', time() - 86400)['strikes']);

$alice = ms_test_seed_user($pdo, 'alice@example.com');
$r = $act('create_personal', ['local' => 'alice.one'], $alice);
ms_test_same('C6. the first sticky address of the day is created', true, $r['json']['success'] ?? null);
$r = $act('create_personal', ['local' => 'alice.two'], $alice);
ms_test_no_php_errors('C7. a refused create_personal raises no PHP error', $r);
ms_test_same('C8. the second is refused', [false, true], [$r['json']['success'] ?? null, $r['json']['rate_limited'] ?? null]);
ms_test_same('C9. and was not created', 0, ms_test_count($pdo, 'temp_emails', 'unique_address = ?', ['alice.two']));
ms_test_same('C10. the account got a strike and a warning', [1, 1], [count(ms_ag_events($pdo, 'account_strike')), count(ms_ag_events($pdo, 'account_warning'))]);
$act('create_personal', ['local' => 'alice.three'], $alice);
ms_test_same('C11. a second refusal in the same hour is not a second strike', 1, count(ms_ag_events($pdo, 'account_strike')));

// list_personal shows the quarantine
$aliceOne = (int)$pdo->query("SELECT id FROM temp_emails WHERE unique_address = 'alice.one'")->fetchColumn();
abuseQuarantineAddress($pdo, 'alice.one', $aliceOne, $alice, 'messages_hour', time(), abuseGuardSettings([]));
$r = $act('list_personal', [], $alice);
$row = $r['json']['personal'][0] ?? [];
ms_test_check('C12. list_personal carries paused_until', !empty($row['paused_until']));
ms_test_same('C13. and closed = false', false, $row['closed'] ?? null);

// A suspended account is signed out by its next request.
$pdo->prepare('UPDATE pro_users SET suspended_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $alice]);
$r = $act('list_personal', [], $alice, 'agprobe-alice');
ms_test_no_php_errors('C14. a suspended session raises no PHP error', $r);
ms_test_same('C15. a suspended account is signed out', 'Not authenticated', $r['json']['error'] ?? null);
$pdo->prepare('UPDATE pro_users SET suspended_at = NULL WHERE id = ?')->execute([$alice]);
$r = $act('list_personal', [], $alice, 'agprobe-alice2');
ms_test_same('C16. after the suspension is lifted it works again', true, $r['json']['success'] ?? null);

// deleteDirectAdminForwarder() lives in config.php; its quarantine branch is
// scanned in E. Here: the real delete_personal still works on a quarantined row.
$r = $act('delete_personal', ['id' => $aliceOne], $alice, 'agprobe-alice3');
ms_test_same('C17. a quarantined address can be deleted by its owner', true, $r['json']['success'] ?? null);

// =====================================================================
ms_test_section('D. the webhook hourly cap in dispatchWebhooks()');
// =====================================================================

require_once $msRepoRoot . '/php_imap_processor.php';
$pdo = ms_test_refresh_db($msSqlite);
$pdo->exec(ms_test_webhook_address_schema());
$GLOBALS['config']['abuse'] = ['webhook_max_hour' => 2];
$bob = ms_test_seed_user($pdo, 'bob@example.com');
$bobAddr = ms_test_seed_address($pdo, 'bob.box', ['pro_user_id' => $bob, 'is_personal' => 1]);
$hook = ms_test_seed_webhook($pdo, $bob, 'generic', 'all', 'Bob hook');
$pdo->prepare('INSERT INTO pro_webhook_addresses (webhook_id, temp_email_id) VALUES (?, ?)')->execute([$hook, $bobAddr]);
for ($i = 0; $i < 4; $i++) {
    ms_test_dispatch($bob, ['to' => 'bob.box@manjo.me', 'subject' => 'n' . $i]);
}
ms_test_same('D1. two deliveries per hour are queued, the rest skipped', [$hook => 2], ms_test_deliveries($pdo, $bob));
$capped = ms_ag_events($pdo, 'webhook_capped', 'hook:' . $hook);
ms_test_same('D2. the user is told once', [1, 1], [count($capped), (int)($capped[0]['notify'] ?? 0)]);

ms_test_cleanup($msProbe);

// =====================================================================
ms_test_section('E. scans');
// =====================================================================

foreach (['index.php', 'inbox.php', 'pro.php', 'pro_profile.php', 'pro_profile_page.php', 'pro_contact.php', 'partials/nav.php',
          'client_agent_api.php', 'client_agent_manage.php', 'client_agent_download.php', 'client_agent_install_download.php'] as $file) {
    $src = (string)file_get_contents($msRepoRoot . '/' . $file);
    $start = strpos($src, 'session_start()');
    $call = strpos($src, 'proSessionEndIfSuspended()');
    ms_test_check("E1. {$file} signs a suspended account out right after session_start()", $start !== false && $call !== false && $call > $start && $call - $start < 400);
}
$auth = (string)file_get_contents($msRepoRoot . '/pro_auth.php');
ms_test_same('E2. pro_auth.php refuses a suspended account on magic link, password and 2FA', 3, substr_count($auth, 'proUserIsSuspended('));
ms_test_same('E3. pro_feed.php stops both feeds of a suspended account', 2, substr_count((string)file_get_contents($msRepoRoot . '/pro_feed.php'), 'proUserIsSuspended('));
$config = (string)file_get_contents($msRepoRoot . '/config.php');
ms_test_check('E4. deleteDirectAdminForwarder() ends a quarantine before calling DirectAdmin', (bool)preg_match('/function deleteDirectAdminForwarder\(.*?abuseQuarantineForget\(.*?directAdminRemoveForwarder\(/s', $config));
$parse = (string)file_get_contents($msRepoRoot . '/parse.php');
ms_test_check('E5. parse.php counts before it rejects an oversize message', strpos($parse, 'abuseAddressRecord(') !== false && strpos($parse, 'abuseAddressRecord(') < strpos($parse, "parseReject('Message exceeds"));
ms_test_check('E6. parse.php limits attachments before building the DTO', strpos($parse, 'abuseLimitAttachments(') !== false && strpos($parse, 'abuseLimitAttachments(') < strpos($parse, 'EmailAttachment::fromArray('));

exit(ms_test_summary());
