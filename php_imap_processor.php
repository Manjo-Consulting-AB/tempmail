<?php
defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

/**
 * Pro webhook dispatch.
 *
 * The name is historical: this class was the IMAP intake, and the IMAP intake
 * it is named after was removed in #212. What is left is Pro webhook dispatch
 * only - dispatchWebhooks() queues deliveries, dispatchDelivery() sends them.
 * Mail arrives only through parse.php and the Email Storage service.
 */
class ImapProcessor
{
    private array $config;
    private PDO $pdo;
    private bool $debugMode = false;
    private ?bool $tempEmailsHasPushoverColumn = null;

    public function __construct(array $config, PDO $pdo, bool $debugMode = false)
    {
        $this->config = $config;
        $this->pdo = $pdo;
        $this->debugMode = $debugMode;
    }

    public function dispatchWebhooks(int $proUserId, array $payload): void
    {
        try {
            // Entitlement check lives here (not in the caller) so every current and future
            // caller of dispatchWebhooks() is covered. function_exists() guards against a caller
            // that constructs this class without loading config.php.
            if (function_exists('proUserIsPro') && !proUserIsPro($proUserId)) {
                $this->log('DEBUG', 'Skipping webhook dispatch for non-pro account', ['user_id' => $proUserId]);
                return;
            }

            // Only select webhooks that are not paused
            $stmt = $this->pdo->prepare('SELECT id, name, url, kind, config, secret, filter_mode FROM pro_webhooks WHERE user_id = ? AND filter_mode = \'all\'');
            $stmt->execute([$proUserId]);
            $hooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($hooks)) return;

            $ins = $this->pdo->prepare('INSERT INTO pro_webhook_deliveries (webhook_id, user_id, payload, attempts, status, next_attempt_at, created_at) VALUES (?, ?, ?, 0, \'pending\', ?, NOW())');
            $now = date('Y-m-d H:i:s');
            // Pushover is opt-in per destination address (#174). Resolved on the
            // first Pushover hook and memoised in the loop, so a user without any
            // Pushover webhook pays no extra query per message.
            $pushoverEligible = null;
            foreach ($hooks as $h) {
                if (($h['kind'] ?? 'generic') === 'pushover') {
                    if ($pushoverEligible === null) {
                        $pushoverEligible = $this->pushoverEnabledForAddress($proUserId, $payload['to'] ?? null);
                    }
                    if (!$pushoverEligible) {
                        // Expected, routine state: Pushover is off for this address
                        // (the default). Generic webhooks are unaffected.
                        $this->log('DEBUG', 'Skipping Pushover webhook: destination address has Pushover disabled', [
                            'webhook_id' => $h['id'],
                            'user_id' => $proUserId,
                            'to' => $payload['to'] ?? null
                        ]);
                        continue;
                    }
                }
                try {
                    $ins->execute([$h['id'], $proUserId, json_encode($payload, JSON_UNESCAPED_UNICODE), $now]);
                    $this->log('INFO', 'Webhook queued', [
                        'webhook_id' => $h['id'],
                        'webhook_name' => $h['name'] ?? null,
                        'kind' => $h['kind'],
                        'user_id' => $proUserId,
                        'subject' => $payload['subject'] ?? null
                    ]);
                } catch (Exception $e) {
                    $this->log('ERROR', 'Enqueue webhook failed: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $this->log('ERROR', 'Dispatch webhooks error: ' . $e->getMessage());
        }
    }

    /**
     * Is Pushover enabled for the address this message was delivered to? (#174)
     *
     * `$toAddress` is the recipient the intake resolved from the message headers
     * and stored on stored_emails.to_address, so this is the same address the
     * message is filed under - a temporary address (is_personal = 0) can never
     * inherit a personal address' opt-in, because the flag is read off that
     * address' own row.
     *
     * The lookup is scoped to $proUserId as well, so a destination that somehow
     * does not belong to the user being dispatched for reads as "off" rather
     * than borrowing another account's preference.
     *
     * One enabled address enables every active Pushover webhook of the user:
     * the Pushover token and user key live on the webhook (pro_webhooks.config),
     * never on the address, so there is no per-address selection of individual
     * Pushover configurations. Paused webhooks stay excluded by the
     * filter_mode = 'all' filter on the webhook query, not here.
     *
     * Anything we cannot prove is enabled reads as disabled: a missing row, a
     * missing column (migration not run - no address can have opted in yet) and
     * a missing tableHasColumn() helper (entrypoint without config.php) all mean
     * "no push", never "push anyway".
     */
    private function pushoverEnabledForAddress(int $proUserId, $toAddress): bool
    {
        if (empty($toAddress) || !is_string($toAddress)) return false;
        $local = explode('@', strtolower(trim($toAddress)))[0];
        if ($local === '') return false;

        try {
            if ($this->tempEmailsHasPushoverColumn === null) {
                $this->tempEmailsHasPushoverColumn = function_exists('tableHasColumn')
                    && tableHasColumn('temp_emails', 'pushover_enabled');
            }
            if (!$this->tempEmailsHasPushoverColumn) return false;

            $stmt = $this->pdo->prepare('SELECT pushover_enabled FROM temp_emails WHERE unique_address = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1');
            $stmt->execute([$local, $proUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false && (int)$row['pushover_enabled'] === 1;
        } catch (Exception $e) {
            $this->log('WARNING', 'Pushover destination lookup failed: ' . $e->getMessage(), [
                'user_id' => $proUserId
            ]);
            return false;
        }
    }

    private function sendWebhookRequest(array $hook, array $payload): array
    {
        $kind = $hook['kind'] ?? 'generic';
        $url = $hook['url'] ?? '';
        if (empty($url)) throw new Exception('Webhook URL missing');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($kind === 'pushover') {
            $cfg = !empty($hook['config']) ? json_decode($hook['config'], true) : [];
            $token = $cfg['token'] ?? null;
            $user = $cfg['user'] ?? null;
            if (empty($token) || empty($user)) throw new Exception('Pushover config missing');
            $message = ($payload['subject'] ?? '(No subject)') . "\n\n" . trim(strip_tags($payload['body'] ?? ''));
            if (strlen($message) > 4096) $message = substr($message, 0, 4000) . '...';
            $post = ['token' => $token, 'user' => $user, 'message' => $message, 'title' => ($payload['to'] ?? 'Mail Shield')];

            if (function_exists('curl_init')) {
                $ch = curl_init('https://api.pushover.net/1/messages.json');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                if (defined('CURL_HTTP_VERSION_1_1')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                curl_setopt($ch, CURLOPT_USERAGENT, 'TempMailWebhook/1.0');
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp === false) return ['code' => 0, 'body' => ($err ?: '')];
                return ['code' => (int)$code, 'body' => $resp];
            }

            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded\r\n', 'content' => http_build_query($post), 'timeout' => 5]]);
            $resp = @file_get_contents('https://api.pushover.net/1/messages.json', false, $ctx);
            return $resp === false ? ['code' => 0, 'body' => 'file_get_contents failed'] : ['code' => 200, 'body' => $resp];
        }

        $headers = ['Content-Type: application/json'];
        if (!empty($hook['secret'])) {
            $secret = $this->decryptHookSecret($hook['secret']);
            $headers[] = 'X-TempMail-Signature: sha256=' . hash_hmac('sha256', $payloadJson, $secret);
        }

        // This is the real, recurring webhook delivery path (fired on every new
        // email) - webhook_create's validation in pro_profile.php only gates what
        // URL gets saved, so re-validate + pin the resolved IP here too, otherwise
        // a URL that was public at creation time (or that resolves differently via
        // DNS rebinding) could be used to reach internal services on every delivery.
        $target = function_exists('resolveUrlToPublicTarget') ? resolveUrlToPublicTarget($url) : null;
        if ($target === null) {
            return ['code' => 0, 'body' => 'Target host could not be resolved to a permitted address'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_RESOLVE, [$target['host'] . ':' . $target['port'] . ':' . $target['ip']]);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false) return ['code' => 0, 'body' => ($err ?: '')];
            return ['code' => (int)$code, 'body' => $resp];
        }

        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $payloadJson, 'timeout' => 5]]);
        $resp = @file_get_contents($url, false, $ctx);
        return $resp === false ? ['code' => 0, 'body' => 'file_get_contents failed'] : ['code' => 200, 'body' => $resp];
    }

    private function decryptHookSecret($encoded)
    {
        if (empty($encoded)) return null;
        $key = $_ENV['WEBHOOKS_KEY'] ?? null;
        if (empty($key)) return $encoded;
        $method = 'AES-256-CBC';
        $raw = base64_decode($encoded);
        $ivlen = openssl_cipher_iv_length($method);
        $iv = substr($raw, 0, $ivlen);
        $ciphertext = substr($raw, $ivlen);
        $plain = openssl_decrypt($ciphertext, $method, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? $encoded : $plain;
    }

    public function dispatchDelivery(int $deliveryId): bool
    {
        try {
            $this->pdo->beginTransaction();
            $s = $this->pdo->prepare('SELECT * FROM pro_webhook_deliveries WHERE id = ? FOR UPDATE');
            $s->execute([$deliveryId]);
            $d = $s->fetch(PDO::FETCH_ASSOC);
            if (!$d) { $this->pdo->commit(); return false; }
            if ($d['status'] !== 'pending') { $this->pdo->commit(); return false; }
            if (!empty($d['next_attempt_at']) && strtotime($d['next_attempt_at']) > time()) { $this->pdo->commit(); return false; }
            $hs = $this->pdo->prepare('SELECT * FROM pro_webhooks WHERE id = ? LIMIT 1');
            $hs->execute([$d['webhook_id']]);
            $hook = $hs->fetch(PDO::FETCH_ASSOC);
            if (!$hook) { $u = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET status='failed', last_error=?, updated_at=NOW() WHERE id=?"); $u->execute(['Webhook not found', $deliveryId]); $this->pdo->commit(); return false; }
            $payload = json_decode($d['payload'], true) ?: [];
            $this->pdo->commit();

            $res = $this->sendWebhookRequest($hook, $payload);
            $code = (int)($res['code'] ?? 0);
            $body = $res['body'] ?? null;
            if ($code >= 200 && $code < 300) {
                $this->pdo->beginTransaction();
                $u = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET status='succeeded', attempts=attempts+1, last_error=NULL, response_code=?, response_body=?, updated_at=NOW() WHERE id=?");
                $u->execute([$code, $body, $deliveryId]);
                $this->pdo->commit();
                $this->log('INFO', 'Webhook delivered successfully', [
                    'delivery_id' => $deliveryId,
                    'webhook_id' => $hook['id'],
                    'webhook_name' => $hook['name'] ?? null,
                    'kind' => $hook['kind'] ?? 'generic',
                    'http_code' => $code
                ]);
                return true;
            }

            $this->pdo->beginTransaction();
            $attempts = (int)$d['attempts'] + 1;
            $max = 5;
            $backoff = min(86400, 60 * pow(2, max(0, $attempts - 1)));
            $next = date('Y-m-d H:i:s', time() + $backoff);
            $status = ($attempts >= $max) ? 'failed' : 'pending';
            $lastError = 'HTTP ' . $code;
            if ($body) $lastError .= ' ' . (is_string($body) ? substr($body, 0, 2000) : '');
            $u = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET attempts=?, last_error=?, next_attempt_at=?, status=?, response_code=?, response_body=?, updated_at=NOW() WHERE id=?");
            $u->execute([$attempts, $lastError, $next, $status, $code, $body, $deliveryId]);
            $this->pdo->commit();
            $this->log('WARNING', 'Webhook delivery failed', [
                'delivery_id' => $deliveryId,
                'webhook_id' => $hook['id'],
                'webhook_name' => $hook['name'] ?? null,
                'kind' => $hook['kind'] ?? 'generic',
                'http_code' => $code,
                'attempts' => $attempts,
                'status' => $status,
                'next_attempt' => $status === 'pending' ? $next : null
            ]);
            return false;
        } catch (Exception $e) {
            try { $this->pdo->rollBack(); } catch (Exception $_) {}
            $this->log('ERROR', 'dispatchDelivery exception: ' . $e->getMessage());
            return false;
        }
    }

    private function log($level, $message, $context = null)
    {
        // Always log INFO, WARNING, ERROR to database
        // Only gate DEBUG level behind debugMode
        $alwaysLog = in_array(strtoupper($level), ['INFO', 'WARNING', 'ERROR'], true);
        
        if ($alwaysLog || $this->debugMode) {
            if (function_exists('logMessage')) {
                logMessage($level, 'IMAP: ' . $message, $context);
            } else {
                error_log("[{$level}] IMAP: {$message}" . ($context ? ' ' . json_encode($context) : ''));
            }
        }
    }
}
