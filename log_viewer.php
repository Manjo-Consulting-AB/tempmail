<?php
/**
 * TempMail Log Viewer
 * Standalone admin UI for inspecting system_logs and managing hidden log types.
 */

// Load environment
function loadEnvFile($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "'\"");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

$envFile = __DIR__ . '/.env.production';
if (file_exists($envFile)) loadEnvFile($envFile);

// Database connection
$dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'db');
$dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'tempmail');
$dbUser = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'tempmail_user');
$dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
$dbCharset = getenv('DB_CHARSET') ?: ($_ENV['DB_CHARSET'] ?? 'utf8mb4');

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset);
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

// Session-based access control
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$allowedEmail = 'tony@manjo.se';
$userEmail = isset($_SESSION['pro_user_email']) ? strtolower(trim((string)$_SESSION['pro_user_email'])) : null;
if ($userEmail !== strtolower($allowedEmail)) {
    http_response_code(403);
    echo "Access denied\n";
    exit;
}

// Helper: JSON response
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// AJAX action handlers
if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    header('Content-Type: application/json');
    
    try {
        if ($action === 'unique_logs') {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1000;
            $limit = max(1, min(5000, $limit));
            $sql = "SELECT LEFT(message,255) AS log_key, COUNT(*) AS cnt, MAX(created_at) AS last_seen 
                    FROM system_logs GROUP BY log_key ORDER BY last_seen DESC LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$limit]);
            jsonResponse(['success' => true, 'rows' => $stmt->fetchAll()]);
        }

        if ($action === 'get_hidden') {
            $stmt = $pdo->query("SELECT log_key, description, hidden, created_at, updated_at FROM hidden_log_types ORDER BY updated_at DESC");
            jsonResponse(['success' => true, 'rows' => $stmt->fetchAll()]);
        }

        if ($action === 'set_hidden' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $logKey = isset($_POST['log_key']) ? mb_substr(str_replace("\0", '', trim($_POST['log_key'])), 0, 255) : null;
            $hidden = isset($_POST['hidden']) && (int)$_POST['hidden'] ? 1 : 0;
            $description = isset($_POST['description']) ? mb_substr(str_replace("\0", '', trim($_POST['description'])), 0, 500) : null;
            
            if (!$logKey) {
                jsonResponse(['success' => false, 'error' => 'log_key required'], 400);
            }
            
            $stmt = $pdo->prepare("INSERT INTO hidden_log_types (log_key, description, hidden) 
                                   VALUES (?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE description = VALUES(description), hidden = VALUES(hidden), updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$logKey, $description, $hidden]);
            jsonResponse(['success' => true]);
        }

        // Unknown action
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Parse filter parameters
$format = (isset($_GET['format']) && $_GET['format'] === 'json') ? 'json' : 'html';
$level = isset($_GET['level']) && $_GET['level'] !== '' ? strtoupper(trim($_GET['level'])) : null;
$q = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null;
$since = isset($_GET['since']) && $_GET['since'] !== '' ? trim($_GET['since']) : null;
$until = isset($_GET['until']) && $_GET['until'] !== '' ? trim($_GET['until']) : null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 2000) $limit = 200;

// Build query
$where = [];
$params = [];
if ($level) {
    $where[] = 'log_level = ?';
    $params[] = $level;
}
if ($q) {
    $where[] = '(message LIKE ? OR context LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
if ($since) {
    $where[] = 'created_at >= ?';
    $params[] = $since;
}
if ($until) {
    $where[] = 'created_at <= ?';
    $params[] = $until;
}

$sql = 'SELECT id, log_level, message, context, created_at FROM system_logs';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    if ($format === 'json') {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
    echo '<pre>Query error: ' . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

// JSON output
if ($format === 'json') {
    jsonResponse(['success' => true, 'count' => count($rows), 'rows' => $rows]);
}

// HTML output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>TempMail Log Viewer</title>
    <style>
        body { font-family: Inter, "Segoe UI", Arial, Helvetica, sans-serif; margin: 16px; background: #f7f7fb; }
        .controls { margin-bottom: 12px; }
        input, select { padding: 6px; margin-right: 6px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #fafafa; text-align: left; }
        .level-ERROR { color: #a00; font-weight: 600; }
        .level-WARNING { color: #b65; }
        .level-INFO { color: #0b66; }
        .level-DEBUG { color: #666; }
        .mono { font-family: SFMono-Regular, Menlo, monospace; font-size: 12px; color: #444; }
        .tabs { margin-bottom: 12px; }
        .tabs button { margin-right: 6px; padding: 6px 10px; cursor: pointer; }
        .tabs button.active { background: #007bff; color: #fff; border-color: #007bff; }
        #adminSection { display: none; }
    </style>
</head>
<body>
<h3>TempMail Log Viewer</h3>

<div class="tabs">
    <button type="button" id="tabLogs" class="active">Logs</button>
    <button type="button" id="tabAdmin">Admin</button>
</div>

<div id="logsSection">
    <form method="get" class="controls" id="filterForm">
        <label>Level:
            <select name="level">
                <option value=""<?php echo $level === null ? ' selected' : ''; ?>>Any</option>
                <option value="ERROR"<?php echo $level === 'ERROR' ? ' selected' : ''; ?>>ERROR</option>
                <option value="WARNING"<?php echo $level === 'WARNING' ? ' selected' : ''; ?>>WARNING</option>
                <option value="INFO"<?php echo $level === 'INFO' ? ' selected' : ''; ?>>INFO</option>
                <option value="DEBUG"<?php echo $level === 'DEBUG' ? ' selected' : ''; ?>>DEBUG</option>
            </select>
        </label>
        <label>Search: <input type="search" name="q" value="<?php echo htmlspecialchars($q ?? ''); ?>" placeholder="text or JSON"/></label>
        <label>Since: <input type="date" name="since" value="<?php echo htmlspecialchars($since ?? ''); ?>"/></label>
        <label>Until: <input type="date" name="until" value="<?php echo htmlspecialchars($until ?? ''); ?>"/></label>
        <label>Limit: <input type="number" name="limit" value="<?php echo $limit; ?>" min="1" max="2000" style="width:70px"/></label>
        <button type="submit">Apply</button>
        <button type="button" id="tailBtn">Start Tail</button>
    </form>

    <table id="logs">
        <thead><tr><th style="width:150px">Time</th><th style="width:80px">Level</th><th>Message / Context</th></tr></thead>
        <tbody>
<?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td class="level-<?php echo htmlspecialchars($r['log_level']); ?>"><?php echo htmlspecialchars($r['log_level']); ?></td>
                <td>
                    <?php echo htmlspecialchars($r['message']); ?>
<?php if (!empty($r['context'])): ?>
                    <div class="mono">Context: <?php echo htmlspecialchars($r['context']); ?></div>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="adminSection">
    <h4>Admin — Unique log entries</h4>
    <p>Shows unique log messages (first 255 chars). Mark "Hide" to suppress future logging of that type.</p>
    <div style="margin-bottom:8px">
        <label>Limit: <input type="number" id="adminLimit" value="500" min="10" max="5000" style="width:80px"/></label>
        <button type="button" id="reloadAdmin">Reload</button>
    </div>
    <table id="adminTable">
        <thead><tr><th>Last Seen</th><th>Count</th><th>Log Key</th><th>Hidden</th></tr></thead>
        <tbody></tbody>
    </table>

    <h4 style="margin-top:16px">Hidden overrides</h4>
    <table id="hiddenTable">
        <thead><tr><th>Log Key</th><th>Description</th><th>Hidden</th><th>Updated</th></tr></thead>
        <tbody></tbody>
    </table>
</div>

<script>
(function() {
    // Escape HTML helper
    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/[&"'<>]/g, function(c) {
            return {'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'}[c];
        });
    }

    // Tab switching
    var tabLogs = document.getElementById('tabLogs');
    var tabAdmin = document.getElementById('tabAdmin');
    var logsSection = document.getElementById('logsSection');
    var adminSection = document.getElementById('adminSection');

    tabLogs.addEventListener('click', function() {
        tabLogs.classList.add('active');
        tabAdmin.classList.remove('active');
        logsSection.style.display = 'block';
        adminSection.style.display = 'none';
    });

    tabAdmin.addEventListener('click', function() {
        tabAdmin.classList.add('active');
        tabLogs.classList.remove('active');
        logsSection.style.display = 'none';
        adminSection.style.display = 'block';
        loadAdmin();
    });

    // Tailing
    var tailing = false;
    var tailBtn = document.getElementById('tailBtn');
    var form = document.getElementById('filterForm');

    tailBtn.addEventListener('click', function() {
        tailing = !tailing;
        tailBtn.textContent = tailing ? 'Stop Tail' : 'Start Tail';
        if (tailing) tailTick();
    });

    function tailTick() {
        if (!tailing) return;
        var params = new URLSearchParams(new FormData(form));
        params.set('format', 'json');
        fetch(location.pathname + '?' + params.toString())
            .then(function(res) { return res.json(); })
            .then(function(j) {
                if (j && j.rows) {
                    var tbody = document.querySelector('#logs tbody');
                    tbody.innerHTML = '';
                    j.rows.forEach(function(r) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td class="mono">' + escapeHtml(r.created_at) + '</td>' +
                                       '<td class="level-' + escapeHtml(r.log_level) + '">' + escapeHtml(r.log_level) + '</td>' +
                                       '<td>' + escapeHtml(r.message) +
                                       (r.context ? '<div class="mono">Context: ' + escapeHtml(r.context) + '</div>' : '') +
                                       '</td>';
                        tbody.appendChild(tr);
                    });
                }
            })
            .catch(function(e) { console.error('Tail error', e); });
        setTimeout(tailTick, 3000);
    }

    // Admin tab
    var adminLimit = document.getElementById('adminLimit');
    var reloadAdmin = document.getElementById('reloadAdmin');

    reloadAdmin.addEventListener('click', loadAdmin);

    function loadAdmin() {
        var limit = parseInt(adminLimit.value, 10) || 500;
        Promise.all([
            fetch(location.pathname + '?action=unique_logs&limit=' + encodeURIComponent(limit)).then(function(r) { return r.json(); }),
            fetch(location.pathname + '?action=get_hidden').then(function(r) { return r.json(); })
        ]).then(function(results) {
            var uniqueJson = results[0];
            var hiddenJson = results[1];

            // Build hidden map
            var hiddenMap = {};
            if (hiddenJson && hiddenJson.rows) {
                hiddenJson.rows.forEach(function(h) { hiddenMap[h.log_key] = h; });
            }

            // Populate unique logs table
            var tbody = document.querySelector('#adminTable tbody');
            tbody.innerHTML = '';
            if (uniqueJson && uniqueJson.rows) {
                uniqueJson.rows.forEach(function(r) {
                    var key = r.log_key;
                    var h = hiddenMap[key];
                    var isHidden = h ? (h.hidden == 1) : false;
                    var tr = document.createElement('tr');
                    var radioName = 'hide_' + btoa(key).replace(/[^a-zA-Z0-9]/g, '');
                    tr.innerHTML = '<td class="mono">' + escapeHtml(r.last_seen) + '</td>' +
                                   '<td>' + escapeHtml(r.cnt) + '</td>' +
                                   '<td class="mono" style="max-width:400px;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(key) + '</td>' +
                                   '<td>' +
                                   '<label><input type="radio" name="' + radioName + '" value="0"' + (!isHidden ? ' checked' : '') + '/> Show</label> ' +
                                   '<label><input type="radio" name="' + radioName + '" value="1"' + (isHidden ? ' checked' : '') + '/> Hide</label>' +
                                   '</td>';
                    tbody.appendChild(tr);

                    // Attach event listeners to radios
                    tr.querySelectorAll('input[type=radio]').forEach(function(radio) {
                        radio.addEventListener('change', function(ev) {
                            var hidden = ev.target.value === '1' ? 1 : 0;
                            setHidden(key, hidden);
                        });
                    });
                });
            }

            // Populate hidden overrides table
            var ht = document.querySelector('#hiddenTable tbody');
            ht.innerHTML = '';
            if (hiddenJson && hiddenJson.rows) {
                hiddenJson.rows.forEach(function(h) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="mono" style="max-width:300px;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(h.log_key) + '</td>' +
                                   '<td>' + escapeHtml(h.description || '') + '</td>' +
                                   '<td>' + (h.hidden == 1 ? 'Yes' : 'No') + '</td>' +
                                   '<td class="mono">' + escapeHtml(h.updated_at) + '</td>';
                    ht.appendChild(tr);
                });
            }
        }).catch(function(e) {
            console.error('Admin load error', e);
        });
    }

    function setHidden(key, hidden) {
        var fd = new URLSearchParams();
        fd.append('action', 'set_hidden');
        fd.append('log_key', key);
        fd.append('hidden', hidden ? '1' : '0');
        fetch(location.pathname, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(j) {
                if (!j || !j.success) {
                    console.error('Failed to set hidden', j);
                }
                setTimeout(loadAdmin, 300);
            })
            .catch(function(e) { console.error('setHidden error', e); });
    }
})();
</script>
</body>
</html>
