<?php

declare(strict_types=1);

/**
 * scrub_logged_emails.php — one-time CLI clean-up: remove plaintext copies of
 * users' email addresses that earlier code wrote into system_logs and
 * login_attempts. The code no longer writes them (it logs user_id, or the
 * keyed emailLogRef() from email_log_ref.php); this fixes the rows already
 * there.
 *
 * Usage: php scrub_logged_emails.php [--dry-run] [--without-ref]
 *
 *   --dry-run      count what would change, write nothing
 *   --without-ref  proceed although PRO_TRIAL_HASH_KEY is not configured:
 *                  addresses that do not resolve to an account are then
 *                  dropped with no reference, and login_attempts.email
 *                  becomes ''. Without this flag a missing key stops the
 *                  run (a dry run is always allowed), because a later run
 *                  with the key could no longer recover the correlation.
 *
 * What it changes:
 *   system_logs.context (JSON), at any depth: every string value that is an
 *     email address outside the service's own mail domain is removed. It is
 *     replaced by user_id when it matches a pro_users row (and that level has
 *     no other user_id), else by "<key>_ref" (the keyed reference; "email_ref"
 *     for the keys email/to/user_email/supporter_email/payer_email/
 *     customer_email), else by nothing. Addresses embedded inside a longer
 *     string (an error message quoting one, say) become [email_ref:<ref>],
 *     [user:<id>] or [email].
 *   system_logs.message: embedded addresses, the same way.
 *   login_attempts.email: every value containing '@' becomes the keyed
 *     reference (or '' without a key), matching what pro_auth.php writes now.
 *
 * What it leaves alone, deliberately:
 *   Addresses on the service's own domain ($config['email']['domain'] and
 *   its subdomains): temporary/personal inbox addresses such as the
 *   'to'/'to_address'/'address' values that parse.php, EmailStorage and
 *   index.php log about inbound mail, and noreply@/support@. Those are the
 *   service's addresses, not a user's registered one, and operators need
 *   them to trace delivery. Everything else (pro_users, Paddle tables,
 *   pro_trial_claims, pending_profile_changes) is untouched.
 *
 * Idempotent: a second run finds nothing to change. Never prints an address.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/email_log_ref.php';
require_once __DIR__ . '/pii_crypto.php';

const SCRUB_EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)+/';

if (!function_exists('scrubNewStats')) {
    function scrubNewStats(): array
    {
        return [
            'log_rows_scanned' => 0,
            'log_rows_changed' => 0,
            'fields_to_user_id' => 0,
            'fields_to_ref' => 0,
            'fields_dropped' => 0,
            'embedded_replaced' => 0,
            'login_attempts_scanned' => 0,
            'login_attempts_to_ref' => 0,
            'login_attempts_blanked' => 0,
        ];
    }
}

if (!function_exists('scrubIsServiceAddress')) {
    /** True when $address is on one of the service's own mail domains. */
    function scrubIsServiceAddress(string $address, array $serviceDomains): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($address, $at + 1));
        foreach ($serviceDomains as $service) {
            if ($domain === $service || substr($domain, -strlen('.' . $service)) === '.' . $service) {
                return true;
            }
        }
        return false;
    }
}

/**
 * The scrubber: holds the PDO for pro_users lookups, the key and the counts.
 */
if (!class_exists('EmailLogScrubber')) {
    final class EmailLogScrubber
    {
        private const EMAIL_REF_KEYS = ['email', 'to', 'user_email', 'supporter_email', 'payer_email', 'customer_email'];

        /** @var array<string, ?int> */
        private array $userIdCache = [];
        public array $stats;

        public function __construct(
            private PDO $pdo,
            private ?string $baseKey,
            private array $serviceDomains
        ) {
            $this->stats = scrubNewStats();
            $this->serviceDomains = array_values(array_filter(array_map(
                static fn($d) => strtolower(trim((string) $d)),
                $serviceDomains
            ), static fn($d) => $d !== ''));
        }

        private function ref(string $email): ?string
        {
            return $this->baseKey === null ? null : emailLogRef($email, $this->baseKey);
        }

        private function userIdFor(string $email): ?int
        {
            $norm = strtolower(trim($email));
            if (!array_key_exists($norm, $this->userIdCache)) {
                // By the blind index (pii_crypto.php); without PII_INDEX_KEY it
                // matches nothing and the address becomes the log reference.
                $stmt = $this->pdo->prepare('SELECT id FROM pro_users WHERE email_hash = ? LIMIT 1');
                $stmt->execute([piiEmailLookupHash($norm)]);
                $id = $stmt->fetchColumn();
                $this->userIdCache[$norm] = $id === false ? null : (int) $id;
            }
            return $this->userIdCache[$norm];
        }

        private function isUserAddress(string $value): bool
        {
            $value = trim($value);
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                && !scrubIsServiceAddress($value, $this->serviceDomains);
        }

        /** Replaces addresses embedded in free text. */
        public function scrubText(string $text): string
        {
            if (strpos($text, '@') === false) {
                return $text;
            }
            return (string) preg_replace_callback(SCRUB_EMAIL_PATTERN, function (array $m): string {
                if (scrubIsServiceAddress($m[0], $this->serviceDomains)) {
                    return $m[0];
                }
                $this->stats['embedded_replaced']++;
                $userId = $this->userIdFor($m[0]);
                if ($userId !== null) {
                    return '[user:' . $userId . ']';
                }
                $ref = $this->ref($m[0]);
                return $ref === null ? '[email]' : '[email_ref:' . $ref . ']';
            }, $text);
        }

        /** Scrubs one decoded context level, recursively. */
        public function scrubContext(array $context): array
        {
            $isList = array_is_list($context);
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $context[$key] = $this->scrubContext($value);
                    continue;
                }
                if (!is_string($value) || strpos($value, '@') === false) {
                    continue;
                }
                if (!$this->isUserAddress($value)) {
                    $context[$key] = $this->scrubText($value);
                    continue;
                }

                $userId = $this->userIdFor(trim($value));
                $ref = $this->ref(trim($value));

                if ($isList || !is_string($key)) {
                    // Keep the list's shape: replace the element in place.
                    if ($userId !== null) {
                        $context[$key] = '[user:' . $userId . ']';
                        $this->stats['fields_to_user_id']++;
                    } elseif ($ref !== null) {
                        $context[$key] = '[email_ref:' . $ref . ']';
                        $this->stats['fields_to_ref']++;
                    } else {
                        $context[$key] = '[email]';
                        $this->stats['fields_dropped']++;
                    }
                    continue;
                }

                unset($context[$key]);
                $existingUserId = isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : null;
                $refKey = in_array($key, self::EMAIL_REF_KEYS, true) ? 'email_ref' : $key . '_ref';

                if ($userId !== null && ($existingUserId === null || $existingUserId === $userId)) {
                    if ($existingUserId === null && !array_key_exists('user_id', $context)) {
                        $context['user_id'] = $userId;
                    }
                    $this->stats['fields_to_user_id']++;
                } elseif ($ref !== null && !array_key_exists($refKey, $context)) {
                    $context[$refKey] = $ref;
                    $this->stats['fields_to_ref']++;
                } else {
                    $this->stats['fields_dropped']++;
                }
            }
            return $context;
        }

        /** Returns [newMessage, newContext] for one system_logs row. */
        public function scrubLogRow(string $message, ?string $context): array
        {
            $newMessage = $this->scrubText($message);
            $newContext = $context;
            if ($context !== null && strpos($context, '@') !== false) {
                $decoded = json_decode($context, true);
                if (is_array($decoded)) {
                    $newContext = json_encode($this->scrubContext($decoded));
                } elseif (is_string($decoded)) {
                    $newContext = json_encode($this->scrubText($decoded));
                } else {
                    // Not JSON after all: treat it as text.
                    $newContext = $this->scrubText($context);
                }
            }
            return [$newMessage, $newContext];
        }

        public function scrubSystemLogs(bool $dryRun, int $batch = 500): void
        {
            $select = $this->pdo->prepare(
                "SELECT id, message, context FROM system_logs
                 WHERE id > ? AND (message LIKE '%@%' OR context LIKE '%@%')
                 ORDER BY id LIMIT " . max(1, $batch)
            );
            $update = $this->pdo->prepare('UPDATE system_logs SET message = ?, context = ? WHERE id = ?');
            $lastId = 0;
            while (true) {
                $select->execute([$lastId]);
                $rows = $select->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $this->stats['log_rows_scanned']++;
                    $message = (string) $row['message'];
                    $context = $row['context'] === null ? null : (string) $row['context'];
                    [$newMessage, $newContext] = $this->scrubLogRow($message, $context);
                    if ($newMessage === $message && $newContext === $context) {
                        continue;
                    }
                    $this->stats['log_rows_changed']++;
                    if (!$dryRun) {
                        $update->execute([$newMessage, $newContext, $lastId]);
                    }
                }
            }
        }

        public function scrubLoginAttempts(bool $dryRun, int $batch = 500): void
        {
            $select = $this->pdo->prepare(
                "SELECT id, email FROM login_attempts WHERE id > ? AND email LIKE '%@%' ORDER BY id LIMIT " . max(1, $batch)
            );
            $update = $this->pdo->prepare('UPDATE login_attempts SET email = ? WHERE id = ?');
            $lastId = 0;
            while (true) {
                $select->execute([$lastId]);
                $rows = $select->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $this->stats['login_attempts_scanned']++;
                    $ref = $this->ref((string) $row['email']);
                    if ($ref !== null) {
                        $this->stats['login_attempts_to_ref']++;
                    } else {
                        $this->stats['login_attempts_blanked']++;
                    }
                    if (!$dryRun) {
                        $update->execute([$ref ?? '', $lastId]);
                    }
                }
            }
        }
    }
}

// Tests load this file for the functions above only.
if (defined('SCRUB_LOGGED_EMAILS_LIBRARY')) {
    return;
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$dryRun = in_array('--dry-run', $argv, true);
$withoutRef = in_array('--without-ref', $argv, true);

$baseKey = emailLogRefBaseKey();
if (emailLogRef('probe@example.com', $baseKey) === null) {
    $baseKey = null;
    if (!$dryRun && !$withoutRef) {
        fwrite(STDERR, "PRO_TRIAL_HASH_KEY is not configured (32+ characters), so no keyed references can be written.\n"
            . "Set it first, or re-run with --without-ref to drop unresolvable addresses with no reference.\n");
        exit(2);
    }
    echo "No PRO_TRIAL_HASH_KEY: unresolvable addresses are dropped with no reference.\n";
}

$serviceDomains = [(string) ($config['email']['domain'] ?? '')];
$scrubber = new EmailLogScrubber($pdo, $baseKey, $serviceDomains);

foreach (['system_logs' => 'scrubSystemLogs', 'login_attempts' => 'scrubLoginAttempts'] as $table => $method) {
    try {
        $scrubber->$method($dryRun);
    } catch (PDOException $e) {
        fwrite(STDERR, "{$table}: could not be scrubbed - " . $e->getMessage() . "\n");
        exit(1);
    }
}

$s = $scrubber->stats;
$verb = $dryRun ? 'would change (dry run)' : 'changed';
printf("system_logs: %d row(s) with an '@' scanned, %d %s.\n", $s['log_rows_scanned'], $s['log_rows_changed'], $verb);
printf("  context fields: %d -> user_id, %d -> keyed reference, %d dropped; %d embedded address(es) replaced.\n",
    $s['fields_to_user_id'], $s['fields_to_ref'], $s['fields_dropped'], $s['embedded_replaced']);
printf("login_attempts: %d plaintext row(s) %s: %d -> keyed reference, %d -> ''.\n",
    $s['login_attempts_scanned'], $verb, $s['login_attempts_to_ref'], $s['login_attempts_blanked']);
exit(0);
