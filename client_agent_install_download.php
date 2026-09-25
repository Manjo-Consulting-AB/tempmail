<?php
// config.php first: it fixes the session cookie parameters (HttpOnly,
// SameSite, Secure), which only apply to a session started after it.
require_once __DIR__ . '/config.php';
session_start();
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    header('Location: pro_login.php');
    exit;
}

if (!proUserIsPro((int)$_SESSION['pro_user_id'])) {
    http_response_code(403);
    echo 'Pro required';
    exit;
}

$installerPath = __DIR__ . '/client/agent/install.php';
if (!is_file($installerPath) || !is_readable($installerPath)) {
    http_response_code(404);
    echo 'install.php not found';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="install.php"');
header('Content-Length: ' . filesize($installerPath));
header('X-Content-Type-Options: nosniff');

readfile($installerPath);
exit;
