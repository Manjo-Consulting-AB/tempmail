<?php
/**
 * App shell for the admin pages (log_viewer.php, abuse_admin.php): the same
 * Bootstrap + bridge head and partials/nav.php as the other app pages, then
 * the admin sub-navigation. Closed by partials/admin_foot.php.
 *
 * Expects $msAdminTitle (the <title> prefix) and $msAdminTab (see
 * partials/admin_tabs.php). The caller has already enforced the
 * ADMIN_USER_IDS gate.
 */
if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}
$msAssetVersion = static function (string $path): int {
    return (int) (@filemtime(dirname(__DIR__) . '/' . $path) ?: 1);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#FAFAF9">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars((string) ($msAdminTitle ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?> · Mail Shield</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/mailshield-fonts.css?v=<?php echo $msAssetVersion('assets/css/mailshield-fonts.css'); ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo $msAssetVersion('assets/css/mailshield.css'); ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo $msAssetVersion('assets/css/mailshield-bootstrap.css'); ?>" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <?php require __DIR__ . '/nav.php'; ?>
        </div>
        <main class="ms-admin ms-admin__main">
            <?php require __DIR__ . '/admin_tabs.php'; ?>
