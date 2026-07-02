<?php
/**
 * Login page for magic link (pro users)
 * Handles token verification and user session
 */
require_once 'config.php';

session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['pro_user_id'])) {
    header('Location: pro.php');
    exit;
}

// Handle token from GET
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
    $error = '';
// Validate token format: hex string, 48-96 chars
if ($token && (!preg_match('/^[a-f0-9]+$/i', $token) || strlen($token) < 48 || strlen($token) > 96)) {
    $token = ''; // Invalid format, treat as no token
    $error = 'Invalid token format.';
}
if ($token) {
    $stmt = $pdo->prepare("SELECT lt.id, lt.user_id, lt.expires_at, lt.used, pu.email FROM login_tokens lt JOIN pro_users pu ON lt.user_id = pu.id WHERE lt.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $error = "Invalid or expired link.";
        } elseif ($row['used']) {
            $error = "This link has already been used.";
        } elseif (strtotime($row['expires_at']) < time()) {
            $error = "The link has expired.";
    } else {
        // Mark token as used
        $stmt = $pdo->prepare("UPDATE login_tokens SET used = 1 WHERE id = ?");
        $stmt->execute([$row['id']]);
        // Log in user
        $_SESSION['pro_user_id'] = $row['user_id'];
        $_SESSION['pro_user_email'] = $row['email'];
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
    <title>TempMail Pro - Login</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <script>
        // Only show the magic-link test URL in non-production environments
        window.showMagicLink = <?php echo (strpos($config['email']['base_url'], 'manjo.me') === false) ? 'true' : 'false'; ?>;
    </script>

</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1><i class="fas fa-user-lock"></i> TempMail Pro</h1>
            <p class="lead">Sign in to manage your Pro account</p>

            <?php require 'partials/nav.php'; ?>
        </div>

        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3>Sign in</h3></div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="mb-3">
                    <div class="d-flex align-items-center">
                        <div class="btn-group me-3" role="group" aria-label="Login mode">
                        <input type="radio" class="btn-check" name="loginMode" id="modeMagic" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="modeMagic">Magic link</label>

                        <input type="radio" class="btn-check" name="loginMode" id="modePassword" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="modePassword">Password</label>
                    </div>
                    <div>
                        <button id="showRedeemLink" class="btn btn-outline-primary">Redeem a code</button>
                    </div>
                </div>

                <hr>
                <h5 id="redeemHeading" style="display:none;">Redeem voucher</h5>
                <div id="redeemBlock" style="display:none;">
                    <form id="redeemForm">
                        <div class="mb-3">
                            <label for="redeemEmail" class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" id="redeemEmail" required />
                        </div>
                        <div class="mb-3">
                            <label for="redeemCode" class="form-label">Voucher code</label>
                            <input type="text" class="form-control" name="code" id="redeemCode" required />
                        </div>
                        <button type="submit" class="btn btn-success w-100">Redeem</button>
                    </form>
                    <div id="redeemMsg" class="mt-3"></div>
                </div>

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
            </div>
        </div>

        <!-- Getting Started Guide -->
        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3><i class="fas fa-rocket"></i> Getting Started</h3></div>
            <div class="card-body">
                <p class="text-muted mb-3">New to TempMail Pro? Follow these simple steps to set up your account:</p>
                
                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">1</span>
                    </div>
                    <div>
                        <strong>Redeem Your Code</strong>
                        <p class="text-muted mb-0 small">Click "Redeem a code" above and enter your email address along with the voucher code you received. This will create your Pro account.</p>
                    </div>
                </div>
                
                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">2</span>
                    </div>
                    <div>
                        <strong>Sign In with Magic Link</strong>
                        <p class="text-muted mb-0 small">Enter your email address and click "Send login link". You'll receive a secure, one-time link in your inbox — simply click it to sign in instantly.</p>
                    </div>
                </div>
                
                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">3</span>
                    </div>
                    <div>
                        <strong>Configure Your Preferences</strong>
                        <p class="text-muted mb-0 small">Once signed in, navigate to your profile settings using the <i class="fas fa-cog"></i> icon. Here you can customize your account preferences.</p>
                    </div>
                </div>
                
                <div class="d-flex">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;">4</span>
                    </div>
                    <div>
                        <strong>Set a Password (Optional)</strong>
                        <p class="text-muted mb-0 small">Prefer traditional login? In your profile settings, you can set a password to sign in using email and password instead of magic links.</p>
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

        // Redeem voucher handler
        $('#redeemForm').on('submit', function(e){
            e.preventDefault();
            var email = $('#redeemEmail').val();
            var code = $('#redeemCode').val();
            $('#redeemMsg').html('<div class="alert alert-info">Redeeming...</div>');
            $.post('pro_auth.php', { action: 'redeem_voucher', email: email, code: code }, function(res){
                if (res && res.success) {
                    $('#redeemMsg').html('<div class="alert alert-success">' + (res.message || 'Voucher redeemed. You can now sign in.') + '</div>');
                    
                    // After successful redeem: close redeem block, show magic link, pre-fill email
                    setTimeout(function(){
                        // Hide redeem block
                        $('#redeemBlock').hide();
                        $('#redeemHeading').hide();
                        $('#redeemMsg').html('');
                        $('#showRedeemLink').text('Redeem a code');
                        
                        // Show magic link block and ensure password block is hidden
                        $('#magicBlock').show();
                        $('#passwordBlock').hide();
                        $('#modeMagic').prop('checked', true);
                        
                        // Pre-fill the magic link email field with the redeem email
                        $('#email').val(email);
                        
                        // Focus on the email field and show success message
                        $('#loginRequestMsg').html('<div class="alert alert-success"><i class="fas fa-check-circle"></i> Account created! Enter your email to receive a login link.</div>');
                        $('#email').focus();
                    }, 1500);
                } else {
                    $('#redeemMsg').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Redemption failed') + '</div>');
                }
            }, 'json').fail(function(){
                $('#redeemMsg').html('<div class="alert alert-danger">Request failed</div>');
            });
        });

        // Toggle redeem block and heading visibility
        $('#showRedeemLink').on('click', function(e){
            e.preventDefault();
            var visible = $('#redeemBlock').is(':visible');
            if (!visible) {
                // Show redeem, hide login blocks
                $('#redeemBlock').show();
                $('#redeemHeading').show();
                $('#magicBlock').hide();
                $('#passwordBlock').hide();
                $(this).text('Hide redeem');
                setTimeout(function(){ $('#redeemEmail').focus(); }, 50);
            } else {
                // Hide redeem, restore selected login mode
                $('#redeemBlock').hide();
                $('#redeemHeading').hide();
                if ($('#modePassword').is(':checked')) {
                    $('#passwordBlock').show();
                    $('#magicBlock').hide();
                } else {
                    $('#magicBlock').show();
                    $('#passwordBlock').hide();
                }
                $(this).text('Redeem a code');
            }
        });

        // Password login handler
        $('#passwordLoginForm').on('submit', function(e){
            e.preventDefault();
            var email = $('#pwEmail').val();
            var password = $('#pwPassword').val();
            $('#passwordLoginMsg').html('<div class="alert alert-info">Signing in...</div>');
            $.post('pro_auth.php', { action: 'password_login', email: email, password: password }, function(res){
                if (res && res.success) {
                    // Redirect to dashboard
                    window.location = res.redirect || 'pro.php';
                } else {
                    $('#passwordLoginMsg').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Login failed') + '</div>');
                }
            }, 'json').fail(function(){ $('#passwordLoginMsg').html('<div class="alert alert-danger">Network error</div>'); });
        });
    });
    </script>
</body>
</html>
