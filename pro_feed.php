<?php
/**
 * RSS feed for Pro users. Access via token: /pro_feed.php?token=<token>&limit=50
 */
require_once __DIR__ . '/config.php';

// Minimal hardening
if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "This script is intended to be invoked via HTTP.\n");
    exit(2);
}

$rawToken = $_GET['token'] ?? '';
$limit = sanitizeInt($_GET['limit'] ?? null, 1, 200, 50);

// Validate token format first: hex string, 32-128 chars
// This must come BEFORE detectSuspiciousPatterns() because valid hex tokens
// trigger a false positive on the base64_payload pattern (64 hex chars look like base64)
$token = sanitizeHexToken($rawToken, 32, 128);
if (!$token) {
    // Only check for suspicious patterns if it's not a valid hex token
    // This catches actual attacks while allowing legitimate tokens through
    $suspicious = detectSuspiciousPatterns((string)$rawToken);
    if (!empty($suspicious)) {
        logMessage('WARNING', 'Suspicious feed token attempt', ['patterns' => $suspicious, 'ip' => getVisitorIp()]);
        // Flagga IP för blockering
        flagMaliciousActivity(getVisitorIp(), 'Suspicious feed token: ' . implode(', ', $suspicious));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid request";
        exit;
    }
    
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing or invalid token";
    exit;
}

try {
    // Find pro_user by token
    $stmt = $pdo->prepare("SELECT id, email, pro_expires_at FROM pro_users WHERE feed_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid token";
        exit;
    }
    $userId = (int)$user['id'];

    // Fetch recent stored_emails for this user's temp addresses
    $q = $pdo->prepare(
        "SELECT se.id, se.from_address, se.subject, se.body_html, se.body_text, se.received_at
         FROM stored_emails se
         JOIN temp_emails te ON se.temp_email_id = te.id
         WHERE te.pro_user_id = ?
         ORDER BY se.received_at DESC
         LIMIT ?"
    );
    $q->bindValue(1, $userId, PDO::PARAM_INT);
    $q->bindValue(2, $limit, PDO::PARAM_INT);
    $q->execute();
    $emails = $q->fetchAll(PDO::FETCH_ASSOC);

    // Build RSS 2.0
    $base = rtrim($config['email']['base_url'] ?? '', '/');
    // Cache: short private cache to allow CDNs to respect privacy
    header('Cache-Control: private, max-age=60, s-maxage=60');
    header('Content-Type: application/rss+xml; charset=utf-8');

    // Use a single timestamp for all attachment signatures in this feed
    $signatureTime = time();

    // Determine last build date from newest email if available
    $lastBuildTs = $signatureTime;
    if (!empty($emails) && !empty($emails[0]['received_at'])) {
        $t = strtotime($emails[0]['received_at']);
        if ($t !== false) $lastBuildTs = $t;
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<rss version=\"2.0\">\n<channel>\n";
    echo "<title>TempMail Pro - {$user['email']} - Inbox</title>\n";
    echo "<link>" . htmlspecialchars($base ?: '') . "/pro.php</link>\n";
    echo "<description>Recent messages for your TempMail Pro account</description>\n";
    echo "<language>en</language>\n";
    echo "<lastBuildDate>" . date(DATE_RSS, $lastBuildTs) . "</lastBuildDate>\n";

    foreach ($emails as $e) {
        $eid = (int)$e['id'];
        $title = htmlspecialchars($e['subject'] ?: '(no subject)');
        $from = htmlspecialchars($e['from_address'] ?? '(unknown)');
        $pub = isset($e['received_at']) ? date(DATE_RSS, strtotime($e['received_at'])) : date(DATE_RSS);

        // Prefer HTML body if available
        $content = '';
        if (!empty($e['body_html'])) {
            $content = $e['body_html'];
        } elseif (!empty($e['body_text'])) {
            $content = nl2br(htmlspecialchars($e['body_text']));
        }

        // Fetch attachments for this email
        $attStmt = $pdo->prepare("SELECT id, filename, mime_type FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
        $attStmt->execute([$eid]);
        $atts = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<item>\n";
        echo "<title>" . $title . "</title>\n";
        // Link points to pro UI; full email content is in description
        echo "<link>" . htmlspecialchars($base . '/pro.php') . "</link>\n";
        echo "<description><![CDATA[<div><strong>From:</strong> {$from}</div>" . $content . "]]></description>\n";
        echo "<pubDate>" . $pub . "</pubDate>\n";
        echo "<guid isPermaLink=\"false\">email-" . $eid . "</guid>\n";

        // Add enclosures for attachments
        foreach ($atts as $a) {
            $aid = (int)$a['id'];
            $mime = htmlspecialchars($a['mime_type'] ?? 'application/octet-stream');
            // Generate signed URL with consistent timestamp
            $url = function_exists('generateSignedAttachmentUrl') ? generateSignedAttachmentUrl($aid, null, $signatureTime) : ($base . '/files.php?id=' . $aid);
            echo "<enclosure url=\"" . htmlspecialchars($url) . "\" type=\"{$mime}\" />\n";
        }

        echo "</item>\n";
    }

    echo "</channel>\n</rss>\n";

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    if (function_exists('logMessage')) {
        logMessage('ERROR', '[pro_feed] Error', ['error' => $e->getMessage(), 'token' => $token ?? null]);
    } else {
        error_log('[pro_feed] Error: ' . $e->getMessage());
    }
    echo 'Server error';
    exit;
}

exit;
