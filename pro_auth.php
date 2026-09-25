<?php
/**
 * Backend för magic link-inloggning (pro-användare)
 * Endpoints: request_login_link, verify_token
 */
require_once 'config.php';
require_once __DIR__ . '/TwoFactorAuth.php';
require_once __DIR__ . '/pro_trial.php';
require_once __DIR__ . '/login_tokens.php';
require_once __DIR__ . '/email_log_ref.php';
require_once __DIR__ . '/pii_crypto.php';

// Hjälpfunktion: generera slumpad token
function generateLoginToken($length = 48) {
    return authTokenGenerate((int) $length);
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
    // Without the keys no account can be found or created (pii_crypto.php).
    if (!piiEmailRequireKeys('getOrCreateProUser')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM pro_users WHERE email_hash = ? LIMIT 1");
    $stmt->execute([piiEmailLookupHash((string) $email)]);
    $user = $stmt->fetch();
    if ($user) return $user['id'];
    // When creating a new pro user, set default address TTL (in days).
    $defaultTtl = 1; // default 1 day
    $stmt = $pdo->prepare("INSERT INTO pro_users (email, address_ttl_days) VALUES (?, ?)");
    $stmt->execute([$email, $defaultTtl]);
    $userId = $pdo->lastInsertId();
    proUserStoreEmailPii($pdo, (int) $userId, (string) $email);
    return $userId;
}

// Hjälpfunktion: uppdatera last_login_at direkt efter att en session beviljats.
// Anropas från alla inloggningsvägar (magic link, lösenord, lösenord +
// trusted device, lösenord + 2FA) - se documentaion/ACCOUNT_TIERS.md §5.
// Fail-open: ett fel här ska aldrig blockera en redan beviljad inloggning.
// Nollställer även inactivity_warned_at (#63) i samma UPDATE: annars skulle
// en användare som loggar in efter att ha fått en inaktivitetsvarning inte
// kunna bli varnad igen efter en ny inaktiv period (kolumnen skulle förbli
// satt för alltid). Kolumnen kontrolleras separat eftersom den kan saknas
// även när last_login_at finns (infördes i en senare migrering, #63).
function recordProUserLogin(int $userId): void {
    global $pdo;
    if (!tableHasColumn('pro_users', 'last_login_at')) {
        return;
    }
    try {
        if (tableHasColumn('pro_users', 'inactivity_warned_at')) {
            $stmt = $pdo->prepare("UPDATE pro_users SET last_login_at = NOW(), inactivity_warned_at = NULL WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE pro_users SET last_login_at = NOW() WHERE id = ?");
        }
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        logMessage('WARNING', 'recordProUserLogin failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

// Hjälpfunktion: skapa och spara login-token. Returnerar den råa token som
// ska in i e-postlänken; databasen lagrar bara dess hash (login_tokens.php).
function createLoginToken($userId, $validMinutes = 30) {
    global $pdo;
    return loginTokenCreate($pdo, (int) $userId, (int) $validMinutes);
}

// Hjälpfunktion: verifiera och förbruka en magic link-token i ett enda steg.
// Delad av pro_login.php och GET-token-endpointen nedan så att formatvalidering,
// engångsanvändning och utgångskontroll bara finns på ett ställe (se issue #12).
// Returnerar ['user_id' => ..., 'email' => ...] vid en giltig, oanvänd token
// (och markerar den som använd, atomiskt), annars null. Se login_tokens.php.
function consumeLoginToken(string $token): ?array {
    global $pdo;
    return loginTokenConsume($pdo, $token);
}

// Hjälpfunktion: skicka e-post med login-länk
function sendLoginEmail($email, $token, $userId = null) {
    global $config;
    $loginUrl = $config['email']['base_url'] . "pro_login.php?token=" . urlencode($token);
    $subject = "Your login link for Mail Shield";
    $message = "Hello,\n\nClick the link below to sign in to your Mail Shield account:\n\n" . $loginUrl . "\n\nThis link is valid for 30 minutes.\n\nIf you did not request this link, please ignore this email.\n\nRegards,\nThe Mail Shield Team";
    // Bestäm avsändaradress (kan sättas via ENV t.ex. EMAIL_FROM)
    $fromAddress = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));

    // Sätt headers för en tydlig avsändare och charset
    $headers = [];
    $headers[] = 'From: Mail Shield <' . $fromAddress . '>';
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
                logMessage('ERROR', 'mail() returned false when attempting to send magic link', emailLogContext((string) $email, $userId));
            } else {
                error_log('mail() returned false when attempting to send magic link');
            }
        }
    } catch (Exception $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Mail sending unexpected error', ['error' => $e->getMessage()] + emailLogContext((string) $email, $userId));
        } else {
            error_log('Mail sending unexpected error: ' . $e->getMessage());
        }
        $sent = false;
    }

    // Logga i systemloggen — undvik att skriva ut token i produktion.
    if ($sent) {
        $logContext = emailLogContext((string) $email, $userId);
        if (!empty($config['app']['debug_mode']) && !appIsProduction()) {
            // Only include token in debug/development mode, never in production
            $logContext['token'] = $token;
        }
        logMessage('INFO', 'Magic link sent', $logContext);
    } else {
        logMessage('ERROR', 'Failed to send magic link', emailLogContext((string) $email, $userId));
        if (!function_exists('logMessage')) {
            error_log('Failed to send magic link');
        }
    }

    return $sent;
}

// Hjälpfunktion: skicka verifieringsmail vid självbetjäningsregistrering
// (register_account, se documentaion/ACCOUNT_TIERS.md §4). Byggd som
// sendLoginEmail() ovan - samma From-header, samma mail()-anrop med envelope,
// samma logg-hantering, samma försiktighet med att inte logga token i
// produktion. Länken pekar på pro_login.php?token=..., som konsumerar token
// via consumeLoginToken() och - om kontot är overifierat - sätter
// email_verified_at samtidigt som den loggar in. Verifiering och första
// inloggning blir alltså samma klick.
function sendVerificationEmail(string $email, string $token, ?int $userId = null): bool {
    global $config;
    $verifyUrl = $config['email']['base_url'] . "pro_login.php?token=" . urlencode($token);
    $subject = "Confirm your Mail Shield account";
    $message = "Hello,\n\nThanks for signing up. Click the link below to verify your email address and sign in:\n\n" . $verifyUrl . "\n\nThis link is valid for 30 minutes.\n\nIf you did not create this account, please ignore this email.\n\nRegards,\nThe Mail Shield Team";
    $fromAddress = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));

    $headers = [];
    $headers[] = 'From: Mail Shield <' . $fromAddress . '>';
    $headers[] = 'Reply-To: ' . $fromAddress;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headersStr = implode("\r\n", $headers);

    $sent = false;
    try {
        $envelope = '-f' . $fromAddress;
        $sent = mail($email, $subject, $message, $headersStr, $envelope);
        if ($sent === false) {
            logMessage('ERROR', 'mail() returned false when attempting to send verification email', emailLogContext($email, $userId));
        }
    } catch (Exception $e) {
        logMessage('ERROR', 'Verification mail sending unexpected error', ['error' => $e->getMessage()] + emailLogContext($email, $userId));
        $sent = false;
    }

    if ($sent) {
        $logContext = emailLogContext($email, $userId);
        if (!empty($config['app']['debug_mode']) && !appIsProduction()) {
            // Only include token in debug/development mode, never in production
            $logContext['token'] = $token;
        }
        logMessage('INFO', 'Verification email sent', $logContext);
    } else {
        logMessage('ERROR', 'Failed to send verification email', emailLogContext($email, $userId));
    }

    return $sent;
}

// Hjälpfunktion: notismail när register_account träffar en redan verifierad
// e-postadress (se ACCOUNT_TIERS.md §4.3). Skapar och ändrar inget konto -
// pekar bara mottagaren till befintlig inloggning. Skickas alltid från samma
// kodväg som den generiska registreringsresponsen, aldrig från en gren som
// avslöjar kontots existens till klienten.
function sendAlreadyRegisteredEmail(string $email, ?int $userId = null): bool {
    global $config;
    $loginUrl = $config['email']['base_url'] . "pro_login.php";
    $subject = "You already have a Mail Shield account";
    $message = "Hello,\n\nSomeone (hopefully you) just tried to create a Mail Shield account with this email address, but an account already exists.\n\nIf that was you, sign in here:\n\n" . $loginUrl . "\n\nIf you don't remember signing up, you can request a magic sign-in link from that page - no password needed.\n\nIf you did not try to create an account, you can safely ignore this email.\n\nRegards,\nThe Mail Shield Team";
    $fromAddress = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));

    $headers = [];
    $headers[] = 'From: Mail Shield <' . $fromAddress . '>';
    $headers[] = 'Reply-To: ' . $fromAddress;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headersStr = implode("\r\n", $headers);

    $sent = false;
    try {
        $envelope = '-f' . $fromAddress;
        $sent = mail($email, $subject, $message, $headersStr, $envelope);
    } catch (Exception $e) {
        logMessage('ERROR', 'Already-registered mail sending unexpected error', ['error' => $e->getMessage()] + emailLogContext($email, $userId));
        $sent = false;
    }

    if (!$sent) {
        logMessage('ERROR', 'Failed to send already-registered email', emailLogContext($email, $userId));
    } else {
        logMessage('INFO', 'Already-registered notice sent', emailLogContext($email, $userId));
    }

    return $sent;
}

// Hjälpfunktion: adminnotis vid ny registrering. Skickas till en fast
// admin-adress när ett kontos email_verified_at sätts för första gången
// (dvs. anroparen bekräftar att UPDATE-satsen faktiskt träffade en rad) -
// alltså precis när en användare registrerar sig OCH verifierar sin adress
// i samma klick (pro_login.php:s magic-link-konsumtion). Byggd som
// sendLoginEmail() ovan. Fail-open: ett fel här får aldrig blockera
// användarens egen inloggning.
function sendAdminRegistrationNotification(string $userEmail, ?int $userId = null): bool {
    $adminEmail = $_ENV['ADMIN_NOTIFICATION_EMAIL'] ?? 'tony@manjo.me';
    $subject = "New Mail Shield registration verified";
    $message = "A new user just registered and verified their email address:\n\n" . $userEmail . "\n\nRegards,\nThe Mail Shield System";
    $fromAddress = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($GLOBALS['config']['email']['domain'] ?? 'manjo.me'));

    $headers = [];
    $headers[] = 'From: Mail Shield <' . $fromAddress . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headersStr = implode("\r\n", $headers);

    $sent = false;
    try {
        $envelope = '-f' . $fromAddress;
        $sent = mail($adminEmail, $subject, $message, $headersStr, $envelope);
    } catch (Exception $e) {
        logMessage('ERROR', 'Admin registration notification mail sending unexpected error', ['error' => $e->getMessage()] + emailLogContext($userEmail, $userId));
        $sent = false;
    }

    if (!$sent) {
        logMessage('ERROR', 'Failed to send admin registration notification', emailLogContext($userEmail, $userId));
    } else {
        logMessage('INFO', 'Admin registration notification sent', emailLogContext($userEmail, $userId));
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
 * Felkoder som redeemVoucherForEmail() kan returnera i 'error_code'. Håll i
 * synk med voucherRedemptionErrorMessage() nedan.
 */
const VOUCHER_ERROR_INVALID_EMAIL = 'invalid_email';
const VOUCHER_ERROR_INVALID_CODE_FORMAT = 'invalid_code_format';
const VOUCHER_ERROR_INVALID_OR_EXPIRED_CODE = 'invalid_or_expired_code';
const VOUCHER_ERROR_CODE_NOT_ACTIVE = 'code_not_active';
const VOUCHER_ERROR_CODE_EXPIRED = 'code_expired';
const VOUCHER_ERROR_CODE_FULLY_REDEEMED = 'code_fully_redeemed';
const VOUCHER_ERROR_DOMAIN_NOT_ALLOWED = 'domain_not_allowed';
const VOUCHER_ERROR_ALREADY_REDEEMED = 'already_redeemed';
const VOUCHER_ERROR_REDEMPTION_FAILED = 'redemption_failed';

/**
 * Mappar en av VOUCHER_ERROR_*-koderna ovan till användarvisat text. Hålls
 * separat från redeemVoucherForEmail()s returvärde så att strängen som
 * ekas till klienten alltid kommer från denna hårdkodade tabell, aldrig
 * direkt från funktionens returarray - annars flaggar Semgrep
 * (php.lang.security.injection.echoed-request) $result['error'] som
 * request-taint eftersom $email/$code (från $_POST) flödar in i samma
 * funktion, trots att alla faktiska felmeddelanden är literaler.
 */
function voucherRedemptionErrorMessage(?string $code): string {
    $messages = [
        VOUCHER_ERROR_INVALID_EMAIL => 'Invalid email address',
        VOUCHER_ERROR_INVALID_CODE_FORMAT => 'Invalid voucher code format',
        VOUCHER_ERROR_INVALID_OR_EXPIRED_CODE => 'Invalid code or expired',
        VOUCHER_ERROR_CODE_NOT_ACTIVE => 'This code is not active',
        VOUCHER_ERROR_CODE_EXPIRED => 'This code has expired',
        VOUCHER_ERROR_CODE_FULLY_REDEEMED => 'This code has been fully redeemed',
        VOUCHER_ERROR_DOMAIN_NOT_ALLOWED => 'Email addresses at this domain are not allowed',
        VOUCHER_ERROR_ALREADY_REDEEMED => 'You have already redeemed this code',
        VOUCHER_ERROR_REDEMPTION_FAILED => 'Redemption failed',
    ];
    return $messages[$code] ?? 'Redemption failed';
}

/**
 * Löser in en voucherkod för en e-postadress. Skapar kontot om det inte finns,
 * annars förlängs pro_expires_at. Delas av redeem_voucher-endpointet och
 * registreringsflödet — se documentaion/ACCOUNT_TIERS.md §4.
 *
 * @return array ['success' => bool, 'error_code' => string|null, 'user_id' => int|null]
 */
function redeemVoucherForEmail(string $email, string $code): array {
    global $pdo;
    global $config;
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error_code' => VOUCHER_ERROR_INVALID_EMAIL, 'user_id' => null];
    }
    // Validate voucher code: alphanumeric, dashes, max 64 chars
    if ($code === '' || strlen($code) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
        return ['success' => false, 'error_code' => VOUCHER_ERROR_INVALID_CODE_FORMAT, 'user_id' => null];
    }
    // The account is found (and created) by its blind index: no keys, no redemption.
    if (!piiEmailRequireKeys('voucher redemption')) {
        return ['success' => false, 'error_code' => VOUCHER_ERROR_REDEMPTION_FAILED, 'user_id' => null];
    }

    try {
        // Start transaction and lock voucher row
        $pdo->beginTransaction();
        $vstmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ? FOR UPDATE");
        $vstmt->execute([$code]);
        $v = $vstmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            $pdo->rollBack();
            return ['success' => false, 'error_code' => VOUCHER_ERROR_INVALID_OR_EXPIRED_CODE, 'user_id' => null];
        }
        if (!(int)$v['is_active']) {
            $pdo->rollBack();
            return ['success' => false, 'error_code' => VOUCHER_ERROR_CODE_NOT_ACTIVE, 'user_id' => null];
        }
        if (!is_null($v['expires_at']) && strtotime($v['expires_at']) <= time()) {
            $pdo->rollBack();
            return ['success' => false, 'error_code' => VOUCHER_ERROR_CODE_EXPIRED, 'user_id' => null];
        }
        if (!is_null($v['max_uses']) && (int)$v['current_uses'] >= (int)$v['max_uses']) {
            $pdo->rollBack();
            return ['success' => false, 'error_code' => VOUCHER_ERROR_CODE_FULLY_REDEEMED, 'user_id' => null];
        }

        // Lock or create user
        $ustmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE email_hash = ? FOR UPDATE");
        $ustmt->execute([piiEmailLookupHash($email)]);
        $u = $ustmt->fetch(PDO::FETCH_ASSOC);
        $now = time();
        $created = false;
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
                return ['success' => false, 'error_code' => VOUCHER_ERROR_DOMAIN_NOT_ALLOWED, 'user_id' => null];
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
            // Inside the voucher transaction, so the pair commits with the row.
            proUserStoreEmailPii($pdo, (int) $userId, $email);
            $created = true;
        }

        // Increment voucher usage
        // Prevent the same user from redeeming the same voucher more than once
        $checkRedeem = $pdo->prepare("SELECT id FROM redemption_log WHERE user_id = ? AND voucher_id = ? LIMIT 1");
        $checkRedeem->execute([$userId, $v['id']]);
        if ($checkRedeem->fetch()) {
            $pdo->rollBack();
            return ['success' => false, 'error_code' => VOUCHER_ERROR_ALREADY_REDEEMED, 'user_id' => null];
        }

        $upv = $pdo->prepare("UPDATE vouchers SET current_uses = current_uses + 1 WHERE id = ?");
        $upv->execute([$v['id']]);

        // Log redemption
        $rstmt = $pdo->prepare("INSERT INTO redemption_log (user_id, voucher_id) VALUES (?, ?)");
        $rstmt->execute([$userId, $v['id']]);

        $pdo->commit();

        if ($created) {
            // #267: a voucher-created account is verified on creation - record the address.
            proTrialRecordClaim($pdo, $email, (string)($config['trial']['hash_key'] ?? ''));
        }

        return ['success' => true, 'error_code' => null, 'user_id' => (int)$userId];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logMessage('ERROR', 'Voucher redemption failed', ['error' => $e->getMessage(), 'code' => $code] + emailLogContext((string) $email, empty($created) ? ($userId ?? null) : null));
        return ['success' => false, 'error_code' => VOUCHER_ERROR_REDEMPTION_FAILED, 'user_id' => null];
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
        $email = proUserEmail($pdo, (int) $userId);
        if (!$email) {
            return;
        }
        $subject = 'A two-factor recovery code was used to sign in to Mail Shield';
        $message = "Hello,\n\nA two-factor recovery code was just used to sign in to your Mail Shield account. Recovery codes are meant as a backup — consider generating new ones from your profile if you're running low.\n\nIf you did not sign in just now, sign in using your magic link, disable two-factor authentication and change your password immediately.\n\nRegards,\nThe Mail Shield Team";
        $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
        $headers = [];
        $headers[] = 'From: Mail Shield <' . $from . '>';
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
    $ip = getVisitorIp();
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
    // Om migrationen (#53) inte är körd finns kolumnen inte - kolla FÖRE
    // SELECT:en så vi aldrig frågar efter en kolumn som inte finns (annars
    // kastar PDO ett obehandlat undantag och endpointen kraschar med 500 för
    // alla anrop, inte bara den här grenen).
    $hasVerifiedCol = tableHasColumn('pro_users', 'email_verified_at');
    $stmt = $pdo->prepare($hasVerifiedCol
        ? "SELECT id, email_verified_at FROM pro_users WHERE email_hash = ? LIMIT 1"
        : "SELECT id FROM pro_users WHERE email_hash = ? LIMIT 1");
    $stmt->execute([piiEmailLookupHash($email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $token = null;
    $sent = false;

    if ($row) {
        // Saknas kolumnen: behandla alla befintliga konton som verifierade
        // så ingen låses ute.
        $isVerified = !$hasVerifiedCol || !is_null($row['email_verified_at']);
        if ($isVerified) {
            $userId = $row['id'];
            $token = createLoginToken($userId);
            $sent = sendLoginEmail($email, $token, (int) $userId);
        } else {
            // Not verified: do not send email. We intentionally do not reveal this to the caller.
            logMessage('INFO', 'Magic link requested for unverified user', ['user_id' => (int) $row['id']]);
        }
    } else {
        // User does not exist: do not create here and do not send email. Log for audit.
        logMessage('INFO', 'Magic link requested for unknown user', emailLogContext($email));
    }

    // Return a generic success response so callers cannot enumerate accounts.
    $response = ['success' => true];
    // In debug mode, optionally expose the login URL when a token was generated.
    // Never in production: the link is a working login for the account.
    if (!empty($config['app']['debug_mode']) && !appIsProduction() && $token) {
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
        // False positive: Semgrep's taint tracking marks this tainted purely
        // because $email/$code (from $_POST) were passed into
        // redeemVoucherForEmail() above, not because any of the echoed text
        // is influenced by user input. voucherRedemptionErrorMessage() only
        // ever returns one of the fixed literals from its hardcoded lookup
        // table (see its definition) - $result['error_code'] can only be one
        // of the VOUCHER_ERROR_* constants, never arbitrary user input.
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo json_encode(['success' => false, 'error' => voucherRedemptionErrorMessage($result['error_code'])]);
    }
    exit;
}

// Endpoint: självbetjäningsregistrering (Regular eller Pro-med-voucherkod).
// Se documentaion/ACCOUNT_TIERS.md §4 - detta är designunderlaget för allt
// nedan. Svaret måste vara byte-identiskt oavsett om ett nytt konto skapades,
// ett verifierat konto redan fanns, eller ett färskt overifierat konto redan
// fanns (samt vid rate limiting och domänblockering) - annars blir endpointet
// en kontoenumerator. Enda undantagen är rena formatfel på indata (ogiltig
// e-post, för kort/olika lösenord) och voucherfel, som handlar om vad
// användaren skrev, inte om vilka konton som finns.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_account') {
    // 1. Vitlista plan. Okänt värde -> 'regular'.
    $plan = $_POST['plan'] ?? 'regular';
    if (!in_array($plan, ['regular', 'pro'], true)) {
        $plan = 'regular';
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $code = trim((string) ($_POST['code'] ?? ''));
    $ip = getVisitorIp();

    // 2. Detektera misstänkta mönster i e-posten, precis som index.php gör på
    // sina POST-actions, innan input används till något.
    $suspicious = function_exists('detectSuspiciousPatterns') ? detectSuspiciousPatterns($email) : [];
    if (!empty($suspicious)) {
        logMessage('WARNING', 'Suspicious register_account input', [
            'patterns' => $suspicious,
            'ip' => $ip,
        ]);
        // base64_payload alone is logged and rejected, but never blocks the IP.
        $flagPatterns = patternsWarrantingIpFlag($suspicious);
        if ($flagPatterns && function_exists('flagMaliciousActivity')) {
            flagMaliciousActivity($ip, 'Suspicious register_account input: ' . implode(',', $flagPatterns));
        }
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit;
    }

    // 3. Lösenordspolicy - matchar EXAKT pro_profile.php:s set_password (samma
    // ordning, samma trösklar, samma felmeddelanden). Två olika krav i samma
    // produkt vore en bugg i sig.
    if ($password === '' || $password !== $passwordConfirm) {
        echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
        exit;
    }
    if (strlen($password) > 256) {
        echo json_encode(['success' => false, 'error' => 'Password too long (max 256 characters)']);
        exit;
    }

    // Kodens NÄRVARO för plan=pro är ett rent formatfel (samma kategori som
    // lösenordskraven ovan) och kontrolleras därför här, INNAN kontostatus
    // slås upp - annars skulle "kod saknas" avslöja om e-posten redan har ett
    // konto (den grenen hoppar över voucher-inlösen helt, se nedan). Själva
    // kodens GILTIGHET kan bara kontrolleras när inlösen faktiskt försöks
    // (endast i grenen "inget/färskt-utgånget konto" nedan) - det är den
    // smala, avsiktliga voucher-felkanalen som issuen tillåter.
    if ($plan === 'pro' && $code === '') {
        echo json_encode(['success' => false, 'error' => 'A voucher code is required for a Pro account']);
        exit;
    }

    // Det generiska svaret - identiskt i alla grenar utom formatfel/voucherfel ovan.
    $genericResponse = ['success' => true, 'message' => 'Check your email to finish creating your account'];

    // 4. Rate limiting, byggd exakt som magic_link_requests i
    // request_login_link ovan: samma fönster, samma gräns, samma
    // flagMaliciousActivity(), samma fail-open i catch-grenen (ett DB-fel här
    // får aldrig blockera en legitim registrering, men ska loggas).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS registration_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $windowMinutes = 15;
        $maxRequests = 5;
        $s1 = $pdo->prepare("SELECT COUNT(*) FROM registration_requests WHERE ip = ? AND requested_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $s1->execute([$ip, $windowMinutes]);
        if ((int) $s1->fetchColumn() >= $maxRequests) {
            if (function_exists('flagMaliciousActivity')) {
                flagMaliciousActivity($ip, 'Registration rate limit exceeded');
            }
            logMessage('WARNING', 'Registration rate limit exceeded', ['ip' => $ip]);
            // Samma generiska svar som en lyckad registrering - en rate
            // limit-träff får inte ha ett eget felmeddelande.
            echo json_encode($genericResponse);
            exit;
        }
        $ins = $pdo->prepare("INSERT INTO registration_requests (ip) VALUES (?)");
        $ins->execute([$ip]);
    } catch (Exception $e) {
        // Fail open (blockera aldrig legitima registreringar p.g.a. ett
        // trasigt rate limit-test), men logga.
        logMessage('ERROR', 'Registration rate-check failed', ['error' => $e->getMessage()]);
    }

    // 5a. Egen domän blockeras - samma kontroll som getOrCreateProUser().
    $parts = explode('@', $email);
    $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
    $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
    if ($domainPart === $forbiddenDomain) {
        logMessage('INFO', 'Registration attempt using service domain rejected', ['domain' => $domainPart]);
        echo json_encode($genericResponse);
        exit;
    }
    // 5b. Blocklista för kända engångsdomäner (config.php).
    if (isDisposableEmailDomain($email)) {
        logMessage('INFO', 'Registration attempt using disposable email domain rejected', ['domain' => $domainPart]);
        echo json_encode($genericResponse);
        exit;
    }

    // 6. Hantering av befintlig e-postadress - se ACCOUNT_TIERS.md §4.3.
    // Kolla email_verified_at-kolumnen FÖRE SELECT:en (se motsvarande
    // kommentar vid magic link-endpointen ovan) så en omigrerad prod-databas
    // inte kraschar hela registreringsflödet.
    // Without the PII keys an account can neither be looked up nor found
    // again after creation (pii_crypto.php). The answer does not depend on
    // which accounts exist, so it reveals nothing.
    if (!piiEmailRequireKeys('register_account')) {
        echo json_encode(['success' => false, 'error' => 'Registration is temporarily unavailable. Please try again later.']);
        exit;
    }
    $hasVerifiedCol = tableHasColumn('pro_users', 'email_verified_at');
    $hasAccountTypeCol = tableHasColumn('pro_users', 'account_type');
    $stmt = $pdo->prepare($hasVerifiedCol
        ? "SELECT id, email_verified_at, created_at FROM pro_users WHERE email_hash = ? LIMIT 1"
        : "SELECT id, created_at FROM pro_users WHERE email_hash = ? LIMIT 1");
    $stmt->execute([piiEmailLookupHash($email)]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $isVerified = !$hasVerifiedCol || !is_null($existing['email_verified_at']);

        if ($isVerified) {
            // Verifierat konto finns: skapa/ändra inget. Skicka ett "du har
            // redan ett konto"-mail. Voucher-inlösen anropas medvetet ALDRIG
            // i den här grenen - annars skulle plan=pro kunna användas för
            // att tyst uppgradera någon annans befintliga konto till Pro.
            sendAlreadyRegisteredEmail($email, (int) $existing['id']);
            logMessage('INFO', 'Registration attempted for existing verified account', ['user_id' => $existing['id']]);
            echo json_encode($genericResponse);
            exit;
        }

        $ageSeconds = time() - strtotime($existing['created_at']);
        if ($ageSeconds < 24 * 3600) {
            // Overifierat konto, yngre än 24 h: skicka om verifieringsmailet.
            // Skapa/ändra inget - varken lösenord eller voucher-inlösen körs
            // här, av samma anledning som ovan.
            $token = createLoginToken($existing['id']);
            sendVerificationEmail($email, $token, (int) $existing['id']);
            logMessage('INFO', 'Verification email resent for unverified account', ['user_id' => $existing['id']]);
            echo json_encode($genericResponse);
            exit;
        }
        // Overifierat konto, äldre än 24 h: faller igenom till
        // skapa/skriv-över-logiken nedan - detta är skyddet mot
        // e-postsquatting (ACCOUNT_TIERS.md §4.3, sista raden).
    }

    // 7. Skapa nytt konto, eller skriv över en färdig-att-återta overifierad
    // rad (>= 24 h gammal). $existing är här antingen null, eller en rad som
    // garanterat är overifierad och >= 24 h gammal.
    $userId = null;
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($plan === 'pro') {
        // Lös in vouchern FÖRE eget skrivande till pro_users - misslyckas
        // inlösen skapas/ändras inget konto alls (varken här eller i
        // redeemVoucherForEmail, som själv rullar tillbaka sin transaktion).
        //
        // redeemVoucherForEmail() skapar SJÄLV raden i pro_users om ingen
        // fanns (INSERT-grenen: account_type='pro', email_verified_at=NOW()
        // - precis som det befintliga redeem_voucher-endpointet redan gör,
        // dvs. en giltig kod litar vi på direkt, samma tillit som idag).
        // Fanns raden redan (den överåriga overifierade raden ovan) tar den
        // i stället UPDATE-grenen, som INTE rör email_verified_at - kontot
        // förblir overifierat tills den nya ägaren klickar verifieringslänken,
        // vilket är exakt squatting-skyddet vi vill ha kvar även för Pro.
        // password_hash sätts aldrig av redeemVoucherForEmail() - det gör vi
        // separat direkt efter, oavsett vilken av dess två grenar som körde.
        $result = redeemVoucherForEmail($email, $code);
        if (!$result['success']) {
            // Voucherfel handlar om vad användaren skrev, inte om kontots
            // existens (se filens topkommentar) - riktigt felmeddelande OK.
            // Samma falska positiv som vid redeem_voucher-endpointet ovan -
            // se kommentaren där för motivering.
            // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
            echo json_encode(['success' => false, 'error' => voucherRedemptionErrorMessage($result['error_code'])]);
            exit;
        }
        $userId = (int) $result['user_id'];
        $upw = $pdo->prepare("UPDATE pro_users SET password_hash = ? WHERE id = ?");
        $upw->execute([$passwordHash, $userId]);
    } elseif ($existing) {
        // Skriv över den överåriga overifierade raden med de nya uppgifterna.
        if ($hasAccountTypeCol && $hasVerifiedCol) {
            $upd = $pdo->prepare("UPDATE pro_users SET password_hash = ?, account_type = 'regular', email_verified_at = NULL, address_ttl_days = 1 WHERE id = ?");
        } elseif ($hasVerifiedCol) {
            $upd = $pdo->prepare("UPDATE pro_users SET password_hash = ?, email_verified_at = NULL, address_ttl_days = 1 WHERE id = ?");
        } else {
            $upd = $pdo->prepare("UPDATE pro_users SET password_hash = ?, address_ttl_days = 1 WHERE id = ?");
        }
        $upd->execute([$passwordHash, $existing['id']]);
        $userId = (int) $existing['id'];
    } else {
        // Nytt Regular-konto: email_verified_at = NULL, address_ttl_days = 1,
        // password_hash satt via password_hash(..., PASSWORD_DEFAULT).
        if ($hasAccountTypeCol && $hasVerifiedCol) {
            $ins = $pdo->prepare("INSERT INTO pro_users (email, password_hash, account_type, email_verified_at, address_ttl_days) VALUES (?, ?, 'regular', NULL, 1)");
        } elseif ($hasVerifiedCol) {
            $ins = $pdo->prepare("INSERT INTO pro_users (email, password_hash, email_verified_at, address_ttl_days) VALUES (?, ?, NULL, 1)");
        } else {
            $ins = $pdo->prepare("INSERT INTO pro_users (email, password_hash, address_ttl_days) VALUES (?, ?, 1)");
        }
        $ins->execute([$email, $passwordHash]);
        $userId = (int) $pdo->lastInsertId();
        proUserStoreEmailPii($pdo, $userId, $email);
    }

    // 8. Skicka verifieringsmail. Samma token/tabell som magic link
    // (createLoginToken()) - länken pekar på pro_login.php?token=..., som
    // konsumerar token och sätter email_verified_at om kontot var
    // overifierat (utökat i den här issuen, se pro_login.php).
    $token = createLoginToken($userId);
    sendVerificationEmail($email, $token, $userId);
    logMessage('INFO', 'Account registered, verification email sent', ['user_id' => $userId, 'plan' => $plan]);

    // 9. Svara - alltid samma generiska svar.
    echo json_encode($genericResponse);
    exit;
}

// Endpoint: lösenordsinloggning (email + password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'password_login') {
    // Login CSRF: a cross-site form could otherwise sign the victim's browser
    // into an attacker's account, whose inbox then collects what they create.
    // Same guard, and same answer, as verify_2fa below.
    if (!requireSameOriginRequest()) {
        logMessage('WARNING', 'Rejected cross-origin password_login request');
        echo json_encode(['success' => false, 'error' => 'Invalid request origin']);
        exit;
    }
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    // What login_attempts stores for this address: its keyed reference, or
    // '' when no key is configured - never the address itself.
    $attemptRef = emailLogRef($email) ?? '';
    // Rate limiting / brute-force protection (measure by IP only)
    try {
        $ip = getVisitorIp();
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

        // Per account as well: the IP limit alone lets a password be guessed
        // from many addresses. Counted by the email tried (login_attempts
        // records it for unknown accounts too, so the answer is the same
        // whether the account exists). The IP is not flagged here - many
        // IPs, one target, and the owner may share none of them.
        // login_attempts.email holds the keyed emailLogRef() of the address,
        // never the address itself. Without a configured key there is no
        // reference, rows get '' and this per-account check is skipped (the
        // per-IP limit above still applies) - comparing '' would lump every
        // attempt together and lock out everyone.
        $maxFailsAccount = 10;
        if ($attemptRef !== '') {
            $s2 = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempt_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
            $s2->execute([$attemptRef, $windowMinutes]);
            $failsAccount = (int)$s2->fetchColumn();
        } else {
            $failsAccount = 0;
        }
        if ($failsAccount >= $maxFailsAccount) {
            logMessage('WARNING', 'Password login throttled for account', ['ip' => $ip, 'email_ref' => $attemptRef]);
            echo json_encode(['success' => false, 'error' => 'Too many failed login attempts for this account. Try again in a few minutes or use the magic link.']);
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
    // Hämta användare och verifiera hash. Kolla email_verified_at-kolumnen
    // FÖRE SELECT:en (se motsvarande kommentar vid magic link-endpointen
    // ovan) så en omigrerad prod-databas inte kraschar hela inloggningen.
    $hasVerifiedCol = tableHasColumn('pro_users', 'email_verified_at');
    $stmt = $pdo->prepare($hasVerifiedCol
        ? "SELECT id, email_enc, password_hash, email_verified_at FROM pro_users WHERE email_hash = ? LIMIT 1"
        : "SELECT id, email_enc, password_hash FROM pro_users WHERE email_hash = ? LIMIT 1");
    $stmt->execute([piiEmailLookupHash($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // The stored address for the session. Should it not decrypt, the typed
    // one is the same address (it hashed to this row), modulo case.
    $userEmail = $user ? (piiEmailOpen($user['email_enc'], ['user_id' => (int) $user['id']]) ?? $email) : '';
    if (!$user) {
        // Record failed attempt (unknown user) for IP + email
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, 0)");
            $ins->execute([$ip, $attemptRef]);
        } catch (Exception $e) {
            // ignore
        }
        // Do not reveal whether the user exists
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password. If you normally sign in with a link, use the magic link instead.']);
        exit;
    }
    if (empty($user['password_hash'])) {
        // Record failed attempt (no password set) to slow abuse
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 0)");
            $ins->execute([$ip, $attemptRef, $user['id']]);
        } catch (Exception $e) {
            // ignore
        }
        // Same answer as a wrong password, so this cannot tell an attacker
        // which addresses have an account. The hint helps real magic-link users.
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password. If you normally sign in with a link, use the magic link instead.']);
        exit;
    }
    if (!password_verify($password, $user['password_hash'])) {
        // Record failed attempt
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 0)");
            $ins->execute([$ip, $attemptRef, $user['id']]);
        } catch (Exception $e) {
            // ignore
        }
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password. If you normally sign in with a link, use the magic link instead.']);
        exit;
    }
    // Login is tier-neutral (see documentaion/ACCOUNT_TIERS.md §5), but the
    // account must be email-verified. Fallback: if the migration (#53) hasn't
    // run, the column doesn't exist yet - treat every account as verified.
    $isVerified = !$hasVerifiedCol || !is_null($user['email_verified_at']);
    if (!$isVerified) {
        // Record failed attempt (unverified account). Same generic error as a
        // wrong password so this endpoint can't be used to enumerate accounts.
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 0)");
            $ins->execute([$ip, $attemptRef, $user['id']]);
        } catch (Exception $e) {
            // ignore
        }
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password. If you normally sign in with a link, use the magic link instead.']);
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
            $_SESSION['pro_user_email'] = $userEmail;
            $_SESSION['pro_login_method'] = 'password';
            recordProUserLogin($user['id']);
            try {
                $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 1)");
                $ins->execute([$ip, $attemptRef, $user['id']]);
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
            'email' => $userEmail,
            'created_at' => time(),
        ];
        echo json_encode(['success' => true, 'requires_2fa' => true]);
        exit;
    }

    // Sätt session och logga in
    $_SESSION['pro_user_id'] = $user['id'];
    $_SESSION['pro_user_email'] = $userEmail;
    $_SESSION['pro_login_method'] = 'password';
    recordProUserLogin($user['id']);
    // Record successful attempt
    try {
        $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 1)");
        $ins->execute([$ip, $attemptRef, $user['id']]);
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
        $ins->execute([$ip, emailLogRef((string) $pendingEmail) ?? '', $userId]);
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
    $token = trim((string) $_GET['confirm_profile_change']);
    global $pdo;
    try {
        // Validates the format and matches the stored hash (login_tokens.php).
        $row = pendingChangeFindByToken($pdo, $token);
        if (!$row) {
            echo "Invalid or expired link.";
            exit;
        }
        if ($row['used']) {
            echo "This link has already been used.";
            exit;
        }
        if (authTokenIsExpired($row['expires_at'])) {
            echo "This link has expired.";
            exit;
        }

        // Atomically claim this token before doing any work. Email link
        // scanners/prefetchers (Safe Links, antivirus gateways, etc.) can
        // fetch this URL on their own, close in time to the user's real
        // click. Without this, both requests would pass the `used` check
        // above and race to run the same deletes concurrently, which can
        // deadlock in MySQL - one request's transaction fails with "An
        // error occurred..." even though the other one already completed
        // the action (e.g. the account really was deleted).
        if (!pendingChangeClaim($pdo, (int) $row['id'])) {
            echo "This link has already been used.";
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
            // Without the keys the new address could not be looked up (the
            // "taken" check would find nothing) nor found at the next login.
            if (!piiEmailRequireKeys('confirm email change')) {
                echo "An error occurred during confirmation.";
                exit;
            }
            // Ensure still not taken
            $check = $pdo->prepare("SELECT id FROM pro_users WHERE email_hash = ? AND id <> ? LIMIT 1");
            $check->execute([piiEmailLookupHash($newEmail), $userId]);
            if ($check->fetch()) {
                echo "The email address is already taken by another user.";
                exit;
            }
            // Fetch old email before applying change so we can notify it and offer undo
            $oldEmail = (string) (proUserEmail($pdo, $userId) ?? '');

            // Apply the email change. Do NOT create an undo token.
            // (Token already marked used by the atomic claim above.)
            // email_enc/email_hash change in the same statement, so they can
            // never describe the old address (pii_crypto.php).
            $pii = piiEmailWriteFields('pro_users', $newEmail);
            $u = $pdo->prepare("UPDATE pro_users SET email = ?" . ($pii ? ", email_enc = ?, email_hash = ?" : "") . " WHERE id = ?");
            $u->execute($pii ? [$newEmail, $pii['email_enc'], $pii['email_hash'], $userId] : [$newEmail, $userId]);

            // #267: a confirmed email change proves the new address - record it (no trial granted).
            proTrialRecordClaim($pdo, $newEmail, (string)($config['trial']['hash_key'] ?? ''));

            // Notify old email that account email has changed (no undo link)
            try {
                if (!empty($oldEmail) && filter_var($oldEmail, FILTER_VALIDATE_EMAIL) && $oldEmail !== $newEmail) {
                    $subjectOld = 'Your Mail Shield email has been changed';
                    $messageOld = "Hello,\n\nThis is a notification that the email address for your Mail Shield account was changed from " . $oldEmail . " to " . $newEmail . ".\n\nIf you did NOT authorize this change, please contact support immediately.\n\nRegards,\nThe Mail Shield Team";
                    $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                    $headers = [];
                    $headers[] = 'From: Mail Shield <' . $from . '>';
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
            $oldEmail = (string) (proUserEmail($pdo, $userId) ?? '');

            // Update password hash and set password_changed_at
            try {
                $u = $pdo->prepare("UPDATE pro_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?");
                $u->execute([$hash, $userId]);
            } catch (Exception $e) {
                // Fallback: try update without password_changed_at
                $u = $pdo->prepare("UPDATE pro_users SET password_hash = ? WHERE id = ?");
                $u->execute([$hash, $userId]);
            }

            // A password change invalidates any "remember this browser"
            // cookies — see documentaion/2FA_DESIGN.md §2.
            TwoFactorAuth::revokeAllTrustedDevices($userId);

            // Notify old email that password has changed (no undo link)
            try {
                if (!empty($oldEmail) && filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
                    $subjectOld = 'Your Mail Shield password has been changed';
                    $messageOld = "Hello,\n\nThis is a notification that the password for your Mail Shield account associated with " . $oldEmail . " has been changed.\n\nIf you did NOT authorize this change, contact support immediately.\n\nRegards,\nThe Mail Shield Team";
                    $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                    $headers = [];
                    $headers[] = 'From: Mail Shield <' . $from . '>';
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

                    // (Token already marked used by the atomic claim above.)

                    // Delete the user's temp addresses (and their stored emails/
                    // attachments) BEFORE deleting the pro_users row itself, the
                    // same way cron/cleanup.php's cleanupInactiveRegularAccounts()
                    // does. temp_emails.pro_user_id is a real FK to pro_users.id,
                    // so leaving these rows behind makes the final DELETE FROM
                    // pro_users fail with a foreign key constraint violation.
                    $tstmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ?");
                    $tstmt->execute([$userId]);
                    $addresses = $tstmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($addresses as $address) {
                        $tempEmailId = $address['id'];

                        $sstmt = $pdo->prepare("SELECT id FROM stored_emails WHERE temp_email_id = ?");
                        $sstmt->execute([$tempEmailId]);
                        $emailIds = $sstmt->fetchAll(PDO::FETCH_COLUMN);

                        foreach ($emailIds as $emailId) {
                            $astmt = $pdo->prepare("SELECT filename, file_path FROM email_attachments WHERE email_id = ?");
                            $astmt->execute([$emailId]);
                            foreach ($astmt->fetchAll(PDO::FETCH_ASSOC) as $attachment) {
                                $fullPath = __DIR__ . '/' . ltrim($attachment['file_path'], '/');
                                if (file_exists($fullPath)) {
                                    @unlink($fullPath);
                                }
                            }
                            $delAttachments = $pdo->prepare("DELETE FROM email_attachments WHERE email_id = ?");
                            $delAttachments->execute([$emailId]);
                        }

                        $delStoredEmails = $pdo->prepare("DELETE FROM stored_emails WHERE temp_email_id = ?");
                        $delStoredEmails->execute([$tempEmailId]);

                        $delTempEmail = $pdo->prepare("DELETE FROM temp_emails WHERE id = ?");
                        $delTempEmail->execute([$tempEmailId]);
                        if ($delTempEmail->rowCount() > 0) {
                            deleteDirectAdminForwarder($address['unique_address']);
                        }
                    }

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

                    echo "Your Mail Shield account has been deleted.";
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
            logMessage('ERROR', 'Failed applying pending_profile_changes', ['error' => $e->getMessage(), 'pending_change_id' => $row['id'] ?? null]);
        } else {
            error_log('Failed applying pending_profile_changes: ' . $e->getMessage());
        }
        echo "An error occurred during confirmation.";
        exit;
    }
}

// Endpoint: undo a recently applied profile change (from old-email notification)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['undo_profile_change'])) {
    $token = trim((string) $_GET['undo_profile_change']);
    global $pdo;
    try {
        // Validates the format and matches the stored hash (login_tokens.php).
        $row = pendingChangeFindByToken($pdo, $token);
        if (!$row) {
            echo "Invalid or expired link.";
            exit;
        }
        if ($row['used']) {
            echo "This link has already been used or revoked.";
            exit;
        }
        if (authTokenIsExpired($row['expires_at'])) {
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
            // Without the keys the reverted address could not be found at login.
            if (!piiEmailRequireKeys('undo email change')) {
                echo "An error occurred during revert.";
                exit;
            }
            // Atomically claim the undo token before reverting, so two
            // concurrent requests cannot both apply the revert.
            if (!pendingChangeClaim($pdo, (int) $row['id'])) {
                echo "This link has already been used or revoked.";
                exit;
            }
            // Revert email back to oldEmail (and its email_enc/email_hash, same statement)
            $pii = piiEmailWriteFields('pro_users', $oldEmail);
            $u = $pdo->prepare("UPDATE pro_users SET email = ?" . ($pii ? ", email_enc = ?, email_hash = ?" : "") . " WHERE id = ?");
            $u->execute($pii ? [$oldEmail, $pii['email_enc'], $pii['email_hash'], $userId] : [$oldEmail, $userId]);
            echo "Email change reverted. Your email is now: " . htmlspecialchars($oldEmail);
            exit;
        } elseif ($action === 'undo_set_password') {
            $oldHash = $data['old_hash'] ?? '';
            if ($oldHash === '') {
                echo "Invalid request.";
                exit;
            }
            // Atomically claim the undo token before reverting (see above).
            if (!pendingChangeClaim($pdo, (int) $row['id'])) {
                echo "This link has already been used or revoked.";
                exit;
            }
            // Revert password hash
            $u = $pdo->prepare("UPDATE pro_users SET password_hash = ? WHERE id = ?");
            $u->execute([$oldHash, $userId]);
            echo "Password change reverted. You can log in with your previous password.";
            exit;
        } else {
            echo "Unknown action type.";
            exit;
        }
    } catch (Exception $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Failed applying undo pending_profile_changes', ['error' => $e->getMessage(), 'pending_change_id' => $row['id'] ?? null]);
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