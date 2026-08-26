<?php

declare(strict_types=1);

/**
 * CLI diagnostic for the client-agent signing key infrastructure
 * (client/backend/bootstrap.php: clientBackendReadKek() /
 * clientBackendGenerateSigningKeyPair() / clientBackendRotateUserSigningKeys()).
 *
 * Both the "Rotate the keys used to sign client list-sync payloads" button
 * on pro_profile_page.php and cron/rotate_signing_keys.php ultimately fail
 * for the same handful of reasons and, until logging was added alongside
 * this script, none of them were visible anywhere. This script loads the
 * real config/environment (unlike check_directadmin_client.php/check_totp.php,
 * which stub out config.php) and walks through each precondition so a
 * failure can be diagnosed on the actual server without digging through logs.
 *
 * Never prints the KEK or any private key material - only booleans/lengths.
 *
 * Usage: php check_signing_keys.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client/backend/bootstrap.php';

$failures = 0;
$total = 0;

function check(string $label, bool $condition, string $detail = ''): void {
    global $failures, $total;
    $total++;
    if ($condition) {
        echo "PASS: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    } else {
        echo "FAIL: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
        $failures++;
    }
}

echo "== Client signing key diagnostics ==\n\n";

// ---------------------------------------------------------------------
// 1) openssl extension + a real key generation attempt. On shared hosting
//    this is the classic failure: openssl_pkey_new() returns false because
//    PHP can't find a usable openssl.cnf.
// ---------------------------------------------------------------------
check('openssl extension loaded', function_exists('openssl_pkey_new'));

$pair = clientBackendGenerateSigningKeyPair();
check('Can generate an RSA signing key pair (openssl_pkey_new/export/get_details)', is_array($pair));
if (!is_array($pair)) {
    echo "  -> see the ERROR line just above for the openssl error string; check system_logs / debug log too.\n";
}

// ---------------------------------------------------------------------
// 2) KEK (key-encryption-key) configuration. Read from
//    CLIENT_BACKEND_KEK_PATH or CLIENT_KEK_FILE - a file path, not the
//    key itself. Undocumented outside bootstrap.php, so this is the most
//    likely thing to be missing on a given deployment.
// ---------------------------------------------------------------------
$kekPath = getenv('CLIENT_BACKEND_KEK_PATH') ?: ($_ENV['CLIENT_BACKEND_KEK_PATH'] ?? '') ?: getenv('CLIENT_KEK_FILE') ?: ($_ENV['CLIENT_KEK_FILE'] ?? '');
check('CLIENT_BACKEND_KEK_PATH or CLIENT_KEK_FILE is set', is_string($kekPath) && trim($kekPath) !== '');

if (is_string($kekPath) && trim($kekPath) !== '') {
    check('KEK file exists and is readable', is_readable($kekPath), $kekPath);

    $kek = clientBackendReadKek();
    check('KEK file contains a valid 32-byte key (64 hex chars, base64, or 32 raw bytes)', is_string($kek) && strlen($kek) === 32);
} else {
    echo "  -> set this env var (in your .env.{environment} file) to the path of a file\n";
    echo "     holding a 32-byte key, as 64 hex chars, base64, or 32 raw bytes.\n";
    echo "     Generate one with: openssl rand -hex 32 > /path/outside/webroot/client_backend.kek\n";
}

// ---------------------------------------------------------------------
// 3) DB reachability + schema (rotation needs pro_users with the
//    agent_signing_* columns).
// ---------------------------------------------------------------------
check('Database connection available', isset($pdo) && $pdo instanceof PDO);

if (isset($pdo) && $pdo instanceof PDO) {
    check('pro_users table exists', clientBackendHasDbTable('pro_users'));
    foreach (['agent_signing_public_key', 'agent_signing_private_key_enc', 'agent_signing_key_version', 'agent_signing_updated_at'] as $col) {
        check("pro_users.$col column exists", clientBackendHasDbColumn('pro_users', $col));
    }
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
if ($failures > 0) {
    echo "See ERROR-level entries just above (also written via logMessage()) for exact reasons.\n";
}
exit($failures === 0 ? 0 : 1);
