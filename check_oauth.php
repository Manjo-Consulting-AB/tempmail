<?php

declare(strict_types=1);

/**
 * CLI diagnostics for the OAuth schema (epic #331, migrate_oauth.php), in the
 * same spirit as check_mcp_tokens.php / check_account_tiers.php.
 *
 * Reports whether oauth_clients, oauth_authorization_codes and the OAuth
 * columns and indexes on mcp_access_tokens all exist, how many clients and
 * codes are stored, and the two states that are never legitimate: a grant or a
 * code linked to a client that does not exist. Read-only: writes nothing,
 * prints counts only, never a client name, a redirect URI, a code or a token.
 *
 * Usage: php check_oauth.php
 *
 * Exit codes: 0 clean, 1 the schema is partial or links are inconsistent,
 * 2 the tables are missing (run the migration).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth_server.php';

$failures = 0;
$total = 0;

function oauthCheck(string $label, bool $condition, string $detail = ''): void {
    global $failures, $total;
    $total++;
    if ($condition) {
        echo "[OK] $label\n";
    } else {
        echo "[FEL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failures++;
    }
}

/** Whether $index exists on $table, via information_schema (bound parameters). */
function oauthCheckIndexExists(PDO $pdo, array $config, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

$hasClients = tableHasColumn('oauth_clients', 'client_id');
$hasCodes = tableHasColumn('oauth_authorization_codes', 'code_hash');

if (!$hasClients && !$hasCodes) {
    echo "[FEL] OAuth tables exist — they are missing\n";
    echo "\nWhile they are, the metadata documents are 404 and a client that tries to sign in\n";
    echo "fails closed: mcp.php keeps asking for a Bearer token, exactly as before OAuth.\n";
    echo "\nRun: php migrate_oauth.php\n";
    exit(2);
}

// ---------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------

echo "-- schema --\n";

foreach (['id', 'client_id', 'client_name', 'redirect_uris', 'created_at', 'last_used_at', 'registered_ip_hash'] as $column) {
    oauthCheck("oauth_clients.{$column} exists", tableHasColumn('oauth_clients', $column));
}
oauthCheck(
    'uniq_oauth_clients_client_id exists',
    $hasClients && oauthCheckIndexExists($pdo, $config, 'oauth_clients', 'uniq_oauth_clients_client_id')
);

foreach (['id', 'code_hash', 'client_id', 'pro_user_id', 'redirect_uri', 'scopes', 'code_challenge', 'resource', 'expires_at', 'used_at'] as $column) {
    oauthCheck("oauth_authorization_codes.{$column} exists", tableHasColumn('oauth_authorization_codes', $column));
}
oauthCheck(
    'uniq_oauth_authorization_codes_hash exists',
    $hasCodes && oauthCheckIndexExists($pdo, $config, 'oauth_authorization_codes', 'uniq_oauth_authorization_codes_hash')
);

foreach (['oauth_client_id', 'refresh_token_hash', 'refresh_expires_at', 'grant_created_at', 'rotated_refresh_hash', 'rotated_at'] as $column) {
    oauthCheck("mcp_access_tokens.{$column} exists", tableHasColumn('mcp_access_tokens', $column));
}
oauthCheck('uniq_mcp_access_tokens_refresh exists', oauthCheckIndexExists($pdo, $config, 'mcp_access_tokens', 'uniq_mcp_access_tokens_refresh'));
oauthCheck('idx_mcp_access_tokens_oauth exists', oauthCheckIndexExists($pdo, $config, 'mcp_access_tokens', 'idx_mcp_access_tokens_oauth'));

// ---------------------------------------------------------------------
// Counts
// ---------------------------------------------------------------------

$nowStr = date('Y-m-d H:i:s');

echo "\n-- clients --\n";

if ($hasClients) {
    $clients = (int) $pdo->query('SELECT COUNT(*) FROM oauth_clients')->fetchColumn();
    echo "[OK] clients: {$clients} stored\n";

    // A client with no grant in 30 days is idle; step 4/6 sweeps those.
    $idleCutoff = date('Y-m-d H:i:s', time() - 30 * 86400);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM oauth_clients c WHERE NOT EXISTS (
        SELECT 1 FROM mcp_access_tokens t
        WHERE t.oauth_client_id = c.client_id AND t.grant_created_at IS NOT NULL AND t.grant_created_at > ?
    )');
    $stmt->execute([$idleCutoff]);
    $idle = (int) $stmt->fetchColumn();
    echo "[OK] clients with no grant in 30 days: {$idle}\n";
}

echo "\n-- authorization codes --\n";

if ($hasCodes) {
    $stmt = $pdo->prepare('SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN used_at IS NULL AND expires_at > ? THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN used_at IS NULL AND expires_at <= ? THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN used_at IS NOT NULL THEN 1 ELSE 0 END) AS used
        FROM oauth_authorization_codes');
    $stmt->execute([$nowStr, $nowStr]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo "[OK] codes: " . (int) ($counts['total'] ?? 0) . " total, "
        . (int) ($counts['pending'] ?? 0) . " pending, "
        . (int) ($counts['expired'] ?? 0) . " expired, "
        . (int) ($counts['used'] ?? 0) . " used\n";
}

echo "\n-- access tokens --\n";

if (tableHasColumn('mcp_access_tokens', 'oauth_client_id')) {
    $stmt = $pdo->prepare('SELECT
            SUM(CASE WHEN oauth_client_id IS NULL THEN 1 ELSE 0 END) AS manual,
            SUM(CASE WHEN oauth_client_id IS NOT NULL THEN 1 ELSE 0 END) AS oauth
        FROM mcp_access_tokens');
    $stmt->execute();
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo "[OK] tokens: " . (int) ($counts['oauth'] ?? 0) . " via OAuth, " . (int) ($counts['manual'] ?? 0) . " manual\n";
}

// ---------------------------------------------------------------------
// Grants: an OAuth access token is an mcp_access_tokens row that is also a
// grant. The two states below are never legitimate; the two counts are what
// step 4/6's sweep works from.
// ---------------------------------------------------------------------

echo "\n-- grants --\n";

if (tableHasColumn('mcp_access_tokens', 'oauth_client_id')) {
    $activeGrants = (int) $pdo->query('SELECT COUNT(*) FROM mcp_access_tokens WHERE oauth_client_id IS NOT NULL AND revoked_at IS NULL')->fetchColumn();
    echo "[OK] active grants: {$activeGrants}\n";

    // The 11th authorisation is refused (step 3/6 and the exchange), so an
    // account above the cap got there through a path that skipped the check.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT pro_user_id FROM mcp_access_tokens WHERE oauth_client_id IS NOT NULL AND revoked_at IS NULL GROUP BY pro_user_id HAVING COUNT(*) > ?) over_cap');
    $stmt->execute([OAUTH_GRANT_MAX_PER_USER]);
    $overCap = (int) $stmt->fetchColumn();
    oauthCheck(
        'no account holds more than ' . OAUTH_GRANT_MAX_PER_USER . ' grants',
        $overCap === 0,
        $overCap . ' account(s) are over the cap'
    );

    // A grant whose account is gone can never resolve; mcpTokenCleanup()
    // removes it, so one still here means the sweep has not run.
    $orphanGrants = (int) $pdo->query('SELECT COUNT(*) FROM mcp_access_tokens WHERE oauth_client_id IS NOT NULL AND pro_user_id NOT IN (SELECT id FROM pro_users)')->fetchColumn();
    oauthCheck(
        'every grant belongs to an existing account',
        $orphanGrants === 0,
        $orphanGrants . ' grant(s) belong to a missing account'
    );

    // Informational: clients no grant points at (the sweep removes those idle
    // for 30 days) and codes old enough that the sweep should have taken them.
    $clientsWithoutGrants = (int) $pdo->query('SELECT COUNT(*) FROM oauth_clients WHERE client_id NOT IN (SELECT oauth_client_id FROM mcp_access_tokens WHERE oauth_client_id IS NOT NULL)')->fetchColumn();
    echo "[OK] clients without grants: {$clientsWithoutGrants}\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM oauth_authorization_codes WHERE expires_at <= ?');
    $stmt->execute([date('Y-m-d H:i:s', time() - OAUTH_CODE_SWEEP_SECONDS)]);
    echo "[OK] expired codes not yet swept: " . (int) $stmt->fetchColumn() . "\n";
}

// ---------------------------------------------------------------------
// Orphans: a link to a deleted client can never be used and should have been
// swept (step 4/6).
// ---------------------------------------------------------------------

echo "\n-- orphans --\n";

if ($hasClients && tableHasColumn('mcp_access_tokens', 'oauth_client_id')) {
    $stmt = $pdo->query('SELECT COUNT(*) FROM mcp_access_tokens WHERE oauth_client_id IS NOT NULL AND oauth_client_id NOT IN (SELECT client_id FROM oauth_clients)');
    $orphanTokens = (int) $stmt->fetchColumn();
    oauthCheck(
        'every OAuth token belongs to a stored client',
        $orphanTokens === 0,
        $orphanTokens . ' token(s) link to a missing client'
    );
}

if ($hasClients && $hasCodes) {
    $stmt = $pdo->query('SELECT COUNT(*) FROM oauth_authorization_codes WHERE client_id NOT IN (SELECT client_id FROM oauth_clients)');
    $orphanCodes = (int) $stmt->fetchColumn();
    oauthCheck(
        'every authorization code belongs to a stored client',
        $orphanCodes === 0,
        $orphanCodes . ' code(s) link to a missing client'
    );
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
