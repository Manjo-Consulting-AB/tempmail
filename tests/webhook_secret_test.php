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

// --- Credentials inside a hook's config (Pushover token + user key) ---
$cfg = ['token' => 'azGDORePK8gMaC0QOYAMyEEuzJnyUi', 'user' => 'uQiRzpo4DXghDmr9QzzfQu27cmVRsG', 'sound' => 'magic', 'priority' => 1];
$sealedCfg = webhookConfigSeal($cfg, 'pushover', $key);
check('seal encrypts token and user', is_array($sealedCfg) && webhookConfigIsSealed($sealedCfg['token']) && webhookConfigIsSealed($sealedCfg['user']));
check('seal leaves the other fields readable', $sealedCfg['sound'] === 'magic' && $sealedCfg['priority'] === 1);
check('stored JSON no longer contains the credentials', strpos(json_encode($sealedCfg), $cfg['token']) === false && strpos(json_encode($sealedCfg), $cfg['user']) === false);
check('seal is idempotent', webhookConfigSeal($sealedCfg, 'pushover', $key) === $sealedCfg);
check('open restores the original config', webhookConfigOpen($sealedCfg, 'pushover', $key) === $cfg);
check('open passes legacy plaintext through', webhookConfigOpen($cfg, 'pushover', $key) === $cfg);
check('generic hooks are left alone', webhookConfigSeal(['token' => 'x'], 'generic', $key) === ['token' => 'x']);
check('seal without a key refuses', webhookConfigSeal($cfg, 'pushover', '') === null);
$threw = false;
try { webhookConfigOpen($sealedCfg, 'pushover', 'other-key'); } catch (RuntimeException $e) { $threw = true; }
check('open with the wrong key throws (delivery fails, never sends ciphertext)', $threw);
$masked = webhookConfigMask($sealedCfg, 'pushover', $key);
check('mask shows only the last four characters', $masked['token'] === '••••' . substr($cfg['token'], -4) && $masked['user'] === '••••' . substr($cfg['user'], -4));
check('mask keeps the other fields', $masked['sound'] === 'magic');
check('mask of legacy plaintext hides it too', webhookConfigMask($cfg, 'pushover', $key)['token'] === '••••' . substr($cfg['token'], -4));
check('mask when it cannot decrypt shows no characters', webhookConfigMask($sealedCfg, 'pushover', 'other-key')['token'] === '••••');

echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
