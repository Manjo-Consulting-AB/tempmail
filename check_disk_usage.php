<?php
/**
 * Read-only CLI audit of what takes up space: free disk, the largest database
 * tables, system_logs age, the attachments directory (and files no row points
 * at), and debug_logs/. Prints sizes and counts only, never a path to a user's
 * file name or an address. Exit 0 always unless the database cannot be read (2).
 *
 *   php check_disk_usage.php
 */
define('TEMPMAIL_APP', true);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/config.php';

$mb = static fn($b) => sprintf('%.1f MB', $b / 1048576);
$dirSize = static function (string $d): array {
    $bytes = 0;
    $files = 0;
    if (is_dir($d)) {
        foreach (new FilesystemIterator($d, FilesystemIterator::SKIP_DOTS) as $f) {
            if ($f->isFile()) {
                $bytes += $f->getSize();
                $files++;
            }
        }
    }
    return [$bytes, $files];
};

echo "Disk (" . __DIR__ . ")\n";
printf("  free %s of %s\n", $mb(disk_free_space(__DIR__)), $mb(disk_total_space(__DIR__)));

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database unavailable\n");
    exit(2);
}

echo "\nLargest tables\n";
$rows = $pdo->query("SELECT table_name, data_length + index_length AS bytes, table_rows
    FROM information_schema.tables WHERE table_schema = DATABASE()
    ORDER BY bytes DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$dbTotal = (int) $pdo->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
foreach ($rows as $r) {
    printf("  %-28s %10s  ~%d rows\n", $r['table_name'] ?? $r['TABLE_NAME'], $mb((int) ($r['bytes'] ?? $r['BYTES'])), (int) ($r['table_rows'] ?? $r['TABLE_ROWS']));
}
printf("  total %s (a dump is roughly this size before gzip)\n", $mb($dbTotal));

$days = (int) ($config['cleanup']['log_retention_days'] ?? 30);
$oldLogs = (int) $pdo->query("SELECT COUNT(*) FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL " . $days . " DAY)")->fetchColumn();
printf("\nsystem_logs: retention %d days, %d rows past it (cleanup.php should have removed them)\n", $days, $oldLogs);

echo "\nAttachments\n";
$attDir = __DIR__ . '/attachments';
[$attBytes, $attFiles] = $dirSize($attDir);
printf("  on disk: %d files, %s\n", $attFiles, $mb($attBytes));
$known = [];
foreach ($pdo->query("SELECT file_path FROM email_attachments") as $r) {
    $known[basename((string) $r['file_path'])] = true;
}
$orphans = 0;
$orphanBytes = 0;
if (is_dir($attDir)) {
    foreach (new FilesystemIterator($attDir, FilesystemIterator::SKIP_DOTS) as $f) {
        if ($f->isFile() && $f->getFilename()[0] !== '.' && !isset($known[$f->getFilename()])) {
            $orphans++;
            $orphanBytes += $f->getSize();
        }
    }
}
printf("  with no row in email_attachments: %d files, %s (safe to delete when older than a day)\n", $orphans, $mb($orphanBytes));
printf("  rows pointing at a missing file: %d\n", count(array_filter(array_keys($known), static fn($n) => !is_file($attDir . '/' . $n))));

echo "\ndebug_logs/ (written by debug_logger.php, never rotated)\n";
[$dbgBytes, $dbgFiles] = $dirSize(__DIR__ . '/debug_logs');
printf("  %d files, %s\n", $dbgFiles, $mb($dbgBytes));
