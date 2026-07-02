<?php
// Centralized navbar partial for TempMail
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
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
?>
<nav class="header-navbar">
    <div class="navbar-content">
        <div class="navbar-left">
            <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
                <span class="navbar-user-info">Signed in as <span class="badge bg-light text-dark"><?php echo htmlspecialchars($_SESSION['pro_user_email'] ?? ''); ?></span></span>
                <span id="proExpiryLine" class="navbar-pro-status"></span>
            <?php else: ?>
                <span class="navbar-user-info"><i class="fas fa-info-circle"></i> Help & Information</span>
            <?php endif; ?>
        </div>

        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle menu">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>

        <div class="navbar-right" id="navbarMenu">
            <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
            <a href="pro.php" class="<?php echo tm_nav_active('pro.php', $current); ?>" title="Home">
                <i class="fas fa-home"></i> <span class="nav-text">Home</span>
            </a>
            <?php else: ?>
            <a href="index.php" class="<?php echo tm_nav_active('index.php', $current); ?>" title="Home">
                <i class="fas fa-home"></i> <span class="nav-text">Home</span>
            </a>
            <?php endif; ?>
            <a href="blog.php" class="<?php echo tm_nav_active('blog.php', $current); ?>" title="Blog">
                <i class="fas fa-book-open"></i> <span class="nav-text">Blog</span>
            </a>
            <a href="faq.php" class="<?php echo tm_nav_active('faq.php', $current); ?>" title="FAQ">
                <i class="fas fa-question-circle"></i> <span class="nav-text">FAQ</span>
            </a>

            <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
                <a href="client_agent_manage.php" class="<?php echo tm_nav_active('client_agent_manage.php', $current); ?>" title="Client Agent">
                    <i class="fas fa-robot"></i> <span class="nav-text">Client Agent</span>
                </a>
                <a href="pro_profile_page.php" class="<?php echo tm_nav_active('pro_profile_page.php', $current); ?>" title="Profile">
                    <i class="fas fa-cog"></i> <span class="nav-text">Settings</span>
                </a>
                <a href="pro_contact.php" class="<?php echo tm_nav_active('pro_contact.php', $current); ?>" title="Contact">
                    <i class="fas fa-envelope"></i> <span class="nav-text">Contact</span>
                </a>
                <a href="pro_logout.php" class="navbar-link navbar-logout" title="Log out">
                    <i class="fas fa-sign-out-alt"></i> <span class="nav-text">Log out</span>
                </a>
            <?php else: ?>
                <a href="pro_login.php" class="<?php echo tm_nav_active('pro_login.php', $current); ?>" title="Pro login">
                    <i class="fas fa-user-lock"></i> <span class="nav-text">Pro</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>


<script>
document.getElementById('hamburgerBtn')?.addEventListener('click', function(){
    var menu = document.getElementById('navbarMenu'); this.classList.toggle('active'); menu.classList.toggle('show');
});
document.addEventListener('click', function(e){ var menu = document.getElementById('navbarMenu'); var btn = document.getElementById('hamburgerBtn'); if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) { menu.classList.remove('show'); btn.classList.remove('active'); } });
</script>
