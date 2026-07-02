<?php

declare(strict_types=1);

define('CLIENT_AGENT_LIBRARY_ONLY', true);
require_once __DIR__ . '/agent.php';

function clientAgentInstallPrompt(string $question, ?string $default = null, bool $secret = false): string {
    $suffix = $default !== null && $default !== '' ? ' [' . $default . ']' : '';
    fwrite(STDOUT, $question . $suffix . ': ');

    $usedNoEcho = false;
    if ($secret && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo');
        $usedNoEcho = true;
    }

    $line = fgets(STDIN);

    if ($usedNoEcho) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    $value = is_string($line) ? trim($line) : '';
    if ($value === '' && $default !== null) {
        return $default;
    }

    return $value;
}

function clientAgentInstallEncryptValue(string $plain, string $key): string {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext) || $tag === '') {
        throw new RuntimeException('Failed to encrypt value');
    }

    return base64_encode($nonce . $ciphertext . $tag);
}

function clientAgentInstallUpdateEmbeddedConfig(string $privateRoot, string $defaultScriptId): bool {
    $agentPath = __DIR__ . '/agent.php';
    $source = file_get_contents($agentPath);
    if (!is_string($source) || $source === '') {
        return false;
    }

    $replacement = "/* CLIENT_AGENT_INSTALL_CONFIG_START */\n"
        . "$" . "clientAgentEmbeddedConfig = [\n"
        . "    'private_root' => " . var_export($privateRoot, true) . ",\n"
        . "    'default_script_id' => " . var_export($defaultScriptId, true) . ",\n"
        . "];\n"
        . "/* CLIENT_AGENT_INSTALL_CONFIG_END */";

    $updated = preg_replace(
        '/\/\* CLIENT_AGENT_INSTALL_CONFIG_START \*\/.*?\/\* CLIENT_AGENT_INSTALL_CONFIG_END \*\//s',
        $replacement,
        $source,
        1
    );

    if (!is_string($updated)) {
        return false;
    }

    // Idempotent re-installs: if config is already current, this is a success.
    if ($updated === $source) {
        return true;
    }

    return file_put_contents($agentPath, $updated) !== false;
}

function clientAgentRunInstaller(?string $scriptIdArg = null): int {
    if (!extension_loaded('openssl')) {
        fwrite(STDERR, "OpenSSL extension is required for --install mode." . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, "Client Agent installer" . PHP_EOL);
    fwrite(STDOUT, "This will create key + encrypted local config outside web-root." . PHP_EOL . PHP_EOL);

    $embeddedDefaultScriptId = function_exists('clientAgentGetEmbeddedDefaultScriptId') ? clientAgentGetEmbeddedDefaultScriptId() : '';
    $defaultScriptId = is_string($scriptIdArg) && trim($scriptIdArg) !== ''
        ? trim($scriptIdArg)
        : ($embeddedDefaultScriptId !== '' ? $embeddedDefaultScriptId : 'demo');
    $scriptId = trim(clientAgentInstallPrompt('Script ID to configure', $defaultScriptId));
    if ($scriptId === '') {
        fwrite(STDERR, "Script ID is required." . PHP_EOL);
        return 1;
    }

    $embeddedPrivateRoot = function_exists('clientAgentGetEmbeddedPrivateRoot') ? clientAgentGetEmbeddedPrivateRoot() : '';
    $cwd = getcwd();
    $defaultSecureDir = $embeddedPrivateRoot !== ''
        ? $embeddedPrivateRoot
        : (is_string($cwd) && $cwd !== ''
            ? dirname($cwd) . DIRECTORY_SEPARATOR . 'tempmail-private'
            : '..' . DIRECTORY_SEPARATOR . 'tempmail-private');

    $secureDir = clientAgentInstallPrompt('Secure directory path', $defaultSecureDir);
    if ($secureDir === '') {
        fwrite(STDERR, "Secure directory path is required." . PHP_EOL);
        return 1;
    }

    if (!is_dir($secureDir) && !mkdir($secureDir, 0700, true)) {
        fwrite(STDERR, "Failed to create directory: {$secureDir}" . PHP_EOL);
        return 1;
    }

    $keyPath = $secureDir . DIRECTORY_SEPARATOR . 'client-agent.key';
    if (file_exists($keyPath)) {
        $overwrite = strtolower(clientAgentInstallPrompt('Key already exists. Overwrite? (yes/no)', 'no'));
        if (!in_array($overwrite, ['y', 'yes'], true)) {
            fwrite(STDOUT, "Keeping existing key file." . PHP_EOL);
        } else {
            $newKeyHex = bin2hex(random_bytes(32));
            if (file_put_contents($keyPath, $newKeyHex . PHP_EOL) === false) {
                fwrite(STDERR, "Failed to write key file." . PHP_EOL);
                return 1;
            }
        }
    } else {
        $newKeyHex = bin2hex(random_bytes(32));
        if (file_put_contents($keyPath, $newKeyHex . PHP_EOL) === false) {
            fwrite(STDERR, "Failed to write key file." . PHP_EOL);
            return 1;
        }
    }

    @chmod($keyPath, 0600);

    $key = clientAgentReadEncryptionKey($keyPath);
    if (!is_string($key) || strlen($key) !== 32) {
        fwrite(STDERR, "Invalid key file after creation: {$keyPath}" . PHP_EOL);
        return 1;
    }

    $imapHost = clientAgentInstallPrompt('IMAP host (example imap.websupport.se)');
    $imapPortRaw = clientAgentInstallPrompt('IMAP port', '993');
    $imapSslRaw = strtolower(clientAgentInstallPrompt('Use SSL? (yes/no)', 'yes'));
    $imapFolder = clientAgentInstallPrompt('Mailbox folder', 'INBOX');
    $imapUser = clientAgentInstallPrompt('IMAP user/login (email)');
    $imapPassword = clientAgentInstallPrompt('IMAP password', null, true);

    $imapPort = (int) $imapPortRaw;
    $useSsl = in_array($imapSslRaw, ['y', 'yes', '1', 'true'], true);
    $imapServer = clientAgentBuildMailboxString($imapHost, $imapPort, $useSsl, $imapFolder);

    if ($imapHost === '' || $imapPort <= 0 || $imapUser === '' || $imapPassword === '') {
        fwrite(STDERR, "IMAP host, port, user and password are required." . PHP_EOL);
        return 1;
    }

    try {
        $serverEnc = clientAgentInstallEncryptValue($imapServer, $key);
        $userEnc = clientAgentInstallEncryptValue($imapUser, $key);
        $passwordEnc = clientAgentInstallEncryptValue($imapPassword, $key);
    } catch (Throwable $e) {
        fwrite(STDERR, "Encryption failed: " . $e->getMessage() . PHP_EOL);
        return 1;
    }

    $safeScriptId = preg_replace('/[^a-zA-Z0-9._-]/', '_', $scriptId);
    $localConfigPath = $secureDir . DIRECTORY_SEPARATOR . 'client-agent-' . $safeScriptId . '.local.php';
    $localConfig = "<?php\nreturn [\n"
        . "    'key_file' => " . var_export($keyPath, true) . ",\n"
        . "    'imap_server_encrypted' => " . var_export($serverEnc, true) . ",\n"
        . "    'imap_user_encrypted' => " . var_export($userEnc, true) . ",\n"
        . "    'imap_password_encrypted' => " . var_export($passwordEnc, true) . ",\n"
        . "];\n";

    if (file_put_contents($localConfigPath, $localConfig) === false) {
        fwrite(STDERR, "Failed to write local config file: {$localConfigPath}" . PHP_EOL);
        return 1;
    }

    @chmod($localConfigPath, 0600);

    putenv('CLIENT_AGENT_PRIVATE_ROOT=' . $secureDir);
    $map = clientAgentLoadLocalConfigMap();
    $map[$scriptId] = $localConfigPath;

    if (!clientAgentSaveLocalConfigMap($map)) {
        fwrite(STDERR, "Failed to save script config map file." . PHP_EOL);
        return 1;
    }

    if (!clientAgentInstallUpdateEmbeddedConfig($secureDir, $scriptId)) {
        fwrite(STDERR, "Failed to write install config into agent.php. Check file permissions." . PHP_EOL);
        return 1;
    }

    putenv('CLIENT_AGENT_LOCAL_CONFIG_PATH=' . $localConfigPath);
    $selfCheck = clientAgentLoadEncryptedLocalImapSettings($scriptId);
    if (($selfCheck['success'] ?? false) !== true) {
        fwrite(STDERR, "Self-check failed: " . (string)($selfCheck['error'] ?? 'Unknown error') . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, PHP_EOL . "Installation complete for script_id: {$scriptId}" . PHP_EOL);
    fwrite(STDOUT, "Installed private config and embedded runtime settings." . PHP_EOL);
    fwrite(STDOUT, "Next: run agent with cron or URL trigger." . PHP_EOL);

    return 0;
}

function clientAgentPrintInstallerHelp(): void {
    $help = <<<'TXT'
Usage:
  php install.php --install [script_id]
      Interactive installer for key + local encrypted IMAP config.

TXT;
    fwrite(STDOUT, $help . PHP_EOL);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Installer is CLI-only.';
    exit;
}

$argv = $_SERVER['argv'] ?? [];
$cmd = $argv[1] ?? '--install';

if ($cmd === '--help' || $cmd === '-h' || $cmd === 'help') {
    clientAgentPrintInstallerHelp();
    exit(0);
}

if ($cmd === '--install' || $cmd === 'install') {
    $installScriptId = $argv[2] ?? null;
    exit(clientAgentRunInstaller(is_string($installScriptId) ? $installScriptId : null));
}

clientAgentPrintInstallerHelp();
exit(2);
