<?php

declare(strict_types=1);

/**
 * Regression coverage for reserved_local_parts.php: which local parts a user
 * may never claim as a personal address, and the stricter syntax a NEW
 * personal address must satisfy.
 *
 * Run with:  php tests/reserved_local_parts_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Pure functions only: no
 * database, no network, no docroot.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../reserved_local_parts.php';
// A repeat require must not redeclare anything.
require __DIR__ . '/../reserved_local_parts.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

echo "== Reserved names ==\n";
$reserved = [
    // CA/B Forum BR 3.2.2.4.4
    'admin', 'administrator', 'webmaster', 'hostmaster', 'postmaster',
    // RFC 2142
    'abuse', 'noc', 'security', 'info', 'marketing', 'sales', 'support',
    // system / sender
    'root', 'mailer-daemon', 'daemon', 'nobody', 'noreply', 'no-reply',
    'do-not-reply', 'donotreply', 'bounce', 'bounces',
    // roles / infra / brand
    'billing', 'payments', 'privacy', 'legal', 'dmarc', 'dkim', 'spf', 'mail',
    'smtp', 'imap', 'pop', 'www', 'ftp', 'api', 'help', 'contact', 'team',
    'manjo', 'mailshield', 'tempmail', 'ssl-admin', 'ssladmin', 'it', 'hr',
    'office',
    // the pre-existing blacklist
    'jj', 'roland', 'investering',
];
foreach ($reserved as $name) {
    check("reserved: {$name}", isReservedLocalPart($name));
}

echo "== Normalisation variants ==\n";
$variants = [
    'ADMIN', 'Admin', 'post.master', 'post-master', 'post_master', 'no_reply',
    'no.reply', 'n.o-r_e.p.l.y', 'Mailer.Daemon', 'mailerdaemon', 'web-master',
    'host_master', 'mail-shield', 'temp.mail', 'ssl_admin', 'i.t', 'h-r',
    'xn--80ak6aa92e', 'XN--abc', '...', '-_-',
];
foreach ($variants as $name) {
    check("reserved variant: {$name}", isReservedLocalPart($name));
}

echo "== Allowed names (no substring false positives) ==\n";
$allowed = [
    'tony', 'crew-1', 'john.doe', 'itsme', 'admiral', 'administrators-club',
    'supporter', 'mailbox99', 'teamwork', 'hrafn', 'rolandsson', 'jjj',
    'office365fan', 'root-beer', 'myinfo', 'xnabc',
];
foreach ($allowed as $name) {
    check("allowed: {$name}", !isReservedLocalPart($name));
}

echo "== Syntax for a new address ==\n";
$validSyntax = ['tony', 'crew-1', 'john.doe', 'a_b', 'a.b-c_d', 'abc', 'x1y'];
foreach ($validSyntax as $name) {
    check("valid syntax: {$name}", isValidNewLocalPartSyntax($name));
}
$invalidSyntax = [
    '.tony', 'tony.', '-tony', 'tony-', '_tony', 'tony_', 'john..doe',
    'a...b', '.', '..', '', 'Tony', 'to ny', 'to+ny', 'tony@x', "tony\n",
    "t\xC3\xB6n", '-', '_',
];
foreach ($invalidSyntax as $name) {
    check('invalid syntax: ' . json_encode($name), !isValidNewLocalPartSyntax($name));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
