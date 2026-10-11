<?php
/**
 * Probe for the JSON actions of index.php (epic #428, step 3/8): the real
 * index.php run as a CLI subprocess in the throwaway docroot of
 * tests/lib/pushover_harness.php, with a handful of patches to its stub
 * config.php so a request can carry a header, switch Swedish on and make the
 * mail forwarder fail. Shared by tests/i18n_test.php and the fixture
 * generation (tests/fixtures/i18n/app_actions.*.en.json).
 *
 * Every case is one request whose answer carries an `error` or a `message`.
 * Nothing here is deployed.
 */

require_once __DIR__ . '/pushover_harness.php';

/**
 * Builds the probe docroot and its seeded SQLite database.
 *
 * @return array{root:string,ids:array<string,int>}
 */
function ms_aa_build(string $repoRoot): array
{
    $root = ms_test_probe_build($repoRoot);
    $sqlite = ms_test_probe_sqlite($root);
    foreach (['email_html_sanitizer.php'] as $extra) {
        if (is_file($repoRoot . '/' . $extra)) {
            copy($repoRoot . '/' . $extra, $root . '/' . $extra);
        }
    }

    $config = (string) file_get_contents($root . '/config.php');
    $config = str_replace(
        "'i18n' => ['enabled' => ['en']],",
        '\'i18n\' => [\'enabled\' => array_values(array_filter(array_map(\'trim\', explode(\',\', (string) ($MS_EXTRA[\'locales\'] ?? \'en\')))))],',
        $config
    );
    $config = str_replace(
        'function detectSuspiciousPatterns(string $input): array { return []; }',
        "function detectSuspiciousPatterns(string \$input): array { return stripos(\$input, 'script') !== false ? ['probe_script'] : []; }",
        $config
    );
    $config = str_replace(
        'function createDirectAdminForwarder(...$args) { return true; }',
        'function createDirectAdminForwarder(...$args) { return empty($GLOBALS[\'MS_EXTRA\'][\'fail_forwarder\']); }',
        $config
    );
    $config .= <<<'PHP'

// Only when a case asks: the app area offered in Swedish (the '@app' gate, I18N.md 2.1).
if (!empty($MS_EXTRA['app_sv'])) {
    i18nTranslatedPages(array_merge(i18nTranslatedPages(), ['@app' => ['en', 'sv']]));
}
function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = sanitizeString($local, $maxLength, true);
    if ($s === null || strlen($s) < $minLength || !preg_match('/^[a-zA-Z0-9._-]+$/', $s)) return null;
    return strtolower($s);
}
function saveNewAddress($address, $proUserId = null, $isPersonal = 0) {
    return saveNewAddressWithExpiry($address, $proUserId, $isPersonal, $GLOBALS['__custom_expires_at'] ?? date('Y-m-d H:i:s', time() + 86400));
}
PHP;
    // Per-request switches arrive through probe_extra.json (ms_test_request() passes a fixed environment).
    $config = preg_replace('/^<\?php\s*/', "<?php\n\$MS_EXTRA = \$GLOBALS['MS_EXTRA'] = (array) json_decode((string) @file_get_contents(__DIR__ . '/probe_extra.json'), true);\n", $config, 1);
    file_put_contents($root . '/config.php', $config);

    $runner = (string) file_get_contents($root . '/ms_probe_runner.php');
    $runner = str_replace(
        "require __DIR__ . '/' . \$probePage;",
        "foreach ((array) (json_decode((string) @file_get_contents(__DIR__ . '/probe_extra.json'), true)['server'] ?? []) as \$k => \$v) { \$_SERVER[\$k] = \$v; }\nrequire __DIR__ . '/' . \$probePage;",
        $runner
    );
    file_put_contents($root . '/ms_probe_runner.php', $runner);

    $pdo = ms_test_db($sqlite);
    $pdo->exec('CREATE TABLE stored_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT, to_address TEXT, from_address TEXT, subject TEXT,
        body_text TEXT, body_html TEXT, received_at TEXT, expires_at TEXT)');
    $pdo->exec('CREATE TABLE address_cooldowns (
        id INTEGER PRIMARY KEY AUTOINCREMENT, local_part TEXT NOT NULL UNIQUE, pro_user_id INTEGER NOT NULL,
        released_at TEXT NOT NULL, blocked_until TEXT NOT NULL)');

    $ids = [];
    $ids['alice'] = ms_test_seed_user($pdo, 'alice@example.com');
    $ids['regular'] = ms_test_seed_user($pdo, 'regular@example.com', 'regular');
    $ids['capper'] = ms_test_seed_user($pdo, 'capper@example.com');
    ms_test_seed_address($pdo, 'aabbccdd11', ['pro_user_id' => $ids['alice'], 'is_personal' => 1]);
    ms_test_seed_address($pdo, 'eeff001122', ['pro_user_id' => null, 'is_personal' => 0]);
    for ($i = 0; $i < 10; $i++) {
        ms_test_seed_address($pdo, 'cap.box' . $i, ['pro_user_id' => $ids['capper'], 'is_personal' => 1]);
    }
    $past = date('Y-m-d H:i:s', time() - 3600);
    $future = date('Y-m-d H:i:s', time() + 3600);
    $mail = $pdo->prepare('INSERT INTO stored_emails (id, to_address, from_address, subject, body_text, received_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $mail->execute([1, 'aabbccdd11@manjo.me', 's@example.com', 'expired', 'x', $past, $past]);
    $mail->execute([2, 'eeff001122@manjo.me', 's@example.com', 'open', 'x', $past, $future]);
    $mail->execute([3, 'aabbccdd11@manjo.me', 's@example.com', 'open', 'x', $past, $future]);
    $pdo = null;

    return ['root' => $root, 'ids' => $ids];
}

/**
 * Every index.php action answer that carries an `error` or a `message`.
 * [name => [post fields, user key (null = signed out), options]]; options:
 * origin, fail_forwarder.
 *
 * @return array<string,array{0:array<string,string>,1:?string,2:array<string,mixed>}>
 */
function ms_aa_cases(): array
{
    return [
        'suspicious' => [['action' => 'myscript'], null, []],
        'forbidden' => [['action' => 'get_stats'], 'alice', ['origin' => 'http://evil.test']],
        'get_expires_at.invalid' => [['action' => 'get_expires_at', 'address' => 'nope'], null, []],
        'get_expires_at.not_found' => [['action' => 'get_expires_at', 'address' => '0123456789ab'], null, []],
        'check_address_owner.invalid' => [['action' => 'check_address_owner', 'address' => 'nope'], null, []],
        'create_personal.not_authenticated' => [['action' => 'create_personal', 'local' => 'newbox'], null, []],
        'create_personal.pro_required' => [['action' => 'create_personal', 'local' => 'newbox'], 'regular', []],
        'create_personal.suspicious' => [['action' => 'create_personal', 'local' => 'script'], 'alice', []],
        'create_personal.invalid_local_part' => [['action' => 'create_personal', 'local' => 'a!'], 'alice', []],
        'create_personal.reserved' => [['action' => 'create_personal', 'local' => 'admin'], 'alice', []],
        'create_personal.taken' => [['action' => 'create_personal', 'local' => 'aabbccdd11'], 'alice', []],
        'create_personal.cap' => [['action' => 'create_personal', 'local' => 'onemore'], 'capper', []],
        'create_personal.delivery' => [['action' => 'create_personal', 'local' => 'newbox'], 'alice', ['fail_forwarder' => true]],
        'list_personal.not_authenticated' => [['action' => 'list_personal'], null, []],
        'delete_personal.not_authenticated' => [['action' => 'delete_personal', 'id' => '1'], null, []],
        'delete_personal.invalid_id' => [['action' => 'delete_personal', 'id' => '0'], 'alice', []],
        'delete_personal.not_owned' => [['action' => 'delete_personal', 'id' => '1'], 'regular', []],
        'generate.timed_delivery' => [['action' => 'generate'], 'alice', ['fail_forwarder' => true]],
        'generate.anonymous_delivery' => [['action' => 'generate'], null, ['fail_forwarder' => true]],
        'get_emails.invalid_address' => [['action' => 'get_emails', 'address' => 'nope'], null, []],
        'get_emails.auth_required' => [['action' => 'get_emails', 'address' => 'aabbccdd11'], null, []],
        'has_new_emails.invalid_address' => [['action' => 'has_new_emails', 'address' => 'nope'], null, []],
        'has_new_emails.failed' => [['action' => 'has_new_emails', 'address' => 'eeff001122'], null, []],
        'refresh_emails.invalid_address' => [['action' => 'refresh_emails', 'address' => 'nope'], null, []],
        'refresh_emails.auth_required' => [['action' => 'refresh_emails', 'address' => 'aabbccdd11'], null, []],
        'refresh_emails.none_found' => [['action' => 'refresh_emails', 'address' => 'eeff001122'], null, []],
        'get_email.invalid_id' => [['action' => 'get_email', 'email_id' => '0'], null, []],
        'get_email.auth_required_sticky' => [['action' => 'get_email', 'email_id' => '3'], null, []],
        'get_email.auth_required_email' => [['action' => 'get_email', 'email_id' => '2'], null, []],
        'get_email.not_found' => [['action' => 'get_email', 'email_id' => '999'], null, []],
        'get_email.expired' => [['action' => 'get_email', 'email_id' => '1'], 'alice', []],
        'get_stats.not_signed_in' => [['action' => 'get_stats'], null, []],
        'unknown_action' => [['action' => 'nothing'], null, []],
    ];
}

/**
 * Runs one case; returns the trimmed response body and the raw request result.
 * $locale is sent as X-MS-Locale and switches Swedish on (LOCALES_ENABLED and
 * the '@app' gate); $acceptLanguage is sent as Accept-Language.
 *
 * @param array{root:string,ids:array<string,int>} $probe
 * @return array{body:string,json:?array,res:array<string,mixed>}
 */
function ms_aa_run(array $probe, string $name, ?string $locale = null, ?string $acceptLanguage = null): array
{
    $cases = ms_aa_cases();
    [$post, $user, $opts] = $cases[$name];
    $server = [];
    if ($locale !== null) {
        $server['HTTP_X_MS_LOCALE'] = $locale;
    }
    if ($acceptLanguage !== null) {
        $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
    }
    $sv = $locale !== null || $acceptLanguage !== null;
    file_put_contents($probe['root'] . '/probe_extra.json', json_encode([
        'server' => $server,
        'locales' => $sv ? 'en,sv' : 'en',
        'app_sv' => $sv,
        'fail_forwarder' => !empty($opts['fail_forwarder']),
    ]));
    $request = [
        'page' => 'index.php',
        'method' => 'POST',
        'user_id' => $user === null ? 0 : $probe['ids'][$user],
        'user_email' => $user === null ? '' : $user . '@example.com',
        'post' => $post,
    ];
    if (isset($opts['origin'])) {
        $request['origin'] = $opts['origin'];
    }
    $res = ms_test_request($probe['root'], $request);
    return ['body' => trim((string) $res['stdout']), 'json' => $res['json'], 'res' => $res];
}
