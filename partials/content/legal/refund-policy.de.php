<?php
/**
 * The refund policy in German: the whole document as data, rendered by
 * partials/legal_page.php. Mirrors refund-policy.en.php: the same section ids
 * in the same order, the same facts as variables and the same 'updated' date;
 * only the words differ. It states no clause on which language prevails (epic
 * #441 decision 2). Glossary: the German glossary (informal "du").
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
    'title'       => 'Rückerstattungsrichtlinie',
    'lede'        => 'Wenn Mail Shield Pro nicht zu dir passt, bekommst du dein Geld innerhalb von ' . $guaranteeDays . ' Tagen nach der Zahlung zurück. Ohne Fragen.',
    'description' => 'Mail Shield bietet eine ' . $guaranteeDays . '-Tage-Geld-zurück-Garantie auf Pro-Abonnements und Lifetime-Käufe. Rückerstattungen erfolgen durch Paddle, unseren Wiederverkäufer.',
    'path'        => '/refund-policy.php',
    'updated'     => '2026-10-10',
    'sections'    => [
        [
            'id'      => 'guarantee',
            'heading' => $guaranteeDays . '-Tage-Geld-zurück-Garantie',
            'html'    => '<p>Jede Zahlung für Mail Shield Pro kann vollständig erstattet werden, wenn du innerhalb von ' . $guaranteeDays . ' Tagen ab dem Datum der Abbuchung darum bittest. Das gilt für:</p>'
                . '<ul>'
                . '<li>die erste Zahlung eines Monats- oder Jahresabonnements;</li>'
                . '<li>jede Verlängerung eines Abonnements, gerechnet ab dem Datum dieser Verlängerung;</li>'
                . '<li>einen einmaligen Lifetime-Kauf.</li>'
                . '</ul>'
                . '<p>Du musst keinen Grund angeben. Erstattet wird der volle Betrag, den du bezahlt hast, einschließlich etwaiger Steuern.</p>'
                . '<p>Diese Garantie gilt zusätzlich zu deinen gesetzlichen Rechten als Verbraucher, einschließlich eines etwaigen Widerrufsrechts, das Paddle als Merchant of Record nach seinen Käuferbedingungen abwickelt. Sie ersetzt oder beschränkt diese Rechte nicht.</p>',
        ],
        [
            'id'      => 'how',
            'heading' => 'So bittest du um eine Rückerstattung',
            'html'    => '<p>Unsere Bestellabwicklung erfolgt über unseren Online-Wiederverkäufer Paddle.com. Paddle.com ist der Merchant of Record für alle unsere Bestellungen. Paddle übernimmt alle Kundenserviceanfragen und bearbeitet Rückgaben.</p>'
                . '<p>Du kannst auf zwei Wegen um eine Rückerstattung bitten:</p>'
                . '<ul>'
                . '<li><strong>Über Paddle</strong> – folge dem Link in deiner Quittungs-E-Mail oder suche deinen Kauf unter <a href="https://paddle.net" rel="noopener">paddle.net</a>.</li>'
                . '<li><strong>Über uns</strong> – schreibe eine E-Mail an ' . $f['contact'] . ' von der Adresse deines Kontos oder von der Adresse auf der Quittung, dann leiten wir die Anfrage an Paddle weiter.</li>'
                . '</ul>'
                . '<p>Die Rückerstattung geht auf das Zahlungsmittel zurück, das du verwendet hast. Paddle bearbeitet sie in der Regel innerhalb weniger Werktage; wann das Geld bei dir ankommt, hängt von deiner Bank oder deinem Kartenanbieter ab.</p>',
        ],
        [
            'id'      => 'after',
            'heading' => 'Was mit deinem Konto passiert',
            'html'    => '<p>Eine Rückerstattung beendet die Pro-Zeit, für die die Zahlung galt, und ein erstattetes Abonnement wird gekündigt, sodass es sich nicht verlängert. Dein Konto bleibt bestehen, als kostenloses Regular-Konto. Wie bei jedem Pro-Tarif, der endet, werden Sticky-Adressen ' . $graceDays . ' Tage später samt ihren E-Mails gelöscht – übertrage also alles, was du behalten möchtest, vorher.</p>'
                . '<p>Ein erstatteter Lifetime-Kauf beendet Pro am Tag der Rückerstattung. Danach gelten dieselben ' . $graceDays . ' Tage, bevor Sticky-Adressen gelöscht werden.</p>'
                . '<p>Pro-Zeit, die du vor dem Kauf hattest – die kostenlose Testphase oder ein Gutschein –, bleibt von der Rückerstattung unberührt.</p>'
                . '<p>Wird eine Zahlung, die über eine <a href="' . $msU('/terms.php#referrals') . '">Einladung</a> erfolgte, erstattet oder zurückgebucht, bevor die Belohnung der einladenden Person gewährt wird, was ' . $ref['hold_days'] . ' Tage nach der Zahlung der Fall ist, wird deren Bonus nicht gewährt, und die Bonusmonate des Freundes enden zusammen mit dem erstatteten Abonnement. Eine Rückerstattung, nachdem die einladende Person ihre Belohnung erhalten hat, nimmt diese Belohnung nicht zurück.</p>'
                . '<p>Eine Rückerstattung ist keine Aufforderung, deine Daten zu löschen. Abgesehen davon, dass Pro endet, gelten für dein Konto und seine Daten die üblichen Aufbewahrungsregeln in unserer <a href="' . $msU('/privacy.php#retention') . '">Datenschutzerklärung</a>. Um sie zu löschen, lösche dein Konto.</p>',
        ],
        [
            'id'      => 'cancel',
            'heading' => 'Kündigen ohne Rückerstattung',
            'html'    => '<p>Du kannst ein Abonnement jederzeit über <strong>Manage billing</strong> in deinem Profil kündigen. Die Kündigung stoppt künftige Verlängerungen; du behältst Pro bis zum Ende des bezahlten Zeitraums. Liegt die letzte Zahlung noch nicht länger als ' . $guaranteeDays . ' Tage zurück und möchtest du außerdem dein Geld zurück, bitte wie oben beschrieben um eine Rückerstattung.</p>',
        ],
        [
            'id'      => 'after-30',
            'heading' => 'Nach ' . $guaranteeDays . ' Tagen',
            'html'    => '<p>Nach ' . $guaranteeDays . ' Tagen werden Zahlungen normalerweise nicht erstattet. Deine Rechte nach zwingendem Verbraucherrecht bleiben unberührt, und wenn auf unserer Seite etwas schiefgelaufen ist – eine doppelte Abbuchung oder ein Dienst, der nicht wie beschrieben funktioniert hat –, kontaktiere uns unter ' . $f['contact'] . ', dann bringen wir es in Ordnung.</p>'
                . '<p>Wenn wir Mail Shield einstellen oder dein Konto aus einem anderen Grund als einem Verstoß gegen unsere <a href="' . $msU('/terms.php') . '">AGB</a> beenden, erstatten wir so, wie es dort beschrieben ist.</p>',
        ],
    ],
];
