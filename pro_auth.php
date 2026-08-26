<?php
/**
 * Backend för magic link-inloggning (pro-användare)
 * Endpoints: request_login_link, verify_token
 */
require_once 'config.php';
require_once __DIR__ . '/TwoFactorAuth.php';

// Hjälpfunktion: generera slumpad token
function generateLoginToken($length = 48) {
    return bin2hex(random_bytes($length / 2));
}

// Hjälpfunktion: hämta eller skapa användare
function getOrCreateProUser($email) {
    global $pdo;
    global $config;
    // Disallow creating accounts with service email domain
    $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
    $parts = explode('@', $email);
    $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
    if ($domainPart === $forbiddenDomain) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM pro_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) return $user['id'];
    // When creating a new pro user, set default address TTL (in days).
    $defaultTtl = 1; // default 1 day
    $stmt = $pdo->prepare("INSERT INTO pro_users (email, address_ttl_days) VALUES (?, ?)");
    $stmt->execute([$email, $defaultTtl]);
    return $pdo->lastInsertId();
}

// Hjälpfunktion: uppdatera last_login_at direkt efter att en session beviljats.
// Anropas från alla inloggningsvägar (magic link, lösenord, lösenord +
// trusted device, lösenord + 2FA) - se documentaion/ACCOUNT_TIERS.md §5.
// Fail-open: ett fel här ska aldrig blockera en redan beviljad inloggning.
function recordProUserLogin(int $userId): void {
    global $pdo;
    if (!tableHasColumn('pro_users', 'last_login_at')) {
        return;
    }
    try {
        $stmt = $pdo->prepare("UPDATE pro_users SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        logMessage('WARNING', 'recordProUserLogin failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

// Hjälpfunktion: skapa och spara login-token
function createLoginToken($userId, $validMinutes = 30) {
    global $pdo;
    $token = generateLoginToken();
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validMinutes} minutes"));
    $stmt = $pdo->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $token, $expiresAt]);
    return $token;
}

// Hjälpfunktion: verifiera och förbruka en magic link-token i ett enda steg.
// Delad av pro_login.php och GET-token-endpointen nedan så att formatvalidering,
// engångsanvändning och utgångskontroll bara finns på ett ställe (se issue #12).
// Returnerar ['user_id' => ..., 'email' => ...] vid en giltig, oanvänd token
// (och markerar den som använd), annars null.
function consumeLoginToken(string $token): ?array {
    global $pdo;
    // Tokenformat: hex-sträng, 48-96 tecken
    if (!preg_match('/^[a-f0-9]+$/i', $token) || strlen($token) < 48 || strlen($token) > 96) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT lt.id, lt.user_id, lt.expires_at, lt.used, pu.email FROM login_tokens lt JOIN pro_users pu ON lt.user_id = pu.id WHERE lt.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['used'] || strtotime($row['expires_at']) < time()) {
        return null;
    }
    $upd = $pdo->prepare("UPDATE login_tokens SET used = 1 WHERE id = ?");
    $upd->execute([$row['id']]);
    return ['user_id' => $row['user_id'], 'email' => $row['email']];
}

// Hjälpfunktion: skicka e-post med login-länk
function sendLoginEmail($email, $token) {
    global $config;
    $loginUrl = $config['email']['base_url'] . "pro_login.php?token=" . urlencode($token);
    $subject = "Your login link for TempMail Pro";
    $message = "Hello,\n\nClick the link below to sign in to your TempMail Pro account:\n\n" . $loginUrl . "\n\nThis link is valid for 30 minutes.\n\nIf you did not request this link, please ignore this email.\n\nRegards,\nThe TempMail Team";
    // Bestäm avsändaradress (kan sättas via ENV t.ex. EMAIL_FROM)
    $fromAddress = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));

    // Sätt headers för en tydlig avsändare och charset
    $headers = [];
    $headers[] = 'From: TempMail <' . $fromAddress . '>';
    $headers[] = 'Reply-To: ' . $fromAddress;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $headersStr = implode("\r\n", $headers);

    // Försök skicka e-post med PHP `mail()`.
    $sent = false;
    try {
        // Använd envelope param för att ange Return-Path om servern stödjer det
        $envelope = '-f' . $fromAddress;
        $sent = mail($email, $subject, $message, $headersStr, $envelope);
        if ($sent === false) {
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'mail() returned false when attempting to send magic link', ['to' => $email]);
            } else {
                error_log('mail() returned false when attempting to send magic link to ' . $email);
            }
        }
    } catch (Exception $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Mail sending unexpected error', ['error' => $e->getMessage(), 'to' => $email]);
        } else {
            error_log('Mail sending unexpected error: ' . $e->getMessage());
        }
        $sent = false;
    }

    // Logga i systemloggen — undvik att skriva ut token i produktion.
    if ($sent) {
        $logContext = ['to' => $email];
        if (!empty($config['app']['debug_mode'])) {
            // Only include token in debug/development mode
            $logContext['token'] = $token;
        }
        logMessage('INFO', 'Magic link sent', $logContext);
    } else {
        logMessage('ERROR', 'Failed to send magic link', ['to' => $email]);
        if (!function_exists('logMessage')) {
            error_log('Failed to send magic link to ' . $email);
        }
    }

    return $sent;
}

// Hjälpfunktion: hämta senaste aktiva temp-adress för en pro-användare.
// Bruten ut ur password_login så samma svarsform kan återanvändas av verify_2fa.
function getProUserLatestAddress(PDO $pdo, array $config, $userId) {
    try {
        $col = 'unique_address';
        $colStmt = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'unique_address'");
        if ($colStmt->rowCount() === 0) {
            $col = 'address';
        }
        $ae = $pdo->prepare("SELECT $col AS local_part, expires_at FROM temp_emails WHERE pro_user_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
        $ae->execute([$userId]);
        $ar = $ae->fetch(PDO::FETCH_ASSOC);
        if ($ar && !empty($ar['local_part'])) {
            return [
                'current_address' => $ar['local_part'] . '@' . ($config['email']['domain'] ?? 'manjo.me'),
                'expires_at' => $ar['expires_at'] ?? null,
            ];
        }
    } catch (Exception $e) {
        // fall through to null result below
    }
    return ['current_address' => null, 'expires_at' => null];
}

/**
 * Löser in en voucherkod för en e-postadress. Skapar kontot om det inte finns,
 * annars förlängs pro_expires_at. Delas av redeem_voucher-endpointet och
 * registreringsflödet — se documentaion/ACCOUNT_TIERS.md §4.
 *
 * @return array ['success' => bool, 'error' => string|null, 'user_id' => int|null]
 */
function redeemVoucherForEmail(string $email, string $code): array {
    global $pdo;
    global $config;
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address', 'user_id' => null];
    }
    // Validate voucher code: alphanumeric, dashes, max 64 chars
    if ($code === '' || strlen($code) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
        return ['success' => false, 'error' => 'Invalid voucher code format', 'user_id' => null];
    }

    try {
        // Start transaction and lock voucher row
        $pdo->beginTransaction();
        $vstmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ? FOR UPDATE");
        $vstmt->execute([$code]);
        $v = $vstmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Invalid code or expired', 'user_id' => null];
        }
        if (!(int)$v['is_active']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'This code is not active', 'user_id' => null];
        }
        if (!is_null($v['expires_at']) && strtotime($v['expires_at']) <= time()) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'This code has expired', 'user_id' => null];
        }
        if (!is_null($v['max_uses']) && (int)$v['current_uses'] >= (int)$v['max_uses']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'This code has been fully redeemed', 'user_id' => null];
        }

        // Lock or create user
        $ustmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE email = ? FOR UPDATE");
        $ustmt->execute([$email]);
        $u = $ustmt->fetch(PDO::FETCH_ASSOC);
        $now = time();
        if ($u) {
            $userId = $u['id'];
            $currentExpires = $u['pro_expires_at'];
            if (is_null($v['duration_days'])) {
                $newExpires = null; // forever
            } else {
                $dur = (int)$v['duration_days'];
                if (!is_null($currentExpires) && strtotime($currentExpires) > $now) {
                    $newExpires = date('Y-m-d H:i:s', strtotime($currentExpires) + $dur * 86400);
                } else {
                    $newExpires = date('Y-m-d H:i:s', strtotime("+{$dur} days"));
                }
            }
            if (tableHasColumn('pro_users', 'account_type')) {
                $up = $pdo->prepare("UPDATE pro_users SET pro_expires_at = ?, account_type = 'pro' WHERE id = ?");
            } else {
                $up = $pdo->prepare("UPDATE pro_users SET pro_expires_at = ? WHERE id = ?");
            }
            $up->execute([$newExpires, $userId]);
        } else {
            // Create new pro user with default TTL
            // Disallow creating accounts using the service domain
            $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
            $parts = explode('@', $email);
            $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
            if ($domainPart === $forbiddenDomain) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Email addresses at this domain are not allowed', 'user_id' => null];
            }
            if (is_null($v['duration_days'])) {
                $newExpires = null;
            } else {
                $dur = (int)$v['duration_days'];
                $newExpires = date('Y-m-d H:i:s', strtotime("+{$dur} days"));
            }
            $defaultTtl = 1;
            if (tableHasColumn('pro_users', 'account_type')) {
                $ins = $pdo->prepare("INSERT INTO pro_users (email, pro_expires_at, address_ttl_days, account_type, email_verified_at) VALUES (?, ?, ?, 'pro', NOW())");
            } else {
                $ins = $pdo->prepare("INSERT INTO pro_users (email, pro_expires_at, address_ttl_days) VALUES (?, ?, ?)");
            }
            $ins->execute([$email, $newExpires, $defaultTtl]);
            $userId = $pdo->lastInsertId();
        }

        // Increment voucher usage
        // Prevent the same user from redeeming the same voucher more than once
        $checkRedeem = $pdo->prepare("SELECT id FROM redemption_log WHERE user_id = ? AND voucher_id = ? LIMIT 1");
        $checkRedeem->execute([$userId, $v['id']]);
        if ($checkRedeem->fetch()) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'You have already redeemed this code', 'user_id' => null];
        }

        $upv = $pdo->prepare("UPDATE vouchers SET current_uses = current_uses + 1 WHERE id = ?");
        $upv->execute([$v['id']]);

        // Log redemption
        $rstmt = $pdo->prepare("INSERT INTO redemption_log (user_id, voucher_id) VALUES (?, ?)");
        $rstmt->execute([$userId, $v['id']]);

        $pdo->commit();

        return ['success' => true, 'error' => null, 'user_id' => (int)$userId];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logMessage('ERROR', 'Voucher redemption failed', ['error' => $e->getMessage(), 'email' => $email, 'code' => $code]);
        return ['success' => false, 'error' => 'Redemption failed', 'user_id' => null];
    }
}

// Hjälpfunktion: sätt/uppdatera trusted-device-cookien (§2 i designdokumentet).
// HttpOnly + Secure + SameSite=Lax, path '/', så den bara går till servern
// över HTTPS och aldrig till JS. $expiresAt är en 'Y-m-d H:i:s'-sträng.
function setTrustedDeviceCookie(string $value, string $expiresAt): void {
    setcookie(TwoFactorAuth::TRUSTED_DEVICE_COOKIE, $value, [
        'expires' => strtotime($expiresAt) ?: (time() + 30 * 86400),
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Hjälpfunktion: notismail när en engångskod (recovery code) förbrukas vid
// inloggning. Se documentaion/2FA_DESIGN.md §5.4/§9 — aldrig koden själv.
function send_2fa_recovery_code_used_email($userId) {
    global $pdo, $config;
    try {
        $stmt = $pdo->prepare("SELECT email FROM pro_users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        if (!$email) {
            return;
        }
        $subject = 'A two-factor recovery code was used to sign in to TempMail Pro';
        $message = "Hello,\n\nA two-factor recovery code was just used to sign in to your TempMail Pro account. Recovery codes are meant as a backup — consider generating new ones from your profile if you're running low.\n\nIf you did not sign in just now, sign in using your magic link, disable two-factor authentication and change your password immediately.\n\nRegards,\nThe TempMail Team";
        $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
        $headers = [];
        $headers[] = 'From: TempMail <' . $from . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headersStr = implode("\r\n", $headers);
        @mail($email, $subject, $message, $headersStr);
    } catch (Exception $e) {
        logMessage('WARNING', 'Failed sending 2FA recovery code used email', ['error' => $e->getMessage(), 'user_id' => $userId]);
    }
}

// Endpointerna nedan ska bara köras när pro_auth.php anropas direkt — inte när
// filen require:as från pro_login.php enbart för consumeLoginToken(). Annars
// skulle t.ex. GET-token-grenen nedan konsumera token och avsluta requesten
// innan pro_login.php hunnit rendera sin HTML-sida.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)):

// Endpoint: begär inloggningslänk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_login_link') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit;
    }

    // Rate limiting: without this, an attacker can mail-bomb any inbox by
    // repeatedly requesting login links for it (measured by IP only, same
    // pattern as password_login's brute-force guard below).
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateLimited = false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS magic_link_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $windowMinutes = 15;
        $maxRequests = 5;
        $s1 = $pdo->prepare("SELECT COUNT(*) FROM magic_link_requests WHERE ip = ? AND requested_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $s1->execute([$ip, $windowMinutes]);
        if ((int) $s1->fetchColumn() >= $maxRequests) {
            $rateLimited = true;
            if (function_exists('flagMaliciousActivity')) {
                flagMaliciousActivity($ip, 'Magic link request rate limit exceeded');
            }
            logMessage('WARNING', 'Magic link request rate limit exceeded', ['ip' => $ip]);
        } else {
            $ins = $pdo->prepare("INSERT INTO magic_link_requests (ip) VALUES (?)");
            $ins->execute([$ip]);
        }
    } catch (Exception $e) {
        // Fail open (don't block legitimate logins if the check itself breaks), but log it.
        logMessage('ERROR', 'Magic link rate-check failed', ['error' => $e->getMessage()]);
    }

    if ($rateLimited) {
        // Same generic response as every other branch below, so a rate-limited
        // caller can't distinguish this from "link sent" or "unknown account".
        echo json_encode(['success' => true]);
        exit;
    }

    // Login is tier-neutral (see documentaion/ACCOUNT_TIERS.md §5): check that
    // the account is email-verified before sending a magic link, not its Pro
    // status. Do NOT create a new pro user here.
    $stmt = $pdo->prepare("SELECT id, email_verified_at FROM pro_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $token = null;
    $sent = false;

    if ($row) {
        // Om migrationen (#53) inte är körd finns kolumnen inte - behandla då
        // alla befintliga konton som verifierade så ingen låses ute.
        $isVerified = !tableHasColumn('pro_users', 'email_verified_at') || !is_null($row['email_verified_at']);
        if ($isVerified) {
            $userId = $row['id'];
            $token = createLoginToken($userId);
            $sent = sendLoginEmail($email, $token);
        } else {
            // Not verified: do not send email. We intentionally do not reveal this to the caller.
            logMessage('INFO', 'Magic link requested for unverified user', ['email' => $email]);
        }
    } else {
        // User does not exist: do not create here and do not send email. Log for audit.
        logMessage('INFO', 'Magic link requested for unknown user', ['email' => $email]);
    }

    // Return a generic success response so callers cannot enumerate accounts.
    $response = ['success' => true];
    // In debug mode, optionally expose the login URL when a token was generated.
    if (!empty($config['app']['debug_mode']) && $token) {
        $response['login_url'] = $config['email']['base_url'] . "pro_login.php?token=" . urlencode($token);
    }
    echo json_encode($response);
    exit;
}

// Endpoint: redeem voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'redeem_voucher') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');

    $result = redeemVoucherForEmail($email, $code);

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Voucher redeemed successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
    exit;
}

// Endpoint: lösenordsinloggning (email + password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'password_login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    // Rate limiting / brute-force protection (measure by IP only)
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $windowMinutes = 15;
        $maxFails = 5;

        // Count recent failed attempts for this IP only
        $s1 = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempt_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $s1->execute([$ip, $windowMinutes]);
        $failsIp = (int)$s1->fetchColumn();

        if ($failsIp >= $maxFails) {
            // Too many attempts from this IP, tell the client to wait
            // Flagga IP för blockering (misslyckade inloggningsförsök)
            flagMaliciousActivity($ip, 'Brute force login: ' . $failsIp . ' misslyckade försök');
            echo json_encode(['success' => false, 'error' => 'Too many failed login attempts from your IP. Try again later.']);
            exit;
        }
    } catch (Exception $e) {
        // If DB check fails for some reason, continue (fail-open) but log
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Login rate-check failed', ['error' => $e->getMessage()]);
        } else {
            error_log('Login rate-check failed: ' . $e->getMessage());
        }
    }
    // Hämta användare och verifiera hash
    $stmt = $pdo->prepare("SELECT id, email, password_hash, email_verified_at FROM pro_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        // Record failed attempt (unknown user) for IP + email
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, 0)");
            $ins->execute([$ip, $email]);
        } catch (Exception $e) {
            // ignore
        }
        // Do not reveal whether the user exists
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password']);
        exit;
    }
    if (empty($user['password_hash'])) {
        // Record failed attempt (no password set) to slow abuse
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 0)");
            $ins->execute([$ip, $email, $user['id']]);
        } catch (Exception $e) {
            // ignore
        }
        echo json_encode(['success' => false, 'error' => 'No password is set for this account. Use the magic link or set a password in your profile.']);
        exit;
    }
    if (!password_verify($password, $user['password_hash'])) {
        // Record failed attempt
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 0)");
            $ins->execute([$ip, $email, $user['id']]);
        } catch (Exception $e) {
            // ignore
        }
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password']);
        exit;
    }
    // Login is tier-neutral (see documentaion/ACCOUNT_TIERS.md §5), but the
    // account must be email-verified. Fallback: if the migration (#53) hasn't
    // run, the column doesn't exist yet - treat every account as verified.
    $isVerified = !tableHasColumn('pro_users', 'email_verified_at') || !is_null($user['email_verified_at']);
    if (!$isVerified) {
        // Record failed attempt (unverified account). Same generic error as a
        // wrong password so this endpoint can't be used to enumerate accounts.
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 0)");
            $ins->execute([$ip, $email, $user['id']]);
        } catch (Exception $e) {
            // ignore
        }
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password']);
        exit;
    }
    session_start();

    // Correct password does not grant a session by itself when 2FA is
    // active — the magic link path (unaffected by this) already gives full
    // access without a code; here the password is only the first factor.
    // No login_attempts row is written for this half-authenticated step: the
    // IP rate limit above already guards the password itself, and the real
    // attempt gets recorded (against the code) in verify_2fa.
    if (TwoFactorAuth::isEnabledFor($user['id'])) {
        // A valid, unexpired trusted-device cookie for this exact user_id
        // skips the challenge entirely — checked BEFORE the challenge is
        // shown, per documentaion/2FA_DESIGN.md §2/§5.2. The validator is
        // rotated on every use so the cookie value is never reused.
        $trustedCookie = (string) ($_COOKIE[TwoFactorAuth::TRUSTED_DEVICE_COOKIE] ?? '');
        $rotated = $trustedCookie !== '' ? TwoFactorAuth::verifyAndRotateTrustedDevice($user['id'], $trustedCookie) : null;

        if ($rotated !== null) {
            setTrustedDeviceCookie($rotated['cookie_value'], $rotated['expires_at']);

            session_regenerate_id(true);
            $_SESSION['pro_user_id'] = $user['id'];
            $_SESSION['pro_user_email'] = $user['email'];
            $_SESSION['pro_login_method'] = 'password';
            recordProUserLogin($user['id']);
            try {
                $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 1)");
                $ins->execute([$ip, $email, $user['id']]);
            } catch (Exception $e) {
                // ignore
            }
            logMessage('INFO', 'Pro user logged in via password + trusted device', ['user_id' => $user['id']]);

            $addr = getProUserLatestAddress($pdo, $config, $user['id']);
            echo json_encode(['success' => true, 'redirect' => 'pro.php', 'current_address' => $addr['current_address'], 'expires_at' => $addr['expires_at']]);
            exit;
        }

        $_SESSION['pending_2fa'] = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'created_at' => time(),
        ];
        echo json_encode(['success' => true, 'requires_2fa' => true]);
        exit;
    }

    // Sätt session och logga in
    $_SESSION['pro_user_id'] = $user['id'];
    $_SESSION['pro_user_email'] = $user['email'];
    $_SESSION['pro_login_method'] = 'password';
    recordProUserLogin($user['id']);
    // Record successful attempt
    try {
        $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 1)");
        $ins->execute([$ip, $email, $user['id']]);
    } catch (Exception $e) {
        // ignore
    }
    logMessage('INFO', 'Pro user logged in via password', ['user_id' => $user['id']]);

    $addr = getProUserLatestAddress($pdo, $config, $user['id']);
    echo json_encode(['success' => true, 'redirect' => 'pro.php', 'current_address' => $addr['current_address'], 'expires_at' => $addr['expires_at']]);
    exit;
}

// Endpoint: verifiera 2FA-kod (eller engångskod) efter password_login med
// pending_2fa. Se documentaion/2FA_DESIGN.md §4-§6.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
    if (!requireSameOriginRequest()) {
        logMessage('WARNING', 'Rejected cross-origin verify_2fa request');
        echo json_encode(['success' => false, 'error' => 'Invalid request origin']);
        exit;
    }

    session_start();

    $pending = $_SESSION['pending_2fa'] ?? null;
    if (
        !is_array($pending)
        || empty($pending['user_id'])
        || empty($pending['created_at'])
        || (time() - (int) $pending['created_at']) > 600
    ) {
        unset($_SESSION['pending_2fa']);
        echo json_encode(['success' => false, 'error' => 'Login session expired. Please sign in again.']);
        exit;
    }

    $userId = (int) $pending['user_id'];
    $pendingEmail = $pending['email'] ?? '';
    $ip = getVisitorIp();

    // Locked out: same generic response as an incorrect code below. The IP
    // side of this already calls flagMaliciousActivity() internally.
    if (TwoFactorAuth::isChallengeBlocked($userId, $ip)) {
        echo json_encode(['success' => false, 'error' => 'Incorrect code']);
        exit;
    }

    $code = trim((string) ($_POST['code'] ?? ''));

    $valid = TwoFactorAuth::verifyForUser($userId, $code);
    $usedRecoveryCode = false;
    if (!$valid) {
        $usedRecoveryCode = TwoFactorAuth::consumeRecoveryCode($userId, $code);
        $valid = $usedRecoveryCode;
    }

    if (!$valid) {
        TwoFactorAuth::recordAttempt($userId, $ip, false);
        // Generic error: never reveal whether the code was wrong or the
        // account/IP is locked.
        echo json_encode(['success' => false, 'error' => 'Incorrect code']);
        exit;
    }

    TwoFactorAuth::recordAttempt($userId, $ip, true);

    // Promote pending_2fa to a real session. Regenerate the session ID
    // (session fixation protection) before granting pro_user_id.
    session_regenerate_id(true);
    $_SESSION['pro_user_id'] = $userId;
    $_SESSION['pro_user_email'] = $pendingEmail;
    $_SESSION['pro_login_method'] = 'password';
    unset($_SESSION['pending_2fa']);
    recordProUserLogin($userId);

    // "Remember this browser" checkbox: only ever creates a trusted-device
    // row AFTER a successful verification above, never before — see
    // documentaion/2FA_DESIGN.md §2.
    if (!empty($_POST['remember_device'])) {
        $label = TwoFactorAuth::summarizeUserAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $device = TwoFactorAuth::createTrustedDevice($userId, $label);
        setTrustedDeviceCookie($device['cookie_value'], $device['expires_at']);
    }

    try {
        $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 1)");
        $ins->execute([$ip, $pendingEmail, $userId]);
    } catch (Exception $e) {
        // ignore
    }
    logMessage('INFO', 'Pro user logged in via password + 2FA', ['user_id' => $userId]);

    if ($usedRecoveryCode) {
        logMessage('WARNING', '2fa_recovery_code_used', ['user_id' => $userId]);
        send_2fa_recovery_code_used_email($userId);
    }

    $addr = getProUserLatestAddress($pdo, $config, $userId);
    echo json_encode(['success' => true, 'redirect' => 'pro.php', 'current_address' => $addr['current_address'], 'expires_at' => $addr['expires_at']]);
    exit;
}

// Endpoint: avbryt en pågående pending_2fa-utmaning (t.ex. "Cancel" eller
// "Lost your authenticator?" i UI:t) och ta tillbaka användaren till
// inloggningsformuläret. Rör inte en redan inloggad session.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_2fa') {
    if (!requireSameOriginRequest()) {
        logMessage('WARNING', 'Rejected cross-origin cancel_2fa request');
        echo json_encode(['success' => false, 'error' => 'Invalid request origin']);
        exit;
    }

    session_start();
    unset($_SESSION['pending_2fa']);
    echo json_encode(['success' => true]);
    exit;
}

// Endpoint: verifiera token och logga in
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    $token = trim((string) $_GET['token']);

    $result = consumeLoginToken($token);
    if ($result === null) {
        echo "Invalid or expired link.";
        exit;
    }

    // Magic link is itself two factors (knowledge of the address + inbox
    // access) — it must never trigger a pending_2fa challenge, and any
    // leftover half-login from a password attempt must not survive into
    // this session. See documentaion/2FA_DESIGN.md.
    session_start();
    unset($_SESSION['pending_2fa']);
    session_regenerate_id(true);
    $_SESSION['pro_user_id'] = $result['user_id'];
    $_SESSION['pro_user_email'] = $result['email'];
    $_SESSION['pro_login_method'] = 'magic_link';
    // Redirect to dashboard so server-side will load the user's latest active temp address
    header('Location: pro.php');
    exit;
}

// Endpoint: confirm pending profile change (password/email)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['confirm_profile_change'])) {
    $token = $_GET['confirm_profile_change'];
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, user_id, action, data, expires_at, used FROM pending_profile_changes WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "Invalid or expired link.";
            exit;
        }
        if ($row['used']) {
            echo "This link has already been used.";
            exit;
        }
        if (strtotime($row['expires_at']) < time()) {
            echo "This link has expired.";
            exit;
        }

        $userId = (int)$row['user_id'];
        $action = $row['action'];
        $data = json_decode($row['data'], true);

        if ($action === 'update_email') {
            $newEmail = $data['new_email'] ?? '';
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                echo "Invalid email in request.";
                exit;
            }
            // Ensure still not taken
            $check = $pdo->prepare("SELECT id FROM pro_users WHERE email = ? AND id <> ? LIMIT 1");
            $check->execute([$newEmail, $userId]);
            if ($check->fetch()) {
                echo "The email address is already taken by another user.";
                exit;
            }
            // Fetch old email before applying change so we can notify it and offer undo
            $oldStmt = $pdo->prepare("SELECT email FROM pro_users WHERE id = ? LIMIT 1");
            $oldStmt->execute([$userId]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            $oldEmail = $oldRow['email'] ?? '';

            // Apply the email change and mark pending used. Do NOT create an undo token.
            $u = $pdo->prepare("UPDATE pro_users SET email = ? WHERE id = ?");
            $u->execute([$newEmail, $userId]);
            // Mark original pending as used
            $m = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ?");
            $m->execute([$row['id']]);

            // Notify old email that account email has changed (no undo link)
            try {
                if (!empty($oldEmail) && filter_var($oldEmail, FILTER_VALIDATE_EMAIL) && $oldEmail !== $newEmail) {
                    $subjectOld = 'Your TempMail Pro email has been changed';
                    $messageOld = "Hello,\n\nThis is a notification that the email address for your TempMail Pro account was changed from " . $oldEmail . " to " . $newEmail . ".\n\nIf you did NOT authorize this change, please contact support immediately.\n\nRegards,\nThe TempMail Team";
                    $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                    $headers = [];
                    $headers[] = 'From: TempMail <' . $from . '>';
                    $headers[] = 'MIME-Version: 1.0';
                    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                    $headersStr = implode("\r\n", $headers);
                    @mail($oldEmail, $subjectOld, $messageOld, $headersStr);
                }
            } catch (Exception $e) {
                if (function_exists('logMessage')) {
                    logMessage('ERROR', 'Failed sending old-email notification', ['error' => $e->getMessage(), 'user_id' => $userId ?? null]);
                } else {
                    error_log('Failed sending old-email notification: ' . $e->getMessage());
                }
            }

            echo "Email change confirmed. Your new email is: " . htmlspecialchars($newEmail);
            exit;
        } elseif ($action === 'set_password') {
            $hash = $data['password_hash'] ?? '';
            if (empty($hash)) {
                echo "Invalid request.";
                exit;
            }
            // Apply password change WITHOUT creating an undo token. Update password and set password_changed_at.
            try {
                // Ensure password_changed_at column exists
                $colStmt = $pdo->query("SHOW COLUMNS FROM pro_users LIKE 'password_changed_at'");
                if ($colStmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE pro_users ADD COLUMN password_changed_at DATETIME NULL");
                }
            } catch (Exception $e) {
                // If altering table fails, continue without setting the column
                logMessage('WARNING', 'Could not ensure password_changed_at column exists', ['error' => $e->getMessage()]);
            }

            // Fetch old email for notification
            $oldStmt = $pdo->prepare("SELECT email FROM pro_users WHERE id = ? LIMIT 1");
            $oldStmt->execute([$userId]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            $oldEmail = $oldRow['email'] ?? '';

            // Update password hash and set password_changed_at
            try {
                $u = $pdo->prepare("UPDATE pro_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?");
                $u->execute([$hash, $userId]);
            } catch (Exception $e) {
                // Fallback: try update without password_changed_at
                $u = $pdo->prepare("UPDATE pro_users SET password_hash = ? WHERE id = ?");
                $u->execute([$hash, $userId]);
            }

            // Mark original pending as used
            $m = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ?");
            $m->execute([$row['id']]);

            // A password change invalidates any "remember this browser"
            // cookies — see documentaion/2FA_DESIGN.md §2.
            TwoFactorAuth::revokeAllTrustedDevices($userId);

            // Notify old email that password has changed (no undo link)
            try {
                if (!empty($oldEmail) && filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
                    $subjectOld = 'Your TempMail Pro password has been changed';
                    $messageOld = "Hello,\n\nThis is a notification that the password for your TempMail Pro account associated with " . $oldEmail . " has been changed.\n\nIf you did NOT authorize this change, contact support immediately.\n\nRegards,\nThe TempMail Team";
                    $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                    $headers = [];
                    $headers[] = 'From: TempMail <' . $from . '>';
                    $headers[] = 'MIME-Version: 1.0';
                    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                    $headersStr = implode("\r\n", $headers);
                    @mail($oldEmail, $subjectOld, $messageOld, $headersStr);
                }
            } catch (Exception $e) {
                if (function_exists('logMessage')) {
                    logMessage('ERROR', 'Failed sending old-email notification for password change', ['error' => $e->getMessage(), 'user_id' => $userId ?? null]);
                } else {
                    error_log('Failed sending old-email notification for password change: ' . $e->getMessage());
                }
            }

            // If the current session belongs to this user, refresh session-stored password_changed_at so current session remains valid
            try {
                session_start();
                if (isset($_SESSION['pro_user_id']) && (int)$_SESSION['pro_user_id'] === $userId) {
                    // Fetch new password_changed_at
                    $s = $pdo->prepare("SELECT password_changed_at FROM pro_users WHERE id = ? LIMIT 1");
                    $s->execute([$userId]);
                    $sr = $s->fetch(PDO::FETCH_ASSOC);
                    $_SESSION['pro_password_changed_at'] = $sr['password_changed_at'] ?? null;
                }
            } catch (Exception $e) {
                // ignore
            }

            echo "Password change confirmed. You can now log in with your new password.";
            exit;
            } elseif ($action === 'delete_account') {
                // Apply account deletion: remove pro user and related pro data
                try {
                    // Fetch current email for display/logging
                    $oldStmt = $pdo->prepare("SELECT email FROM pro_users WHERE id = ? LIMIT 1");
                    $oldStmt->execute([$userId]);
                    $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
                    $oldEmail = $oldRow['email'] ?? '';

                    // Mark this pending request as used to prevent reuse
                    $m = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ?");
                    $m->execute([$row['id']]);

                    // Perform deletions in a transaction
                    $pdo->beginTransaction();
                    // Delete webhook deliveries for user's webhooks
                    $delDeliveries = $pdo->prepare("DELETE d FROM pro_webhook_deliveries d JOIN pro_webhooks w ON w.id = d.webhook_id WHERE w.user_id = ?");
                    $delDeliveries->execute([$userId]);
                    // Delete webhooks
                    $delWebhooks = $pdo->prepare("DELETE FROM pro_webhooks WHERE user_id = ?");
                    $delWebhooks->execute([$userId]);
                    // Delete login tokens
                    $delTokens = $pdo->prepare("DELETE FROM login_tokens WHERE user_id = ?");
                    $delTokens->execute([$userId]);
                    // Delete any remaining pending profile changes for this user
                    $delPending = $pdo->prepare("DELETE FROM pending_profile_changes WHERE user_id = ?");
                    $delPending->execute([$userId]);
                    // Delete 2FA state: TOTP enrollment, recovery codes, trusted
                    // devices. No FK cascade exists on these tables (see
                    // TwoFactorAuth::ensureSchema), so this must happen here.
                    TwoFactorAuth::ensureSchema($pdo);
                    $delTotp = $pdo->prepare("DELETE FROM pro_user_totp WHERE user_id = ?");
                    $delTotp->execute([$userId]);
                    $delRecoveryCodes = $pdo->prepare("DELETE FROM pro_user_recovery_codes WHERE user_id = ?");
                    $delRecoveryCodes->execute([$userId]);
                    $delTrustedDevices = $pdo->prepare("DELETE FROM pro_trusted_devices WHERE user_id = ?");
                    $delTrustedDevices->execute([$userId]);
                    // Finally delete the pro_users row
                    $delUser = $pdo->prepare("DELETE FROM pro_users WHERE id = ?");
                    $delUser->execute([$userId]);
                    $pdo->commit();

                    logMessage('INFO', 'Pro user account deleted via confirmation', ['user_id' => $userId]);

                    // Destroy session if current session belongs to this user
                    try {
                        session_start();
                        if (isset($_SESSION['pro_user_id']) && (int)$_SESSION['pro_user_id'] === $userId) {
                            @session_unset();
                            @session_destroy();
                        }
                    } catch (Exception $e) {
                        // ignore
                    }

                    echo "Your TempMail Pro account has been deleted.";
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    logMessage('ERROR', 'Failed deleting pro account via confirmation', ['user_id' => $userId, 'error' => $e->getMessage()]);
                    echo "An error occurred while deleting your account.";
                    exit;
                }
        } else {
            echo "Unknown action type.";
            exit;
        }
    } catch (Exception $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Failed applying pending_profile_changes', ['error' => $e->getMessage(), 'token' => $token ?? null]);
        } else {
            error_log('Failed applying pending_profile_changes: ' . $e->getMessage());
        }
        echo "An error occurred during confirmation.";
        exit;
    }
}

// Endpoint: undo a recently applied profile change (from old-email notification)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['undo_profile_change'])) {
    $token = $_GET['undo_profile_change'];
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, user_id, action, data, expires_at, used FROM pending_profile_changes WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "Invalid or expired link.";
            exit;
        }
        if ($row['used']) {
            echo "This link has already been used or revoked.";
            exit;
        }
        if (strtotime($row['expires_at']) < time()) {
            echo "This link has expired.";
            exit;
        }

        $userId = (int)$row['user_id'];
        $action = $row['action'];
        $data = json_decode($row['data'], true);

        if ($action === 'undo_update_email') {
            $oldEmail = $data['old_email'] ?? '';
            $newEmail = $data['new_email'] ?? '';
            if (!filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
                echo "Invalid email in request.";
                exit;
            }
            // Revert email back to oldEmail
            $u = $pdo->prepare("UPDATE pro_users SET email = ? WHERE id = ?");
            $u->execute([$oldEmail, $userId]);
            // Mark undo token as used
            $m = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ?");
            $m->execute([$row['id']]);
            echo "Email change reverted. Your email is now: " . htmlspecialchars($oldEmail);
            exit;
        } elseif ($action === 'undo_set_password') {
            $oldHash = $data['old_hash'] ?? '';
            if ($oldHash === '') {
                echo "Invalid request.";
                exit;
            }
            // Revert password hash
            $u = $pdo->prepare("UPDATE pro_users SET password_hash = ? WHERE id = ?");
            $u->execute([$oldHash, $userId]);
            $m = $pdo->prepare("UPDATE pending_profile_changes SET used = 1 WHERE id = ?");
            $m->execute([$row['id']]);
            echo "Password change reverted. You can log in with your previous password.";
            exit;
        } else {
            echo "Unknown action type.";
            exit;
        }
    } catch (Exception $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Failed applying undo pending_profile_changes', ['error' => $e->getMessage(), 'token' => $token ?? null]);
        } else {
            error_log('Failed applying undo pending_profile_changes: ' . $e->getMessage());
        }
        echo "An error occurred during revert.";
        exit;
    }
}

// Om ingen endpoint matchar
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ogiltig begäran']);
exit;

endif;