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
 *      and Pro is unlocked by voucher code only (PRO_SELF_SIGNUP_ENABLED is
 *      off).
 *
 * The layout is grouped sections of always-visible copy, not collapsible
 * disclosure widgets: a page that hides every answer by default is worse for the
 * reader and for search, and Redesign 21 builds structured data from this
 * heading structure. So the markup stays plain and semantic — one top-level
 * heading, one heading per group, one per question, answers in the prose
 * measure.
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
$msUrl         = $msOrigin . '/faq.php';
$msOgImage     = $msOrigin . '/assets/images/og-mailshield.png';
$msTitle       = 'Frequently asked questions · Mail Shield';
$msDescription = 'Short answers about Mail Shield: what it is, what a free account includes, how long temporary and personal addresses last, and what you can connect it to.';

// The bar switches on the same session flag the marketing nav uses, so a
// signed-in reader is never sent back through sign-up.
$msSignedIn = !empty($_SESSION['pro_user_id']);
$msCtaHref  = $msSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msCtaLabel = $msSignedIn ? 'Go to your inbox' : 'Create your inbox';

$msEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$msDomain = $msEsc($domain);

// The six groups, in page order. Kept as data so the jump list and the sections
// cannot drift apart.
$msGroups = [
    'general'       => 'General',
    'accounts'      => 'Accounts and plans',
    'temporary'     => 'Temporary addresses',
    'automation'    => 'Automation',
    'security'      => 'Security and privacy',
    'troubleshooting' => 'Troubleshooting',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $msEsc($msTitle); ?></title>
    <meta name="description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo $msEsc($msUrl); ?>">

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

    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">

    <!-- App-layer dependencies, in the brief §11 order: the bridge loads last and
         wins. None of these are used by this page's own markup yet — they stay
         because faq.php and blog.php share the app's <head> pattern, and
         removing them here would silently fork it. -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
<a class="ms-skip" href="#main">Skip to content</a>
<div class="ms-faq">
    <header class="ms-faq__bar">
        <div class="ms-container ms-faq__bar-inner">
            <?php echo ms_logo(['href' => '/', 'class' => 'ms-faq__logo']); ?>

            <ul class="ms-faq__links">
                <li><a href="/">Home</a></li>
                <li><a href="/temporary-email.php">Temporary email</a></li>
                <li><a href="/blog.php">Blog</a></li>
            </ul>

            <div class="ms-faq__actions">
                <?php if ($msSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php else : ?>
                    <a class="ms-faq__login" href="/pro_login.php">Log in</a>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="ms-faq__main" id="main">
        <div class="ms-container--narrow">
            <h1 class="ms-faq__title">Frequently asked questions</h1>
            <p class="ms-lede ms-faq__lede">What Mail Shield is, what a free account includes, how long addresses last, and what you can connect it to. If you came here looking for a disposable address specifically, the <a href="/temporary-email.php">temporary email page</a> goes deeper.</p>

            <nav class="ms-faq__jump" aria-label="Jump to a section">
                <p class="ms-eyebrow">Jump to</p>
                <ul class="ms-faq__jump-list">
                    <?php foreach ($msGroups as $msGroupId => $msGroupTitle) : ?>
                    <li><a href="#<?php echo $msEsc($msGroupId); ?>"><?php echo $msEsc($msGroupTitle); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <section class="ms-faq__group" id="general" aria-labelledby="faq-h-general">
                <h2 class="ms-h2" id="faq-h-general">General</h2>
                <div class="ms-faq__items">
                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What is Mail Shield?</h3>
                        <div class="ms-prose">
                            <p>A separate inbox for the email you don't want in your primary one. Shopping, newsletters, signups, forum replies, automated mail and the services you're only trying out all have somewhere else to go, and you read them in one place on the web.</p>
                            <p>You get addresses of your own — temporary ones that delete themselves, and permanent ones you keep. It is not a page that prints a throwaway address and forgets you the moment you close the tab: an address here belongs to an account, so a confirmation that arrives an hour later is still waiting for you.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How do I get a temporary address?</h3>
                        <div class="ms-prose">
                            <p>Create an account with your own email address, verify it, then create the temporary address from your inbox. There is no button on the homepage that issues one anonymously — an address needs an account behind it, which is what lets you come back for a message that arrives later.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How long are messages and addresses kept?</h3>
                        <div class="ms-prose">
                            <p>A temporary address on a free account is deleted automatically <strong>24 hours</strong> after you create it, and the messages in it go with it. On Pro you choose the lifetime for each temporary address, from <strong>1 to 7 days</strong>.</p>
                            <p>Personal addresses don't run on a timer at all — they stay until you delete them.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">Does it cost anything?</h3>
                        <div class="ms-prose">
                            <p>A free account costs nothing and needs no card. Pro is unlocked with a voucher code today — online payment is on the way — and no prices have been published yet.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ms-faq__group" id="accounts" aria-labelledby="faq-h-accounts">
                <h2 class="ms-h2" id="faq-h-accounts">Accounts and plans</h2>
                <div class="ms-faq__items">
                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What's the difference between Free and Pro?</h3>
                        <div class="ms-prose">
                            <p>A free account covers temporary email: one temporary address at a time, deleted 24 hours after you create it.</p>
                            <p>Pro adds the permanent side of the product and the automation — up to 10 personal addresses, a temporary-address lifetime of 1 to 7 days that you choose, RSS, webhooks, Pushover, digest emails, external mailboxes and the Agent.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How many addresses can I create?</h3>
                        <div class="ms-prose">
                            <p>Addresses belong to your account, not to a browser session. Every account holds one temporary address at a time, and creating a new one replaces the old one.</p>
                            <p>Pro accounts also get up to 10 personal addresses, which live alongside the temporary one and deliver into the same inbox.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How do I create a Pro account?</h3>
                        <div class="ms-prose">
                            <p>Register with a voucher code — pick Pro on the sign-up page and enter the code. There is no online checkout yet; payment is on the way. Pro accounts sign in with a magic link sent to their email, and can optionally set a password in their profile.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What settings are available on Pro?</h3>
                        <div class="ms-prose">
                            <ul>
                                <li>Set a password for sign-in, alongside the email link.</li>
                                <li>Choose how long new temporary addresses last, from 1 to 7 days.</li>
                                <li>Create, review and delete up to 10 personal addresses.</li>
                                <li>Turn two-factor authentication on or off, and manage trusted browsers.</li>
                                <li>Configure automation: RSS, webhooks and digest emails.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ms-faq__group" id="temporary" aria-labelledby="faq-h-temporary">
                <h2 class="ms-h2" id="faq-h-temporary">Temporary addresses</h2>
                <div class="ms-faq__items">
                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What's the difference between a temporary and a personal address?</h3>
                        <div class="ms-prose">
                            <p>A temporary address is meant to be thrown away. It expires on a timer you don't have to think about — 24 hours on a free account, 1 to 7 days on Pro — and takes its messages with it.</p>
                            <p>A personal address is one you keep: a readable name like <span class="ms-addr">shopping@<?php echo $msDomain; ?></span> instead of a random string, no expiry, and you decide when it goes. Both kinds arrive in the same inbox.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">Can I use Mail Shield for permanent addresses?</h3>
                        <div class="ms-prose">
                            <p>Yes — that's what personal addresses are for. A Pro account can hold up to 10 of them, all permanent in the sense that matters: they don't expire, and you delete them when you're done with them.</p>
                            <p>Every address uses the <?php echo $msDomain; ?> domain, and every one of them delivers into the same inbox.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How long do personal addresses last?</h3>
                        <div class="ms-prose">
                            <p>Until you delete them. They carry no expiry timer and aren't caught by the clean-up that removes temporary addresses. Deleting one from your profile removes the address, and it stops receiving mail from then on.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ms-faq__group" id="automation" aria-labelledby="faq-h-automation">
                <h2 class="ms-h2" id="faq-h-automation">Automation</h2>
                <div class="ms-faq__items">
                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What can I connect it to?</h3>
                        <div class="ms-prose">
                            <p>Pro accounts can route mail onward in several ways:</p>
                            <ul>
                                <li>A private RSS feed, read in any feed reader, protected by a token.</li>
                                <li>Webhooks that POST each incoming message as JSON to an endpoint you control.</li>
                                <li>Pushover, for incoming mail as a notification on your phone.</li>
                                <li>Digest emails, a periodic summary of what arrived.</li>
                                <li>External mailboxes you already run.</li>
                                <li>The Agent, which runs on your own mail server and applies your filter scripts locally.</li>
                            </ul>
                            <p>A public API is coming; it isn't available yet.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">Is automation included in a free account?</h3>
                        <div class="ms-prose">
                            <p>No. RSS, webhooks, Pushover, digests, external mailboxes and the Agent are all part of Pro. A free account covers temporary email.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ms-faq__group" id="security" aria-labelledby="faq-h-security">
                <h2 class="ms-h2" id="faq-h-security">Security and privacy</h2>
                <div class="ms-faq__items">
                    <div class="ms-faq__item">
                        <h3 class="ms-h3">Is my email content private?</h3>
                        <div class="ms-prose">
                            <p>The answer differs by kind of address, so it's worth being exact. A temporary address is reachable through its link: whoever holds that link can read the inbox, and so can anyone who finds it. Treat a temporary address as somewhere to receive, not somewhere to keep a secret.</p>
                            <p>Personal addresses are different. They belong to your account and can only be read while you're signed in as the owner.</p>
                            <p>Either way, don't send sensitive personal data — banking, identity documents, medical details — to an address you intend to throw away.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How are attachments handled?</h3>
                        <div class="ms-prose">
                            <p>Attachments are stored temporarily, and download links are signed and time-limited so a link can't be guessed or passed around indefinitely. Files are cleaned up together with the messages they arrived on.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What does two-factor authentication (2FA) protect?</h3>
                        <div class="ms-prose">
                            <p>2FA protects password sign-in to your account: after 2FA is enabled, signing in with your password also requires a 6-digit code from an authenticator app. It does not add a step to email sign-in links — a magic link already proves you have access to the inbox, which is itself a second factor.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">Which authenticator apps are supported?</h3>
                        <div class="ms-prose">
                            <p>Any standard TOTP authenticator app works, including 1Password, Authy, and Google Authenticator. Set it up from the two-factor authentication section of your profile by scanning the QR code or entering the key manually.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">What if I lose my phone or authenticator app?</h3>
                        <div class="ms-prose">
                            <p>You're not locked out. Sign in using your email login link (magic link) as usual — it works even with 2FA enabled — and then turn off two-factor authentication from your profile. From there you can set up 2FA again on a new device, or leave it off. You can also use one of the one-time recovery codes shown when you enrolled, if you saved them.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">Is the Agent safe?</h3>
                        <div class="ms-prose">
                            <p>The Agent runs on your own server, so your data stays under your control. It verifies RSA-signed webhooks from Mail Shield before acting on them, and stores the secrets it needs encrypted rather than in plain text. Filtering happens on your machine, so your mail doesn't have to leave it to be sorted.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ms-faq__group" id="troubleshooting" aria-labelledby="faq-h-troubleshooting">
                <h2 class="ms-h2" id="faq-h-troubleshooting">Troubleshooting</h2>
                <div class="ms-faq__items">
                    <div class="ms-faq__item">
                        <h3 class="ms-h3">I didn't receive an expected message — what now?</h3>
                        <div class="ms-prose">
                            <p>Check the full address you gave out, local part and domain both. For a personal address, make sure you're signed in to the account that owns it. Then give it a moment and refresh — mail is fetched from the server on a schedule, so a message can take a short while to appear. If it still doesn't arrive, the sender may have delayed or rejected it.</p>
                        </div>
                    </div>

                    <div class="ms-faq__item">
                        <h3 class="ms-h3">How do I get support?</h3>
                        <div class="ms-prose">
                            <p>Account-specific problems are best handled from inside your account: <a href="/pro_login.php">sign in</a> and use the contact page, and include the address or the message you're asking about. This FAQ is meant to answer the general cases first.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer class="ms-faq__foot">
        <div class="ms-container">
            <nav class="ms-faq__foot-links" aria-label="Footer">
                <a href="/register.php?plan=regular">Create your inbox</a>
                <a href="/pro_login.php">Log in</a>
                <a href="/temporary-email.php">Temporary email addresses</a>
                <a href="/blog.php">Blog</a>
                <a href="/faq.php">FAQ</a>
            </nav>
            <p class="ms-faq__foot-legal">&copy; <?php echo date('Y'); ?> Manjo Consulting AB <span aria-hidden="true">&middot;</span> Mail Shield v<?php echo $msEsc($config['app']['version'] ?? ''); ?></p>
        </div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
