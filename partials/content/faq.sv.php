<?php
/**
 * Vanliga frågor på svenska: samma struktur som faq.en.php (samma grupp-nycklar,
 * lika många frågor per grupp, samma villkorsgrenar för provperiod och inloggning
 * med OAuth). Bara orden skiljer. Variablerna är de som faq.php definierar, se
 * docblocket i faq.en.php. Siffror, domänen och MCP-adressen skrivs aldrig in
 * här utan kommer från variablerna. Ordlistan: documentaion/I18N.md §5.
 *
 * Gruppnycklarna (arrayns nycklar) är engelska i alla språk så att #-ankarna
 * fungerar likadant.
 *
 * @return array<string, array{title: string, items: list<array{q: string, a: string}>}>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

$msAiSignInAnswer = <<<HTML
<p>Ja, med Pro. Lägg till <code>{$msMcpUrl}</code> i din app och logga in när den ber om det – Mail Shield visar vad appen vill ha och du godkänner det. En klient som bara tar emot en header använder en av dina åtkomsttokens i stället. Oavsett väg ansluter assistenten via Model Context Protocol (MCP), och kan sedan lista dina adresser, läsa din e-post och – om du gav den skrivåtkomst – skapa och radera adresser åt dig.</p>
<p>Assistenten agerar som ditt konto, så behandla det du ger den som ett lösenord. Allt den läser skickas till den som driver den: den leverantören väljer du, inte vi, och vi är inte dess personuppgiftsbiträde. Koppla bort en app, eller återkalla en token, i kortet Anslutna appar i din profil så slutar den fungera direkt. Om du byter lösenord eller e-postadress bryts alla anslutningar på en gång.</p>
HTML;
$msAiTokenAnswer = <<<HTML
<p>Ja, med Pro. Du skapar en åtkomsttoken i din profil och ger den till assistenten, som ansluter till Mail Shield via Model Context Protocol (MCP) på <code>{$msMcpUrl}</code>. Den kan sedan lista dina adresser, läsa din e-post och – om du gav tokenen skrivåtkomst – skapa och radera adresser åt dig.</p>
<p>Assistenten agerar som ditt konto, så behandla dess åtkomsttoken som ett lösenord. Allt den läser skickas till den som driver den: den leverantören väljer du, inte vi, och vi är inte dess personuppgiftsbiträde. Återkalla en token från kortet Anslutna appar i din profil så slutar den fungera direkt. Om du byter lösenord eller e-postadress återkallas alla tokens på en gång.</p>
HTML;
$msAiAnswer = $msMcpSignIn ? $msAiSignInAnswer : $msAiTokenAnswer;

return [
    'general' => [
        'title' => 'Allmänt',
        'items' => [
            [
                'q' => 'Vad är Mail Shield?',
                'a' => <<<HTML
<p>En separat inkorg för den e-post du inte vill ha i din vanliga. Shopping, nyhetsbrev, registreringar, svar från forum, automatiska mejl och tjänster du bara testar får alla en annan plats att hamna på, och du läser dem på ett ställe på webben.</p>
<p>Du får egna adresser – Timed-adresser som raderar sig själva och Sticky-adresser som du behåller. Det är inte en sida som skriver ut en engångsadress och glömmer bort dig när du stänger fliken: en adress här tillhör ett konto, så en bekräftelse som kommer en timme senare väntar fortfarande på dig.</p>
HTML,
            ],
            [
                'q' => 'Hur får jag en Timed-adress?',
                'a' => <<<HTML
<p>Skapa ett konto med din egen e-postadress, verifiera den och skapa sedan Timed-adressen från din inkorg. Det finns ingen knapp på startsidan som ger ut en adress anonymt – en adress behöver ett konto bakom sig, och det är det som gör att du kan komma tillbaka för ett meddelande som kommer senare.</p>
HTML,
            ],
            [
                'q' => 'Hur länge sparas meddelanden och adresser?',
                'a' => <<<HTML
<p>En Timed-adress på ett gratiskonto raderas automatiskt <strong>24 timmar</strong> efter att du skapade den, och meddelandena i den försvinner med den. Med Pro väljer du livslängden för varje Timed-adress, från <strong>1 till 7 dagar</strong>.</p>
<p>Sticky-adresser går inte på någon timer alls – de finns kvar tills du raderar dem.</p>
HTML,
            ],
            [
                'q' => 'Kostar det något?',
                'a' => $msT > 0 ? <<<HTML
<p>Ett gratiskonto kostar ingenting och kräver inget kort, och varje nytt konto börjar med <strong>{$msT} dagar Pro</strong> utan kostnad. Därefter stannar du kvar på Pro med <a href="{$msU('/pricing.php')}">en månads-, års- eller engångsplan</a>, eller en värdekod.</p>
HTML : <<<HTML
<p>Ett gratiskonto kostar ingenting och kräver inget kort. Pro är <a href="{$msU('/pricing.php')}">en månads-, års- eller engångsplan</a>, eller en värdekod.</p>
HTML,
            ],
        ],
    ],
    'accounts' => [
        'title' => 'Konton och planer',
        'items' => [
            [
                'q' => 'Vad är skillnaden mellan Free och Pro?',
                'a' => <<<HTML
<p>Ett gratiskonto täcker tillfällig e-post: en Timed-adress i taget, som raderas 24 timmar efter att du skapade den.</p>
<p>Pro lägger till den permanenta sidan av produkten och automatiseringen – upp till 10 Sticky-adresser, en livslängd för Timed-adresser på 1 till 7 dagar som du väljer själv, RSS, webhooks, Pushover, sammanfattningsmejl och Agenten.</p>
HTML . ($msT > 0 ? <<<HTML
<p>Varje nytt konto får Pro under sina första {$msT} dagar, så att du kan prova allt innan du bestämmer dig.</p>
HTML : ''),
            ],
            ...($msT > 0 ? [[
                'q' => "Hur fungerar provperioden på {$msT} dagar med Pro?",
                'a' => <<<HTML
<p>Varje nytt konto får Pro under sina första {$msT} dagar, från det att du bekräftar din e-postadress. Du behöver inte ange något kort och det finns inget att säga upp.</p>
<p>Provperioden gäller en gång per e-postadress. Om du raderar ditt konto och registrerar dig igen med samma adress fortsätter klockan att gå från din första registrering – den börjar inte om.</p>
<p>När provperioden tar slut fortsätter ditt konto som ett gratiskonto. Automatiseringen stängs av, och Sticky-adresser – med e-posten i dem – raderas 7 dagar senare, så flytta först det du fortfarande behöver. För att stanna kvar på Pro väljer du någon av våra <a href="{$msU('/pricing.php')}">planer</a>, eller löser in en värdekod i din profil.</p>
HTML,
            ]] : []),
            [
                'q' => 'Hur många adresser kan jag skapa?',
                'a' => <<<HTML
<p>Adresser tillhör ditt konto, inte en webbläsarsession. Varje konto har en Timed-adress i taget, och när du skapar en ny ersätter den den gamla.</p>
<p>Pro-konton får dessutom upp till 10 Sticky-adresser, som finns vid sidan av Timed-adressen och levererar till samma inkorg.</p>
HTML,
            ],
            [
                'q' => 'Hur skaffar jag ett Pro-konto?',
                'a' => $msT > 0 ? <<<HTML
<p>Skapa ett konto – det börjar med Pro under sina första {$msT} dagar. För att behålla Pro efter det väljer du en <a href="{$msU('/pricing.php')}">plan</a> medan du är inloggad, eller löser in en värdekod i din profil; i båda fallen läggs tiden till efter att provperioden tagit slut. Konton loggar in med en inloggningslänk (magic link) som skickas till deras e-post, och kan frivilligt ange ett lösenord i sin profil.</p>
HTML : <<<HTML
<p>Skapa ett gratiskonto och välj en <a href="{$msU('/pricing.php')}">plan</a> medan du är inloggad – eller registrera dig med en värdekod: välj Pro på registreringssidan och ange koden. Pro-konton loggar in med en inloggningslänk (magic link) som skickas till deras e-post, och kan frivilligt ange ett lösenord i sin profil.</p>
HTML,
            ],
            [
                'q' => 'Vilka inställningar finns med Pro?',
                'a' => <<<HTML
<ul>
<li>Ange ett lösenord för inloggning, vid sidan av e-postlänken.</li>
<li>Välj hur länge nya Timed-adresser varar, från 1 till 7 dagar.</li>
<li>Skapa, granska och radera upp till 10 Sticky-adresser.</li>
<li>Slå på eller av tvåfaktorsautentisering och hantera betrodda webbläsare.</li>
<li>Spara e-post i {$msHoldDays} dagar, upp till {$msHoldMax} gånger per år.</li>
<li>Ställ in automatisering: RSS, webhooks och sammanfattningsmejl.</li>
</ul>
HTML,
            ],
            [
                'q' => 'Kan jag spara min e-post längre?',
                'a' => <<<HTML
<p>Ja, med Pro. Upp till {$msHoldMax} gånger per år kan du hålla tillbaka städningen: e-post på dina Sticky-adresser som annars skulle raderas sparas i {$msHoldDays} dagar från den dag den kom, i stället för de vanliga 1 till 7. Starta <strong>Pausa radering</strong> genom att välja {$msHoldDays} dagar under <strong>Profil &rarr; Standardlivslängd</strong>.</p>
<p>Om du avslutar pausen i raderingen i förtid ligger den e-post som redan förlängts kvar. Timed-adresser påverkas inte – de löper ut enligt schemat och tar sina meddelanden med sig. Gränsen för din brevlådas lagringsutrymme gäller fortfarande, och när den nås tas den äldsta e-posten bort först.</p>
HTML,
            ],
        ],
    ],
    'temporary' => [
        'title' => 'Timed- och Sticky-adresser',
        'items' => [
            [
                'q' => 'Vad är skillnaden mellan en Timed-adress och en Sticky-adress?',
                'a' => <<<HTML
<p>En Timed-adress är Mail Shields version av tillfällig e-post: den är gjord för att slängas. Den löper ut på en timer som du inte behöver tänka på – 24 timmar på ett gratiskonto, 1 till 7 dagar med Pro – och tar sina meddelanden med sig.</p>
<p>En Sticky-adress är en du behåller: ett läsbart namn som <span class="ms-addr">shopping@{$msDomain}</span> i stället för en slumpmässig sträng, utan utgångsdatum, och du bestämmer när den försvinner. Båda sorterna kommer till samma inkorg.</p>
HTML,
            ],
            [
                'q' => 'Kan jag använda Mail Shield för permanenta adresser?',
                'a' => <<<HTML
<p>Ja – det är vad Sticky-adresser är till för. Ett Pro-konto kan ha upp till 10 av dem, alla permanenta i den mening som spelar roll: de löper inte ut, och du raderar dem när du är klar med dem.</p>
<p>Alla adresser använder domänen {$msDomain}, och alla levererar till samma inkorg.</p>
HTML,
            ],
            [
                'q' => 'Hur länge varar Sticky-adresser?',
                'a' => <<<HTML
<p>Tills du raderar dem. De har ingen utgångstimer och omfattas inte av städningen som tar bort Timed-adresser. När du raderar en från din profil tas adressen bort, och den tar inte emot någon e-post efter det.</p>
HTML,
            ],
        ],
    ],
    'automation' => [
        'title' => 'Automatisering',
        'items' => [
            [
                'q' => 'Vad kan jag koppla den till?',
                'a' => <<<HTML
<p>Pro-konton kan skicka e-post vidare på flera sätt:</p>
<ul>
<li>Ett privat RSS-flöde, som du läser i valfri flödesläsare och som skyddas av en token – för hela ditt konto eller för en enskild Sticky-adress.</li>
<li>Webhooks som skickar varje inkommande meddelande som JSON med POST till en endpoint som du styr över.</li>
<li>Pushover, för inkommande e-post som en avisering på din telefon.</li>
<li>Sammanfattningsmejl, en regelbunden översikt över vad som kommit.</li>
</ul>
<p>Om du kör din egen e-postserver finns även Agenten: du hanterar avsändarlistor och filterregler i Mail Shield, och Agenten tillämpar dem på en brevlåda på din server. Den e-posten stannar på din server – den kommer inte in i Mail Shield.</p>
<p>Du kan också ansluta en AI-assistent till ditt konto, som läser och hanterar dina adresser åt dig. Den har en egen fråga nedan.</p>
HTML,
            ],
            [
                'q' => 'Kan jag ansluta en AI-assistent till mitt konto?',
                'a' => $msAiAnswer,
            ],
            [
                'q' => 'Ingår automatisering i ett gratiskonto?',
                'a' => $msT > 0 ? <<<HTML
<p>Under de första {$msT} dagarna, ja – varje nytt konto börjar med Pro, automatisering inkluderad. Efter provperioden ingår RSS, webhooks, Pushover, sammanfattningsmejl och Agenten i Pro, och ett gratiskonto täcker tillfällig e-post.</p>
HTML : <<<HTML
<p>Nej. RSS, webhooks, Pushover, sammanfattningsmejl och Agenten ingår alla i Pro. Ett gratiskonto täcker tillfällig e-post.</p>
HTML,
            ],
        ],
    ],
    'security' => [
        'title' => 'Säkerhet och integritet',
        'items' => [
            [
                'q' => 'Är innehållet i min e-post privat?',
                'a' => <<<HTML
<p>Svaret skiljer sig mellan adresstyperna, så det är värt att vara exakt. En Timed-adress nås via sin länk: den som har länken kan läsa inkorgen, och det kan även den som hittar den. Se en Timed-adress som en plats att ta emot på, inte en plats att förvara hemligheter på.</p>
<p>Sticky-adresser är annorlunda. De tillhör ditt konto och kan bara läsas medan du är inloggad som ägare.</p>
<p>Oavsett vilket: skicka inga känsliga personuppgifter – bankuppgifter, id-handlingar, medicinska uppgifter – till en adress du tänker slänga.</p>
HTML,
            ],
            [
                'q' => 'Hur hanteras bilagor?',
                'a' => <<<HTML
<p>Bilagor sparas tillfälligt, och nedladdningslänkarna är signerade och tidsbegränsade så att en länk inte kan gissas eller spridas i det oändliga. Filerna städas bort tillsammans med de meddelanden de kom med.</p>
HTML,
            ],
            [
                'q' => 'Vad skyddar tvåfaktorsautentisering (2FA)?',
                'a' => <<<HTML
<p>2FA skyddar inloggning med lösenord till ditt konto: när 2FA är på kräver inloggning med lösenord också en sexsiffrig kod från en autentiseringsapp. Den lägger inte till något steg för inloggningslänkar via e-post – en inloggningslänk (magic link) bevisar redan att du har tillgång till inkorgen, vilket i sig är en andra faktor.</p>
HTML,
            ],
            [
                'q' => 'Vilka autentiseringsappar stöds?',
                'a' => <<<HTML
<p>Alla vanliga TOTP-appar fungerar, däribland 1Password, Authy och Google Authenticator. Ställ in det i avsnittet om tvåfaktorsautentisering i din profil genom att skanna QR-koden eller ange nyckeln manuellt.</p>
HTML,
            ],
            [
                'q' => 'Vad gör jag om jag tappar bort telefonen eller autentiseringsappen?',
                'a' => <<<HTML
<p>Du är inte utelåst. Logga in med din inloggningslänk via e-post (magic link) som vanligt – den fungerar även när 2FA är på – och stäng sedan av tvåfaktorsautentiseringen i din profil. Därifrån kan du ställa in 2FA igen på en ny enhet, eller låta den vara av. Du kan också använda en av engångskoderna för återställning som visades när du aktiverade den, om du sparade dem.</p>
HTML,
            ],
            [
                'q' => 'Är Agenten säker?',
                'a' => <<<HTML
<p>Agenten körs på din egen server, så dina data stannar under din kontroll. Den verifierar RSA-signerade webhooks från Mail Shield innan den agerar på dem, och lagrar de hemligheter den behöver krypterade i stället för i klartext. Filtreringen sker på din maskin, så din e-post behöver inte lämna den för att sorteras.</p>
HTML,
            ],
        ],
    ],
    'troubleshooting' => [
        'title' => 'Felsökning',
        'items' => [
            [
                'q' => 'Jag fick inte ett meddelande jag väntade på – vad gör jag?',
                'a' => <<<HTML
<p>Kontrollera hela adressen du angav, både lokal del och domän. För en Sticky-adress: se till att du är inloggad på kontot som äger den. Ge det sedan en liten stund och uppdatera – e-post hämtas från servern enligt ett schema, så ett meddelande kan ta en kort stund på sig att dyka upp. Om det fortfarande inte kommer kan avsändaren ha fördröjt eller avvisat det.</p>
HTML,
            ],
            [
                'q' => 'Hur får jag support?',
                'a' => <<<HTML
<p>Kontospecifika problem hanteras bäst inifrån ditt konto: <a href="/pro_login.php">logga in</a> och använd kontaktsidan, och ange adressen eller meddelandet du frågar om. Den här sidan med vanliga frågor är tänkt att svara på de allmänna fallen först.</p>
HTML,
            ],
        ],
    ],
];
