<?php
/**
 * Mail Shield — Google Analytics, consent-gated.
 * Spec: documentaion/REDESIGN_BRIEF.md §12.10, issue #313 (part of epic #312).
 *
 * The single copy of the analytics snippet. It prints no request to
 * googletagmanager.com by itself — it only tells assets/js/consent.js the
 * measurement id and lets that script decide, from a stored consent choice or
 * from the visitor's answer to the notice it shows, whether gtag.js is ever
 * requested. Declining, or never answering, means nothing beyond this file's
 * own two small script tags is ever loaded.
 *
 * Included by:
 *   - partials/public_head.php, for every marketing page, unless the page sets
 *     $msPage['analytics'] = false (welcome.php, the checkout success page,
 *     opts out this way — it is signed-in, noindex, post-checkout context).
 *   - blog.php and faq.php directly, since both carry their own <head> (the
 *     app-layer Bootstrap shell) rather than the marketing one.
 *
 * Never included by inbox.php, pro.php, pro_profile_page.php, pro_login.php,
 * pro_logout.php or register.php — those are app/auth pages and must never
 * request Google Analytics, whatever the consent state (brief §12.10).
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}
?>
<!-- Google Analytics: consent-gated. See partials/analytics.php and
     assets/js/consent.js — gtag.js is requested only after the visitor
     accepts the cookie notice this script shows. -->
<script>window.msAnalyticsId = 'G-BFX6EC3575';</script>
<script src="/assets/js/consent.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/consent.js') ?: 1; ?>"></script>
