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
            <h1 class="ms-display"><?php echo te('landing.hero.title'); ?></h1>
            <p class="ms-lede"><?php echo te('landing.hero.lede'); ?></p>
            <div class="ms-hero__actions">
                <?php if ($msHeroSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php"><?php echo te('landing.hero.go_to_inbox'); ?></a>
                <?php else : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="<?php echo htmlspecialchars(i18nUrl('/register.php?plan=regular'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('landing.hero.create_inbox'); ?></a>
                <?php endif; ?>
                <a class="ms-btn ms-btn--secondary ms-btn--lg" href="#how-it-works"><?php echo te('landing.hero.see_how'); ?></a>
            </div>
            <?php if (!$msHeroSignedIn) : ?>
                <?php if ($msHeroTrialDays > 0) : ?>
                    <p class="ms-hero__note"><?php echo te('landing.hero.note_trial', ['days' => $msHeroTrialDays]); ?></p>
                <?php else : ?>
                    <p class="ms-hero__note"><?php echo te('landing.hero.note_free'); ?></p>
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
                        <p class="ms-eyebrow"><?php echo te('landing.mock.inbox'); ?></p>
                        <p class="ms-mock__count"><?php echo htmlspecialchars(tp('landing.mock.count', 24), ENT_QUOTES, 'UTF-8'); ?></p>

                        <p class="ms-eyebrow ms-mock__addrs-label"><?php echo te('landing.mock.addresses'); ?></p>
                        <ul class="ms-mock__addrs">
                            <li class="ms-chip ms-mock__addr ms-mock__addr--active">
                                <span class="ms-mock__addr-name"><?php echo te('landing.mock.addr_shopping'); ?>@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">12</span>
                            </li>
                            <li class="ms-chip ms-mock__addr">
                                <span class="ms-mock__addr-name"><?php echo te('landing.mock.addr_news'); ?>@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">7</span>
                            </li>
                            <li class="ms-chip ms-mock__addr">
                                <span class="ms-mock__addr-name"><?php echo te('landing.mock.addr_projects'); ?>@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">3</span>
                            </li>
                            <li class="ms-chip ms-mock__addr">
                                <span class="ms-mock__addr-name"><?php echo te('landing.mock.addr_random'); ?>@<?php echo $msHeroDomain; ?></span>
                                <span class="ms-mock__addr-count">2</span>
                            </li>
                        </ul>
                    </div>

                    <ul class="ms-mock__list">
                        <li class="ms-mock__row ms-mock__row--unread ms-rise">
                            <span class="ms-mock__avatar">A</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">Amazon</span>
                                <span class="ms-mock__time"><?php echo te('landing.mock.minutes', ['n' => 2]); ?></span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject"><?php echo te('landing.mock.subject_shipped'); ?></span>
                                <span class="ms-chip ms-mock__to"><?php echo te('landing.mock.to', ['name' => t('landing.mock.addr_shopping')]); ?></span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-mock__row--unread ms-rise">
                            <span class="ms-mock__avatar">G</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">GitHub</span>
                                <span class="ms-mock__time"><?php echo te('landing.mock.minutes', ['n' => 18]); ?></span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject"><?php echo te('landing.mock.subject_security'); ?></span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-rise">
                            <span class="ms-mock__avatar">S</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">Spotify</span>
                                <span class="ms-mock__time"><?php echo te('landing.mock.hours', ['n' => 1]); ?></span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject"><?php echo te('landing.mock.subject_releases'); ?></span>
                                <span class="ms-chip ms-mock__to"><?php echo te('landing.mock.to', ['name' => t('landing.mock.addr_news')]); ?></span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-rise">
                            <span class="ms-mock__avatar">R</span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from">Reddit</span>
                                <span class="ms-mock__time"><?php echo te('landing.mock.hours', ['n' => 3]); ?></span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject"><?php echo te('landing.mock.subject_reply'); ?></span>
                            </span>
                        </li>
                        <li class="ms-mock__row ms-rise">
                            <span class="ms-mock__avatar"><?php echo te('landing.mock.avatar_dispatch_hero'); ?></span>
                            <span class="ms-mock__head">
                                <span class="ms-mock__from"><?php echo te('landing.mock.from_dispatch'); ?></span>
                                <span class="ms-mock__time"><?php echo te('landing.mock.yesterday'); ?></span>
                            </span>
                            <span class="ms-mock__sub">
                                <span class="ms-mock__subject"><?php echo te('landing.mock.subject_offers'); ?></span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>
