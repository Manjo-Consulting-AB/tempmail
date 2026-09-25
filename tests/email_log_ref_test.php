<?php

declare(strict_types=1);

/**
 * Regression coverage for keeping users' email addresses out of logs:
 * email_log_ref.php (the keyed log reference), the per-account password
 * login limit in pro_auth.php that now counts that reference, the one-time
 * scrub_logged_emails.php, and a repository scan of the logging call sites.
 *
 * Run with:  php tests/email_log_ref_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. SQLite only (in memory, or a
 * throwaway file for the pro_auth.php subprocess): no MySQL, no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SCRUB_LOGGED_EMAILS_LIBRARY', true);
require __DIR__ . '/../email_log_ref.php';
require __DIR__ . '/../pro_trial.php';
require __DIR__ . '/../scrub_logged_emails.php';

// Accounts are looked up by the email_hash blind index (pii_crypto.php).
$piiEncKey = str_repeat('e', 32);
$piiIdxKey = str_repeat('i', 32);
putenv('PII_ENCRYPTION_KEY=' . $piiEncKey);
putenv('PII_INDEX_KEY=' . $piiIdxKey);

$repoRoot = dirname(__DIR__);
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

$key = str_repeat('k', 32);
$otherKey = str_repeat('q', 32);

// ---------------------------------------------------------------------------
// 1. emailLogRef() / emailLogContext()
// ---------------------------------------------------------------------------

$ref = emailLogRef('alice@example.com', $key);
check('R1. a reference is 16 lowercase hex characters', is_string($ref) && preg_match('/^[0-9a-f]{16}$/', $ref) === 1, var_export($ref, true));
same('R2. stable: the same address and key give the same reference', $ref, emailLogRef('alice@example.com', $key));
same('R3. case and surrounding whitespace are normalised', $ref, emailLogRef("  Alice@EXAMPLE.com \n", $key));
check('R4. a different address gives a different reference', $ref !== emailLogRef('bob@example.com', $key));
check('R5. keyed: a different key gives a different reference', $ref !== emailLogRef('alice@example.com', $otherKey));
check('R6. not an unkeyed hash of the address',
    $ref !== substr(hash('sha256', 'alice@example.com'), 0, 16)
    && $ref !== substr(hash_hmac('sha256', 'alice@example.com', ''), 0, 16));
check('R7. not a prefix of the pro_trial_claims hash under the same key',
    $ref !== substr((string) proTrialEmailHash('alice@example.com', $key), 0, 16));
check('R8. +tags and dots are NOT merged (distinct accounts keep distinct references)',
    emailLogRef('a.lice+x@example.com', $key) !== $ref);
same('R9. no key: no reference', null, emailLogRef('alice@example.com', ''));
same('R10. a key shorter than 32 characters: no reference', null, emailLogRef('alice@example.com', str_repeat('k', 31)));
same('R11. an empty address: no reference', null, emailLogRef('   ', $key));

unset($GLOBALS['config']);
same('R12. no configured key: no reference', null, emailLogRef('alice@example.com'));
$GLOBALS['config'] = ['trial' => ['hash_key' => $key]];
same('R13. the configured PRO_TRIAL_HASH_KEY is the default key', $ref, emailLogRef('alice@example.com'));

same('C1. a known user id wins over the reference', ['user_id' => 7], emailLogContext('alice@example.com', 7));
same('C2. no user id: the reference', ['email_ref' => $ref], emailLogContext('alice@example.com'));
same('C3. the reference field can be named', ['new_email_ref' => $ref], emailLogContext('alice@example.com', null, 'new_email_ref'));
same('C4. user id 0 is not a user id', ['email_ref' => $ref], emailLogContext('alice@example.com', 0));
$GLOBALS['config'] = ['trial' => ['hash_key' => '']];
same('C5. no key and no user id: nothing at all, never the address', [], emailLogContext('alice@example.com'));

// ---------------------------------------------------------------------------
// 2. scrub_logged_emails.php on SQLite
// ---------------------------------------------------------------------------

function scrubDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, email_enc TEXT NULL, email_hash TEXT NULL)');
    $pdo->exec('CREATE TABLE system_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, log_level TEXT, message TEXT, context TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, email TEXT, user_id INTEGER, success INTEGER, attempt_at TEXT)');
    $users = $pdo->prepare('INSERT INTO pro_users (id, email, email_enc, email_hash) VALUES (?, ?, ?, ?)');
    foreach ([[5, 'Known@Example.com'], [6, 'other@example.org']] as [$id, $address]) {
        $users->execute([$id, $address, piiEmailEncrypt($address), piiEmailHash($address)]);
    }
    $logs = [
        // 1: a registered user's address under 'to', no user_id yet.
        ['INFO', 'Magic link sent', ['to' => 'known@example.com']],
        // 2: unknown address under 'email'.
        ['INFO', 'Magic link requested for unknown user', ['email' => 'stranger@example.net']],
        // 3: user_id already there.
        ['INFO', 'Inactive regular account warned', ['user_id' => 5, 'email' => 'known@example.com']],
        // 4: inbound mail to a temp address - must stay.
        ['INFO', 'parse.php: saved incoming email', ['email_id' => 9, 'to' => 'abcdef1234@manjo.me']],
        // 5: nested payload plus an address embedded in an error string (the shape
        // of a legacy payment-webhook row, which still names the payer supporter_email).
        ['WARNING', 'Payment webhook missing or invalid email', ['data' => ['supporter_email' => 'payer@example.net', 'items' => ['x@example.net']], 'error' => "Duplicate entry 'dup@example.net' for key 'email'"]],
        // 6: address interpolated into the message text, next to a service address.
        ['ERROR', 'Failed to send magic link to stranger@example.net from noreply@manjo.me', null],
        // 7: new_email of a pending change.
        ['INFO', 'Pending email change created', ['user_id' => 6, 'new_email' => 'fresh@example.com']],
        // 8: nothing to do.
        ['INFO', 'Pro user logged in via password', ['user_id' => 5]],
        // 9: service subdomain address.
        ['INFO', 'Something', ['from' => 'bounce@mail.manjo.me']],
    ];
    $ins = $pdo->prepare('INSERT INTO system_logs (log_level, message, context) VALUES (?, ?, ?)');
    foreach ($logs as [$level, $message, $context]) {
        $ins->execute([$level, $message, $context === null ? null : json_encode($context)]);
    }
    $pdo->exec("INSERT INTO login_attempts (ip, email, success) VALUES ('1.1.1.1', 'Known@Example.com', 0), ('1.1.1.2', 'stranger@example.net', 0)");
    $pdo->exec("INSERT INTO login_attempts (ip, email, success) VALUES ('1.1.1.3', 'abcdef0123456789', 0)");
    return $pdo;
}

function logRow(PDO $pdo, int $id): array
{
    $row = $pdo->query('SELECT message, context FROM system_logs WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    return ['message' => (string) $row['message'], 'context' => $row['context'] === null ? null : json_decode((string) $row['context'], true)];
}

function dumpAll(PDO $pdo): string
{
    $out = '';
    foreach ($pdo->query('SELECT message, context FROM system_logs')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out .= $r['message'] . ' ' . $r['context'] . "\n";
    }
    foreach ($pdo->query('SELECT email FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN) as $e) {
        $out .= $e . "\n";
    }
    return $out;
}

$userAddresses = ['known@example.com', 'stranger@example.net', 'payer@example.net', 'x@example.net', 'dup@example.net', 'fresh@example.com'];
$domains = ['manjo.me'];

// Dry run writes nothing.
$pdo = scrubDb();
$before = dumpAll($pdo);
$dry = new EmailLogScrubber($pdo, $key, $domains);
$dry->scrubSystemLogs(true, 3);
$dry->scrubLoginAttempts(true, 1);
same('S1. a dry run writes nothing', $before, dumpAll($pdo));
same('S2. a dry run counts the rows it would change', 6, $dry->stats['log_rows_changed']);
same('S3. a dry run counts the plaintext login_attempts rows', 2, $dry->stats['login_attempts_scanned']);

// Real run with a key (small batches to exercise paging).
$scrubber = new EmailLogScrubber($pdo, $key, $domains);
$scrubber->scrubSystemLogs(false, 2);
$scrubber->scrubLoginAttempts(false, 1);
$after = dumpAll($pdo);
$leaked = array_values(array_filter($userAddresses, static fn($a) => stripos($after, $a) !== false));
same('S4. no user address is left anywhere', [], $leaked);

same('S5. a registered address becomes its user_id', ['user_id' => 5], logRow($pdo, 1)['context']);
same('S6. an unknown address becomes the keyed reference', ['email_ref' => emailLogRef('stranger@example.net', $key)], logRow($pdo, 2)['context']);
same('S7. an existing matching user_id is kept, the address dropped', ['user_id' => 5], logRow($pdo, 3)['context']);
same('S8. a temp address on the service domain is left alone', ['email_id' => 9, 'to' => 'abcdef1234@manjo.me'], logRow($pdo, 4)['context']);
$r5 = logRow($pdo, 5)['context'];
same('S9. nested: supporter_email becomes email_ref', emailLogRef('payer@example.net', $key), $r5['data']['email_ref'] ?? null);
same('S10. nested list element replaced in place', ['[email_ref:' . emailLogRef('x@example.net', $key) . ']'], $r5['data']['items'] ?? null);
same('S11. an address embedded in a string is replaced',
    "Duplicate entry '[email_ref:" . emailLogRef('dup@example.net', $key) . "]' for key 'email'", $r5['error'] ?? null);
same('S12. message text: user address replaced, service address kept',
    'Failed to send magic link to [email_ref:' . emailLogRef('stranger@example.net', $key) . '] from noreply@manjo.me', logRow($pdo, 6)['message']);
same('S13. new_email becomes new_email_ref next to the existing user_id',
    ['user_id' => 6, 'new_email_ref' => emailLogRef('fresh@example.com', $key)], logRow($pdo, 7)['context']);
same('S14. a service subdomain address is left alone', ['from' => 'bounce@mail.manjo.me'], logRow($pdo, 9)['context']);
same('S15. login_attempts: plaintext rows become the reference',
    [emailLogRef('known@example.com', $key), emailLogRef('stranger@example.net', $key), 'abcdef0123456789'],
    $pdo->query('SELECT email FROM login_attempts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
same('S16. the converted row matches what pro_auth.php now writes for the same address (any case)',
    emailLogRef('KNOWN@example.com', $key), $pdo->query('SELECT email FROM login_attempts WHERE id = 1')->fetchColumn());

$again = new EmailLogScrubber($pdo, $key, $domains);
$again->scrubSystemLogs(false);
$again->scrubLoginAttempts(false);
same('S17. idempotent: a second run changes no system_logs row', 0, $again->stats['log_rows_changed']);
same('S18. idempotent: a second run finds no plaintext login_attempts row', 0, $again->stats['login_attempts_scanned']);
same('S19. idempotent: the data is unchanged by the second run', $after, dumpAll($pdo));

// Without a key: nothing that is not an account id survives, and no address either.
$pdo = scrubDb();
$noKey = new EmailLogScrubber($pdo, null, $domains);
$noKey->scrubSystemLogs(false);
$noKey->scrubLoginAttempts(false);
$afterNoKey = dumpAll($pdo);
same('S20. no key: no user address is left anywhere', [], array_values(array_filter($userAddresses, static fn($a) => stripos($afterNoKey, $a) !== false)));
same('S21. no key: a registered address still becomes its user_id', ['user_id' => 5], logRow($pdo, 1)['context']);
same('S22. no key: an unknown address is dropped with no reference', [], logRow($pdo, 2)['context']);
same('S23. no key: login_attempts rows are blanked', ['', '', 'abcdef0123456789'],
    $pdo->query('SELECT email FROM login_attempts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check('S24. no key: embedded addresses become [email]', strpos(logRow($pdo, 6)['message'], '[email]') !== false);

// ---------------------------------------------------------------------------
// 3. pro_auth.php password_login, run for real as a subprocess
// ---------------------------------------------------------------------------

function probeStubConfig(): string
{
    return <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/email_log_ref_test.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');

$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => 'http://localhost:8085/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => 60, 'hash_key' => (string) getenv('PROBE_KEY'), 'claim_retention_days' => 1825],
];

/** MySQL's DATE_SUB(NOW(), INTERVAL ? MINUTE) has no SQLite syntax; rewrite it. */
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('DATE_SUB(NOW(), INTERVAL ? MINUTE)', 'PROBE_MINUTES_AGO(?)', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new ProbePdo('sqlite:' . getenv('PROBE_SQLITE'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('PROBE_MINUTES_AGO', static fn($m): string => date('Y-m-d H:i:s', time() - 60 * (int) $m), 1);

function logMessage($level, $message, $context = null) {
    file_put_contents((string) getenv('PROBE_LOG'), json_encode([$level, $message, $context]) . "\n", FILE_APPEND);
}
function tableHasColumn($table, $column) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
    $stmt->execute([(string) $table, (string) $column]);
    return (int) $stmt->fetchColumn() > 0;
}
function getVisitorIp(): string { return (string) getenv('PROBE_IP'); }
function flagMaliciousActivity(string $ip, string $reason, ?PDO $pdoConnection = null): bool { return true; }
function requireSameOriginRequest(): bool { return true; }
function appIsProduction(): bool { return false; }

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'password_login', 'email' => (string) getenv('PROBE_EMAIL'), 'password' => (string) getenv('PROBE_PASSWORD')];
PHP;
}

$probe = sys_get_temp_dir() . '/ms_email_log_ref_' . bin2hex(random_bytes(6));
mkdir($probe);
foreach (['pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'login_tokens.php', 'email_log_ref.php', 'pii_crypto.php', 'pro_remember.php'] as $file) {
    copy($repoRoot . '/' . $file, $probe . '/' . $file);
}
file_put_contents($probe . '/config.php', probeStubConfig());

function probeDb(string $path): void
{
    @unlink($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, email_enc TEXT NULL, email_hash TEXT NULL, password_hash TEXT, email_verified_at TEXT)');
    $pdo->exec("CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $stmt = $pdo->prepare("INSERT INTO pro_users (id, email, email_enc, email_hash, password_hash, email_verified_at) VALUES (1, 'victim@example.com', ?, ?, ?, '2026-01-01 00:00:00')");
    $stmt->execute([piiEmailEncrypt('victim@example.com'), piiEmailHash('victim@example.com'), password_hash('right-password', PASSWORD_DEFAULT)]);
}

/** Runs one password_login; returns the decoded JSON answer. */
function probeLogin(string $probe, string $db, string $log, string $probeKey, string $ip, string $email, string $password): array
{
    $env = ['PROBE_SQLITE' => $db, 'PROBE_LOG' => $log, 'PROBE_KEY' => $probeKey, 'PROBE_IP' => $ip, 'PROBE_EMAIL' => $email, 'PROBE_PASSWORD' => $password, 'PATH' => (string) getenv('PATH'),
        'PII_ENCRYPTION_KEY' => (string) getenv('PII_ENCRYPTION_KEY'), 'PII_INDEX_KEY' => (string) getenv('PII_INDEX_KEY')];
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $probe . '/pro_auth.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode((string) $out, true);
    return is_array($decoded) ? $decoded : ['raw' => $out, 'stderr' => $err];
}

$throttled = 'Too many failed login attempts for this account';
$db = $probe . '/probe.sqlite';
$log = $probe . '/probe.log';

// 3a. With a key: ten failures from ten IPs, address typed in varying case.
probeDb($db);
@unlink($log);
$variants = ['victim@example.com', 'VICTIM@example.com', ' Victim@Example.com ', 'victim@EXAMPLE.COM'];
for ($i = 0; $i < 10; $i++) {
    $answer = probeLogin($probe, $db, $log, $key, '10.0.0.' . ($i + 1), $variants[$i % 4], 'wrong');
    if ($i === 0) {
        check('P1. a wrong password gets the generic answer', str_contains((string) ($answer['error'] ?? ''), 'Incorrect email or password'), json_encode($answer));
    }
}
$answer = probeLogin($probe, $db, $log, $key, '10.0.1.1', 'Victim@example.com', 'right-password');
check('P2. the 11th attempt (new IP, even the right password) hits the per-account limit', str_contains((string) ($answer['error'] ?? ''), $throttled), json_encode($answer));
$answer = probeLogin($probe, $db, $log, $key, '10.0.1.2', 'someone-else@example.com', 'wrong');
check('P3. another address from a fresh IP is not throttled', !str_contains((string) ($answer['error'] ?? ''), $throttled), json_encode($answer));
$probePdo = new PDO('sqlite:' . $db);
$stored = $probePdo->query('SELECT DISTINCT email FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN);
sort($stored);
$expectedRefs = [emailLogRef('victim@example.com', $key), emailLogRef('someone-else@example.com', $key)];
sort($expectedRefs);
same('P4. login_attempts.email holds only the keyed references', $expectedRefs, $stored);
$logText = (string) @file_get_contents($log);
check('P5. the throttle is logged with the reference, not the address',
    str_contains($logText, (string) emailLogRef('victim@example.com', $key)) && stripos($logText, 'victim@example.com') === false && stripos($logText, 'someone-else@') === false);

// 3b. Without a key: '' is stored, and the per-account limit is skipped (never lumps everyone together).
probeDb($db);
@unlink($log);
for ($i = 0; $i < 11; $i++) {
    $answer = probeLogin($probe, $db, $log, '', '10.1.0.' . ($i + 1), 'victim@example.com', 'wrong');
}
check('P6. no key: the 11th failure is not throttled per account', !str_contains((string) ($answer['error'] ?? ''), $throttled) && str_contains((string) ($answer['error'] ?? ''), 'Incorrect email'), json_encode($answer));
$probePdo = new PDO('sqlite:' . $db);
same('P7. no key: login_attempts.email is empty, never the address', [''], $probePdo->query('SELECT DISTINCT email FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN));
for ($i = 0; $i < 5; $i++) {
    probeLogin($probe, $db, $log, '', '10.9.9.9', 'x' . $i . '@example.com', 'wrong');
}
$answer = probeLogin($probe, $db, $log, '', '10.9.9.9', 'victim@example.com', 'right-password');
check('P8. no key: the per-IP limit still applies', str_contains((string) ($answer['error'] ?? ''), 'from your IP'), json_encode($answer));

foreach (glob($probe . '/*') as $f) {
    @unlink($f);
}
@rmdir($probe);

// ---------------------------------------------------------------------------
// 4. Repository scan: no logging call passes a raw user address
// ---------------------------------------------------------------------------

/** The argument text of every call to one of $names in $source, keyed by line. */
function callArguments(string $source, array $names): array
{
    $tokens = token_get_all($source);
    $n = count($tokens);
    $calls = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], $names, true)) {
            continue;
        }
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
            continue; // the definition, not a call
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $depth = 0;
        $text = '';
        for ($k = $j; $k < $n; $k++) {
            $v = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($v === '(') {
                $depth++;
            } elseif ($v === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text .= $v;
        }
        $calls[] = ['line' => $t[2], 'args' => $text];
    }
    return $calls;
}

/** Removes emailLogContext(...)/emailLogRef(...) calls: those are the safe way to mention an address. */
function stripSafeCalls(string $args): string
{
    $out = $args;
    while (preg_match('/\b(emailLogContext|emailLogRef)\s*\(/', $out, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1];
        $pos = $start + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($out);
        while ($pos < $len && $depth > 0) {
            if ($out[$pos] === '(') {
                $depth++;
            } elseif ($out[$pos] === ')') {
                $depth--;
            }
            $pos++;
        }
        $out = substr($out, 0, $start) . 'SAFE' . substr($out, $pos);
    }
    return $out;
}

$scanFiles = ['pro_auth.php', 'pro_profile.php', 'pro_login.php', 'register.php', 'pro_contact.php', 'index.php',
    'paddle_sync.php', 'paddle_webhook.php', 'pro_trial.php', 'login_tokens.php', 'TwoFactorAuth.php',
    'cron/cleanup.php', 'cron/send-digests.php'];
// A user's registered address travels under these names in the scanned files.
$addressVar = '/\$(email|to|userEmail|newEmail|oldEmail|pendingEmail|currentEmail|user_email)\b(?!\s*\[)|\$(user|u|existing|row|result|pending)\s*\[\s*[\'"]email[\'"]\s*\]/';
$addressKey = '/[\'"](email|to|user_email|new_email|old_email|supporter_email|payer_email)[\'"]\s*=>/';
$callsSeen = 0;
$offenders = [];
foreach ($scanFiles as $file) {
    $path = $repoRoot . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    foreach (callArguments((string) file_get_contents($path), ['logMessage', 'error_log', 'paddleLog']) as $call) {
        $callsSeen++;
        $args = stripSafeCalls($call['args']);
        if (preg_match($addressVar, $args) || preg_match($addressKey, $args)) {
            $offenders[] = $file . ':' . $call['line'];
        }
    }
}
check('L1. the scan found logging calls to check', $callsSeen > 100, "only {$callsSeen}");
same('L2. no logMessage()/error_log() in the listed files passes a raw user address', [], $offenders);

// The contexts built into a variable first ($logContext) are covered too.
$authSrc = (string) file_get_contents($repoRoot . '/pro_auth.php');
same('L3. pro_auth.php builds no $logContext from a raw address', 0, preg_match('/\$logContext\s*=\s*\[[^\]]*\$email/', $authSrc));

// login_attempts: every INSERT binds a reference, never the address.
preg_match_all('/INSERT INTO login_attempts[^;]*;\s*\$ins->execute\(\[(.*?)\]\);/', $authSrc, $m);
check('L4. pro_auth.php has login_attempts INSERTs to check', count($m[1]) >= 7, 'found ' . count($m[1]));
same('L5. no login_attempts INSERT binds $email/$pendingEmail directly', [],
    array_values(array_filter($m[1], static fn($a) => preg_match('/\$(email|pendingEmail)\b/', stripSafeCalls($a)) === 1)));
check('L6. the per-account limit compares the reference', preg_match('/FROM login_attempts WHERE email = \?[^;]*;\s*\$s2->execute\(\[\$attemptRef,/', $authSrc) === 1);

// A mail is only ever sent to an existing account, so every send helper
// must be given that account's id: its log lines then carry user_id, and the
// keyed reference is left for the genuine no-account case.
/** Number of top-level arguments in a call's argument text "( ... )". */
function argumentCount(string $args): int
{
    $inner = trim(substr(trim($args), 1));
    if ($inner === '') {
        return 0;
    }
    $depth = 0;
    $count = 1;
    foreach (token_get_all('<?php ' . $inner) as $tok) {
        $v = is_array($tok) ? $tok[1] : $tok;
        if ($v === '(' || $v === '[') {
            $depth++;
        } elseif ($v === ')' || $v === ']') {
            $depth--;
        } elseif ($v === ',' && $depth === 0) {
            $count++;
        }
    }
    return $count;
}
$sendHelpers = ['sendLoginEmail', 'sendVerificationEmail', 'sendAlreadyRegisteredEmail',
    'sendAdminRegistrationNotification', 'sendInactivityWarningEmail'];
$sendCalls = 0;
$withoutId = [];
foreach (['pro_auth.php', 'pro_login.php', 'cron/cleanup.php'] as $file) {
    foreach (callArguments((string) file_get_contents($repoRoot . '/' . $file), $sendHelpers) as $call) {
        $sendCalls++;
        if (argumentCount($call['args']) < 2) {
            $withoutId[] = $file . ':' . $call['line'];
        }
    }
}
check('L7. the send helpers are called', $sendCalls >= 6, "found {$sendCalls}");
same('L8. every send-helper call passes the account id', [], $withoutId);
check('L9. sendLoginEmail/sendVerificationEmail calls pass three arguments (email, token, user id)',
    preg_match_all('/(?<!function )\bsend(Login|Verification)Email\(\s*\$email,\s*\$token,\s*[^;]*\$(userId|existing)/', $authSrc) === 3);
same('L10. pro_auth.php logs a keyed reference only for the unknown-address case', 1,
    preg_match_all('/emailLogContext\(\$email\)/', $authSrc));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
