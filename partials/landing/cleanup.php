<?php
/**
 * Landing — smart cleanup. Spec: the redesign brief (`REDESIGN_BRIEF.md`), §8
 * (copy), §9 (the honest feature inventory) and §3.1 (how far the name is
 * allowed to go).
 *
 * The second half of the product's argument: a second inbox is only worth
 * having if it does not become a second inbox to manage. §3.1 lets the name
 * carry some weight here, but only as *less clutter* — never as threat
 * language. Hence a plain list of things that happen by themselves, and no
 * padlock, no shield art and no warning anywhere in it.
 *
 * Every one of the four points is code that ships today:
 *
 *   Point 1  cron/cleanup.php — temporary addresses and their messages expire
 *   Point 2  the Client Agent's sender lists (mailfilter_whitelist /
 *            mailfilter_blacklist / mailfilter_reported_senders)
 *   Point 3  cron/send-digests.php
 *   Point 4  the Pushover webhook kind — the same one automation.php names
 *
 * Point 2 is the only one that does not run on our side of the wire: the lists
 * live in the Agent, which the user installs on their own mail server. It keeps
 * a quiet qualifier underneath (--ms-fs-xs / --ms-text-faint) rather than being
 * dropped, because a visitor reading only the point would reasonably assume the
 * filtering is ours. The point says "allow and block lists per sender" and the
 * qualifier says where they run; neither claims a spam classifier, because
 * there is none in this codebase — the lists are what exists, and they are what
 * the copy names.
 *
 * Treatment: deliberately not a card grid. automation.php immediately above is
 * already seven boxes on a sunken band, and a second grid would flatten two
 * sections into one texture. This is a flat two-column list — one column on
 * mobile — divided by hairlines, with a small check mark per point.
 *
 * The marks are drawn here in the same register as the icons in automation.php
 * and the shield in partials/brand.php: currentColor, 1.5 stroke. Marketing
 * pages load no icon font (§11), so nothing is pulled from a set. They are
 * aria-hidden — these are bullets, and the list already reads as a list without
 * them. Muted rather than accent: four accent marks would spend the section's
 * whole accent budget on a bullet point (§5.1).
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }
?>
<section class="ms-section" id="cleanup">
    <div class="ms-container">
        <p class="ms-eyebrow">Smart cleanup</p>
        <h2 class="ms-h2">Less clutter. Automatically.</h2>
        <p class="ms-cleanup__body">Your temporary inbox shouldn't become another inbox you have to manage.</p>

        <ul class="ms-cleanup__list">
            <li class="ms-cleanup__item">
                <svg class="ms-cleanup__mark" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M3.5 8.5L6.5 11.5L12.5 4.5"/>
                </svg>
                <p class="ms-cleanup__text">Temporary addresses and their messages are deleted automatically.</p>
            </li>

            <li class="ms-cleanup__item">
                <svg class="ms-cleanup__mark" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M3.5 8.5L6.5 11.5L12.5 4.5"/>
                </svg>
                <p class="ms-cleanup__text">Allow and block lists per sender, so the noise stops before you see it.</p>
                <p class="ms-cleanup__qualifier">Sender lists run through the Agent.</p>
            </li>

            <li class="ms-cleanup__item">
                <svg class="ms-cleanup__mark" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M3.5 8.5L6.5 11.5L12.5 4.5"/>
                </svg>
                <p class="ms-cleanup__text">Digest emails summarise what arrived while you were away.</p>
            </li>

            <li class="ms-cleanup__item">
                <svg class="ms-cleanup__mark" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M3.5 8.5L6.5 11.5L12.5 4.5"/>
                </svg>
                <p class="ms-cleanup__text">Notifications for the messages that actually matter.</p>
            </li>
        </ul>
    </div>
</section>
