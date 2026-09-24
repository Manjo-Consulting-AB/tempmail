<?php

declare(strict_types=1);

/**
 * Regression coverage for boolean env vars in config.php and the production
 * guards around debug-only conveniences.
 *
 * Env values are strings, so `DEBUG_MODE=false` used to yield the truthy
 * string "false" and turned debug mode on - which made request_login_link in
 * pro_auth.php return a working login link for any verified account.
 *
 * config.php cannot be required here (it opens a MySQL connection on load),
 * so this checks the parsing semantics directly and scans the source for the
 * patterns that must hold.
 *
 * Run with:  php tests/config_bool_env_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database, no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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

// ---------------------------------------------------------------------------
// Parsing semantics: the expression config.php uses for each boolean env var
// ---------------------------------------------------------------------------

function parseBoolEnv(array $env, string $key, bool $default): bool
{
    return filter_var($env[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
}

foreach (['false', 'FALSE', '0', '', 'off', 'no'] as $value) {
    check('B1. "' . $value . '" parses as false (default true)', parseBoolEnv(['DEBUG_MODE' => $value], 'DEBUG_MODE', true) === false);
}
foreach (['true', 'TRUE', '1', 'on', 'yes'] as $value) {
    check('B2. "' . $value . '" parses as true (default false)', parseBoolEnv(['DEBUG_MODE' => $value], 'DEBUG_MODE', false) === true);
}
check('B3. unset falls back to the default (false)', parseBoolEnv([], 'DEBUG_MODE', false) === false);
check('B4. unset falls back to the default (true)', parseBoolEnv([], 'DEBUG_MODE', true) === true);

// ---------------------------------------------------------------------------
// Source scan: config.php
// ---------------------------------------------------------------------------

$configSrc = (string) file_get_contents(__DIR__ . '/../config.php');
$authSrc = (string) file_get_contents(__DIR__ . '/../pro_auth.php');

// Every read of a boolean env var must go through FILTER_VALIDATE_BOOLEAN.
$boolVars = 'DEBUG_MODE|IMAP_ENABLED|DA_FORWARDER_ENABLED|PRO_SELF_SIGNUP_ENABLED|WEBHOOK_DELIVER_IMMEDIATELY';
$lines = preg_split('/\R/', $configSrc) ?: [];
$reads = 0;
foreach ($lines as $i => $line) {
    if (preg_match('/\$_ENV\[\'(' . $boolVars . ')\'\]/', $line, $m)) {
        $reads++;
        check('C1. config.php line ' . ($i + 1) . ' parses ' . $m[1] . ' as a boolean',
            strpos($line, 'filter_var(') !== false && strpos($line, 'FILTER_VALIDATE_BOOLEAN') !== false,
            trim($line));
    }
}
check('C2. the boolean env vars are read at all (scan found them)', $reads >= 7, "found {$reads}");

check('C3. appIsProduction() is defined in config.php',
    preg_match('/function\s+appIsProduction\s*\(\s*\)\s*:\s*bool/', $configSrc) === 1);

check('C4. the verbose DB error is guarded against production',
    preg_match('/if\s*\(\s*\$config\[\'app\'\]\[\'debug_mode\'\]\s*&&\s*!appIsProduction\(\)\s*\)\s*\{\s*die\("Databasanslutning/', $configSrc) === 1);

// ---------------------------------------------------------------------------
// Source scan: pro_auth.php - tokens and login links never leave in production
// ---------------------------------------------------------------------------

$authLines = preg_split('/\R/', $authSrc) ?: [];
$debugChecks = 0;
foreach ($authLines as $i => $line) {
    if (strpos($line, "\$config['app']['debug_mode']") !== false) {
        $debugChecks++;
        check('A1. pro_auth.php line ' . ($i + 1) . ' also requires !appIsProduction()',
            strpos($line, '!appIsProduction()') !== false, trim($line));
    }
}
check('A2. pro_auth.php debug checks found (magic link log, verification log, login_url)', $debugChecks >= 3, "found {$debugChecks}");

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
