<?php
/**
 * Simple endpoint to get/update pro user profile/settings (JSON)
 */
require_once 'config.php';
require_once __DIR__ . '/client/backend/bootstrap.php';
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
        // No key configured — store plaintext (but log warning)
        logMessage('WARNING', 'WEBHOOKS_KEY not set; storing webhook secret in plaintext');
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
            $profile = ['email' => $row['email'] ?? '', 'pro_expires_at' => $row['pro_expires_at'] ?? null];
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
                'digest_tz' => $row['digest_tz'] ?? null
            ];
            send_json($res);
            break;

        case 'update_digest_settings':
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
                $subject = 'Confirm your email change for TempMail Pro';
                $message = "Hello,\n\nA request was made to change the email for your TempMail Pro account to this address.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email.\n\nRegards,\nThe TempMail Team";
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: TempMail <' . $from . '>';
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
                $subject = 'Confirm your password change for TempMail Pro';
                $message = "Hello,\n\nA request was made to change the password for your TempMail Pro account.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email or contact support.\n\nRegards,\nThe TempMail Team";
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: TempMail <' . $from . '>';
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
                $headers[] = 'From: TempMail <' . $from . '>';
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headersStr = implode("\r\n", $headers);

                if ($action === 'update_email') {
                    $to = $data['new_email'] ?? '';
                    if (!$to) {
                        send_json(['success' => false, 'error' => 'Pending email address missing']);
                    }
                    $subject = 'Confirm your email change for TempMail Pro';
                    $message = "Hello,\n\nThis is a resend of the confirmation link for your TempMail Pro email change request.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email.\n\nRegards,\nThe TempMail Team";
                    @mail($to, $subject, $message, $headersStr);
                    send_json(['success' => true, 'message' => 'Confirmation link resent to the new email address']);
                }

                if ($action === 'set_password') {
                    $to = $_SESSION['pro_user_email'] ?? '';
                    if (!$to) {
                        send_json(['success' => false, 'error' => 'User email not available']);
                    }
                    $subject = 'Confirm your password change for TempMail Pro';
                    $message = "Hello,\n\nThis is a resend of the confirmation link for your TempMail Pro password change request.\n\nPlease confirm the change by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email or contact support.\n\nRegards,\nThe TempMail Team";
                    @mail($to, $subject, $message, $headersStr);
                    send_json(['success' => true, 'message' => 'Confirmation link resent to your email address']);
                }

                if ($action === 'delete_account') {
                    $to = $_SESSION['pro_user_email'] ?? '';
                    if (!$to) {
                        send_json(['success' => false, 'error' => 'User email not available']);
                    }
                    $subject = 'Confirm your account deletion for TempMail Pro';
                    $message = "Hello,\n\nThis is a resend of the confirmation link for your TempMail Pro account deletion request.\n\nPlease confirm the deletion by clicking the link below:\n\n" . $confirmUrl . "\n\nIf you did not request this change, ignore this email or contact support.\n\nRegards,\nThe TempMail Team";
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
                $s = $pdo->prepare("SELECT id, name, url, kind, config, secret, filter_mode, created_at FROM pro_webhooks WHERE user_id = ? ORDER BY id DESC");
                $s->execute([$userId]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
                // Decode config JSON for response
                foreach ($rows as &$r) {
                    $r['config'] = $r['config'] ? json_decode($r['config'], true) : null;
                    unset($r['secret']); // Do not expose secret in list
                }
                echo json_encode(['success' => true, 'webhooks' => $rows]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed listing webhooks', ['user_id' => $userId, 'error' => $e->getMessage()]);
                echo json_encode(['success' => false, 'error' => 'Could not fetch webhooks']);
            }
            break;

        case 'feed_get_token':
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

        case 'webhook_create':
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
            // Only allow https URLs (except localhost for testing)
            $parsedUrl = parse_url($url);
            $host = $parsedUrl['host'] ?? '';
            $scheme = $parsedUrl['scheme'] ?? '';
            if ($scheme !== 'https' && !in_array($host, ['localhost', '127.0.0.1'])) {
                send_json(['success' => false, 'error' => 'Only HTTPS URLs are allowed']);
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
                            $post = ['token' => $token, 'user' => $userKey, 'message' => $payload['message'], 'title' => 'TempMail Test'];
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
                        if (function_exists('curl_init')) {
                            $ch = curl_init($url);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
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
                logMessage('INFO', 'Webhook deleted', ['user_id' => $userId, 'webhook_id' => $wid]);
                send_json(['success' => true]);
            } catch (Exception $e) {
                logMessage('ERROR', 'Failed deleting webhook', ['user_id' => $userId, 'error' => $e->getMessage()]);
                send_json(['success' => false, 'error' => 'Could not delete webhook']);
            }
            break;

        case 'webhook_set_filter_mode':
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
                $subject = 'Confirm account deletion for TempMail Pro';
                $message = "Hello,\n\nA request was made to permanently delete your TempMail Pro account.\n\nIf you want to proceed, please confirm by clicking the link below (valid for 2 hours):\n\n" . $confirmUrl . "\n\nIf you did not request this, ignore this email.\n\nRegards,\nThe TempMail Team";
                $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
                $headers = [];
                $headers[] = 'From: TempMail <' . $from . '>';
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
