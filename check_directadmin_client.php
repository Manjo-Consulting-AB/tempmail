<?php

declare(strict_types=1);

/**
 * CLI smoke test for DirectAdminClient.php, in the same spirit as
 * check_totp.php / check_parser.php. Exercises only the class's
 * pure/local logic (construction, alias sanitization, config guard) —
 * no real network calls against DirectAdmin are made.
 *
 * Usage: php check_directadmin_client.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

if (!function_exists('logMessage')) {
    // DirectAdminClient calls logMessage() on error/info paths; provide a
    // minimal, DB-free stand-in so this script never needs config.php/a database.
    function logMessage($level, $message, $context = null): void {
        // no-op: this smoke test asserts return values, not log output
    }
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/DirectAdminClient.php';

$failures = 0;
$total = 0;

function check(string $label, bool $condition): void {
    global $failures, $total;
    $total++;
    if ($condition) {
        echo "PASS: $label\n";
    } else {
        echo "FAIL: $label\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------
// Class loads and can be instantiated with a full/empty config
// ---------------------------------------------------------------------

$client = new DirectAdminClient([
    'host' => 'https://server.example.com:2222',
    'user' => 'admin',
    'api_key' => 'not-a-real-key',
    'domain' => 'manjo.me',
]);
check('DirectAdminClient can be instantiated with config', $client instanceof DirectAdminClient);

check('createForwarder and deleteForwarder methods exist', method_exists($client, 'createForwarder') && method_exists($client, 'deleteForwarder'));

// ---------------------------------------------------------------------
// Unconfigured client fails closed (no host/user/api_key/domain) rather
// than attempting a network call or throwing.
// ---------------------------------------------------------------------

$unconfigured = new DirectAdminClient([]);
check('createForwarder returns false when unconfigured (no network call attempted)', $unconfigured->createForwarder('abc12345', 'test@example.com') === false);
check('deleteForwarder returns false when unconfigured (no network call attempted)', $unconfigured->deleteForwarder('abc12345') === false);

// ---------------------------------------------------------------------
// Alias validation: only a safe local-part-like string should ever reach
// the API; anything else must be rejected before a request is built.
// ---------------------------------------------------------------------

$configured = new DirectAdminClient([
    'host' => 'https://server.example.com:2222',
    'user' => 'admin',
    'api_key' => 'not-a-real-key',
    'domain' => 'manjo.me',
]);

check('createForwarder rejects an alias containing "/"', $configured->createForwarder('abc/../etc', 'test@example.com') === false);
check('createForwarder rejects an alias containing whitespace', $configured->createForwarder('abc def', 'test@example.com') === false);
check('createForwarder rejects an empty alias', $configured->createForwarder('', 'test@example.com') === false);
check('deleteForwarder rejects an alias containing "@"', $configured->deleteForwarder('abc@manjo.me') === false);

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
