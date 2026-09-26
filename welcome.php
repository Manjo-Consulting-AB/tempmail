<?php
/**
 * Mail Shield — after checkout. pricing.js sends Paddle Checkout's successUrl
 * here once a payment completes.
 *
 * Deliberately claims nothing about the account: Pro is granted asynchronously
 * by paddle_webhook.php, and only for a payment it can link to an account, so
 * this page only confirms the payment and points at the receipt Paddle emails. noindex — it
 * is only meaningful straight after a checkout.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$msPage = [
    'title'       => 'Thank you · Mail Shield',
    'description' => 'Your Mail Shield payment went through.',
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
            <h1 class="ms-h1">Thank you for your purchase</h1>
            <p class="ms-lede ms-welcome__body">Your payment went through. A receipt is on its way to your email from Paddle, which handles payments for Mail Shield.</p>

            <div class="ms-welcome__actions">
                <?php if ($welcomeSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro.php">Go to your inbox</a>
                <?php else : ?>
                    <a class="ms-btn ms-btn--primary ms-btn--lg" href="/pro_login.php">Log in</a>
                    <a class="ms-btn ms-btn--secondary ms-btn--lg" href="/register.php?plan=regular">Create your inbox</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?php require 'partials/public_footer.php'; ?>
