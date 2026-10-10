<?php
// Inbjudningslänk /invite/<kod> (referrals, epic #387): sätter cookien ms_ref
// för en giltig kod och skickar alltid vidare till startsidan.
define('TEMPMAIL_APP', true);
require_once 'config.php';
require_once __DIR__ . '/referrals.php';

header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

$code = strtolower(trim((string) ($_GET['code'] ?? '')));
$settings = referralSettings($config['referral'] ?? []);

if ($settings['enabled'] && referralIsValidCode($code) && referralCodeExists($pdo, $code)) {
    setcookie('ms_ref', $code, [
        'expires' => time() + $settings['cookie_days'] * 86400,
        'path' => '/',
        'secure' => appCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Samma svar oavsett utfall, så att länken inte avslöjar om en kod finns.
header('Location: /', true, 302);
exit;
