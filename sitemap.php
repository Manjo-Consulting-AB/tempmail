<?php
/**
 * Mail Shield — generated XML sitemap, served at /sitemap.xml by the rewrite in
 * .htaccess. Spec: documentaion/REDESIGN_BRIEF.md §14.
 *
 * Generated rather than hand-written so it cannot drift from the routes: the
 * list below is the only place to edit, and <lastmod> comes from filemtime() on
 * the file that serves each path. That mtime is a proxy for "last changed",
 * which is what a crawler wants from <lastmod> — not a fabricated publish date.
 *
 * Only the public, indexable pages are listed. Every private path (the
 * acceptance criteria grep for inbox|pro_|client_agent|log_viewer) is absent by
 * construction: adding one here means adding a route that already belongs in
 * robots.txt's Disallow list, which is a sign the two lists have diverged.
 *
 * The origin comes from $config['email']['base_url'] (env BASE_URL), the same
 * source the marketing shell uses for canonical and OG URLs — §14 forbids
 * hardcoding https://manjo.me in page output. robots.txt is the one exception
 * and says so itself.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';

header('Content-Type: application/xml; charset=UTF-8');

$base = rtrim((string) ($config['email']['base_url'] ?? ''), '/');

// path => [priority, change frequency, file holding the mtime]
$pages = [
    '/'                  => ['1.0', 'weekly',  __DIR__ . '/index.php'],
    '/temporary-email.php' => ['0.9', 'monthly', __DIR__ . '/temporary-email.php'],
    '/faq.php'           => ['0.7', 'monthly', __DIR__ . '/faq.php'],
    '/blog.php'          => ['0.6', 'weekly',  __DIR__ . '/blog.php'],
    '/register.php'      => ['0.5', 'monthly', __DIR__ . '/register.php'],
    '/pro_login.php'     => ['0.3', 'yearly',  __DIR__ . '/pro_login.php'],
    '/terms.php'         => ['0.2', 'yearly',  __DIR__ . '/terms.php'],
    '/privacy.php'       => ['0.2', 'yearly',  __DIR__ . '/privacy.php'],
    '/refund-policy.php' => ['0.2', 'yearly',  __DIR__ . '/refund-policy.php'],
];

// Every value is escaped for XML — ENT_XML1 rather than ENT_QUOTES, so an
// ampersand in a configured base URL can never break the document.
$xmlUrl = function (string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
};

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $path => [$priority, $changefreq, $file]) : ?>
    <url>
        <loc><?php echo $xmlUrl($base . $path); ?></loc>
        <lastmod><?php echo $xmlUrl(gmdate('c', @filemtime($file) ?: time())); ?></lastmod>
        <changefreq><?php echo $xmlUrl($changefreq); ?></changefreq>
        <priority><?php echo $xmlUrl($priority); ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
