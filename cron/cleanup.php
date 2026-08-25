<?php
/**
 * TempMail - Cleanup Script
 * Detta script körs som cron job för att rensa gamla data
 */

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../TwoFactorAuth.php';

// Säkerhetskontroll - endast CLI eller localhost eller HTTP-anrop med giltig hemlig nyckel
// För att tillåta cron via HTTP (wget/curl), sätt miljövariabeln CRON_HTTP_SECRET i produktion
$cronHttpSecret = $_ENV['CRON_HTTP_SECRET'] ?? ($config['cron']['http_secret'] ?? null);

if (php_sapi_name() !== 'cli') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    // Allow localhost calls as before
    if ($remote === '127.0.0.1') {
        // allowed
    } else {
        // Try header X-Cron-Secret first, fallback to ?key=
        $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['key'] ?? null);
        if (empty($cronHttpSecret) || empty($provided) || !function_exists('hash_equals') || !hash_equals((string)$cronHttpSecret, (string)$provided)) {
            http_response_code(403);
            die('Access denied - only CLI, localhost or authorized HTTP clients allowed');
        }
        // authorized via secret key
    }
}

logMessage('DEBUG', 'Starting cleanup process');

/**
 * Rensa gamla temporära e-postadresser
 */
function cleanupExpiredAddresses() {
    global $pdo, $config;
    
    try {
        // Hämta temporära adresser som ska raderas.
        // Preferera explicit expires_at om satt, annars fallback till created_at window.
        $stmt = $pdo->prepare(
            "SELECT id, unique_address AS address, expires_at, is_personal " .
            "FROM temp_emails " .
            "WHERE is_personal = 0 AND ( (expires_at IS NOT NULL AND expires_at <= NOW()) OR (expires_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)) )"
        );
        $stmt->execute([$config['app']['cleanup_hours']]);
        $expiredAddresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($expiredAddresses)) {
            logMessage('DEBUG', 'No expired addresses found');
            return 0;
        }
        
        $deletedCount = 0;
        
        foreach ($expiredAddresses as $addr) {
            // Först hämta e-postmeddelanden för denna adress
            $stmt = $pdo->prepare("SELECT id FROM stored_emails WHERE to_address LIKE ?");
            $stmt->execute([$addr['address'] . '@%']);
            $emailIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $totalAttachments = 0;
            $totalFiles = 0;
            
            // Rensa bilagor för alla e-postmeddelanden
            foreach ($emailIds as $emailId) {
                $cleanupResult = cleanupEmailAttachments($emailId);
                $totalAttachments += $cleanupResult['attachments'];
                $totalFiles += $cleanupResult['files'];
            }
            
            // Radera e-postmeddelanden (detta raderar också attachments via CASCADE)
            $stmt = $pdo->prepare("DELETE FROM stored_emails WHERE to_address LIKE ?");
            $stmt->execute([$addr['address'] . '@%']);
            $emailsDeleted = $stmt->rowCount();
            
            // Radera temporära adressen
            $stmt = $pdo->prepare("DELETE FROM temp_emails WHERE id = ?");
            $stmt->execute([$addr['id']]);

            if ($stmt->rowCount() > 0) {
                $deletedCount++;
                deleteDirectAdminForwarder($addr['address']);
                logMessage('INFO', 'Expired address cleaned up', [
                    'address' => $addr['address'],
                    'emails_deleted' => $emailsDeleted,
                    'attachments_deleted' => $totalAttachments,
                    'files_deleted' => $totalFiles
                ]);
            }
        }
        
        return $deletedCount;
        
    } catch (PDOException $e) {
        logMessage('ERROR', 'Failed to cleanup expired addresses: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rensa gamla e-postmeddelanden och deras bilagor (säkerhetsåtgärd)
 */
function cleanupOldEmails() {
    global $pdo, $config;
    
    try {
        // Först, hämta e-postmeddelanden som ska raderas för att kunna rensa bilagor
            $stmt = $pdo->prepare("
                SELECT id FROM stored_emails 
                WHERE (expires_at IS NOT NULL AND expires_at < NOW())
                OR (expires_at IS NULL AND received_at < DATE_SUB(NOW(), INTERVAL ? HOUR))
            ");
        $stmt->execute([$config['app']['cleanup_hours']]);
        $expiredEmails = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $deletedEmailCount = 0;
        $deletedAttachmentCount = 0;
        $deletedFileCount = 0;
        
        if (!empty($expiredEmails)) {
            // Rensa bilagor för varje e-postmeddelande
            foreach ($expiredEmails as $emailId) {
                $cleanupResult = cleanupEmailAttachments($emailId);
                $deletedAttachmentCount += $cleanupResult['attachments'];
                $deletedFileCount += $cleanupResult['files'];
            }
            
            // Radera e-postmeddelanden (detta raderar också attachments via CASCADE)
            $placeholders = str_repeat('?,', count($expiredEmails) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM stored_emails WHERE id IN ($placeholders)");
            $stmt->execute($expiredEmails);
            
            $deletedEmailCount = $stmt->rowCount();
        }
        
        if ($deletedEmailCount > 0) {
            logMessage('INFO', 'Old emails and attachments cleaned up', [
                'emails' => $deletedEmailCount,
                'attachments' => $deletedAttachmentCount,
                'files' => $deletedFileCount
            ]);
        } else {
            logMessage('DEBUG', 'No old emails to cleanup');
        }
        
        return $deletedEmailCount;
        
    } catch (PDOException $e) {
        logMessage('ERROR', 'Failed to cleanup old emails: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rensa bilagor för ett specifikt e-postmeddelande
 */
function cleanupEmailAttachments($emailId) {
    global $pdo;
    
    $deletedAttachments = 0;
    $deletedFiles = 0;
    
    try {
        // Hämta alla bilagor för detta e-postmeddelande
        $stmt = $pdo->prepare("SELECT filename, file_path FROM email_attachments WHERE email_id = ?");
        $stmt->execute([$emailId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($attachments as $attachment) {
            // Radera fysisk fil
            $fullPath = __DIR__ . '/../' . ltrim($attachment['file_path'], '/');
            if (file_exists($fullPath)) {
                if (unlink($fullPath)) {
                    $deletedFiles++;
                    logMessage('DEBUG', 'Deleted attachment file: ' . $attachment['filename']);
                } else {
                    logMessage('WARNING', 'Failed to delete attachment file: ' . $attachment['filename']);
                }
            } else {
                logMessage('DEBUG', 'Attachment file already missing: ' . $attachment['filename']);
            }
        }
        
        // Radera attachment-poster från databasen
        $stmt = $pdo->prepare("DELETE FROM email_attachments WHERE email_id = ?");
        $stmt->execute([$emailId]);
        $deletedAttachments = $stmt->rowCount();
        
        return [
            'attachments' => $deletedAttachments,
            'files' => $deletedFiles
        ];
        
    } catch (Exception $e) {
        logMessage('ERROR', 'Failed to cleanup attachments for email ' . $emailId . ': ' . $e->getMessage());
        return ['attachments' => 0, 'files' => 0];
    }
}

/**
 * Rensa gamla systemloggar
 */
function cleanupOldLogs() {
    global $pdo, $config;
    
    try {
        // Radera loggar äldre än konfiguerad tid
        $stmt = $pdo->prepare("
            DELETE FROM system_logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$config['cleanup']['log_retention_days']]);
        
        $deletedCount = $stmt->rowCount();
        
        if ($deletedCount > 0) {
            logMessage('INFO', 'Old logs cleaned up', ['count' => $deletedCount]);
        }
        
        return $deletedCount;
        
    } catch (PDOException $e) {
        logMessage('ERROR', 'Failed to cleanup old logs: ' . $e->getMessage());
        return false;
    }
}

/**
 * Optimera databas (körs veckovis)
 */
function optimizeDatabase() {
    global $pdo;
    
    try {
        $tables = ['temp_emails', 'stored_emails', 'system_logs'];
        
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("OPTIMIZE TABLE $table");
            $stmt->execute();
        }
        
        logMessage('INFO', 'Database optimization completed');
        return true;
        
    } catch (PDOException $e) {
        logMessage('ERROR', 'Database optimization failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rensa utgångna "remember this browser"-cookies (2FA trusted devices)
 */
function cleanupExpiredTrustedDevices() {
    try {
        $count = TwoFactorAuth::cleanupExpiredTrustedDevices();
        if ($count > 0) {
            logMessage('INFO', 'Expired trusted devices cleaned up', ['count' => $count]);
        }
        return $count;
    } catch (PDOException $e) {
        logMessage('ERROR', 'Failed to cleanup expired trusted devices: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rensa personliga adresser för utgångna PRO-konton och nollställ vissa fält
 */
function cleanupExpiredProUsers() {
    global $pdo;

    try {
        // Hitta PRO-användare där pro_expires_at är satt och har passerat
        $stmt = $pdo->prepare("SELECT id, email, pro_expires_at FROM pro_users WHERE pro_expires_at IS NOT NULL AND pro_expires_at < NOW()");
        $stmt->execute();
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        logMessage('DEBUG', 'cleanupExpiredProUsers: found ' . count($expired) . ' expired pro users');

        if (empty($expired)) {
            return 0;
        }

        $processed = 0;

        foreach ($expired as $u) {
            $userId = (int)$u['id'];
            $email = $u['email'] ?? null;

            logMessage('DEBUG', 'Processing expired pro user', ['user_id' => $userId, 'email' => $email, 'pro_expires_at' => $u['pro_expires_at']]);

            // Hämta personliga adresser för denna pro user
            $tstmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1");
            $tstmt->execute([$userId]);
            $personalAddresses = $tstmt->fetchAll(PDO::FETCH_ASSOC);
            $personalIds = array_column($personalAddresses, 'id');

            logMessage('DEBUG', 'Found personal addresses for user', ['user_id' => $userId, 'count' => count($personalAddresses), 'ids' => $personalIds]);

            $totalEmailsDeleted = 0;
            $totalAttachments = 0;
            $totalFiles = 0;

            foreach ($personalAddresses as $personalAddress) {
                $tempEmailId = $personalAddress['id'];
                // Hämta e-postmeddelanden via temp_email_id (FK) för säkrare koppling
                $sstmt = $pdo->prepare("SELECT id FROM stored_emails WHERE temp_email_id = ?");
                $sstmt->execute([$tempEmailId]);
                $emailIds = $sstmt->fetchAll(PDO::FETCH_COLUMN);

                logMessage('DEBUG', 'Found stored_emails for temp_email', ['temp_email_id' => $tempEmailId, 'email_count' => count($emailIds)]);

                foreach ($emailIds as $emailId) {
                    $r = cleanupEmailAttachments($emailId);
                    $totalAttachments += $r['attachments'];
                    $totalFiles += $r['files'];
                }

                // Radera e-postmeddelanden via temp_email_id
                $delStmt = $pdo->prepare("DELETE FROM stored_emails WHERE temp_email_id = ?");
                $delStmt->execute([$tempEmailId]);
                $deletedCount = $delStmt->rowCount();
                $totalEmailsDeleted += $deletedCount;

                // Radera temp_emails-raden
                $tempDelStmt = $pdo->prepare("DELETE FROM temp_emails WHERE id = ?");
                $tempDelStmt->execute([$tempEmailId]);
                if ($tempDelStmt->rowCount() > 0) {
                    deleteDirectAdminForwarder($personalAddress['unique_address']);
                }
            }

            // Nollställ lösenord och stäng av digest
            $uup = $pdo->prepare("UPDATE pro_users SET password_hash = NULL, digest_enabled = 0 WHERE id = ?");
            $uup->execute([$userId]);

            // Ta bort kontot helt om det har varit utgånget i mer än 7 dagar
            $deletedUser = false;
            if (!empty($u['pro_expires_at'])) {
                $expiresTs = strtotime($u['pro_expires_at']);
                if ($expiresTs !== false && $expiresTs <= strtotime('-7 days')) {
                    $dstmt = $pdo->prepare("DELETE FROM pro_users WHERE id = ?");
                    $dstmt->execute([$userId]);
                    if ($dstmt->rowCount() > 0) {
                        $deletedUser = true;
                        logMessage('INFO', 'Expired pro user deleted', [
                            'user_id' => $userId,
                            'email' => $email,
                            'pro_expires_at' => $u['pro_expires_at']
                        ]);
                    } else {
                        logMessage('WARNING', 'Failed to delete expired pro user', ['user_id' => $userId]);
                    }
                } else {
                    logMessage('DEBUG', 'Expired pro user within 7-day grace period', ['user_id' => $userId, 'pro_expires_at' => $u['pro_expires_at']]);
                }
            }

            if (!$deletedUser) {
                logMessage('INFO', 'Expired pro user processed', [
                    'user_id' => $userId,
                    'email' => $email,
                    'personal_addresses_removed' => count($personalIds),
                    'emails_deleted' => $totalEmailsDeleted,
                    'attachments_deleted' => $totalAttachments,
                    'files_deleted' => $totalFiles
                ]);
            }

            $processed++;
        }

        return $processed;

    } catch (Exception $e) {
        logMessage('ERROR', 'Failed to cleanup expired pro users: ' . $e->getMessage());
        return false;
    }
}

/**
 * Hämta databasstatistik
 */
function getDatabaseStats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // Antal aktiva adresser
        $stmt = $pdo->query("SELECT COUNT(*) FROM temp_emails");
        $stats['active_addresses'] = $stmt->fetchColumn();
        
        // Antal lagrade e-postmeddelanden
        $stmt = $pdo->query("SELECT COUNT(*) FROM stored_emails");
        $stats['stored_emails'] = $stmt->fetchColumn();
        
        // Antal systemloggar
        $stmt = $pdo->query("SELECT COUNT(*) FROM system_logs");
        $stats['system_logs'] = $stmt->fetchColumn();
        
        // Databasstorlek (approximation)
        $stmt = $pdo->query("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
        ");
        $stats['database_size_mb'] = $stmt->fetchColumn();
        
        return $stats;
        
    } catch (PDOException $e) {
        logMessage('ERROR', 'Failed to get database stats: ' . $e->getMessage());
        return false;
    }
}

/**
 * Huvudfunktion för cleanup
 */
function runCleanup($options = []) {
    $results = [
        'addresses_cleaned' => 0,
        'emails_cleaned' => 0,
        'logs_cleaned' => 0,
        'optimized' => false,
        'stats' => []
    ];
    
    // Hämta statistik före cleanup
    $statsBefore = getDatabaseStats();
    if ($statsBefore) {
        logMessage('INFO', 'Database stats before cleanup', $statsBefore);
    }
    
    // Rensa gamla adresser
    $addressesResult = cleanupExpiredAddresses();
    if ($addressesResult !== false) {
        $results['addresses_cleaned'] = $addressesResult;
    }
    
    // Rensa gamla e-postmeddelanden (säkerhetsåtgärd)
    $emailsResult = cleanupOldEmails();
    if ($emailsResult !== false) {
        $results['emails_cleaned'] = $emailsResult;
    }
    
    // Rensa gamla loggar
    $logsResult = cleanupOldLogs();
    if ($logsResult !== false) {
        $results['logs_cleaned'] = $logsResult;
    }

    // Rensa personliga adresser för utgångna PRO-konton och nollställ fält
    $proUsersResult = cleanupExpiredProUsers();
    if ($proUsersResult !== false) {
        $results['expired_pro_users_cleaned'] = $proUsersResult;
    }

    // Rensa utgångna 2FA trusted-device-cookies
    $trustedDevicesResult = cleanupExpiredTrustedDevices();
    if ($trustedDevicesResult !== false) {
        $results['trusted_devices_cleaned'] = $trustedDevicesResult;
    }
    
    // Optimera databas (om begärt)
    if (isset($options['optimize']) && $options['optimize']) {
        $results['optimized'] = optimizeDatabase();
    }
    
    // Hämta statistik efter cleanup
    $statsAfter = getDatabaseStats();
    if ($statsAfter) {
        $results['stats'] = $statsAfter;
        logMessage('INFO', 'Database stats after cleanup', $statsAfter);
    }
    
    // Logga resultat
    logMessage('INFO', 'Cleanup completed', $results);
    
    return $results;
}

// Hantera CLI-argument
$options = [];
if (php_sapi_name() === 'cli') {
    $args = array_slice($argv, 1);
    foreach ($args as $arg) {
        switch ($arg) {
            case '--optimize':
                $options['optimize'] = true;
                break;
            case '--stats-only':
                $options['stats_only'] = true;
                break;
            case '--help':
                echo "TempMail Cleanup Script\n\n";
                echo "Usage: php cleanup.php [options]\n\n";
                echo "Options:\n";
                echo "  --optimize    Run database optimization\n";
                echo "  --stats-only  Only show database statistics\n";
                echo "  --help        Show this help message\n\n";
                exit(0);
        }
    }
}

// Huvudkörning
if (php_sapi_name() === 'cli') {
    echo "TempMail - Starting cleanup process...\n";
}

if (isset($options['stats_only']) && $options['stats_only']) {
    $stats = getDatabaseStats();
    if (php_sapi_name() === 'cli') {
        echo "Database Statistics:\n";
        echo "- Active addresses: " . $stats['active_addresses'] . "\n";
        echo "- Stored emails: " . $stats['stored_emails'] . "\n";
        echo "- System logs: " . $stats['system_logs'] . "\n";
        echo "- Database size: " . $stats['database_size_mb'] . " MB\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['stats' => $stats]);
    }
} else {
    $results = runCleanup($options);
    
    if (php_sapi_name() === 'cli') {
        echo "Cleanup Results:\n";
        echo "- Addresses cleaned: " . $results['addresses_cleaned'] . "\n";
        echo "- Emails cleaned: " . $results['emails_cleaned'] . "\n";
        echo "- Logs cleaned: " . $results['logs_cleaned'] . "\n";
        echo "- Database optimized: " . ($results['optimized'] ? 'Yes' : 'No') . "\n";
        
        if (!empty($results['stats'])) {
            echo "\nCurrent Database Stats:\n";
            echo "- Active addresses: " . $results['stats']['active_addresses'] . "\n";
            echo "- Stored emails: " . $results['stats']['stored_emails'] . "\n";
            echo "- System logs: " . $results['stats']['system_logs'] . "\n";
            echo "- Database size: " . $results['stats']['database_size_mb'] . " MB\n";
        }
        
        exit(0);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['results' => $results]);
    }
}
?>