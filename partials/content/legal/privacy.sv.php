<?php
/**
 * The privacy policy in Swedish: the whole document as data, rendered by
 * partials/legal_page.php. Mirrors privacy.en.php: the same section ids in the
 * same order, the same conditional branches, the same facts as variables and
 * the same 'updated' date; only the words differ. It states no clause on which
 * language prevails (epic #441 decision 2). Glossary: documentaion/I18N.md
 * section 5.
 *
 * Variables privacy.php defines for this file:
 *   $f     array    the legal facts (partials/legal_facts.php), already escaped
 *   $ref   array    the invite programme's settings (referralSettings())
 * and the fixed numbers listed below the guard itself.
 *
 * 'html' holds trusted markup authored here with every interpolated value
 * escaped, never anything a visitor supplies. Never write a number into the
 * text: it comes from $f, $ref or the named values below.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// Numbers the code fixes rather than $config; the same names as in
// privacy.en.php, so no number is typed into the prose.
$ttlMinDays = 1;
$ttlMaxDays = 7;
$graceDays = 7;
$trustedDays = 30;
$maxSignedInDays = 90;
$stayDays = [1, 7, 30];
$tokenSweepDays = 30;
$pushoverChars = "4\u{00A0}000";
$minAge = 16;
$abuseEventDays = 90;

return [
    'title'       => 'Integritetspolicy',
    'lede'        => 'Mail Shield finns för att hålla din primära adress borta från ställen där den inte behöver vara. Den här sidan beskriver vad vi samlar in för att göra det, varför, hur länge vi sparar det och vilka andra som får se det.',
    'description' => 'Vilka personuppgifter Mail Shield samlar in, varför, hur länge de sparas och vem som behandlar dem – inklusive betalningar som hanteras av Paddle.',
    'path'        => '/privacy.php',
    'updated'     => '2026-10-11',
    'sections'    => [
        [
            'id'      => 'controller',
            'heading' => 'Vem som ansvarar',
            'html'    => '<p>Personuppgiftsansvarig är ' . $f['company_full'] . ', som driver Mail Shield. Frågor om dina uppgifter, och begäranden om att utöva de rättigheter som beskrivs nedan, skickas till ' . $f['contact'] . '.</p>'
                . '<p>Vid köp är Paddle Merchant of Record och personuppgiftsansvarig för de betalningsuppgifter som Paddle samlar in; se <a href="#payments">Betalningar</a>.</p>',
        ],
        [
            'id'      => 'data',
            'heading' => 'Vad vi samlar in',
            'html'    => '<h3>Ditt konto</h3>'
                . '<p>E-postadressen du registrerar dig med och en hash av ditt lösenord – aldrig själva lösenordet. Om du slår på tvåfaktorsautentisering lagrar vi dess hemlighet krypterad, och en förteckning över de webbläsare du väljer att lita på. Vi registrerar när kontot skapades och när du senast loggade in, samt dina inställningar: plan, adressernas livslängd, sammanfattningsmejl och integrationer. Om du väljer ett språk, eller registrerar dig eller loggar in på en sida på ett annat språk, sparar kontot den språkinställningen.</p>'
                . '<p>Din registrerade e-postadress lagras krypterad och slås upp via en nyckelad hash i stället för i klartext.</p>'
                . '<h3>Mejlen du tar emot</h3>'
                . '<p>Meddelanden som skickas till dina Mail Shield-adresser – rubriker, innehåll och bilagor – lagras så att du kan läsa dem. Personer på Mail Shield tittar inte på dina mejl som ett led i att driva tjänsten, och de används aldrig för reklam eller profilering. De behandlas automatiskt för att lagras, visas för dig och skickas vidare till de integrationer du har ställt in. Vi tar bara del av ett enskilt meddelande när det behövs för att utreda missbruk eller en säkerhetsincident, för att hjälpa dig när du ber oss om det, eller när lagen kräver det.</p>'
                . '<h3>Anslutna appar</h3>'
                . '<p>Du kan ansluta en app eller en AI-assistent till ditt konto: antingen genom att logga in och godkänna appen när den ber om det, eller genom att skapa en åtkomsttoken och ge den till appen. I båda fallen lagrar vi bara en enkelriktad hash av inloggningsuppgiften – aldrig själva token – tillsammans med den åtkomst du gav (endast läsa, eller läsa och ändra) och när den användes senast. En token du skapat själv har också det namn du gav den och den utgångstid du valde.</p>'
                . '<p>För en app som du godkänner på inloggningsskärmen sparar vi det namn appen gav sig själv, värden för den adress den bad att skickas tillbaka till och när du anslöt den. Appen lämnar de uppgifterna själv när den registrerar sig; det är dem kortet Anslutna appar visar för dig, så att du kan skilja appar åt och koppla bort dem.</p>'
                . '<h3>Inbjudningar</h3>'
                . '<p>När du registrerar dig via en inbjudningslänk sparar vi vilket konto som bjöd in dig och inbjudans status, med datum och, när du köper Pro, den plan och den Paddle-prenumeration eller -transaktion som det gäller. Inbjudningskoden sparas i en cookie och på ditt nya konto tills du har verifierat din adress. Den som bjöd in dig ser bara antal, aldrig vem du är. Uppgiften innehåller kontonummer, inte e-postadresser, och finns kvar när något av kontona raderas. Varje konto har också en egen inbjudningskod.</p>'
                . '<h3>Tekniska uppgifter</h3>'
                . '<p>Våra serverloggar registrerar händelser som inloggningar, fel och misstänkta anrop, med den IP-adress som berörs. Inloggningsförsök registreras för att stoppa gissning av lösenord. Vi skriver inte din registrerade e-postadress i loggarna. På de inloggade sidorna och i inkorgen läses webbläsarens språkinställning för att välja visningsspråk; den sparas inte.</p>'
                . '<p>För att skydda adresser mot översvämning av mejl och tjänsten mot missbruk räknar vi hur många meddelanden, och hur många byte, varje adress tar emot, hur många adresser som skapas från varje IP-adress och konto, och hur många aviseringar varje integration skickar. När en gräns nås pausar vi adressen eller integrationen en stund och berättar det för dig, och vi sparar en post om det steget.</p>'
                . '<h3>Provperiodspost</h3>'
                . '<p>För att varje e-postadress bara ska få den kostnadsfria Pro-provperioden en gång sparar vi en nyckelad hash av den normaliserade adressen. Den är inte kopplad till ditt konto och kan inte göras om till adressen.</p>'
                . '<h3>Betalningar</h3>'
                . '<p>När du köper Pro talar Paddle om för oss ditt kund-ID, den e-postadress du betalade med samt status för din prenumeration och dina transaktioner, så att vi kan ge ditt konto Pro. Vi tar aldrig emot eller lagrar dina kortuppgifter.</p>'
                . '<h3>Support</h3>'
                . '<p>Om du kontaktar oss sparar vi konversationen så att vi kan svara dig.</p>',
        ],
        [
            'id'      => 'purposes',
            'heading' => 'Varför, och med vilken rättslig grund',
            'html'    => '<ul>'
                . '<li><strong>Att driva tjänsten du registrerade dig för</strong> – ditt konto, dina adresser, dina mejl, dina integrationer och din plan. Rättslig grund: fullgörande av vårt avtal med dig (GDPR artikel 6.1 b).</li>'
                . '<li><strong>Att hålla tjänsten säker</strong> – loggar, inloggningsgränser, upptäckt av missbruk och provperiodsposten. Rättslig grund: vårt berättigade intresse av att skydda Mail Shield och dess användare mot missbruk (artikel 6.1 f).</li>'
                . '<li><strong>Bokföring</strong> – betalningsunderlag. Rättslig grund: vår rättsliga förpliktelse enligt svensk bokföringslag (artikel 6.1 c).</li>'
                . '<li><strong>Att förstå hur de publika sidorna används</strong> – Google Analytics, bara efter att du godkänt det i cookie-meddelandet. Rättslig grund: ditt samtycke (GDPR artikel 6.1 a), som vi frågar efter innan någon analyscookie sätts; att tacka nej ändrar inget annat på webbplatsen.</li>'
                . '<li><strong>Att informera dig om ditt konto</strong> – verifiering, inloggningslänkar, säkerhetsmeddelanden, inaktivitetsvarningar och ändringar i våra villkor. Det är servicemeddelanden, inte marknadsföring; vi skickar inga nyhetsbrev.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'retention',
            'heading' => 'Hur länge vi sparar det',
            'html'    => '<ul>'
                . '<li><strong>Timed-adresser och deras mejl</strong> – ' . $f['free_hours'] . ' timmar på ett gratiskonto, eller de ' . $ttlMinDays . ' till ' . $ttlMaxDays . ' dagar du väljer på Pro. Därefter raderas de automatiskt.</li>'
                . '<li><strong>Sticky-adresser</strong> – tills du tar bort dem, eller ' . $graceDays . ' dagar efter att din Pro-plan tar slut. Deras mejl raderas tillsammans med dem.</li>'
                . '<li><strong>Mejl du väljer att spara längre</strong> – ett Pro-konto kan pausa raderingen av mejlen på sina Sticky-adresser: upp till ' . $f['hold_max'] . ' gånger per år förlänger en paus de mejlen till ' . $f['hold_days'] . ' dagar från den dag de kom. Avslutas en paus i förtid ligger de mejl som redan förlängts kvar, och en Timed-adress går alltid ut enligt sitt eget schema. Lagringsgränsen nedan gäller fortfarande.</li>'
                . '<li><strong>Alla mejl, innan de annars skulle gå ut</strong> – en lagringskvot gäller för ditt konto (eller för varje adress, om den inte är kopplad till ett konto): ' . $f['quota_free_mb'] . ' MB på ett gratiskonto och ' . $f['quota_pro_mb'] . ' MB på Pro, där Pro-värdet gäller även under provperioden. När kvoten är full raderas de äldsta mejlen i den omfattningen för att ge plats, oavsett vilken adress de ligger på. Kvoten läses av när ett meddelande kommer in, så ett konto som går ner till gratis (när en plan eller provperiod tar slut) trimmas, äldsta först, allteftersom nya mejl kommer.</li>'
                . '<li><strong>Borttagna Sticky-adresser</strong> – själva adressen (inte dess mejl) sparas på en reserverad lista i ' . $f['cooldown_months'] . ' månader efter att den tagits bort, kopplad till ditt kontonummer, så att ingen annan kan ta över den och ta emot mejl som var avsedda för dig. Det gäller även efter att ditt konto raderats.</li>'
                . '<li><strong>Ditt konto</strong> – tills du raderar det. Ett kostnadsfritt Regular-konto som ingen loggar in på under ' . $f['delete_days'] . ' dagar raderas, efter en varning via e-post.</li>'
                . '<li><strong>Serverloggar</strong> – ' . $f['log_days'] . ' dagar.</li>'
                . '<li><strong>Missbruksräknare</strong> (meddelanden per adress, nya adresser per IP-adress och konto) – två dagar. Posten över pauser, varningar och andra skyddssteg – ' . $abuseEventDays . ' dagar.</li>'
                . '<li><strong>Betrodda webbläsare för tvåfaktorsautentisering</strong> – ' . $trustedDays . ' dagar, eller tills du tar bort dem.</li>'
                . '<li><strong>Enheter du förblir inloggad på</strong> (ett webbläsar- och operativsystemnamn, och när enheten användes senast) – den period du valde, räknad från senaste besöket och aldrig mer än ' . $maxSignedInDays . ' dagar efter att du loggade in, eller tills du tar bort enheten, loggar ut på den eller byter lösenord eller e-postadress.</li>'
                . '<li><strong>Åtkomsttokens du skapat själv</strong> – tills du återkallar dem, eller tills den utgångstid du valde. En återkallad eller utgången token raderas ' . $tokenSweepDays . ' dagar senare. Om du byter lösenord eller e-postadress, eller raderar ditt konto, återkallas alla på en gång.</li>'
                . '<li><strong>Appar du anslutit genom att logga in</strong> – tills du kopplar bort dem, eller ' . $f['grant_idle_days'] . ' dagar efter att appen senast använde sin åtkomst, vilket som inträffar först. En inloggning du påbörjade men aldrig godkände förfaller efter ' . $f['code_seconds'] . ' sekunder, och en app som registrerat sig men aldrig använts tas bort efter ' . $f['client_idle_days'] . ' dagar. Att koppla bort en app, byta lösenord eller e-postadress eller radera ditt konto avslutar dessa direkt.</li>'
                . '<li><strong>Provperiodspost</strong> – fem år från första gången adressen användes.</li>'
                . '<li><strong>Betalningsunderlag</strong> – sju år, vilket svensk bokföringslag kräver.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'sharing',
            'heading' => 'Vilka andra som behandlar dina uppgifter',
            'html'    => '<p>Vi säljer inte dina uppgifter och delar dem inte med annonsörer. De behandlas av dessa parter, och bara av de skäl som anges:</p>'
                . '<ul>'
                . '<li><strong>Vår hostingleverantör, ' . $f['hosting'] . '</strong>, som driver de servrar som tar emot, lagrar och levererar dina mejl, som vårt personuppgiftsbiträde enligt ett personuppgiftsbiträdesavtal. Ditt konto och dina mejl lagras i Sverige.</li>'
                . '<li><strong>Paddle</strong> (Paddle.com Market Ltd), för betalningar – se nedan.</li>'
                . '<li><strong>Google</strong> (Google Ireland Ltd), för Google Analytics på våra publika sidor, om du godkänner analys.</li>'
                . '<li><strong>De mottagare du väljer</strong>, när du ställer in en integration:'
                . '<ul>'
                . '<li><strong>Webhook:</strong> avsändaren, mottagaradressen, ämnet, hela meddelandetexten och tidpunkten då det kom. Bilagor skickas inte, bara hur många det är.</li>'
                . '<li><strong>Pushover:</strong> ämnet och ett utdrag i klartext ur texten (upp till cirka ' . $pushoverChars . ' tecken), med mottagaradressen som rubrik, skickat till Pushover och vidare till dina enheter.</li>'
                . '<li><strong>RSS-flöde:</strong> avsändaren, ämnet, datumet och hela meddelandetexten, med länkar till bilagorna, tillgängligt för alla som har flödeslänken.</li>'
                . '<li><strong>En app eller AI-assistent du ansluter</strong> – oavsett om du godkände den på inloggningsskärmen eller gav den en åtkomsttoken: dina adresser och de mejl den ber om – avsändare, ämne, text och bilagelänkar. Till skillnad från integrationerna ovan hämtar appen det själv, och vad den gör med det avgör du tillsammans med den som driver den.</li>'
                . '</ul>'
                . 'De tjänsterna väljer du själv och de hanterar uppgifterna enligt sina egna villkor. En AI-assistent du ansluter är inget undantag: dess leverantör svarar inför dig, inte inför oss, och är inte vårt personuppgiftsbiträde.</li>'
                . '<li><strong>Myndigheter</strong>, när lagen kräver att vi lämnar ut uppgifter.</li>'
                . '</ul>'
                . '<p>Ett sammanfattningsmejl delas inte med någon annan: det listar avsändare, ämne och tid för dina väntande meddelanden och går till din egen registrerade adress.</p>',
        ],
        [
            'id'      => 'automated-limits',
            'heading' => 'Automatiska gränser',
            'html'    => '<p>Automatiska gränser skyddar adresser och tjänsten: när en gräns nås pausas en adress eller integration en stund och du får veta det. Dessa pauser är tillfälliga. Att stänga av ett konto avgörs alltid av en människa. Vi fattar inga beslut som enbart grundas på automatiserad behandling och som har rättsliga följder eller på liknande sätt väsentligt påverkar dig (GDPR artikel 22). Om du tycker att en gräns drabbade dig felaktigt, <a href="#controller">kontakta oss</a>.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Betalningar behandlas av Paddle',
            'html'    => '<p>Vår orderhantering sköts av vår återförsäljare Paddle.com. Paddle.com är Merchant of Record för alla våra beställningar. När du går till kassan lämnar du dina betalningsuppgifter, ditt namn, ditt land och din e-postadress till Paddle, inte till oss, och Paddle behandlar dem som självständigt personuppgiftsansvarig enligt <a href="https://www.paddle.com/legal/privacy" rel="noopener">Paddles integritetspolicy</a>.</p>'
                . '<p>Prissidan, och planavsnittet på startsidan, läser in Paddles skript för att visa priser i din valuta, och kassan använder det för att öppnas. När det läses in kontaktar din webbläsare Paddle, som då ser din IP-adress; Paddle kan sätta egna cookies där.</p>',
        ],
        [
            'id'      => 'cookies',
            'heading' => 'Cookies',
            'html'    => '<ul>'
                . '<li><strong>En sessionscookie</strong>, som håller dig inloggad. Den är nödvändig för tjänsten och upphör när du loggar ut eller stänger sessionen.</li>'
                . '<li><strong>En cookie för betrodd webbläsare</strong>, som bara sätts om du väljer att få en webbläsare ihågkommen för tvåfaktorsautentisering. Den gäller i ' . $trustedDays . ' dagar.</li>'
                . '<li><strong>En cookie för att förbli inloggad</strong>, som bara sätts om du väljer att förbli inloggad på en enhet. Den gäller den period du valde (' . $stayDays[0] . ', ' . $stayDays[1] . ' eller ' . $stayDays[2] . ' dagar från ditt senaste besök, högst ' . $maxSignedInDays . ' dagar) och tas bort när du loggar ut.</li>'
                . '<li><strong>Google Analytics-cookies</strong>, som bara sätts på de publika sidorna (aldrig i inkorgen, på ditt konto eller på inloggningssidorna) och bara efter att du klickat på Godkänn i cookie-meddelandet. Använd Cookie-inställningar i sidfoten för att dra tillbaka ditt samtycke; då slutar dessa cookies sättas på senare sidor och de som redan satts raderas.</li>'
                . '<li><strong>En inbjudningscookie, <code>ms_ref</code></strong>, som bara sätts när du följer en inbjudningslänk medan inbjudningsprogrammet är öppet. Den innehåller inbjudningskoden, gäller i upp till ' . $ref['cookie_days'] . ' dagar och tas bort när du verifierar ditt konto.</li>'
                . '<li><strong>Paddle-cookies</strong> på startsidan, prissidan och i kassan.</li>'
                . '<li><strong>Ditt cookieval</strong> kommer vi ihåg i webbläsarens lokala lagring, inte i en cookie, så att vi kan respektera det; det är absolut nödvändigt och skickas inte till oss.</li>'
                . '</ul>'
                . '<p>Du kan blockera eller radera cookies i din webbläsare. Om du blockerar sessionscookien kan du inte logga in. Ingen cookie används för språk.</p>',
        ],
        [
            'id'      => 'transfers',
            'heading' => 'Överföringar utanför EU',
            'html'    => '<p>Mail Shield själv hostas i Sverige, och dina mejl lagras där. Bara Google och Paddle behandlar uppgifter utanför EU och EES, bland annat i USA och Storbritannien. När de gör det omfattas överföringen av ett beslut om adekvat skyddsnivå – som EU–USA Data Privacy Framework eller beslutet för Storbritannien – eller av EU:s standardavtalsklausuler.</p>',
        ],
        [
            'id'      => 'security',
            'heading' => 'Hur vi skyddar det',
            'html'    => '<p>Anslutningar till Mail Shield är krypterade. Din registrerade adress, hemligheter för tvåfaktorsautentisering och dina integrationers inloggningsuppgifter – webhook-hemligheter och -rubriker, Pushover-nycklar och flödeslänkar – är krypterade i vilande tillstånd; lösenord och åtkomsttokens för anslutna appar lagras som hashar i stället för som sig själva. Bilagor levereras bara via signerade länkar som löper ut. Åtkomst till produktionsdata är begränsad till de personer som driver tjänsten.</p>'
                . '<p>Kom ihåg att e-post i sig vanligen inte är end-to-end-krypterad, och att en delad inkorgslänk kan läsas av alla som har den.</p>',
        ],
        [
            'id'      => 'rights',
            'heading' => 'Dina rättigheter',
            'html'    => '<p>Enligt GDPR har du rätt att få tillgång till de personuppgifter vi har om dig, att få dem rättade eller raderade, att begränsa eller invända mot behandlingen av dem och att få ut dem i ett portabelt format. Där vi stödjer oss på ditt samtycke kan du när som helst återkalla det. Där vi stödjer oss på vårt berättigade intresse har du rätt att invända mot den behandlingen.</p>'
                . '<p>Vissa av dessa rättigheter har begränsningar. Betalningsunderlag sparas i sju år enligt svensk bokföringslag, även efter att ditt konto raderats, och uppgifter som behövs för att hantera en säkerhetsincident eller ett rättsligt anspråk kan sparas så länge det pågår.</p>'
                . '<p>Det mesta kan du göra själv: din profil visar dina kontouppgifter, och om du raderar ditt konto tas de bort, utom det vi måste spara för bokföringen. För allt annat, mejla ' . $f['contact'] . '. Vi svarar inom en månad.</p>'
                . '<p>Om du tycker att vi hanterar dina uppgifter fel kan du klaga hos Integritetsskyddsmyndigheten (IMY, <a href="https://www.imy.se" rel="noopener">imy.se</a>) eller hos tillsynsmyndigheten där du bor.</p>',
        ],
        [
            'id'      => 'children',
            'heading' => 'Barn',
            'html'    => '<p>Mail Shield är inte avsett för barn under ' . $minAge . ' år, och vi samlar inte medvetet in uppgifter från dem.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Ändringar i den här policyn',
            'html'    => '<p>När policyn ändras uppdaterar vi datumet högst upp. Om en ändring är väsentlig meddelar vi dig via e-post innan den träder i kraft.</p>',
        ],
    ],
];
