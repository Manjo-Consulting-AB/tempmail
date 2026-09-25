<?php
/**
 * Mail Shield — shared renderer for the legal pages: terms.php, privacy.php and
 * refund-policy.php. Paddle, our reseller, requires all three to be public
 * before it approves the domain, and requires them to be linked from the site
 * footer and from the pricing page.
 *
 * The calling page sets $msLegalDoc and requires this file; it renders the
 * public shell, the contents list and every section, then the footer. The
 * contents are the page's own; this file owns only the layout, so the three
 * documents cannot drift apart in structure.
 *
 *   'title'       string  the <h1>
 *   'lede'        string  one plain-text paragraph under it
 *   'description' string  meta description
 *   'path'        string  canonical path, e.g. '/terms.php'
 *   'updated'     string  effective date as shown, e.g. '25 September 2026' —
 *                         a real date the text took effect, not a file mtime,
 *                         because a legal text is dated by when it applies
 *   'sections'    array   list of ['id' => …, 'heading' => …, 'html' => …];
 *                         heading is plain text, html is trusted markup built by
 *                         the page with every interpolated value escaped
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

if (!isset($msLegalDoc) || !is_array($msLegalDoc)) {
    http_response_code(500);
    exit;
}

$msPage = [
    'title'       => $msLegalDoc['title'] . ' · Mail Shield',
    'description' => $msLegalDoc['description'],
    'path'        => $msLegalDoc['path'],
];

$msNavAnchors = false;   // Features / How it works live on the landing page

require __DIR__ . '/brand.php';
require __DIR__ . '/public_head.php';
require __DIR__ . '/public_nav.php';

$msLegalEsc = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<main id="main">
    <article class="ms-doc">
        <div class="ms-container--narrow">
            <h1 class="ms-doc__title"><?php echo $msLegalEsc($msLegalDoc['title']); ?></h1>
            <p class="ms-lede ms-doc__lede"><?php echo $msLegalEsc($msLegalDoc['lede']); ?></p>
            <p class="ms-doc__meta">Effective <?php echo $msLegalEsc($msLegalDoc['updated']); ?></p>

            <nav class="ms-doc__toc" aria-label="On this page">
                <p class="ms-eyebrow">On this page</p>
                <ul class="ms-doc__toc-list">
                    <?php foreach ($msLegalDoc['sections'] as $msLegalSection) : ?>
                        <li><a href="#<?php echo $msLegalEsc($msLegalSection['id']); ?>"><?php echo $msLegalEsc($msLegalSection['heading']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php foreach ($msLegalDoc['sections'] as $msLegalSection) : ?>
                <section class="ms-doc__section" id="<?php echo $msLegalEsc($msLegalSection['id']); ?>">
                    <h2 class="ms-h2"><?php echo $msLegalEsc($msLegalSection['heading']); ?></h2>
                    <div class="ms-prose">
                        <?php echo $msLegalSection['html']; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </article>
</main>
<?php require __DIR__ . '/public_footer.php'; ?>
