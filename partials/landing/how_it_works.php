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
        <h2 class="ms-h2"><?php echo te('landing.how.title'); ?></h2>

        <ol class="ms-grid ms-grid--3 ms-steps">
            <li class="ms-card ms-step">
                <span class="ms-step__num" aria-hidden="true">1</span>
                <h3 class="ms-h3"><?php echo te('landing.how.step1_title'); ?></h3>
                <p class="ms-step__body"><?php echo te('landing.how.step1_body'); ?></p>
            </li>
            <li class="ms-card ms-step">
                <span class="ms-step__num" aria-hidden="true">2</span>
                <h3 class="ms-h3"><?php echo te('landing.how.step2_title'); ?></h3>
                <p class="ms-step__body"><?php echo tHtml('landing.how.step2_body', [], ['addr' => '<span class="ms-addr">' . te('landing.mock.addr_shopping') . '@' . $msHowDomain . '</span>']); ?></p>
            </li>
            <li class="ms-card ms-step">
                <span class="ms-step__num" aria-hidden="true">3</span>
                <h3 class="ms-h3"><?php echo te('landing.how.step3_title'); ?></h3>
                <p class="ms-step__body"><?php echo te('landing.how.step3_body'); ?></p>
            </li>
        </ol>

        <p class="ms-how__more">
            <a class="ms-btn ms-btn--quiet" href="<?php echo htmlspecialchars(i18nUrl('/temporary-email.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('landing.how.more'); ?></a>
        </p>
    </div>
</section>
