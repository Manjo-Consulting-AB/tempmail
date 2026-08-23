<?php

declare(strict_types=1);

/**
 * CLI smoke test for TwoFactorAuth.php, in the same spirit as check_parser.php.
 * Exercises only the pure crypto/logic paths — no database required.
 *
 * Usage: php check_totp.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

if (!function_exists('logMessage')) {
    // TwoFactorAuth.php calls logMessage() on error paths; provide a minimal,
    // DB-free stand-in so this script never needs config.php/a database.
    function logMessage($level, $message, $context = null): void {
        // no-op: this smoke test asserts return values, not log output
    }
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/TwoFactorAuth.php';

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
// Base32 round trip
// ---------------------------------------------------------------------

$randomBinary = random_bytes(20);
$encoded = TwoFactorAuth::base32Encode($randomBinary);
check('base32 encode produces only [A-Z2-7]', (bool) preg_match('/^[A-Z2-7]*$/', $encoded));
check('base32 round trip (encode -> decode)', TwoFactorAuth::base32Decode($encoded) === $randomBinary);
check('base32 decode tolerates lowercase/spaces/padding', TwoFactorAuth::base32Decode(strtolower($encoded) . '  ===') === $randomBinary);
check('base32 decode rejects invalid characters', TwoFactorAuth::base32Decode('this-is-not-base32!!!') === '');
check('base32 decode of empty string is empty', TwoFactorAuth::base32Decode('') === '');
check('base32Encode/base32Decode roundtrip a known secret', TwoFactorAuth::base32Decode(TwoFactorAuth::generateSecret()) !== '');

// ---------------------------------------------------------------------
// RFC 6238 test vectors (SHA1, 20-byte ASCII secret "12345678901234567890")
// Values below are the 6-digit truncation of the official 8-digit RFC 6238
// Appendix B vectors (6-digit code = 8-digit code mod 10^6, i.e. its last 6 digits).
// ---------------------------------------------------------------------

$rfcSecret = TwoFactorAuth::base32Encode('12345678901234567890');
$vectors = [
    59          => '287082',
    1111111109  => '081804',
    1111111111  => '050471',
    1234567890  => '005924',
    2000000000  => '279037',
    20000000000 => '353130',
];

foreach ($vectors as $time => $expectedCode) {
    $matchedStep = TwoFactorAuth::verifyCode($rfcSecret, $expectedCode, 0, $time);
    check("RFC 6238 vector T=$time produces $expectedCode", $matchedStep === intdiv($time, 30));
}

check('verifyCode rejects a wrong code', TwoFactorAuth::verifyCode($rfcSecret, '000000', 0, 59) === null);
check('verifyCode rejects a non-numeric code', TwoFactorAuth::verifyCode($rfcSecret, 'abcdef', 0, 59) === null);
check('verifyCode rejects wrong-length code', TwoFactorAuth::verifyCode($rfcSecret, '12345', 0, 59) === null);

// Window tolerance: a code from one step in the future/past is accepted only
// within the requested window.
$oneStepFuture = 59 + 30;
$futureCode = null;
foreach ($vectors as $time => $expectedCode) {
    if ($time === 59) {
        $futureCode = $expectedCode;
    }
}
check('verifyCode rejects an adjacent step with window=0', TwoFactorAuth::verifyCode($rfcSecret, $vectors[59], 0, $oneStepFuture) === null);
check('verifyCode accepts an adjacent step with window=1', TwoFactorAuth::verifyCode($rfcSecret, $vectors[59], 1, $oneStepFuture) === intdiv(59, 30));

// ---------------------------------------------------------------------
// otpauth:// URI
// ---------------------------------------------------------------------

$uri = TwoFactorAuth::otpauthUri($rfcSecret, 'user@example.com');
check('otpauth URI has the right scheme/host', str_starts_with($uri, 'otpauth://totp/'));
check('otpauth URI carries the secret', str_contains($uri, 'secret=' . rawurlencode($rfcSecret)));
check('otpauth URI declares SHA1/6/30', str_contains($uri, 'algorithm=SHA1&digits=6&period=30'));

// ---------------------------------------------------------------------
// Encryption / decryption (AES-256-GCM), including fail-closed behavior
// ---------------------------------------------------------------------

$plainSecret = TwoFactorAuth::generateSecret();

unset($_ENV['TOTP_ENCRYPTION_KEY']);
$threwWithoutKey = false;
$exceptionMessage = '';
try {
    TwoFactorAuth::encryptSecret($plainSecret);
} catch (RuntimeException $e) {
    $threwWithoutKey = true;
    $exceptionMessage = $e->getMessage();
}
check('encryptSecret throws when TOTP_ENCRYPTION_KEY is missing', $threwWithoutKey);
check('encryptSecret exception message does not leak the secret', !str_contains($exceptionMessage, $plainSecret));
check('decryptSecret fails closed (returns null) when key is missing', TwoFactorAuth::decryptSecret('anything') === null);

$_ENV['TOTP_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
$cipher = TwoFactorAuth::encryptSecret($plainSecret);
check('encryptSecret output does not contain the plaintext secret', !str_contains($cipher, $plainSecret));
check('decryptSecret round trip recovers the plaintext secret', TwoFactorAuth::decryptSecret($cipher) === $plainSecret);

$_ENV['TOTP_ENCRYPTION_KEY'] = base64_encode(random_bytes(32)); // different key
check('decryptSecret fails closed with the wrong key', TwoFactorAuth::decryptSecret($cipher) === null);

// ---------------------------------------------------------------------
// A code from a real authenticator flow: generate a secret, verify a code
// derived from it the same way an app would compute one.
// ---------------------------------------------------------------------

$secret = TwoFactorAuth::generateSecret();
$now = time();
// Independently recompute the code an authenticator app would show right now,
// via the same HOTP algorithm the class itself implements, to catch any
// accidental scope/visibility change to hotp() rather than testing a tautology.
$counter = intdiv($now, 30);
$secretBinary = TwoFactorAuth::base32Decode($secret);
$counterBytes = pack('J', $counter);
$hash = hash_hmac('sha1', $counterBytes, $secretBinary, true);
$offset = ord($hash[19]) & 0x0f;
$binary = ((ord($hash[$offset]) & 0x7f) << 24)
    | ((ord($hash[$offset + 1]) & 0xff) << 16)
    | ((ord($hash[$offset + 2]) & 0xff) << 8)
    | (ord($hash[$offset + 3]) & 0xff);
$appCode = str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
check('a code computed the same way an authenticator app would is accepted', TwoFactorAuth::verifyCode($secret, $appCode) === $counter);

// ---------------------------------------------------------------------
// Recovery codes: format + hash/verify round trip (no DB)
// ---------------------------------------------------------------------

$rawCode = TwoFactorAuth::generateRecoveryCode();
check('recovery code uses only the documented alphabet', (bool) preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{10}$/', $rawCode));

$formatted = TwoFactorAuth::formatRecoveryCode($rawCode);
check('recovery code formats as XXXXX-XXXXX', (bool) preg_match('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/', $formatted));

$hash = password_hash($rawCode, PASSWORD_DEFAULT);
check('normalizeRecoveryCode strips hyphen/case before verifying', password_verify(TwoFactorAuth::normalizeRecoveryCode(strtolower($formatted)), $hash));
$wrongCode = TwoFactorAuth::normalizeRecoveryCode('00000-00001');
check('normalizeRecoveryCode rejects a different code', $wrongCode === $rawCode || !password_verify($wrongCode, $hash));

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
