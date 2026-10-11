<?php
/**
 * Mail Shield — temporary email addresses. Spec: documentaion/REDESIGN_BRIEF.md
 * §14 (this is the dedicated keyword page: the redesign moves the brand to Mail
 * Shield, so the tempmail terms have to live somewhere other than the title of
 * index.php), §9 (the honest feature inventory, which every claim below is
 * checked against), §11 (marketing pages load the mailshield stylesheets alone —
 * no CSS framework, no icon font, no JS library) and §7 (copy voice).
 *
 * What this page is: a long-form reading page, not a second landing page. It
 * answers six questions in order — what a temporary address is, how to get one,
 * how long it lasts, when to use it, when not to, and how it differs from a
 * stable personal address — and hands the reader to sign-up twice.
 *
 * Three claims on this page are load-bearing and deliberately unflattering,
 * because §7 forbids claiming a feature the code does not have:
 *
 *   - An account is required (register.php: email + password + verification).
 *     §9 says never imply one-click generation without one, so section 2 spells
 *     out the three real steps instead of hinting at a button that isn't there.
 *   - The retention split is exactly 24 hours free / 1–7 days Pro. It is stated
 *     twice (§9's approved wording in both places) and never rounded to "about a
 *     week" — that would overclaim for free accounts and underclaim for Pro.
 *   - A shared inbox link is not private. That is what inbox.php actually does,
 *     so section 5 says so. Stating it is a better trust signal than leaving the
 *     reader to discover it.
 *
 * §14's tempmail clichés, the anonymity claim and the no-registration claim are
 * absent from the copy and from this file: the acceptance criteria grep for all
 * three, and a page that ranked on an overclaim would be worth less than none.
 *
 * $msNavAnchors = false because features/#how-it-works are landing-page sections,
 * not sections of this page; public_nav.php then points those two links back at
 * /#features and /#how-it-works so they resolve from here.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// §2.1: the domain is rendered, never written into copy. The fallback is the
// empty string rather than a hardcoded domain — config.php always populates
// this key, and the landing partials use the same fallback.
$domain = $config['email']['domain'] ?? '';

$msOrigin  = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msModTime = @filemtime(__FILE__) ?: time();

// A link to a public page, in this request's language and escaped for HTML. The
// copy in partials/content/temporary-email.<locale>.php uses it for every public
// page; links to app pages stay literal.
$msU = function (string $path): string {
    return htmlspecialchars(i18nUrl($path), ENT_QUOTES, 'UTF-8');
};

// Both CTAs switch on the same session flag the nav uses, so a signed-in reader
// is never sent back through sign-up.
$msDocSignedIn = !empty($_SESSION['pro_user_id']);
$msDocCtaHref  = $msDocSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msDocCtaLabel = $msDocSignedIn ? t('nav.go_to_inbox') : t('nav.create_inbox');
// Every new account starts on Pro for this many days (epic #267); signed-out only.
$msDocTrialDays = $msDocSignedIn ? 0 : max(0, (int) ($config['trial']['days'] ?? 0));

$msDocDomain = htmlspecialchars((string) $domain, ENT_QUOTES, 'UTF-8');

// The retention numbers as the approved sentence states them (brief §9): a free
// timed address lasts 24 hours, a Pro one is set to 1–7 days (index.php `case
// 'generate'`), and Pro holds up to 10 sticky addresses. They reach the copy as
// variables so no language file carries a number of its own.
$msDocFreeHours  = 24;
$msDocProMinDays = 1;
$msDocProMaxDays = 7;
$msDocStickyMax  = 10;

// The copy: one data file per language (documentaion/I18N.md §4), English when a
// language has none. The file reads $msU, $msDocDomain, $msDocTrialDays,
// $msDocFreeHours, $msDocProMinDays, $msDocProMaxDays and $msDocStickyMax (listed
// in temporary-email.en.php's docblock) and returns the whole article as data.
$msLocale = i18nLocale();
$msDocFile = __DIR__ . '/partials/content/temporary-email.' . $msLocale . '.php';
if (!preg_match('/^[a-z]{2}$/', $msLocale) || !is_file($msDocFile)) {
    $msDocFile = __DIR__ . '/partials/content/temporary-email.en.php';
}
$msDoc = require $msDocFile;

$msPage = [
    'title'        => $msDoc['title'],
    'description'  => $msDoc['description'],
    'path'         => '/temporary-email.php',
    'preload_font' => true,
];

// Article structured data for the keyword page (§14). The dates come from this
// file's mtime, which is when the page last changed — an honest lastmod proxy
// rather than an invented publication date.
$msPage['jsonld'] = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Article',
    'inLanguage'    => $msLocale,
    'headline'      => $msDoc['headline'],
    'description'   => $msPage['description'],
    'datePublished' => gmdate('c', $msModTime),
    'dateModified'  => gmdate('c', $msModTime),
    'mainEntityOfPage' => $msOrigin . i18nUrl('/temporary-email.php'),
    // image + author are what make an Article eligible for a rich result rather
    // than merely valid; both are real (the OG image, and the organisation that
    // wrote the page), so neither is a fabricated claim.
    'image'         => $msOrigin . '/assets/images/og-mailshield.png',
    'author'        => [
        '@type' => 'Organization',
        'name'  => 'Mail Shield',
    ],
    'publisher'     => [
        '@type' => 'Organization',
        'name'  => 'Mail Shield',
        'logo'  => [
            '@type' => 'ImageObject',
            'url'   => $msOrigin . '/assets/images/og-mailshield.png',
        ],
    ],
];

$msNavAnchors = false;   // anchors point back at /#... from this page

require 'partials/brand.php';
require 'partials/public_head.php';
require 'partials/public_nav.php';

$msDocEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
// Indent a block of authored markup to where the section body sits.
$msDocIndent = function (string $html): string {
    return preg_replace('/^(?=.)/m', str_repeat(' ', 20), $html);
};

// $line is authored markup from the data file, like the sections.
$msDocCta = function (string $line) use ($msDocEsc, $msDocCtaHref, $msDocCtaLabel): void { ?>
                <p class="ms-doc__cta-line"><?php echo $line; ?></p>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="<?php echo $msDocEsc($msDocCtaHref); ?>"><?php echo $msDocEsc($msDocCtaLabel); ?></a>
<?php };
?>
<main id="main">
    <article class="ms-doc">
        <div class="ms-container--narrow">
            <h1 class="ms-doc__title"><?php echo $msDocEsc($msDoc['headline']); ?></h1>
            <p class="ms-lede ms-doc__lede"><?php echo $msDoc['lede']; ?></p>

            <nav class="ms-doc__toc" aria-label="<?php echo $msDocEsc($msDoc['toc_label']); ?>">
                <p class="ms-eyebrow"><?php echo $msDocEsc($msDoc['toc_eyebrow']); ?></p>
                <ul class="ms-doc__toc-list">
<?php foreach ($msDoc['sections'] as $msDocSection) : ?>
                    <li><a href="#<?php echo $msDocEsc($msDocSection['id']); ?>"><?php echo $msDocEsc($msDocSection['heading']); ?></a></li>
<?php endforeach; ?>
                </ul>
            </nav>
<?php foreach ($msDoc['sections'] as $msDocSection) : ?>

            <section class="ms-doc__section" id="<?php echo $msDocEsc($msDocSection['id']); ?>">
                <h2 class="ms-h2"><?php echo $msDocEsc($msDocSection['heading']); ?></h2>
                <div class="ms-prose">
<?php echo $msDocIndent($msDocSection['html']); ?>

                </div>
            </section>
<?php if ($msDocSection['id'] === $msDoc['cta']['after']) : ?>

            <div class="ms-doc__cta">
<?php $msDocCta($msDoc['cta']['line']); ?>
            </div>
<?php endif; ?>
<?php endforeach; ?>

            <div class="ms-doc__cta ms-doc__cta--end">
<?php $msDocCta($msDoc['cta']['end_line']); ?>
            </div>
        </div>
    </article>
</main>
<?php require 'partials/public_footer.php'; ?>
