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

// Canonical/OG origin, from $config and never hardcoded (§14) — same source the
// marketing shell derives its URLs from. This page stays indexable: it is the
// sign-up entry point and one of the six paths in sitemap.php.
$msOgOrigin = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msOgUrl    = $msOgOrigin . '/register.php';
$msOgImage  = $msOgOrigin . '/assets/images/og-mailshield.png';
$msTitle    = 'Create your inbox · Mail Shield';

// Every new account starts on Pro for this many days (epic #267). Read from
// $config so the page never promises a trial the backend does not grant; at 0
// the pre-trial copy is shown instead.
$msTrialDays = max(0, (int) ($config['trial']['days'] ?? 0));
$msDesc     = $msTrialDays > 0
    ? 'Create a Mail Shield account and start with ' . $msTrialDays . ' days of Pro, free: a separate inbox for the email you don\'t want in your primary one, with sticky and timed addresses and automatic clean-up.'
    : 'Create a free Mail Shield account and get a separate inbox for the email you don\'t want in your primary one. Sticky and timed addresses, with automatic clean-up.';
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
                <p class="ms-auth__sub"><?php echo $msTrialDays > 0 ? 'Includes ' . $msTrialDays . ' days of Pro, free. No card needed.' : 'Free to start. No card needed.'; ?></p>

                <div class="mb-3" id="registerModeToggle">
                    <div class="ms-auth__segment" role="group" aria-label="Account type">
                        <input type="radio" class="btn-check" name="registerMode" id="modeRegular" autocomplete="off" <?php echo $plan === 'regular' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="modeRegular">Free</label>

                        <input type="radio" class="btn-check" name="registerMode" id="modePro" autocomplete="off" <?php echo $plan === 'pro' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-secondary" for="modePro">Pro (voucher code)</label>
                    </div>
                </div>

                <?php if ($msTrialDays > 0) : ?>
                    <p class="ms-auth__hint" id="regHintFree">Your first <?php echo $msTrialDays; ?> days are on Pro &mdash; sticky addresses, automation and 1&ndash;7 day timed addresses. After that it's free: one timed address, deleted after 24 hours. One trial per email address.</p>
                <?php else : ?>
                    <p class="ms-auth__hint" id="regHintFree">One timed address at a time. The address and its messages are deleted after 24 hours.</p>
                <?php endif; ?>
                <p class="ms-auth__hint" id="regHintPro" style="display:none;">Sticky addresses and automation. Incoming mail is still temporary and is cleaned up according to your retention settings. Requires a voucher code &mdash; online payment is on the way.</p>

                <div id="registerBlock">
                    <form id="registerForm">
                        <input type="hidden" name="plan" id="registerPlan" value="<?php echo htmlspecialchars($plan); ?>">
                        <div class="mb-3">
                            <label for="regEmail" class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" id="regEmail" autocomplete="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="regPassword" class="form-label">Password</label>
                            <div class="ms-password-field">
                                <input type="password" class="form-control" name="password" id="regPassword" autocomplete="new-password" required>
                                <button type="button" class="ms-password-toggle" data-target="regPassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="regPasswordConfirm" class="form-label">Confirm password</label>
                            <div class="ms-password-field">
                                <input type="password" class="form-control" name="password_confirm" id="regPasswordConfirm" autocomplete="new-password" required>
                                <button type="button" class="ms-password-toggle" data-target="regPasswordConfirm" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </div>
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

    <script>
    /**
     * Show/hide toggles for password fields.
     *
     * Generic by design: each button names its input in `data-target`, so one
     * binding covers both fields here and a further field needs markup only.
     * The auth pages do not load the inbox script, so the binding is repeated
     * here rather than shared; it is deliberately vanilla instead of jQuery so
     * it does not queue behind the ready handler above.
     *
     * The button carries `type="button"` in the markup, without which it would
     * submit the registration form.
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
