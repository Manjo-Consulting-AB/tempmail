<?php
/**
 * The terms of service in German: the whole document as data, rendered by
 * partials/legal_page.php. Mirrors terms.en.php: the same section ids in the
 * same order, the same conditional branches (trial on/off), the same facts as
 * variables and the same 'updated' date; only the words differ. It states no
 * clause on which language prevails (epic #441 decision 2). A DRAFT for legal
 * review. Glossary: the German glossary (informal "du").
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
    ? '<p>Jedes neue Konto beginnt mit einer kostenlosen Pro-Testphase von ' . $f['trial_days'] . ' Tagen, einmal pro E-Mail-Adresse. Für die Testphase sind keine Zahlungsdaten nötig, und sie geht nicht von selbst in einen kostenpflichtigen Tarif über: Wenn sie endet, läuft das Konto als kostenloses Regular-Konto weiter, es sei denn, du kaufst Pro.</p>'
    : '';

return [
    'title'       => 'AGB',
    'lede'        => 'Diese Bedingungen sind die Vereinbarung zwischen dir und uns, wenn du Mail Shield nutzt. Sie sind zum Lesen geschrieben und deshalb dort kurz, wo es geht.',
    'description' => 'Die Bedingungen, die gelten, wenn du Mail Shield nutzt, einschließlich der Pro-Tarife, die über Paddle als Merchant of Record verkauft werden.',
    'path'        => '/terms.php',
    'updated'     => '2026-10-10',
    'sections'    => [
        [
            'id'      => 'who',
            'heading' => 'Wer wir sind',
            'html'    => '<p>Mail Shield wird betrieben von ' . $f['company_full'] . ' („wir“, „uns“). Du erreichst uns unter ' . $f['contact'] . '.</p>'
                . '<p>Mit dem Erstellen eines Kontos oder der Nutzung des Dienstes akzeptierst du diese Bedingungen. Wenn du sie nicht akzeptierst, nutze Mail Shield bitte nicht.</p>',
        ],
        [
            'id'      => 'service',
            'heading' => 'Der Dienst',
            'html'    => '<p>Mail Shield ist eine zweite E-Mail-Umgebung auf der Domain ' . $f['domain'] . '. Sie empfängt E-Mails für dich; sie ist kein Dienst zum Versenden von E-Mails. Je nach Tarif kannst du:</p>'
                . '<ul>'
                . '<li>Timed-Adressen erstellen, die bei einem kostenlosen Konto ' . $f['free_hours'] . ' Stunden lang und mit Pro ' . $proTtlMinDays . ' bis ' . $proTtlMaxDays . ' Tage lang E-Mails empfangen;</li>'
                . '<li>bis zu 10 Sticky-Adressen behalten (nur Pro);</li>'
                . '<li>eingehende Nachrichten an andere Systeme weiterleiten – RSS, Webhooks, Pushover und E-Mail-Zusammenfassungen (nur Pro).</li>'
                . '</ul>'
                . '<p>Ein kostenloses Konto hat eine Speicherquote von ' . $f['quota_free_mb'] . ' MB, ein Pro-Konto eine von ' . $f['quota_pro_mb'] . ' MB; die Pro-Quote gilt ab dem ersten Tag der Testphase, weil ein Testkonto ein Pro-Konto ist. E-Mails an eine Adresse, die nicht mit einem Konto verknüpft ist, haben eigene ' . $f['quota_free_mb'] . ' MB. Ist eine Quote erreicht, werden die ältesten Nachrichten darin automatisch gelöscht, um Platz für neue zu schaffen, auch wenn die Adresse, an die sie gingen, noch nicht abgelaufen ist.</p>'
                . '<p>Maßgeblich ist die Quote, die gilt, wenn eine Nachricht eintrifft. Endet ein Pro-Tarif und liegt das Konto dann über der kostenlosen Quote, wird es mit neu eingehender Post auf diese Quote zurückgeschnitten – die ältesten Nachrichten zuerst.</p>'
                . '<p>Adressen und Integrationen haben Nutzungsgrenzen, die sie und den Dienst schützen. Wird eine Grenze erreicht, wird die Adresse oder Integration automatisch für eine Weile pausiert, und du wirst benachrichtigt; siehe <a href="' . $msU('/privacy.php#automated-limits') . '">Automatische Grenzen</a>.</p>'
                . '<p>E-Mails werden automatisch gelöscht, wenn eine Adresse abläuft oder entfernt wird. Mail Shield ist kein Archiv: Bewahre eine Kopie von allem, was du behalten musst, an anderer Stelle auf.</p>',
        ],
        [
            'id'      => 'account',
            'heading' => 'Dein Konto',
            'html'    => '<p>Du brauchst ein Konto, um Mail Shield zu nutzen. Du musst eine echte E-Mail-Adresse angeben, die du kontrollierst, dein Passwort für dich behalten und uns Bescheid geben, wenn du glaubst, dass jemand anderes Zugriff auf dein Konto hat. Du bist für das verantwortlich, was unter deinem Konto geschieht.</p>'
                . '<p>Du musst mindestens 16 Jahre alt sein oder das Alter für die digitale Einwilligung an deinem Wohnort erreicht haben, falls dieses höher ist.</p>'
                . '<p>Ein kostenloses Regular-Konto, bei dem sich ' . $f['delete_days'] . ' Tage lang niemand angemeldet hat, wird zusammen mit seinen Adressen und E-Mails gelöscht. Wir senden ' . ($f['delete_days'] - $f['warn_days']) . ' Tage vorher eine Warnung per E-Mail. Pro-Konten werden nicht wegen Inaktivität gelöscht.</p>'
                . '<p>Du kannst dein Konto jederzeit in deinem Profil löschen. Die Löschung entfernt deine Adressen und deine E-Mails.</p>',
        ],
        [
            'id'      => 'acceptable-use',
            'heading' => 'Zulässige Nutzung',
            'html'    => '<p>Du darfst Mail Shield nicht nutzen, um:</p>'
                . '<ul>'
                . '<li>gegen das Gesetz zu verstoßen oder jemand anderem dabei zu helfen;</li>'
                . '<li>Betrug zu begehen, dich als jemand anderes auszugeben oder Sperren, Beschränkungen oder Verifizierungen auf eine Weise zu umgehen, die dem Dienst schadet, bei dem du die Adresse verwendest;</li>'
                . '<li>eine Adresse zu nutzen, um Passwörter, Zahlungsdaten oder andere Daten zu sammeln, die durch Phishing, Schadsoftware oder andere Täuschung erlangt wurden, oder um die Ergebnisse solcher Machenschaften zu empfangen;</li>'
                . '<li>Adressen oder Konten massenhaft anzulegen, um anderswo Spam, Betrug oder Operationen mit gefälschten Konten zu betreiben;</li>'
                . '<li>Material zu empfangen, dessen Besitz rechtswidrig ist, darunter Darstellungen sexuellen Kindesmissbrauchs;</li>'
                . '<li>Mail Shield oder seine Infrastruktur anzugreifen, auszuspähen oder zu überlasten oder seine Grenzen zu umgehen;</li>'
                . '<li>den Dienst ohne unsere schriftliche Erlaubnis als dein eigenes Produkt weiterzuverkaufen oder Zugang dazu zu gewähren.</li>'
                . '</ul>'
                . '<p>Wir dürfen ein Konto, das gegen diese Regeln verstößt, sperren oder schließen und damit verbundene Inhalte entfernen. Soweit das Gesetz es verlangt, arbeiten wir mit den Behörden zusammen.</p>',
        ],
        [
            'id'      => 'suitability',
            'heading' => 'Wofür Mail Shield nicht gedacht ist',
            'html'    => '<p>Nutze eine Timed-Adresse nicht für etwas, das du behalten oder wiederherstellen können musst – Bank, Behördendienste oder ein Konto, bei dem eine Passwortzurücksetzung dorthin gesendet würde. Sobald die Adresse abgelaufen ist, endet auch dein Zugriff auf diese E-Mails.</p>'
                . '<p>Eine Timed-Adresse, die über einen geteilten Link erreichbar ist, kann von jedem gelesen werden, der den Link hat. Sieh sie als Ort zum Empfangen von E-Mails, nicht als Ort, um ein Geheimnis aufzubewahren.</p>'
                . '<p>Wir können nicht garantieren, dass jede an dich gesendete Nachricht ankommt. Absender können E-Mails verzögern, ablehnen oder filtern, bevor sie uns erreichen.</p>',
        ],
        [
            'id'      => 'plans',
            'heading' => 'Tarife, Testphase und Gutscheincodes',
            'html'    => '<p>Mail Shield hat einen kostenlosen Regular-Tarif und einen kostenpflichtigen Pro-Tarif. Pro kann als Monats- oder Jahresabonnement oder einmalig als Lifetime gekauft werden. Die Pro-Funktionen sind die, die zum Zeitpunkt deines Kaufs auf der <a href="' . $msU('/pricing.php') . '">Preisseite</a> beschrieben sind.</p>'
                . $trialLine
                . '<p>Pro-Zeit kann auch mit einem Gutscheincode gewährt werden. Ein Gutschein hat keinen Barwert und kann nicht gegen Geld eingetauscht werden.</p>'
                . '<p>„Lifetime“ bedeutet: so lange, wie wir Mail Shield betreiben – nicht auf Lebenszeit von dir oder von uns. Sollten wir den Dienst jemals einstellen, sagen wir dir vorher Bescheid, wie unter <a href="#termination">Beendigung des Dienstes</a> beschrieben.</p>'
                . '<p>Wenn Pro endet, wird das Konto zu einem kostenlosen Regular-Konto. Seine Sticky-Adressen werden ' . $graceDays . ' Tage später samt ihren E-Mails gelöscht, und seine Integrationen stoppen.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Zahlungen und Paddle',
            'html'    => '<p>Unsere Bestellabwicklung erfolgt über unseren Online-Wiederverkäufer Paddle.com. Paddle.com ist der Merchant of Record für alle unsere Bestellungen. Paddle übernimmt alle Kundenserviceanfragen und bearbeitet Rückgaben.</p>'
                . '<p>Das bedeutet: Der Kauf selbst ist ein Vertrag zwischen dir und Paddle, auf Grundlage der <a href="https://www.paddle.com/legal/checkout-buyer-terms" rel="noopener">Käuferbedingungen von Paddle</a>. Paddle zieht die Zahlung ein, erhebt und führt die an deinem Standort anfallende Umsatzsteuer oder Mehrwertsteuer ab und schickt dir die Quittung. Die Preise werden in deiner Landeswährung einschließlich dieser Steuer angezeigt.</p>'
                . '<h3>Abonnements</h3>'
                . '<p>Ein Monats- oder Jahresabonnement verlängert sich am Ende jedes Zeitraums automatisch zum dann geltenden Preis, bis du es kündigst. Du kannst jederzeit über <strong>Manage billing</strong> in deinem Profil oder über Paddle kündigen. Nach der Kündigung behältst du Pro bis zum Ende des bezahlten Zeitraums; danach erfolgt keine Abbuchung mehr.</p>'
                . '<p>Ändern wir den Preis eines Abonnements, informieren wir dich per E-Mail, bevor die Änderung für deine nächste Verlängerung gilt, sodass du vorher kündigen kannst.</p>'
                . '<h3>Rückerstattungen</h3>'
                . '<p>Du kannst innerhalb von ' . $guaranteeDays . ' Tagen nach einer Zahlung dein Geld zurückbekommen. Die Einzelheiten stehen in unserer <a href="' . $msU('/refund-policy.php') . '">Rückerstattungsrichtlinie</a>.</p>',
        ],
        [
            'id'      => 'referrals',
            'heading' => 'Einladungen',
            'html'    => '<p>Solange das Einladungsprogramm offen ist, hat jedes Konto einen persönlichen Einladungslink, der in deinem Profil angezeigt wird. Die Regeln sind:</p>'
                . '<ul>'
                . '<li>Ein Freund, der über deinen Link ein <strong>neues</strong> Konto erstellt und dessen E-Mail-Adresse noch nie mit Mail Shield verwendet wurde, wird dir zugeordnet. Die Zuordnung erfolgt einmalig bei der ersten Verifizierung des Kontos und kann danach nicht mehr geändert werden.</li>'
                . '<li>Kauft dieser Freund innerhalb von ' . $ref['window_days'] . ' Tagen nach Erstellung des Kontos einen Pro-Tarif über ' . $yearPlanMonths . ' Monate, erhält er ' . $ref['bonus_months'] . ' zusätzliche Monate: Seine nächste Zahlung wird um ' . $ref['bonus_months'] . ' Monate nach hinten verschoben. Ist sein Abonnement stattdessen auf Beendigung gestellt, werden die Monate seiner Pro-Zeit hinzugefügt.</li>'
                . '<li>Du erhältst ' . $ref['bonus_months'] . ' Monate Pro ' . $ref['hold_days'] . ' Tage nach der Zahlung des Freundes, sofern sie bis dahin nicht erstattet oder zurückgebucht wurde. Zahlst du per Abonnement, verschiebt sich deine nächste Zahlung um ' . $ref['bonus_months'] . ' Monate nach hinten. Andernfalls werden die Monate zu deinem aktuellen Enddatum addiert oder, falls dieses vorbei ist, zum heutigen Datum. Hast du Lifetime, erhältst du stattdessen ' . $ref['sticky_bonus'] . ' weitere Sticky-Adressen zusätzlich zum üblichen Limit, solange das Konto Pro ist.</li>'
                . '<li>Ein Lifetime-Kauf des Freundes zählt ebenfalls für dich, gibt dem Freund aber nichts extra.</li>'
                . '<li>Die Zahl der Einladungen ist nicht begrenzt, und Belohnungen summieren sich. Belohnungen haben keinen Barwert und sind nicht übertragbar.</li>'
                . '<li>Sich selbst einzuladen oder mehrere Konten oder Zahlungsdaten zu nutzen, um Belohnungen zu sammeln, ist nicht erlaubt. Auf diese Weise erlangte Belohnungen dürfen wir zurückhalten oder entfernen.</li>'
                . '<li>Wir dürfen das Programm ändern oder beenden. Bereits gewährte Belohnungen bleiben bestehen.</li>'
                . '</ul>'
                . '<p>Was mit einer Belohnung geschieht, wenn eine Zahlung erstattet wird, steht in der <a href="' . $msU('/refund-policy.php#after') . '">Rückerstattungsrichtlinie</a>; was wir zu einer Einladung speichern, steht in der <a href="' . $msU('/privacy.php#data') . '">Datenschutzerklärung</a>.</p>',
        ],
        [
            'id'      => 'termination',
            'heading' => 'Beendigung des Dienstes',
            'html'    => '<p>Du kannst Mail Shield jederzeit nicht mehr nutzen und dein Konto löschen. Ein Abonnement zu kündigen ist ein eigener Schritt, der über Manage billing erfolgt; das Löschen des Kontos kündigt es nicht von selbst.</p>'
                . '<p>Wir können deine Nutzung von Mail Shield auf drei verschiedene Arten beenden oder einschränken:</p>'
                . '<ul>'
                . '<li><strong>Sofortige Sperrung.</strong> Wenn wir Missbrauch oder ein Risiko für die Sicherheit von Mail Shield oder seiner Nutzer feststellen, dürfen wir ein Konto sofort und ohne Vorankündigung sperren, während wir den Fall prüfen.</li>'
                . '<li><strong>Schließung nach einem Verstoß.</strong> Wenn du gegen diese Bedingungen verstößt, dürfen wir dein Konto schließen.</li>'
                . '<li><strong>Unsere eigene Entscheidung.</strong> Beenden wir dein Konto aus einem anderen Grund oder stellen wir Mail Shield ganz ein, geben wir mindestens ' . $noticeDays . ' Tage Vorlaufzeit per E-Mail und erstatten den nicht genutzten Teil jedes von dir bezahlten Abonnementzeitraums. Bei einem Lifetime-Kauf, der weniger als zwei Jahre vor der Einstellung des Dienstes erfolgte, erstatten wir einen Anteil des Preises im Verhältnis zu dem Teil dieser zwei Jahre, der noch übrig ist.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'transfer',
            'heading' => 'Übertragung des Dienstes',
            'html'    => '<p>Wir dürfen Mail Shield und diese Bedingungen auf einen anderen Betreiber übertragen, der den Dienst übernimmt. Wir informieren dich vorher per E-Mail. Deine Rechte, Lifetime eingeschlossen, bestehen gegenüber dem neuen Betreiber fort, und wenn du die Übertragung nicht akzeptierst, kannst du dein Konto löschen.</p>',
        ],
        [
            'id'      => 'liability',
            'heading' => 'Gewährleistung und Haftung',
            'html'    => '<p>Wir bemühen uns, Mail Shield verfügbar und deine Post sicher zu halten, aber der Dienst wird so bereitgestellt, wie er ist, und wir versprechen nicht, dass er unterbrechungs- oder fehlerfrei ist.</p>'
                . '<p>Soweit das Gesetz es zulässt, haften wir nicht für mittelbare Schäden oder Folgeschäden, etwa entgangenen Gewinn, verlorene Daten oder verlorenen Zugriff auf ein Konto andernorts, und unsere Gesamthaftung dir gegenüber ist auf das begrenzt, was du in den ' . $liabilityMonths . ' Monaten vor Entstehen des Anspruchs für Mail Shield bezahlt hast.</p>'
                . '<p>Nichts in diesen Bedingungen schränkt Rechte ein, die dir als Verbraucher nach zwingendem Recht zustehen, oder unsere Haftung für grobe Fahrlässigkeit oder Vorsatz.</p>',
        ],
        [
            'id'      => 'privacy',
            'heading' => 'Datenschutz',
            'html'    => '<p>Wie wir mit personenbezogenen Daten umgehen, einschließlich der E-Mails, die du empfängst, ist in unserer <a href="' . $msU('/privacy.php') . '">Datenschutzerklärung</a> beschrieben.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Änderungen dieser Bedingungen',
            'html'    => '<p>Wir dürfen diese Bedingungen aktualisieren. Das Datum oben zeigt, wann sie zuletzt geändert wurden. Wirkt sich eine Änderung wesentlich auf deine Rechte aus, schreiben wir dir mindestens ' . $noticeDays . ' Tage, bevor sie in Kraft tritt, eine E-Mail. Wenn du Mail Shield danach weiter nutzt, gelten die neuen Bedingungen; wenn du sie nicht akzeptierst, kannst du vorher dein Konto löschen und dein Abonnement kündigen.</p>',
        ],
        [
            'id'      => 'law',
            'heading' => 'Anwendbares Recht und Streitigkeiten',
            'html'    => '<p>Diese Bedingungen unterliegen schwedischem Recht. Streitigkeiten werden von den schwedischen Gerichten entschieden, mit dem Amtsgericht Stockholm (Stockholms tingsrätt) als erster Instanz, sofern dir nicht zwingendes Verbraucherrecht das Recht gibt, an deinem Wohnort zu klagen.</p>'
                . '<p>Wenn du Verbraucher in der EU bist, kannst du dich auch an die schwedische Verbraucherschlichtungsstelle (Allmänna reklamationsnämnden, <a href="https://www.arn.se" rel="noopener">arn.se</a>) wenden. Bitte kontaktiere uns zuerst unter ' . $f['contact'] . ' – die meisten Dinge lassen sich direkt schneller klären.</p>',
        ],
    ],
];
