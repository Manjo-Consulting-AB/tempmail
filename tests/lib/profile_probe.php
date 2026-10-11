<?php
/**
 * Probe for the profile page and its JSON endpoint (epic #428, step 4/8): the
 * real pro_profile_page.php and pro_profile.php run as CLI subprocesses in the
 * throwaway docroot of tests/lib/app_actions_probe.php (which patches the stub
 * config.php so a request can carry a header and switch Swedish on), with a
 * few more patches to its config.php and schema:
 *
 *  - the PII keys, so an account's address can be found and decrypted
 *    (switch `nokeys` leaves them out);
 *  - a PDO that drops `FOR UPDATE`, so the real redeemVoucherForEmail() runs on SQLite;
 *  - `origin_flip`: the second same-origin check of a request fails, which is the
 *    only way to reach the per-action "Invalid request origin" answer (the gate
 *    at the top of pro_profile.php has already passed by then);
 *  - `referral`: the referral programme switched on.
 *
 * Shared by tests/i18n_test.php and the fixture generation
 * (tests/fixtures/i18n/profile.*.en.{html,json}). Nothing here is deployed.
 */

require_once __DIR__ . '/app_actions_probe.php';
require_once __DIR__ . '/auth_probe.php';

/**
 * Builds the probe docroot and its seeded SQLite database.
 *
 * @return array{root:string,ids:array<string,int>}
 */
function ms_pp_build(string $repoRoot): array
{
    require_once $repoRoot . '/pii_crypto.php';
    $probe = ms_aa_build($repoRoot);
    $root = $probe['root'];
    foreach (['paddle_api.php'] as $extra) {
        if (is_file($repoRoot . '/' . $extra)) {
            copy($repoRoot . '/' . $extra, $root . '/' . $extra);
        }
    }

    $config = (string) file_get_contents($root . '/config.php');
    $config = str_replace(
        '$pdo = new PDO(',
        "final class MsProbePdo extends PDO {\n    public function prepare(string \$query, array \$options = []): PDOStatement|false {\n        return parent::prepare(str_replace(' FOR UPDATE', '', \$query), \$options);\n    }\n}\n\$pdo = new MsProbePdo(",
        $config
    );
    $config = str_replace(
        "function requireSameOriginRequest(): bool {\n    global \$config;",
        "function requireSameOriginRequest(): bool {\n    global \$config;\n    static \$calls = 0;\n    \$calls++;\n    if (!empty(\$GLOBALS['MS_EXTRA']['origin_flip']) && \$calls > 1) return false;",
        $config
    );
    $config .= "\n// Profile probe (tests/lib/profile_probe.php).\n"
        . "if (empty(\$MS_EXTRA['nokeys'])) {\n"
        . "    putenv('PII_ENCRYPTION_KEY=" . MS_AUTH_ENC_KEY . "'); \$_ENV['PII_ENCRYPTION_KEY'] = '" . MS_AUTH_ENC_KEY . "';\n"
        . "    putenv('PII_INDEX_KEY=" . MS_AUTH_IDX_KEY . "'); \$_ENV['PII_INDEX_KEY'] = '" . MS_AUTH_IDX_KEY . "';\n"
        . "}\n"
        . "if (!empty(\$MS_EXTRA['referral'])) { \$config['referral'] = ['enabled' => true]; }\n"
        // The real helper reads PADDLE_ENVIRONMENT; here no Paddle is configured, so a portal URL cannot be minted.
        . "if (!function_exists('paddleClientSettings')) { function paddleClientSettings(array \$config): array { throw new RuntimeException('PADDLE_ENVIRONMENT is not set'); } }\n";
    file_put_contents($root . '/config.php', $config);

    $pdo = ms_test_db(ms_test_probe_sqlite($root));
    foreach ([
        'ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN email_hash TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN locale TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN password_hash TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN referral_code TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN referral_pending_code TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN bonus_sticky_slots INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE pending_profile_changes ADD COLUMN action TEXT NULL',
        'ALTER TABLE pending_profile_changes ADD COLUMN data TEXT NULL',
        'ALTER TABLE pending_profile_changes ADD COLUMN token TEXT NULL',
        'ALTER TABLE pending_profile_changes ADD COLUMN expires_at TEXT NULL',
        'ALTER TABLE pending_profile_changes ADD COLUMN created_at TEXT NULL',
        'CREATE TABLE retention_holds (id INTEGER PRIMARY KEY AUTOINCREMENT, pro_user_id INTEGER NOT NULL, started_at TEXT NOT NULL, ends_at TEXT NOT NULL, ended_at TEXT NULL)',
        'CREATE TABLE vouchers (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, duration_days INTEGER NULL, max_uses INTEGER NULL, current_uses INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, expires_at TEXT NULL)',
        'CREATE TABLE redemption_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, voucher_id INTEGER NOT NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    $probe['ids']['trial'] = ms_test_seed_user($pdo, 'trial@example.com', 'pro', '2099-06-15 12:00:00');
    $set = $pdo->prepare('UPDATE pro_users SET email_enc = ?, email_hash = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, email FROM pro_users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $set->execute([piiEmailEncrypt($row['email'], MS_AUTH_ENC_KEY), piiEmailHash($row['email'], MS_AUTH_IDX_KEY), $row['id']]);
    }
    $pdo = null;

    return $probe;
}

/** Back to the seeded state, then the case's own setup. */
function ms_pp_reset(array $probe, ?callable $setup = null): void
{
    $pdo = ms_test_db(ms_test_probe_sqlite($probe['root']));
    foreach (['pending_profile_changes', 'retention_holds', 'vouchers', 'redemption_log'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    foreach (['paddle_subscriptions', 'paddle_transactions', 'referrals'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    $pdo->exec('UPDATE pro_users SET locale = NULL, bonus_sticky_slots = 0, address_ttl_days = 1, referral_code = NULL, referral_pending_code = NULL');
    if ($setup !== null) {
        $setup($pdo, $probe['ids']);
    }
    $pdo = null;
}

/** The Paddle tables with one linked subscription for $userId. */
function ms_pp_paddle_rows(PDO $pdo, int $userId): void
{
    $pdo->exec('CREATE TABLE paddle_subscriptions (subscription_id TEXT PRIMARY KEY, customer_id TEXT, pro_user_id INTEGER, status TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE paddle_transactions (transaction_id TEXT PRIMARY KEY, customer_id TEXT, pro_user_id INTEGER, updated_at TEXT)');
    $pdo->prepare("INSERT INTO paddle_subscriptions VALUES ('sub_1', 'ctm_01abcdefghjkmnpqrstvwxyz12', ?, 'active', '2026-01-01 00:00:00')")->execute([$userId]);
}

/** The referrals table, one friend who joined through $referrerId's link, and codes for the two. */
function ms_pp_referral_rows(PDO $pdo, int $referrerId, int $refereeId): void
{
    if (!defined('TEMPMAIL_APP')) {
        define('TEMPMAIL_APP', true);
    }
    require_once dirname(__DIR__, 2) . '/referrals.php';
    foreach (referralSchemaStatements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $pdo->prepare("UPDATE pro_users SET referral_code = ? WHERE id = ?")->execute(['abcd2345', $referrerId]);
    $pdo->prepare("UPDATE pro_users SET referral_code = ? WHERE id = ?")->execute(['wxyz6789', $refereeId]);
    $pdo->prepare("INSERT INTO referrals (referrer_id, referee_id, status, created_at, window_ends_at, updated_at) VALUES (?, ?, 'joined', '2026-01-01 00:00:00', '2099-12-31 12:00:00', '2026-01-01 00:00:00')")
        ->execute([$referrerId, $refereeId]);
}

/**
 * [fixture name => [user key, setup|null, extra switches]] for the page
 * (tests/fixtures/i18n/profile.page.<name>.en.html).
 *
 * @return array<string,array{0:string,1:?callable,2:array<string,mixed>}>
 */
function ms_pp_page_cases(): array
{
    return [
        'pro' => ['trial', static fn(PDO $pdo, array $ids) => ms_pp_paddle_rows($pdo, $ids['trial']), []],
        'regular' => ['regular', null, []],
        'lifetime' => ['alice', null, []],
        'pending' => ['trial', static function (PDO $pdo, array $ids): void {
            $pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at, created_at) VALUES (?, 'update_email', ?, 't1', '2099-01-02 10:00:00', '2099-01-01 10:00:00')")
                ->execute([$ids['trial'], json_encode(['new_email' => 'new@example.com'])]);
        }, []],
        'referral' => ['trial', static function (PDO $pdo, array $ids): void {
            ms_pp_referral_rows($pdo, $ids['alice'], $ids['trial']);
            $pdo->exec('UPDATE pro_users SET bonus_sticky_slots = 3 WHERE id = ' . (int) $ids['trial']);
        }, ['referral' => true]],
        'referral_lifetime' => ['alice', static function (PDO $pdo, array $ids): void {
            ms_pp_referral_rows($pdo, $ids['alice'], $ids['trial']);
        }, ['referral' => true]],
    ];
}

/**
 * [name => [post fields, user key (null = signed out), setup|null, switches]] for
 * every pro_profile.php answer that carries an `error` or a `message`
 * (tests/fixtures/i18n/profile.<name>.en.json). Switches: origin (the request's
 * Origin), method, nokeys, origin_flip.
 *
 * @return array<string,array{0:array<string,string>,1:?string,2:?callable,3:array<string,mixed>}>
 */
function ms_pp_json_cases(): array
{
    $pending = static fn(string $action, string $data, int $used = 0): callable => static function (PDO $pdo, array $ids) use ($action, $data, $used): void {
        $pdo->prepare("INSERT INTO pending_profile_changes (id, user_id, action, data, token, expires_at, created_at, used) VALUES (5, ?, ?, ?, 't5', '2099-01-02 10:00:00', '2099-01-01 10:00:00', ?)")
            ->execute([$ids['trial'], $action, $data, $used]);
    };
    $voucher = static fn(?int $days): callable => static function (PDO $pdo, array $ids) use ($days): void {
        $pdo->prepare("INSERT INTO vouchers (code, duration_days, max_uses, current_uses, is_active) VALUES ('GOODCODE', ?, 5, 0, 1)")->execute([$days]);
    };
    $hold = static fn(int $count, bool $active): callable => static function (PDO $pdo, array $ids) use ($count, $active): void {
        $ins = $pdo->prepare('INSERT INTO retention_holds (pro_user_id, started_at, ends_at, ended_at) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $last = $i === $count - 1;
            $start = date('Y-m-d H:i:s', time() - 86400 * (10 + $i));
            $ends = date('Y-m-d H:i:s', time() + (($last && $active) ? 86400 * 20 : -86400));
            $ins->execute([$ids['trial'], $start, $ends, ($last && $active) ? null : $start]);
        }
    };
    return [
        'shared.not_authenticated' => [['action' => 'get_profile'], null, null, []],
        'shared.method_not_allowed' => [['action' => 'update_ttl'], 'trial', null, ['method' => 'GET']],
        'shared.forbidden' => [['action' => 'update_ttl', 'ttl' => '2'], 'trial', null, ['origin' => 'http://evil.test']],
        'shared.pro_required' => [['action' => 'update_ttl', 'ttl' => '2'], 'regular', null, []],
        'shared.unknown_action' => [['action' => 'nothing'], 'trial', null, []],
        'shared.invalid_origin' => [['action' => 'update_email', 'email' => 'x@example.org'], 'trial', null, ['origin_flip' => true]],
        'get_profile.user_not_found' => [['action' => 'get_profile'], 'ghost', null, ['method' => 'GET']],
        'update_email.suspicious' => [['action' => 'update_email', 'email' => 'myscript@example.org'], 'trial', null, []],
        'update_email.invalid' => [['action' => 'update_email', 'email' => 'not-an-email'], 'trial', null, []],
        'update_email.own_domain' => [['action' => 'update_email', 'email' => 'someone@manjo.me'], 'trial', null, []],
        'update_email.unavailable' => [['action' => 'update_email', 'email' => 'x@example.org'], 'trial', null, ['nokeys' => true]],
        'update_email.in_use' => [['action' => 'update_email', 'email' => 'regular@example.com'], 'trial', null, []],
        'update_email.sent' => [['action' => 'update_email', 'email' => 'fresh@example.org'], 'trial', null, []],
        'upgrade_with_voucher.user_not_found' => [['action' => 'upgrade_with_voucher', 'code' => 'GOODCODE'], 'ghost', null, []],
        'upgrade_with_voucher.invalid_code_format' => [['action' => 'upgrade_with_voucher', 'code' => 'bad code!'], 'regular', null, []],
        'upgrade_with_voucher.invalid_or_expired_code' => [['action' => 'upgrade_with_voucher', 'code' => 'NOSUCHCODE'], 'regular', null, []],
        'upgrade_with_voucher.already_lifetime' => [['action' => 'upgrade_with_voucher', 'code' => 'GOODCODE'], 'alice', $voucher(30), []],
        'billing_portal.unavailable' => [['action' => 'billing_portal'], 'trial', null, []],
        'billing_portal.no_subscription' => [['action' => 'billing_portal'], 'regular', static fn(PDO $pdo, array $ids) => ms_pp_paddle_rows($pdo, $ids['trial']), []],
        'billing_portal.failed' => [['action' => 'billing_portal'], 'trial', static fn(PDO $pdo, array $ids) => ms_pp_paddle_rows($pdo, $ids['trial']), []],
        'resend_pending_change.invalid_id' => [['action' => 'resend_pending_change', 'id' => '0'], 'trial', null, []],
        'resend_pending_change.not_found' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', null, []],
        'resend_pending_change.processed' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', $pending('update_email', json_encode(['new_email' => 'n@example.org']), 1), []],
        'resend_pending_change.email_missing' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', $pending('update_email', '{}'), []],
        'resend_pending_change.email_sent' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', $pending('update_email', json_encode(['new_email' => 'n@example.org'])), []],
        'resend_pending_change.password_sent' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', $pending('set_password', '{}'), []],
        'resend_pending_change.delete_sent' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', $pending('delete_account', '{}'), []],
        'resend_pending_change.unsupported' => [['action' => 'resend_pending_change', 'id' => '5'], 'trial', $pending('something_else', '{}'), []],
        'cancel_pending_change.invalid_id' => [['action' => 'cancel_pending_change', 'id' => 'x'], 'trial', null, []],
        'cancel_pending_change.not_found' => [['action' => 'cancel_pending_change', 'id' => '5'], 'trial', null, []],
        'cancel_pending_change.processed' => [['action' => 'cancel_pending_change', 'id' => '5'], 'trial', $pending('update_email', '{}', 1), []],
        'update_ttl.range' => [['action' => 'update_ttl', 'ttl' => '9'], 'trial', null, []],
        'retention_hold_start.active' => [['action' => 'retention_hold_start'], 'trial', $hold(1, true), []],
        'retention_hold_start.used_up' => [['action' => 'retention_hold_start'], 'trial', $hold(4, false), []],
        'retention_hold_end.none' => [['action' => 'retention_hold_end'], 'trial', null, []],
    ];
}

/**
 * Runs one request of the profile probe. $extra switches: server (headers),
 * locales, app_sv, nokeys, origin_flip, referral. Returns the raw request
 * result plus the trimmed body.
 *
 * @param array{root:string,ids:array<string,int>} $probe
 * @param array<string,mixed> $request ms_test_request() keys (page, method, user key as 'user', post, get, origin)
 * @param array<string,mixed> $extra
 * @return array{body:string,json:?array,res:array<string,mixed>}
 */
function ms_pp_run(array $probe, array $request, ?callable $setup = null, array $extra = []): array
{
    ms_pp_reset($probe, $setup);
    file_put_contents($probe['root'] . '/probe_extra.json', json_encode($extra + ['server' => [], 'locales' => 'en', 'app_sv' => false]));
    $user = $request['user'] ?? null;
    unset($request['user']);
    $userId = $user === null ? 0 : ($probe['ids'][$user] ?? 9999);
    $request += ['user_id' => $userId, 'user_email' => $user === null ? '' : ($user === 'ghost' ? '' : $user . '@example.com')];
    // The probe's seeded users are alice@, regular@, capper@ and trial@example.com.
    $res = ms_test_request($probe['root'], $request);
    return ['body' => trim((string) $res['stdout']), 'json' => $res['json'], 'res' => $res];
}

/** One JSON case by name. $locale is sent as X-MS-Locale (and switches Swedish on). */
function ms_pp_json(array $probe, string $name, ?string $locale = null): array
{
    [$post, $user, $setup, $switches] = ms_pp_json_cases()[$name];
    $server = $locale !== null ? ['HTTP_X_MS_LOCALE' => $locale] : [];
    $request = [
        'page' => 'pro_profile.php',
        'method' => $switches['method'] ?? 'POST',
        'user' => $user,
        'post' => ($switches['method'] ?? 'POST') === 'GET' ? [] : $post,
        'get' => ($switches['method'] ?? 'POST') === 'GET' ? $post : [],
    ];
    if (isset($switches['origin'])) {
        $request['origin'] = $switches['origin'];
    }
    $extra = ['server' => $server, 'locales' => $locale !== null ? 'en,sv' : 'en', 'app_sv' => $locale !== null]
        + array_intersect_key($switches, ['nokeys' => 1, 'origin_flip' => 1]);
    return ms_pp_run($probe, $request, $setup, $extra);
}

/**
 * The page for one case. $acceptLanguage is sent as Accept-Language; $svApp
 * offers Swedish in the app area; $locale stores the account's language first.
 *
 * @return array{body:string,json:?array,res:array<string,mixed>}
 */
function ms_pp_page(array $probe, string $name, bool $svApp = false, string $acceptLanguage = '', ?string $accountLocale = null): array
{
    [$user, $setup, $switches] = ms_pp_page_cases()[$name];
    $combined = static function (PDO $pdo, array $ids) use ($setup, $accountLocale, $user): void {
        if ($setup !== null) {
            $setup($pdo, $ids);
        }
        if ($accountLocale !== null) {
            $pdo->prepare('UPDATE pro_users SET locale = ? WHERE id = ?')->execute([$accountLocale, $ids[$user]]);
        }
    };
    $server = $acceptLanguage !== '' ? ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage] : [];
    $extra = ['server' => $server, 'locales' => $svApp ? 'en,sv' : 'en', 'app_sv' => $svApp] + $switches;
    return ms_pp_run($probe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user' => $user], $combined, $extra);
}
