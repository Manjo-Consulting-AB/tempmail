<?php
/**
 * Mail Shield brand marks. Inline SVG so the mark inherits currentColor
 * and needs no icon font. See documentaion/REDESIGN_BRIEF.md section 4.
 *
 * Dependency-free on purpose: no config.php, no session, no DB. Every function
 * is function_exists-guarded so a repeated require cannot redeclare them.
 */

if (!function_exists('ms_shield_svg')) {
    /**
     * Internal: builds the shield SVG. Single source of truth for the geometry,
     * shared by ms_shield_mark() and ms_logo() so the two cannot drift apart.
     *
     * The mark is a 20x24 shield with an envelope flap inside it. The interior
     * is tuned for 16px — at that size the fold line and the two flap strokes
     * still leave a visible gap instead of collapsing into a solid triangle.
     *
     * @param int    $size       Rendered square size in px.
     * @param string $class      Extra CSS class(es) for the <svg>.
     * @param bool   $decorative True when a wordmark carries the meaning, so
     *                           the SVG is hidden from assistive tech.
     */
    function ms_shield_svg(int $size, string $class, bool $decorative): string
    {
        $size = max(1, $size);
        $attr = ' xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
              . ' viewBox="0 0 20 24" fill="none" focusable="false"';

        if ($class !== '') {
            $attr .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
        }

        $attr .= $decorative ? ' aria-hidden="true"' : ' role="img"';

        $stroke = ' fill="none" stroke="currentColor" stroke-width="1.6"'
                . ' stroke-linejoin="round" stroke-linecap="round"';

        $svg = '<svg' . $attr . '>';

        if (!$decorative) {
            $svg .= '<title>Mail Shield</title>';
        }

        return $svg
            // Shield outline: flat top, straight sides, taper to a soft point.
            . '<path d="M2.4 3.2H17.6V11.8C17.6 16.6 14.6 19.8 10 21.3C5.4 19.8 2.4 16.6 2.4 11.8V3.2Z"' . $stroke . '/>'
            // Envelope flap: fold line on the third, two strokes to the centre.
            . '<path d="M5.6 8.6H14.4M5.6 8.6L10 13.6L14.4 8.6"' . $stroke . '/>'
            . '</svg>';
    }
}

if (!function_exists('ms_shield_mark')) {
    /**
     * The shield mark on its own.
     *
     * @param int    $size  Rendered square size in px.
     * @param string $class Extra CSS class(es) for the <svg>.
     */
    function ms_shield_mark(int $size = 22, string $class = ''): string
    {
        return ms_shield_svg($size, $class, true);
    }
}

if (!function_exists('ms_logo')) {
    /**
     * Mark + "Mail Shield" wordmark.
     *
     * @param array $opts href (string|null - wraps in <a> when set),
     *                    size (int), class (string), mark_only (bool)
     */
    function ms_logo(array $opts = []): string
    {
        $href     = isset($opts['href']) && $opts['href'] !== '' ? (string) $opts['href'] : null;
        $size     = isset($opts['size']) ? (int) $opts['size'] : 22;
        $class    = isset($opts['class']) ? trim((string) $opts['class']) : '';
        $markOnly = !empty($opts['mark_only']);

        $inner = ms_shield_svg($size, 'ms-logo__mark', !$markOnly);

        if (!$markOnly) {
            $inner .= '<span class="ms-logo__word">Mail Shield</span>';
        }

        $classes = $class === '' ? 'ms-logo' : 'ms-logo ' . $class;
        $classes = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');

        if ($href !== null) {
            return '<a class="' . $classes . '" href="'
                 . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a>';
        }

        return '<span class="' . $classes . '">' . $inner . '</span>';
    }
}
