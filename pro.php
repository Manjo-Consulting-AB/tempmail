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

// The mail domain, escaped, for the rail and the empty state. The JS config
// block below prints it unescaped exactly as it always has, so it is read a
// second time here rather than changing that block's output.
$msDomain = htmlspecialchars((string)($config['email']['domain'] ?? ''), ENT_QUOTES, 'UTF-8');
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
        <!-- Header. The nav (#116) carries the identity now, so the old page
             title and its lead paragraph are gone — the lead was a description
             of a setting, not a headline. #proExpiryLine comes from
             partials/nav.php, which the require below renders before the
             script that writes into it. -->
        <div class="header">
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

        <main class="ms-dash">
            <h1 class="ms-dash__title">Your inbox</h1>

            <div class="ms-dash__grid">
                <!-- The inbox is the page, in two boxes: the address header up
                     here and the message list below it. Kept apart so a phone
                     can put the rail's address rows — which filter the list —
                     between them instead of under every message; above 1100px
                     they are drawn as the single frame they have always been
                     and the rail sits beside them. The frame, its rows and the
                     statistics footnote are the ones #117 built for inbox.php. -->
                <div class="ms-dash__inbox">
                    <div class="ms-inbox__frame">
                        <?php if (!empty($pendingChanges)): ?>
                        <!-- Pending profile changes: an inline notice at the top of
                             the frame rather than a card of its own. The `card`,
                             `card-header`, `badge` and `list-group-item` classes are
                             kept as hooks — the inbox script reaches for all four by
                             name when a change is cancelled — and restyled by the
                             Dashboard section of assets/css/mailshield.css. -->
                        <div class="card ms-pending">
                            <div class="card-header ms-pending__head">
                                <h2 class="ms-pending__title">Pending profile changes</h2>
                                <span class="badge bg-warning"><?php echo count($pendingChanges); ?> pending</span>
                            </div>
                            <ul class="list-group ms-pending__list">
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
                                <li id="pending-item-<?php echo $pc['id']; ?>" class="list-group-item ms-pending__item">
                                    <div class="ms-pending__body">
                                        <strong class="ms-pending__action"><?php echo $label; ?></strong>
                                        <div class="ms-pending__detail"><?php echo htmlspecialchars($detail); ?></div>
                                        <div class="ms-pending__meta">Requested: <?php echo htmlspecialchars($pc['created_at']); ?></div>
                                        <div class="ms-pending__meta">Expires: <?php echo htmlspecialchars($pc['expires_at']); ?></div>
                                        <a href="#" class="resend-pending-link ms-pending__resend" data-id="<?php echo $pc['id']; ?>">Resend confirmation email</a>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger btn-cancel-pending" data-id="<?php echo $pc['id']; ?>">Cancel</button>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <!-- Initial address generator (visas tills adress är skapad) -->
                        <div id="initial-generator" class="ms-inbox__empty">
                            <h2 class="ms-inbox__empty-title">Click to get your temporary email address</h2>
                            <button id="generateBtn" class="btn btn-primary btn-lg">
                                <i class="fas fa-magic" aria-hidden="true"></i> Get Email Address
                            </button>
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
                                    <!-- Second way into the generator. It carries the
                                         other id the inbox script's delegated handler
                                         answers to ('#generateBtn, #newAddressBtn')
                                         instead of a second #generateBtn: duplicate ids
                                         are invalid, and getElementById only ever finds
                                         the first — the empty state above. -->
                                    <button id="newAddressBtn" class="btn btn-outline-secondary">
                                        <i class="fas fa-plus" aria-hidden="true"></i> New address
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- The message list. A box of its own rather than the
                         lower half of the frame above: the Addresses filter
                         rows belong between the two on a phone, and above
                         1100px the two boxes are drawn as the one frame they
                         have always been. The second `email-container` is not a
                         duplicate — the inbox script toggles both by class, so
                         the head above and the list here appear and disappear
                         together. -->
                    <div class="ms-dash__messages">
                        <div class="email-container d-none">
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

                            <!-- Aktivt adressfilter. Raden hålls dold tills
                                 app.js visar den; adressen skrivs in som text i
                                 #addressFilterLabel av syncAddressFilterUI(). -->
                            <div class="ms-inbox__filter" id="addressFilterBar" hidden>
                                <span class="ms-chip ms-inbox__filter-chip">
                                    <i class="fas fa-filter" aria-hidden="true"></i>
                                    <span>Filtered by: <strong id="addressFilterLabel"></strong></span>
                                </span>
                                <button type="button" class="ms-address-filter-clear">Clear filter</button>
                            </div>

                            <!-- E-postlista -->
                            <div class="email-list" id="emailList">
                                <!-- E-postmeddelanden läses in här via JavaScript -->
                            </div>
                        </div>

                        <!-- Statistik: en fotnot, inte en funktion -->
                        <div class="ms-inbox__stats">
                            <div class="ms-inbox__stat">
                                <span class="ms-inbox__stat-n" id="statsTotal">0</span>
                                <span class="ms-inbox__stat-l">Total emails</span>
                            </div>
                            <div class="ms-inbox__stat">
                                <span class="ms-inbox__stat-n" id="statsProcessed">0</span>
                                <span class="ms-inbox__stat-l">Emails processed</span>
                            </div>
                            <div class="ms-inbox__stat">
                                <span class="ms-inbox__stat-n" id="statsCreated">0</span>
                                <span class="ms-inbox__stat-l">Addresses created</span>
                            </div>
                            <div class="ms-inbox__stat">
                                <span class="ms-inbox__stat-n" id="statsAttachments">0</span>
                                <span class="ms-inbox__stat-l">Attachments processed</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- The rail. Creating and deleting addresses stays on the
                     profile page (the hint below links there); a row here
                     filters the inbox on that address instead of navigating. -->
                <div class="ms-dash__rail">
                    <section class="ms-rail__group" aria-labelledby="railAddressesTitle">
                        <h2 class="ms-eyebrow ms-rail__title" id="railAddressesTitle">Addresses</h2>
                        <ul class="ms-rail__addrs" id="railAddressList" role="list"></ul>
                        <p class="ms-rail__hint">
                            Manage your addresses in <a href="pro_profile_page.php">Settings</a>.
                        </p>
                    </section>

                    <section class="ms-rail__group" aria-labelledby="railTtlTitle">
                        <h2 class="ms-eyebrow ms-rail__title" id="railTtlTitle">Default lifetime</h2>
                        <label class="ms-visually-hidden" for="ttlSelect">Default lifetime</label>
                        <select id="ttlSelect" class="form-select ms-rail__select">
                            <?php for ($d=1;$d<=7;$d++): ?>
                                <option value="<?php echo $d; ?>" <?php echo ($d === $proTtl) ? 'selected' : ''; ?>><?php echo $d; ?> day<?php echo $d>1 ? 's' : ''; ?></option>
                            <?php endfor; ?>
                        </select>
                        <div id="ttlMsg"></div>
                        <p class="ms-rail__hint">Applied to new temporary addresses.</p>
                    </section>
                </div>
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

    // The rail's address list. Reads the existing index.php `list_personal`
    // action — it already returns the account's personal addresses, so no new
    // endpoint is needed. Each row filters the inbox on that address (handled
    // by the delegated .ms-rail__addr-link handler in assets/js/app.js); the
    // profile page stays the only place addresses are created or deleted.
    (function(){
        var list = document.getElementById('railAddressList');
        if (!list) return;

        function esc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        $.post('index.php', { action: 'list_personal' }, function(res){
            var rows = (res && res.success && res.personal) ? res.personal : [];
            if (!rows.length) {
                list.innerHTML = '<li class="ms-rail__empty">No personal addresses yet</li>';
                return;
            }
            var addresses = [];
            list.innerHTML = rows.map(function(a){
                var full = a.full_address || a.address || '';
                addresses.push(full);
                return '<li class="ms-rail__addr">' +
                       '<button type="button" class="ms-rail__addr-link" data-address="' + esc(full) + '"' +
                       ' title="Show only mail sent to this address">' + esc(full) + '</button>' +
                       '</li>';
            }).join('');
            // The inbox script needs the list to tell an address that belongs
            // to this account from one that does not, before it applies a
            // ?filter_address= from the URL. Passing it also applies that
            // filter, now that the rows above exist to be marked active.
            if (window.tempMailApp && typeof window.tempMailApp.setKnownPersonalAddresses === 'function') {
                window.tempMailApp.setKnownPersonalAddresses(addresses);
            }
        }, 'json').fail(function(){
            list.innerHTML = '<li class="ms-rail__empty">Could not load addresses</li>';
        });
    })();
    </script>
</body>
</html>
