<?php
/**
 * Häufige Fragen auf Deutsch: gleiche Struktur wie faq.en.php (dieselben
 * Gruppenschlüssel, gleich viele Fragen pro Gruppe, dieselben Bedingungszweige
 * für die Testphase und die Anmeldung per OAuth). Nur die Worte unterscheiden
 * sich. Die Variablen sind die, die faq.php definiert, siehe den Docblock in
 * faq.en.php. Zahlen, die Domain und die MCP-Adresse stehen hier nie fest,
 * sondern kommen aus den Variablen. Glossar: documentaion/I18N.md.
 *
 * Die Gruppenschlüssel (die Schlüssel des Arrays) sind in allen Sprachen
 * englisch, damit die #-Anker gleich funktionieren.
 *
 * @return array<string, array{title: string, items: list<array{q: string, a: string}>}>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

$msAiSignInAnswer = <<<HTML
<p>Ja, mit Pro. Füge <code>{$msMcpUrl}</code> in deiner App hinzu und melde dich an, wenn sie danach fragt – Mail Shield zeigt dir, was die App möchte, und du bestätigst es. Ein Client, der nur einen Header akzeptiert, nutzt stattdessen eines deiner Zugriffstoken. Auf beiden Wegen verbindet sich der Assistent über das Model Context Protocol (MCP) und kann dann deine Adressen auflisten, deine E-Mails lesen und – wenn du ihm Schreibzugriff gegeben hast – Adressen für dich erstellen und löschen.</p>
<p>Der Assistent handelt als dein Konto, behandle also das, was du ihm gibst, wie ein Passwort. Alles, was er liest, geht an den, der ihn betreibt: Diesen Anbieter wählst du aus, nicht wir, und wir sind nicht sein Auftragsverarbeiter. Trenne eine App oder widerrufe ein Token in der Karte „Verbundene Apps“ in deinem Profil, dann funktioniert sie sofort nicht mehr. Wenn du dein Passwort oder deine E-Mail-Adresse änderst, werden alle Verbindungen auf einmal getrennt.</p>
HTML;
$msAiTokenAnswer = <<<HTML
<p>Ja, mit Pro. Du erstellst in deinem Profil ein Zugriffstoken und gibst es dem Assistenten, der sich über das Model Context Protocol (MCP) unter <code>{$msMcpUrl}</code> mit Mail Shield verbindet. Er kann dann deine Adressen auflisten, deine E-Mails lesen und – wenn du dem Token Schreibzugriff gegeben hast – Adressen für dich erstellen und löschen.</p>
<p>Der Assistent handelt als dein Konto, behandle sein Zugriffstoken also wie ein Passwort. Alles, was er liest, geht an den, der ihn betreibt: Diesen Anbieter wählst du aus, nicht wir, und wir sind nicht sein Auftragsverarbeiter. Widerrufe ein Token in der Karte „Verbundene Apps“ in deinem Profil, dann funktioniert es sofort nicht mehr. Wenn du dein Passwort oder deine E-Mail-Adresse änderst, werden alle Token auf einmal widerrufen.</p>
HTML;
$msAiAnswer = $msMcpSignIn ? $msAiSignInAnswer : $msAiTokenAnswer;

return [
    'general' => [
        'title' => 'Allgemeines',
        'items' => [
            [
                'q' => 'Was ist Mail Shield?',
                'a' => <<<HTML
<p>Ein separater Posteingang für die E-Mails, die du nicht in deinem normalen haben willst. Shopping, Newsletter, Registrierungen, Forenantworten, automatische Mails und Dienste, die du nur ausprobierst, bekommen alle einen anderen Ort, und du liest sie an einer Stelle im Web.</p>
<p>Du bekommst eigene Adressen – Timed-Adressen, die sich selbst löschen, und Sticky-Adressen, die du behältst. Das ist keine Seite, die eine Wegwerfadresse ausgibt und dich vergisst, sobald du den Tab schließt: Eine Adresse hier gehört zu einem Konto, sodass eine Bestätigung, die eine Stunde später ankommt, immer noch auf dich wartet.</p>
HTML,
            ],
            [
                'q' => 'Wie bekomme ich eine Timed-Adresse?',
                'a' => <<<HTML
<p>Erstelle ein Konto mit deiner eigenen E-Mail-Adresse, bestätige sie und erstelle dann die Timed-Adresse in deinem Posteingang. Auf der Startseite gibt es keinen Button, der anonym eine Adresse ausgibt – eine Adresse braucht ein Konto dahinter, und genau das ermöglicht es dir, für eine später eintreffende Nachricht zurückzukommen.</p>
HTML,
            ],
            [
                'q' => 'Wie lange werden Nachrichten und Adressen aufbewahrt?',
                'a' => <<<HTML
<p>Eine Timed-Adresse in einem kostenlosen Konto wird <strong>24 Stunden</strong> nach dem Erstellen automatisch gelöscht, und die Nachrichten darin verschwinden mit ihr. Mit Pro wählst du die Lebensdauer jeder Timed-Adresse selbst, von <strong>1 bis 7 Tagen</strong>.</p>
<p>Sticky-Adressen laufen überhaupt nicht auf einen Timer – sie bleiben, bis du sie löschst.</p>
HTML,
            ],
            [
                'q' => 'Kostet das etwas?',
                'a' => $msT > 0 ? <<<HTML
<p>Ein kostenloses Konto kostet nichts und braucht keine Karte, und jedes neue Konto startet mit <strong>{$msT} Tagen Pro</strong> ohne Kosten. Danach bleibst du mit <a href="{$msU('/pricing.php')}">einem Monats-, Jahres- oder Einmal-Tarif</a> oder einem Gutscheincode bei Pro.</p>
HTML : <<<HTML
<p>Ein kostenloses Konto kostet nichts und braucht keine Karte. Pro gibt es als <a href="{$msU('/pricing.php')}">Monats-, Jahres- oder Einmal-Tarif</a> oder mit einem Gutscheincode.</p>
HTML,
            ],
        ],
    ],
    'accounts' => [
        'title' => 'Konten und Tarife',
        'items' => [
            [
                'q' => 'Was ist der Unterschied zwischen Free und Pro?',
                'a' => <<<HTML
<p>Ein kostenloses Konto deckt temporäre E-Mail ab: eine Timed-Adresse nach der anderen, die 24 Stunden nach dem Erstellen gelöscht wird.</p>
<p>Pro ergänzt die dauerhafte Seite des Produkts und die Automatisierung – bis zu 10 Sticky-Adressen, eine selbst gewählte Lebensdauer für Timed-Adressen von 1 bis 7 Tagen, RSS, Webhooks, Pushover, E-Mail-Zusammenfassungen und den Agent.</p>
HTML . ($msT > 0 ? <<<HTML
<p>Jedes neue Konto bekommt Pro für die ersten {$msT} Tage, damit du alles ausprobieren kannst, bevor du dich entscheidest.</p>
HTML : ''),
            ],
            ...($msT > 0 ? [[
                'q' => "Wie funktioniert die {$msT}-tägige Pro-Testphase?",
                'a' => <<<HTML
<p>Jedes neue Konto bekommt Pro für die ersten {$msT} Tage, ab dem Moment, in dem du deine E-Mail-Adresse bestätigst. Du musst keine Karte angeben, und es gibt nichts zu kündigen.</p>
<p>Die Testphase gilt einmal pro E-Mail-Adresse. Wenn du dein Konto löschst und dich mit derselben Adresse erneut registrierst, läuft die Uhr ab deiner ersten Registrierung weiter – sie beginnt nicht von vorn.</p>
<p>Wenn die Testphase endet, läuft dein Konto als kostenloses weiter. Die Automatisierung wird abgeschaltet, und Sticky-Adressen – mitsamt den E-Mails darin – werden 7 Tage später gelöscht. Sichere also zuerst, was du noch brauchst. Um bei Pro zu bleiben, wähle einen unserer <a href="{$msU('/pricing.php')}">Tarife</a> oder löse in deinem Profil einen Gutscheincode ein.</p>
HTML,
            ]] : []),
            [
                'q' => 'Wie viele Adressen kann ich erstellen?',
                'a' => <<<HTML
<p>Adressen gehören zu deinem Konto, nicht zu einer Browsersitzung. Jedes Konto hat eine Timed-Adresse nach der anderen, und wenn du eine neue erstellst, ersetzt sie die alte.</p>
<p>Pro-Konten bekommen zusätzlich bis zu 10 Sticky-Adressen, die neben der Timed-Adresse bestehen und in denselben Posteingang liefern.</p>
HTML,
            ],
            [
                'q' => 'Wie bekomme ich ein Pro-Konto?',
                'a' => $msT > 0 ? <<<HTML
<p>Erstelle ein Konto – es startet für die ersten {$msT} Tage mit Pro. Um Pro danach zu behalten, wähle angemeldet einen <a href="{$msU('/pricing.php')}">Tarif</a> oder löse in deinem Profil einen Gutscheincode ein; in beiden Fällen wird die Zeit nach dem Ende der Testphase angehängt. Konten melden sich mit einem Magic Link an, der an ihre E-Mail-Adresse geschickt wird, und können in ihrem Profil optional ein Passwort festlegen.</p>
HTML : <<<HTML
<p>Erstelle ein kostenloses Konto und wähle angemeldet einen <a href="{$msU('/pricing.php')}">Tarif</a> – oder registriere dich mit einem Gutscheincode: Wähle auf der Registrierungsseite Pro und gib den Code ein. Konten melden sich mit einem Magic Link an, der an ihre E-Mail-Adresse geschickt wird, und können in ihrem Profil optional ein Passwort festlegen.</p>
HTML,
            ],
            [
                'q' => 'Welche Einstellungen gibt es mit Pro?',
                'a' => <<<HTML
<ul>
<li>Ein Passwort für die Anmeldung festlegen, zusätzlich zum Link per E-Mail.</li>
<li>Wählen, wie lange neue Timed-Adressen bestehen, von 1 bis 7 Tagen.</li>
<li>Bis zu 10 Sticky-Adressen erstellen, ansehen und löschen.</li>
<li>Die Zwei-Faktor-Authentifizierung ein- oder ausschalten und vertrauenswürdige Browser verwalten.</li>
<li>E-Mails {$msHoldDays} Tage aufbewahren, bis zu {$msHoldMax} Mal pro Jahr.</li>
<li>Automatisierung einrichten: RSS, Webhooks und E-Mail-Zusammenfassungen.</li>
</ul>
HTML,
            ],
            [
                'q' => 'Kann ich meine E-Mails länger aufbewahren?',
                'a' => <<<HTML
<p>Ja, mit Pro. Bis zu {$msHoldMax} Mal pro Jahr kannst du die Bereinigung zurückhalten: E-Mails auf deinen Sticky-Adressen, die sonst gelöscht würden, werden {$msHoldDays} Tage ab dem Tag ihres Eingangs aufbewahrt, statt der üblichen 1 bis 7. Starte eine Löschpause, indem du unter <strong>Profil &rarr; Standarddauer</strong> {$msHoldDays} Tage wählst.</p>
<p>Wenn du eine Löschpause vorzeitig beendest, bleiben die bereits verlängerten E-Mails erhalten. Timed-Adressen sind nicht betroffen – sie laufen planmäßig ab und nehmen ihre Nachrichten mit. Das Speicherlimit deines Postfachs gilt weiterhin, und wenn es erreicht ist, werden zuerst die ältesten E-Mails entfernt.</p>
HTML,
            ],
        ],
    ],
    'temporary' => [
        'title' => 'Timed- und Sticky-Adressen',
        'items' => [
            [
                'q' => 'Was ist der Unterschied zwischen einer Timed-Adresse und einer Sticky-Adresse?',
                'a' => <<<HTML
<p>Eine Timed-Adresse ist die Mail-Shield-Variante von temporärer E-Mail (Wegwerf-E-Mail): Sie ist zum Wegwerfen gedacht. Sie läuft auf einem Timer ab, um den du dich nicht kümmern musst – 24 Stunden in einem kostenlosen Konto, 1 bis 7 Tage mit Pro – und nimmt ihre Nachrichten mit.</p>
<p>Eine Sticky-Adresse behältst du: ein lesbarer Name wie <span class="ms-addr">shopping@{$msDomain}</span> statt einer zufälligen Zeichenfolge, ohne Ablauf, und du bestimmst, wann sie verschwindet. Beide Arten kommen im selben Posteingang an.</p>
HTML,
            ],
            [
                'q' => 'Kann ich Mail Shield für dauerhafte Adressen nutzen?',
                'a' => <<<HTML
<p>Ja – dafür sind Sticky-Adressen da. Ein Pro-Konto kann bis zu 10 davon haben, alle dauerhaft in dem Sinne, auf den es ankommt: Sie laufen nicht ab, und du löschst sie, wenn du sie nicht mehr brauchst.</p>
<p>Alle Adressen nutzen die Domain {$msDomain}, und alle liefern in denselben Posteingang.</p>
HTML,
            ],
            [
                'q' => 'Wie lange bestehen Sticky-Adressen?',
                'a' => <<<HTML
<p>Bis du sie löschst. Sie haben keinen Ablauf-Timer und werden von der Bereinigung, die Timed-Adressen entfernt, nicht erfasst. Wenn du eine in deinem Profil löschst, wird die Adresse entfernt, und sie empfängt danach keine E-Mails mehr.</p>
HTML,
            ],
        ],
    ],
    'automation' => [
        'title' => 'Automatisierung',
        'items' => [
            [
                'q' => 'Womit kann ich es verbinden?',
                'a' => <<<HTML
<p>Pro-Konten können E-Mails auf mehreren Wegen weiterleiten:</p>
<ul>
<li>Ein privater RSS-Feed, den du in jedem Feed-Reader liest und der durch ein Token geschützt ist – für dein ganzes Konto oder für eine einzelne Sticky-Adresse.</li>
<li>Webhooks, die jede eingehende Nachricht als JSON per POST an einen Endpunkt senden, den du kontrollierst.</li>
<li>Pushover, für eingehende E-Mails als Benachrichtigung auf deinem Handy.</li>
<li>E-Mail-Zusammenfassungen, eine regelmäßige Übersicht darüber, was angekommen ist.</li>
</ul>
<p>Wenn du einen eigenen Mailserver betreibst, gibt es außerdem den Agent: Du verwaltest Absenderlisten und Filterregeln in Mail Shield, und der Agent wendet sie auf ein Postfach auf deinem Server an. Diese E-Mails bleiben auf deinem Server – sie kommen nicht in Mail Shield an.</p>
<p>Du kannst auch einen KI-Assistenten mit deinem Konto verbinden, der deine Adressen für dich liest und verwaltet. Dazu gibt es unten eine eigene Frage.</p>
HTML,
            ],
            [
                'q' => 'Kann ich einen KI-Assistenten mit meinem Konto verbinden?',
                'a' => $msAiAnswer,
            ],
            [
                'q' => 'Ist die Automatisierung in einem kostenlosen Konto enthalten?',
                'a' => $msT > 0 ? <<<HTML
<p>In den ersten {$msT} Tagen ja – jedes neue Konto startet mit Pro, Automatisierung inklusive. Nach der Testphase gehören RSS, Webhooks, Pushover, E-Mail-Zusammenfassungen und der Agent zu Pro, und ein kostenloses Konto deckt temporäre E-Mail ab.</p>
HTML : <<<HTML
<p>Nein. RSS, Webhooks, Pushover, E-Mail-Zusammenfassungen und der Agent gehören alle zu Pro. Ein kostenloses Konto deckt temporäre E-Mail ab.</p>
HTML,
            ],
        ],
    ],
    'security' => [
        'title' => 'Sicherheit und Datenschutz',
        'items' => [
            [
                'q' => 'Ist der Inhalt meiner E-Mails privat?',
                'a' => <<<HTML
<p>Die Antwort hängt von der Art der Adresse ab, deshalb lohnt es sich, genau zu sein. Eine Timed-Adresse ist über ihren Link erreichbar: Wer den Link hat, kann den Posteingang lesen, und das kann auch jeder, der ihn findet. Sieh eine Timed-Adresse als einen Ort zum Empfangen, nicht als einen Ort, um Geheimnisse aufzubewahren.</p>
<p>Sticky-Adressen sind anders. Sie gehören zu deinem Konto und können nur gelesen werden, solange du als Inhaber angemeldet bist.</p>
<p>In beiden Fällen gilt: Schicke keine sensiblen persönlichen Daten – Bankdaten, Ausweisdokumente, medizinische Angaben – an eine Adresse, die du wegwerfen willst.</p>
HTML,
            ],
            [
                'q' => 'Wie werden Anhänge behandelt?',
                'a' => <<<HTML
<p>Anhänge werden vorübergehend gespeichert, und die Download-Links sind signiert und zeitlich begrenzt, sodass ein Link weder erraten noch unbegrenzt weitergegeben werden kann. Die Dateien werden zusammen mit den Nachrichten bereinigt, mit denen sie angekommen sind.</p>
HTML,
            ],
            [
                'q' => 'Was schützt die Zwei-Faktor-Authentifizierung (2FA)?',
                'a' => <<<HTML
<p>2FA schützt die Anmeldung mit Passwort bei deinem Konto: Ist 2FA aktiviert, verlangt die Anmeldung mit Passwort zusätzlich einen 6-stelligen Code aus einer Authenticator-App. Für Anmeldelinks per E-Mail kommt kein weiterer Schritt hinzu – ein Magic Link beweist bereits, dass du Zugriff auf den Posteingang hast, und das ist selbst ein zweiter Faktor.</p>
HTML,
            ],
            [
                'q' => 'Welche Authenticator-Apps werden unterstützt?',
                'a' => <<<HTML
<p>Jede gängige TOTP-App funktioniert, darunter 1Password, Authy und Google Authenticator. Richte sie im Abschnitt zur Zwei-Faktor-Authentifizierung in deinem Profil ein, indem du den QR-Code scannst oder den Schlüssel manuell eingibst.</p>
HTML,
            ],
            [
                'q' => 'Was ist, wenn ich mein Handy oder meine Authenticator-App verliere?',
                'a' => <<<HTML
<p>Du bist nicht ausgesperrt. Melde dich wie gewohnt mit deinem Anmeldelink per E-Mail (Magic Link) an – er funktioniert auch bei aktivierter 2FA – und schalte dann die Zwei-Faktor-Authentifizierung in deinem Profil aus. Von dort aus kannst du 2FA auf einem neuen Gerät erneut einrichten oder ausgeschaltet lassen. Du kannst auch einen der einmaligen Wiederherstellungscodes verwenden, die dir bei der Einrichtung angezeigt wurden, falls du sie gespeichert hast.</p>
HTML,
            ],
            [
                'q' => 'Ist der Agent sicher?',
                'a' => <<<HTML
<p>Der Agent läuft auf deinem eigenen Server, deine Daten bleiben also unter deiner Kontrolle. Er überprüft RSA-signierte Webhooks von Mail Shield, bevor er darauf reagiert, und speichert die Geheimnisse, die er braucht, verschlüsselt statt im Klartext. Das Filtern geschieht auf deinem Rechner, deine E-Mails müssen ihn also nicht verlassen, um sortiert zu werden.</p>
HTML,
            ],
        ],
    ],
    'troubleshooting' => [
        'title' => 'Fehlersuche',
        'items' => [
            [
                'q' => 'Ich habe eine erwartete Nachricht nicht erhalten – was tun?',
                'a' => <<<HTML
<p>Prüfe die vollständige Adresse, die du angegeben hast, sowohl den lokalen Teil als auch die Domain. Bei einer Sticky-Adresse: Achte darauf, dass du in dem Konto angemeldet bist, dem sie gehört. Warte dann einen Moment und aktualisiere – E-Mails werden gespeichert, sobald der Server des Absenders sie übergibt, und der Posteingang holt sie bei der nächsten Prüfung oder wenn du aktualisierst, doch ein Absender kann die Zustellung verzögern oder erneut versuchen. Kommt sie trotzdem nicht an, hat der Absender sie möglicherweise verzögert oder abgelehnt.</p>
HTML,
            ],
            [
                'q' => 'Wie bekomme ich Support?',
                'a' => <<<HTML
<p>Kontospezifische Probleme lassen sich am besten innerhalb deines Kontos klären: <a href="/pro_login.php">Melde dich an</a> und nutze die Kontaktseite, und gib die Adresse oder die Nachricht an, nach der du fragst. Diese Seite mit häufigen Fragen soll zuerst die allgemeinen Fälle beantworten.</p>
HTML,
            ],
        ],
    ],
];
