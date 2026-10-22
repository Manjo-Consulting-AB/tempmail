<?php

declare(strict_types=1);

/**
 * Regression coverage for emailHtmlForFeed() (email_html_sanitizer.php): the
 * RSS feed's loosening of purified newsletter HTML so it flows in a feed
 * reader's column instead of sitting boxed at a fixed width.
 *
 * Run with:  php tests/feed_html_layout_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No database, no network;
 * the purifier round trip runs only when composer install has been run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$msRepoRoot = dirname(__DIR__);
require $msRepoRoot . '/email_html_sanitizer.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

echo "Mail Shield — newsletter HTML in the RSS feed\n";

$newsletter = '<table width="600" bgcolor="#eeeeee" style="width:600px;background-color:#eeeeee;max-width:600px;" align="center">'
    . '<tr><td width="600" height="40" style="padding:10px;color:#ffffff;height:40px;font-size:20px;">Hej <a href="https://sender.test/x?a=1&amp;b=2" style="color:#fff;background-color:#f00;">Läs mer</a></td></tr>'
    . '</table>'
    . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">preheader</div>'
    . '<div style="width:640px;min-width:640px;margin:0 auto;">Kropp</div>'
    . '<font color="#ff0000" size="3">röd</font>'
    . '<img src="https://sender.test/banner.png" width="600" height="200" alt="b" style="width:600px;height:200px;display:block;">';

$out = emailHtmlForFeed($newsletter);

check('1a. no fixed width attribute is left on a table or cell', !preg_match('/<t(able|d)[^>]*\swidth=/i', $out), $out);
check('1b. no height attribute is left on a cell', !preg_match('/<td[^>]*\sheight=/i', $out), $out);
check('1c. no bgcolor is left', stripos($out, 'bgcolor') === false, $out);
check('1d. no width/height/min-width declaration is left outside images', !preg_match('/<(?!img)[a-z]+[^>]*style="[^"]*(?<![-a-z])(min-)?(width|height):/i', $out), $out);
check('1e. no colour or background colour is left', !preg_match('/(?<![-a-z])(background-)?color:/i', $out) && stripos($out, 'color="') === false, $out);
check('1f. the table is capped at the column', (bool) preg_match('/<table[^>]*style="[^"]*max-width:100%/i', $out), $out);
check('1g. padding, font-size, margin and align survive', str_contains($out, 'padding:10px') && str_contains($out, 'font-size:20px') && str_contains($out, 'margin:0 auto') && str_contains($out, 'align="center"'), $out);
check('1h. the preheader stays hidden', str_contains($out, 'display:none;max-height:0;overflow:hidden;opacity:0'), $out);
check('1i. the image scales to the column', (bool) preg_match('/<img[^>]*style="width:600px;display:block;max-width:100%;height:auto"/', $out), $out);
check('1j. the image keeps its src and size attributes', str_contains($out, 'src="https://sender.test/banner.png"') && str_contains($out, 'width="600"'), $out);
check('1k. the link and its query string survive', str_contains($out, 'href="https://sender.test/x?a=1&amp;b=2"'), $out);
check('1l. non-ASCII text survives as UTF-8', str_contains($out, 'Läs mer') && str_contains($out, 'röd'), $out);
check('1m. the font size attribute survives', str_contains($out, 'size="3"'), $out);
check('1n. no wrapper markup leaks out', !preg_match('/ms-feed-root|<html|<body|<meta/i', $out), $out);

check('2a. an empty body stays empty', emailHtmlForFeed('') === '');
check('2b. plain text is unchanged', emailHtmlForFeed('Bara text') === 'Bara text');
check('2c. an element left with no declarations loses its style attribute', emailHtmlForFeed('<p style="color:#000;">x</p>') === '<p>x</p>');

if (emailHtmlPurifierAvailable()) {
    $raw = '<html><head><style>.x{color:red}</style></head><body>' . $newsletter . '<script>alert(1)</script></body></html>';
    $round = emailHtmlForFeed((string) purifyEmailHtml($raw));
    check('3a. after the purifier: no script and no fixed table width', stripos($round, '<script') === false && !preg_match('/<table[^>]*\swidth=/i', $round), $round);
    check('3b. after the purifier: the image still scales', str_contains($round, 'max-width:100%;height:auto'), $round);
} else {
    echo "[SKIP] 3. HTMLPurifier not installed (composer install)\n";
}

$feed = (string) file_get_contents($msRepoRoot . '/pro_feed.php');
check('4a. pro_feed.php passes purified HTML through emailHtmlForFeed()', str_contains($feed, 'emailHtmlForFeed($purified)'));
$index = (string) file_get_contents($msRepoRoot . '/index.php');
check('4b. the inbox (index.php) does not loosen its HTML', !str_contains($index, 'emailHtmlForFeed('));

echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
