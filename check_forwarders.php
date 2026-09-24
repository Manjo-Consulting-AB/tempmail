<?php

declare(strict_types=1);

/**
 * Read-only CLI audit (#216): reports the drift between the active rows in
 * `temp_emails` and the mail forwarders that actually exist in DirectAdmin.
 *
 * With the catch-all closed an address only receives mail if a forwarder
 * exists for it, and createDirectAdminForwarder() in config.php fails open —
 * so an address can exist in the database and never receive anything. This
 * script shows which addresses are affected; it does not repair them.
 *
 * Changes nothing: no forwarder is created or deleted, nothing is written to
 * the database. Run it from the CLI on the server that owns the DirectAdmin
 * API credentials.
 *
 * Exit codes:
 *   0 — every active address has a forwarder pointing at the configured pipe
 *   1 — at least one address is missing a forwarder, or points elsewhere
 *   2 — the forwarder list could not be read (transport/API failure)
 *
 * Usage: php check_forwarders.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/DirectAdminClient.php';

/**
 * DirectAdmin returns a pipe destination wrapped in literal double quotes
 * (see formatDestination() in DirectAdminClient.php), and the configured
 * destination may carry stray whitespace. Normalise both sides before
 * comparing: trim whitespace, then strip one pair of surrounding quotes.
 */
function normaliseForwarderDestination(string $destination): string
{
    $destination = trim($destination);

    if (strlen($destination) >= 2 && $destination[0] === '"' && substr($destination, -1) === '"') {
        $destination = substr($destination, 1, -1);
    }

    return $destination;
}

// ---------------------------------------------------------------------
// Active addresses
// ---------------------------------------------------------------------

$stmt = $pdo->prepare("SELECT unique_address FROM temp_emails WHERE expires_at > NOW() ORDER BY unique_address");
$stmt->execute();
$activeAddresses = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ---------------------------------------------------------------------
// Forwarders (read-only GET against CMD_API_EMAIL_FORWARDERS)
// ---------------------------------------------------------------------

$forwarders = (new DirectAdminClient($config['directadmin']))->listForwarders();

if ($forwarders === null) {
    echo "Could not list DirectAdmin forwarders\n";
    exit(2);
}

// Aliases are compared case-insensitively: DirectAdmin lower-cases them.
$forwardersByLowerCaseAlias = [];
foreach ($forwarders as $alias => $destination) {
    $forwardersByLowerCaseAlias[strtolower((string)$alias)] = (string)$destination;
}

$configuredDestination = normaliseForwarderDestination((string)($config['directadmin']['forwarder_destination'] ?? ''));

echo "Active addresses in temp_emails: " . count($activeAddresses) . "\n";
echo "Forwarders in DirectAdmin: " . count($forwarders) . "\n";
echo "Configured pipe destination: {$configuredDestination}\n\n";

// ---------------------------------------------------------------------
// 1. Active addresses without a forwarder
// ---------------------------------------------------------------------

$withoutForwarder = [];
$pointingElsewhere = [];
$activeByLowerCaseAlias = [];

foreach ($activeAddresses as $address) {
    $address = (string)$address;
    $lower = strtolower($address);
    $activeByLowerCaseAlias[$lower] = true;

    if (!array_key_exists($lower, $forwardersByLowerCaseAlias)) {
        $withoutForwarder[] = $address;
        continue;
    }

    $actual = normaliseForwarderDestination($forwardersByLowerCaseAlias[$lower]);
    if ($actual !== $configuredDestination) {
        $pointingElsewhere[$address] = $actual;
    }
}

echo 'Active addresses WITHOUT a forwarder: ' . count($withoutForwarder) . "\n";
foreach ($withoutForwarder as $address) {
    echo "  {$address}\n";
}

// ---------------------------------------------------------------------
// 2. Active addresses whose forwarder does not point at the configured pipe
// ---------------------------------------------------------------------

echo "\nActive addresses whose forwarder does not point at the configured pipe: " . count($pointingElsewhere) . "\n";
foreach ($pointingElsewhere as $address => $actual) {
    echo "  {$address} -> {$actual}\n";
}

// ---------------------------------------------------------------------
// 3. Forwarders without an active address (informational — these are the
//    leftovers of a delete that failed, see deleteDirectAdminForwarder()).
//    They are not a delivery problem, so they do not affect the exit code.
// ---------------------------------------------------------------------

$orphaned = [];
foreach ($forwardersByLowerCaseAlias as $lowerAlias => $destination) {
    if (!isset($activeByLowerCaseAlias[$lowerAlias])) {
        $orphaned[$lowerAlias] = $destination;
    }
}

echo "\nForwarders without an active address: " . count($orphaned) . "\n";
foreach ($orphaned as $alias => $destination) {
    echo "  {$alias} -> {$destination}\n";
}

echo "\n";
if ($withoutForwarder !== [] || $pointingElsewhere !== []) {
    echo "Result: drift found — " . count($withoutForwarder) . " address(es) without a forwarder, "
        . count($pointingElsewhere) . " pointing elsewhere.\n";
    exit(1);
}

echo "Result: every active address has a forwarder pointing at the configured pipe.\n";
exit(0);
