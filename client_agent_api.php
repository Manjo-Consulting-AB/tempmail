<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$route = $_GET['route'] ?? ($_POST['route'] ?? '');
if (is_string($route) && $route !== '') {
    $_SERVER['REQUEST_URI'] = '/' . ltrim($route, '/');
    $_SERVER['PATH_INFO'] = '/' . ltrim($route, '/');
}

require_once __DIR__ . '/client/backend/api.php';
