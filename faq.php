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
$msUrl         = $msOrigin . '/faq.php';
$msOgImage     = $msOrigin . '/assets/images/og-mailshield.png';
$msTitle       = 'Frequently asked questions · Mail Shield';
$msDescription = 'Short answers about Mail Shield: what it is, what a free account includes, how long timed and sticky addresses last, and what you can connect it to.';

// The bar switches on the same session flag the marketing nav uses, so a
// signed-in reader is never sent back through sign-up.
$msSignedIn = !empty($_SESSION['pro_user_id']);
$msCtaHref  = $msSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msCtaLabel = $msSignedIn ? 'Go to your inbox' : 'Create your inbox';

$msEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$msDomain = $msEsc($domain);

// Every new account starts on Pro for this many days (epic #267). Read from
// $config, never written into copy; at 0 every answer below falls back to its
// pre-trial wording and the trial question is left out.
$msT = max(0, (int) ($config['trial']['days'] ?? 0));

/**
 * The whole page as data: six groups, in page order, each holding its questions.
 *
 * Both the visible copy and the FAQPage structured data are rendered from this
 * one array, so the answers a crawler reads cannot drift from the answers a
 * person reads. That is the point of the shape — the alternative is a second
 * copy of every answer inside the JSON-LD block, which is a guaranteed future
 * lie the first time someone edits one and not the other.
 *
 * 'a' holds the inner HTML of the answer (what goes inside .ms-prose), not
 * escaped text: it is markup we author here, never anything a visitor supplies.
 */
$msFaq = [
    'general' => [
        'title' => 'General',
        'items' => [
            [
                'q' => 'What is Mail Shield?',
                'a' => <<<HTML
<p>A separate inbox for the email you don't want in your primary one. Shopping, newsletters, signups, forum replies, automated mail and the services you're only trying out all have somewhere else to go, and you read them in one place on the web.</p>
<p>You get addresses of your own — timed ones that delete themselves, and sticky ones you keep. It is not a page that prints a throwaway address and forgets you the moment you close the tab: an address here belongs to an account, so a confirmation that arrives an hour later is still waiting for you.</p>
HTML,
            ],
            [
                'q' => 'How do I get a timed address?',
                'a' => <<<HTML
<p>Create an account with your own email address, verify it, then create the timed address from your inbox. There is no button on the homepage that issues one anonymously — an address needs an account behind it, which is what lets you come back for a message that arrives later.</p>
HTML,
            ],
            [
                'q' => 'How long are messages and addresses kept?',
                'a' => <<<HTML
<p>A timed address on a free account is deleted automatically <strong>24 hours</strong> after you create it, and the messages in it go with it. On Pro you choose the lifetime for each timed address, from <strong>1 to 7 days</strong>.</p>
<p>Sticky addresses don't run on a timer at all — they stay until you delete them.</p>
HTML,
            ],
            [
                'q' => 'Does it cost anything?',
                'a' => $msT > 0 ? <<<HTML
<p>A free account costs nothing and needs no card, and every new account starts with <strong>{$msT} days of Pro</strong> at no cost. After that, Pro is unlocked with a voucher code — online payment is on the way — and no prices have been published yet.</p>
HTML : <<<HTML
<p>A free account costs nothing and needs no card. Pro is unlocked with a voucher code today — online payment is on the way — and no prices have been published yet.</p>
HTML,
            ],
        ],
    ],
    'accounts' => [
        'title' => 'Accounts and plans',
        'items' => [
            [
                'q' => 'What\'s the difference between Free and Pro?',
                'a' => <<<HTML
<p>A free account covers temporary email: one timed address at a time, deleted 24 hours after you create it.</p>
<p>Pro adds the permanent side of the product and the automation — up to 10 sticky addresses, a timed-address lifetime of 1 to 7 days that you choose, RSS, webhooks, Pushover, digest emails and the Agent.</p>
HTML . ($msT > 0 ? <<<HTML
<p>Every new account gets Pro for its first {$msT} days, so you can try all of it before you decide.</p>
HTML : ''),
            ],
            ...($msT > 0 ? [[
                'q' => "How does the {$msT}-day Pro trial work?",
                'a' => <<<HTML
<p>Every new account gets Pro for its first {$msT} days, starting when you confirm your email address. There's no card to enter and nothing to cancel.</p>
<p>The trial is once per email address. If you delete your account and sign up again with the same address, the clock keeps running from your first sign-up — it doesn't start over.</p>
<p>When the trial ends, your account carries on as a free one. Automation is switched off, and sticky addresses — with the mail in them — are deleted 7 days later, so move anything you still need first. To stay on Pro, redeem a voucher code in your profile; online payment is on the way.</p>
HTML,
            ]] : []),
            [
                'q' => 'How many addresses can I create?',
                'a' => <<<HTML
<p>Addresses belong to your account, not to a browser session. Every account holds one timed address at a time, and creating a new one replaces the old one.</p>
<p>Pro accounts also get up to 10 sticky addresses, which live alongside the timed one and deliver into the same inbox.</p>
HTML,
            ],
            [
                'q' => 'How do I create a Pro account?',
                'a' => $msT > 0 ? <<<HTML
<p>Create an account — it starts on Pro for its first {$msT} days. To keep Pro after that, redeem a voucher code in your profile; redeemed during the trial, the code's time is added after the trial ends. There is no online checkout yet; payment is on the way. Accounts sign in with a magic link sent to their email, and can optionally set a password in their profile.</p>
HTML : <<<HTML
<p>Register with a voucher code — pick Pro on the sign-up page and enter the code. There is no online checkout yet; payment is on the way. Pro accounts sign in with a magic link sent to their email, and can optionally set a password in their profile.</p>
HTML,
            ],
            [
                'q' => 'What settings are available on Pro?',
                'a' => <<<HTML
<ul>
<li>Set a password for sign-in, alongside the email link.</li>
<li>Choose how long new timed addresses last, from 1 to 7 days.</li>
<li>Create, review and delete up to 10 sticky addresses.</li>
<li>Turn two-factor authentication on or off, and manage trusted browsers.</li>
<li>Configure automation: RSS, webhooks and digest emails.</li>
</ul>
HTML,
            ],
        ],
    ],
    'temporary' => [
        'title' => 'Timed and sticky addresses',
        'items' => [
            [
                'q' => 'What\'s the difference between a timed and a sticky address?',
                'a' => <<<HTML
<p>A timed address is Mail Shield's take on temporary email: it is meant to be thrown away. It expires on a timer you don't have to think about — 24 hours on a free account, 1 to 7 days on Pro — and takes its messages with it.</p>
<p>A sticky address is one you keep: a readable name like <span class="ms-addr">shopping@{$msDomain}</span> instead of a random string, no expiry, and you decide when it goes. Both kinds arrive in the same inbox.</p>
HTML,
            ],
            [
                'q' => 'Can I use Mail Shield for permanent addresses?',
                'a' => <<<HTML
<p>Yes — that's what sticky addresses are for. A Pro account can hold up to 10 of them, all permanent in the sense that matters: they don't expire, and you delete them when you're done with them.</p>
<p>Every address uses the {$msDomain} domain, and every one of them delivers into the same inbox.</p>
HTML,
            ],
            [
                'q' => 'How long do sticky addresses last?',
                'a' => <<<HTML
<p>Until you delete them. They carry no expiry timer and aren't caught by the clean-up that removes timed addresses. Deleting one from your profile removes the address, and it stops receiving mail from then on.</p>
HTML,
            ],
        ],
    ],
    'automation' => [
        'title' => 'Automation',
        'items' => [
            [
                'q' => 'What can I connect it to?',
                'a' => <<<HTML
<p>Pro accounts can route mail onward in several ways:</p>
<ul>
<li>A private RSS feed, read in any feed reader, protected by a token — for your whole account, or for one sticky address on its own.</li>
<li>Webhooks that POST each incoming message as JSON to an endpoint you control.</li>
<li>Pushover, for incoming mail as a notification on your phone.</li>
<li>Digest emails, a periodic summary of what arrived.</li>
</ul>
<p>If you run your own mail server, the Agent is there too: you manage sender lists and filter rules in Mail Shield, and the Agent applies them to a mailbox on your server. That mail stays on your server — it doesn't come into Mail Shield.</p>
<p>A public API is coming; it isn't available yet.</p>
HTML,
            ],
            [
                'q' => 'Is automation included in a free account?',
                'a' => $msT > 0 ? <<<HTML
<p>For the first {$msT} days, yes — every new account starts on Pro, automation included. After the trial, RSS, webhooks, Pushover, digests and the Agent are part of Pro, and a free account covers temporary email.</p>
HTML : <<<HTML
<p>No. RSS, webhooks, Pushover, digests and the Agent are all part of Pro. A free account covers temporary email.</p>
HTML,
            ],
        ],
    ],
    'security' => [
        'title' => 'Security and privacy',
        'items' => [
            [
                'q' => 'Is my email content private?',
                'a' => <<<HTML
<p>The answer differs by kind of address, so it's worth being exact. A timed address is reachable through its link: whoever holds that link can read the inbox, and so can anyone who finds it. Treat a timed address as somewhere to receive, not somewhere to keep a secret.</p>
<p>Sticky addresses are different. They belong to your account and can only be read while you're signed in as the owner.</p>
<p>Either way, don't send sensitive personal data — banking, identity documents, medical details — to an address you intend to throw away.</p>
HTML,
            ],
            [
                'q' => 'How are attachments handled?',
                'a' => <<<HTML
<p>Attachments are stored temporarily, and download links are signed and time-limited so a link can't be guessed or passed around indefinitely. Files are cleaned up together with the messages they arrived on.</p>
HTML,
            ],
            [
                'q' => 'What does two-factor authentication (2FA) protect?',
                'a' => <<<HTML
<p>2FA protects password sign-in to your account: after 2FA is enabled, signing in with your password also requires a 6-digit code from an authenticator app. It does not add a step to email sign-in links — a magic link already proves you have access to the inbox, which is itself a second factor.</p>
HTML,
            ],
            [
                'q' => 'Which authenticator apps are supported?',
                'a' => <<<HTML
<p>Any standard TOTP authenticator app works, including 1Password, Authy, and Google Authenticator. Set it up from the two-factor authentication section of your profile by scanning the QR code or entering the key manually.</p>
HTML,
            ],
            [
                'q' => 'What if I lose my phone or authenticator app?',
                'a' => <<<HTML
<p>You're not locked out. Sign in using your email login link (magic link) as usual — it works even with 2FA enabled — and then turn off two-factor authentication from your profile. From there you can set up 2FA again on a new device, or leave it off. You can also use one of the one-time recovery codes shown when you enrolled, if you saved them.</p>
HTML,
            ],
            [
                'q' => 'Is the Agent safe?',
                'a' => <<<HTML
<p>The Agent runs on your own server, so your data stays under your control. It verifies RSA-signed webhooks from Mail Shield before acting on them, and stores the secrets it needs encrypted rather than in plain text. Filtering happens on your machine, so your mail doesn't have to leave it to be sorted.</p>
HTML,
            ],
        ],
    ],
    'troubleshooting' => [
        'title' => 'Troubleshooting',
        'items' => [
            [
                'q' => 'I didn\'t receive an expected message — what now?',
                'a' => <<<HTML
<p>Check the full address you gave out, local part and domain both. For a sticky address, make sure you're signed in to the account that owns it. Then give it a moment and refresh — mail is fetched from the server on a schedule, so a message can take a short while to appear. If it still doesn't arrive, the sender may have delayed or rejected it.</p>
HTML,
            ],
            [
                'q' => 'How do I get support?',
                'a' => <<<HTML
<p>Account-specific problems are best handled from inside your account: <a href="/pro_login.php">sign in</a> and use the contact page, and include the address or the message you're asking about. This FAQ is meant to answer the general cases first.</p>
HTML,
            ],
        ],
    ],
];

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
    'mainEntity' => $msFaqEntities,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FAFAF9">
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
    <link rel="manifest" href="/site.webmanifest">

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
