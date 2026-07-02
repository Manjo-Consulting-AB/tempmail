<?php
/**
 * Backend för magic link-inloggning (pro-användare)
 * Endpoints: request_login_link, verify_token
 */
require_once 'config.php';

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

// Hjälpfunktion: skapa och spara login-token
function createLoginToken($userId, $validMinutes = 30) {
    global $pdo;
    $token = generateLoginToken();
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validMinutes} minutes"));
    $stmt = $pdo->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $token, $expiresAt]);
    return $token;
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

// Endpoint: begär inloggningslänk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_login_link') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit;
    }
    // Check PRO status before sending magic link. Do NOT create a new pro user here.
    $stmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $token = null;
    $sent = false;

    if ($row) {
        // Consider NULL as "forever". User is PRO if pro_expires_at is NULL (lifetime)
        // or if the pro_expires_at timestamp is in the future.
        $isPro = (is_null($row['pro_expires_at']) || (strtotime($row['pro_expires_at']) >= time()));
        if ($isPro) {
            $userId = $row['id'];
            $token = createLoginToken($userId);
            $sent = sendLoginEmail($email, $token);
        } else {
            // Not PRO or expired: do not send email. We intentionally do not reveal this to the caller.
            logMessage('INFO', 'Magic link requested for non-PRO or expired user', ['email' => $email]);
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
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit;
    }
    // Validate voucher code: alphanumeric, dashes, max 64 chars
    if ($code === '' || strlen($code) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
        echo json_encode(['success' => false, 'error' => 'Invalid voucher code format']);
        exit;
    }

    try {
        // Start transaction and lock voucher row
        $pdo->beginTransaction();
        $vstmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ? FOR UPDATE");
        $vstmt->execute([$code]);
        $v = $vstmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Invalid code or expired']);
            exit;
        }
        if (!(int)$v['is_active']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'This code is not active']);
            exit;
        }
        if (!is_null($v['expires_at']) && strtotime($v['expires_at']) <= time()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'This code has expired']);
            exit;
        }
        if (!is_null($v['max_uses']) && (int)$v['current_uses'] >= (int)$v['max_uses']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'This code has been fully redeemed']);
            exit;
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
            $up = $pdo->prepare("UPDATE pro_users SET pro_expires_at = ? WHERE id = ?");
            $up->execute([$newExpires, $userId]);
        } else {
            // Create new pro user with default TTL
            // Disallow creating accounts using the service domain
            global $config;
            $forbiddenDomain = strtolower($config['email']['domain'] ?? 'manjo.me');
            $parts = explode('@', $email);
            $domainPart = isset($parts[1]) ? strtolower($parts[1]) : '';
            if ($domainPart === $forbiddenDomain) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Email addresses at this domain are not allowed']);
                exit;
            }
            if (is_null($v['duration_days'])) {
                $newExpires = null;
            } else {
                $dur = (int)$v['duration_days'];
                $newExpires = date('Y-m-d H:i:s', strtotime("+{$dur} days"));
            }
            $defaultTtl = 1;
            $ins = $pdo->prepare("INSERT INTO pro_users (email, pro_expires_at, address_ttl_days) VALUES (?, ?, ?)");
            $ins->execute([$email, $newExpires, $defaultTtl]);
            $userId = $pdo->lastInsertId();
        }

        // Increment voucher usage
        // Prevent the same user from redeeming the same voucher more than once
        $checkRedeem = $pdo->prepare("SELECT id FROM redemption_log WHERE user_id = ? AND voucher_id = ? LIMIT 1");
        $checkRedeem->execute([$userId, $v['id']]);
        if ($checkRedeem->fetch()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'You have already redeemed this code']);
            exit;
        }

        $upv = $pdo->prepare("UPDATE vouchers SET current_uses = current_uses + 1 WHERE id = ?");
        $upv->execute([$v['id']]);

        // Log redemption
        $rstmt = $pdo->prepare("INSERT INTO redemption_log (user_id, voucher_id) VALUES (?, ?)");
        $rstmt->execute([$userId, $v['id']]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Voucher redeemed successfully']);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logMessage('ERROR', 'Voucher redemption failed', ['error' => $e->getMessage(), 'email' => $email, 'code' => $code]);
        echo json_encode(['success' => false, 'error' => 'Redemption failed']);
        exit;
    }
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
    $stmt = $pdo->prepare("SELECT id, email, password_hash FROM pro_users WHERE email = ? LIMIT 1");
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
    // Sätt session och logga in
    session_start();
    $_SESSION['pro_user_id'] = $user['id'];
    $_SESSION['pro_user_email'] = $user['email'];
    // Record successful attempt
    try {
        $ins = $pdo->prepare("INSERT INTO login_attempts (ip, email, user_id, success) VALUES (?, ?, ?, 1)");
        $ins->execute([$ip, $email, $user['id']]);
    } catch (Exception $e) {
        // ignore
    }
    logMessage('INFO', 'Pro user logged in via password', ['user_id' => $user['id']]);
    // Also try to fetch the latest active temporary address for this pro user
    try {
        $col = 'unique_address';
        $colStmt = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'unique_address'");
        if ($colStmt->rowCount() === 0) {
            $col = 'address';
        }
        $ae = $pdo->prepare("SELECT $col AS local_part, expires_at FROM temp_emails WHERE pro_user_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
        $ae->execute([$user['id']]);
        $ar = $ae->fetch(PDO::FETCH_ASSOC);
        if ($ar && !empty($ar['local_part'])) {
            $currentAddress = $ar['local_part'] . '@' . ($config['email']['domain'] ?? 'manjo.me');
            $expiresAt = $ar['expires_at'] ?? null;
        } else {
            $currentAddress = null;
            $expiresAt = null;
        }
    } catch (Exception $e) {
        $currentAddress = null;
        $expiresAt = null;
    }

    echo json_encode(['success' => true, 'redirect' => 'pro.php', 'current_address' => $currentAddress, 'expires_at' => $expiresAt]);
    exit;
}

// Endpoint: verifiera token och logga in
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    $token = $_GET['token'];
    global $pdo;
    $stmt = $pdo->prepare("SELECT lt.id, lt.user_id, lt.expires_at, lt.used, pu.email FROM login_tokens lt JOIN pro_users pu ON lt.user_id = pu.id WHERE lt.token = ? LIMIT 1");
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
    // Markera token som använd
    $stmt = $pdo->prepare("UPDATE login_tokens SET used = 1 WHERE id = ?");
    $stmt->execute([$row['id']]);
    // Logga in användaren (sätt session)
    session_start();
    $_SESSION['pro_user_id'] = $row['user_id'];
    $_SESSION['pro_user_email'] = $row['email'];
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