<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

if (!defined('CLIENT_BACKEND_BOOTSTRAPPED')) {
    define('CLIENT_BACKEND_BOOTSTRAPPED', true);
    define('CLIENT_BACKEND_ROOT', __DIR__);
}

function clientBackendGetDb(): ?PDO {
    global $pdo;
    return isset($pdo) && $pdo instanceof PDO ? $pdo : null;
}

function clientBackendHasDbTable(string $tableName): bool {
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $db = clientBackendGetDb();
    if (!$db) {
        $cache[$tableName] = false;
        return false;
    }

    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$tableName]);
        $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function clientBackendHasDbColumn(string $tableName, string $columnName): bool {
    static $cache = [];

    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = clientBackendGetDb();
    if (!$db) {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $columnName]);
        $cache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function clientBackendEnsureSpamFiltersColumn(): bool {
    $db = clientBackendGetDb();
    if (!$db || !clientBackendHasDbTable('client_scripts')) {
        return false;
    }

    if (clientBackendHasDbColumn('client_scripts', 'spam_filters_json')) {
        return true;
    }

    try {
        $db->exec('ALTER TABLE client_scripts ADD COLUMN IF NOT EXISTS spam_filters_json LONGTEXT NULL AFTER blacklist_json');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function clientBackendUseDatabase(): bool {
    return clientBackendHasDbTable('client_scripts');
}

function clientBackendDecodeJsonList($json): array {
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $items = [];
    foreach ($data as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $items[] = $value;
        }
    }

    return array_values(array_unique($items));
}

function clientBackendEncodeJsonList(array $items): string {
    $items = array_values(array_unique(array_filter(array_map(static function ($item): string {
        return trim((string) $item);
    }, $items), static function (string $item): bool {
        return $item !== '';
    })));

    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '[]';
}

function clientBackendNormalizeTargetHost($value): ?string {
    $raw = strtolower(trim((string) $value));
    if ($raw === '') {
        return null;
    }

    if (preg_match('/[\/?#@]/', $raw) === 1) {
        return null;
    }

    $parts = explode(':', $raw, 2);
    $host = trim($parts[0]);
    $port = $parts[1] ?? null;

    if ($host === '' || strlen($host) > 253) {
        return null;
    }

    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    $isDomain = preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    if (!$isIp && !$isDomain) {
        return null;
    }

    if ($isIp) {
        $allowPrivate = (getenv('CLIENT_BACKEND_ALLOW_PRIVATE_TARGETS') ?: '0') === '1';
        if (!$allowPrivate) {
            $publicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($publicIp === false) {
                return null;
            }
        }
    }

    if ($port !== null) {
        if (!ctype_digit($port)) {
            return null;
        }
        $portNo = (int) $port;
        if ($portNo < 1 || $portNo > 65535) {
            return null;
        }
        return $host . ':' . $portNo;
    }

    return $host;
}

function clientBackendNormalizeTargetPath($value): string {
    $path = trim((string) $value);
    if ($path === '') {
        return '/client/agent/agent.php';
    }

    if (preg_match('/^https?:\/\//i', $path) === 1) {
        return '/client/agent/agent.php';
    }

    $pathOnly = parse_url($path, PHP_URL_PATH);
    if (!is_string($pathOnly) || $pathOnly === '') {
        return '/client/agent/agent.php';
    }

    $pathOnly = '/' . ltrim($pathOnly, '/');
    $pathOnly = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $pathOnly) ?? '/client/agent/agent.php';

    return $pathOnly !== '' ? $pathOnly : '/client/agent/agent.php';
}

function clientBackendNormalizeListPattern($value): ?string {
    $pattern = trim((string) $value);
    if ($pattern === '' || strlen($pattern) > 255) {
        return null;
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $pattern) === 1) {
        return null;
    }
    return $pattern;
}

function clientBackendNormalizeSpamFilterRule(array $rule): ?array {
    $ruleId = isset($rule['rule_id']) && is_string($rule['rule_id']) && trim($rule['rule_id']) !== ''
        ? trim($rule['rule_id'])
        : 'r_' . bin2hex(random_bytes(8));

    $type = isset($rule['type']) && strtolower((string) $rule['type']) === 'regex' ? 'regex' : 'text';
    $scope = isset($rule['scope']) && in_array((string) $rule['scope'], ['subject', 'body', 'from', 'any'], true)
        ? (string) $rule['scope']
        : 'any';
    $pattern = trim((string) ($rule['pattern'] ?? ''));

    if ($pattern === '' || strlen($pattern) > 512) {
        return null;
    }

    if ($type === 'regex') {
        $testRegex = '~' . str_replace('~', '\\~', $pattern) . '~' . (!empty($rule['case_insensitive']) ? 'i' : '');
        $ok = @preg_match($testRegex, '');
        if ($ok === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }
    }

    return [
        'rule_id' => $ruleId,
        'type' => $type,
        'scope' => $scope,
        'pattern' => $pattern,
        'case_insensitive' => !empty($rule['case_insensitive']),
    ];
}

function clientBackendDecodeJsonObjects($json): array {
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $items = [];
    foreach ($data as $value) {
        if (is_array($value)) {
            $normalized = clientBackendNormalizeSpamFilterRule($value);
            if (is_array($normalized)) {
                $items[] = $normalized;
            }
        }
    }

    return $items;
}

function clientBackendEncodeJsonObjects(array $items): string {
    $normalized = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $rule = clientBackendNormalizeSpamFilterRule($item);
            if (is_array($rule)) {
                $normalized[] = $rule;
            }
        }
    }

    $json = json_encode(array_values($normalized), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '[]';
}

function clientBackendHydrateScriptRow(array $row): array {
    return [
        'script_id' => (string) ($row['script_id'] ?? ''),
        'owner_pro_user_id' => array_key_exists('owner_pro_user_id', $row) && $row['owner_pro_user_id'] !== null ? (int) $row['owner_pro_user_id'] : null,
        'label' => (string) ($row['label'] ?? 'New script'),
        'target_host' => $row['target_host'] !== null ? (string) $row['target_host'] : null,
        'target_path' => (string) ($row['target_path'] ?? '/client/agent/agent.php'),
        'greylist_days' => (int) ($row['greylist_days'] ?? 30),
        'whitelist' => clientBackendDecodeJsonList($row['whitelist_json'] ?? '[]'),
        'blacklist' => clientBackendDecodeJsonList($row['blacklist_json'] ?? '[]'),
        'spam_filters' => clientBackendDecodeJsonObjects($row['spam_filters_json'] ?? '[]'),
        'pending_sync_at' => $row['pending_sync_at'] ?? null,
        'last_webhook_sent_at' => $row['last_webhook_sent_at'] ?? null,
        'sync_status' => (string) ($row['sync_status'] ?? 'idle'),
        'sync_message' => $row['sync_message'] !== null ? (string) $row['sync_message'] : null,
        'last_webhook_result' => isset($row['last_webhook_result_json']) && is_string($row['last_webhook_result_json']) && trim($row['last_webhook_result_json']) !== ''
            ? (json_decode($row['last_webhook_result_json'], true) ?: null)
            : null,
        'dry_run' => isset($row['dry_run']) ? (bool) $row['dry_run'] : false,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function clientBackendDbFetchScripts(): array {
    $db = clientBackendGetDb();
    if (!$db || !clientBackendUseDatabase()) {
        return [];
    }

    $stmt = $db->query('SELECT * FROM client_scripts ORDER BY id DESC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $scripts = [];
    foreach ($rows as $row) {
        $script = clientBackendHydrateScriptRow($row);
        if ($script['script_id'] !== '') {
            $scripts[$script['script_id']] = $script;
        }
    }

    return $scripts;
}

function clientBackendDbSaveScripts(array $scripts): void {
    $db = clientBackendGetDb();
    if (!$db || !clientBackendUseDatabase()) {
        return;
    }

    $hasSpamFiltersColumn = clientBackendHasDbColumn('client_scripts', 'spam_filters_json') || clientBackendEnsureSpamFiltersColumn();

    $db->beginTransaction();
    try {
        $existingStmt = $db->query('SELECT script_id FROM client_scripts');
        $existingIds = $existingStmt ? array_map(static fn($value): string => (string) $value, $existingStmt->fetchAll(PDO::FETCH_COLUMN)) : [];
        $keepIds = [];

        if ($hasSpamFiltersColumn) {
            $sql = 'INSERT INTO client_scripts (
                script_id, owner_pro_user_id, label, target_host, target_path, greylist_days,
                whitelist_json, blacklist_json, spam_filters_json, pending_sync_at, last_webhook_sent_at,
                sync_status, sync_message, last_webhook_result_json, dry_run
            ) VALUES (
                :script_id, :owner_pro_user_id, :label, :target_host, :target_path, :greylist_days,
                :whitelist_json, :blacklist_json, :spam_filters_json, :pending_sync_at, :last_webhook_sent_at,
                :sync_status, :sync_message, :last_webhook_result_json, :dry_run
            ) ON DUPLICATE KEY UPDATE
                owner_pro_user_id = VALUES(owner_pro_user_id),
                label = VALUES(label),
                target_host = VALUES(target_host),
                target_path = VALUES(target_path),
                greylist_days = VALUES(greylist_days),
                whitelist_json = VALUES(whitelist_json),
                blacklist_json = VALUES(blacklist_json),
                spam_filters_json = VALUES(spam_filters_json),
                pending_sync_at = VALUES(pending_sync_at),
                last_webhook_sent_at = VALUES(last_webhook_sent_at),
                sync_status = VALUES(sync_status),
                sync_message = VALUES(sync_message),
                last_webhook_result_json = VALUES(last_webhook_result_json),
                dry_run = VALUES(dry_run),
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO client_scripts (
                script_id, owner_pro_user_id, label, target_host, target_path, greylist_days,
                whitelist_json, blacklist_json, pending_sync_at, last_webhook_sent_at,
                sync_status, sync_message, last_webhook_result_json, dry_run
            ) VALUES (
                :script_id, :owner_pro_user_id, :label, :target_host, :target_path, :greylist_days,
                :whitelist_json, :blacklist_json, :pending_sync_at, :last_webhook_sent_at,
                :sync_status, :sync_message, :last_webhook_result_json, :dry_run
            ) ON DUPLICATE KEY UPDATE
                owner_pro_user_id = VALUES(owner_pro_user_id),
                label = VALUES(label),
                target_host = VALUES(target_host),
                target_path = VALUES(target_path),
                greylist_days = VALUES(greylist_days),
                whitelist_json = VALUES(whitelist_json),
                blacklist_json = VALUES(blacklist_json),
                pending_sync_at = VALUES(pending_sync_at),
                last_webhook_sent_at = VALUES(last_webhook_sent_at),
                sync_status = VALUES(sync_status),
                sync_message = VALUES(sync_message),
                last_webhook_result_json = VALUES(last_webhook_result_json),
                dry_run = VALUES(dry_run),
                updated_at = CURRENT_TIMESTAMP';
        }
        $stmt = $db->prepare($sql);

        foreach ($scripts as $scriptId => $script) {
            $scriptId = (string) $scriptId;
            $keepIds[] = $scriptId;
            $whitelist = is_array($script['whitelist'] ?? null) ? $script['whitelist'] : [];
            $blacklist = is_array($script['blacklist'] ?? null) ? $script['blacklist'] : [];
            $spamFilters = is_array($script['spam_filters'] ?? null) ? $script['spam_filters'] : [];
            $lastWebhookResult = $script['last_webhook_result'] ?? null;

            $params = [
                ':script_id' => $scriptId,
                ':owner_pro_user_id' => array_key_exists('owner_pro_user_id', $script) && $script['owner_pro_user_id'] !== null ? (int) $script['owner_pro_user_id'] : null,
                ':label' => (string) ($script['label'] ?? 'New script'),
                ':target_host' => ($script['target_host'] ?? null) !== null && (string) $script['target_host'] !== '' ? (string) $script['target_host'] : null,
                ':target_path' => (string) ($script['target_path'] ?? '/client/agent/agent.php'),
                ':greylist_days' => (int) ($script['greylist_days'] ?? 30),
                ':whitelist_json' => clientBackendEncodeJsonList($whitelist),
                ':blacklist_json' => clientBackendEncodeJsonList($blacklist),
                ':pending_sync_at' => ($script['pending_sync_at'] ?? null) !== null && (string) $script['pending_sync_at'] !== '' ? (string) $script['pending_sync_at'] : null,
                ':last_webhook_sent_at' => ($script['last_webhook_sent_at'] ?? null) !== null && (string) $script['last_webhook_sent_at'] !== '' ? (string) $script['last_webhook_sent_at'] : null,
                ':sync_status' => (string) ($script['sync_status'] ?? 'idle'),
                ':sync_message' => ($script['sync_message'] ?? null) !== null && (string) $script['sync_message'] !== '' ? (string) $script['sync_message'] : null,
                ':last_webhook_result_json' => $lastWebhookResult !== null ? json_encode($lastWebhookResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':dry_run' => !empty($script['dry_run']) ? 1 : 0,
            ];
            if ($hasSpamFiltersColumn) {
                $params[':spam_filters_json'] = clientBackendEncodeJsonObjects($spamFilters);
            }

            $stmt->execute($params);
        }

        if (!empty($existingIds)) {
            $idsToDelete = array_values(array_diff($existingIds, $keepIds));
            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $deleteStmt = $db->prepare('DELETE FROM client_scripts WHERE script_id IN (' . $placeholders . ')');
                $deleteStmt->execute($idsToDelete);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function clientBackendGetScripts(): array {
    return clientBackendDbFetchScripts();
}

function clientBackendSaveScripts(array $scripts): void {
    clientBackendDbSaveScripts($scripts);
}

function clientBackendGenerateScriptId(): string {
    return substr(strtolower(bin2hex(random_bytes(6))), 0, 12);
}

function clientBackendGetCurrentUserId(): ?int {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $userId = $_SESSION['pro_user_id'] ?? null;
    return is_numeric($userId) ? (int) $userId : null;
}

function clientBackendGetUserTimezone(int $userId): ?string {
    $db = clientBackendGetDb();
    if (!$db || !clientBackendHasDbTable('pro_users')) {
        return null;
    }

    try {
        $stmt = $db->prepare('SELECT digest_tz FROM pro_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $tz = trim((string)($row['digest_tz'] ?? ''));
        if ($tz === '') {
            return null;
        }

        // Validate timezone identifier to avoid returning invalid values to the client.
        new DateTimeZone($tz);
        return $tz;
    } catch (Throwable $e) {
        return null;
    }
}

function clientBackendCanAccessScript(array $script, ?int $userId): bool {
    if ($userId === null) {
        return false;
    }

    $ownerId = $script['owner_pro_user_id'] ?? null;
    return $ownerId !== null && (int) $ownerId === $userId;
}

function clientBackendGetScriptsForUser(int $userId): array {
    $scripts = clientBackendGetScripts();
    return array_filter($scripts, static function (array $script) use ($userId): bool {
        return clientBackendCanAccessScript($script, $userId);
    }, ARRAY_FILTER_USE_BOTH);
}

function clientBackendCreateScript(array $input): array {
    $scripts = clientBackendGetScripts();
    $scriptId = clientBackendGenerateScriptId();
    $targetHost = clientBackendNormalizeTargetHost($input['target_host'] ?? null);
    $targetPath = clientBackendNormalizeTargetPath($input['target_path'] ?? '/client/agent/agent.php');
    $script = [
        'script_id' => $scriptId,
        'owner_pro_user_id' => isset($input['owner_pro_user_id']) ? (int) $input['owner_pro_user_id'] : null,
        'label' => $input['label'] ?? 'New script',
        'target_host' => $targetHost,
        'target_path' => $targetPath,
        'greylist_days' => 30,
        'whitelist' => [],
        'blacklist' => [],
        'spam_filters' => [],
        'pending_sync_at' => null,
        'last_webhook_sent_at' => null,
        'sync_status' => 'idle',
        'sync_message' => null,
        'last_webhook_result' => null,
        'dry_run' => false,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];

    $scripts[$scriptId] = $script;
    clientBackendSaveScripts($scripts);
    return $script;
}

function clientBackendGetScript(string $scriptId): ?array {
    $scripts = clientBackendGetScripts();
    return $scripts[$scriptId] ?? null;
}

function clientBackendUpdateScript(string $scriptId, array $changes): ?array {
    $scripts = clientBackendGetScripts();
    if (!isset($scripts[$scriptId])) {
        return null;
    }

    // Always route target_host/target_path through normalization, regardless of
    // caller, so no code path can set an unvalidated SSRF target (e.g. a private
    // or loopback address) on the script record.
    if (array_key_exists('target_host', $changes)) {
        $changes['target_host'] = clientBackendNormalizeTargetHost($changes['target_host']);
    }
    if (array_key_exists('target_path', $changes)) {
        $changes['target_path'] = clientBackendNormalizeTargetPath($changes['target_path']);
    }
    // script_id is the array key and must not be overwritten via a generic update.
    unset($changes['script_id']);

    foreach ($changes as $key => $value) {
        $scripts[$scriptId][$key] = $value;
    }

    $scripts[$scriptId]['updated_at'] = gmdate('c');
    clientBackendSaveScripts($scripts);
    return $scripts[$scriptId];
}

function clientBackendScheduleSync(string $scriptId): ?array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return null;
    }

    $script['pending_sync_at'] = gmdate('c', time() + 60);
    return clientBackendUpdateScript($scriptId, ['pending_sync_at' => $script['pending_sync_at']]);
}

function clientBackendCancelSync(string $scriptId): ?array {
    return clientBackendUpdateScript($scriptId, ['pending_sync_at' => null]);
}

function clientBackendAddListItem(string $scriptId, string $type, string $pattern): ?array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return null;
    }

    $listKey = $type === 'blacklist' ? 'blacklist' : 'whitelist';
    $pattern = clientBackendNormalizeListPattern($pattern);
    if ($pattern === null) {
        return null;
    }
    $list = $script[$listKey] ?? [];
    if (!in_array($pattern, $list, true)) {
        $list[] = $pattern;
        $script[$listKey] = $list;
        $script['updated_at'] = gmdate('c');
        $scripts = clientBackendGetScripts();
        $scripts[$scriptId] = $script;
        clientBackendSaveScripts($scripts);
        return clientBackendPersistAndDispatch($scriptId, [$listKey => $list]);
    }

    return clientBackendPersistAndDispatch($scriptId, []);
}

function clientBackendAddSpamFilter(string $scriptId, array $rule): ?array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return null;
    }

    $rules = is_array($script['spam_filters'] ?? null) ? $script['spam_filters'] : [];
    $normalized = clientBackendNormalizeSpamFilterRule($rule);
    if (!is_array($normalized)) {
        return null;
    }
    if (count($rules) >= 100) {
        return null;
    }
    $rules[] = $normalized;
    $script['spam_filters'] = $rules;
    $script['updated_at'] = gmdate('c');
    $scripts = clientBackendGetScripts();
    $scripts[$scriptId] = $script;
    clientBackendSaveScripts($scripts);

    return clientBackendPersistAndDispatch($scriptId, ['spam_filters' => $rules]);
}

function clientBackendRemoveSpamFilter(string $scriptId, string $ruleId): ?array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return null;
    }

    $rules = is_array($script['spam_filters'] ?? null) ? $script['spam_filters'] : [];
    $rules = array_values(array_filter($rules, static function ($rule) use ($ruleId): bool {
        return !is_array($rule) || (string) ($rule['rule_id'] ?? '') !== $ruleId;
    }));
    $script['spam_filters'] = $rules;
    $script['updated_at'] = gmdate('c');
    $scripts = clientBackendGetScripts();
    $scripts[$scriptId] = $script;
    clientBackendSaveScripts($scripts);

    return clientBackendPersistAndDispatch($scriptId, ['spam_filters' => $rules]);
}

function clientBackendRemoveListItem(string $scriptId, string $type, string $pattern): ?array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return null;
    }

    $listKey = $type === 'blacklist' ? 'blacklist' : 'whitelist';
    $pattern = clientBackendNormalizeListPattern($pattern);
    if ($pattern === null) {
        return null;
    }
    $list = $script[$listKey] ?? [];
    $script[$listKey] = array_values(array_filter($list, static function ($value) use ($pattern): bool {
        return $value !== $pattern;
    }));
    $script['updated_at'] = gmdate('c');
    $scripts = clientBackendGetScripts();
    $scripts[$scriptId] = $script;
    clientBackendSaveScripts($scripts);
    return clientBackendPersistAndDispatch($scriptId, [$listKey => $script[$listKey]]);
}

function clientBackendUpdateSettings(string $scriptId, array $changes): ?array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return null;
    }

    $changesToApply = [];
    if (isset($changes['greylist_days'])) {
        $script['greylist_days'] = (int) $changes['greylist_days'];
        $changesToApply['greylist_days'] = $script['greylist_days'];
    }
    if (isset($changes['label'])) {
        $script['label'] = (string) $changes['label'];
        $changesToApply['label'] = $script['label'];
    }
    if (isset($changes['target_host'])) {
        $script['target_host'] = clientBackendNormalizeTargetHost($changes['target_host']);
        $changesToApply['target_host'] = $script['target_host'];
    }
    if (isset($changes['target_path'])) {
        $script['target_path'] = clientBackendNormalizeTargetPath($changes['target_path']);
        $changesToApply['target_path'] = $script['target_path'];
    }

    $script['updated_at'] = gmdate('c');
    $scripts = clientBackendGetScripts();
    $scripts[$scriptId] = $script;
    clientBackendSaveScripts($scripts);
    return clientBackendPersistAndDispatch($scriptId, $changesToApply);
}

function clientBackendReadKek(): ?string {
    $path = getenv('CLIENT_BACKEND_KEK_PATH') ?: ($_ENV['CLIENT_BACKEND_KEK_PATH'] ?? '') ?: getenv('CLIENT_KEK_FILE') ?: ($_ENV['CLIENT_KEK_FILE'] ?? '');
    if (!is_string($path) || trim($path) === '') {
        logMessage('ERROR', 'Client backend KEK not configured: set CLIENT_BACKEND_KEK_PATH (or CLIENT_KEK_FILE) to a file holding the key-encryption-key');
        return null;
    }
    if (!is_readable($path)) {
        logMessage('ERROR', 'Client backend KEK file is not readable', ['path' => $path]);
        return null;
    }

    $raw = file_get_contents(trim($path));
    if (!is_string($raw)) {
        logMessage('ERROR', 'Client backend KEK file could not be read', ['path' => $path]);
        return null;
    }

    $value = trim($raw);
    if ($value === '') {
        logMessage('ERROR', 'Client backend KEK file is empty', ['path' => $path]);
        return null;
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
        $bin = hex2bin($value);
        if (!is_string($bin)) {
            logMessage('ERROR', 'Client backend KEK file has invalid hex content', ['path' => $path]);
        }
        return is_string($bin) ? $bin : null;
    }

    $decoded = base64_decode($value, true);
    if (is_string($decoded) && strlen($decoded) === 32) {
        return $decoded;
    }

    if (strlen($value) === 32) {
        return $value;
    }

    logMessage('ERROR', 'Client backend KEK file does not contain a valid 32-byte key (expected 64 hex chars, base64, or 32 raw bytes)', ['path' => $path, 'length' => strlen($value)]);
    return null;
}

function clientBackendEncryptWithKek(string $plain, string $kek): ?string {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext) || $tag === '') {
        return null;
    }

    return base64_encode($nonce . $ciphertext . $tag);
}

function clientBackendDecryptWithKek(string $encoded, string $kek): ?string {
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) <= 28) {
        return null;
    }

    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, -16);
    $ciphertext = substr($raw, 12, -16);
    if (!is_string($nonce) || !is_string($tag) || !is_string($ciphertext)) {
        return null;
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag);
    return is_string($plain) ? $plain : null;
}

function clientBackendGenerateSigningKeyPair(): ?array {
    if (!function_exists('openssl_pkey_new')) {
        logMessage('ERROR', 'Cannot generate signing key pair: openssl extension is not available');
        return null;
    }

    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        $opensslErrors = [];
        while (($err = openssl_error_string()) !== false) {
            $opensslErrors[] = $err;
        }
        logMessage('ERROR', 'openssl_pkey_new() failed while generating signing key pair (often a missing/misconfigured openssl.cnf on shared hosting)', ['openssl_errors' => $opensslErrors]);
        return null;
    }

    $privatePem = '';
    if (!openssl_pkey_export($resource, $privatePem)) {
        logMessage('ERROR', 'openssl_pkey_export() failed while generating signing key pair');
        return null;
    }

    $details = openssl_pkey_get_details($resource);
    $publicPem = is_array($details) && isset($details['key']) ? (string) $details['key'] : '';
    if ($privatePem === '' || $publicPem === '') {
        logMessage('ERROR', 'openssl_pkey_get_details() returned no usable public key');
        return null;
    }

    return [
        'private_pem' => $privatePem,
        'public_pem' => $publicPem,
    ];
}

function clientBackendEnsureUserSigningKeys(int $userId): ?array {
    $db = clientBackendGetDb();
    if (!$db || !clientBackendHasDbTable('pro_users')) {
        logMessage('ERROR', 'Cannot ensure signing keys: DB unavailable or pro_users table missing', ['user_id' => $userId]);
        return null;
    }

    $kek = clientBackendReadKek();
    if (!is_string($kek) || strlen($kek) !== 32) {
        return null;
    }

    $select = $db->prepare('SELECT id, agent_signing_public_key, agent_signing_private_key_enc, agent_signing_key_version FROM pro_users WHERE id = ? LIMIT 1');
    $select->execute([$userId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        logMessage('ERROR', 'Cannot ensure signing keys: pro_users row not found', ['user_id' => $userId]);
        return null;
    }

    $publicPem = isset($row['agent_signing_public_key']) ? trim((string) $row['agent_signing_public_key']) : '';
    $privateEnc = isset($row['agent_signing_private_key_enc']) ? trim((string) $row['agent_signing_private_key_enc']) : '';
    $version = isset($row['agent_signing_key_version']) ? (int) $row['agent_signing_key_version'] : 1;

    if ($publicPem !== '' && $privateEnc !== '') {
        $privatePem = clientBackendDecryptWithKek($privateEnc, $kek);
        if (is_string($privatePem) && $privatePem !== '') {
            return [
                'public_pem' => $publicPem,
                'private_pem' => $privatePem,
                'key_id' => 'u' . $userId . 'v' . max(1, $version),
            ];
        }
    }

    $pair = clientBackendGenerateSigningKeyPair();
    if (!is_array($pair)) {
        return null;
    }

    $privateEncrypted = clientBackendEncryptWithKek($pair['private_pem'], $kek);
    if (!is_string($privateEncrypted) || $privateEncrypted === '') {
        logMessage('ERROR', 'Cannot ensure signing keys: failed to encrypt private key with KEK', ['user_id' => $userId]);
        return null;
    }

    $newVersion = max(1, $version);
    $update = $db->prepare('UPDATE pro_users SET agent_signing_public_key = ?, agent_signing_private_key_enc = ?, agent_signing_key_version = ?, agent_signing_updated_at = NOW() WHERE id = ?');
    $update->execute([$pair['public_pem'], $privateEncrypted, $newVersion, $userId]);

    return [
        'public_pem' => $pair['public_pem'],
        'private_pem' => $pair['private_pem'],
        'key_id' => 'u' . $userId . 'v' . $newVersion,
    ];
}

function clientBackendRotateUserSigningKeys(int $userId): ?array {
    $db = clientBackendGetDb();
    if (!$db || !clientBackendHasDbTable('pro_users')) {
        logMessage('ERROR', 'Cannot rotate signing keys: DB unavailable or pro_users table missing', ['user_id' => $userId]);
        return null;
    }

    $kek = clientBackendReadKek();
    if (!is_string($kek) || strlen($kek) !== 32) {
        return null;
    }

    $select = $db->prepare('SELECT id, agent_signing_key_version FROM pro_users WHERE id = ? LIMIT 1');
    $select->execute([$userId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        logMessage('ERROR', 'Cannot rotate signing keys: pro_users row not found', ['user_id' => $userId]);
        return null;
    }

    $currentVersion = isset($row['agent_signing_key_version']) ? (int) $row['agent_signing_key_version'] : 0;
    $newVersion = max(1, $currentVersion + 1);

    $pair = clientBackendGenerateSigningKeyPair();
    if (!is_array($pair)) {
        return null;
    }

    $privateEncrypted = clientBackendEncryptWithKek($pair['private_pem'], $kek);
    if (!is_string($privateEncrypted) || $privateEncrypted === '') {
        logMessage('ERROR', 'Cannot rotate signing keys: failed to encrypt new private key with KEK', ['user_id' => $userId]);
        return null;
    }

    $update = $db->prepare('UPDATE pro_users SET agent_signing_public_key = ?, agent_signing_private_key_enc = ?, agent_signing_key_version = ?, agent_signing_updated_at = NOW() WHERE id = ?');
    $update->execute([$pair['public_pem'], $privateEncrypted, $newVersion, $userId]);

    return [
        'public_pem' => $pair['public_pem'],
        'private_pem' => $pair['private_pem'],
        'key_id' => 'u' . $userId . 'v' . $newVersion,
    ];
}

function clientBackendBuildWebhookPayload(array $script): array {
    $ownerId = isset($script['owner_pro_user_id']) ? (int) $script['owner_pro_user_id'] : 0;
    $signing = $ownerId > 0 ? clientBackendEnsureUserSigningKeys($ownerId) : null;
    $timestamp = gmdate('c');

    return [
        'api_version' => '1.0',
        'script_id' => $script['script_id'],
        'timestamp' => $timestamp,
        'action' => 'update_lists',
        'data' => [
            'whitelist' => $script['whitelist'] ?? [],
            'blacklist' => $script['blacklist'] ?? [],
            'spam_filters' => $script['spam_filters'] ?? [],
            'greylist_days' => (int) ($script['greylist_days'] ?? 30),
            'dry_run' => false,
            'signing_key_id' => $signing['key_id'] ?? null,
            'signing_public_key' => $signing['public_pem'] ?? null,
        ],
    ];
}

function clientBackendBuildWebhookSignature(string $body, array $script): ?string {
    $ownerId = isset($script['owner_pro_user_id']) ? (int) $script['owner_pro_user_id'] : 0;
    if ($ownerId <= 0) {
        return null;
    }

    $signing = clientBackendEnsureUserSigningKeys($ownerId);
    if (!is_array($signing)) {
        return null;
    }

    $privateKey = openssl_pkey_get_private($signing['private_pem']);
    if ($privateKey === false) {
        return null;
    }

    $signature = '';
    $ok = openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (is_resource($privateKey) || $privateKey instanceof OpenSSLAsymmetricKey) {
        openssl_free_key($privateKey);
    }
    if (!$ok || $signature === '') {
        return null;
    }

    return 'rsa-sha256=' . base64_encode($signature);
}

function clientBackendIsPublicIp(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Resolves the webhook URL's host to a concrete IP and validates it is not
// private/loopback/link-local. clientBackendNormalizeTargetHost() only checks
// syntax for domain names (it can't know what they'll resolve to), so a target
// domain could otherwise point at an internal address - either at save time
// (attacker-controlled DNS) or later via DNS rebinding. Resolving and pinning
// here, right before the request is made, closes both gaps for the curl path.
function clientBackendResolveDispatchTarget(string $url): ?array {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $allowPrivate = (getenv('CLIENT_BACKEND_ALLOW_PRIVATE_TARGETS') ?: '0') === '1';

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!$allowPrivate && !clientBackendIsPublicIp($host)) {
            return null;
        }
        return ['host' => $host, 'port' => $port, 'ip' => $host];
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }
    if (empty($ips)) {
        $legacy = @gethostbynamel($host);
        if (is_array($legacy)) {
            $ips = $legacy;
        }
    }
    if (empty($ips)) {
        return null;
    }

    if (!$allowPrivate) {
        foreach ($ips as $ip) {
            if (!clientBackendIsPublicIp($ip)) {
                return null;
            }
        }
    }

    return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

function clientBackendSendJsonRequest(string $url, array $payload, array $script): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return [
            'success' => false,
            'http_code' => 0,
            'response_body' => '',
            'error' => 'Failed to encode JSON payload',
        ];
    }

    $signature = clientBackendBuildWebhookSignature($body, $script);
    if (!is_string($signature) || $signature === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'response_body' => '',
            'error' => 'Failed to sign webhook payload',
        ];
    }

    $target = clientBackendResolveDispatchTarget($url);
    if ($target === null) {
        return [
            'success' => false,
            'http_code' => 0,
            'response_body' => '',
            'error' => 'Target host could not be resolved to a permitted address',
        ];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'X-Client-Webhook-Signature: ' . $signature]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // Redirect targets are unvalidated, so don't follow them.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // Pin the connection to the IP we just validated so a DNS change
        // between validation and connect time can't redirect the request.
        curl_setopt($ch, CURLOPT_RESOLVE, [$target['host'] . ':' . $target['port'] . ':' . $target['ip']]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $hasSuccessfulBody = is_string($responseBody) && stripos($responseBody, '"status":"ok"') !== false;
        return [
            'success' => ($httpCode >= 200 && $httpCode < 300) || $hasSuccessfulBody,
            'http_code' => (int) $httpCode,
            'response_body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error ?: null,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Client-Webhook-Signature: {$signature}\r\n",
            'content' => $body,
            'timeout' => 10,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $httpCode = 0;
    $error = null;

    if ($responseBody === false) {
        $error = error_get_last()['message'] ?? 'Request failed';
    }

    $hasSuccessfulBody = is_string($responseBody) && stripos($responseBody, '"status":"ok"') !== false;
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300) || $hasSuccessfulBody,
        'http_code' => $httpCode,
        'response_body' => is_string($responseBody) ? $responseBody : '',
        'error' => $error,
    ];
}

function clientBackendBuildSyncMessage(array $dispatchResult): string {
    if (($dispatchResult['success'] ?? false) === true) {
        return 'Settings were saved and delivered to the client.';
    }

    if (!empty($dispatchResult['message'])) {
        return (string) $dispatchResult['message'];
    }

    if (!empty($dispatchResult['error'])) {
        return 'The update could not be delivered to the client. Please try again or contact support.';
    }

    return 'The update could not be delivered to the client. Please try again or contact support.';
}

function clientBackendPersistAndDispatch(string $scriptId, array $changes): ?array {
    $updated = clientBackendUpdateScript($scriptId, $changes);
    if ($updated === null) {
        return null;
    }

    $dispatch = clientBackendDispatchWebhookToScript($scriptId);
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return $updated;
    }

    $script['sync_status'] = $dispatch['status'] ?? 'failed';
    $script['sync_message'] = clientBackendBuildSyncMessage($dispatch);
    return $script;
}

function clientBackendDispatchWebhookToScript(string $scriptId): array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return [
            'script_id' => $scriptId,
            'status' => 'error',
            'message' => 'Script not found',
        ];
    }

    if (empty($script['target_host'])) {
        return [
            'script_id' => $scriptId,
            'status' => 'error',
            'message' => 'Target host not configured',
        ];
    }

    $targetPath = $script['target_path'] ?? '/client/agent/agent.php';
    $url = 'http://' . $script['target_host'] . $targetPath . '?script_id=' . urlencode($scriptId) . '&action=update_lists';
    $payload = clientBackendBuildWebhookPayload($script);
    $result = clientBackendSendJsonRequest($url, $payload, $script);

    $scripts = clientBackendGetScripts();
    $scripts[$scriptId]['pending_sync_at'] = null;
    $scripts[$scriptId]['last_webhook_sent_at'] = gmdate('c');
    $scripts[$scriptId]['updated_at'] = gmdate('c');
    $scripts[$scriptId]['last_webhook_result'] = [
        'success' => $result['success'],
        'http_code' => $result['http_code'],
        'response_body' => $result['response_body'],
        'error' => $result['error'],
    ];
    $scripts[$scriptId]['sync_status'] = $result['success'] ? 'sent' : 'failed';
    $scripts[$scriptId]['sync_message'] = clientBackendBuildSyncMessage($result);
    clientBackendSaveScripts($scripts);

    return [
        'script_id' => $scriptId,
        'status' => $result['success'] ? 'sent' : 'failed',
        'http_code' => $result['http_code'],
        'response_body' => $result['response_body'],
        'error' => $result['error'],
        'payload' => $payload,
    ];
}

function clientBackendRunPendingSyncForScript(string $scriptId): array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return [
            'script_id' => $scriptId,
            'status' => 'error',
            'message' => 'Script not found',
        ];
    }

    if (empty($script['pending_sync_at'])) {
        return [
            'script_id' => $scriptId,
            'status' => 'skipped',
            'message' => 'No pending sync',
        ];
    }

    $pendingTime = strtotime($script['pending_sync_at']);
    $now = time();
    if ($pendingTime === false || $pendingTime > $now) {
        return [
            'script_id' => $scriptId,
            'status' => 'skipped',
            'message' => 'Pending sync not due yet',
        ];
    }

    return clientBackendDispatchWebhookToScript($scriptId);
}

function clientBackendRunPendingSyncs(): array {
    $scripts = clientBackendGetScripts();
    $results = [];

    foreach ($scripts as $scriptId => $_script) {
        $results[] = clientBackendRunPendingSyncForScript($scriptId);
    }

    return $results;
}

function clientBackendVerifyWebhookFlow(string $scriptId, string $targetHost, string $targetPath = '/client/agent/agent.php'): array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return ['status' => 'error', 'message' => 'Script not found'];
    }

    $normalizedHost = clientBackendNormalizeTargetHost($targetHost);
    $normalizedPath = clientBackendNormalizeTargetPath($targetPath);
    if ($normalizedHost === null) {
        return ['status' => 'error', 'message' => 'Invalid target host'];
    }

    $script['target_host'] = $normalizedHost;
    $script['target_path'] = $normalizedPath;
    clientBackendUpdateScript($scriptId, ['target_host' => $normalizedHost, 'target_path' => $normalizedPath]);
    return clientBackendDispatchWebhookToScript($scriptId);
}

function clientBackendGetStatusSummary(string $scriptId, ?int $userId = null): array {
    $script = clientBackendGetScript($scriptId);
    if ($script === null) {
        return ['status' => 'error', 'message' => 'Script not found'];
    }

    $timezone = null;
    if ($userId !== null && $userId > 0) {
        $timezone = clientBackendGetUserTimezone($userId);
    }

    return [
        'script_id' => $scriptId,
        'sync_status' => $script['sync_status'] ?? 'idle',
        'sync_message' => $script['sync_message'] ?? null,
        'updated_at' => $script['updated_at'] ?? null,
        'last_webhook_sent_at' => $script['last_webhook_sent_at'] ?? null,
        'last_webhook_result' => $script['last_webhook_result'] ?? null,
        'user_timezone' => $timezone,
    ];
}
