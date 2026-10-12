<?php
/**
 * Mail Shield — after checkout. pricing.js sends Paddle Checkout's successUrl
 * here once a payment completes.
 *
 * Deliberately claims nothing about the account: Pro is granted asynchronously
 * by paddle_webhook.php, and only for a payment it can link to an account, so
 * this page only confirms the payment and points at the receipt Paddle emails. noindex — it
 * is only meaningful straight after a checkout.
 *
 * Built on the marketing shell, so its language comes from the URL like the other
 * public-shell pages (/sv/welcome.php); pricing.php hands Paddle the URL in the
 * visitor's language. Deliberately not in sitemap.php: it is noindex.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$msPage = [
    'title'       => t('welcome.meta.title'),
    'description' => t('welcome.meta.desc'),
    'path'        => '/welcome.php',
    'robots'      => 'noindex, nofollow',
    'analytics'   => false,
];

$msNavAnchors = false;

require 'partials/brand.php';
require 'partials/public_head.php';
require 'partials/public_nav.php';

$welcomeSignedIn = !empty($_SESSION['pro_user_id']);
?>
<main id="main">
    <section class="ms-section">
        <div class="ms-container--narrow">
            <h1 class="ms-h1"><?php echo te('welcome.heading'); ?></h1>
            <p class="ms-lede ms-welcome__body"><?php echo te('welcome.body'); ?></p>

            <div class="ms-welcome__actions">
                <?php if ($welcomeSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php"><?php echo te('welcome.cta_inbox'); ?></a>
                <?php else : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="<?php echo htmlspecialchars(i18nUrl('/pro_login.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('welcome.cta_login'); ?></a>
                    <a class="ms-btn ms-btn--secondary ms-btn--lg" href="<?php echo htmlspecialchars(i18nUrl('/register.php?plan=regular'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo te('welcome.cta_create'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?php require 'partials/public_footer.php'; ?>
