<?php
/**
 * Mail Shield marketing shell — document head and page opening.
 * Spec: documentaion/REDESIGN_BRIEF.md §11 (marketing pages load only the two
 * mailshield stylesheets), §12.10 (keep the analytics snippet as it is),
 * §14 (canonical/OG origin comes from $config, never hardcoded).
 *
 * The including page configures the head by setting $msPage before the require:
 *
 *   $msPage = [
 *       'title'        => 'Mail Shield — Your inbox for everything else',
 *       'description'  => '…',
 *       'path'         => '/',
 *       'robots'       => 'index, follow',
 *       'og_type'      => 'website',
 *       'preload_font' => true,
 *   ];
 *   require 'partials/public_head.php';
 *
 * Emits through to the opening <div class="ms-page">. partials/public_footer.php
 * closes it, so the two are only valid as a pair.
 */

if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit;
}

$msDefaults = [
    'title'        => 'Mail Shield — Your inbox for everything else',
    'description'  => '',
    'path'         => '/',
    'robots'       => 'index, follow',
    'og_type'      => 'website',
    'preload_font' => false,
];

if (!isset($msPage) || !is_array($msPage)) {
    $msPage = [];
}

$msPage += $msDefaults;

// Absolute origin for canonical/og:url/twitter:image. BASE_URL carries a
// trailing slash; the path is normalised to exactly one leading slash.
$msOrigin = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msPath   = (string) $msPage['path'];

if ($msPath === '') {
    $msPath = '/';
} elseif ($msPath[0] !== '/') {
    $msPath = '/' . $msPath;
}

$msUrl = $msOrigin . $msPath;

$msTitle       = (string) $msPage['title'];
$msDescription = (string) $msPage['description'];
$msOgImage     = $msOrigin . '/assets/images/og-mailshield.png';

$msEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-BFX6EC3575"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);} 
        gtag('js', new Date());
        gtag('config', 'G-BFX6EC3575');
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $msEsc($msTitle); ?></title>
    <meta name="description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="robots" content="<?php echo $msEsc($msPage['robots']); ?>">
    <link rel="canonical" href="<?php echo $msEsc($msUrl); ?>">

    <meta property="og:type" content="<?php echo $msEsc($msPage['og_type']); ?>">
    <meta property="og:site_name" content="Mail Shield">
    <meta property="og:title" content="<?php echo $msEsc($msTitle); ?>">
    <meta property="og:description" content="<?php echo $msEsc($msDescription); ?>">
    <meta property="og:url" content="<?php echo $msEsc($msUrl); ?>">
    <meta property="og:image" content="<?php echo $msEsc($msOgImage); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $msEsc($msTitle); ?>">
    <meta name="twitter:description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="twitter:image" content="<?php echo $msEsc($msOgImage); ?>">

    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
<?php if (!empty($msPage['preload_font'])) : ?>
    <link rel="preload" href="/assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<?php endif; ?>

    <!-- Marketing pages load these two stylesheets only (brief §11). -->
    <link rel="stylesheet" href="/assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/mailshield-fonts.css') ?: 1; ?>">
    <link rel="stylesheet" href="/assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/mailshield.css') ?: 1; ?>">
</head>
<body>
<div class="ms-page">
