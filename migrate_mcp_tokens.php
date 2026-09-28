<?php

declare(strict_types=1);

/**
 * migrate_mcp_tokens.php — CLI migration: personal access tokens for the MCP
 * server (epic #318, #320; see mcp_tokens.php).
 *
 * Adds mcp_access_tokens: one row per token a user created under "Connected
 * apps" — the user's own label, the token's SHA-256 (the token itself is shown
 * once and never stored), a 12-character prefix for the list, the scope it was
 * granted, and the created / last-used / expiry / revoked times. No foreign
 * key, like pro_remember_tokens: the removal paths revoke an account's tokens
 * themselves and cron/cleanup.php sweeps the dead and the orphaned ones.
 *
 * Until this has run, no token resolves and the "Connected apps" card on
 * pro_profile_page.php says the feature is not available yet (fail closed).
 *
 * Idempotent: guarded by the existence of mcp_access_tokens.token_hash, so
 * running this script a second time does nothing and exits 0.
 *
 * Usage: php migrate_mcp_tokens.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

try {
    if (tableHasColumn('mcp_access_tokens', 'token_hash')) {
        echo "[skip] mcp_access_tokens: table already exists\n";
        echo "Nothing to do.\n";
    } else {
        $pdo->exec("CREATE TABLE mcp_access_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            pro_user_id INT NOT NULL,
            name VARCHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_prefix CHAR(12) NOT NULL,
            scopes VARCHAR(32) NOT NULL DEFAULT 'read',
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_mcp_access_tokens_hash (token_hash),
            KEY idx_mcp_access_tokens_user (pro_user_id, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] mcp_access_tokens: table created\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
