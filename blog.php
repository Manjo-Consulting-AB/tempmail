<?php
/**
 * Mail Shield — the blog. Spec: documentaion/REDESIGN_BRIEF.md §11 (this is an
 * app-layer page: Bootstrap, Font Awesome and the bridge stay, and only the
 * page shape is new), §14 (canonical, Open Graph and Twitter tags, origin taken
 * from $config) and §8 (copy voice).
 *
 * Four things worth knowing before editing this file:
 *
 *   1. markdown_to_html() is deliberately left alone. It escapes the whole
 *      input first and only then runs its regexes over already-escaped text,
 *      escaping attributes again inside each callback — that ordering is what
 *      stops a post's content from injecting markup, so it is not restructured
 *      and no markdown library is swapped in. The single change made here is a
 *      literal space in the link callback becoming a non-breaking one, so the
 *      external-link icon cannot be orphaned onto a line of its own. No
 *      escaping step was touched.
 *   2. The files under blog/ are dated historical records. They are read as
 *      content and nothing else — no rewriting, retitling or rebranding, which
 *      is exactly why the brand rename skipped them.
 *   3. The shell is the FAQ shell. Redesign 19 built the slim bar, narrow
 *      reading column and footer for faq.php, and Redesign 22 has not restyled
 *      partials/nav.php yet, so the same `.ms-faq__*` classes are reused here
 *      rather than copied — the two secondary pages then cannot drift apart.
 *      Only what is specific to the blog is new: the index rows, the article
 *      header and the `.ms-article` prose scope.
 *   4. One post per view. The index lists every post newest-first and links to
 *      `/blog.php?post=<basename>`; an article view renders one. The `?post=`
 *      value is never treated as a path — it is looked up in the list built
 *      from the directory scan, so an unknown value cannot read anything.
 */

define('TEMPMAIL_APP', true);
require_once 'config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/partials/brand.php';

/**
 * Renders the small markdown subset the posts use as HTML.
 *
 * Security note: the input is escaped in full before any regex runs, and each
 * callback escapes its own attribute values again. Keep that order — text is
 * escaped on the way in, never around the generated tags.
 */
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
                // &nbsp; rather than a plain space: the icon is part of the link
                // and must stay with the last word instead of wrapping alone.
                return '<a href="' . $hrefEsc . '" target="_blank" rel="noopener noreferrer">' . $textEsc . '&nbsp;' . $icon . $vis . '</a>';
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

$msEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

/**
 * Plain-text excerpt of a post body, used only for the meta description. The
 * markdown is stripped, not rendered: headings, image syntax and link targets
 * all go, and the result is cut to a length a search result will show.
 */
$msExcerpt = function (string $md, int $limit = 155): string {
    $text = (string) preg_replace('/^\s*#{1,6}\s.*$/m', '', $md);
    $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
    $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
    $text = (string) preg_replace('/[*_`>#]+/', '', $text);
    $text = trim((string) preg_replace('/\s+/', ' ', $text));

    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        $text = rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    return $text;
};

// The post list. Discovery and ordering are unchanged: every *.md directly in
// blog/, sorted by filename descending, which for YYYY-MM-DD.md is newest first.
$msPosts = [];
$rootBlog = realpath(__DIR__ . '/blog');

if ($rootBlog !== false && is_dir($rootBlog)) {
    $files = glob($rootBlog . '/*.md');
    usort($files, function ($a, $b) {
        return strcmp(basename($b), basename($a));
    });

    foreach ($files as $file) {
        $md = (string) file_get_contents($file);
        $basename = basename($file, '.md');

        // The title is the post's first `#` heading; when there is none the
        // formatted date stands in for it.
        $title = null;
        $lines = preg_split('/\r?\n/', ltrim($md));
        if (isset($lines[0]) && preg_match('/^#\s+(.*)$/', $lines[0], $m)) {
            $title = trim($m[1]);
            array_shift($lines);
            $md = ltrim(implode("\n", $lines));
        }

        $dt = DateTime::createFromFormat('Y-m-d', $basename);

        $msPosts[$basename] = [
            'title' => $title,
            'body'  => $md,
            'iso'   => $basename,
            'date'  => $dt ? $dt->format('j F Y') : $basename,
        ];
    }
}

// Routing. `?post=` can only ever *match* one of the records read from disk —
// the value that reaches the page is always a record from the directory scan,
// never the request, which is what keeps arbitrary input out of the output.
// An unmatched value is a 404 rather than a silent fall back to the index.
$msPostKey = isset($_GET['post']) && is_string($_GET['post']) ? $_GET['post'] : '';

$msPost = null;
if ($msPostKey !== '') {
    foreach ($msPosts as $msCandidate) {
        if ($msCandidate['iso'] === $msPostKey) {
            $msPost = $msCandidate;
            break;
        }
    }
}

$msMissing = $msPostKey !== '' && $msPost === null;

$msStandfirst = 'Release notes and notes on running the service.';

$msOrigin  = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msOgImage = $msOrigin . '/assets/images/og-mailshield.png';

if ($msPost !== null) {
    $msView        = 'article';
    $msTitle       = ($msPost['title'] ?? $msPost['date']) . ' · Blog · Mail Shield';
    $msDescription = $msExcerpt((string) $msPost['body']);
    $msUrl         = $msOrigin . '/blog.php?post=' . rawurlencode((string) $msPost['iso']);
    $msRobots      = 'index, follow';
} elseif ($msMissing) {
    $msView        = 'missing';
    $msTitle       = 'Post not found · Blog · Mail Shield';
    $msDescription = 'That post does not exist. Every published post is listed on the blog index.';
    $msUrl         = $msOrigin . '/blog.php';
    $msRobots      = 'noindex, nofollow';
} else {
    $msView        = 'index';
    $msTitle       = 'Blog · Mail Shield';
    $msDescription = $msStandfirst;
    $msUrl         = $msOrigin . '/blog.php';
    $msRobots      = 'index, follow';
}

if ($msView === 'missing') {
    http_response_code(404);
}

// The bar switches on the same session flag the marketing nav uses, so a
// signed-in reader is never sent back through sign-up.
$msSignedIn = !empty($_SESSION['pro_user_id']);
$msCtaHref  = $msSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msCtaLabel = $msSignedIn ? 'Go to your inbox' : 'Create your inbox';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-BFX6EC3575"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-BFX6EC3575');
    </script>
    <title><?php echo $msEsc($msTitle); ?></title>
    <meta name="description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="robots" content="<?php echo $msEsc($msRobots); ?>">
    <link rel="canonical" href="<?php echo $msEsc($msUrl); ?>">

    <meta property="og:type" content="<?php echo $msView === 'article' ? 'article' : 'website'; ?>">
    <meta property="og:site_name" content="Mail Shield">
    <meta property="og:title" content="<?php echo $msEsc($msTitle); ?>">
    <meta property="og:description" content="<?php echo $msEsc($msDescription); ?>">
    <meta property="og:url" content="<?php echo $msEsc($msUrl); ?>">
    <meta property="og:image" content="<?php echo $msEsc($msOgImage); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php if ($msView === 'article') : ?>
    <meta property="article:published_time" content="<?php echo $msEsc($msPost['iso']); ?>">
    <?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $msEsc($msTitle); ?>">
    <meta name="twitter:description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="twitter:image" content="<?php echo $msEsc($msOgImage); ?>">

    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">

    <!-- App-layer dependencies, in the brief §11 order: the bridge loads last and
         wins. -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
<a class="ms-skip" href="#main">Skip to content</a>
<div class="ms-faq">
    <header class="ms-faq__bar">
        <div class="ms-container ms-faq__bar-inner">
            <?php echo ms_logo(['href' => '/', 'class' => 'ms-faq__logo']); ?>

            <ul class="ms-faq__links">
                <li><a href="/">Home</a></li>
                <li><a href="/temporary-email.php">Temporary email</a></li>
                <li><a href="/faq.php">FAQ</a></li>
            </ul>

            <div class="ms-faq__actions">
                <?php if ($msSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php else : ?>
                    <a class="ms-faq__login" href="/pro_login.php">Log in</a>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="ms-faq__main" id="main">
        <div class="ms-container--narrow">
        <?php if ($msView === 'article') : ?>
            <article class="ms-blog__post">
                <p class="ms-blog__back"><a href="/blog.php"><span aria-hidden="true">&larr;</span> All posts</a></p>

                <header class="ms-blog__head">
                    <h1 class="ms-blog__title"><?php echo $msEsc($msPost['title'] ?? $msPost['date']); ?></h1>
                    <time class="ms-blog__date" datetime="<?php echo $msEsc($msPost['iso']); ?>"><?php echo $msEsc($msPost['date']); ?></time>
                </header>

                <div class="ms-article">
                    <?php echo markdown_to_html((string) $msPost['body']); ?>
                </div>

                <p class="ms-blog__back ms-blog__back--end"><a href="/"><span aria-hidden="true">&larr;</span> Back to Mail Shield</a></p>
            </article>
        <?php elseif ($msView === 'missing') : ?>
            <h1 class="ms-faq__title">Post not found</h1>
            <p class="ms-lede ms-faq__lede">That post does not exist. Every published post is listed on the <a href="/blog.php">blog index</a>.</p>
        <?php else : ?>
            <h1 class="ms-faq__title">Blog</h1>
            <p class="ms-lede ms-faq__lede"><?php echo $msEsc($msStandfirst); ?></p>

            <?php if (empty($msPosts)) : ?>
                <p class="ms-blog__empty">No posts yet.</p>
            <?php else : ?>
                <ul class="ms-blog__list">
                    <?php foreach ($msPosts as $msEntry) : ?>
                    <li class="ms-blog__item">
                        <a class="ms-blog__entry" href="/blog.php?post=<?php echo rawurlencode((string) $msEntry['iso']); ?>">
                            <time class="ms-blog__date" datetime="<?php echo $msEsc($msEntry['iso']); ?>"><?php echo $msEsc($msEntry['date']); ?></time>
                            <span class="ms-blog__entry-title"><?php echo $msEsc($msEntry['title'] ?? $msEntry['date']); ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </main>

    <footer class="ms-faq__foot">
        <div class="ms-container">
            <nav class="ms-faq__foot-links" aria-label="Footer">
                <a href="/register.php?plan=regular">Create your inbox</a>
                <a href="/pro_login.php">Log in</a>
                <a href="/temporary-email.php">Temporary email addresses</a>
                <a href="/blog.php">Blog</a>
                <a href="/faq.php">FAQ</a>
            </nav>
            <p class="ms-faq__foot-legal">&copy; <?php echo date('Y'); ?> Manjo Consulting AB <span aria-hidden="true">&middot;</span> Mail Shield v<?php echo $msEsc($config['app']['version'] ?? ''); ?></p>
        </div>
    </footer>
</div>
</body>
</html>
