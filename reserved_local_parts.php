<?php
/**
 * Reserved local parts - the single source of truth for which mailbox names a
 * user may never claim as a personal address on the mail domain.
 *
 * Why this matters: whoever controls admin@, hostmaster@, postmaster@ or
 * webmaster@ (CA/Browser Forum Baseline Requirements 3.2.2.4.4) can pass a
 * certificate authority's email domain validation and obtain a TLS
 * certificate for the whole domain. The RFC 2142 role names, the addresses
 * the app itself sends from (noreply@) and the brand names are reserved for
 * the same reason: a user holding them could impersonate the operator.
 *
 * Deliberately dependency-free (no config.php, no session, no DB) so the CLI
 * test can load it on its own; every function is function_exists-guarded so a
 * repeat require cannot redeclare it.
 *
 * Only address CREATION uses this. Inbound validation in parse.php still uses
 * sanitizeLocalPart() in config.php, so an address that already exists keeps
 * receiving mail.
 */

if (!function_exists('reservedLocalParts')) {
    /**
     * The reserved names, lowercase, in normalised form (no dots, hyphens or
     * underscores) - see normalizeLocalPartForReservation().
     *
     * @return array<string, true>
     */
    function reservedLocalParts(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }
        $names = [
            // RFC 2142 mailbox names
            'postmaster', 'hostmaster', 'webmaster', 'abuse', 'noc', 'security',
            'info', 'marketing', 'sales', 'support', 'usenet', 'news', 'uucp',
            // CA/B Forum BR 3.2.2.4.4 constructed addresses
            'admin', 'administrator',
            // System and MTA accounts
            'root', 'mailer-daemon', 'mailerdaemon', 'daemon', 'nobody', 'system',
            'sysadmin', 'operator',
            // Addresses the app sends from, or that look like it
            'noreply', 'no-reply', 'do-not-reply', 'donotreply', 'bounce', 'bounces',
            // Business and legal roles
            'billing', 'payments', 'payment', 'invoice', 'invoices', 'privacy',
            'legal', 'compliance', 'gdpr', 'dpo',
            // Mail and DNS infrastructure
            'dmarc', 'dkim', 'spf', 'mail', 'email', 'smtp', 'imap', 'pop', 'pop3',
            'mx', 'dns', 'www', 'ftp', 'api', 'ssl-admin', 'ssladmin',
            'ssl-administrator', 'ssladministrator', 'webadmin', 'certadmin',
            // Generic operator roles
            'help', 'contact', 'team', 'staff', 'it', 'hr', 'office', 'owner',
            // Brand names
            'manjo', 'mailshield', 'mail-shield', 'tempmail', 'temp-mail',
            // Names reserved before this list existed
            'jj', 'roland', 'investering',
        ];
        $set = [];
        foreach ($names as $name) {
            $set[normalizeLocalPartForReservation($name)] = true;
        }
        return $set;
    }
}

if (!function_exists('normalizeLocalPartForReservation')) {
    /**
     * Lowercase and strip the separators ., - and _ so that post.master,
     * no_reply and Mailer-Daemon compare equal to their reserved form.
     */
    function normalizeLocalPartForReservation(string $local): string
    {
        return str_replace(['.', '-', '_'], '', strtolower($local));
    }
}

if (!function_exists('isReservedLocalPart')) {
    /**
     * True when $local may not be claimed as a new address. The normalised
     * string is compared for equality with the reserved set - never as a
     * substring - so "itsme" or "admiral" stay available. A punycode prefix
     * (xn--) is reserved as well, to rule out look-alike names.
     */
    function isReservedLocalPart(string $local): bool
    {
        $lower = strtolower($local);
        if (strncmp($lower, 'xn--', 4) === 0) {
            return true;
        }
        $normalized = normalizeLocalPartForReservation($lower);
        if ($normalized === '') {
            return true;
        }
        return isset(reservedLocalParts()[$normalized]);
    }
}

if (!function_exists('isValidNewLocalPartSyntax')) {
    /**
     * Stricter syntax for a NEW personal address than sanitizeLocalPart()
     * enforces: the character set [a-z0-9._-] (lowercase, as sanitizeLocalPart
     * returns it), no leading or trailing ".", "-" or "_", and no consecutive
     * dots - an unquoted RFC 5321 dot-atom may not start or end with a dot or
     * contain "..".
     */
    function isValidNewLocalPartSyntax(string $local): bool
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $local)) {
            return false;
        }
        return strpos($local, '..') === false;
    }
}
