<?php
/**
 * Probe docroot for the sign-in area (epic #428, step 2/8): the real
 * register.php, pro_login.php, pro_logout.php and pro_auth.php, run as CLI
 * subprocesses against a stub config.php on SQLite — the same approach as
 * tests/remember_session_test.php and tests/pii_crypto_test.php, shared here
 * so tests/i18n_test.php and the one-off fixture generator use one harness.
 *
 * Nothing here is deployed. The stub is a TEST DOUBLE of config.php: it
 * provides $config, $pdo and the few helpers the pages call, loads the real
 * i18n.php and calls the real i18nBootstrap() so a /sv/ URL behaves as in
 * production. Everything per request (URL, locale gate, trial length, body,
 * session) arrives through the PROBE_REQUEST environment variable.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MS_AUTH_ENC_KEY = 'ee-probe-encryption-key-0123456789abcdef';
const MS_AUTH_IDX_KEY = 'ii-probe-index-key-0123456789abcdef-xyz';
const MS_AUTH_HASH_KEY = 'hh-probe-trial-hash-key-0123456789abcdef';
// base64 of 32 bytes, as TwoFactorAuth expects
const MS_AUTH_TOTP_KEY = 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

function ms_auth_stub_config(): string
{
    return <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/lib/auth_probe.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');
$probeRequest = json_decode((string) getenv('PROBE_REQUEST'), true) ?: [];
$_ENV['TOTP_ENCRYPTION_KEY'] = getenv('TOTP_ENCRYPTION_KEY') ?: '';
require_once __DIR__ . '/i18n.php';
$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => $probeRequest['base_url'] ?? 'https://example.test/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => (int) ($probeRequest['trial'] ?? 60), 'hash_key' => (string) getenv('PRO_TRIAL_HASH_KEY'), 'claim_retention_days' => 1825],
    'i18n' => ['enabled' => i18nParseEnabled((string) ($probeRequest['locales'] ?? 'en'))],
];
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(['DATE_SUB(NOW(), INTERVAL ? MINUTE)', ' FOR UPDATE', 'DELETE d FROM pro_webhook_deliveries d JOIN pro_webhooks w ON w.id = d.webhook_id WHERE w.user_id = ?'],
            ['PROBE_MINUTES_AGO(?)', '', 'DELETE FROM pro_webhook_deliveries WHERE webhook_id IN (SELECT id FROM pro_webhooks WHERE user_id = ?)'], $query);
        return parent::prepare($query, $options);
    }
    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*(CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS|ALTER\s+TABLE)\b/i', $statement)) {
            return 0;
        }
        return parent::exec($statement);
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
function getVisitorIp(): string { return '10.0.0.1'; }
function flagMaliciousActivity(string $ip, string $reason, ?PDO $pdoConnection = null): bool { return true; }
function requireSameOriginRequest(): bool { return (($GLOBALS['probeRequest']['origin'] ?? 'ok') === 'ok'); }
function appIsProduction(): bool { return false; }
function appCookieSecure(): bool { return false; }
function isDisposableEmailDomain(string $email): bool { return false; }
function detectSuspiciousPatterns(string $input): array { return stripos($input, 'script') !== false ? ['probe_script'] : []; }
function patternsWarrantingIpFlag(array $patterns): array { return []; }
function proUserIsSuspended(int $userId): bool {
    global $pdo;
    $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return $v !== false && $v !== null;
}
function proUserIsPro($userId) { return false; }
function isAdminUser($userId) { return false; }
require_once __DIR__ . '/pii_crypto.php';
require_once __DIR__ . '/pro_remember.php';
// The language of this request, from its /<locale>/ prefix — as config.php does.
$_SERVER['REQUEST_URI'] = (string) ($probeRequest['uri'] ?? '/');
$_SERVER['REQUEST_METHOD'] = (string) ($probeRequest['method'] ?? 'GET');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/120.0';
foreach (($probeRequest['server'] ?? []) as $k => $v) {
    $_SERVER[$k] = $v;
}
i18nBootstrap($config['i18n'], $_SERVER);
// The app area is offered in Swedish only once '@app' lists it (step 7/8); a case can switch that on.
if (!empty($probeRequest['app_sv'])) {
    i18nTranslatedPages(array_merge(i18nTranslatedPages(), ['@app' => ['en', 'sv']]));
}
if (!empty($probeRequest['session_id'])) {
    session_id((string) $probeRequest['session_id']);
}
$_POST = $probeRequest['post'] ?? [];
$_GET = $probeRequest['get'] ?? [];
$_COOKIE = $probeRequest['cookie'] ?? [];
PHP;
}

/** The SQLite schema every case starts from. */
function ms_auth_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, email_enc TEXT NULL, email_hash CHAR(64) NULL UNIQUE,
        password_hash TEXT NULL, account_type TEXT NOT NULL DEFAULT 'regular', email_verified_at TEXT NULL, address_ttl_days INTEGER NOT NULL DEFAULT 1,
        pro_expires_at TEXT NULL, password_changed_at TEXT NULL, last_login_at TEXT NULL, inactivity_warned_at TEXT NULL, suspended_at TEXT NULL,
        referral_pending_code TEXT NULL, locale TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', pending_secret_enc TEXT NULL, last_used_step INTEGER NULL, confirmed_at TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec('CREATE TABLE pro_user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NULL, used_at TEXT NULL)');
    $pdo->exec('CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, selector TEXT NULL, validator_hash TEXT NULL, label TEXT NULL, created_at TEXT NULL, last_used_at TEXT NULL, expires_at TEXT NULL)');
    $pdo->exec('CREATE TABLE two_factor_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, ip TEXT NOT NULL, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE registration_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE pending_profile_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, action TEXT NOT NULL, data TEXT, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, duration_days INTEGER NULL, max_uses INTEGER NULL, current_uses INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, expires_at TEXT NULL)');
    $pdo->exec('CREATE TABLE redemption_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, voucher_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE pro_trial_claims (id INTEGER PRIMARY KEY AUTOINCREMENT, email_hash TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}

/** Alice (verified, password "correct-horse") plus the other states the answers depend on. */
function ms_auth_seed(PDO $pdo): array
{
    require_once dirname(__DIR__, 2) . '/pii_crypto.php';
    $add = function (string $email, ?string $password, ?string $verified, ?string $suspended = null) use ($pdo): int {
        $pdo->prepare('INSERT INTO pro_users (email, email_enc, email_hash, password_hash, email_verified_at, suspended_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$email, piiEmailEncrypt($email, MS_AUTH_ENC_KEY), piiEmailHash($email, MS_AUTH_IDX_KEY), $password === null ? null : password_hash($password, PASSWORD_DEFAULT), $verified, $suspended]);
        return (int) $pdo->lastInsertId();
    };
    return [
        'alice' => $add('alice@example.com', 'correct-horse', '2026-01-01 00:00:00'),
        'carol' => $add('carol@example.com', 'correct-horse', null),
        'dave'  => $add('dave@example.com', null, '2026-01-01 00:00:00'),
        'erin'  => $add('erin@example.com', 'correct-horse', '2026-01-01 00:00:00', '2026-01-02 00:00:00'),
    ];
}

/** Builds the probe docroot; returns its path. Remove it with ms_auth_probe_remove(). */
function ms_auth_probe_build(string $repoRoot): string
{
    $root = sys_get_temp_dir() . '/ms_auth_probe_' . bin2hex(random_bytes(6));
    mkdir($root);
    foreach (['register.php', 'pro_login.php', 'pro_logout.php', 'pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'referrals.php', 'login_tokens.php', 'email_log_ref.php', 'pii_crypto.php', 'pro_remember.php', 'mcp_tokens.php', 'after_login.php', 'i18n.php'] as $file) {
        if (!copy($repoRoot . '/' . $file, $root . '/' . $file)) {
            throw new RuntimeException("Could not copy {$file}");
        }
    }
    symlink($repoRoot . '/partials', $root . '/partials');
    symlink($repoRoot . '/assets', $root . '/assets');
    symlink($repoRoot . '/lang', $root . '/lang');
    file_put_contents($root . '/config.php', ms_auth_stub_config());
    // Prints [code => [key, English message]] for every VOUCHER_ERROR_* constant, plus the two edge inputs.
    file_put_contents($root . '/voucher_keys.php', "<?php\nrequire __DIR__ . '/config.php';\nrequire __DIR__ . '/pro_auth.php';\n\$out = [];\nforeach (get_defined_constants(true)['user'] as \$n => \$v) {\n    if (strpos(\$n, 'VOUCHER_ERROR_') === 0) { \$out[\$v] = [voucherRedemptionErrorKey(\$v), voucherRedemptionErrorMessage(\$v)]; }\n}\n\$out['@null'] = [voucherRedemptionErrorKey(null), voucherRedemptionErrorMessage(null)];\n\$out['@unknown'] = [voucherRedemptionErrorKey('nope'), voucherRedemptionErrorMessage('nope')];\necho json_encode(\$out);\n");
    // Prints a fresh magic-link token for the user id in argv[1] (the real createLoginToken()).
    file_put_contents($root . '/mk_token.php', "<?php\nrequire __DIR__ . '/config.php';\nrequire __DIR__ . '/pro_auth.php';\necho createLoginToken((int) \$argv[1]);\n");
    mkdir($root . '/sessions');
    $base = new PDO('sqlite:' . $root . '/base.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ms_auth_schema($base);
    $base = null;
    return $root;
}

function ms_auth_probe_remove(string $root): void
{
    foreach (['partials', 'assets', 'lang'] as $link) {
        @unlink($root . '/' . $link);
    }
    foreach (glob($root . '/sessions/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($root . '/sessions');
    foreach (glob($root . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($root);
}

/**
 * A fresh database for one case: the base schema, the seeded accounts, then
 * whatever $setup adds. Returns [dbPath, ids].
 *
 * @return array{0:string,1:array<string,int>}
 */
function ms_auth_probe_db(string $root, ?callable $setup = null): array
{
    $path = $root . '/case_' . bin2hex(random_bytes(4)) . '.sqlite';
    copy($root . '/base.sqlite', $path);
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ids = ms_auth_seed($pdo);
    if ($setup !== null) {
        $setup($pdo, $ids);
    }
    return [$path, $ids];
}

/**
 * Runs one script of the probe docroot as a request. $request keys: method,
 * uri, get, post, cookie, locales, trial, origin ('ok'|'bad'), session_id,
 * server, base_url; $env adds environment (the PII keys are always set unless
 * 'nokeys' is true).
 *
 * @return array{out:string,err:string,log:string}
 */
function ms_auth_probe_run(string $root, string $script, array $request, string $dbPath, bool $keys = true, array $env = [], array $args = []): array
{
    $logPath = $dbPath . '.log';
    @unlink($logPath);
    $environment = ['PROBE_SQLITE' => $dbPath, 'PROBE_LOG' => $logPath, 'PROBE_REQUEST' => json_encode($request), 'PATH' => (string) getenv('PATH'),
        'PRO_TRIAL_HASH_KEY' => MS_AUTH_HASH_KEY, 'TOTP_ENCRYPTION_KEY' => MS_AUTH_TOTP_KEY] + $env;
    if ($keys) {
        $environment += ['PII_ENCRYPTION_KEY' => MS_AUTH_ENC_KEY, 'PII_INDEX_KEY' => MS_AUTH_IDX_KEY];
    }
    // The trailing '#' comments out the -f envelope option mail() appends.
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'sendmail_path=cat >> ' . escapeshellarg($root . '/mail.log') . ' #', '-d', 'session.save_path=' . $root . '/sessions', $root . '/' . $script, ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return ['out' => (string) $out, 'err' => (string) $err, 'log' => (string) @file_get_contents($logPath)];
}

/** Writes a PHP session file, so a request can start with e.g. pending_2fa set. */
function ms_auth_probe_session(string $root, string $id, array $data): void
{
    $encoded = '';
    foreach ($data as $key => $value) {
        $encoded .= $key . '|' . serialize($value);
    }
    file_put_contents($root . '/sessions/sess_' . $id, $encoded);
}

/**
 * Every pro_auth.php answer that carries an error or a message, one entry per
 * distinct body: name => [request, setup|null, options]. The names are the
 * fixture names (tests/fixtures/i18n/pro_auth.<name>.en.json).
 *
 * @return array<string,array{request:array<string,mixed>,setup:?callable,keys:bool,session:?array,env:array<string,string>}>
 */
function ms_auth_json_cases(): array
{
    $post = static fn(array $p, array $extra = []): array => ['method' => 'POST', 'post' => $p] + $extra;
    $voucher = static fn(PDO $pdo, array $v = []) => $pdo->prepare('INSERT INTO vouchers (code, duration_days, max_uses, current_uses, is_active, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$v['code'] ?? 'GOODCODE', array_key_exists('days', $v) ? $v['days'] : 30, $v['max'] ?? 5, $v['used'] ?? 0, $v['active'] ?? 1, $v['expires'] ?? null]);
    $case = static fn(array $request, ?callable $setup = null, bool $keys = true, ?array $session = null, array $env = []): array
        => ['request' => $request, 'setup' => $setup, 'keys' => $keys, 'session' => $session, 'env' => $env];
    $register = static fn(array $over = []): array => $over + ['action' => 'register_account', 'plan' => 'regular', 'email' => 'newbie@example.com', 'password' => 'long-enough-1', 'password_confirm' => 'long-enough-1'];
    $login = static fn(array $over = []): array => $over + ['action' => 'password_login', 'email' => 'alice@example.com', 'password' => 'correct-horse'];
    $pending = static fn(string $who): array => ['pending_2fa' => ['user_id' => $who, 'email' => 'x@example.com', 'created_at' => time(), 'stay_days' => 0]];

    $cases = [];

    // request_login_link
    $cases['login_link.invalid_email'] = $case($post(['action' => 'request_login_link', 'email' => 'not-an-address']));

    // redeem_voucher: the success message and every voucher error
    $cases['voucher.redeemed'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo));
    $cases['voucher.invalid_email'] = $case($post(['action' => 'redeem_voucher', 'email' => 'nope', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo));
    $cases['voucher.invalid_code_format'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'bad code!']));
    $cases['voucher.invalid_or_expired_code'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'NOSUCHCODE']));
    $cases['voucher.code_not_active'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo, ['active' => 0]));
    $cases['voucher.code_expired'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo, ['expires' => '2020-01-01 00:00:00']));
    $cases['voucher.code_fully_redeemed'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo, ['max' => 1, 'used' => 1]));
    $cases['voucher.domain_not_allowed'] = $case($post(['action' => 'redeem_voucher', 'email' => 'someone@manjo.me', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo));
    $cases['voucher.already_redeemed'] = $case($post(['action' => 'redeem_voucher', 'email' => 'alice@example.com', 'code' => 'GOODCODE']), function ($pdo, $ids) use ($voucher) {
        $voucher($pdo);
        $pdo->prepare('INSERT INTO redemption_log (user_id, voucher_id) VALUES (?, 1)')->execute([$ids['alice']]);
    });
    $cases['voucher.already_lifetime'] = $case($post(['action' => 'redeem_voucher', 'email' => 'alice@example.com', 'code' => 'GOODCODE']), function ($pdo, $ids) use ($voucher) {
        $voucher($pdo);
        $pdo->prepare("UPDATE pro_users SET account_type = 'pro', pro_expires_at = NULL WHERE id = ?")->execute([$ids['alice']]);
    });
    $cases['voucher.redemption_failed'] = $case($post(['action' => 'redeem_voucher', 'email' => 'fresh@example.com', 'code' => 'GOODCODE']), fn($pdo) => $voucher($pdo), false);

    // register_account
    $cases['register.suspicious'] = $case($post($register(['email' => 'a<script>@example.com'])));
    $cases['register.invalid_email'] = $case($post($register(['email' => 'not-an-address'])));
    $cases['register.passwords_mismatch'] = $case($post($register(['password_confirm' => 'something-else-1'])));
    $cases['register.password_short'] = $case($post($register(['password' => 'short', 'password_confirm' => 'short'])));
    $cases['register.password_long'] = $case($post($register(['password' => str_repeat('p', 257), 'password_confirm' => str_repeat('p', 257)])));
    $cases['register.voucher_required'] = $case($post($register(['plan' => 'pro', 'code' => ''])));
    $cases['register.unavailable'] = $case($post($register()), null, false);
    $cases['register.check_email'] = $case($post($register()));
    $cases['register.pro_bad_code'] = $case($post($register(['plan' => 'pro', 'code' => 'NOSUCHCODE'])));

    // password_login
    $cases['password.bad_origin'] = $case($post($login(), ['origin' => 'bad']));
    $cases['password.invalid_request'] = $case($post($login(['password' => ''])));
    $cases['password.throttled_ip'] = $case($post($login()), function ($pdo) {
        for ($i = 0; $i < 5; $i++) {
            $pdo->prepare("INSERT INTO login_attempts (ip, email, success) VALUES ('10.0.0.1', 'x', 0)")->execute();
        }
    });
    $cases['password.throttled_account'] = $case($post($login()), function ($pdo) {
        require_once dirname(__DIR__, 2) . '/email_log_ref.php';
        $ref = emailLogRef('alice@example.com', MS_AUTH_HASH_KEY);
        for ($i = 0; $i < 10; $i++) {
            $pdo->prepare("INSERT INTO login_attempts (ip, email, success) VALUES ('10.9.9.9', ?, 0)")->execute([$ref]);
        }
    });
    $cases['password.incorrect_unknown'] = $case($post($login(['email' => 'nobody@example.com'])));
    $cases['password.incorrect_no_password'] = $case($post($login(['email' => 'dave@example.com'])));
    $cases['password.incorrect_wrong'] = $case($post($login(['password' => 'wrong-horse'])));
    $cases['password.incorrect_unverified'] = $case($post($login(['email' => 'carol@example.com'])));
    $cases['password.suspended'] = $case($post($login(['email' => 'erin@example.com'])));

    // verify_2fa / cancel_2fa
    $cases['twofa.bad_origin'] = $case($post(['action' => 'verify_2fa', 'code' => '123456'], ['origin' => 'bad']));
    $cases['twofa.session_expired'] = $case($post(['action' => 'verify_2fa', 'code' => '123456']));
    $cases['twofa.suspended'] = $case($post(['action' => 'verify_2fa', 'code' => '123456']), null, true, ['pending_user' => 'erin']);
    $cases['twofa.blocked'] = $case($post(['action' => 'verify_2fa', 'code' => '123456']), function ($pdo, $ids) {
        for ($i = 0; $i < 5; $i++) {
            $pdo->prepare("INSERT INTO two_factor_attempts (user_id, ip, success) VALUES (?, '10.9.9.9', 0)")->execute([$ids['alice']]);
        }
    }, true, ['pending_user' => 'alice']);
    $cases['twofa.incorrect'] = $case($post(['action' => 'verify_2fa', 'code' => '123456']), null, true, ['pending_user' => 'alice']);
    $cases['twofa.cancel_bad_origin'] = $case($post(['action' => 'cancel_2fa'], ['origin' => 'bad']));

    // No endpoint matches
    $cases['fallback.no_action'] = $case(['method' => 'POST', 'post' => ['action' => 'nothing']]);

    return $cases;
}

/**
 * Renders one page of the probe docroot (register.php, pro_login.php or
 * pro_logout.php) at $uri — '/register.php', '/sv/register.php' — and returns
 * the HTML. $locales is the LOCALES_ENABLED value.
 *
 * @return array{html:string,err:string,log:string}
 */
function ms_auth_page(string $root, string $script, string $uri, array $get = [], int $trial = 60, string $locales = 'en'): array
{
    [$db] = ms_auth_probe_db($root);
    $run = ms_auth_probe_run($root, $script, ['method' => 'GET', 'uri' => $uri, 'get' => $get, 'trial' => $trial, 'locales' => $locales], $db);
    @unlink($db);
    @unlink($db . '.log');
    return ['html' => $run['out'], 'err' => $run['err'], 'log' => $run['log']];
}

/** [fixture name => [script, uri, get, trial]] for every English page state. */
function ms_auth_page_cases(): array
{
    $cases = [];
    foreach (['trial' => 60, 'notrial' => 0] as $t => $days) {
        foreach (['regular', 'pro'] as $plan) {
            $cases["register.{$plan}.{$t}"] = ['register.php', '/register.php', ['plan' => $plan], $days];
        }
        $cases["pro_login.plain.{$t}"] = ['pro_login.php', '/pro_login.php', [], $days];
        $cases["pro_login.token_invalid.{$t}"] = ['pro_login.php', '/pro_login.php', ['token' => 'not-a-valid-token'], $days];
    }
    $cases['register.unknown_plan.trial'] = ['register.php', '/register.php', ['plan' => 'bogus'], 60];
    $cases['pro_logout'] = ['pro_logout.php', '/pro_logout.php', [], 60];
    return $cases;
}

/**
 * Runs one JSON case; returns the response body (everything up to a trailing
 * newline) and the raw run. $headerLocale, when set, is sent as X-MS-Locale.
 *
 * @return array{body:string,run:array{out:string,err:string,log:string},ids:array<string,int>}
 */
function ms_auth_json_run(string $root, array $case, ?string $headerLocale, string $locales = 'en'): array
{
    [$db, $ids] = ms_auth_probe_db($root, $case['setup']);
    $request = $case['request'] + ['locales' => $locales, 'uri' => '/pro_auth.php'];
    if ($headerLocale !== null) {
        $request['server'] = ($request['server'] ?? []) + ['HTTP_X_MS_LOCALE' => $headerLocale];
    }
    if (is_array($case['session'])) {
        $id = 'probesess' . bin2hex(random_bytes(4));
        ms_auth_probe_session($root, $id, ['pending_2fa' => [
            'user_id' => $ids[$case['session']['pending_user']], 'email' => 'x@example.com', 'created_at' => time(), 'stay_days' => 0,
        ]]);
        $request['session_id'] = $id;
    }
    $run = ms_auth_probe_run($root, 'pro_auth.php', $request, $db, $case['keys'], $case['env']);
    @unlink($db);
    @unlink($db . '.log');
    return ['body' => trim($run['out']), 'run' => $run, 'ids' => $ids];
}
