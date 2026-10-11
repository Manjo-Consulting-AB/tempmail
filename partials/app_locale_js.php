<?php
/**
 * Tells the page's scripts which language the page is in and sends it to our
 * own endpoints as X-MS-Locale (documentaion/I18N.md §2.1), so a JSON answer
 * is in the language of the page that asked for it.
 *
 * Include it immediately after the jQuery <script> tag. Without jQuery on the
 * page it only sets window.msLocale. A plain fetch() to one of our own
 * endpoints adds 'X-MS-Locale': window.msLocale to its headers itself.
 */
?>
<script>
(function () {
    window.msLocale = document.documentElement.lang || 'en';
    if (window.jQuery) {
        window.jQuery.ajaxSetup({ headers: { 'X-MS-Locale': window.msLocale } });
    }
})();
</script>
