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
 *     this page then answers 500 rather than guessing an environment), and
 *     hands both to the browser as a JSON block.
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
 * test catalog never ends up in search results. The page is not in the nav or
 * sitemap.php yet — payment is being built step by step. A completed checkout
 * grants Pro through paddle_webhook.php (see paddle_sync.php).
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $paddle = paddleClientSettings($config);
} catch (RuntimeException $e) {
    logMessage('ERROR', 'pricing.php: Paddle is not configured', ['error' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Pricing is unavailable: Paddle is not configured on this server.';
    exit;
}

$tiers = require __DIR__ . '/pricing_tiers.php';

/**
 * ISO 3166-1 alpha-2 country from the CDN in front of the site, or null.
 * Vercel sends x-vercel-ip-country, Cloudflare cf-ipcountry (with XX for
 * "unknown" and T1 for Tor, neither of which is a country).
 */
function pricingDetectCountry(): ?string
{
    foreach (['HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_CF_IPCOUNTRY'] as $header) {
        $value = strtoupper(trim((string) ($_SERVER[$header] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $value) && $value !== 'XX' && $value !== 'T1') {
            return $value;
        }
    }
    return null;
}

$signedIn      = !empty($_SESSION['pro_user_id']);
$customerEmail = $signedIn ? (string) ($_SESSION['pro_user_email'] ?? '') : '';
$origin        = rtrim((string) ($config['email']['base_url'] ?? ''), '/');

$pricingConfig = [
    'environment' => $paddle['environment'],
    'token'       => $paddle['token'],
    'country'     => pricingDetectCountry(),
    'email'       => $customerEmail !== '' ? $customerEmail : null,
    'userId'      => $signedIn ? (int) $_SESSION['pro_user_id'] : null,
    'successUrl'  => $origin . '/welcome.php',
    'tiers'       => array_map(function (array $tier): array {
        return ['name' => $tier['name'], 'priceId' => $tier['priceId']];
    }, $tiers),
];

$msPage = [
    'title'       => 'Pricing · Mail Shield',
    'description' => 'Mail Shield plans and prices, shown in your local currency.',
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
?>
<main id="main">
    <section class="ms-section ms-pricing">
        <div class="ms-container">
            <h1 class="ms-h1">Plans and pricing</h1>
            <p class="ms-lede ms-pricing__lede">Subscribe monthly or yearly, or pay once for good. Prices are shown in your local currency, including any tax that applies where you are.</p>

            <div class="ms-pricing__toggle" role="group" aria-label="Billing period">
                <button type="button" class="ms-pricing__period" data-period="month" aria-pressed="true">Monthly</button>
                <button type="button" class="ms-pricing__period" data-period="year" aria-pressed="false">Yearly</button>
            </div>

            <p class="ms-pricing__error" id="ms-pricing-error" role="alert" hidden>Prices could not be loaded right now. Please reload the page to try again.</p>

            <div class="ms-pricing__grid">
                <?php foreach ($tiers as $index => $tier) :
                    $featured = !empty($tier['featured']);
                    $oneTime  = isset($tier['priceId']['once']);
                ?>
                    <div class="ms-card ms-pricing__card<?php echo $featured ? ' ms-pricing__card--featured' : ''; ?>">
                        <div class="ms-plans__head">
                            <h2 class="ms-h3"><?php echo $esc($tier['name']); ?></h2>
                            <?php if ($featured) : ?>
                                <span class="ms-chip">Most useful</span>
                            <?php endif; ?>
                        </div>
                        <p class="ms-plans__summary"><?php echo $esc($tier['description']); ?></p>

                        <p class="ms-pricing__price" aria-live="polite">
                            <span class="ms-pricing__amount" data-tier="<?php echo (int) $index; ?>">&hellip;</span>
                            <span class="ms-pricing__per"<?php echo $oneTime ? '' : ' data-per'; ?>><?php echo $oneTime ? 'one-time' : '/ month'; ?></span>
                        </p>

                        <?php if ($signedIn) : ?>
                            <button type="button" class="ms-btn <?php echo $featured ? 'ms-btn--primary' : 'ms-btn--secondary'; ?> ms-pricing__subscribe"
                                    data-tier="<?php echo (int) $index; ?>" disabled><?php echo $oneTime ? 'Buy' : 'Subscribe'; ?></button>
                        <?php else : ?>
                            <a class="ms-btn <?php echo $featured ? 'ms-btn--primary' : 'ms-btn--secondary'; ?> ms-pricing__subscribe-link"
                               href="/pro_login.php"><?php echo $oneTime ? 'Log in to buy' : 'Log in to subscribe'; ?></a>
                        <?php endif; ?>

                        <ul class="ms-plans__list">
                            <?php foreach ($tier['features'] as $feature) : ?>
                                <li><?php echo $esc($feature); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$signedIn) : ?>
                <p class="ms-plans__note">You need a Mail Shield account to subscribe, so the plan lands on the right inbox. No account yet? <a href="/register.php?plan=regular">Create one for free</a>, then come back here.</p>
            <?php endif; ?>
            <p class="ms-plans__note">Payments are handled by Paddle, our reseller, which also sends your receipt.</p>
        </div>
    </section>
</main>

<!-- Data for assets/js/pricing.js. json_encode's HEX flags keep "<", ">", "&"
     and quotes from ending this element early. -->
<script type="application/json" id="ms-pricing-config"><?php echo json_encode($pricingConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
<script src="/assets/js/pricing.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/pricing.js') ?: 1; ?>"></script>
<?php require 'partials/public_footer.php'; ?>
