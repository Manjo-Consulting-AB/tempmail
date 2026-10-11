<?php
/**
 * Landing — personal addresses. Spec: documentaion/REDESIGN_BRIEF.md §8 (copy),
 * §3.2 (the "spine" model, used subtly), §2.1 (render the domain).
 *
 * The section that separates Mail Shield from a tempmail generator: several
 * stable addresses feeding one inbox. The addresses can remain in use while
 * the mail they receive is still subject to the user's retention settings.
 * That idea is carried by the diagram — three thin connectors converging on a
 * single inbox card, one of them tinted to show the live path. No arrowheads,
 * no glow, no sci-fi network (§3.2).
 *
 * id="features" is load-bearing: the nav's Features link targets it, so this is
 * where the feature run starts.
 *
 * The diagram is decoration. Every claim it makes is already in the copy above
 * it, so the whole thing is aria-hidden and nothing inside it is focusable.
 *
 * The card reuses the hero's .ms-mock markup; .ms-mock--compact stacks it,
 * because the addresses live outside the card in this section rather than in
 * the sidebar the hero uses.
 *
 * The count is not arbitrary: 12 + 7 + 3 are the per-address counts the hero
 * mockup shows for the same three addresses.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

// index.php sets $domain; the config fallback keeps the partial self-contained.
$msAddrDomain = htmlspecialchars(
    (string) ($domain ?? ($config['email']['domain'] ?? '')),
    ENT_QUOTES,
    'UTF-8'
);

// The tier note doubles as the trial pitch for signed-out visitors (epic #267):
// this is where personal addresses start to look worth having.
$msAddrTrialDays = empty($_SESSION['pro_user_id']) ? max(0, (int) ($config['trial']['days'] ?? 0)) : 0;

// The cap on sticky addresses per account (index.php `case 'create_personal'`).
// Passed to the copy as a placeholder so no catalog value carries the number.
$msAddrStickyMax = 10;
?>
<section class="ms-section" id="features">
    <div class="ms-container">
        <div class="ms-addresses">
            <div class="ms-addresses__copy">
                <p class="ms-eyebrow"><?php echo te('landing.addresses.eyebrow'); ?></p>
                <h2 class="ms-h2"><?php echo te('landing.addresses.title'); ?></h2>
                <p class="ms-addresses__body"><?php echo te('landing.addresses.body', ['max' => $msAddrStickyMax]); ?></p>
                <p class="ms-addresses__kicker"><?php echo te('landing.addresses.kicker'); ?></p>
                <p class="ms-addresses__tier"><?php echo $msAddrTrialDays > 0 ? te('landing.addresses.tier_trial', ['days' => $msAddrTrialDays]) : te('landing.addresses.tier'); ?></p>
            </div>

            <div class="ms-addresses__routing">
                <ul class="ms-addresses__list">
                    <li class="ms-addresses__item"><span class="ms-addr"><?php echo te('landing.mock.addr_shopping'); ?>@<?php echo $msAddrDomain; ?></span></li>
                    <li class="ms-addresses__item"><span class="ms-addr"><?php echo te('landing.mock.addr_news'); ?>@<?php echo $msAddrDomain; ?></span></li>
                    <li class="ms-addresses__item"><span class="ms-addr"><?php echo te('landing.mock.addr_projects'); ?>@<?php echo $msAddrDomain; ?></span></li>
                </ul>

                <svg class="ms-addresses__links" viewBox="0 0 72 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path class="ms-addresses__link ms-addresses__link--active" d="M0 16.667C36 16.667 36 50 72 50" pathLength="1" />
                    <path class="ms-addresses__link" d="M0 50H72" />
                    <path class="ms-addresses__link" d="M0 83.333C36 83.333 36 50 72 50" />
                </svg>

                <div class="ms-addresses__inbox" aria-hidden="true" inert>
                    <div class="ms-mock ms-mock--compact">
                        <div class="ms-mock__bar">
                            <span class="ms-mock__dot"></span>
                            <span class="ms-mock__dot"></span>
                            <span class="ms-mock__dot"></span>
                        </div>

                        <div class="ms-mock__body">
                            <div class="ms-mock__side">
                                <p class="ms-eyebrow"><?php echo te('landing.mock.inbox'); ?></p>
                                <p class="ms-mock__count"><?php echo htmlspecialchars(tp('landing.mock.count', 22), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>

                            <ul class="ms-mock__list">
                                <li class="ms-mock__row ms-mock__row--unread">
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
                                <li class="ms-mock__row ms-mock__row--unread">
                                    <span class="ms-mock__avatar"><?php echo te('landing.mock.avatar_dispatch_card'); ?></span>
                                    <span class="ms-mock__head">
                                        <span class="ms-mock__from"><?php echo te('landing.mock.from_dispatch'); ?></span>
                                        <span class="ms-mock__time"><?php echo te('landing.mock.hours', ['n' => 1]); ?></span>
                                    </span>
                                    <span class="ms-mock__sub">
                                        <span class="ms-mock__subject"><?php echo te('landing.mock.subject_offers'); ?></span>
                                        <span class="ms-chip ms-mock__to"><?php echo te('landing.mock.to', ['name' => t('landing.mock.addr_news')]); ?></span>
                                    </span>
                                </li>
                                <li class="ms-mock__row">
                                    <span class="ms-mock__avatar">G</span>
                                    <span class="ms-mock__head">
                                        <span class="ms-mock__from">GitHub</span>
                                        <span class="ms-mock__time"><?php echo te('landing.mock.hours', ['n' => 3]); ?></span>
                                    </span>
                                    <span class="ms-mock__sub">
                                        <span class="ms-mock__subject"><?php echo te('landing.mock.subject_security'); ?></span>
                                        <span class="ms-chip ms-mock__to"><?php echo te('landing.mock.to', ['name' => t('landing.mock.addr_projects')]); ?></span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
