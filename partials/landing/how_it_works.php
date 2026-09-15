<?php
/**
 * Landing — how it works. Spec: documentaion/REDESIGN_BRIEF.md §8.
 *
 * This is where the hero's secondary CTA and the nav's "How it works" link both
 * land, so it has to pay off the scroll: three steps, one card each, nothing
 * else. The section is sunken so the page gets a single band change here rather
 * than alternating on every section (§5.3).
 *
 * The <ol> carries the sequence semantically; the visible number is a
 * typographic detail, not copy, so it is aria-hidden to keep a screen reader
 * from announcing the step twice.
 *
 * Step 2 renders the configured domain, never a hardcoded one (§2.1).
 *
 * The closing link points at temporary-email.php, which Redesign 17 creates —
 * it 404s until then, exactly as the footer's link to the same page already
 * does. Removing the link is not an option (§12.4).
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

// index.php sets $domain; the config fallback keeps the partial self-contained.
$msHowDomain = htmlspecialchars(
    (string) ($domain ?? ($config['email']['domain'] ?? '')),
    ENT_QUOTES,
    'UTF-8'
);
?>
<section class="ms-section ms-section--sunken" id="how-it-works">
    <div class="ms-container">
        <h2 class="ms-h2">How it works</h2>

        <ol class="ms-grid ms-grid--3 ms-steps">
            <li class="ms-card ms-step">
                <span class="ms-step__num" aria-hidden="true">1</span>
                <h3 class="ms-h3">Create your inbox</h3>
                <p class="ms-step__body">Sign up with your real email address. It stays private.</p>
            </li>
            <li class="ms-card ms-step">
                <span class="ms-step__num" aria-hidden="true">2</span>
                <h3 class="ms-h3">Choose an address</h3>
                <p class="ms-step__body">Use a stable address like <span class="ms-addr">shopping@<?php echo $msHowDomain; ?></span> when you want to hear from a service again, or create a temporary one for mail you only need for a while.</p>
            </li>
            <li class="ms-card ms-step">
                <span class="ms-step__num" aria-hidden="true">3</span>
                <h3 class="ms-h3">Everything lands in one place</h3>
                <p class="ms-step__body">Read it on the web. Mail is kept only as long as your retention settings allow, or route it onward with RSS, webhooks or push.</p>
            </li>
        </ol>

        <p class="ms-how__more">
            <a class="ms-btn ms-btn--quiet" href="/temporary-email.php">More about temporary email addresses</a>
        </p>
    </div>
</section>
