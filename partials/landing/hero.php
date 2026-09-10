<?php
/**
 * Landing — hero. Spec: documentaion/REDESIGN_BRIEF.md §8.
 *
 * Not a placeholder: the real headline, lede and both CTAs ship here, so the
 * page states its positioning from the moment the skeleton exists. Redesign 09
 * adds the product mockup beside this copy.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$msHeroSignedIn = !empty($_SESSION['pro_user_id']);
?>
<!-- Filled in by Redesign 09 -->
<section class="ms-section" id="hero">
    <div class="ms-container">
        <div class="ms-stack">
            <h1 class="ms-display">Your inbox for everything else.</h1>
            <p class="ms-lede">Keep your primary inbox for what matters. Use Mail Shield for everything else.</p>
            <div class="ms-hero__actions">
                <?php if ($msHeroSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php">Go to your inbox</a>
                <?php else : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="/register.php?plan=regular">Create your inbox</a>
                <?php endif; ?>
                <a class="ms-btn ms-btn--secondary ms-btn--lg" href="#how-it-works">See how it works</a>
            </div>
        </div>
    </div>
</section>
