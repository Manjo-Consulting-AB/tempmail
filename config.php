<?php
/**
 * TempMail Configuration
 * Centraliserade inställningar för temporära e-postadresser
 */

// Förhindra direktåtkomst
defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

// One clock for PHP and MySQL. PHP runs in Europe/Stockholm, and the DB
// session below is pinned to the same UTC offset, so date() values written
// by PHP and NOW() in SQL always agree - whatever time zone the database
// server itself is configured with. Set first, before anything computes a date.
date_default_timezone_set('Europe/Stockholm');

function getEnvFileCandidates(string $environment): array {
    $fileName = '.env.' . $environment;
    $candidates = [];

    $addCandidate = static function(string $path) use (&$candidates): void {
        $trimmed = trim($path);
        if ($trimmed !== '') {
            $candidates[] = $trimmed;
        }
    };

    $explicit = getenv('TEMPMAIL_ENV_FILE') ?: getenv('ENV_FILE_PATH') ?: '';
    if (is_string($explicit) && trim($explicit) !== '') {
        $addCandidate(trim($explicit));
    }

    $secureDir = getenv('TEMPMAIL_ENV_DIR') ?: getenv('SECURE_ENV_DIR') ?: '';
    if (is_string($secureDir) && trim($secureDir) !== '') {
        $addCandidate(rtrim(trim($secureDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName);
    }

    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
    if (is_string($home) && trim($home) !== '') {
        $homeDir = rtrim(trim($home), DIRECTORY_SEPARATOR);
        $addCandidate($homeDir . DIRECTORY_SEPARATOR . 'manjo.me' . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . $fileName);
        $addCandidate($homeDir . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . $fileName);
    }

    // Hosting fallbacks based on known script roots and webserver paths.
    $pathAnchors = [
        __DIR__,
        dirname(__DIR__),
        dirname(dirname(__DIR__)),
        $_SERVER['DOCUMENT_ROOT'] ?? '',
        isset($_SERVER['SCRIPT_FILENAME']) ? dirname((string) $_SERVER['SCRIPT_FILENAME']) : '',
        getcwd() ?: ''
    ];

    foreach ($pathAnchors as $anchor) {
        if (!is_string($anchor) || trim($anchor) === '') {
            continue;
        }

        $anchor = rtrim(trim($anchor), DIRECTORY_SEPARATOR);
        $addCandidate($anchor . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . $fileName);
        $addCandidate(dirname($anchor) . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . $fileName);
        $addCandidate(dirname(dirname($anchor)) . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . $fileName);
    }

    // Project-local fallback inside src.
    $addCandidate(__DIR__ . DIRECTORY_SEPARATOR . $fileName);

    $unique = [];
    foreach ($candidates as $path) {
        if (!in_array($path, $unique, true)) {
            $unique[] = $path;
        }
    }

    return $unique;
}

function resolveEnvFilePath(string $environment): ?string {
    $candidates = getEnvFileCandidates($environment);
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    error_log('TempMail env file not found/readable for environment "' . $environment . '". Candidates: ' . implode(', ', $candidates));

    return null;
}

// Ladda miljövariabler från miljöspecifik .env-fil
function loadEnvironmentVariables($environment = null) {
    // Om miljö inte angetts, detektera den
    if ($environment === null) {
        $environment = detectEnvironment();
    }
    
    $envFile = resolveEnvFilePath((string) $environment);

    if ($envFile === null) {
        return;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Hoppa över kommentarer
        if (strpos($line, '#') === 0) {
            continue;
        }
        
        // Dela upp nyckel=värde
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Ta bort citattecken om de finns
            $value = trim($value, '"\'');
            
            // Sätt miljövariabel
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Ladda miljövariabler
loadEnvironmentVariables();

// ====================================================================
// MILJÖSPECIFIK KONFIGURATION
// Detekterar automatiskt om vi kör i utveckling (Docker) eller produktion
// ====================================================================

// Detektera miljö
function detectEnvironment() {
    // Track which check determined the environment via $GLOBALS['__env_detect_source']
    // 1) Explicit environment variables take precedence (DOCKER_ENV, APP_ENV, ENV)
    $explicit = getenv('DOCKER_ENV') ?: getenv('APP_ENV') ?: getenv('ENV');
    if ($explicit !== false && $explicit !== null && $explicit !== '') {
        $e = strtolower(trim($explicit));
        if (in_array($e, ['production', 'prod'], true)) {
            $GLOBALS['__env_detect_source'] = 'explicit_env_var';
            return 'production';
        }
        if (in_array($e, ['development', 'dev'], true)) {
            $GLOBALS['__env_detect_source'] = 'explicit_env_var';
            return 'development';
        }
        $GLOBALS['__env_detect_source'] = 'explicit_env_var';
        return $e;
    }

    // 2) If running in a Docker container, assume development
    if (file_exists('/.dockerenv')) {
        $GLOBALS['__env_detect_source'] = 'docker_env_file';
        return 'development';
    }

    // 3) If running from CLI, attempt to infer environment from .env.production content
    if (php_sapi_name() === 'cli' || defined('STDIN')) {
        $prodFile = resolveEnvFilePath('production');
        if ($prodFile !== null) {
            $lines = file($prodFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $vals = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) continue;
                if (strpos($line, '=') === false) continue;
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                $vals[$k] = $v;
            }
            // Heuristics: if production DB host isn't the docker default, or IMAP server points to websupport, treat as production
            if (!empty($vals['DB_HOST']) && $vals['DB_HOST'] !== 'db') {
                $GLOBALS['__env_detect_source'] = 'cli_.env.production_db_host';
                return 'production';
            }
            if (!empty($vals['IMAP_SERVER']) && stripos($vals['IMAP_SERVER'], 'websupport') !== false) {
                $GLOBALS['__env_detect_source'] = 'cli_.env.production_imap';
                return 'production';
            }
            if (!empty($vals['BASE_URL']) && stripos($vals['BASE_URL'], 'manjo.me') !== false) {
                $GLOBALS['__env_detect_source'] = 'cli_.env.production_baseurl';
                return 'production';
            }
            $GLOBALS['__env_detect_source'] = 'cli_.env.production_no_match';
        } else {
            $GLOBALS['__env_detect_source'] = 'cli_no_env_file';
        }
    }

    // 4) If running via web, check host patterns
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'manjo.me') !== false) {
        $GLOBALS['__env_detect_source'] = 'http_host_manjo';
        return 'production';
    }

    // 5) localhost patterns imply development
    if (isset($_SERVER['HTTP_HOST']) && (
        strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'], '::1') !== false)) {
        $GLOBALS['__env_detect_source'] = 'http_host_local';
        return 'development';
    }

    // Default to development
    $GLOBALS['__env_detect_source'] = 'default_development';
    return 'development';
}

$environment = detectEnvironment();

/**
 * True when the detected environment is production. Security-sensitive
 * debug conveniences (login links in responses, tokens in logs, verbose DB
 * errors) must check this in addition to debug_mode, so a misconfigured
 * DEBUG_MODE can never expose them in production.
 */
function appIsProduction(): bool {
    // Falls back to detecting again when config.php was included from inside
    // a function (then $environment is not a global), so this never fails open.
    $environment = $GLOBALS['environment'] ?? detectEnvironment();
    return $environment === 'production';
}

// Capture the detection source (set inside detectEnvironment)
$env_source = $GLOBALS['__env_detect_source'] ?? 'unknown';
// Log a concise line for diagnostics - CLI only: on the web this ran on every
// request and filled the PHP error log.
if (PHP_SAPI === 'cli') {
    error_log("TempMail Environment detected as: {$environment} (source: {$env_source})");
}

// Ladda miljövariabler för detekterad miljö
loadEnvironmentVariables($environment);

// Grundkonfiguration som delas mellan miljöer
$baseConfig = [
    'email' => [
        'domain' => $_ENV['EMAIL_DOMAIN'] ?? 'manjo.me',
        // Use primary domain for generated links in production (no subdomain)
        'base_url' => $_ENV['BASE_URL'] ?? ($environment === 'production' ? 'https://manjo.me/' : 'http://localhost:8085/'),
        // Largest raw message parse.php accepts, in bytes (#212). 10 MB.
        'max_message_bytes' => (int)($_ENV['MAX_MESSAGE_BYTES'] ?? 10485760),
        // Stored-mail quota per user account, or per anonymous address, in bytes (#212). 100 MB.
        'quota_bytes' => (int)($_ENV['MAILBOX_QUOTA_BYTES'] ?? 104857600),
    ],
    'app' => [
        'cleanup_hours' => 24,
        'address_length' => 12,
        'max_emails_per_address' => 50,
        // Displayed in the site footer. Bump this on release.
        'version' => '2.24'
    ],
    'cleanup' => [
        // Default retention; can be overridden with environment variable LOG_RETENTION_DAYS
        'log_retention_days' => (int) (getenv('LOG_RETENTION_DAYS') ?: ($_ENV['LOG_RETENTION_DAYS'] ?? 30)),
        // Inaktivitetsstädning av Regular-konton (#63) - se documentaion/ACCOUNT_TIERS.md §6.2.
        // Varning skickas vid REGULAR_INACTIVITY_WARN_DAYS, radering sker vid REGULAR_INACTIVITY_DAYS.
        'regular_inactivity_warn_days' => (int) (getenv('REGULAR_INACTIVITY_WARN_DAYS') ?: ($_ENV['REGULAR_INACTIVITY_WARN_DAYS'] ?? 335)),
        'regular_inactivity_days' => (int) (getenv('REGULAR_INACTIVITY_DAYS') ?: ($_ENV['REGULAR_INACTIVITY_DAYS'] ?? 365))
    ]
    ,
    'cron' => [
        // Optional HTTP secret for allowing authorized HTTP triggers
        'http_secret' => $_ENV['CRON_HTTP_SECRET'] ?? null,
        // SMTP/digest throttling defaults (can be overridden via env vars)
        'smtp_max_per_run' => (int)($_ENV['SMTP_MAX_PER_RUN'] ?? 500),
        'smtp_batch_size' => (int)($_ENV['SMTP_BATCH_SIZE'] ?? 50),
        'smtp_per_minute' => (int)($_ENV['SMTP_PER_MINUTE'] ?? 200)
    ],
    'webhooks' => [
        // Send Pro webhooks from parse.php as soon as the email is stored, instead
        // of waiting for cron/process-webhook-deliveries.php (which still retries
        // failures). WEBHOOK_DELIVER_IMMEDIATELY=false restores queue-only.
        'deliver_immediately' => filter_var($_ENV['WEBHOOK_DELIVER_IMMEDIATELY'] ?? true, FILTER_VALIDATE_BOOLEAN),
        // Upper bound on deliveries sent inline per email, so slow targets cannot
        // hold the Exim pipe for long; the rest go to the cron worker.
        'immediate_limit' => (int)($_ENV['WEBHOOK_IMMEDIATE_LIMIT'] ?? 5),
    ],
    'directadmin' => [
        // DirectAdmin API base URL, e.g. https://server.inleed.net:2222
        'host' => $_ENV['DA_HOST'] ?? '',
        'user' => $_ENV['DA_USER'] ?? '',
        'api_key' => $_ENV['DA_API_KEY'] ?? '',
        'domain' => $_ENV['DA_DOMAIN'] ?? ($_ENV['EMAIL_DOMAIN'] ?? 'manjo.me'),
        // The forwarder is the only mail intake (#212): on by default in production,
        // off by default elsewhere (no DirectAdmin credentials in local dev). An
        // explicit DA_FORWARDER_ENABLED always wins.
        'forwarder_enabled' => filter_var($_ENV['DA_FORWARDER_ENABLED'] ?? ($environment === 'production'), FILTER_VALIDATE_BOOLEAN),
        // Pipe destination new forwarders are created with. Path confirmed via SSH
        // against the real Inleed server in #35 — username s174280, webroot under
        // domains/<domain>/public_html. Override with DA_FORWARDER_DESTINATION if
        // the server layout ever changes.
        'forwarder_destination' => $_ENV['DA_FORWARDER_DESTINATION']
            ?? ('|/usr/bin/php /home/s174280/domains/' . ($_ENV['DA_DOMAIN'] ?? ($_ENV['EMAIL_DOMAIN'] ?? 'manjo.me')) . '/public_html/parse.php')
    ],
    // Account ids (pro_users.id) allowed into admin tools such as
    // log_viewer.php. Empty = no admins. See isAdminUser().
    'admin' => [
        'user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) ($_ENV['ADMIN_USER_IDS'] ?? ''))), static fn(int $id): bool => $id > 0)),
    ],
    'pro' => [
        // Kill switch of the same kind as 'directadmin.forwarder_enabled' above:
        // preparation for the upcoming payment integration (public Pro self-
        // signup without a voucher code, see documentaion/ACCOUNT_TIERS.md §8).
        // Must stay off until that payment flow actually exists — while off,
        // register.php?plan=pro keeps requiring a valid voucher code exactly
        // as it does today. This issue only introduces/reads the flag; no
        // caller changes behavior based on it yet.
        'self_signup_enabled' => filter_var($_ENV['PRO_SELF_SIGNUP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)
    ],
    'paddle' => [
        // Deliberately no defaults: paddleClientSettings() refuses to run when
        // either is missing, so pricing.php can never silently talk to the
        // wrong Paddle account. Only the client-side token lives here — a
        // server-side API key must never reach this array, it is rendered
        // into the page.
        'environment'  => $_ENV['PADDLE_ENVIRONMENT'] ?? null,
        'client_token' => $_ENV['PADDLE_CLIENT_TOKEN'] ?? null,
        // Secret of the notification destination that points at
        // paddle_webhook.php (pdl_ntfset_…). Server-side only.
        'webhook_secret' => $_ENV['PADDLE_WEBHOOK_SECRET'] ?? null,
        // Server-side API key (pdl_sdbx_apikey_… / pdl_live_apikey_…) with only
        // the customer-portal-session permission; read by paddle_api.php.
        'api_key' => $_ENV['PADDLE_API_KEY'] ?? null
    ],
    // 60-day Pro trial for new Regular accounts, one trial per email address,
    // ever (epic #267). hash_key must never change once set: it is the HMAC
    // key that turns a normalised address into pro_trial_claims.email_hash,
    // and rotating it makes every stored hash unmatchable, silently letting
    // every address claim a trial again. days = 0 turns the trial off, but
    // addresses are still recorded (decision 8) so a later flip-on is exact.
    'trial' => [
        'days' => max(0, (int)($_ENV['PRO_TRIAL_DAYS'] ?? 60)),
        'hash_key' => (string)($_ENV['PRO_TRIAL_HASH_KEY'] ?? ''),
        'claim_retention_days' => 1825,
    ],
    // Cool-off for released personal addresses (address_cooldown.php): a
    // deleted personal address stays reserved for its last owner for
    // `months`, and each owner holds at most `max_per_user` such
    // reservations — adding one more releases the oldest to everyone.
    'address_cooldown' => [
        'months' => max(1, (int)($_ENV['ADDRESS_COOLDOWN_MONTHS'] ?? 6)),
        'max_per_user' => max(1, (int)($_ENV['ADDRESS_COOLDOWN_MAX_PER_USER'] ?? 30)),
    ],
    // Abuse guard (abuse_guard.php, documentaion/ABUSE_PROTECTION.md). Only
    // the keys whose env var is set are filled in here; abuseGuardSettings()
    // supplies every default, so the numbers live in one place.
    'abuse' => (static function (): array {
        $map = [
            'ABUSE_ADDRESS_MAX_5MIN' => 'address_max_5min',
            'ABUSE_ADDRESS_MAX_HOUR' => 'address_max_hour',
            'ABUSE_ADDRESS_MAX_BYTES_HOUR' => 'address_max_bytes_hour',
            'ABUSE_ADDRESS_MAX_STRIKES_HOUR' => 'address_max_strikes_hour',
            'ABUSE_WARN_RATIO' => 'warn_ratio',
            'ABUSE_QUARANTINE_STEPS' => 'quarantine_steps_minutes',
            'MAX_ATTACHMENTS_PER_MESSAGE' => 'max_attachments',
            'MAX_INLINE_IMAGES_PER_MESSAGE' => 'max_inline_images',
            'WEBHOOK_MAX_PER_HOUR' => 'webhook_max_hour',
            'ABUSE_GENERATE_IP_HOUR' => 'generate_ip_hour',
            'ABUSE_GENERATE_IP_DAY' => 'generate_ip_day',
            'ABUSE_GENERATE_USER_DAY' => 'generate_user_day',
            'ABUSE_PERSONAL_USER_DAY' => 'personal_user_day',
            'ABUSE_ACCOUNT_STRIKES_DAY' => 'account_strikes_day',
            'ABUSE_ACCOUNT_QUARANTINE_MINUTES' => 'account_quarantine_minutes',
            'ABUSE_ACCOUNT_QUARANTINES_WEEK' => 'account_quarantines_week',
        ];
        $out = ['enabled' => filter_var($_ENV['ABUSE_GUARD_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN)];
        foreach ($map as $env => $key) {
            if (isset($_ENV[$env]) && trim((string)$_ENV[$env]) !== '') {
                $out[$key] = trim((string)$_ENV[$env]);
            }
        }
        return $out;
    })(),
    // Who the legal pages (terms.php, privacy.php, refund-policy.php) name as
    // the seller and data controller. contact_email falls back to support@ on
    // the mail domain; org_number is left out of the copy while it is empty.
    'legal' => [
        'company' => 'Manjo Consulting AB',
        'org_number' => (string)($_ENV['LEGAL_ORG_NUMBER'] ?? ''),
        'contact_email' => (string)($_ENV['LEGAL_CONTACT_EMAIL'] ?? ('support@' . ($_ENV['EMAIL_DOMAIN'] ?? 'manjo.me'))),
    ],
];

/**
 * Validated Paddle.js settings for the browser, or a RuntimeException naming
 * what is wrong. Checks that the environment is set and known, and that the
 * token is a client-side token for that same environment (test_ = sandbox,
 * live_ = production).
 */
function paddleClientSettings(array $config): array
{
    $environment = strtolower(trim((string) ($config['paddle']['environment'] ?? '')));
    $token       = trim((string) ($config['paddle']['client_token'] ?? ''));

    if ($environment === '') {
        throw new RuntimeException('PADDLE_ENVIRONMENT is not set (expected "sandbox" or "production")');
    }
    $prefixes = ['sandbox' => 'test_', 'production' => 'live_'];
    if (!isset($prefixes[$environment])) {
        throw new RuntimeException('PADDLE_ENVIRONMENT must be "sandbox" or "production", got "' . $environment . '"');
    }
    if ($token === '') {
        throw new RuntimeException('PADDLE_CLIENT_TOKEN is not set');
    }
    if (strpos($token, $prefixes[$environment]) !== 0 || strlen($token) <= strlen($prefixes[$environment])) {
        throw new RuntimeException('PADDLE_CLIENT_TOKEN is not a ' . $environment . ' client-side token (must start with ' . $prefixes[$environment] . ')');
    }

    return ['environment' => $environment, 'token' => $token];
}

// Miljöspecifika konfigurationer
$environmentConfigs = [
    'development' => [
        'db' => [
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'port' => (int)($_ENV['DB_PORT'] ?? 3306),
            'socket' => $_ENV['DB_SOCKET'] ?? null,
            'name' => $_ENV['DB_NAME'] ?? 'tempmail',
            'user' => $_ENV['DB_USER'] ?? 'tempmail_user',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4'
        ],
        'imap' => [
            'server' => $_ENV['IMAP_SERVER'] ?? '{imap.websupport.se:993/imap/ssl}INBOX',
            'user' => $_ENV['IMAP_USER'] ?? 'catch-all@manjo.me',
            'password' => $_ENV['IMAP_PASSWORD'] ?? '',
            'enabled' => filter_var($_ENV['IMAP_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN)
        ],
        'app' => [
            'debug_mode' => filter_var($_ENV['DEBUG_MODE'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'log_level' => $_ENV['LOG_LEVEL'] ?? 'debug'
        ]
    ],
    'production' => [
        'db' => [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => (int)($_ENV['DB_PORT'] ?? 3306),
            'socket' => $_ENV['DB_SOCKET'] ?? null,
            'name' => $_ENV['DB_NAME'] ?? 'tempmail',
            'user' => $_ENV['DB_USER'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4'
        ],
        'imap' => [
            'server' => $_ENV['IMAP_SERVER'] ?? '',
            'user' => $_ENV['IMAP_USER'] ?? '',
            'password' => $_ENV['IMAP_PASSWORD'] ?? '',
            'enabled' => filter_var($_ENV['IMAP_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN)
        ],
        'app' => [
            'debug_mode' => filter_var($_ENV['DEBUG_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'log_level' => $_ENV['LOG_LEVEL'] ?? 'error'
        ]
    ]
];

// Slå samman konfigurationer
$config = array_merge_recursive($baseConfig, $environmentConfigs[$environment]);

// Attachments signing configuration (signed, time-limited download URLs)
$config['attachments'] = $config['attachments'] ?? [];
$config['attachments']['download_ttl'] = (int)($_ENV['ATTACHMENT_TTL'] ?? ($config['attachments']['download_ttl'] ?? 3600));
$config['attachments']['download_secret'] = $_ENV['ATTACHMENT_DOWNLOAD_SECRET'] ?? ($config['attachments']['download_secret'] ?? null);
// No explicit secret: outside production, derive a local-dev fallback from the
// DB credentials + base_url. Never in production - a key derived from the DB
// password is guessable by anyone who learns it, and signs every download
// link. There files.php answers 500 instead, which is loud and safe.
if (empty($config['attachments']['download_secret']) && $environment !== 'production') {
    $config['attachments']['download_secret'] = hash('sha256', ($config['db']['user'] ?? '') . ':' . ($config['db']['password'] ?? '') . ($config['email']['base_url'] ?? ''));
}

/**
 * Generate a signed, time-limited URL for downloading an attachment.
 * Returns an absolute URL string.
 * 
 * @param int $attachmentId The attachment ID
 * @param int|null $ttlSeconds Optional TTL in seconds (default from config)
 * @param int|null $baseTime Optional base timestamp for signature (default: current time).
 *                           Pass the same value to multiple calls to ensure consistent signatures.
 */
function generateSignedAttachmentUrl(int $attachmentId, ?int $ttlSeconds = null, ?int $baseTime = null): string {
    global $config;
    $ttl = $ttlSeconds ?? (int)($config['attachments']['download_ttl'] ?? 3600);
    $now = $baseTime ?? time();
    $expires = $now + $ttl;
    $data = $attachmentId . '|' . $expires;
    $secret = $config['attachments']['download_secret'] ?? '';
    $sig = hash_hmac('sha256', $data, $secret);
    $base = rtrim($config['email']['base_url'] ?? '', '/');
    // Ensure there is no double slash
    return $base . '/files.php?id=' . urlencode((string)$attachmentId) . '&expires=' . $expires . '&sig=' . urlencode($sig);
}

// Expose which source determined the environment for runtime checks
$config['env_source'] = $env_source;

/**
 * Kända engångs-/tempmail-domäner som blockeras vid självbetjäningsregistrering
 * (pro_auth.php: register_account, se documentaion/ACCOUNT_TIERS.md §4.2).
 *
 * Ironiskt nog måste en tempmail-tjänst blockera andra tempmail-tjänster: utan
 * detta kringgås kontofarmningens IP-rate limit och e-postverifiering enkelt
 * genom att bara låta en konkurrerande engångsadress ta emot verifieringsmailet.
 * Listan ska vara kort och uppenbar, inte uttömmande - underhållbarhet först.
 */
const DISPOSABLE_EMAIL_DOMAINS = [
    'mailinator.com',
    'guerrillamail.com',
    'guerrillamail.info',
    '10minutemail.com',
    '10minutemail.net',
    'yopmail.com',
    'temp-mail.org',
    'tempmail.com',
    'throwaway.email',
    'getnada.com',
    'trashmail.com',
    'dispostable.com',
    'sharklasers.com',
];

/**
 * True om e-postadressens domän finns i den kända engångsdomän-blocklistan
 * ovan. Domänjämförelsen är case-insensitive; ogiltiga adresser (utan '@')
 * räknas inte som blockerade här - anropande kod validerar formatet separat.
 */
function isDisposableEmailDomain(string $email): bool {
    $parts = explode('@', $email);
    $domain = isset($parts[1]) ? strtolower(trim($parts[1])) : '';
    if ($domain === '') {
        return false;
    }
    return in_array($domain, DISPOSABLE_EMAIL_DOMAINS, true);
}

/**
 * ============================================================================
 * INPUT SANITIZATION & VALIDATION HELPERS
 * Centralized functions for secure input handling across the application.
 * ============================================================================
 */

/**
 * Sanitize a string: trim whitespace, strip null bytes, and optionally limit length.
 * Returns null if input is null/empty after sanitization.
 *
 * @param mixed $input The input value
 * @param int $maxLength Maximum allowed length (0 = no limit)
 * @param bool $stripHtml Whether to strip HTML tags
 * @return string|null Sanitized string or null
 */
function sanitizeString($input, int $maxLength = 0, bool $stripHtml = false): ?string {
    if ($input === null || $input === '') return null;
    $s = (string) $input;
    // Remove null bytes (common in attack payloads)
    $s = str_replace("\0", '', $s);
    // Trim whitespace
    $s = trim($s);
    // Optionally strip HTML
    if ($stripHtml) {
        $s = strip_tags($s);
    }
    // Enforce max length
    if ($maxLength > 0 && mb_strlen($s) > $maxLength) {
        $s = mb_substr($s, 0, $maxLength);
    }
    return $s !== '' ? $s : null;
}

/**
 * Validate and sanitize an email address.
 *
 * @param mixed $email The email input
 * @return string|null Valid email or null if invalid
 */
function sanitizeEmail($email): ?string {
    $email = sanitizeString($email, 254, true);
    if ($email === null) return null;
    $email = strtolower($email);
    $valid = filter_var($email, FILTER_VALIDATE_EMAIL);
    return $valid !== false ? $valid : null;
}

/**
 * Validate that a string contains only alphanumeric characters (and optionally dashes/underscores).
 *
 * @param mixed $input The input value
 * @param int $maxLength Maximum allowed length
 * @param bool $allowDashes Allow dashes and underscores
 * @return string|null Validated string or null
 */
function sanitizeAlphanumeric($input, int $maxLength = 64, bool $allowDashes = false): ?string {
    $s = sanitizeString($input, $maxLength, true);
    if ($s === null) return null;
    $pattern = $allowDashes ? '/^[a-zA-Z0-9_-]+$/' : '/^[a-zA-Z0-9]+$/';
    return preg_match($pattern, $s) ? $s : null;
}

/**
 * Validate a hexadecimal string (e.g., tokens, hashes).
 *
 * @param mixed $input The input value
 * @param int $minLength Minimum length required
 * @param int $maxLength Maximum length allowed
 * @return string|null Validated hex string or null
 */
function sanitizeHexToken($input, int $minLength = 32, int $maxLength = 128): ?string {
    $s = sanitizeString($input, $maxLength, true);
    if ($s === null) return null;
    if (strlen($s) < $minLength) return null;
    return preg_match('/^[a-fA-F0-9]+$/', $s) ? strtolower($s) : null;
}

/**
 * Validate and sanitize an integer within a range.
 *
 * @param mixed $input The input value
 * @param int $min Minimum value
 * @param int $max Maximum value
 * @param int|null $default Default value if invalid
 * @return int|null Validated integer or default/null
 */
function sanitizeInt($input, int $min = 0, int $max = PHP_INT_MAX, ?int $default = null): ?int {
    if ($input === null || $input === '') return $default;
    $val = filter_var($input, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    return $val !== false ? $val : $default;
}

/**
 * Validate and sanitize a URL with optional scheme whitelist.
 *
 * @param mixed $url The URL input
 * @param int $maxLength Maximum URL length
 * @param array $allowedSchemes Allowed URL schemes
 * @return string|null Validated URL or null
 */
function sanitizeUrl($url, int $maxLength = 2048, array $allowedSchemes = ['https']): ?string {
    $url = sanitizeString($url, $maxLength, true);
    if ($url === null) return null;
    // Validate URL structure
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    // Check scheme
    $parsed = parse_url($url);
    if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), $allowedSchemes, true)) {
        return null;
    }
    return $url;
}

/**
 * Safely decode and validate JSON with depth/size limits.
 *
 * @param string $json The JSON string
 * @param int $maxSize Maximum byte size allowed
 * @param int $maxDepth Maximum nesting depth
 * @return array|null Decoded array or null on failure
 */
function sanitizeJson(string $json, int $maxSize = 8192, int $maxDepth = 10): ?array {
    if (strlen($json) > $maxSize) return null;
    $decoded = json_decode($json, true, $maxDepth);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return null;
    }
    return $decoded;
}

/**
 * Validate a "local part" for personal addresses (allows letters, numbers, dots, hyphens, underscores).
 *
 * @param mixed $local The local part input
 * @param int $minLength Minimum length
 * @param int $maxLength Maximum length
 * @return string|null Validated local part (lowercased) or null
 */
function sanitizeLocalPart($local, int $minLength = 3, int $maxLength = 64): ?string {
    $s = sanitizeString($local, $maxLength, true);
    if ($s === null) return null;
    if (strlen($s) < $minLength) return null;
    // Only allow a-z, 0-9, ., -, _
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $s)) return null;
    return strtolower($s);
}

/**
 * Escape output for safe HTML display.
 *
 * @param mixed $input The input to escape
 * @return string Escaped string safe for HTML
 */
function escapeHtml($input): string {
    return htmlspecialchars((string)$input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Detect potential attack patterns in input (logging purposes).
 * Returns array of detected patterns or empty array if clean.
 *
 * @param string $input The input to check
 * @return array List of detected suspicious patterns
 */
function detectSuspiciousPatterns(string $input): array {
    $patterns = [
        'null_byte' => "/\x00/",
        'proto_pollution' => '/__proto__|constructor\s*\[|prototype\s*\[/i',
        'script_injection' => '/<script|javascript:|on\w+\s*=/i',
        'sql_injection' => "/('|\")\s*(OR|AND|UNION|SELECT|INSERT|UPDATE|DELETE|DROP)\s/i",
        'path_traversal' => '/\.\.[\\/]/',
        'shell_command' => '/\$\(|`|\|.*\||;\s*(rm|cat|wget|curl|bash|sh)\s/i',
        'base64_payload' => '/[A-Za-z0-9+\/]{50,}={0,2}/',
    ];
    
    $detected = [];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $input)) {
            $detected[] = $name;
        }
    }
    return $detected;
}

// Skapa PDO-anslutning
try {
    $dbHost = (string)($config['db']['host'] ?? 'localhost');
    $dbName = (string)($config['db']['name'] ?? 'tempmail');
    $dbCharset = (string)($config['db']['charset'] ?? 'utf8mb4');
    $dbPort = (int)($config['db']['port'] ?? 3306);
    $dbSocket = (string)($config['db']['socket'] ?? '');

    if ($dbSocket !== '') {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $dbSocket,
            $dbName,
            $dbCharset
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbHost,
            $dbPort,
            $dbName,
            $dbCharset
        );
    }
    
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . $dbCharset . ", time_zone = '" . date('P') . "'"
    ]);
} catch (PDOException $e) {
    // Shared hosting often resolves localhost to a missing socket for CLI cron.
    if (
        strpos((string)$e->getMessage(), 'No such file or directory') !== false
        && (($config['db']['host'] ?? '') === 'localhost')
        && empty($config['db']['socket'])
    ) {
        try {
            $fallbackDsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                '127.0.0.1',
                (int)($config['db']['port'] ?? 3306),
                (string)($config['db']['name'] ?? 'tempmail'),
                (string)($config['db']['charset'] ?? 'utf8mb4')
            );

            $pdo = new PDO($fallbackDsn, $config['db']['user'], $config['db']['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . ((string)($config['db']['charset'] ?? 'utf8mb4')) . ", time_zone = '" . date('P') . "'"
            ]);

            $config['db']['host'] = '127.0.0.1';
        } catch (PDOException $e2) {
            $e = $e2;
        }
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
    // The DirectAdmin/Exim pipe intake (parse.php) must not print here: any
    // output makes Exim bounce the mail permanently. Defer instead — exit 75
    // (EX_TEMPFAIL), silent, so the transport retries the delivery later.
    if (defined('TEMPMAIL_PIPE_INTAKE')) {
        error_log('Database connection failed (pipe intake, deferring): ' . $e->getMessage());
        exit(75);
    }
    // Never print connection details in production, whatever DEBUG_MODE says.
    if ($config['app']['debug_mode'] && !appIsProduction()) {
        die("Databasanslutning misslyckades i {$environment}-miljö: " . $e->getMessage() . 
            "<br>Host: {$config['db']['host']}, DB: {$config['db']['name']}, User: {$config['db']['user']}");
    } else {
        die('Databasfel uppstod. Försök igen senare.');
    }
    }
}

// Validera inaktivitetströsklarna (#63): varningen måste komma före raderingen,
// annars hinner kontot aldrig varnas. Felkonfiguration loggas och faller
// tillbaka till standardvärdena (335/365 dagar) i stället för att stoppa
// hela cleanup-körningen. Placerad efter PDO-anslutningen ovan eftersom
// logMessage() kräver ett fungerande $pdo.
if ((int)$config['cleanup']['regular_inactivity_warn_days'] >= (int)$config['cleanup']['regular_inactivity_days']) {
    logMessage('WARNING', 'Misconfigured REGULAR_INACTIVITY_WARN_DAYS/REGULAR_INACTIVITY_DAYS (warn must be less than delete) - falling back to defaults', [
        'regular_inactivity_warn_days' => $config['cleanup']['regular_inactivity_warn_days'],
        'regular_inactivity_days' => $config['cleanup']['regular_inactivity_days']
    ]);
    $config['cleanup']['regular_inactivity_warn_days'] = 335;
    $config['cleanup']['regular_inactivity_days'] = 365;
}

// ============================================================================
// IP BLACKLIST SECURITY FILTER
// Kontrollerar besökarens IP mot ip_blacklist-tabellen och nekar åtkomst om
// IP:n är spärrad (permanent eller tidsbunden).
// ============================================================================

/**
 * Vitlista med IP-adresser som aldrig får blockeras.
 * Inkluderar admin-IP:er, server-IP:er och lokala adresser.
 */
$IP_WHITELIST = [
    '127.0.0.1',           // IPv4 localhost
    '::1',                 // IPv6 localhost
    '10.0.0.0/8',          // Privat nätverk (Docker, interna nätverk)
    '172.16.0.0/12',       // Privat nätverk (Docker default)
    '192.168.0.0/16',      // Privat nätverk (hem/kontor)
    // Lägg till admin-IP:er här vid behov:
    // '203.0.113.50',
];

// ipMatchesCidr(), getVisitorIp() and the other pure IP helpers live in
// ip_utils.php (dependency-free, so tests/visitor_ip_test.php can load them).
require_once __DIR__ . '/ip_utils.php';

/**
 * Kontrollera om en IP-adress är vitlistad.
 * 
 * @param string $ip IP-adress att kontrollera
 * @return bool True om IP:n är vitlistad
 */
function isIpWhitelisted(string $ip): bool {
    global $IP_WHITELIST;
    
    foreach ($IP_WHITELIST as $entry) {
        if (ipMatchesCidr($ip, $entry)) {
            return true;
        }
    }
    // Safety net: never block a proxy. If the site sits behind Cloudflare (or
    // a configured proxy) but TRUST_CLOUDFLARE / TRUSTED_PROXIES is missing,
    // getVisitorIp() returns the proxy's address for every visitor, and one
    // block on it would lock everyone out.
    if (ipInAnyRange($ip, cloudflareIpRanges())
        || ipInAnyRange($ip, parseTrustedProxies(visitorIpEnv('TRUSTED_PROXIES')))) {
        return true;
    }
    return false;
}

/**
 * Kontrollera om en IP-adress är blockerad.
 * 
 * @param PDO $pdo Databasanslutning
 * @param string|null $ip IP-adress att kontrollera (null = aktuell besökare)
 * @return array|false Blockeringsinfo om blockerad, annars false
 */
function isIpBlocked(PDO $pdo, ?string $ip = null): array|false {
    if ($ip === null) {
        $ip = getVisitorIp();
    }
    
    // Vitlistade IP:er är aldrig blockerade
    if (isIpWhitelisted($ip)) {
        return false;
    }
    
    try {
        // Hämta endast nödvändiga kolumner för prestanda
        // En IP är blockerad om:
        // 1. is_permanent = 1, ELLER
        // 2. blocked_until > NOW()
        $stmt = $pdo->prepare("
            SELECT id, reason, attempts, blocked_until, is_permanent
            FROM ip_blacklist
            WHERE ip_address = ?
              AND (is_permanent = 1 OR blocked_until > NOW())
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result;
        }
        
        return false;
    } catch (PDOException $e) {
        // "Fail open" - vid databasfel låt besökaren passera men logga felet
        error_log("IP Blacklist check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Hantera en blockerad besökare - skicka 403 och avsluta.
 * 
 * @param array $blockInfo Blockeringsinfo från isIpBlocked()
 */
function handleBlockedVisitor(array $blockInfo): void {
    // Sätt HTTP 403 Forbidden
    http_response_code(403);
    
    // Sätt headers för att förhindra cachning
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
    
    // Minimalistiskt meddelande utan systeminformation.
    // 403-sidan är engelskspråkig som resten av produkten: Redesign 21 (#115)
    // kräver att ingen sida deklarerar svensk språkkod men serverar engelsk
    // text, och att deklarera engelska för svensk text vore värre än att
    // översätta de två raderna.
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access denied</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
               display: flex; justify-content: center; align-items: center; 
               height: 100vh; margin: 0; background: #f5f5f5; }
        .container { text-align: center; padding: 40px; background: white; 
                     border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #d32f2f; margin-bottom: 10px; }
        p { color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h1>403</h1>
        <p>Access denied for security reasons.</p>
    </div>
</body>
</html>';
    
    // Terminera omedelbart
    exit();
}

/**
 * Flagga en IP-adress för misstänkt aktivitet.
 * Implementerar eskaleringslogik:
 * - 1-2 försök: Logga i ip_blacklist
 * - 3+ försök: Blockera i 24 timmar
 * - 10+ försök: Permanent blockering
 * 
 * @param string $ip IP-adress att flagga
 * @param string $reason Anledning till flaggning
 * @param PDO|null $pdoConnection Databasanslutning (null = använd global)
 * @return bool True om flaggning lyckades
 */
function flagMaliciousActivity(string $ip, string $reason, ?PDO $pdoConnection = null): bool {
    global $pdo;
    $db = $pdoConnection ?? $pdo;
    
    // Skydda vitlistade IP:er
    if (isIpWhitelisted($ip)) {
        error_log("Försökte flagga vitlistad IP: {$ip} - Anledning: {$reason}");
        return false;
    }
    
    // Tröskelvärden för blockering
    // Raised from 1/5: a single false positive (or a shared NAT/proxy IP)
    // must not lock a visitor out for 24 hours on the first flag.
    $TEMP_BLOCK_THRESHOLD = 3;    // Försök innan tillfällig blockering
    $PERMANENT_THRESHOLD = 10;     // Försök innan permanent blockering
    $TEMP_BLOCK_HOURS = 24;        // Timmar för tillfällig blockering
    
    try {
        // Kontrollera om IP:n redan finns
        $stmt = $db->prepare("SELECT id, attempts, is_permanent FROM ip_blacklist WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // IP finns redan - uppdatera
            $newAttempts = (int)$existing['attempts'] + 1;
            
            // Avgör blockeringsstatus baserat på nya antalet försök
            if ($newAttempts >= $PERMANENT_THRESHOLD) {
                // Permanent blockering
                $stmt = $db->prepare("
                    UPDATE ip_blacklist 
                    SET attempts = ?, 
                        reason = CONCAT(reason, ' | ', ?),
                        is_permanent = 1,
                        blocked_until = NULL,
                        last_activity = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newAttempts, $reason, $existing['id']]);
                error_log("IP {$ip} permanent blockerad efter {$newAttempts} försök. Senaste anledning: {$reason}");
            } elseif ($newAttempts >= $TEMP_BLOCK_THRESHOLD) {
                // Tillfällig blockering i 24 timmar
                $blockedUntil = date('Y-m-d H:i:s', strtotime("+{$TEMP_BLOCK_HOURS} hours"));
                $stmt = $db->prepare("
                    UPDATE ip_blacklist 
                    SET attempts = ?, 
                        reason = CONCAT(reason, ' | ', ?),
                        blocked_until = ?,
                        last_activity = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newAttempts, $reason, $blockedUntil, $existing['id']]);
                error_log("IP {$ip} tillfälligt blockerad till {$blockedUntil}. Försök: {$newAttempts}. Anledning: {$reason}");
            } else {
                // Bara öka antalet försök
                $stmt = $db->prepare("
                    UPDATE ip_blacklist 
                    SET attempts = ?, 
                        reason = CONCAT(reason, ' | ', ?),
                        last_activity = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newAttempts, $reason, $existing['id']]);
            }
        } else {
            // Ny IP - skapa rad
            // Med threshold = 1, blockera direkt vid första försöket
            $blockedUntil = null;
            if ($TEMP_BLOCK_THRESHOLD <= 1) {
                $blockedUntil = date('Y-m-d H:i:s', strtotime("+{$TEMP_BLOCK_HOURS} hours"));
            }
            
            $stmt = $db->prepare("
                INSERT INTO ip_blacklist (ip_address, reason, attempts, blocked_until, is_permanent)
                VALUES (?, ?, 1, ?, 0)
            ");
            $stmt->execute([$ip, $reason, $blockedUntil]);
            
            if ($blockedUntil) {
                error_log("Ny IP {$ip} flaggad och blockerad till {$blockedUntil}. Anledning: {$reason}");
            } else {
                error_log("Ny IP {$ip} flaggad. Anledning: {$reason}");
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Kunde inte flagga IP {$ip}: " . $e->getMessage());
        return false;
    }
}

/**
 * Ta bort en IP från blockeringslistan (t.ex. för manuell vitlistning).
 * 
 * @param string $ip IP-adress att avblockera
 * @param PDO|null $pdoConnection Databasanslutning (null = använd global)
 * @return bool True om borttagning lyckades
 */
function unblockIp(string $ip, ?PDO $pdoConnection = null): bool {
    global $pdo;
    $db = $pdoConnection ?? $pdo;
    
    try {
        $stmt = $db->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?");
        $stmt->execute([$ip]);
        error_log("IP {$ip} avblockerad manuellt.");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Kunde inte avblockera IP {$ip}: " . $e->getMessage());
        return false;
    }
}

/**
 * Hämta information om en blockerad IP.
 * 
 * @param string $ip IP-adress att kontrollera
 * @param PDO|null $pdoConnection Databasanslutning (null = använd global)
 * @return array|null IP-info eller null om ej finns
 */
function getIpBlockInfo(string $ip, ?PDO $pdoConnection = null): ?array {
    global $pdo;
    $db = $pdoConnection ?? $pdo;
    
    try {
        $stmt = $db->prepare("SELECT * FROM ip_blacklist WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    } catch (PDOException $e) {
        error_log("Kunde inte hämta IP-info för {$ip}: " . $e->getMessage());
        return null;
    }
}

// =============================================================================
// UTFÖR IP-BLOCKERINGSKONTROLL
// Denna kod körs automatiskt vid varje sidladdning.
// =============================================================================

// Endast för HTTP-förfrågningar (inte CLI)
if (php_sapi_name() !== 'cli') {
    $blockInfo = isIpBlocked($pdo);
    if ($blockInfo !== false) {
        // Logga blockeringsförsöket
        $visitorIp = getVisitorIp();
        error_log("Blockerad IP försökte komma åt sajten: {$visitorIp}");
        
        // Hantera den blockerade besökaren (skicka 403 och avsluta)
        handleBlockedVisitor($blockInfo);
    }
}

// Debug-information (visas endast i utvecklingsläge)
if ($config['app']['debug_mode']) {
    error_log("TempMail Environment: {$environment}");
    error_log("Database Host: {$config['db']['host']}");
    error_log("IMAP Enabled: " . ($config['imap']['enabled'] ? 'Yes' : 'No'));
}

// Helper: check if a table has a given column (useful when migrations aren't applied)
function tableHasColumn($table, $column) {
    global $pdo, $config;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$config['db']['name'], $table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        // If we cannot query information_schema, assume the column does not exist to be safe
        error_log('tableHasColumn check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Kontotyp för ett konto: 'pro' eller 'regular'.
 * Se documentaion/ACCOUNT_TIERS.md §2.
 */
function proUserAccountType(int $userId): string {
    global $pdo;
    static $hasColumnCache = null;
    static $typeCache = [];

    if (array_key_exists($userId, $typeCache)) {
        return $typeCache[$userId];
    }

    if ($hasColumnCache === null) {
        $hasColumnCache = tableHasColumn('pro_users', 'account_type');
    }

    if (!$hasColumnCache) {
        // Migrationen är inte körd än - fall tillbaka på legacy-regeln.
        return $typeCache[$userId] = proUserIsPro($userId) ? 'pro' : 'regular';
    }

    try {
        $stmt = $pdo->prepare("SELECT account_type FROM pro_users WHERE id = ?");
        $stmt->execute([$userId]);
        $accountType = $stmt->fetchColumn();
        if ($accountType === 'pro' || $accountType === 'regular') {
            return $typeCache[$userId] = $accountType;
        }
        return $typeCache[$userId] = 'regular';
    } catch (Exception $e) {
        logMessage('WARNING', 'proUserAccountType lookup failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        // Fail-closed på entitlement: ge aldrig bort Pro på ett fel.
        return $typeCache[$userId] = 'regular';
    }
}

/**
 * True när kontot är berättigat till Pro-funktioner.
 * Regel: account_type = 'pro' OCH (pro_expires_at IS NULL ELLER pro_expires_at > NOW()).
 * Se documentaion/ACCOUNT_TIERS.md §2.1.
 */
function proUserIsPro(int $userId): bool {
    global $pdo;
    static $hasColumnCache = null;
    static $isProCache = [];

    if (array_key_exists($userId, $isProCache)) {
        return $isProCache[$userId];
    }

    if ($hasColumnCache === null) {
        $hasColumnCache = tableHasColumn('pro_users', 'account_type');
    }

    try {
        if ($hasColumnCache) {
            $stmt = $pdo->prepare("SELECT account_type, pro_expires_at FROM pro_users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $isProCache[$userId] = false;
            }
            $isPro = $row['account_type'] === 'pro'
                && (is_null($row['pro_expires_at']) || strtotime($row['pro_expires_at']) >= time());
            return $isProCache[$userId] = $isPro;
        }

        // Legacy-fallback: kolumnen finns inte än, använd dagens logik.
        $stmt = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $isProCache[$userId] = false;
        }
        $isPro = is_null($row['pro_expires_at']) || (strtotime($row['pro_expires_at']) >= time());
        return $isProCache[$userId] = $isPro;
    } catch (Exception $e) {
        logMessage('WARNING', 'proUserIsPro lookup failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        // Fail-closed på entitlement: ge aldrig bort Pro på ett fel.
        return $isProCache[$userId] = false;
    }
}

/**
 * True when $userId is one of the admin accounts in ADMIN_USER_IDS.
 */
function isAdminUser(int $userId): bool {
    global $config;
    return $userId > 0 && in_array($userId, $config['admin']['user_ids'] ?? [], true);
}

/**
 * Generera en unik adressträng
 */
function generateUniqueString($length = null) {
    global $config;
    $length = $length ?: $config['app']['address_length'];
    return bin2hex(random_bytes($length / 2));
}

/**
 * Creation of the DirectAdmin mail forwarder for a newly created temp
 * address (alias@domain -> parse.php pipe). Fail-closed since #212: the
 * forwarder is the only way mail reaches the address, so when it cannot be
 * created the address must not be created either. Returns false when
 * createForwarder() fails or throws (both logged at ERROR), true when it
 * succeeds. No-op returning true while
 * $config['directadmin']['forwarder_enabled'] is off (there is no forwarder
 * to create in that configuration, so creation proceeds as before).
 */
function createDirectAdminForwarder(string $alias): bool {
    global $config;

    if (empty($config['directadmin']['forwarder_enabled'])) {
        return true;
    }

    require_once __DIR__ . '/DirectAdminClient.php';

    try {
        $client = new DirectAdminClient($config['directadmin']);
        $ok = $client->createForwarder($alias, $config['directadmin']['forwarder_destination']);
        if (!$ok) {
            logMessage('ERROR', 'DirectAdmin forwarder creation failed; address creation is refused', ['alias' => $alias]);
            return false;
        }
        return true;
    } catch (Throwable $e) {
        logMessage('ERROR', 'DirectAdmin forwarder creation failed; address creation is refused', ['alias' => $alias, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Best-effort deletion of the DirectAdmin mail forwarder for an address
 * whose row in `temp_emails` is being removed (expired or deleted by the
 * user). Fail-open, same as createDirectAdminForwarder(): a failure here
 * never blocks the DB deletion (the address must disappear from the app
 * regardless), but is logged as ERROR rather than WARNING since a failed
 * delete leaves a live forwarder pointing at an address the app no longer
 * knows about — an "orphaned forwarder" that needs manual cleanup in
 * DirectAdmin until #35 defines a way to detect/reconcile these
 * automatically (see PR description for #33).
 *
 * Every deletion path comes through here, so this is also where a
 * quarantine of the address ends (abuse_guard.php): when the quarantine
 * had already removed the forwarder there is nothing left to delete.
 */
function deleteDirectAdminForwarder(string $alias): void {
    global $pdo;

    require_once __DIR__ . '/abuse_guard.php';
    try {
        if (abuseGuardAvailable() && abuseQuarantineForget($pdo, strtolower($alias))) {
            logMessage('DEBUG', 'DirectAdmin forwarder already removed by a quarantine', ['alias' => $alias]);
            return;
        }
    } catch (Throwable $e) {
        logMessage('WARNING', 'Could not end the quarantine of a deleted address', ['alias' => $alias, 'error' => $e->getMessage()]);
    }

    directAdminRemoveForwarder($alias);
}

/**
 * Delete the DirectAdmin forwarder of $alias now, and say whether it worked.
 * Used by deleteDirectAdminForwarder() and by the abuse guard's quarantine,
 * which has to know the outcome. True while DA_FORWARDER_ENABLED is off:
 * there is no forwarder to remove in that configuration.
 */
function directAdminRemoveForwarder(string $alias): bool {
    global $config;

    if (empty($config['directadmin']['forwarder_enabled'])) {
        return true;
    }

    require_once __DIR__ . '/DirectAdminClient.php';

    try {
        $client = new DirectAdminClient($config['directadmin']);
        $ok = $client->deleteForwarder($alias);
        if (!$ok) {
            logMessage('ERROR', 'DirectAdmin forwarder deletion failed; forwarder may now be orphaned and require manual cleanup', ['alias' => $alias]);
        }
        return (bool)$ok;
    } catch (Throwable $e) {
        logMessage('ERROR', 'DirectAdmin forwarder deletion threw an exception; forwarder may now be orphaned and require manual cleanup', ['alias' => $alias, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Is the account suspended (abuse_guard.php, set only by an admin)? False
 * when the column does not exist yet or the lookup fails: a suspension
 * shuts a session out, and a database hiccup must not log everyone out.
 */
function proUserIsSuspended(int $userId): bool {
    global $pdo;
    static $cache = [];
    if ($userId <= 0) {
        return false;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    if (!tableHasColumn('pro_users', 'suspended_at')) {
        return $cache[$userId] = false;
    }
    try {
        $stmt = $pdo->prepare('SELECT suspended_at FROM pro_users WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $cache[$userId] = ($value !== false && $value !== null);
    } catch (Throwable $e) {
        logMessage('WARNING', 'proUserIsSuspended lookup failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return $cache[$userId] = false;
    }
}

/**
 * Called right after session_start() on every page and action that trusts
 * $_SESSION['pro_user_id']: a suspended account's session is signed out on
 * its next request. Returns true when it did so.
 */
function proSessionEndIfSuspended(): bool {
    $userId = (int)($_SESSION['pro_user_id'] ?? 0);
    if ($userId <= 0 || !proUserIsSuspended($userId)) {
        return false;
    }
    unset($_SESSION['pro_user_id'], $_SESSION['pro_user_email'], $_SESSION['pro_login_method'], $_SESSION['pending_2fa']);
    logMessage('INFO', 'Session of a suspended account signed out', ['user_id' => $userId]);
    return true;
}

/**
 * Spara en ny temporär e-postadress
 */
function saveNewAddress($address, $proUserId = null, $isPersonal = 0) {
    global $pdo;

    try {
        // Default expiresAt is 24 hours from now
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        // If caller supplied a specific expires timestamp via global variable, use it
        if (isset($GLOBALS['__custom_expires_at']) && $GLOBALS['__custom_expires_at']) {
            $expiresAt = $GLOBALS['__custom_expires_at'];
        }

        // Insert with optional pro_user_id and is_personal if the column exists
        $hasIsPersonal = tableHasColumn('temp_emails', 'is_personal');
        if ($proUserId) {
            if ($hasIsPersonal) {
                $stmt = $pdo->prepare("INSERT INTO temp_emails (unique_address, expires_at, pro_user_id, is_personal) VALUES (?, ?, ?, ?)");
                $result = $stmt->execute([$address, $expiresAt, $proUserId, $isPersonal ? 1 : 0]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO temp_emails (unique_address, expires_at, pro_user_id) VALUES (?, ?, ?)");
                $result = $stmt->execute([$address, $expiresAt, $proUserId]);
            }
        } else {
            if ($hasIsPersonal) {
                $stmt = $pdo->prepare("INSERT INTO temp_emails (unique_address, expires_at, is_personal) VALUES (?, ?, ?)");
                $result = $stmt->execute([$address, $expiresAt, $isPersonal ? 1 : 0]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO temp_emails (unique_address, expires_at) VALUES (?, ?)");
                $result = $stmt->execute([$address, $expiresAt]);
            }
        }

        if ($result) {
            // The forwarder is the only intake path for mail, so an address
            // whose forwarder could not be created is removed again instead of
            // being handed to the user, and counts no stats (#212).
            if (!createDirectAdminForwarder($address)) {
                $pdo->prepare("DELETE FROM temp_emails WHERE unique_address = ?")->execute([$address]);
                return false;
            }
            updateStat('emails_created', 1);
            updateStat('total_users', 1);
        }
        return $result;
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte spara ny adress: ' . $e->getMessage(), ['address' => $address]);
        return false;
    }
}

/**
 * Kontrollera om en adress existerar och är giltig (inte för gammal)
 */
function isValidAddress($address) {
    global $pdo, $config;

    try {
        // Personal (Pro) addresses carry a long-lived expires_at far past
        // cleanup_hours - checking created_at here (instead of expiry) used
        // to lock Pro users out of their own addresses after 24h. Fall back
        // to the created_at check only for legacy rows with no expires_at,
        // mirroring the same pattern already used for stored_emails lookups.
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM temp_emails
            WHERE unique_address = ?
            AND (
                (expires_at IS NOT NULL AND expires_at > NOW())
                OR (expires_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR))
            )
        ");
        $stmt->execute([$address, $config['app']['cleanup_hours']]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte validera adress: ' . $e->getMessage(), ['address' => $address]);
        return false;
    }
}

// Session-scoped capability grant: an address becomes "unlocked" for the
// current session once the caller has proven knowledge of it (by passing
// isValidAddress() + any ownership check in get_emails/refresh_emails). Used
// by get_email so stored_emails' sequential integer id can't be enumerated
// to read messages for addresses the caller never demonstrated knowing.
function grantSessionAddressAccess(array $fullAddresses): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $existing = $_SESSION['unlocked_addresses'] ?? [];
    $_SESSION['unlocked_addresses'] = array_values(array_unique(array_merge($existing, $fullAddresses)));
}

function hasSessionAddressAccess(string $fullAddress): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return in_array($fullAddress, $_SESSION['unlocked_addresses'] ?? [], true);
}

function isPublicIpAddress(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Resolves a URL's host to a concrete public IP, rejecting private/loopback/
// link-local/reserved addresses (SSRF protection). Unlike checking the literal
// host string, this also catches a domain that *resolves* to an internal
// address (including via DNS rebinding, since this should be called again
// right before each dispatch, not just once at save time). Returns null if
// the URL is malformed or resolves to any non-public address.
function resolveUrlToPublicTarget(string $url): ?array {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!isPublicIpAddress($host)) {
            return null;
        }
        return ['host' => $host, 'port' => $port, 'ip' => $host];
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }
    if (empty($ips)) {
        $legacy = @gethostbynamel($host);
        if (is_array($legacy)) {
            $ips = $legacy;
        }
    }
    if (empty($ips)) {
        return null;
    }

    foreach ($ips as $ip) {
        if (!isPublicIpAddress($ip)) {
            return null;
        }
    }

    return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

// Lightweight CSRF mitigation for specific high-value endpoints. This app has
// no CSRF-token infrastructure (a larger, separate undertaking), but checking
// Origin (falling back to Referer) against the app's own configured host is a
// real, low-effort defense against forged cross-site requests, recommended by
// OWASP as a mitigation where full token-based protection isn't in place yet.
// Modern browsers reliably send Origin on same-origin POST/XHR/fetch requests
// (how this app's own JS makes these calls), so this shouldn't affect
// legitimate use.
//
// Fail-closed: no Origin and no Referer, an opaque `Origin: null` (sandboxed
// iframes, data: URLs, some redirects) or a non-http(s) source all answer
// false. Only the host is compared, deliberately: the site is served from one
// host (base_url's), and comparing scheme/port as well would turn a small
// BASE_URL misconfiguration into every mutating action failing.
function requireSameOriginRequest(): bool {
    global $config;
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
    if (!is_string($source) || trim($source) === '' || strcasecmp(trim($source), 'null') === 0) {
        return false;
    }
    $sourceScheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
    if ($sourceScheme !== 'http' && $sourceScheme !== 'https') {
        return false;
    }
    $sourceHost = parse_url($source, PHP_URL_HOST);
    $expectedHost = parse_url($config['email']['base_url'] ?? '', PHP_URL_HOST);
    if (empty($sourceHost) || empty($expectedHost)) {
        return false;
    }
    // www.<host> and the bare host are the same site (both resolve to this
    // server), so a visitor on either name must not see every POST refused.
    $strip = static fn(string $h): string => preg_replace('/^www\./', '', strtolower($h));
    return $strip((string) $sourceHost) === $strip((string) $expectedHost);
}

/**
 * Delete every stored email of one temp_emails row and their attachment rows.
 * Returns the absolute paths of the attachment files, which the caller must
 * unlink with unlinkAttachmentFiles() only AFTER its transaction commits.
 * Does not delete the temp_emails row itself.
 */
function deleteStoredEmailsForTempEmail(PDO $pdo, int $tempEmailId): array {
    $stmt = $pdo->prepare("SELECT ea.file_path FROM email_attachments ea JOIN stored_emails se ON se.id = ea.email_id WHERE se.temp_email_id = ?");
    $stmt->execute([$tempEmailId]);
    $filePaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $delAttachments = $pdo->prepare("DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM stored_emails WHERE temp_email_id = ?)");
    $delAttachments->execute([$tempEmailId]);

    $delStoredEmails = $pdo->prepare("DELETE FROM stored_emails WHERE temp_email_id = ?");
    $delStoredEmails->execute([$tempEmailId]);

    $paths = [];
    foreach ($filePaths as $filePath) {
        // basename() so a stored path can never point outside attachments/
        $paths[] = __DIR__ . '/attachments/' . basename($filePath);
    }
    return $paths;
}

/** Unlink attachment files collected by deleteStoredEmailsForTempEmail(). Never throws. */
function unlinkAttachmentFiles(array $paths): void {
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        if (!@unlink($path)) {
            logMessage('WARNING', 'Failed to delete attachment file', ['file' => basename($path)]);
        }
    }
}

/**
 * Hämta e-postmeddelanden för en specifik adress
 */
function getEmailsForAddress($address, $limit = 20) {
    global $pdo, $config;
    
    try {
        $fullAddress = $address . '@' . $config['email']['domain'];
            $stmt = $pdo->prepare("
                SELECT id, from_address, subject, body_text, body_html, received_at, expires_at
                FROM stored_emails 
                WHERE to_address = ? 
                AND (
                    (expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR))
                    OR (expires_at IS NOT NULL AND expires_at > NOW())
                )
                ORDER BY received_at DESC 
                LIMIT ?
            ");
        $stmt->execute([$fullAddress, $config['app']['cleanup_hours'], $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte hämta e-post: ' . $e->getMessage(), ['address' => $address]);
        return [];
    }
}

/**
 * Logga meddelanden till systemloggen
 */
/**
 * Hämta alla rader från hidden_log_types
 */
function getHiddenLogTypes() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, log_key, description, hidden, created_at, updated_at FROM hidden_log_types");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Kontrollera om en viss log_key är dold
 */
function isLogTypeHidden($logKey) {
    global $pdo;
    if (empty($logKey)) return false;
    try {
        $stmt = $pdo->prepare("SELECT hidden FROM hidden_log_types WHERE log_key = ? LIMIT 1");
        $stmt->execute([$logKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (bool)$row['hidden'] : false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Sätt eller uppdatera en log_key som dold/visad
 */
function setLogTypeHidden($logKey, $hidden = true, $description = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO hidden_log_types (log_key, description, hidden) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description), hidden = VALUES(hidden), updated_at = CURRENT_TIMESTAMP");
        return $stmt->execute([$logKey, $description, $hidden ? 1 : 0]);
    } catch (PDOException $e) {
        return false;
    }
}

function logMessage($level, $message, $context = null) {
    global $pdo, $config;
    
    // Bara logga om log-nivån matchar eller debug är på
    $logLevels = ['ERROR' => 1, 'WARNING' => 2, 'INFO' => 3, 'DEBUG' => 4];

    // Defensive: ensure $config['app'] is an array and contains expected keys
    $appConfig = [];
    if (isset($config['app']) && is_array($config['app'])) {
        $appConfig = $config['app'];
    }

    $configuredLevel = strtoupper($appConfig['log_level'] ?? 'info');
    $currentLevel = $logLevels[$configuredLevel] ?? 3;
    $messageLevel = $logLevels[strtoupper($level)] ?? 3;

    $debugMode = !empty($appConfig['debug_mode']);
    if ($messageLevel > $currentLevel && !$debugMode) {
        return;
    }
    // Beräkna en enkel log-nyckel baserat på meddelandetext (trimma och begränsa längd)
    $normalized = trim(preg_replace('/\s+/', ' ', strip_tags((string)$message)));
    $logKey = mb_substr($normalized, 0, 255);
    // Om denna typ är dold, hoppa över loggningen
    if ($logKey && isLogTypeHidden($logKey)) {
        return;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (log_level, message, context) VALUES (?, ?, ?)");
        $contextJson = $context ? json_encode($context) : null;
        $stmt->execute([$level, $message, $contextJson]);
    } catch (PDOException $e) {
        // Om loggning misslyckas, skriv till PHP error log som fallback
        error_log("TempMail Log Error: " . $e->getMessage());
        error_log("Original message: [$level] $message");
    }
}

/**
 * Uppdatera statistik
 */
function updateStat($statName, $increment = 1) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_stats (stat_name, stat_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE 
            stat_value = stat_value + VALUES(stat_value),
            last_updated = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$statName, $increment]);
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte uppdatera statistik: ' . $e->getMessage(), [
            'stat_name' => $statName,
            'increment' => $increment
        ]);
        return false;
    }
}

/**
 * Hämta statistik
 */
function getStats() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT stat_name, stat_value FROM email_stats");
        $stmt->execute();
        $stats = [];
        while ($row = $stmt->fetch()) {
            $stats[$row['stat_name']] = (int)$row['stat_value'];
        }
        return $stats;
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte hämta statistik: ' . $e->getMessage());
        return [
            'emails_processed' => 0,
            'emails_created' => 0,
            'total_users' => 0,
            'attachments_processed' => 0
        ];
    }
}

/**
 * One account's own numbers for the inbox footer (pro.php / inbox.php).
 *
 * Counted live from the rows themselves, not from email_stats: those counters
 * are system-wide (they are shown on the landing page) and only ever grow.
 * The scope is the one MailboxQuota uses — every address of the account,
 * personal and temporary — so "storage used" is the figure the quota is
 * enforced against. "Emails" applies the same visibility rule as get_emails in
 * index.php, so the count matches what the inbox can show.
 *
 * @return array{emails:int, received_24h:int, addresses:int, storage_bytes:int, quota_bytes:int}
 */
function getUserStats($userId) {
    global $pdo, $config;

    $userId = (int)$userId;
    $stats = [
        'emails' => 0,
        'received_24h' => 0,
        'addresses' => 0,
        'storage_bytes' => 0,
        'quota_bytes' => (int)($config['email']['quota_bytes'] ?? 104857600),
    ];
    if ($userId <= 0) {
        return $stats;
    }

    $scope = 'se.temp_email_id IN (SELECT id FROM temp_emails WHERE pro_user_id = ?)';

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN (se.expires_at IS NULL AND se.received_at > DATE_SUB(NOW(), INTERVAL ? HOUR))
                                    OR (se.expires_at IS NOT NULL AND se.expires_at > NOW()) THEN 1 ELSE 0 END), 0) AS emails,
                COALESCE(SUM(CASE WHEN se.received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END), 0) AS received_24h,
                COALESCE(SUM(COALESCE(LENGTH(se.subject), 0) + COALESCE(LENGTH(se.body_text), 0) + COALESCE(LENGTH(se.body_html), 0)), 0) AS body_bytes
             FROM stored_emails se
             WHERE $scope"
        );
        $stmt->execute([(int)($config['app']['cleanup_hours'] ?? 24), $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['emails'] = (int)($row['emails'] ?? 0);
        $stats['received_24h'] = (int)($row['received_24h'] ?? 0);

        $att = $pdo->prepare(
            "SELECT COALESCE(SUM(ea.file_size), 0) FROM email_attachments ea
             JOIN stored_emails se ON se.id = ea.email_id
             WHERE $scope"
        );
        $att->execute([$userId]);
        $stats['storage_bytes'] = (int)($row['body_bytes'] ?? 0) + (int)$att->fetchColumn();

        $addr = $pdo->prepare("SELECT COUNT(*) FROM temp_emails WHERE pro_user_id = ? AND expires_at > NOW()");
        $addr->execute([$userId]);
        $stats['addresses'] = (int)$addr->fetchColumn();
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte hämta användarstatistik: ' . $e->getMessage(), ['user_id' => $userId]);
    }

    return $stats;
}

// ---------------------------------------------------------------------
// Session cookie hardening (CSRF finding)
//
// config.php is required before any session_start() in the app, so the
// cookie parameters are fixed here, once: HttpOnly (no script access),
// SameSite=Lax (the cookie is not sent on cross-site POSTs, iframes or
// sub-resource requests) and Secure whenever the site is served over HTTPS
// — decided from base_url or the request itself, so local dev over plain
// http://localhost:8085 keeps working. Strict mode refuses session ids the
// server never issued (session fixation), and only cookies carry the id.
// Cross-site request forgery is additionally blocked per action by
// requireSameOriginRequest() (defined above); this block is defence in depth.
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $msSessionHttps = strcasecmp((string) parse_url((string) ($config['email']['base_url'] ?? ''), PHP_URL_SCHEME), 'https') === 0
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        // Lifetime and domain keep whatever the host configured, so this
        // does not change how long a login lasts or which host gets it.
        'lifetime' => (int) ini_get('session.cookie_lifetime'),
        'path' => '/',
        'domain' => (string) ini_get('session.cookie_domain'),
        'secure' => $msSessionHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($msSessionHttps);
}


// Sätt applikationskonstant för säkerhet
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}

// Logga applikationsstart i debug-läge (kan undertryckas av konsumenter genom att definiera TEMPMAIL_SUPPRESS_INIT_LOG)
if ($config['app']['debug_mode'] && !defined('TEMPMAIL_SUPPRESS_INIT_LOG')) {
    logMessage('DEBUG', 'TempMail application initialized', ['config_loaded' => true]);
}
?>