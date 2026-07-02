<?php

declare(strict_types=1);

function usage(): void {
    $msg = <<<'TXT'
Usage:
  php encrypt_local_config.php \
    --key-file=/etc/tempmail/client-agent.key \
    --imap-server='{imap.example.com:993/imap/ssl}INBOX' \
    --imap-user='user@example.com' \
    --imap-password='super-secret-password'

Output:
  Prints PHP array values for:
  - imap_server_encrypted
  - imap_user_encrypted
  - imap_password_encrypted
TXT;
    fwrite(STDERR, $msg . PHP_EOL);
}

function readKey(string $keyFile): ?string {
    if (!is_readable($keyFile)) {
        return null;
    }

    $raw = file_get_contents($keyFile);
    if (!is_string($raw)) {
        return null;
    }

    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
        $bin = hex2bin($value);
        return is_string($bin) ? $bin : null;
    }

    $decoded = base64_decode($value, true);
    if (is_string($decoded) && strlen($decoded) === 32) {
        return $decoded;
    }

    return strlen($value) === 32 ? $value : null;
}

function encryptValue(string $plain, string $key): string {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext) || $tag === '') {
        throw new RuntimeException('Encryption failed');
    }

    return base64_encode($nonce . $ciphertext . $tag);
}

$options = getopt('', ['key-file:', 'imap-server:', 'imap-user:', 'imap-password:']);

$keyFile = (string) ($options['key-file'] ?? '');
$imapServer = (string) ($options['imap-server'] ?? '');
$imapUser = (string) ($options['imap-user'] ?? '');
$imapPassword = (string) ($options['imap-password'] ?? '');

if ($keyFile === '' || $imapServer === '' || $imapUser === '' || $imapPassword === '') {
    usage();
    exit(1);
}

$key = readKey($keyFile);
if (!is_string($key) || strlen($key) !== 32) {
    fwrite(STDERR, 'Invalid key file. Expected 32-byte key (plain32/base64/hex64).' . PHP_EOL);
    exit(1);
}

try {
    $serverEnc = encryptValue($imapServer, $key);
    $userEnc = encryptValue($imapUser, $key);
    $passwordEnc = encryptValue($imapPassword, $key);
} catch (Throwable $e) {
    fwrite(STDERR, 'Encryption error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "'imap_server_encrypted' => '" . addslashes($serverEnc) . "'," . PHP_EOL;
echo "'imap_user_encrypted' => '" . addslashes($userEnc) . "'," . PHP_EOL;
echo "'imap_password_encrypted' => '" . addslashes($passwordEnc) . "'," . PHP_EOL;
