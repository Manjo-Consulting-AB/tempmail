<?php
/**
 * Landing — external email. Spec: the redesign brief (`REDESIGN_BRIEF.md`), §8
 * (copy) and §9 (the honest feature inventory).
 *
 * The power-user section, and the last one before the plans. The copy deck
 * allows it to be more technical than the rest and says it does not need to be
 * fully understandable to a casual visitor, so it is written for someone who
 * already runs mail infrastructure.
 *
 * It is also the one place where the Agent is the subject rather than a card in
 * a list: automation.php has already told the reader that the Agent exists, and
 * this says what it does. The technical sentence is the approved one, verbatim.
 * It names RSA-signed webhooks, filter scripts and sender lists because all
 * three are real (client/agent/ and the mailfilter_scripts / whitelist /
 * blacklist tables behind client/backend/), and it promises nothing beyond
 * them.
 *
 * The monospace block is a three-line flow, in prose. It is deliberately not a
 * request, a header, an endpoint or a config snippet — someone would copy one
 * of those, and every value in it would be invented. There is also no docs link
 * to add: this repository's documentation is repository documentation, not a
 * published site, and the pages that configure the Agent are behind a login.
 * So the section ends on the flow, with no CTA — a reader who runs their own
 * mail server does not need to be walked to it.
 *
 * Sits on the default --ms-bg: automation.php above cleanup.php already
 * supplies the sunken band, and one is enough for this stretch of the page.
 *
 * Full .ms-container, not .ms-container--narrow. The narrow container is for
 * the two centred sections (problem.php, cta.php), where its different width is
 * invisible. This section is left-aligned, so a narrow container put its copy
 * 200px to the right of every other section's at 1440px and the page lost its
 * left edge. The measure is held by the paragraphs' own max-widths instead.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }
?>
<section class="ms-section" id="external">
    <div class="ms-container">
        <p class="ms-eyebrow">Power user</p>
        <h2 class="ms-h2">Bring other mailboxes into the picture.</h2>
        <p class="ms-external__body">Connect external email infrastructure and let Mail Shield handle it alongside your other addresses.</p>
        <p class="ms-external__detail">The Agent runs on your own mail server, verifies RSA-signed webhooks from Mail Shield, and applies your filter scripts and sender lists locally.</p>

        <ol class="ms-external__flow">
            <li class="ms-external__step">message arrives</li>
            <li class="ms-external__step">signed webhook</li>
            <li class="ms-external__step">your server applies your rules</li>
        </ol>
    </div>
</section>
