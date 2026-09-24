<?php
// config.php first: it fixes the session cookie parameters (HttpOnly,
// SameSite, Secure), which only apply to a session started after it.
require_once __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    header('Location: pro_login.php');
    exit;
}

if (!proUserIsPro((int)$_SESSION['pro_user_id'])) {
    http_response_code(403);
    echo 'Pro required';
    exit;
}

$agentPath = __DIR__ . '/client/agent/agent.php';
if (!is_file($agentPath) || !is_readable($agentPath)) {
    http_response_code(404);
    echo 'agent.php not found';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="agent.php"');
header('Content-Length: ' . filesize($agentPath));
header('X-Content-Type-Options: nosniff');

readfile($agentPath);
exit;
