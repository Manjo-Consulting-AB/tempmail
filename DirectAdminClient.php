<?php
/**
 * Isolated API client for DirectAdmin's email forwarder management
 * (CMD_API_EMAIL_FORWARDERS), used to create/delete the forwarder that
 * pipes incoming mail for a temp address into parse.php.
 *
 * This class has no dependency on the rest of the app beyond the
 * $config['directadmin'] block built in config.php — it can be
 * instantiated and unit-tested standalone (see check_directadmin_client.php).
 */

defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

class DirectAdminClient
{
    private string $host;
    private string $user;
    private string $apiKey;
    private string $domain;
    private int $timeoutSeconds;

    public function __construct(array $config)
    {
        $this->host = rtrim((string)($config['host'] ?? ''), '/');
        $this->user = (string)($config['user'] ?? '');
        $this->apiKey = (string)($config['api_key'] ?? '');
        $this->domain = (string)($config['domain'] ?? '');
        $this->timeoutSeconds = (int)($config['timeout_seconds'] ?? 10);
    }

    /**
     * Create a mail forwarder alias@domain -> destination.
     * $destination may be an email address or a pipe command
     * (e.g. "|/usr/bin/php /path/to/parse.php").
     */
    public function createForwarder(string $alias, string $destination): bool
    {
        $alias = $this->sanitizeAlias($alias);
        if ($alias === '') {
            $this->log('ERROR', 'DirectAdmin createForwarder: invalid alias', ['alias' => $alias]);
            return false;
        }

        $result = $this->request('CMD_API_EMAIL_FORWARDERS', [
            'action' => 'create',
            'domain' => $this->domain,
            'user' => $alias,
            'email' => $this->formatDestination($destination),
        ]);

        if ($result === null) {
            return false;
        }

        if (isset($result['error']) && (string)$result['error'] !== '0') {
            $this->log('ERROR', 'DirectAdmin createForwarder failed', [
                'alias' => $alias,
                'response_message' => $result['text'] ?? $result['message'] ?? null,
            ]);
            return false;
        }

        $this->log('INFO', 'DirectAdmin forwarder created', ['alias' => $alias]);
        return true;
    }

    /**
     * Delete a forwarder previously created for $alias.
     */
    public function deleteForwarder(string $alias): bool
    {
        $alias = $this->sanitizeAlias($alias);
        if ($alias === '') {
            $this->log('ERROR', 'DirectAdmin deleteForwarder: invalid alias', ['alias' => $alias]);
            return false;
        }

        $result = $this->request('CMD_API_EMAIL_FORWARDERS', [
            'action' => 'delete',
            'domain' => $this->domain,
            'select0' => $alias,
        ]);

        if ($result === null) {
            return false;
        }

        if (isset($result['error']) && (string)$result['error'] !== '0') {
            $this->log('ERROR', 'DirectAdmin deleteForwarder failed', [
                'alias' => $alias,
                'response_message' => $result['text'] ?? $result['message'] ?? null,
            ]);
            return false;
        }

        $this->log('INFO', 'DirectAdmin forwarder deleted', ['alias' => $alias]);
        return true;
    }

    /**
     * DirectAdmin's CMD_API_EMAIL_FORWARDERS rejects a bare "|command" value
     * in the 'email' field as "String contains an invalid email address" —
     * confirmed against the live server in #35. The control panel's own UI
     * wraps pipe destinations in literal double quotes ("|command"), which
     * the API accepts, so replicate that here rather than sending the pipe
     * syntax raw.
     */
    private function formatDestination(string $destination): string
    {
        if (str_starts_with($destination, '|')) {
            return '"' . $destination . '"';
        }
        return $destination;
    }

    /**
     * Only the local part of an email address is a valid forwarder alias.
     * Reject anything else rather than passing it through to the API.
     */
    private function sanitizeAlias(string $alias): string
    {
        $alias = trim($alias);
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $alias)) {
            return '';
        }
        return $alias;
    }

    /**
     * Low-level HTTP call against the DirectAdmin API. Returns the parsed
     * key=value response as an array, or null on transport failure.
     * Never includes the API key in logged output.
     */
    private function request(string $command, array $params): ?array
    {
        if ($this->host === '' || $this->user === '' || $this->apiKey === '' || $this->domain === '') {
            $this->log('ERROR', 'DirectAdmin client not configured (missing host/user/api_key/domain)');
            return null;
        }

        $url = $this->host . '/' . ltrim($command, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            $this->log('ERROR', 'DirectAdmin request: curl_init failed');
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_USERPWD => $this->user . ':' . $this->apiKey,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log('ERROR', 'DirectAdmin request transport error', ['command' => $command, 'curl_error' => $curlError]);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log('ERROR', 'DirectAdmin request non-2xx response', ['command' => $command, 'http_code' => $httpCode]);
            return null;
        }

        parse_str((string)$response, $parsed);
        return $parsed;
    }

    private function log(string $level, string $message, ?array $context = null): void
    {
        if (function_exists('logMessage')) {
            logMessage($level, $message, $context);
        } else {
            error_log("[$level] $message" . ($context ? ' ' . json_encode($context) : ''));
        }
    }
}
