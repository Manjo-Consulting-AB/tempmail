<?php
/**
 * Mail Shield public navigation. Spec: documentaion/REDESIGN_BRIEF.md §8.
 *
 * Approved items only. Pricing links to pricing.php (#280 §6); the nav itself
 * shows no amount. Icons are inline SVG; the behaviour is vanilla JS with no
 * library dependency.
 *
 * The including page may set $msNavAnchors = false before the require (as
 * temporary-email.php does) when the "Features"/"How it works" anchors are not
 * on the current page; those links then point back at the landing page.
 *
 * A signed-in visitor still gets the marketing page — nothing here redirects.
 *
 * This partial emits the skip link, so it is the first focusable element in
 * <body> on every marketing page. It targets #main, which each page's own
 * <main id="main"> provides.
 */

if (!isset($config) || !is_array($config)) {
    http_response_code(500);
    exit;
}

require_once __DIR__ . '/brand.php';

$msNavSignedIn = !empty($_SESSION['pro_user_id']);
$msNavEmail    = (string) ($_SESSION['pro_user_email'] ?? '');

$msNavAnchors  = !isset($msNavAnchors) || (bool) $msNavAnchors;
$msNavFeatures = $msNavAnchors ? '#features' : i18nUrl('/') . '#features';
$msNavHow      = $msNavAnchors ? '#how-it-works' : i18nUrl('/') . '#how-it-works';

$msNavEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<a class="ms-skip" href="#main"><?php echo te('nav.skip'); ?></a>
<header class="ms-nav" id="ms-nav">
    <div class="ms-container ms-nav__inner">
        <?php echo ms_logo(['href' => i18nUrl('/'), 'class' => 'ms-nav__logo']); ?>

        <button type="button" class="ms-nav__toggle" id="ms-nav-toggle"
                aria-expanded="false" aria-controls="ms-nav-panel" aria-label="<?php echo te('nav.menu'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"
                 fill="none" aria-hidden="true" focusable="false">
                <path d="M3 6h14M3 10h14M3 14h14" fill="none" stroke="currentColor"
                      stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </button>

        <nav class="ms-nav__panel" id="ms-nav-panel" aria-label="<?php echo te('nav.main'); ?>" hidden>
            <ul class="ms-nav__links">
                <li><a href="<?php echo $msNavEsc($msNavFeatures); ?>"><?php echo te('nav.features'); ?></a></li>
                <li><a href="<?php echo $msNavEsc($msNavHow); ?>"><?php echo te('nav.how_it_works'); ?></a></li>
                <li><a href="<?php echo $msNavEsc(i18nUrl('/pricing.php')); ?>"><?php echo te('nav.pricing'); ?></a></li>
                <li><a href="<?php echo $msNavEsc(i18nUrl('/faq.php')); ?>"><?php echo te('nav.faq'); ?></a></li>
            </ul>

            <div class="ms-nav__actions">
                <?php if ($msNavSignedIn) : ?>
                    <?php if ($msNavEmail !== '') : ?>
                        <span class="ms-nav__user"><?php echo $msNavEsc($msNavEmail); ?></span>
                    <?php endif; ?>
                    <a class="ms-btn ms-btn--primary" href="/pro.php"><?php echo te('nav.go_to_inbox'); ?></a>
                <?php else : ?>
                    <a class="ms-nav__login" href="<?php echo $msNavEsc(i18nUrl('/pro_login.php')); ?>"><?php echo te('nav.login'); ?></a>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msNavEsc(i18nUrl('/register.php?plan=regular')); ?>"><?php echo te('nav.create_inbox'); ?></a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>

<script>
(function () {
    var nav = document.getElementById('ms-nav');
    var btn = document.getElementById('ms-nav-toggle');
    var panel = document.getElementById('ms-nav-panel');
    if (!nav || !btn || !panel) return;

    function isOpen() { return btn.getAttribute('aria-expanded') === 'true'; }
    function setOpen(open) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
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
    document.addEventListener('click', function (e) { if (!nav.contains(e.target)) close(false); });

    // Hairline only once the page has left the top of the document.
    var onScroll = function () { nav.classList.toggle('ms-nav--scrolled', window.scrollY > 0); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
})();
</script>
