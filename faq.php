<?php
require_once 'config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
?>
<!DOCTYPE html>
<?php
require_once 'config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FAQ - TempMail</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1><i class="fas fa-question-circle"></i> FAQ</h1>
            <p class="lead">Frequently asked questions about TempMail</p>

            <?php require 'partials/nav.php'; ?>
        </div>

        <div class="card mt-4 card-main-width">
            <div class="card-body">
                <h3>General</h3>
                <div class="mb-3">
                    <strong>What is TempMail?</strong>
                    <p class="mb-0">TempMail provides disposable email addresses you can use for registrations, verifications and one-time communication. Messages are shown in the web UI and removed automatically according to the service rules.</p>
                </div>

                <div class="mb-3">
                    <strong>How do I get a temporary address?</strong>
                    <p class="mb-0">Click the "Get Email Address" button on the homepage. A new temporary address will be generated and displayed. No signup is required for the basic service.</p>
                </div>

                <div class="mb-3">
                    <strong>How long are messages and addresses kept?</strong>
                    <p class="mb-0">Regular (non-Pro) temporary addresses and their messages are automatically deleted after 24 hours. Pro accounts can change address TTL up to a limited maximum (see Pro section).</p>
                </div>

                <h3 class="mt-4">Accounts & Pro</h3>
                <div class="mb-3">
                    <strong>How do I create a Pro account?</strong>
                    <p class="mb-0">Pro accounts are created through the Pro signup flow (redeem code or purchase). Pro users sign in using a magic link emailed to them or optionally set a password in their profile.</p>
                </div>

                <div class="mb-3">
                    <strong>What's the difference between Regular and Pro?</strong>
                    <p class="mb-0">Regular users get session-less temporary addresses with 24-hour retention. Pro users get additional features: configurable address TTL, personal addresses, persistent management, and account controls (magic link login, optional password, profile settings).</p>
                </div>

                <div class="mb-3">
                    <strong>How many addresses can I create?</strong>
                    <p class="mb-0">Basic visitors can generate temporary addresses from the homepage as needed; these are not tied to accounts and are deleted after their TTL. Pro users may have one active non-personal temporary address at a time and up to ten personal addresses that they manage from their profile.</p>
                </div>

                <h3 class="mt-4">Pro Settings</h3>
                <div class="mb-3">
                    <strong>What settings are available for Pro users?</strong>
                    <p class="mb-0">Pro users can:</p>
                    <ul>
                        <li>Set a password for direct access (optional).</li>
                        <li>Configure the default address TTL (within allowed range).</li>
                        <li>Create and manage up to ten personal addresses (long-lived).</li>
                        <li>Delete personal addresses and view address expiry information.</li>
                    </ul>
                </div>

                <div class="mb-3">
                    <strong>How long do personal addresses last?</strong>
                    <p class="mb-0">Personal addresses created by Pro users are long-lived (application default is an extended TTL — effectively persistent until explicitly deleted by the user or the account). Temporary non-personal addresses remain transient.</p>
                </div>

                <h3 class="mt-4">Security & Privacy</h3>
                <div class="mb-3">
                    <strong>Is my email content private?</strong>
                    <p class="mb-0">Messages are accessible via the web UI for the generated address. If an address is owned by a Pro account, only that account can view messages for that address. Avoid sending sensitive personal data using temporary addresses.</p>
                </div>

                <div class="mb-3">
                    <strong>How are attachments handled?</strong>
                    <p class="mb-0">Attachments are stored temporarily and download links are signed to prevent unauthorised access. Links and files expire according to the service's retention rules.</p>
                </div>

                <div class="mb-3">
                    <strong>Is Client Agent safe?</strong>
                    <p class="mb-0">Client Agent is designed with security in mind. It uses industry-standard encryption and follows best practices to protect your data. All communications are encrypted, and sensitive information is never stored in plain text. Agent runs on your own server with your data under your control.</p>
                </div>

                <div class="mb-3">
                    <strong>What does two-factor authentication (2FA) protect?</strong>
                    <p class="mb-0">2FA protects password sign-in to your Pro account: after 2FA is enabled, signing in with your password also requires a 6-digit code from an authenticator app. It does not add a step to email sign-in links — a magic link already proves you have access to the inbox, which is itself a second factor.</p>
                </div>

                <div class="mb-3">
                    <strong>Which authenticator apps are supported?</strong>
                    <p class="mb-0">Any standard TOTP authenticator app works, including 1Password, Authy, and Google Authenticator. Set it up from the two-factor authentication section of your profile by scanning the QR code or entering the key manually.</p>
                </div>

                <div class="mb-3">
                    <strong>What if I lose my phone or authenticator app?</strong>
                    <p class="mb-0">You're not locked out. Sign in using your email login link (magic link) as usual — it works even with 2FA enabled — and then turn off two-factor authentication from your profile. From there you can set up 2FA again on a new device, or leave it off. You can also use one of the one-time recovery codes shown when you enrolled, if you saved them.</p>
                </div>

                <h3 class="mt-4">Troubleshooting</h3>
                <div class="mb-3">
                    <strong>I didn't receive an expected message — what now?</strong>
                    <p class="mb-0">Check that you used the correct full address (local part + domain). For Pro-owned addresses, ensure you are signed into the owning Pro account when fetching messages. If the server is temporarily unable to fetch messages from IMAP, try refreshing (the page provides a refresh action).</p>
                </div>

                <div class="mb-3">
                    <strong>Contact / Support</strong>
                    <p class="mb-0">For questions about Pro accounts or billing, use the contact information on the site or reach out to the service operator. This FAQ is intended for general guidance; account-specific issues may require direct support.</p>
                </div>

            </div>
        </div>

        <footer class="text-center mt-5 py-4">
            <div class="container">
                <p class="text-light mb-0 mt-1">
                    <small>&copy; <?php echo date('Y'); ?> Manjo Consulting AB | v <?php echo htmlspecialchars($config['app']['version'] ?? ''); ?></small>
                </p>
                <div class="mt-2"></div>
            </div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
