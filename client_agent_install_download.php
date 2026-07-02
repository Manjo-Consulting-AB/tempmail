<?php
session_start();

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    header('Location: pro_login.php');
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
