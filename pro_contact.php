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
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
    
    $category = $_POST['category'] ?? '';
    $message = trim($_POST['message'] ?? '');
    
    // Validate category
    $validCategories = ['Question', 'Suggestion', 'Bug report'];
    if (!in_array($category, $validCategories)) {
        echo json_encode(['success' => false, 'error' => 'Invalid category selected']);
        exit;
    }

    if ($message === '') {
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        exit;
    }
    if (strlen($message) > 5000) {
        echo json_encode(['success' => false, 'error' => 'Message too long (max 5000 characters)']);
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

    echo json_encode(['success' => true, 'message' => 'Thanks! Your message has been sent.']);
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#FAFAF9">
    <meta name="robots" content="noindex, nofollow">
    <title>Contact support · Mail Shield</title>
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
    <div class="main-container">
        <div class="header">
            <?php require 'partials/nav.php'; ?>
            <script>
            (function(){
                try {
                    var proExpiry = <?php echo json_encode($proExpires); ?>;
                    var isProAccount = <?php echo json_encode($isProAccount); ?>;
                    if (!isProAccount) {
                        document.getElementById('proExpiryLine').textContent = 'Free';
                        return;
                    }
                    if (!proExpiry) {
                        document.getElementById('proExpiryLine').textContent = 'Pro: Lifetime';
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
                        document.getElementById('proExpiryLine').textContent = 'Pro expired on ' + dateOnly;
                        document.getElementById('proExpiryLine').style.color = '#ff6b6b';
                    } else {
                        document.getElementById('proExpiryLine').textContent = 'Pro expires: ' + dateOnly;
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
            <h1 class="ms-contact__title">Contact support</h1>
            <p class="ms-contact__lede">Messages go straight to the team. We reply by email to the address on your account.</p>

            <div class="ms-card ms-contact__card">
                <div id="contactAlert"></div>

                <p class="ms-contact__to">
                    Reply goes to
                    <span class="ms-contact__to-addr"><?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                </p>

                <form id="contactForm">
                    <div class="mb-3">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Select a category...</option>
                            <option value="Question">Question</option>
                            <option value="Suggestion">Suggestion</option>
                            <option value="Bug report">Bug report</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control" id="message" name="message" rows="6"
                                  placeholder="Please describe your question, suggestion, or the bug you encountered..."
                                  required maxlength="5000"></textarea>
                        <div class="form-text">
                            <span id="charCount">0</span> / 5000 characters
                        </div>
                    </div>

                    <div class="ms-contact__actions">
                        <a href="pro.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to dashboard
                        </a>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane" aria-hidden="true"></i> Send message
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            var originalText = $btn.html();
            
            // Disable button and show loading
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
            $('#contactAlert').html('');
            
            $.post('pro_contact.php', {
                category: $('#category').val(),
                message: $('#message').val()
            }, function(res){
                if (res && res.success) {
                    $('#contactAlert').html(
                        '<div class="alert alert-success">' +
                        '<i class="fas fa-check-circle"></i> ' + (res.message || 'Message sent successfully!') +
                        '</div>'
                    );
                    // Reset form
                    $('#contactForm')[0].reset();
                    $('#charCount').text('0');
                } else {
                    $('#contactAlert').html(
                        '<div class="alert alert-danger">' +
                        '<i class="fas fa-exclamation-circle"></i> ' + (res && res.error ? res.error : 'Failed to send message') +
                        '</div>'
                    );
                }
            }, 'json').fail(function(){
                $('#contactAlert').html(
                    '<div class="alert alert-danger">' +
                    '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.' +
                    '</div>'
                );
            }).always(function(){
                $btn.prop('disabled', false).html(originalText);
            });
        });
    });
    </script>
</body>
</html>