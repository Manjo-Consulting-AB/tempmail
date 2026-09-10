<?php
/**
 * Landing — final CTA. Spec: the redesign brief (`REDESIGN_BRIEF.md`), §8 (copy).
 *
 * The close. One heading, one sentence, one button, centred and on a sunken
 * band — and nothing else. No secondary link, no email capture, no repeat of
 * the feature list, no second footer: public_footer.php is directly below and
 * would make any of those read as a stutter.
 *
 * The sunken band runs straight into the footer's own sunken background, so the
 * two share a colour field and the footer's hairline is the only seam between
 * them. That is the intent — the end of the page is one closing zone rather
 * than two competing bands — and it is why the section carries extra top
 * padding: the air has to be above the heading, not between it and the button.
 *
 * Signed in, the button matches the nav and the hero and points at the app
 * instead of at a signup the visitor has already completed. The heading and the
 * sentence are the same either way.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$msCtaSignedIn = !empty($_SESSION['pro_user_id']);
?>
<section class="ms-section ms-section--sunken ms-cta" id="get-started">
    <div class="ms-container--narrow ms-cta__inner">
        <h2 class="ms-h2">Give your inbox some breathing room.</h2>
        <p class="ms-cta__body">Keep your primary inbox for what matters. Let Mail Shield handle everything else.</p>
        <?php if ($msCtaSignedIn) : ?>
            <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php">Go to your inbox</a>
        <?php else : ?>
            <a class="ms-btn ms-btn--primary ms-btn--lg" href="/register.php?plan=regular">Create your inbox</a>
        <?php endif; ?>
    </div>
</section>
