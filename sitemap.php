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

// pricing.php is noindex while it shows the sandbox catalog, so it is listed
// only on production (#280 §6).
if (strtolower(trim((string) ($config['paddle']['environment'] ?? ''))) === 'production') {
    $pages['/pricing.php'] = ['0.6', 'monthly', __DIR__ . '/pricing.php'];
}

// Every value is escaped for XML — ENT_XML1 rather than ENT_QUOTES, so an
// ampersand in a configured base URL can never break the document.
$xmlUrl = function (string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
};

// Every language a page is offered in gets its own <url> (i18n.php), each
// listing all versions as xhtml:link alternates — Google's sitemap form of
// hreflang. A page in one language only has no alternates, so a monolingual
// site produces the same sitemap as before.
$enabledLocales = $config['i18n']['enabled'] ?? ['en'];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
<?php foreach ($pages as $path => [$priority, $changefreq, $file]) : ?>
<?php $alternates = i18nAlternates($path, $enabledLocales, $base); ?>
<?php foreach (i18nPageLocales($path, $enabledLocales) as $locale) : ?>
    <url>
        <loc><?php echo $xmlUrl($base . i18nUrl($path, $locale, $enabledLocales)); ?></loc>
        <lastmod><?php echo $xmlUrl(gmdate('c', @filemtime($file) ?: time())); ?></lastmod>
        <changefreq><?php echo $xmlUrl($changefreq); ?></changefreq>
        <priority><?php echo $xmlUrl($priority); ?></priority>
<?php foreach ($alternates as $alt) : ?>
        <xhtml:link rel="alternate" hreflang="<?php echo $xmlUrl($alt['hreflang']); ?>" href="<?php echo $xmlUrl($alt['href']); ?>"/>
<?php endforeach; ?>
    </url>
<?php endforeach; ?>
<?php endforeach; ?>
</urlset>
