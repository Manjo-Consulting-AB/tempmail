<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agent/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// CSRF: this API is session-authenticated and accepts form-encoded POST, so a
// cross-site page could otherwise drive it with the user's cookie. Its only
// caller is client_agent_manage.php's same-origin fetch(), which sends Origin
// on every non-GET request; anything else that mutates is refused.
if (!in_array(strtoupper((string) $method), ['GET', 'HEAD'], true) && !requireSameOriginRequest()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
$segments = $path === '' ? [] : explode('/', $path);
$userId = clientBackendGetCurrentUserId();

function clientBackendIsValidScriptId(string $scriptId): bool {
    return preg_match('/^[a-z0-9]{6,64}$/', $scriptId) === 1;
}

if ($userId === null) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

if (!proUserIsPro($userId)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Pro required']);
    exit;
}

if ($method === 'POST' && ($segments[0] ?? '') === 'api' && ($segments[1] ?? '') === 'mailfilter' && ($segments[2] ?? '') === 'create') {
    $input = $_POST;
    if (empty($input)) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $script = clientBackendCreateScript(array_merge($input, ['owner_pro_user_id' => $userId]));
    echo json_encode(['status' => 'ok', 'script' => $script]);
    exit;
}

if (($segments[0] ?? '') === 'api' && ($segments[1] ?? '') === 'mailfilter' && (($segments[2] ?? '') === 'scripts' || isset($segments[2]))) {
    if (($segments[2] ?? '') === 'scripts') {
        $scripts = clientBackendGetScriptsForUser($userId);
        $summaries = [];
        foreach ($scripts as $scriptId => $script) {
            $summaries[] = [
                'script_id' => $scriptId,
                'label' => $script['label'] ?? null,
                'sync_status' => $script['sync_status'] ?? 'idle',
                'sync_message' => $script['sync_message'] ?? null,
                'last_webhook_sent_at' => $script['last_webhook_sent_at'] ?? null,
                'updated_at' => $script['updated_at'] ?? null,
            ];
        }
        echo json_encode(['status' => 'ok', 'scripts' => $summaries]);
        exit;
    }

    $scriptId = $segments[2];
    if (!clientBackendIsValidScriptId($scriptId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid script id']);
        exit;
    }

    $script = clientBackendGetScript($scriptId);

    if ($script === null || !clientBackendCanAccessScript($script, $userId)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Script not found']);
        exit;
    }

    if ($method === 'GET' && ($segments[3] ?? '') === 'status') {
        $summary = clientBackendGetStatusSummary($scriptId, $userId);
        echo json_encode(['status' => 'ok', 'script' => $summary]);
        exit;
    }

    if ($method === 'DELETE' && ($segments[3] ?? '') === '') {
        // Deletes this one row, and only while $userId still owns it; no other
        // script is read or written.
        if (!clientBackendDeleteScript($scriptId, $userId)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Script not found']);
            exit;
        }

        echo json_encode(['status' => 'ok', 'deleted_script_id' => $scriptId]);
        exit;
    }

    if ($method === 'GET') {
        echo json_encode(['status' => 'ok', 'script' => $script]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'whitelist') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $pattern = (string) ($input['pattern'] ?? '');
        $updated = clientBackendAddListItem($scriptId, 'whitelist', $pattern);
        if ($updated === null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid whitelist pattern']);
            exit;
        }
        $syncStatus = $updated['sync_status'] ?? 'sent';
        $isFailure = $syncStatus !== 'sent';
        if ($isFailure) {
            http_response_code(502);
        }
        echo json_encode([
            'status' => $isFailure ? 'warning' : 'ok',
            'message' => $updated['sync_message'] ?? null,
            'script' => $updated,
        ]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'blacklist') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $pattern = (string) ($input['pattern'] ?? '');
        $updated = clientBackendAddListItem($scriptId, 'blacklist', $pattern);
        if ($updated === null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid blacklist pattern']);
            exit;
        }
        $syncStatus = $updated['sync_status'] ?? 'sent';
        $isFailure = $syncStatus !== 'sent';
        if ($isFailure) {
            http_response_code(502);
        }
        echo json_encode([
            'status' => $isFailure ? 'warning' : 'ok',
            'message' => $updated['sync_message'] ?? null,
            'script' => $updated,
        ]);
        exit;
    }

    if ($method === 'DELETE' && ($segments[3] ?? '') === 'whitelist') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $pattern = (string) ($input['pattern'] ?? '');
        $updated = clientBackendRemoveListItem($scriptId, 'whitelist', $pattern);
        if ($updated === null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid whitelist pattern']);
            exit;
        }
        echo json_encode(['status' => 'ok', 'script' => $updated]);
        exit;
    }

    if ($method === 'DELETE' && ($segments[3] ?? '') === 'blacklist') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $pattern = (string) ($input['pattern'] ?? '');
        $updated = clientBackendRemoveListItem($scriptId, 'blacklist', $pattern);
        if ($updated === null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid blacklist pattern']);
            exit;
        }
        echo json_encode(['status' => 'ok', 'script' => $updated]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'spam-filters') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $updated = clientBackendAddSpamFilter($scriptId, $input);
        if ($updated === null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid spam filter rule']);
            exit;
        }
        echo json_encode([
            'status' => ($updated['sync_status'] ?? 'sent') !== 'sent' ? 'warning' : 'ok',
            'message' => $updated['sync_message'] ?? null,
            'script' => $updated,
        ]);
        exit;
    }

    if ($method === 'DELETE' && ($segments[3] ?? '') === 'spam-filters') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $ruleId = (string) ($input['rule_id'] ?? '');
        if ($ruleId === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'rule_id is required']);
            exit;
        }
        $updated = clientBackendRemoveSpamFilter($scriptId, $ruleId);
        echo json_encode([
            'status' => 'ok',
            'script' => $updated,
        ]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'schedule-sync') {
        $updated = clientBackendScheduleSync($scriptId);
        echo json_encode(['status' => 'ok', 'script' => $updated]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'run-cycle') {
        $result = clientAgentRunCycle($scriptId);
        echo json_encode(['status' => 'ok', 'result' => $result]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'cancel-sync') {
        $updated = clientBackendCancelSync($scriptId);
        echo json_encode(['status' => 'ok', 'script' => $updated]);
        exit;
    }

    if ($method === 'POST' && ($segments[3] ?? '') === 'sync') {
        // Force-send the current state to this script's webhook now, regardless
        // of pending_sync_at (manual "resend" action) - see
        // documentaion/backend/LIST_MANAGEMENT_API.md. Scoped to $scriptId only,
        // whose ownership was already verified above.
        $result = clientBackendDispatchWebhookToScript($scriptId);
        echo json_encode(['status' => 'ok', 'result' => $result]);
        exit;
    }

    if ($method === 'PATCH' && ($segments[3] ?? '') === 'settings') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $updated = clientBackendUpdateSettings($scriptId, $input);
        $syncStatus = $updated['sync_status'] ?? 'sent';
        $isFailure = $syncStatus !== 'sent';
        if ($isFailure) {
            http_response_code(502);
        }
        echo json_encode([
            'status' => $isFailure ? 'warning' : 'ok',
            'message' => $updated['sync_message'] ?? null,
            'script' => $updated,
        ]);
        exit;
    }

    if ($method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
        $updated = clientBackendUpdateScript($scriptId, array_merge($input, ['owner_pro_user_id' => $userId]));
        echo json_encode(['status' => 'ok', 'script' => $updated]);
        exit;
    }
}

echo json_encode(['status' => 'ok', 'message' => 'Backend scaffold ready']);
