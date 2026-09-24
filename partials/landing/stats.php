<?php
/**
 * Landing — system-wide numbers.
 *
 * These are the email_stats counters that used to sit at the bottom of the
 * inbox, where a signed-in user read them as their own usage. They describe
 * the whole service, so they belong here; the inbox now shows the account's
 * own numbers instead (getUserStats() in config.php).
 *
 * Every figure is real and read at request time — nothing is rounded up or
 * seeded:
 *
 *   emails_processed       bumped by EmailStorage::store() for every stored message
 *   emails_created         bumped by saveNewAddress() in config.php
 *   attachments_processed  bumped by EmailStorage for every stored attachment
 *   active addresses       counted live from temp_emails
 *
 * `emails_total` is deliberately not shown: it counted messages examined by
 * the IMAP intake, which was removed in #212, so it no longer moves.
 *
 * Server-rendered, because marketing pages load no jQuery (brief §11) and a
 * number that animates on scroll would break the motion rules. If the database
 * is unreachable the section is left out rather than showing zeros.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$msStats = function_exists('getStats') ? getStats() : [];
$msStatsActive = null;
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $msStatsActive = (int) $pdo->query('SELECT COUNT(*) FROM temp_emails WHERE expires_at > NOW()')->fetchColumn();
    }
} catch (Throwable $e) {
    $msStatsActive = null;
}

$msStatsItems = [
    ['value' => (int) ($msStats['emails_processed'] ?? 0), 'label' => 'Emails received'],
    ['value' => (int) ($msStats['emails_created'] ?? 0), 'label' => 'Addresses created'],
    ['value' => (int) ($msStats['attachments_processed'] ?? 0), 'label' => 'Attachments handled'],
];
if ($msStatsActive !== null) {
    $msStatsItems[] = ['value' => $msStatsActive, 'label' => 'Addresses active right now'];
}

$msStatsHasData = false;
foreach ($msStatsItems as $msStatsItem) {
    if ($msStatsItem['value'] > 0) {
        $msStatsHasData = true;
        break;
    }
}
if (!$msStatsHasData) {
    return;
}
?>
<section class="ms-section ms-section--sunken ms-section--tight" id="stats">
    <div class="ms-container">
        <p class="ms-eyebrow">In numbers</p>
        <h2 class="ms-h2">Mail Shield so far</h2>

        <dl class="ms-stats">
            <?php foreach ($msStatsItems as $msStatsItem) : ?>
            <div class="ms-stats__item">
                <dt class="ms-stats__label"><?php echo htmlspecialchars($msStatsItem['label'], ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd class="ms-stats__value"><?php echo htmlspecialchars(number_format($msStatsItem['value'], 0, ',', "\u{00A0}"), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </div>
</section>
