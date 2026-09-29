<?php
/**
 * The facts the legal pages share, already escaped for HTML. Returned as an
 * array so terms.php, privacy.php and refund-policy.php interpolate the same
 * company, contact address and retention numbers instead of each typing them.
 *
 * Everything that is configured is read from $config, never written into copy:
 * the mail domain, the origin, the Pro trial length and the two mailbox quotas
 * (Free and Pro, #340 — the trial counts as Pro through proUserIsPro(), so it
 * gets the Pro figure). The
 * retention numbers that are not configurable are the ones the code enforces:
 * 24 hours for a free temporary address (config app.cleanup_hours), 1–7 days on
 * Pro, a 7-day grace before a lapsed Pro account's personal addresses go
 * (cron/cleanup.php), and the inactivity rule for Regular accounts.
 *
 * The three connected-app numbers mirror oauth_server.php's constants — a grant
 * survives as long as its refresh token does (OAUTH_REFRESH_TOKEN_TTL_SECONDS,
 * sliding from every rotation), an authorization code is short-lived
 * (OAUTH_CODE_TTL_SECONDS), and a client with no grant and no activity is swept
 * (OAUTH_CLIENT_IDLE_SECONDS). They are written here rather than read from the
 * constants because this file is shared by the legal pages, which have no
 * business loading the OAuth library; check them against oauth_server.php when
 * any of the three is changed.
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
    // The storage limit is per tier, and the pages quote both figures. A trial
    // account is Pro through proUserIsPro(), so it is on `quota_pro_mb`.
    'quota_free_mb'   => (int) round(((int) ($config['email']['quota_bytes_free'] ?? 10485760)) / 1048576),
    'quota_pro_mb'    => (int) round(((int) ($config['email']['quota_bytes_pro'] ?? 104857600)) / 1048576),
    'log_days'     => (int) ($config['cleanup']['log_retention_days'] ?? 30),
    'free_hours'   => (int) ($config['app']['cleanup_hours'] ?? 24),
    'warn_days'    => (int) ($config['cleanup']['regular_inactivity_warn_days'] ?? 335),
    'delete_days'  => (int) ($config['cleanup']['regular_inactivity_days'] ?? 365),
    'cooldown_months' => (int) ($config['address_cooldown']['months'] ?? 6),
    // Connected apps (#331): a grant lives while its refresh token does, an
    // authorization code is good for a minute, and an unused registered client
    // is swept after a month. See the note above.
    'grant_idle_days' => 90,
    'code_seconds'    => 60,
    'client_idle_days' => 30,
    'hosting'      => 'Inleed, a trade name of Yelles AB (Sweden)',
];
