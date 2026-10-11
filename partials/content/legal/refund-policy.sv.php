<?php
/**
 * The refund policy in Swedish: the whole document as data, rendered by
 * partials/legal_page.php. Mirrors refund-policy.en.php: the same section ids
 * in the same order, the same facts as variables and the same 'updated' date;
 * only the words differ. It states no clause on which language prevails (epic
 * #441 decision 2). Glossary: documentaion/I18N.md §5.
 *
 * Variables refund-policy.php defines for this file:
 *   $f     array    the legal facts (partials/legal_facts.php), already escaped
 *   $ref   array    the invite programme's settings (referralSettings())
 *   $msU   closure  a public page path -> its escaped, language-aware URL
 * and defines $guaranteeDays and $graceDays itself.
 *
 * 'html' holds trusted markup authored here with every interpolated value
 * escaped, never anything a visitor supplies. Never write a number into the
 * text: it comes from $f or $ref.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// How long the money-back guarantee lasts, and the grace before a lapsed Pro
// account's Sticky addresses are deleted (cron/cleanup.php). Fixed by the code,
// not by $config; named here so no number is typed into the prose.
$guaranteeDays = 30;
$graceDays = 7;

return [
    'title'       => 'Återbetalningspolicy',
    'lede'        => 'Om Mail Shield Pro inte passar dig kan du få pengarna tillbaka inom ' . $guaranteeDays . ' dagar efter betalningen. Utan frågor.',
    'description' => 'Mail Shield har ' . $guaranteeDays . ' dagars pengarna-tillbaka-garanti på Pro-prenumerationer och Lifetime-köp. Återbetalningar görs av Paddle, vår återförsäljare.',
    'path'        => '/refund-policy.php',
    'updated'     => '2026-10-10',
    'sections'    => [
        [
            'id'      => 'guarantee',
            'heading' => $guaranteeDays . ' dagars pengarna-tillbaka-garanti',
            'html'    => '<p>Varje betalning för Mail Shield Pro kan återbetalas i sin helhet om du ber om det inom ' . $guaranteeDays . ' dagar från det datum då pengarna drogs. Det gäller:</p>'
                . '<ul>'
                . '<li>den första betalningen för en månads- eller årsprenumeration;</li>'
                . '<li>varje förnyelse av en prenumeration, räknat från förnyelsens datum;</li>'
                . '<li>ett engångsköp av Lifetime.</li>'
                . '</ul>'
                . '<p>Du behöver inte ange någon anledning. Återbetalningen avser hela det belopp du betalade, inklusive eventuella skatter.</p>'
                . '<p>Garantin gäller utöver dina lagstadgade rättigheter som konsument, däribland eventuell ångerrätt, som Paddle hanterar som Merchant of Record enligt sina köpvillkor. Den ersätter eller begränsar dem inte.</p>',
        ],
        [
            'id'      => 'how',
            'heading' => 'Så ber du om återbetalning',
            'html'    => '<p>Vår orderhantering sköts av vår återförsäljare Paddle.com. Paddle.com är Merchant of Record för alla våra beställningar. Paddle tar hand om alla kundtjänstärenden och hanterar returer.</p>'
                . '<p>Du kan be om återbetalning på två sätt:</p>'
                . '<ul>'
                . '<li><strong>Via Paddle</strong> – följ länken i ditt kvitto-mejl, eller slå upp ditt köp på <a href="https://paddle.net" rel="noopener">paddle.net</a>.</li>'
                . '<li><strong>Via oss</strong> – mejla ' . $f['contact'] . ' från kontots adress, eller från adressen på kvittot, så skickar vi begäran vidare till Paddle.</li>'
                . '</ul>'
                . '<p>Pengarna går tillbaka till det betalsätt du använde. Paddle hanterar dem vanligtvis inom några arbetsdagar; hur snart pengarna syns beror på din bank eller ditt kortföretag.</p>',
        ],
        [
            'id'      => 'after',
            'heading' => 'Vad som händer med ditt konto',
            'html'    => '<p>En återbetalning avslutar den Pro-tid som betalningen avsåg, och en återbetald prenumeration avbryts så att den inte förnyas. Ditt konto finns kvar, som ett gratis Regular-konto. Som när vilken Pro-plan som helst tar slut raderas Sticky-adresserna ' . $graceDays . ' dagar senare, tillsammans med deras mejl, så flytta det du vill spara innan dess.</p>'
                . '<p>Ett återbetalt Lifetime-köp avslutar Pro på återbetalningsdagen. Därefter gäller samma ' . $graceDays . ' dagar innan Sticky-adresserna raderas.</p>'
                . '<p>Pro-tid du hade före köpet – den gratis provperioden eller en värdekod – påverkas inte av återbetalningen.</p>'
                . '<p>Om en betalning som gjorts via en <a href="' . $msU('/terms.php#referrals') . '">inbjudan</a> återbetalas eller återkrävs innan den som bjöd in har fått sin belöning, vilket sker ' . $ref['hold_days'] . ' dagar efter betalningen, beviljas inte inbjudarens bonus, och vännens bonusmånader upphör tillsammans med den återbetalda prenumerationen. En återbetalning efter att inbjudaren har fått sin belöning tar inte tillbaka belöningen.</p>'
                . '<p>En återbetalning är inte en begäran om att radera dina uppgifter. Bortsett från att Pro upphör gäller de vanliga lagringsreglerna i vår <a href="' . $msU('/privacy.php#retention') . '">integritetspolicy</a> för ditt konto och dess uppgifter. Vill du radera dem, radera ditt konto.</p>',
        ],
        [
            'id'      => 'cancel',
            'heading' => 'Avsluta utan återbetalning',
            'html'    => '<p>Du kan när som helst avsluta en prenumeration med <strong>Manage billing</strong> i din profil. Att avsluta stoppar framtida förnyelser; du behåller Pro till slutet av den period du har betalat för. Om det fortfarande är högst ' . $guaranteeDays . ' dagar sedan senaste betalningen och du vill ha pengarna tillbaka också, be om återbetalning enligt ovan.</p>',
        ],
        [
            'id'      => 'after-30',
            'heading' => 'Efter ' . $guaranteeDays . ' dagar',
            'html'    => '<p>Efter ' . $guaranteeDays . ' dagar återbetalas betalningar normalt inte. Dina rättigheter enligt tvingande konsumentlagstiftning påverkas inte, och om något gick fel hos oss – en dubbeldragning, eller en tjänst som inte fungerade som utlovat – kontakta oss på ' . $f['contact'] . ' så rättar vi till det.</p>'
                . '<p>Om vi lägger ned Mail Shield eller avslutar ditt konto av någon annan anledning än att du brutit mot våra <a href="' . $msU('/terms.php') . '">användarvillkor</a>, återbetalar vi enligt vad som står där.</p>',
        ],
    ],
];
