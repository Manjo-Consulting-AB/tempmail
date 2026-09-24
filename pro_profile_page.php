<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    header('Location: pro_login.php');
    exit;
}

$userEmail = $_SESSION['pro_user_email'] ?? '';

// Tier for the Plan card's one-line status. proUserIsPro() is the single
// entitlement source (see documentaion/ACCOUNT_TIERS.md) and fails closed, so a
// lookup error states the free plan rather than claiming Pro.
$accountIsPro = proUserIsPro((int) $_SESSION['pro_user_id']);

// When a Pro account's time runs out (the 60-day trial of epic #267, or a
// voucher), for display only: entitlement is still decided by proUserIsPro()
// above. A time-limited Pro account keeps the voucher field, because
// redeemVoucherForEmail() adds a code's days after the current end date rather
// than replacing it - without the field, a trial account could not stay on Pro.
$accountProExpiresAt = null;
if ($accountIsPro) {
    try {
        $stmt = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $_SESSION['pro_user_id']]);
        $expires = $stmt->fetchColumn();
        if (is_string($expires) && $expires !== '' && strtotime($expires) !== false) {
            $accountProExpiresAt = $expires;
        }
    } catch (Exception $e) {
        // Fall back to the plain "You're on Pro." note.
        logMessage('WARNING', 'Failed reading pro_expires_at for the Plan card', ['user_id' => (int) $_SESSION['pro_user_id'], 'error' => $e->getMessage()]);
    }
}

// Whether the account has a Paddle payment linked to it (paddle_webhook.php),
// which is what the Plan card's "Manage billing" button needs: the portal is
// opened for that payment's Paddle customer. Only existence is read here; the
// portal URL itself is minted per click by pro_profile.php (billing_portal).
// Fails closed — no Paddle tables yet, or an error, just hides the button.
$hasPaddleBilling = false;
try {
    require_once __DIR__ . '/paddle_sync.php';
    $hasPaddleBilling = paddlePortalTarget($pdo, (int) $_SESSION['pro_user_id']) !== null;
} catch (Exception $e) {
    logMessage('WARNING', 'Failed checking for Paddle billing', ['user_id' => (int) $_SESSION['pro_user_id'], 'error' => $e->getMessage()]);
}

// Whether the account owns any webhook at all — the existence check behind the
// per-address Pause/Start control (#251 step 5). kind is deliberately not
// consulted: Pushover is just another hook now, and a paused hook is still a
// configured one, so the control stays visible. Only the existence of a row is
// read here, never any hook's config or secret.
$hasAnyWebhook = false;
try {
    $stmt = $pdo->prepare("SELECT 1 FROM pro_webhooks WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int) $_SESSION['pro_user_id']]);
    $hasAnyWebhook = (bool) $stmt->fetchColumn();
} catch (Exception $e) {
    // Fail closed: better no control than one whose toggle cannot work.
    logMessage('ERROR', 'Failed checking for webhooks', ['user_id' => (int) $_SESSION['pro_user_id'], 'error' => $e->getMessage()]);
}

// Whether the per-hook address routing exists yet (#251). All three columns
// arrive together with migrate_webhook_addresses.php; until they do, the page
// shows neither the per-hook checkboxes nor the per-address control, and says
// so in one line under the webhook list.
$hookRoutingAvailable = tableHasColumn('pro_webhook_addresses', 'webhook_id')
    && tableHasColumn('pro_webhooks', 'include_temporary')
    && tableHasColumn('temp_emails', 'hooks_paused');

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
    <meta name="robots" content="noindex, nofollow">
    <title>Settings · Mail Shield</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        #tfaQrContainer svg { max-width: 200px; width: 100%; height: auto; }
    </style>
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <?php require 'partials/nav.php'; ?>
        </div>

        <!-- Settings: six groups behind a rail of anchors — not a stack of
             full-width cards, and deliberately not tabs. Anchored navigation
             keeps every section in the document flow, so no control that this
             page's own scripts bind by id can end up inside a panel that is
             hidden when they run; deep links and find-in-page keep working too. -->
        <main class="ms-settings">
            <h1 class="ms-settings__title">Settings</h1>

            <div class="ms-settings__grid">
                <nav class="ms-settings__rail" aria-label="Settings sections">
                    <ul class="ms-settings__rail-list">
                        <li><a class="ms-settings__rail-link" href="#settings-account" aria-current="location">Account</a></li>
                        <li><a class="ms-settings__rail-link" href="#settings-addresses">Addresses</a></li>
                        <li><a class="ms-settings__rail-link" href="#settings-automation">Automation</a></li>
                        <li><a class="ms-settings__rail-link" href="#settings-security">Security</a></li>
                        <li><a class="ms-settings__rail-link" href="#settings-agent">Client Agent</a></li>
                        <li><a class="ms-settings__rail-link" href="#settings-delete">Delete account</a></li>
                    </ul>
                </nav>

                <div class="ms-settings__content">
                    <!-- Email, password and signing-key outcomes all report here.
                         It sticks under the nav while it has content, so an error
                         raised from a group far down the page is still read. -->
                    <div id="proProfileAlert" class="ms-settings__alert"></div>

                    <form id="proProfileForm">
                        <section class="ms-settings__section" id="settings-account">
                            <h2 class="ms-settings__section-title">Account</h2>
                            <p class="ms-settings__section-lede">Who you are here, and what your plan covers.</p>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Plan</h3>
                                <p class="ms-card__desc">Temporary email is free. Pro adds permanent addresses, webhooks, digest emails, the RSS feed and the client agent.</p>
                                <?php if ($accountIsPro && $accountProExpiresAt !== null) : ?>
                                <p class="ms-card__note">You're on Pro until <?php echo htmlspecialchars(date('j F Y', strtotime($accountProExpiresAt)), ENT_QUOTES, 'UTF-8'); ?>.</p>
                                <?php elseif ($accountIsPro) : ?>
                                <p class="ms-card__note">You're on Pro.</p>
                                <?php else : ?>
                                <p class="ms-card__note">You're on the free plan.</p>
                                <?php endif; ?>
                                <?php if ($hasPaddleBilling) : ?>
                                <div class="mb-3">
                                    <p class="form-text">Cancel your subscription, change your payment method or download invoices on Paddle, which handles payments for Mail Shield.</p>
                                    <button type="button" id="billingPortalBtn" class="btn btn-secondary">Manage billing</button>
                                </div>
                                <?php endif; ?>
                                <div id="upgradeToProSection" class="d-none">
                                    <?php if ($accountProExpiresAt !== null) : ?>
                                    <p class="form-text">Stay on Pro: redeem a voucher code and its time is added after your current end date. Online payment is on the way.</p>
                                    <?php else : ?>
                                    <p class="form-text">Redeem a voucher code to switch to Pro. Online payment is on the way.</p>
                                    <?php endif; ?>
                                    <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
                                        <input type="text" id="voucherCodeInput" class="form-control" placeholder="Voucher code" style="max-width:220px;" />
                                        <button type="button" id="redeemVoucherBtn" class="btn btn-primary"><?php echo $accountProExpiresAt !== null ? 'Extend' : 'Upgrade'; ?></button>
                                    </div>
                                    <div id="voucherMsg" class="mt-2"></div>
                                </div>
                            </div>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Email address</h3>
                                <p class="ms-card__desc">Where sign-in links, digests and account notices are sent.</p>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" id="proEmail" />
                                </div>
                                <button type="button" id="saveProfileEmailBtn" class="btn btn-primary">Save email</button>
                            </div>

                        </section>

                        <section class="ms-settings__section" id="settings-addresses">
                            <h2 class="ms-settings__section-title">Addresses</h2>
                            <p class="ms-settings__section-lede">The addresses that keep working, alongside the temporary ones you throw away.</p>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Personal addresses</h3>
                                <p class="ms-card__desc">Create up to 10 personal addresses and manage them from one inbox.</p>
                                <div class="d-flex align-items-center mb-2 flex-wrap">
                                    <div class="input-group" style="max-width:420px;">
                                        <input id="personalLocal" class="form-control" placeholder="yourname" aria-label="local part" />
                                        <span class="input-group-text">@<?php echo htmlspecialchars($config['email']['domain']); ?></span>
                                    </div>
                                    <button type="button" id="createPersonalBtn" class="btn btn-primary btn-sm btn-create-personal ms-3">Create</button>
                                    <div id="personalMsg" style="margin-left:10px; color:#9ecbff; margin-top:6px;"></div>
                                </div>
                                <div>
                                    <span id="personalCounterLabel" class="text-muted">You have</span>
                                    <span id="personalCounterBadge" class="badge bg-secondary ms-2">0/10</span>
                                </div>
                                <div id="personalList" class="mt-3"></div>
                                <div id="personalCreateProNote" class="form-text text-muted d-none">Creating personal addresses requires a Pro account. Existing addresses can still be viewed and deleted.</div>
                                <div id="personalFeedProNote" class="form-text text-muted d-none">A per-address RSS feed requires a Pro account. Deleting addresses stays available.</div>
                            </div>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Default lifetime</h3>
                                <p class="ms-card__desc">How long a newly generated temporary address and its mail stay alive (1-7 days).</p>
                                <div class="d-flex align-items-center">
                                    <select id="ttlSelect" class="form-select" style="width:120px;"></select>
                                    <div id="ttlMsg" style="margin-left:10px; color:#9ecbff;"></div>
                                </div>
                                <div id="ttlProNote" class="form-text text-muted d-none">Regular accounts use a fixed 24-hour address lifetime. Upgrade to Pro to choose 1-7 days.</div>
                            </div>
                        </section>

                        <section class="ms-settings__section" id="settings-automation">
                            <h2 class="ms-settings__section-title">Automation</h2>
                            <p class="ms-settings__section-lede">Send your mail onward as it arrives, or read it somewhere else entirely.</p>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Webhooks</h3>
                                <p class="ms-card__desc">POST every incoming message as JSON to your own endpoint, or push it to your phone with Pushover.</p>
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
                                    <div class="form-text">A JSON object, sent as-is. Pushover: every key goes to the Pushover API — <code>token</code> and <code>user</code> are required, add e.g. <code>device</code>, <code>sound</code> or <code>priority</code>. Generic: the keys are merged into the JSON body and replace ours where the names match, except <code>headers</code>, an object sent as HTTP headers, e.g. <code>{"headers":{"Authorization":"Bearer ..."}}</code>.</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Secret (optional)</label>
                                    <input id="whSecret" class="form-control" placeholder="Optional signing secret" />
                                    <div class="form-text">If set, requests will include header <code>X-TempMail-Signature</code> with sha256 HMAC.</div>
                                </div>
                                <div class="mb-2">
                                    <button id="whCreateBtn" class="btn btn-primary">Create webhook</button>
                                </div>
                                <div id="whProNote" class="form-text text-muted d-none">Webhooks require a Pro account.</div>
                                <div id="whMsg" class="mt-2"></div>

                                <h4 class="ms-card__subtitle">Your webhooks</h4>
                                <div id="webhookList"></div>
                            </div>

                            <div class="ms-card">
                                <h3 class="ms-card__title">RSS feed</h3>
                                <p class="ms-card__desc">A private RSS feed covering <strong>all</strong> of your addresses, read in any feed reader. For a feed limited to one address, use the RSS button on that address under Personal addresses.</p>
                                <div class="input-group">
                                    <input type="text" id="feedUrlInput" class="form-control" placeholder="(loading...)" readonly />
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Feed actions">
                                        <button id="copyFeedToken" class="btn btn-sm btn-outline-secondary">Copy</button>
                                        <button id="openFeedBtn" class="btn btn-sm btn-outline-secondary">Open</button>
                                        <button id="regenFeedToken" class="btn btn-sm btn-danger">Regenerate</button>
                                    </div>
                                </div>
                                <div class="form-text">Keep the token part of the URL secret. Subscribe using the full URL shown above.</div>
                                <div id="feedProNote" class="form-text text-muted d-none">The RSS feed requires a Pro account.</div>
                            </div>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Digest emails</h3>
                                <p class="ms-card__desc">A periodic summary of what arrived, sent to your real inbox.</p>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="digestEnabled" />
                                    <label class="form-check-label" for="digestEnabled">Enable digest emails</label>
                                </div>
                                <div class="mt-3">
                                    <label class="form-label">Frequency (days)</label>
                                    <select id="digestFrequency" class="form-select" style="width:150px;">
                                    </select>
                                </div>
                                <div class="mt-3 d-flex flex-wrap" style="gap:12px; align-items:center;">
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
                        </section>

                        <section class="ms-settings__section" id="settings-security">
                            <h2 class="ms-settings__section-title">Security</h2>
                            <p class="ms-settings__section-lede">Codes, devices, and how a password sign-in is verified.</p>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Two-factor authentication</h3>
                                <p class="ms-card__desc">A code from your authenticator app, on top of your password.</p>
                                <div id="twoFactorSection">
                                    <p id="tfaStatusLine" class="mb-2">Loading status...</p>
                                    <p class="form-text">Protects password sign-in with a code from your authenticator app. Email sign-in links already require access to your inbox and keep working as before.</p>
                                    <p class="form-text">Lost your authenticator? You can always sign in with your email login link instead, then turn off two-factor authentication here.</p>
                                    <div id="tfaNoPasswordNote" class="alert alert-info d-none">
                                        Two-factor authentication only takes effect once you have a password set — <a href="#proPassword">set one below</a> to activate protection after enrolling.
                                    </div>
                                    <div id="tfaAlert"></div>

                                    <div id="tfaDisabledState" class="d-none">
                                        <button type="button" id="tfaEnableBtn" class="btn btn-primary">Enable 2FA</button>
                                    </div>

                                    <div id="tfaEnrollStep" class="d-none">
                                        <p>Scan with 1Password, Authy, Google Authenticator, or a similar app.</p>
                                        <div id="tfaQrContainer" class="mb-2"></div>
                                        <details class="mb-3">
                                            <summary style="cursor:pointer;">Can't scan?</summary>
                                            <div class="mt-2 d-flex align-items-center flex-wrap" style="gap:8px;">
                                                <code id="tfaManualKey"></code>
                                                <button type="button" id="tfaCopyKeyBtn" class="btn btn-sm btn-outline-secondary">Copy</button>
                                            </div>
                                        </details>
                                        <div class="mb-2">
                                            <label class="form-label" for="tfaCodeInput">Enter the 6-digit code from your app</label>
                                            <input type="text" class="form-control" id="tfaCodeInput" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" style="max-width:160px;">
                                        </div>
                                        <button type="button" id="tfaConfirmBtn" class="btn btn-primary">Confirm and enable</button>
                                        <button type="button" id="tfaCancelEnrollBtn" class="btn btn-link">Cancel</button>
                                    </div>

                                    <div id="tfaRecoveryCodesBox" class="d-none">
                                        <div class="alert alert-warning">
                                            <strong>Save these recovery codes now.</strong> They are shown this one time only. Store them somewhere safe — each one can be used once if you lose access to your authenticator app.
                                        </div>
                                        <pre id="tfaRecoveryCodesList" class="p-3 border rounded" style="white-space:pre-wrap; word-break:break-word;"></pre>
                                        <div class="mb-2">
                                            <button type="button" id="tfaCopyRecoveryBtn" class="btn btn-sm btn-outline-secondary">Copy all</button>
                                            <button type="button" id="tfaDownloadRecoveryBtn" class="btn btn-sm btn-outline-secondary">Download as .txt</button>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="tfaSavedCodesCheck">
                                            <label class="form-check-label" for="tfaSavedCodesCheck">I have saved my codes</label>
                                        </div>
                                        <button type="button" id="tfaCloseRecoveryBtn" class="btn btn-primary" disabled>Done</button>
                                    </div>

                                    <div id="tfaEnabledState" class="d-none">
                                        <div id="tfaLowCodesWarning" class="alert alert-warning d-none">
                                            You have <span id="tfaCodesLeftCount"></span> recovery codes left.
                                            <button type="button" id="tfaRegenBtn" class="btn btn-sm btn-warning ms-2">Generate new codes</button>
                                        </div>
                                        <button type="button" id="tfaDisableBtn" class="btn btn-outline-danger">Disable 2FA</button>

                                        <div id="tfaTrustedDevicesSection" class="mt-4">
                                            <h4 class="ms-card__subtitle">Trusted devices</h4>
                                            <p class="form-text">Browsers where you checked "Remember this browser" at sign-in skip the code for 30 days. Revoke a device to require the code there again.</p>
                                            <div id="tfaTrustedDevicesAlert"></div>
                                            <p id="tfaTrustedDevicesEmpty" class="text-muted d-none">No trusted devices.</p>
                                            <ul id="tfaTrustedDevicesList" class="list-group mb-2"></ul>
                                            <button type="button" id="tfaRevokeAllDevicesBtn" class="btn btn-sm btn-outline-danger d-none">Revoke all</button>
                                        </div>
                                    </div>

                                    <div id="tfaRegenForm" class="d-none mt-2">
                                        <label class="form-label" for="tfaRegenCodeInput">Enter a current code to generate new recovery codes</label>
                                        <input type="text" class="form-control mb-2" id="tfaRegenCodeInput" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" style="max-width:160px;">
                                        <button type="button" id="tfaRegenConfirmBtn" class="btn btn-primary btn-sm">Generate</button>
                                        <button type="button" id="tfaRegenCancelBtn" class="btn btn-link btn-sm">Cancel</button>
                                    </div>

                                    <div id="tfaDisableForm" class="d-none mt-2">
                                        <div id="tfaDisablePasswordField" class="mb-2">
                                            <label class="form-label" for="tfaDisablePasswordInput">Enter your password to confirm</label>
                                            <!-- The width constraint sits on the wrapper, not the
                                                 field: the toggle is positioned against the wrapper,
                                                 so the two have to be the same box. -->
                                            <div class="ms-password-field" style="max-width:260px;">
                                                <input type="password" class="form-control" id="tfaDisablePasswordInput">
                                                <button type="button" class="ms-password-toggle" data-target="tfaDisablePasswordInput" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                            </div>
                                        </div>
                                        <button type="button" id="tfaDisableConfirmBtn" class="btn btn-danger btn-sm">Disable 2FA</button>
                                        <button type="button" id="tfaDisableCancelBtn" class="btn btn-link btn-sm">Cancel</button>
                                    </div>
                                </div>
                            </div>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Password</h3>
                                <p class="ms-card__desc">Set one to sign in without waiting for an email link.</p>
                                <!-- Current-password field removed: users can change password without supplying previous password -->
                                <div class="mb-3">
                                    <label class="form-label">New password</label>
                                    <div class="ms-password-field">
                                        <input type="password" class="form-control" id="proPassword" />
                                        <button type="button" class="ms-password-toggle" data-target="proPassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm password</label>
                                    <div class="ms-password-field">
                                        <input type="password" class="form-control" id="proPasswordConfirm" />
                                        <button type="button" class="ms-password-toggle" data-target="proPasswordConfirm" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <button type="button" id="saveProfilePasswordBtn" class="btn btn-secondary">Set password</button>
                            </div>
                        </section>

                        <section class="ms-settings__section" id="settings-agent">
                            <h2 class="ms-settings__section-title">Client Agent</h2>
                            <p class="ms-settings__section-lede">Keys for the agent you run on your own mail server.</p>

                            <div class="ms-card">
                                <h3 class="ms-card__title">Client signing keys</h3>
                                <p class="ms-card__desc">The agent verifies signed list-sync payloads against these keys. Rotate them if one may have leaked.</p>
                                <button type="button" id="rotateSigningKeysBtn" class="btn btn-primary">Rotate signing keys</button>
                                <div id="signingKeysProNote" class="form-text text-muted d-none">Client signing keys require a Pro account.</div>
                            </div>
                        </section>

                        <section class="ms-settings__section" id="settings-delete">
                            <h2 class="ms-settings__section-title">Delete account</h2>

                            <div class="ms-card">
                                <p class="ms-card__desc">Permanently delete your account and all associated Pro settings. This cannot be undone.</p>
                                <p class="ms-card__desc">A confirmation email with a deletion link is sent to your address first, so nothing happens until you click it.</p>
                                <button type="button" id="deleteAccountBtn" class="btn btn-danger">Delete account</button>
                            </div>
                        </section>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- NOTE: app.js is intentionally NOT included here to avoid auto-refresh email polling -->
    <script>
        // On page load, fetch profile and populate (reuse same endpoints)
        $(function(){
            $('#proProfileAlert').html('<div class="alert alert-info">Loading profile...</div>');

            // Assume Pro until get_profile says otherwise, so nothing gets disabled/shown
            // before we actually know the account tier (avoids a flash of the wrong state,
            // and matches today's reality where existing accounts are Pro).
            var isProAccount = true;

            // Whether the account has any webhook at all (#251 step 5), rendered
            // server-side. This is the existence check, not any hook's paused
            // state, and it is not the address's own pause — that comes per row
            // from list_personal. It is kept in step with the webhook list
            // afterwards, so creating or deleting the account's last webhook on
            // this page shows or hides the address controls without a reload.
            var hasAnyWebhook = <?php echo $hasAnyWebhook ? 'true' : 'false'; ?>;

            // Whether the routing schema is in place, also rendered server-side.
            // With it off the page renders no checkboxes and no row control, and
            // says why under the webhook list.
            var hookRoutingAvailable = <?php echo $hookRoutingAvailable ? 'true' : 'false'; ?>;

            // The two lists the page renders from, shared because each one's
            // markup embeds the other's state: a hook's "Triggers for" group
            // lists the personal addresses, and each of those rows shows the
            // address' pause hint. Either arriving alone re-renders both.
            var personalAddresses = [];
            var lastWebhooks = [];

            // Rendered from $accountProExpiresAt above: true for a Pro account
            // with an end date, i.e. one that can still extend with a voucher.
            var proHasExpiry = <?php echo $accountProExpiresAt !== null ? 'true' : 'false'; ?>;

            // Gray out the Pro-only sections for Regular accounts. Called once, from the
            // get_profile callback below, before anything else on the page runs its own
            // (independent) load calls.
            function applyProGating(isPro) {
                isProAccount = isPro;
                if (isPro) {
                    // A time-limited Pro account (trial or voucher) keeps the
                    // voucher field so it can extend; lifetime Pro has no use for it.
                    $('#upgradeToProSection').toggleClass('d-none', !proHasExpiry);
                    return;
                }
                $('#upgradeToProSection').removeClass('d-none');

                // Default lifetime: fixed to 1 day for Regular
                $('#ttlSelect').prop('disabled', true);
                $('#ttlProNote').removeClass('d-none');

                // Digest emails: Pro-only regardless of TTL
                $('#digestEnabled').prop('checked', false).prop('disabled', true);
                $('#digestFrequency, #digestHour, #digestTz').prop('disabled', true);
                $('#digestNote').text('Digest emails require a Pro account.');

                // Personal addresses: creation is Pro-only (list/delete stay available)
                $('#personalLocal').prop('disabled', true);
                $('#createPersonalBtn').prop('disabled', true);
                $('#personalCreateProNote').removeClass('d-none');

                // Per-address RSS feeds (#162): Pro-only. Rows rendered before this
                // ran are caught here; rows rendered after it read isProAccount.
                // Deleting an address stays enabled either way.
                $('.ms-address-row__feed').prop('disabled', true);
                $('#personalFeedProNote').removeClass('d-none');

                // Per-address hook control (#251 step 5): same Pro-only rule,
                // same two paths — rows already in the DOM are caught here,
                // later ones read isProAccount. The per-hook address checkboxes
                // are built by renderWebhooks(), which reads isProAccount too.
                $('.ms-address-row__hooks').prop('disabled', true);
                $('.ms-hook-routing input').prop('disabled', true);

                // Webhooks
                $('#whName, #whKind, #whUrl, #whConfig, #whSecret, #whCreateBtn').prop('disabled', true);
                $('#whProNote').removeClass('d-none');

                // Client signing keys
                $('#rotateSigningKeysBtn').prop('disabled', true);
                $('#signingKeysProNote').removeClass('d-none');

                // Pro RSS Feed
                $('#copyFeedToken, #openFeedBtn, #regenFeedToken').prop('disabled', true);
                $('#feedProNote').removeClass('d-none');
            }

            $('#redeemVoucherBtn').on('click', function(){
                var code = $.trim($('#voucherCodeInput').val() || '');
                var $msg = $('#voucherMsg');
                if (!code) {
                    $msg.html('<div class="alert alert-danger">Enter a voucher code</div>');
                    return;
                }
                var $btn = $(this).prop('disabled', true);
                $msg.html('<div class="alert alert-info">Checking code...</div>');
                $.post('pro_profile.php', { action: 'upgrade_with_voucher', code: code }, function(res){
                    if (res && res.success) {
                        $msg.html('<div class="alert alert-success">' + (proHasExpiry ? 'Pro extended! Reloading...' : 'Upgraded to Pro! Reloading...') + '</div>');
                        setTimeout(function(){ window.location.reload(); }, 1200);
                    } else {
                        $btn.prop('disabled', false);
                        $msg.html('<div class="alert alert-danger">' + $('<div>').text((res && res.error) ? res.error : 'Could not redeem code').html() + '</div>');
                    }
                }, 'json').fail(function(){
                    $btn.prop('disabled', false);
                    $msg.html('<div class="alert alert-danger">Network error</div>');
                });
            });

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

            // Two-factor authentication (TOTP)
            function tfaAlert(type, msg) {
                $('#tfaAlert').empty().append($('<div>').addClass('alert alert-' + type).text(msg));
            }

            var tfaState = {};

            function tfaResetPanels() {
                $('#tfaDisabledState, #tfaEnrollStep, #tfaRecoveryCodesBox, #tfaEnabledState, #tfaRegenForm, #tfaDisableForm').addClass('d-none');
            }

            function tfaRenderStatus(st) {
                tfaState = st;
                tfaResetPanels();
                if (!st.has_password) {
                    $('#tfaNoPasswordNote').removeClass('d-none');
                } else {
                    $('#tfaNoPasswordNote').addClass('d-none');
                }

                if (st.enabled) {
                    var left = parseInt(st.recovery_codes_left || 0, 10);
                    var text = 'Active';
                    if (st.confirmed_at) {
                        var d = new Date(st.confirmed_at + ' UTC');
                        var dd = String(d.getUTCDate()).padStart(2, '0');
                        var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                        var yyyy = d.getUTCFullYear();
                        text += ' since ' + dd + '/' + mm + '/' + yyyy;
                    }
                    text += ' — ' + left + (left === 1 ? ' recovery code left' : ' recovery codes left');
                    $('#tfaStatusLine').text(text);
                    $('#tfaCodesLeftCount').text(left);
                    $('#tfaLowCodesWarning').toggleClass('d-none', left > 3);
                    $('#tfaEnabledState').removeClass('d-none');
                    tfaLoadTrustedDevices();
                } else if (st.pending) {
                    $('#tfaStatusLine').text('Setup started, not yet confirmed');
                    $('#tfaDisabledState').removeClass('d-none');
                } else {
                    $('#tfaStatusLine').text('Disabled');
                    $('#tfaDisabledState').removeClass('d-none');
                }
            }

            function tfaLoadStatus() {
                $.post('pro_profile.php', { action: 'totp_status' }, function(res){
                    if (res && res.success) {
                        tfaRenderStatus(res);
                    } else {
                        $('#tfaStatusLine').text('Could not load status');
                    }
                }, 'json').fail(function(){
                    $('#tfaStatusLine').text('Network error loading status');
                });
            }

            $('#tfaEnableBtn').on('click', function(){
                tfaAlert('info', 'Starting setup...');
                $.post('pro_profile.php', { action: 'totp_begin_enroll' }, function(res){
                    if (res && res.success) {
                        $('#tfaAlert').empty();
                        tfaResetPanels();
                        $('#tfaEnrollStep').removeClass('d-none');
                        if (res.qr_svg) {
                            // Server-rendered SVG derived from our own otpauth URI, not user input.
                            $('#tfaQrContainer').html(res.qr_svg);
                            $('#tfaEnrollStep details').prop('open', false);
                        } else {
                            $('#tfaQrContainer').empty().append($('<p>').addClass('text-muted').text('QR code unavailable — enter the key manually below.'));
                            $('#tfaEnrollStep details').prop('open', true);
                        }
                        $('#tfaManualKey').text(res.manual_key || '');
                        $('#tfaCodeInput').val('').trigger('focus');
                    } else {
                        tfaAlert('danger', (res && res.error) ? res.error : 'Could not start setup');
                    }
                }, 'json').fail(function(){ tfaAlert('danger', 'Network error'); });
            });

            $('#tfaCopyKeyBtn').on('click', function(){
                var key = ($('#tfaManualKey').text() || '').replace(/\s+/g, '');
                if (!key) return;
                navigator.clipboard && navigator.clipboard.writeText(key).catch(function(){
                    alert('Could not copy to clipboard');
                });
            });

            $('#tfaCancelEnrollBtn').on('click', function(){
                $('#tfaEnrollStep').addClass('d-none');
                $('#tfaCodeInput').val('');
                $('#tfaManualKey').empty();
                $('#tfaQrContainer').empty();
                $('#tfaAlert').empty();
                tfaLoadStatus();
            });

            $('#tfaConfirmBtn').on('click', function(){
                var code = ($('#tfaCodeInput').val() || '').trim();
                if (!code) { tfaAlert('danger', 'Enter the 6-digit code'); return; }
                tfaAlert('info', 'Confirming...');
                $.post('pro_profile.php', { action: 'totp_confirm_enroll', code: code }, function(res){
                    if (res && res.success) {
                        $('#tfaAlert').empty();
                        $('#tfaEnrollStep').addClass('d-none');
                        $('#tfaCodeInput').val('');
                        $('#tfaManualKey').empty();
                        $('#tfaQrContainer').empty();
                        tfaShowRecoveryCodes(res.recovery_codes || []);
                    } else {
                        tfaAlert('danger', (res && res.error) ? res.error : 'Could not confirm code');
                    }
                }, 'json').fail(function(){ tfaAlert('danger', 'Network error'); });
            });

            function tfaShowRecoveryCodes(codes) {
                var $list = $('#tfaRecoveryCodesList').empty();
                codes.forEach(function(c){
                    $list.append(document.createTextNode(c + '\n'));
                });
                $('#tfaSavedCodesCheck').prop('checked', false);
                $('#tfaCloseRecoveryBtn').prop('disabled', true);
                $('#tfaRecoveryCodesBox').removeClass('d-none').data('codes', codes);
            }

            $('#tfaSavedCodesCheck').on('change', function(){
                $('#tfaCloseRecoveryBtn').prop('disabled', !this.checked);
            });

            $('#tfaCopyRecoveryBtn').on('click', function(){
                var codes = $('#tfaRecoveryCodesBox').data('codes') || [];
                if (!codes.length) return;
                navigator.clipboard && navigator.clipboard.writeText(codes.join('\n')).catch(function(){
                    alert('Could not copy to clipboard');
                });
            });

            $('#tfaDownloadRecoveryBtn').on('click', function(){
                var codes = $('#tfaRecoveryCodesBox').data('codes') || [];
                if (!codes.length) return;
                var blob = new Blob([codes.join('\n') + '\n'], { type: 'text/plain' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'tempmail-recovery-codes.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
            });

            $('#tfaCloseRecoveryBtn').on('click', function(){
                $('#tfaRecoveryCodesBox').addClass('d-none').removeData('codes');
                $('#tfaRecoveryCodesList').empty();
                tfaLoadStatus();
            });

            $('#tfaRegenBtn').on('click', function(){
                $('#tfaRegenForm').removeClass('d-none');
                $('#tfaRegenCodeInput').val('').trigger('focus');
            });

            $('#tfaRegenCancelBtn').on('click', function(){
                $('#tfaRegenForm').addClass('d-none');
                $('#tfaRegenCodeInput').val('');
            });

            $('#tfaRegenConfirmBtn').on('click', function(){
                var code = ($('#tfaRegenCodeInput').val() || '').trim();
                if (!code) { tfaAlert('danger', 'Enter a current code'); return; }
                tfaAlert('info', 'Generating new codes...');
                $.post('pro_profile.php', { action: 'totp_recovery_regenerate', code: code }, function(res){
                    if (res && res.success) {
                        $('#tfaAlert').empty();
                        $('#tfaRegenForm').addClass('d-none');
                        $('#tfaRegenCodeInput').val('');
                        $('#tfaEnabledState').addClass('d-none');
                        tfaShowRecoveryCodes(res.recovery_codes || []);
                    } else {
                        tfaAlert('danger', (res && res.error) ? res.error : 'Could not generate new codes');
                    }
                }, 'json').fail(function(){ tfaAlert('danger', 'Network error'); });
            });

            $('#tfaDisableBtn').on('click', function(){
                $('#tfaDisableForm').removeClass('d-none');
                $('#tfaDisablePasswordField').toggleClass('d-none', !tfaState.has_password);
                $('#tfaDisablePasswordInput').val('').trigger('focus');
            });

            $('#tfaDisableCancelBtn').on('click', function(){
                $('#tfaDisableForm').addClass('d-none');
                $('#tfaDisablePasswordInput').val('');
            });

            $('#tfaDisableConfirmBtn').on('click', function(){
                if (!window.confirm('Disable two-factor authentication?')) return;
                var post = { action: 'totp_disable' };
                if (tfaState.has_password) {
                    post.password = $('#tfaDisablePasswordInput').val();
                }
                tfaAlert('info', 'Disabling...');
                $.post('pro_profile.php', post, function(res){
                    if (res && res.success) {
                        $('#tfaDisableForm').addClass('d-none');
                        $('#tfaDisablePasswordInput').val('');
                        tfaAlert('success', 'Two-factor authentication disabled');
                        tfaLoadStatus();
                    } else {
                        tfaAlert('danger', (res && res.error) ? res.error : 'Could not disable two-factor authentication');
                    }
                }, 'json').fail(function(){ tfaAlert('danger', 'Network error'); });
            });

            function tfaFormatDate(s) {
                if (!s) return '';
                var d = new Date(s + ' UTC');
                var dd = String(d.getUTCDate()).padStart(2, '0');
                var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                var yyyy = d.getUTCFullYear();
                return dd + '/' + mm + '/' + yyyy;
            }

            function tfaRenderTrustedDevices(devices) {
                var $list = $('#tfaTrustedDevicesList').empty();
                $('#tfaTrustedDevicesEmpty').toggleClass('d-none', devices.length > 0);
                $('#tfaRevokeAllDevicesBtn').toggleClass('d-none', devices.length === 0);

                devices.forEach(function(dev){
                    var $li = $('<li>').addClass('list-group-item d-flex justify-content-between align-items-center flex-wrap');
                    var $info = $('<div>');
                    $info.append($('<div>').text(dev.label || 'Unknown device'));
                    $info.append(
                        $('<div>').addClass('text-muted small').text(
                            'Added ' + tfaFormatDate(dev.created_at) +
                            ' — last used ' + tfaFormatDate(dev.last_used_at) +
                            ' — expires ' + tfaFormatDate(dev.expires_at)
                        )
                    );
                    var $btn = $('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-danger').text('Revoke').data('id', dev.id);
                    $li.append($info).append($btn);
                    $list.append($li);
                });
            }

            function tfaLoadTrustedDevices() {
                $.post('pro_profile.php', { action: 'trusted_devices_list' }, function(res){
                    if (res && res.success) {
                        tfaRenderTrustedDevices(res.devices || []);
                    }
                }, 'json');
            }

            $('#tfaTrustedDevicesList').on('click', 'button', function(){
                var id = $(this).data('id');
                if (!window.confirm('Revoke this trusted device? The next sign-in from it will require a code again.')) return;
                $.post('pro_profile.php', { action: 'trusted_devices_revoke', id: id }, function(res){
                    if (res && res.success) {
                        tfaLoadTrustedDevices();
                    } else {
                        $('#tfaTrustedDevicesAlert').empty().append($('<div>').addClass('alert alert-danger').text((res && res.error) ? res.error : 'Could not revoke device'));
                    }
                }, 'json').fail(function(){
                    $('#tfaTrustedDevicesAlert').empty().append($('<div>').addClass('alert alert-danger').text('Network error'));
                });
            });

            $('#tfaRevokeAllDevicesBtn').on('click', function(){
                if (!window.confirm('Revoke all trusted devices? Every browser will require a code again at next sign-in.')) return;
                $.post('pro_profile.php', { action: 'trusted_devices_revoke', all: 1 }, function(res){
                    if (res && res.success) {
                        tfaLoadTrustedDevices();
                    } else {
                        $('#tfaTrustedDevicesAlert').empty().append($('<div>').addClass('alert alert-danger').text((res && res.error) ? res.error : 'Could not revoke devices'));
                    }
                }, 'json').fail(function(){
                    $('#tfaTrustedDevicesAlert').empty().append($('<div>').addClass('alert alert-danger').text('Network error'));
                });
            });

            tfaLoadStatus();

            // Paddle customer portal: the URL is one-time and short-lived, so it
            // is minted on every click and never kept. Same tab, so the portal's
            // own "back" link returns here.
            $('#billingPortalBtn').on('click', function(e){
                e.preventDefault();
                var $btn = $(this).prop('disabled', true);
                $('#proProfileAlert').html('<div class="alert alert-info">Opening the billing portal...</div>');
                $.post('pro_profile.php', { action: 'billing_portal' }, function(res){
                    if (res && res.success && typeof res.url === 'string' && res.url.indexOf('https://') === 0) {
                        window.location.href = res.url;
                        return;
                    }
                    $btn.prop('disabled', false);
                    $('#proProfileAlert').html($('<div class="alert alert-danger"></div>').text(res && res.error ? res.error : 'Could not open the billing portal'));
                }, 'json').fail(function(){
                    $btn.prop('disabled', false);
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
                    if (!res.profile.is_pro) {
                        $('#proExpiryLine').text('Free').css('color', '');
                    } else if (expires) {
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
                    applyProGating(!!res.profile.is_pro);
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
                personalAddresses = items || [];
                // The hook list embeds these addresses, so it is re-rendered
                // from whatever was loaded last — including the empty case,
                // which swaps a hook's checkboxes for "No personal addresses
                // yet." the moment the last address is deleted.
                renderWebhooks(lastWebhooks);
                var $container = $('#personalList');
                if (!items || items.length === 0) {
                    $container.html('<p class="text-muted">No personal addresses yet.</p>');
                    $('#personalCounterBadge').text('0/' + MAX_PERSONAL_ADDRESSES);
                    return;
                }
                var html = '<div class="list-group">';
                items.forEach(function(it){
                    var feedOn = !!it.feed_enabled;
                    var hooksPaused = !!it.hooks_paused;
                    // The Pause/Start control exists only when the account has a
                    // webhook at all and the routing schema is in place; which
                    // addresses its hooks fire for is decided per hook. It is
                    // hidden while no hook is linked to this address, since there
                    // is nothing to pause — see syncAddressHooksButtons().
                    var hooksButton = '';
                    if (hasAnyWebhook && hookRoutingAvailable) {
                        hooksButton = '                                            <button type="button" class="btn btn-sm btn-outline-secondary ms-address-row__hooks'+(hooksPaused ? '' : ' is-on')+(addressHasLinkedHook(it.id) ? '' : ' d-none')+'" aria-pressed="'+(hooksPaused ? 'true' : 'false')+'" title="'+escapeHtml(addressHooksTitle(hooksPaused))+'"'+(isProAccount ? '' : ' disabled')+'>\n'
                        + '                                                <i class="fas '+(hooksPaused ? 'fa-bell-slash' : 'fa-bell')+'" aria-hidden="true"></i> <span class="ms-address-row__hooks-state">'+(hooksPaused ? 'Hooks paused' : 'Hooks on')+'</span>\n'
                        + '                                            </button>\n';
                    }
                    html += '\n                                <div class="list-group-item ms-address-row" data-id="'+escapeHtml(it.id)+'">\n'
                        + '                                    <div class="d-flex justify-content-between align-items-center ms-address-row__main">\n'
                        + '                                        <div class="flex-grow-1 personal-item" style="cursor:pointer;" data-address="'+escapeHtml(it.address)+'">\n'
                        + '                                            <strong>'+escapeHtml(it.full_address)+'</strong>\n'
                        + '                                        </div>\n'
                        + '                                        <div class="d-flex align-items-center ms-address-row__actions">\n'
                        + '                                            <button type="button" class="btn btn-sm btn-outline-secondary ms-address-row__feed'+(feedOn ? ' is-on' : '')+'" aria-expanded="false" aria-controls="addressFeedPanel'+escapeHtml(it.id)+'" title="RSS feed for this address"'+(isProAccount ? '' : ' disabled')+'>\n'
                        + '                                                <i class="fas fa-rss" aria-hidden="true"></i> <span class="ms-address-row__feed-state">'+(feedOn ? 'RSS on' : 'RSS')+'</span>\n'
                        + '                                            </button>\n'
                        + hooksButton
                        + '                                            <button class="btn btn-sm btn-danger personal-delete" data-id="'+escapeHtml(it.id)+'" title="Delete">\n'
                        + '                                                <i class="fas fa-trash"></i>\n'
                        + '                                            </button>\n'
                        + '                                        </div>\n'
                        + '                                    </div>\n'
                        + '                                    <div id="addressFeedPanel'+escapeHtml(it.id)+'" class="ms-address-feed d-none"></div>\n'
                        + '                                </div>';
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
                    $('#createPersonalBtn').prop('disabled', !isProAccount);
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

            // --- Per-address feeds (#162). The token is fetched lazily on the
            // first expand of a row, never rendered up front for all addresses. ---
            function setAddressFeedState($row, on) {
                $row.find('.ms-address-row__feed')
                    .toggleClass('is-on', !!on)
                    .find('.ms-address-row__feed-state').text(on ? 'RSS on' : 'RSS');
            }

            function renderAddressFeedPanel($panel, addressId, token) {
                var inputId = 'addressFeedUrl' + addressId;
                $panel.html(
                    '<div class="ms-address-feed__url">'
                  +     '<label class="form-label ms-address-feed__label" for="' + escapeHtml(inputId) + '">Feed URL for this address</label>'
                  +     '<input type="text" id="' + escapeHtml(inputId) + '" class="form-control form-control-sm ms-address-feed__input" value="' + escapeHtml(buildFeedUrl(token)) + '" readonly>'
                  + '</div>'
                  + '<div class="ms-address-feed__actions">'
                  +     '<button type="button" class="btn btn-sm btn-outline-secondary ms-address-feed__copy">Copy</button>'
                  +     '<button type="button" class="btn btn-sm btn-outline-secondary ms-address-feed__open">Open</button>'
                  +     '<button type="button" class="btn btn-sm btn-danger ms-address-feed__regen">Regenerate</button>'
                  +     '<button type="button" class="btn btn-sm btn-outline-danger ms-address-feed__off">Turn off</button>'
                  + '</div>'
                );
            }

            function collapseAddressFeedPanel($row) {
                $row.find('.ms-address-feed').addClass('d-none').empty().removeData('loaded');
                $row.find('.ms-address-row__feed').attr('aria-expanded', 'false');
            }

            $(document).on('click', '.ms-address-row__feed', function(e){
                e.stopPropagation();
                var $btn = $(this);
                if ($btn.prop('disabled')) return;
                var $row = $btn.closest('.ms-address-row');
                var $panel = $row.find('.ms-address-feed');
                if (!$panel.hasClass('d-none')) {
                    collapseAddressFeedPanel($row);
                    return;
                }
                $btn.attr('aria-expanded', 'true');
                $panel.removeClass('d-none');
                if ($panel.data('loaded')) return;
                $panel.html('<p class="form-text text-muted mb-0">Loading feed URL...</p>');
                $.post('pro_profile.php', { action: 'address_feed_get_token', id: $row.data('id') }, function(res){
                    if (res && res.success) {
                        renderAddressFeedPanel($panel, $row.data('id'), res.token);
                        $panel.data('loaded', true);
                        // get_token mints a token when the address had none, so
                        // the row can now show the feed as on.
                        setAddressFeedState($row, true);
                    } else {
                        $panel.html('<p class="text-danger small mb-0">' + escapeHtml((res && res.error) ? res.error : 'Could not load the feed URL') + '</p>');
                    }
                }, 'json').fail(function(){
                    $panel.html('<p class="text-danger small mb-0">Request failed</p>');
                });
            });

            $(document).on('click', '.ms-address-feed__copy', function(e){
                e.stopPropagation();
                var $panel = $(this).closest('.ms-address-feed');
                copyFeedUrl($panel.find('.ms-address-feed__input').val() || '', $panel.find('.ms-address-feed__url'));
            });

            $(document).on('click', '.ms-address-feed__open', function(e){
                e.stopPropagation();
                openFeedUrl($(this).closest('.ms-address-feed').find('.ms-address-feed__input').val() || '');
            });

            $(document).on('click', '.ms-address-feed__regen', function(e){
                e.stopPropagation();
                if (!confirm('Regenerating this feed token will invalidate existing subscriptions. Continue?')) return;
                var $btn = $(this).prop('disabled', true);
                var $row = $btn.closest('.ms-address-row');
                $.post('pro_profile.php', { action: 'address_feed_regenerate', id: $row.data('id') }, function(res){
                    $btn.prop('disabled', false);
                    if (res && res.success) {
                        $row.find('.ms-address-feed__input').val(buildFeedUrl(res.token));
                        setAddressFeedState($row, true);
                    } else {
                        alert((res && res.error) ? res.error : 'Failed to regenerate token');
                    }
                }, 'json').fail(function(){ $btn.prop('disabled', false); alert('Request failed'); });
            });

            $(document).on('click', '.ms-address-feed__off', function(e){
                e.stopPropagation();
                if (!confirm('Turning off this feed will invalidate existing subscriptions. Continue?')) return;
                var $btn = $(this).prop('disabled', true);
                var $row = $btn.closest('.ms-address-row');
                $.post('pro_profile.php', { action: 'address_feed_disable', id: $row.data('id') }, function(res){
                    $btn.prop('disabled', false);
                    if (res && res.success) {
                        // Clear the panel: the credential no longer exists, so
                        // nothing may stay in the DOM. The next expand refetches.
                        collapseAddressFeedPanel($row);
                        setAddressFeedState($row, false);
                    } else {
                        alert((res && res.error) ? res.error : 'Failed to turn off feed');
                    }
                }, 'json').fail(function(){ $btn.prop('disabled', false); alert('Request failed'); });
            });

            // --- Per-address hook pause (#251 step 5). One control per address,
            // shown whenever the account has any webhook at all, whatever its
            // kind: it silences every hook linked to that address without
            // touching the links themselves, so pausing and resuming is
            // reversible and loses nothing. ---

            // Follow the account's webhook count. Called after the webhook list
            // loads, so deleting the last webhook takes the address controls away
            // on this page and creating one brings them back. Nothing is written
            // to the addresses here: the rows are re-rendered from list_personal,
            // which still reports each address's stored pause, so an address
            // paused before the webhook was deleted returns as paused. Pausing a
            // webhook is not a configuration change for this purpose — the
            // webhook row still exists — so the controls stay.
            function syncAddressHooksControl(hasAny) {
                if (hasAny === hasAnyWebhook) return;
                hasAnyWebhook = hasAny;
                if ($('#personalList').length) loadPersonalList();
            }

            // Is any hook linked to this personal address? A paused hook still
            // counts: the link exists and the address' own pause still applies
            // when the hook is resumed.
            function addressHasLinkedHook(addressId) {
                var target = String(addressId);
                return lastWebhooks.some(function(w){
                    return (w.address_ids || []).some(function(id){ return String(id) === target; });
                });
            }

            // Show or hide each row's Pause/Start button from the current links,
            // without re-rendering the rows (which would collapse an open feed
            // panel). Called whenever lastWebhooks changes.
            function syncAddressHooksButtons() {
                $('#personalList .ms-address-row').each(function(){
                    var $row = $(this);
                    $row.find('.ms-address-row__hooks').toggleClass('d-none', !addressHasLinkedHook($row.data('id')));
                });
            }

            function addressHooksTitle(paused) {
                return paused
                    ? 'Hooks are paused for this address. Click to start them.'
                    : 'Hooks are active for this address. Click to pause them.';
            }

            // aria-pressed tracks the *paused* state, which is what the button
            // does; `is-on` tracks the accent tint, which is what the address is.
            function setAddressHooksState($row, paused) {
                var isPaused = !!paused;
                var $btn = $row.find('.ms-address-row__hooks')
                    .toggleClass('is-on', !isPaused)
                    .attr('aria-pressed', isPaused ? 'true' : 'false')
                    .attr('title', addressHooksTitle(isPaused));
                $btn.find('.ms-address-row__hooks-state').text(isPaused ? 'Hooks paused' : 'Hooks on');
                // The icon carries the same state as the label, so it flips with
                // it — a slashed bell beside "Hooks paused", a plain one beside
                // "Hooks on".
                $btn.find('i')
                    .toggleClass('fa-bell', !isPaused)
                    .toggleClass('fa-bell-slash', isPaused);
            }

            $(document).on('click', '.ms-address-row__hooks', function(e){
                e.stopPropagation();
                var $btn = $(this);
                if ($btn.prop('disabled')) return;
                var $row = $btn.closest('.ms-address-row');
                var wasPaused = $btn.attr('aria-pressed') === 'true';
                // Flip on the click so the state reads immediately, then let the
                // response confirm it or put it back.
                setAddressHooksState($row, !wasPaused);
                $btn.prop('disabled', true);
                $.post('pro_profile.php', {
                    action: wasPaused ? 'address_hooks_resume' : 'address_hooks_pause',
                    id: $row.data('id')
                }, function(res){
                    $btn.prop('disabled', false);
                    if (res && res.success) {
                        setAddressHooksState($row, res.hooks_paused);
                        // The hook lists carry this address' "(hooks paused)"
                        // hint, so they are re-rendered from the new state.
                        var rowId = String($row.data('id'));
                        personalAddresses.forEach(function(a){
                            if (String(a.id) === rowId) a.hooks_paused = !!res.hooks_paused;
                        });
                        renderWebhooks(lastWebhooks);
                    } else {
                        setAddressHooksState($row, wasPaused);
                        alert((res && res.error) ? res.error : 'Could not update the hooks for this address');
                    }
                }, 'json').fail(function(){
                    $btn.prop('disabled', false);
                    setAddressHooksState($row, wasPaused);
                    alert('Request failed');
                });
            });

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
                        ? '<button class="btn btn-sm btn-outline-secondary wh-toggle-pause" data-id="'+escapeHtml(w.id)+'" data-mode="all" title="Resume webhook" aria-label="Resume webhook"><i class="fas fa-play text-success"></i></button>'
                        : '<button class="btn btn-sm btn-outline-secondary wh-toggle-pause" data-id="'+escapeHtml(w.id)+'" data-mode="paused" title="Pause webhook" aria-label="Pause webhook"><i class="fas fa-pause text-warning"></i></button>';
                    // The routing group is the item's third child, not a wrapper
                    // around the other two: style.css styles `.me-3` and
                    // `.btn-group` as *direct* children of `#webhookList
                    // .list-group-item` (which it makes a wrapping flex
                    // container), and an extra wrapper would drop both rules and
                    // let the hook's URL set the row's minimum width. As a third
                    // flex item it takes a line of its own — see .ms-hook-routing
                    // in mailshield.css.
                    html += '<div class="list-group-item d-flex justify-content-between align-items-start">'
                        + '<div class="me-3"><strong>' + escapeHtml(w.name || ('#'+w.id)) + '</strong>' + statusBadge + '<div class="text-muted small">' + escapeHtml(w.kind) + ' — ' + escapeHtml(w.url) + '</div>'
                        + (cfg ? '<div class="text-muted small">Config: ' + escapeHtml(cfg) + '</div>' : '')
                        + '</div>'
                        + '<div class="btn-group">'
                        + pauseBtn
                        + '<button class="btn btn-sm btn-outline-secondary wh-deliveries" data-id="'+escapeHtml(w.id)+'">Deliveries</button>'
                        + '<button class="btn btn-sm btn-danger wh-delete" data-id="'+escapeHtml(w.id)+'" title="Delete webhook" aria-label="Delete webhook"><i class="fas fa-trash"></i></button>'
                        + '</div>'
                        + (hookRoutingAvailable ? renderHookRouting(w) : '')
                        + '</div>';
                });
                html += '</div>';
                if (!hookRoutingAvailable) {
                    // One line for the whole page rather than one per hook: the
                    // migration is an account-wide fact, not a per-hook one.
                    html += '<p class="text-muted small mb-0 mt-2">Address routing is not available yet.</p>';
                }
                $c.html(html);
            }

            // The "Triggers for" group under one hook: every personal address as
            // a checkbox (checked = linked), plus the hook's Temporary addresses
            // switch. Empty means the hook fires for nothing, which is worth
            // saying out loud rather than leaving as an all-unchecked group.
            function renderHookRouting(w) {
                var linked = w.address_ids || [];
                // Whether any *rendered* box is checked — not whether the hook
                // has link rows. Deleting an address takes its link rows with it,
                // but the hook list in memory is not refetched, so a stale id
                // here would keep the warning hidden on a hook that now fires for
                // nothing.
                var anyLinked = false;
                var out = '<fieldset class="ms-hook-routing" data-hook="'+escapeHtml(w.id)+'">'
                    + '<legend class="ms-hook-routing__legend">Triggers for</legend>';
                if (personalAddresses.length === 0) {
                    out += '<p class="form-text text-muted mb-0">No personal addresses yet.</p>';
                } else {
                    personalAddresses.forEach(function(a){
                        var boxId = 'hookAddr' + escapeHtml(w.id) + '_' + escapeHtml(a.id);
                        // Compared as numbers: the ids come back from PHP as
                        // strings in the list and as ints in the response.
                        var on = false;
                        var target = parseInt(a.id, 10);
                        for (var i = 0; i < linked.length; i++) {
                            if (parseInt(linked[i], 10) === target) { on = true; break; }
                        }
                        if (on) anyLinked = true;
                        out += '<div class="form-check">'
                            + '<input class="form-check-input ms-hook-routing__addr" type="checkbox" id="'+boxId+'" value="'+escapeHtml(a.id)+'"'+(on ? ' checked' : '')+(isProAccount ? '' : ' disabled')+'>'
                            + '<label class="form-check-label" for="'+boxId+'">'+escapeHtml(a.full_address)
                            + (a.hooks_paused ? ' <span class="ms-hook-routing__paused">(hooks paused)</span>' : '')
                            + '</label>'
                            + '</div>';
                    });
                }
                var tempId = 'hookTemp' + escapeHtml(w.id);
                out += '<div class="form-check">'
                    + '<input class="form-check-input ms-hook-routing__temp" type="checkbox" id="'+tempId+'"'+(w.include_temporary ? ' checked' : '')+(isProAccount ? '' : ' disabled')+'>'
                    + '<label class="form-check-label" for="'+tempId+'">Temporary addresses</label>'
                    + '</div>'
                    + '<p class="ms-hook-routing__empty'+((anyLinked || w.include_temporary) ? ' d-none' : '')+'">This hook is not triggered for any address.</p>'
                    + '</fieldset>';
                return out;
            }

            function loadWebhooks() {
                $.getJSON('pro_profile.php?action=webhooks_list', function(res){
                    if (res && res.success) {
                        lastWebhooks = res.webhooks || [];
                        renderWebhooks(lastWebhooks);
                        syncAddressHooksButtons();
                        // Only whether any hook exists is read here — never a
                        // config, which can hold a Pushover token and user key.
                        syncAddressHooksControl(lastWebhooks.length > 0);
                    } else {
                        $('#webhookList').html('<p class="text-danger">Could not load webhooks</p>');
                    }
                }).fail(function(){ $('#webhookList').html('<p class="text-danger">Request failed</p>'); });
            }

            // --- Per-hook address routing (#251 step 5). One change saves the
            // hook's *whole* set — what should be linked, not a diff — so a
            // concurrent edit on another tab cannot merge into a set nobody
            // asked for. The response is authoritative and re-renders the list.
            $(document).on('change', '.ms-hook-routing__addr, .ms-hook-routing__temp', function(){
                var $box = $(this);
                var $group = $box.closest('.ms-hook-routing');
                var hookId = $group.data('hook');
                if (!hookId && hookId !== 0) return;
                var ids = [];
                $group.find('.ms-hook-routing__addr:checked').each(function(){
                    ids.push($(this).val());
                });
                var includeTemp = $group.find('.ms-hook-routing__temp').is(':checked');
                $group.find('input').prop('disabled', true);
                $.post('pro_profile.php', {
                    action: 'webhook_set_addresses',
                    id: hookId,
                    address_ids: ids,
                    include_temporary: includeTemp ? '1' : '0'
                }, function(res){
                    if (res && res.success) {
                        var target = String(hookId);
                        lastWebhooks.forEach(function(w){
                            if (String(w.id) === target) {
                                w.address_ids = res.address_ids || [];
                                w.include_temporary = !!res.include_temporary;
                            }
                        });
                        renderWebhooks(lastWebhooks);
                        syncAddressHooksButtons();
                    } else {
                        $box.prop('checked', !$box.prop('checked'));
                        $group.find('input').prop('disabled', !isProAccount);
                        alert((res && res.error) ? res.error : 'Could not save the address selection');
                    }
                }, 'json').fail(function(){
                    $box.prop('checked', !$box.prop('checked'));
                    $group.find('input').prop('disabled', !isProAccount);
                    alert('Request failed');
                });
            });

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
                        $('#whMsg').html('<div class="text-danger">'+escapeHtml(r && r.error ? r.error : 'Failed')+'</div>');
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
                                out += '<div class="mb-2">['+escapeHtml(d.status)+'] attempts='+escapeHtml(d.attempts)+' created='+escapeHtml(d.created_at)+'<div class="text-muted small">error: '+escapeHtml(d.last_error||'')+'</div></div>';
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

            // --- Feed URL helpers, shared by the account-wide feed below and the
            // per-address feeds in the Personal addresses card (#162). ---
            function buildFeedUrl(token) {
                var base = window.location.origin || (window.location.protocol + '//' + window.location.hostname);
                return base + '/pro_feed.php?token=' + encodeURIComponent(token || '');
            }

            // Flash a short-lived "Copied" note directly after $anchor.
            function flashCopied($anchor) {
                $anchor.parent().find('.feed-copied').remove();
                var $msg = $('<div class="feed-copied text-success small ms-2">Copied</div>');
                $anchor.after($msg);
                setTimeout(function(){ $msg.fadeOut(300, function(){ $(this).remove(); }); }, 1500);
            }

            function copyFeedUrl(url, $anchor) {
                if (!url) return;
                if (!navigator.clipboard) { alert('Could not copy to clipboard'); return; }
                navigator.clipboard.writeText(url).then(function(){
                    flashCopied($anchor);
                }).catch(function(){ alert('Could not copy to clipboard'); });
            }

            function openFeedUrl(url) {
                if (!url) return;
                window.open(url, '_blank');
            }

            // Every value interpolated into row HTML goes through this — the
            // address local part is validated server-side, but row markup is
            // built as a string and must not trust it.
            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            // Load or create feed token and wire UI (display full URL in the input)
            function loadFeedToken() {
                // POST, not GET: feed_get_token mints a token on first use,
                // so pro_profile.php treats it as a state-changing action.
                $.post('pro_profile.php', { action: 'feed_get_token' }, function(res) {
                    if (res && res.success) {
                        var feedUrl = buildFeedUrl(res.token);
                        $('#feedUrlInput').val(feedUrl);
                        $('#openFeedBtn').attr('data-feed', feedUrl);
                    } else {
                        $('#feedUrlInput').val('Unavailable');
                        $('#openFeedBtn').removeAttr('data-feed');
                    }
                }, 'json').fail(function(){ $('#feedUrlInput').val('Request failed'); $('#openFeedBtn').removeAttr('data-feed'); });
            }

            $('#copyFeedToken').on('click', function(){
                copyFeedUrl($('#feedUrlInput').val() || '', $('#feedUrlInput'));
            });

            $('#openFeedBtn').on('click', function(){
                openFeedUrl($(this).attr('data-feed') || $('#feedUrlInput').val() || '');
            });

            $('#regenFeedToken').on('click', function(){
                if (!confirm('Regenerating the feed token will invalidate existing subscriptions. Continue?')) return;
                var $btn = $(this).prop('disabled', true);
                $.post('pro_profile.php', { action: 'feed_regenerate' }, function(r){
                    $btn.prop('disabled', false);
                    if (r && r.success) {
                        var feedUrl = buildFeedUrl(r.token);
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

    <script>
    /**
     * Marks the settings group the reader is actually looking at.
     *
     * Kept out of the block above on purpose: it is read-only and independent of
     * the profile handlers, so it shares no state with them and cannot affect
     * what any of them post. Navigation itself stays anchored and script-free —
     * this only reflects scroll position, and the markup already carries the
     * marker for the first group, so a browser without IntersectionObserver or
     * without JS shows a correct, if static, rail.
     */
    (function () {
        var root = document.querySelector('.ms-settings');
        if (!root) return;

        var links = {};
        Array.prototype.forEach.call(root.querySelectorAll('.ms-settings__rail-link'), function (a) {
            var id = (a.getAttribute('href') || '').replace('#', '');
            if (id) links[id] = a;
        });

        var sections = Array.prototype.slice.call(root.querySelectorAll('.ms-settings__section'));
        if (!sections.length) return;

        // The heading of a section an anchor jumped to sits this far down the
        // viewport, so that is the line the "current" section is measured
        // against — not the very top, which the heading never reaches.
        var offset = parseFloat(getComputedStyle(sections[0]).scrollMarginTop) || 0;
        var current = '';
        var ticking = false;

        function mark(id) {
            if (id === current || !links[id]) return;
            current = id;
            Object.keys(links).forEach(function (key) {
                if (key === id) {
                    links[key].setAttribute('aria-current', 'location');
                } else {
                    links[key].removeAttribute('aria-current');
                }
            });
        }

        function update() {
            ticking = false;
            // The last section to have crossed the line is the one in view.
            var best = sections[0].id;
            for (var i = 0; i < sections.length; i++) {
                if (sections[i].getBoundingClientRect().top <= offset + 1) best = sections[i].id;
            }
            // The final group cannot be scrolled to the line, so the bottom of
            // the page counts as being in it.
            if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2) {
                best = sections[sections.length - 1].id;
            }
            mark(best);
        }

        function onScroll() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        update();
    })();
    </script>

    <script>
    /**
     * Show/hide toggles for password fields.
     *
     * Generic by design: each button names its input in `data-target`, so one
     * binding covers all three fields on this page and a further field needs
     * markup only. The profile page does not load the inbox script (it polls),
     * so the binding is repeated here rather than shared.
     *
     * The buttons carry `type="button"` in the markup. That matters more here
     * than on the auth pages: the three fields sit inside one large settings
     * form, so an untyped button would submit every setting on the page.
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
