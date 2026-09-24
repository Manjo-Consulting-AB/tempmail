<?php

declare(strict_types=1);

/**
 * Regression coverage for the client-agent script persistence in
 * client/backend/bootstrap.php and the DELETE handler in client/backend/api.php.
 *
 * Run with:  php tests/client_scripts_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Needs pdo_sqlite and nothing
 * else: no MySQL, no network. The suite copies the real bootstrap.php, api.php
 * and client/agent/bootstrap.php into a throwaway docroot next to a stub
 * config.php whose $pdo is a SQLite file, so the code under test is the code
 * that ships. What SQLite cannot show: the MySQL-only `SELECT ... FOR UPDATE`
 * row lock and the spam_filters_json ALTER on installs that lack the column.
 *
 * The bug this guards against: every write used to be "read all scripts,
 * change one, save all", and the save deleted every row missing from its
 * snapshot, so a request working from a stale snapshot deleted scripts other
 * users had created meanwhile, and concurrent edits overwrote each other.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This suite needs the pdo_sqlite extension.\n");
    exit(1);
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  [pass] ' : '  [FAIL] ') . $label . ($ok || $detail === '' ? '' : " -- {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------
// Probe docroot
// ---------------------------------------------------------------------

$repo = dirname(__DIR__);
$probe = rtrim(sys_get_temp_dir(), '/') . '/ms-client-scripts-test-' . bin2hex(random_bytes(4));
mkdir($probe . '/client/backend', 0700, true);
mkdir($probe . '/client/agent', 0700, true);
mkdir($probe . '/sessions', 0700, true);
register_shutdown_function(static function () use ($probe): void {
    rrmdir($probe);
});

foreach (['client/backend/bootstrap.php', 'client/backend/api.php', 'client/agent/bootstrap.php'] as $file) {
    copy($repo . '/' . $file, $probe . '/' . $file);
}
$dbPath = $probe . '/test.sqlite';

// Stub config.php: only the database and the helpers the paths under test
// call. proUserIsPro() says yes for everyone, because Pro gating is not what
// this suite is about.
file_put_contents($probe . '/config.php', <<<'PHP'
<?php
if (!isset($GLOBALS['pdo'])) {
    $GLOBALS['pdo'] = new PDO('sqlite:' . getenv('MS_CLIENT_SCRIPTS_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
$pdo = $GLOBALS['pdo'];
if (!function_exists('logMessage')) {
    function logMessage($level, $message, $context = null) {}
}
if (!function_exists('proUserIsPro')) {
    function proUserIsPro(int $userId): bool { return true; }
}
if (!function_exists('requireSameOriginRequest')) {
    // The CSRF gate itself is covered by tests/webhook_routing_test.php; here
    // every simulated request is the site's own same-origin call.
    function requireSameOriginRequest(): bool { return true; }
}
PHP);

// Runs the real api.php once as user $userId, in its own process (api.php
// always ends in exit).
file_put_contents($probe . '/run_api.php', <<<'PHP'
<?php
[$_, $method, $uri, $userId] = $argv;
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.save_path', __DIR__ . '/sessions');
session_start();
$_SESSION['pro_user_id'] = (int) $userId;
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
require __DIR__ . '/client/backend/api.php';
PHP);

putenv('MS_CLIENT_SCRIPTS_DB=' . $dbPath);

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE client_scripts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    script_id TEXT NOT NULL UNIQUE,
    owner_pro_user_id INTEGER NULL,
    label TEXT NOT NULL DEFAULT 'New script',
    target_host TEXT NULL,
    target_path TEXT NOT NULL DEFAULT '/client/agent/agent.php',
    greylist_days INTEGER NOT NULL DEFAULT 30,
    whitelist_json TEXT NULL,
    blacklist_json TEXT NULL,
    spam_filters_json TEXT NULL,
    pending_sync_at TEXT NULL,
    last_webhook_sent_at TEXT NULL,
    sync_status TEXT NOT NULL DEFAULT 'idle',
    sync_message TEXT NULL,
    last_webhook_result_json TEXT NULL,
    dry_run INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$GLOBALS['pdo'] = $pdo;

define('TEMPMAIL_APP', true);
require $probe . '/client/backend/bootstrap.php';

function api(string $method, string $uri, int $userId): array
{
    global $probe;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe . '/run_api.php') . ' '
        . escapeshellarg($method) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg((string) $userId) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $json = json_decode($out, true);
    return is_array($json) ? $json : ['raw' => $out];
}

function rowCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM client_scripts')->fetchColumn();
}

const USER_A = 101;
const USER_B = 202;

// ---------------------------------------------------------------------
echo "\n== Storage detection on SQLite ==\n";
check('clientBackendUseDatabase() sees the client_scripts table', clientBackendUseDatabase());
check('spam_filters_json column detected', clientBackendHasDbColumn('client_scripts', 'spam_filters_json'));

// ---------------------------------------------------------------------
echo "\n== Create and read one row ==\n";
$a1 = clientBackendCreateScript(['owner_pro_user_id' => USER_A, 'label' => 'A one']);
$fetched = clientBackendGetScript($a1['script_id']);
check('created script is readable by id', $fetched !== null && $fetched['label'] === 'A one');
check('owner is stored', $fetched !== null && $fetched['owner_pro_user_id'] === USER_A);
check('unknown id reads as null', clientBackendGetScript('zzzzzzzzzzzz') === null);

// ---------------------------------------------------------------------
echo "\n== A stale snapshot never deletes another user's script ==\n";
$snapshotOfA = clientBackendGetScriptsForUser(USER_A);          // request 1 reads
$b1 = clientBackendCreateScript(['owner_pro_user_id' => USER_B, 'label' => 'B one']); // request 2 creates
clientBackendUpdateScript($a1['script_id'], ['label' => 'A renamed']);   // request 1 writes
clientBackendAddListItem($a1['script_id'], 'whitelist', 'friend@example.com');
check("user B's script created after A's read still exists", clientBackendGetScript($b1['script_id']) !== null);
check("A's update landed", (clientBackendGetScript($a1['script_id'])['label'] ?? null) === 'A renamed');
check('snapshot of A held only A\'s scripts', array_keys($snapshotOfA) === [$a1['script_id']]);

// ---------------------------------------------------------------------
echo "\n== Updating one script leaves every other row untouched ==\n";
$bBefore = $pdo->query("SELECT * FROM client_scripts WHERE script_id = " . $pdo->quote($b1['script_id']))->fetch(PDO::FETCH_ASSOC);
clientBackendUpdateScript($a1['script_id'], ['greylist_days' => 7]);
clientBackendAddSpamFilter($a1['script_id'], ['type' => 'text', 'scope' => 'subject', 'pattern' => 'win a prize']);
clientBackendScheduleSync($a1['script_id']);
$bAfter = $pdo->query("SELECT * FROM client_scripts WHERE script_id = " . $pdo->quote($b1['script_id']))->fetch(PDO::FETCH_ASSOC);
check("B's row is byte-for-byte unchanged", $bBefore === $bAfter);
$a = clientBackendGetScript($a1['script_id']);
check('greylist_days updated', ($a['greylist_days'] ?? null) === 7);
check('spam filter stored', count($a['spam_filters'] ?? []) === 1);
check('pending sync scheduled', !empty($a['pending_sync_at']));

// ---------------------------------------------------------------------
echo "\n== Interleaved list edits on the same script keep both items ==\n";
$staleA = clientBackendGetScript($a1['script_id']);             // request 1 has an old copy
clientBackendAddListItem($a1['script_id'], 'whitelist', 'second@example.com'); // request 2
clientBackendAddListItem($a1['script_id'], 'whitelist', 'third@example.com');  // request 1
$whitelist = clientBackendGetScript($a1['script_id'])['whitelist'] ?? [];
check('all three whitelist entries present', $whitelist === ['friend@example.com', 'second@example.com', 'third@example.com'], json_encode($whitelist));
check('stale copy did not have them', !in_array('second@example.com', $staleA['whitelist'], true));
clientBackendRemoveListItem($a1['script_id'], 'whitelist', 'second@example.com');
check('remove drops only that entry', (clientBackendGetScript($a1['script_id'])['whitelist'] ?? []) === ['friend@example.com', 'third@example.com']);
check('invalid pattern is refused', clientBackendAddListItem($a1['script_id'], 'blacklist', '   ') === null);
check('list edit on unknown script is null', clientBackendAddListItem('zzzzzzzzzzzz', 'whitelist', 'x@example.com') === null);

// ---------------------------------------------------------------------
echo "\n== Generic update cannot move ownership or identity ==\n";
clientBackendUpdateScript($a1['script_id'], ['owner_pro_user_id' => USER_B, 'script_id' => $b1['script_id'], 'label' => 'still A']);
$a = clientBackendGetScript($a1['script_id']);
check('owner unchanged', ($a['owner_pro_user_id'] ?? null) === USER_A);
check('label change applied to the right row', ($a['label'] ?? null) === 'still A');
check("B's label untouched", (clientBackendGetScript($b1['script_id'])['label'] ?? null) === 'B one');

// ---------------------------------------------------------------------
echo "\n== Owner-scoped listing and delete ==\n";
$a2 = clientBackendCreateScript(['owner_pro_user_id' => USER_A, 'label' => 'A two']);
check('A lists exactly its two scripts', array_keys(clientBackendGetScriptsForUser(USER_A)) == [$a2['script_id'], $a1['script_id']]);
check('B lists exactly its one script', array_keys(clientBackendGetScriptsForUser(USER_B)) === [$b1['script_id']]);
check('B cannot delete A\'s script', clientBackendDeleteScript($a2['script_id'], USER_B) === false);
check('A\'s script survived B\'s attempt', clientBackendGetScript($a2['script_id']) !== null);
check('A deletes its own script', clientBackendDeleteScript($a2['script_id'], USER_A) === true);
check('deleted script is gone', clientBackendGetScript($a2['script_id']) === null);
check('only that one row went', rowCount($pdo) === 2);

// ---------------------------------------------------------------------
echo "\n== api.php DELETE handler (real file, subprocess) ==\n";
$a3 = clientBackendCreateScript(['owner_pro_user_id' => USER_A, 'label' => 'A three']);
$res = api('DELETE', '/api/mailfilter/' . $a3['script_id'], USER_B);
check('B deleting A\'s script gets "Script not found"', ($res['message'] ?? null) === 'Script not found', json_encode($res));
check('A\'s script still there after B\'s DELETE', clientBackendGetScript($a3['script_id']) !== null);
$res = api('DELETE', '/api/mailfilter/' . $a3['script_id'], USER_A);
check('A deleting its script answers ok', ($res['status'] ?? null) === 'ok' && ($res['deleted_script_id'] ?? null) === $a3['script_id'], json_encode($res));
check('A\'s script removed', clientBackendGetScript($a3['script_id']) === null);
check('the other scripts survive the DELETE', clientBackendGetScript($a1['script_id']) !== null && clientBackendGetScript($b1['script_id']) !== null);

echo "\n== api.php routing after the precedence fix ==\n";
$res = api('GET', '/api/mailfilter/scripts', USER_B);
check('GET /api/mailfilter/scripts lists B\'s script', ($res['status'] ?? null) === 'ok' && array_column($res['scripts'] ?? [], 'script_id') === [$b1['script_id']], json_encode($res));
$res = api('GET', '/api/mailfilter/' . $a1['script_id'] . '/status', USER_A);
check('GET /api/mailfilter/{id}/status works for the owner', ($res['script']['script_id'] ?? null) === $a1['script_id'], json_encode($res));
$res = api('GET', '/nope/mailfilter/scripts', USER_B);
check('a first segment other than "api" no longer reaches the handler', ($res['message'] ?? null) === 'Backend scaffold ready', json_encode($res));

// ---------------------------------------------------------------------
echo "\n== No whole-table writer remains ==\n";
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php' || strpos($path, '/vendor/') !== false || strpos($path, '/.git/') !== false
        || strpos($path, '/.claude/') !== false || $path === __FILE__) {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (strpos($src, 'clientBackendSaveScripts') !== false || strpos($src, 'clientBackendDbSaveScripts') !== false) {
        $offenders[] = substr($path, strlen($repo) + 1);
    }
}
check('nothing references the removed save-all functions', $offenders === [], implode(', ', $offenders));
$bootstrapSrc = (string) file_get_contents($repo . '/client/backend/bootstrap.php');
preg_match_all('/DELETE FROM client_scripts[^\'"]*/', $bootstrapSrc, $deletes);
check('the only client_scripts DELETE is owner-scoped', $deletes[0] === ['DELETE FROM client_scripts WHERE script_id = ? AND owner_pro_user_id = ?'], json_encode($deletes[0]));

echo "\n" . ($failures === 0 ? 'All checks passed.' : "{$failures} check(s) failed.") . "\n";
exit($failures === 0 ? 0 : 1);
