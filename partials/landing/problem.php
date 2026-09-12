<?php
/**
 * Landing — problem. Spec: documentaion/REDESIGN_BRIEF.md §8.
 *
 * One beat between the hero and the steps: name the problem, then turn it. The
 * second paragraph is the turn, so it keeps --ms-text while the first sits in
 * --ms-text-muted. No illustration — §6 says the product is the visual, and the
 * brief rules out a before/after or a cluttered-inbox graphic.
 *
 * The chips below the turn name the categories the product absorbs. They are
 * decoration-grade on purpose (hairline border, muted, no accent, no icons) and
 * they wrap rather than scroll.
 *
 * Apostrophes are typographic (’) per the issue's copy check.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }
?>
<section class="ms-section" id="problem">
    <div class="ms-container--narrow ms-problem">
        <h2 class="ms-h2">Your inbox wasn’t built for all this.</h2>
        <p class="ms-problem__body">Every signup, newsletter, online purchase and random service adds another message to the inbox you actually care about.</p>
        <p class="ms-problem__body ms-problem__body--turn">Mail Shield gives those emails somewhere else to go.</p>

        <ul class="ms-problem__chips">
            <li class="ms-chip">shopping</li>
            <li class="ms-chip">newsletters</li>
            <li class="ms-chip">registrations</li>
            <li class="ms-chip">forums</li>
            <li class="ms-chip">services you want to try</li>
            <li class="ms-chip">projects</li>
            <li class="ms-chip">automated email</li>
        </ul>
    </div>
</section>
