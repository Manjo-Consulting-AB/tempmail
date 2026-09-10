<?php
/**
 * Registration page for new accounts — free, or Pro with a voucher code
 * (ACCOUNT_TIERS.md §4). Posts to pro_auth.php's register_account action - no
 * new backend logic lives here, this is purely the form and its client-side
 * wiring.
 *
 * Shell: brief §11 keeps this page on the Bootstrap bridge (Bootstrap and
 * jQuery stay, the bridge restyles them), so this issue only replaces the page
 * shape — see the auth section at the end of assets/css/mailshield.css. Every
 * input id, name and data-* attribute, every jQuery selector, the endpoint and
 * MIN_PASSWORD_LENGTH are carried over untouched: pro_auth.php's
 * register_account mirrors the same `?plan=` whitelist and the same length
 * rule, and the regression check in this issue's acceptance criteria greps the
 * diff for exactly those.
 */
require_once 'config.php';
require_once __DIR__ . '/partials/brand.php';

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
    <title>Create your inbox · Mail Shield</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

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
                <h1 class="ms-auth__title">Create your inbox</h1>
                <p class="ms-auth__sub">Free to start. No card needed.</p>

                <div class="mb-3" id="registerModeToggle">
                    <div class="ms-auth__segment" role="group" aria-label="Account type">
                        <input type="radio" class="btn-check" name="registerMode" id="modeRegular" autocomplete="off" <?php echo $plan === 'regular' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="modeRegular">Free</label>

                        <input type="radio" class="btn-check" name="registerMode" id="modePro" autocomplete="off" <?php echo $plan === 'pro' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="modePro">Pro (voucher code)</label>
                    </div>
                </div>

                <p class="ms-auth__hint" id="regHintFree">One temporary address at a time, deleted after 24 hours.</p>
                <p class="ms-auth__hint" id="regHintPro" style="display:none;">Permanent addresses and automation. Requires a voucher code &mdash; online payment is on the way.</p>

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

                <p class="ms-auth__alt">Already have an account? <a href="pro_login.php">Sign in here</a>.</p>
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
        // Minimum password length must match the server exactly
        // (register_account in pro_auth.php, mirroring pro_profile.php's
        // set_password). Client-side validation is a convenience only -
        // the server remains authoritative.
        var MIN_PASSWORD_LENGTH = 8;

        // The status region is shared by all three fields (and the voucher
        // field when it is shown), so it is associated with them here rather
        // than in the markup: every one of those input lines carries an id, and
        // this issue's regression check forbids touching any line that does.
        // Set before first paint, so it is equivalent for assistive tech.
        $('#regEmail, #regPassword, #regPasswordConfirm, #regCode')
            .attr('aria-describedby', 'registerMsg');

        function showMode(mode) {
            if (mode === 'pro') {
                $('#regHintFree').hide();
                $('#regHintPro').show();
                $('#regCodeBlock').show();
                $('#regCode').prop('required', true);
            } else {
                $('#regHintPro').hide();
                $('#regHintFree').show();
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
