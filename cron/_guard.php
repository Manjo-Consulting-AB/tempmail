<?php
/**
 * Shared access guards for the scripts under cron/.
 *
 * cronRequireCli()    - CLI only; anything else gets 403 before any work.
 * cronRequireAccess() - the rule cleanup.php, send-digests.php and
 *                       rotate_signing_keys.php already apply inline: CLI,
 *                       a request from 127.0.0.1, or an HTTP request that
 *                       carries CRON_HTTP_SECRET in the X-Cron-Secret header
 *                       or ?key=, compared with hash_equals().
 *
 * This file only defines functions. Requested on its own over HTTP it does
 * nothing and answers 403.
 */

if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}

if (!function_exists('cronRequireCli')) {
    function cronRequireCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Access denied - CLI only\n";
            exit;
        }
    }
}

if (!function_exists('cronRequireAccess')) {
    /**
     * @param string|null $cronHttpSecret the configured CRON_HTTP_SECRET
     */
    function cronRequireAccess(?string $cronHttpSecret): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1') {
            return;
        }
        $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['key'] ?? null);
        if (empty($cronHttpSecret) || empty($provided) || !is_string($provided)
            || !hash_equals((string) $cronHttpSecret, $provided)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Access denied - only CLI, localhost or authorized HTTP clients allowed\n";
            exit;
        }
    }
}
