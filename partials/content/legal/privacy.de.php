<?php
/**
 * The privacy policy in German: the whole document as data, rendered by
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
$pushoverChars = '4.000';
$minAge = 16;
$abuseEventDays = 90;

return [
    'title'       => 'Datenschutzerklärung',
    'lede'        => 'Mail Shield gibt es, damit deine Haupt-Adresse dort bleibt, wo sie hingehört, und nicht an Orten landet, an denen sie nichts zu suchen hat. Diese Seite beschreibt, was wir dafür erheben, warum, wie lange wir es speichern und wer es sonst noch zu sehen bekommt.',
    'description' => 'Welche personenbezogenen Daten Mail Shield erhebt, warum, wie lange sie gespeichert werden und wer sie verarbeitet – einschließlich der Zahlungen, die über Paddle abgewickelt werden.',
    'path'        => '/privacy.php',
    'updated'     => '2026-10-11',
    'sections'    => [
        [
            'id'      => 'controller',
            'heading' => 'Wer verantwortlich ist',
            'html'    => '<p>Verantwortlicher im Sinne der DSGVO ist ' . $f['company_full'] . ', die Mail Shield betreibt. Fragen zu deinen Daten und Anfragen zur Ausübung der unten beschriebenen Rechte richtest du an ' . $f['contact'] . '.</p>'
                . '<p>Bei Käufen ist Paddle der Merchant of Record und Verantwortlicher für die Zahlungsdaten, die Paddle erhebt; siehe <a href="#payments">Zahlungen</a>.</p>',
        ],
        [
            'id'      => 'data',
            'heading' => 'Was wir erheben',
            'html'    => '<h3>Dein Konto</h3>'
                . '<p>Die E-Mail-Adresse, mit der du dich registrierst, und ein Hash deines Passworts – niemals das Passwort selbst. Wenn du die Zwei-Faktor-Authentifizierung aktivierst, speichern wir deren Geheimnis verschlüsselt sowie eine Liste der Browser, denen du vertraust. Wir erfassen, wann das Konto erstellt wurde und wann du dich zuletzt angemeldet hast, außerdem deine Einstellungen: Tarif, Lebensdauer der Adressen, E-Mail-Zusammenfassungen und Integrationen. Wenn du eine Sprache auswählst oder dich auf einer Seite in einer anderen Sprache registrierst oder anmeldest, speichert das Konto diese Spracheinstellung.</p>'
                . '<p>Deine registrierte E-Mail-Adresse wird verschlüsselt gespeichert und über einen Schlüssel-Hash statt im Klartext gesucht.</p>'
                . '<h3>Die E-Mails, die du erhältst</h3>'
                . '<p>Nachrichten, die an deine Mail Shield-Adressen gesendet werden – Header, Inhalt und Anhänge – werden gespeichert, damit du sie lesen kannst. Personen bei Mail Shield sehen sich deine E-Mails im Rahmen des Betriebs des Dienstes nicht an, und sie werden niemals für Werbung oder Profiling verwendet. Sie werden automatisiert verarbeitet, um sie zu speichern, dir anzuzeigen und an die von dir eingerichteten Integrationen weiterzugeben. Wir greifen nur dann auf eine einzelne Nachricht zu, wenn das nötig ist, um Missbrauch oder einen Sicherheitsvorfall zu untersuchen, um dir zu helfen, wenn du uns darum bittest, oder wenn das Gesetz es verlangt.</p>'
                . '<h3>Verbundene Apps</h3>'
                . '<p>Du kannst eine App oder einen KI-Assistenten mit deinem Konto verbinden: entweder indem du dich anmeldest und die App freigibst, wenn sie danach fragt, oder indem du ein Zugriffstoken erstellst und es der App gibst. In beiden Fällen speichern wir nur einen Einweg-Hash der Zugangsdaten – niemals das Token selbst – zusammen mit dem Zugriff, den du erteilt hast (nur lesen oder lesen und ändern), und dem Zeitpunkt der letzten Verwendung. Ein Token, das du selbst erstellt hast, trägt außerdem den Namen, den du ihm gegeben hast, und das Ablaufdatum, das du gewählt hast.</p>'
                . '<p>Bei einer App, die du auf dem Anmeldebildschirm freigibst, speichern wir den Namen, den sich die App selbst gegeben hat, den Host der Adresse, an die sie zurückgeleitet werden wollte, und den Zeitpunkt, zu dem du sie verbunden hast. Die App liefert diese Angaben bei ihrer Registrierung selbst; sie sind es, die dir die Karte „Verbundene Apps“ anzeigt, damit du Apps voneinander unterscheiden und trennen kannst.</p>'
                . '<h3>Einladungen</h3>'
                . '<p>Wenn du dich über einen Einladungslink registrierst, speichern wir, welches Konto dich eingeladen hat, und den Status der Einladung samt Daten sowie – sobald du Pro kaufst – den Tarif und das Paddle-Abonnement bzw. die Paddle-Transaktion, um die es geht. Der Einladungscode wird in einem Cookie und in deinem neuen Konto gespeichert, bis du deine Adresse bestätigt hast. Die Person, die dich eingeladen hat, sieht nur Zahlen, niemals, wer du bist. Der Eintrag enthält Kontonummern, keine E-Mail-Adressen, und bleibt bestehen, wenn eines der beiden Konten gelöscht wird. Jedes Konto hat außerdem einen eigenen Einladungscode.</p>'
                . '<h3>Technische Daten</h3>'
                . '<p>Unsere Server-Logs erfassen Ereignisse wie Anmeldungen, Fehler und verdächtige Anfragen, jeweils mit der beteiligten IP-Adresse. Anmeldeversuche werden erfasst, um das Erraten von Passwörtern zu verhindern. Deine registrierte E-Mail-Adresse schreiben wir nicht in die Logs. Auf den Seiten für angemeldete Nutzer und im Posteingang wird die Spracheinstellung deines Browsers gelesen, um die Anzeigesprache zu wählen; sie wird nicht gespeichert.</p>'
                . '<p>Um Adressen vor einer Flut von E-Mails und den Dienst vor Missbrauch zu schützen, zählen wir, wie viele Nachrichten und wie viele Bytes jede Adresse erhält, wie viele Adressen von jeder IP-Adresse und jedem Konto aus erstellt werden und wie viele Benachrichtigungen jede Integration versendet. Wird ein Limit erreicht, pausieren wir die Adresse oder die Integration für eine Weile und informieren dich darüber; über diesen Schritt führen wir einen Eintrag.</p>'
                . '<h3>Eintrag zur Testphase</h3>'
                . '<p>Damit jede E-Mail-Adresse die kostenlose Pro-Testphase nur einmal erhält, speichern wir einen Schlüssel-Hash der normalisierten Adresse. Er ist nicht mit deinem Konto verknüpft und lässt sich nicht in die Adresse zurückrechnen.</p>'
                . '<h3>Zahlungen</h3>'
                . '<p>Wenn du Pro kaufst, teilt uns Paddle deine Kunden-ID, die E-Mail-Adresse, mit der du bezahlt hast, sowie den Status deines Abonnements und deiner Transaktionen mit, damit wir deinem Konto Pro freischalten können. Deine Kartendaten erhalten oder speichern wir nie.</p>'
                . '<h3>Support</h3>'
                . '<p>Wenn du uns kontaktierst, speichern wir die Unterhaltung, damit wir dir antworten können.</p>',
        ],
        [
            'id'      => 'purposes',
            'heading' => 'Warum, und auf welcher Rechtsgrundlage',
            'html'    => '<ul>'
                . '<li><strong>Betrieb des Dienstes, für den du dich registriert hast</strong> – dein Konto, deine Adressen, deine E-Mails, deine Integrationen und dein Tarif. Rechtsgrundlage: Erfüllung unseres Vertrags mit dir (Art. 6 Abs. 1 lit. b DSGVO).</li>'
                . '<li><strong>Sicherheit des Dienstes</strong> – Logs, Anmeldelimits, Missbrauchserkennung und der Eintrag zur Testphase. Rechtsgrundlage: unser berechtigtes Interesse, Mail Shield und seine Nutzer vor Missbrauch zu schützen (Art. 6 Abs. 1 lit. f DSGVO).</li>'
                . '<li><strong>Buchhaltung</strong> – Zahlungsbelege. Rechtsgrundlage: unsere rechtliche Verpflichtung nach schwedischem Buchführungsrecht (Art. 6 Abs. 1 lit. c DSGVO).</li>'
                . '<li><strong>Verstehen, wie die öffentlichen Seiten genutzt werden</strong> – Google Analytics, nur nachdem du im Cookie-Hinweis zugestimmt hast. Rechtsgrundlage: deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die wir einholen, bevor ein Analyse-Cookie gesetzt wird; wenn du ablehnst, ändert sich auf der Website sonst nichts.</li>'
                . '<li><strong>Information über dein Konto</strong> – Bestätigung, Anmeldelinks, Sicherheitshinweise, Inaktivitätswarnungen und Änderungen unserer Bedingungen. Das sind Servicemitteilungen, keine Werbung; wir versenden keine Newsletter.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'retention',
            'heading' => 'Wie lange wir es speichern',
            'html'    => '<ul>'
                . '<li><strong>Timed-Adressen und ihre E-Mails</strong> – ' . $f['free_hours'] . ' Stunden bei einem kostenlosen Konto oder die ' . $ttlMinDays . ' bis ' . $ttlMaxDays . ' Tage, die du bei Pro wählst. Danach werden sie automatisch gelöscht.</li>'
                . '<li><strong>Sticky-Adressen</strong> – bis du sie entfernst, oder ' . $graceDays . ' Tage nach dem Ende deines Pro-Tarifs. Ihre E-Mails werden zusammen mit ihnen gelöscht.</li>'
                . '<li><strong>E-Mails, die du länger aufbewahren willst</strong> – ein Pro-Konto kann die Löschung der E-Mails auf seinen Sticky-Adressen pausieren: bis zu ' . $f['hold_max'] . ' Mal pro Jahr verlängert eine Löschpause diese E-Mails auf ' . $f['hold_days'] . ' Tage ab dem Tag ihres Eingangs. Wird eine Löschpause vorzeitig beendet, bleiben die bereits verlängerten E-Mails bestehen; eine Timed-Adresse läuft immer nach ihrem eigenen Zeitplan ab. Das unten genannte Speicherlimit gilt weiterhin.</li>'
                . '<li><strong>Alle E-Mails, bevor sie sonst ablaufen würden</strong> – für dein Konto (oder für jede einzelne Adresse, wenn sie nicht mit einem Konto verknüpft ist) gilt ein Speicherkontingent: ' . $f['quota_free_mb'] . ' MB bei einem kostenlosen Konto und ' . $f['quota_pro_mb'] . ' MB bei Pro, wobei der Pro-Wert auch während der Testphase gilt. Ist das Kontingent voll, werden die ältesten E-Mails in diesem Bereich gelöscht, um Platz zu schaffen, unabhängig davon, auf welcher Adresse sie liegen. Das Kontingent wird beim Eingang einer Nachricht geprüft; ein Konto, das auf „kostenlos“ zurückfällt (weil ein Tarif oder eine Testphase endet), wird daher beim Eintreffen neuer E-Mails, die ältesten zuerst, verkleinert.</li>'
                . '<li><strong>Entfernte Sticky-Adressen</strong> – die Adresse selbst (nicht ihre E-Mails) wird nach dem Entfernen ' . $f['cooldown_months'] . ' Monate lang auf einer Sperrliste geführt, verknüpft mit deiner Kontonummer, damit niemand sonst sie übernehmen und E-Mails empfangen kann, die für dich bestimmt waren. Das gilt auch, nachdem dein Konto gelöscht wurde.</li>'
                . '<li><strong>Dein Konto</strong> – bis du es löschst. Ein kostenloses Regular-Konto, bei dem sich ' . $f['delete_days'] . ' Tage lang niemand anmeldet, wird nach einer Warnung per E-Mail gelöscht.</li>'
                . '<li><strong>Server-Logs</strong> – ' . $f['log_days'] . ' Tage.</li>'
                . '<li><strong>Missbrauchszähler</strong> (Nachrichten pro Adresse, neue Adressen pro IP-Adresse und Konto) – zwei Tage. Der Eintrag über Pausen, Warnungen und andere Schutzmaßnahmen – ' . $abuseEventDays . ' Tage.</li>'
                . '<li><strong>Vertrauenswürdige Browser für die Zwei-Faktor-Authentifizierung</strong> – ' . $trustedDays . ' Tage oder bis du sie entfernst.</li>'
                . '<li><strong>Geräte, auf denen du angemeldet bleibst</strong> (ein Browser- und Betriebssystemname und der Zeitpunkt der letzten Verwendung) – der von dir gewählte Zeitraum, gerechnet ab dem letzten Besuch und nie mehr als ' . $maxSignedInDays . ' Tage nach deiner Anmeldung, oder bis du das Gerät entfernst, dich darauf abmeldest oder dein Passwort bzw. deine E-Mail-Adresse änderst.</li>'
                . '<li><strong>Zugriffstoken, die du selbst erstellt hast</strong> – bis du sie widerrufst oder bis zum von dir gewählten Ablaufdatum. Ein widerrufenes oder abgelaufenes Token wird ' . $tokenSweepDays . ' Tage später gelöscht. Wenn du dein Passwort oder deine E-Mail-Adresse änderst oder dein Konto löschst, werden alle auf einmal widerrufen.</li>'
                . '<li><strong>Apps, die du per Anmeldung verbunden hast</strong> – bis du sie trennst, oder ' . $f['grant_idle_days'] . ' Tage nachdem die App ihren Zugriff zuletzt genutzt hat, je nachdem, was zuerst eintritt. Eine Anmeldung, die du begonnen, aber nie freigegeben hast, verfällt nach ' . $f['code_seconds'] . ' Sekunden, und eine App, die sich selbst registriert hat und nie benutzt wurde, wird nach ' . $f['client_idle_days'] . ' Tagen entfernt. Das Trennen einer App, die Änderung deines Passworts oder deiner E-Mail-Adresse oder die Löschung deines Kontos beendet diese sofort.</li>'
                . '<li><strong>Eintrag zur Testphase</strong> – fünf Jahre ab dem ersten Mal, dass die Adresse verwendet wurde.</li>'
                . '<li><strong>Zahlungsbelege</strong> – sieben Jahre, wie es das schwedische Buchführungsrecht verlangt.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'sharing',
            'heading' => 'Wer deine Daten sonst noch verarbeitet',
            'html'    => '<p>Wir verkaufen deine Daten nicht und geben sie nicht an Werbetreibende weiter. Sie werden von diesen Stellen verarbeitet, und zwar nur aus den genannten Gründen:</p>'
                . '<ul>'
                . '<li><strong>Unser Hosting-Anbieter, ' . $f['hosting'] . '</strong>, der die Server betreibt, die deine E-Mails empfangen, speichern und ausliefern, als unser Auftragsverarbeiter auf Grundlage eines Auftragsverarbeitungsvertrags. Dein Konto und deine E-Mails werden in Schweden gespeichert.</li>'
                . '<li><strong>Paddle</strong> (Paddle.com Market Ltd) für Zahlungen – siehe unten.</li>'
                . '<li><strong>Google</strong> (Google Ireland Ltd) für Google Analytics auf unseren öffentlichen Seiten, wenn du der Analyse zustimmst.</li>'
                . '<li><strong>Die Ziele, die du auswählst</strong>, wenn du eine Integration einrichtest:'
                . '<ul>'
                . '<li><strong>Webhook:</strong> der Absender, die Empfängeradresse, der Betreff, der vollständige Nachrichtentext und der Zeitpunkt des Eingangs. Anhänge werden nicht gesendet, nur ihre Anzahl.</li>'
                . '<li><strong>Pushover:</strong> der Betreff und ein Klartext-Auszug des Textes (bis zu etwa ' . $pushoverChars . ' Zeichen), mit der Empfängeradresse als Titel, gesendet an Pushover und weiter an deine Geräte.</li>'
                . '<li><strong>RSS-Feed:</strong> der Absender, der Betreff, das Datum und der vollständige Nachrichtentext, mit Links zu den Anhängen, abrufbar für alle, die den Feed-Link haben.</li>'
                . '<li><strong>Eine App oder ein KI-Assistent, die bzw. den du verbindest</strong> – egal, ob du sie auf dem Anmeldebildschirm freigegeben oder ihr ein Zugriffstoken gegeben hast: deine Adressen und die E-Mails, die sie anfordert – Absender, Betreff, Text und Anhang-Links. Anders als bei den oben genannten Integrationen ruft die App sie selbst ab, und was sie damit macht, regelst du mit demjenigen, der sie betreibt.</li>'
                . '</ul>'
                . 'Diese Dienste wählst du selbst aus, und sie behandeln die Daten nach ihren eigenen Bedingungen. Ein KI-Assistent, den du verbindest, ist keine Ausnahme: Sein Anbieter ist dir gegenüber verantwortlich, nicht uns gegenüber, und ist nicht unser Auftragsverarbeiter.</li>'
                . '<li><strong>Behörden</strong>, wenn das Gesetz uns zur Offenlegung von Daten verpflichtet.</li>'
                . '</ul>'
                . '<p>Eine E-Mail-Zusammenfassung wird an niemand anderen weitergegeben: Sie listet Absender, Betreff und Zeit deiner wartenden Nachrichten auf und geht an deine eigene registrierte Adresse.</p>',
        ],
        [
            'id'      => 'automated-limits',
            'heading' => 'Automatische Limits',
            'html'    => '<p>Automatische Limits schützen Adressen und den Dienst: Wird eines erreicht, wird eine Adresse oder Integration für eine Weile pausiert, und du wirst informiert. Diese Pausen sind vorübergehend. Ob ein Konto gesperrt wird, entscheidet immer ein Mensch. Wir treffen keine Entscheidungen, die ausschließlich auf einer automatisierten Verarbeitung beruhen und dir gegenüber rechtliche Wirkung entfalten oder dich in ähnlicher Weise erheblich beeinträchtigen (Art. 22 DSGVO). Wenn du meinst, dass dich ein Limit zu Unrecht getroffen hat, <a href="#controller">kontaktiere uns</a>.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Zahlungen werden von Paddle verarbeitet',
            'html'    => '<p>Unsere Bestellabwicklung erfolgt über unseren Online-Wiederverkäufer Paddle.com. Paddle.com ist der Merchant of Record für alle unsere Bestellungen. Beim Bezahlen gibst du deine Zahlungsdaten, deinen Namen, dein Land und deine E-Mail-Adresse an Paddle weiter, nicht an uns, und Paddle verarbeitet sie als eigenständig Verantwortlicher gemäß der <a href="https://www.paddle.com/legal/privacy" rel="noopener">Datenschutzerklärung von Paddle</a>.</p>'
                . '<p>Die Preisseite und der Tarifbereich der Startseite laden das Skript von Paddle, um Preise in deiner Währung anzuzeigen, und der Checkout nutzt es, um sich zu öffnen. Beim Laden nimmt dein Browser Kontakt zu Paddle auf, das dabei deine IP-Adresse sieht; Paddle kann dort eigene Cookies setzen.</p>',
        ],
        [
            'id'      => 'cookies',
            'heading' => 'Cookies',
            'html'    => '<ul>'
                . '<li><strong>Ein Sitzungs-Cookie</strong>, das dich angemeldet hält. Es ist für den Dienst erforderlich und endet, wenn du dich abmeldest oder die Sitzung schließt.</li>'
                . '<li><strong>Ein Cookie für vertrauenswürdige Browser</strong>, das nur gesetzt wird, wenn du dich dafür entscheidest, dass ein Browser für die Zwei-Faktor-Authentifizierung gemerkt wird. Es gilt ' . $trustedDays . ' Tage.</li>'
                . '<li><strong>Ein Cookie zum Angemeldetbleiben</strong>, das nur gesetzt wird, wenn du dich dafür entscheidest, auf einem Gerät angemeldet zu bleiben. Es gilt für den von dir gewählten Zeitraum (' . $stayDays[0] . ', ' . $stayDays[1] . ' oder ' . $stayDays[2] . ' Tage ab deinem letzten Besuch, höchstens ' . $maxSignedInDays . ' Tage) und wird entfernt, wenn du dich abmeldest.</li>'
                . '<li><strong>Google-Analytics-Cookies</strong>, die nur auf den öffentlichen Seiten gesetzt werden (nie im Posteingang, in deinem Konto oder auf den Anmeldeseiten) und nur, nachdem du im Cookie-Hinweis auf „Akzeptieren“ geklickt hast. Über „Cookie-Einstellungen“ in der Fußzeile kannst du deine Einwilligung widerrufen; dann werden diese Cookies auf späteren Seiten nicht mehr gesetzt, und bereits gesetzte werden gelöscht.</li>'
                . '<li><strong>Ein Einladungs-Cookie, <code>ms_ref</code></strong>, das nur gesetzt wird, wenn du einem Einladungslink folgst, während das Einladungsprogramm geöffnet ist. Es enthält den Einladungscode, gilt bis zu ' . $ref['cookie_days'] . ' Tage und wird entfernt, wenn du dein Konto bestätigst.</li>'
                . '<li><strong>Paddle-Cookies</strong> auf der Startseite, der Preisseite und im Checkout.</li>'
                . '<li><strong>Deine Cookie-Auswahl</strong> selbst merken wir uns im lokalen Speicher deines Browsers, nicht in einem Cookie, damit wir sie berücksichtigen können; sie ist unbedingt erforderlich und wird nicht an uns gesendet.</li>'
                . '</ul>'
                . '<p>Du kannst Cookies in deinem Browser blockieren oder löschen. Wenn du das Sitzungs-Cookie blockierst, kannst du dich nicht anmelden. Für die Sprache wird kein Cookie verwendet.</p>',
        ],
        [
            'id'      => 'transfers',
            'heading' => 'Übermittlungen außerhalb der EU',
            'html'    => '<p>Mail Shield selbst wird in Schweden gehostet, und deine E-Mails werden dort gespeichert. Nur Google und Paddle verarbeiten Daten außerhalb der EU und des EWR, unter anderem in den USA und im Vereinigten Königreich. Wo sie das tun, ist die Übermittlung durch einen Angemessenheitsbeschluss abgesichert – etwa das EU-US Data Privacy Framework oder den Beschluss für das Vereinigte Königreich – oder durch die Standardvertragsklauseln der EU.</p>',
        ],
        [
            'id'      => 'security',
            'heading' => 'Wie wir sie schützen',
            'html'    => '<p>Verbindungen zu Mail Shield sind verschlüsselt. Deine registrierte Adresse, Geheimnisse der Zwei-Faktor-Authentifizierung und die Zugangsdaten deiner Integrationen – Webhook-Geheimnisse und -Header, Pushover-Schlüssel und Feed-Links – sind im gespeicherten Zustand verschlüsselt; Passwörter und die Zugriffstoken für verbundene Apps werden als Hashes statt im Klartext gespeichert. Anhänge werden nur über signierte Links ausgeliefert, die ablaufen. Der Zugriff auf Produktionsdaten ist auf die Personen beschränkt, die den Dienst betreiben.</p>'
                . '<p>Bedenke, dass E-Mail an sich meist nicht Ende-zu-Ende-verschlüsselt ist und dass ein geteilter Posteingangs-Link von jedem gelesen werden kann, der ihn hat.</p>',
        ],
        [
            'id'      => 'rights',
            'heading' => 'Deine Rechte',
            'html'    => '<p>Nach der DSGVO hast du das Recht auf Auskunft über die personenbezogenen Daten, die wir über dich gespeichert haben, auf Berichtigung oder Löschung, auf Einschränkung der Verarbeitung, auf Widerspruch gegen die Verarbeitung und auf Datenübertragbarkeit in einem gängigen Format. Wo wir uns auf deine Einwilligung stützen, kannst du sie jederzeit widerrufen. Wo wir uns auf unser berechtigtes Interesse stützen, hast du das Recht, dieser Verarbeitung zu widersprechen.</p>'
                . '<p>Einige dieser Rechte unterliegen Einschränkungen. Zahlungsbelege werden nach schwedischem Buchführungsrecht sieben Jahre lang aufbewahrt, auch nachdem dein Konto gelöscht wurde, und Daten, die zur Bearbeitung eines Sicherheitsvorfalls oder eines Rechtsanspruchs nötig sind, können so lange gespeichert werden, wie dieser andauert.</p>'
                . '<p>Das Meiste kannst du selbst erledigen: Dein Profil zeigt deine Kontodaten, und wenn du dein Konto löschst, werden sie entfernt, außer dem, was wir für die Buchhaltung aufbewahren müssen. Für alles andere schreibe an ' . $f['contact'] . '. Wir antworten innerhalb eines Monats.</p>'
                . '<p>Wenn du meinst, dass wir deine Daten fehlerhaft verarbeiten, kannst du dich bei der schwedischen Datenschutzbehörde (Integritetsskyddsmyndigheten, <a href="https://www.imy.se" rel="noopener">imy.se</a>) oder bei der Aufsichtsbehörde an deinem Wohnort beschweren.</p>',
        ],
        [
            'id'      => 'children',
            'heading' => 'Kinder',
            'html'    => '<p>Mail Shield ist nicht für Kinder unter ' . $minAge . ' Jahren gedacht, und wir erheben wissentlich keine Daten von ihnen.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Änderungen dieser Erklärung',
            'html'    => '<p>Wenn sich diese Erklärung ändert, aktualisieren wir das Datum ganz oben. Bei einer wesentlichen Änderung informieren wir dich per E-Mail, bevor sie in Kraft tritt.</p>',
        ],
    ],
];
