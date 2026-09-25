<?php
/**
 * Log out pro user
 * This endpoint clears server session and returns a small HTML payload
 * that clears client-side localStorage keys used to persist the temporary
 * address before redirecting to the public index page. This prevents the
 * temporary address from remaining visible on shared machines after logout.
 */
require_once __DIR__ . '/config.php';
session_start();
// End "stay signed in" on this device: its token is removed server-side, not
// only its cookie (pro_remember.php).
proRememberForgetCurrent();
// Clear session variables
$_SESSION = [];
// If session cookie exists, clear it
if (ini_get("session.use_cookies")) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000,
				$params['path'], $params['domain'], $params['secure'], $params['httponly']
		);
}
// Destroy server session
session_unset();
session_destroy();

// Also attempt to clear all other cookies server-side (helps remove HttpOnly cookies)
if (!empty($_COOKIE) && is_array($_COOKIE)) {
	// Use path '/' to clear cookies set site-wide. Also attempt with and without leading dot domain.
	$cookieParams = session_get_cookie_params();
	$path = $cookieParams['path'] ?? '/';
	$domain = $cookieParams['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '');
	foreach ($_COOKIE as $cname => $cval) {
		// Clear without domain
		setcookie($cname, '', time() - 42000, $path);
		// Clear with domain variations if available
		if ($domain) {
			setcookie($cname, '', time() - 42000, $path, $domain);
			// Leading dot variant
			if (strpos($domain, '.') !== 0) {
				setcookie($cname, '', time() - 42000, $path, '.' . $domain);
			}
		}
	}
}

// Emit small HTML that clears localStorage keys and redirects to index
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
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

	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Logged out</title>
	<script>
		try {
			// Remove common keys that may contain user-specific data
			localStorage.removeItem('tempmail_address');
			localStorage.removeItem('block_images');
			// Also remove any keys that start with 'tempmail' or 'pro_' to be conservative
			try {
				for (var i = localStorage.length - 1; i >= 0; i--) {
					var key = localStorage.key(i);
					if (!key) continue;
					if (/^(tempmail|pro_)/i.test(key) || /block_images/i.test(key)) {
						localStorage.removeItem(key);
					}
				}
			} catch (inner) {
				// ignore per-key errors
			}
		} catch (e) {
			// ignore
			console.warn('Could not clear localStorage during logout', e);
		}

		// Also clear cookies client-side (non-HttpOnly). Server-side cleared HttpOnly above.
		(function(){
			try {
				var cookies = document.cookie.split(';');
				for (var i=0;i<cookies.length;i++){
					var parts = cookies[i].split('=');
					var name = parts.shift().trim();
					if (!name) continue;
					// Expire for path /
					document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/;';
					// Try with domain
					try {
						var host = window.location.hostname;
						document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + host + ';';
						// Leading dot
						document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=.' + host + ';';
					} catch (ignore) {}
				}
			} catch (e) {
				// ignore
			}
		})();
		// Redirect to public index
		window.location.replace('index.php');
	</script>
	<noscript>
		<meta http-equiv="refresh" content="0;url=index.php">
	</noscript>
</head>
<body>
	<p>Signing out… If you are not redirected automatically, <a href="index.php">click here</a>.</p>
</body>
</html>
