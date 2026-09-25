<?php
/**
 * The facts the legal pages share, already escaped for HTML. Returned as an
 * array so terms.php, privacy.php and refund-policy.php interpolate the same
 * company, contact address and retention numbers instead of each typing them.
 *
 * Everything that is configured is read from $config, never written into copy:
 * the mail domain, the origin, the Pro trial length and the mailbox quota. The
 * retention numbers that are not configurable are the ones the code enforces:
 * 24 hours for a free temporary address (config app.cleanup_hours), 1–7 days on
 * Pro, a 7-day grace before a lapsed Pro account's personal addresses go
 * (cron/cleanup.php), and the inactivity rule for Regular accounts.
 */

if (!defined('TEMPMAIL_APP')) { http_response_code(403); exit; }

$msLegalH = function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$msLegalCompany   = (string) ($config['legal']['company'] ?? 'Manjo Consulting AB');
$msLegalOrgNumber = trim((string) ($config['legal']['org_number'] ?? ''));
$msLegalContact   = (string) ($config['legal']['contact_email'] ?? '');

return [
    'company'      => $msLegalH($msLegalCompany),
    // "Manjo Consulting AB (org. no. 556…), Sweden" when the number is set.
    'company_full' => $msLegalH($msLegalCompany)
        . ($msLegalOrgNumber !== '' ? ' (corporate identity number ' . $msLegalH($msLegalOrgNumber) . ')' : '')
        . ', a company registered in Sweden',
    'contact'      => '<a href="mailto:' . $msLegalH($msLegalContact) . '">' . $msLegalH($msLegalContact) . '</a>',
    'domain'       => $msLegalH($config['email']['domain'] ?? ''),
    'trial_days'   => max(0, (int) ($config['trial']['days'] ?? 0)),
    'quota_mb'     => (int) round(((int) ($config['email']['quota_bytes'] ?? 104857600)) / 1048576),
    'log_days'     => (int) ($config['cleanup']['log_retention_days'] ?? 30),
    'free_hours'   => (int) ($config['app']['cleanup_hours'] ?? 24),
    'warn_days'    => (int) ($config['cleanup']['regular_inactivity_warn_days'] ?? 335),
    'delete_days'  => (int) ($config['cleanup']['regular_inactivity_days'] ?? 365),
];
