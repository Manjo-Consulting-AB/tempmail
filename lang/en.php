<?php
/**
 * English catalog — the source language and the fallback for every key
 * (i18n.php, documentaion/I18N.md). Every key used in the code is defined
 * here first; other catalogs translate a subset of these keys and nothing
 * else (tests/i18n_test.php checks both, and that placeholders match).
 *
 * Keys are grouped by where they appear: <area>.<item>. A value is plain text
 * with {name} placeholders, or ['one' => …, 'other' => …] for tp().
 */

return [
    'lang.switcher.label' => 'Language',
];
