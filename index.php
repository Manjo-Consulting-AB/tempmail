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
            'ip' => getVisitorIp()
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
                if (!proUserIsPro((int)$_SESSION['pro_user_id'])) {
                    logMessage('INFO', 'create_personal denied: not a Pro account', ['user_id' => $_SESSION['pro_user_id']]);
                    echo json_encode(['success' => false, 'error' => 'Pro required', 'pro_required' => true]);
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
                // Enforce max 10 personal addresses per pro user
                try {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1");
                    $cntStmt->execute([$_SESSION['pro_user_id']]);
                    $cntRow = $cntStmt->fetch(PDO::FETCH_ASSOC);
                    $existingCount = (int)($cntRow['cnt'] ?? 0);
                    if ($existingCount >= 10) {
                        echo json_encode(['success' => false, 'error' => 'Maximum of 10 personal addresses allowed']);
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
                    if (!createDirectAdminForwarder($local)) {
                        $pdo->prepare("DELETE FROM temp_emails WHERE unique_address = ? AND pro_user_id = ? AND is_personal = 1")
                            ->execute([$local, $_SESSION['pro_user_id']]);
                        echo json_encode(['success' => false, 'error' => 'Mail delivery could not be set up for this address, so it was not created. Please try again in a moment.']);
                        break;
                    }
                    logMessage('INFO', 'Personal address created', ['user_id' => $_SESSION['pro_user_id'], 'address' => $local]);
                    echo json_encode(['success' => true, 'address' => $local, 'full_address' => $local . '@' . $config['email']['domain'], 'expires_at' => $expiresAt]); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                } catch (Exception $e) {
                    logMessage('ERROR', 'Failed creating personal address', ['error' => $e->getMessage()]);
                    echo json_encode(['success' => false, 'error' => 'Could not create address']);
                }
                break;
            case 'list_personal':
                // Avsiktligt inte Pro-gated: ett degraderat konto måste kunna se
                // sina personliga adresser under grace-perioden.
                if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
                    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                    break;
                }
                try {
                    // feed_enabled exposed (#160) so the UI can show feed state
                    // without handing out the credential itself: this action is
                    // deliberately not Pro-gated, and a degraded account must not
                    // be able to read live credentials from it. The credential is
                    // only ever returned by the Pro-gated address_feed_* actions
                    // in pro_profile.php.
                    $feedEnabledCol = tableHasColumn('temp_emails', 'feed_token') ? ", (feed_token IS NOT NULL) AS feed_enabled" : "";
                    // hooks_paused exposed (#251 step 4) on the same terms: it is
                    // a preference, not a credential — no address, no hook and no
                    // URL travels with it, only whether the address' routing is
                    // silenced. The links themselves stay in pro_profile.php.
                    $hooksPausedCol = tableHasColumn('temp_emails', 'hooks_paused') ? ", (hooks_paused = 1) AS hooks_paused" : "";
                    $stmt = $pdo->prepare("SELECT id, unique_address AS address, expires_at{$feedEnabledCol}{$hooksPausedCol} FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1 ORDER BY created_at DESC");
                    $stmt->execute([$_SESSION['pro_user_id']]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $list = [];
                    foreach ($rows as $r) {
                        $list[] = [
                            'id' => $r['id'],
                            'address' => $r['address'],
                            'full_address' => $r['address'] . '@' . $config['email']['domain'],
                            'expires_at' => $r['expires_at'],
                            'feed_enabled' => !empty($r['feed_enabled']),
                            // Absent when the migration hasn't run, and empty()
                            // reads an undefined key as false, i.e. not paused.
                            'hooks_paused' => !empty($r['hooks_paused'])
                        ];
                    }
                    echo json_encode(['success' => true, 'personal' => $list]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to list personal addresses']);
                }
                break;
            case 'delete_personal':
                // Avsiktligt inte Pro-gated: att kunna ta bort sina egna adresser
                // ska aldrig kräva Pro.
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
                    $stmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
                    $stmt->execute([$id, $_SESSION['pro_user_id']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        echo json_encode(['success' => false, 'error' => 'Address not found or not owned by user']);
                        break;
                    }
                    $id = (int)$row['id'];
                    $unique = $row['unique_address'];

                    $pdo->beginTransaction();
                    // Delete the stored emails and attachment rows explicitly: there is
                    // no guaranteed FK cascade from temp_emails. The attachment files
                    // are unlinked only after the commit, so a rollback cannot leave
                    // rows pointing at deleted files.
                    $attachmentFiles = deleteStoredEmailsForTempEmail($pdo, $id);
                    // The routing links have no foreign key either (#251 step 4),
                    // so they go with the address; a surviving row would keep
                    // routing this now-reusable id to hooks that are not its own.
                    if (tableHasColumn('pro_webhook_addresses', 'webhook_id')) {
                        $dl = $pdo->prepare("DELETE FROM pro_webhook_addresses WHERE temp_email_id = ?");
                        $dl->execute([$id]);
                    }
                    $d2 = $pdo->prepare("DELETE FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
                    $d2->execute([$id, $_SESSION['pro_user_id']]);

                    $pdo->commit();
                    unlinkAttachmentFiles($attachmentFiles);
                    deleteDirectAdminForwarder($unique);
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
                if (isset($_SESSION['pro_user_id']) && $_SESSION['pro_user_id'] && proUserIsPro((int)$_SESSION['pro_user_id'])) {
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
                    $replacedAddresses = [];
                    $replacedAttachmentFiles = [];
                    try {
                        $pdo->beginTransaction();
                        // Look up the address(es) about to be replaced so their DirectAdmin
                        // forwarder can be removed after the transaction commits.
                        $oldStmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ? AND is_personal = 0");
                        $oldStmt->execute([$proUserId]);
                        $oldRows = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($oldRows as $oldRow) {
                            $replacedAddresses[] = $oldRow['unique_address'];
                            // Delete the stored emails and attachment rows explicitly:
                            // there is no guaranteed FK cascade from temp_emails.
                            $replacedAttachmentFiles = array_merge(
                                $replacedAttachmentFiles,
                                deleteStoredEmailsForTempEmail($pdo, (int)$oldRow['id'])
                            );
                        }
                        // Delete any existing non-personal temp addresses for this pro user
                        $del = $pdo->prepare("DELETE FROM temp_emails WHERE pro_user_id = ? AND is_personal = 0");
                        $del->execute([$proUserId]);
                        // Now insert new address
                        $saved = saveNewAddress($address, $proUserId);
                        if ($saved) {
                            $pdo->commit();
                        } else {
                            // The new address could not be set up, so the previous
                            // one is kept: rolling back undoes the delete above,
                            // leaving its rows, its mail and its forwarder intact
                            // (#212).
                            $pdo->rollBack();
                        }
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                    if ($saved) {
                        // Files are unlinked only after the commit, so a rollback cannot
                        // leave rows pointing at deleted files.
                        unlinkAttachmentFiles($replacedAttachmentFiles);
                        foreach ($replacedAddresses as $replacedAddress) {
                            deleteDirectAdminForwarder($replacedAddress);
                        }
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
                    throw new Exception('Mail delivery could not be set up for a new address, so none was created. Please try again in a moment.');
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

                // Caller has now proven knowledge of these addresses - allow get_email
                // to serve individual messages for them later in this session.
                grantSessionAddressAccess($addresses);

                // Query stored_emails for any of the addresses
                try {
                    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
                    $safe_placeholders = preg_replace('/[^?,]/', '', $placeholders);
                    // Order by received_at DESC so newest messages appear first
                    $sql = "SELECT id, from_address, subject, body_text, body_html, received_at, to_address, expires_at FROM stored_emails WHERE to_address IN ($safe_placeholders) AND ((expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR)) OR (expires_at IS NOT NULL AND expires_at > NOW())) ORDER BY received_at DESC LIMIT ?"; // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string, php.lang.security.injection.tainted-sql-string.tainted-sql-string
                    // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable
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
                
                echo json_encode([ // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
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

                // Check if this address is marked as personal - only personal addresses require authentication
                // (same check as get_emails - this was previously missing here, letting anyone who
                // guesses a Pro user's personal alias learn whether that mailbox has new messages)
                $ownerCheck = $pdo->prepare("SELECT pro_user_id, is_personal FROM temp_emails WHERE unique_address = ? LIMIT 1");
                $ownerCheck->execute([$address]);
                $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
                if ($ownerRow && !empty($ownerRow['pro_user_id']) && !empty($ownerRow['is_personal'])) {
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

                grantSessionAddressAccess($addresses);

                try {
                    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
                    // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
                    $safe_placeholders = preg_replace('/[^?,]/', '', $placeholders);
                    // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
                    $sql = "SELECT COALESCE(MAX(id), 0) AS latest_id, COUNT(*) AS cnt FROM stored_emails WHERE to_address IN ($safe_placeholders) AND ((expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR)) OR (expires_at IS NOT NULL AND expires_at > NOW()))";
                    // nosemgrep:  php.lang.security.injection.tainted-callable.tainted-callable
                    $stmt = $pdo->prepare($sql);
                    $params = array_merge($addresses, [$config['app']['cleanup_hours']]);
                    $stmt->execute($params);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $latest = (int)($row['latest_id'] ?? 0);
                    $cnt = (int)($row['cnt'] ?? 0);

                    $hasNew = $latest > $lastKnown;
                    echo json_encode(['success' => true, 'has_new' => $hasNew, 'latest_id' => $latest, 'count' => $cnt]); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
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

                // Check if this address is marked as personal - only personal addresses require authentication
                // (same check as get_emails - this was previously missing here, letting anyone who
                // guesses a Pro user's personal alias read that mailbox's messages)
                $ownerCheck = $pdo->prepare("SELECT pro_user_id, is_personal FROM temp_emails WHERE unique_address = ? LIMIT 1");
                $ownerCheck->execute([$address]);
                $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
                if ($ownerRow && !empty($ownerRow['pro_user_id']) && !empty($ownerRow['is_personal'])) {
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
                // Mail arrives through the DirectAdmin pipe (parse.php) as it is delivered;
                // there is no mailbox to poll, so a refresh only re-reads the database.
                $imapSuccess = true;
                $newEmailsFromImap = 0;
                $imapMessage = '';

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

                grantSessionAddressAccess($addresses);

                try {
                    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
                    $safe_placeholders = preg_replace('/[^?,]/', '', $placeholders);
                    // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
                    $sql = "SELECT id, from_address, subject, body_text, body_html, received_at, to_address, expires_at FROM stored_emails WHERE to_address IN ($safe_placeholders) AND ((expires_at IS NULL AND received_at > DATE_SUB(NOW(), INTERVAL ? HOUR)) OR (expires_at IS NOT NULL AND expires_at > NOW())) ORDER BY received_at DESC LIMIT ?";
                    // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable
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
                    
                echo json_encode([ // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
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
                        $isPersonalOwned = false;
                        if ($toLocal !== '') {
                            $ownerCheck = $pdo->prepare("SELECT pro_user_id, is_personal FROM temp_emails WHERE unique_address = ? LIMIT 1");
                            $ownerCheck->execute([$toLocal]);
                            $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
                            if ($ownerRow && !empty($ownerRow['pro_user_id']) && !empty($ownerRow['is_personal'])) {
                                $isPersonalOwned = true;
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

                        // stored_emails.id is a bare sequential integer - without this check,
                        // anyone could enumerate email_id=1,2,3,... and read every message ever
                        // received by any non-personal address on the service. Require the caller
                        // to have already proven knowledge of this address this session (via
                        // get_emails/refresh_emails/has_new_emails, which validate + own-check it).
                        if (!$isPersonalOwned && !hasSessionAddressAccess($email['to_address'])) {
                            logMessage('WARNING', 'Unauthorized get_email access attempt', [
                                'email_id' => $emailId,
                                'to_address' => $email['to_address']
                            ]);
                            throw new Exception('Authentication required to access this email');
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
                // The signed-in account's own numbers. The system-wide
                // email_stats counters are rendered server-side on the
                // landing page (partials/landing/stats.php), not here.
                $statsUserId = (int)($_SESSION['pro_user_id'] ?? 0);
                if ($statsUserId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Not signed in']);
                    break;
                }
                echo json_encode([
                    'success' => true,
                    'stats' => getUserStats($statsUserId)
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

// Shared inbox links used to be read on this page. The inbox now lives in
// inbox.php; redirect so existing links keep working.
if ($urlAddress !== null) {
    header('Location: inbox.php?address=' . urlencode($urlAddress), true, 302);
    exit;
}
?>
<?php
/**
 * Mail Shield landing page. Spec: documentaion/REDESIGN_BRIEF.md §8 (section
 * order and copy) and §11 (marketing pages load only the mailshield
 * stylesheets).
 *
 * Everything above this point is the POST/AJAX action surface that app.js in
 * inbox.php still posts to — it is deliberately untouched. The page body itself
 * is ten stub sections, one file each, so Redesigns 09–15 can fill them in
 * independently without touching this file.
 */

$domain = $config['email']['domain'] ?? 'manjo.me';

// Origin for structured data — the same $config source public_head.php derives
// canonical/og:url from, never a hardcoded domain (§14).
$msOrigin = rtrim((string) ($config['email']['base_url'] ?? ''), '/');

// Organization + WebSite only. Deliberately no SoftwareApplication with an
// offers block: no price has been published (§9), and an invented Offer is
// exactly the claim that gets a site penalised rather than ranked.
$msPage = [
    'title'        => 'Mail Shield — Your inbox for everything else',
    'description'  => 'A separate inbox for shopping, newsletters, signups and temporary email. Create permanent addresses or disposable temporary email addresses, and keep your primary inbox for what matters.',
    'path'         => '/',
    'preload_font' => true,
    'jsonld'       => [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'              => 'Organization',
                'name'               => 'Mail Shield',
                'url'                => $msOrigin . '/',
                'logo'               => $msOrigin . '/assets/images/og-mailshield.png',
                'parentOrganization' => [
                    '@type' => 'Organization',
                    'name'  => 'Manjo Consulting AB',
                ],
            ],
            [
                '@type' => 'WebSite',
                'name'  => 'Mail Shield',
                'url'   => $msOrigin . '/',
            ],
        ],
    ],
];

require 'partials/brand.php';
require 'partials/public_head.php';
require 'partials/public_nav.php';
?>
<main id="main">
<?php
foreach ([
    'hero',
    'problem',
    'how_it_works',
    'addresses',
    'temporary',
    'automation',
    'cleanup',
    'external',
    'stats',
    'plans',
    'cta',
] as $msSection) {
    require __DIR__ . '/partials/landing/' . $msSection . '.php';
}
?>
</main>
<?php require 'partials/public_footer.php'; ?>
