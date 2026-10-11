<?php
/**
 * Hands part of the string catalog to a page's scripts (documentaion/I18N.md).
 *
 * Reads $msI18nJs, a list of key prefixes. Non-empty: emits one
 * <script type="application/json" id="ms-i18n"> holding those keys in this
 * request's language (English where a key is not translated). Empty: emits
 * nothing, so a page without scripts that need strings pays no bytes.
 * The JSON flags keep "</script>", "&" and quotes inert inside the element.
 */

if (!empty($msI18nJs) && is_array($msI18nJs) && function_exists('i18nCatalogSubset')) {
    $msI18nJsJson = json_encode(
        i18nCatalogSubset($msI18nJs),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    if (is_string($msI18nJsJson)) {
        echo '<script type="application/json" id="ms-i18n">' . $msI18nJsJson . '</script>' . "\n";
    }
}
