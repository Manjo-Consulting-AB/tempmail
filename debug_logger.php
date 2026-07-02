<?php
/**
 * Safe debug logger that writes JSON lines to a local file.
 * This is intentionally independent from the database to avoid
 * cascading failures when DB/network is unavailable.
 */

if (!function_exists('safeDebugLog')) {
    function safeDebugLog(string $level, string $message, $context = null): void
    {
        try {
            $dir = __DIR__ . '/debug_logs';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $file = $dir . '/attachments_debug.log';

            $entry = [
                'ts' => date('c'),
                'level' => $level,
                'message' => $message,
                'context' => $context
            ];

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                // Fall back to a simple string line
                $line = date('c') . " | {$level} | {$message}\n";
            } else {
                $line .= "\n";
            }

            // Use LOCK_EX to avoid race conditions across processes
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // As last resort, write to PHP error log; do not throw
            error_log("[safeDebugLog] Failed to write debug log: " . $e->getMessage());
        }
    }
}
