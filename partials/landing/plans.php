<?php
/**
 * Landing — free and Pro. Spec: the redesign brief (`REDESIGN_BRIEF.md`), §8
 * (copy), §9 (the honest feature inventory) and §5.1 (the accent is spent
 * sparingly).
 *
 * Replaces the bordered, striped tier table that used to sit on index.php.
 * That table read like a spec sheet, and it was the single most
 * generic-tempmail element on the page; two cards say the same thing in the
 * register the rest of the landing page is written in.
 *
 * Every bullet below is a claim the code backs today (§9):
 *
 *   one temporary address at a time   index.php `case 'generate'` — creating a
 *                                     new one replaces the previous
 *   24 hours                          index.php `case 'generate'` /
 *                                     config.php saveNewAddress() default
 *   signed attachment links           files.php
 *   up to 10 personal addresses       index.php `case 'create_personal'` — the
 *                                     quota is 10 per account
 *   lifetime 1 to 7 days on Pro       index.php `case 'generate'` clamps
 *                                     address_ttl_days to that range
 *   RSS, webhooks, Pushover, digests  pro_feed.php, ImapProcessor::dispatchWebhooks(),
 *                                     the Pushover webhook kind, cron/send-digests.php
 *   the Agent                         the Client Agent subsystem (client/agent/)
 *   personal addresses are private    index.php's owner check on is_personal
 *                                     rows, plus pro_profile_page.php settings
 *   support                           pro_contact.php, open to any signed-in account
 *
 * There is no price anywhere in it, on purpose. Online payment does not exist:
 * Pro is unlocked with a voucher code and PRO_SELF_SIGNUP_ENABLED is off by
 * default, so the note under the cards says exactly that and nothing here
 * invents a number or a currency.
 *
 * The one offer it does make is real: every new account starts on Pro for
 * $config['trial']['days'] days (epic #267, pro_trial.php, granted on first
 * verification in pro_login.php), once per email address. The number is read
 * from $config, never written into the copy, and at 0 the section falls back to
 * the pre-trial wording. The trial copy is shown to signed-out visitors only.
 *
 * The tier is called Free on the website. The database column behind it is
 * `account_type = 'regular'`, and that word stays out of visitor-facing copy.
 *
 * The Pro card is the recommended one, marked with restraint: the accent on the
 * card's hairline border and a chip. No filled accent background, no transform,
 * no ribbon — a page that has to point at its own best option that loudly is
 * not confident about it.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$msPlansSignedIn = !empty($_SESSION['pro_user_id']);
$msPlansTrialDays = $msPlansSignedIn ? 0 : max(0, (int) ($config['trial']['days'] ?? 0));
?>
<section class="ms-section" id="plans">
    <div class="ms-container">
        <h2 class="ms-h2">Free and Pro</h2>
        <?php if ($msPlansTrialDays > 0) : ?>
            <p class="ms-plans__body">Every new account starts with <?php echo $msPlansTrialDays; ?> days of Pro, free &mdash; sticky addresses and automation included. When the trial ends, you keep a free account, or stay on Pro.</p>
        <?php else : ?>
            <p class="ms-plans__body">A free account gives you a timed address. Pro adds sticky addresses and the tools to route and automate the mail they receive.</p>
        <?php endif; ?>

        <div class="ms-plans__grid">
            <div class="ms-card ms-plans__card">
                <div class="ms-plans__head">
                    <h3 class="ms-h3">Free</h3>
                </div>
                <p class="ms-plans__summary">Timed addresses, in a real inbox.</p>

                <ul class="ms-plans__list">
                    <li>One timed address at a time</li>
                    <li>Addresses and messages deleted after 24 hours</li>
                    <li>Read your mail on the web</li>
                    <li>Signed, time-limited attachment links</li>
                </ul>
            </div>

            <div class="ms-card ms-plans__card ms-plans__card--pro">
                <div class="ms-plans__head">
                    <h3 class="ms-h3">Pro</h3>
                    <span class="ms-chip"><?php echo $msPlansTrialDays > 0 ? 'First ' . $msPlansTrialDays . ' days free' : 'Most useful'; ?></span>
                </div>
                <p class="ms-plans__summary">Your second inbox, with the wiring.</p>

                <ul class="ms-plans__list">
                    <li>Up to 10 sticky addresses</li>
                    <li>Timed address lifetime configurable from 1 to 7 days</li>
                    <li>Incoming mail is cleaned up automatically according to your retention settings</li>
                    <li>RSS, webhooks, Pushover and digest emails</li>
                    <li>The Agent, for sender rules on your own mail server</li>
                    <li>Sticky addresses belong to your account</li>
                    <li>Priority support</li>
                </ul>
            </div>
        </div>

        <?php if ($msPlansTrialDays > 0) : ?>
            <p class="ms-plans__note">No card needed, and nothing to cancel. One trial per email address. After the trial, Pro continues with a voucher code &mdash; online payment is on the way.</p>
        <?php else : ?>
            <p class="ms-plans__note">Pro currently requires a voucher code &mdash; online payment is on the way.</p>
        <?php endif; ?>

        <div class="ms-plans__actions">
            <?php if ($msPlansSignedIn) : ?>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php">Go to your inbox</a>
            <?php else : ?>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="/register.php?plan=regular"><?php echo $msPlansTrialDays > 0 ? 'Start with ' . $msPlansTrialDays . ' days of Pro' : 'Create your inbox'; ?></a>
                <a class="ms-btn ms-btn--secondary ms-btn--lg" href="/register.php?plan=pro">Get Pro with a code</a>
            <?php endif; ?>
        </div>
    </div>
</section>
