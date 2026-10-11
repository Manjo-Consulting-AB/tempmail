<?php
/**
 * The temporary-email page in English: the whole article as data. Loaded by
 * temporary-email.php for the English page, and by temporary-email.php as the
 * fallback when a language has no partials/content/temporary-email.<locale>.php.
 * A Swedish file (temporary-email.sv.php) mirrors this structure: the same
 * section ids in the same order, the same conditional branches (trial on/off)
 * and the same variables; only the words differ, and the Swedish page states
 * the same facts and nothing more (brief §9).
 *
 * Variables temporary-email.php defines for this file:
 *   $msU              closure: a public page path -> its escaped, language-aware URL
 *   $msDocDomain      the mail domain, already HTML-escaped
 *   $msDocTrialDays   trial length in days (0 = no trial, and always 0 when signed in)
 *   $msDocFreeHours   how long a Timed address lasts on a free account, in hours
 *   $msDocProMinDays  the shortest Timed lifetime a Pro account can choose, in days
 *   $msDocProMaxDays  the longest Timed lifetime a Pro account can choose, in days
 *   $msDocStickyMax   the cap on Sticky addresses
 *
 * 'lede', each section's 'html' and the 'cta' lines hold markup authored here, never anything a
 * visitor supplies; 'title', 'description', 'headline' and 'heading' are plain
 * text that the page escapes. Section ids stay English in every language so the
 * #anchors keep working; the table of contents is built from the sections.
 * 'cta' places the first call to action after the section named in 'after'.
 *
 * @return array{title: string, description: string, headline: string, lede: string, toc_label: string, toc_eyebrow: string, sections: list<array{id: string, heading: string, html: string}>, cta: array{after: string, line: string, end_line: string}}
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

return [
    'title'       => 'Temporary email addresses — free disposable email · Mail Shield',
    'description' => 'A temporary email address for signups, downloads and services you only need for a while. Read the mail in your Mail Shield inbox, then let it be cleaned up automatically.',
    'headline'    => 'Temporary email addresses',
    'lede'        => 'Some signups aren\'t worth your real address. A temporary email address gives you a disposable one instead — read it in a proper web inbox, then let Mail Shield clean it up for you.',
    'toc_label'   => 'On this page',
    'toc_eyebrow' => 'On this page',
    'sections'    => [
        [
            'id'      => 'what',
            'heading' => 'What is a temporary email address?',
            'html'    => <<<HTML
<p>A temporary email address exists for a short time and then stops existing. You use it wherever you'd rather not hand over the one you actually read — a free trial, a download, a forum — and anything sent to it lands in a web inbox you can open straight away.</p>
<p>The other name for it is disposable email. The address is meant to be thrown away, so it never has to be memorable or tidy. That's the point: your primary inbox keeps the mail that matters, and the rest goes somewhere you don't have to look after.</p>
<p>In Mail Shield this is called a <strong>timed address</strong>: an address that runs on a clock and deletes itself, with everything in it, when the clock runs out.</p>
HTML,
        ],
        [
            'id'      => 'get',
            'heading' => 'How to get a temporary email address',
            'html'    => <<<HTML
<p>There is no button on this page that prints an address for you, and that's deliberate — a Mail Shield inbox needs an account behind it.</p>
<ol>
    <li>Register with your own email address and a password. It's used to verify the account and to reach you about it, nothing else.</li>
    <li>Verify it, then sign in.</li>
    <li>Create a timed address from your inbox. It's ready to paste into whatever you're signing up for.</li>
</ol>
<p>The extra minute buys something a throwaway generator can't offer. The address lives inside a real inbox that persists, so you can come back for a confirmation that arrives an hour later, or find the message that explains how to cancel. A page that prints an address and forgets you the moment you close the tab can do neither.</p>
HTML,
        ],
        [
            'id'      => 'lifetime',
            'heading' => 'How long does a temporary address last?',
            'html'    => <<<HTML
<p>It depends on your account, and both numbers are exact.</p>

<dl class="ms-doc__retention">
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Free</dt>
        <dd class="ms-doc__tier-value">{$msDocFreeHours} hours</dd>
    </div>
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Pro</dt>
        <dd class="ms-doc__tier-value">{$msDocProMinDays}&ndash;{$msDocProMaxDays} days</dd>
    </div>
</dl>

<p>A free account keeps a timed address for {$msDocFreeHours} hours. On Pro you choose a lifetime of {$msDocProMinDays} to {$msDocProMaxDays} days for each address. When the clock runs out, the address and everything in it is deleted automatically.</p>
HTML,
        ],
        [
            'id'      => 'when-to-use',
            'heading' => 'When to use a temporary email address',
            'html'    => <<<HTML
<p>Temporary email is at its best when the address is the only part you're unsure about.</p>
<ul>
    <li>Free trials for tools you may never open again.</li>
    <li>A one-off download that asks for an address before it starts.</li>
    <li>Forum and community registrations.</li>
    <li>Marketplace listings and classifieds, where you'd rather not publish the address you actually use.</li>
    <li>Testing your own signup and confirmation emails.</li>
</ul>
HTML,
        ],
        [
            'id'      => 'when-not-to-use',
            'heading' => 'When not to use one',
            'html'    => <<<HTML
<p>This is the wrong tool for anything that matters.</p>
<ul>
    <li>Banking, government services, or anything tied to your identity.</li>
    <li>Accounts you'd need to recover — once the address is gone, so is the password reset.</li>
    <li>Anything you intend to keep.</li>
</ul>
<p>One thing worth saying plainly: a temporary inbox you reach through a shared link isn't private. Pass the link on and whoever receives it can read the mail. Mail Shield would rather tell you that than let you assume otherwise, so treat a timed address as somewhere to receive, not somewhere to keep a secret.</p>
HTML,
        ],
        [
            'id'      => 'permanent',
            'heading' => 'Temporary email, or a sticky address?',
            'html'    => <<<HTML
<p>Timed addresses are one half of the story. Mail Shield also gives Pro accounts up to {$msDocStickyMax} sticky addresses — <span class="ms-addr">shopping@{$msDocDomain}</span>, <span class="ms-addr">news@{$msDocDomain}</span>, <span class="ms-addr">projects@{$msDocDomain}</span> — for the senders you'll hear from again. The addresses don't expire. You choose when to remove them, while the mail they receive is still cleaned up according to your retention settings.</p>
<p>If you want a disposable email you never think about again, a timed address is the right choice. If you've been handing out your real address because there was nowhere else to put a newsletter, a sticky address is the better fix. <a href="{$msU('/')}">The landing page has the full picture</a>.</p>
HTML,
        ],
    ],
    'cta' => [
        'after'    => 'lifetime',
        'line'     => $msDocTrialDays > 0
            ? 'It takes about a minute, and your first ' . $msDocTrialDays . ' days include Pro, free.'
            : 'It takes about a minute, and it\'s free.',
        'end_line' => 'Your first timed address is a minute away.',
    ],
];
