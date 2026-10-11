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
        "final class MsProbePdo extends PDO {\n"
        . "    public function prepare(string \$query, array \$options = []): PDOStatement|false {\n"
        . "        return parent::prepare(str_replace([' FOR UPDATE', 'DATE_SUB(NOW(), INTERVAL ? MINUTE)'], ['', 'PROBE_MINUTES_AGO(?)'], \$query), \$options);\n    }\n"
        // TwoFactorAuth / pro_profile.php emit MySQL DDL (ENUM, ENGINE=InnoDB) SQLite cannot parse; the tables exist already.
        . "    public function exec(string \$statement): int|false {\n"
        . "        if (preg_match('/^\\\\s*CREATE\\\\s+TABLE\\\\s+IF\\\\s+NOT\\\\s+EXISTS\\\\b/i', \$statement) && stripos(\$statement, 'ENGINE=') !== false) return 0;\n"
        . "        return parent::exec(\$statement);\n    }\n"
        // set_password asks MySQL's SHOW COLUMNS and reads rowCount(); SQLite has no such statement.
        . "    public function query(string \$query, ?int \$fetchMode = null, mixed ...\$args): PDOStatement|false {\n"
        . "        if (preg_match(\"/^\\\\s*SHOW COLUMNS FROM pro_users LIKE 'password_hash'/i\", \$query)) {\n"
        . "            \$query = \"UPDATE pro_users SET id = id WHERE id = (SELECT MIN(id) FROM pro_users) AND EXISTS (SELECT 1 FROM pragma_table_info('pro_users') WHERE name = 'password_hash')\";\n"
        . "        }\n"
        . "        return \$fetchMode === null ? parent::query(\$query) : parent::query(\$query, \$fetchMode, ...\$args);\n    }\n}\n"
        . "\$pdo = new MsProbePdo(",
        $config
    );
    $config = str_replace(
        "function requireSameOriginRequest(): bool {\n    global \$config;",
        "function requireSameOriginRequest(): bool {\n    global \$config;\n    static \$calls = 0;\n    \$calls++;\n    if (!empty(\$GLOBALS['MS_EXTRA']['origin_flip']) && \$calls > 1) return false;",
        $config
    );
    // The harness stub accepts every URL; this one refuses a loopback host, like the real check, and gives a port.
    $config = str_replace("function resolveUrlToPublicTarget(...\$args) { return ['ip' => '127.0.0.1', 'host' => 'localhost']; }\n", "function resolveUrlToPublicTarget(\$url, ...\$rest) { \$h = parse_url(\$url, PHP_URL_HOST); return in_array(\$h, ['127.0.0.1', 'localhost'], true) ? null : ['ip' => \$h, 'host' => \$h, 'port' => 443]; }\n", $config);
    $config .= "\n// Profile probe (tests/lib/profile_probe.php).\n"
        . "if (empty(\$MS_EXTRA['nokeys'])) {\n"
        . "    putenv('PII_ENCRYPTION_KEY=" . MS_AUTH_ENC_KEY . "'); \$_ENV['PII_ENCRYPTION_KEY'] = '" . MS_AUTH_ENC_KEY . "';\n"
        . "    putenv('PII_INDEX_KEY=" . MS_AUTH_IDX_KEY . "'); \$_ENV['PII_INDEX_KEY'] = '" . MS_AUTH_IDX_KEY . "';\n"
        . "}\n"
        . "if (!empty(\$MS_EXTRA['referral'])) { \$config['referral'] = ['enabled' => true]; }\n"
        // Step 6/8: OAuth discovery on (OAUTH_ENABLED; the page also needs oauth_clients, see ms_pp_oauth_on()), the TOTP key and SQLite's stand-in for DATE_SUB.
        . "if (!empty(\$MS_EXTRA['oauth'])) { \$config['oauth'] = ['enabled' => true]; }\n"
        . "putenv('TOTP_ENCRYPTION_KEY=" . MS_AUTH_TOTP_KEY . "'); \$_ENV['TOTP_ENCRYPTION_KEY'] = '" . MS_AUTH_TOTP_KEY . "';\n"
        . "\$pdo->sqliteCreateFunction('PROBE_MINUTES_AGO', static fn(\$m): string => date('Y-m-d H:i:s', time() - (int) \$m * 60));\n"
        // The real helper reads PADDLE_ENVIRONMENT; here no Paddle is configured, so a portal URL cannot be minted.
        . "if (!function_exists('paddleClientSettings')) { function paddleClientSettings(array \$config): array { throw new RuntimeException('PADDLE_ENVIRONMENT is not set'); } }\n";
    file_put_contents($root . '/config.php', $config);

    $pdo = ms_test_db(ms_test_probe_sqlite($root));
    foreach ([
        'ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN email_hash TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN locale TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN password_hash TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN digest_enabled INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE pro_users ADD COLUMN digest_frequency_days INTEGER NULL',
        'ALTER TABLE pro_users ADD COLUMN digest_last_sent TEXT NULL',
        'ALTER TABLE pro_users ADD COLUMN digest_hour INTEGER NULL',
        'ALTER TABLE pro_users ADD COLUMN digest_tz TEXT NULL',
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
    // A case may drop the locale column (the fail-open path); the next one starts with it again.
    if ((int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('pro_users') WHERE name = 'locale'")->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE pro_users ADD COLUMN locale TEXT NULL');
    }
    // Step 5/8 cases break and extend the schema; every request starts from the seeded one.
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'ms_fail%'")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
        $pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger);
    }
    foreach (['pro_webhooks', 'pro_webhook_deliveries'] as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$table}_gone'")->fetchColumn() === 1) {
            $pdo->exec("ALTER TABLE {$table}_gone RENAME TO {$table}");
        }
    }
    ms_pp_security_drop($pdo);
    $pdo->exec('DROP TABLE IF EXISTS pro_webhook_addresses');
    foreach ([['pro_webhooks', 'include_temporary'], ['temp_emails', 'hooks_paused'], ['pro_users', 'feed_token_hash'], ['pro_users', 'feed_token_enc'], ['temp_emails', 'feed_token_hash'], ['temp_emails', 'feed_token_enc']] as [$table, $column]) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('{$table}') WHERE name = '{$column}'")->fetchColumn() === 1) {
            $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
    foreach (['pro_webhook_deliveries', 'pro_webhooks'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name = '{$table}'");
    }
    $pdo->exec('DELETE FROM temp_emails WHERE is_personal = 1');
    foreach (['pending_profile_changes', 'retention_holds', 'vouchers', 'redemption_log'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    foreach (['paddle_subscriptions', 'paddle_transactions', 'referrals'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    $pdo->exec('UPDATE pro_users SET locale = NULL, bonus_sticky_slots = 0, address_ttl_days = 1, referral_code = NULL, referral_pending_code = NULL');
    // A case may drop password_hash (set_password's unavailable branch); every request starts with it, unset.
    if ((int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('pro_users') WHERE name = 'password_hash'")->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE pro_users ADD COLUMN password_hash TEXT NULL');
    }
    $pdo->exec('UPDATE pro_users SET password_hash = NULL');
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
    [$post, $user, $setup, $switches] = (ms_pp_json_cases() + ms_pp_automation_json_cases() + ms_pp_security_json_cases())[$name];
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
 * offers Swedish in the app area; $accountLocale stores the account's language first;
 * $locales overrides LOCALES_ENABLED (e.g. 'en,sv' with the app area still English).
 *
 * @return array{body:string,json:?array,res:array<string,mixed>}
 */
function ms_pp_page(array $probe, string $name, bool $svApp = false, string $acceptLanguage = '', ?string $accountLocale = null, ?string $locales = null): array
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
    $extra = ['server' => $server, 'locales' => $locales ?? ($svApp ? 'en,sv' : 'en'), 'app_sv' => $svApp] + $switches;
    return ms_pp_run($probe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user' => $user], $combined, $extra);
}

/** The per-hook routing schema (migrate_webhook_addresses.php) on top of the probe's pre-migration one. */
function ms_pp_routing_on(PDO $pdo): void
{
    $pdo->exec(ms_test_webhook_address_schema());
}

/** feed_token_hash / feed_token_enc on both tables (migrate_feed_token_encryption.php). */
function ms_pp_feed_columns_on(PDO $pdo): void
{
    foreach (['pro_users', 'temp_emails'] as $table) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN feed_token_hash TEXT NULL");
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN feed_token_enc TEXT NULL");
    }
}

/** One Sticky address of the trial account, with the fixed id 900 the cases post. */
function ms_pp_sticky_address(PDO $pdo, array $ids): void
{
    $id = ms_test_seed_address($pdo, 'mine', ['pro_user_id' => $ids['trial'], 'is_personal' => 1]);
    $pdo->exec('UPDATE temp_emails SET id = 900 WHERE id = ' . (int) $id);
}

/**
 * Epic #428 step 5/8: the Automation section. [name => [user key, setup, switches]]
 * for tests/fixtures/i18n/profile.automation.<name>.en.html, which holds just the
 * <section id="settings-automation"> of the page (the hook list itself is drawn by
 * the page's script from webhooks_list, so the markup is the same with or without
 * hooks; the second case proves that and seeds a generic and a Pushover hook).
 *
 * @return array<string,array{0:string,1:?callable,2:array<string,mixed>}>
 */
function ms_pp_automation_page_cases(): array
{
    return [
        'none' => ['trial', static fn(PDO $pdo, array $ids) => ms_pp_routing_on($pdo), []],
        'hooks' => ['trial', static function (PDO $pdo, array $ids): void {
            ms_pp_routing_on($pdo);
            ms_test_seed_webhook($pdo, $ids['trial'], 'generic', 'all', 'My endpoint');
            ms_test_seed_webhook($pdo, $ids['trial'], 'pushover', 'all', 'My phone');
        }, []],
    ];
}

/** The <section id="settings-automation"> of a rendered profile page ('' when it is missing). */
function ms_pp_automation_section(string $html): string
{
    $start = strpos($html, '<section class="ms-settings__section" id="settings-automation">');
    $end = $start === false ? false : strpos($html, '</section>', $start);
    return $start === false || $end === false ? '' : substr($html, $start, $end + 10 - $start);
}

/** The Automation section for one case; $svApp offers Swedish in the app area, $acceptLanguage is sent as Accept-Language. */
function ms_pp_automation_page(array $probe, string $name, bool $svApp = false, string $acceptLanguage = ''): array
{
    [$user, $setup, $switches] = ms_pp_automation_page_cases()[$name];
    $extra = ['server' => $acceptLanguage !== '' ? ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage] : [], 'locales' => $svApp ? 'en,sv' : 'en', 'app_sv' => $svApp] + $switches;
    $r = ms_pp_run($probe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user' => $user], $setup, $extra);
    $r['section'] = ms_pp_automation_section($r['body']);
    return $r;
}

/**
 * Every pro_profile.php answer of the Automation actions that carries an `error`
 * or a `message` (tests/fixtures/i18n/profile.<name>.en.json). A failure the code
 * catches is provoked with a trigger or a renamed table (both undone by
 * ms_pp_reset()). The creating cases all stop before the INSERT, so no test
 * delivery is ever sent.
 *
 * @return array<string,array{0:array<string,mixed>,1:?string,2:?callable,3:array<string,mixed>}>
 */
function ms_pp_automation_json_cases(): array
{
    $routing = static fn(PDO $pdo, array $ids) => ms_pp_routing_on($pdo);
    $hook = static function (PDO $pdo, array $ids): void {
        ms_pp_routing_on($pdo);
        ms_test_seed_webhook($pdo, $ids['trial'], 'generic', 'all', 'My endpoint');
    };
    $address = static fn(PDO $pdo, array $ids) => ms_pp_sticky_address($pdo, $ids);
    $routedAddress = static function (PDO $pdo, array $ids): void {
        ms_pp_routing_on($pdo);
        ms_pp_sticky_address($pdo, $ids);
    };
    $feedAddress = static function (PDO $pdo, array $ids): void {
        ms_pp_feed_columns_on($pdo);
        ms_pp_sticky_address($pdo, $ids);
    };
    $fail = static fn(string $table, string $event, ?callable $before = null): callable => static function (PDO $pdo, array $ids) use ($table, $event, $before): void {
        if ($before !== null) {
            $before($pdo, $ids);
        }
        $pdo->exec("CREATE TRIGGER ms_fail BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'probe failure'); END");
    };
    $gone = static fn(string $table): callable => static function (PDO $pdo, array $ids) use ($table): void {
        $pdo->exec("ALTER TABLE {$table} RENAME TO {$table}_gone");
    };
    $feedCols = static fn(PDO $pdo, array $ids) => ms_pp_feed_columns_on($pdo);
    $feedCorrupt = static function (PDO $pdo, array $ids): void {
        ms_pp_feed_columns_on($pdo);
        $pdo->prepare("UPDATE pro_users SET feed_token_hash = 'h', feed_token_enc = 'not-a-valid-ciphertext' WHERE id = ?")->execute([$ids['trial']]);
    };
    $addressCorrupt = static function (PDO $pdo, array $ids): void {
        ms_pp_feed_columns_on($pdo);
        ms_pp_sticky_address($pdo, $ids);
        $pdo->exec("UPDATE temp_emails SET feed_token_hash = 'h', feed_token_enc = 'not-a-valid-ciphertext' WHERE id = 900");
    };
    $create = static fn(array $over): array => $over + ['action' => 'webhook_create', 'name' => 'x', 'url' => 'https://8.8.8.8/hook', 'kind' => 'generic'];

    return [
        'get_digest_settings.user_not_found' => [['action' => 'get_digest_settings'], 'ghost', null, []],
        'update_digest_settings.ttl_one' => [['action' => 'update_digest_settings', 'digest_enabled' => '1', 'digest_frequency_days' => '1'], 'trial', null, []],
        'update_digest_settings.frequency' => [['action' => 'update_digest_settings', 'digest_enabled' => '0', 'digest_frequency_days' => '3'], 'trial', null, []],
        'update_digest_settings.hour' => [['action' => 'update_digest_settings', 'digest_enabled' => '0', 'digest_frequency_days' => '1', 'digest_hour' => '24'], 'trial', null, []],
        'update_digest_settings.timezone' => [['action' => 'update_digest_settings', 'digest_enabled' => '0', 'digest_frequency_days' => '1', 'digest_tz' => 'Mars/Olympus'], 'trial', null, []],
        'webhooks_list.failed' => [['action' => 'webhooks_list'], 'trial', $gone('pro_webhooks'), []],
        'webhook_set_addresses.invalid_origin' => [['action' => 'webhook_set_addresses', 'id' => '1'], 'trial', $hook, ['origin_flip' => true]],
        'webhook_set_addresses.unavailable' => [['action' => 'webhook_set_addresses', 'id' => '1'], 'trial', null, []],
        'webhook_set_addresses.invalid_id' => [['action' => 'webhook_set_addresses', 'id' => '0'], 'trial', $routing, []],
        'webhook_set_addresses.invalid_request' => [['action' => 'webhook_set_addresses', 'id' => '1', 'address_ids' => 'x'], 'trial', $hook, []],
        'webhook_set_addresses.too_many' => [['action' => 'webhook_set_addresses', 'id' => '1', 'address_ids' => range(1, 51)], 'trial', $hook, []],
        'webhook_set_addresses.not_found' => [['action' => 'webhook_set_addresses', 'id' => '99'], 'trial', $hook, []],
        'webhook_set_addresses.address_not_found' => [['action' => 'webhook_set_addresses', 'id' => '1', 'address_ids' => ['999']], 'trial', $hook, []],
        'webhook_set_addresses.failed' => [['action' => 'webhook_set_addresses', 'id' => '1', 'address_ids' => []], 'trial', $fail('pro_webhooks', 'UPDATE', $hook), []],
        'feed_get_token.unavailable' => [['action' => 'feed_get_token'], 'trial', $feedCols, []],
        'feed_get_token.undecryptable' => [['action' => 'feed_get_token'], 'trial', $feedCorrupt, []],
        'feed_get_token.failed' => [['action' => 'feed_get_token'], 'trial', $fail('pro_users', 'UPDATE'), []],
        'feed_regenerate.unavailable' => [['action' => 'feed_regenerate'], 'trial', $feedCols, []],
        'feed_regenerate.failed' => [['action' => 'feed_regenerate'], 'trial', $fail('pro_users', 'UPDATE'), []],
        'address_feed_get_token.invalid' => [['action' => 'address_feed_get_token', 'id' => '0'], 'trial', $feedCols, []],
        'address_feed_get_token.not_found' => [['action' => 'address_feed_get_token', 'id' => '999'], 'trial', $feedCols, []],
        'address_feed_get_token.unavailable' => [['action' => 'address_feed_get_token', 'id' => '900'], 'trial', $feedAddress, []],
        'address_feed_get_token.undecryptable' => [['action' => 'address_feed_get_token', 'id' => '900'], 'trial', $addressCorrupt, []],
        'address_feed_get_token.failed' => [['action' => 'address_feed_get_token', 'id' => '900'], 'trial', $fail('temp_emails', 'UPDATE', $address), []],
        'address_feed_regenerate.invalid' => [['action' => 'address_feed_regenerate', 'id' => '0'], 'trial', $feedCols, []],
        'address_feed_regenerate.not_found' => [['action' => 'address_feed_regenerate', 'id' => '999'], 'trial', $feedCols, []],
        'address_feed_regenerate.unavailable' => [['action' => 'address_feed_regenerate', 'id' => '900'], 'trial', $feedAddress, []],
        'address_feed_regenerate.failed' => [['action' => 'address_feed_regenerate', 'id' => '900'], 'trial', $fail('temp_emails', 'UPDATE', $address), []],
        'address_feed_disable.invalid' => [['action' => 'address_feed_disable', 'id' => '0'], 'trial', null, []],
        'address_feed_disable.not_found' => [['action' => 'address_feed_disable', 'id' => '999'], 'trial', null, []],
        'address_feed_disable.failed' => [['action' => 'address_feed_disable', 'id' => '900'], 'trial', $fail('temp_emails', 'UPDATE', $address), []],
        'address_hooks_pause.invalid_origin' => [['action' => 'address_hooks_pause', 'id' => '900'], 'trial', $routedAddress, ['origin_flip' => true]],
        'address_hooks_pause.unavailable' => [['action' => 'address_hooks_pause', 'id' => '900'], 'trial', $address, []],
        'address_hooks_pause.invalid' => [['action' => 'address_hooks_pause', 'id' => '0'], 'trial', $routing, []],
        'address_hooks_pause.not_found' => [['action' => 'address_hooks_pause', 'id' => '999'], 'trial', $routing, []],
        'address_hooks_resume.failed' => [['action' => 'address_hooks_resume', 'id' => '900'], 'trial', $fail('temp_emails', 'UPDATE', $routedAddress), []],
        'webhook_create.suspicious' => [$create(['name' => 'myscript']), 'trial', null, []],
        'webhook_create.secret_too_long' => [$create(['secret' => str_repeat('s', 101)]), 'trial', null, []],
        'webhook_create.url_required' => [$create(['url' => '']), 'trial', null, []],
        'webhook_create.invalid_url' => [$create(['url' => 'not a url']), 'trial', null, []],
        'webhook_create.https_only' => [$create(['url' => 'http://8.8.8.8/hook']), 'trial', null, []],
        'webhook_create.not_public' => [$create(['url' => 'https://127.0.0.1/hook']), 'trial', null, []],
        'webhook_create.invalid_kind' => [$create(['kind' => 'other']), 'trial', null, []],
        'webhook_create.config_too_large' => [$create(['config' => '{"a":"' . str_repeat('x', 4100) . '"}']), 'trial', null, []],
        'webhook_create.invalid_json' => [$create(['config' => '{bad']), 'trial', null, []],
        'webhook_create.not_object' => [$create(['config' => '[1,2]']), 'trial', null, []],
        'webhook_create.header_reserved' => [$create(['config' => '{"headers":{"Host":"x"}}']), 'trial', null, []],
        'webhook_create.header_name' => [$create(['config' => '{"headers":{"Bad Name":"x"}}']), 'trial', null, []],
        'webhook_create.headers_not_object' => [$create(['config' => '{"headers":"x"}']), 'trial', null, []],
        'webhook_create.pushover_config' => [$create(['kind' => 'pushover', 'config' => '{"token":"t"}']), 'trial', null, []],
        'webhook_create.secrets_unavailable' => [$create(['secret' => 'abc']), 'trial', null, []],
        'webhook_create.credentials_unavailable' => [$create(['kind' => 'pushover', 'config' => '{"token":"t","user":"u"}']), 'trial', null, []],
        'webhook_create.failed' => [$create([]), 'trial', $fail('pro_webhooks', 'INSERT'), []],
        'webhook_delete.invalid_id' => [['action' => 'webhook_delete', 'id' => '0'], 'trial', null, []],
        'webhook_delete.not_found' => [['action' => 'webhook_delete', 'id' => '99'], 'trial', $hook, []],
        'webhook_delete.failed' => [['action' => 'webhook_delete', 'id' => '1'], 'trial', $fail('pro_webhooks', 'DELETE', $hook), []],
        'webhook_set_filter_mode.invalid_id' => [['action' => 'webhook_set_filter_mode', 'id' => '0', 'filter_mode' => 'all'], 'trial', null, []],
        'webhook_set_filter_mode.invalid_mode' => [['action' => 'webhook_set_filter_mode', 'id' => '1', 'filter_mode' => 'x'], 'trial', null, []],
        'webhook_set_filter_mode.not_found' => [['action' => 'webhook_set_filter_mode', 'id' => '99', 'filter_mode' => 'paused'], 'trial', $hook, []],
        'webhook_set_filter_mode.failed' => [['action' => 'webhook_set_filter_mode', 'id' => '1', 'filter_mode' => 'paused'], 'trial', $fail('pro_webhooks', 'UPDATE', $hook), []],
        'webhook_deliveries.failed' => [['action' => 'webhook_deliveries'], 'trial', $gone('pro_webhook_deliveries'), []],
    ];
}


// ---------------------------------------------------------------------
// Epic #428 step 6/8: the Security, Client Agent and Delete account sections.
// ---------------------------------------------------------------------

/** Removes every table of ms_pp_security_schema() (and oauth_clients); the earlier steps' cases run without them. */
function ms_pp_security_drop(PDO $pdo): void
{
    foreach (['pro_user_totp', 'pro_user_recovery_codes', 'two_factor_attempts', 'pro_trusted_devices', 'two_factor_enroll_requests', 'pro_remember_tokens', 'mcp_access_tokens', 'oauth_clients'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
}

/**
 * Wraps a case's setup so the Security tables exist first (the step 4/8 and 5/8
 * cases run without them, as they were fixtured).
 */
function ms_pp_with_security(?callable $setup): callable
{
    return static function (PDO $pdo, array $ids) use ($setup): void {
        ms_pp_security_schema($pdo);
        if ($setup !== null) {
            $setup($pdo, $ids);
        }
    };
}

/**
 * The tables behind the Security cards: 2FA (TwoFactorAuth.php, as the migrations leave them), "Stay signed in"
 * (migrate_remember_tokens.php) and the MCP tokens with their OAuth columns
 * (migrate_mcp_tokens.php + migrate_oauth.php). oauth_clients is not here: it is
 * what switches OAuth discovery on (ms_pp_oauth_on()).
 */
function ms_pp_security_schema(PDO $pdo): void
{
    ms_pp_security_drop($pdo);
    foreach ([
        "CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', last_used_step INTEGER NULL, confirmed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)",
        'CREATE TABLE pro_user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL)',
        'CREATE TABLE two_factor_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, ip TEXT NOT NULL, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, selector TEXT NULL, validator_hash TEXT NULL, label TEXT NULL, created_at TEXT NULL, last_used_at TEXT NULL, expires_at TEXT NULL)',
        'CREATE TABLE two_factor_enroll_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        "CREATE TABLE pro_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, selector TEXT NOT NULL, validator_hash TEXT NOT NULL, prev_validator_hash TEXT NULL, rotated_at TEXT NULL, days INTEGER NOT NULL, label TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, last_used_at TEXT NOT NULL, expires_at TEXT NOT NULL)",
        "CREATE TABLE mcp_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, pro_user_id INTEGER NOT NULL, name TEXT NOT NULL, token_hash TEXT NOT NULL, token_prefix TEXT NOT NULL, scopes TEXT NOT NULL DEFAULT 'read', created_at TEXT NOT NULL, last_used_at TEXT NULL, expires_at TEXT NULL, revoked_at TEXT NULL, oauth_client_id TEXT NULL, refresh_token_hash TEXT NULL, refresh_expires_at TEXT NULL, grant_created_at TEXT NULL, rotated_refresh_hash TEXT NULL, rotated_at TEXT NULL)",
        'CREATE UNIQUE INDEX uniq_mcp_access_tokens_hash ON mcp_access_tokens (token_hash)',
    ] as $sql) {
        $pdo->exec($sql);
    }
}

/** oauth_clients, which is all oauthAvailable() looks for: with it and OAUTH_ENABLED, discovery is on. */
function ms_pp_oauth_on(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL, client_name TEXT NOT NULL, redirect_uris TEXT NOT NULL, created_at TEXT NOT NULL, last_used_at TEXT NULL, registered_ip_hash TEXT NULL)');
}

/** TwoFactorAuth's own AES-256-GCM of a TOTP secret under the probe's key. */
function ms_pp_totp_secret_enc(string $plain): string
{
    $key = base64_decode(MS_AUTH_TOTP_KEY, true);
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $cipher);
}

/** 2FA switched on for $userId, with $codes unused recovery codes and one trusted device (id 7). */
function ms_pp_totp_on(PDO $pdo, int $userId, int $codes = 2): void
{
    $pdo->prepare("INSERT INTO pro_user_totp (user_id, secret_enc, status, confirmed_at) VALUES (?, ?, 'active', '2026-03-04 10:00:00')")
        ->execute([$userId, ms_pp_totp_secret_enc('JBSWY3DPEHPK3PXP')]);
    for ($i = 0; $i < $codes; $i++) {
        $pdo->prepare('INSERT INTO pro_user_recovery_codes (user_id, code_hash) VALUES (?, ?)')->execute([$userId, 'hash' . $i]);
    }
    $pdo->prepare("INSERT INTO pro_trusted_devices (id, user_id, selector, validator_hash, label, created_at, last_used_at, expires_at) VALUES (7, ?, 'sel7', 'v', 'Firefox on Linux', '2026-03-05 10:00:00', '2026-03-06 10:00:00', '2099-04-05 10:00:00')")
        ->execute([$userId]);
}

/** One OAuth grant (an app that names itself with markup) and one manual access token for $userId. */
function ms_pp_tokens_on(PDO $pdo, int $userId): void
{
    if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'oauth_clients'")->fetchColumn() === 0) {
        ms_pp_oauth_on($pdo);
    }
    $pdo->prepare("INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES ('c1', ?, '[]', '2026-03-01 10:00:00')")->execute(['Probe <b>App</b>']);
    $ins = $pdo->prepare('INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at, last_used_at, expires_at, oauth_client_id, grant_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$userId, 'Probe <b>App</b>', hash('sha256', 'a'), 'msk_aaaaaaaa', 'read,write', '2026-03-01 10:00:00', null, '2099-01-01 00:00:00', 'c1', '2026-03-01 10:00:00']);
    $ins->execute([$userId, 'Laptop', hash('sha256', 'b'), 'msk_bbbbbbbb', 'read', '2026-03-02 10:00:00', '2026-03-03 10:00:00', null, null, null]);
}

/** A password for $userId, so the 2FA actions that ask for one run their password branch. */
function ms_pp_password_on(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE pro_users SET password_hash = ? WHERE id = ?')->execute([password_hash('probe-password', PASSWORD_DEFAULT), $userId]);
}

/**
 * [name => [user key, setup, switches]] for tests/fixtures/i18n/profile.security.<name>.en.html,
 * which holds the Security, Client Agent and Delete account sections. The cards
 * that depend on state (2FA, the token and grant lists, the device list) are drawn
 * by the page's script, so only OAuth discovery and the token table change the
 * server-rendered markup; the cases with 2FA on and with a grant plus a token
 * prove it by being the same markup as their plain counterparts.
 *
 * @return array<string,array{0:string,1:?callable,2:array<string,mixed>}>
 */
function ms_pp_security_page_cases(): array
{
    $full = static function (PDO $pdo, array $ids): void {
        ms_pp_oauth_on($pdo);
        ms_pp_totp_on($pdo, $ids['trial']);
        ms_pp_tokens_on($pdo, $ids['trial']);
    };
    $fullOff = static function (PDO $pdo, array $ids): void {
        ms_pp_totp_on($pdo, $ids['trial']);
        ms_pp_tokens_on($pdo, $ids['trial']);
        $pdo->exec('DROP TABLE oauth_clients');
    };
    $cases = [
        'oauth_on' => ['trial', static fn(PDO $pdo, array $ids) => ms_pp_oauth_on($pdo), ['oauth' => true]],
        'oauth_off' => ['trial', null, []],
        'oauth_on_2fa_tokens' => ['trial', $full, ['oauth' => true]],
        'oauth_off_2fa_tokens' => ['trial', $fullOff, []],
        'tokens_unavailable' => ['trial', static fn(PDO $pdo, array $ids) => $pdo->exec('DROP TABLE mcp_access_tokens'), []],
    ];
    foreach ($cases as $name => $case) {
        $cases[$name][1] = ms_pp_with_security($case[1]);
    }
    return $cases;
}

/** The Security, Client Agent and Delete account sections of a rendered profile page ('' when missing). */
function ms_pp_security_section(string $html): string
{
    $start = strpos($html, '<section class="ms-settings__section" id="settings-security">');
    $delete = strpos($html, 'id="settings-delete"');
    $end = $delete === false ? false : strpos($html, '</section>', $delete);
    return $start === false || $end === false ? '' : substr($html, $start, $end + 10 - $start);
}

/** The Security sections for one case; $svApp offers Swedish in the app area, $acceptLanguage is sent as Accept-Language. */
function ms_pp_security_page(array $probe, string $name, bool $svApp = false, string $acceptLanguage = ''): array
{
    [$user, $setup, $switches] = ms_pp_security_page_cases()[$name];
    $extra = ['server' => $acceptLanguage !== '' ? ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage] : [], 'locales' => $svApp ? 'en,sv' : 'en', 'app_sv' => $svApp] + $switches;
    $r = ms_pp_run($probe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user' => $user], $setup, $extra);
    $r['section'] = ms_pp_security_section($r['body']);
    return $r;
}

/**
 * Every pro_profile.php answer of the Security, 2FA, devices, tokens, password,
 * signing-key and deletion actions that carries an `error` or a `message`
 * (tests/fixtures/i18n/profile.<name>.en.json). A failure the code catches is
 * provoked by dropping a table or adding a trigger (both undone by ms_pp_reset()).
 * Not reachable here, and so not fixtured: the per-action `Method not allowed`
 * (the gate at the top answers first), a successful enrolment (MySQL's ON
 * DUPLICATE KEY UPDATE) and a successful key rotation (needs the key-encryption key).
 *
 * @return array<string,array{0:array<string,mixed>,1:?string,2:?callable,3:array<string,mixed>}>
 */
function ms_pp_security_json_cases(): array
{
    $drop = static fn(string ...$tables): callable => static function (PDO $pdo, array $ids) use ($tables): void {
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE {$table}");
        }
    };
    $fail = static fn(string $table, string $event, ?callable $before = null): callable => static function (PDO $pdo, array $ids) use ($table, $event, $before): void {
        if ($before !== null) {
            $before($pdo, $ids);
        }
        $pdo->exec("CREATE TRIGGER ms_fail BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'probe failure'); END");
    };
    $totp = static fn(PDO $pdo, array $ids) => ms_pp_totp_on($pdo, $ids['trial']);
    $locked = static function (PDO $pdo, array $ids): void {
        $ins = $pdo->prepare("INSERT INTO two_factor_attempts (user_id, ip, success, attempt_at) VALUES (?, '10.0.0.9', 0, ?)");
        for ($i = 0; $i < 5; $i++) {
            $ins->execute([$ids['trial'], date('Y-m-d H:i:s')]);
        }
    };
    $enrollLimited = static function (PDO $pdo, array $ids): void {
        $ins = $pdo->prepare('INSERT INTO two_factor_enroll_requests (user_id, requested_at) VALUES (?, ?)');
        for ($i = 0; $i < 10; $i++) {
            $ins->execute([$ids['trial'], date('Y-m-d H:i:s')]);
        }
    };
    $remember = static function (PDO $pdo, array $ids): void {
        $pdo->prepare("INSERT INTO pro_remember_tokens (id, user_id, selector, validator_hash, days, label, created_at, last_used_at, expires_at) VALUES (4, ?, 'sel4', 'v', 7, 'Firefox on Linux', '2026-03-01 10:00:00', '2026-03-02 10:00:00', '2099-03-08 10:00:00')")
            ->execute([$ids['trial']]);
    };
    $noRemember = static fn(PDO $pdo, array $ids) => $pdo->exec('DROP TABLE pro_remember_tokens');
    $tokens = static function (int $count): callable {
        return static function (PDO $pdo, array $ids) use ($count): void {
            $ins = $pdo->prepare("INSERT INTO mcp_access_tokens (pro_user_id, name, token_hash, token_prefix, scopes, created_at) VALUES (?, ?, ?, 'msk_xxxxxxxx', 'read', '2026-03-01 10:00:00')");
            for ($i = 0; $i < $count; $i++) {
                $ins->execute([$ids['trial'], 'Token ' . $i, hash('sha256', 't' . $i)]);
            }
        };
    };
    $create = static fn(array $over): array => $over + ['action' => 'mcp_token_create', 'name' => 'Laptop', 'scopes' => 'read', 'expires_days' => '0'];

    $cases = [
        'totp_status.failed' => [['action' => 'totp_status'], 'trial', $drop('pro_user_totp'), ['method' => 'GET']],
        'totp_begin_enroll.invalid_origin' => [['action' => 'totp_begin_enroll'], 'trial', null, ['origin_flip' => true]],
        'totp_begin_enroll.already_enabled' => [['action' => 'totp_begin_enroll'], 'trial', $totp, []],
        'totp_begin_enroll.rate_limited' => [['action' => 'totp_begin_enroll'], 'trial', $enrollLimited, []],
        'totp_begin_enroll.failed' => [['action' => 'totp_begin_enroll'], 'trial', null, []],
        'totp_confirm_enroll.invalid_origin' => [['action' => 'totp_confirm_enroll', 'code' => '123456'], 'trial', null, ['origin_flip' => true]],
        'totp_confirm_enroll.blocked' => [['action' => 'totp_confirm_enroll', 'code' => '123456'], 'trial', $locked, []],
        'totp_confirm_enroll.invalid_code' => [['action' => 'totp_confirm_enroll', 'code' => '123456'], 'trial', null, []],
        'totp_confirm_enroll.failed' => [['action' => 'totp_confirm_enroll', 'code' => '123456'], 'trial', $drop('pro_user_totp'), []],
        'totp_disable.invalid_origin' => [['action' => 'totp_disable'], 'trial', null, ['origin_flip' => true]],
        'totp_disable.invalid_password' => [['action' => 'totp_disable', 'password' => 'wrong-password'], 'trial', static function (PDO $pdo, array $ids): void {
            ms_pp_password_on($pdo, $ids['trial']);
            ms_pp_totp_on($pdo, $ids['trial']);
        }, []],
        'totp_disable.not_enabled' => [['action' => 'totp_disable'], 'trial', null, []],
        'totp_disable.failed' => [['action' => 'totp_disable'], 'trial', $drop('pro_user_totp'), []],
        'totp_recovery_regenerate.invalid_origin' => [['action' => 'totp_recovery_regenerate', 'code' => '123456'], 'trial', null, ['origin_flip' => true]],
        'totp_recovery_regenerate.not_enabled' => [['action' => 'totp_recovery_regenerate', 'code' => '123456'], 'trial', null, []],
        'totp_recovery_regenerate.blocked' => [['action' => 'totp_recovery_regenerate', 'code' => '123456'], 'trial', static function (PDO $pdo, array $ids) use ($totp, $locked): void {
            $totp($pdo, $ids);
            $locked($pdo, $ids);
        }, []],
        'totp_recovery_regenerate.invalid_code' => [['action' => 'totp_recovery_regenerate', 'code' => '123456'], 'trial', $totp, []],
        'totp_recovery_regenerate.failed' => [['action' => 'totp_recovery_regenerate', 'code' => '123456'], 'trial', $drop('pro_user_totp'), []],
        'trusted_devices_list.invalid_origin' => [['action' => 'trusted_devices_list'], 'trial', null, ['origin_flip' => true]],
        'trusted_devices_list.failed' => [['action' => 'trusted_devices_list'], 'trial', $drop('pro_trusted_devices'), []],
        'trusted_devices_revoke.invalid_origin' => [['action' => 'trusted_devices_revoke', 'id' => '7'], 'trial', null, ['origin_flip' => true]],
        'trusted_devices_revoke.invalid_id' => [['action' => 'trusted_devices_revoke', 'id' => '0'], 'trial', null, []],
        'trusted_devices_revoke.not_found' => [['action' => 'trusted_devices_revoke', 'id' => '99'], 'trial', null, []],
        'trusted_devices_revoke.failed' => [['action' => 'trusted_devices_revoke', 'id' => '7'], 'trial', $drop('pro_trusted_devices'), []],
        'stay_signed_in_list.failed' => [['action' => 'stay_signed_in_list'], 'trial', static fn(PDO $pdo, array $ids) => $pdo->exec('ALTER TABLE pro_remember_tokens DROP COLUMN label'), []],
        'stay_signed_in_set.invalid_period' => [['action' => 'stay_signed_in_set', 'days' => '5'], 'trial', null, []],
        'stay_signed_in_set.unavailable' => [['action' => 'stay_signed_in_set', 'days' => '7'], 'trial', $noRemember, []],
        'stay_signed_in_set.failed' => [['action' => 'stay_signed_in_set', 'days' => '7'], 'trial', $fail('pro_remember_tokens', 'INSERT'), []],
        'stay_signed_in_revoke.unavailable' => [['action' => 'stay_signed_in_revoke', 'id' => '4'], 'trial', $noRemember, []],
        'stay_signed_in_revoke.not_found' => [['action' => 'stay_signed_in_revoke', 'id' => '99'], 'trial', $remember, []],
        'stay_signed_in_revoke.failed' => [['action' => 'stay_signed_in_revoke', 'id' => '4'], 'trial', $fail('pro_remember_tokens', 'DELETE', $remember), []],
        'mcp_token_list.failed' => [['action' => 'mcp_token_list'], 'trial', static fn(PDO $pdo, array $ids) => $pdo->exec('ALTER TABLE mcp_access_tokens DROP COLUMN token_prefix'), []],
        'mcp_token_create.unavailable' => [$create([]), 'trial', $drop('mcp_access_tokens'), []],
        'mcp_token_create.suspicious' => [$create(['name' => 'myscript']), 'trial', null, []],
        'mcp_token_create.name_required' => [$create(['name' => '   ']), 'trial', null, []],
        'mcp_token_create.invalid_access' => [$create(['scopes' => 'admin']), 'trial', null, []],
        'mcp_token_create.invalid_expiry' => [$create(['expires_days' => '7']), 'trial', null, []],
        'mcp_token_create.too_many' => [$create([]), 'trial', $tokens(10), []],
        'mcp_token_create.failed' => [$create([]), 'trial', $fail('mcp_access_tokens', 'INSERT'), []],
        'mcp_token_revoke.unavailable' => [['action' => 'mcp_token_revoke', 'id' => '1'], 'trial', $drop('mcp_access_tokens'), []],
        'mcp_token_revoke.not_found' => [['action' => 'mcp_token_revoke', 'id' => '99'], 'trial', null, []],
        'mcp_token_revoke.failed' => [['action' => 'mcp_token_revoke', 'id' => '1'], 'trial', $fail('mcp_access_tokens', 'UPDATE', $tokens(1)), []],
        'set_password.mismatch' => [['action' => 'set_password', 'password' => 'abcdefgh1', 'confirm' => 'other'], 'trial', null, []],
        'set_password.short' => [['action' => 'set_password', 'password' => 'abc', 'confirm' => 'abc'], 'trial', null, []],
        'set_password.long' => [['action' => 'set_password', 'password' => str_repeat('a', 257), 'confirm' => str_repeat('a', 257)], 'trial', null, []],
        'set_password.unavailable' => [['action' => 'set_password', 'password' => 'abcdefgh1', 'confirm' => 'abcdefgh1'], 'trial', static fn(PDO $pdo, array $ids) => $pdo->exec('ALTER TABLE pro_users DROP COLUMN password_hash'), []],
        'set_password.sent' => [['action' => 'set_password', 'password' => 'abcdefgh1', 'confirm' => 'abcdefgh1'], 'trial', null, []],
        'set_password.failed' => [['action' => 'set_password', 'password' => 'abcdefgh1', 'confirm' => 'abcdefgh1'], 'trial', $fail('pending_profile_changes', 'INSERT'), []],
        'rotate_signing_keys.invalid_origin' => [['action' => 'rotate_signing_keys'], 'trial', null, ['origin_flip' => true]],
        'rotate_signing_keys.failed' => [['action' => 'rotate_signing_keys'], 'trial', null, []],
        'delete_account.sent' => [['action' => 'delete_account'], 'trial', null, []],
        'delete_account.failed' => [['action' => 'delete_account'], 'trial', $fail('pending_profile_changes', 'INSERT'), []],
    ];
    foreach ($cases as $name => $case) {
        $cases[$name][2] = ms_pp_with_security($case[2]);
    }
    return $cases;
}
