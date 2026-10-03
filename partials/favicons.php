<?php
/**
 * The favicon, touch icon and manifest links, shared by every page's <head>
 * so the set cannot drift between pages again.
 *
 * - favicon.ico (16/32/48) carries sizes="32x32" so Chrome does not prefer it
 *   over the SVG; it is the fallback for browsers without SVG favicons.
 * - apple-touch-icon is a full-bleed opaque square: iOS rounds the corners
 *   itself and would paint transparent corners black.
 * - ?v= is the file's mtime, as for the other assets, because browsers keep
 *   favicons far longer than ordinary cache headers say.
 *
 * Dependency-free on purpose (no config.php, no session), like brand.php.
 * The raster files are rendered from favicon.svg; regenerate them all when
 * the mark changes. /favicon.ico at the root is rewritten to the same file in
 * .htaccess for clients that never read this markup.
 */

if (!function_exists('ms_favicon_url')) {
    function ms_favicon_url(string $file): string
    {
        $path = '/assets/images/' . $file;
        $mtime = @filemtime(dirname(__DIR__) . $path);
        return $path . ($mtime ? '?v=' . $mtime : '');
    }
}
?>
    <link rel="icon" href="<?php echo htmlspecialchars(ms_favicon_url('favicon.ico'), ENT_QUOTES, 'UTF-8'); ?>" sizes="32x32">
    <link rel="icon" href="<?php echo htmlspecialchars(ms_favicon_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(ms_favicon_url('favicon-180x180.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="manifest" href="/site.webmanifest">
