<?php
/**
 * TempMail Pro - Contact Page
 * Allows pro users to send messages to support
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}
if (function_exists('i18nUseAppLocale')) {
    i18nUseAppLocale($pdo, isset($_SESSION['pro_user_id']) ? (int) $_SESSION['pro_user_id'] : null, $_SERVER, $config['i18n'] ?? [], $_SERVER['REQUEST_METHOD'] === 'POST');
}

// Require pro authentication
if (empty($_SESSION['pro_user_id'])) {
    header('Location: pro_login.php');
    exit;
}

$userEmail = $_SESSION['pro_user_email'] ?? '';
$userId = $_SESSION['pro_user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // CSRF: the form is submitted by this page's own same-origin $.post; a
    // cross-site form must not send support mail in the user's name.
    if (!requireSameOriginRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('contact.error.forbidden')]);
        exit;
    }
    
    $category = $_POST['category'] ?? '';
    $message = trim($_POST['message'] ?? '');
    
    // Validate category
    $validCategories = ['Question', 'Suggestion', 'Bug report'];
    if (!in_array($category, $validCategories)) {
        echo json_encode(['success' => false, 'error' => t('contact.error.invalid_category')]);
        exit;
    }

    if ($message === '') {
        echo json_encode(['success' => false, 'error' => t('contact.error.message_required')]);
        exit;
    }
    if (strlen($message) > 5000) {
        echo json_encode(['success' => false, 'error' => t('contact.error.too_long', ['max' => 5000])]);
        exit;
    }

    // Previously this block only fetched pro_expires_at (unrelated - needed for the
    // page render below) and fell through without ever storing/sending the message
    // or returning JSON, so every contact form submission was silently discarded.
    logMessage('INFO', 'Pro contact form submission', [
        'user_id' => $userId,
        'category' => $category,
        'message' => $message
    ]);

    try {
        $supportTo = $_ENV['SUPPORT_EMAIL'] ?? ('support@' . ($config['email']['domain'] ?? 'manjo.me'));
        $from = $_ENV['EMAIL_FROM'] ?? ('noreply@' . ($config['email']['domain'] ?? 'manjo.me'));
        $subject = 'Mail Shield contact form: ' . $category;
        $body = "From: {$userEmail} (user_id {$userId})\nCategory: {$category}\n\n{$message}";
        $headers = [];
        $headers[] = 'From: Mail Shield <' . $from . '>';
        $headers[] = 'Reply-To: ' . $userEmail;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        @mail($supportTo, $subject, $body, implode("\r\n", $headers));
    } catch (Exception $e) {
        logMessage('WARNING', 'Failed emailing contact form submission', ['error' => $e->getMessage(), 'user_id' => $userId]);
    }

    echo json_encode(['success' => true, 'message' => t('contact.sent')]);
    exit;
}

try {
    $pstmt = $pdo->prepare("SELECT pro_expires_at FROM pro_users WHERE id = ? LIMIT 1");
    $pstmt->execute([$userId]);
    $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
    $proExpires = $prow['pro_expires_at'] ?? null;
} catch (Exception $e) {
    $proExpires = null;
}
$isProAccount = proUserIsPro((int)$userId);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(i18nLocale(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#FAFAF9">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo te('contact.meta.title'); ?></title>
<?php require __DIR__ . '/partials/favicons.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <?php require 'partials/nav.php'; ?>
            <script>
            (function(){
                try {
                    var proExpiry = <?php echo json_encode($proExpires); ?>;
                    var isProAccount = <?php echo json_encode($isProAccount); ?>;
                    // The four texts, in this request's language ({date} is filled in below).
                    var L = <?php echo json_encode([
                        'free' => t('app.common.expiry_free'),
                        'lifetime' => t('app.common.expiry_lifetime'),
                        'expired' => t('app.common.expiry_expired', ['date' => '{date}']),
                        'expires' => t('app.common.expiry_expires', ['date' => '{date}']),
                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
                    if (!isProAccount) {
                        document.getElementById('proExpiryLine').textContent = L.free;
                        return;
                    }
                    if (!proExpiry) {
                        document.getElementById('proExpiryLine').textContent = L.lifetime;
                        return;
                    }
                    var d = new Date(proExpiry + ' UTC');
                    var dd = String(d.getUTCDate()).padStart(2, '0');
                    var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                    var yyyy = d.getUTCFullYear();
                    var dateOnly = dd + '/' + mm + '/' + yyyy;
                    var now = new Date();
                    var diffMs = d - now;
                    if (diffMs <= 0) {
                        document.getElementById('proExpiryLine').textContent = L.expired.replace('{date}', dateOnly);
                        document.getElementById('proExpiryLine').style.color = '#ff6b6b';
                    } else {
                        document.getElementById('proExpiryLine').textContent = L.expires.replace('{date}', dateOnly);
                    }
                } catch (e) {}
            })();
            
            </script>
        </div>

        <!-- One centred column and one card: this page does a single thing, and
             nothing else on it is boxed. The category values, the textarea, the
             ids and the jQuery handler below are untouched — pro_contact.php
             validates the category against $validCategories server-side. -->
        <main class="ms-contact">
            <h1 class="ms-contact__title"><?php echo te('contact.title'); ?></h1>
            <p class="ms-contact__lede"><?php echo te('contact.lede'); ?></p>

            <div class="ms-card ms-contact__card">
                <div id="contactAlert"></div>

                <p class="ms-contact__to">
                    <?php echo te('contact.reply_to'); ?>
                    <span class="ms-contact__to-addr"><?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                </p>

                <form id="contactForm">
                    <div class="mb-3">
                        <label for="category" class="form-label"><?php echo te('contact.category_label'); ?></label>
                        <select class="form-select" id="category" name="category" required>
                            <option value=""><?php echo te('contact.category_placeholder'); ?></option>
                            <option value="Question"><?php echo te('contact.category_question'); ?></option>
                            <option value="Suggestion"><?php echo te('contact.category_suggestion'); ?></option>
                            <option value="Bug report"><?php echo te('contact.category_bug'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="message" class="form-label"><?php echo te('contact.message_label'); ?></label>
                        <textarea class="form-control" id="message" name="message" rows="6"
                                  placeholder="<?php echo te('contact.message_placeholder'); ?>"
                                  required maxlength="5000"></textarea>
                        <div class="form-text">
                            <?php echo tHtml('contact.chars', ['max' => 5000], ['count' => '<span id="charCount">0</span>']); ?>
                        </div>
                    </div>

                    <div class="ms-contact__actions">
                        <a href="pro.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left" aria-hidden="true"></i> <?php echo te('contact.back'); ?>
                        </a>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane" aria-hidden="true"></i> <?php echo te('contact.send'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <?php require __DIR__ . '/partials/app_locale_js.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
// The script's own strings (partials/i18n_js.php, read by msT() below).
$msI18nJs = ['contact.'];
require __DIR__ . '/partials/i18n_js.php';
?>
    <script>
    // The page's string for a key in this request's language (the #ms-i18n element above),
    // or the English fallback; {name} placeholders are filled in. Exactly as
    // documentaion/I18N.md section 4. The value is plain text: put it in with .text().
    var msI18n = null;
    function msT(key, fallback, params) {
        if (msI18n === null) {
            try { msI18n = JSON.parse(document.getElementById('ms-i18n').textContent); }
            catch (e) { msI18n = {}; }
        }
        var text = (typeof msI18n[key] === 'string') ? msI18n[key] : fallback;
        return text.replace(/\{(\w+)\}/g, function (m, name) {
            return params && name in params ? String(params[name]) : m;
        });
    }

    // One message in the alert area. The text is set as text, never as HTML: it may be a
    // server message.
    function contactAlert(type, icon, text) {
        var $alert = $('<div class="alert"></div>').addClass('alert-' + type);
        $alert.append($('<i class="fas"></i>').addClass(icon)).append(document.createTextNode(' ' + text));
        $('#contactAlert').empty().append($alert);
    }

    $(function(){
        // Character counter
        $('#message').on('input', function(){
            var len = $(this).val().length;
            $('#charCount').text(len);
            if (len > 4500) {
                $('#charCount').addClass('text-warning');
            } else {
                $('#charCount').removeClass('text-warning');
            }
        });

        // Form submission
        $('#contactForm').on('submit', function(e){
            e.preventDefault();

            var $btn = $('#submitBtn');
            var originalContent = $btn.contents().clone();

            // Disable button and show loading
            $btn.prop('disabled', true).empty()
                .append($('<i class="fas fa-spinner fa-spin"></i>'))
                .append(document.createTextNode(' ' + msT('contact.sending', 'Sending...')));
            $('#contactAlert').empty();

            $.post('pro_contact.php', {
                category: $('#category').val(),
                message: $('#message').val()
            }, function(res){
                if (res && res.success) {
                    contactAlert('success', 'fa-check-circle', res.message || msT('contact.sent_fallback', 'Message sent successfully!'));
                    // Reset form
                    $('#contactForm')[0].reset();
                    $('#charCount').text('0');
                } else {
                    contactAlert('danger', 'fa-exclamation-circle', res && res.error ? res.error : msT('contact.failed', 'Failed to send message'));
                }
            }, 'json').fail(function(){
                contactAlert('danger', 'fa-exclamation-circle', msT('contact.network', 'Network error. Please try again.'));
            }).always(function(){
                $btn.prop('disabled', false).empty().append(originalContent);
            });
        });
    });
    </script>
</body>
</html>
