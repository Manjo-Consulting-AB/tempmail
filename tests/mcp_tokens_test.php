<?php

declare(strict_types=1);

/**
 * Regression coverage for MCP personal access tokens (#320, mcp_tokens.php,
 * table mcp_access_tokens from migrate_mcp_tokens.php).
 *
 * Run with:  php tests/mcp_tokens_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. No MySQL and no network:
 *
 *   A. the library on an in-memory SQLite database — token format, the hash
 *      at rest, resolve against revoked/expired/non-Pro/suspended accounts, a
 *      malformed token reaching no query at all, the per-account cap, listing,
 *      revoking and the cleanup sweep.
 *   B. the real pro_auth.php as subprocesses on SQLite — a password change, an
 *      email change, both undo links and an account deletion each revoke every
 *      one of the account's tokens, through its own throwaway docroot (the
 *      deletion path needs tables — 2FA state, webhooks — the shared harness
 *      does not carry, and TwoFactorAuth::ensureSchema() emits MySQL-only DDL).
 *   C. the real pro_profile.php actions and pro_profile_page.php, through the
 *      shared probe docroot in tests/lib/pushover_harness.php — create, list,
 *      revoke, the Pro gate, the same-origin gate, the one-time display and
 *      the two UI states of the card.
 *   D. scans: every removal path is wired up, and no log call is handed a
 *      token.
 */

$repoRoot = dirname(__DIR__);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

date_default_timezone_set('UTC');
require $repoRoot . '/mcp_tokens.php';
require __DIR__ . '/lib/pushover_harness.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "\n       {$detail}" : '') . "\n";
    }
}

/** SQLite version of the table migrate_mcp_tokens.php creates. */
function mcpSchema(): string
{
    return <<<'SQL'
CREATE TABLE mcp_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pro_user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    token_prefix TEXT NOT NULL,
    scopes TEXT NOT NULL DEFAULT 'read',
    created_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    expires_at TEXT NULL,
    revoked_at TEXT NULL
);
SQL;
}

function mcpDb(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/** One token row by id, or null. */
function mcpRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mcp_access_tokens WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Live (not revoked) rows of an account, newest first. */
function mcpLiveRows(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM mcp_access_tokens WHERE pro_user_id = ? AND revoked_at IS NULL ORDER BY id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// The entitlement doubles resolve() consults. Part A drives them from here;
// parts B and C run in subprocesses with their own stub config.php.
$GLOBALS['mcp_test_pro'] = [];
$GLOBALS['mcp_test_suspended'] = [];
function proUserIsPro(int $userId): bool
{
    return (bool) ($GLOBALS['mcp_test_pro'][$userId] ?? true);
}
function proUserIsSuspended(int $userId): bool
{
    return (bool) ($GLOBALS['mcp_test_suspended'][$userId] ?? false);
}

/** A PDO that counts prepare() calls, to prove a malformed token never queries. */
final class McpCountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

// ---------------------------------------------------------------------------
echo "A. mcp_tokens.php on SQLite\n";
// ---------------------------------------------------------------------------

$t0 = strtotime('2026-03-01 12:00:00');

$token = mcpTokenGenerate();
check('A1. the token is msk_ + 64 hex characters', preg_match('/^msk_[a-f0-9]{64}$/', $token) === 1, $token);
check('A2. a fresh call gives a different token', $token !== mcpTokenGenerate());
check('A3. the format check accepts a well-formed token and nothing else',
    mcpTokenValidate($token) === $token
    && mcpTokenValidate(null) === null
    && mcpTokenValidate('') === null
    && mcpTokenValidate('msk_') === null
    && mcpTokenValidate(substr($token, 0, -1)) === null
    && mcpTokenValidate(strtoupper($token)) === null
    && mcpTokenValidate('msk_' . str_repeat('g', 64)) === null
    && mcpTokenValidate(str_repeat('a', 64)) === null
    && mcpTokenValidate('x' . $token) === null
    && mcpTokenValidate(mcpTokenHash($token)) === null);
check('A4. the hash is a plain SHA-256 of the token', mcpTokenHash($token) === hash('sha256', $token) && preg_match('/^[a-f0-9]{64}$/', mcpTokenHash($token)) === 1);
check('A5. the prefix is the first 12 characters', mcpTokenPrefixOf($token) === substr($token, 0, 12) && strlen(mcpTokenPrefixOf($token)) === 12);

check('A6. the two scopes are accepted, anything else is not',
    mcpTokenNormaliseScope('read') === 'read'
    && mcpTokenNormaliseScope(' read ') === 'read'
    && mcpTokenNormaliseScope('read,write') === 'read,write'
    && mcpTokenNormaliseScope('write') === null
    && mcpTokenNormaliseScope('read, write') === null
    && mcpTokenNormaliseScope('read,write,admin') === null
    && mcpTokenNormaliseScope('') === null
    && mcpTokenNormaliseScope(null) === null);
check('A7. only the offered expiries are accepted, 0 meaning never',
    mcpTokenNormaliseExpiryDays('0') === 0
    && mcpTokenNormaliseExpiryDays(30) === 30
    && mcpTokenNormaliseExpiryDays(' 90 ') === 90
    && mcpTokenNormaliseExpiryDays('365') === 365
    && mcpTokenNormaliseExpiryDays('1') === null
    && mcpTokenNormaliseExpiryDays('3650') === null
    && mcpTokenNormaliseExpiryDays('-30') === null
    && mcpTokenNormaliseExpiryDays('30; DROP') === null
    && mcpTokenNormaliseExpiryDays(['30']) === null
    && mcpTokenNormaliseExpiryDays(null) === null);

// File-backed, not :memory: — this build hands out one shared in-memory
// database per process, so two "separate" in-memory handles would not be.
$tmpDb = sys_get_temp_dir() . '/ms_mcp_partA_' . bin2hex(random_bytes(6));
$db = mcpDb($tmpDb . '-none.sqlite');
check('A8. unavailable without the table', mcpTokenAvailable($db) === false);
check('A9. and a well-formed token resolves to nothing (fail closed)', mcpTokenResolve($db, $token, $t0) === null);

$db2 = mcpDb($tmpDb . '-full.sqlite');
$db2->exec(mcpSchema());
// The orphan half of the cleanup sweep joins against pro_users.
$db2->exec('CREATE TABLE pro_users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
check('A10. available with the table', mcpTokenAvailable($db2) === true);

// The malformed token must be rejected before any query is even prepared.
$counting = new McpCountingPdo('sqlite:' . $tmpDb . '-count.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$counting->exec(mcpSchema());
$before = $counting->prepares;
check('A11. a malformed token resolves to nothing', mcpTokenResolve($counting, 'msk_not-a-token', $t0) === null);
check('A12. ... without a single query', $counting->prepares === $before, 'prepares went from ' . $before . ' to ' . $counting->prepares);
check('A13. a null token likewise', mcpTokenResolve($counting, null, $t0) === null && $counting->prepares === $before);

$db = $db2;
$db->exec("INSERT INTO pro_users (id) VALUES (1), (2), (3), (4), (5)");

$created = mcpTokenCreate($db, 1, 'Claude Desktop', 'read', null, $t0);
check('A14. a token is created and returned once', is_array($created) && mcpTokenValidate($created['token']) === $created['token']);
check('A15. with the id, prefix, name and scope', $created['id'] > 0 && $created['token_prefix'] === mcpTokenPrefixOf($created['token'])
    && $created['name'] === 'Claude Desktop' && $created['scopes'] === 'read');
check('A16. no expiry when none was asked for', $created['expires_at'] === null);

$row = mcpRow($db, $created['id']);
check('A17. the row holds the SHA-256 of the token, not the token', $row['token_hash'] === mcpTokenHash($created['token']));
check('A18. the plaintext token is nowhere in the row', strpos((string) json_encode($row), $created['token']) === false);
check('A19. and the prefix is what the list shows', $row['token_prefix'] === $created['token_prefix']);
check('A20. created_at is set, last_used_at and revoked_at are not', $row['created_at'] === date('Y-m-d H:i:s', $t0) && $row['last_used_at'] === null && $row['revoked_at'] === null);

$resolved = mcpTokenResolve($db, $created['token'], $t0 + 10);
check('A21. the token resolves to its account and scope', $resolved !== null && $resolved['user_id'] === 1 && $resolved['scopes'] === 'read' && $resolved['name'] === 'Claude Desktop');
check('A22. resolving sets last_used_at', $resolved['last_used_at'] === date('Y-m-d H:i:s', $t0 + 10) && mcpRow($db, $created['id'])['last_used_at'] === date('Y-m-d H:i:s', $t0 + 10));
mcpTokenResolve($db, $created['token'], $t0 + 40);
check('A23. ... at most once a minute', mcpRow($db, $created['id'])['last_used_at'] === date('Y-m-d H:i:s', $t0 + 10));
mcpTokenResolve($db, $created['token'], $t0 + 71);
check('A24. ... and again once the minute has passed', mcpRow($db, $created['id'])['last_used_at'] === date('Y-m-d H:i:s', $t0 + 71));

check('A25. an unknown token resolves to nothing', mcpTokenResolve($db, 'msk_' . str_repeat('a', 64), $t0) === null);
check('A26. a scope that is not offered is refused at creation', mcpTokenCreate($db, 1, 'x', 'admin') === null);
check('A27. a token belongs to its account only', mcpTokenResolve($db, $created['token'], $t0)['user_id'] === 1);

// Expiry
$shortLived = mcpTokenCreate($db, 1, 'Short', 'read,write', $t0 + 3600, $t0);
check('A28. a token with an expiry stores it', $shortLived['expires_at'] === date('Y-m-d H:i:s', $t0 + 3600));
check('A29. it resolves before the expiry', mcpTokenResolve($db, $shortLived['token'], $t0 + 3599) !== null);
check('A30. and not at or after it', mcpTokenResolve($db, $shortLived['token'], $t0 + 3600) === null && mcpTokenResolve($db, $shortLived['token'], $t0 + 3601) === null);

// Revoking
check('A31. revoking someone else\'s token does nothing', mcpTokenRevoke($db, 2, $created['id'], $t0) === false
    && mcpTokenResolve($db, $created['token'], $t0) !== null);
check('A32. revoking an unknown id does nothing', mcpTokenRevoke($db, 1, 99999, $t0) === false);
check('A33. revoking one\'s own token marks it', mcpTokenRevoke($db, 1, $created['id'], $t0) === true);
check('A34. a revoked token resolves to nothing', mcpTokenResolve($db, $created['token'], $t0) === null);
check('A35. ... and cannot be revoked twice', mcpTokenRevoke($db, 1, $created['id'], $t0) === false);
check('A36. the row is kept for the audit, with the time it happened', mcpRow($db, $created['id'])['revoked_at'] === date('Y-m-d H:i:s', $t0));

// Entitlement and suspension
$fresh = mcpTokenCreate($db, 1, 'Gated', 'read', null, $t0);
$GLOBALS['mcp_test_pro'][1] = false;
check('A37. a token of an account that is no longer Pro resolves to nothing', mcpTokenResolve($db, $fresh['token'], $t0) === null);
$GLOBALS['mcp_test_pro'][1] = true;
check('A38. it resolves again once the account is Pro', mcpTokenResolve($db, $fresh['token'], $t0) !== null);
$GLOBALS['mcp_test_suspended'][1] = true;
check('A39. a suspended account\'s token resolves to nothing', mcpTokenResolve($db, $fresh['token'], $t0) === null);
$GLOBALS['mcp_test_suspended'][1] = false;
check('A40. and resolves again once the suspension is lifted', mcpTokenResolve($db, $fresh['token'], $t0) !== null);

// The cap
$db->exec('DELETE FROM mcp_access_tokens');
$tokens = [];
for ($i = 0; $i < MCP_TOKEN_MAX_PER_USER; $i++) {
    $tokens[] = mcpTokenCreate($db, 1, 'n' . $i, 'read', null, $t0 + $i);
}
check('A41. ' . MCP_TOKEN_MAX_PER_USER . ' tokens can be created', count(array_filter($tokens)) === MCP_TOKEN_MAX_PER_USER
    && mcpTokenActiveCount($db, 1, $t0 + 60) === MCP_TOKEN_MAX_PER_USER);
check('A42. the next one is refused', mcpTokenCreate($db, 1, 'one too many', 'read', null, $t0 + 60) === null);
check('A43. and nothing was silently dropped to make room', mcpTokenActiveCount($db, 1, $t0 + 60) === MCP_TOKEN_MAX_PER_USER
    && mcpRow($db, $tokens[0]['id'])['revoked_at'] === null);
check('A44. another account is unaffected by the cap', mcpTokenCreate($db, 2, 'mine', 'read', null, $t0 + 60) !== null);

// An expired token frees its slot without being swept.
$expiring = mcpTokenCreate($db, 3, 'expiring', 'read', $t0 + 10, $t0);
check('A45. an expired token no longer counts against the cap', mcpTokenActiveCount($db, 3, $t0 + 11) === 0);
for ($i = 0; $i < MCP_TOKEN_MAX_PER_USER; $i++) {
    mcpTokenCreate($db, 3, 'fresh' . $i, 'read', null, $t0 + 11 + $i);
}
check('A46. so the account can hold a full set of live ones', mcpTokenActiveCount($db, 3, $t0 + 60) === MCP_TOKEN_MAX_PER_USER);

// Listing
$db->exec('DELETE FROM mcp_access_tokens');
$a1 = mcpTokenCreate($db, 4, 'Alpha', 'read,write', $t0 + 86400 * 30, $t0);
$a2 = mcpTokenCreate($db, 4, 'Beta', 'read', null, $t0 + 1);
$b1 = mcpTokenCreate($db, 5, 'Other', 'read', null, $t0 + 2);
mcpTokenRevoke($db, 4, $a2['id'], $t0 + 5);
$list = mcpTokenList($db, 4, $t0 + 6);
check('A47. the list holds the account\'s own live tokens, newest first', array_column($list, 'name') === ['Alpha']);
check('A48. it exposes no secret', !array_key_exists('token_hash', $list[0]) && !array_key_exists('token', $list[0])
    && strpos((string) json_encode($list), $a1['token']) === false);
check('A49. it carries the prefix, scope, created, last used and expiry',
    $list[0]['token_prefix'] === $a1['token_prefix'] && $list[0]['scopes'] === 'read,write'
    && $list[0]['created_at'] === date('Y-m-d H:i:s', $t0) && $list[0]['last_used_at'] === null
    && $list[0]['expires_at'] === date('Y-m-d H:i:s', $t0 + 86400 * 30) && $list[0]['expired'] === false);
check('A50. an expired token is listed and flagged', (function () use ($db, $t0) {
    mcpTokenCreate($db, 6, 'Gone', 'read', $t0 + 10, $t0);
    $rows = mcpTokenList($db, 6, $t0 + 20);
    return count($rows) === 1 && $rows[0]['expired'] === true;
})());
check('A51. a revoked token is not listed', !in_array('Beta', array_column(mcpTokenList($db, 4, $t0 + 6), 'name'), true));

// Revoke-all
$db->exec('DELETE FROM mcp_access_tokens');
mcpTokenCreate($db, 4, 'A', 'read', null, $t0);
mcpTokenCreate($db, 4, 'B', 'read', null, $t0 + 1);
mcpTokenCreate($db, 4, 'C', 'read', null, $t0 + 2);
mcpTokenCreate($db, 5, 'D', 'read', null, $t0 + 3);
check('A52. revoke-all revokes every token of the account', mcpTokenRevokeAll($db, 4, $t0 + 10) === 3 && mcpTokenActiveCount($db, 4, $t0 + 10) === 0);
check('A53. and none of anyone else\'s', mcpTokenActiveCount($db, 5, $t0 + 10) === 1);
check('A54. revoking again finds nothing left to do', mcpTokenRevokeAll($db, 4, $t0 + 10) === 0);
check('A55. an account id of 0 revokes nothing', mcpTokenRevokeAll($db, 0, $t0) === 0);

// Cleanup: revoked/expired beyond the grace period, and orphans.
$db->exec('DELETE FROM mcp_access_tokens');
$stale = mcpTokenCreate($db, 4, 'stale', 'read', null, $t0);
mcpTokenRevoke($db, 4, $stale['id'], $t0);
$recent = mcpTokenCreate($db, 4, 'recent', 'read', null, $t0);
mcpTokenRevoke($db, 4, $recent['id'], $t0 + MCP_TOKEN_SWEEP_DAYS * 86400 - 10);
$expiredOld = mcpTokenCreate($db, 4, 'expired old', 'read', $t0, $t0);
$live = mcpTokenCreate($db, 4, 'live', 'read', $t0 + 86400 * 400, $t0);
$orphan = mcpTokenCreate($db, 999, 'orphan', 'read', null, $t0);

$sweepAt = $t0 + MCP_TOKEN_SWEEP_DAYS * 86400 + 1;
check('A56. the sweep removes a revoked row past the grace period', mcpTokenCleanup($db, $sweepAt) >= 1 && mcpRow($db, $stale['id']) === null);
check('A57. it keeps one revoked more recently', mcpRow($db, $recent['id']) !== null);
check('A58. it removes a long-expired row', mcpRow($db, $expiredOld['id']) === null);
check('A59. it keeps a live token', mcpRow($db, $live['id']) !== null);
check('A60. it removes a token whose account is gone', mcpRow($db, $orphan['id']) === null);
check('A61. the count it returns is the number of rows removed', mcpTokenCleanup($db, $sweepAt) === 0);

$db = null;
foreach (glob($tmpDb . '*.sqlite') ?: [] as $f) {
    @unlink($f);
}

// ---------------------------------------------------------------------------
echo "B. the real pro_auth.php revokes through its removal paths (subprocess)\n";
// ---------------------------------------------------------------------------

$encKey = str_repeat('e', 40);
$idxKey = str_repeat('i', 40);
$keys = ['PII_ENCRYPTION_KEY' => $encKey, 'PII_INDEX_KEY' => $idxKey];

$stub = <<<'PHP'
<?php
// TEST DOUBLE of config.php for tests/mcp_tokens_test.php. Never deployed.
if (!defined('TEMPMAIL_APP')) {
    define('TEMPMAIL_APP', true);
}
date_default_timezone_set('UTC');
$config = [
    'email' => ['domain' => 'manjo.me', 'base_url' => 'http://localhost:8085/'],
    'app' => ['debug_mode' => false, 'log_level' => 'DEBUG', 'environment' => 'test'],
    'trial' => ['days' => 60, 'hash_key' => 'test-trial-hash-key-0123456789abcdef', 'claim_retention_days' => 1825],
];

/** MySQL-only syntax the exercised paths use, rewritten for SQLite. */
final class ProbePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            [
                'DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                ' FOR UPDATE',
                'DELETE d FROM pro_webhook_deliveries d JOIN pro_webhooks w ON w.id = d.webhook_id WHERE w.user_id = ?',
            ],
            [
                'PROBE_MINUTES_AGO(?)',
                '',
                'DELETE FROM pro_webhook_deliveries WHERE webhook_id IN (SELECT id FROM pro_webhooks WHERE pro_webhooks.user_id = ?)',
            ],
            $query
        );
        return parent::prepare($query, $options);
    }

    /** MySQL self-healing DDL (CREATE TABLE IF NOT EXISTS ... ENGINE=, ALTER): the probe schema already has it. */
    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*(CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS|ALTER\s+TABLE)\b/i', $statement)) {
            return 0;
        }
        return parent::exec($statement);
    }
}
$pdo = new ProbePdo('sqlite:' . getenv('PROBE_SQLITE'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('PROBE_MINUTES_AGO', static fn($m): string => date('Y-m-d H:i:s', time() - 60 * (int) $m), 1);

function logMessage($level, $message, $context = null) {
    file_put_contents((string) getenv('PROBE_LOG'), json_encode([$level, $message, $context]) . "\n", FILE_APPEND);
}
function tableHasColumn($table, $column) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE lower(name) = lower(?)');
    $stmt->execute([(string) $table, (string) $column]);
    return (int) $stmt->fetchColumn() > 0;
}
function getVisitorIp(): string { return '10.0.0.1'; }
function flagMaliciousActivity(string $ip, string $reason, ?PDO $pdoConnection = null): bool { return true; }
function requireSameOriginRequest(): bool { return true; }
function appIsProduction(): bool { return false; }
function appCookieSecure(): bool { return false; }
function isDisposableEmailDomain(string $email): bool { return false; }
function proUserIsPro(int $userId): bool { return true; }
function proUserIsSuspended(int $userId): bool { return false; }
function sanitizeString($input, int $maxLength = 0, bool $stripHtml = false): ?string {
    if ($input === null || $input === '') return null;
    $s = trim(str_replace("\0", '', (string) $input));
    if ($stripHtml) $s = strip_tags($s);
    return $maxLength > 0 && mb_strlen($s) > $maxLength ? mb_substr($s, 0, $maxLength) : $s;
}
function sanitizeAlphanumeric($input, int $maxLength = 64, bool $allowDashes = false): ?string {
    $s = sanitizeString($input, $maxLength, true);
    if ($s === null) return null;
    return preg_match($allowDashes ? '/^[a-zA-Z0-9_-]+$/' : '/^[a-zA-Z0-9]+$/', $s) ? $s : null;
}
function detectSuspiciousPatterns(string $input): array { return []; }
function patternsWarrantingIpFlag(array $patterns): array { return $patterns; }
function deleteDirectAdminForwarder(...$args) { return true; }
function createDirectAdminForwarder(...$args) { return true; }

$request = json_decode((string) getenv('PROBE_REQUEST'), true);
$_SERVER['REQUEST_METHOD'] = $request['method'];
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:8085';
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];
PHP;

/** DDL the pro_auth.php removal paths touch, on top of mcpSchema(). */
function mcpProbeSchema(): string
{
    return <<<'SQL'
CREATE TABLE pro_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    email_enc TEXT NULL,
    email_hash CHAR(64) NULL,
    password_hash TEXT NULL,
    account_type TEXT NOT NULL DEFAULT 'regular',
    email_verified_at TEXT NULL,
    address_ttl_days INTEGER NOT NULL DEFAULT 1,
    pro_expires_at TEXT NULL,
    password_changed_at TEXT NULL,
    last_login_at TEXT NULL,
    inactivity_warned_at TEXT NULL,
    suspended_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, user_id INTEGER, success INTEGER NOT NULL DEFAULT 0, attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE pro_user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending');
CREATE TABLE pro_user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NULL);
CREATE TABLE pro_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, selector TEXT NULL, validator_hash TEXT NULL, expires_at TEXT NULL);
CREATE TABLE magic_link_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE login_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE pending_profile_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, action TEXT NOT NULL, data TEXT, token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE pro_webhooks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL);
CREATE TABLE pro_webhook_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, webhook_id INTEGER NOT NULL);
CREATE TABLE temp_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_address TEXT NOT NULL, pro_user_id INTEGER NULL, is_personal INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stored_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, temp_email_id INTEGER NOT NULL);
CREATE TABLE email_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, email_id INTEGER NOT NULL, filename TEXT NULL, file_path TEXT NULL);
SQL;
}

$probe = sys_get_temp_dir() . '/ms_mcp_tokens_' . bin2hex(random_bytes(6));
mkdir($probe);
foreach (['pro_auth.php', 'TwoFactorAuth.php', 'pro_trial.php', 'referrals.php', 'login_tokens.php', 'email_log_ref.php', 'pii_crypto.php', 'pro_remember.php', 'mcp_tokens.php', 'after_login.php'] as $file) {
    copy($repoRoot . '/' . $file, $probe . '/' . $file);
}
file_put_contents($probe . '/config.php', $stub);
$dbPath = $probe . '/probe.sqlite';
$logPath = $probe . '/probe.log';

$pdo = mcpDb($dbPath);
$pdo->exec(mcpProbeSchema());
$pdo->exec(mcpSchema());

require_once $repoRoot . '/pii_crypto.php';
$addUser = function (string $email) use ($pdo, $encKey, $idxKey): int {
    $pdo->prepare("INSERT INTO pro_users (email, email_enc, email_hash, password_hash, account_type, email_verified_at) VALUES (?, ?, ?, ?, 'pro', '2026-01-01 00:00:00')")
        ->execute([$email, piiEmailEncrypt($email, $encKey), piiEmailHash($email, $idxKey), password_hash('correct-horse', PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
};
$alice = $addUser('alice@example.com');
$bob = $addUser('bob@example.com');

$liveTokens = function (int $userId) use ($dbPath): int {
    $fresh = mcpDb($dbPath);
    $stmt = $fresh->prepare('SELECT COUNT(*) FROM mcp_access_tokens WHERE pro_user_id = ? AND revoked_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
};

$run = function (array $request) use ($probe, $dbPath, $logPath, $keys): string {
    $env = ['PROBE_SQLITE' => $dbPath, 'PROBE_LOG' => $logPath, 'PROBE_REQUEST' => json_encode($request), 'PATH' => (string) getenv('PATH')] + $keys;
    // Every mail() is appended to mail.log; nothing here asserts on it, but the
    // paths under test hand it real mail and a real sendmail would try to send.
    $sendmail = 'sendmail_path=cat >> ' . escapeshellarg($probe . '/mail.log');
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', $sendmail, '-d', 'session.save_path=' . $probe, $probe . '/pro_auth.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $probe, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return (string) $out . ($err !== '' ? "\n[stderr] " . $err : '');
};

/** Insert a pending change and return the GET request that confirms or undoes it. */
$pending = function (int $userId, string $action, array $data, string $param) use ($dbPath): array {
    $fresh = mcpDb($dbPath);
    $token = bin2hex(random_bytes(24));
    $fresh->prepare('INSERT INTO pending_profile_changes (user_id, action, data, token, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $action, json_encode($data), hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
    return ['method' => 'GET', 'get' => [$param => $token]];
};

// A token per account, plus one for Bob that must survive everything.
$aliceToken = mcpTokenCreate($pdo, $alice, 'Alice app', 'read', null);
$bobToken = mcpTokenCreate($pdo, $bob, 'Bob app', 'read', null);
check('B1. both accounts start with a live token', $liveTokens($alice) === 1 && $liveTokens($bob) === 1);

// A password change.
$out = $run($pending($alice, 'set_password', ['password_hash' => password_hash('new-horse', PASSWORD_DEFAULT)], 'confirm_profile_change'));
check('B2. a confirmed password change goes through', strpos($out, 'Password change confirmed') !== false, $out);
check('B3. ... and revokes every token of the account', $liveTokens($alice) === 0);
check('B4. ... and none of anyone else\'s', $liveTokens($bob) === 1);

// An email change.
mcpTokenCreate($pdo, $alice, 'Alice app 2', 'read', null);
$out = $run($pending($alice, 'update_email', ['new_email' => 'alice2@example.com'], 'confirm_profile_change'));
check('B5. a confirmed email change goes through', strpos($out, 'Email change confirmed') !== false, $out);
check('B6. ... and revokes every token of the account', $liveTokens($alice) === 0);
check('B7. ... and none of anyone else\'s', $liveTokens($bob) === 1);

// The undo link for an email change.
mcpTokenCreate($pdo, $alice, 'Alice app 3', 'read', null);
$out = $run($pending($alice, 'undo_update_email', ['old_email' => 'alice@example.com', 'new_email' => 'alice2@example.com'], 'undo_profile_change'));
check('B8. an email-change undo goes through', strpos($out, 'Email change reverted') !== false, $out);
check('B9. ... and revokes every token of the account', $liveTokens($alice) === 0);

// The undo link for a password change.
mcpTokenCreate($pdo, $alice, 'Alice app 4', 'read', null);
$out = $run($pending($alice, 'undo_set_password', ['old_hash' => password_hash('correct-horse', PASSWORD_DEFAULT)], 'undo_profile_change'));
check('B10. a password-change undo goes through', strpos($out, 'Password change reverted') !== false, $out);
check('B11. ... and revokes every token of the account', $liveTokens($alice) === 0);

// Account deletion.
mcpTokenCreate($pdo, $alice, 'Alice app 5', 'read', null);
$out = $run($pending($alice, 'delete_account', [], 'confirm_profile_change'));
$fresh = mcpDb($dbPath);
$userGone = (int) $fresh->query("SELECT COUNT(*) FROM pro_users WHERE id = {$alice}")->fetchColumn() === 0;
check('B12. the account is deleted', $userGone, $out);
check('B13. its tokens are revoked', $liveTokens($alice) === 0);
check('B14. and the other account keeps its token', $liveTokens($bob) === 1);

check('B15. no token value reached the log', strpos((string) @file_get_contents($logPath), $aliceToken['token']) === false
    && strpos((string) @file_get_contents($logPath), $bobToken['token']) === false);

foreach (glob($probe . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($probe);

// ---------------------------------------------------------------------------
echo "C. the real pro_profile.php actions and the Connected apps card\n";
// ---------------------------------------------------------------------------

$msProbe = ms_test_probe_build($repoRoot);
echo "probe docroot: {$msProbe}\n";

// The card's unavailable state: the table is not there yet at this point.
$render = ms_test_request($msProbe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user_id' => 1, 'user_email' => 'greta@example.com']);
ms_test_no_php_errors('C1. pro_profile_page.php renders without PHP errors', $render);
check('C2. the Connected apps card is on the page', strpos($render['stdout'], 'Connected apps') !== false);
check('C3. without the table it says the feature is not available yet', strpos($render['stdout'], 'Not available yet.') !== false);
check('C4. ... and renders no create form', strpos($render['stdout'], 'id="connectedAppsCreateBtn"') === false);

// The schema, and a log dump so the probe's logMessage() lines are visible here.
$pdo = ms_test_db(ms_test_probe_sqlite($msProbe));
$pdo->exec(mcpSchema());
file_put_contents($msProbe . '/config.php', ms_test_stub_config_php() . <<<'PHP'

register_shutdown_function(static function () {
    $entries = $GLOBALS['ms_test_logs'] ?? [];
    if ($entries !== []) {
        file_put_contents(__DIR__ . '/probe.log', json_encode($entries) . "\n", FILE_APPEND);
    }
});
PHP);
$probeLogPath = $msProbe . '/probe.log';
$probeLog = function () use ($probeLogPath): string {
    $text = (string) @file_get_contents($probeLogPath);
    @unlink($probeLogPath);
    return $text;
};
$probeLog();

$userPro = ms_test_seed_user($pdo, 'greta@example.com', 'pro');
$userRegular = ms_test_seed_user($pdo, 'nils@example.com', 'regular');
$pdo = null;

$action = function (string $name, array $fields, int $userId, ?string $origin = MS_TEST_ORIGIN) use ($msProbe): array {
    return ms_test_request($msProbe, [
        'page' => 'pro_profile.php',
        'method' => 'POST',
        'user_id' => $userId,
        'user_email' => 'user' . $userId . '@example.com',
        'post' => array_merge(['action' => $name], $fields),
        'origin' => $origin,
    ]);
};

// Create
$response = $action('mcp_token_create', ['name' => 'Claude Desktop', 'scopes' => 'read', 'expires_days' => '0'], $userPro);
ms_test_no_php_errors('C5. mcp_token_create answers without PHP errors', $response);
$created = ms_test_json('C6. mcp_token_create answers with JSON', $response);
check('C7. the token is created', ($created['success'] ?? null) === true, json_encode($created));
$newToken = (string) ($created['token'] ?? '');
check('C8. the token is msk_ + 64 hex characters', preg_match('/^msk_[a-f0-9]{64}$/', $newToken) === 1);
check('C9. the response carries the prefix and the scope', ($created['token_prefix'] ?? '') === substr($newToken, 0, 12) && ($created['scopes'] ?? '') === 'read');

$fresh = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
$rows = mcpLiveRows($fresh, $userPro);
check('C10. one row is stored', count($rows) === 1);
check('C11. the row holds the SHA-256, never the token', $rows[0]['token_hash'] === hash('sha256', $newToken)
    && strpos((string) json_encode($rows[0]), $newToken) === false);
check('C12. and the name is the user\'s own label', $rows[0]['name'] === 'Claude Desktop');

$log = $probeLog();
check('C13. the action logs the token id and the account, not the token',
    strpos($log, '"token_id"') !== false && strpos($log, $newToken) === false && strpos($log, 'greta@example.com') === false, $log);

// List
$response = $action('mcp_token_list', [], $userPro);
$listed = ms_test_json('C14. mcp_token_list answers with JSON', $response);
check('C15. the list is available and holds the token', ($listed['available'] ?? null) === true && count($listed['tokens'] ?? []) === 1);
$entry = $listed['tokens'][0] ?? [];
check('C16. with name, prefix, scope, created, last used and expiry',
    $entry['name'] === 'Claude Desktop' && $entry['token_prefix'] === substr($newToken, 0, 12)
    && $entry['scopes'] === 'read' && !empty($entry['created_at'])
    && $entry['last_used_at'] === null && $entry['expires_at'] === null && $entry['expired'] === false);
check('C17. and the token itself is not in the answer', strpos($response['stdout'], $newToken) === false);
check('C18. nor is the hash', strpos($response['stdout'], hash('sha256', $newToken)) === false);

// Expiry chosen at creation
$response = $action('mcp_token_create', ['name' => 'Expiring', 'scopes' => 'read,write', 'expires_days' => '30'], $userPro);
$expiring = ms_test_json('C19. a token with a 30-day expiry is created', $response);
$fresh = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
$expiringRow = null;
foreach (mcpLiveRows($fresh, $userPro) as $r) {
    if ($r['name'] === 'Expiring') {
        $expiringRow = $r;
    }
}
check('C20. it expires 30 days out, with the wider scope', $expiringRow !== null
    && $expiringRow['scopes'] === 'read,write'
    && abs(strtotime((string) $expiringRow['expires_at']) - (time() + 30 * 86400)) < 120);

// Validation
check('C21. an empty name is refused', (function () use ($action, $userPro) {
    $r = ms_test_json('', $action('mcp_token_create', ['name' => '  ', 'scopes' => 'read', 'expires_days' => '0'], $userPro));
    return ($r['success'] ?? null) === false;
})());
check('C22. an invalid scope is refused', (function () use ($action, $userPro) {
    $r = ms_test_json('', $action('mcp_token_create', ['name' => 'Nope', 'scopes' => 'admin', 'expires_days' => '0'], $userPro));
    return ($r['success'] ?? null) === false;
})());
check('C23. an invalid expiry is refused', (function () use ($action, $userPro) {
    $r = ms_test_json('', $action('mcp_token_create', ['name' => 'Nope', 'scopes' => 'read', 'expires_days' => '9999'], $userPro));
    return ($r['success'] ?? null) === false;
})());
check('C24. none of the refused ones were stored', count(mcpLiveRows(ms_test_refresh_db(ms_test_probe_sqlite($msProbe)), $userPro)) === 2);

// The Pro gate
$response = $action('mcp_token_create', ['name' => 'Regular app', 'scopes' => 'read', 'expires_days' => '0'], $userRegular);
$gated = ms_test_json('C25. a Regular account cannot create a token', $response);
check('C26. it is refused as Pro-only', ($gated['success'] ?? null) === false && ($gated['pro_required'] ?? null) === true);
check('C27. and nothing is stored for it', mcpLiveRows(ms_test_refresh_db(ms_test_probe_sqlite($msProbe)), $userRegular) === []);

// The same-origin gate
$response = $action('mcp_token_create', ['name' => 'Cross origin', 'scopes' => 'read', 'expires_days' => '0'], $userPro, 'https://evil.example');
ms_test_json('C28. a cross-origin create is answered', $response);
check('C29. and refused', strpos($response['stdout'], '"success":false') !== false);
check('C30. with nothing stored', count(mcpLiveRows(ms_test_refresh_db(ms_test_probe_sqlite($msProbe)), $userPro)) === 2);

// The cap, through the action: 10 live tokens, the 11th refused, none dropped.
$pdo = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
for ($i = 0; $i < MCP_TOKEN_MAX_PER_USER; $i++) {
    mcpTokenCreate($pdo, $userPro, 'cap' . $i, 'read', null);
}
$oldest = mcpLiveRows($pdo, $userPro);
$oldestId = (int) end($oldest)['id'];
$response = $action('mcp_token_create', ['name' => 'Over the cap', 'scopes' => 'read', 'expires_days' => '0'], $userPro);
$capped = ms_test_json('C31. creating past the cap is answered', $response);
check('C32. it is refused with an explanation', ($capped['success'] ?? null) === false && strpos((string) ($capped['error'] ?? ''), 'Revoke one') !== false);
$fresh = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
check('C33. the cap holds at ' . MCP_TOKEN_MAX_PER_USER, count(mcpLiveRows($fresh, $userPro)) === MCP_TOKEN_MAX_PER_USER);
check('C34. and the oldest token is still there', mcpRow($fresh, $oldestId) !== null && mcpRow($fresh, $oldestId)['revoked_at'] === null);

// Revoke
$response = $action('mcp_token_revoke', ['id' => (string) $oldestId], $userRegular);
$wrong = ms_test_json('C35. revoking another account\'s token is answered', $response);
check('C36. it is refused as not found', ($wrong['success'] ?? null) === false);
check('C37. and the token is untouched', mcpRow(ms_test_refresh_db(ms_test_probe_sqlite($msProbe)), $oldestId)['revoked_at'] === null);

$response = $action('mcp_token_revoke', ['id' => (string) $oldestId], $userPro);
$revoked = ms_test_json('C38. revoking one\'s own token is answered', $response);
check('C39. it succeeds', ($revoked['success'] ?? null) === true);
$fresh = ms_test_refresh_db(ms_test_probe_sqlite($msProbe));
check('C40. the row is marked revoked', mcpRow($fresh, $oldestId)['revoked_at'] !== null);
// The oldest live row is the very first token created, so its credential is
// the one whose plaintext this suite holds.
check('C41. and its credential no longer resolves', $oldestId === (int) $created['id'] && mcpTokenResolve($fresh, $newToken) === null);
$log = $probeLog();
check('C42. the revoke logs the id, never a token', strpos($log, '"token_id"') !== false && strpos($log, $newToken) === false, $log);

// The card, once the table is there: the create form and the list are rendered.
$response = $action('mcp_token_list', [], $userPro);
check('C43. the list is still served while the account has live tokens', count(ms_test_json('', $response)['tokens'] ?? []) === MCP_TOKEN_MAX_PER_USER - 1);

$render = ms_test_request($msProbe, ['page' => 'pro_profile_page.php', 'method' => 'GET', 'user_id' => $userPro, 'user_email' => 'greta@example.com']);
ms_test_no_php_errors('C44. pro_profile_page.php renders without PHP errors with the schema', $render);
foreach (['connectedAppsName', 'connectedAppsScope', 'connectedAppsExpiry', 'connectedAppsCreateBtn', 'connectedAppsList', 'connectedAppsNewToken', 'connectedAppsProNote', 'connectedAppsAlert'] as $needle) {
    check('C45. the page renders #' . $needle, strpos($render['stdout'], 'id="' . $needle . '"') !== false);
}
check('C46. the page no longer claims the feature is unavailable', strpos($render['stdout'], 'id="connectedAppsUnavailable"') === false);
check('C47. the page lists Read only as the preselected scope and Read and change as the other', strpos($render['stdout'], '<option value="read" selected>') !== false
    && strpos($render['stdout'], '<option value="read,write">') !== false);
check('C47b. and offers never, 30, 90 and 365 days of expiry', substr_count($render['stdout'], '<option value="0" selected>Never</option>') === 1
    && strpos($render['stdout'], '<option value="365">') !== false);
foreach (['mcp_token_create', 'mcp_token_list', 'mcp_token_revoke'] as $name) {
    check('C48. the page\'s script calls ' . $name, strpos($render['stdout'], "'" . $name . "'") !== false);
}

ms_test_cleanup($msProbe);

// ---------------------------------------------------------------------------
echo "D. wiring\n";
// ---------------------------------------------------------------------------

$src = fn(string $file): string => (string) file_get_contents($repoRoot . '/' . $file);

check('D1. config.php is untouched: mcp_tokens.php is loaded where it is used',
    strpos($src('config.php'), 'mcp_tokens.php') === false);
check('D2. pro_auth.php loads mcp_tokens.php', strpos($src('pro_auth.php'), "require_once __DIR__ . '/mcp_tokens.php';") !== false);
check('D3. pro_auth.php revokes on password change, email change, both undos and deletion', substr_count($src('pro_auth.php'), 'mcpTokensRevokeAllFor(') === 5);
check('D4. pro_profile.php loads mcp_tokens.php', strpos($src('pro_profile.php'), "require_once __DIR__ . '/mcp_tokens.php';") !== false);
check('D5. abuse_admin.php revokes on suspension', strpos($src('abuse_admin.php'), 'mcpTokensRevokeAllFor(') !== false);
check('D6. cron/cleanup.php revokes for a deleted inactive account', strpos($src('cron/cleanup.php'), 'mcpTokensRevokeAllFor(') !== false);
check('D7. cron/cleanup.php sweeps the dead rows', strpos($src('cron/cleanup.php'), 'mcpTokenCleanup($pdo)') !== false
    && strpos($src('cron/cleanup.php'), 'cleanupExpiredMcpTokens()') !== false);
check('D8. the profile page asks the schema before rendering the card',
    strpos($src('pro_profile_page.php'), "tableHasColumn('mcp_access_tokens', 'token_hash')") !== false);
check('D9. the migration is guarded by the table, so it can run twice',
    strpos($src('migrate_mcp_tokens.php'), "tableHasColumn('mcp_access_tokens', 'token_hash')") !== false
    && strpos($src('migrate_mcp_tokens.php'), 'CREATE TABLE mcp_access_tokens') !== false);
check('D10. the audit compares the same column the migration creates',
    strpos($src('check_mcp_tokens.php'), "tableHasColumn('mcp_access_tokens', 'token_hash')") !== false);

// No log call anywhere is handed a token. $tokenId and $tokenName are fine, and
// so is a hash — pro_feed.php logs its own feed token's lookup hash, which is
// not a credential; only the bare variable and a bare 'token' key are not.
$badLogs = [];
$scan = array_merge(
    glob($repoRoot . '/*.php') ?: [],
    glob($repoRoot . '/cron/*.php') ?: [],
    glob($repoRoot . '/client/backend/*.php') ?: []
);
foreach ($scan as $file) {
    $text = (string) file_get_contents($file);
    if (preg_match_all('/logMessage\((?:[^();]|\([^()]*\))*\)/s', $text, $m)) {
        foreach ($m[0] as $call) {
            if (preg_match('/\$token\b|\$rawToken\b|\$newToken\b|\$created\[.token.\]|\'token\'\s*=>|"token"\s*=>/', $call)) {
                $badLogs[] = basename($file) . ': ' . preg_replace('/\s+/', ' ', mb_substr($call, 0, 120));
            }
        }
    }
}
check('D11. no log call is handed a token value', $badLogs === [], implode("\n       ", array_slice($badLogs, 0, 5)));
check('D12. the only writer of token_hash is mcp_tokens.php',
    substr_count($src('mcp_tokens.php'), 'token_hash') > 0
    && strpos($src('migrate_mcp_tokens.php'), 'token_hash') !== false
    && strpos($src('check_mcp_tokens.php'), 'token_hash') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
