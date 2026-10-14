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
 *      external-link icon cannot be orphaned onto a line of its own. Since
 *      then a paragraph line ending in two spaces or a backslash also emits a
 *      generated <br /> (a markdown hard line break). No escaping step was
 *      touched.
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
 *   5. Languages (documentaion/I18N.md). English posts are blog/*.md and are
 *      never touched; a translation is blog/<locale>/<same basename>.md and is
 *      as much a dated record as the original (old names and features stay as
 *      they were). The index of a language lists only the posts that have a
 *      file in that language, and a non-English post URL whose post exists only
 *      in English answers a 302 to the English post. The chrome (headings,
 *      navigation, footer, dates) goes through the catalog under `blog.`.
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
 *
 * $rootRelative is for posts shown under a language prefix (/sv/blog.php): a
 * relative image or link path such as blog/images/x.jpeg would resolve against
 * /sv/, so it gets a leading slash. A scheme (https:, mailto:), a leading
 * slash or # is left as written. It only changes a path after the escaping.
 */
function markdown_to_html(string $text, bool $rootRelative = false): string
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
    $hard_break = false;

    $absolutise = function (string $path) use ($rootRelative): string {
        if (!$rootRelative || $path === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $path)) {
            return $path;
        }
        return '/' . $path;
    };

    $inline = function ($s) use ($absolutise) {
        // images: allow optional width suffix like ![alt](path/to/img.jpg|200)
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\|]+)(?:\|(\d+))?\)/', function ($m) use ($absolutise) {
            $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $src = htmlspecialchars($absolutise($m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $width = isset($m[3]) && $m[3] !== '' ? intval($m[3]) : null;
            $style = $width ? 'style="width:' . $width . 'px;height:auto;"' : '';
            return '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid" ' . $style . ' />';
        }, $s);

        // inline code
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
            // links [text](url) - open in new tab safely. Use callback to escape attributes.
            $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) use ($absolutise) {
                $text = $m[1];
                $href = $absolutise($m[2]);
                $textEsc = $text; // already escaped earlier via htmlspecialchars
                $hrefEsc = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $icon = '<i class="fas fa-external-link-alt ms-1" aria-hidden="true"></i>';
                $vis = '<span class="visually-hidden"> ' . htmlspecialchars(t('blog.new_tab'), ENT_QUOTES, 'UTF-8') . '</span>';
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
        if (!$in_paragraph) { $out .= "<p>"; $in_paragraph = true; $hard_break = false; }
        else { $out .= $hard_break ? "<br />\n" : "\n"; }
        // A line ending in two spaces or a backslash breaks the line without
        // starting a new paragraph (standard markdown hard line break). The
        // <br /> is ours, never taken from the post, so escaping is unaffected.
        $hard_break = (bool) preg_match('/(?: {2,}|\\\\)$/', $line);
        if ($hard_break) {
            $line = preg_replace('/(?:\s+|\\\\)$/', '', $line);
        }
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

$msLocale  = i18nLocale();
$msDefault = i18nDefaultLocale();
$msIsDefault = $msLocale === $msDefault;
$msEnabled = $config['i18n']['enabled'] ?? [$msDefault];

// The post list of one language. Discovery and ordering are unchanged: every
// *.md directly in the language's directory (blog/ for English, blog/<locale>/
// for any other), sorted by filename descending, which for YYYY-MM-DD.md is
// newest first. The locale reaches the path only after it has been checked to
// be two letters, and it comes from the URL prefix i18nBootstrap() accepted.
$msScanPosts = function (string $locale) use ($msDefault): array {
    $posts = [];
    $rootBlog = realpath(__DIR__ . '/blog');
    if ($rootBlog === false || !is_dir($rootBlog)) {
        return $posts;
    }
    $dir = $rootBlog;
    if ($locale !== $msDefault) {
        if (!preg_match('/^[a-z]{2}$/', $locale)) {
            return $posts;
        }
        $dir = $rootBlog . '/' . $locale;
        if (!is_dir($dir)) {
            return $posts;
        }
    }

    $files = glob($dir . '/*.md') ?: [];
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

        $posts[$basename] = [
            'title' => $title,
            'body'  => $md,
            'iso'   => $basename,
            'date'  => $dt ? i18nDate($dt->getTimestamp()) : $basename,
        ];
    }

    return $posts;
};

$msPosts   = $msScanPosts($msLocale);
$msEnPosts = $msIsDefault ? $msPosts : $msScanPosts($msDefault);

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

// A post that exists only in English: from another language's URL the reader
// is sent to the English post. The target is the matched record's own
// basename, never the request.
if ($msPost === null && $msPostKey !== '' && !$msIsDefault) {
    foreach ($msEnPosts as $msCandidate) {
        if ($msCandidate['iso'] === $msPostKey) {
            header('Location: ' . i18nUrl('/blog.php?post=' . rawurlencode((string) $msCandidate['iso']), $msDefault, $msEnabled), true, 302);
            exit;
        }
    }
}

$msMissing = $msPostKey !== '' && $msPost === null;

$msStandfirst = t('blog.lede');

$msPath    = '/blog.php';
$msOrigin  = rtrim((string) ($config['email']['base_url'] ?? ''), '/');
$msOgImage = $msOrigin . '/assets/images/og-mailshield.png';

// A link to a public page in this request's language, escaped for HTML.
$msU = function (string $path) use ($msLocale, $msEnabled): string {
    return htmlspecialchars(i18nUrl($path, $msLocale, $msEnabled), ENT_QUOTES, 'UTF-8');
};
$msAssets = $msIsDefault ? '' : '/';

// hreflang alternates of one post: only when the post exists in more than one
// of the languages the page is offered in. i18nAlternates() is path-only, so
// the query is built here.
$msPostAlternates = [];
if ($msPost !== null) {
    $msPostQuery = $msPath . '?post=' . rawurlencode((string) $msPost['iso']);
    $msHave = [];
    foreach (i18nPageLocales($msPath, $msEnabled) as $msCode) {
        $msList = $msCode === $msLocale ? $msPosts : ($msCode === $msDefault ? $msEnPosts : $msScanPosts($msCode));
        if (isset($msList[$msPost['iso']])) {
            $msHave[] = $msCode;
        }
    }
    if (count($msHave) > 1) {
        foreach ($msHave as $msCode) {
            $msPostAlternates[] = ['hreflang' => $msCode, 'href' => $msOrigin . i18nUrl($msPostQuery, $msCode, $msEnabled)];
        }
        $msPostAlternates[] = ['hreflang' => 'x-default', 'href' => $msOrigin . i18nUrl($msPostQuery, $msDefault, $msEnabled)];
    }
}

if ($msPost !== null) {
    $msView        = 'article';
    $msTitle       = t('blog.post.title', ['title' => $msPost['title'] ?? $msPost['date']]);
    $msDescription = $msExcerpt((string) $msPost['body']);
    $msUrl         = $msOrigin . i18nUrl('/blog.php?post=' . rawurlencode((string) $msPost['iso']));
    $msRobots      = 'index, follow';
} elseif ($msMissing) {
    $msView        = 'missing';
    $msTitle       = t('blog.missing.title');
    $msDescription = t('blog.missing.description');
    $msUrl         = $msOrigin . i18nUrl('/blog.php');
    $msRobots      = 'noindex, nofollow';
} else {
    $msView        = 'index';
    $msTitle       = t('blog.meta.title');
    $msDescription = $msStandfirst;
    $msUrl         = $msOrigin . i18nUrl('/blog.php');
    $msRobots      = 'index, follow';
}

if ($msView === 'missing') {
    http_response_code(404);
}

// The bar switches on the same session flag the marketing nav uses, so a
// signed-in reader is never sent back through sign-up.
$msSignedIn = !empty($_SESSION['pro_user_id']);
$msCtaHref  = $msSignedIn ? '/pro.php' : '/register.php?plan=regular';
$msCtaLabel = $msSignedIn ? t('blog.go_to_inbox') : t('blog.create_inbox');
?>
<!DOCTYPE html>
<html lang="<?php echo $msEsc($msLocale); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FAFAF9">
    <?php require __DIR__ . '/partials/analytics.php'; ?>
    <title><?php echo $msEsc($msTitle); ?></title>
    <meta name="description" content="<?php echo $msEsc($msDescription); ?>">
    <meta name="robots" content="<?php echo $msEsc($msRobots); ?>">
    <link rel="canonical" href="<?php echo $msEsc($msUrl); ?>">
<?php if ($msView === 'index') : ?>
<?php require __DIR__ . '/partials/i18n_head.php'; ?>
<?php elseif (count($msPostAlternates) > 0) : ?>
<?php foreach ($msPostAlternates as $msAlt) : ?>
    <link rel="alternate" hreflang="<?php echo $msEsc($msAlt['hreflang']); ?>" href="<?php echo $msEsc($msAlt['href']); ?>">
<?php endforeach; ?>
    <meta property="og:locale" content="<?php echo $msEsc(['en' => 'en_GB', 'sv' => 'sv_SE', 'de' => 'de_DE'][$msLocale] ?? $msLocale); ?>">
<?php endif; ?>

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

<?php require __DIR__ . '/partials/favicons.php'; ?>

    <!-- App-layer dependencies, in the brief §11 order: the bridge loads last and
         wins. -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/style.css" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/mailshield-fonts.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-fonts.css') ?: 1; ?>" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/mailshield.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield.css') ?: 1; ?>" rel="stylesheet">
    <link href="<?php echo $msAssets; ?>assets/css/mailshield-bootstrap.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/mailshield-bootstrap.css') ?: 1; ?>" rel="stylesheet">
</head>
<body>
<a class="ms-skip" href="#main"><?php echo te('blog.skip'); ?></a>
<div class="ms-faq">
    <header class="ms-faq__bar">
        <div class="ms-container ms-faq__bar-inner">
            <?php echo ms_logo(['href' => i18nUrl('/'), 'class' => 'ms-faq__logo']); ?>

            <ul class="ms-faq__links">
                <li><a href="<?php echo $msU('/'); ?>"><?php echo te('blog.home'); ?></a></li>
                <li><a href="<?php echo $msU('/temporary-email.php'); ?>"><?php echo te('blog.temporary_email'); ?></a></li>
                <li><a href="<?php echo $msU('/faq.php'); ?>"><?php echo te('blog.faq'); ?></a></li>
            </ul>

            <div class="ms-faq__actions">
                <?php if ($msSignedIn) : ?>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php else : ?>
                    <a class="ms-faq__login" href="/pro_login.php"><?php echo te('blog.login'); ?></a>
                    <a class="ms-btn ms-btn--primary" href="<?php echo $msEsc($msCtaHref); ?>"><?php echo $msEsc($msCtaLabel); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="ms-faq__main" id="main">
        <div class="ms-container--narrow">
        <?php if ($msView === 'article') : ?>
            <article class="ms-blog__post">
                <p class="ms-blog__back"><a href="<?php echo $msU('/blog.php'); ?>"><span aria-hidden="true">&larr;</span> <?php echo te('blog.all_posts'); ?></a></p>

                <header class="ms-blog__head">
                    <h1 class="ms-blog__title"><?php echo $msEsc($msPost['title'] ?? $msPost['date']); ?></h1>
                    <time class="ms-blog__date" datetime="<?php echo $msEsc($msPost['iso']); ?>"><?php echo $msEsc($msPost['date']); ?></time>
                </header>

                <div class="ms-article">
                    <?php echo markdown_to_html((string) $msPost['body'], !$msIsDefault); ?>
                </div>

                <p class="ms-blog__back ms-blog__back--end"><a href="<?php echo $msU('/'); ?>"><span aria-hidden="true">&larr;</span> <?php echo te('blog.back_to_site'); ?></a></p>
            </article>
        <?php elseif ($msView === 'missing') : ?>
            <h1 class="ms-faq__title"><?php echo te('blog.missing.heading'); ?></h1>
            <p class="ms-lede ms-faq__lede"><?php echo tHtml('blog.missing.text', [], ['index' => '<a href="' . $msU('/blog.php') . '">', 'index_end' => '</a>']); ?></p>
        <?php else : ?>
            <h1 class="ms-faq__title"><?php echo te('blog.title'); ?></h1>
            <p class="ms-lede ms-faq__lede"><?php echo $msEsc($msStandfirst); ?></p>

            <?php if (empty($msPosts)) : ?>
                <p class="ms-blog__empty"><?php echo te('blog.empty'); ?></p>
            <?php else : ?>
                <ul class="ms-blog__list">
                    <?php foreach ($msPosts as $msEntry) : ?>
                    <li class="ms-blog__item">
                        <a class="ms-blog__entry" href="<?php echo $msU('/blog.php?post=' . rawurlencode((string) $msEntry['iso'])); ?>">
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
            <nav class="ms-faq__foot-links" aria-label="<?php echo te('blog.footer_label'); ?>">
                <a href="/register.php?plan=regular"><?php echo te('blog.foot.create_inbox'); ?></a>
                <a href="/pro_login.php"><?php echo te('blog.foot.login'); ?></a>
                <a href="<?php echo $msU('/temporary-email.php'); ?>"><?php echo te('blog.foot.temporary_email'); ?></a>
                <a href="<?php echo $msU('/blog.php'); ?>"><?php echo te('blog.foot.blog'); ?></a>
                <a href="<?php echo $msU('/faq.php'); ?>"><?php echo te('blog.foot.faq'); ?></a>
                <button type="button" class="ms-footer__cookie" data-ms-cookie-settings><?php echo te('blog.foot.cookie_settings'); ?></button>
            </nav>
            <p class="ms-faq__foot-legal">&copy; <?php echo date('Y'); ?> Manjo Consulting AB <span aria-hidden="true">&middot;</span> Mail Shield v<?php echo $msEsc($config['app']['version'] ?? ''); ?></p>
        </div>
    </footer>
</div>
</body>
</html>
