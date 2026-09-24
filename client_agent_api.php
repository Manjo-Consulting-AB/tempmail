<?php
// config.php first: it fixes the session cookie parameters (HttpOnly,
// SameSite, Secure), which only apply to a session started after it.
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$route = $_GET['route'] ?? ($_POST['route'] ?? '');
if (is_string($route) && $route !== '') {
    $normalizedRoute = '/' . ltrim($route, '/');
    if (preg_match('/^\/[a-zA-Z0-9_\-\/]+$/', $normalizedRoute) === 1) {
        $_SERVER['REQUEST_URI'] = $normalizedRoute;
        $_SERVER['PATH_INFO'] = $normalizedRoute;
    }
}

require_once __DIR__ . '/client/backend/api.php';
