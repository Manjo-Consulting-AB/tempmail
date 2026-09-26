<?php
/**
 * Login page for magic link (pro users)
 * Handles token verification and user session
 *
 * Shell: brief §11 keeps this page on the Bootstrap bridge — Bootstrap and
 * jQuery stay and the bridge restyles them — so this issue replaces the page
 * shape only (see the auth section at the end of assets/css/mailshield.css).
 * All three entry paths are untouched: the token branch above, the magic-link
 * form, the password form and the 2FA challenge below, including every element
 * id, name and data-* attribute the jQuery in this file binds to. In
 * particular the remember-this-browser checkbox keeps its place at the top of
 * the 2FA form, which the auto-submit at six digits depends on (commit 0ba9ddc).
 */
require_once 'config.php';
require_once __DIR__ . '/pro_auth.php';
require_once __DIR__ . '/pro_trial.php';
require_once __DIR__ . '/partials/brand.php';

session_start();

// A device kept signed in (pro_remember.php) goes straight to the dashboard -
// but not when a magic link is being opened: that link may be a registration
// verification, or for another account, and must be handled below.
if (!isset($_GET['token'])) {
    proRememberRestoreSession();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['pro_user_id'])) {
    header('Location: pro.php');
    exit;
}

// Handle token from GET
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$error = '';
if ($token) {
    $result = consumeLoginToken($token);
    if ($result === null) {
        $error = "Invalid or expired link.";
    } else {
        // Magic link is itself two factors (knowledge of the address +
        // inbox access) — it must never trigger a pending_2fa challenge, and
        // any leftover half-login from a password attempt must not survive
        // into this session. See documentaion/2FA_DESIGN.md.
        unset($_SESSION['pending_2fa']);
        session_regenerate_id(true);
        $_SESSION['pro_user_id'] = $result['user_id'];
        $_SESSION['pro_user_email'] = $result['email'];
        $_SESSION['pro_login_method'] = 'magic_link';
        recordProUserLogin($result['user_id']);
        // "Stay signed in" period chosen on the login form, carried in the link.
        proRememberIssue((int) $result['user_id'], proRememberNormaliseDays($_GET['stay'] ?? 0));

        // Registration (#59, ACCOUNT_TIERS.md §4.1/§4.3) reuses this same
        // magic-link token as its verification link, so verification and the
        // first login happen in the same click - no separate "you're now
        // verified" page. Only ever sets it, never clears it, and only when
        // still NULL (an already-verified account is left untouched).
        if (tableHasColumn('pro_users', 'email_verified_at')) {
            $verifyStmt = $pdo->prepare("UPDATE pro_users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL");
            $verifyStmt->execute([$result['user_id']]);
            if ($verifyStmt->rowCount() > 0) {
                // Only fires on the row's first verification (the WHERE clause
                // above only matches when email_verified_at was still NULL) -
                // never on a plain magic-link login by an already-verified user.
                // Grant the 60-day Pro trial on first verification (epic #267).
                proTrialGrantOnVerification($pdo, (int) $result['user_id'], (string) $result['email'], $config['trial'] ?? []);
                sendAdminRegistrationNotification((string) $result['email'], (int) $result['user_id']);
            }
        }

        header('Location: pro.php');
        exit;
    }
}

// Canonical/OG origin, from $config and never hardcoded (§14). This page stays
// indexable (§14 lists only inbox/pro_profile/client_agent/log_viewer/pro_contact
// as noindex) and is one of the six paths in sitemap.php.
$msOgOrigin = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msOgUrl    = $msOgOrigin . '/pro_login.php';
$msOgImage  = $msOgOrigin . '/assets/images/og-mailshield.png';
$msTitle    = 'Log in · Mail Shield';
$msDesc     = 'Log in to Mail Shield with a magic link sent to your email address, or with your password if you have set one.';
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FAFAF9">
    <title><?php echo htmlspecialchars($msTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($msDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo htmlspecialchars($msOgUrl, ENT_QUOTES, 'UTF-8'); ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Mail Shield">
    <meta property="og:title" content="<?php echo htmlspecialchars($msTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($msDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($msOgUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($msOgImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($msTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($msDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($msOgImage, ENT_QUOTES, 'UTF-8'); ?>">

    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <script>
        // Only show the magic-link test URL in non-production environments
        window.showMagicLink = <?php echo (strpos($config['email']['base_url'], 'manjo.me') === false) ? 'true' : 'false'; ?>;
    </script>

    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="ms-auth">
        <header class="ms-auth__bar">
            <div class="ms-auth__bar-inner">
                <?php echo ms_logo(['href' => '/']); ?>
            </div>
        </header>

        <main class="ms-auth__main">
            <div class="ms-card ms-auth__card">
                <h1 class="ms-auth__title">Log in</h1>
                <p class="ms-auth__sub">Sign in to your Mail Shield account.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="mb-3" id="loginModeToggle">
                    <div class="ms-auth__segment" role="group" aria-label="Login mode">
                        <input type="radio" class="btn-check" name="loginMode" id="modeMagic" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="modeMagic">Magic link</label>

                        <input type="radio" class="btn-check" name="loginMode" id="modePassword" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="modePassword">Password</label>
                    </div>
                </div>

                <hr id="loginModeDivider">

                <!-- Shared by both modes; the choice travels with the magic link
                     and waits through the 2FA challenge (pro_remember.php). -->
                <div class="mb-3" id="staySignedInField">
                    <label for="staySignedIn" class="form-label">Stay signed in on this device</label>
                    <select class="form-select" id="staySignedIn" aria-describedby="staySignedInHint">
                        <option value="0">Until I close the browser</option>
                        <option value="1">For 1 day</option>
                        <option value="7">For 7 days</option>
                        <option value="30">For 30 days</option>
                    </select>
                    <div class="form-text" id="staySignedInHint">Keeps you signed in when your phone closes the browser in the background. Only choose a period on a device that is yours.</div>
                </div>

                <div id="magicBlock">
                    <form method="post" action="pro_auth.php" id="loginRequestForm">
                        <input type="hidden" name="action" value="request_login_link">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" id="email" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send me a login link</button>
                    </form>
                    <div id="loginRequestMsg" class="mt-3"></div>
                </div>

                <div id="passwordBlock" style="display:none;">
                    <form id="passwordLoginForm">
                        <input type="hidden" name="action" value="password_login">
                        <div class="mb-3">
                            <label for="pwEmail" class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" id="pwEmail" required>
                        </div>
                        <div class="mb-3">
                            <label for="pwPassword" class="form-label">Password</label>
                            <div class="ms-password-field">
                                <input type="password" class="form-control" name="password" id="pwPassword" required>
                                <button type="button" class="ms-password-toggle" data-target="pwPassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Sign in</button>
                    </form>
                    <div id="passwordLoginMsg" class="mt-3"></div>
                </div>

                <div id="twoFactorBlock" style="display:none;">
                    <p class="ms-auth__hint ms-auth__hint--lead">Open your authenticator app (1Password, Authy, Google Authenticator &hellip;) and enter the 6-digit code for Mail Shield.</p>
                    <form id="twoFactorForm">
                        <input type="hidden" name="action" value="verify_2fa">
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" name="remember_device" id="rememberDeviceCheck">
                            <label class="form-check-label" for="rememberDeviceCheck">Remember this browser for 30 days</label>
                        </div>
                        <div class="mb-3">
                            <label for="twoFactorCode" class="form-label" id="twoFactorCodeLabel">Authentication code</label>
                            <input type="text" class="form-control" name="code" id="twoFactorCode"
                                   inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*"
                                   autofocus required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Verify</button>
                    </form>
                    <div class="ms-auth__link-row">
                        <a href="#" id="useRecoveryCodeLink">Use a recovery code instead</a>
                    </div>
                    <div class="ms-auth__link-row">
                        <a href="#" id="lostAuthenticatorLink">Lost your authenticator? Sign in with an email link instead</a> &mdash; you can then turn off two-factor authentication in your profile.
                    </div>
                    <div class="ms-auth__link-row">
                        <a href="#" id="cancel2faLink">Cancel</a>
                    </div>
                    <div id="twoFactorMsg" class="mt-3"></div>
                </div>

                <?php $msTrialDays = max(0, (int) ($config['trial']['days'] ?? 0)); ?>
                <p class="ms-auth__alt">New here? <a href="/register.php?plan=regular">Create your inbox</a><?php if ($msTrialDays > 0) : ?> &mdash; it starts with <?php echo $msTrialDays; ?> days of Pro<?php endif; ?>.</p>
            </div>
        </main>

        <footer class="ms-auth__foot">
            <p><a href="/">&larr; Back to Mail Shield</a><span class="ms-auth__foot-sep" aria-hidden="true">&middot;</span><span>&copy; <?php echo date('Y'); ?> Manjo Consulting AB</span></p>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(function(){
        // Each status region belongs to the fields that write into it, so the
        // association is made here rather than in the markup: every one of
        // those input lines carries an id, and this issue's regression check
        // forbids touching any line that does. Set before first paint, so it is
        // equivalent for assistive tech.
        $('#email').attr('aria-describedby', 'loginRequestMsg');
        $('#pwEmail, #pwPassword').attr('aria-describedby', 'passwordLoginMsg');
        $('#twoFactorCode').attr('aria-describedby', 'twoFactorMsg');

        // The last choice is remembered on this device only (a convenience,
        // not a credential), under a key sign-out does not clear.
        var STAY_KEY = 'ms_stay_signed_in';
        try {
            var savedStay = localStorage.getItem(STAY_KEY);
            if (savedStay !== null && $('#staySignedIn option[value="' + savedStay.replace(/[^0-9]/g, '') + '"]').length) {
                $('#staySignedIn').val(savedStay);
            }
        } catch (e) { /* storage unavailable: keep the default */ }
        $('#staySignedIn').on('change', function(){
            try { localStorage.setItem(STAY_KEY, $(this).val()); } catch (e) { /* ignore */ }
        });
        function stayDays() {
            return $('#staySignedIn').val() || '0';
        }

        function showMode(mode) {
            if (mode === 'password') {
                $('#magicBlock').hide();
                $('#passwordBlock').show();
            } else {
                $('#passwordBlock').hide();
                $('#magicBlock').show();
            }
        }

        $('input[name="loginMode"]').on('change', function(){
            if ($('#modePassword').is(':checked')) showMode('password'); else showMode('magic');
        });

        // --- 2FA challenge (shown after password_login responds with requires_2fa) ---
        var usingRecoveryCode = false;

        function resetTwoFactorField() {
            usingRecoveryCode = false;
            $('#twoFactorCode').attr({
                maxlength: 6,
                inputmode: 'numeric',
                pattern: '[0-9]*',
                autocomplete: 'one-time-code'
            });
            $('#twoFactorCodeLabel').text('Authentication code');
            $('#useRecoveryCodeLink').text('Use a recovery code instead');
        }

        function showTwoFactorBlock() {
            $('#loginModeToggle, #loginModeDivider, #staySignedInField, #magicBlock, #passwordBlock').hide();
            resetTwoFactorField();
            $('#twoFactorMsg').html('');
            $('#twoFactorCode').val('');
            $('#rememberDeviceCheck').prop('checked', false);
            $('#twoFactorBlock').show();
            $('#twoFactorCode').trigger('focus');
        }

        function hideTwoFactorBlock(restoreMode) {
            $('#twoFactorBlock').hide();
            $('#loginModeToggle, #loginModeDivider, #staySignedInField').show();
            $('#twoFactorMsg').html('');
            $('#twoFactorCode').val('');
            showMode(restoreMode);
            if (restoreMode === 'password') {
                $('#modePassword').prop('checked', true);
            } else {
                $('#modeMagic').prop('checked', true);
            }
        }

        // Best-effort: tell the server to drop pending_2fa, then continue
        // regardless of whether the request succeeded (the session entry
        // also expires on its own after 10 minutes).
        function cancelTwoFactor(callback) {
            $.post('pro_auth.php', { action: 'cancel_2fa' }, function(){}, 'json').always(callback);
        }

        $('#twoFactorCode').on('input', function(){
            if (!usingRecoveryCode) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length === 6) {
                    $('#twoFactorForm').trigger('submit');
                }
            }
        });

        $('#twoFactorForm').on('submit', function(e){
            e.preventDefault();
            var code = $('#twoFactorCode').val();
            if (!code) return;
            var rememberDevice = $('#rememberDeviceCheck').is(':checked') ? 1 : 0;
            $('#twoFactorMsg').html('<div class="alert alert-info">Verifying...</div>');
            $.post('pro_auth.php', { action: 'verify_2fa', code: code, remember_device: rememberDevice }, function(res){
                if (res && res.success) {
                    window.location = res.redirect || 'pro.php';
                } else {
                    $('#twoFactorMsg').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Incorrect code') + '</div>');
                    $('#twoFactorCode').val('').trigger('focus');
                }
            }, 'json').fail(function(){
                $('#twoFactorMsg').html('<div class="alert alert-danger">Network error</div>');
                $('#twoFactorCode').val('').trigger('focus');
            });
        });

        $('#useRecoveryCodeLink').on('click', function(e){
            e.preventDefault();
            usingRecoveryCode = !usingRecoveryCode;
            if (usingRecoveryCode) {
                $('#twoFactorCode').attr({
                    maxlength: 11,
                    inputmode: 'text',
                    pattern: '[A-Za-z0-9-]*',
                    autocomplete: 'off'
                });
                $('#twoFactorCodeLabel').text('Recovery code');
                $(this).text('Use your authenticator app instead');
            } else {
                resetTwoFactorField();
            }
            $('#twoFactorMsg').html('');
            $('#twoFactorCode').val('').trigger('focus');
        });

        $('#lostAuthenticatorLink').on('click', function(e){
            e.preventDefault();
            cancelTwoFactor(function(){ hideTwoFactorBlock('magic'); });
        });

        $('#cancel2faLink').on('click', function(e){
            e.preventDefault();
            cancelTwoFactor(function(){ hideTwoFactorBlock('password'); });
        });

        $('#loginRequestForm').on('submit', function(e){
            e.preventDefault();
            var email = $('#email').val();
            $('#loginRequestMsg').html('<div class="alert alert-info">Sending link...</div>');
            $.post('pro_auth.php', {action:'request_login_link', email:email, stay_days: stayDays()}, function(res){
                if (res.success) {
                    let html = '<div class="alert alert-success">Login link sent! Check your email.</div>';
                    // Only display the test magic link when allowed by server config
                    if (res.login_url && window.showMagicLink) {
                        html += '<div class="alert alert-info">Magic link (test):<br><a href="' + res.login_url + '" target="_blank">' + res.login_url + '</a></div>';
                    }
                    $('#loginRequestMsg').html(html);
                } else {
                    $('#loginRequestMsg').html('<div class="alert alert-danger">' + (res.error || 'Could not send link.') + '</div>');
                }
            }, 'json');
        });

        // Password login handler
        $('#passwordLoginForm').on('submit', function(e){
            e.preventDefault();
            var email = $('#pwEmail').val();
            var password = $('#pwPassword').val();
            $('#passwordLoginMsg').html('<div class="alert alert-info">Signing in...</div>');
            $.post('pro_auth.php', { action: 'password_login', email: email, password: password, stay_days: stayDays() }, function(res){
                if (res && res.success) {
                    if (res.requires_2fa) {
                        $('#passwordLoginMsg').html('');
                        showTwoFactorBlock();
                    } else {
                        // Redirect to dashboard
                        window.location = res.redirect || 'pro.php';
                    }
                } else {
                    $('#passwordLoginMsg').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Login failed') + '</div>');
                }
            }, 'json').fail(function(){ $('#passwordLoginMsg').html('<div class="alert alert-danger">Network error</div>'); });
        });
    });
    </script>

    <script>
    /**
     * Show/hide toggles for password fields.
     *
     * Generic by design: each button names its input in `data-target`, so one
     * binding covers every password field on the page and a further field
     * needs markup only. The auth pages do not load the inbox script, so the
     * binding is repeated here rather than shared; it is deliberately vanilla
     * instead of jQuery so it does not queue behind the ready handler above.
     *
     * The button carries `type="button"` in the markup, without which it would
     * submit the surrounding form.
     */
    (function () {
        var buttons = document.querySelectorAll('.ms-password-toggle');

        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-target'));
                if (!input) return;

                var masked = input.type === 'password';
                input.type = masked ? 'text' : 'password';

                var icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !masked);
                    icon.classList.toggle('fa-eye-slash', masked);
                }

                btn.setAttribute('aria-pressed', String(masked));
                btn.setAttribute('aria-label', masked ? 'Hide password' : 'Show password');
            });
        });
    })();
    </script>
</body>
</html>
