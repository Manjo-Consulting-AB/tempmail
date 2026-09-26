<?php

declare(strict_types=1);

/**
 * migrate_pushover_credentials.php — thin wrapper, kept so existing deploy
 * notes still work.
 *
 * Superseded by migrate_webhook_credentials.php (#314), which does the same
 * thing for Pushover's token/user key and also seals generic hooks' custom
 * header values. This file just delegates to it.
 *
 * Usage: php migrate_pushover_credentials.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/migrate_webhook_credentials.php';
