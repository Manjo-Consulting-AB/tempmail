<?php
// Simple blog page: reads markdown files from ../blog/*.md and renders HTML

declare(strict_types=1);

require_once 'config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function markdown_to_html(string $text): string
{
    $text = rtrim($text, "\n");
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Handle fenced code blocks ```lang\n...```
    $text = preg_replace_callback('#```\s*([^\n\r]*)\n(.*?)```#ms', function ($m) {
        $lang = trim($m[1]);
        $code = $m[2];
        return "<pre><code" . ($lang ? " class=\"language-" . htmlspecialchars($lang) . "\"" : '') . ">" . $code . "</code></pre>";
    }, $text);

    $lines = preg_split('/\r?\n/', $text);
    $out = '';
    $in_paragraph = false;
    $in_ul = false;
    $in_ol = false;

    $inline = function ($s) {
        // images: allow optional width suffix like ![alt](path/to/img.jpg|200)
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\|]+)(?:\|(\d+))?\)/', function ($m) {
            $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $src = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $width = isset($m[3]) && $m[3] !== '' ? intval($m[3]) : null;
            $style = $width ? 'style="width:' . $width . 'px;height:auto;"' : '';
            return '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid" ' . $style . ' />';
        }, $s);

        // inline code
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
            // links [text](url) - open in new tab safely. Use callback to escape attributes.
            $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
                $text = $m[1];
                $href = $m[2];
                $textEsc = $text; // already escaped earlier via htmlspecialchars
                $hrefEsc = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $icon = '<i class="fas fa-external-link-alt ms-1" aria-hidden="true"></i>';
                $vis = '<span class="visually-hidden"> (opens in new tab)</span>';
                return '<a href="' . $hrefEsc . '" target="_blank" rel="noopener noreferrer">' . $textEsc . ' ' . $icon . $vis . '</a>';
            }, $s);
        // bold
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        // italic
        $s = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $s);
        return $s;
    };

    foreach ($lines as $line) {
        // headings
        if (preg_match('/^(#{1,6})\s*(.*)$/', $line, $m)) {
            // close lists / paragraphs
            if ($in_ul) { $out .= "</ul>\n"; $in_ul = false; }
            if ($in_ol) { $out .= "</ol>\n"; $in_ol = false; }
            if ($in_paragraph) { $out .= "</p>\n"; $in_paragraph = false; }
            $level = strlen($m[1]);
            $content = $inline(trim($m[2]));
            $out .= "<h{$level}>" . $content . "</h{$level}>\n";
            continue;
        }

        // unordered list
        if (preg_match('/^[-\*]\s+(.*)$/', $line, $m)) {
            if ($in_paragraph) { $out .= "</p>\n"; $in_paragraph = false; }
            if (!$in_ul) { $out .= "<ul>\n"; $in_ul = true; }
            $out .= "<li>" . $inline(trim($m[1])) . "</li>\n";
            continue;
        }

        // ordered list
        if (preg_match('/^\d+\.\s+(.*)$/', $line, $m)) {
            if ($in_paragraph) { $out .= "</p>\n"; $in_paragraph = false; }
            if (!$in_ol) { $out .= "<ol>\n"; $in_ol = true; }
            $out .= "<li>" . $inline(trim($m[1])) . "</li>\n";
            continue;
        }

        // blank line
        if (trim($line) === '') {
            if ($in_ul) { $out .= "</ul>\n"; $in_ul = false; }
            if ($in_ol) { $out .= "</ol>\n"; $in_ol = false; }
            if ($in_paragraph) { $out .= "</p>\n"; $in_paragraph = false; }
            continue;
        }

        // regular paragraph line
        if (!$in_paragraph) { $out .= "<p>"; $in_paragraph = true; }
        else { $out .= "\n"; }
        $out .= $inline($line);
    }

    // close any open tags
    if ($in_ul) { $out .= "</ul>\n"; }
    if ($in_ol) { $out .= "</ol>\n"; }
    if ($in_paragraph) { $out .= "</p>\n"; }

    return $out;
}

// Entrypoint: render blog index with posts from ./blog
$rootBlog = realpath(__DIR__ . '/blog');
if ($rootBlog === false || !is_dir($rootBlog)) {
    http_response_code(200);
    echo "<h1>Blog</h1><p>No blog directory found at <strong>/blog</strong>. Create a folder named <code>blog</code> at project root and add files like <code>2026-01-03.md</code>.</p>";
    exit;
}

$files = glob($rootBlog . '/*.md');
// sort descending by filename (dates like YYYY-MM-DD.md)
usort($files, function ($a, $b) {
    return strcmp(basename($b), basename($a));
});

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-BFX6EC3575"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);} 
        gtag('js', new Date());
        gtag('config', 'G-BFX6EC3575');
    </script>
    <title>TempMail - Blog</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        /* Small blog-specific tweaks that complement main stylesheet */
        .blog-header h1 { color: white; margin-bottom: 0.25rem; }
        .blog-post pre { background: #0b0b0b; color: #f8f8f2; padding: 12px; border-radius: 6px; overflow:auto; }
        .blog-post code { background: #f4f4f4; padding: 2px 4px; border-radius:4px; }
        .blog-post p { color: #333; line-height:1.6; }
        .blog-post h2, .blog-post h3 { margin-top:0.5rem; }
        .card.blog-modual { border-radius:12px; }
        .blog-post img { max-width:100%; height:auto; border-radius:8px; display:block; margin:0.5rem 0; }
        .post-anchor { font-size:0.9rem; color: #6c757d; margin-left:0.5rem; text-decoration:none; }
        .post-anchor:hover { color: #343a40; }
    </style>
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header blog-header">
            <h1><i class="fas fa-book-open"></i> Blog</h1>
            <p class="lead">Updates, notes and news from the TempMail project.</p>
        </div>

        <?php require 'partials/nav.php'; ?>

        <?php if (empty($files)): ?>
            <div class="card mt-4 card-main-width">
                <div class="card-body">
                    <p>No posts yet.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($files as $file):
        $md = file_get_contents($file);
        // extract title if first line is a H1
        $title = null;
        $lines = preg_split('/\r?\n/', ltrim($md));
        if (isset($lines[0]) && preg_match('/^#\s+(.*)$/', $lines[0], $m)) {
            $title = trim($m[1]);
            // remove first line from body
            array_shift($lines);
            $md = ltrim(implode("\n", $lines));
        }
        $basename = basename($file, '.md');
        $date = $basename;
        // try to parse date from filename
        $dt = DateTime::createFromFormat('Y-m-d', $basename);
        $readableDate = $dt ? $dt->format('F j, Y') : $basename;
        // build a stable slug for anchors: prefer date + slugified title when available
        $slugBase = $title ? $title : $basename;
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim(preg_replace('/\s+/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT', $slugBase)))));
        $slug = trim($slug, '-');
        if ($slug === '') { $slug = $basename; }
        $anchorId = htmlspecialchars($basename . '-' . $slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
    <div id="post-<?php echo $anchorId; ?>" class="card mt-4 card-main-width blog-modual">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-start">
                <h3 class="mb-0"><?php echo $title ? $title : $readableDate; ?>
                    <a class="post-anchor" href="#post-<?php echo $anchorId; ?>" aria-label="Link to this post"><i class="fas fa-link" aria-hidden="true"></i></a>
                </h3>
                <time datetime="<?php echo htmlspecialchars($date); ?>" class="ms-2 text-muted"><?php echo htmlspecialchars($readableDate); ?></time>
            </div>
        </div>
        <div class="card-body blog-post">
            <?php echo markdown_to_html($md); ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
