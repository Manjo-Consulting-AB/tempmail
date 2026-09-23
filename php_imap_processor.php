<?php
defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

class ImapProcessor
{
    private array $config;
    private PDO $pdo;
    private bool $debugMode = false;
    private bool $dryRun = false;
    private ?bool $tempEmailsHasPushoverColumn = null;
    private ?EmailStorage $emailStorage = null;

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
            // The IMAP server's internal date, not the Date: header - unchanged.
            $receivedAt = (new DateTimeImmutable())->setTimestamp((int)($header->udate ?? time()));
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

            [$tempEmailId, $proUserId, $expiresAt] = $this->resolveOwnershipContext($toAddress);

            // MailParser attachments travel inside the DTO and are persisted by
            // the storage service with the email. LegacyImapFallback needs the
            // live IMAP connection, so it runs afterwards and hands what it
            // extracted to the same attachment persistence.
            //
            // The stored body keeps its `cid:` references either way: index.php
            // rewrites them to freshly signed URLs at display time from these
            // same attachment rows, which is what keeps the inline image and the
            // attachment list on one signature timestamp.
            $attachments = ($imapConnection && $messageNumber)
                ? $this->parseAttachmentsFromMessage($imapConnection, $messageNumber)
                : [];

            $emailStorage = $this->emailStorage();
            $result = $emailStorage->store(new IncomingEmail(
                toAddress: $toAddress,
                receivedAt: $receivedAt,
                fromAddress: $fromAddress,
                subject: $subject,
                bodyText: $bodyText,
                bodyHtml: $bodyHtml,
                tempEmailId: $tempEmailId,
                proUserId: $proUserId,
                expiresAt: $expiresAt,
                attachments: $attachments
            ));

            if (function_exists('safeDebugLog')) {
                safeDebugLog('DEBUG', 'stored_emails store result', [
                    'status' => $result->status,
                    'message' => $result->message,
                    'storedEmailId' => $result->storedEmailId,
                    'subject' => substr($subject, 0, 30)
                ]);
            }

            if (!$result->isStored()) {
                // `stored`, and `duplicate` if it is ever returned, mean the
                // message is accounted for; `failed`/`rejected` do not, and only
                // those are reported as "not saved" to the caller.
                if ($result->status === StorageResult::STATUS_DUPLICATE) {
                    // Nothing was written, so no attachment and no webhook can
                    // follow. Unreachable today: this path passes no options, so
                    // duplicate detection stays off.
                    return true;
                }

                // The service has already logged the reason.
                $this->log('WARNING', 'Email was not stored', [
                    'status' => $result->status,
                    'to' => $toAddress,
                    'message' => $result->message
                ]);
                return false;
            }

            $emailId = (int)$result->storedEmailId;

            // The service stored what the DTO carried; whenever that was
            // nothing, the legacy fallback is reached - the condition this path
            // has always used (`!$usedMailParser || $saved === 0`).
            $savedByMailParser = count($attachments) - count($result->attachmentWarnings);
            if ($savedByMailParser === 0 && $imapConnection && $messageNumber && $emailId > 0) {
                try {
                    $this->log('DEBUG', 'Using LegacyImapFallback for email', ['email_id' => $emailId]);
                    $legacySaved = $this->saveLegacyAttachmentsFromMessage($imapConnection, $messageNumber, $emailId);
                    $this->log('DEBUG', 'LegacyImapFallback saved attachments', ['email_id' => $emailId, 'saved' => $legacySaved]);
                } catch (Exception $e) {
                    $this->log('WARNING', 'Saving attachments failed: ' . $e->getMessage());
                }
            }

            // Webhooks run only after the service reported a stored email, and
            // outside its transaction. They stay here until #196 centralizes
            // them. Entitlement is checked inside dispatchWebhooks().
            if ($proUserId !== null) {
                $payload = [
                    'to' => $toAddress,
                    'from' => $fromAddress,
                    'subject' => $subject,
                    'body' => ($bodyHtml ?? $bodyText),
                    'received_at' => $receivedAt->format('Y-m-d H:i:s'),
                    'temp_email_id' => $tempEmailId
                ];
                $this->dispatchWebhooks($proUserId, $payload);
            }

            return true;
        } catch (Exception $e) {
            $this->log('ERROR', 'Save email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The Email Storage service (epic #169, #191), built once per processor.
     *
     * It owns the `temp_emails` ownership lookup, the retention calculation,
     * the transactional `stored_emails` insert, attachment persistence and the
     * `emails_processed`/`attachments_processed` counters.
     */
    private function emailStorage(): EmailStorage
    {
        if ($this->emailStorage === null) {
            require_once __DIR__ . '/EmailStorage/IncomingEmail.php';
            require_once __DIR__ . '/EmailStorage/StorageResult.php';
            require_once __DIR__ . '/EmailStorage/EmailStorage.php';
            $this->emailStorage = new EmailStorage($this->pdo, $this->debugMode);
        }

        return $this->emailStorage;
    }

    /**
     * Ownership context for a recipient address: `temp_emails.id`,
     * `temp_emails.pro_user_id` and the address' own expiry.
     *
     * The storage service resolves this same row itself and its freshly read
     * copy is authoritative for the row it writes; what is read here is handed
     * to the DTO as the documented fallback and, for `pro_user_id`, decides
     * whether webhooks are dispatched at all.
     *
     * A lookup that finds no row leaves all three null and the email is stored
     * anyway, as before. A lookup that *throws* is logged here, and then fails
     * the store inside the service, which does not swallow the same error
     * (documentaion/EMAIL_STORAGE_API.md §7.3, §7.5).
     *
     * @return array{0: ?int, 1: ?int, 2: ?DateTimeImmutable}
     */
    private function resolveOwnershipContext(string $toAddress): array
    {
        $tempEmailId = null;
        $proUserId = null;
        $expiresAt = null;

        try {
            $local = explode('@', strtolower($toAddress))[0] ?? null;
            if ($local) {
                $lookup = $this->pdo->prepare('SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1');
                $lookup->execute([$local]);
                $r = $lookup->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $tempEmailId = isset($r['id']) ? (int)$r['id'] : null;
                    $proUserId = !empty($r['pro_user_id']) ? (int)$r['pro_user_id'] : null;
                    if (!empty($r['expires_at'])) {
                        try {
                            $expiresAt = new DateTimeImmutable((string)$r['expires_at']);
                        } catch (\Throwable $_) {
                            // Unreadable expiry: the service falls back the same way.
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->log('WARNING', 'Lookup temp_emails failed: ' . $e->getMessage());
        }

        return [$tempEmailId, $proUserId, $expiresAt];
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

    public function dispatchWebhooks(int $proUserId, array $payload): void
    {
        try {
            // Entitlement check lives here (not in the caller) so every current and future
            // caller of dispatchWebhooks() is covered. function_exists() guards against running
            // from entrypoints (run_imap_processor.php, cron/run_imap_once.php) that construct
            // this class without loading config.php.
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

    /**
     * The MailParser (ZBateson) attachments of one message, normalized for the
     * storage DTO. Parsing only: no file and no row is written here, the service
     * persists what the DTO carries.
     *
     * A parse failure is non-fatal - it yields no attachments, which is what
     * lets the legacy fallback below take over, as before.
     *
     * @return list<EmailAttachment>
     */
    private function parseAttachmentsFromMessage($imapConnection, $messageNumber): array
    {
        if (!file_exists(__DIR__ . '/MailParser.php')) {
            return [];
        }

        require_once __DIR__ . '/MailParser.php';
        require_once __DIR__ . '/EmailStorage/EmailAttachment.php';

        try {
            $rawHeaders = @imap_fetchheader($imapConnection, $messageNumber);
            $rawBody = @imap_body($imapConnection, $messageNumber);
            $raw = ($rawHeaders ?: '') . "\r\n" . ($rawBody ?: '');

            $parser = new MailParser($this->pdo, $this->config, $this->debugMode);
            $parsed = $parser->parseRawMessage($raw);
        } catch (\Throwable $e) {
            $this->log('WARNING', 'MailParser failed: ' . $e->getMessage());
            return [];
        }

        $attachments = [];
        foreach ($parsed['attachments'] ?? [] as $part) {
            if (is_array($part)) {
                $attachments[] = EmailAttachment::fromArray($part);
            }
        }

        $this->log('DEBUG', 'MailParser parsed attachments from message', [
            'message_number' => $messageNumber,
            'parsed' => count($attachments)
        ]);

        return $attachments;
    }

    /**
     * Extract the message's parts with LegacyImapFallback, which fetches each
     * part over the live IMAP connection - the reason it cannot travel in the
     * storage DTO and needs a call of its own here - and hand what it returns to
     * the storage service's attachment persistence, the same path the DTO's
     * MailParser attachments take.
     *
     * @return int attachments saved
     */
    private function saveLegacyAttachmentsFromMessage($imapConnection, $messageNumber, int $emailId): int
    {
        if (!file_exists(__DIR__ . '/LegacyImapFallback.php')) {
            return 0;
        }
        require_once __DIR__ . '/LegacyImapFallback.php';

        $structure = @imap_fetchstructure($imapConnection, $messageNumber);
        $parts = $structure ? ($structure->parts ?? []) : [];
        if (empty($parts)) {
            return 0;
        }

        $attachments = LegacyImapFallback::extractAttachments($imapConnection, $messageNumber, $parts);
        if ($attachments === []) {
            return 0;
        }

        // Warnings are logged per attachment by the service; the count is what
        // this path reports and what the counter has already been bumped for.
        [, $saved] = $this->emailStorage()->persistAttachments($emailId, $attachments);

        return $saved;
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
        // The fallback persists through the Email Storage service via the CLI
        // bridge (#194) instead of opening a database connection of its own.
        // Both paths travel in the inherited environment — the same way DB_* and
        // IMAP_* already reach Python (see config.php's loadEnvironmentVariables)
        // — so nothing is added to the command line and no shell-quoting
        // concern exists. The bridge lives beside this file in the docroot,
        // which the Python script cannot derive from its own location in
        // /usr/local/bin.
        putenv('TEMPMAIL_PHP_BIN=' . (PHP_BINARY ?: '/usr/bin/php'));
        putenv('TEMPMAIL_PYTHON_BRIDGE=' . __DIR__ . '/python_imap_bridge.php');
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp);
        $out = shell_exec($cmd);
        @unlink($tmp);
        $res = json_decode(trim($out), true);
        return $res['new_emails'] ?? 0;
    }

    private function sanitizeSavedBody($body)
    {
        if (empty($body) || !is_string($body)) return $body;
        // Strip actual inline data: URIs (embedded images/attachments) before storage.
        // A previous second pass here flagged *any* run of 200+ base64-alphabet
        // characters anywhere in the body as "probably an embedded attachment" -
        // with no awareness of HTML/URL context, this also matched long opaque
        // tokens in legitimate links (e.g. mailing-list subscription-confirmation
        // URLs), truncating them to "...[removed base64]" and breaking the link
        // before it was even saved. Removed - the data: URI match above already
        // covers the documented intent.
        $body = preg_replace('/data:[^;\"]+;base64,[A-Za-z0-9+\/=\r\n]+/i', '[attachment removed]', $body);
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
