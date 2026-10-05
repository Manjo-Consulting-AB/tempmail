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
 *   keep mail for N days, N times a year  retention_hold.php — a Pro account's
 *                                     retention hold on its Sticky addresses'
 *                                     mail (epic #369); both numbers come from
 *                                     $config['retention_hold'], never the copy
 *   RSS, webhooks, Pushover, digests  pro_feed.php, ImapProcessor::dispatchWebhooks(),
 *                                     the Pushover webhook kind, cron/send-digests.php
 *   the Agent                         the Client Agent subsystem (client/agent/)
 *   personal addresses are private    index.php's owner check on is_personal
 *                                     rows, plus pro_profile_page.php settings
 *   support                           pro_contact.php, open to any signed-in account
 *   mail storage, 10 / 100 MB         EmailStorage/MailboxQuota.php, the tiered
 *                                     limits in $config['email'] (#340)
 *   your AI assistant                 mcp.php (epic #318), sign-in named only
 *                                     while oauthDiscoveryEnabled() (#331)
 *
 * No amount is written here, on purpose. The Pro amounts live in Paddle and
 * pricing.php shows them localised to the visitor's country. The card is
 * rendered price-free — it names the billing periods pricing_tiers.php actually
 * has (month, year, once) and links to pricing.php — and, on production only,
 * assets/js/landing-price.js replaces that wording with "From <monthly price>"
 * in the visitor's currency, straight from Paddle.PricePreview(), the same
 * source and country detection as pricing.php (pricing_helpers.php). Without
 * JavaScript, or when Paddle cannot be reached, or on sandbox (test prices on
 * an indexable page), the price-free wording stays. Nothing here invents a
 * number or a currency.
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

require_once __DIR__ . '/../../pricing_helpers.php';

// Null unless this is production with a monthly price: the card then stays
// price-free and no third-party script is loaded.
$msPlansPrice = pricingLandingConfig($config);

$msPlansSignedIn = !empty($_SESSION['pro_user_id']);
$msPlansTrialDays = $msPlansSignedIn ? 0 : max(0, (int) ($config['trial']['days'] ?? 0));

// Mailbox storage per tier (#340), read the way legal_facts.php reads it.
// MailboxQuota deletes the oldest mail once a mailbox is over its limit, so
// the bullet says that rather than implying mail is bounced. The "×N" is only
// stated when the Pro figure is a whole multiple of the Free one.
$msPlansQuotaFree = (int) round(((int) ($config['email']['quota_bytes_free'] ?? 10485760)) / 1048576);
$msPlansQuotaPro  = (int) round(((int) ($config['email']['quota_bytes_pro'] ?? 104857600)) / 1048576);
$msPlansQuotaRatio = ($msPlansQuotaFree > 0 && $msPlansQuotaPro > $msPlansQuotaFree && $msPlansQuotaPro % $msPlansQuotaFree === 0)
    ? (int) ($msPlansQuotaPro / $msPlansQuotaFree) : 0;

// The retention hold (epic #369), read the way legal_facts.php reads it: a Pro
// account may keep the mail on its sticky addresses for this many days, this
// many times a year. Both numbers come from $config, never the copy.
$msPlansHoldDays = max(1, (int) ($config['retention_hold']['days'] ?? 30));
$msPlansHoldMax  = max(1, (int) ($config['retention_hold']['max_per_year'] ?? 4));

// The MCP bullet names the sign-in only while OAuth discovery is on, like the
// AI assistants card in automation.php; index.php sets $msMcpSignIn.
$msPlansMcpText = !empty($msMcpSignIn)
    ? 'Let your AI assistant handle your mail &mdash; connect Claude or another MCP client by signing in'
    : 'Let your AI assistant handle your mail &mdash; connect Claude or another MCP client with an access token';
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
                <p class="ms-plans__price"><span class="ms-plans__amount">No cost</span> No card needed</p>

                <ul class="ms-plans__list">
                    <li>One timed address at a time</li>
                    <li>Addresses and messages deleted after 24 hours</li>
                    <li><?php echo $msPlansQuotaFree; ?> MB of mail storage &mdash; the oldest mail makes room for new</li>
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
                <p class="ms-plans__price"><span class="ms-plans__amount"<?php echo $msPlansPrice !== null ? ' data-landing-price' : ''; ?>>Monthly, yearly or once</span> <a href="/pricing.php">See prices</a></p>

                <ul class="ms-plans__list">
                    <li>Up to 10 sticky addresses</li>
                    <li>Timed address lifetime configurable from 1 to 7 days</li>
                    <li>Keep mail for <?php echo $msPlansHoldDays; ?> days, up to <?php echo $msPlansHoldMax; ?> times a year</li>
                    <li><?php echo $msPlansQuotaPro; ?> MB of mail storage<?php echo $msPlansQuotaRatio > 1 ? ' &mdash; ' . $msPlansQuotaRatio . '&times; Free' : ''; ?></li>
                    <li>Incoming mail is cleaned up automatically according to your retention settings</li>
                    <li><?php echo $msPlansMcpText; ?></li>
                    <li>RSS, webhooks, Pushover and digest emails</li>
                    <li>The Agent, for sender rules on your own mail server</li>
                    <li>Sticky addresses belong to your account</li>
                    <li>Priority support</li>
                </ul>
            </div>
        </div>

        <?php if ($msPlansTrialDays > 0) : ?>
            <p class="ms-plans__note">No card needed, and nothing to cancel. One trial per email address. After the trial, stay on Pro with a <a href="/pricing.php">monthly, yearly or one-time plan</a>, or a voucher code.</p>
        <?php else : ?>
            <p class="ms-plans__note">Pro is a <a href="/pricing.php">monthly, yearly or one-time plan</a>, or a voucher code.</p>
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
<?php if ($msPlansPrice !== null) : ?>
<!-- Data for assets/js/landing-price.js. json_encode's HEX flags keep "<", ">",
     "&" and quotes from ending this element early. Both scripts are deferred,
     so Paddle.js has run before landing-price.js does. -->
<script type="application/json" id="ms-landing-price-config"><?php echo json_encode($msPlansPrice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="https://cdn.paddle.com/paddle/v2/paddle.js" defer></script>
<script src="/assets/js/landing-price.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/landing-price.js') ?: 1; ?>" defer></script>
<?php endif; ?>
