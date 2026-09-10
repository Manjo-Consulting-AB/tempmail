<?php
/**
 * Enkel dashboard för inloggade pro-användare
 */
require_once 'config.php';
session_start();

if (!isset($_SESSION['pro_user_id'])) {
    header('Location: pro_login.php');
    exit;
}
$email = $_SESSION['pro_user_email'];
// Fetch current pro user TTL preference
$proTtl = 1;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['pro_user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['ttl_days'])) {
        $proTtl = (int)$row['ttl_days'];
    }
} catch (Exception $e) {
    // ignore and keep default
}

// Fetch pro_expires_at for display
$proExpires = null;
try {
    $pstmt = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
    $pstmt->execute([$_SESSION['pro_user_id']]);
    $pr = $pstmt->fetch(PDO::FETCH_ASSOC);
    if ($pr) $proExpires = $pr['pro_expires_at'] ?? null;
} catch (Exception $e) {
    // ignore
}
$isProAccount = proUserIsPro((int)$_SESSION['pro_user_id']);

// Fetch the most recent active temporary email for this pro user (if any)
$initialAddress = null;
$initialExpires = null;
try {
    // Prefer 'unique_address' column if present, otherwise use 'address'
    $col = 'unique_address';
    $colStmt = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'unique_address'");
    if ($colStmt->rowCount() === 0) {
        $col = 'address';
    }
        // Detect presence of is_personal column and exclude personal addresses from the "temporary address" field
        $hasIsPersonal = false;
        try {
            $ipStmt = $pdo->query("SHOW COLUMNS FROM temp_emails LIKE 'is_personal'");
            $hasIsPersonal = ($ipStmt && $ipStmt->rowCount() > 0);
        } catch (Exception $_) {
            $hasIsPersonal = false;
        }

        $whereExtra = $hasIsPersonal ? 'AND is_personal = 0' : '';
    $ae = $pdo->prepare("SELECT $col AS local_part, expires_at FROM temp_emails WHERE pro_user_id = ? $whereExtra AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $ae->execute([$_SESSION['pro_user_id']]);
    $ar = $ae->fetch(PDO::FETCH_ASSOC);
    if ($ar && !empty($ar['local_part'])) {
        $initialAddress = $ar['local_part'];
        $initialExpires = $ar['expires_at'] ?? null;
    }
} catch (Exception $e) {
    // ignore
}

// Fetch pending profile changes for the logged-in user (unconfirmed)
$pendingChanges = [];
try {
    $pstmt = $pdo->prepare("SELECT id, action, data, created_at, expires_at FROM pending_profile_changes WHERE user_id = ? AND used = 0 ORDER BY created_at DESC");
    $pstmt->execute([$_SESSION['pro_user_id']]);
    $pendingChanges = $pstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
        <style>
        /* Quota reached pulse animation */
        @keyframes tm-pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }
        .quota-reached {
            animation: tm-pulse 900ms ease-in-out infinite;
            box-shadow: 0 0 0 6px rgba(220,53,69,0.08);
        }
        </style>
    <script>
    window.tempMailConfig = {
        domain: '<?php echo $config['email']['domain']; ?>',
        refreshRate: 10000,
        maxEmailPreview: 150,
        urlAddress: <?php echo json_encode($initialAddress); ?>,
        urlExpiresAt: <?php echo json_encode($initialExpires); ?>,
        proExpiresAt: <?php echo json_encode($proExpires); ?>,
        isProAccount: <?php echo json_encode($isProAccount); ?>
    };
    // Mark this page as served for a logged-in pro user
    window.tempMailConfig.isPro = true;
    // Personal-address blacklist (local parts) - keep in sync with server
    window.personalAddressBlacklist = <?php echo json_encode(array_values(['jj', 'roland'])); ?>;
    </script>
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-envelope"></i> Mail Shield</h1>
            <p class="lead">Temporary email addresses for pro users. Default lifetime applied to new addresses.</p>
            
            <?php require 'partials/nav.php'; ?>
            <script>
            (function(){
                try {
                    var ex = window.tempMailConfig && window.tempMailConfig.proExpiresAt ? window.tempMailConfig.proExpiresAt : null;
                    if (!(window.tempMailConfig && window.tempMailConfig.isProAccount)) {
                        document.getElementById('proExpiryLine').textContent = 'Free';
                        return;
                    }
                    if (!ex) {
                        document.getElementById('proExpiryLine').textContent = 'Pro: Lifetime';
                        return;
                    }
                    var d = new Date(ex + ' UTC');
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
                } catch (e) {
                    // ignore
                }
            })();
            
            
            </script>
        </div>

                        <!-- 'How It Works' removed from Pro dashboard (not shown to signed-in users) -->

                        <!-- Personal address UI removed from dashboard (moved to profile page) -->

                        <!-- Initial address generator (visas tills adress är skapad) -->
                        <div id="initial-generator" class="card fade-in">
                            <div class="card-body text-center">
                                <h4 class="mb-3">Click to get your temporary email address</h4>
                                <button id="generateBtn" class="btn btn-primary btn-lg">
                                    <i class="fas fa-magic"></i> Get Email Address
                                </button>
                            </div>
                        </div>

                        <?php if (!empty($pendingChanges)): ?>
                        <div class="card fade-in">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h3><i class="fas fa-hourglass-half"></i> Pending Profile Changes</h3>
                                <span class="badge bg-warning"><?php echo count($pendingChanges); ?> pending</span>
                            </div>
                            <div class="card-body">
                                <ul class="list-group">
                                    <?php foreach ($pendingChanges as $pc):
                                        $d = json_decode($pc['data'], true);
                                        $label = htmlspecialchars($pc['action']);
                                        $detail = '';
                                        if ($pc['action'] === 'update_email' && isset($d['new_email'])) {
                                            $detail = 'New email: ' . htmlspecialchars($d['new_email']);
                                        } elseif ($pc['action'] === 'set_password') {
                                            $detail = 'Password change pending confirmation';
                                        }
                                    ?>
                                    <li id="pending-item-<?php echo $pc['id']; ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo $label; ?></strong>
                                            <div class="small text-muted"><?php echo htmlspecialchars($detail); ?></div>
                                            <div class="small text-muted">Requested: <?php echo htmlspecialchars($pc['created_at']); ?></div>
                                            <div class="small text-muted">Expires: <?php echo htmlspecialchars($pc['expires_at']); ?></div>
                                            <a href="#" class="small d-block mt-1 text-decoration-none text-primary resend-pending-link" data-id="<?php echo $pc['id']; ?>">Resend confirmation email</a>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-danger btn-cancel-pending mt-2" data-id="<?php echo $pc['id']; ?>">Cancel</button>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- E-postadress display (visas när adress är skapad) -->
                        <div class="email-container d-none">
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h3><i class="fas fa-envelope-open"></i> Your Temporary Email Address</h3>
                                </div>
                                <div class="card-body">
                                    <div class="email-display">
                                        <h4 class="email-address" id="currentEmail" tabindex="0"></h4>
                                        <div class="email-info">
                                            <i class="fas fa-clock"></i> <span id="validityText">Valid for 24 hours</span>
                                        </div>
                                        <div class="mt-3 d-flex align-items-center" style="gap:8px;">
                                            <label for="ttlSelect" class="form-label mb-0" style="color:#fff; margin-right:8px;">Default lifetime:</label>
                                            <select id="ttlSelect" class="form-select" style="width:120px;">
                                                <?php for ($d=1;$d<=7;$d++): ?>
                                                    <option value="<?php echo $d; ?>" <?php echo ($d === $proTtl) ? 'selected' : ''; ?>><?php echo $d; ?> day<?php echo $d>1 ? 's' : ''; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <div id="ttlMsg" style="margin-left:10px; color:#9ecbff;"></div>
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-md-6 mb-3">
                                            <button id="copyBtn" class="btn btn-success w-100">
                                                <i class="fas fa-copy"></i> Copy Address
                                            </button>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <button id="refreshBtn" class="btn btn-outline-secondary w-100">
                                                <i class="fas fa-sync-alt"></i> Refresh
                                            </button>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <button id="shareBtn" class="btn btn-outline-secondary w-100">
                                                <i class="fas fa-share-alt"></i> Share Link
                                            </button>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <button id="generateBtn" class="btn btn-outline-secondary w-100">
                                                <i class="fas fa-plus"></i> New Address
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-end mt-3">
                                        <small class="text-muted">
                                            Last updated: <span id="lastUpdate">Never</span>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- E-postlista -->
                            <div class="card fade-in">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3><i class="fas fa-inbox"></i> Incoming Emails</h3>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-primary me-2" id="emailCount">0</span>
                                        <button id="imageToggle" class="btn btn-sm btn-outline-secondary" title="Blockera externa bilder">
                                            <i class="fas fa-image"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="email-list" id="emailList">
                                        <!-- E-postmeddelanden läses in här via JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Statistik -->
                        <div class="card fade-in">
                            <div class="card-header">
                                <h3><i class="fas fa-chart-bar"></i> System Statistics</h3>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-3 mb-3">
                                        <div class="stat-box">
                                            <i class="fas fa-inbox fa-2x text-info mb-2"></i>
                                            <h4 class="stat-number" id="statsTotal">0</h4>
                                            <p class="text-muted mb-0">Total Emails</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="stat-box">
                                            <i class="fas fa-envelope-open-text fa-2x text-primary mb-2"></i>
                                            <h4 class="stat-number" id="statsProcessed">0</h4>
                                            <p class="text-muted mb-0">Emails Processed</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="stat-box">
                                            <i class="fas fa-plus-circle fa-2x text-success mb-2"></i>
                                            <h4 class="stat-number" id="statsCreated">0</h4>
                                            <p class="text-muted mb-0">Addresses Created</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="stat-box">
                                            <i class="fas fa-paperclip fa-2x text-warning mb-2"></i>
                                            <h4 class="stat-number" id="statsAttachments">0</h4>
                                            <p class="text-muted mb-0">Attachments Processed</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-sync-alt"></i> 
                                        Statistics updated automatically every minute
                                    </small>
                                    <br>
                                    <div class="status-indicator mt-2">
                                        <i class="fas fa-circle"></i> Ready
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- E-post Modal -->
                    <div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-main modal-fullscreen-sm-down">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="emailModalLabel">Email Message</h5>
                                    <div class="d-flex align-items-center">
                                        <button id="modalShowImagesBtn" type="button" class="btn btn-sm btn-outline-secondary me-2" title="Visa bilder i detta meddelande">Visa bilder</button>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                    <script src="assets/js/app.js?v=<?php echo file_exists(__DIR__ . '/assets/js/app.js') ? filemtime(__DIR__ . '/assets/js/app.js') : time(); ?>"></script>
                    <script>
                    (function(){
                        function performSaveTtl(ttl) {
                            var $msg = $('#ttlMsg');
                            // Indicate saving (blue)
                            $msg.css('color', '#9ecbff').text('Saving...');
                            // Include currently shown address (if any) so backend can update its expires_at
                            var currentAddress = $('#currentEmail').text().trim();
                            $.post('pro_profile.php', {action: 'update_ttl', ttl: ttl, address: currentAddress}, function(res){
                                if (res && res.success) {
                                    // Show Saved in white for 2 seconds
                                    $msg.css('color', '#fff').text('Saved');
                                    if (res.expires_at) {
                                        // Parse server timestamp (YYYY-MM-DD HH:MM:SS) into JS Date
                                        try {
                                            var parsed = new Date(res.expires_at.replace(' ', 'T'));
                                            if (window.tempMailApp) {
                                                window.tempMailApp.expiresAt = parsed;
                                                if (typeof window.tempMailApp.startValidityCountdown === 'function') {
                                                    window.tempMailApp.startValidityCountdown();
                                                } else if (typeof window.tempMailApp.updateValidityText === 'function') {
                                                    window.tempMailApp.updateValidityText();
                                                } else {
                                                    $('#validityText').text('Valid until ' + res.expires_at);
                                                }
                                            } else {
                                                $('#validityText').text('Valid until ' + res.expires_at);
                                            }
                                        } catch (e) {
                                            $('#validityText').text('Valid until ' + res.expires_at);
                                        }
                                    }
                                    setTimeout(function(){ $msg.text('').css('color', '#9ecbff'); }, 2000);
                                } else {
                                    // Show error externally in red
                                    $msg.css('color', '#ff6b6b').text(res.error || 'Error');
                                }
                            }, 'json').fail(function(){
                                $msg.css('color', '#ff6b6b').text('Request failed');
                            });
                        }

                        // Auto-save when dropdown changes
                        $('#ttlSelect').on('change', function(){
                            var ttl = parseInt($(this).val(), 10) || 1;
                            performSaveTtl(ttl);
                        });

                        // Personal address functionality moved to profile page; removed from dashboard to avoid duplication.
                    })();
                    </script>
                </body>
                </html>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
