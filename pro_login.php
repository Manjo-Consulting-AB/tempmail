<?php
/**
 * Login page for magic link (pro users)
 * Handles token verification and user session
 */
require_once 'config.php';
require_once __DIR__ . '/pro_auth.php';

session_start();

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
                sendAdminRegistrationNotification($result['email']);
            }
        }

        header('Location: pro.php');
        exit;
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · Mail Shield</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
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
    <div class="main-container">
        <div class="header">
            <h1><i class="fas fa-user-lock"></i> Mail Shield</h1>
            <p class="lead">Sign in to manage your Pro account</p>

            <?php require 'partials/nav.php'; ?>
        </div>

        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3>Sign in</h3></div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="mb-3" id="loginModeToggle">
                    <div class="d-flex align-items-center">
                        <div class="btn-group me-3" role="group" aria-label="Login mode">
                        <input type="radio" class="btn-check" name="loginMode" id="modeMagic" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="modeMagic">Magic link</label>

                        <input type="radio" class="btn-check" name="loginMode" id="modePassword" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="modePassword">Password</label>
                    </div>
                    </div>
                </div>

                <hr id="loginModeDivider">

                <div id="magicBlock">
                    <form method="post" action="pro_auth.php" id="loginRequestForm">
                        <input type="hidden" name="action" value="request_login_link">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" id="email" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send login link</button>
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
                            <input type="password" class="form-control" name="password" id="pwPassword" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Sign in</button>
                    </form>
                    <div id="passwordLoginMsg" class="mt-3"></div>
                </div>

                <div id="twoFactorBlock" style="display:none;">
                    <p class="text-muted">Open your authenticator app (1Password, Authy, Google Authenticator &hellip;) and enter the 6-digit code for Mail Shield.</p>
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
                    <div class="mt-3">
                        <a href="#" id="useRecoveryCodeLink">Use a recovery code instead</a>
                    </div>
                    <div class="mt-2">
                        <a href="#" id="lostAuthenticatorLink">Lost your authenticator? Sign in with an email link instead</a> &mdash; you can then turn off two-factor authentication in your profile.
                    </div>
                    <div class="mt-2">
                        <a href="#" id="cancel2faLink">Cancel</a>
                    </div>
                    <div id="twoFactorMsg" class="mt-3"></div>
                </div>
            </div>
        </div>

        <!-- Getting Started Guide -->
        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3><i class="fas fa-rocket"></i> Getting Started</h3></div>
            <div class="card-body">
                <p class="text-muted mb-3">New to Mail Shield? Follow these simple steps to set up your account:</p>
                
                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">1</span>
                    </div>
                    <div>
                        <strong>Create an Account</strong>
                        <p class="text-muted mb-0 small">Don't have an account yet? <a href="register.php">Sign up here</a> — free accounts don't need a voucher code, Pro accounts do.</p>
                    </div>
                </div>

                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">2</span>
                    </div>
                    <div>
                        <strong>Verify Your Email</strong>
                        <p class="text-muted mb-0 small">Check your inbox for a verification link. Clicking it confirms your email address and signs you in — all in the same click.</p>
                    </div>
                </div>

                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">3</span>
                    </div>
                    <div>
                        <strong>Sign In with Magic Link</strong>
                        <p class="text-muted mb-0 small">Enter your email address and click "Send login link". You'll receive a secure, one-time link in your inbox — simply click it to sign in instantly.</p>
                    </div>
                </div>

                <div class="d-flex">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">4</span>
                    </div>
                    <div>
                        <strong>Configure Your Preferences</strong>
                        <p class="text-muted mb-0 small">Once signed in, navigate to your profile settings using the <i class="fas fa-cog"></i> icon. Here you can set a password for traditional login and customize your account preferences.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(function(){
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
            $('#loginModeToggle, #loginModeDivider, #magicBlock, #passwordBlock').hide();
            resetTwoFactorField();
            $('#twoFactorMsg').html('');
            $('#twoFactorCode').val('');
            $('#rememberDeviceCheck').prop('checked', false);
            $('#twoFactorBlock').show();
            $('#twoFactorCode').trigger('focus');
        }

        function hideTwoFactorBlock(restoreMode) {
            $('#twoFactorBlock').hide();
            $('#loginModeToggle, #loginModeDivider').show();
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
            $.post('pro_auth.php', {action:'request_login_link', email:email}, function(res){
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
            $.post('pro_auth.php', { action: 'password_login', email: email, password: password }, function(res){
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
</body>
</html>
