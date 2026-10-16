<?php
/**
 * Die Seite zu temporären E-Mail-Adressen auf Deutsch. Geschrieben rund um die
 * deutschen Suchbegriffe (primär: temporäre E-Mail-Adresse, Wegwerf-E-Mail),
 * sagt aber exakt dasselbe wie temporary-email.en.php: dieselben Abschnitts-IDs
 * in derselben Reihenfolge, derselbe Bedingungszweig für die Testphase und
 * dieselben Variablen. Der Text darf nichts behaupten, was die englische Seite
 * nicht behauptet (brief §9). Zahlen und die Domain stehen hier nie fest,
 * sondern kommen aus den Variablen, die temporary-email.php definiert und die
 * im Docblock von temporary-email.en.php aufgelistet sind. Glossar: Sticky und
 * Timed bleiben Produktnamen, Anrede mit „du“.
 *
 * Die Abschnitts-IDs sind in allen Sprachen englisch, damit die #-Anker gleich
 * funktionieren.
 *
 * @return array{title: string, description: string, headline: string, lede: string, toc_label: string, toc_eyebrow: string, sections: list<array{id: string, heading: string, html: string}>, cta: array{after: string, line: string, end_line: string}}
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

return [
    'title'       => 'Temporäre E-Mail-Adresse – kostenlose Wegwerf-E-Mail · Mail Shield',
    'description' => 'Eine temporäre E-Mail-Adresse für Registrierungen, Downloads und Dienste, die du nur kurz brauchst. Lies die Mails in deinem Mail-Shield-Posteingang und lass sie automatisch aufräumen.',
    'headline'    => 'Temporäre E-Mail-Adresse',
    'lede'        => 'Nicht jede Registrierung ist deine echte Adresse wert. Eine temporäre E-Mail-Adresse gibt dir stattdessen eine Wegwerfadresse – lies sie in einem richtigen Web-Posteingang und lass Mail Shield für dich aufräumen.',
    'toc_label'   => 'Auf dieser Seite',
    'toc_eyebrow' => 'Auf dieser Seite',
    'sections'    => [
        [
            'id'      => 'what',
            'heading' => 'Was ist eine temporäre E-Mail-Adresse?',
            'html'    => <<<HTML
<p>Eine temporäre E-Mail-Adresse existiert eine kurze Zeit und hört dann auf zu existieren. Du nutzt sie überall dort, wo du die Adresse, die du tatsächlich liest, lieber nicht herausgibst – eine kostenlose Testphase, ein Download, ein Forum – und alles, was an sie geschickt wird, landet in einem Web-Posteingang, den du sofort öffnen kannst, sodass du E-Mails online empfangen kannst.</p>
<p>Ein anderes Wort dafür ist Wegwerf-E-Mail oder Einweg-E-Mail. Die Adresse ist zum Wegwerfen gedacht, sie muss also nie einprägsam oder ordentlich sein. Das ist der Punkt: Dein Haupt-Posteingang behält die E-Mails, die wichtig sind, und der Rest landet an einem Ort, um den du dich nicht kümmern musst.</p>
<p>In Mail Shield heißt sie <strong>Timed-Adresse</strong>: eine Adresse, die auf einem Timer läuft und sich samt allem darin selbst löscht, wenn die Zeit abgelaufen ist.</p>
HTML,
        ],
        [
            'id'      => 'get',
            'heading' => 'So bekommst du eine temporäre E-Mail-Adresse',
            'html'    => <<<HTML
<p>Auf dieser Seite gibt es keinen Button, der dir eine Adresse ausgibt, und das ist Absicht – ein Mail-Shield-Posteingang braucht ein Konto dahinter.</p>
<ol>
    <li>Registriere dich mit deiner eigenen E-Mail-Adresse und einem Passwort. Sie wird genutzt, um das Konto zu bestätigen und dich dazu zu erreichen, sonst für nichts.</li>
    <li>Bestätige sie und melde dich dann an.</li>
    <li>Erstelle in deinem Posteingang eine Timed-Adresse. Sie ist bereit, dort eingefügt zu werden, wo du dich registrierst.</li>
</ol>
<p>Die zusätzliche Minute bringt dir etwas, das ein Wegwerf-Generator nicht bieten kann. Die Adresse liegt in einem echten Posteingang, der erhalten bleibt, sodass du für eine Bestätigung zurückkommen kannst, die eine Stunde später eintrifft, oder die Nachricht findest, die erklärt, wie du kündigst. Eine Seite, die eine Adresse ausgibt und dich vergisst, sobald du den Tab schließt, kann beides nicht.</p>
HTML,
        ],
        [
            'id'      => 'lifetime',
            'heading' => 'Wie lange besteht eine temporäre Adresse?',
            'html'    => <<<HTML
<p>Das hängt von deinem Konto ab, und beide Angaben sind exakt.</p>

<dl class="ms-doc__retention">
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Kostenlos</dt>
        <dd class="ms-doc__tier-value">{$msDocFreeHours} Stunden</dd>
    </div>
    <div class="ms-doc__tier">
        <dt class="ms-eyebrow">Pro</dt>
        <dd class="ms-doc__tier-value">{$msDocProMinDays}&ndash;{$msDocProMaxDays} Tage</dd>
    </div>
</dl>

<p>Ein kostenloses Konto behält eine Timed-Adresse {$msDocFreeHours} Stunden. Mit Pro wählst du für jede Adresse eine Lebensdauer von {$msDocProMinDays} bis {$msDocProMaxDays} Tagen. Wenn die Zeit abgelaufen ist, werden die Adresse und alles darin automatisch gelöscht.</p>
HTML,
        ],
        [
            'id'      => 'when-to-use',
            'heading' => 'Wann passt eine temporäre E-Mail-Adresse?',
            'html'    => <<<HTML
<p>Temporäre E-Mail passt am besten, wenn die Adresse das Einzige ist, bei dem du unsicher bist.</p>
<ul>
    <li>Kostenlose Testphasen für Tools, die du vielleicht nie wieder öffnest.</li>
    <li>Ein einmaliger Download, der vor dem Start eine Adresse verlangt.</li>
    <li>Registrierungen in Foren und Communitys.</li>
    <li>Anzeigen auf Marktplätzen und in Kleinanzeigen, wo du die Adresse, die du tatsächlich nutzt, lieber nicht veröffentlichst.</li>
    <li>Das Testen deiner eigenen Registrierungs- und Bestätigungsmails.</li>
</ul>
HTML,
        ],
        [
            'id'      => 'when-not-to-use',
            'heading' => 'Wann du keine nutzen solltest',
            'html'    => <<<HTML
<p>Für alles, was wichtig ist, ist das das falsche Werkzeug.</p>
<ul>
    <li>Bank, Behördendienste oder alles, was mit deiner Identität verknüpft ist.</li>
    <li>Konten, die du wiederherstellen müsstest – ist die Adresse weg, ist auch das Zurücksetzen des Passworts weg.</li>
    <li>Alles, was du behalten willst.</li>
</ul>
<p>Eines sollte man klar aussprechen: Ein temporärer Posteingang, den du über einen geteilten Link erreichst, ist nicht privat. Gibst du den Link weiter, kann jeder, der ihn bekommt, die E-Mails lesen. Mail Shield sagt dir das lieber, als dich etwas anderes annehmen zu lassen. Sieh eine Timed-Adresse also als einen Ort zum Empfangen, nicht als einen Ort, um Geheimnisse aufzubewahren.</p>
HTML,
        ],
        [
            'id'      => 'permanent',
            'heading' => 'Temporäre E-Mail oder eine Sticky-Adresse?',
            'html'    => <<<HTML
<p>Timed-Adressen sind nur die eine Hälfte. Mail Shield gibt Pro-Konten außerdem bis zu {$msDocStickyMax} Sticky-Adressen – <span class="ms-addr">shopping@{$msDocDomain}</span>, <span class="ms-addr">news@{$msDocDomain}</span>, <span class="ms-addr">projects@{$msDocDomain}</span> – für Absender, von denen du wieder hören wirst. Die Adressen laufen nicht ab. Du entscheidest selbst, wann du sie entfernst, während die E-Mails, die sie empfangen, weiterhin gemäß deinen Einstellungen zur Aufbewahrungsdauer aufgeräumt werden.</p>
<p>Wenn du eine Wegwerfadresse willst, an die du nie wieder denken musst, ist eine Timed-Adresse die richtige Wahl. Wenn du deine echte Adresse herausgegeben hast, weil es keinen anderen Ort für einen Newsletter gab, ist eine Sticky-Adresse die bessere Lösung. <a href="{$msU('/')}">Die Startseite zeigt das ganze Bild</a>.</p>
HTML,
        ],
    ],
    'cta' => [
        'after'    => 'lifetime',
        'line'     => $msDocTrialDays > 0
            ? 'Es dauert etwa eine Minute, und deine ersten ' . $msDocTrialDays . ' Tage enthalten Pro, kostenlos.'
            : 'Es dauert etwa eine Minute und ist kostenlos.',
        'end_line' => 'Deine erste Timed-Adresse ist eine Minute entfernt.',
    ],
];
