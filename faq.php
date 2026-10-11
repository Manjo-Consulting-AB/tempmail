<?php
/**
 * Mail Shield — frequently asked questions. Spec: documentaion/REDESIGN_BRIEF.md
 * §8 (copy voice), §9 (the honest feature inventory — every claim below is
 * checked against it), §11 (this is an app-layer page: Bootstrap, Font Awesome
 * and jQuery stay, the bridge restyles them, and only the page shape is new) and
 * §14 (canonical/OG/Twitter, origin from $config, never hardcoded).
 *
 * Two things this file used to get wrong and no longer does:
 *
 *   1. It emitted its PHP header block and its document-type declaration twice,
 *      so a stray one shipped as text before the document began. There is now
 *      exactly one of each.
 *   2. Several answers described a product that no longer exists: anonymous
 *      address generation from the homepage, addresses detached from any
 *      account, a Pro checkout path, and an unqualified retention claim.
 *      Creating an address requires a registered account (inbox.php gates the
 *      generator on it), the retention split is 24 hours free / 1–7 days Pro
 *      (index.php:256 and :266), the personal-address cap is 10 (index.php:166),
 *      and Pro is unlocked by a paid plan on pricing.php (checkout needs a
 *      signed-in account) or a voucher code (PRO_SELF_SIGNUP_ENABLED is off, so
 *      register.php?plan=pro still asks for one).
 *
 * The layout is grouped sections of always-visible copy, not collapsible
 * disclosure widgets: a page that hides every answer by default is worse for the
 * reader and for search, and Redesign 21 (#115) emits FAQPage structured data
 * from it. So the markup stays plain and semantic — one top-level heading, one
 * heading per group, one per question, answers in the prose measure.
 *
 * Since #115 the copy itself is a PHP array ($msFaq, below) that both the
 * sections and the FAQPage JSON-LD render from. That is deliberate: the
 * acceptance criterion is that the structured data matches the visible answers
 * exactly, and the only way to keep that true over time is to have one copy of
 * each answer rather than two.
 *
 * The bar is rendered here rather than through partials/nav.php. That partial is
 * still the old app nav (hamburger, Font Awesome icons, signed-in dashboard
 * links) and Redesign 22 owns its restyle; linking it in today would put the
 * pre-redesign header on a redesigned page. When Redesign 22 lands, this bar can
 * be swapped for it — the links are the same public set.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/partials/brand.php';

// §2.1: the domain is rendered, never written into copy.
$domain = $config['email']['domain'] ?? '';

$msOrigin      = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msPath        = '/faq.php';
$msUrl         = $msOrigin . i18nUrl($msPath);
$msOgImage     = $msOrigin . '/assets/images/og-mailshield.png';
$msTitle       = t('faq.meta.title');
$msDescription = t('faq.meta.description');

// The bar switches on the same session flag the marketing nav uses, so a
// signed-in reader is never sent back through sign-up.
$msSignedIn = !empty($_SESSION['pro_user_id']);
$msCtaHref  = $msSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msCtaLabel = $msSignedIn ? t('faq.go_to_inbox') : t('faq.create_inbox');

$msEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$msDomain = $msEsc($domain);

// A link to a public page, in this request's language and escaped for HTML. The
// answers in partials/content/faq.<locale>.php use it for every public page;
// links to app pages stay literal.
$msU = function (string $path): string {
    return htmlspecialchars(i18nUrl($path), ENT_QUOTES, 'UTF-8');
};

// The page's own stylesheets are relative links (history). Under /sv/ a relative
// link would resolve to /sv/assets/..., so every non-default language gets the
// root-relative form; the English page's markup is unchanged.
$msAssets = i18nLocale() === i18nDefaultLocale() ? '' : '/';

// Where an MCP client connects (#324): the configured origin plus /mcp, never a
// literal. The automation answers below interpolate it.
$msMcpUrl = $msEsc($msOrigin . '/mcp');

// Whether a client can connect by signing in yet (#331, step 6) — the same pair
// mcp.php advertises the flow on: the operator's OAUTH_ENABLED switch and
// migrate_oauth.php, through oauthDiscoveryEnabled(), which fails closed. The
// AI-assistant answer below promises a sign-in only while this holds and falls
// back to the token wording otherwise, so the page never claims OAuth exists
// while it is switched off.
require_once __DIR__ . '/oauth_server.php';
$msMcpSignIn = oauthDiscoveryEnabled($pdo);

// Every new account starts on Pro for this many days (epic #267). Read from
// $config, never written into copy; at 0 every answer below falls back to its
// pre-trial wording and the trial question is left out.
$msT = max(0, (int) ($config['trial']['days'] ?? 0));

// The retention hold (epic #369): a Pro account may keep the mail on its
// sticky addresses for this many days, this many times a year. Both numbers
// are read from $config, never written into copy.
$msHoldDays = max(1, (int) ($config['retention_hold']['days'] ?? 30));
$msHoldMax  = max(1, (int) ($config['retention_hold']['max_per_year'] ?? 4));

// The copy: one data file per language (documentaion/I18N.md §4), English when a
// language has none. The file reads $msU, $msT, $msDomain, $msMcpUrl,
// $msMcpSignIn, $msHoldDays and $msHoldMax (listed in faq.en.php's docblock) and
// returns the whole page as data: six groups, in page order, each holding its
// questions. Both the visible copy and the FAQPage structured data render from
// that one array, so the answers a crawler reads cannot drift from the answers a
// person reads. 'a' holds the inner HTML of an answer (what goes inside
// .ms-prose), not escaped text: markup we author there, never anything a visitor
// supplies.
$msLocale = i18nLocale();
$msFaqFile = __DIR__ . '/partials/content/faq.' . $msLocale . '.php';
if (!preg_match('/^[a-z]{2}$/', $msLocale) || !is_file($msFaqFile)) {
    $msFaqFile = __DIR__ . '/partials/content/faq.en.php';
}
$msFaq = require $msFaqFile;

/**
 * Flatten one answer's markup to the plain text a search engine displays.
 *
 * Block-level boundaries are turned into spaces first: strip_tags() alone would
 * join "</p><p>" with nothing between it, turning two sentences into one word
 * run ("...create it.The messages..."). Inline tags — <strong>, <a>, <span> —
 * take no separator, so "24 hours" and "shopping@example.com" survive intact.
 */
$msFaqPlain = function (string $html): string {
    $text = preg_replace('#</?(?:p|li|ul|ol|br)\b[^>]*>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
};

// FAQPage structured data, built from the same array the sections render from.
$msFaqEntities = [];
foreach ($msFaq as $msFaqGroup) {
    foreach ($msFaqGroup['items'] as $msFaqItem) {
        $msFaqEntities[] = [
            '@type'          => 'Question',
            'name'           => $msFaqItem['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $msFaqPlain($msFaqItem['a']),
            ],
        ];
    }
}

$msFaqJsonLd = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'inLanguage' => i18nLocale(),
    'mainEntity' => $msFaqEntities,
];
?>
<!DOCTYPE html>
<html lang="<?php echo $msEsc(i18nLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FAFAF9">
    <?php require __DIR__ . '/partials/analytics.php'; ?>
    <title><?php echo $msEsc($msTitle); ?></title>
    <meta name="description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo $msEsc($msUrl); ?>">
<?php require __DIR__ . '/partials/i18n_head.php'; ?>

    <meta property="og:type" content="website">
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

<?php require __DIR__ . '/partials/favicons.php'; ?>

    <!-- FAQPage structured data. Emitted here rather than through
         partials/public_head.php because this page does not use the marketing
         shell — it carries the app's <head>. The array is built above from
         $msFaq, the same source the sections below render from, so the answers
         here are the answers on the page by construction, not by discipline.
         json_encode escapes "/", so a value containing "</script>" cannot end
         this element early. -->
    <script type="application/ld+json"><?php echo json_encode($msFaqJsonLd); ?></script>

    <!-- App-layer dependencies, in the brief §11 order: the bridge loads last and
         wins. None of these are used by this page's own markup yet — they stay
         because faq.php and blog.php share the app's <head> pattern, and
         removing them here would silently fork it. -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/style.css" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
<a class="ms-skip" href="#main"><?php echo te('faq.skip'); ?></a>
<div class="ms-faq">
    <header class="ms-faq__bar">
        <div class="ms-container ms-faq__bar-inner">
            <?php echo ms_logo(['href' => i18nUrl('/'), 'class' => 'ms-faq__logo']); ?>

            <ul class="ms-faq__links">
                <li><a href="<?php echo $msU('/'); ?>"><?php echo te('faq.home'); ?></a></li>
                <li><a href="<?php echo $msU('/temporary-email.php'); ?>"><?php echo te('faq.temporary_email'); ?></a></li>
                <li><a href="<?php echo $msU('/blog.php'); ?>"><?php echo te('faq.blog'); ?></a></li>
            </ul>

            <div class="ms-faq__actions">
                <?php if ($msSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php else : ?>
                    <a class="ms-faq__login" href="/pro_login.php"><?php echo te('faq.login'); ?></a>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="ms-faq__main" id="main">
        <div class="ms-container--narrow">
            <h1 class="ms-faq__title"><?php echo te('faq.title'); ?></h1>
            <p class="ms-lede ms-faq__lede"><?php echo tHtml('faq.lede', [], ['temp' => '<a href="' . $msU('/temporary-email.php') . '">', 'temp_end' => '</a>']); ?></p>

            <nav class="ms-faq__jump" aria-label="<?php echo te('faq.jump_label'); ?>">
                <p class="ms-eyebrow"><?php echo te('faq.jump_to'); ?></p>
                <ul class="ms-faq__jump-list">
                    <?php foreach ($msFaq as $msGroupId => $msGroup) : ?>
                    <li><a href="#<?php echo $msEsc($msGroupId); ?>"><?php echo $msEsc($msGroup['title']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php foreach ($msFaq as $msGroupId => $msGroup) : ?>
            <section class="ms-faq__group" id="<?php echo $msEsc($msGroupId); ?>" aria-labelledby="faq-h-<?php echo $msEsc($msGroupId); ?>">
                <h2 class="ms-h2" id="faq-h-<?php echo $msEsc($msGroupId); ?>"><?php echo $msEsc($msGroup['title']); ?></h2>
                <div class="ms-faq__items">
                    <?php foreach ($msGroup['items'] as $msItem) : ?>
                    <div class="ms-faq__item">
                        <h3 class="ms-h3"><?php echo $msEsc($msItem['q']); ?></h3>
                        <div class="ms-prose"><?php echo $msItem['a']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="ms-faq__foot">
        <div class="ms-container">
            <nav class="ms-faq__foot-links" aria-label="<?php echo te('faq.footer_label'); ?>">
                <a href="/register.php?plan=regular"><?php echo te('faq.foot.create_inbox'); ?></a>
                <a href="/pro_login.php"><?php echo te('faq.foot.login'); ?></a>
                <a href="<?php echo $msU('/temporary-email.php'); ?>"><?php echo te('faq.foot.temporary_email'); ?></a>
                <a href="<?php echo $msU('/blog.php'); ?>"><?php echo te('faq.foot.blog'); ?></a>
                <a href="<?php echo $msU('/faq.php'); ?>"><?php echo te('faq.foot.faq'); ?></a>
                <button type="button" class="ms-footer__cookie" data-ms-cookie-settings><?php echo te('faq.foot.cookie_settings'); ?></button>
            </nav>
            <p class="ms-faq__foot-legal">&copy; <?php echo date('Y'); ?> Manjo Consulting AB <span aria-hidden="true">&middot;</span> Mail Shield v<?php echo $msEsc($config['app']['version'] ?? ''); ?></p>
        </div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
