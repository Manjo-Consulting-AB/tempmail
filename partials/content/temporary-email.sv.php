<?php
/**
 * Sidan om tillfälliga e-postadresser på svenska. Skriven kring de svenska
 * sökorden (primärt: tillfällig e-postadress) men säger exakt samma sak som
 * temporary-email.en.php: samma sektions-id i samma ordning, samma villkorsgren
 * för provperioden och samma variabler. Inget i texten får påstå mer än den
 * engelska sidan (brief §9). Siffror och domänen skrivs aldrig in här utan
 * kommer från variablerna, som definieras av temporary-email.php och listas i
 * docblocket i temporary-email.en.php. Ordlistan: Sticky och Timed behålls som
 * produktnamn, tilltal med "du".
 *
 * Sektions-id:n är engelska i alla språk så att #-ankarna fungerar likadant.
 *
 * @return array{title: string, description: string, headline: string, lede: string, toc_label: string, toc_eyebrow: string, sections: list<array{id: string, heading: string, html: string}>, cta: array{after: string, line: string, end_line: string}}
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

return [
    'title'       => 'Tillfällig e-postadress – gratis engångsmejl · Mail Shield',
    'description' => 'En tillfällig e-postadress för registreringar, nedladdningar och tjänster du bara behöver en stund. Läs mejlen i din Mail Shield-inkorg och låt dem städas bort automatiskt.',
    'headline'    => 'Tillfällig e-postadress',
    'lede'        => 'Alla registreringar är inte värda din riktiga adress. En tillfällig e-postadress ger dig en engångsadress i stället – läs den i en riktig webbinkorg och låt Mail Shield städa åt dig.',
    'toc_label'   => 'På den här sidan',
    'toc_eyebrow' => 'På den här sidan',
    'sections'    => [
        [
            'id'      => 'what',
            'heading' => 'Vad är en tillfällig e-postadress?',
            'html'    => <<<HTML
<p>En tillfällig e-postadress finns en kort tid och upphör sedan att finnas. Du använder den överallt där du helst inte lämnar ut den adress du faktiskt läser – en gratis provperiod, en nedladdning, ett forum – och allt som skickas till den hamnar i en webbinkorg som du kan öppna direkt, så att du kan ta emot e-post online.</p>
<p>Ett annat ord för det är engångsmejl. Adressen är till för att kastas, så den behöver aldrig vara minnesvärd eller snygg. Det är poängen: din huvudinkorg behåller det som är viktigt, och resten hamnar någonstans du inte behöver sköta.</p>
<p>I Mail Shield kallas den en <strong>Timed-adress</strong>: en adress som går på en timer och raderar sig själv, med allt i den, när tiden är slut.</p>
HTML,
        ],
        [
            'id'      => 'get',
            'heading' => 'Så skaffar du en tillfällig e-postadress',
            'html'    => <<<HTML
<p>Ingen knapp på den här sidan skriver ut en adress åt dig, och det är avsiktligt – en Mail Shield-inkorg behöver ett konto bakom sig.</p>
<ol>
    <li>Registrera dig med din egen e-postadress och ett lösenord. Den används för att verifiera kontot och för att nå dig om det, inget annat.</li>
    <li>Verifiera den och logga sedan in.</li>
    <li>Skapa en Timed-adress från din inkorg. Den är redo att klistras in där du registrerar dig.</li>
</ol>
<p>Den extra minuten ger dig något som en engångsgenerator inte kan erbjuda. Adressen finns i en riktig inkorg som sparas, så du kan komma tillbaka för en bekräftelse som kommer en timme senare, eller hitta meddelandet som förklarar hur du säger upp dig. En sida som skriver ut en adress och glömmer bort dig när du stänger fliken klarar ingetdera.</p>
HTML,
        ],
        [
            'id'      => 'lifetime',
            'heading' => 'Hur länge finns en tillfällig adress kvar?',
            'html'    => <<<HTML
<p>Det beror på ditt konto, och båda siffrorna är exakta.</p>

<dl class="ms-doc__retention">
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Gratis</dt>
        <dd class="ms-doc__tier-value">{$msDocFreeHours} timmar</dd>
    </div>
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Pro</dt>
        <dd class="ms-doc__tier-value">{$msDocProMinDays}&ndash;{$msDocProMaxDays} dagar</dd>
    </div>
</dl>

<p>Ett gratiskonto behåller en Timed-adress i {$msDocFreeHours} timmar. Med Pro väljer du en livslängd på {$msDocProMinDays} till {$msDocProMaxDays} dagar för varje adress. När tiden är slut raderas adressen och allt i den automatiskt.</p>
HTML,
        ],
        [
            'id'      => 'when-to-use',
            'heading' => 'När passar temporär e-post?',
            'html'    => <<<HTML
<p>Temporär e-post passar bäst när adressen är det enda du är osäker på.</p>
<ul>
    <li>Gratis provperioder för verktyg du kanske aldrig öppnar igen.</li>
    <li>En enstaka nedladdning som kräver en adress innan den startar.</li>
    <li>Registreringar på forum och i communityn.</li>
    <li>Annonser på marknadsplatser och i annonssajter, där du helst inte publicerar den adress du faktiskt använder.</li>
    <li>Att testa dina egna registrerings- och bekräftelsemejl.</li>
</ul>
HTML,
        ],
        [
            'id'      => 'when-not-to-use',
            'heading' => 'När du inte ska använda en',
            'html'    => <<<HTML
<p>Det här är fel verktyg för allt som är viktigt.</p>
<ul>
    <li>Bank, myndighetstjänster eller allt som är kopplat till din identitet.</li>
    <li>Konton du skulle behöva återställa – när adressen är borta är lösenordsåterställningen det också.</li>
    <li>Allt du tänker behålla.</li>
</ul>
<p>En sak som är värd att säga rakt ut: en tillfällig inkorg som du når via en delad länk är inte privat. Skickar du länken vidare kan den som får den läsa mejlen. Mail Shield berättar hellre det än låter dig anta något annat, så se en Timed-adress som en plats att ta emot på, inte en plats att gömma en hemlighet.</p>
HTML,
        ],
        [
            'id'      => 'permanent',
            'heading' => 'Tillfällig e-post eller en Sticky-adress?',
            'html'    => <<<HTML
<p>Timed-adresser är bara ena halvan. Mail Shield ger också Pro-konton upp till {$msDocStickyMax} Sticky-adresser – <span class="ms-addr">shopping@{$msDocDomain}</span>, <span class="ms-addr">news@{$msDocDomain}</span>, <span class="ms-addr">projects@{$msDocDomain}</span> – för avsändare du kommer att höra från igen. Adresserna går inte ut. Du väljer själv när du tar bort dem, medan mejlen de tar emot fortfarande städas bort enligt dina inställningar för lagringstid.</p>
<p>Vill du ha en engångsadress som du aldrig behöver tänka på igen är en Timed-adress rätt val. Om du har lämnat ut din riktiga adress för att det inte fanns någon annan plats för ett nyhetsbrev är en Sticky-adress den bättre lösningen. <a href="{$msU('/')}">Startsidan visar hela bilden</a>.</p>
HTML,
        ],
    ],
    'cta' => [
        'after'    => 'lifetime',
        'line'     => $msDocTrialDays > 0
            ? 'Det tar ungefär en minut, och dina första ' . $msDocTrialDays . ' dagar ingår Pro, gratis.'
            : 'Det tar ungefär en minut, och det är gratis.',
        'end_line' => 'Din första Timed-adress är en minut bort.',
    ],
];
