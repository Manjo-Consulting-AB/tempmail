<?php
/**
 * Landing — temporary addresses. Spec: documentaion/REDESIGN_BRIEF.md §8 (copy),
 * §2 (temporary email is one feature among several, not the identity) and §9
 * (the retention claim: 24 h free, 1–7 days Pro).
 *
 * The section's visual is the lifecycle, not a product mockup: create, use,
 * gone — three beats, hairlines between them, no card and no screenshot. This
 * is deliberately the plainest block on the page. Temporary email used to be
 * the whole product; the page must no longer say so (§2), and a big mockup
 * here would.
 *
 * The example local part is eight hex characters because the code validates
 * addresses against ^[a-f0-9]{8,16}$ (CLAUDE.md) — "temp-1234" would misdescribe
 * what the product actually hands out. The domain comes from index.php on every
 * render, never hardcoded (§2.1).
 *
 * The retention sentence is the approved one, verbatim. Both tiers stay in it:
 * dropping the qualifier ("up to 7 days") would overclaim for free accounts,
 * and rounding it to "about a week" would underclaim for Pro (§9).
 *
 * The link points at temporary-email.php, which Redesign 17 creates — it 404s
 * until then, exactly as the footer's and how-it-works' links to the same page
 * already do. Removing the link is not an option (§12.4, §14).
 *
 * There is no generate button and nothing focusable but the link: creating an
 * address requires a registered account (§9), so the landing page must not fake
 * a one-click flow it cannot deliver.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

// index.php sets $domain; the config fallback keeps the partial self-contained.
$msTempDomain = htmlspecialchars(
    (string) ($domain ?? ($config['email']['domain'] ?? '')),
    ENT_QUOTES,
    'UTF-8'
);

// Retention as the approved sentence states it (brief §9): a free Timed address
// lasts 24 hours, a Pro one is configurable up to 7 days (index.php `case 'generate'`).
// Passed to the copy as placeholders so no catalog value carries a number.
$msTempFreeHours = 24;
$msTempProMaxDays = 7;
?>
<section class="ms-section" id="temporary">
    <div class="ms-container">
        <p class="ms-eyebrow"><?php echo te('landing.temporary.eyebrow'); ?></p>
        <h2 class="ms-h2"><?php echo te('landing.temporary.title'); ?></h2>
        <p class="ms-temp__body"><?php echo te('landing.temporary.body'); ?></p>

        <ol class="ms-lifecycle">
            <li class="ms-lifecycle__beat">
                <span class="ms-eyebrow ms-lifecycle__label"><?php echo te('landing.temporary.create'); ?></span>
                <span class="ms-addr ms-lifecycle__addr">a4f9c2e1@<?php echo $msTempDomain; ?></span>
            </li>
            <li class="ms-lifecycle__beat">
                <span class="ms-eyebrow ms-lifecycle__label"><?php echo te('landing.temporary.use'); ?></span>
                <span class="ms-lifecycle__detail"><?php echo te('landing.temporary.use_detail'); ?></span>
            </li>
            <li class="ms-lifecycle__beat ms-lifecycle__beat--end">
                <span class="ms-eyebrow ms-lifecycle__label"><?php echo te('landing.temporary.gone'); ?></span>
                <span class="ms-lifecycle__detail"><?php echo te('landing.temporary.gone_detail'); ?></span>
            </li>
        </ol>

        <p class="ms-temp__retention"><?php echo te('landing.temporary.retention', ['hours' => $msTempFreeHours, 'days' => $msTempProMaxDays]); ?></p>
        <p class="ms-temp__aside"><?php echo te('landing.temporary.aside'); ?></p>
        <p class="ms-temp__more"><a class="ms-btn ms-btn--quiet" href="<?php echo htmlspecialchars(i18nUrl('/temporary-email.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('landing.temporary.more'); ?></a></p>
    </div>
</section>
