<?php
/**
 * RSS feed for Pro users. Access via token: /pro_feed.php?token=<token>&limit=50
 */
require_once __DIR__ . '/config.php';

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Minimal hardening
if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "This script is intended to be invoked via HTTP.\n");
    exit(2);
}

// purifyEmailHtml() - the HTMLPurifier allowlist shared with the inbox
// (index.php get_email) - lives in email_html_sanitizer.php.
require_once __DIR__ . '/email_html_sanitizer.php';
require_once __DIR__ . '/pii_crypto.php';
require_once __DIR__ . '/feed_token.php';

/**
 * Reject a feed request. Every token failure - unknown token, degraded
 * account, address that is not personal - goes through this one path so the
 * responses stay byte-identical and the endpoint can't be used as an oracle
 * that distinguishes "valid but degraded" from "unknown".
 */
function feedDenyInvalidToken(): void {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid token";
    exit;
}

/**
 * Emit the <item> elements for a feed. Shared by the account-wide and the
 * per-address branch so purification, attachment enclosures and escaping
 * cannot drift apart between the two.
 */
function renderFeedItems(array $emails, string $itemLink, string $base, PDO $pdo, int $signatureTime): void {
    foreach ($emails as $e) {
        $eid = (int)$e['id'];
        $title = htmlspecialchars($e['subject'] ?: '(no subject)');
        $from = htmlspecialchars($e['from_address'] ?? '(unknown)');
        $pub = isset($e['received_at']) ? date(DATE_RSS, strtotime($e['received_at'])) : date(DATE_RSS);

        // Prefer HTML body if available. body_html is raw inbound email HTML -
        // fully attacker-controlled by anyone who emails this address, with no
        // login required - so it's run through HTMLPurifier's allowlist (see
        // purifyEmailHtml()) rather than embedded as-is. If the library is
        // unavailable for any reason, fall back to the safe plain-text flatten.
        $content = '';
        if (!empty($e['body_html'])) {
            $purified = purifyEmailHtml($e['body_html']);
            if ($purified !== null) {
                $content = $purified;
            } else {
                // strip_tags() only removes the <style>/<script> tags themselves,
                // not their text content, so CSS-heavy emails (nearly all of them
                // put their rules in a <head><style> block) would otherwise dump
                // raw CSS source into the feed. Drop those elements entirely first.
                $withoutStyleScript = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $e['body_html']);
                $withBreaks = preg_replace('/<(br|\/p|\/div|\/tr|\/li)\s*\/?>/i', "\n", $withoutStyleScript);
                $content = nl2br(htmlspecialchars(strip_tags($withBreaks)));
            }
        } elseif (!empty($e['body_text'])) {
            $content = nl2br(htmlspecialchars($e['body_text']));
        }
        // Defense in depth: neutralize the CDATA terminator sequence so nothing
        // in the (now fully escaped/tag-stripped) content can break out of the
        // CDATA section below, even if this code changes in the future.
        $content = str_replace(']]>', ']] >', $content);

        // Fetch attachments for this email
        $attStmt = $pdo->prepare("SELECT id, filename, mime_type FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
        $attStmt->execute([$eid]);
        $atts = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<item>\n";
        echo "<title>" . $title . "</title>\n";
        // Link points to the reader for this feed; full email content is in description
        echo "<link>" . htmlspecialchars($itemLink) . "</link>\n";
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
}

$rawToken = $_GET['token'] ?? '';
$limit = sanitizeInt($_GET['limit'] ?? null, 1, 200, 50);

// Validate token format first: exactly 64 hex chars (bin2hex(random_bytes(32)))
// This must come BEFORE detectSuspiciousPatterns() because valid hex tokens
// trigger a false positive on the base64_payload pattern (64 hex chars look like base64)
$token = feedTokenValidate($rawToken);
if (!$token) {
    // Only check for suspicious patterns if it's not a valid hex token
    // This catches actual attacks while allowing legitimate tokens through
    $suspicious = detectSuspiciousPatterns((string)$rawToken);
    if (!empty($suspicious)) {
        logMessage('WARNING', 'Suspicious feed token attempt', ['patterns' => $suspicious, 'ip' => getVisitorIp()]);
        // Flagga IP för blockering - base64_payload alone (any long
        // alphanumeric run) is logged and rejected but never blocks the IP.
        $flagPatterns = patternsWarrantingIpFlag($suspicious);
        if ($flagPatterns) {
            flagMaliciousActivity(getVisitorIp(), 'Suspicious feed token: ' . implode(', ', $flagPatterns));
        }
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
    $base = rtrim($config['email']['base_url'] ?? '', '/');

    // Two token kinds share this endpoint and URL shape: pro_users
    // (account-wide, the original and unchanged behaviour) and temp_emails
    // (one personal address, #160). Since #315 both are looked up by
    // feed_token_hash, never by the plaintext feed_token column - the hash
    // needs no key, so this keeps resolving even without WEBHOOKS_KEY. The
    // account-wide lookup runs first so existing subscriptions resolve
    // exactly as before. Guarded by tableHasColumn() so this behaves exactly
    // as before migrate_feed_token_encryption.php has run.
    $tokenHash = feedTokenHash($token);
    $user = null;
    if (feedTokenColumnsExist('pro_users')) {
        $stmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE feed_token_hash = ? LIMIT 1");
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Between the deploy and migrate_feed_token_encryption.php the new
        // columns do not exist yet; existing feed URLs must keep working, so
        // the plaintext column is used until then, and only then.
        $stmt = $pdo->prepare("SELECT id, pro_expires_at FROM pro_users WHERE feed_token = ? /* pre-migration fallback */ LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($user) {
        $userId = (int)$user['id'];

        // Regular accounts don't get the RSS feed. Respond with the exact same
        // "Invalid token" text as an unknown token so this endpoint can't be used
        // as an oracle that reveals a token is valid but the account is degraded.
        if (!proUserIsPro($userId) || (function_exists('proUserIsSuspended') && proUserIsSuspended($userId))) {
            feedDenyInvalidToken();
        }

        logMessage('DEBUG', 'Feed served', ['branch' => 'account', 'user_id' => $userId]);

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

        // The owner's address titles the feed; left out if it cannot be decrypted (ERROR logged).
        $ownerEmail = proUserEmail($pdo, $userId);
        $channelTitle = ($ownerEmail !== null ? htmlspecialchars($ownerEmail, ENT_XML1 | ENT_QUOTES, 'UTF-8') . ' · ' : '') . 'Inbox · Mail Shield';
        $channelLink = $base . '/pro.php';
        $channelDescription = 'Recent messages for your Mail Shield account';
        $itemLink = $base . '/pro.php';
    } elseif (feedTokenColumnsExist('temp_emails') || tableHasColumn('temp_emails', 'feed_token')) {
        // Per-address feed. is_personal = 1 belongs in the SQL and not just in a
        // PHP check: it is the second line of defence behind the token issuance
        // rules, so an address that stops being personal stops serving even if it
        // somehow kept a token. Before the migration has run the plaintext column
        // is used instead, as for the account-wide lookup above.
        if (feedTokenColumnsExist('temp_emails')) {
            $q = $pdo->prepare("SELECT id, unique_address, pro_user_id FROM temp_emails WHERE feed_token_hash = ? AND is_personal = 1 LIMIT 1");
            $q->execute([$tokenHash]);
        } else {
            $q = $pdo->prepare("SELECT id, unique_address, pro_user_id FROM temp_emails WHERE feed_token = ? /* pre-migration fallback */ AND is_personal = 1 LIMIT 1");
            $q->execute([$token]);
        }
        $addr = $q->fetch(PDO::FETCH_ASSOC);

        // A deleted address, a temporary one, or one whose owner has since been
        // degraded all collapse into the same opaque failure as an unknown token.
        if (!$addr || !proUserIsPro((int)$addr['pro_user_id'])
            || (function_exists('proUserIsSuspended') && proUserIsSuspended((int)$addr['pro_user_id']))) {
            feedDenyInvalidToken();
        }

        $addressId = (int)$addr['id'];
        $address = (string)$addr['unique_address'];
        $ownerId = (int)$addr['pro_user_id'];

        logMessage('DEBUG', 'Feed served', ['branch' => 'address', 'user_id' => $ownerId, 'address' => $address]);

        $q = $pdo->prepare(
            "SELECT se.id, se.from_address, se.subject, se.body_html, se.body_text, se.received_at
             FROM stored_emails se
             WHERE se.temp_email_id = ?
             ORDER BY se.received_at DESC
             LIMIT ?"
        );
        $q->bindValue(1, $addressId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->execute();
        $emails = $q->fetchAll(PDO::FETCH_ASSOC);

        $domain = (string)($config['email']['domain'] ?? '');
        $fullAddress = htmlspecialchars($address . '@' . $domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $channelTitle = $fullAddress . ' · Mail Shield';
        // A reader click lands on the inbox for this address, not the account UI.
        $itemLink = $base . '/inbox.php?address=' . rawurlencode($address);
        $channelLink = $itemLink;
        $channelDescription = 'Messages received at ' . $fullAddress;
    } else {
        feedDenyInvalidToken();
    }

    // Build RSS 2.0
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
    echo "<title>" . $channelTitle . "</title>\n";
    echo "<link>" . htmlspecialchars($channelLink) . "</link>\n";
    echo "<description>" . $channelDescription . "</description>\n";
    echo "<language>en</language>\n";
    echo "<lastBuildDate>" . date(DATE_RSS, $lastBuildTs) . "</lastBuildDate>\n";

    renderFeedItems($emails, $itemLink, $base, $pdo, $signatureTime);

    echo "</channel>\n</rss>\n";

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    if (function_exists('logMessage')) {
        // Never a token: the hash is a safe reference (it identifies the
        // request without letting anyone reading logs read the mail).
        logMessage('ERROR', '[pro_feed] Error', ['error' => $e->getMessage(), 'token_hash' => $tokenHash ?? null]);
    } else {
        error_log('[pro_feed] Error: ' . $e->getMessage());
    }
    echo 'Server error';
    exit;
}

exit;
