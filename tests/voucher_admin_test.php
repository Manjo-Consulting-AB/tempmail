<?php

declare(strict_types=1);

/**
 * Regression coverage for the voucher admin page (voucher_admin.php, epic
 * #359 step 3/4).
 *
 * Run with:  php tests/voucher_admin_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network: it
 * reuses the throwaway-docroot harness in tests/lib/pushover_harness.php
 * (tests/feed_token_test.php's pattern) and runs the *real* page through
 * PHP's built-in web server, because the page answers with redirects and a
 * session flash that a CLI include cannot show. A cookie-carrying client
 * keeps the session from one request to the next; the session is seeded by
 * writing PHP's own session file, so the ADMIN_USER_IDS gate is exercised
 * without a full sign-in.
 *
 *  A. access: no session / a non-admin / an admin, and a cross-origin POST;
 *  B. create: days, lifetime, multi-use, custom code, a taken custom code,
 *     an empty days field (never a silent lifetime), a bad date, a note;
 *  C. the list and its status filter;
 *  D. deactivate and reactivate;
 *  E. the redemptions view: account ids, no address;
 *  F. the "not available" page before migrate_vouchers.php has run;
 *  G. repository scans: robots.txt, the admin tab row, and no log line in the
 *     page that could carry a code.
 *
 * The redemption itself is covered by tests/voucher_service_test.php section F
 * (the real redeemVoucherForEmail() on a voucher the service created); this
 * suite asserts that a page-created code lands as exactly that row.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension (it brings its own SQLite database so it needs no MySQL).\n");
    exit(1);
}

$msRepoRoot = dirname(__DIR__);
require __DIR__ . '/lib/pushover_harness.php';

const MS_VA_ORIGIN = 'http://localhost:8085';
const MS_VA_ADMIN_ID = 1;
const MS_VA_OTHER_ID = 2;

// ---------------------------------------------------------------------
// Schema on top of ms_test_schema(): the voucher tables as
// migrate_vouchers.php leaves them.
// ---------------------------------------------------------------------

function ms_va_voucher_schema(): string
{
    return <<<'SQL'
CREATE TABLE vouchers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT NULL,
    max_uses INTEGER NULL,
    current_uses INTEGER NOT NULL DEFAULT 0,
    duration_days INTEGER NULL,
    created_at TEXT NULL,
    source TEXT NOT NULL DEFAULT 'legacy',
    created_by_user_id INTEGER NULL,
    issuer_id INTEGER NULL,
    external_ref TEXT NULL,
    batch_id TEXT NULL,
    note TEXT NULL
);

CREATE UNIQUE INDEX uniq_vouchers_code ON vouchers (code);

CREATE TABLE redemption_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    voucher_id INTEGER NOT NULL,
    redeemed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX uniq_redemption_log_user_voucher ON redemption_log (user_id, voucher_id);
SQL;
}

// ---------------------------------------------------------------------
// The probe docroot: the real page + voucher_service.php + a stub config.php
// that also mirrors the ADMIN_USER_IDS gate.
// ---------------------------------------------------------------------

function ms_va_config_extra(): string
{
    return <<<'PHP'

$config['admin']['user_ids'] = [1];

/** Mirrors config.php's isAdminUser(). */
function isAdminUser(int $userId): bool {
    global $config;
    return $userId > 0 && in_array($userId, $config['admin']['user_ids'] ?? [], true);
}

/** Mirrors config.php's proUserIsSuspended(): suspended_at is set. */
function proUserIsSuspended(int $userId): bool {
    global $pdo;
    if ($userId <= 0) return false;
    try {
        $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null;
    } catch (Exception $e) {
        return false;
    }
}

/** Mirrors config.php's proSessionEndIfSuspended(), minus the device restore. */
function proSessionEndIfSuspended(): bool {
    $userId = (int) ($_SESSION['pro_user_id'] ?? 0);
    if ($userId <= 0 || !proUserIsSuspended($userId)) return false;
    unset($_SESSION['pro_user_id']);
    return true;
}
PHP;
}

/**
 * Build a probe docroot. $withVouchers = false leaves the voucher tables out,
 * which is the state before migrate_vouchers.php has run.
 */
function ms_va_build_probe(string $repoRoot, bool $withVouchers): string
{
    $probe = ms_test_probe_build($repoRoot);

    foreach (['voucher_admin.php', 'voucher_service.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $probe . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file} into the probe docroot");
        }
    }
    if (file_put_contents($probe . '/config.php', ms_test_stub_config_php() . ms_va_config_extra()) === false) {
        throw new RuntimeException('Could not write the patched config.php');
    }

    $pdo = ms_test_db(ms_test_probe_sqlite($probe));
    $pdo->exec('ALTER TABLE pro_users ADD COLUMN suspended_at TEXT NULL');
    if ($withVouchers) {
        $pdo->exec(ms_va_voucher_schema());
    }
    $pdo = null;

    return $probe;
}

// ---------------------------------------------------------------------
// The built-in web server and a cookie-carrying client
// ---------------------------------------------------------------------

function ms_va_sessions_dir(string $root): string
{
    $dir = $root . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function ms_va_start_server(string $root): array
{
    $env = [
        'PROBE_SQLITE' => ms_test_probe_sqlite($root),
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
    ];
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 60000);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=E_ALL',
                '-d', 'session.save_path=' . ms_va_sessions_dir($root), '-d', 'session.use_strict_mode=0',
                '-S', "127.0.0.1:{$port}", '-t', $root],
            $descriptors,
            $pipes,
            $root,
            $env
        );
        if (!is_resource($proc)) {
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        usleep(300000);
        $status = proc_get_status($proc);
        if ($status['running']) {
            return [$proc, $port, $pipes];
        }
        proc_close($proc);
    }
    throw new RuntimeException("Could not start PHP's built-in server for {$root}");
}

function ms_va_stop_server(array $server): void
{
    [$proc, , $pipes] = $server;
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

/**
 * Seed a session by writing PHP's own session file, so the next request that
 * carries that PHPSESSID is that account's signed-in session. $probe is the
 * docroot whose sessions/ directory the server was started with.
 */
function ms_va_seed_session(string $probe, string $sessionId, int $userId): void
{
    $file = ms_va_sessions_dir($probe) . '/sess_' . $sessionId;
    if (file_put_contents($file, 'pro_user_id|i:' . $userId . ';') === false) {
        throw new RuntimeException("Could not seed the session file {$file}");
    }
}

/**
 * A browser: it keeps the cookies the server set, so a session (and the flash
 * stored in it) survives from one request to the next. Redirects are never
 * followed — where a response sends the user is exactly what these tests
 * assert on.
 */
final class MsVaBrowser
{
    /** @var array<string,string> */
    public array $cookies = [];

    public function __construct(private int $port)
    {
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get(string $path, array $headers = []): array
    {
        return $this->send('GET', $path, $headers, '');
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function post(string $path, array $form, array $headers = []): array
    {
        return $this->send('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers, http_build_query($form));
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function send(string $method, string $path, array $headers, string $body): array
    {
        $lines = '';
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $lines .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
        }
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $responseBody = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $responseHeaders = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if ($name === 'set-cookie') {
                $pair = explode(';', $value, 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $this->cookies[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
                }
                continue;
            }
            $responseHeaders[$name] = $value;
        }

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : $responseBody,
            'headers' => $responseHeaders,
        ];
    }
}

/** A browser signed in as $userId, its session seeded in $probe. */
function ms_va_browser(string $probe, int $port, string $sessionId, int $userId = 0): MsVaBrowser
{
    $browser = new MsVaBrowser($port);
    if ($userId > 0) {
        ms_va_seed_session($probe, $sessionId, $userId);
        $browser->setCookie('PHPSESSID', $sessionId);
    }
    return $browser;
}

// ---------------------------------------------------------------------
// Small readers
// ---------------------------------------------------------------------

function ms_va_row(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function ms_va_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
}

/** The code the flash showed, or null when the page did not show one. */
function ms_va_flash_code(string $html): ?string
{
    if (preg_match('/<code class="ms-admin__code" id="newVoucherCode">([^<]+)<\/code>/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return null;
}

/** POST one action to the page as $browser; returns the 303 response. */
function ms_va_post(MsVaBrowser $browser, array $fields, ?string $origin = MS_VA_ORIGIN): array
{
    $headers = $origin === null ? [] : ['Origin' => $origin];
    return $browser->post('/voucher_admin.php', $fields, $headers);
}

/** POST then follow the redirect with a GET; returns the GET response. */
function ms_va_post_then_get(MsVaBrowser $browser, array $fields): array
{
    $post = ms_va_post($browser, $fields);
    return $browser->get('/voucher_admin.php');
}

/** A create form as the page sends it, with $overrides applied. */
function ms_va_create_fields(array $overrides = []): array
{
    return array_merge([
        'action' => 'create',
        'duration' => 'days',
        'days' => '30',
        'max_uses' => '1',
        'redeemable_until' => '',
        'code' => '',
        'note' => '',
    ], $overrides);
}

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------

echo "Mail Shield — voucher admin (#359 step 3/4)\n";

$probe = ms_va_build_probe($msRepoRoot, true);
$probeNoSchema = ms_va_build_probe($msRepoRoot, false);
$server = ms_va_start_server($probe);
$serverNoSchema = ms_va_start_server($probeNoSchema);
$port = $server[1];
$portNoSchema = $serverNoSchema[1];
$sqlite = ms_test_probe_sqlite($probe);
echo "probe docroot: {$probe}\n";

$guest = ms_va_browser($probe, $port, 'vaguestsession');
$other = ms_va_browser($probe, $port, 'vaothersession', MS_VA_OTHER_ID);
$admin = ms_va_browser($probe, $port, 'vaadminsession', MS_VA_ADMIN_ID);

// =====================================================================
ms_test_section('A. Access');
// =====================================================================

$response = $guest->get('/voucher_admin.php');
ms_test_same('A1. a visitor with no session is refused', 403, $response['status']);
ms_test_check('A2. ... with no admin markup', !str_contains($response['body'], 'Create a code'));

$response = $other->get('/voucher_admin.php');
ms_test_same('A3. a signed-in non-admin is refused', 403, $response['status']);
ms_test_check('A4. ... and sees no admin markup', !str_contains($response['body'], 'Create a code'));

$response = $admin->get('/voucher_admin.php');
ms_test_same('A5. the admin sees the page', 200, $response['status']);
ms_test_check('A6. ... with the create form', str_contains($response['body'], 'Create a code'));
ms_test_same('A7. the page is noindex', 'noindex, nofollow', $response['headers']['x-robots-tag'] ?? null);
ms_test_check('A8. the Vouchers tab is in the admin tab row',
    str_contains($response['body'], 'voucher_admin.php') && str_contains($response['body'], '>Vouchers<'));

$response = ms_va_post($other, ms_va_create_fields(), MS_VA_ORIGIN);
ms_test_same('A9. a non-admin cannot post either', 403, $response['status']);
ms_test_same('A10. ... and nothing was created', 0, ms_va_count(ms_test_db($sqlite)));

$response = ms_va_post($admin, ms_va_create_fields(), 'http://evil.example');
ms_test_same('A11. a cross-origin POST is refused', 403, $response['status']);
ms_test_check('A12. ... with "Forbidden"', str_contains($response['body'], 'Forbidden'));

$response = ms_va_post($admin, ms_va_create_fields(), null);
ms_test_same('A13. a POST with no Origin and no Referer is refused', 403, $response['status']);

$response = ms_va_post($admin, ms_va_create_fields(), MS_VA_ORIGIN);
ms_test_same('A14. a same-origin POST is accepted (a redirect back)', 303, $response['status']);
ms_test_check('A15. ... to the page itself', str_contains((string) ($response['headers']['location'] ?? ''), 'voucher_admin.php'));

// =====================================================================
ms_test_section('B. Create');
// =====================================================================

$body = $admin->get('/voucher_admin.php')['body'];
$code1 = ms_va_flash_code($body);
ms_test_check('B1. the redirected-to page shows the new code once', is_string($code1) && $code1 !== '');
ms_test_check('B2. ... in the documented shape', is_string($code1) && preg_match('/^MS-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code1) === 1, (string) $code1);
ms_test_check('B3. ... with the one-line summary', str_contains($body, '30 days of Pro') && str_contains($body, '1 account'));
ms_test_check('B4. ... and a copy button', str_contains($body, 'id="copyVoucherCode"'));

$pdo = ms_test_db($sqlite);
$row = ms_va_row($pdo, (string) $code1);
ms_test_check('B5. the row is an ordinary admin voucher',
    $row !== null && (int) $row['duration_days'] === 30 && (int) $row['max_uses'] === 1
    && (int) $row['current_uses'] === 0 && (int) $row['is_active'] === 1
    && $row['source'] === 'admin' && (int) $row['created_by_user_id'] === MS_VA_ADMIN_ID
    && $row['expires_at'] === null && $row['created_at'] !== null,
    ms_test_dump($row));
ms_test_same('B6. nothing carries a batch_id yet', null, $row['batch_id'] ?? null);

$bodyAgain = $admin->get('/voucher_admin.php')['body'];
ms_test_check('B7. the next GET no longer shows the code', ms_va_flash_code($bodyAgain) === null && !str_contains($bodyAgain, 'id="copyVoucherCode"'));
ms_test_check('B8. ... but the code is still in the list', str_contains($bodyAgain, (string) $code1));

// --- lifetime: an explicit choice ------------------------------------

$body = ms_va_post_then_get($admin, ms_va_create_fields(['duration' => 'lifetime', 'days' => '']))['body'];
$codeLifetime = ms_va_flash_code($body);
ms_test_check('B9. choosing Lifetime creates a code with no duration', is_string($codeLifetime) && $codeLifetime !== '');
ms_test_check('B10. ... and the flash says so', str_contains($body, 'Lifetime'));
$pdo = ms_test_db($sqlite);
$lifetimeRow = ms_va_row($pdo, (string) $codeLifetime);
ms_test_check('B11. the row has duration_days NULL', $lifetimeRow !== null && $lifetimeRow['duration_days'] === null);

// --- multi-use --------------------------------------------------------

$body = ms_va_post_then_get($admin, ms_va_create_fields(['max_uses' => '5']))['body'];
$codeMulti = ms_va_flash_code($body);
$pdo = ms_test_db($sqlite);
ms_test_same('B12. max_uses is stored', 5, (int) (ms_va_row($pdo, (string) $codeMulti)['max_uses'] ?? 0));
ms_test_check('B13. ... and the summary counts accounts', str_contains($body, '5 accounts'));

// --- custom code ------------------------------------------------------

$body = ms_va_post_then_get($admin, ms_va_create_fields(['code' => 'MY-ADMIN-CODE1']))['body'];
ms_test_same('B14. a custom code is used exactly as given', 'MY-ADMIN-CODE1', ms_va_flash_code($body));
$pdo = ms_test_db($sqlite);
ms_test_check('B15. ... and stored', ms_va_row($pdo, 'MY-ADMIN-CODE1') !== null);

$before = ms_va_count(ms_test_db($sqlite));
$body = ms_va_post_then_get($admin, ms_va_create_fields(['code' => 'MY-ADMIN-CODE1']))['body'];
ms_test_check('B16. a taken custom code answers the service\'s message', str_contains($body, 'That code already exists'));
ms_test_same('B17. ... and creates nothing', $before, ms_va_count(ms_test_db($sqlite)));

// --- an empty days field is an error, never a silent lifetime ---------

$before = ms_va_count(ms_test_db($sqlite));
$body = ms_va_post_then_get($admin, ms_va_create_fields(['duration' => 'days', 'days' => '']))['body'];
ms_test_check('B18. an empty days field with the days radio is refused',
    str_contains($body, 'Enter the number of days, or choose Lifetime.'));
ms_test_same('B19. ... and no voucher was created', $before, ms_va_count(ms_test_db($sqlite)));
ms_test_check('B20. ... in particular not a lifetime one', ms_va_flash_code($body) === null);

foreach (['0', '3651', 'abc', '-5'] as $badDays) {
    $before = ms_va_count(ms_test_db($sqlite));
    $body = ms_va_post_then_get($admin, ms_va_create_fields(['days' => $badDays]))['body'];
    ms_test_check("B21. days = '{$badDays}' is refused", ms_va_flash_code($body) === null && str_contains($body, 'days of Pro') === false);
    ms_test_same("B22. ... and creates nothing ({$badDays})", $before, ms_va_count(ms_test_db($sqlite)));
}

$before = ms_va_count(ms_test_db($sqlite));
$body = ms_va_post_then_get($admin, ms_va_create_fields(['max_uses' => '']))['body'];
ms_test_check('B23. an empty accounts field is refused', ms_va_flash_code($body) === null);
ms_test_same('B24. ... and creates nothing', $before, ms_va_count(ms_test_db($sqlite)));

// --- redeemable until --------------------------------------------------

$future = date('Y-m-d', time() + 30 * 86400);
$body = ms_va_post_then_get($admin, ms_va_create_fields(['redeemable_until' => $future]))['body'];
$codeUntil = ms_va_flash_code($body);
$pdo = ms_test_db($sqlite);
ms_test_same('B25. a date is stored as that day 23:59:59', $future . ' 23:59:59', ms_va_row($pdo, (string) $codeUntil)['expires_at'] ?? null);
ms_test_check('B26. ... and the summary names it', str_contains($body, 'redeemable until ' . $future));

$before = ms_va_count(ms_test_db($sqlite));
$body = ms_va_post_then_get($admin, ms_va_create_fields(['redeemable_until' => date('Y-m-d', time() - 86400)]))['body'];
ms_test_check('B27. a past date is refused', str_contains($body, 'Redeemable until must be a future date.'));
ms_test_same('B28. ... and creates nothing', $before, ms_va_count(ms_test_db($sqlite)));

$before = ms_va_count(ms_test_db($sqlite));
$body = ms_va_post_then_get($admin, ms_va_create_fields(['redeemable_until' => '2026-13-45']))['body'];
ms_test_check('B29. a malformed date is refused', str_contains($body, 'Enter a valid date'));
ms_test_same('B30. ... and creates nothing', $before, ms_va_count(ms_test_db($sqlite)));

// --- note --------------------------------------------------------------

$body = ms_va_post_then_get($admin, ms_va_create_fields(['note' => 'For the podcast giveaway']))['body'];
$codeNote = ms_va_flash_code($body);
$pdo = ms_test_db($sqlite);
$noteRow = ms_va_row($pdo, (string) $codeNote);
ms_test_same('B31. the note is stored', 'For the podcast giveaway', $noteRow['note'] ?? null);
ms_test_check('B32. ... and shown in the list', str_contains($admin->get('/voucher_admin.php')['body'], 'For the podcast giveaway'));

// =====================================================================
ms_test_section('C. List and status filter');
// =====================================================================

$body = $admin->get('/voucher_admin.php')['body'];
ms_test_check('C1. the list names the custom code', str_contains($body, 'MY-ADMIN-CODE1'));
ms_test_check('C2. ... shows the Pro time', str_contains($body, 'Lifetime') && str_contains($body, '30 days'));
ms_test_check('C3. ... and the status filter', str_contains($body, 'Used up') && str_contains($body, '>Inactive<'));

$body = $admin->get('/voucher_admin.php?status=active')['body'];
ms_test_check('C4. status=active lists an active code', str_contains($body, 'MY-ADMIN-CODE1'));
$body = $admin->get('/voucher_admin.php?status=inactive')['body'];
ms_test_check('C5. status=inactive does not', !str_contains($body, 'MY-ADMIN-CODE1'));

$response = $admin->get('/voucher_admin.php?status=nonsense');
ms_test_same('C6. an unknown status is ignored, not an error', 200, $response['status']);
ms_test_check('C7. ... and still lists the codes', str_contains($response['body'], 'MY-ADMIN-CODE1'));

// =====================================================================
ms_test_section('D. Deactivate and reactivate');
// =====================================================================

$pdo = ms_test_db($sqlite);
$voucherId = (int) ms_va_row($pdo, 'MY-ADMIN-CODE1')['id'];

$response = ms_va_post($admin, ['action' => 'deactivate', 'id' => $voucherId], MS_VA_ORIGIN);
ms_test_same('D1. deactivate answers a redirect', 303, $response['status']);
$body = $admin->get('/voucher_admin.php')['body'];
ms_test_check('D2. ... with a flash notice', str_contains($body, 'Code deactivated.'));
$pdo = ms_test_db($sqlite);
ms_test_same('D3. ... and the row is inactive', 0, (int) ms_va_row($pdo, 'MY-ADMIN-CODE1')['is_active']);

$body = $admin->get('/voucher_admin.php?status=inactive')['body'];
ms_test_check('D4. the code now lists as Inactive', str_contains($body, 'MY-ADMIN-CODE1') && str_contains($body, '>Inactive<'));

$response = ms_va_post($admin, ['action' => 'activate', 'id' => $voucherId], MS_VA_ORIGIN);
ms_test_same('D5. reactivate answers a redirect', 303, $response['status']);
$pdo = ms_test_db($sqlite);
ms_test_same('D6. ... and the row is active again', 1, (int) ms_va_row($pdo, 'MY-ADMIN-CODE1')['is_active']);
ms_test_check('D7. ... with its own flash notice', str_contains($admin->get('/voucher_admin.php')['body'], 'Code reactivated.'));

$response = ms_va_post($admin, ['action' => 'deactivate', 'id' => 999999], MS_VA_ORIGIN);
ms_test_same('D8. deactivating a missing id still redirects', 303, $response['status']);
ms_test_check('D9. ... with the service\'s "Voucher not found"', str_contains($admin->get('/voucher_admin.php')['body'], 'Voucher not found'));

// =====================================================================
ms_test_section('E. Redemptions view');
// =====================================================================

$pdo = ms_test_db($sqlite);
$redeemedVoucher = ms_va_row($pdo, 'MY-ADMIN-CODE1');
$redeemedId = (int) $redeemedVoucher['id'];
$pdo->prepare('INSERT INTO redemption_log (user_id, voucher_id, redeemed_at) VALUES (?, ?, ?)')
    ->execute([4242, $redeemedId, '2026-02-03 10:11:12']);

$response = $admin->get('/voucher_admin.php?view=redemptions&id=' . $redeemedId);
ms_test_same('E1. the redemptions view answers', 200, $response['status']);
ms_test_check('E2. ... naming the code', str_contains($response['body'], 'MY-ADMIN-CODE1'));
ms_test_check('E3. ... with the account id', str_contains($response['body'], '#4242'));
ms_test_check('E4. ... and the date', str_contains($response['body'], '2026-02-03 10:11:12'));
// Only the redemptions table matters: the shell around it links Bootstrap
// from a CDN, and that URL contains an '@'.
preg_match('/<table class="ms-admin__table">.*?<\/table>/s', $response['body'], $tableMatch);
ms_test_check('E5. ... and the table never carries an email address',
    isset($tableMatch[0]) && !str_contains($tableMatch[0], '@'), substr($tableMatch[0] ?? '', 0, 200));
ms_test_check('E6. ... with a way back to the list', str_contains($response['body'], 'All codes'));

$response = $admin->get('/voucher_admin.php?view=redemptions&id=999999');
ms_test_check('E7. an unknown voucher answers "Voucher not found"', str_contains($response['body'], 'Voucher not found'));

// =====================================================================
ms_test_section('F. Before the migration');
// =====================================================================

$adminNoSchema = ms_va_browser($probeNoSchema, $portNoSchema, 'vanoschema', MS_VA_ADMIN_ID);
$response = $adminNoSchema->get('/voucher_admin.php');
ms_test_same('F1. the page still answers', 200, $response['status']);
ms_test_check('F2. ... with the migrate message',
    str_contains($response['body'], 'Vouchers are not available (run <code>migrate_vouchers.php</code>).'));
ms_test_check('F3. ... and no create form at all',
    !str_contains($response['body'], '<form') && !str_contains($response['body'], 'name="duration"')
    && !str_contains($response['body'], 'ms-admin__heading">Create a code'));

$response = ms_va_post($adminNoSchema, ms_va_create_fields(), MS_VA_ORIGIN);
ms_test_same('F4. a create POST still redirects', 303, $response['status']);
ms_test_check('F5. ... and the flash repeats the migrate message',
    str_contains($adminNoSchema->get('/voucher_admin.php')['body'], 'Vouchers are not available'));

// =====================================================================
ms_test_section('G. Repository scans');
// =====================================================================

$robots = (string) file_get_contents($msRepoRoot . '/robots.txt');
ms_test_check('G1. robots.txt disallows /voucher_admin.php', str_contains($robots, "Disallow: /voucher_admin.php\n"));

$tabs = (string) file_get_contents($msRepoRoot . '/partials/admin_tabs.php');
ms_test_check('G2. partials/admin_tabs.php carries the Vouchers tab',
    str_contains($tabs, "'vouchers' => ['Vouchers', 'voucher_admin.php']"));
ms_test_check('G3. ... and names it in the doc comment', str_contains($tabs, "'vouchers'"));

$page = (string) file_get_contents($msRepoRoot . '/voucher_admin.php');
ms_test_check('G4. the page logs nothing with a code key',
    preg_match('/logMessage\([^;]*[\'"]code[\'"]\s*=>/s', $page) === 0);
ms_test_check('G5. ... and there is at least one log call, so the scan is not vacuous',
    preg_match('/logMessage\(/', $page) === 1);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

ms_va_stop_server($server);
ms_va_stop_server($serverNoSchema);

$exitCode = ms_test_summary();

if ($exitCode === 0) {
    ms_test_cleanup($probe);
    ms_test_cleanup($probeNoSchema);
    ms_test_rrmdir(sys_get_temp_dir() . '/ms-pushover-test-sessions');
} else {
    echo "Probe docroots left in place for inspection: {$probe}, {$probeNoSchema}\n";
}
exit($exitCode);
