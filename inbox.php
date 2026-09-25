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
<html lang="en">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-BFX6EC3575"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);} 
        gtag('js', new Date());
        gtag('config', 'G-BFX6EC3575');
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#FAFAF9">
    <meta name="robots" content="noindex, nofollow">
    <title>Your inbox · Mail Shield</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link rel="manifest" href="/site.webmanifest">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
    
    <meta name="description" content="Create timed addresses that are automatically deleted after 24 hours. Safe and easy to use.">
    
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
                ? 'Timed addresses for Pro users. Default lifetime applied to new addresses.' 
                : 'Timed addresses that are deleted after 24 hours'; ?></p>
            
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
                    if (!proExpiry.isPro) {
                        document.getElementById('proExpiryLine').textContent = 'Free';
                        return;
                    }
                    if (!proExpiry.expires) {
                        document.getElementById('proExpiryLine').textContent = 'Pro: Lifetime';
                        return;
                    }
                    var d = new Date(proExpiry.expires + ' UTC');
                    var dd = String(d.getUTCDate()).padStart(2, '0');
                    var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                    var yyyy = d.getUTCFullYear();
                    var dateOnly = dd + '/' + mm + '/' + yyyy;
                    var now = new Date();
                    var diffMs = d - now;
                    if (diffMs <= 0) {
                        document.getElementById('proExpiryLine').textContent = 'Pro expired on ' + dateOnly;
                        document.getElementById('proExpiryLine').style.color = '#ff6b6b';
                    } else {
                        document.getElementById('proExpiryLine').textContent = 'Pro expires: ' + dateOnly;
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
                        <h2 class="ms-inbox__empty-title">Click to get your timed address</h2>
                        <button id="generateBtn" class="btn btn-primary btn-lg">
                            <i class="fas fa-magic" aria-hidden="true"></i> Get Email Address
                        </button>
                    <?php else: ?>
                        <h2 class="ms-inbox__empty-title">An account is required to create a timed address</h2>
                        <a href="/register.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus" aria-hidden="true"></i> Create a free account
                        </a>
                        <p class="ms-inbox__empty-hint">
                            <a href="/pro_login.php" rel="nofollow noreferrer">Already have an account? Log in</a>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- E-postadress display (visas när adress är skapad) -->
                <div class="email-container d-none">
                    <div class="ms-inbox__head">
                        <div class="ms-inbox__id">
                            <p class="ms-eyebrow ms-inbox__label">Your address</p>
                            <h2 class="email-address ms-inbox__addr" id="currentEmail" tabindex="0"></h2>
                            <p class="ms-inbox__meta">
                                <span id="privacyIndicator" class="d-none ms-inbox__privacy"><i class="fas fa-lock" aria-hidden="true"></i> <span id="privacyText">Private</span></span>
                                <span class="ms-inbox__validity"><i class="fas fa-clock" aria-hidden="true"></i> <span id="validityText">Valid for 24 hours</span></span>
                            </p>
                        </div>

                        <div class="ms-inbox__actions">
                            <button id="copyBtn" class="btn btn-primary">
                                <i class="fas fa-copy" aria-hidden="true"></i> Copy address
                            </button>
                            <button id="refreshBtn" class="btn btn-outline-secondary">
                                <i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh
                            </button>
                            <button id="shareBtn" class="btn btn-outline-secondary">
                                <i class="fas fa-share-alt" aria-hidden="true"></i> Share link
                            </button>
                            <button id="newAddressBtn" class="btn btn-outline-secondary">
                                <i class="fas fa-plus" aria-hidden="true"></i> New address
                            </button>
                        </div>
                    </div>

                    <div class="ms-inbox__listbar">
                        <div class="ms-inbox__count">
                            <span id="emailCount">0</span>
                            <span>messages</span>
                            <button id="imageToggle" class="btn btn-sm btn-outline-secondary" title="Blockera externa bilder">
                                <i class="fas fa-image" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="ms-inbox__freshness">
                            <span class="status-indicator"><i class="fas fa-circle" aria-hidden="true"></i> Ready</span>
                            <span class="ms-inbox__updated">Last updated: <span id="lastUpdate">Never</span></span>
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
                        <span class="ms-inbox__stat-l">Emails in your inbox</span>
                    </div>
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsReceived24h">0</span>
                        <span class="ms-inbox__stat-l">Received last 24 h</span>
                    </div>
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsAddresses">0</span>
                        <span class="ms-inbox__stat-l">Active addresses</span>
                    </div>
                    <div class="ms-inbox__stat">
                        <span class="ms-inbox__stat-n" id="statsStorage">&ndash;</span>
                        <span class="ms-inbox__stat-l">Storage used</span>
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
                    <h5 class="modal-title" id="emailModalLabel">Email Message</h5>
                    <div class="d-flex align-items-center">
                        <button id="modalShowImagesBtn" type="button" class="btn btn-sm btn-outline-secondary me-2" title="Visa bilder i detta meddelande">Visa bilder</button>
                        <button type="button" class="btn-close" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong>From:</strong></div>
                        <div class="col-sm-9" id="emailFrom"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong>To:</strong></div>
                        <div class="col-sm-9" id="emailTo"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"><strong>Date:</strong></div>
                        <div class="col-sm-9" id="emailDate"></div>
                    </div>
                    <hr>
                    <div class="email-content" id="emailContent">
                        <!-- Email content loaded here -->
                    </div>
                    <div id="emailAttachments" class="mt-3" style="display:none">
                        <h6>Attachments</h6>
                        <!-- Attachment links injected here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pro Profile Modal -->
    <div class="modal fade" id="proProfileModal" tabindex="-1" aria-labelledby="proProfileLabel" aria-hidden="true">
        <div class="modal-dialog modal-main modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="proProfileLabel">Profile</h5>
                    <button type="button" class="btn-close" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="proProfileAlert"></div>
                    <form id="proProfileForm">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="proEmail" />
                        </div>
                        <div class="mb-3">
                            <button type="button" id="saveProfileEmailBtn" class="btn btn-primary">Save email</button>
                        </div>

                        <hr>
                        <h6>Set a password for direct access</h6>
                        <div id="proPasswordCurrentGroup" class="mb-3 d-none">
                            <label class="form-label">Current password</label>
                            <input type="password" class="form-control" id="proPasswordCurrent" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New password</label>
                            <input type="password" class="form-control" id="proPassword" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm password</label>
                            <input type="password" class="form-control" id="proPasswordConfirm" />
                        </div>
                        <div class="mb-3">
                            <button type="button" id="saveProfilePasswordBtn" class="btn btn-secondary">Set password</button>
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
                <small>Mail Shield · Operated by Manjo Consulting AB · v <?php echo htmlspecialchars($config['app']['version'] ?? ''); ?></small>
            </p>
            <p class="text-light mb-0 mt-1">
                <small>&copy; <?php echo date('Y'); ?> Manjo Consulting AB</small>
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/site-controls.js"></script>
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
