<?php
/**
 * CLI regression suite for webhook_secret.php: AES-256-GCM at rest, legacy
 * AES-256-CBC still readable, fail closed without a key. No database.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../webhook_secret.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "[OK]  {$name}\n"; } else { $failed++; echo "[FAIL] {$name}\n"; }
}

$key = 'test-key-from-env';

$enc = webhookSecretEncrypt('s3cr3t', $key);
check('encrypts to the v2 format', is_string($enc) && strncmp($enc, 'v2:', 3) === 0);
check('round-trips', webhookSecretDecrypt($enc, $key) === 's3cr3t');
check('two encryptions differ (random nonce)', webhookSecretEncrypt('s3cr3t', $key) !== $enc);
check('wrong key gives null', webhookSecretDecrypt($enc, 'other-key') === null);

$raw = base64_decode(substr($enc, 3));
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
check('tampered ciphertext gives null (authenticated)', webhookSecretDecrypt('v2:' . base64_encode($raw), $key) === null);
check('truncated value gives null', webhookSecretDecrypt('v2:' . base64_encode(substr($raw, 0, 20)), $key) === null);
check('garbage gives null', webhookSecretDecrypt('v2:***', $key) === null);

// A value written by the old pro_profile.php encrypt_webhook_secret().
$iv = random_bytes(16);
$legacy = base64_encode($iv . openssl_encrypt('old-secret', 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv));
check('legacy CBC value still decrypts', webhookSecretDecrypt($legacy, $key) === 'old-secret');

check('no key: encrypt refuses', webhookSecretEncrypt('s3cr3t', '') === null);
check('no key: decrypt refuses', webhookSecretDecrypt($enc, '') === null);
check('empty stored value gives null', webhookSecretDecrypt('', $key) === null && webhookSecretDecrypt(null, $key) === null);

$max = webhookSecretEncrypt(str_repeat('x', 100), $key);
check('a 100-character secret fits pro_webhooks.secret VARCHAR(191)', is_string($max) && strlen($max) <= 191);

echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
