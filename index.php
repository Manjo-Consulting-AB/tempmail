<?php
/**
 * TempMail - Temporära E-postadresser (Production Version)
 * Huvudsida för webbgränssnitt och API-endpoints
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

// Start session to detect logged-in pro users for AJAX actions
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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

// Hantera URL-parameter för direkt adress-access
$urlAddress = null;
$urlExpiresAt = null;
if (isset($_GET['address']) && !empty($_GET['address'])) {
    $urlAddress = trim($_GET['address']);
    // Validera att adressen bara innehåller giltiga tecken (hexadecimala)
    if (preg_match('/^[a-f0-9]{8,16}$/i', $urlAddress)) {
        // Kontrollera om adressen finns i databasen och hämta expires_at
        $stmt = $pdo->prepare("SELECT unique_address, expires_at FROM temp_emails WHERE unique_address = ?");
        $stmt->execute([$urlAddress]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $urlAddress = $row['unique_address'];
            $urlExpiresAt = $row['expires_at'];
        } else {
            $urlAddress = null; // Adressen finns inte
        }
    } else {
        $urlAddress = null; // Ogiltigt format
    }
}

// Hantera AJAX-förfrågningar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $rawAction = $_POST['action'] ?? '';
    
    // Detect suspicious patterns in action (and log attack attempts)
    $suspicious = function_exists('detectSuspiciousPatterns') ? detectSuspiciousPatterns((string)$rawAction) : [];
    if (!empty($suspicious)) {
        logMessage('WARNING', 'Suspicious POST action attempted', [
            'patterns' => $suspicious,
            'action' => mb_substr($rawAction, 0, 200),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        echo json_encode(['success' => false, 'error' => 'Ogiltig förfrågan']);
        exit;
    }
    
    // Sanitize and validate action - alphanumeric with underscores only
    $action = function_exists('sanitizeAlphanumeric') 
        ? sanitizeAlphanumeric($rawAction, 50, true) ?? '' 
        : preg_replace('/[^a-zA-Z0-9_-]/', '', $rawAction);
    
    error_log('AJAX POST action: ' . $action);
    error_log('AJAX POST data: ' . json_encode($_POST));
    try {
        switch ($action) {
            case 'get_expires_at':
                // Returnera expires_at för given adress
                $address = $_POST['address'] ?? '';
                if (!$address || !isValidAddress($address)) {
                    echo json_encode(['success' => false, 'error' => 'Ogiltig adress']);
                    break;
                }
                $stmt = $pdo->prepare("SELECT expires_at FROM temp_emails WHERE unique_address = ?");
                $stmt->execute([$address]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['expires_at']) {
                    echo json_encode(['success' => true, 'expires_at' => $row['expires_at']]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Adress hittades inte']);
                }
                break;
            case 'check_address_owner':
                // Quick owner check for client-side privacy: returns owner_pro_user_id (or null) and is_owner flag
                $address = trim($_POST['address'] ?? '');
                if (!$address || !isValidAddress($address)) {
                    echo json_encode(['success' => false, 'error' => 'Ogiltig adress']);
                    break;
                }
                try {
                    // Detect whether DB has is_personal column (backwards compatible)
                    $hasIsPersonal = false;
                    try {
                        $colStmt = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'is_personal'");
                        $hasIsPersonal = ($colStmt && $colStmt->rowCount() > 0);
                    } catch (Exception $_) {
                        $hasIsPersonal = false;
                    }

                    if ($hasIsPersonal) {
                        $stmt = $pdo->prepare("SELECT id, pro_user_id, is_personal FROM temp_emails WHERE unique_address = ? LIMIT 1");
                    } else {
                        $stmt = $pdo->prepare("SELECT id, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1");
                    }
                    $stmt->execute([$address]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $ownerId = null;
                    $isPersonal = false;
                    if ($row) {
                        if (!empty($row['pro_user_id'])) {
                            $ownerId = (int)$row['pro_user_id'];
                        }
                        if ($hasIsPersonal && isset($row['is_personal'])) {
                            $isPersonal = (bool)$row['is_personal'];
                        }
                    }
                    $currentUserId = (int)($_SESSION['pro_user_id'] ?? 0);
                    $isOwner = $ownerId !== null && $currentUserId === $ownerId;
                    echo json_encode(['success' => true, 'owner_pro_user_id' => $ownerId, 'is_owner' => $isOwner, 'is_personal' => $isPersonal]);
                } catch (Exception $e) {
                    logMessage('ERROR', 'Failed checking address owner', ['error' => $e->getMessage(), 'address' => $address]);
                    echo json_encode(['success' => false, 'error' => 'Could not check address owner']);
                }
                break;
            case 'create_personal':
                // Create a personal address for logged-in pro users with 50 years TTL
                if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
                    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                    break;
                }
                $rawLocal = $_POST['local'] ?? '';
                // Detect suspicious patterns
                $suspicious = function_exists('detectSuspiciousPatterns') ? detectSuspiciousPatterns((string)$rawLocal) : [];
                if (!empty($suspicious)) {
                    logMessage('WARNING', 'Suspicious create_personal input', ['patterns' => $suspicious, 'user_id' => $_SESSION['pro_user_id']]);
                    echo json_encode(['success' => false, 'error' => 'Invalid request']);
                    break;
                }
                // Validate and sanitize local part using central function (already lowercased)
                $local = function_exists('sanitizeLocalPart') ? sanitizeLocalPart($rawLocal, 3, 64) : null;
                if (!$local) {
                    echo json_encode(['success' => false, 'error' => 'Invalid local part']);
                    break;
                }
                // Blacklisted local parts that users may NOT choose
                $blacklist = ['jj', 'roland', 'investering'];
                if (in_array($local, $blacklist, true)) {
                    echo json_encode(['success' => false, 'error' => 'This local part is not allowed']);
                    break;
                }
                // Check if already exists
                $stmt = $pdo->prepare("SELECT id FROM temp_emails WHERE unique_address = ? LIMIT 1");
                $stmt->execute([$local]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Address already taken']);
                    break;
                }
                // Enforce max 3 personal addresses per pro user
                try {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1");
                    $cntStmt->execute([$_SESSION['pro_user_id']]);
                    $cntRow = $cntStmt->fetch(PDO::FETCH_ASSOC);
                    $existingCount = (int)($cntRow['cnt'] ?? 0);
                    if ($existingCount >= 3) {
                        echo json_encode(['success' => false, 'error' => 'Maximum of 3 personal addresses allowed']);
                        break;
                    }
                } catch (Exception $e) {
                    // If counting fails, log and continue (but do not allow creation as safe default)
                    logMessage('WARNING', 'Could not verify personal address count', ['error' => $e->getMessage()]);
                    echo json_encode(['success' => false, 'error' => 'Could not verify address quota']);
                    break;
                }
                // Create with 50 years TTL
                $expiresAt = date('Y-m-d H:i:s', strtotime('+50 years'));
                try {
                    $ins = $pdo->prepare("INSERT INTO temp_emails (unique_address, expires_at, pro_user_id, is_personal) VALUES (?, ?, ?, 1)");
                    $ins->execute([$local, $expiresAt, $_SESSION['pro_user_id']]);
                    logMessage('INFO', 'Personal address created', ['user_id' => $_SESSION['pro_user_id'], 'address' => $local]);
                    // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                    echo json_encode(['success' => true, 'address' => $local, 'full_address' => $local . '@' . $config['email']['domain'], 'expires_at' => $expiresAt]);
                } catch (Exception $e) {
                    logMessage('ERROR', 'Failed creating personal address', ['error' => $e->getMessage()]);
                    echo json_encode(['success' => false, 'error' => 'Could not create address']);
                }
                break;
            case 'list_personal':
                if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
                    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                    break;
                }
                try {
                    $stmt = $pdo->prepare("SELECT id, unique_address AS address, expires_at FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1 ORDER BY created_at DESC");
                    $stmt->execute([$_SESSION['pro_user_id']]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $list = [];
                    foreach ($rows as $r) {
                        $list[] = [
                            'id' => $r['id'],
                            'address' => $r['address'],
                            'full_address' => $r['address'] . '@' . $config['email']['domain'],
                            'expires_at' => $r['expires_at']
                        ];
                    }
                    echo json_encode(['success' => true, 'personal' => $list]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to list personal addresses']);
                }
                break;
            case 'delete_personal':
                if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
                    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['success' => false, 'error' => 'Invalid id']);
                    break;
                }
                try {
                    // Ensure the address belongs to this pro user and is a personal address
                    $stmt = $pdo->prepare("SELECT unique_address FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
                    $stmt->execute([$id, $_SESSION['pro_user_id']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        echo json_encode(['success' => false, 'error' => 'Address not found or not owned by user']);
                        break;
                    }
                    $unique = $row['unique_address'];

                    $pdo->beginTransaction();
                    // Delete the personal address row (DB FK will cascade stored_emails)
                    $d2 = $pdo->prepare("DELETE FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
                    $d2->execute([$id, $_SESSION['pro_user_id']]);

                    $pdo->commit();
                    logMessage('INFO', 'Personal address deleted', ['user_id' => $_SESSION['pro_user_id'], 'address' => $unique]);
                    echo json_encode(['success' => true, 'deleted_address' => $unique]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    logMessage('ERROR', 'Failed deleting personal address', ['error' => $e->getMessage()]);
                    echo json_encode(['success' => false, 'error' => 'Delete failed']);
                }
                break;
            case 'generate':
                // Generera ny temporär adress
                $address = generateUniqueString();
                // Determine expires_at: if a pro user is logged in, use their preference
                $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                if (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id']) {
                    try {
                        $stmt = $pdo->prepare("SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1");
                        $stmt->execute([$_SESSION['pro_user_id']]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && isset($row['ttl_days'])) {
                            $ttlDays = (int)$row['ttl_days'];
                            // enforce limits
                            if ($ttlDays < 1) $ttlDays = 1;
                            if ($ttlDays > 7) $ttlDays = 7;
                            $expiresAt = date('Y-m-d H:i:s', strtotime("+$ttlDays days"));
                        }
                    } catch (Exception $e) {
                        logMessage('WARNING', 'Could not fetch pro user TTL, falling back to default', ['error' => $e->getMessage()]);
                    }
                }

                // Pass custom expires to saveNewAddress via global (keeps signature backward compatible)
                $GLOBALS['__custom_expires_at'] = $expiresAt;
                $proUserId = (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id']) ? $_SESSION['pro_user_id'] : null;
                // If this is a pro user, ensure they only have one non-personal temp address at a time.
                if ($proUserId) {
                    try {
                        $pdo->beginTransaction();
                        // Delete any existing non-personal temp addresses for this pro user (will cascade stored_emails)
                        $del = $pdo->prepare("DELETE FROM temp_emails WHERE pro_user_id = ? AND is_personal = 0");
                        $del->execute([$proUserId]);
                        // Now insert new address
                        $saved = saveNewAddress($address, $proUserId);
                        $pdo->commit();
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                } else {
                    $saved = saveNewAddress($address, $proUserId);
                }

                if ($saved) {
                    unset($GLOBALS['__custom_expires_at']);
                    logMessage('INFO', 'New temporary address generated via AJAX', ['address' => $address, 'expires_at' => $expiresAt]);
                    echo json_encode([
                        'success' => true,
                        'address' => $address,
                        'full_address' => $address . '@' . $config['email']['domain'],
                        'expires_at' => $expiresAt
                    ]);
                } else {
                    unset($GLOBALS['__custom_expires_at']);
                    throw new Exception('Could not create address');
                }
                break;
                
            case 'get_emails':
                // Hämta e-postmeddelanden för adress
                $address = $_POST['address'] ?? '';
                if (!$address || !isValidAddress($address)) {
                    throw new Exception('Ogiltig adress');
                }
                
                // Check if this address is marked as personal - only personal addresses require authentication
                $ownerCheck = $pdo->prepare("SELECT pro_user_id, is_personal FROM temp_emails WHERE unique_address = ? LIMIT 1");
                $ownerCheck->execute([$address]);
                $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
                if ($ownerRow && !empty($ownerRow['pro_user_id']) && !empty($ownerRow['is_personal'])) {
                    // Address is a personal address - verify the requester is the owner
                    $ownerId = (int)$ownerRow['pro_user_id'];
                    $currentUserId = (int)($_SESSION['pro_user_id'] ?? 0);
                    if ($currentUserId !== $ownerId) {
                        logMessage('WARNING', 'Unauthorized access attempt to personal address', [
                            'address' => $address,
                            'owner_id' => $ownerId,
                            'requester_id' => $currentUserId ?: 'anonymous'
                        ]);
                        throw new Exception('Authentication required to access this personal address');
                    }
                }
                
                // Build list of addresses to fetch emails for. Always include the requested address.
                $fullAddress = $address . '@' . $config['email']['domain'];
                $addresses = [$fullAddress];

                // If the requester is a logged-in pro user, also include their personal addresses
                if (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id']) {
                    try {
                        $pstmt = $pdo->prepare("SELECT unique_address FROM temp_emails WHERE pro_user_id = ?");
                        $pstmt->execute([$_SESSION['pro_user_id']]);
                        $rows = $pstmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            if (!empty($r['unique_address'])) {
                                $addresses[] = $r['unique_address'] . '@' . $config['email']['domain'];
                            }
                        }
                        // Make addresses unique
                        $addresses = array_values(array_unique($addresses));
                    } catch (Exception $e) {
                        // If we cannot fetch personal addresses, continue with requested address only
                        logMessage('WARNING', 'Could not fetch personal addresses for get_emails', ['error' => $e->getMessage()]);
                    }
                }

                // Query stored_emails for any of the addresses
                try {
                    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
                    $safe_placeholders = preg_replace('/[^?,]/', '', $placeholders);
                    // Order by received_at DESC so newest messages appear first
                    $sql = "SELECT id, from_address, subject, body_text, body_html, received_at, to_address, expires_at FROM stored_emails WHERE to_address IN ($safe_placeholders) AND ((expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR)) OR (expires_at IS NOT NULL AND expires_at > NOW())) ORDER BY received_at DESC LIMIT ?";
                    $stmt = $pdo->prepare($sql);
                    // bind address params, then cleanup_hours and limit
                    $params = array_merge($addresses, [$config['app']['cleanup_hours'], 50]);
                    $stmt->execute($params);
                    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    logMessage('ERROR', 'Failed fetching emails for addresses', ['error' => $e->getMessage(), 'addresses' => $addresses]);
                    throw $e;
                }
                logMessage('DEBUG', 'Emails fetched via AJAX', [
                    'address' => $address,
                    'count' => count($emails),
                    'addresses_included' => $addresses
                ]);
                
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                echo json_encode([
                    'success' => true,
                    'emails' => $emails
                ]);
                break;

            case 'has_new_emails':
                // Lightweight check: return latest stored_emails.id for this address set
                $address = $_POST['address'] ?? '';
                $lastKnown = (int)($_POST['last_known_id'] ?? 0);
                if (!$address || !isValidAddress($address)) {
                    throw new Exception('Ogiltig adress');
                }

                // Build same addresses list as in get_emails (include personal addresses for pro users)
                $fullAddress = $address . '@' . $config['email']['domain'];
                $addresses = [$fullAddress];
                if (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id']) {
                    try {
                        $pstmt = $pdo->prepare("SELECT unique_address FROM temp_emails WHERE pro_user_id = ?");
                        $pstmt->execute([$_SESSION['pro_user_id']]);
                        $rows = $pstmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            if (!empty($r['unique_address'])) {
                                $addresses[] = $r['unique_address'] . '@' . $config['email']['domain'];
                            }
                        }
                        $addresses = array_values(array_unique($addresses));
                    } catch (Exception $e) {
                        // best-effort: continue with requested address only
                    }
                }

                try {
                    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
                    $safe_placeholders = preg_replace('/[^?,]/', '', $placeholders);
                    $sql = "SELECT COALESCE(MAX(id), 0) AS latest_id, COUNT(*) AS cnt FROM stored_emails WHERE to_address IN ($safe_placeholders) AND ((expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR)) OR (expires_at IS NOT NULL AND expires_at > NOW()))";
                    $stmt = $pdo->prepare($sql);
                    $params = array_merge($addresses, [$config['app']['cleanup_hours']]);
                    $stmt->execute($params);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $latest = (int)($row['latest_id'] ?? 0);
                    $cnt = (int)($row['cnt'] ?? 0);

                    $hasNew = $latest > $lastKnown;
                    // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                    echo json_encode(['success' => true, 'has_new' => $hasNew, 'latest_id' => $latest, 'count' => $cnt]);
                } catch (Exception $e) {
                    logMessage('WARNING', 'has_new_emails check failed', ['error' => $e->getMessage(), 'address' => $address]);
                    echo json_encode(['success' => false, 'error' => 'Could not check for new emails']);
                }
                break;
                
            case 'refresh_emails':
                // Kör IMAP-hämtning direkt med Production PHP IMAP-processor
                $address = $_POST['address'] ?? '';
                if (!$address || !isValidAddress($address)) {
                    throw new Exception('Ogiltig adress');
                }
                
                logMessage('DEBUG', 'Starting refresh_emails for address', ['address' => $address]);
                // Build and log the full address set we will consider (include personal addresses for pro users)
                try {
                    $fullAddress = $address . '@' . $config['email']['domain'];
                    $addresses = [$fullAddress];
                    if (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id']) {
                        try {
                            $pstmt = $pdo->prepare("SELECT unique_address FROM temp_emails WHERE pro_user_id = ?");
                            $pstmt->execute([$_SESSION['pro_user_id']]);
                            $rows = $pstmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($rows as $r) {
                                if (!empty($r['unique_address'])) {
                                    $addresses[] = $r['unique_address'] . '@' . $config['email']['domain'];
                                }
                            }
                            $addresses = array_values(array_unique($addresses));
                        } catch (Exception $_) {
                            // best-effort: fall back to requested address only
                            $addresses = [$fullAddress];
                        }
                    }
                    logMessage('DEBUG', 'refresh_emails will consider addresses', ['address' => $address, 'addresses' => $addresses]);
                } catch (Exception $_) {
                    // ignore logging failures
                }
                // Note: we run IMAP first, then fetch DB results to ensure newly fetched messages are returned
                
                // Anropa Production PHP IMAP-processor direkt
                try {
                    // Kontrollera om PHP IMAP extension finns
                    if (!extension_loaded('imap')) {
                        // Fallback: Använd cURL-baserad lösning eller logga varning
                        logMessage('WARNING', 'PHP IMAP extension saknas - emails kan inte processas automatiskt', [
                            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                            'php_version' => PHP_VERSION
                        ]);
                        
                        $imapResult = [
                            'success' => false,
                            'new_emails' => 0,
                            'message' => 'PHP IMAP extension saknas på servern'
                        ];
                    } else {
                        require_once __DIR__ . '/php_imap_processor.php';
                        
                        $imapProcessor = new ImapProcessor($config, $pdo, $config['app']['debug_mode']);
                        $imapResult = $imapProcessor->processEmails();
                    }
                    
                    $imapSuccess = $imapResult['success'] ?? false;
                    $newEmailsFromImap = $imapResult['new_emails'] ?? 0;
                    $imapMessage = $imapResult['message'] ?? 'Unknown response';
                    
                    logMessage('DEBUG', 'Production IMAP processor completed', [
                        'success' => $imapSuccess,
                        'new_emails_from_imap' => $newEmailsFromImap,
                        'message' => $imapMessage
                    ]);
                    
                } catch (Exception $e) {
                    $imapSuccess = false;
                    $newEmailsFromImap = 0;
                    $imapMessage = 'IMAP-processor fel: ' . $e->getMessage();
                    logMessage('ERROR', 'Production IMAP processing failed', [
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Hämta e-post från databasen efter IMAP-körning
                // Use the same address-set logic as in 'get_emails' so pro users see personal addresses too
                $fullAddress = $address . '@' . $config['email']['domain'];
                $addresses = [$fullAddress];
                if (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id']) {
                    try {
                        $pstmt = $pdo->prepare("SELECT unique_address FROM temp_emails WHERE pro_user_id = ?");
                        $pstmt->execute([$_SESSION['pro_user_id']]);
                        $rows = $pstmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            if (!empty($r['unique_address'])) {
                                $addresses[] = $r['unique_address'] . '@' . $config['email']['domain'];
                            }
                        }
                        $addresses = array_values(array_unique($addresses));
                    } catch (Exception $e) {
                        // If we cannot fetch personal addresses, continue with requested address only
                        $addresses = [$fullAddress];
                    }
                }

                try {
                    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
                    $safe_placeholders = preg_replace('/[^?,]/', '', $placeholders);
                    $sql = "SELECT id, from_address, subject, body_text, body_html, received_at, to_address, expires_at FROM stored_emails WHERE to_address IN ($safe_placeholders) AND ((expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR)) OR (expires_at IS NOT NULL AND expires_at > NOW())) ORDER BY received_at DESC LIMIT ?";
                    $stmt = $pdo->prepare($sql);
                    $params = array_merge($addresses, [$config['app']['cleanup_hours'], 50]);
                    $stmt->execute($params);
                    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    logMessage('ERROR', 'Failed fetching emails after IMAP refresh', ['error' => $e->getMessage(), 'addresses' => $addresses]);
                    $emails = [];
                }
                $emailsAfterCount = count($emails);
                $newEmailsFound = $newEmailsFromImap;
                
                logMessage('DEBUG', 'IMAP refresh completed', [
                    'address' => $address,
                    'emails_before' => null,
                    'emails_after' => $emailsAfterCount,
                    'new_emails_found' => $newEmailsFound,
                    'new_emails_from_imap' => $newEmailsFromImap,
                    'imap_success' => $imapSuccess
                ]);
                
                $message = $imapSuccess 
                    ? ($newEmailsFromImap > 0 ? "Hämtade $newEmailsFromImap nya e-postmeddelanden" : "Inga nya e-postmeddelanden hittades")
                    : "IMAP-hämtning misslyckades: $imapMessage";
                    
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                echo json_encode([
                    'success' => true,
                    'emails' => $emails,
                    'refreshed' => $imapSuccess,
                    'new_emails' => max($newEmailsFound, $newEmailsFromImap),
                    'message' => $message
                ]);
                break;
                
            case 'get_email':
                // Hämta specifikt e-postmeddelande
                $emailId = (int)($_POST['email_id'] ?? 0);
                if (!$emailId) {
                    throw new Exception('Ogiltigt e-post ID');
                }
                
                    // Fetch the email including possible expires_at
                    $stmt = $pdo->prepare("SELECT * FROM stored_emails WHERE id = ?");
                    $stmt->execute([$emailId]);
                    $email = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Check if this email belongs to a personal address - only personal addresses require authentication
                    if ($email && !empty($email['to_address'])) {
                        // Extract local part from to_address (e.g., "abc123" from "abc123@domain.com")
                        $toLocal = explode('@', $email['to_address'])[0] ?? '';
                        if ($toLocal !== '') {
                            $ownerCheck = $pdo->prepare("SELECT pro_user_id, is_personal FROM temp_emails WHERE unique_address = ? LIMIT 1");
                            $ownerCheck->execute([$toLocal]);
                            $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
                            if ($ownerRow && !empty($ownerRow['pro_user_id']) && !empty($ownerRow['is_personal'])) {
                                $ownerId = (int)$ownerRow['pro_user_id'];
                                $currentUserId = (int)($_SESSION['pro_user_id'] ?? 0);
                                if ($currentUserId !== $ownerId) {
                                    logMessage('WARNING', 'Unauthorized access attempt to personal email', [
                                        'email_id' => $emailId,
                                        'owner_id' => $ownerId,
                                        'requester_id' => $currentUserId ?: 'anonymous'
                                    ]);
                                    throw new Exception('Authentication required to access this personal email');
                                }
                            }
                        }
                    }

                    if (!$email) {
                        throw new Exception('E-postmeddelandet hittades inte');
                    }

                    // Determine if the message has expired.
                    $isExpired = false;
                    // Prefer explicit per-message expires_at when present
                    if (!empty($email['expires_at'])) {
                        $expiresTs = strtotime($email['expires_at']);
                        if ($expiresTs !== false && $expiresTs <= time()) {
                            $isExpired = true;
                        }
                    } else {
                        // Fallback to global cleanup window based on received_at
                        if (!empty($email['received_at'])) {
                            $cutoff = date('Y-m-d H:i:s', strtotime('-' . intval($config['app']['cleanup_hours']) . ' hour'));
                            if ($email['received_at'] <= $cutoff) {
                                $isExpired = true;
                            }
                        }
                    }

                    if ($isExpired) {
                        // Do not reveal expired message contents
                        echo json_encode(['success' => false, 'error' => 'E-postmeddelandet har förfallit']);
                        break;
                    }

                    // Fetch attachments for this email
                    try {
                        // If DB has content_id column, include it so we can map cid: references
                        $hasContentId = tableHasColumn('email_attachments', 'content_id');
                        if ($hasContentId) {
                            $ast = $pdo->prepare("SELECT id, filename, file_path, mime_type AS content_type, created_at, content_id FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
                        } else {
                            $ast = $pdo->prepare("SELECT id, filename, file_path, mime_type AS content_type, created_at FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
                        }
                        $ast->execute([$emailId]);
                        $attachments = $ast->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $attachments = [];
                    }

                    // Enrich attachments with a time-limited download URL and build content-id -> signed URL mapping
                    try {
                        // Use a single timestamp for all signature generations in this request.
                        // This ensures inline images and attachment list use identical signatures.
                        $signatureTime = time();
                        
                        foreach ($attachments as &$aRow) {
                            $aidRow = (int)($aRow['id'] ?? 0);
                            if ($aidRow <= 0) continue;
                            $signed = function_exists('generateSignedAttachmentUrl')
                                ? generateSignedAttachmentUrl($aidRow, null, $signatureTime)
                                : (rtrim($config['email']['base_url'] ?? '', '/') ?: '') . '/files.php?id=' . $aidRow;
                            $aRow['download_url'] = $signed;
                        }
                        unset($aRow);

                        // Build content-id -> signed URL mapping (if possible) and replace cid: references in the email body
                        $cidMap = [];
                        // Helper to normalize CID for reliable matching
                        $normalizeCid = static function(string $cid): string {
                            return strtolower(preg_replace('/[^a-z0-9]/i', '', $cid) ?? '');
                        };
                        
                        foreach ($attachments as $a) {
                            $aid = (int)($a['id'] ?? 0);
                            if ($aid <= 0) continue;
                            
                            // Use the attachment's already-generated download_url (same signature)
                            $signedUrl = $a['download_url'] ?? (function_exists('generateSignedAttachmentUrl')
                                ? generateSignedAttachmentUrl($aid, null, $signatureTime)
                                : (rtrim($config['email']['base_url'] ?? '', '/') ?: '') . '/files.php?id=' . $aid);
                            
                            // Map by content_id (original and normalized)
                            if (!empty($a['content_id'])) {
                                $cid = trim($a['content_id']);
                                if ($cid !== '') {
                                    $cidMap[$cid] = $signedUrl;
                                    $normalizedCid = $normalizeCid($cid);
                                    if ($normalizedCid !== '' && $normalizedCid !== $cid) {
                                        $cidMap[$normalizedCid] = $signedUrl;
                                    }
                                }
                            }
                            
                            // Fallback: map by filename for clients that reference attachments by name
                            $fn = $a['filename'] ?? '';
                            if ($fn !== '') {
                                $base = pathinfo($fn, PATHINFO_FILENAME);
                                if (preg_match('/^[A-Za-z0-9_-]{4,}$/', $base)) {
                                    $cidMap[$base] = $signedUrl;
                                    $normalizedBase = $normalizeCid($base);
                                    if ($normalizedBase !== '' && $normalizedBase !== $base) {
                                        $cidMap[$normalizedBase] = $signedUrl;
                                    }
                                }
                            }
                        }

                        if (!empty($cidMap)) {
                            // Use regex callback to robustly replace cid: variants (plain, <...>, url-encoded)
                            $cidReplacer = function($matches) use ($cidMap, $pdo, $emailId, $config, $normalizeCid, $signatureTime) {
                                $raw = $matches[1] ?? '';
                                // Decode potential url-encoded values
                                $token = rawurldecode($raw);
                                $token = trim($token, "<> \t\n\r\0\x0B");
                                
                                // 1. Try direct mapping (exact match)
                                if (isset($cidMap[$token])) {
                                    return $cidMap[$token];
                                }
                                
                                // 2. Try normalized matching
                                $normalizedToken = $normalizeCid($token);
                                if ($normalizedToken !== '' && isset($cidMap[$normalizedToken])) {
                                    return $cidMap[$normalizedToken];
                                }
                                
                                // 3. Try case-insensitive match against all keys
                                $lk = strtolower($token);
                                foreach ($cidMap as $k => $v) {
                                    if (strtolower($k) === $lk) {
                                        return $v;
                                    }
                                }

                                // As a last resort, look up the attachment by content_id or filename in DB
                                try {
                                    // Try exact content_id matches (with and without <>)
                                    $q = $pdo->prepare("SELECT id FROM email_attachments WHERE email_id = ? AND (content_id = ? OR content_id = ? OR content_id LIKE ?) LIMIT 1");
                                    $q->execute([$emailId, $token, '<' . $token . '>', '%' . $token . '%']);
                                    $r = $q->fetch(PDO::FETCH_ASSOC);
                                    if ($r && !empty($r['id'])) {
                                        $aid = (int)$r['id'];
                                        // Generate URL with same timestamp for consistency
                                        if (function_exists('generateSignedAttachmentUrl')) return generateSignedAttachmentUrl($aid, null, $signatureTime);
                                        return (rtrim($config['email']['base_url'] ?? '', '/') ?: '') . '/files.php?id=' . $aid;
                                    }

                                    // Fallback: try matching filename (without extension)
                                    $base = $token;
                                    // strip surrounding quotes if present
                                    $base = trim($base, '"\'');
                                    $fn = pathinfo($base, PATHINFO_FILENAME);
                                    if ($fn !== '') {
                                        $q2 = $pdo->prepare("SELECT id FROM email_attachments WHERE email_id = ? AND (filename = ? OR filename LIKE ? ) LIMIT 1");
                                        $q2->execute([$emailId, $base, $fn . '.%']);
                                        $r2 = $q2->fetch(PDO::FETCH_ASSOC);
                                        if ($r2 && !empty($r2['id'])) {
                                            $aid = (int)$r2['id'];
                                            // Generate URL with same timestamp for consistency
                                            if (function_exists('generateSignedAttachmentUrl')) return generateSignedAttachmentUrl($aid, null, $signatureTime);
                                            return (rtrim($config['email']['base_url'] ?? '', '/') ?: '') . '/files.php?id=' . $aid;
                                        }
                                    }
                                } catch (Exception $_) {
                                    // ignore DB errors in best-effort replacement
                                }

                                // No replacement found — return original match unchanged
                                return $matches[0];
                            };

                            $pattern = '/cid:\s*<?([^>\s"\']+)>?/i';
                            if (!empty($email['body_html'])) {
                                $email['body_html'] = preg_replace_callback($pattern, $cidReplacer, $email['body_html']);
                            }
                            if (!empty($email['body_text'])) {
                                $email['body_text'] = preg_replace_callback($pattern, $cidReplacer, $email['body_text']);
                            }
                        }
                    } catch (Exception $_) {
                        // Best-effort replacement; if anything fails, continue with original body
                    }

                    // Debug: Log what we're returning for attachments
                    if (!empty($attachments)) {
                        error_log("[get_email] Returning " . count($attachments) . " attachments for email_id=$emailId");
                        foreach ($attachments as $dbgAtt) {
                            error_log("[get_email] Attachment id=" . ($dbgAtt['id'] ?? '?') . " download_url=" . ($dbgAtt['download_url'] ?? 'NOT SET'));
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'email' => $email,
                        'attachments' => $attachments
                    ]);
                break;
                
            case 'get_stats':
                // Hämta systemstatistik
                $stats = getStats();
                echo json_encode([
                    'success' => true,
                    'stats' => $stats
                ]);
                break;
                
            default:
                throw new Exception('Okänd åtgärd');
        }
    } catch (Exception $e) {
        logMessage('WARNING', 'AJAX request failed', [
            'action' => $action,
            'error' => $e->getMessage(),
            'post_data' => $_POST
        ]);
        
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-BFX6EC3575"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);} 
        gtag('js', new Date());
        gtag('config', 'G-BFX6EC3575');
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TempMail - Temporary Email Service</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
    
    <meta name="description" content="Create temporary email addresses that are automatically deleted after 24 hours. Safe and easy to use.">
    
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-envelope"></i> TempMail<?php echo !empty($_SESSION['pro_user_id'] ?? null) ? ' Pro' : ''; ?></h1>
            <p class="lead"><?php echo !empty($_SESSION['pro_user_id'] ?? null) 
                ? 'Temporary email addresses for pro users. Default lifetime applied to new addresses.' 
                : 'Temporary email addresses that are deleted after 24 hours'; ?></p>
            
            <?php require 'partials/nav.php'; ?>
            <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
            <script>
            (function(){
                try {
                    var proExpiry = <?php 
                        // Fetch pro_expires_at from session or database
                        $proExpires = null;
                        if (!empty($_SESSION['pro_user_id'])) {
                            try {
                                $pstmt = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
                                $pstmt->execute([$_SESSION['pro_user_id']]);
                                $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                                $proExpires = $prow['pro_expires_at'] ?? null;
                            } catch (Exception $e) {}
                        }
                        echo json_encode($proExpires);
                    ?>;
                    if (!proExpiry) {
                        document.getElementById('proExpiryLine').textContent = 'Pro: Lifetime';
                        return;
                    }
                    var d = new Date(proExpiry + ' UTC');
                    var dd = String(d.getUTCDate()).padStart(2, '0');
                    var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                    var yyyy = d.getUTCFullYear();
                    var dateOnly = dd + '/' + mm + '/' + yyyy;
                    var now = new Date();
                    var diffMs = d - now;
                    if (diffMs <= 0) {
                        document.getElementById('proExpiryLine').textContent = 'Pro expired on ' + dateOnly;
                        document.getElementById('proExpiryLine').style.color = '#ff6b6b';
                    } else {
                        document.getElementById('proExpiryLine').textContent = 'Pro expires: ' + dateOnly;
                    }
                } catch (e) {}
            })();
            </script>
            <?php endif; ?>
            <!-- navbar script moved to partial -->
        </div>

        <!-- Funktioner och information -->
        <?php if (empty($_SESSION['pro_user_id'] ?? null)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Security:</strong> All email addresses and messages are automatically deleted after 24 hours. 
                        Don't use for sensitive information.
                    </div>
        <div class="card fade-in">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> How It Works</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="text-center">
                            <i class="fas fa-mouse-pointer fa-2x text-primary mb-3"></i>
                            <h5>1. Generate</h5>
                            <p>Click the button to get a new temporary email address</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="text-center">
                            <i class="fas fa-paper-plane fa-2x text-success mb-3"></i>
                            <h5>2. Use</h5>
                            <p>Use the address for registrations and verifications</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="text-center">
                            <i class="fas fa-eye fa-2x text-info mb-3"></i>
                            <h5>3. Read</h5>
                            <p>View incoming emails here on the page in real-time</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
            </div>
        </div>

        

        <!-- Initial address generator (visas tills adress är skapad) -->
        <div id="initial-generator" class="card fade-in card-main-width">
            <div class="card-body text-center">
                <h4 class="mb-3">Click to get your temporary email address</h4>
                <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
                    <button id="generateBtn" class="btn btn-primary btn-lg">
                        <i class="fas fa-magic"></i> Get Email Address
                    </button>
                <?php else: ?>
                    <a id="generateBtn" href="/pro_login.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-magic"></i> Get Email Address
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- E-postadress display (visas när adress är skapad) -->
        <div class="email-container d-none">
            <div class="card fade-in card-main-width">
                <div class="card-header">
                    <h3><i class="fas fa-envelope-open"></i> Your Temporary Email Address</h3>
                </div>
                <div class="card-body">
                    <div class="email-display">
                        <h4 class="email-address" id="currentEmail" tabindex="0"></h4>
                        <div class="email-info">
                            <span id="privacyIndicator" class="d-none me-3"><i class="fas fa-lock"></i> <span id="privacyText">Private</span></span>
                            <i class="fas fa-clock"></i> <span id="validityText">Valid for 24 hours</span>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6 mb-3">
                            <button id="copyBtn" class="btn btn-success w-100">
                                <i class="fas fa-copy"></i> Copy Address
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button id="refreshBtn" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button id="shareBtn" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-share-alt"></i> Share Link
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button id="newAddressBtn" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-plus"></i> New Address
                            </button>
                        </div>
                    </div>
                    
                    <div class="text-end mt-3">
                        <small class="text-muted">
                            Last updated: <span id="lastUpdate">Never</span>
                        </small>
                    </div>
                </div>
            </div>

            <!-- E-postlista -->
            <div class="card fade-in card-main-width">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3><i class="fas fa-inbox"></i> Incoming Emails</h3>
                    <div class="d-flex align-items-center">
                        <span class="badge bg-primary me-2" id="emailCount">0</span>
                        <button id="imageToggle" class="btn btn-sm btn-outline-secondary" title="Blockera externa bilder">
                            <i class="fas fa-image"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="email-list" id="emailList">
                        <!-- E-postmeddelanden läses in här via JavaScript -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistik -->
        <!-- Pro vs Regular comparison -->
        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3>Pro vs Regular</h3></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th>Regular</th>
                                <th>Pro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Spam protection</td>
                                <td>General protection</td>
                                <td>General protection</td>
                            </tr>
                            <tr>
                                <td>Address lifetime</td>
                                <td>Default 24 hours</td>
                                <td>Configurable (1–7 days)</td>
                            </tr>
                            <tr>
                                <td>Personal addresses</td>
                                <td>Not available</td>
                                <td>Create up to 3 persistent personal addresses</td>
                            </tr>
                            <tr>
                                <td>Webhooks</td>
                                <td>Not available</td>
                                <td>Receive webhooks for incoming mail (POST JSON)</td>
                            </tr>
                            <tr>
                                <td>Digest emails</td>
                                <td>Not available</td>
                                <td>Periodic digests with unread messages</td>
                            </tr>
                            <tr>
                                <td>RSS feed</td>
                                <td>Not available</td>
                                <td>Private RSS feed of your inbox (token protected)</td>
                            </tr>
                            <tr>
                                <td>Remote agent</td>
                                <td>Not available</td>
                                <td>Monitor and manage any remote mailbox</td>
                            </tr>
                            <tr>
                                <td>Address privacy</td>
                                <td>Public — anyone with the address can read emails</td>
                                <td>Temp addresses shareable; Personal addresses private (login required)</td>
                            </tr>
                            <tr>
                                <td>Attachments</td>
                                <td>Download attachments (limited)</td>
                                <td>Signed, time-limited download links</td>
                            </tr>
                            <tr>
                                <td>Support</td>
                                <td>Community / public docs</td>
                                <td>Priority support</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card fade-in card-main-width">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> System Statistics</h3>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3 mb-3">
                        <div class="stat-box">
                            <i class="fas fa-inbox fa-2x text-info mb-2"></i>
                            <h4 class="stat-number" id="statsTotal">0</h4>
                            <p class="text-muted mb-0">Total Emails</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-box">
                            <i class="fas fa-envelope-open-text fa-2x text-primary mb-2"></i>
                            <h4 class="stat-number" id="statsProcessed">0</h4>
                            <p class="text-muted mb-0">Emails Processed</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-box">
                            <i class="fas fa-plus-circle fa-2x text-success mb-2"></i>
                            <h4 class="stat-number" id="statsCreated">0</h4>
                            <p class="text-muted mb-0">Addresses Created</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-box">
                            <i class="fas fa-paperclip fa-2x text-warning mb-2"></i>
                            <h4 class="stat-number" id="statsAttachments">0</h4>
                            <p class="text-muted mb-0">Attachments Processed</p>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="fas fa-sync-alt"></i> 
                        Statistics updated automatically every minute
                    </small>
                    <br>
                    <div class="status-indicator mt-2">
                        <i class="fas fa-circle"></i> Ready
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- E-post Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-main modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailModalLabel">Email Message</h5>
                    <div class="d-flex align-items-center">
                        <button id="modalShowImagesBtn" type="button" class="btn btn-sm btn-outline-secondary me-2" title="Visa bilder i detta meddelande">Visa bilder</button>
                        <button type="button" class="btn-close" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong>From:</strong></div>
                        <div class="col-sm-9" id="emailFrom"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong>To:</strong></div>
                        <div class="col-sm-9" id="emailTo"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong>Date:</strong></div>
                        <div class="col-sm-9" id="emailDate"></div>
                    </div>
                    <hr>
                    <div class="email-content" id="emailContent">
                        <!-- Email content loaded here -->
                    </div>
                    <div id="emailAttachments" class="mt-3" style="display:none">
                        <h6>Attachments</h6>
                        <!-- Attachment links injected here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pro Profile Modal -->
    <div class="modal fade" id="proProfileModal" tabindex="-1" aria-labelledby="proProfileLabel" aria-hidden="true">
        <div class="modal-dialog modal-main modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="proProfileLabel">Profile</h5>
                    <button type="button" class="btn-close" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="proProfileAlert"></div>
                    <form id="proProfileForm">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="proEmail" />
                        </div>
                        <div class="mb-3">
                            <button type="button" id="saveProfileEmailBtn" class="btn btn-primary">Save email</button>
                        </div>

                        <hr>
                        <h6>Set a password for direct access</h6>
                        <div id="proPasswordCurrentGroup" class="mb-3 d-none">
                            <label class="form-label">Current password</label>
                            <input type="password" class="form-control" id="proPasswordCurrent" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New password</label>
                            <input type="password" class="form-control" id="proPassword" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm password</label>
                            <input type="password" class="form-control" id="proPasswordConfirm" />
                        </div>
                        <div class="mb-3">
                            <button type="button" id="saveProfilePasswordBtn" class="btn btn-secondary">Set password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center mt-5 py-4">
        <div class="container">
            <p class="text-light mb-0">
                <small>TempMail - Secure temporary email | All messages deleted after 24 hours</small>
            </p>
            <p class="text-light mb-0 mt-1">
                <small>&copy; <?php echo date('Y'); ?> Manjo Consulting AB</small>
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/site-controls.js"></script>
    <script>
        // Konfigurations-objekt för JavaScript
        window.tempMailConfig = {
            domain: '<?php echo $config['email']['domain']; ?>',
            refreshRate: 10000,
            maxEmailPreview: 150,
            urlAddress: <?php echo $urlAddress ? "'" . htmlspecialchars($urlAddress, ENT_QUOTES) . "'" : 'null'; ?>,
            urlExpiresAt: <?php echo $urlExpiresAt ? "'" . htmlspecialchars($urlExpiresAt, ENT_QUOTES) . "'" : 'null'; ?>,
            isPro: <?php echo !empty($_SESSION['pro_user_id']) ? 'true' : 'false'; ?>
        };
    </script>
    <script src="assets/js/app.js?v=<?php echo file_exists(__DIR__ . '/assets/js/app.js') ? filemtime(__DIR__ . '/assets/js/app.js') : time(); ?>"></script>
    <script>
    (function(){
        // No personal-address handlers on index; profile page contains those controls now.
    })();
    </script>
</body>
</html>