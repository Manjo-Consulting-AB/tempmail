<?php

declare(strict_types=1);

if (!defined('CLIENT_AGENT_BOOTSTRAPPED')) {
    define('CLIENT_AGENT_BOOTSTRAPPED', true);
    define('CLIENT_AGENT_ROOT', __DIR__);
}

function clientAgentGetPrivateRoot(): string {
    $env = getenv('CLIENT_AGENT_PRIVATE_ROOT');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($documentRoot) && trim($documentRoot) !== '') {
        return rtrim(dirname($documentRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tempmail-private';
    }

    $home = getenv('HOME') ?: getenv('USERPROFILE');
    if (is_string($home) && trim($home) !== '') {
        return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tempmail-private';
    }

    return rtrim(CLIENT_AGENT_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tempmail-private';
}

function clientAgentGetStorageDir(): string {
    return rtrim(clientAgentGetPrivateRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage';
}

function clientAgentEnsureStorage(): void {
    $storageDir = clientAgentGetStorageDir();
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0700, true);
    }
}

function clientAgentLoadSettings(string $scriptId): array {
    clientAgentEnsureStorage();

    $file = clientAgentGetStorageDir() . '/' . $scriptId . '.json';
    if (!file_exists($file)) {
        return [
            'script_id' => $scriptId,
            'whitelist' => [],
            'blacklist' => [],
            'spam_filters' => [],
            'greylist_days' => 30,
            'dry_run' => false,
            'logging_enabled' => true,
            'last_run' => null,
        ];
    }

    $json = file_get_contents($file);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function clientAgentSaveSettings(string $scriptId, array $settings): void {
    clientAgentEnsureStorage();

    $file = clientAgentGetStorageDir() . '/' . $scriptId . '.json';
    file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function clientAgentLog(string $scriptId, string $message): void {
    clientAgentEnsureStorage();
    $logFile = clientAgentGetStorageDir() . '/' . $scriptId . '.log';
    $entry = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function clientAgentVerifyWebhookSignature(string $body, string $headerSignature, string $publicKeyPem): bool {
    $prefix = 'rsa-sha256=';
    if (stripos($headerSignature, $prefix) !== 0) {
        return false;
    }

    $encoded = trim(substr($headerSignature, strlen($prefix)));
    $signature = base64_decode($encoded, true);
    if (!is_string($signature) || $signature === '') {
        return false;
    }

    $publicKey = openssl_pkey_get_public($publicKeyPem);
    if ($publicKey === false) {
        return false;
    }

    $verified = openssl_verify($body, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    if (is_resource($publicKey) || $publicKey instanceof OpenSSLAsymmetricKey) {
        openssl_free_key($publicKey);
    }

    return $verified;
}

function clientAgentIsWebhookTimestampFresh(array $payload, int $ttlSeconds = 600): bool {
    $timestampRaw = $payload['timestamp'] ?? null;
    if (!is_string($timestampRaw) || trim($timestampRaw) === '') {
        return false;
    }

    $timestamp = strtotime($timestampRaw);
    if ($timestamp === false) {
        return false;
    }

    return abs(time() - $timestamp) <= $ttlSeconds;
}

function clientAgentGetLocalConfigMapPath(): string {
    return rtrim(clientAgentGetPrivateRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'client-agent.config-map.json';
}

function clientAgentLoadLocalConfigMap(): array {
    $mapPath = clientAgentGetLocalConfigMapPath();
    if (!is_readable($mapPath)) {
        return [];
    }

    $json = file_get_contents($mapPath);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function clientAgentGetLocalConfigPath(?string $scriptId = null): ?string {
    $path = getenv('CLIENT_AGENT_LOCAL_CONFIG_PATH');
    if (is_string($path) && trim($path) !== '') {
        return trim($path);
    }

    if (is_string($scriptId) && trim($scriptId) !== '') {
        $map = clientAgentLoadLocalConfigMap();
        $mapped = $map[$scriptId] ?? null;
        if (is_string($mapped) && trim($mapped) !== '' && is_readable($mapped)) {
            return trim($mapped);
        }
    }

    $candidates = [
        rtrim(clientAgentGetPrivateRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'client-agent.local.php',
    ];

    if (is_string($scriptId) && trim($scriptId) !== '') {
        $safeScriptId = preg_replace('/[^a-zA-Z0-9._-]/', '_', $scriptId);
        array_unshift($candidates, rtrim(clientAgentGetPrivateRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'client-agent-' . $safeScriptId . '.local.php');
    }

    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function clientAgentBuildMailboxString(string $host, int $port, bool $useSsl, string $folder = 'INBOX'): string {
    $cleanHost = trim($host);
    $cleanFolder = trim($folder) !== '' ? trim($folder) : 'INBOX';
    $portPart = $port > 0 ? ':' . $port : '';
    $flags = '/imap' . ($useSsl ? '/ssl' : '');
    return '{' . $cleanHost . $portPart . $flags . '}' . $cleanFolder;
}

function clientAgentReadEncryptionKey(string $keyFile): ?string {
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

function clientAgentDecryptValue(string $encodedCiphertext, string $key): ?string {
    $raw = base64_decode($encodedCiphertext, true);
    if (!is_string($raw) || strlen($raw) <= 28) {
        return null;
    }

    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, -16);
    $ciphertext = substr($raw, 12, -16);

    if (!is_string($nonce) || !is_string($tag) || !is_string($ciphertext)) {
        return null;
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return is_string($plain) ? $plain : null;
}

function clientAgentLoadEncryptedLocalImapSettings(?string $scriptId = null): array {
    $configPath = clientAgentGetLocalConfigPath($scriptId);
    if ($configPath === null) {
        return [
            'success' => false,
            'error' => 'Missing CLIENT_AGENT_LOCAL_CONFIG_PATH',
        ];
    }

    if (!is_readable($configPath)) {
        return [
            'success' => false,
            'error' => 'Local IMAP config is not readable',
        ];
    }

    $config = require $configPath;
    if (!is_array($config)) {
        return [
            'success' => false,
            'error' => 'Local IMAP config must return an array',
        ];
    }

    $keyFile = isset($config['key_file']) ? (string) $config['key_file'] : '';
    $key = $keyFile !== '' ? clientAgentReadEncryptionKey($keyFile) : null;
    if (!is_string($key) || strlen($key) !== 32) {
        return [
            'success' => false,
            'error' => 'Invalid encryption key. key_file must point to a 32-byte key outside web-root',
        ];
    }

    $serverEnc = isset($config['imap_server_encrypted']) ? (string) $config['imap_server_encrypted'] : '';
    $userEnc = isset($config['imap_user_encrypted']) ? (string) $config['imap_user_encrypted'] : '';
    $passwordEnc = isset($config['imap_password_encrypted']) ? (string) $config['imap_password_encrypted'] : '';

    if ($serverEnc === '' || $userEnc === '' || $passwordEnc === '') {
        return [
            'success' => false,
            'error' => 'Encrypted IMAP fields are required in local config',
        ];
    }

    $server = clientAgentDecryptValue($serverEnc, $key);
    $user = clientAgentDecryptValue($userEnc, $key);
    $password = clientAgentDecryptValue($passwordEnc, $key);

    if (!is_string($server) || !is_string($user) || !is_string($password) || $server === '' || $user === '' || $password === '') {
        return [
            'success' => false,
            'error' => 'Failed to decrypt IMAP credentials',
        ];
    }

    return [
        'success' => true,
        'imap_server' => $server,
        'imap_user' => $user,
        'imap_password' => $password,
    ];
}

function clientAgentHandleWebhook(string $scriptId, array $payload, string $rawBody = ''): array {
    $settings = clientAgentLoadSettings($scriptId);
    $data = $payload['data'] ?? [];
    $action = $payload['action'] ?? 'update_lists';

    $receivedSignature = isset($payload['signature']) && is_string($payload['signature']) ? $payload['signature'] : '';
    if ($receivedSignature === '') {
        return [
            'status' => 'error',
            'script_id' => $scriptId,
            'action' => $action,
            'message' => 'Missing webhook signature',
        ];
    }

    $publicKeyPem = '';
    if (isset($data['signing_public_key']) && is_string($data['signing_public_key']) && trim($data['signing_public_key']) !== '') {
        $publicKeyPem = trim($data['signing_public_key']);
        $settings['signing_public_key'] = $publicKeyPem;
    } elseif (isset($settings['signing_public_key']) && is_string($settings['signing_public_key'])) {
        $publicKeyPem = trim($settings['signing_public_key']);
    }

    if ($publicKeyPem === '') {
        return [
            'status' => 'error',
            'script_id' => $scriptId,
            'action' => $action,
            'message' => 'Missing signing public key',
        ];
    }

    if (!clientAgentIsWebhookTimestampFresh($payload)) {
        return [
            'status' => 'error',
            'script_id' => $scriptId,
            'action' => $action,
            'message' => 'Webhook timestamp is invalid or too old',
        ];
    }

    $bodyForVerification = trim($rawBody) !== '' ? $rawBody : (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    if (!clientAgentVerifyWebhookSignature($bodyForVerification, $receivedSignature, $publicKeyPem)) {
        return [
            'status' => 'error',
            'script_id' => $scriptId,
            'action' => $action,
            'message' => 'Invalid webhook signature',
        ];
    }

    if (isset($data['imap_server']) || isset($data['imap_user']) || isset($data['imap_password'])) {
        clientAgentLog($scriptId, 'Ignored IMAP credentials in webhook payload. Credentials must be local and encrypted.');
    }

    if (!empty($data['whitelist'])) {
        $settings['whitelist'] = array_values(array_unique($data['whitelist']));
    }
    if (!empty($data['blacklist'])) {
        $settings['blacklist'] = array_values(array_unique($data['blacklist']));
    }
        if (isset($data['spam_filters']) && is_array($data['spam_filters'])) {
            $normalized = [];
            foreach ($data['spam_filters'] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $pattern = trim((string) ($rule['pattern'] ?? ''));
                $type = isset($rule['type']) && strtolower((string) $rule['type']) === 'regex' ? 'regex' : 'text';
                $caseInsensitive = !empty($rule['case_insensitive']);
                if ($pattern === '' || strlen($pattern) > 512) {
                    continue;
                }
                if ($type === 'regex' && !clientAgentIsSafeRegexPattern($pattern, $caseInsensitive)) {
                    continue;
                }
                $normalized[] = [
                    'rule_id' => isset($rule['rule_id']) ? (string) $rule['rule_id'] : 'r_' . bin2hex(random_bytes(8)),
                    'type' => $type,
                    'scope' => isset($rule['scope']) && in_array((string) $rule['scope'], ['subject', 'body', 'from', 'any'], true) ? (string) $rule['scope'] : 'any',
                    'pattern' => $pattern,
                    'case_insensitive' => $caseInsensitive,
                ];
            }
            $settings['spam_filters'] = $normalized;
        }
    if (isset($data['greylist_days'])) {
        $settings['greylist_days'] = (int) $data['greylist_days'];
    }
    if (array_key_exists('dry_run', $data)) {
        $settings['dry_run'] = (bool) $data['dry_run'];
    }
    if (isset($data['signing_key_id']) && is_string($data['signing_key_id']) && trim($data['signing_key_id']) !== '') {
        $settings['signing_key_id'] = trim($data['signing_key_id']);
    }

    $settings['last_run'] = gmdate('c');
    clientAgentSaveSettings($scriptId, $settings);
    clientAgentLog($scriptId, 'Webhook applied: ' . $action);

    return [
        'status' => 'ok',
        'script_id' => $scriptId,
        'action' => $action,
        'settings' => $settings,
    ];
}

function clientAgentMatchesPattern(string $senderEmail, string $pattern): bool {
    $senderEmail = strtolower(trim($senderEmail));
    $pattern = strtolower(trim($pattern));

    if ($pattern === '') {
        return false;
    }

    if (strpos($pattern, '@') === 0) {
        $domain = substr($senderEmail, strrpos($senderEmail, '@') + 1);
        return $domain === substr($pattern, 1);
    }

    if (strpos($pattern, '*') !== false) {
        $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
        return preg_match($regex, $senderEmail) === 1;
    }

    return $senderEmail === $pattern;
}

function clientAgentIsSafeRegexPattern(string $pattern, bool $caseInsensitive = false): bool {
    if ($pattern === '' || strlen($pattern) > 512) {
        return false;
    }

    $regex = '~' . str_replace('~', '\\~', $pattern) . '~' . ($caseInsensitive ? 'i' : '');
    $ok = @preg_match($regex, '');
    return $ok !== false && preg_last_error() === PREG_NO_ERROR;
}

    function clientAgentDoesSpamRuleMatch(array $rule, string $senderEmail, string $subject, string $body): bool {
        $scope = isset($rule['scope']) ? (string) $rule['scope'] : 'any';
        $type = isset($rule['type']) ? strtolower((string) $rule['type']) : 'text';
        $pattern = trim((string) ($rule['pattern'] ?? ''));
        if ($pattern === '' || strlen($pattern) > 512) {
            return false;
        }

        $fields = [];
        if ($scope === 'from' || $scope === 'any') {
            $fields[] = $senderEmail;
        }
        if ($scope === 'subject' || $scope === 'any') {
            $fields[] = $subject;
        }
        if ($scope === 'body' || $scope === 'any') {
            $fields[] = $body;
        }

        if ($type === 'regex') {
            $caseInsensitive = !empty($rule['case_insensitive']);
            if (!clientAgentIsSafeRegexPattern($pattern, $caseInsensitive)) {
                return false;
            }

            $delimiter = '~';
            $regex = $delimiter . str_replace($delimiter, '\\' . $delimiter, $pattern) . $delimiter . ($caseInsensitive ? 'i' : '');
            foreach ($fields as $field) {
                if (preg_match($regex, (string) $field) === 1) {
                    return true;
                }
            }
            return false;
        }

        foreach ($fields as $field) {
            $haystack = (string) $field;
            if (!empty($rule['case_insensitive']) ? stripos($haystack, $pattern) !== false : strpos($haystack, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    function clientAgentShouldDeleteMessage(array $settings, string $senderEmail, string $subject, string $body, int $receivedAt): bool {
    foreach ($settings['whitelist'] ?? [] as $pattern) {
        if (clientAgentMatchesPattern($senderEmail, $pattern)) {
            return false;
        }
    }

    foreach ($settings['blacklist'] ?? [] as $pattern) {
        if (clientAgentMatchesPattern($senderEmail, $pattern)) {
            return true;
        }
    }

        foreach ($settings['spam_filters'] ?? [] as $rule) {
            if (is_array($rule) && clientAgentDoesSpamRuleMatch($rule, $senderEmail, $subject, $body)) {
                return true;
            }
        }

    $greylistDays = (int) ($settings['greylist_days'] ?? 30);
    if ($greylistDays > 0) {
        $cutoff = strtotime('-' . $greylistDays . ' days');
        return $receivedAt < $cutoff;
    }

    return false;
}

function clientAgentConnectImap(string $scriptId, array $settings): array {
    $localImap = clientAgentLoadEncryptedLocalImapSettings($scriptId);
    if (($localImap['success'] ?? false) !== true) {
        return [
            'success' => false,
            'mode' => 'fallback',
            'error' => (string) ($localImap['error'] ?? 'Local encrypted IMAP config is missing'),
            'connection' => null,
        ];
    }

    $server = (string) $localImap['imap_server'];
    $user = (string) $localImap['imap_user'];
    $password = (string) $localImap['imap_password'];

    if (!extension_loaded('imap')) {
        return [
            'success' => false,
            'mode' => 'fallback',
            'error' => 'PHP IMAP extension is not available',
            'connection' => null,
        ];
    }

    $mailbox = @imap_open($server, $user, $password);
    if ($mailbox === false) {
        return [
            'success' => false,
            'mode' => 'fallback',
            'error' => imap_last_error() ?: 'IMAP connection failed',
            'connection' => null,
        ];
    }

    return [
        'success' => true,
        'mode' => 'imap',
        'error' => null,
        'connection' => $mailbox,
    ];
}

function clientAgentExtractSenderEmail($header): string {
    if (!is_object($header) || empty($header->from)) {
        return '';
    }

    $from = $header->from[0] ?? null;
    if (!is_object($from)) {
        return '';
    }

    $mailbox = $from->mailbox ?? '';
    $host = $from->host ?? '';
    if ($mailbox === '' || $host === '') {
        return '';
    }

    return strtolower($mailbox . '@' . $host);
}

    function clientAgentExtractMessageSubject($header): string {
        if (!is_object($header)) {
            return '';
        }

        $subject = $header->subject ?? '';
        return is_string($subject) ? trim($subject) : '';
    }

    function clientAgentExtractMessageBody($imapHandle, int $messageId): string {
        $body = @imap_body($imapHandle, $messageId, FT_PEEK) ?: '';
        if ($body === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($body, 0, 12000);
        }

        return substr($body, 0, 12000);
    }

function clientAgentRunCycle(string $scriptId): array {
    $settings = clientAgentLoadSettings($scriptId);
    clientAgentLog($scriptId, 'Agent cycle started');

    $results = [
        'kept' => 0,
        'deleted' => 0,
        'messages' => [],
    ];

    $dryRun = (bool) ($settings['dry_run'] ?? true);

    $imapConnection = clientAgentConnectImap($scriptId, $settings);
    if (!$imapConnection['success']) {
        $settings['last_run'] = gmdate('c');
        clientAgentSaveSettings($scriptId, $settings);
        clientAgentLog($scriptId, 'IMAP_ERROR: ' . $imapConnection['error']);
        return [
            'status' => 'error',
            'script_id' => $scriptId,
            'message' => $imapConnection['error'],
            'mode' => $imapConnection['mode'] ?? 'fallback',
            'settings' => $settings,
            'results' => $results,
        ];
    }

    $imapHandle = $imapConnection['connection'];
    $messageIds = @imap_search($imapHandle, 'ALL');
    if (!is_array($messageIds) || $messageIds === []) {
        $settings['last_run'] = gmdate('c');
        clientAgentSaveSettings($scriptId, $settings);
        return [
            'status' => 'ok',
            'script_id' => $scriptId,
            'message' => 'No messages found',
            'settings' => $settings,
            'results' => $results,
        ];
    }

    foreach ($messageIds as $messageId) {
        $header = @imap_headerinfo($imapHandle, (int) $messageId);
        $sender = clientAgentExtractSenderEmail($header);
            $subject = clientAgentExtractMessageSubject($header);
            $body = clientAgentExtractMessageBody($imapHandle, (int) $messageId);
        $receivedAt = isset($header->date) ? strtotime($header->date) : time();
            $delete = clientAgentShouldDeleteMessage($settings, $sender, $subject, $body, (int) $receivedAt);
        $action = $delete ? ($dryRun ? 'would_delete' : 'delete') : 'keep';

        $results['messages'][] = [
            'id' => (int) $messageId,
            'sender' => $sender,
                'subject' => $subject,
            'action' => $action,
        ];

        if ($delete) {
            $results['deleted']++;
            if (!$dryRun) {
                @imap_delete($imapHandle, (string) $messageId);
            }
            clientAgentLog($scriptId, 'DELETE ' . $sender);
        } else {
            $results['kept']++;
            clientAgentLog($scriptId, 'KEEP ' . $sender);
        }
    }

    if (!$dryRun) {
        @imap_expunge($imapHandle);
    }

    @imap_close($imapHandle);

    $settings['last_run'] = gmdate('c');
    clientAgentSaveSettings($scriptId, $settings);

    return [
        'status' => 'ok',
        'script_id' => $scriptId,
        'message' => 'Agent cycle completed',
        'dry_run' => $dryRun,
        'settings' => $settings,
        'results' => $results,
    ];
}
