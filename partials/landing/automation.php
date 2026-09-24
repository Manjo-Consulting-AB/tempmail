<?php
/**
 * Landing — automation. Spec: documentaion/REDESIGN_BRIEF.md §8 (copy), §9
 * (the honest feature inventory), §11 (marketing pages load no icon font).
 *
 * The differentiating section: seven capabilities, laid out as one shelf. Six
 * of them ship and the seventh does not, and the whole section is only worth
 * anything if it keeps those two apart (§9). So every card below is a claim
 * the code backs today:
 *
 *   RSS                pro_feed.php, a token-protected feed
 *   Webhooks           ImapProcessor::dispatchWebhooks(), a generic JSON POST
 *   Pushover           a webhook kind, not push infrastructure of our own
 *   Digest emails      cron/send-digests.php
 *   External mailboxes the Client Agent subsystem
 *   Agent              client/agent/, RSA-signed webhooks from this backend
 *   API                does not exist — hence the Coming badge and nothing else
 *
 * Card 3 names Pushover, the service, because that is what exists: a message
 * reaches a phone through Pushover's own app, not through notification
 * infrastructure of ours. The word "push" appears here only inside that
 * verbatim line and never as a capability in its own right.
 *
 * No card is a link. There is nothing public to link them to: the pages that
 * configure RSS, webhooks and digests are all behind a login
 * (pro_profile_page.php, client_agent_manage.php — both ruled out by the
 * issue), so a card that looked clickable would be a hover affordance on
 * something that does nothing. Plain .ms-card, and the grid is a <ul> so the
 * seven still read as one list to a screen reader.
 *
 * The icons are drawn here in the same register as the shield in
 * partials/brand.php — 20-unit box, 1.5 stroke, currentColor — rather than
 * pulled from an icon set. Marketing pages load no Font Awesome and no other
 * font icon (§11); no third-party logo is reproduced, and no brand colour is
 * borrowed, because nothing here is drawn from the vendors' own marks.
 *
 * The seventh card is the only one with a badge, and it is the only card that
 * cannot fill a row on its own. Below 1096px (see mailshield.css) it is given
 * the full row so it reads as the deliberate note it is rather than as an
 * orphan of the auto-fit grid.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

// Same trial note as the personal-addresses section (epic #267), signed-out only.
$msAutoTrialDays = empty($_SESSION['pro_user_id']) ? max(0, (int) ($config['trial']['days'] ?? 0)) : 0;
?>
<section class="ms-section ms-section--sunken" id="automation">
    <div class="ms-container">
        <p class="ms-eyebrow">Automation</p>
        <h2 class="ms-h2">Email doesn't have to stay in your inbox.</h2>
        <p class="ms-automation__body">Connect Mail Shield to the tools you already use.</p>
        <p class="ms-automation__tier">Automation is part of Pro<?php if ($msAutoTrialDays > 0) : ?> &mdash; and included in your first <?php echo $msAutoTrialDays; ?> days<?php endif; ?>.</p>

        <ul class="ms-grid ms-grid--3 ms-automation__grid">
            <li class="ms-card ms-automation__card">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="4.9" cy="15.1" r="1.5" fill="currentColor" stroke="none"/>
                    <path d="M4.9 10.6A4.5 4.5 0 0 1 9.4 15.1"/>
                    <path d="M4.9 6.6A8.5 8.5 0 0 1 13.4 15.1"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">RSS</h3>
                <p class="ms-automation__text">Read your inbox in any feed reader, through a private token-protected feed.</p>
            </li>

            <li class="ms-card ms-automation__card">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M5.9 14.1L14.1 5.9"/>
                    <circle cx="4.5" cy="15.5" r="2"/>
                    <circle cx="15.5" cy="4.5" r="2"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">Webhooks</h3>
                <p class="ms-automation__text">POST every incoming message as JSON to your own endpoint.</p>
            </li>

            <li class="ms-card ms-automation__card">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="6" y="2.5" width="8" height="15" rx="2"/>
                    <circle cx="11.5" cy="6.5" r="1.5" fill="currentColor" stroke="none"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">Pushover</h3>
                <p class="ms-automation__text">Get incoming mail as a push notification on your phone.</p>
            </li>

            <li class="ms-card ms-automation__card">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="2.5" y="4.5" width="15" height="11" rx="2"/>
                    <path d="M6 8.5H14"/>
                    <path d="M6 11.5H11"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">Digest emails</h3>
                <p class="ms-automation__text">A periodic summary of what arrived, sent to your real inbox.</p>
            </li>

            <li class="ms-card ms-automation__card">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="3" y="8.5" width="14" height="9" rx="2"/>
                    <path d="M10 2.5V7.4"/>
                    <path d="M7.8 5.2L10 7.4L12.2 5.2"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">External mailboxes</h3>
                <p class="ms-automation__text">Point Mail Shield at a mailbox you already run.</p>
            </li>

            <li class="ms-card ms-automation__card">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="3" y="3" width="14" height="5" rx="1.5"/>
                    <circle cx="6" cy="5.5" r=".85" fill="currentColor" stroke="none"/>
                    <circle cx="8.8" cy="5.5" r=".85" fill="currentColor" stroke="none"/>
                    <path d="M6.4 13.4L9 16L14 11"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">Agent</h3>
                <p class="ms-automation__text">Run filtering on your own mail server, driven by signed webhooks.</p>
            </li>

            <li class="ms-card ms-automation__card ms-automation__card--soon">
                <svg class="ms-automation__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M8 6L3.5 10L8 14"/>
                    <path d="M12 6L16.5 10L12 14"/>
                </svg>
                <h3 class="ms-h3 ms-automation__title">API <span class="ms-badge ms-badge--soon">Coming</span></h3>
                <p class="ms-automation__text">Programmatic access to your addresses and messages.</p>
            </li>
        </ul>

        <p class="ms-automation__note">Every incoming message can trigger more than one of these.</p>
    </div>
</section>
