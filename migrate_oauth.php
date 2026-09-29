<?php

declare(strict_types=1);

/**
 * migrate_oauth.php — CLI migration: the OAuth 2.1 tables behind the MCP
 * endpoint (epic #331, step 1/6; see oauth_server.php).
 *
 * Creates the two new tables and widens mcp_access_tokens so an OAuth access
 * token is an ordinary row there, resolved by the same mcpTokenResolve():
 *
 *  - oauth_clients: one row per dynamically registered client (RFC 7591). The
 *    client_id is public — 32 hex characters from random_bytes(16) — and no
 *    secret is ever issued (token_endpoint_auth_method is "none" only).
 *    redirect_uris is the JSON array of exact-match URIs, registered_ip_hash
 *    is the sha256 of the registering IP under a sub-key (never the raw IP).
 *
 *  - oauth_authorization_codes: one row per pending authorization code. Only
 *    the sha256 of the code is stored; the code, like an access token, is a
 *    bearer credential.
 *
 *  - mcp_access_tokens gains nullable oauth_client_id / refresh_token_hash /
 *    refresh_expires_at / grant_created_at plus rotated_refresh_hash and
 *    rotated_at (the previous refresh token, kept for re-use detection). A
 *    manual token — every token that exists today — leaves all of them NULL,
 *    so nothing about the "Connected apps" card changes.
 *
 * No foreign keys, like the rest of the schema: the cleanup paths and the
 * account-removal paths handle the links themselves.
 *
 * Requires migrate_mcp_tokens.php to have run first (it widens its table).
 * Idempotent: every step is guarded by the column/table it creates, so a
 * second run does nothing and exits 0. Nothing on the site reads these tables
 * yet, so this step is inert once deployed.
 *
 * Usage: php migrate_oauth.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

if (!function_exists('migrationIndexExists')) {
    /**
     * Is $index on $table? Via information_schema, with bound parameters,
     * mirroring tableHasColumn() in config.php.
     */
    function migrationIndexExists(PDO $pdo, string $table, string $index): bool {
        global $config;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->execute([$config['db']['name'], $table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

try {
    // -----------------------------------------------------------------
    // oauth_clients — dynamically registered OAuth clients (#331).
    // -----------------------------------------------------------------
    if (tableHasColumn('oauth_clients', 'client_id')) {
        echo "[skip] oauth_clients: table already exists\n";
    } else {
        $pdo->exec("CREATE TABLE oauth_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id CHAR(32) NOT NULL,
            client_name VARCHAR(64) NOT NULL,
            redirect_uris TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            registered_ip_hash CHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_oauth_clients_client_id (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] oauth_clients: table created\n";
    }

    // -----------------------------------------------------------------
    // oauth_authorization_codes — pending, single-use authorization codes.
    // -----------------------------------------------------------------
    if (tableHasColumn('oauth_authorization_codes', 'code_hash')) {
        echo "[skip] oauth_authorization_codes: table already exists\n";
    } else {
        $pdo->exec("CREATE TABLE oauth_authorization_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_hash CHAR(64) NOT NULL,
            client_id CHAR(32) NOT NULL,
            pro_user_id INT NOT NULL,
            redirect_uri VARCHAR(512) NOT NULL,
            scopes VARCHAR(32) NOT NULL,
            code_challenge CHAR(43) NOT NULL,
            resource VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_oauth_authorization_codes_hash (code_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[done] oauth_authorization_codes: table created\n";
    }

    // -----------------------------------------------------------------
    // mcp_access_tokens — link an OAuth grant to an access token.
    // -----------------------------------------------------------------
    if (!tableHasColumn('mcp_access_tokens', 'token_hash')) {
        fwrite(STDERR, "ERROR: mcp_access_tokens is missing — run php migrate_mcp_tokens.php first.\n");
        exit(1);
    }

    foreach ([
        'oauth_client_id' => 'CHAR(32) NULL',
        'refresh_token_hash' => 'CHAR(64) NULL',
        'refresh_expires_at' => 'DATETIME NULL',
        'grant_created_at' => 'DATETIME NULL',
        'rotated_refresh_hash' => 'CHAR(64) NULL',
        'rotated_at' => 'DATETIME NULL',
    ] as $column => $definition) {
        if (tableHasColumn('mcp_access_tokens', $column)) {
            echo "[skip] mcp_access_tokens.{$column}: column already exists\n";
            continue;
        }
        $pdo->exec("ALTER TABLE mcp_access_tokens ADD COLUMN {$column} {$definition}");
        echo "[done] mcp_access_tokens.{$column}: column added\n";
    }

    if (migrationIndexExists($pdo, 'mcp_access_tokens', 'uniq_mcp_access_tokens_refresh')) {
        echo "[skip] uniq_mcp_access_tokens_refresh: index already exists\n";
    } else {
        $pdo->exec("ALTER TABLE mcp_access_tokens ADD UNIQUE KEY uniq_mcp_access_tokens_refresh (refresh_token_hash)");
        echo "[done] uniq_mcp_access_tokens_refresh: unique key added\n";
    }

    if (migrationIndexExists($pdo, 'mcp_access_tokens', 'idx_mcp_access_tokens_oauth')) {
        echo "[skip] idx_mcp_access_tokens_oauth: index already exists\n";
    } else {
        $pdo->exec("ALTER TABLE mcp_access_tokens ADD KEY idx_mcp_access_tokens_oauth (oauth_client_id, pro_user_id)");
        echo "[done] idx_mcp_access_tokens_oauth: index added\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
