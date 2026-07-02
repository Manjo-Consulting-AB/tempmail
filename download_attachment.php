<?php
/**
 * Secure attachment download proxy.
 * Usage: /download_attachment.php?id=<attachment_id>&expires=...&sig=...
 */
require_once __DIR__ . '/config.php';

// Only allow CLI or web requests
if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "This script is intended to be invoked via HTTP.\n");
    exit(2);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid attachment id';
    exit;
}

// Validate signed URL parameters: expires and sig
$expires = isset($_GET['expires']) ? (int)$_GET['expires'] : 0;
$sig = isset($_GET['sig']) ? trim($_GET['sig']) : '';
if (empty($sig) || $expires <= 0) {
    http_response_code(403);
    echo 'Missing signature or expiry';
    exit;
}
if ($expires < time()) {
    http_response_code(410);
    echo 'Link expired';
    exit;
}
$secret = $config['attachments']['download_secret'] ?? '';
if (empty($secret)) {
    error_log('[download_attachment] Missing attachments download secret');
    http_response_code(500);
    echo 'Server configuration error';
    exit;
}
$expected = hash_hmac('sha256', $id . '|' . $expires, $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

try {
    // Join with stored_emails to ensure the email exists and is not expired
    // NOTE: DB column for mime type is `mime_type` in schema; map it to content_type
    $stmt = $pdo->prepare("SELECT ea.filename, ea.file_path, ea.mime_type AS content_type, se.received_at, se.expires_at FROM email_attachments ea JOIN stored_emails se ON ea.email_id = se.id WHERE ea.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo 'Attachment not found';
        exit;
    }

    // Check expiration
    $isExpired = false;
    if (!empty($row['expires_at'])) {
        $expiresTs = strtotime($row['expires_at']);
        if ($expiresTs !== false && $expiresTs <= time()) {
            $isExpired = true;
        }
    } else {
        if (!empty($row['received_at'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . intval($config['app']['cleanup_hours']) . ' hour'));
            if ($row['received_at'] <= $cutoff) $isExpired = true;
        }
    }

    if ($isExpired) {
        http_response_code(410);
        echo 'Attachment expired';
        exit;
    }

    // Securely resolve attachment path to the attachments folder
    $attachmentsRoot = realpath(__DIR__ . '/../attachments');
    if ($attachmentsRoot === false) {
        error_log('[download_attachment] attachments root not found');
        http_response_code(500);
        echo 'Server configuration error';
        exit;
    }

    // Use only the basename of stored file_path to avoid traversal
    $fileBasename = basename($row['file_path']);
    $fullPath = $attachmentsRoot . DIRECTORY_SEPARATOR . $fileBasename;
    $real = realpath($fullPath);
    if (!$real || strpos($real, $attachmentsRoot) !== 0) {
        error_log('[download_attachment] File missing or outside attachments root: ' . $fullPath);
        http_response_code(404);
        echo 'File missing';
        exit;
    }

    // Determine content type
    $ctype = $row['content_type'] ?: 'application/octet-stream';

    // Sanitize filename for header
    $downloadName = basename($row['filename']);
    $downloadName = preg_replace('/[\r\n\\\"\']+/', '_', $downloadName);

    // Support X-Sendfile/X-Accel if enabled via env var USE_X_SENDFILE=1 (requires server config)
    $useXSend = getenv('USE_X_SENDFILE') === '1';
    if ($useXSend) {
        // Prefer nginx X-Accel-Redirect if configured
        $xAccel = getenv('X_ACCEL_REDIRECT_LOCATION'); // example: /protected_attachments/
        if ($xAccel) {
            // Map filesystem path to internal location if possible
            header('Content-Type: ' . $ctype);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('X-Accel-Redirect: ' . rtrim($xAccel, '/') . '/' . $fileBasename);
            exit;
        }
        // Otherwise use X-Sendfile header (Apache mod_xsendfile)
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('X-Sendfile: ' . $real);
        exit;
    }

    // Basic support for HTTP Range requests (resume)
    $filesize = filesize($real);
    $start = 0;
    $length = $filesize;
    $statusCode = 200;
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $rstart = $matches[1] === '' ? null : intval($matches[1]);
            $rend = $matches[2] === '' ? null : intval($matches[2]);
            if ($rstart !== null) $start = $rstart;
            if ($rend !== null) $length = $rend - $start + 1;
            else $length = $filesize - $start;
            if ($start < 0 || $start >= $filesize) {
                http_response_code(416);
                header('Content-Range: bytes */' . $filesize);
                exit;
            }
            $statusCode = 206;
        }
    }

    if ($statusCode === 206) http_response_code(206);
    header('Content-Type: ' . $ctype);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Accept-Ranges: bytes');
    if ($statusCode === 206) {
        $end = $start + $length - 1;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
        header('Content-Length: ' . $length);
    } else {
        header('Content-Length: ' . $filesize);
    }
    header('Cache-Control: private, max-age=0, must-revalidate');

    // Stream file with fseek for range support
    $fh = fopen($real, 'rb');
    if ($fh === false) {
        error_log('[download_attachment] Failed to open file: ' . $real);
        http_response_code(500);
        echo 'Server error';
        exit;
    }
    if ($start > 0) fseek($fh, $start);
    $bytesToSend = $length;
    $bufferSize = 8192;
    while (!feof($fh) && $bytesToSend > 0) {
        $read = min($bufferSize, $bytesToSend);
        $data = fread($fh, $read);
        if ($data === false) break;
        echo $data;
        flush();
        $bytesToSend -= strlen($data);
    }
    fclose($fh);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Server error';
    exit;
}
