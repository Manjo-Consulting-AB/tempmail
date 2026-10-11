<?php
/**
 * Router for the local preview, emulating the language rewrites in .htaccess
 * that PHP's built-in server does not read (i18n.php, documentaion/I18N.md):
 *
 *   php -S 127.0.0.1:8085 tests/lib/dev_router.php
 *
 * /sv/ and /sv/<page>.php are served by the same file as the English page,
 * with REQUEST_URI left as it was — exactly what Apache does, so
 * i18nBootstrap() sees the prefix. Everything else is left to the built-in
 * server (return false), so the preview behaves as `php -S` without a router.
 * tests/i18n_test.php uses it too. Refuses to run under anything but the
 * built-in server.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (preg_match('#^/(sv)/?$#', $path)) {
    $file = 'index.php';
} elseif (preg_match('#^/(sv)/([A-Za-z0-9_-]+\.php)$#', $path, $m)) {
    $file = $m[2];
} else {
    return false;
}

if (!is_file($root . '/' . $file)) {
    http_response_code(404);
    exit;
}

$_SERVER['SCRIPT_NAME'] = '/' . $file;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $file;
$_SERVER['PHP_SELF'] = '/' . $file;
chdir($root);
require $root . '/' . $file;
