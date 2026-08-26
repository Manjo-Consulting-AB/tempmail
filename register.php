<?php
/**
 * Registration page for new Regular/Pro accounts (ACCOUNT_TIERS.md §4).
 * Posts to pro_auth.php's register_account action - no new backend logic
 * lives here, this is purely the form and its client-side wiring.
 */
require_once 'config.php';

session_start();

// If already logged in, redirect to dashboard (same as pro_login.php:11-14)
if (isset($_SESSION['pro_user_id'])) {
    header('Location: pro.php');
    exit;
}

// Mode from ?plan=, whitelisted against 'regular' and 'pro'. Unknown or
// missing -> 'regular'. This mirrors register_account's own whitelist in
// pro_auth.php so the page and the endpoint never disagree on what a bad
// value defaults to.
$plan = $_GET['plan'] ?? 'regular';
if (!in_array($plan, ['regular', 'pro'], true)) {
    $plan = 'regular';
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
    <title>TempMail - Create Account</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1><i class="fas fa-user-plus"></i> TempMail</h1>
            <p class="lead">Create a free or Pro account</p>

            <?php require 'partials/nav.php'; ?>
        </div>

        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3>Create account</h3></div>
            <div class="card-body">

                <div class="mb-3" id="registerModeToggle">
                    <div class="d-flex align-items-center">
                        <div class="btn-group me-3" role="group" aria-label="Account type">
                        <input type="radio" class="btn-check" name="registerMode" id="modeRegular" autocomplete="off" <?php echo $plan === 'regular' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="modeRegular">Free account</label>

                        <input type="radio" class="btn-check" name="registerMode" id="modePro" autocomplete="off" <?php echo $plan === 'pro' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="modePro">Pro (requires a code)</label>
                    </div>
                    </div>
                </div>

                <hr>

                <div id="registerBlock">
                    <form id="registerForm">
                        <input type="hidden" name="plan" id="registerPlan" value="<?php echo htmlspecialchars($plan); ?>">
                        <div class="mb-3">
                            <label for="regEmail" class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" id="regEmail" autocomplete="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="regPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="regPassword" autocomplete="new-password" required>
                        </div>
                        <div class="mb-3">
                            <label for="regPasswordConfirm" class="form-label">Confirm password</label>
                            <input type="password" class="form-control" name="password_confirm" id="regPasswordConfirm" autocomplete="new-password" required>
                        </div>
                        <div class="mb-3" id="regCodeBlock" style="display:none;">
                            <label for="regCode" class="form-label">Voucher code</label>
                            <input type="text" class="form-control" name="code" id="regCode">
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="registerSubmit">Create account</button>
                    </form>
                    <div id="registerMsg" class="mt-3"></div>
                </div>

                <p class="text-muted mt-3 mb-0 small">Already have an account? <a href="pro_login.php">Sign in here</a>.</p>
            </div>
        </div>

        <!-- Getting Started Guide -->
        <div class="card mt-4 card-main-width">
            <div class="card-header"><h3><i class="fas fa-rocket"></i> Getting Started</h3></div>
            <div class="card-body">
                <p class="text-muted mb-3">Not sure which account to pick? Here's the difference:</p>

                <div class="d-flex mb-3">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;"><i class="fas fa-check"></i></span>
                    </div>
                    <div>
                        <strong>Free account</strong>
                        <p class="text-muted mb-0 small">One temporary address at a time, with a 24-hour lifetime. No voucher code needed &mdash; just verify your email.</p>
                    </div>
                </div>

                <div class="d-flex">
                    <div class="me-3">
                        <span class="badge bg-primary rounded-circle" style="width: 28px; height: 28px; line-height: 20px;"><i class="fas fa-star"></i></span>
                    </div>
                    <div>
                        <strong>Pro account</strong>
                        <p class="text-muted mb-0 small">Longer address lifetimes and extra features. Requires a voucher code until online payments launch.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(function(){
        // Minimum password length must match the server exactly
        // (register_account in pro_auth.php, mirroring pro_profile.php's
        // set_password). Client-side validation is a convenience only -
        // the server remains authoritative.
        var MIN_PASSWORD_LENGTH = 8;

        function showMode(mode) {
            if (mode === 'pro') {
                $('#regCodeBlock').show();
                $('#regCode').prop('required', true);
            } else {
                $('#regCodeBlock').hide();
                $('#regCode').prop('required', false).val('');
            }
            $('#registerPlan').val(mode);
        }

        showMode($('#modePro').is(':checked') ? 'pro' : 'regular');

        $('input[name="registerMode"]').on('change', function(){
            showMode($('#modePro').is(':checked') ? 'pro' : 'regular');
        });

        $('#registerForm').on('submit', function(e){
            e.preventDefault();

            var plan = $('#registerPlan').val();
            var email = $('#regEmail').val();
            var password = $('#regPassword').val();
            var passwordConfirm = $('#regPasswordConfirm').val();
            var code = $('#regCode').val();

            if (password !== passwordConfirm) {
                $('#registerMsg').html('<div class="alert alert-danger">Passwords do not match</div>');
                return;
            }
            if (password.length < MIN_PASSWORD_LENGTH) {
                $('#registerMsg').html('<div class="alert alert-danger">Password must be at least ' + MIN_PASSWORD_LENGTH + ' characters</div>');
                return;
            }

            $('#registerSubmit').prop('disabled', true);
            $('#registerMsg').html('<div class="alert alert-info">Creating account...</div>');

            $.post('pro_auth.php', {
                action: 'register_account',
                plan: plan,
                email: email,
                password: password,
                password_confirm: passwordConfirm,
                code: code
            }, function(res){
                if (res && res.success) {
                    // Hide the form so there's no submit button left to press
                    // again - the message is "check your email", not a
                    // completed action with something more to do here.
                    $('#registerForm').hide();
                    $('#registerMsg').html('<div class="alert alert-success">' + (res.message || 'Check your email to finish creating your account') + '</div>');
                } else {
                    $('#registerSubmit').prop('disabled', false);
                    $('#registerMsg').html('<div class="alert alert-danger">' + (res && res.error ? res.error : 'Registration failed') + '</div>');
                }
            }, 'json').fail(function(){
                $('#registerSubmit').prop('disabled', false);
                $('#registerMsg').html('<div class="alert alert-danger">Network error</div>');
            });
        });
    });
    </script>
</body>
</html>
