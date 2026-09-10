<?php
/**
 * Mail Shield public footer. Spec: documentaion/REDESIGN_BRIEF.md §8.
 *
 * Closes the .ms-page wrapper that partials/public_head.php opens, so the two
 * are only valid as a pair. There is no Contact link: the only contact page in
 * the app (pro_contact.php) requires a login.
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
?>
<footer class="ms-footer">
    <div class="ms-container ms-footer__inner">
        <p class="ms-footer__trust">Mail Shield is operated by Manjo Consulting AB.</p>

        <div class="ms-footer__cols">
            <div class="ms-footer__col">
                <h2 class="ms-footer__heading">Product</h2>
                <ul class="ms-footer__links">
                    <li><a href="/register.php?plan=regular">Create your inbox</a></li>
                    <li><a href="/pro_login.php">Log in</a></li>
                    <li><a href="/temporary-email.php">Temporary email addresses</a></li>
                    <li><a href="/faq.php">FAQ</a></li>
                </ul>
            </div>

            <div class="ms-footer__col">
                <h2 class="ms-footer__heading">More</h2>
                <ul class="ms-footer__links">
                    <li><a href="/blog.php">Blog</a></li>
                </ul>
            </div>

            <div class="ms-footer__col">
                <h2 class="ms-footer__heading">Legal</h2>
                <p class="ms-footer__legal">&copy; <?php echo $msFooterEsc($msFooterYear); ?> Manjo Consulting AB &middot; Mail Shield v<?php echo $msFooterEsc($msFooterVersion); ?></p>
            </div>
        </div>
    </div>
</footer>
</div><!-- /.ms-page -->
</body>
</html>
