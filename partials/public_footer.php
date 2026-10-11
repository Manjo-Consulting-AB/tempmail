<?php
/**
 * Mail Shield public footer. Spec: documentaion/REDESIGN_BRIEF.md §8.
 *
 * Closes the .ms-page wrapper that partials/public_head.php opens, so the two
 * are only valid as a pair. There is no Contact link: the only contact page in
 * the app (pro_contact.php) requires a login.
 *
 * The three Legal links are required by Paddle, our reseller, before it
 * approves the domain; pricing.php links the same three pages. Pricing sits
 * with them (#347) so a reviewer can find the plans and prices; it is not in
 * the nav or sitemap.php until the switch to production (#280 §6).
 */

if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit;
}

$msFooterYear    = date('Y');
$msFooterVersion = (string) ($config['app']['version'] ?? '');

$msFooterEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

// Language switcher (i18n.php): the languages this page is offered in, shown
// only when there is more than one. Each link is the same page in that
// language — never a cookie or a redirect, so every version keeps its own URL.
$msFooterLocales = [];
if (function_exists('i18nPageLocales')) {
    $msFooterEnabled = $config['i18n']['enabled'] ?? ['en'];
    $msFooterPath    = (string) ($msPage['path'] ?? '/');
    $msFooterLocales = i18nPageLocales($msFooterPath, $msFooterEnabled);
}
?>
<footer class="ms-footer">
    <div class="ms-container ms-footer__inner">
        <p class="ms-footer__trust"><?php echo te('footer.trust', ['company' => 'Manjo Consulting AB']); ?></p>

        <div class="ms-footer__cols">
            <div class="ms-footer__col">
                <h2 class="ms-footer__heading"><?php echo te('footer.product'); ?></h2>
                <ul class="ms-footer__links">
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/register.php?plan=regular')); ?>"><?php echo te('footer.create_inbox'); ?></a></li>
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/pro_login.php')); ?>"><?php echo te('footer.login'); ?></a></li>
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/temporary-email.php')); ?>"><?php echo te('footer.temporary_email'); ?></a></li>
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/faq.php')); ?>"><?php echo te('footer.faq'); ?></a></li>
                </ul>
            </div>

            <div class="ms-footer__col">
                <h2 class="ms-footer__heading"><?php echo te('footer.more'); ?></h2>
                <ul class="ms-footer__links">
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/blog.php')); ?>"><?php echo te('footer.blog'); ?></a></li>
                </ul>
            </div>

            <div class="ms-footer__col">
                <h2 class="ms-footer__heading"><?php echo te('footer.legal'); ?></h2>
                <ul class="ms-footer__links">
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/pricing.php')); ?>"><?php echo te('footer.pricing'); ?></a></li>
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/terms.php')); ?>"><?php echo te('footer.terms'); ?></a></li>
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/privacy.php')); ?>"><?php echo te('footer.privacy'); ?></a></li>
                    <li><a href="<?php echo $msFooterEsc(i18nUrl('/refund-policy.php')); ?>"><?php echo te('footer.refund'); ?></a></li>
                    <li><button type="button" class="ms-footer__cookie" data-ms-cookie-settings><?php echo te('footer.cookie_settings'); ?></button></li>
                </ul>
<?php if (count($msFooterLocales) > 1) : ?>
                <nav class="ms-footer__langs" aria-label="<?php echo te('lang.switcher.label'); ?>">
                    <?php foreach ($msFooterLocales as $msFooterCode) : ?>
                        <a href="<?php echo $msFooterEsc(i18nUrl($msFooterPath, $msFooterCode, $msFooterEnabled)); ?>"
                           hreflang="<?php echo $msFooterEsc($msFooterCode); ?>"
                           lang="<?php echo $msFooterEsc($msFooterCode); ?>"<?php echo $msFooterCode === i18nLocale() ? ' aria-current="true"' : ''; ?>><?php echo $msFooterEsc(i18nSupportedLocales()[$msFooterCode] ?? $msFooterCode); ?></a>
                    <?php endforeach; ?>
                </nav>
<?php endif; ?>
                <p class="ms-footer__legal">&copy; <?php echo $msFooterEsc($msFooterYear); ?> Manjo Consulting AB &middot; Mail Shield v<?php echo $msFooterEsc($msFooterVersion); ?></p>
            </div>
        </div>
    </div>
</footer>
</div><!-- /.ms-page -->
</body>
</html>
