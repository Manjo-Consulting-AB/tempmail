<?php
defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

class ImapProcessor
{
    private array $config;
    private PDO $pdo;
    private bool $debugMode = false;
    private bool $dryRun = false;

    public function __construct(array $config, PDO $pdo, bool $debugMode = false)
    {
        $this->config = $config;
        $this->pdo = $pdo;
        $this->debugMode = $debugMode;
    }

    public function setDryRun(bool $dry): void
    {
        $this->dryRun = (bool)$dry;
    }

    public function processEmails(): array
    {
        try {
            // Only emit the startup message when the configured app log level is not INFO
            $configuredLevel = strtoupper($this->config['app']['log_level'] ?? ($GLOBALS['config']['app']['log_level'] ?? 'INFO'));
            if ($configuredLevel !== 'INFO') {
                if (function_exists('logMessage')) {
                    logMessage('INFO', 'Starting IMAP processor');
                } else {
                    error_log("[INFO] Production IMAP: Starting IMAP processor");
                }
            }

            if (!extension_loaded('imap')) {
                return $this->processEmailsWithCurl();
            }

            $addresses = $this->getValidAddresses();
            // Log number of addresses being processed and a short sample to aid debugging
            try {
                $count = is_array($addresses['full']) ? count($addresses['full']) : 0;
                $sample = [];
                if ($count > 0) {
                    $sample = array_slice($addresses['full'], 0, 10);
                }
                $this->log('DEBUG', 'IMAP will process addresses', ['count' => $count, 'sample' => $sample]);
            } catch (Exception $_) {
                // ignore logging errors
            }
            if (empty($addresses['full'])) {
                return ['success' => true, 'new_emails' => 0, 'message' => 'No active addresses'];
            }

            $imap = $this->connectToImap();
            if (!$imap) throw new Exception('Unable to connect to IMAP');

            $count = $this->fetchEmailsFromServer($imap, $addresses);
            imap_close($imap);

            $this->log('INFO', "IMAP processing complete: {$count} new emails");
            return ['success' => true, 'new_emails' => $count, 'message' => "Fetched {$count} messages"];
        } catch (Exception $e) {
            $this->log('ERROR', 'IMAP processor failed: ' . $e->getMessage());
            return ['success' => false, 'new_emails' => 0, 'message' => $e->getMessage()];
        }
    }

    private function getValidAddresses(): array
    {
        $sql = 'SELECT unique_address FROM temp_emails WHERE expires_at > NOW() ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $unique = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $full = array_map(fn($u) => $u . '@manjo.me', $unique);
        return ['unique' => $unique, 'full' => $full];
    }

    private function connectToImap()
    {
        // Prefer new `imap` config block, fall back to legacy `mail` config
        $imapCfg = $this->config['imap'] ?? [];
        if (empty($imapCfg)) {
            $imapCfg = $this->config['mail'] ?? [];
        }

        // If a full mailbox string is provided (eg {host:993/imap/ssl}INBOX), use it directly
        $server = $imapCfg['server'] ?? null;
        $user = $imapCfg['user'] ?? ($imapCfg['imap_user'] ?? null);
        $pass = $imapCfg['password'] ?? ($imapCfg['imap_pass'] ?? null);

        if (!empty($server)) {
            if (empty($user) || empty($pass)) return false;
            $conn = @imap_open($server, $user, $pass, 0);
            return $conn ?: false;
        }

        // Fallback: build mailbox from host + flags
        $host = $imapCfg['host'] ?? ($imapCfg['imap_host'] ?? 'localhost');
        $flags = $imapCfg['flags'] ?? ($imapCfg['imap_flags'] ?? '/imap/ssl/novalidate-cert');
        if (empty($user) || empty($pass)) return false;
        $mailbox = '{' . $host . $flags . '}INBOX';
        $conn = @imap_open($mailbox, $user, $pass, 0);
        return $conn ?: false;
    }

    private function fetchEmailsFromServer($imapConnection, array $addresses): int
    {
        $new = 0;
        $mc = @imap_search($imapConnection, 'ALL');
        if (!$mc) return 0;
        foreach ($mc as $num) {
            try {
                $header = @imap_headerinfo($imapConnection, $num);
                $toAddresses = [];
                foreach ($header->to ?? [] as $t) {
                    $toAddresses[] = strtolower(($t->mailbox ?? '') . '@' . ($t->host ?? ''));
                }
                $matching = array_values(array_intersect($toAddresses, $addresses['full']));
                if (!empty($matching)) {
                    $body = $this->getMessageBody($imapConnection, $num);
                    $saved = $this->saveEmail($header, $body, $matching[0], $imapConnection, $num);
                    if ($saved) {
                        $new++;
                        $this->log('INFO', 'Saved email for: ' . $matching[0]);
                    }
                    if (!$this->dryRun) @imap_delete($imapConnection, $num);
                    $this->updateStat('emails_total', 1);
                } else {
                    $this->log('INFO', 'Deleting email without valid match: ' . implode(', ', $toAddresses));
                    if (!$this->dryRun) @imap_delete($imapConnection, $num);
                    $this->updateStat('emails_total', 1);
                }
            } catch (Exception $e) {
                $this->log('ERROR', 'Error processing message ' . $num . ': ' . $e->getMessage());
            }
        }
        if (!$this->dryRun) @imap_expunge($imapConnection);
        return $new;
    }

    private function getMessageBody($imapConnection, $messageNumber): string
    {
        // Robust recursive extraction: prefer HTML, fall back to plain text
        $structure = @imap_fetchstructure($imapConnection, $messageNumber);
        $result = ['html' => null, 'text' => null];

        // Helper to fetch and decode a part by part number
        $fetchDecode = function($partNo, $partObj) use ($imapConnection, $messageNumber) {
            $data = @imap_fetchbody($imapConnection, $messageNumber, $partNo);
            if ($data === false) return null;
            $encoding = $partObj->encoding ?? null;
            if ($encoding == 3) return base64_decode($data);
            if ($encoding == 4) return quoted_printable_decode($data);
            return $data;
        };

        // Recursive traversal to find text/html or text/plain parts
        $traverse = function($parts, $prefix = '') use (&$traverse, &$result, $fetchDecode) {
            foreach ($parts as $idx => $part) {
                $partNo = $prefix === '' ? ($idx + 1) : ($prefix . '.' . ($idx + 1));
                $type = strtoupper($part->subtype ?? '');
                $major = $part->type ?? null;

                // If this part is itself multipart, recurse
                if (!empty($part->parts) && is_array($part->parts)) {
                    $traverse($part->parts, $partNo);
                }

                // Check for text/plain or text/html
                if ($major == 0 && in_array($type, ['PLAIN', 'HTML'])) {
                    $data = $fetchDecode($partNo, $part);
                    if ($data !== null) {
                        if ($type === 'HTML' && $result['html'] === null) $result['html'] = $data;
                        if ($type === 'PLAIN' && $result['text'] === null) $result['text'] = $data;
                    }
                }
            }
        };

        if ($structure && !empty($structure->parts) && is_array($structure->parts)) {
            $traverse($structure->parts);
        } else {
            // Singlepart message
            $data = @imap_fetchbody($imapConnection, $messageNumber, 1);
            if ($data === false || $data === '') $data = @imap_body($imapConnection, $messageNumber);
            if ($structure && ($structure->encoding == 3)) $data = base64_decode($data);
            elseif ($structure && ($structure->encoding == 4)) $data = quoted_printable_decode($data);
            $result['text'] = $data;
        }

        // Prefer HTML if present
        $out = $result['html'] ?? $result['text'] ?? '';
        return (string)$out;
    }

    private function saveEmail($header, $body, $toAddress, $imapConnection = null, $messageNumber = null): bool
    {
        try {
            $fromAddress = '';
            if (!empty($header->from[0])) {
                $f = $header->from[0];
                if (isset($f->mailbox, $f->host)) $fromAddress = $f->mailbox . '@' . $f->host;
            }

            $subject = isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '(no subject)';
            $receivedDate = date('Y-m-d H:i:s', $header->udate ?? time());
            $bodyClean = $this->sanitizeSavedBody($body);

            // Determine if body is HTML and split into body_html / body_text
            $bodyHtml = null;
            $bodyText = null;
            if (preg_match('/<[^>]+>/', $bodyClean)) {
                // Treat as HTML
                $bodyHtml = $bodyClean;
                $bodyText = trim(html_entity_decode(strip_tags($bodyClean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // Normalize excessive whitespace
                $bodyText = preg_replace('/[ \t]+/', ' ', $bodyText);
                $bodyText = preg_replace('/(\r?\n){3,}/', "\n\n", $bodyText);
            } else {
                // Plain text
                $bodyText = $bodyClean;
            }

            $expiresAt = null;
            $proUserId = null;
            $tempEmailId = null;

            try {
                $local = explode('@', strtolower($toAddress))[0] ?? null;
                if ($local) {
                    $lookup = $this->pdo->prepare('SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1');
                    $lookup->execute([$local]);
                    $r = $lookup->fetch(PDO::FETCH_ASSOC);
                    if ($r) {
                        $tempEmailId = $r['id'] ?? null;
                        $proUserId = $r['pro_user_id'] ?? null;
                        if (!empty($r['pro_user_id'])) {
                            $pstmt = $this->pdo->prepare('SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1');
                            $pstmt->execute([(int)$r['pro_user_id']]);
                            $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                            if ($prow && isset($prow['ttl_days'])) {
                                $ttl = max(1, min(365 * 50, (int)$prow['ttl_days']));
                                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttl} days", strtotime($receivedDate) ?: time()));
                            }
                        }
                        if (empty($expiresAt) && !empty($r['expires_at'])) $expiresAt = $r['expires_at'];
                    }
                }
            } catch (Exception $e) {
                $this->log('WARNING', 'Lookup temp_emails failed: ' . $e->getMessage());
            }

            $sql = 'INSERT INTO stored_emails (to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->pdo->prepare($sql);
            $ok = $stmt->execute([$toAddress, $fromAddress, $subject, $bodyText, $bodyHtml, $receivedDate, $expiresAt, $tempEmailId]);
            
            // IMPORTANT: Get lastInsertId IMMEDIATELY after INSERT, BEFORE any other DB operations
            $emailId = $ok ? (int)$this->pdo->lastInsertId() : 0;
            
            // Debug: log INSERT result and any errors
            $insertError = $ok ? 'none' : implode(',', $stmt->errorInfo());
            if (function_exists('safeDebugLog')) {
                safeDebugLog('DEBUG', 'stored_emails INSERT', [
                    'ok' => $ok,
                    'error' => $insertError,
                    'emailId' => $emailId,
                    'subject' => substr($subject, 0, 30)
                ]);
            }
            
            $this->updateStat('emails_processed', 1);

            // Save attachments for ALL emails (not just PRO users)
            if ($ok && $emailId > 0) {
                
                // Debug: log lastInsertId immediately after stored_emails INSERT
                if (function_exists('safeDebugLog')) {
                    safeDebugLog('DEBUG', 'saveEmail lastInsertId', [
                        'emailId' => $emailId,
                        'to' => $toAddress,
                        'subject' => substr($subject, 0, 30)
                    ]);
                }
                
                if ($imapConnection && $messageNumber) {
                    try {
                        $this->log('DEBUG', 'Calling saveAttachmentsFromMessage: ' . json_encode(['message_number' => $messageNumber, 'email_id' => $emailId, 'temp_email_id' => $tempEmailId]));
                        $attachResult = $this->saveAttachmentsFromMessage($imapConnection, $messageNumber, $emailId);
                        $attachMapping = is_array($attachResult) ? ($attachResult['mapping'] ?? []) : [];
                        $this->log('DEBUG', 'saveAttachmentsFromMessage result: ' . json_encode(['saved' => $attachResult['saved'] ?? 0, 'mapping' => $attachMapping]));
                        // NOTE: We intentionally do NOT replace cid: references here.
                        // The cid: references are preserved in the database and replaced with
                        // freshly signed URLs at display time in index.php. This ensures that
                        // both inline images and attachment list use the same signature timestamp,
                        // avoiding the issue where inline images and attachment downloads had
                        // different (and potentially invalid) signatures.
                    } catch (Exception $e) {
                        $this->log('WARNING', 'Saving attachments failed: ' . $e->getMessage());
                    }
                }

                // Webhooks remain PRO-only
                if (!empty($proUserId)) {
                    $payload = ['to' => $toAddress, 'from' => $fromAddress, 'subject' => $subject, 'body' => ($bodyHtml ?? $bodyText), 'received_at' => $receivedDate, 'temp_email_id' => $tempEmailId];
                    $this->dispatchWebhooks((int)$proUserId, $payload);
                }
            }

            return (bool)$ok;
        } catch (Exception $e) {
            $this->log('ERROR', 'Save email failed: ' . $e->getMessage());
            return false;
        }
    }

    private function updateStat($statName, $increment = 1): bool
    {
        try {
            $sql = 'INSERT INTO email_stats (stat_name, stat_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE stat_value = stat_value + VALUES(stat_value), last_updated = CURRENT_TIMESTAMP';
            $stmt = $this->pdo->prepare($sql);
            return (bool)$stmt->execute([$statName, $increment]);
        } catch (PDOException $e) {
            $this->log('ERROR', 'Update stat failed: ' . $e->getMessage());
            return false;
        }
    }

    private function decodeMimeHeader($text): string
    {
        if (function_exists('imap_mime_header_decode')) {
            $decoded = imap_mime_header_decode($text);
            $out = '';
            foreach ($decoded as $el) $out .= $el->text;
            return $out;
        }
        return $text;
    }

    private function dispatchWebhooks(int $proUserId, array $payload): void
    {
        try {
            // Only select webhooks that are not paused
            $stmt = $this->pdo->prepare('SELECT id, name, url, kind, config, secret, filter_mode FROM pro_webhooks WHERE user_id = ? AND filter_mode = \'all\'');
            $stmt->execute([$proUserId]);
            $hooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($hooks)) return;

            $ins = $this->pdo->prepare('INSERT INTO pro_webhook_deliveries (webhook_id, user_id, payload, attempts, status, next_attempt_at, created_at) VALUES (?, ?, ?, 0, \'pending\', ?, NOW())');
            $now = date('Y-m-d H:i:s');
            foreach ($hooks as $h) {
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
            $post = ['token' => $token, 'user' => $user, 'message' => $message, 'title' => ($payload['to'] ?? 'TempMail')];

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

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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

    private function saveAttachmentsFromMessage($imapConnection, $messageNumber, $emailId): array
    {
        $rawHeaders = @imap_fetchheader($imapConnection, $messageNumber);
        $rawBody = @imap_body($imapConnection, $messageNumber);
        $raw = ($rawHeaders ?: '') . "\r\n" . ($rawBody ?: '');

        $attachmentsDir = __DIR__ . '/attachments';
        if (!is_dir($attachmentsDir)) @mkdir($attachmentsDir, 0755, true);

        $cidMap = [];
        $saved = 0;
        $usedMailParser = false;

        // Try MailParser first (requires ZBateson library)
        if (file_exists(__DIR__ . '/MailParser.php')) {
            require_once __DIR__ . '/MailParser.php';
            try {
                $parser = new MailParser($this->pdo, $this->config, $this->debugMode);
                $parsed = $parser->parseRawMessage($raw);
                if (!empty($parsed['attachments'])) {
                    $ps = $parser->saveAttachments($parsed['attachments'], (int)$emailId);
                    if (is_array($ps)) {
                        $cidMap = $ps['mapping'] ?? [];
                        $saved = (int)($ps['count'] ?? 0);
                        $usedMailParser = true;
                        $this->log('DEBUG', "MailParser saved {$saved} attachments for email_id={$emailId}");
                    }
                }
            } catch (Exception $e) {
                $this->log('WARNING', 'MailParser failed: ' . $e->getMessage());
            }
        }

        // Always fall back to LegacyImapFallback if MailParser didn't save any attachments
        if (!$usedMailParser || $saved === 0) {
            $this->log('DEBUG', "Using LegacyImapFallback for email_id={$emailId}");
            if (file_exists(__DIR__ . '/LegacyImapFallback.php')) {
                require_once __DIR__ . '/LegacyImapFallback.php';
            }
            $structure = @imap_fetchstructure($imapConnection, $messageNumber);
            if ($structure) {
                $parts = $structure->parts ?? [];
                if (!empty($parts)) {
                    $legacySaved = 0;
                    LegacyImapFallback::savePartsRecursive($this->pdo, $imapConnection, $messageNumber, $parts, '', $emailId, $attachmentsDir, $legacySaved, $cidMap);
                    $saved += $legacySaved;
                    $this->log('DEBUG', "LegacyImapFallback saved {$legacySaved} attachments for email_id={$emailId}");
                }
            }
        }

        // Update global stats for attachments processed
        try {
            if ($saved && function_exists('updateStat')) {
                updateStat('attachments_processed', (int)$saved);
            }
        } catch (\Throwable $_) {
            // Don't let stats failures break processing
        }

        return ['saved' => $saved, 'mapping' => $cidMap];
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

    private function findRealDestinationFromHeaders($imapConnection, $messageNumber, array $validUniqueAddresses)
    {
        try {
            $rawHeaders = @imap_fetchheader($imapConnection, $messageNumber);
            $lines = explode("\n", $rawHeaders);
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^(To|X-Original-To|Delivered-To|Envelope-To):\s*(.+)/i', $line, $m)) {
                    $headerValue = trim($m[2]);
                    if (preg_match_all('/([a-zA-Z0-9_\-]+)@manjo\.me/', $headerValue, $matches)) {
                        foreach ($matches[1] as $unique) {
                            if (in_array($unique, $validUniqueAddresses)) return $unique . '@manjo.me';
                        }
                    }
                }
            }
            $body = @imap_fetchbody($imapConnection, $messageNumber, 1);
            if (preg_match_all('/([a-zA-Z0-9_\-]+)@manjo\.me/', $body, $bm)) {
                foreach ($bm[1] as $u) {
                    if (in_array($u, $validUniqueAddresses)) return $u . '@manjo.me';
                }
            }
            return null;
        } catch (Exception $e) {
            $this->log('ERROR', 'Header parsing error: ' . $e->getMessage());
            return null;
        }
    }

    private function processEmailsWithCurl()
    {
        $this->log('INFO', 'Using Python IMAP fallback (no PHP imap extension)');
        try {
            $addresses = $this->getValidAddresses();
            if (empty($addresses['full'])) return ['success' => true, 'new_emails' => 0, 'message' => 'No active addresses'];
            $count = $this->runPythonImapScript($addresses);
            return ['success' => true, 'new_emails' => $count, 'message' => "Python IMAP fetched {$count} messages"];
        } catch (Exception $e) {
            $this->log('ERROR', 'Python IMAP fallback failed: ' . $e->getMessage());
            return ['success' => false, 'new_emails' => 0, 'message' => $e->getMessage()];
        }
    }

    private function runPythonImapScript($addresses)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tempmail_addresses_');
        file_put_contents($tmp, json_encode($addresses));
        $python = $this->config['python_path'] ?? '/usr/bin/python3';
        $script = $this->config['python_imap_script'] ?? '/usr/local/bin/python_imap_fallback.py';
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp);
        $out = shell_exec($cmd);
        @unlink($tmp);
        $res = json_decode(trim($out), true);
        return $res['new_emails'] ?? 0;
    }

    private function sanitizeSavedBody($body)
    {
        if (empty($body) || !is_string($body)) return $body;
        $body = preg_replace('/data:[^;\"]+;base64,[A-Za-z0-9+\/=\r\n]+/i', '[attachment removed]', $body);
        $body = preg_replace_callback('/([A-Za-z0-9+\/=\r\n]{200,})/', function ($m) {
            $s = preg_replace('/\s+/', '', $m[1]);
            if (preg_match('/^[A-Za-z0-9+\/=]{200,}$/', $s)) return '[removed base64]';
            return $m[1];
        }, $body);
        return $body;
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
