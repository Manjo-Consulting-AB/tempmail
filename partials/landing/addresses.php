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
?>
<section class="ms-section" id="features">
    <div class="ms-container">
        <div class="ms-addresses">
            <div class="ms-addresses__copy">
                <p class="ms-eyebrow">Sticky addresses</p>
                <h2 class="ms-h2">One account. Multiple addresses.</h2>
                <p class="ms-addresses__body">Create up to 10 sticky addresses and manage them from one inbox. Keep the addresses you want to use, without turning this into another inbox you have to maintain.</p>
                <p class="ms-addresses__kicker">Multiple identities. One inbox.</p>
                <p class="ms-addresses__tier">Sticky addresses are part of Pro<?php if ($msAddrTrialDays > 0) : ?> &mdash; and included in your first <?php echo $msAddrTrialDays; ?> days<?php endif; ?>.</p>
            </div>

            <div class="ms-addresses__routing">
                <ul class="ms-addresses__list">
                    <li class="ms-addresses__item"><span class="ms-addr">shopping@<?php echo $msAddrDomain; ?></span></li>
                    <li class="ms-addresses__item"><span class="ms-addr">news@<?php echo $msAddrDomain; ?></span></li>
                    <li class="ms-addresses__item"><span class="ms-addr">projects@<?php echo $msAddrDomain; ?></span></li>
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
                                <p class="ms-eyebrow">INBOX</p>
                                <p class="ms-mock__count">22 messages</p>
                            </div>

                            <ul class="ms-mock__list">
                                <li class="ms-mock__row ms-mock__row--unread">
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
                                <li class="ms-mock__row ms-mock__row--unread">
                                    <span class="ms-mock__avatar">W</span>
                                    <span class="ms-mock__head">
                                        <span class="ms-mock__from">The Weekly Dispatch</span>
                                        <span class="ms-mock__time">1h</span>
                                    </span>
                                    <span class="ms-mock__sub">
                                        <span class="ms-mock__subject">This week's offers</span>
                                        <span class="ms-chip ms-mock__to">To: news</span>
                                    </span>
                                </li>
                                <li class="ms-mock__row">
                                    <span class="ms-mock__avatar">G</span>
                                    <span class="ms-mock__head">
                                        <span class="ms-mock__from">GitHub</span>
                                        <span class="ms-mock__time">3h</span>
                                    </span>
                                    <span class="ms-mock__sub">
                                        <span class="ms-mock__subject">Security alert: new sign-in</span>
                                        <span class="ms-chip ms-mock__to">To: projects</span>
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
