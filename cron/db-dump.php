<?php
/**
 * Database dump with rotation and a free-space check. CLI only. Usage:
 *
 *   php cron/db-dump.php --dir=/path/outside/webroot [--keep=3] [--min-free-mb=500] [--skip-log-data]
 *
 * The crontab line is added by the operator; once a night is enough, the
 * data changes little and most of it expires on its own:
 *
 *   15 3 * * * php /path/to/cron/db-dump.php --dir=/path/to/dumps
 *
 *  --dir           target directory (or DB_DUMP_DIR); must exist and must not be
 *                  inside the document root
 *  --keep          dumps to keep (default 3, DB_DUMP_KEEP)
 *  --min-free-mb   refuse to start with less free space than this (default 500)
 *  --skip-log-data dump the structure of system_logs but not its rows (the
 *                  largest table, and rebuilt by use)
 *
 * Before a run it needs max(min-free, 2 x the newest dump) free. When that is
 * not the case it first removes the oldest dumps (never the newest one); if
 * there is still not enough room it exits 1 without writing anything, so a
 * full disk never leaves a truncated dump behind. The dump is written to a
 * .tmp file and renamed only when mysqldump exited 0, so a failed run never
 * replaces or counts as a backup. The password goes to mysqldump through the
 * environment, never the command line. Exit: 0 done, 1 failed or no room.
 */
define('TEMPMAIL_APP', true);
require_once __DIR__ . '/_guard.php';
cronRequireCli();
require_once __DIR__ . '/../config.php';

$opts = getopt('', ['dir:', 'keep:', 'min-free-mb:', 'skip-log-data']);
$dir = rtrim((string) ($opts['dir'] ?? ($_ENV['DB_DUMP_DIR'] ?? '')), '/');
$keep = max(1, (int) ($opts['keep'] ?? ($_ENV['DB_DUMP_KEEP'] ?? 3)));
$minFree = max(0, (int) ($opts['min-free-mb'] ?? 500)) * 1048576;
$skipLogData = isset($opts['skip-log-data']);

$fail = static function (string $msg): void {
    fwrite(STDERR, $msg . "\n");
    if (function_exists('logMessage')) {
        logMessage('ERROR', 'Database dump failed: ' . $msg);
    }
    exit(1);
};

if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
    $fail('--dir is missing, not a directory or not writable');
}
$real = realpath($dir);
$docroot = realpath(__DIR__ . '/..');
if ($real === false || $docroot === false || $real === $docroot || strpos($real . '/', $docroot . '/') === 0) {
    $fail('--dir must be outside the application directory');
}

$lock = fopen(sys_get_temp_dir() . '/tempmail_db_dump.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "already running\n";
    exit(0);
}

$db = $config['db'] ?? [];
if (empty($db['name']) || empty($db['user'])) {
    $fail('database settings are missing');
}

$list = static function () use ($real): array {
    $files = glob($real . '/tempmail-db-*.sql.gz') ?: [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a)); // newest first
    return $files;
};

// Space: twice the newest dump (or the floor), freeing old dumps if short.
$existing = $list();
$need = max($minFree, $existing ? 2 * (int) filesize($existing[0]) : 0);
while (disk_free_space($real) < $need && count($existing) > 1) {
    $old = array_pop($existing);
    if (@unlink($old)) {
        echo "Removed " . basename($old) . " to make room\n";
    } else {
        break;
    }
}
if (disk_free_space($real) < $need) {
    $fail(sprintf('not enough free space (%d MB free, %d MB needed)', disk_free_space($real) / 1048576, $need / 1048576));
}

$base = ['mysqldump', '--single-transaction', '--quick', '--no-tablespaces', '--default-character-set=utf8mb4'];
if (!empty($db['socket'])) {
    $base[] = '--socket=' . $db['socket'];
} else {
    $base[] = '--host=' . ($db['host'] ?? 'localhost');
    $base[] = '--port=' . (int) ($db['port'] ?? 3306);
}
$base[] = '--user=' . $db['user'];

$name = 'tempmail-db-' . date('Ymd-His') . '.sql.gz';
$tmp = $real . '/' . $name . '.tmp';
$out = gzopen($tmp, 'wb6');
if ($out === false) {
    $fail('could not open the target file');
}

// With --skip-log-data: structure of system_logs first, then everything else minus its rows.
$runs = [];
if ($skipLogData) {
    $runs[] = array_merge($base, ['--no-data', $db['name'], 'system_logs']);
    $runs[] = array_merge($base, ['--ignore-table=' . $db['name'] . '.system_logs', $db['name']]);
} else {
    $runs[] = array_merge($base, [$db['name']]);
}

$ok = true;
foreach ($runs as $cmd) {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        array_merge(getenv(), ['MYSQL_PWD' => (string) ($db['password'] ?? '')]));
    if (!is_resource($proc)) {
        $ok = false;
        break;
    }
    stream_set_blocking($pipes[2], false);
    $err = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1048576);
        if ($chunk !== false && $chunk !== '' && gzwrite($out, $chunk) === false) {
            $ok = false;
            break;
        }
        $err .= (string) stream_get_contents($pipes[2]);
    }
    $err .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        $ok = false;
        fwrite(STDERR, trim(substr($err, 0, 500)) . "\n");
    }
    if (!$ok) {
        break;
    }
}
gzclose($out);

if (!$ok || !@rename($tmp, $real . '/' . $name)) {
    @unlink($tmp);
    $fail('mysqldump failed or the disk filled up; no dump was written');
}

foreach (array_slice($list(), $keep) as $old) {
    @unlink($old);
}

$size = (int) filesize($real . '/' . $name);
logMessage('INFO', 'Database dump written', ['file' => $name, 'bytes' => $size, 'kept' => min($keep, count($list()))]);
echo sprintf("Wrote %s (%.1f MB)\n", $name, $size / 1048576);
exit(0);
