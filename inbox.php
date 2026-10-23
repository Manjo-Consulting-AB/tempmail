<?php
/**
 * Mail Shield - inbox reader.
 *
 * Split out of index.php so the landing page can be a pure marketing page
 * (see documentaion/REDESIGN_BRIEF.md section 10). The POST/AJAX action
 * surface deliberately stayed behind in index.php: assets/js/app.js posts to
 * the relative URL 'index.php', which resolves correctly from here.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}
if (function_exists('i18nUseAppLocale')) {
    i18nUseAppLocale($pdo, isset($_SESSION['pro_user_id']) ? (int) $_SESSION['pro_user_id'] : null, $_SERVER, $config['i18n'] ?? []);
}

// Hantera URL-parameter för direkt adress-access
$urlAddress = null;
$urlExpiresAt = null;
if (isset($_GET['address']) && !empty($_GET['address'])) {
    $urlAddress = trim($_GET['address']);
    // Validera att adressen bara innehåller giltiga tecken (hexadecimala)
    if (preg_match('/^[a-f0-9]{8,16}$/i', $urlAddress)) {
        // Kontrollera om adressen finns i databasen och hämta expires_at
        $stmt = $pdo->prepare("SELECT unique_address, expires_at FROM temp_emails WHERE unique_address = ?");
        $stmt->execute([$urlAddress]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $urlAddress = $row['unique_address'];
            $urlExpiresAt = $row['expires_at'];
        } else {
            $urlAddress = null; // Adressen finns inte
        }
    } else {
        $urlAddress = null; // Ogiltigt format
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(i18nLocale(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#FAFAF9">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo te('app.common.title'); ?></title>
<?php require __DIR__ . '/partials/favicons.php'; ?>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
    
    <meta name="description" content="<?php echo te('app.inbox.meta_desc', ['hours' => 24]); ?>">
    
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-envelope"></i> Mail Shield</h1>
            <p class="lead"><?php echo !empty($_SESSION['pro_user_id'] ?? null)
                ? te('app.inbox.lead_pro')
                : te('app.inbox.lead_free', ['hours' => 24]); ?></p>
            
            <?php require 'partials/nav.php'; ?>
            <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
            <script>
            (function(){
                try {
                    var proExpiry = <?php 
                        // Fetch pro_expires_at from session or database
                        $proExpires = null;
                        $isProAccount = false;
                        if (!empty($_SESSION['pro_user_id'])) {
                            try {
                                $pstmt = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
                                $pstmt->execute([$_SESSION['pro_user_id']]);
                                $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                                $proExpires = $prow['pro_expires_at'] ?? null;
                                $isProAccount = proUserIsPro((int)$_SESSION['pro_user_id']);
                            } catch (Exception $e) {}
                        }
                        echo json_encode(['expires' => $proExpires, 'isPro' => $isProAccount]);
                    ?>;
                    // The four texts, in this request's language ({date} is filled in below).
                    var L = <?php echo json_encode([
                        'free' => t('app.common.expiry_free'),
                        'lifetime' => t('app.common.expiry_lifetime'),
                        'expired' => t('app.common.expiry_expired', ['date' => '{date}']),
                        'expires' => t('app.common.expiry_expires', ['date' => '{date}']),
                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
                    if (!proExpiry.isPro) {
                        document.getElementById('proExpiryLine').textContent = L.free;
                        return;
                    }
                    if (!proExpiry.expires) {
                        document.getElementById('proExpiryLine').textContent = L.lifetime;
                        return;
                    }
                    var d = new Date(String(proExpiry.expires).replace(' ', 'T') + 'Z');
                    var dd = String(d.getUTCDate()).padStart(2, '0');
                    var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                    var yyyy = d.getUTCFullYear();
                    var dateOnly = dd + '/' + mm + '/' + yyyy;
                    var now = new Date();
                    var diffMs = d - now;
                    if (diffMs <= 0) {
                        document.getElementById('proExpiryLine').textContent = L.expired.replace('{date}', dateOnly);
                        document.getElementById('proExpiryLine').style.color = '#ff6b6b';
                    } else {
                        document.getElementById('proExpiryLine').textContent = L.expires.replace('{date}', dateOnly);
                    }
                } catch (e) {}
            })();
            </script>
            <?php endif; ?>
            <!-- navbar script moved to partial -->
        </div>

        <!-- Inbox: one frame, the way the landing hero mockup draws it -->
        <main class="ms-inbox">
            <div class="ms-inbox__frame">
                <!-- Empty state (visas tills adress är skapad) -->
                <div id="initial-generator" class="ms-inbox__empty">
                    <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
                        <h2 class="ms-inbox__empty-title"><?php echo te('app.common.click_to_get'); ?></h2>
                        <button id="generateBtn" class="btn btn-primary btn-lg">
                            <i class="fas fa-magic" aria-hidden="true"></i> <?php echo te('app.common.get_address'); ?>
                        </button>
                    <?php else: ?>
                        <h2 class="ms-inbox__empty-title"><?php echo te('app.inbox.account_required'); ?></h2>
                        <a href="/register.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus" aria-hidden="true"></i> <?php echo te('app.inbox.create_account'); ?>
                        </a>
                        <p class="ms-inbox__empty-hint">
                            <a href="/pro_login.php" rel="nofollow noreferrer"><?php echo te('app.inbox.have_account'); ?></a>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- E-postadress display (visas när adress är skapad) -->
                <div class="email-container d-none">
                    <div class="ms-inbox__head">
                        <div class="ms-inbox__id">
                            <p class="ms-eyebrow ms-inbox__label"><?php echo te('app.common.address_label'); ?></p>
                            <h2 class="email-address ms-inbox__addr" id="currentEmail" tabindex="0"></h2>
                            <p class="ms-inbox__meta">
                                <span id="privacyIndicator" class="d-none ms-inbox__privacy"><i class="fas fa-lock" aria-hidden="true"></i> <span id="privacyText"><?php echo te('app.common.privacy_private'); ?></span></span>
                                <span class="ms-inbox__validity"><i class="fas fa-clock" aria-hidden="true"></i> <span id="validityText"><?php echo te('app.common.validity', ['hours' => 24]); ?></span></span>
                            </p>
                        </div>

                        <div class="ms-inbox__actions">
                            <button id="copyBtn" class="btn btn-primary">
                                <i class="fas fa-copy" aria-hidden="true"></i> <?php echo te('app.common.copy_address'); ?>
                            </button>
                            <button id="refreshBtn" class="btn btn-outline-secondary">
                                <i class="fas fa-sync-alt" aria-hidden="true"></i> <?php echo te('app.common.refresh'); ?>
                            </button>
                            <button id="shareBtn" class="btn btn-outline-secondary">
                                <i class="fas fa-share-alt" aria-hidden="true"></i> <?php echo te('app.common.share_link'); ?>
                            </button>
                            <button id="newAddressBtn" class="btn btn-outline-secondary">
                                <i class="fas fa-plus" aria-hidden="true"></i> <?php echo te('app.common.new_address'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="ms-inbox__listbar">
                        <div class="ms-inbox__count">
                            <span id="emailCount">0</span>
                            <span><?php echo te('app.common.messages'); ?></span>
                            <button id="imageToggle" class="btn btn-sm btn-outline-secondary" title="<?php echo te('app.common.block_images_title'); ?>">
                                <i class="fas fa-image" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="ms-inbox__freshness">
                            <span class="status-indicator"><i class="fas fa-circle" aria-hidden="true"></i> <?php echo te('app.common.status_ready'); ?></span>
                            <span class="ms-inbox__updated"><?php echo tHtml('app.common.last_updated', [], ['time' => '<span id="lastUpdate">' . te('app.common.never') . '</span>']); ?></span>
                        </div>
                    </div>

                    <!-- E-postlista -->
                    <div class="email-list" id="emailList">
                        <!-- E-postmeddelanden läses in här via JavaScript -->
                    </div>
                </div>

                <?php if (!empty($_SESSION['pro_user_id'] ?? null)) : ?>
                <!-- Statistik: the signed-in account's own numbers (get_stats in index.php). A logged-out visitor gets none - the system-wide totals live on the landing page. -->
                <div class="ms-inbox__stats" id="userStats">
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsEmails">0</span>
                        <span class="ms-inbox__stat-l"><?php echo te('app.common.stat_emails'); ?></span>
                    </div>
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsReceived24h">0</span>
                        <span class="ms-inbox__stat-l"><?php echo te('app.common.stat_received', ['hours' => 24]); ?></span>
                    </div>
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsAddresses">0</span>
                        <span class="ms-inbox__stat-l"><?php echo te('app.common.stat_addresses'); ?></span>
                    </div>
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsStorage">&ndash;</span>
                        <span class="ms-inbox__stat-l"><?php echo te('app.common.stat_storage'); ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- E-post Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-main modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailModalLabel"><?php echo te('app.common.modal_title'); ?></h5>
                    <div class="d-flex align-items-center">
                        <button id="modalShowImagesBtn" type="button" class="btn btn-sm btn-outline-secondary me-2" title="<?php echo te('app.common.show_images_title'); ?>"><?php echo te('app.common.show_images'); ?></button>
                        <button type="button" class="btn-close" aria-label="<?php echo te('app.common.close'); ?>"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong><?php echo te('app.common.from'); ?></strong></div>
                        <div class="col-sm-9" id="emailFrom"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong><?php echo te('app.common.to'); ?></strong></div>
                        <div class="col-sm-9" id="emailTo"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong><?php echo te('app.common.date'); ?></strong></div>
                        <div class="col-sm-9" id="emailDate"></div>
                    </div>
                    <hr>
                    <div class="email-content" id="emailContent">
                        <!-- Email content loaded here -->
                    </div>
                    <div id="emailAttachments" class="mt-3" style="display:none">
                        <h6><?php echo te('app.common.attachments'); ?></h6>
                        <!-- Attachment links injected here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"><?php echo te('app.common.close'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pro Profile Modal -->
    <div class="modal fade" id="proProfileModal" tabindex="-1" aria-labelledby="proProfileLabel" aria-hidden="true">
        <div class="modal-dialog modal-main modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="proProfileLabel"><?php echo te('app.inbox.profile_title'); ?></h5>
                    <button type="button" class="btn-close" aria-label="<?php echo te('app.common.close'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div id="proProfileAlert"></div>
                    <form id="proProfileForm">
                        <div class="mb-3">
                            <label class="form-label"><?php echo te('app.inbox.profile_email'); ?></label>
                            <input type="email" class="form-control" id="proEmail" />
                        </div>
                        <div class="mb-3">
                            <button type="button" id="saveProfileEmailBtn" class="btn btn-primary"><?php echo te('app.inbox.save_email'); ?></button>
                        </div>

                        <hr>
                        <h6><?php echo te('app.inbox.password_heading'); ?></h6>
                        <div id="proPasswordCurrentGroup" class="mb-3 d-none">
                            <label class="form-label"><?php echo te('app.inbox.current_password'); ?></label>
                            <input type="password" class="form-control" id="proPasswordCurrent" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo te('app.inbox.new_password'); ?></label>
                            <input type="password" class="form-control" id="proPassword" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo te('app.inbox.confirm_password'); ?></label>
                            <input type="password" class="form-control" id="proPasswordConfirm" />
                        </div>
                        <div class="mb-3">
                            <button type="button" id="saveProfilePasswordBtn" class="btn btn-secondary"><?php echo te('app.inbox.set_password'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center mt-5 py-4">
        <div class="container">
            <p class="text-light mb-0">
                <small><?php echo te('app.common.footer', ['version' => (string) ($config['app']['version'] ?? '')]); ?></small>
            </p>
            <p class="text-light mb-0 mt-1">
                <small>&copy; <?php echo date('Y'); ?> Manjo Consulting AB</small>
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <?php require __DIR__ . '/partials/app_locale_js.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/site-controls.js"></script>
<?php
// The scripts' own strings (partials/i18n_js.php, read by msT() in assets/js/app.js).
$msI18nJs = ['app.'];
require __DIR__ . '/partials/i18n_js.php';
?>
    <script>
        // Konfigurations-objekt för JavaScript
        window.tempMailConfig = {
            domain: '<?php echo $config['email']['domain']; ?>',
            refreshRate: 10000,
            maxEmailPreview: 150,
            urlAddress: <?php echo $urlAddress ? "'" . htmlspecialchars($urlAddress, ENT_QUOTES) . "'" : 'null'; ?>,
            urlExpiresAt: <?php echo $urlExpiresAt ? "'" . htmlspecialchars($urlExpiresAt, ENT_QUOTES) . "'" : 'null'; ?>,
            isPro: <?php echo !empty($_SESSION['pro_user_id']) ? 'true' : 'false'; ?>
        };
    </script>
    <script src="assets/js/app.js?v=<?php echo file_exists(__DIR__ . '/assets/js/app.js') ? filemtime(__DIR__ . '/assets/js/app.js') : time(); ?>"></script>
    <script>
    (function(){
        // No personal-address handlers on index; profile page contains those controls now.
    })();
    </script>
</body>
