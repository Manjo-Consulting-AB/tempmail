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

$msPage = [
    'title'        => 'Temporary email addresses — free disposable email · Mail Shield',
    'description'  => 'A temporary email address for signups, downloads and services you only need for a while. Read the mail in your Mail Shield inbox, then let it be cleaned up automatically.',
    'path'         => '/temporary-email.php',
    'preload_font' => true,
];

// Article structured data for the keyword page (§14). The dates come from this
// file's mtime, which is when the page last changed — an honest lastmod proxy
// rather than an invented publication date.
$msOrigin  = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msModTime = @filemtime(__FILE__) ?: time();

$msPage['jsonld'] = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Article',
    'headline'      => 'Temporary email addresses',
    'description'   => $msPage['description'],
    'datePublished' => gmdate('c', $msModTime),
    'dateModified'  => gmdate('c', $msModTime),
    'mainEntityOfPage' => $msOrigin . '/temporary-email.php',
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

// Both CTAs switch on the same session flag the nav uses, so a signed-in reader
// is never sent back through sign-up.
$msDocSignedIn = !empty($_SESSION['pro_user_id']);
$msDocCtaHref  = $msDocSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msDocCtaLabel = $msDocSignedIn ? 'Go to your inbox' : 'Create your inbox';
// Every new account starts on Pro for this many days (epic #267); signed-out only.
$msDocTrialDays = $msDocSignedIn ? 0 : max(0, (int) ($config['trial']['days'] ?? 0));

$msDocDomain = htmlspecialchars((string) $domain, ENT_QUOTES, 'UTF-8');
?>
<main id="main">
    <article class="ms-doc">
        <div class="ms-container--narrow">
            <h1 class="ms-doc__title">Temporary email addresses</h1>
            <p class="ms-lede ms-doc__lede">Some signups aren't worth your real address. A temporary email address gives you a disposable one instead — read it in a proper web inbox, then let Mail Shield clean it up for you.</p>

            <nav class="ms-doc__toc" aria-label="On this page">
                <p class="ms-eyebrow">On this page</p>
                <ul class="ms-doc__toc-list">
                    <li><a href="#what">What is a temporary email address?</a></li>
                    <li><a href="#get">How to get a temporary email address</a></li>
                    <li><a href="#lifetime">How long does a temporary address last?</a></li>
                    <li><a href="#when-to-use">When to use a temporary email address</a></li>
                    <li><a href="#when-not-to-use">When not to use one</a></li>
                    <li><a href="#permanent">Temporary email, or a sticky address?</a></li>
                </ul>
            </nav>

            <section class="ms-doc__section" id="what">
                <h2 class="ms-h2">What is a temporary email address?</h2>
                <div class="ms-prose">
                    <p>A temporary email address exists for a short time and then stops existing. You use it wherever you'd rather not hand over the one you actually read — a free trial, a download, a forum — and anything sent to it lands in a web inbox you can open straight away.</p>
                    <p>The other name for it is disposable email. The address is meant to be thrown away, so it never has to be memorable or tidy. That's the point: your primary inbox keeps the mail that matters, and the rest goes somewhere you don't have to look after.</p>
                    <p>In Mail Shield this is called a <strong>timed address</strong>: an address that runs on a clock and deletes itself, with everything in it, when the clock runs out.</p>
                </div>
            </section>

            <section class="ms-doc__section" id="get">
                <h2 class="ms-h2">How to get a temporary email address</h2>
                <div class="ms-prose">
                    <p>There is no button on this page that prints an address for you, and that's deliberate — a Mail Shield inbox needs an account behind it.</p>
                    <ol>
                        <li>Register with your own email address and a password. It's used to verify the account and to reach you about it, nothing else.</li>
                        <li>Verify it, then sign in.</li>
                        <li>Create a timed address from your inbox. It's ready to paste into whatever you're signing up for.</li>
                    </ol>
                    <p>The extra minute buys something a throwaway generator can't offer. The address lives inside a real inbox that persists, so you can come back for a confirmation that arrives an hour later, or find the message that explains how to cancel. A page that prints an address and forgets you the moment you close the tab can do neither.</p>
                </div>
            </section>

            <section class="ms-doc__section" id="lifetime">
                <h2 class="ms-h2">How long does a temporary address last?</h2>
                <div class="ms-prose">
                    <p>It depends on your account, and both numbers are exact.</p>

                    <dl class="ms-doc__retention">
                        <div class="ms-doc__tier">
                            <dt class="ms-eyebrow">Free</dt>
                            <dd class="ms-doc__tier-value">24 hours</dd>
                        </div>
                        <div class="ms-doc__tier">
                            <dt class="ms-eyebrow">Pro</dt>
                            <dd class="ms-doc__tier-value">1&ndash;7 days</dd>
                        </div>
                    </dl>

                    <p>A free account keeps a timed address for 24 hours. On Pro you choose a lifetime of 1 to 7 days for each address. When the clock runs out, the address and everything in it is deleted automatically.</p>
                </div>
            </section>

            <div class="ms-doc__cta">
                <p class="ms-doc__cta-line"><?php echo $msDocTrialDays > 0 ? 'It takes about a minute, and your first ' . $msDocTrialDays . ' days include Pro, free.' : 'It takes about a minute, and it\'s free.'; ?></p>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="<?php echo htmlspecialchars($msDocCtaHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($msDocCtaLabel, ENT_QUOTES, 'UTF-8'); ?></a>
            </div>

            <section class="ms-doc__section" id="when-to-use">
                <h2 class="ms-h2">When to use a temporary email address</h2>
                <div class="ms-prose">
                    <p>Temporary email is at its best when the address is the only part you're unsure about.</p>
                    <ul>
                        <li>Free trials for tools you may never open again.</li>
                        <li>A one-off download that asks for an address before it starts.</li>
                        <li>Forum and community registrations.</li>
                        <li>Marketplace listings and classifieds, where you'd rather not publish the address you actually use.</li>
                        <li>Testing your own signup and confirmation emails.</li>
                    </ul>
                </div>
            </section>

            <section class="ms-doc__section" id="when-not-to-use">
                <h2 class="ms-h2">When not to use one</h2>
                <div class="ms-prose">
                    <p>This is the wrong tool for anything that matters.</p>
                    <ul>
                        <li>Banking, government services, or anything tied to your identity.</li>
                        <li>Accounts you'd need to recover — once the address is gone, so is the password reset.</li>
                        <li>Anything you intend to keep.</li>
                    </ul>
                    <p>One thing worth saying plainly: a temporary inbox you reach through a shared link isn't private. Pass the link on and whoever receives it can read the mail. Mail Shield would rather tell you that than let you assume otherwise, so treat a timed address as somewhere to receive, not somewhere to keep a secret.</p>
                </div>
            </section>

            <section class="ms-doc__section" id="permanent">
                <h2 class="ms-h2">Temporary email, or a sticky address?</h2>
                <div class="ms-prose">
                    <p>Timed addresses are one half of the story. Mail Shield also gives Pro accounts up to 10 sticky addresses — <span class="ms-addr">shopping@<?php echo $msDocDomain; ?></span>, <span class="ms-addr">news@<?php echo $msDocDomain; ?></span>, <span class="ms-addr">projects@<?php echo $msDocDomain; ?></span> — for the senders you'll hear from again. The addresses don't expire. You choose when to remove them, while the mail they receive is still cleaned up according to your retention settings.</p>
                    <p>If you want a disposable email you never think about again, a timed address is the right choice. If you've been handing out your real address because there was nowhere else to put a newsletter, a sticky address is the better fix. <a href="/">The landing page has the full picture</a>.</p>
                </div>
            </section>

            <div class="ms-doc__cta ms-doc__cta--end">
                <p class="ms-doc__cta-line">Your first timed address is a minute away.</p>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="<?php echo htmlspecialchars($msDocCtaHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($msDocCtaLabel, ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        </div>
    </article>
</main>
<?php require 'partials/public_footer.php'; ?>
