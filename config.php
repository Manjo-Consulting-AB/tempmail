<?php
/**
 * TempMail Configuration
 * Centraliserade inställningar för temporära e-postadresser
 */

// Förhindra direktåtkomst
defined('TEMPMAIL_APP') or define('TEMPMAIL_APP', true);

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

// Capture the detection source (set inside detectEnvironment)
$env_source = $GLOBALS['__env_detect_source'] ?? 'unknown';
// Log a concise line for diagnostics
error_log("TempMail Environment detected as: {$environment} (source: {$env_source})");

// Ladda miljövariabler för detekterad miljö
loadEnvironmentVariables($environment);

// Grundkonfiguration som delas mellan miljöer
$baseConfig = [
    'email' => [
        'domain' => $_ENV['EMAIL_DOMAIN'] ?? 'manjo.me',
        // Use primary domain for generated links in production (no subdomain)
        'base_url' => $_ENV['BASE_URL'] ?? ($environment === 'production' ? 'https://manjo.me/' : 'http://localhost:8085/')
    ],
    'app' => [
        'cleanup_hours' => 24,
        'address_length' => 12,
        'max_emails_per_address' => 50
    ],
    'cleanup' => [
        // Default retention; can be overridden with environment variable LOG_RETENTION_DAYS
        'log_retention_days' => (int) (getenv('LOG_RETENTION_DAYS') ?: ($_ENV['LOG_RETENTION_DAYS'] ?? 30))
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
    'bmac' => [
        // Buy Me a Coffee webhook secret for signature verification
        'webhook_secret' => $_ENV['BMAC_WEBHOOK_SECRET'] ?? null
    ]
];

// Miljöspecifika konfigurationer
$environmentConfigs = [
    'development' => [
        'db' => [
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'port' => (int)($_ENV['DB_PORT'] ?? 3306),
            'socket' => $_ENV['DB_SOCKET'] ?? null,
            'name' => $_ENV['DB_NAME'] ?? 'tempmail',
            'user' => $_ENV['DB_USER'] ?? 'tempmail_user',
            'password' => $_ENV['DB_PASSWORD'] ?? 'SecurePassword123!',
            'charset' => 'utf8mb4'
        ],
        'imap' => [
            'server' => $_ENV['IMAP_SERVER'] ?? '{imap.websupport.se:993/imap/ssl}INBOX',
            'user' => $_ENV['IMAP_USER'] ?? 'catch-all@manjo.me',
            'password' => $_ENV['IMAP_PASSWORD'] ?? '',
            'enabled' => $_ENV['IMAP_ENABLED'] ?? true
        ],
        'app' => [
            'debug_mode' => $_ENV['DEBUG_MODE'] ?? true,
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
            'enabled' => $_ENV['IMAP_ENABLED'] ?? true
        ],
        'app' => [
            'debug_mode' => $_ENV['DEBUG_MODE'] ?? false,
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
// If no explicit secret provided, derive a fallback from DB credentials+base_url (best-effort, not recommended for long-term)
if (empty($config['attachments']['download_secret'])) {
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
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . $dbCharset
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
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . ((string)($config['db']['charset'] ?? 'utf8mb4'))
            ]);

            $config['db']['host'] = '127.0.0.1';
        } catch (PDOException $e2) {
            $e = $e2;
        }
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
    if ($config['app']['debug_mode']) {
        die("Databasanslutning misslyckades i {$environment}-miljö: " . $e->getMessage() . 
            "<br>Host: {$config['db']['host']}, DB: {$config['db']['name']}, User: {$config['db']['user']}");
    } else {
        die('Databasfel uppstod. Försök igen senare.');
    }
    }
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

/**
 * Kontrollera om en IP-adress matchar ett CIDR-block.
 * 
 * @param string $ip IP-adress att kontrollera
 * @param string $cidr CIDR-notation (t.ex. "192.168.0.0/16")
 * @return bool True om IP:n är inom CIDR-blocket
 */
function ipMatchesCidr(string $ip, string $cidr): bool {
    // Hantera exakt matchning (ingen slash)
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    
    list($subnet, $mask) = explode('/', $cidr, 2);
    $mask = (int)$mask;
    
    // Avgör om det är IPv6 eller IPv4
    $isIpv6 = strpos($ip, ':') !== false;
    $isSubnetIpv6 = strpos($subnet, ':') !== false;
    
    // Blanda inte IPv4 och IPv6
    if ($isIpv6 !== $isSubnetIpv6) {
        return false;
    }
    
    if ($isIpv6) {
        // IPv6-hantering
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Skapa mask för IPv6 (128 bitar)
        $maskBin = str_repeat("\xff", (int)($mask / 8));
        if ($mask % 8 > 0) {
            $maskBin .= chr(256 - pow(2, 8 - ($mask % 8)));
        }
        $maskBin = str_pad($maskBin, 16, "\x00");
        
        return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
    } else {
        // IPv4-hantering
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}

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
    return false;
}

/**
 * Hämta besökarens riktiga IP-adress med stöd för proxys.
 * Hanterar både IPv4 och IPv6.
 * 
 * @return string IP-adress
 */
function getVisitorIp(): string {
    // Proxy-headers att kontrollera (i prioritetsordning)
    $proxyHeaders = [
        'HTTP_CF_CONNECTING_IP',    // Cloudflare
        'HTTP_X_REAL_IP',           // Nginx proxy
        'HTTP_X_FORWARDED_FOR',     // Standard proxy header
        'HTTP_X_CLIENT_IP',         // Annan proxy
        'HTTP_CLIENT_IP',           // Annan proxy
    ];
    
    foreach ($proxyHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For kan innehålla flera IP:er (klient, proxy1, proxy2...)
            // Ta första (klientens ursprungliga IP)
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            
            // Validera att det är en giltig IP-adress
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    // Fallback till REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
    
    // Minimalistiskt meddelande utan systeminformation
    echo '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Åtkomst nekad</title>
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
        <p>Åtkomst nekad av säkerhetsskäl.</p>
    </div>
</body>
</html>';
    
    // Terminera omedelbart
    exit();
}

/**
 * Flagga en IP-adress för misstänkt aktivitet.
 * Implementerar eskaleringslogik:
 * - 1 försök: Logga i ip_blacklist
 * - 1+ försök: Blockera i 24 timmar
 * - 5+ försök: Permanent blockering
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
    $TEMP_BLOCK_THRESHOLD = 1;    // Försök innan tillfällig blockering
    $PERMANENT_THRESHOLD = 5;      // Försök innan permanent blockering
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

/**
 * Generera en unik adressträng
 */
function generateUniqueString($length = null) {
    global $config;
    $length = $length ?: $config['app']['address_length'];
    return bin2hex(random_bytes($length / 2));
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
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM temp_emails 
            WHERE unique_address = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$address, $config['app']['cleanup_hours']]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        logMessage('ERROR', 'Kunde inte validera adress: ' . $e->getMessage(), ['address' => $address]);
        return false;
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
 * Rensa gamla data (anropas av cleanup.php)
 */
function cleanupOldData() {
    global $pdo, $config;
    
    try {
        $pdo->beginTransaction();
        
        // Radera gamla adresser
        $stmt = $pdo->prepare("DELETE FROM temp_emails WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
        $stmt->execute([$config['app']['cleanup_hours']]);
        $deletedAddresses = $stmt->rowCount();
        
        // Radera gamla e-postmeddelanden
            $stmt = $pdo->prepare("DELETE FROM stored_emails WHERE (expires_at IS NOT NULL AND expires_at < NOW()) OR (expires_at IS NULL AND received_at < DATE_SUB(NOW(), INTERVAL ? HOUR))");
        $stmt->execute([$config['app']['cleanup_hours']]);
        $deletedEmails = $stmt->rowCount();
        
        // Radera gamla loggar (behåll 7 dagar)
        $stmt = $pdo->prepare("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$config['cleanup']['log_retention_days']]);
        $deletedLogs = $stmt->rowCount();
        
        $pdo->commit();
        
        logMessage('INFO', 'Cleanup completed successfully', [
            'deleted_addresses' => $deletedAddresses,
            'deleted_emails' => $deletedEmails, 
            'deleted_logs' => $deletedLogs
        ]);
        
        return true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        logMessage('ERROR', 'Cleanup failed: ' . $e->getMessage());
        return false;
    }
}

// Sätt timezone
date_default_timezone_set('Europe/Stockholm');

// Sätt applikationskonstant för säkerhet
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}

// Logga applikationsstart i debug-läge (kan undertryckas av konsumenter genom att definiera TEMPMAIL_SUPPRESS_INIT_LOG)
if ($config['app']['debug_mode'] && !defined('TEMPMAIL_SUPPRESS_INIT_LOG')) {
    logMessage('DEBUG', 'TempMail application initialized', ['config_loaded' => true]);
}
?>