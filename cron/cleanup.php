<?php
/**
 * TempMail - Cleanup Script
 * Detta script körs som cron job för att rensa gamla data
 */

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../TwoFactorAuth.php';
require_once __DIR__ . '/../email_log_ref.php';
require_once __DIR__ . '/../pii_crypto.php';

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
 * Degradera utgångna PRO-konton till Regular (raderar dem aldrig) och
 * städa personliga adresser efter en sjudagars grace-period.
 *
 * Se documentaion/ACCOUNT_TIERS.md §6.1 för bakgrund/beslut.
 *
 * Steg 1 (degradering, körs direkt vid pro_expires_at < NOW()):
 *   account_type='regular', digest_enabled=0, address_ttl_days=1,
 *   feed_token=NULL, och pro_webhooks pausas (filter_mode='paused')
 *   i stället för att raderas - de fungerar igen vid en ny uppgradering.
 *   Även de per-adress-flödena (#160) nollas: temp_emails.feed_token sätts
 *   till NULL för användarens personliga adresser, så en degraderad kontos
 *   gamla flödes-URL:er slutar fungera. Antalet nollade rader loggas som
 *   address_feed_tokens_cleared.
 *   password_hash rörs inte längre: kontot lever vidare som Regular och
 *   måste kunna logga in med lösenord.
 *
 * Steg 2 (adressradering, grace-period 7 dagar efter pro_expires_at):
 *   personliga adresser (och deras mail/bilagor/DirectAdmin-forwarders)
 *   raderas med den befintliga rutinen, oförändrad.
 *
 * Kontot i pro_users raderas ALDRIG av den här funktionen - inaktiva
 * gratiskonton städas av en separat rutin (se #63) på helt andra grunder.
 *
 * Idempotens: Steg 1:s urval scopas till account_type='pro', så en redan
 * degraderad användare plockas inte upp igen (ingen dubbelloggning, inga
 * ompausade webhooks efter att användaren själv återaktiverat dem). Steg
 * 2:s urval kräver dessutom att det faktiskt finns kvarvarande personliga
 * adresser (INNER JOIN mot temp_emails), så när adresserna väl är borta
 * försvinner kontot ur nästa körnings urval av sig självt - även det
 * idempotent, utan extra tillståndsflagga.
 */
function cleanupExpiredProUsers() {
    global $pdo;

    if (!tableHasColumn('pro_users', 'account_type')) {
        logMessage('WARNING', 'cleanupExpiredProUsers: pro_users.account_type saknas, hoppar över degradering och adressradering (migrationen är inte körd)');
        return 0;
    }

    try {
        $processed = 0;

        // --- Steg 1: degradera Pro-konton vars pro_expires_at har passerat ---
        // Scopas till account_type = 'pro' för idempotens: så fort ett konto
        // degraderats till 'regular' plockas det inte upp av den här frågan igen.
        $stmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE account_type = 'pro' AND pro_expires_at IS NOT NULL AND pro_expires_at < NOW()");
        $stmt->execute();
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        logMessage('DEBUG', 'cleanupExpiredProUsers: found ' . count($expired) . ' pro users to degrade');

        foreach ($expired as $u) {
            $userId = (int)$u['id'];

            $uup = $pdo->prepare("UPDATE pro_users SET account_type = 'regular', digest_enabled = 0, address_ttl_days = 1, feed_token = NULL WHERE id = ?");
            $uup->execute([$userId]);

            $wstmt = $pdo->prepare("UPDATE pro_webhooks SET filter_mode = 'paused' WHERE user_id = ? AND filter_mode != 'paused'");
            $wstmt->execute([$userId]);
            $pausedWebhooks = $wstmt->rowCount();

            // Nolla även per-adress-flödena (#160). Guarded: kolumnen finns inte
            // förrän migrate_address_feed_tokens.php har körts.
            $feedTokensCleared = 0;
            if (tableHasColumn('temp_emails', 'feed_token')) {
                $fstmt = $pdo->prepare("UPDATE temp_emails SET feed_token = NULL WHERE pro_user_id = ? AND feed_token IS NOT NULL");
                $fstmt->execute([$userId]);
                $feedTokensCleared = $fstmt->rowCount();
            }

            logMessage('INFO', 'Expired pro user degraded to regular', [
                'user_id' => $userId,
                'pro_expires_at' => $u['pro_expires_at'],
                'webhooks_paused' => $pausedWebhooks,
                'address_feed_tokens_cleared' => $feedTokensCleared
            ]);

            $processed++;
        }

        // --- Steg 2: radera personliga adresser efter sjudagars grace-period ---
        // INNER JOIN mot temp_emails gör frågan idempotent: ett konto vars
        // adresser redan raderats en tidigare natt plockas inte upp igen.
        // Fångar både konton som just degraderades ovan (samma körning) och
        // redan degraderade konton från tidigare körningar.
        $gstmt = $pdo->prepare(
            "SELECT DISTINCT pu.id, pu.pro_expires_at
             FROM pro_users pu
             INNER JOIN temp_emails te ON te.pro_user_id = pu.id AND te.is_personal = 1
             WHERE pu.pro_expires_at IS NOT NULL AND pu.pro_expires_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $gstmt->execute();
        $graceExpired = $gstmt->fetchAll(PDO::FETCH_ASSOC);

        logMessage('DEBUG', 'cleanupExpiredProUsers: found ' . count($graceExpired) . ' users past the 7-day address grace period');

        foreach ($graceExpired as $u) {
            $userId = (int)$u['id'];

            // Hämta personliga adresser för denna användare
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

            logMessage('INFO', 'Personal addresses removed for expired pro user past grace period', [
                'user_id' => $userId,
                'pro_expires_at' => $u['pro_expires_at'],
                'personal_addresses_removed' => count($personalIds),
                'emails_deleted' => $totalEmailsDeleted,
                'attachments_deleted' => $totalAttachments,
                'files_deleted' => $totalFiles
            ]);

            $processed++;
        }

        return $processed;

    } catch (Exception $e) {
        logMessage('ERROR', 'Failed to cleanup expired pro users: ' . $e->getMessage());
        return false;
    }
}

/**
 * Skicka varningsmail till ett Regular-konto som varit inaktivt länge.
 * Byggd som sendLoginEmail() i pro_auth.php:86-142 - samma From-header,
 * samma mail()-anrop med envelope-parameter, samma logg-hantering vid
 * misslyckande. Mailtexten är på engelska, som övriga utskick.
 */
function sendInactivityWarningEmail($email, $userId = null) {
    global $config;

    $loginUrl = $config['email']['base_url'] . "pro_login.php";
    $subject = "Your Mail Shield account will be deleted soon";
    $message = "Hello,\n\n"
        . "Your Mail Shield account (" . $email . ") has been inactive for a while. "
        . "To keep it, simply sign in within the next month:\n\n"
        . $loginUrl . "\n\n"
        . "If you do not sign in, your account and all of its email addresses "
        . "will be automatically and permanently deleted in about 30 days.\n\n"
        . "If you no longer need this account, no action is required - it will "
        . "be removed automatically.\n\n"
        . "Regards,\nThe Mail Shield Team";

    // Bestäm avsändaradress (kan sättas via ENV t.ex. EMAIL_FROM)
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
        // Använd envelope param för att ange Return-Path om servern stödjer det
        $envelope = '-f' . $fromAddress;
        $sent = mail($email, $subject, $message, $headersStr, $envelope);
        if ($sent === false) {
            logMessage('ERROR', 'mail() returned false when attempting to send inactivity warning', emailLogContext((string) $email, $userId));
        }
    } catch (Exception $e) {
        logMessage('ERROR', 'Inactivity warning mail sending unexpected error', ['error' => $e->getMessage()] + emailLogContext((string) $email, $userId));
        $sent = false;
    }

    if ($sent) {
        logMessage('INFO', 'Inactivity warning email sent', emailLogContext((string) $email, $userId));
    } else {
        logMessage('ERROR', 'Failed to send inactivity warning email', emailLogContext((string) $email, $userId));
    }

    return $sent;
}

/**
 * Städa inaktiva Regular-konton: varning i god tid, radering därefter.
 *
 * Se documentaion/ACCOUNT_TIERS.md §6.2 för bakgrund/beslut.
 *
 * Underlag för inaktivitet: COALESCE(last_login_at, created_at) - ett konto
 * som aldrig loggat in räknas från när det skapades.
 *
 * Steg 1 (varning, inaktiv >= REGULAR_INACTIVITY_WARN_DAYS men fortfarande
 * under REGULAR_INACTIVITY_DAYS, och inactivity_warned_at IS NULL): skicka
 * varningsmail och sätt inactivity_warned_at = NOW(). Den övre gränsen
 * (< REGULAR_INACTIVITY_DAYS) finns för att undvika att ett konto både
 * varnas och raderas i samma körning (t.ex. om cronen legat nere länge) -
 * ett varningsmail strax innan kontot ändå raderas fyller ingen funktion.
 * Misslyckat mailutskick sätter INTE inactivity_warned_at - annars skulle
 * kontot kunna raderas senare utan att någon varning någonsin gått fram.
 *
 * Steg 2 (radering, inaktiv >= REGULAR_INACTIVITY_DAYS): radera kontots
 * samtliga adresser (personliga OCH icke-personliga - till skillnad från
 * cleanupExpiredProUsers() som bara rör personliga adresser), deras mail,
 * bilagor och DirectAdmin-forwarders (samma mönster som
 * cleanupExpiredProUsers(), rad 353-396), samt kringliggande rader utan
 * FK-cascade (se kommentar vid raderingskoden nedan) och slutligen
 * pro_users-raden.
 *
 * Konton med account_type = 'pro' rörs ALDRIG, oavsett hur inaktiva de är -
 * frågorna nedan scopas alltid till account_type = 'regular'.
 *
 * Bakåtkompatibilitet: saknas account_type, last_login_at eller
 * inactivity_warned_at i schemat görs ingenting alls (varken varning eller
 * radering) - en raderingsrutin får aldrig köra på ofullständiga antaganden.
 * Migreringen (migrate_account_types.php) lägger till alla tre kolumnerna
 * tillsammans, så i praktiken skiljer de sig aldrig åt, men vi kontrollerar
 * inactivity_warned_at explicit ändå: utan den kolumnen kan rutinen aldrig
 * markera ett konto som varnat, vilket skulle göra "varna alltid före
 * radering" omöjligt att garantera - inte bara för denna körning utan för
 * alltid.
 */
function cleanupInactiveRegularAccounts() {
    global $pdo, $config;

    if (
        !tableHasColumn('pro_users', 'account_type')
        || !tableHasColumn('pro_users', 'last_login_at')
        || !tableHasColumn('pro_users', 'inactivity_warned_at')
    ) {
        logMessage('WARNING', 'cleanupInactiveRegularAccounts: pro_users saknar account_type/last_login_at/inactivity_warned_at, hoppar över (migrationen är inte körd)');
        return 0;
    }

    // Trösklarna valideras redan i config.php (varning måste komma före
    // radering, annars faller de tillbaka till 335/365) - dubbelkollas här
    // ändå som ett andra skyddsnät innan en raderingsfråga byggs.
    $warnDays = (int)($config['cleanup']['regular_inactivity_warn_days'] ?? 335);
    $deleteDays = (int)($config['cleanup']['regular_inactivity_days'] ?? 365);
    if ($warnDays >= $deleteDays) {
        logMessage('WARNING', 'cleanupInactiveRegularAccounts: ogiltiga trösklar vid anropstillfället, faller tillbaka till standardvärden', ['warn_days' => $warnDays, 'delete_days' => $deleteDays]);
        $warnDays = 335;
        $deleteDays = 365;
    }

    try {
        $processed = 0;

        // --- Steg 1: varna inaktiva Regular-konton ---
        $wstmt = $pdo->prepare(
            "SELECT id, email_enc FROM pro_users " .
            "WHERE account_type = 'regular' AND inactivity_warned_at IS NULL " .
            "AND COALESCE(last_login_at, created_at) <= DATE_SUB(NOW(), INTERVAL ? DAY) " .
            "AND COALESCE(last_login_at, created_at) > DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $wstmt->execute([$warnDays, $deleteDays]);
        $toWarn = $wstmt->fetchAll(PDO::FETCH_ASSOC);

        logMessage('DEBUG', 'cleanupInactiveRegularAccounts: found ' . count($toWarn) . ' regular users to warn');

        foreach ($toWarn as $u) {
            $userId = (int)$u['id'];
            // Decrypted from email_enc; null (ERROR logged) means no mail and
            // no inactivity_warned_at, so the account is retried next run.
            $email = piiEmailOpen($u['email_enc'], ['user_id' => $userId]);

            if (empty($email)) {
                logMessage('WARNING', 'cleanupInactiveRegularAccounts: skipping warning, no email on file', ['user_id' => $userId]);
                continue;
            }

            $sent = sendInactivityWarningEmail($email, $userId);
            if ($sent) {
                $uup = $pdo->prepare("UPDATE pro_users SET inactivity_warned_at = NOW() WHERE id = ?");
                $uup->execute([$userId]);
                logMessage('INFO', 'Inactive regular account warned', ['user_id' => $userId]);
                $processed++;
            } else {
                // Misslyckat mailutskick - sätt INTE inactivity_warned_at (se
                // funktionskommentaren ovan).
                logMessage('WARNING', 'Inactivity warning email failed, inactivity_warned_at not set', ['user_id' => $userId]);
            }
        }

        // --- Steg 2: radera Regular-konton som varit inaktiva tillräckligt länge ---
        $dstmt = $pdo->prepare(
            "SELECT id FROM pro_users " .
            "WHERE account_type = 'regular' " .
            "AND COALESCE(last_login_at, created_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $dstmt->execute([$deleteDays]);
        $toDelete = $dstmt->fetchAll(PDO::FETCH_ASSOC);

        logMessage('DEBUG', 'cleanupInactiveRegularAccounts: found ' . count($toDelete) . ' regular users to delete');

        foreach ($toDelete as $u) {
            $userId = (int)$u['id'];

            // Hämta ALLA adresser för kontot - personliga OCH icke-personliga.
            $tstmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ?");
            $tstmt->execute([$userId]);
            $addresses = $tstmt->fetchAll(PDO::FETCH_ASSOC);

            $totalEmailsDeleted = 0;
            $totalAttachments = 0;
            $totalFiles = 0;

            foreach ($addresses as $address) {
                $tempEmailId = $address['id'];

                $sstmt = $pdo->prepare("SELECT id FROM stored_emails WHERE temp_email_id = ?");
                $sstmt->execute([$tempEmailId]);
                $emailIds = $sstmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($emailIds as $emailId) {
                    $r = cleanupEmailAttachments($emailId);
                    $totalAttachments += $r['attachments'];
                    $totalFiles += $r['files'];
                }

                $delStmt = $pdo->prepare("DELETE FROM stored_emails WHERE temp_email_id = ?");
                $delStmt->execute([$tempEmailId]);
                $totalEmailsDeleted += $delStmt->rowCount();

                $tempDelStmt = $pdo->prepare("DELETE FROM temp_emails WHERE id = ?");
                $tempDelStmt->execute([$tempEmailId]);
                if ($tempDelStmt->rowCount() > 0) {
                    deleteDirectAdminForwarder($address['unique_address']);
                }
            }

            // Radera kringliggande rader utan FK-cascade innan pro_users-raden
            // tas bort. Ingen av tabellerna i den här kodbasen har någon
            // FOREIGN KEY (verifierat - se pro_auth.php:s delete_account-flöde,
            // som redan gör exakt samma sak av samma anledning, och
            // TwoFactorAuth::ensureSchema() som visar att pro_user_totp/
            // pro_trusted_devices saknar FK helt). redemption_log rörs
            // medvetet INTE - precis som i delete_account-flödet - eftersom
            // den är en historik-/missbruksspärrlogg (en redan raderad
            // användares id återanvänds aldrig av AUTO_INCREMENT, så
            // kvarvarande rader varken pekar fel eller möjliggör nya
            // voucher-inlösen).
            $pdo->beginTransaction();
            try {
                $delDeliveries = $pdo->prepare("DELETE d FROM pro_webhook_deliveries d JOIN pro_webhooks w ON w.id = d.webhook_id WHERE w.user_id = ?");
                $delDeliveries->execute([$userId]);

                $delWebhooks = $pdo->prepare("DELETE FROM pro_webhooks WHERE user_id = ?");
                $delWebhooks->execute([$userId]);

                $delTokens = $pdo->prepare("DELETE FROM login_tokens WHERE user_id = ?");
                $delTokens->execute([$userId]);

                $delPending = $pdo->prepare("DELETE FROM pending_profile_changes WHERE user_id = ?");
                $delPending->execute([$userId]);

                TwoFactorAuth::ensureSchema($pdo);
                $delTotp = $pdo->prepare("DELETE FROM pro_user_totp WHERE user_id = ?");
                $delTotp->execute([$userId]);

                $delRecoveryCodes = $pdo->prepare("DELETE FROM pro_user_recovery_codes WHERE user_id = ?");
                $delRecoveryCodes->execute([$userId]);

                $delTrustedDevices = $pdo->prepare("DELETE FROM pro_trusted_devices WHERE user_id = ?");
                $delTrustedDevices->execute([$userId]);

                $delUser = $pdo->prepare("DELETE FROM pro_users WHERE id = ?");
                $delUser->execute([$userId]);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                logMessage('ERROR', 'Failed deleting inactive regular account (related rows/pro_users row)', ['user_id' => $userId, 'error' => $e->getMessage()]);
                continue;
            }

            logMessage('INFO', 'Inactive regular account deleted', [
                'user_id' => $userId,
                'addresses_removed' => count($addresses),
                'emails_deleted' => $totalEmailsDeleted,
                'attachments_deleted' => $totalAttachments,
                'files_deleted' => $totalFiles
            ]);

            $processed++;
        }

        return $processed;

    } catch (Exception $e) {
        logMessage('ERROR', 'Failed to cleanup inactive regular accounts: ' . $e->getMessage());
        return false;
    }
}

/**
 * Städa föräldralösa länkar i pro_webhook_addresses (#251 step 6).
 *
 * Den nya routingen (epic #251) kopplar varje webhook till sina personliga
 * adresser via pro_webhook_addresses. Tabellen har medvetet INGA foreign keys
 * (se migrate_webhook_addresses.php), så en länk städas bara om just den
 * kodväg som tar bort raden också tar bort länkarna. Det gör delete_personal
 * (index.php) och webhook_delete (pro_profile.php) - men inte de vägar som
 * raderar adresser och konton i bulk: utgångna adresser i
 * cleanupExpiredAddresses(), grace-periodsraderingen i
 * cleanupExpiredProUsers() och kontoborttagningen i pro_auth.php. De lämnar
 * alltså länkar kvar som pekar på en webhook eller en adress som inte finns,
 * eller på en adress som inte längre är personlig eller inte tillhör
 * webhookens ägare.
 *
 * Den här svepningen tar bort dem. Villkoret är detsamma som routingen
 * förutsätter: länken behålls bara om både webhooken och en personlig adress
 * som ägs av samma användare finns kvar.
 *
 * Idempotent och billig: en körning utan orphan-rader gör ingenting.
 */
function cleanupOrphanWebhookAddressLinks() {
    global $pdo;

    if (!tableHasColumn('pro_webhook_addresses', 'webhook_id')) {
        logMessage('DEBUG', 'cleanupOrphanWebhookAddressLinks: pro_webhook_addresses saknas, hoppar över (migrationen är inte körd)');
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            "DELETE l FROM pro_webhook_addresses l
             LEFT JOIN pro_webhooks w ON w.id = l.webhook_id
             LEFT JOIN temp_emails t ON t.id = l.temp_email_id AND t.is_personal = 1 AND t.pro_user_id = w.user_id
             WHERE w.id IS NULL OR t.id IS NULL"
        );
        $stmt->execute();
        $deleted = $stmt->rowCount();

        if ($deleted > 0) {
            logMessage('INFO', 'Orphan webhook address links cleaned up', ['count' => $deleted]);
        } else {
            logMessage('DEBUG', 'No orphan webhook address links found');
        }

        return $deleted;

    } catch (Exception $e) {
        logMessage('ERROR', 'Failed to cleanup orphan webhook address links: ' . $e->getMessage());
        return false;
    }
}

/**
 * Städa gamla rader i pro_trial_claims (epic #267, #271).
 *
 * En claim-rad hålls i fem år efter first_seen_at
 * ($config['trial']['claim_retention_days'], default 1825) och raderas
 * därefter - adressen räknas då som aldrig sedd. Tabellen har medvetet
 * ingen koppling till pro_users (se migrate_trial_claims.php), så den här
 * svepningen är den enda platsen som tar bort rader ur den, och gör det
 * bara på ålder.
 *
 * Idempotent och billig: en körning utan utgångna rader gör ingenting.
 */
function cleanupExpiredTrialClaims() {
    global $pdo, $config;

    if (!tableHasColumn('pro_trial_claims', 'email_hash')) {
        logMessage('DEBUG', 'cleanupExpiredTrialClaims: pro_trial_claims saknas, hoppar över (migrationen är inte körd, se migrate_trial_claims.php)');
        return 0;
    }

    $days = (int)($config['trial']['claim_retention_days'] ?? 1825);
    if ($days < 1) {
        $days = 1825;
    }

    try {
        $stmt = $pdo->prepare(
            "DELETE FROM pro_trial_claims WHERE first_seen_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        $deleted = $stmt->rowCount();

        if ($deleted > 0) {
            logMessage('INFO', 'Expired trial claims cleaned up', ['count' => $deleted]);
        } else {
            logMessage('DEBUG', 'No expired trial claims found');
        }

        return $deleted;

    } catch (Exception $e) {
        logMessage('ERROR', 'Failed to cleanup expired trial claims: ' . $e->getMessage());
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

    // Degradera utgångna PRO-konton till Regular och rensa deras personliga
    // adresser efter grace-perioden (raderar aldrig kontot, se §6.1)
    $proUsersResult = cleanupExpiredProUsers();
    if ($proUsersResult !== false) {
        $results['expired_pro_users_cleaned'] = $proUsersResult;
    }

    // Varna och radera inaktiva Regular-konton (raderar ALDRIG Pro-konton, se §6.2)
    $regularUsersResult = cleanupInactiveRegularAccounts();
    if ($regularUsersResult !== false) {
        $results['regular_users_cleaned'] = $regularUsersResult;
    }

    // Städa länkar som raderingsvägarna ovan kan ha lämnat kvar (#251 step 6)
    $orphanLinksResult = cleanupOrphanWebhookAddressLinks();
    if ($orphanLinksResult !== false) {
        $results['orphan_webhook_links_cleaned'] = $orphanLinksResult;
    }

    // Städa claims i pro_trial_claims som är äldre än fem år (epic #267)
    $trialClaimsResult = cleanupExpiredTrialClaims();
    if ($trialClaimsResult !== false) {
        $results['trial_claims_cleaned'] = $trialClaimsResult;
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
                echo "Mail Shield Cleanup Script\n\n";
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
    echo "Mail Shield - Starting cleanup process...\n";
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