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

    'nav.skip'          => 'Skip to content',
    'nav.menu'          => 'Menu',
    'nav.main'          => 'Main',
    'nav.features'      => 'Features',
    'nav.how_it_works'  => 'How it works',
    'nav.pricing'       => 'Pricing',
    'nav.faq'           => 'FAQ',
    'nav.login'         => 'Log in',
    'nav.create_inbox'  => 'Create your inbox',
    'nav.go_to_inbox'   => 'Go to your inbox',

    'footer.trust'           => 'Mail Shield is operated by {company}.',
    'footer.product'         => 'Product',
    'footer.more'            => 'More',
    'footer.legal'           => 'Legal',
    'footer.create_inbox'    => 'Create your inbox',
    'footer.login'           => 'Log in',
    'footer.temporary_email' => 'Temporary email addresses',
    'footer.faq'             => 'FAQ',
    'footer.blog'            => 'Blog',
    'footer.pricing'         => 'Pricing',
    'footer.terms'           => 'Terms of service',
    'footer.privacy'         => 'Privacy policy',
    'footer.refund'          => 'Refund policy',
    'footer.cookie_settings' => 'Cookie settings',
];
