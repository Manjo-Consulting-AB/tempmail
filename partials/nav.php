<?php
/**
 * Mail Shield app navigation. Spec: documentaion/REDESIGN_BRIEF.md §5, §11.
 *
 * Shared by the pages that keep the Bootstrap bridge (inbox.php, pro.php,
 * pro_profile_page.php, client_agent_manage.php, pro_contact.php). The public
 * marketing pages use partials/public_nav.php instead — the two are separate on
 * purpose and must not be merged.
 *
 * Three things other code depends on and that must survive any change here:
 *   - tm_nav_active() and the `navbar-link active` class it returns.
 *   - #proExpiryLine, written into by inline scripts in index.php, pro.php,
 *     inbox.php, pro_contact.php and pro_profile_page.php.
 *   - #hamburgerBtn / #navbarMenu.
 *
 * The links carry the legacy `navbar-link` class because tm_nav_active()
 * returns it; the new look is applied by the `ms-appnav` rules in
 * assets/css/mailshield.css, scoped so they outrank assets/css/style.css
 * without that file being edited (brief §11).
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}

require_once __DIR__ . '/brand.php';

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
function tm_nav_active($name, $current) {
    return $name === $current ? 'navbar-link active' : 'navbar-link';
}

$pendingChangeCount = 0;
if (!empty($_SESSION['pro_user_id'] ?? null) && isset($pdo)) {
    try {
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM pending_profile_changes WHERE user_id = ? AND used = 0");
        $pendingStmt->execute([$_SESSION['pro_user_id']]);
        $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        $pendingChangeCount = (int)($pendingRow['cnt'] ?? 0);
    } catch (Exception $e) {
        $pendingChangeCount = 0;
    }
}

$msNavSignedIn = !empty($_SESSION['pro_user_id'] ?? null);
$msNavEmail    = (string) ($_SESSION['pro_user_email'] ?? '');
// The Admin link is shown only to the accounts in ADMIN_USER_IDS; the admin
// pages enforce the same gate themselves, so hiding the link is not the
// protection, only the menu staying clean for everyone else.
$msNavAdmin    = $msNavSignedIn && function_exists('isAdminUser')
    && isAdminUser((int) $_SESSION['pro_user_id']);
$msNavAdminActive = in_array($current, ['log_viewer.php', 'abuse_admin.php'], true);

$msNavEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<nav class="ms-appnav" aria-label="Application">
    <div class="ms-appnav__inner">
        <?php echo ms_logo([
            'href'  => $msNavSignedIn ? '/pro.php' : '/',
            'class' => 'ms-appnav__logo',
        ]); ?>

        <button type="button" class="ms-appnav__toggle" id="hamburgerBtn"
                aria-expanded="false" aria-controls="navbarMenu" aria-label="Menu">
            <span class="ms-appnav__toggle-line"></span>
            <span class="ms-appnav__toggle-line"></span>
            <span class="ms-appnav__toggle-line"></span>
        </button>

        <div class="ms-appnav__panel" id="navbarMenu">
            <div class="ms-appnav__links">
                <?php if ($msNavSignedIn) : ?>
                <a href="pro.php" class="<?php echo tm_nav_active('pro.php', $current); ?>">Home</a>
                <?php else: ?>
                <a href="index.php" class="<?php echo tm_nav_active('index.php', $current); ?>">Home</a>
                <?php endif; ?>
                <a href="blog.php" class="<?php echo tm_nav_active('blog.php', $current); ?>">Blog</a>
                <a href="faq.php" class="<?php echo tm_nav_active('faq.php', $current); ?>">FAQ</a>

                <?php if ($msNavSignedIn) : ?>
                    <a href="client_agent_manage.php" class="<?php echo tm_nav_active('client_agent_manage.php', $current); ?>">Client Agent</a>
                    <a href="pro_profile_page.php" class="<?php echo tm_nav_active('pro_profile_page.php', $current); ?>">Settings<?php if ($pendingChangeCount > 0) : ?><span class="ms-appnav__count"><?php echo (int) $pendingChangeCount; ?><span class="ms-visually-hidden"> pending changes</span></span><?php endif; ?></a>
                    <a href="pro_contact.php" class="<?php echo tm_nav_active('pro_contact.php', $current); ?>">Contact</a>
                    <?php if ($msNavAdmin) : ?>
                    <a href="log_viewer.php" class="<?php echo $msNavAdminActive ? 'navbar-link active' : 'navbar-link'; ?>"<?php echo $msNavAdminActive ? ' aria-current="page"' : ''; ?>>Admin</a>
                    <?php endif; ?>
                    <a href="pro_logout.php" class="navbar-link">Log out</a>
                <?php else: ?>
                    <a href="register.php" class="<?php echo tm_nav_active('register.php', $current); ?>">Sign up</a>
                    <a href="pro_login.php" class="<?php echo tm_nav_active('pro_login.php', $current); ?>">Pro</a>
                <?php endif; ?>
            </div>

            <?php if ($msNavSignedIn) : ?>
            <div class="ms-appnav__account">
                <span class="ms-appnav__email">Signed in as <span class="ms-appnav__email-addr"><?php echo $msNavEsc($msNavEmail); ?></span></span>
                <span id="proExpiryLine" class="ms-appnav__status"></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<script>
(function () {
    var btn = document.getElementById('hamburgerBtn');
    var panel = document.getElementById('navbarMenu');
    if (!btn || !panel) return;

    function isOpen() { return btn.getAttribute('aria-expanded') === 'true'; }
    function setOpen(open) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.classList.toggle('is-open', open);
    }
    function close(moveFocus) {
        if (!isOpen()) return;
        setOpen(false);
        if (moveFocus) btn.focus();
    }

    btn.addEventListener('click', function () { setOpen(!isOpen()); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(true); });
    // An outside click leaves focus where the user clicked; only Escape pulls it
    // back to the button.
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !btn.contains(e.target)) close(false);
    });
})();
</script>
