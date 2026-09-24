<?php
/**
 * Landing — hero. Spec: documentaion/REDESIGN_BRIEF.md §6 (the product is the
 * hero image), §8 (copy deck), §2.1 (render the domain, never hardcode it).
 *
 * Two columns from 1024px up: copy left, product mockup right. The mockup is
 * markup, not a screenshot, and it is decorative for assistive tech — every
 * sentence it illustrates is already in the h1 and the lede, so a screen reader
 * is not read a list of fake email. Nothing inside it is focusable.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$msHeroSignedIn = !empty($_SESSION['pro_user_id']);

// Every new account starts on Pro for this many days (epic #267). Read from
// $config rather than written into copy, so the page cannot promise a trial the
// backend no longer grants; 0 turns the trial off and the plain note returns.
$msHeroTrialDays = max(0, (int) ($config['trial']['days'] ?? 0));

// index.php sets $domain; the config fallback keeps the partial self-contained.
$msHeroDomain = htmlspecialchars(
    (string) ($domain ?? ($config['email']['domain'] ?? '')),
    ENT_QUOTES,
    'UTF-8'
);
?>
<section class="ms-hero" id="hero">
    <div class="ms-container ms-hero__inner">
        <div class="ms-hero__copy">
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
            <?php if (!$msHeroSignedIn) : ?>
                <?php if ($msHeroTrialDays > 0) : ?>
                    <p class="ms-hero__note">Includes <?php echo $msHeroTrialDays; ?> days of Pro, free. No card needed.</p>
                <?php else : ?>
                    <p class="ms-hero__note">Free to start. No card needed.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="ms-hero__media" aria-hidden="true" inert>
            <div class="ms-mock">
                <div class="ms-mock__bar">
                    <span class="ms-mock__dot"></span>
                    <span class="ms-mock__dot"></span>
                    <span class="ms-mock__dot"></span>
                </div>

                <div class="ms-mock__body">
                    <div class="ms-mock__side">
                        <p class="ms-eyebrow">INBOX</p>
                        <p class="ms-mock__count">24 messages</p>

                        <p class="ms-eyebrow ms-mock__addrs-label">ADDRESSES</p>
                        <ul class="ms-mock__addrs">
                            <li class="ms-chip ms-mock__addr ms-mock__addr--active">
                                <span class="ms-mock__addr-name">shopping@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">12</span>
                            </li>
                            <li class="ms-chip ms-mock__addr">
                                <span class="ms-mock__addr-name">news@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">7</span>
                            </li>
                            <li class="ms-chip ms-mock__addr">
                                <span class="ms-mock__addr-name">projects@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">3</span>
                            </li>
                            <li class="ms-chip ms-mock__addr">
                                <span class="ms-mock__addr-name">random@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">2</span>
                            </li>
                        </ul>
                    </div>

                    <ul class="ms-mock__list">
                        <li class="ms-mock__row ms-mock__row--unread ms-rise">
                            <span class="ms-mock__avatar">A</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">Amazon</span>
                                <span class="ms-mock__time">2m</span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject">Your order has shipped</span>
                                <span class="ms-chip ms-mock__to">To: shopping</span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-mock__row--unread ms-rise">
                            <span class="ms-mock__avatar">G</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">GitHub</span>
                                <span class="ms-mock__time">18m</span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject">Security alert: new sign-in</span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-rise">
                            <span class="ms-mock__avatar">S</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">Spotify</span>
                                <span class="ms-mock__time">1h</span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject">New releases this week</span>
                                <span class="ms-chip ms-mock__to">To: news</span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-rise">
                            <span class="ms-mock__avatar">R</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">Reddit</span>
                                <span class="ms-mock__time">3h</span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject">Someone replied to your post</span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-rise">
                            <span class="ms-mock__avatar">T</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">The Weekly Dispatch</span>
                                <span class="ms-mock__time">Yesterday</span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject">This week's offers</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>
