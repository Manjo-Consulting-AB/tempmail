<?php
/**
 * Shared sanitising of inbound email HTML.
 *
 * `stored_emails.body_html` is raw HTML from whoever mailed the address - it
 * is fully attacker-controlled and needs no login to plant. Every place that
 * hands it to a browser or a feed reader runs it through purifyEmailHtml()
 * first: HTMLPurifier reduces it to a formatting/image/link allowlist, so
 * scripts, event handlers, forms, iframes, objects, embeds and non-http(s)
 * URLs are gone before the markup leaves the server.
 *
 * External images are deliberately kept (http/https `img src`): the inbox UI
 * has its own "block images" preference that rewrites them client-side.
 *
 * Used by index.php (get_email) and pro_feed.php (RSS). The file only defines
 * functions, so a direct HTTP request to it outputs nothing.
 */

if (!function_exists('emailHtmlPurifierAvailable')) {
    /**
     * Load Composer's autoloader if needed and report whether HTMLPurifier
     * can be used. Callers must fail closed when this returns false.
     */
    function emailHtmlPurifierAvailable(): bool
    {
        if (!class_exists('HTMLPurifier', false)) {
            $vendorAutoload = __DIR__ . '/vendor/autoload.php';
            if (is_file($vendorAutoload)) {
                require_once $vendorAutoload;
            }
        }
        return class_exists('HTMLPurifier') && class_exists('HTMLPurifier_Config');
    }
}

if (!function_exists('purifyEmailHtml')) {
    /**
     * Sanitize inbound email HTML down to a safe allowlist.
     *
     * Returns null when HTMLPurifier is unavailable or throws, so the caller
     * can fail closed (drop the HTML body, show text instead) rather than
     * ever passing the raw markup through.
     */
    function purifyEmailHtml(string $html): ?string
    {
        if (!emailHtmlPurifierAvailable()) {
            return null;
        }

        static $purifier = null;
        try {
            if ($purifier === null) {
                $config = HTMLPurifier_Config::createDefault();
                // No writable cache dir is guaranteed on shared hosting; the
                // definition is rebuilt once per request, which is cheap.
                $config->set('Cache.DefinitionImpl', null);
                $config->set('HTML.Allowed', implode(',', [
                    'p[style|align]', 'br', 'b', 'strong', 'i', 'em', 'u', 's',
                    'a[href|title|style]', 'img[src|alt|title|width|height|style]',
                    'ul', 'ol', 'li', 'blockquote', 'hr', 'pre', 'code', 'center',
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                    'span[style]', 'div[style|align]', 'font[color|size|face]',
                    'table[style|width|border|cellpadding|cellspacing|align|bgcolor]',
                    'thead', 'tbody', 'tfoot', 'tr[style|align|valign|bgcolor]',
                    'td[style|colspan|rowspan|width|align|valign|bgcolor]',
                    'th[style|colspan|rowspan|width|align|valign|bgcolor]',
                ]));
                // background/background-image are left out on purpose: a CSS
                // url() would load a remote image past the "block images"
                // preference, which only rewrites <img>.
                $config->set('CSS.AllowedProperties', [
                    'color', 'background-color', 'font-size', 'font-weight', 'font-style',
                    'font-family', 'line-height', 'text-align', 'text-decoration',
                    'vertical-align', 'padding', 'margin', 'border',
                    'width', 'height', 'max-width',
                    // Newsletters hide their preheader (inbox preview text,
                    // padded with hundreds of invisible characters) with
                    // display:none / max-height:0 / overflow:hidden /
                    // opacity:0. Dropping these while keeping max-width:0
                    // turned it into a long blank column above the message.
                    // Harmless in the sandboxed, script-free mail frame.
                    'max-height', 'display', 'visibility', 'overflow', 'opacity',
                ]);
                // display, visibility, overflow and opacity are HTMLPurifier's
                // "tricky" properties and are dropped unless this is on.
                $config->set('CSS.AllowTricky', true);
                // Only schemes that cannot carry script (blocks javascript:,
                // data:, vbscript:, ...). Relative URLs stay allowed so the
                // /files.php links get_email rewrites cid: references to work.
                $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
                // Adds target="_blank" rel="noreferrer noopener" to links.
                $config->set('HTML.TargetBlank', true);
                $purifier = new HTMLPurifier($config);
            }
            return $purifier->purify($html);
        } catch (Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'HTMLPurifier failed on email body', ['error' => $e->getMessage()]);
            }
            return null;
        }
    }
}

if (!function_exists('emailHtmlToText')) {
    /**
     * Flatten email HTML to plain text (for the inbox list preview and as the
     * fail-closed fallback when the HTML body cannot be purified). The result
     * is plain text and must still be escaped by whoever prints it.
     */
    function emailHtmlToText(string $html): string
    {
        // strip_tags() keeps the text of <style>/<script>, which would dump
        // CSS source into the text - drop those elements entirely first.
        $text = preg_replace('/<(style|script|head|title)\b[^>]*>.*?<\/\1\s*>/is', '', $html) ?? '';
        $text = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])\b[^>]*>/i', "\n", $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n\s*\n\s*/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}

if (!function_exists('emailRowForList')) {
    /**
     * Shape a stored_emails row for the inbox list (get_emails /
     * refresh_emails). The list only shows a text preview - the full message
     * is fetched through get_email when opened - so the raw HTML body never
     * goes out here, and a mail without a text part gets a flattened text
     * body for its preview instead. This also keeps the list poll from
     * purifying up to 50 bodies per request.
     */
    function emailRowForList(array $row): array
    {
        if (!empty($row['body_html'])) {
            if (empty($row['body_text'])) {
                $row['body_text'] = mb_substr(emailHtmlToText((string)$row['body_html']), 0, 500);
            }
        }
        if (array_key_exists('body_html', $row)) {
            $row['body_html'] = null;
        }
        return $row;
    }
}

if (!function_exists('emailRowForDisplay')) {
    /**
     * Shape a single stored_emails row for the message view (get_email):
     * body_html is replaced by its purified form, or dropped (fail closed)
     * with a text fallback when purification is unavailable.
     */
    function emailRowForDisplay(array $row): array
    {
        if (!empty($row['body_html'])) {
            $raw = (string)$row['body_html'];
            $purified = purifyEmailHtml($raw);
            $row['body_html'] = ($purified !== null && trim($purified) !== '') ? $purified : null;
            if ($row['body_html'] === null && empty($row['body_text'])) {
                $row['body_text'] = emailHtmlToText($raw);
            }
        }
        return $row;
    }
}
