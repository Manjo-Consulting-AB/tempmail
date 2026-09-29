<?php

declare(strict_types=1);

/**
 * CLI diagnostics for the MCP personal access tokens (#320,
 * migrate_mcp_tokens.php), in the same spirit as check_account_tiers.php /
 * check_address_feeds.php.
 *
 * Reports whether mcp_access_tokens and its two indexes exist, how many tokens
 * are active, revoked and expired, and — the state that is never legitimate —
 * how many belong to an account that no longer exists. Read-only: writes
 * nothing, prints counts and ids only, never a token, never a hash and never a
 * user's email address.
 *
 * Usage: php check_mcp_tokens.php
 *
 * Exit codes: 0 clean, 1 something is wrong (a column or index missing, or
 * orphan rows), 2 the table itself is missing (run the migration).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mcp_tokens.php';

$failures = 0;
$total = 0;

function check(string $label, bool $condition, string $detail = ''): void {
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
function mcpTokenCheckIndexExists(PDO $pdo, array $config, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$config['db']['name'], $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableHasColumn('mcp_access_tokens', 'token_hash')) {
    echo "[FEL] mcp_access_tokens.token_hash exists — the table is missing\n";
    echo "\nThis is what the profile page's Connected apps card reports as \"Not available yet\":\n";
    echo "no token resolves and no app can connect until the table exists.\n";
    echo "\nRun: php migrate_mcp_tokens.php\n";
    exit(2);
}

// ---------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------

echo "-- schema --\n";

foreach (['id', 'pro_user_id', 'name', 'token_hash', 'token_prefix', 'scopes', 'created_at', 'last_used_at', 'expires_at', 'revoked_at'] as $column) {
    check("mcp_access_tokens.{$column} exists", tableHasColumn('mcp_access_tokens', $column));
}
check('uniq_mcp_access_tokens_hash exists', mcpTokenCheckIndexExists($pdo, $config, 'mcp_access_tokens', 'uniq_mcp_access_tokens_hash'));
check('idx_mcp_access_tokens_user exists', mcpTokenCheckIndexExists($pdo, $config, 'mcp_access_tokens', 'idx_mcp_access_tokens_user'));

// ---------------------------------------------------------------------
// Counts
// ---------------------------------------------------------------------

echo "\n-- tokens --\n";

$nowStr = date('Y-m-d H:i:s');
$stmt = $pdo->prepare('SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked,
        SUM(CASE WHEN revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= ? THEN 1 ELSE 0 END) AS expired
    FROM mcp_access_tokens');
$stmt->execute([$nowStr, $nowStr]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$total_tokens = (int) ($counts['total'] ?? 0);
$active = (int) ($counts['active'] ?? 0);
$revoked = (int) ($counts['revoked'] ?? 0);
$expired = (int) ($counts['expired'] ?? 0);

echo "[OK] tokens: {$total_tokens} total, {$active} active, {$revoked} revoked, {$expired} expired\n";

$stmt = $pdo->prepare('SELECT COUNT(DISTINCT pro_user_id) FROM mcp_access_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)');
$stmt->execute([$nowStr]);
$accounts = (int) $stmt->fetchColumn();
echo "[OK] accounts with at least one active token: {$accounts}\n";

// The cap is enforced when a token is created; more than this means a row was
// written by something other than mcpTokenCreate().
$stmt = $pdo->prepare('SELECT pro_user_id, COUNT(*) AS n FROM mcp_access_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) GROUP BY pro_user_id HAVING n > ? ORDER BY n DESC LIMIT 20');
$stmt->execute([$nowStr, MCP_TOKEN_MAX_PER_USER]);
$overCap = $stmt->fetchAll(PDO::FETCH_ASSOC);
check(
    'no account is over the ' . MCP_TOKEN_MAX_PER_USER . '-token cap',
    $overCap === [],
    $overCap === [] ? '' : 'account id(s): ' . implode(', ', array_map(static fn($r) => '#' . (int) $r['pro_user_id'] . ' (' . (int) $r['n'] . ')', $overCap))
);

// ---------------------------------------------------------------------
// Orphans: a token whose account is gone can never resolve and is swept by
// cron/cleanup.php — it should not be here for long.
// ---------------------------------------------------------------------

echo "\n-- orphans --\n";

$stmt = $pdo->query('SELECT id FROM mcp_access_tokens WHERE pro_user_id NOT IN (SELECT id FROM pro_users) ORDER BY id LIMIT 50');
$orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
check(
    'every token belongs to an existing account',
    $orphans === [],
    $orphans === [] ? '' : count($orphans) . '+ token id(s) orphaned — cron/cleanup.php sweeps them: ' . implode(',', array_map('intval', $orphans))
);

// A pair that cannot be revoked or swept because it is stuck in neither state.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM mcp_access_tokens WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= ?');
$stmt->execute([date('Y-m-d H:i:s', time() - MCP_TOKEN_SWEEP_DAYS * 86400)]);
$staleExpired = (int) $stmt->fetchColumn();
if ($staleExpired > 0) {
    echo "[info] {$staleExpired} expired token(s) are past the " . MCP_TOKEN_SWEEP_DAYS . "-day sweep window — run php cron/cleanup.php\n";
}

echo "\n$total checks run, " . ($total - $failures) . " passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
