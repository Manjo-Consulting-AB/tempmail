<?php
/**
 * Secure attachment download proxy (new name: files.php).
 * Usage: /files.php?id=<attachment_id>&expires=...&sig=...
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

// Debug: Log what we received
if (function_exists('logMessage')) {
    logMessage('DEBUG', 'files.php request', ['id' => $id, 'expires' => $expires, 'sig_present' => empty($sig) ? 'NO' : 'YES', 'get' => $_GET]);
} else {
    error_log("[files.php] Request: id=$id, expires=$expires, sig_present=" . (empty($sig) ? 'NO' : 'YES') . ", GET=" . json_encode($_GET));
}

if (empty($sig) || $expires <= 0) {
    http_response_code(403);
    if (function_exists('logMessage')) {
        logMessage('WARNING', 'files.php validation failed', ['sig_empty' => empty($sig) ? 'YES' : 'NO', 'expires' => $expires, 'id' => $id]);
    } else {
        error_log("[files.php] Validation failed: sig_empty=" . (empty($sig) ? 'YES' : 'NO') . ", expires=$expires");
    }
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
    if (function_exists('logMessage')) {
        logMessage('ERROR', 'files.php missing attachments download secret');
    } else {
        error_log('[files.php] Missing attachments download secret');
    }
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
    $attachmentsRoot = realpath(__DIR__ . '/attachments');
    if ($attachmentsRoot === false) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'files.php attachments root not found', ['attachments_root' => __DIR__ . '/attachments']);
        } else {
            error_log('[files.php] attachments root not found');
        }
        http_response_code(500);
        echo 'Server configuration error';
        exit;
    }

    // Use only the basename of stored file_path to avoid traversal
    $fileBasename = basename($row['file_path']);
    $fullPath = $attachmentsRoot . DIRECTORY_SEPARATOR . $fileBasename;
    $real = realpath($fullPath);
    if (!$real || strpos($real, $attachmentsRoot) !== 0) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'files.php file missing or outside attachments root', ['path' => $fullPath]);
        } else {
            error_log('[files.php] File missing or outside attachments root: ' . $fullPath);
        }
        http_response_code(404);
        echo 'File missing';
        exit;
    }

    $ctype = $row['content_type'] ?: 'application/octet-stream';
    $downloadName = basename($row['filename']);
    $downloadName = preg_replace('/[\r\n\\\"\']+/', '_', $downloadName);

    // Support X-Sendfile/X-Accel if enabled via env var USE_X_SENDFILE=1 (requires server config)
    $useXSend = getenv('USE_X_SENDFILE') === '1';
    if ($useXSend) {
        $xAccel = getenv('X_ACCEL_REDIRECT_LOCATION');
        if ($xAccel) {
            header('Content-Type: ' . $ctype);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('X-Accel-Redirect: ' . rtrim($xAccel, '/') . '/' . $fileBasename);
            exit;
        }
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('X-Sendfile: ' . $real);
        exit;
    }

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

    $fh = fopen($real, 'rb');
    if ($fh === false) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'files.php failed to open file', ['path' => $real]);
        } else {
            error_log('[files.php] Failed to open file: ' . $real);
        }
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

