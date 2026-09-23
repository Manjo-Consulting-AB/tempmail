<?php
/**
 * Simple endpoint to get/update pro user profile/settings (JSON)
 */
require_once 'config.php';
require_once __DIR__ . '/client/backend/bootstrap.php';
require_once __DIR__ . '/TwoFactorAuth.php';
// Pulls in redeemVoucherForEmail() for the upgrade_with_voucher action below.
// pro_auth.php guards its actual HTTP endpoints behind
// `if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)):` — since
// the entry script for this request is pro_profile.php, not pro_auth.php,
// that condition is false here and none of pro_auth.php's endpoint code
// (including its session_start()/header() calls) runs. Only its top-level
// function definitions are loaded, which is exactly what we need.
require_once __DIR__ . '/pro_auth.php';
session_start();

header('Content-Type: application/json');

// Start output buffering so accidental warnings/echoes don't break JSON responses
ob_start();

function send_json($data) {
    // Clear any buffered output to avoid invalid JSON
    $buf = @ob_get_clean();
    if (!empty($buf)) {
        // Log the unexpected output for debugging
        logMessage('WARNING', 'Unexpected output while generating JSON response', ['output' => substr($buf,0,2000)]);
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    // Ensure script ends after sending JSON
    exit;
}

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['pro_user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper: encrypt/decrypt webhook secret using environment key WEBHOOKS_KEY
function encrypt_webhook_secret($plaintext) {
    if (empty($plaintext)) return null;
    $key = $_ENV['WEBHOOKS_KEY'] ?? null;
    if (empty($key)) {
        // No key configured — store plaintext. This is a real misconfiguration
        // (webhook secrets should be encrypted at rest), but rejecting webhook
        // creation outright would be a bigger functional regression than
        // logging loudly, since we can't verify WEBHOOKS_KEY is meant to be set
        // in every deployment. Logged at ERROR so it's actionable in log_viewer.php.
        logMessage('ERROR', 'WEBHOOKS_KEY not set; storing webhook secret in plaintext');
        return $plaintext;
    }
    $method = 'AES-256-CBC';
    $ivlen = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $cipher = openssl_encrypt($plaintext, $method, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return null;
    return base64_encode($iv . $cipher);
}

function decrypt_webhook_secret($encoded) {
    if (empty($encoded)) return null;
    $key = $_ENV['WEBHOOKS_KEY'] ?? null;
    if (empty($key)) {
        // No key configured — assume stored plaintext
        return $encoded;
    }
    $method = 'AES-256-CBC';
    $raw = base64_decode($encoded);
    $ivlen = openssl_cipher_iv_length($method);
    $iv = substr($raw, 0, $ivlen);
    $ciphertext = substr($raw, $ivlen);
    $plain = openssl_decrypt($ciphertext, $method, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
}

// Helper: current password_hash for a pro user, or null if unset/column missing.
// Several profile-security actions (password change, 2FA enroll/disable) need
// this same "does a password exist" check.
function pro_user_password_hash(int $userId): ?string {
    global $pdo;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM pro_users LIKE 'password_hash'");
        if ($colStmt->rowCount() === 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT password_hash FROM pro_users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        return !empty($hash) ? $hash : null;
    } catch (Exception $e) {
        return null;
    }
}

function pro_user_has_password(int $userId): bool {
    return pro_user_password_hash($userId) !== null;
}

// Pro-gating: används av de actions som kräver aktivt Pro-konto.
// Se documentaion/ACCOUNT_TIERS.md §3.
function require_pro(int $userId): void {
    if (!proUserIsPro($userId)) {
        logMessage('INFO', 'Pro-only action refused for non-pro account', ['user_id' => $userId]);
        send_json(['success' => false, 'error' => 'Pro required', 'pro_required' => true]);
    }
}

// Is the per-hook address routing schema in place? (epic #251)
//
// True only when all three objects migrate_webhook_addresses.php adds exist, so
// a half-migrated database reads as "not routed" and the actions below keep
// their pre-routing shape — the same rule ImapProcessor::hookRoutingAvailable()
// applies on the dispatch side. Cached: the answer cannot change while one
// process is running. function_exists-guarded so a repeated require cannot
// redeclare it.
if (!function_exists('proWebhookRoutingAvailable')) {
    function proWebhookRoutingAvailable(): bool {
        static $available = null;
        if ($available === null) {
            $available = tableHasColumn('pro_webhook_addresses', 'webhook_id')
                && tableHasColumn('pro_webhooks', 'include_temporary')
                && tableHasColumn('temp_emails', 'hooks_paused');
        }
        return $available;
    }
}

// Helper: notification email for 2FA activation/deactivation. Never includes
// the secret or any code — see documentaion/2FA_DESIGN.md §3 and §5.5.
function send_2fa_notification_email(int $userId, string $event): void {
    global $pdo, $config;
    try {
        $stmt = $pdo->prepare("SELECT email FROM pro_users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        if (!$email) {
            return;
        }

        if ($event === 'enabled') {
            $subject = 'Two-factor authentication enabled for Mail Shield';
            $message = "Hello,\n\nTwo-factor authentication was just enabled on your Mail Shield account. Signing in with your password will now also require a code from your authenticator app.\n\nIf you did not make this change, sign in using your magic link and disable two-factor authentication immediately.\n\nRegards,\nThe Mail Shield Team";
        } else {
            $subject = 'Two-factor authentication disabled for Mail Shield';
            $message = "Hello,\n\nTwo-factor authentication was just disabled on your Mail Shield account. Signing in with your password no longer requires a code.\n\nIf you did not make this change, sign in using your magic link, re-enable two-factor authentication and change your password.\n\nRegards,\nThe Mail Shield Team";
        }

        $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
        $headers = [];
        $headers[] = 'From: Mail Shield <' . $from . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headersStr = implode("\r\n", $headers);
        @mail($email, $subject, $message, $headersStr);
    } catch (Exception $e) {
        logMessage('WARNING', 'Failed sending 2FA notification email', ['error' => $e->getMessage(), 'user_id' => $userId, 'event' => $event]);
    }
}

try {
    switch ($action) {
        case 'get_ttl':
            $stmt = $pdo->prepare("SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $ttl = $row['ttl_days'] ?? 1;
            send_json(['success' => true, 'ttl_days' => (int)$ttl]);
            break;

        case 'get_profile':
            // Return basic pro user profile (email and whether password is set)
            $stmt = $pdo->prepare("SELECT email, pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                    send_json(['success' => false, 'error' => 'User not found']);
            }
            $profile = [
                'email' => $row['email'] ?? '',
                'pro_expires_at' => $row['pro_expires_at'] ?? null,
                'account_type' => proUserAccountType($userId),
                'is_pro' => proUserIsPro($userId)
            ];
            // Check if password_hash column exists and is set
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM pro_users LIKE 'password_hash'");
                if ($colStmt->rowCount() > 0) {
                    $pstmt = $pdo->prepare("SELECT password_hash IS NOT NULL AND password_hash != '' AS has_password FROM pro_users WHERE id = ? LIMIT 1");
                    $pstmt->execute([$userId]);
                    $pr = $pstmt->fetch(PDO::FETCH_ASSOC);
                    $profile['has_password'] = !empty($pr['has_password']);
                } else {
                    $profile['has_password'] = false;
                }
            } catch (Exception $e) {
                $profile['has_password'] = false;
            }
            send_json(['success' => true, 'profile' => $profile]);
            break;

        case 'get_digest_settings':
            $stmt = $pdo->prepare("SELECT digest_enabled, COALESCE(digest_frequency_days,1) AS digest_frequency_days, COALESCE(address_ttl_days,1) AS address_ttl_days, digest_last_sent, digest_hour, digest_tz FROM pro_users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                send_json(['success' => false, 'error' => 'User not found']);
            }
            // Determine max allowed frequency (address_ttl_days - 1), ensure at least 1
            $addressTtl = (int)($row['address_ttl_days'] ?? 1);
            $maxFreq = max(1, max(1, $addressTtl - 1));
            $res = [
                'success' => true,
                'digest_enabled' => (int)$row['digest_enabled'],
                'digest_frequency_days' => (int)$row['digest_frequency_days'],
                'address_ttl_days' => $addressTtl,
                'max_allowed_frequency_days' => $maxFreq,
                'digest_last_sent' => $row['digest_last_sent'] ?? null,
                'digest_hour' => isset($row['digest_hour']) ? (is_null($row['digest_hour']) ? null : (int)$row['digest_hour']) : null,
                'digest_tz' => $row['digest_tz'] ?? null,
                'pro_required' => !proUserIsPro($userId)
            ];
            send_json($res);
            break;

        case 'update_digest_settings':
            require_pro($userId);
            // Expects: digest_enabled (0/1), digest_frequency_days (int), optional digest_hour (0-23), digest_tz (IANA)
            $enabled = isset($_POST['digest_enabled']) ? (int)$_POST['digest_enabled'] : 0;
            $freq = isset($_POST['digest_frequency_days']) ? (int)$_POST['digest_frequency_days'] : 1;
            $hour = array_key_exists('digest_hour', $_POST) ? ($_POST['digest_hour'] === '' ? null : (int)$_POST['digest_hour']) : null;
            $tz = isset($_POST['digest_tz']) ? trim($_POST['digest_tz']) : null;

            // Fetch address_ttl_days to validate
            $s = $pdo->prepare("SELECT COALESCE(address_ttl_days,1) AS address_ttl_days FROM pro_users WHERE id = ? LIMIT 1");
            $s->execute([$userId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $addressTtl = (int)($r['address_ttl_days'] ?? 1);
            $maxFreq = max(1, $addressTtl - 1);

            if ($enabled && $addressTtl <= 1) {
                send_json(['success' => false, 'error' => 'Digests are not allowed when address TTL is 1 day']);
            }

            if ($freq < 1 || $freq > $maxFreq) {
                send_json(['success' => false, 'error' => 'Invalid frequency selection', 'max_allowed' => $maxFreq]);
            }

            // Validate hour if provided
            if (!is_null($hour)) {
                if ($hour < 0 || $hour > 23) {
                    send_json(['success' => false, 'error' => 'Invalid digest hour']);
                }
            }

            // Validate timezone if provided (must be a valid identifier)
            if ($tz !== null && $tz !== '') {
                $validTz = in_array($tz, timezone_identifiers_list(), true);
                if (!$validTz) {
                    send_json(['success' => false, 'error' => 'Invalid timezone']);
                }
            } else {
                $tz = null; // normalize empty to null
            }

            // Build update statement dynamically to avoid overwriting fields unintentionally
            $updates = ['digest_enabled = ?', 'digest_frequency_days = ?'];
            $params = [$enabled, $freq];
            if (!is_null($hour)) {
                $updates[] = 'digest_hour = ?';
                $params[] = $hour;
            }
            if ($tz !== null) {
                $updates[] = 'digest_tz = ?';
                $params[] = $tz;
            }
            $params[] = $userId;
            $sql = "UPDATE pro_users SET " . implode(', ', $updates) . " WHERE id = ?";
            $u = $pdo->prepare($sql);
            $u->execute($params);
            logMessage('INFO', 'Pro user updated digest settings', ['user_id' => $userId, 'enabled' => $enabled, 'freq' => $freq, 'hour' => $hour, 'tz' => $tz]);
            send_json(['success' => true, 'digest_enabled' => $enabled, 'digest_frequency_days' => $freq, 'digest_hour' => $hour, 'digest_tz' => $tz]);
            break;

        case 'update_email':
            // Since account-recovery-relevant and doesn't require the current
            // password (many accounts here are magic-link-only, with no password
            // set at all), this is a high-value CSRF target - the confirmation
            // link goes to the attacker-supplied new address, so a forged request
            // combined with the attacker clicking their own emailed link would be
            // a full account takeover.
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin update_email request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            $rawEmail = $_POST['email'] ?? '';
            // Detect suspicious patterns
            $suspicious = detectSuspiciousPatterns((string)$rawEmail);
            if (!empty($suspicious)) {
                logMessage('WARNING', 'Suspicious email update attempt', ['patterns' => $suspicious, 'user_id' => $userId]);
                // Flagga IP för blockering
                flagMaliciousActivity(getVisitorIp(), 'Suspicious email update: ' . implode(', ', $suspicious)); // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
                send_json(['success' => false, 'error' => 'Invalid request']);
            }
            $newEmail = sanitizeEmail($rawEmail);
            if (!$newEmail) {
                send_json(['success' => false, 'error' => 'Invalid email']);
            }
            // Disallow using the service's own email domain for account addresses
            $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
            $parts = explode('@', $newEmail);
            $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
            if ($domainPart === $forbiddenDomain) {
                send_json(['success' => false, 'error' => 'Using ' . $forbiddenDomain . ' addresses for accounts is not allowed']);
            }
            // Ensure no other pro_user uses this email
            $stmt = $pdo->prepare("SELECT id FROM pro_users WHERE email = ? AND id <> ? LIMIT 1");
            $stmt->execute([$newEmail, $userId]);
            if ($stmt->fetch()) {
                send_json(['success' => false, 'error' => 'Email already in use']);
            }
            // Create pending change and send confirmation link to the NEW email address
            try {
                $token = bin2hex(random_bytes(24));
                $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
                $data = json_encode(['new_email' => $newEmail]);
                $ins = $pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, 'update_email', ?, ?, ?)");
                $ins->execute([$userId, $data, $token, $expires]);

                // Send confirmation email to the new email address
                $confirmUrl = ($config['email']['base_url'] ?? '') . "pro_auth.php?confirm_profile_change=" . urlencode($token);
                $subject = 'Confirm your email change for Mail Shield';
                $message = "Hello,\n\nA request was made to change the email for your Mail Shield account to this address.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email.\n\nRegards,\nThe Mail Shield Team";
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: Mail Shield <' . $from . '>';
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headersStr = implode("\r\n", $headers);
                @mail($newEmail, $subject, $message, $headersStr);

                logMessage('INFO', 'Pending email change created', ['user_id' => $userId, 'new_email' => $newEmail]);
                send_json(['success' => true, 'message' => 'Confirmation link sent to new email address']);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed creating pending email change', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not create confirmation request']);
            }
            break;

        case 'set_password':
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm'] ?? '';

            if (empty($password) || $password !== $confirm) {
                send_json(['success' => false, 'error' => 'Passwords do not match']);
            }
            if (strlen($password) < 8) {
                send_json(['success' => false, 'error' => 'Password must be at least 8 characters']);
            }
            if (strlen($password) > 256) {
                send_json(['success' => false, 'error' => 'Password too long (max 256 characters)']);
            }

            // Ensure password_hash column exists
            $colStmt = $pdo->query("SHOW COLUMNS FROM pro_users LIKE 'password_hash'");
            if ($colStmt->rowCount() === 0) {
                send_json(['success' => false, 'error' => 'Password storage not available on this system']);
            }

            // Create pending change and send confirmation link to the current email address
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(24));
                $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
                $data = json_encode(['password_hash' => $hash]);
                $ins = $pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, 'set_password', ?, ?, ?)");
                $ins->execute([$userId, $data, $token, $expires]);

                // Send confirmation email to current user email
                $confirmUrl = ($config['email']['base_url'] ?? '') . "pro_auth.php?confirm_profile_change=" . urlencode($token);
                $to = $_SESSION['pro_user_email'] ?? '';
                $subject = 'Confirm your password change for Mail Shield';
                $message = "Hello,\n\nA request was made to change the password for your Mail Shield account.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email or contact support.\n\nRegards,\nThe Mail Shield Team";
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: Mail Shield <' . $from . '>';
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headersStr = implode("\r\n", $headers);
                @mail($to, $subject, $message, $headersStr);

                logMessage('INFO', 'Pending password change created', ['user_id' => $userId]);
                send_json(['success' => true, 'message' => 'Confirmation link sent to your email address']);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed creating pending password change', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not create confirmation request']);
            }
            break;

        case 'rotate_signing_keys':
            // Executes immediately with no confirmation step at all - a forged
            // cross-site request would silently invalidate the victim's client-agent
            // signing keys, breaking their configured mail filtering (CSRF-triggered DoS).
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin rotate_signing_keys request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            require_pro($userId);
            try {
                $keys = clientBackendRotateUserSigningKeys($userId);
                if (!$keys) {
                    send_json(['success' => false, 'error' => 'Could not rotate keys']);
                }

                logMessage('INFO', 'Rotated client signing keys', ['user_id' => $userId, 'key_id' => $keys['key_id'] ?? null]);
                send_json(['success' => true, 'message' => 'Signing keys rotated', 'key_id' => $keys['key_id'] ?? null]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed rotating client signing keys', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not rotate keys']);
            }
            break;

        case 'upgrade_with_voucher':
            // Deliberately NOT gated by require_pro() — this action exists so a
            // logged-in Regular account can become Pro. It also works on an
            // already-Pro account, where redeemVoucherForEmail() just extends
            // pro_expires_at (existing, wanted behavior — see #58).
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin upgrade_with_voucher request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                // Email must come from the session's own account, never from
                // POST — otherwise a logged-in attacker could redeem a code
                // against an arbitrary email address instead of their own.
                $stmt = $pdo->prepare("SELECT email FROM pro_users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $email = $stmt->fetchColumn();
                if (!$email) {
                    send_json(['success' => false, 'error' => 'User not found']);
                }

                $code = trim((string) ($_POST['code'] ?? ''));
                $result = redeemVoucherForEmail($email, $code);

                if (!$result['success']) {
                    send_json(['success' => false, 'error' => voucherRedemptionErrorMessage($result['error_code'])]);
                }

                $s = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
                $s->execute([$userId]);
                $expiresAt = $s->fetchColumn();

                logMessage('INFO', 'Regular account upgraded to Pro via voucher', ['user_id' => $userId]);
                send_json(['success' => true, 'account_type' => 'pro', 'pro_expires_at' => $expiresAt !== false ? $expiresAt : null]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed upgrading account via voucher', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not redeem voucher']);
            }
            break;

        case 'totp_status':
            try {
                $state = TwoFactorAuth::getState($userId);
                $enabled = TwoFactorAuth::isEnabledFor($userId);
                $pending = $state !== null && $state['status'] === 'pending';

                send_json([
                    'success' => true,
                    'enabled' => $enabled,
                    'pending' => $pending,
                    'recovery_codes_left' => $enabled ? TwoFactorAuth::countUnusedRecoveryCodes($userId) : 0,
                    'confirmed_at' => $state['confirmed_at'] ?? null,
                    'has_password' => pro_user_has_password($userId),
                ]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed fetching 2FA status', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not fetch two-factor status']);
            }
            break;

        case 'totp_begin_enroll':
            // Starts/replaces a pending enrollment. Behind requireSameOriginRequest()
            // like every other state-changing action here, plus a per-user hourly cap
            // so a stolen session can't be used to spam pending secrets/QR renders.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(['success' => false, 'error' => 'Method not allowed']);
            }
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin totp_begin_enroll request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                if (TwoFactorAuth::isEnabledFor($userId)) {
                    send_json(['success' => false, 'error' => 'Two-factor authentication is already enabled. Disable it first to re-enroll.']);
                }

                $pdo->exec("CREATE TABLE IF NOT EXISTS two_factor_enroll_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_time (user_id, requested_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $windowMinutes = 60;
                $maxRequests = 10;
                $rl = $pdo->prepare("SELECT COUNT(*) FROM two_factor_enroll_requests WHERE user_id = ? AND requested_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $rl->execute([$userId, $windowMinutes]);
                if ((int) $rl->fetchColumn() >= $maxRequests) {
                    logMessage('WARNING', '2fa_enroll_rate_limited', ['user_id' => $userId]);
                    send_json(['success' => false, 'error' => 'Too many enrollment attempts. Please try again later.']);
                }
                $pdo->prepare("INSERT INTO two_factor_enroll_requests (user_id) VALUES (?)")->execute([$userId]);

                $enrollment = TwoFactorAuth::startEnrollment($userId);
                $otpauthUri = $enrollment['otpauth_uri'];

                // Group the secret into 4-character blocks for manual entry, e.g. "ABCD EFGH ...".
                $manualKey = trim(chunk_split($enrollment['secret'], 4, ' '));

                send_json([
                    'success' => true,
                    'otpauth_uri' => $otpauthUri,
                    'qr_svg' => TwoFactorAuth::renderQrSvg($otpauthUri),
                    'manual_key' => $manualKey,
                    // 2FA can still be enrolled without a password (it protects password
                    // login specifically); the UI uses this to explain the code has no
                    // effect until a password is set.
                    'has_password' => pro_user_has_password($userId),
                ]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed starting 2FA enrollment', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not start two-factor enrollment']);
            }
            break;

        case 'totp_confirm_enroll':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(['success' => false, 'error' => 'Method not allowed']);
            }
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin totp_confirm_enroll request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                $code = trim((string) ($_POST['code'] ?? ''));
                $ip = getVisitorIp();

                if (TwoFactorAuth::isChallengeBlocked($userId, $ip)) {
                    send_json(['success' => false, 'error' => 'Too many attempts. Please try again later.']);
                }

                $activated = TwoFactorAuth::activate($userId, $code);
                TwoFactorAuth::recordAttempt($userId, $ip, $activated);

                if (!$activated) {
                    // Generic error: never reveal whether it was the code, a missing
                    // pending enrollment, or an expired step that failed.
                    send_json(['success' => false, 'error' => 'Invalid or expired code']);
                }

                $recoveryCodes = TwoFactorAuth::generateRecoveryCodes($userId);
                send_2fa_notification_email($userId, 'enabled');

                send_json(['success' => true, 'recovery_codes' => $recoveryCodes]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed confirming 2FA enrollment', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not confirm two-factor enrollment']);
            }
            break;

        case 'totp_disable':
            // Works the same whether the session came from a password login or a
            // magic link — magic link is the recovery path for a lost authenticator.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(['success' => false, 'error' => 'Method not allowed']);
            }
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin totp_disable request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                $storedHash = pro_user_password_hash($userId);
                if ($storedHash !== null) {
                    $password = (string) ($_POST['password'] ?? '');
                    if ($password === '' || !password_verify($password, $storedHash)) {
                        logMessage('WARNING', 'Rejected totp_disable with invalid password', ['user_id' => $userId]);
                        send_json(['success' => false, 'error' => 'Invalid password']);
                    }
                }
                // No password set: there is no password login to protect, so an
                // authenticated session alone is enough to disable.

                if (!TwoFactorAuth::isEnabledFor($userId) && TwoFactorAuth::getState($userId) === null) {
                    send_json(['success' => false, 'error' => 'Two-factor authentication is not enabled']);
                }

                TwoFactorAuth::disable($userId);
                send_2fa_notification_email($userId, 'disabled');
                // See issue #15: track how this session was authenticated (password
                // vs. magic link) so the lost-authenticator recovery path is visible
                // in log_viewer.php, not just that a disable happened.
                logMessage('INFO', '2fa_disabled', [
                    'user_id' => $userId,
                    'login_method' => $_SESSION['pro_login_method'] ?? 'unknown',
                ]);

                send_json(['success' => true]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed disabling 2FA', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not disable two-factor authentication']);
            }
            break;

        case 'totp_recovery_regenerate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(['success' => false, 'error' => 'Method not allowed']);
            }
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin totp_recovery_regenerate request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                if (!TwoFactorAuth::isEnabledFor($userId)) {
                    send_json(['success' => false, 'error' => 'Two-factor authentication is not enabled']);
                }

                $code = trim((string) ($_POST['code'] ?? ''));
                $ip = getVisitorIp();

                if (TwoFactorAuth::isChallengeBlocked($userId, $ip)) {
                    send_json(['success' => false, 'error' => 'Too many attempts. Please try again later.']);
                }

                $valid = TwoFactorAuth::verifyForUser($userId, $code);
                TwoFactorAuth::recordAttempt($userId, $ip, $valid);

                if (!$valid) {
                    send_json(['success' => false, 'error' => 'Invalid or expired code']);
                }

                $recoveryCodes = TwoFactorAuth::generateRecoveryCodes($userId);
                // Regenerating codes is a security-sensitive event similar to a
                // password change — revoke trusted devices per the design doc
                // so a compromised device can't keep skipping the challenge.
                TwoFactorAuth::revokeAllTrustedDevices($userId);
                send_json(['success' => true, 'recovery_codes' => $recoveryCodes]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed regenerating 2FA recovery codes', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not regenerate recovery codes']);
            }
            break;

        case 'trusted_devices_list':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(['success' => false, 'error' => 'Method not allowed']);
            }
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin trusted_devices_list request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                $devices = TwoFactorAuth::listTrustedDevices($userId);
                send_json(['success' => true, 'devices' => array_map(function ($d) {
                    return [
                        'id' => (int) $d['id'],
                        'label' => $d['label'],
                        'created_at' => $d['created_at'],
                        'last_used_at' => $d['last_used_at'],
                        'expires_at' => $d['expires_at'],
                    ];
                }, $devices)]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed listing trusted devices', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not list trusted devices']);
            }
            break;

        case 'trusted_devices_revoke':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(['success' => false, 'error' => 'Method not allowed']);
            }
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin trusted_devices_revoke request', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            try {
                if (!empty($_POST['all'])) {
                    TwoFactorAuth::revokeAllTrustedDevices($userId);
                    send_json(['success' => true]);
                }

                $deviceId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                if ($deviceId <= 0) {
                    send_json(['success' => false, 'error' => 'Invalid device id']);
                }
                $revoked = TwoFactorAuth::revokeTrustedDevice($userId, $deviceId);
                if (!$revoked) {
                    send_json(['success' => false, 'error' => 'Device not found']);
                }
                send_json(['success' => true]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed revoking trusted device', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not revoke device']);
            }
            break;

        case 'resend_pending_change':
            $pid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($pid <= 0) {
                send_json(['success' => false, 'error' => 'Invalid id']);
            }
            try {
                $stmt = $pdo->prepare("SELECT id, user_id, action, data, used FROM pending_profile_changes WHERE id = ? LIMIT 1");
                $stmt->execute([$pid]);
                $pc = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pc || (int)$pc['user_id'] !== $userId) {
                    send_json(['success' => false, 'error' => 'Pending change not found']);
                }
                if ((int)$pc['used'] === 1) {
                    send_json(['success' => false, 'error' => 'Pending change already processed']);
                }

                $token = bin2hex(random_bytes(24));
                $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
                $update = $pdo->prepare("UPDATE pending_profile_changes SET token = ?, expires_at = ? WHERE id = ?");
                $update->execute([$token, $expires, $pid]);

                $action = $pc['action'];
                $data = json_decode($pc['data'], true) ?: [];
                $confirmUrl = ($config['email']['base_url'] ?? '') . "pro_auth.php?confirm_profile_change=" . urlencode($token);
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: Mail Shield <' . $from . '>';
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headersStr = implode("\r\n", $headers);

                if ($action === 'update_email') {
                    $to = $data['new_email'] ?? '';
                    if (!$to) {
                        send_json(['success' => false, 'error' => 'Pending email address missing']);
                    }
                    $subject = 'Confirm your email change for Mail Shield';
                    $message = "Hello,\n\nThis is a resend of the confirmation link for your Mail Shield email change request.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email.\n\nRegards,\nThe Mail Shield Team";
                    @mail($to, $subject, $message, $headersStr);
                    send_json(['success' => true, 'message' => 'Confirmation link resent to the new email address']);
                }

                if ($action === 'set_password') {
                    $to = $_SESSION['pro_user_email'] ?? '';
                    if (!$to) {
                        send_json(['success' => false, 'error' => 'User email not available']);
                    }
                    $subject = 'Confirm your password change for Mail Shield';
                    $message = "Hello,\n\nThis is a resend of the confirmation link for your Mail Shield password change request.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email or contact support.\n\nRegards,\nThe Mail Shield Team";
                    @mail($to, $subject, $message, $headersStr);
                    send_json(['success' => true, 'message' => 'Confirmation link resent to your email address']);
                }

                if ($action === 'delete_account') {
                    $to = $_SESSION['pro_user_email'] ?? '';
                    if (!$to) {
                        send_json(['success' => false, 'error' => 'User email not available']);
                    }
                    $subject = 'Confirm your account deletion for Mail Shield';
                    $message = "Hello,\n\nThis is a resend of the confirmation link for your Mail Shield account deletion request.\n\nPlease confirm the deletion by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email or contact support.\n\nRegards,\nThe Mail Shield Team";
                    @mail($to, $subject, $message, $headersStr);
                    send_json(['success' => true, 'message' => 'Confirmation link resent to your email address']);
                }

                send_json(['success' => false, 'error' => 'Unsupported pending change type']);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed resending pending profile confirmation', ['error' => $e->getMessage(), 'user_id' => $userId, 'pending_id' => $pid]);
                send_json(['success' => false, 'error' => 'Could not resend confirmation email']);
            }
            break;

            case 'cancel_pending_change':
                $pid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                if ($pid <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid id']);
                    exit;
                }
                try {
                    $s = $pdo->prepare("SELECT id, user_id, used FROM pending_profile_changes WHERE id = ? LIMIT 1");
                    $s->execute([$pid]);
                    $p = $s->fetch(PDO::FETCH_ASSOC);
                    if (!$p || (int)$p['user_id'] !== $userId) {
                        echo json_encode(['success' => false, 'error' => 'Not found']);
                        exit;
                    }
                    if ((int)$p['used'] === 1) {
                        echo json_encode(['success' => false, 'error' => 'Already processed']);
                        exit;
                    }
                    $u = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ?");
                    $u->execute([$pid]);
                    logMessage('INFO', 'Pending profile change cancelled', ['user_id' => $userId, 'pending_id' => $pid]);
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    logMessage('ERROR', 'Failed cancelling pending change', ['error' => $e->getMessage(), 'user_id' => $userId]);
                    echo json_encode(['success' => false, 'error' => 'Could not cancel pending change']);
                }
                break;

        case 'update_ttl':
            require_pro($userId);
            $ttl = (int)($_POST['ttl'] ?? 0);
            if ($ttl < 1 || $ttl > 7) {
                echo json_encode(['success' => false, 'error' => 'TTL must be between 1 and 7']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE pro_users SET address_ttl_days = ? WHERE id = ?");
            $stmt->execute([$ttl, $userId]);
            logMessage('INFO', 'Pro user updated TTL', ['user_id' => $userId, 'ttl' => $ttl]);

            // If caller provided a current address, update its expires_at as well
            $address = trim($_POST['address'] ?? '');
            $updatedExpires = null;
            if ($address) {
                // Expect full address like unique@domain, extract local part
                $parts = explode('@', $address);
                if (count($parts) >= 1) {
                    $unique = $parts[0];
                    // Basic validation: allow common address characters
                    if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $unique)) {
                        try {
                            // Determine which column holds the local part: prefer unique_address, fallback to address
                            $col = 'unique_address';
                            $colStmt = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'unique_address'");
                            if ($colStmt->rowCount() === 0) {
                                // Try 'address' column
                                $colStmt2 = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'address'");
                                if ($colStmt2->rowCount() > 0) {
                                    $col = 'address';
                                }
                            }

                            // Update expires_at to created_at + ttl days
                            $uStmt = $pdo->prepare("UPDATE temp_emails SET expires_at = DATE_ADD(created_at, INTERVAL ? DAY) WHERE $col = ?");
                            $uStmt->execute([$ttl, $unique]);
                            if ($uStmt->rowCount() > 0) {
                                // Fetch the new expires_at to return to client
                                $s = $pdo->prepare("SELECT expires_at FROM temp_emails WHERE $col = ? LIMIT 1");
                                $s->execute([$unique]);
                                $r = $s->fetch(PDO::FETCH_ASSOC);
                                if ($r && isset($r['expires_at'])) {
                                    $updatedExpires = $r['expires_at'];
                                    logMessage('INFO', 'Updated address expires_at for pro user', ['user_id' => $userId, 'address' => $unique, 'expires_at' => $updatedExpires]);
                                }
                            }
                        } catch (Exception $e) {
                            logMessage('ERROR', 'Failed updating temp_emails expires_at', ['error' => $e->getMessage(), 'address' => $unique]);
                        }
                    }
                }
            }
            $response = ['success' => true, 'ttl_days' => $ttl];
            if ($updatedExpires) {
                $response['expires_at'] = $updatedExpires;
            }
            echo json_encode($response); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
            break;

        case 'webhooks_list':
            try {
                // The routing columns only exist once migrate_webhook_addresses.php
                // has run, so they are selected conditionally rather than assumed.
                $routing = proWebhookRoutingAvailable();
                $routingCol = $routing ? ', include_temporary' : '';
                $s = $pdo->prepare("SELECT id, name, url, kind, config, secret, filter_mode, created_at{$routingCol} FROM pro_webhooks WHERE user_id = ? ORDER BY id DESC");
                $s->execute([$userId]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);

                // Every hook's address links in one extra query (#251 step 4),
                // not one query per hook. The JOINs re-check the ownership the
                // link table cannot: only links pointing at one of this user's
                // own personal addresses are ever returned, so a stale row or a
                // hand-written one cannot surface someone else's address id.
                $linksByHook = [];
                if ($routing) {
                    $ls = $pdo->prepare("SELECT l.webhook_id, l.temp_email_id FROM pro_webhook_addresses l
                        JOIN pro_webhooks w ON w.id = l.webhook_id
                        JOIN temp_emails t ON t.id = l.temp_email_id AND t.pro_user_id = w.user_id AND t.is_personal = 1
                        WHERE w.user_id = ?");
                    $ls->execute([$userId]);
                    foreach ($ls->fetchAll(PDO::FETCH_NUM) as $link) {
                        $linksByHook[(int)$link[0]][] = (int)$link[1];
                    }
                }

                // Decode config JSON for response
                foreach ($rows as &$r) {
                    $r['config'] = $r['config'] ? json_decode($r['config'], true) : null;
                    unset($r['secret']); // Do not expose secret in list
                    // Absent (pre-migration) reads as off / unlinked.
                    $r['include_temporary'] = $routing && (int)($r['include_temporary'] ?? 0) === 1;
                    $addressIds = $linksByHook[(int)$r['id']] ?? [];
                    sort($addressIds);
                    $r['address_ids'] = $addressIds;
                }
                echo json_encode(['success' => true, 'routing_available' => $routing, 'webhooks' => $rows]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed listing webhooks', ['user_id' => $userId, 'error' => $e->getMessage()]);
                echo json_encode(['success' => false, 'error' => 'Could not fetch webhooks']);
            }
            break;

        // Replace one hook's whole address set in a single call (#251 step 4):
        // the UI sends what should be linked, not a diff. Also carries the
        // hook's include_temporary switch, because the Temporary addresses
        // control is a property of the same routing decision.
        case 'webhook_set_addresses':
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin webhook address change', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            require_pro($userId);
            if (!proWebhookRoutingAvailable()) {
                send_json(['success' => false, 'error' => 'Webhook routing is not available yet']);
            }
            $wid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($wid <= 0) {
                send_json(['success' => false, 'error' => 'Invalid id']);
            }
            // address_ids is optional and an empty list is meaningful: it is how
            // a hook is emptied. Present but not an array is a malformed request
            // rather than an empty set.
            $addressIds = [];
            if (array_key_exists('address_ids', $_POST)) {
                if (!is_array($_POST['address_ids'])) {
                    send_json(['success' => false, 'error' => 'Invalid request']);
                }
                foreach ($_POST['address_ids'] as $rawId) {
                    // Non-scalars are dropped rather than cast: (int)[] is 1, so
                    // casting blindly would turn junk into a plausible id.
                    if (!is_scalar($rawId)) {
                        continue;
                    }
                    $candidate = (int)$rawId;
                    if ($candidate > 0) {
                        $addressIds[$candidate] = $candidate;
                    }
                }
                $addressIds = array_values($addressIds);
                if (count($addressIds) > 50) {
                    send_json(['success' => false, 'error' => 'Too many addresses']);
                }
            }
            $includeTemporary = (($_POST['include_temporary'] ?? '') === '1');
            try {
                // Ensure ownership of the hook before anything is written.
                $s = $pdo->prepare("SELECT user_id FROM pro_webhooks WHERE id = ? LIMIT 1");
                $s->execute([$wid]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['user_id'] !== $userId) {
                    send_json(['success' => false, 'error' => 'Not found']);
                }

                // Every id must be one of this user's own personal addresses.
                // One query, placeholders built from the count and the values
                // bound — never interpolated.
                if ($addressIds !== []) {
                    // Only the *count* of the ids reaches the SQL: the
                    // placeholder string is ('?' x N), reduced to '?' and ','
                    // by the preg_replace, and every id is bound separately.
                    // Same construct, and same suppression, as index.php's
                    // multi-address lookup.
                    $placeholders = implode(',', array_fill(0, count($addressIds), '?'));
                    $safePlaceholders = preg_replace('/[^?,]/', '', $placeholders);
                    // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
                    $sql = "SELECT id FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1 AND id IN ($safePlaceholders)";
                    // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable
                    $v = $pdo->prepare($sql);
                    $v->execute(array_merge([$userId], $addressIds));
                    $owned = array_map('intval', $v->fetchAll(PDO::FETCH_COLUMN));
                    if (count($owned) !== count($addressIds)) {
                        // All or nothing: a partially applied set would be a
                        // routing state the caller never asked for.
                        send_json(['success' => false, 'error' => 'Address not found']);
                    }
                }

                $pdo->beginTransaction();
                try {
                    $d = $pdo->prepare("DELETE FROM pro_webhook_addresses WHERE webhook_id = ?");
                    $d->execute([$wid]);
                    if ($addressIds !== []) {
                        $ins = $pdo->prepare("INSERT INTO pro_webhook_addresses (webhook_id, temp_email_id) VALUES (?, ?)");
                        foreach ($addressIds as $addressId) {
                            $ins->execute([$wid, $addressId]);
                        }
                    }
                    $u = $pdo->prepare("UPDATE pro_webhooks SET include_temporary = ? WHERE id = ? AND user_id = ?");
                    $u->execute([$includeTemporary ? 1 : 0, $wid, $userId]);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                sort($addressIds);
                logMessage('INFO', 'Webhook address routing updated', ['user_id' => $userId, 'webhook_id' => $wid, 'count' => count($addressIds)]);
                send_json(['success' => true, 'id' => $wid, 'address_ids' => $addressIds, 'include_temporary' => $includeTemporary]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed updating webhook addresses', ['user_id' => $userId, 'webhook_id' => $wid, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not update webhook addresses']);
            }
            break;

        case 'feed_get_token':
            require_pro($userId);
            try {
                $s = $pdo->prepare("SELECT feed_token FROM pro_users WHERE id = ? LIMIT 1");
                $s->execute([$userId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                $token = $r['feed_token'] ?? null;
                if (empty($token)) {
                    // Generate a new token and persist
                    $token = bin2hex(random_bytes(32));
                    $u = $pdo->prepare("UPDATE pro_users SET feed_token = ? WHERE id = ?");
                    $u->execute([$token, $userId]);
                    logMessage('INFO', 'Generated new feed token for pro user', ['user_id' => $userId]);
                }
                send_json(['success' => true, 'token' => $token]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed fetching/creating feed token', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not retrieve feed token']);
            }
            break;

        case 'feed_regenerate':
            require_pro($userId);
            try {
                $new = bin2hex(random_bytes(32));
                $u = $pdo->prepare("UPDATE pro_users SET feed_token = ? WHERE id = ?");
                $u->execute([$new, $userId]);
                logMessage('INFO', 'Regenerated feed token for pro user', ['user_id' => $userId]);
                send_json(['success' => true, 'token' => $new]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed regenerating feed token', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not regenerate token']);
            }
            break;

        // Per-address feeds (#160). Pro-only, exactly like the account-wide
        // feed above, and only ever for personal addresses: an id is looked up
        // with ownership AND is_personal = 1 in the same query, so another
        // user's address or a temporary one is rejected without a token being
        // minted. Mirrors index.php's delete_personal lookup.
        case 'address_feed_get_token':
            require_pro($userId);
            try {
                if (!tableHasColumn('temp_emails', 'feed_token')) {
                    // Migration not run: no per-address feeds exist yet.
                    send_json(['success' => false, 'error' => 'Could not retrieve feed token']);
                }
                $addressId = (int)($_POST['id'] ?? 0);
                if (!$addressId) {
                    send_json(['success' => false, 'error' => 'Invalid address']);
                }
                $s = $pdo->prepare("SELECT feed_token FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
                $s->execute([$addressId, $userId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    send_json(['success' => false, 'error' => 'Address not found']);
                }
                $token = $row['feed_token'] ?? null;
                if (empty($token)) {
                    // Generate a new token and persist
                    $token = bin2hex(random_bytes(32));
                    $u = $pdo->prepare("UPDATE temp_emails SET feed_token = ? WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
                    $u->execute([$token, $addressId, $userId]);
                    logMessage('INFO', 'Generated new per-address feed token', ['user_id' => $userId, 'address_id' => $addressId]);
                }
                send_json(['success' => true, 'token' => $token]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed fetching/creating per-address feed token', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not retrieve feed token']);
            }
            break;

        case 'address_feed_regenerate':
            require_pro($userId);
            try {
                if (!tableHasColumn('temp_emails', 'feed_token')) {
                    send_json(['success' => false, 'error' => 'Could not regenerate token']);
                }
                $addressId = (int)($_POST['id'] ?? 0);
                if (!$addressId) {
                    send_json(['success' => false, 'error' => 'Invalid address']);
                }
                $s = $pdo->prepare("SELECT id FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
                $s->execute([$addressId, $userId]);
                if (!$s->fetch(PDO::FETCH_ASSOC)) {
                    send_json(['success' => false, 'error' => 'Address not found']);
                }
                $new = bin2hex(random_bytes(32));
                $u = $pdo->prepare("UPDATE temp_emails SET feed_token = ? WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
                $u->execute([$new, $addressId, $userId]);
                logMessage('INFO', 'Regenerated per-address feed token', ['user_id' => $userId, 'address_id' => $addressId]);
                send_json(['success' => true, 'token' => $new]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed regenerating per-address feed token', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not regenerate token']);
            }
            break;

        case 'address_feed_disable':
            require_pro($userId);
            try {
                if (!tableHasColumn('temp_emails', 'feed_token')) {
                    send_json(['success' => false, 'error' => 'Could not disable feed']);
                }
                $addressId = (int)($_POST['id'] ?? 0);
                if (!$addressId) {
                    send_json(['success' => false, 'error' => 'Invalid address']);
                }
                $s = $pdo->prepare("SELECT id FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
                $s->execute([$addressId, $userId]);
                if (!$s->fetch(PDO::FETCH_ASSOC)) {
                    send_json(['success' => false, 'error' => 'Address not found']);
                }
                $u = $pdo->prepare("UPDATE temp_emails SET feed_token = NULL WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
                $u->execute([$addressId, $userId]);
                logMessage('INFO', 'Disabled per-address feed', ['user_id' => $userId, 'address_id' => $addressId]);
                send_json(['success' => true]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed disabling per-address feed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not disable feed']);
            }
            break;

        // Per-address hook pause (#251 step 4). Same shape as the per-address
        // feed actions above: a preference on the address, never a credential,
        // and ownership resolved with id + pro_user_id + is_personal = 1 in one
        // query — another user's address and a temporary one are both simply
        // "not found". Pausing keeps the address' links; it only silences them
        // (see ImapProcessor::hooksForDestination()).
        case 'address_hooks_pause':
        case 'address_hooks_resume':
            if (!requireSameOriginRequest()) {
                logMessage('WARNING', 'Rejected cross-origin per-address hook change', ['user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Invalid request origin']);
            }
            require_pro($userId);
            $pause = ($action === 'address_hooks_pause');
            try {
                if (!tableHasColumn('temp_emails', 'hooks_paused')) {
                    send_json(['success' => false, 'error' => 'Could not update hook settings']);
                }
                $addressId = (int)($_POST['id'] ?? 0);
                if (!$addressId) {
                    send_json(['success' => false, 'error' => 'Invalid address']);
                }
                $s = $pdo->prepare("SELECT id FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
                $s->execute([$addressId, $userId]);
                if (!$s->fetch(PDO::FETCH_ASSOC)) {
                    send_json(['success' => false, 'error' => 'Address not found']);
                }
                $u = $pdo->prepare("UPDATE temp_emails SET hooks_paused = ? WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
                $u->execute([$pause ? 1 : 0, $addressId, $userId]);
                logMessage('INFO', $pause ? 'Paused hooks for address' : 'Resumed hooks for address', ['user_id' => $userId, 'address_id' => $addressId]);
                send_json(['success' => true, 'hooks_paused' => $pause]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed updating per-address hook state', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not update hook settings']);
            }
            break;

        case 'webhook_create':
            require_pro($userId);
            // Expects: name, url, kind (generic|pushover), config (JSON), secret (optional)
            $rawName = $_POST['name'] ?? '';
            $rawUrl = $_POST['url'] ?? '';
            $rawKind = $_POST['kind'] ?? 'generic';
            $config = $_POST['config'] ?? null;
            $rawSecret = $_POST['secret'] ?? '';

            // Detect suspicious patterns in all inputs
            foreach ([$rawName, $rawUrl, $rawSecret] as $input) {
                $suspicious = detectSuspiciousPatterns((string)$input);
                if (!empty($suspicious)) {
                    logMessage('WARNING', 'Suspicious webhook input', ['patterns' => $suspicious, 'user_id' => $userId]);
                    // Flagga IP för blockering
                    flagMaliciousActivity(getVisitorIp(), 'Suspicious webhook input: ' . implode(', ', $suspicious)); // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
                    send_json(['success' => false, 'error' => 'Invalid request']);
                }
            }

            // Sanitize name: strip HTML, null bytes, limit to 100 chars
            $name = sanitizeString($rawName, 100, true);
            $url = sanitizeString($rawUrl, 2048, true);
            $kind = sanitizeAlphanumeric($rawKind, 20) ?: 'generic';
            $secret = sanitizeString($rawSecret, 256, true) ?? '';

            if (empty($url)) {
                send_json(['success' => false, 'error' => 'URL is required']);
            }
            // Validate URL format and require https for security
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                send_json(['success' => false, 'error' => 'Invalid URL format']);
            }
            if (strlen($url) > 2048) {
                send_json(['success' => false, 'error' => 'URL too long (max 2048 characters)']);
            }
            // Only allow https URLs that resolve to a public (non-internal) address.
            // Webhooks are meant for third-party external services, so unlike some
            // other integrations in this app there's no legitimate case for allowing
            // localhost/private-network targets here.
            $parsedUrl = parse_url($url);
            $scheme = $parsedUrl['scheme'] ?? '';
            if ($scheme !== 'https') {
                send_json(['success' => false, 'error' => 'Only HTTPS URLs are allowed']);
            }
            if (resolveUrlToPublicTarget($url) === null) {
                send_json(['success' => false, 'error' => 'URL must resolve to a public address']);
            }
            if (!in_array($kind, ['generic', 'pushover'])) {
                send_json(['success' => false, 'error' => 'Invalid kind']);
            }

            // Validate config JSON
            $configArr = null;
            if ($config) {
                // Limit config size to prevent abuse
                if (is_string($config) && strlen($config) > 4096) {
                    send_json(['success' => false, 'error' => 'Config too large (max 4KB)']);
                }
                if (is_string($config)) {
                    $configArr = json_decode($config, true, 10); // max depth 10
                } else {
                    $configArr = $config;
                }
                if ($configArr === null && $config) {
                    send_json(['success' => false, 'error' => 'Invalid config JSON']);
                }
            }

            // Pushover must include token and user
            if ($kind === 'pushover') {
                if (empty($configArr['token']) || empty($configArr['user'])) {
                    send_json(['success' => false, 'error' => 'Pushover config requires token and user']);
                }
                // Optional device: Pushover device names are up to 25 of [A-Za-z0-9_-],
                // several may be given comma-separated.
                if (isset($configArr['device'])) {
                    $device = is_string($configArr['device']) ? trim($configArr['device']) : null;
                    if ($device === null || ($device !== '' && !preg_match('/^[A-Za-z0-9_-]{1,25}(,[A-Za-z0-9_-]{1,25})*$/', $device))) {
                        send_json(['success' => false, 'error' => 'Pushover device must be a device name (letters, digits, _ or -, max 25), comma-separated for several']);
                    }
                    $configArr['device'] = $device;
                }
            }

            try {
                // Encrypt secret if provided
                $storedSecret = $secret ? encrypt_webhook_secret($secret) : null;
                $ins = $pdo->prepare("INSERT INTO pro_webhooks (user_id, name, url, kind, config, secret) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$userId, $name ?: null, $url, $kind, $configArr ? json_encode($configArr) : null, $storedSecret]);
                $id = (int)$pdo->lastInsertId();
                logMessage('INFO', 'Webhook created', ['user_id' => $userId, 'webhook_id' => $id]);
                // Attempt an immediate test delivery so the user can verify the webhook
                try {
                    $payload = [
                        'type' => 'test',
                        'message' => 'This is a test delivery for your newly created webhook.',
                        'webhook_id' => $id,
                        'created_at' => date('Y-m-d H:i:s')
                    ];

                    // If pushover, send using pushover API
                    $httpCode = 0;
                    $respBody = null;
                    if ($kind === 'pushover') {
                        $token = $configArr['token'] ?? null;
                        $userKey = $configArr['user'] ?? null;
                        if (!empty($token) && !empty($userKey)) {
                            $post = ['token' => $token, 'user' => $userKey, 'message' => $payload['message'], 'title' => 'Mail Shield Test'];
                            if (!empty($configArr['device'])) $post['device'] = $configArr['device'];
                            if (function_exists('curl_init')) {
                                $ch = curl_init('https://api.pushover.net/1/messages.json');
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                                $respBody = curl_exec($ch);
                                $err = curl_error($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
                                curl_close($ch);
                                if ($respBody === false) {
                                    $respBody = $err ?: '';
                                }
                            } else {
                                $ch = curl_init('https://api.pushover.net/1/messages.json');
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                                $resp = curl_exec($ch);
                                $err = curl_error($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
                                curl_close($ch);
                                $respBody = $resp === false ? ($err ?: 'curl_exec failed') : $resp;
                            }
                        }
                    } else {
                        // Generic JSON POST
                        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
                        $headers = ['Content-Type: application/json'];
                        if (!empty($storedSecret)) {
                            $secretPlain = decrypt_webhook_secret($storedSecret);
                            if (!empty($secretPlain)) {
                                $headers[] = 'X-TempMail-Signature: sha256=' . hash_hmac('sha256', $payloadJson, $secretPlain);
                            }
                        }
                        // Re-resolve right before dispatch (not just at validation time above) and
                        // pin the connection to the validated IP, so a DNS change between
                        // validation and connect time can't redirect the request internally.
                        $dispatchTarget = resolveUrlToPublicTarget($url);
                        if ($dispatchTarget === null) {
                            $respBody = 'Target host could not be resolved to a permitted address';
                            $httpCode = 0;
                        } elseif (function_exists('curl_init')) {
                            $ch = curl_init($url);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                            curl_setopt($ch, CURLOPT_RESOLVE, [$dispatchTarget['host'] . ':' . $dispatchTarget['port'] . ':' . $dispatchTarget['ip']]);
                            $respBody = curl_exec($ch);
                            $err = curl_error($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
                            curl_close($ch);
                            if ($respBody === false) $respBody = $err ?: '';
                        } else {
                            $respBody = 'cURL extension not available';
                            $httpCode = 0;
                        }
                    }

                    // Record delivery attempt
                    try {
                        $dstmt = $pdo->prepare("INSERT INTO pro_webhook_deliveries (webhook_id, user_id, payload, attempts, status, response_body, http_code, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $status = ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'failed';
                        $dstmt->execute([$id, $userId, json_encode($payload, JSON_UNESCAPED_UNICODE), 1, $status, $respBody, $httpCode]);
                    } catch (Exception $e) {
                        logMessage('WARNING', 'Failed recording webhook test delivery', ['error' => $e->getMessage(), 'webhook_id' => $id]);
                    }
                } catch (Exception $e) {
                    // Non-fatal — creation succeeded even if test delivery failed
                    logMessage('WARNING', 'Test webhook delivery failed', ['error' => $e->getMessage(), 'webhook_id' => $id]);
                }

                send_json(['success' => true, 'id' => $id]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed creating webhook', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not create webhook']);
            }
            break;

        case 'webhook_delete':
            require_pro($userId);
            $wid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($wid <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid id']);
                exit;
            }
            try {
                // Ensure ownership
                $s = $pdo->prepare("SELECT user_id FROM pro_webhooks WHERE id = ? LIMIT 1");
                $s->execute([$wid]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['user_id'] !== $userId) {
                    echo json_encode(['success' => false, 'error' => 'Not found']);
                    exit;
                }
                $d = $pdo->prepare("DELETE FROM pro_webhooks WHERE id = ?");
                $d->execute([$wid]);
                // pro_webhook_addresses has no foreign key, so its rows go
                // explicitly (#251 step 4). Left behind they would route the
                // next hook that reuses this id to the old addresses.
                if (tableHasColumn('pro_webhook_addresses', 'webhook_id')) {
                    $dl = $pdo->prepare("DELETE FROM pro_webhook_addresses WHERE webhook_id = ?");
                    $dl->execute([$wid]);
                }
                logMessage('INFO', 'Webhook deleted', ['user_id' => $userId, 'webhook_id' => $wid]);
                send_json(['success' => true]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed deleting webhook', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not delete webhook']);
            }
            break;

        case 'webhook_set_filter_mode':
            require_pro($userId);
            // Toggle webhook between 'all' (active) and 'paused' modes
            $wid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $mode = $_POST['filter_mode'] ?? '';
            if ($wid <= 0) {
                send_json(['success' => false, 'error' => 'Invalid id']);
            }
            if (!in_array($mode, ['all', 'paused'], true)) {
                send_json(['success' => false, 'error' => 'Invalid filter_mode. Use "all" or "paused"']);
            }
            try {
                // Ensure ownership
                $s = $pdo->prepare("SELECT user_id, filter_mode FROM pro_webhooks WHERE id = ? LIMIT 1");
                $s->execute([$wid]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['user_id'] !== $userId) {
                    send_json(['success' => false, 'error' => 'Not found']);
                }
                $u = $pdo->prepare("UPDATE pro_webhooks SET filter_mode = ? WHERE id = ?");
                $u->execute([$mode, $wid]);
                logMessage('INFO', 'Webhook filter_mode updated', ['user_id' => $userId, 'webhook_id' => $wid, 'filter_mode' => $mode]);
                send_json(['success' => true, 'filter_mode' => $mode]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed updating webhook filter_mode', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not update webhook']);
            }
            break;

        case 'webhook_deliveries':
            require_pro($userId);
            // Optional: webhook_id, limit
            $wid = isset($_GET['webhook_id']) ? (int)$_GET['webhook_id'] : null;
            $limit = isset($_GET['limit']) ? min(200, (int)$_GET['limit']) : 50;
            try {
                if ($wid) {
                    $s = $pdo->prepare("SELECT d.* FROM pro_webhook_deliveries d JOIN pro_webhooks h ON h.id = d.webhook_id WHERE d.webhook_id = ? AND h.user_id = ? ORDER BY d.created_at DESC LIMIT ?");
                    $s->bindValue(1, $wid, PDO::PARAM_INT);
                    $s->bindValue(2, $userId, PDO::PARAM_INT);
                    $s->bindValue(3, $limit, PDO::PARAM_INT);
                    $s->execute();
                } else {
                    $s = $pdo->prepare("SELECT d.* FROM pro_webhook_deliveries d JOIN pro_webhooks h ON h.id = d.webhook_id WHERE h.user_id = ? ORDER BY d.created_at DESC LIMIT ?");
                    $s->bindValue(1, $userId, PDO::PARAM_INT);
                    $s->bindValue(2, $limit, PDO::PARAM_INT);
                    $s->execute();
                }
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
                send_json(['success' => true, 'deliveries' => $rows]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed listing webhook deliveries', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not fetch deliveries']);
            }
            break;

        case 'delete_account':
            // Create a pending profile change for account deletion and send a confirmation magic link
            try {
                $token = bin2hex(random_bytes(24));
                $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
                $data = json_encode(['requested_by' => $userId]);
                $ins = $pdo->prepare("INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, 'delete_account', ?, ?, ?)");
                $ins->execute([$userId, $data, $token, $expires]);

                // Send confirmation email with magic link
                $confirmUrl = ($config['email']['base_url'] ?? '') . "pro_auth.php?confirm_profile_change=" . urlencode($token);
                $to = $_SESSION['pro_user_email'] ?? '';
                $subject = 'Confirm account deletion for Mail Shield';
                $message = "Hello,\n\nA request was made to permanently delete your Mail Shield account.\n\nIf you want to proceed, please confirm by clicking the link below (valid for 2 hours):\n\n" . $confirmUrl . "\n\nIf you did not request this, ignore this email.\n\nRegards,\nThe Mail Shield Team";
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: Mail Shield <' . $from . '>';
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headersStr = implode("\r\n", $headers);
                @mail($to, $subject, $message, $headersStr);

                logMessage('INFO', 'Pending account deletion created', ['user_id' => $userId]);
                send_json(['success' => true, 'message' => 'Confirmation link sent to your email address']);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed creating pending account deletion', ['error' => $e->getMessage(), 'user_id' => $userId]);
                send_json(['success' => false, 'error' => 'Could not create confirmation request']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            break;
    }
} catch (Exception $e) {
    logMessage('ERROR', 'pro_profile error', ['error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

exit;
