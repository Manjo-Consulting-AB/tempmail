<?php
/**
 * The terms of service in Swedish: the whole document as data, rendered by
 * partials/legal_page.php. Mirrors terms.en.php: the same section ids in the
 * same order, the same conditional branches (trial on/off), the same facts as
 * variables and the same 'updated' date; only the words differ. It states no
 * clause on which language prevails (epic #441 decision 2). A DRAFT for legal
 * review (#446 A). Glossary: documentaion/I18N.md section 5.
 *
 * Section "payments" names Paddle as reseller and Merchant of Record, as the
 * English does.
 *
 * Variables terms.php defines for this file:
 *   $f     array    the legal facts (partials/legal_facts.php), already escaped
 *   $ref   array    the invite programme's settings (referralSettings())
 *   $msU   closure  a public page path -> its escaped, language-aware URL
 *
 * 'html' holds trusted markup authored here with every interpolated value
 * escaped, never anything a visitor supplies. Never write a number into the
 * text: it comes from $f, $ref or the named constants below.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// Numbers the code or these terms fix rather than $config: the Pro timed
// address range (index.php clamps address_ttl_days to 1-7), the grace before a
// lapsed Pro account's Sticky addresses go (cron/cleanup.php), the money-back
// window, the notice period, the length of the plan the invite programme
// rewards and the look-back of the liability cap. Named here so none is typed
// into the prose.
$proTtlMinDays = 1;
$proTtlMaxDays = 7;
$graceDays = 7;
$guaranteeDays = 30;
$noticeDays = 30;
$yearPlanMonths = 12;
$liabilityMonths = 12;

$trialLine = $f['trial_days'] > 0
    ? '<p>Varje nytt konto börjar med en gratis Pro-provperiod på ' . $f['trial_days'] . ' dagar, en gång per e-postadress. Inga betalningsuppgifter behövs för provperioden, och den går inte över i en betald plan av sig själv: när den tar slut fortsätter kontot som ett gratis Regular-konto om du inte köper Pro.</p>'
    : '';

return [
    'title'       => 'Användarvillkor',
    'lede'        => 'Dessa villkor är avtalet mellan dig och oss när du använder Mail Shield. De är skrivna för att läsas, så de är korta där de kan vara det.',
    'description' => 'Villkoren som gäller när du använder Mail Shield, inklusive Pro-planer som säljs via Paddle som Merchant of Record.',
    'path'        => '/terms.php',
    'updated'     => '2026-10-10',
    'sections'    => [
        [
            'id'      => 'who',
            'heading' => 'Vilka vi är',
            'html'    => '<p>Mail Shield drivs av ' . $f['company_full'] . ' (”vi”, ”oss”). Du når oss på ' . $f['contact'] . '.</p>'
                . '<p>Genom att skapa ett konto eller använda tjänsten godkänner du dessa villkor. Om du inte godkänner dem ber vi dig att inte använda Mail Shield.</p>',
        ],
        [
            'id'      => 'service',
            'heading' => 'Tjänsten',
            'html'    => '<p>Mail Shield är en sekundär e-postmiljö på domänen ' . $f['domain'] . '. Den tar emot e-post åt dig; den är inte en tjänst för att skicka e-post. Beroende på din plan kan du:</p>'
                . '<ul>'
                . '<li>skapa Timed-adresser som tar emot mejl i ' . $f['free_hours'] . ' timmar på ett gratis konto, eller i ' . $proTtlMinDays . ' till ' . $proTtlMaxDays . ' dagar med Pro;</li>'
                . '<li>ha upp till 10 Sticky-adresser (endast Pro);</li>'
                . '<li>vidarebefordra det som kommer in till andra system – RSS, webhooks, Pushover och sammanfattningsmejl (endast Pro).</li>'
                . '</ul>'
                . '<p>Ett gratis konto har en lagringskvot på ' . $f['quota_free_mb'] . ' MB och ett Pro-konto en på ' . $f['quota_pro_mb'] . ' MB; Pro-kvoten gäller från första dagen av provperioden, eftersom ett provkonto är ett Pro-konto. Mejl till en adress som inte är kopplad till ett konto har egna ' . $f['quota_free_mb'] . ' MB. När en kvot är full raderas de äldsta meddelandena i den automatiskt för att ge plats åt nya, även om adressen de kom till inte har löpt ut än.</p>'
                . '<p>Den kvot som räknas är den som gäller när ett meddelande kommer in. Om en Pro-plan tar slut och kontot då ligger över den gratis kvoten, trimmas det ned till den – äldsta meddelandena först – i takt med att ny post kommer.</p>'
                . '<p>Adresser och integrationer har användningsgränser som skyddar dem och tjänsten. När en gräns nås pausas adressen eller integrationen automatiskt en stund och du får besked; se <a href="' . $msU('/privacy.php#automated-limits') . '">Automatiska gränser</a>.</p>'
                . '<p>Mejl raderas automatiskt när en adress löper ut eller tas bort. Mail Shield är inte ett arkiv: spara en kopia någon annanstans av sådant du behöver behålla.</p>',
        ],
        [
            'id'      => 'account',
            'heading' => 'Ditt konto',
            'html'    => '<p>Du behöver ett konto för att använda Mail Shield. Du måste ange en riktig e-postadress som du kontrollerar, hålla ditt lösenord för dig själv och berätta för oss om du tror att någon annan har tillgång till ditt konto. Du ansvarar för vad som sker med ditt konto.</p>'
                . '<p>Du måste vara minst 16 år, eller ha uppnått åldern för digitalt samtycke där du bor om den är högre.</p>'
                . '<p>Ett gratis Regular-konto som ingen har loggat in på under ' . $f['delete_days'] . ' dagar raderas, tillsammans med dess adresser och mejl. Vi mejlar en varning ' . ($f['delete_days'] - $f['warn_days']) . ' dagar innan det sker. Pro-konton raderas inte på grund av inaktivitet.</p>'
                . '<p>Du kan radera ditt konto när som helst från din profil. Raderingen tar bort dina adresser och ditt mejl.</p>',
        ],
        [
            'id'      => 'acceptable-use',
            'heading' => 'Tillåten användning',
            'html'    => '<p>Du får inte använda Mail Shield för att:</p>'
                . '<ul>'
                . '<li>bryta mot lagen, eller hjälpa någon annan att göra det;</li>'
                . '<li>begå bedrägeri, utge dig för att vara någon annan, eller kringgå avstängningar, begränsningar eller verifiering på sätt som skadar den tjänst du använder adressen hos;</li>'
                . '<li>använda en adress för att samla in lösenord, betaluppgifter eller andra uppgifter som erhållits genom nätfiske, skadlig kod eller annat svek, eller för att ta emot resultatet av sådana upplägg;</li>'
                . '<li>skapa adresser eller konton i bulk för att bedriva spam, bedrägeri eller verksamhet med falska konton någon annanstans;</li>'
                . '<li>ta emot material som det är olagligt att inneha, däribland material med sexuella övergrepp mot barn;</li>'
                . '<li>angripa, sondera eller överbelasta Mail Shield eller dess infrastruktur, eller gå runt dess gränser;</li>'
                . '<li>sälja vidare eller ge åtkomst till tjänsten som din egen produkt utan vårt skriftliga tillstånd.</li>'
                . '</ul>'
                . '<p>Vi får stänga av eller avsluta ett konto som bryter mot dessa regler och ta bort innehåll som hör till det. Där lagen kräver det samarbetar vi med myndigheterna.</p>',
        ],
        [
            'id'      => 'suitability',
            'heading' => 'Vad Mail Shield inte är till för',
            'html'    => '<p>Använd inte en Timed-adress till något du behöver behålla eller kunna återfå – bank, myndighetstjänster eller ett konto vars lösenordsåterställning skulle skickas dit. När adressen har löpt ut försvinner också din åtkomst till mejlen.</p>'
                . '<p>En Timed-adress som nås via en delad länk kan läsas av alla som har länken. Se den som en plats att ta emot mejl på, inte en plats att förvara en hemlighet på.</p>'
                . '<p>Vi kan inte garantera att varje meddelande som skickas till dig kommer fram. Avsändare kan fördröja, avvisa eller filtrera mejl innan de når oss.</p>',
        ],
        [
            'id'      => 'plans',
            'heading' => 'Planer, provperiod och värdekoder',
            'html'    => '<p>Mail Shield har en gratis Regular-plan och en betald Pro-plan. Pro kan köpas som månads- eller årsprenumeration, eller en gång som Lifetime. Pro-funktionerna är de som beskrivs på <a href="' . $msU('/pricing.php') . '">prissidan</a> när du köper.</p>'
                . $trialLine
                . '<p>Pro-tid kan också ges med en värdekod. En värdekod har inget kontantvärde och kan inte bytas mot pengar.</p>'
                . '<p>”Lifetime” betyder så länge vi driver Mail Shield, inte under din eller vår livstid. Om vi någon gång lägger ned tjänsten berättar vi det i förväg, som det beskrivs under <a href="#termination">Avslut av tjänsten</a>.</p>'
                . '<p>När Pro tar slut blir kontot ett gratis Regular-konto. Dess Sticky-adresser raderas ' . $graceDays . ' dagar senare, tillsammans med deras mejl, och dess integrationer slutar fungera.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Betalningar och Paddle',
            'html'    => '<p>Vår orderhantering sköts av vår återförsäljare Paddle.com. Paddle.com är Merchant of Record för alla våra beställningar. Paddle tar hand om alla kundtjänstärenden och hanterar returer.</p>'
                . '<p>Det betyder att själva köpet är ett avtal mellan dig och Paddle, på <a href="https://www.paddle.com/legal/checkout-buyer-terms" rel="noopener">Paddles köpvillkor</a>. Paddle tar emot betalningen, debiterar och betalar den moms eller försäljningsskatt som gäller där du befinner dig, och skickar kvittot till dig. Priserna visas i din lokala valuta, inklusive den skatten.</p>'
                . '<h3>Prenumerationer</h3>'
                . '<p>En månads- eller årsprenumeration förnyas automatiskt i slutet av varje period, till det pris som då gäller, tills du avslutar den. Du kan avsluta när som helst med <strong>Manage billing</strong> i din profil eller via Paddle. Efter avslutet behåller du Pro till slutet av den period du har betalat för; därefter görs ingen debitering.</p>'
                . '<p>Om vi ändrar priset på en prenumeration meddelar vi dig per e-post innan ändringen börjar gälla för din nästa förnyelse, så att du hinner avsluta först.</p>'
                . '<h3>Återbetalningar</h3>'
                . '<p>Du kan få pengarna tillbaka inom ' . $guaranteeDays . ' dagar efter en betalning. Detaljerna finns i vår <a href="' . $msU('/refund-policy.php') . '">återbetalningspolicy</a>.</p>',
        ],
        [
            'id'      => 'referrals',
            'heading' => 'Inbjudningar',
            'html'    => '<p>Så länge inbjudningsprogrammet är öppet har varje konto en personlig inbjudningslänk, som visas i din profil. Reglerna är:</p>'
                . '<ul>'
                . '<li>En vän som skapar ett <strong>nytt</strong> konto via din länk, och vars e-postadress aldrig tidigare har använts med Mail Shield, kopplas till dig. Kopplingen görs en gång, när kontot först verifieras, och kan inte ändras efteråt.</li>'
                . '<li>Om vännen köper en Pro-plan på ' . $yearPlanMonths . ' månader inom ' . $ref['window_days'] . ' dagar från att kontot skapades får vännen ' . $ref['bonus_months'] . ' extra månader: vännens nästa betalning flyttas ' . $ref['bonus_months'] . ' månader framåt. Om vännens prenumeration i stället är inställd på att upphöra läggs månaderna till vännens Pro-tid.</li>'
                . '<li>Du får ' . $ref['bonus_months'] . ' månaders Pro ' . $ref['hold_days'] . ' dagar efter vännens betalning, under förutsättning att den inte har återbetalats eller återkrävts då. Om du betalar med prenumeration flyttas din nästa betalning ' . $ref['bonus_months'] . ' månader framåt. I annat fall läggs månaderna till ditt nuvarande slutdatum, eller till dagens datum om det har passerat. Om du har Lifetime får du i stället ' . $ref['sticky_bonus'] . ' Sticky-adresser till, utöver den vanliga gränsen, så länge kontot är Pro.</li>'
                . '<li>Ett Lifetime-köp av vännen räknas också för dig, men ger vännen inget extra.</li>'
                . '<li>Antalet inbjudningar är inte begränsat, och belöningar läggs ihop. Belöningar har inget kontantvärde och kan inte överlåtas.</li>'
                . '<li>Det är inte tillåtet att bjuda in sig själv, eller att använda flera konton eller betaluppgifter för att samla belöningar. Vi får innehålla eller ta bort belöningar som erhållits på det sättet.</li>'
                . '<li>Vi får ändra eller avsluta programmet. Belöningar som redan har beviljats behålls.</li>'
                . '</ul>'
                . '<p>Vad som händer med en belöning när en betalning återbetalas står i <a href="' . $msU('/refund-policy.php#after') . '">återbetalningspolicyn</a>; vad vi lagrar om en inbjudan står i <a href="' . $msU('/privacy.php#data') . '">integritetspolicyn</a>.</p>',
        ],
        [
            'id'      => 'termination',
            'heading' => 'Avslut av tjänsten',
            'html'    => '<p>Du kan när som helst sluta använda Mail Shield och radera ditt konto. Att avsluta en prenumeration är ett separat steg, som görs med Manage billing; att radera kontot avslutar den inte av sig självt.</p>'
                . '<p>Vi kan avsluta eller begränsa din användning av Mail Shield på tre olika sätt:</p>'
                . '<ul>'
                . '<li><strong>Omedelbar avstängning.</strong> Om vi ser missbruk, eller en risk för säkerheten hos Mail Shield eller dess användare, får vi stänga av ett konto direkt och utan förvarning medan vi utreder saken.</li>'
                . '<li><strong>Avslut efter ett brott mot villkoren.</strong> Om du bryter mot dessa villkor får vi avsluta ditt konto.</li>'
                . '<li><strong>Vårt eget beslut.</strong> Om vi avslutar ditt konto av något annat skäl, eller lägger ned Mail Shield helt, ger vi minst ' . $noticeDays . ' dagars varsel per e-post och återbetalar den oanvända delen av varje prenumerationsperiod du har betalat för. För ett Lifetime-köp som gjorts mindre än två år innan tjänsten läggs ned återbetalar vi en andel av priset i proportion till den del av de två åren som återstår.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'transfer',
            'heading' => 'Överlåtelse av tjänsten',
            'html'    => '<p>Vi får överlåta Mail Shield och dessa villkor till en annan aktör som tar över tjänsten. Vi meddelar dig per e-post innan det sker. Dina rättigheter, Lifetime inräknat, fortsätter att gälla mot den nya aktören, och om du inte godkänner överlåtelsen kan du radera ditt konto.</p>',
        ],
        [
            'id'      => 'liability',
            'heading' => 'Garanti och ansvar',
            'html'    => '<p>Vi arbetar för att hålla Mail Shield tillgängligt och din post säker, men tjänsten tillhandahålls i befintligt skick, och vi lovar inte att den är oavbruten eller felfri.</p>'
                . '<p>I den utsträckning lagen tillåter ansvarar vi inte för indirekta skador eller följdskador, såsom utebliven vinst, förlorade data eller förlorad åtkomst till ett konto någon annanstans, och vårt sammanlagda ansvar mot dig är begränsat till vad du har betalat för Mail Shield under de ' . $liabilityMonths . ' månaderna innan kravet uppstod.</p>'
                . '<p>Ingenting i dessa villkor begränsar rättigheter du har som konsument enligt tvingande lag, eller vårt ansvar för grov oaktsamhet eller uppsåt.</p>',
        ],
        [
            'id'      => 'privacy',
            'heading' => 'Integritet',
            'html'    => '<p>Hur vi hanterar personuppgifter, inklusive den post du tar emot, beskrivs i vår <a href="' . $msU('/privacy.php') . '">integritetspolicy</a>.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Ändringar av dessa villkor',
            'html'    => '<p>Vi får uppdatera dessa villkor. Datumet högst upp visar när de senast ändrades. Om en ändring väsentligt påverkar dina rättigheter mejlar vi dig minst ' . $noticeDays . ' dagar innan den träder i kraft. Om du fortsätter att använda Mail Shield efter det gäller de nya villkoren; om du inte godkänner dem kan du före dess radera ditt konto och avsluta din prenumeration.</p>',
        ],
        [
            'id'      => 'law',
            'heading' => 'Tillämplig lag och tvister',
            'html'    => '<p>Dessa villkor regleras av svensk rätt. Tvister avgörs av svenska domstolar, med Stockholms tingsrätt som första instans, om inte tvingande konsumentlagstiftning ger dig rätt att väcka talan där du bor.</p>'
                . '<p>Om du är konsument i EU kan du också vända dig till Allmänna reklamationsnämnden (<a href="https://www.arn.se" rel="noopener">arn.se</a>). Kontakta oss gärna först på ' . $f['contact'] . ' – de flesta saker går snabbare att reda ut direkt.</p>',
        ],
    ],
];
