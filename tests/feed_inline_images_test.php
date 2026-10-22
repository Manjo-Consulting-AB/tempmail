<?php

declare(strict_types=1);

/**
 * Regression coverage for images in the RSS feed (pro_feed.php +
 * email_cid_rewrite.php).
 *
 * Run with:  php tests/feed_inline_images_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network: the
 * pure rewrite functions are called directly, and the real pro_feed.php and
 * files.php are served from the throwaway docroot of
 * tests/lib/pushover_harness.php through PHP's built-in web server (both
 * refuse CLI SAPI), with the stub config.php patched to sign attachment URLs
 * the way config.php does.
 *
 * What it pins: an inline image (<img src="cid:...">) reaches the feed as a
 * signed files.php URL instead of being dropped by the purifier; an external
 * image keeps pointing at the sender; the inline image and enclosure links are
 * signed from received_at for the retention cap, so they are identical on
 * every fetch and outlive the inbox's one-hour links; files.php serves the
 * image through such a link and stops the moment the mail is gone.
 */

$msRepoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}
if (!is_file($msRepoRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "This suite needs HTMLPurifier: run composer install first.\n");
    exit(1);
}

require __DIR__ . '/lib/pushover_harness.php';
require $msRepoRoot . '/email_cid_rewrite.php';
require $msRepoRoot . '/feed_token.php';

// ---------------------------------------------------------------------
// 1. The shared rewrite, called directly
// ---------------------------------------------------------------------

echo "Mail Shield — images in the RSS feed\n";

ms_test_section('1. emailRewriteCidReferences()');

$map = emailCidUrlMap([
    ['id' => 7, 'download_url' => 'https://x.test/files.php?id=7', 'content_id' => '<logo@mail.test>', 'filename' => 'logo.png'],
    ['id' => 8, 'download_url' => 'https://x.test/files.php?id=8', 'content_id' => null, 'filename' => 'banner_image.jpg'],
    ['id' => 0, 'download_url' => 'https://x.test/ignored', 'content_id' => 'zero@mail.test'],
]);
ms_test_same('1a. a <cid> reference is rewritten', '<img src="https://x.test/files.php?id=7">',
    emailRewriteCidReferences('<img src="cid:logo@mail.test">', $map));
ms_test_same('1b. an angle-bracketed reference is rewritten', '<img src="https://x.test/files.php?id=7">',
    emailRewriteCidReferences('<img src="cid:<logo@mail.test>">', $map));
ms_test_same('1c. a url-encoded reference is rewritten', '<img src="https://x.test/files.php?id=7">',
    emailRewriteCidReferences('<img src="cid:logo%40mail.test">', $map));
ms_test_same('1d. a filename reference is rewritten', '<img src="https://x.test/files.php?id=8">',
    emailRewriteCidReferences('<img src="cid:banner_image">', $map));
ms_test_same('1e. an unknown reference is left unchanged', '<img src="cid:nope@mail.test">',
    emailRewriteCidReferences('<img src="cid:nope@mail.test">', $map));
ms_test_same('1f. the fallback answers what the map does not', '<img src="https://x.test/fb">',
    emailRewriteCidReferences('<img src="cid:nope@mail.test">', $map, static fn(string $t): ?string => $t === 'nope@mail.test' ? 'https://x.test/fb' : null));
ms_test_check('1g. an attachment id of 0 is never mapped', !in_array('https://x.test/ignored', $map, true));
ms_test_same('1h. an external image is untouched', '<img src="https://sender.test/a.png">',
    emailRewriteCidReferences('<img src="https://sender.test/a.png">', $map));

// ---------------------------------------------------------------------
// Docroot: pro_feed.php + files.php behind the built-in server
// ---------------------------------------------------------------------

$secret = 'feed-images-test-secret-' . bin2hex(random_bytes(8));
$port = random_int(20000, 60000);
$base = 'http://127.0.0.1:' . $port;

$probe = ms_test_probe_build($msRepoRoot);
echo "probe docroot: {$probe}\n";
foreach (['pro_feed.php', 'feed_token.php', 'email_html_sanitizer.php', 'email_cid_rewrite.php', 'files.php'] as $file) {
    if (!copy($msRepoRoot . '/' . $file, $probe . '/' . $file)) {
        throw new RuntimeException("Could not copy {$file} into the probe docroot");
    }
}
// pro_feed.php loads HTMLPurifier through vendor/autoload.php beside itself.
if (!is_link($probe . '/vendor') && !is_dir($probe . '/vendor')) {
    symlink($msRepoRoot . '/vendor', $probe . '/vendor');
}
mkdir($probe . '/attachments', 0700, true);

$configPhp = ms_test_stub_config_php();
$replacements = [
    "'base_url' => 'http://localhost:8085/'," => "'base_url' => '{$base}/',",
    "'attachments' => ['ttl' => 3600, 'max_size' => 10485760]," =>
        "'attachments' => ['ttl' => 3600, 'max_size' => 10485760, 'download_ttl' => 3600, 'download_secret' => '{$secret}'],",
    "'address_length' => 10," => "'address_length' => 10,\n        'cleanup_hours' => 24,",
    // The real body from config.php, so the feed's links verify in files.php.
    "function generateSignedAttachmentUrl(...\$args) { return ''; }" => <<<'PHP'
function generateSignedAttachmentUrl(int $attachmentId, ?int $ttlSeconds = null, ?int $baseTime = null): string {
    global $config;
    $ttl = $ttlSeconds ?? (int)($config['attachments']['download_ttl'] ?? 3600);
    $expires = ($baseTime ?? time()) + $ttl;
    $sig = hash_hmac('sha256', $attachmentId . '|' . $expires, $config['attachments']['download_secret'] ?? '');
    return rtrim($config['email']['base_url'] ?? '', '/') . '/files.php?id=' . urlencode((string)$attachmentId) . '&expires=' . $expires . '&sig=' . urlencode($sig);
}
function sanitizeInt($input, int $min = 0, int $max = PHP_INT_MAX, ?int $default = null): ?int {
    if ($input === null || $input === '' || !is_numeric($input)) return $default;
    return max($min, min($max, (int) $input));
}
PHP,
];
foreach ($replacements as $from => $to) {
    $configPhp = str_replace($from, $to, $configPhp, $count);
    if ($count !== 1) {
        throw new RuntimeException('Could not patch the stub config.php at: ' . strtok($from, "\n"));
    }
}
file_put_contents($probe . '/config.php', $configPhp);

$pdo = ms_test_db(ms_test_probe_sqlite($probe));
foreach ([
    'ALTER TABLE pro_users ADD COLUMN feed_token_hash TEXT NULL',
    'ALTER TABLE pro_users ADD COLUMN feed_token_enc TEXT NULL',
    'ALTER TABLE pro_users ADD COLUMN email_enc TEXT NULL',
    'ALTER TABLE temp_emails ADD COLUMN feed_token_hash TEXT NULL',
    'ALTER TABLE temp_emails ADD COLUMN feed_token_enc TEXT NULL',
    'CREATE TABLE stored_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, temp_email_id INTEGER NOT NULL, from_address TEXT NULL, subject TEXT NULL, body_html TEXT NULL, body_text TEXT NULL, received_at TEXT NULL, expires_at TEXT NULL)',
    'CREATE TABLE email_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, email_id INTEGER NOT NULL, filename TEXT NULL, file_path TEXT NULL, mime_type TEXT NULL, content_id TEXT NULL, created_at TEXT NULL)',
] as $ddl) {
    $pdo->exec($ddl);
}

$token = bin2hex(random_bytes(32));
$userId = ms_test_seed_user($pdo, 'images@example.com', 'pro');
$pdo->prepare('UPDATE pro_users SET feed_token_hash = ? WHERE id = ?')->execute([feedTokenHash($token), $userId]);
$addrId = ms_test_seed_address($pdo, 'imgaddr01', ['pro_user_id' => $userId, 'is_personal' => 1]);

$receivedAt = date('Y-m-d H:i:s', time() - 7200); // two hours ago: an inbox link signed then would be dead
$pdo->prepare('INSERT INTO stored_emails (temp_email_id, from_address, subject, body_html, received_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$addrId, 'news@sender.test', 'Newsletter',
        '<p>Hi</p><img src="cid:logo@sender.test" alt="logo"><img src="https://sender.test/banner.png" alt="banner">',
        $receivedAt, date('Y-m-d H:i:s', time() + 86400)]);
$emailId = (int) $pdo->lastInsertId();

// A real 1x1 PNG on disk, as AttachmentStorage would have written it.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$diskName = time() . '_abcd1234_logo.png';
file_put_contents($probe . '/attachments/' . $diskName, $png);
$pdo->prepare('INSERT INTO email_attachments (email_id, filename, file_path, mime_type, content_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$emailId, 'logo.png', 'attachments/' . $diskName, 'image/png', '<logo@sender.test>', $receivedAt]);
$attId = (int) $pdo->lastInsertId();
$pdo = null;

$serverProc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:{$port}", '-t', $probe],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    $probe,
    ['PROBE_SQLITE' => ms_test_probe_sqlite($probe), 'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
);
usleep(400000);

/** GET $url against the probe; returns [status, body, headers]. */
function ms_img_get(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    $status = 0;
    if (isset($headers[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $headers[0], $m)) {
        $status = (int) $m[1];
    }
    return [$status, $body === false ? '' : $body, $headers];
}

// ---------------------------------------------------------------------
// 2. The feed
// ---------------------------------------------------------------------

ms_test_section('2. pro_feed.php: inline images become signed links, external ones stay');

[$status, $feed] = ms_img_get($base . '/pro_feed.php?token=' . $token);
ms_test_same('2a. the feed answers 200', 200, $status);

$expectedExpires = strtotime($receivedAt) + (18250 + 1) * 86400;
$expectedSig = hash_hmac('sha256', $attId . '|' . $expectedExpires, $secret);
$expectedUrl = $base . '/files.php?id=' . $attId . '&expires=' . $expectedExpires . '&sig=' . $expectedSig;

ms_test_check('2b. the inline image points at files.php, signed from received_at', str_contains($feed, 'src="' . htmlspecialchars($expectedUrl) . '"'), substr($feed, 0, 1500));
ms_test_check('2c. no cid: reference is left in the feed', !str_contains($feed, 'cid:'));
ms_test_check('2d. the external image still points at the sender', str_contains($feed, 'src="https://sender.test/banner.png"'));
ms_test_check('2e. the enclosure carries the same long-lived link', str_contains($feed, '<enclosure url="' . htmlspecialchars($expectedUrl) . '"'));

usleep(1100000);
[, $feedAgain] = ms_img_get($base . '/pro_feed.php?token=' . $token);
ms_test_check('2f. a second fetch a second later yields byte-identical items', $feed !== '' && $feed === $feedAgain);

// ---------------------------------------------------------------------
// 3. files.php through the feed's link
// ---------------------------------------------------------------------

ms_test_section('3. files.php serves the image until the mail is gone');

[$status, $body, $headers] = ms_img_get($expectedUrl);
ms_test_same('3a. the link answers 200 two hours after the mail arrived', 200, $status);
ms_test_same('3b. the bytes are the stored image', $png, $body);
ms_test_check('3c. it is served as image/png', in_array('Content-Type: image/png', $headers, true), implode(' | ', $headers));

$pdo = ms_test_refresh_db(ms_test_probe_sqlite($probe));
$pdo->prepare('UPDATE stored_emails SET expires_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $emailId]);
[$status] = ms_img_get($expectedUrl);
ms_test_same('3d. once the mail has expired the same link answers 410', 410, $status);

$pdo->prepare('DELETE FROM stored_emails WHERE id = ?')->execute([$emailId]);
[$status] = ms_img_get($expectedUrl);
ms_test_same('3e. once the mail is deleted the same link answers 404', 404, $status);
$pdo = null;

// ---------------------------------------------------------------------
// 4. The inbox still uses its own short links (scan)
// ---------------------------------------------------------------------

ms_test_section('4. index.php get_email uses the shared rewrite');

$index = (string) file_get_contents($msRepoRoot . '/index.php');
ms_test_check('4a. index.php calls emailRewriteCidReferences()', str_contains($index, 'emailRewriteCidReferences('));
ms_test_check('4b. index.php keeps the default TTL for its links', str_contains($index, 'generateSignedAttachmentUrl($aid, null, $signatureTime)'));

if (is_resource($serverProc)) {
    proc_terminate($serverProc);
    proc_close($serverProc);
}
ms_test_cleanup($probe);
exit(ms_test_summary());
