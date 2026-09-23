<?php
defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

/**
 * Pro webhook dispatch.
 *
 * The name is historical: this class was the IMAP intake, and the IMAP intake
 * it is named after was removed in #212. What is left is Pro webhook dispatch
 * only - dispatchWebhooks() queues deliveries, dispatchDelivery() sends them,
 * deliverNow() sends fresh ones straight away from the intake.
 * Mail arrives only through parse.php and the Email Storage service.
 *
 * Routing is per hook and per address (epic #251): which hooks a message
 * queues for is decided by the destination address' own row, so
 * migrate_webhook_addresses.php has to have run. It has no fallback - see
 * dispatchWebhooks() and hooksForDestination().
 */
class ImapProcessor
{
    private array $config;
    private PDO $pdo;
    private bool $debugMode = false;
    private ?bool $hasHookRouting = null;

    public function __construct(array $config, PDO $pdo, bool $debugMode = false)
    {
        $this->config = $config;
        $this->pdo = $pdo;
        $this->debugMode = $debugMode;
    }

    /**
     * Queue one delivery per webhook that the destination address routes to.
     *
     * The hooks come from hooksForDestination(), i.e. only the ones the
     * destination address actually routes to. Every hook kind is routed the
     * same way; Pushover gets no special case.
     *
     * Fail-closed: without the routing schema there is no way to tell which
     * hooks an address routes to, so a message queues nothing at all rather
     * than falling back to a rule that would send mail the user never asked
     * for. migrate_webhook_addresses.php is what makes it available.
     *
     * @return int[] ids of the pro_webhook_deliveries rows queued, so a caller
     *               can attempt them right away (see deliverNow()).
     */
    public function dispatchWebhooks(int $proUserId, array $payload): array
    {
        $queued = [];
        try {
            // Entitlement check lives here (not in the caller) so every current and future
            // caller of dispatchWebhooks() is covered. function_exists() guards against a caller
            // that constructs this class without loading config.php.
            if (function_exists('proUserIsPro') && !proUserIsPro($proUserId)) {
                $this->log('DEBUG', 'Skipping webhook dispatch for non-pro account', ['user_id' => $proUserId]);
                return $queued;
            }

            if (!$this->hookRoutingAvailable()) {
                $this->log('WARNING', 'Webhook routing tables missing; run migrate_webhook_addresses.php', [
                    'user_id' => $proUserId
                ]);
                return $queued;
            }

            return $this->enqueueHooks($this->hooksForDestination($proUserId, $payload['to'] ?? null), $proUserId, $payload);
        } catch (Exception $e) {
            $this->log('ERROR', 'Dispatch webhooks error: ' . $e->getMessage());
        }
        return $queued;
    }

    /**
     * Is the per-hook address routing schema in place? (epic #251)
     *
     * True only when all three objects migrate_webhook_addresses.php adds
     * exist, so a half-migrated database - or an entrypoint that did not load
     * config.php and so has no tableHasColumn() - reads as "not routed", and
     * dispatchWebhooks() queues nothing. Cached: the answer cannot change while
     * one process runs, and this is consulted on every stored message.
     */
    private function hookRoutingAvailable(): bool
    {
        if ($this->hasHookRouting === null) {
            $this->hasHookRouting = function_exists('tableHasColumn')
                && tableHasColumn('pro_webhook_addresses', 'webhook_id')
                && tableHasColumn('pro_webhooks', 'include_temporary')
                && tableHasColumn('temp_emails', 'hooks_paused');
        }
        return $this->hasHookRouting;
    }

    /**
     * Which of the user's active hooks does a message to $toAddress route to?
     *
     * $toAddress is the recipient the intake resolved from the message headers
     * and stored on stored_emails.to_address, so it is the address the message
     * is filed under. The address' own row decides, and it must belong to
     * $proUserId:
     *
     *  - Personal address (is_personal = 1): its hooks_paused = 1 queues
     *    nothing at all; otherwise exactly the user's active hooks
     *    (filter_mode = 'all') with a pro_webhook_addresses row for this
     *    address. New personal addresses start unlinked, so they start silent.
     *  - Temporary address (is_personal = 0): the user's active hooks with
     *    include_temporary = 1. The one switch covers all of the user's
     *    temporary addresses, so no link row is involved.
     *
     * Anything unproven reads as "queue nothing": a missing row, an address not
     * owned by $proUserId and a lookup exception all return [].
     *
     * @param string|null $toAddress full recipient address (local@domain)
     * @return list<array<string,mixed>>
     */
    private function hooksForDestination(int $proUserId, $toAddress): array
    {
        if (empty($toAddress) || !is_string($toAddress)) return [];
        $local = explode('@', strtolower(trim($toAddress)))[0];
        if ($local === '') return [];

        try {
            $stmt = $this->pdo->prepare('SELECT id, is_personal, hooks_paused FROM temp_emails WHERE unique_address = ? AND pro_user_id = ? LIMIT 1');
            $stmt->execute([$local, $proUserId]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($address === false) {
                $this->log('DEBUG', 'No webhook routing: destination address not owned by this user', [
                    'user_id' => $proUserId,
                    'to' => $toAddress
                ]);
                return [];
            }

            if ((int)$address['is_personal'] === 1) {
                if ((int)$address['hooks_paused'] === 1) {
                    // Expected, routine state, and reversible from the profile
                    // page: the address keeps its links while it is paused.
                    $this->log('DEBUG', 'Skipping webhooks: hooks are paused for this address', [
                        'user_id' => $proUserId,
                        'temp_email_id' => (int)$address['id'],
                        'to' => $toAddress
                    ]);
                    return [];
                }

                $stmt = $this->pdo->prepare('SELECT w.id, w.name, w.url, w.kind, w.config, w.secret, w.filter_mode FROM pro_webhooks w JOIN pro_webhook_addresses l ON l.webhook_id = w.id WHERE w.user_id = ? AND w.filter_mode = \'all\' AND l.temp_email_id = ?');
                $stmt->execute([$proUserId, (int)$address['id']]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $stmt = $this->pdo->prepare('SELECT id, name, url, kind, config, secret, filter_mode FROM pro_webhooks WHERE user_id = ? AND filter_mode = \'all\' AND include_temporary = 1');
            $stmt->execute([$proUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->log('WARNING', 'Webhook routing lookup failed: ' . $e->getMessage(), [
                'user_id' => $proUserId
            ]);
            return [];
        }
    }

    /**
     * Insert one pending delivery per hook, and return the new delivery ids.
     *
     * The hook set is already routed by address (see hooksForDestination()), so
     * no kind-specific rule applies here: a hook that is in the list is queued.
     */
    private function enqueueHooks(array $hooks, int $proUserId, array $payload): array
    {
        $queued = [];
        if (empty($hooks)) return $queued;

        $ins = $this->pdo->prepare('INSERT INTO pro_webhook_deliveries (webhook_id, user_id, payload, attempts, status, next_attempt_at, created_at) VALUES (?, ?, ?, 0, \'pending\', ?, NOW())');
        $now = date('Y-m-d H:i:s');
        foreach ($hooks as $h) {
            try {
                $ins->execute([$h['id'], $proUserId, json_encode($payload, JSON_UNESCAPED_UNICODE), $now]);
                $queued[] = (int)$this->pdo->lastInsertId();
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
        return $queued;
    }

    /**
     * Attempt freshly queued deliveries immediately, instead of leaving them
     * for the next run of cron/process-webhook-deliveries.php.
     *
     * Best effort only: every delivery is already durable in the queue, so a
     * failure here just leaves it for the cron worker's retry/backoff. At most
     * $limit deliveries are attempted, which bounds how long the caller (the
     * parse.php pipe, with Exim waiting on it) can be held up by slow targets.
     * Never throws.
     */
    public function deliverNow(array $deliveryIds, int $limit = 5): void
    {
        foreach (array_slice($deliveryIds, 0, max(0, $limit)) as $id) {
            try {
                $this->dispatchDelivery((int)$id);
            } catch (Throwable $e) {
                $this->log('WARNING', 'Immediate webhook delivery failed, left for the worker: ' . $e->getMessage(), [
                    'delivery_id' => (int)$id
                ]);
            }
        }
    }

    /**
     * The config JSON on a hook is the user's own, and it is applied as-is:
     * webhooks are a technical feature, so the user gets full control over
     * what is sent, our fixed fields included. What stays out of their reach
     * is where it goes - Pushover always posts to api.pushover.net, a generic
     * hook only to the URL that passed resolveUrlToPublicTarget() - and the
     * config size, capped at creation in pro_profile.php.
     *
     * Form fields for a Pushover messages.json call: our defaults (message,
     * title), then every scalar config key on top (token, user, device, sound,
     * priority, url, html, ttl, ... or message/title themselves). Pushover
     * validates the fields; its error lands in the delivery log. Nested values
     * are dropped - the API takes flat form fields only.
     */
    public static function pushoverPostFields(array $cfg, string $message, string $defaultTitle): array
    {
        $post = ['message' => $message, 'title' => $defaultTitle];
        foreach ($cfg as $key => $value) {
            if (!is_string($key)) continue;
            if (is_bool($value)) $value = $value ? 1 : 0;
            if (!is_scalar($value)) continue;
            $post[$key] = $value;
        }
        return $post;
    }

    /**
     * JSON body for a generic hook: the mail payload with the config's
     * top-level keys merged over it, so a key in the config replaces ours.
     */
    public static function genericWebhookBody(array $cfg, array $payload): array
    {
        foreach ($cfg as $key => $value) {
            if (is_string($key)) $payload[$key] = $value;
        }
        return $payload;
    }

    private function sendWebhookRequest(array $hook, array $payload): array
    {
        $kind = $hook['kind'] ?? 'generic';
        $url = $hook['url'] ?? '';
        if (empty($url)) throw new Exception('Webhook URL missing');
        $cfg = !empty($hook['config']) ? json_decode($hook['config'], true) : [];
        if (!is_array($cfg)) $cfg = [];

        if ($kind === 'pushover') {
            $token = $cfg['token'] ?? null;
            $user = $cfg['user'] ?? null;
            if (empty($token) || empty($user)) throw new Exception('Pushover config missing');
            $message = ($payload['subject'] ?? '(No subject)') . "\n\n" . trim(strip_tags($payload['body'] ?? ''));
            if (strlen($message) > 4096) $message = substr($message, 0, 4000) . '...';
            $post = self::pushoverPostFields($cfg, $message, (string)($payload['to'] ?? 'Mail Shield'));

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

        // Signed over the body as sent, config included.
        $payloadJson = json_encode(self::genericWebhookBody($cfg, $payload), JSON_UNESCAPED_UNICODE);
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

    /**
     * How long a claimed delivery is hidden from other workers while it is
     * being sent. Longer than any request timeout in sendWebhookRequest(); if
     * the process dies mid-send, the delivery becomes due again afterwards.
     */
    private const DELIVERY_LEASE_SECONDS = 120;

    public function dispatchDelivery(int $deliveryId): bool
    {
        try {
            $s = $this->pdo->prepare('SELECT * FROM pro_webhook_deliveries WHERE id = ?');
            $s->execute([$deliveryId]);
            $d = $s->fetch(PDO::FETCH_ASSOC);
            if (!$d) return false;
            if ($d['status'] !== 'pending') return false;
            if (!empty($d['next_attempt_at']) && strtotime($d['next_attempt_at']) > time()) return false;

            // Claim the delivery before sending it. parse.php (immediate
            // delivery) and the cron worker can reach the same pending row at
            // the same time; only the process whose UPDATE moves next_attempt_at
            // past now may send, so a delivery is never sent twice. The lease
            // needs no schema change and expires by itself if we die mid-send.
            $now = date('Y-m-d H:i:s');
            $lease = date('Y-m-d H:i:s', time() + self::DELIVERY_LEASE_SECONDS);
            $claim = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET next_attempt_at = ? WHERE id = ? AND status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)");
            $claim->execute([$lease, $deliveryId, $now]);
            if ($claim->rowCount() !== 1) return false;

            $hs = $this->pdo->prepare('SELECT * FROM pro_webhooks WHERE id = ? LIMIT 1');
            $hs->execute([$d['webhook_id']]);
            $hook = $hs->fetch(PDO::FETCH_ASSOC);
            if (!$hook) { $u = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET status='failed', last_error=?, updated_at=NOW() WHERE id=?"); $u->execute(['Webhook not found', $deliveryId]); return false; }
            $payload = json_decode($d['payload'], true) ?: [];

            try {
                $res = $this->sendWebhookRequest($hook, $payload);
            } catch (Exception $e) {
                // A broken hook config (e.g. missing Pushover keys) is a failed
                // attempt like any other, so it backs off and ends as 'failed'
                // instead of staying pending forever.
                $res = ['code' => 0, 'body' => $e->getMessage()];
            }
            $code = (int)($res['code'] ?? 0);
            $body = $res['body'] ?? null;
            if ($code >= 200 && $code < 300) {
                $u = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET status='succeeded', attempts=attempts+1, last_error=NULL, response_code=?, response_body=?, updated_at=NOW() WHERE id=?");
                $u->execute([$code, $body, $deliveryId]);
                $this->log('INFO', 'Webhook delivered successfully', [
                    'delivery_id' => $deliveryId,
                    'webhook_id' => $hook['id'],
                    'webhook_name' => $hook['name'] ?? null,
                    'kind' => $hook['kind'] ?? 'generic',
                    'http_code' => $code
                ]);
                return true;
            }

            $attempts = (int)$d['attempts'] + 1;
            $max = 5;
            $backoff = min(86400, 60 * pow(2, max(0, $attempts - 1)));
            $next = date('Y-m-d H:i:s', time() + $backoff);
            $status = ($attempts >= $max) ? 'failed' : 'pending';
            $lastError = 'HTTP ' . $code;
            if ($body) $lastError .= ' ' . (is_string($body) ? substr($body, 0, 2000) : '');
            $u = $this->pdo->prepare("UPDATE pro_webhook_deliveries SET attempts=?, last_error=?, next_attempt_at=?, status=?, response_code=?, response_body=?, updated_at=NOW() WHERE id=?");
            $u->execute([$attempts, $lastError, $next, $status, $code, $body, $deliveryId]);
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
