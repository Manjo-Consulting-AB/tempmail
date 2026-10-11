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
if ($msPlansPrice !== null) {
    // landing-price.js fills {price} in this; the wording is the page's language.
    $msPlansPrice['fromTemplate'] = t('landing.plans.from_price');
}

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
    ? te('landing.plans.pro_6_signin')
    : te('landing.plans.pro_6_token');

// Numbers the bullets state, passed as placeholders so no catalog value carries
// one: the sticky cap (index.php `case 'create_personal'`), a free Timed address'
// 24 hours and the 1 to 7 days a Pro account can configure (`case 'generate'`).
$msPlansStickyMax = 10;
$msPlansFreeHours = 24;
$msPlansTtlMin = 1;
$msPlansTtlMax = 7;

// The two links in the notes under the cards, built from literals and escaped values.
$msPlansLinks = [
    'plans'     => '<a href="' . htmlspecialchars(i18nUrl('/pricing.php'), ENT_QUOTES, 'UTF-8') . '">',
    'plans_end' => '</a>',
];
?>
<section class="ms-section" id="plans">
    <div class="ms-container">
        <h2 class="ms-h2"><?php echo te('landing.plans.title'); ?></h2>
        <?php if ($msPlansTrialDays > 0) : ?>
            <p class="ms-plans__body"><?php echo te('landing.plans.body_trial', ['days' => $msPlansTrialDays]); ?></p>
        <?php else : ?>
            <p class="ms-plans__body"><?php echo te('landing.plans.body'); ?></p>
        <?php endif; ?>

        <div class="ms-plans__grid">
            <div class="ms-card ms-plans__card">
                <div class="ms-plans__head">
                    <h3 class="ms-h3"><?php echo te('landing.plans.free_title'); ?></h3>
                </div>
                <p class="ms-plans__summary"><?php echo te('landing.plans.free_summary'); ?></p>
                <p class="ms-plans__price"><span class="ms-plans__amount"><?php echo te('landing.plans.free_amount'); ?></span> <?php echo te('landing.plans.free_nocard'); ?></p>

                <ul class="ms-plans__list">
                    <li><?php echo te('landing.plans.free_1'); ?></li>
                    <li><?php echo te('landing.plans.free_2', ['hours' => $msPlansFreeHours]); ?></li>
                    <li><?php echo te('landing.plans.free_3', ['mb' => $msPlansQuotaFree]); ?></li>
                    <li><?php echo te('landing.plans.free_4'); ?></li>
                    <li><?php echo te('landing.plans.free_5'); ?></li>
                </ul>
            </div>

            <div class="ms-card ms-plans__card ms-plans__card--pro">
                <div class="ms-plans__head">
                    <h3 class="ms-h3"><?php echo te('landing.plans.pro_title'); ?></h3>
                    <span class="ms-chip"><?php echo $msPlansTrialDays > 0 ? te('landing.plans.chip_trial', ['days' => $msPlansTrialDays]) : te('landing.plans.chip'); ?></span>
                </div>
                <p class="ms-plans__summary"><?php echo te('landing.plans.pro_summary'); ?></p>
                <p class="ms-plans__price"><span class="ms-plans__amount"<?php echo $msPlansPrice !== null ? ' data-landing-price' : ''; ?>><?php echo te('landing.plans.price_fallback'); ?></span> <a href="<?php echo htmlspecialchars(i18nUrl('/pricing.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('landing.plans.see_prices'); ?></a></p>

                <ul class="ms-plans__list">
                    <li><?php echo te('landing.plans.pro_1', ['max' => $msPlansStickyMax]); ?></li>
                    <li><?php echo te('landing.plans.pro_2', ['min' => $msPlansTtlMin, 'max' => $msPlansTtlMax]); ?></li>
                    <li><?php echo te('landing.plans.pro_3', ['days' => $msPlansHoldDays, 'max' => $msPlansHoldMax]); ?></li>
                    <li><?php echo $msPlansQuotaRatio > 1 ? te('landing.plans.pro_4_ratio', ['mb' => $msPlansQuotaPro, 'ratio' => $msPlansQuotaRatio]) : te('landing.plans.pro_4', ['mb' => $msPlansQuotaPro]); ?></li>
                    <li><?php echo te('landing.plans.pro_5'); ?></li>
                    <li><?php echo $msPlansMcpText; ?></li>
                    <li><?php echo te('landing.plans.pro_7'); ?></li>
                    <li><?php echo te('landing.plans.pro_8'); ?></li>
                    <li><?php echo te('landing.plans.pro_9'); ?></li>
                    <li><?php echo te('landing.plans.pro_10'); ?></li>
                </ul>
            </div>
        </div>

        <?php if ($msPlansTrialDays > 0) : ?>
            <p class="ms-plans__note"><?php echo tHtml('landing.plans.note_trial', [], $msPlansLinks); ?></p>
        <?php else : ?>
            <p class="ms-plans__note"><?php echo tHtml('landing.plans.note', [], $msPlansLinks); ?></p>
        <?php endif; ?>

        <div class="ms-plans__actions">
            <?php if ($msPlansSignedIn) : ?>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php"><?php echo te('landing.plans.go_to_inbox'); ?></a>
            <?php else : ?>
                <a class="ms-btn ms-btn--primary ms-btn--lg" href="<?php echo htmlspecialchars(i18nUrl('/register.php?plan=regular'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $msPlansTrialDays > 0 ? te('landing.plans.start_trial', ['days' => $msPlansTrialDays]) : te('landing.plans.create_inbox'); ?></a>
                <a class="ms-btn ms-btn--secondary ms-btn--lg" href="<?php echo htmlspecialchars(i18nUrl('/register.php?plan=pro'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('landing.plans.get_pro'); ?></a>
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
