<?php
/**
 * Mail Shield — pricing. The paid plans (pricing_tiers.php) with
 * country-localised prices from Paddle and a Paddle overlay checkout.
 *
 * Division of labour:
 *
 *   - This file detects the visitor's country from CDN headers, validates the
 *     Paddle settings (paddleClientSettings() in config.php — it throws when
 *     PADDLE_ENVIRONMENT or PADDLE_CLIENT_TOKEN is missing or mismatched, and
 *     this page then answers 500 rather than guessing an environment), resolves
 *     pricing_tiers.php to that environment's catalog (paddleTiersForEnvironment()
 *     in paddle_sync.php — the file holds the sandbox and live price IDs side by
 *     side), and hands both to the browser as a JSON block.
 *   - assets/js/pricing.js loads nothing but Paddle.js: Paddle.PricePreview()
 *     for the prices and Paddle.Checkout.open() for Subscribe. It prints the
 *     formattedTotals strings Paddle returns and does no price math.
 *
 * Checkout is for signed-in accounts only: a guest sees the prices but gets a
 * log-in link instead of Subscribe, so every payment carries
 * custom_data.pro_user_id and paddle_sync.php can link it to the right
 * pro_users row (it never creates accounts).
 *
 * Only the client-side token (test_/live_) is ever printed here. There is no
 * server-side Paddle API key anywhere in this flow.
 *
 * Country: when no CDN header names a country, the JSON carries null and
 * pricing.js sends PricePreview no address at all, so Paddle geolocates the
 * visitor by IP. There is no sentinel country code sent to Paddle.
 *
 * Indexing follows the Paddle environment: a sandbox page is noindex, so the
 * test catalog never ends up in search results. The page is linked from the
 * footer, the landing plans card (#347) and the public nav, and sitemap.php
 * lists it on production only (#280 §6).
 *
 * Checkout is open only where it is real: on production, or for a sandbox
 * account named in PADDLE_SANDBOX_USER_IDS so the live site can still be tested
 * (#346). Everywhere else the prices are shown as usual but each card carries a
 * short note instead of Subscribe, so nobody is pointed at a checkout that would
 * only accept a test card. A completed checkout grants Pro through
 * paddle_webhook.php (see paddle_sync.php).
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';
require_once __DIR__ . '/paddle_sync.php';
require_once __DIR__ . '/pricing_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $paddle = paddleClientSettings($config);
    // Which catalog's price IDs the browser may preview: the plans carry both
    // environments and $paddle['environment'] — already validated above — picks
    // one, so a page can never show live prices under the sandbox token.
    $tiers  = paddleTiersForEnvironment(require __DIR__ . '/pricing_tiers.php', $paddle['environment']);
} catch (RuntimeException $e) {
    logMessage('ERROR', 'pricing.php: Paddle is not configured', ['error' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Pricing is unavailable: Paddle is not configured on this server.';
    exit;
}

$signedIn     = !empty($_SESSION['pro_user_id']);
$customerEmail = $signedIn ? (string) ($_SESSION['pro_user_email'] ?? '') : '';
$origin        = rtrim((string) ($config['email']['base_url'] ?? ''), '/');

// Checkout is offered only where it is genuine: on production, or on sandbox
// for the accounts named in PADDLE_SANDBOX_USER_IDS (#346). On sandbox anyone
// else sees the prices but a note instead of Subscribe — a checkout there would
// only take a Paddle test card, and a real payment would grant nothing.
$checkoutEnabled = $paddle['environment'] === 'production'
    || ($signedIn && in_array((int) $_SESSION['pro_user_id'], $config['paddle']['sandbox_user_ids'] ?? [], true));

$pricingConfig = [
    'environment'     => $paddle['environment'],
    'token'           => $paddle['token'],
    'country'         => pricingDetectCountry(),
    'email'           => $customerEmail !== '' ? $customerEmail : null,
    'userId'          => $signedIn ? (int) $_SESSION['pro_user_id'] : null,
    'checkoutEnabled' => $checkoutEnabled,
    'successUrl'      => $origin . '/welcome.php',
    'perMonth'        => t('pricing.per_month'),
    'perYear'         => t('pricing.per_year'),
    'locale'          => i18nLocale(),
    'tiers'           => array_map(function (array $tier): array {
        return ['name' => $tier['name'], 'priceId' => $tier['priceId']];
    }, $tiers),
];

$msPage = [
    'title'       => t('pricing.meta.title'),
    'description' => t('pricing.meta.description'),
    'path'        => '/pricing.php',
    'robots'      => $paddle['environment'] === 'production' ? 'index, follow' : 'noindex, nofollow',
];

$msNavAnchors = false;   // Features / How it works live on the landing page

require 'partials/brand.php';
require 'partials/public_head.php';
require 'partials/public_nav.php';

$esc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

// Referred account inside its window (epic #387 step 8). Fail-open: any
// problem, the flag being off or the migration missing shows nothing.
$referralNote = null;
// The money-back period in the note below: an existing literal of this page.
$moneyBackDays = 30;
if ($signedIn) {
    try {
        require_once __DIR__ . '/referrals.php';
        $referralCfg = referralSettings($config['referral'] ?? []);
        if ($referralCfg['enabled'] && tableHasColumn('referrals', 'status')) {
            $own = referralSummaryFor($pdo, (int) $_SESSION['pro_user_id'], time())['own'];
            if ($own !== null) {
                $referralNote = t('pricing.referral', [
                    'date'   => i18nDate((int) strtotime($own['window_ends_at'])),
                    'months' => 12 + $referralCfg['bonus_months'],
                ]);
            }
        }
    } catch (Throwable $e) {
        $referralNote = null;
    }
}
?>
<main id="main">
    <section class="ms-section ms-pricing">
        <div class="ms-container">
            <h1 class="ms-h1"><?php echo te('pricing.title'); ?></h1>
            <p class="ms-lede ms-pricing__lede"><?php echo te('pricing.lede'); ?></p>

            <div class="ms-pricing__toggle" role="group" aria-label="<?php echo te('pricing.toggle.label'); ?>">
                <button type="button" class="ms-pricing__period" data-period="month" aria-pressed="true"><?php echo te('pricing.toggle.monthly'); ?></button>
                <button type="button" class="ms-pricing__period" data-period="year" aria-pressed="false"><?php echo te('pricing.toggle.yearly'); ?></button>
            </div>

<?php if ($referralNote !== null) { echo '<p class="ms-plans__note">' . $esc($referralNote) . '</p>'; } ?>
            <p class="ms-pricing__error" id="ms-pricing-error" role="alert" hidden><?php echo te('pricing.error'); ?></p>

            <div class="ms-pricing__grid">
                <?php foreach ($tiers as $index => $tier) :
                    $featured = !empty($tier['featured']);
                    $oneTime  = isset($tier['priceId']['once']);
                ?>
                    <div class="ms-card ms-pricing__card<?php echo $featured ? ' ms-pricing__card--featured' : ''; ?>">
                        <div class="ms-plans__head">
                            <h2 class="ms-h3"><?php echo $esc($tier['name']); ?></h2>
                            <?php if ($featured) : ?>
                                <span class="ms-chip"><?php echo te('pricing.chip'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="ms-plans__summary"><?php echo te($tier['description']); ?></p>

                        <p class="ms-pricing__price" aria-live="polite">
                            <span class="ms-pricing__amount" data-tier="<?php echo (int) $index; ?>">&hellip;</span>
                            <span class="ms-pricing__per"<?php echo $oneTime ? '' : ' data-per'; ?>><?php echo $oneTime ? te('pricing.one_time') : te('pricing.per_month'); ?></span>
                        </p>

                        <?php if (!$checkoutEnabled) : ?>
                            <p class="ms-pricing__closed"><?php echo te('pricing.closed'); ?></p>
                        <?php elseif ($signedIn) : ?>
                            <button type="button" class="ms-btn <?php echo $featured ? 'ms-btn--primary' : 'ms-btn--secondary'; ?> ms-pricing__subscribe"
                                    data-tier="<?php echo (int) $index; ?>" disabled><?php echo $oneTime ? te('pricing.button.buy') : te('pricing.button.subscribe'); ?></button>
                        <?php else : ?>
                            <a class="ms-btn <?php echo $featured ? 'ms-btn--primary' : 'ms-btn--secondary'; ?> ms-pricing__subscribe-link"
                               href="<?php echo $esc(i18nUrl('/pro_login.php')); ?>"><?php echo $oneTime ? te('pricing.link.login_buy') : te('pricing.link.login_subscribe'); ?></a>
                        <?php endif; ?>

                        <ul class="ms-plans__list">
                            <?php foreach ($tier['features'] as [$featureKey, $featureParams]) : ?>
                                <li><?php echo te($featureKey, $featureParams); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$signedIn && $checkoutEnabled) : ?>
                <p class="ms-plans__note"><?php echo tHtml('pricing.note.account', [], [
                    'register'     => '<a href="' . $esc(i18nUrl('/register.php?plan=regular')) . '">',
                    'register_end' => '</a>',
                ]); ?></p>
            <?php endif; ?>
            <p class="ms-plans__note"><?php echo te('pricing.note.paddle', ['days' => $moneyBackDays]); ?></p>
            <p class="ms-plans__note"><?php echo tHtml('pricing.note.legal', [], [
                'terms'       => '<a href="' . $esc(i18nUrl('/terms.php')) . '">',
                'terms_end'   => '</a>',
                'privacy'     => '<a href="' . $esc(i18nUrl('/privacy.php')) . '">',
                'privacy_end' => '</a>',
                'refund'      => '<a href="' . $esc(i18nUrl('/refund-policy.php')) . '">',
                'refund_end'  => '</a>',
            ]); ?></p>
        </div>
    </section>
</main>

<!-- Data for assets/js/pricing.js. json_encode's HEX flags keep "<", ">", "&"
     and quotes from ending this element early. -->
<script type="application/json" id="ms-pricing-config"><?php echo json_encode($pricingConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
<script src="/assets/js/pricing.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/pricing.js') ?: 1; ?>"></script>
<?php require 'partials/public_footer.php'; ?>
