<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    header('Location: pro_login.php');
    exit;
}

$userEmail = $_SESSION['pro_user_email'] ?? '';

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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile - TempMail Pro</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1><i class="fas fa-cog"></i> Account Settings</h1>
            <p class="lead">Manage your Pro account</p>

            <?php require 'partials/nav.php'; ?>
        </div>

        <div class="card mt-4">
            <div class="card-header"><h3>Account Settings</h3></div>
            <div class="card-body">
                <div id="proProfileAlert"></div>
                <form id="proProfileForm">
                    <div class="mb-3 profile-delete-section">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="proEmail" />
                    </div>
                    <div class="mb-3">
                        <button type="button" id="saveProfileEmailBtn" class="btn btn-primary">Save email</button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Default lifetime</label>
                        <div class="d-flex align-items-center">
                            <select id="ttlSelect" class="form-select" style="width:120px;"></select>
                            <div id="ttlMsg" style="margin-left:10px; color:#9ecbff;"></div>
                        </div>
                        <div class="form-text">Set default address lifetime for newly generated addresses and emails (1-7 days).</div>
                    </div>

                    <hr>
                    <h6>Digest emails</h6>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="digestEnabled" />
                            <label class="form-check-label" for="digestEnabled">Enable digest emails</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Frequency (days)</label>
                        <select id="digestFrequency" class="form-select" style="width:150px;">
                        </select>
                        <div class="mt-3 d-flex" style="gap:12px; align-items:center;">
                            <div>
                                <label class="form-label mb-1">Send time (hour)</label>
                                <select id="digestHour" class="form-select" style="width:120px;"></select>
                            </div>
                            <div>
                                <label class="form-label mb-1">Timezone</label>
                                <select id="digestTz" class="form-select" style="min-width:220px;"></select>
                            </div>
                        </div>
                        <div id="digestNote" class="form-text text-muted">Digests will be sent at most once per 24 hours and no more frequently than 1 day. Frequency cannot exceed address TTL - 1 day.</div>
                        <div class="mt-2 alert alert-info p-2" style="font-size:0.95rem;">
                            Tip: To help ensure digest emails reach your inbox, add <strong>noreply@<?php echo htmlspecialchars($config['email']['domain']); ?></strong> to your address book or trusted senders list (this reduces the chance of digests being marked as spam).
                        </div>
                    </div>

                    <hr>
                    <h6>Personal addresses</h6>
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-2 flex-wrap">
                            <div class="input-group" style="max-width:420px;">
                                <input id="personalLocal" class="form-control" placeholder="yourname" aria-label="local part" />
                                <span class="input-group-text">@<?php echo htmlspecialchars($config['email']['domain']); ?></span>
                            </div>
                            <button type="button" id="createPersonalBtn" class="btn btn-primary btn-sm ms-3">Create</button>
                            <div id="personalMsg" style="margin-left:10px; color:#9ecbff; margin-top:6px;"></div>
                        </div>
                        <div>
                            <span id="personalCounterLabel" class="text-muted">You have</span>
                            <span id="personalCounterBadge" class="badge bg-secondary ms-2">0/10</span>
                        </div>
                        <div id="personalList" class="mt-3"></div>
                        <div class="form-text">Create up to 10 personal addresses. These persist for pro accounts.</div>
                    </div>

                    <hr>
                    <h6>Webhooks</h6>
                    <div class="mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Name (optional)</label>
                                <input id="whName" class="form-control" placeholder="My Pushover" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Kind</label>
                                <select id="whKind" class="form-select">
                                    <option value="generic">Generic (POST JSON)</option>
                                    <option value="pushover">Pushover</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2 mt-2">
                            <label class="form-label">URL / Endpoint</label>
                            <input id="whUrl" class="form-control" placeholder="https://example.com/webhook" />
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Config (JSON)</label>
                            <input id="whConfig" class="form-control" placeholder='{"token":"...","user":"..."}' />
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Secret (optional)</label>
                            <input id="whSecret" class="form-control" placeholder="Optional signing secret" />
                            <div class="form-text">If set, requests will include header <code>X-TempMail-Signature</code> with sha256 HMAC.</div>
                        </div>
                        <div class="mb-2">
                            <button id="whCreateBtn" class="btn btn-primary">Create webhook</button>
                        </div>
                        <div id="whMsg" class="mt-2"></div>

                        <div class="mt-3">
                            <h6>Your webhooks</h6>
                            <div id="webhookList"></div>
                        </div>
                        <hr>
                        <h6>Client signing keys</h6>
                        <div class="mb-2">
                            <p class="form-text mb-2">Rotate the keys used to sign client list-sync payloads.</p>
                            <button type="button" id="rotateSigningKeysBtn" class="btn btn-primary">Rotate signing keys</button>
                        </div>
                        <div class="mt-4">
                            <h6>Pro RSS Feed</h6>
                            <div class="mb-2">
                                <p class="form-text">You can subscribe to a private RSS feed of all emails for your pro account. Keep the token secret.</p>
                                <div class="input-group mb-2">
                                    <input type="text" id="feedUrlInput" class="form-control" placeholder="(loading...)" readonly />
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Feed actions">
                                        <button id="copyFeedToken" class="btn btn-sm btn-outline-secondary">Copy</button>
                                        <button id="openFeedBtn" class="btn btn-sm btn-outline-secondary">Open</button>
                                        <button id="regenFeedToken" class="btn btn-sm btn-danger">Regenerate</button>
                                    </div>
                                </div>
                                <div class="form-text">Keep the token part of the URL secret. Subscribe using the full URL shown above.</div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6>Set / Change password</h6>
                    <!-- Current-password field removed: users can change password without supplying previous password -->
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" class="form-control" id="proPassword" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm password</label>
                        <input type="password" class="form-control" id="proPasswordConfirm" />
                    </div>
                    <div class="mb-3">
                        <button type="button" id="saveProfilePasswordBtn" class="btn btn-secondary">Set password!</button>
                    </div>
                    <hr>
                    <h6>Danger zone</h6>
                    <div class="mb-3">
                        <p class="text-muted">Permanently delete your Pro account and all associated Pro settings. This cannot be undone.</p>
                        <p class="text-muted">A confirmation email with a deletion link will be sent to your email address after you click the button.</p>
                        <div style="margin-top:8px;">
                            <button type="button" id="deleteAccountBtn" class="btn btn-danger">Delete account</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- NOTE: app.js is intentionally NOT included here to avoid auto-refresh email polling -->
    <script>
        // On page load, fetch profile and populate (reuse same endpoints)
        $(function(){
            $('#proProfileAlert').html('<div class="alert alert-info">Loading profile...</div>');

            $('#saveProfileEmailBtn').on('click', function(e){
                e.preventDefault();
                var email = $('#proEmail').val();
                $('#proProfileAlert').html('<div class="alert alert-info">Saving email...</div>');
                $.post('pro_profile.php', { action: 'update_email', email: email }, function(res){
                    if (res && res.success) {
                        var msg = res.message || 'Verification email sent. Please check your inbox.';
                        $('#proProfileAlert').html('<div class="alert alert-success">' + msg + '</div>');
                    } else {
                        $('#proProfileAlert').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Failed to save email') + '</div>');
                    }
                }, 'json').fail(function(){
                    $('#proProfileAlert').html('<div class="alert alert-danger">Network error</div>');
                });
            });

            $('#saveProfilePasswordBtn').on('click', function(e){
                e.preventDefault();
                var password = $('#proPassword').val();
                var confirm = $('#proPasswordConfirm').val();
                $('#proProfileAlert').html('<div class="alert alert-info">Saving password...</div>');
                $.post('pro_profile.php', { action: 'set_password', password: password, confirm: confirm }, function(res){
                    if (res && res.success) {
                        $('#proProfileAlert').html('');
                        $('#proPassword').val('');
                        $('#proPasswordConfirm').val('');
                        $('#saveProfilePasswordBtn')
                            .removeClass('btn-secondary')
                            .addClass('btn-success')
                            .text('Verification email sent');
                    } else {
                        $('#proProfileAlert').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Failed to set password') + '</div>');
                    }
                }, 'json').fail(function(){
                    $('#proProfileAlert').html('<div class="alert alert-danger">Network error</div>');
                });
            });

            $('#rotateSigningKeysBtn').on('click', function(e){
                e.preventDefault();
                if (!window.confirm('Rotate client signing keys now?')) {
                    return;
                }
                $('#proProfileAlert').html('<div class="alert alert-info">Rotating signing keys...</div>');
                $.post('pro_profile.php', { action: 'rotate_signing_keys' }, function(res){
                    if (res && res.success) {
                        $('#proProfileAlert').html('<div class="alert alert-success">' + (res.message || 'Signing keys rotated') + '</div>');
                    } else {
                        $('#proProfileAlert').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Failed to rotate signing keys') + '</div>');
                    }
                }, 'json').fail(function(){
                    $('#proProfileAlert').html('<div class="alert alert-danger">Network error</div>');
                });
            });

                $.post('pro_profile.php', { action: 'get_profile' }, function(res){
                    if (res && res.success && res.profile) {
                    $('#proEmail').val(res.profile.email || '');
                    // Show pro expiry if provided (date-only under Signed in as)
                    var expires = res.profile.pro_expires_at || null;
                    if (expires) {
                        var d = new Date(expires + ' UTC');
                        // Format date as DD/MM/YYYY (no time)
                        var dd = String(d.getUTCDate()).padStart(2, '0');
                        var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                        var yyyy = d.getUTCFullYear();
                        var dateOnly = dd + '/' + mm + '/' + yyyy;
                        var now = new Date();
                        var diffMs = d - now;
                        var days = Math.floor(diffMs / (1000*60*60*24));
                        if (diffMs <= 0) {
                            $('#proExpiryLine').text('Pro expired on ' + dateOnly).css('color', '#ff6b6b');
                        } else {
                            $('#proExpiryLine').text('Pro expires: ' + dateOnly).css('color', '');
                        }
                    } else {
                        $('#proExpiryLine').text('Pro: Lifetime').css('color', '');
                    }
                    // We no longer require the current password to set a new one, so the current-password field is not shown.
                    $('#proProfileAlert').html('');
                } else {
                    $('#proProfileAlert').html('<div class="alert alert-warning">Could not load profile</div>');
                }
            }, 'json').fail(function(){
                $('#proProfileAlert').html('<div class="alert alert-danger">Network error</div>');
            });

            // Load digest settings
            $.post('pro_profile.php', { action: 'get_digest_settings' }, function(res){
                if (!res || !res.success) return;
                var enabled = res.digest_enabled == 1;
                var freq = parseInt(res.digest_frequency_days || 1, 10);
                var maxAllowed = parseInt(res.max_allowed_frequency_days || 1, 10);
                var ttlDays = parseInt(res.address_ttl_days || 1, 10);

                // Populate frequency dropdown (1..maxAllowed)
                var $sel = $('#digestFrequency');
                $sel.empty();
                for (var i = 1; i <= maxAllowed; i++) {
                    var opt = $('<option/>').attr('value', i).text(i + (i === 1 ? ' day' : ' days'));
                    if (i === freq) opt.attr('selected', 'selected');
                    $sel.append(opt);
                }

                // Set enabled checkbox
                $('#digestEnabled').prop('checked', enabled);
                // Populate hour select (0..23)
                var $hour = $('#digestHour');
                $hour.empty();
                for (var h=0; h<24; h++) {
                    var label = (h<10? '0'+h : h) + ':00';
                    var opt = $('<option/>').attr('value', h).text(label);
                    if (res.digest_hour !== null && parseInt(res.digest_hour,10) === h) opt.attr('selected','selected');
                    $hour.append(opt);
                }

                // Populate timezone select with a reasonable subset and default to browser tz
                var $tz = $('#digestTz');
                $tz.empty();
                var tzList = Intl && Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : null;
                // Fallback smallest curated list if Intl not available
                if (!tzList || !tzList.length) {
                    tzList = ['UTC','Europe/Stockholm','America/New_York','America/Los_Angeles','Asia/Tokyo','Australia/Sydney'];
                }
                var browserTz = Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : 'UTC';
                tzList.forEach(function(tz){
                    var opt = $('<option/>').attr('value', tz).text(tz);
                    if (res.digest_tz && res.digest_tz === tz) opt.attr('selected','selected');
                    $tz.append(opt);
                });
                if (!res.digest_tz) {
                    // default to browser tz if user has none
                    $tz.val(browserTz);
                }
                if (ttlDays <= 1) {
                    // Disable controls when TTL is 1 day
                    $('#digestEnabled').prop('checked', false).prop('disabled', true);
                    $sel.prop('disabled', true);
                    $('#digestNote').text('Digests are not available when address TTL is 1 day.');
                }

                // Autosave handlers
                $('#digestEnabled').on('change', function(){
                    var en = $(this).is(':checked') ? 1 : 0;
                    var fq = parseInt($('#digestFrequency').val() || '1', 10);
                    var hour = parseInt($('#digestHour').val()||'0',10);
                    var tz = $('#digestTz').val() || '';
                    $.post('pro_profile.php', { action: 'update_digest_settings', digest_enabled: en, digest_frequency_days: fq, digest_hour: hour, digest_tz: tz }, function(r){
                        if (!r || !r.success) {
                            alert('Could not update digest settings: ' + (r && r.error ? r.error : 'Unknown'));
                        }
                    }, 'json');
                });

                $('#digestFrequency').on('change', function(){
                    var fq = parseInt($(this).val() || '1', 10);
                    var en = $('#digestEnabled').is(':checked') ? 1 : 0;
                    var hour = parseInt($('#digestHour').val()||'0',10);
                    var tz = $('#digestTz').val() || '';
                    $.post('pro_profile.php', { action: 'update_digest_settings', digest_enabled: en, digest_frequency_days: fq, digest_hour: hour, digest_tz: tz }, function(r){
                        if (!r || !r.success) {
                            alert('Could not update digest frequency: ' + (r && r.error ? r.error : 'Unknown'));
                        }
                    }, 'json');
                });

                // Save hour/timezone when changed
                $('#digestHour, #digestTz').on('change', function(){
                    var fq = parseInt($('#digestFrequency').val() || '1', 10);
                    var en = $('#digestEnabled').is(':checked') ? 1 : 0;
                    var hour = parseInt($('#digestHour').val()||'0',10);
                    var tz = $('#digestTz').val() || '';
                    $.post('pro_profile.php', { action: 'update_digest_settings', digest_enabled: en, digest_frequency_days: fq, digest_hour: hour, digest_tz: tz }, function(r){
                        if (!r || !r.success) {
                            alert('Could not update digest schedule: ' + (r && r.error ? r.error : 'Unknown'));
                        }
                    }, 'json');
                });

            }, 'json');

            // Personal addresses handlers (moved from main page)
            var MAX_PERSONAL_ADDRESSES = 10;
            function renderPersonalList(items) {
                var $container = $('#personalList');
                if (!items || items.length === 0) {
                    $container.html('<p class="text-muted">No personal addresses yet.</p>');
                    $('#personalCounterBadge').text('0/' + MAX_PERSONAL_ADDRESSES);
                    return;
                }
                var html = '<div class="list-group">';
                items.forEach(function(it){
                    html += '\n                                <div class="list-group-item d-flex justify-content-between align-items-center">\n                                    <div class="flex-grow-1 personal-item" style="cursor:pointer;" data-address="'+it.address+'">\n                                            <strong>'+it.full_address+'</strong>\n                                    </div>\n                                    <div>\n                                        <button class="btn btn-sm btn-danger personal-delete" data-id="'+it.id+'" title="Delete">\n                                            <i class="fas fa-trash"></i>\n                                        </button>\n                                    </div>\n                                </div>';
                });
                html += '\n</div>';
                $container.html(html);
                var count = items.length || 0;
                $('#personalCounterBadge').text(count + '/' + MAX_PERSONAL_ADDRESSES);
                if (count >= MAX_PERSONAL_ADDRESSES) {
                    $('#createPersonalBtn').prop('disabled', true);
                    $('#personalMsg').css('color', '#ff6b6b').text('Max ' + MAX_PERSONAL_ADDRESSES + ' personal addresses reached');
                    $('#personalCounterBadge').addClass('quota-reached bg-danger').removeClass('bg-secondary');
                    $('#personalCounterLabel').addClass('text-danger');
                } else {
                    $('#createPersonalBtn').prop('disabled', false);
                    $('#personalMsg').text('').css('color', '#9ecbff');
                    $('#personalCounterBadge').removeClass('quota-reached bg-danger').addClass('bg-secondary');
                    $('#personalCounterLabel').removeClass('text-danger');
                }
            }

            function loadPersonalList() {
                $.post('index.php', { action: 'list_personal' }, function(res){
                    if (res && res.success) {
                        renderPersonalList(res.personal || []);
                    } else {
                        $('#personalList').html('<p class="text-danger">Failed to load personal addresses</p>');
                    }
                }, 'json').fail(function(){ $('#personalList').html('<p class="text-danger">Request failed</p>'); });
            }

            // Create personal
            $('#createPersonalBtn').on('click', function(){
                if ($('#personalList .list-group-item').length >= MAX_PERSONAL_ADDRESSES) {
                    $('#personalMsg').css('color', '#ff6b6b').text('You already have ' + MAX_PERSONAL_ADDRESSES + ' personal addresses');
                    return;
                }
                var local = $('#personalLocal').val().trim();
                var llower = (local || '').toLowerCase();
                if (window.personalAddressBlacklist && window.personalAddressBlacklist.indexOf(llower) !== -1) {
                    $('#personalMsg').css('color', '#ff6b6b').text('This local part is not allowed');
                    return;
                }
                var $msg = $('#personalMsg');
                $msg.css('color', '#9ecbff').text('Creating...');
                $.post('index.php', { action: 'create_personal', local: local }, function(res){
                    if (res && res.success) {
                        $msg.css('color', '#fff').text('Created: ' + res.full_address);
                        setTimeout(function(){ $msg.text('').css('color', '#9ecbff'); }, 4000);
                        loadPersonalList();
                    } else {
                        $msg.css('color', '#ff6b6b').text(res && res.error ? res.error : 'Error creating address');
                    }
                }, 'json').fail(function(){ $msg.css('color', '#ff6b6b').text('Request failed'); });
            });

            // Initial load for personal addresses
            if ($('#personalList').length) {
                loadPersonalList();
            }

            // Click to switch to a personal address
            $(document).on('click', '.personal-item', function(e){
                e.preventDefault();
                var addr = $(this).data('address');
                if (!addr) return;
                if (window.tempMailApp) {
                    window.tempMailApp.currentAddress = addr;
                    localStorage.setItem('tempmail_address', addr);
                    window.tempMailApp.fetchExpiresAt(addr).then(function(expires){
                        window.tempMailApp.expiresAt = expires;
                        window.tempMailApp.updateUI();
                        window.tempMailApp.startValidityCountdown();
                        window.tempMailApp.loadEmails();
                    });
                }
            });

            // Delete personal address handler
            $(document).on('click', '.personal-delete', function(e){
                e.stopPropagation();
                var id = $(this).data('id');
                if (!id) return;
                if (!confirm('Delete this personal address? This action cannot be undone.')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.post('index.php', { action: 'delete_personal', id: id }, function(res){
                    if (res && res.success) {
                        loadPersonalList();
                        if (window.tempMailApp && window.tempMailApp.currentAddress && res.deleted_address) {
                            if (window.tempMailApp.currentAddress === res.deleted_address) {
                                window.tempMailApp.currentAddress = '';
                                localStorage.removeItem('tempmail_address');
                                window.tempMailApp.updateUI();
                            }
                        }
                    } else {
                        alert(res && res.error ? res.error : 'Failed to delete');
                    }
                }, 'json').fail(function(){ alert('Request failed'); }).always(function(){ $btn.prop('disabled', false); });
            });

            // Load TTL (default lifetime) and populate selector
            $.post('pro_profile.php', { action: 'get_ttl' }, function(res){
                if (!res || !res.success) return;
                var ttl = parseInt(res.ttl_days || 1, 10);
                var $sel = $('#ttlSelect');
                $sel.empty();
                for (var i=1;i<=7;i++) {
                    var opt = $('<option/>').attr('value', i).text(i + (i===1 ? ' day' : ' days'));
                    if (i === ttl) opt.attr('selected', 'selected');
                    $sel.append(opt);
                }

                $('#ttlSelect').on('change', function(){
                    var newTtl = parseInt($(this).val()||'1',10);
                    var $msg = $('#ttlMsg');
                    $msg.css('color', '#9ecbff').text('Saving...');
                    var currentAddress = '';
                    try { currentAddress = window.tempMailApp ? window.tempMailApp.currentAddress : ''; } catch(e) {}
                    $.post('pro_profile.php', { action: 'update_ttl', ttl: newTtl, address: currentAddress }, function(resp){
                        if (resp && resp.success) {
                            $msg.css('color', '#fff').text('Saved');
                            setTimeout(function(){ $msg.text('').css('color','#9ecbff'); }, 2000);
                        } else {
                            $msg.css('color', '#ff6b6b').text(resp && resp.error ? resp.error : 'Error');
                        }
                    }, 'json').fail(function(){ $msg.css('color', '#ff6b6b').text('Request failed'); });
                });
            }, 'json');

            // Webhooks: load and manage
            function renderWebhooks(items) {
                var $c = $('#webhookList');
                if (!items || items.length === 0) {
                    $c.html('<p class="text-muted">No webhooks configured.</p>');
                    return;
                }
                var html = '<div class="list-group">';
                items.forEach(function(w){
                    var cfg = w.config ? JSON.stringify(w.config) : '';
                    var isPaused = w.filter_mode === 'paused';
                    var statusBadge = isPaused 
                        ? '<span class="badge bg-warning text-dark ms-2">Paused</span>' 
                        : '<span class="badge bg-success ms-2">Active</span>';
                    var pauseBtn = isPaused
                        ? '<button class="btn btn-sm btn-outline-secondary wh-toggle-pause" data-id="'+w.id+'" data-mode="all" title="Resume webhook"><i class="bi bi-play-fill text-success"></i> Resume</button>'
                        : '<button class="btn btn-sm btn-outline-secondary wh-toggle-pause" data-id="'+w.id+'" data-mode="paused" title="Pause webhook"><i class="bi bi-pause-fill text-warning"></i> Pause</button>';
                    html += '<div class="list-group-item d-flex justify-content-between align-items-start">'
                        + '<div class="me-3"><strong>' + (w.name || ('#'+w.id)) + '</strong>' + statusBadge + '<div class="text-muted small">' + w.kind + ' — ' + w.url + '</div>'
                        + (cfg ? '<div class="text-muted small">Config: ' + cfg + '</div>' : '')
                        + '</div>'
                        + '<div class="btn-group">'
                        + pauseBtn
                        + '<button class="btn btn-sm btn-outline-secondary wh-deliveries" data-id="'+w.id+'">Deliveries</button>'
                        + '<button class="btn btn-sm btn-danger wh-delete" data-id="'+w.id+'">Delete</button>'
                        + '</div></div>';
                });
                html += '</div>';
                $c.html(html);
            }

            function loadWebhooks() {
                $.getJSON('pro_profile.php?action=webhooks_list', function(res){
                    if (res && res.success) {
                        renderWebhooks(res.webhooks || []);
                    } else {
                        $('#webhookList').html('<p class="text-danger">Could not load webhooks</p>');
                    }
                }).fail(function(){ $('#webhookList').html('<p class="text-danger">Request failed</p>'); });
            }

            $('#whCreateBtn').on('click', function(){
                var name = $('#whName').val().trim();
                var url = $('#whUrl').val().trim();
                var kind = $('#whKind').val();
                var config = $('#whConfig').val().trim();
                var secret = $('#whSecret').val().trim();
                if (!url) { $('#whMsg').html('<div class="text-danger">URL is required</div>'); return; }
                var post = { action: 'webhook_create', name: name, url: url, kind: kind };
                if (config) post.config = config;
                if (secret) post.secret = secret;
                $('#whCreateBtn').prop('disabled', true);
                $('#whMsg').html('<div class="text-info">Creating...</div>');
                $.post('pro_profile.php', post, function(r){
                    $('#whCreateBtn').prop('disabled', false);
                    if (r && r.success) {
                        $('#whMsg').html('<div class="text-success">Created</div>');
                        $('#whName,#whUrl,#whConfig,#whSecret').val('');
                        loadWebhooks();
                    } else {
                        $('#whMsg').html('<div class="text-danger">'+(r && r.error ? r.error : 'Failed')+'</div>');
                    }
                }, 'json').fail(function(xhr, status, err){
                    $('#whCreateBtn').prop('disabled', false);
                    var msg = 'Request failed';
                    try {
                        console.error('webhook_create failed', status, err, xhr && xhr.status, xhr && xhr.responseText);
                        var resp = (xhr && xhr.responseText) ? xhr.responseText : null;
                        if (resp) {
                            // Try to parse JSON error message if present
                            try {
                                var j = JSON.parse(resp);
                                if (j && j.error) msg = j.error;
                            } catch (e) {
                                // Not JSON — include a short snippet
                                msg = 'Request failed: ' + (resp.length > 200 ? resp.substring(0,200) + '...' : resp);
                            }
                        }
                    } catch (e) {
                        // ignore
                    }
                    $('#whMsg').html('<div class="text-danger">'+ $('<div>').text(msg).html() +'</div>');
                });
            });

            $(document).on('click', '.wh-toggle-pause', function(){
                var id = $(this).data('id');
                var mode = $(this).data('mode'); // 'all' to resume, 'paused' to pause
                if (!id || !mode) return;
                var $btn = $(this).prop('disabled', true);
                $.post('pro_profile.php', { action: 'webhook_set_filter_mode', id: id, filter_mode: mode }, function(r){
                    $btn.prop('disabled', false);
                    if (r && r.success) {
                        loadWebhooks();
                    } else {
                        alert(r && r.error ? r.error : 'Failed to update webhook');
                    }
                }, 'json').fail(function(){ $btn.prop('disabled', false); alert('Request failed'); });
            });

            $(document).on('click', '.wh-delete', function(){
                var id = $(this).data('id');
                if (!id) return;
                if (!confirm('Delete webhook?')) return;
                var $btn = $(this).prop('disabled', true);
                $.post('pro_profile.php', { action: 'webhook_delete', id: id }, function(r){
                    $btn.prop('disabled', false);
                    if (r && r.success) {
                        loadWebhooks();
                    } else {
                        alert(r && r.error ? r.error : 'Failed to delete');
                    }
                }, 'json').fail(function(){ $btn.prop('disabled', false); alert('Request failed'); });
            });

            $(document).on('click', '.wh-deliveries', function(){
                var id = $(this).data('id');
                if (!id) return;
                var $btn = $(this).prop('disabled', true);
                $.getJSON('pro_profile.php?action=webhook_deliveries&webhook_id=' + id + '&limit=30', function(r){
                    $btn.prop('disabled', false);
                    if (r && r.success) {
                        var out = '<div class="small">';
                        if (!r.deliveries || r.deliveries.length === 0) out += 'No deliveries yet.';
                        else {
                            r.deliveries.forEach(function(d){
                                out += '<div class="mb-2">['+d.status+'] attempts='+d.attempts+' created='+d.created_at+'<div class="text-muted small">error: '+(d.last_error||'')+'</div></div>';
                            });
                        }
                        out += '</div>';
                        // Show in modal-like alert
                        var $w = $('<div class="alert alert-secondary mt-2" id="whDeliveriesBox">'+out+'<div class="mt-2"><button class="btn btn-sm btn-secondary" id="whDeliveriesClose">Close</button></div></div>');
                        $('#webhookList').prepend($w);
                        $('#whDeliveriesClose').on('click', function(){ $w.remove(); });
                    } else {
                        alert(r && r.error ? r.error : 'Failed to fetch deliveries');
                    }
                }).fail(function(){ $btn.prop('disabled', false); alert('Request failed'); });
            });

            // Initial load
            loadWebhooks();

            // Wire webhook kind -> placeholder behaviour (do not overwrite user input)
            $('#whKind').on('change', function(){
                try {
                    var k = $(this).val();
                    var curNameVal = ($('#whName').val() || '').trim();
                    if (k === 'pushover') {
                        // Set placeholder for pushover (do not set .val())
                        if (!curNameVal) {
                            $('#whName').attr('placeholder', 'My Pushover');
                        }
                        $('#whUrl').val('https://api.pushover.net/1/messages.json');
                        // Pretty-print JSON config
                        var curCfg = ($('#whConfig').val() || '').trim();
                        if (!curCfg) {
                            $('#whConfig').val(JSON.stringify({ token: 'secret_token', user: 'pushover_user_key' }));
                        }
                    } else {
                        // Generic (or other) kind: set placeholder to 'My webhook' when name not filled
                        if (!curNameVal) {
                            $('#whName').attr('placeholder', 'My webhook');
                        }
                        // Clear kind-specific fields if they appear to be pushover defaults
                        if ($('#whUrl').val().indexOf('pushover.net') !== -1) {
                            $('#whUrl').val('');
                        }
                        var cfg = ($('#whConfig').val() || '').trim();
                        if (cfg && cfg.indexOf('pushover') !== -1) {
                            $('#whConfig').val('');
                        }
                    }
                } catch (e) {
                    console.warn('Failed applying webhook-kind presets', e);
                }
            });

            // Apply placeholder presets based on current selection on page load
            $('#whKind').trigger('change');

            // Load or create feed token and wire UI (display full URL in the input)
            function loadFeedToken() {
                $.getJSON('pro_profile.php?action=feed_get_token', function(res) {
                    if (res && res.success) {
                        var base = window.location.origin || (window.location.protocol + '//' + window.location.hostname);
                        var feedUrl = base + '/pro_feed.php?token=' + encodeURIComponent(res.token || '');
                        $('#feedUrlInput').val(feedUrl);
                        $('#openFeedBtn').attr('data-feed', feedUrl);
                    } else {
                        $('#feedUrlInput').val('Unavailable');
                        $('#openFeedBtn').removeAttr('data-feed');
                    }
                }).fail(function(){ $('#feedUrlInput').val('Request failed'); $('#openFeedBtn').removeAttr('data-feed'); });
            }

            $('#copyFeedToken').on('click', function(){
                var val = $('#feedUrlInput').val() || '';
                if (!val) return;
                navigator.clipboard && navigator.clipboard.writeText(val).then(function(){
                    $('#feedMsg').remove();
                    $('#feedUrlInput').after('<div id="feedMsg" class="text-success small ms-2">Copied</div>');
                    setTimeout(function(){ $('#feedMsg').fadeOut(300, function(){ $(this).remove(); }); }, 1500);
                }).catch(function(){ alert('Could not copy to clipboard'); });
            });

            $('#openFeedBtn').on('click', function(){
                var url = $(this).attr('data-feed') || $('#feedUrlInput').val() || '';
                if (!url) return;
                window.open(url, '_blank');
            });

            $('#regenFeedToken').on('click', function(){
                if (!confirm('Regenerating the feed token will invalidate existing subscriptions. Continue?')) return;
                var $btn = $(this).prop('disabled', true);
                $.post('pro_profile.php', { action: 'feed_regenerate' }, function(r){
                    $btn.prop('disabled', false);
                    if (r && r.success) {
                        var base = window.location.origin || (window.location.protocol + '//' + window.location.hostname);
                        var feedUrl = base + '/pro_feed.php?token=' + encodeURIComponent(r.token || '');
                        $('#feedUrlInput').val(feedUrl);
                        $('#openFeedBtn').attr('data-feed', feedUrl);
                    } else {
                        alert(r && r.error ? r.error : 'Failed to regenerate token');
                    }
                }, 'json').fail(function(){ $btn.prop('disabled', false); alert('Request failed'); });
            });

            // Delete account handler
            $('#deleteAccountBtn').on('click', function(){
                if (!confirm('Delete account? This will permanently remove your Pro account and cannot be undone. Continue?')) return;
                var $btn = $(this).prop('disabled', true);
                $.post('pro_profile.php', { action: 'delete_account' }, function(r){
                    if (r && r.success) {
                        // Indicate mail sent and disable button
                        $btn.text('Mail sent').removeClass('btn-danger').addClass('btn-success').prop('disabled', true);
                        $('#proProfileAlert').html('<div class="alert alert-success">A confirmation link has been sent to your email. Click the link to complete account deletion.</div>');
                    } else {
                        $btn.prop('disabled', false);
                        $('#proProfileAlert').html('<div class="alert alert-danger">' + (r && r.error ? r.error : 'Failed to create deletion request') + '</div>');
                    }
                }, 'json').fail(function(){ $btn.prop('disabled', false); $('#proProfileAlert').html('<div class="alert alert-danger">Request failed</div>'); });
            });

            // Load token initially
            loadFeedToken();
        });
    </script>
</body>
</html>
