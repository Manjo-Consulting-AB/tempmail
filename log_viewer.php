<?php
/**
 * Mail Shield admin: system overview, log viewer and log-type management.
 *
 * Three views (?view=):
 *   - overview (default): accounts, addresses, incoming mail, the webhook
 *     queue, the abuse guard, the log and health checks, with the things that
 *     need attention listed first (admin_overview.php).
 *   - logs: system_logs with filters, paging and a live tail.
 *   - types: unique log messages and the hidden_log_types overrides that
 *     suppress a noisy message type.
 * The abuse guard's own page, abuse_admin.php, is the fourth tab.
 *
 * Kept for scripts: ?format=json returns the filtered log rows, and the
 * unique_logs / get_hidden / set_hidden actions are unchanged.
 */

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
// config.php loads the environment (including the secure, outside-webroot
// .env.production location) and sets up the global $pdo connection.

// Session-based access control
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}
// Admins are listed by account id in ADMIN_USER_IDS (comma-separated), not by
// email: an id cannot be taken over by changing an account's address, and no
// address is hardcoded in the code. Unset means nobody is admin (fail closed).
$sessionUserId = (int) ($_SESSION['pro_user_id'] ?? 0);
if (!isAdminUser($sessionUserId)) {
    // Server-side only, never echoed; no session id or email in the log.
    error_log(sprintf('log_viewer.php access denied: logged_in=%s', $sessionUserId > 0 ? 'yes' : 'no'));
    http_response_code(403);
    echo "Access denied\n";
    exit;
}

header('X-Robots-Tag: noindex, nofollow');

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
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            jsonResponse(['success' => true, 'rows' => $stmt->fetchAll()]);
        }

        if ($action === 'get_hidden') {
            $stmt = $pdo->query("SELECT log_key, description, hidden, created_at, updated_at FROM hidden_log_types ORDER BY updated_at DESC");
            jsonResponse(['success' => true, 'rows' => $stmt->fetchAll()]);
        }

        if ($action === 'set_hidden' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // CSRF: only this page's own same-origin fetch() may change state.
            if (!requireSameOriginRequest()) {
                jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
            }
            $logKey = isset($_POST['log_key']) ? mb_substr(str_replace("\0", '', trim($_POST['log_key'])), 0, 255) : null;
            $hidden = isset($_POST['hidden']) && (int)$_POST['hidden'] ? 1 : 0;
            $description = isset($_POST['description']) ? mb_substr(str_replace("\0", '', trim($_POST['description'])), 0, 500) : null;

            if (!$logKey) {
                jsonResponse(['success' => false, 'error' => 'log_key required'], 400);
            }

            $stmt = $pdo->prepare("INSERT INTO hidden_log_types (log_key, description, hidden)
                                   VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE description = COALESCE(VALUES(description), description), hidden = VALUES(hidden), updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$logKey, $description, $hidden]);
            logMessage('INFO', 'Log type visibility changed by an admin', ['admin_id' => $sessionUserId, 'hidden' => $hidden]);
            jsonResponse(['success' => true]);
        }

        // Unknown action
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        error_log('log_viewer.php action failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

$validLevels = ['ERROR', 'WARNING', 'INFO', 'DEBUG'];

/** A YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS] filter value, or null. */
function logViewerDate(?string $value, bool $endOfDay): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value)) {
        return str_replace('T', ' ', $value);
    }
    return null;
}

// Parse filter parameters
$view = (string)($_GET['view'] ?? '');
$format = (isset($_GET['format']) && $_GET['format'] === 'json') ? 'json' : 'html';
if ($format === 'json' || !in_array($view, ['overview', 'logs', 'types'], true)) {
    // Old links carrying log filters (and every JSON call) mean the log view.
    $view = ($format === 'json' || isset($_GET['level']) || isset($_GET['q'])) ? 'logs' : 'overview';
}
$level = strtoupper(trim((string)($_GET['level'] ?? '')));
$level = in_array($level, $validLevels, true) ? $level : null;
$q = isset($_GET['q']) && trim($_GET['q']) !== '' ? mb_substr(trim($_GET['q']), 0, 200) : null;
$sinceRaw = isset($_GET['since']) ? trim((string)$_GET['since']) : '';
$untilRaw = isset($_GET['until']) ? trim((string)$_GET['until']) : '';
$since = logViewerDate($sinceRaw, false);
$until = logViewerDate($untilRaw, true);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 2000) $limit = 200;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$rows = [];
$matched = null;
if ($view === 'logs') {
    // One constant statement: every filter is optional through "? IS NULL OR",
    // and LIMIT / OFFSET are bound as integers, so no request value ever
    // becomes part of the SQL text.
    $like = $q !== null ? '%' . addcslashes($q, '%_\\') . '%' : null;
    $filters = [$level, $level, $like, $like, $like, $since, $since, $until, $until];
    $filterSql = ' WHERE (? IS NULL OR log_level = ?)
                     AND (? IS NULL OR message LIKE ? OR context LIKE ?)
                     AND (? IS NULL OR created_at >= ?)
                     AND (? IS NULL OR created_at <= ?)';
    $bindFilters = static function (PDOStatement $stmt) use ($filters): void {
        foreach ($filters as $i => $value) {
            $stmt->bindValue($i + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
    };

    try {
        $stmt = $pdo->prepare('SELECT id, log_level, message, context, created_at FROM system_logs' . $filterSql
            . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        $bindFilters($stmt);
        $stmt->bindValue(count($filters) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($filters) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM system_logs' . $filterSql);
        $bindFilters($countStmt);
        $countStmt->execute();
        $matched = (int)$countStmt->fetchColumn();
    } catch (Exception $e) {
        error_log('log_viewer.php query failed: ' . $e->getMessage());
        if ($format === 'json') {
            jsonResponse(['success' => false, 'error' => 'Database error'], 500);
        }
        $rows = [];
        $matched = null;
    }

    // JSON output
    if ($format === 'json') {
        jsonResponse(['success' => true, 'count' => count($rows), 'total' => $matched, 'rows' => $rows]);
    }
}

$overview = null;
if ($view === 'overview') {
    require_once __DIR__ . '/admin_overview.php';
    $overview = adminOverview($pdo);
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn(?int $v): string => $v === null ? '—' : number_format($v, 0, '.', ' ');

/** The current log filters as a query string, with $overrides applied. */
$logsUrl = static function (array $overrides = []) use ($level, $q, $sinceRaw, $untilRaw, $limit): string {
    $params = array_filter([
        'view' => 'logs',
        'level' => $level,
        'q' => $q,
        'since' => $sinceRaw,
        'until' => $untilRaw,
        'limit' => $limit !== 200 ? $limit : null,
    ], static fn($v) => $v !== null && $v !== '');
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'log_viewer.php?' . http_build_query($params);
};

/** A 24-hour bar chart of one series; the numbers are also in the tooltip and a hidden list. */
$bars = static function (array $series, string $title, string $variant) use ($h): string {
    $max = 0;
    foreach ($series as $point) {
        $max = max($max, (int)$point['count']);
    }
    $total = array_sum(array_column($series, 'count'));
    $html = '<figure class="ms-admin__chart"><figcaption class="ms-admin__chart-title">' . $h($title)
          . ' <span class="ms-admin__chart-total">' . $h(number_format($total, 0, '.', ' ')) . ' in 24 h</span></figcaption>'
          . '<div class="ms-admin__bars ms-admin__bars--' . $h($variant) . '" aria-hidden="true">';
    foreach ($series as $point) {
        $pct = $max > 0 ? max((int)$point['count'] > 0 ? 4 : 0, (int)round((int)$point['count'] / $max * 100)) : 0;
        $html .= '<span class="ms-admin__bar" title="' . $h($point['hour'] . ': ' . $point['count']) . '">'
               . '<span class="ms-admin__bar-fill" style="--ms-bar: ' . $pct . '%"></span></span>';
    }
    $first = $series[0]['label'] ?? '';
    $html .= '</div><div class="ms-admin__bars-axis" aria-hidden="true"><span>' . $h($first) . '</span><span>now</span></div>'
           . '<ul class="ms-visually-hidden">';
    foreach ($series as $point) {
        $html .= '<li>' . $h($point['hour'] . ': ' . $point['count']) . '</li>';
    }
    return $html . '</ul></figure>';
};

$msAdminTab = $view;
$msAdminTitle = ['overview' => 'Admin overview', 'logs' => 'Logs', 'types' => 'Log types'][$view];
require __DIR__ . '/partials/admin_head.php';
?>

<?php if ($view === 'overview'): ?>
<?php
    $o = $overview;
    $acc = $o['accounts'];
    $adr = $o['addresses'];
    $mail = $o['mail'];
    $wh = $o['webhooks'];
    $ab = $o['abuse'];
    $lg = $o['logs'];
    $hl = $o['health'];
    $yes = static fn(bool $ok, string $good = 'yes', string $bad = 'no'): string =>
        '<span class="ms-admin__state ms-admin__state--' . ($ok ? 'ok' : 'bad') . '">' . htmlspecialchars($ok ? $good : $bad, ENT_QUOTES, 'UTF-8') . '</span>';
?>
            <h1 class="ms-admin__title">System overview</h1>
            <p class="ms-admin__lede">
                Snapshot at <?php echo htmlspecialchars((string)($o['db_now'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?> (database time) ·
                <?php echo htmlspecialchars((string)($hl['environment']), ENT_QUOTES, 'UTF-8'); ?> · v<?php echo htmlspecialchars((string)($hl['app_version']), ENT_QUOTES, 'UTF-8'); ?> ·
                <a href="log_viewer.php">Refresh</a>
            </p>

            <section class="ms-admin__section ms-admin__section--first" aria-labelledby="attnHeading">
                <h2 class="ms-admin__heading" id="attnHeading">Needs attention</h2>
<?php if ($o['alerts'] === []): ?>
                <p class="ms-admin__allclear"><span class="ms-admin__state ms-admin__state--ok">OK</span> Nothing needs attention right now.</p>
<?php else: ?>
                <ul class="ms-admin__alerts">
<?php foreach ($o['alerts'] as $alert): ?>
                    <li class="ms-admin__alert ms-admin__alert--<?php echo htmlspecialchars((string)($alert['level']), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="ms-admin__state ms-admin__state--<?php echo $alert['level'] === 'danger' ? 'bad' : 'warn'; ?>"><?php echo $alert['level'] === 'danger' ? 'Problem' : 'Check'; ?></span>
                        <?php if ($alert['href'] !== null): ?><a href="<?php echo htmlspecialchars((string)($alert['href']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($alert['text']), ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><?php echo htmlspecialchars((string)($alert['text']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>

            <div class="ms-admin__grid">
                <section class="ms-admin__card" aria-labelledby="accHeading">
                    <h2 class="ms-admin__card-title" id="accHeading">Accounts</h2>
                    <dl class="ms-admin__stats">
                        <div><dt>Total</dt><dd><?php echo htmlspecialchars($num($acc['total']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Pro</dt><dd><?php echo htmlspecialchars($num($acc['pro']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Free</dt><dd><?php echo htmlspecialchars($num($acc['regular']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>New, 7 days</dt><dd><?php echo htmlspecialchars($num($acc['new_7d']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Signed in, 24 h</dt><dd><?php echo htmlspecialchars($num($acc['active_24h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Pro ending in 7 days</dt><dd><?php echo htmlspecialchars($num($acc['pro_expiring_7d']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Suspended</dt><dd><?php echo htmlspecialchars($num($acc['suspended']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    </dl>
                </section>

                <section class="ms-admin__card" aria-labelledby="adrHeading">
                    <h2 class="ms-admin__card-title" id="adrHeading">Addresses</h2>
                    <dl class="ms-admin__stats">
                        <div><dt>Active</dt><dd><?php echo htmlspecialchars($num($adr['active']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Sticky</dt><dd><?php echo htmlspecialchars($num($adr['sticky']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Timed</dt><dd><?php echo htmlspecialchars($num($adr['timed']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Created, 24 h</dt><dd><?php echo htmlspecialchars($num($adr['created_24h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Expired, not yet removed</dt><dd><?php echo htmlspecialchars($num($adr['overdue_cleanup']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    </dl>
                </section>

                <section class="ms-admin__card" aria-labelledby="mailHeading">
                    <h2 class="ms-admin__card-title" id="mailHeading">Incoming mail</h2>
                    <dl class="ms-admin__stats">
                        <div><dt>Last hour</dt><dd><?php echo htmlspecialchars($num($mail['received_1h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>24 hours</dt><dd><?php echo htmlspecialchars($num($mail['received_24h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>7 days</dt><dd><?php echo htmlspecialchars($num($mail['received_7d']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Stored now</dt><dd><?php echo htmlspecialchars($num($mail['stored']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Attachments</dt><dd><?php echo htmlspecialchars($num($mail['attachments']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)(adminFormatBytes($mail['attachment_bytes'])), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Last received</dt><dd><?php echo htmlspecialchars((string)(adminAge($mail['last_received'], $o['db_now']) !== null ? adminAge($mail['last_received'], $o['db_now']) . ' ago' : '—'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    </dl>
                </section>

                <section class="ms-admin__card" aria-labelledby="whHeading">
                    <h2 class="ms-admin__card-title" id="whHeading">Webhooks</h2>
                    <dl class="ms-admin__stats">
                        <div><dt>Active hooks</dt><dd><?php echo htmlspecialchars($num($wh['active_hooks']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Queued</dt><dd><?php echo htmlspecialchars($num($wh['pending']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Overdue &gt; 15 min</dt><dd><?php echo htmlspecialchars($num($wh['overdue']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Delivered, 24 h</dt><dd><?php echo htmlspecialchars($num($wh['succeeded_24h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Given up, 24 h</dt><dd><?php echo htmlspecialchars($num($wh['failed_24h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    </dl>
                </section>

                <section class="ms-admin__card" aria-labelledby="abHeading">
                    <h2 class="ms-admin__card-title" id="abHeading">Abuse guard</h2>
<?php if (!$ab['enabled']): ?>
                    <p class="ms-admin__empty">Turned off (ABUSE_GUARD_ENABLED).</p>
<?php elseif (!$ab['available']): ?>
                    <p class="ms-admin__empty">Tables missing: run <code>php migrate_abuse_guard.php</code>.</p>
<?php else: ?>
                    <dl class="ms-admin__stats">
                        <div><dt>In quarantine</dt><dd><?php echo htmlspecialchars($num($ab['quarantined']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Closed</dt><dd><?php echo htmlspecialchars($num($ab['closed']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Suspension proposals</dt><dd><?php echo htmlspecialchars($num($ab['proposals']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Events, 24 h</dt><dd><?php echo htmlspecialchars($num($ab['events_24h']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <div><dt>Last hourly report</dt><dd><?php echo htmlspecialchars((string)(adminAge($ab['last_report'], $o['db_now']) !== null ? adminAge($ab['last_report'], $o['db_now']) . ' ago' : '—'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    </dl>
<?php endif; ?>
                    <p class="ms-admin__card-link"><a href="abuse_admin.php">Open the abuse guard</a></p>
                </section>

                <section class="ms-admin__card" aria-labelledby="logHeading">
                    <h2 class="ms-admin__card-title" id="logHeading">Log, 24 hours</h2>
                    <dl class="ms-admin__stats">
<?php foreach ($lg['levels_24h'] as $lvl => $count): ?>
                        <div><dt><a href="<?php echo htmlspecialchars((string)('log_viewer.php?' . http_build_query(['view' => 'logs', 'level' => $lvl, 'since' => date('Y-m-d', strtotime((string)($o['db_now'] ?? 'now')) - 86400)])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($lvl), ENT_QUOTES, 'UTF-8'); ?></a></dt><dd><?php echo htmlspecialchars($num((int)$count), ENT_QUOTES, 'UTF-8'); ?></dd></div>
<?php endforeach; ?>
                        <div><dt>Rows kept</dt><dd><?php echo htmlspecialchars($num($lg['total']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)(int)$hl['log_retention_days'], ENT_QUOTES, 'UTF-8'); ?> days</dd></div>
                        <div><dt>Hidden types</dt><dd><a href="log_viewer.php?view=types"><?php echo htmlspecialchars($num($lg['hidden_types']), ENT_QUOTES, 'UTF-8'); ?></a></dd></div>
                    </dl>
                </section>
            </div>

            <section class="ms-admin__section" aria-labelledby="actHeading">
                <h2 class="ms-admin__heading" id="actHeading">Activity, last 24 hours</h2>
                <div class="ms-admin__charts">
                    <?php echo $bars($mail['hourly'], 'Mail received per hour', 'accent'); ?>
                    <?php echo $bars($lg['hourly_errors'], 'Errors logged per hour', 'danger'); ?>
                </div>
            </section>

            <section class="ms-admin__section" aria-labelledby="topHeading">
                <h2 class="ms-admin__heading" id="topHeading">Most frequent errors and warnings, 24 hours</h2>
<?php if ($lg['top_problems'] === []): ?>
                <p class="ms-admin__empty">None.</p>
<?php else: ?>
                <div class="ms-admin__scroll">
                    <table class="ms-admin__table">
                        <thead><tr><th>Count</th><th>Level</th><th>Last seen</th><th>Message</th></tr></thead>
                        <tbody>
<?php foreach ($lg['top_problems'] as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($num((int)$p['n']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="ms-admin__level ms-admin__level--<?php echo htmlspecialchars((string)(strtolower((string)$p['log_level'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($p['log_level']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars((string)($p['last_seen']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="ms-admin__wrap"><a href="<?php echo htmlspecialchars((string)('log_viewer.php?' . http_build_query(['view' => 'logs', 'level' => $p['log_level'], 'q' => mb_substr((string)$p['log_key'], 0, 200)])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($p['log_key']), ENT_QUOTES, 'UTF-8'); ?></a></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
<?php endif; ?>
            </section>

<?php if ($wh['recent_failures'] !== []): ?>
            <section class="ms-admin__section" aria-labelledby="whfHeading">
                <h2 class="ms-admin__heading" id="whfHeading">Latest webhook deliveries given up</h2>
                <div class="ms-admin__scroll">
                    <table class="ms-admin__table">
                        <thead><tr><th>Delivery</th><th>Hook</th><th>Account</th><th>Attempts</th><th>HTTP</th><th>Updated</th><th>Error</th></tr></thead>
                        <tbody>
<?php foreach ($wh['recent_failures'] as $f): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars((string)(int)$f['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>#<?php echo htmlspecialchars((string)(int)$f['webhook_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>#<?php echo htmlspecialchars((string)(int)$f['user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)(int)$f['attempts'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($f['response_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($f['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="ms-admin__mono"><?php echo htmlspecialchars((string)(mb_substr((string)($f['last_error'] ?? ''), 0, 300)), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="ms-admin__empty">Requeue one with <code>php cron/requeue-webhook-delivery.php</code>.</p>
            </section>
<?php endif; ?>

            <section class="ms-admin__section" aria-labelledby="healthHeading">
                <h2 class="ms-admin__heading" id="healthHeading">Health and configuration</h2>
                <div class="ms-admin__scroll">
                    <table class="ms-admin__table">
                        <tbody>
                            <tr><th scope="row">Environment</th><td><?php echo htmlspecialchars((string)($hl['environment']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <tr><th scope="row">PHP / database</th><td><?php echo htmlspecialchars((string)($hl['php_version']), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($hl['db_version'] ?? 'unreachable'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <tr><th scope="row">Log level</th><td><?php echo htmlspecialchars((string)($hl['log_level']), ENT_QUOTES, 'UTF-8'); ?><?php echo $hl['debug_mode'] ? ' (DEBUG_MODE on)' : ''; ?></td></tr>
                            <tr><th scope="row">DirectAdmin forwarders</th><td><?php echo $hl['environment'] === 'production' ? $yes($hl['forwarders_enabled'], 'enabled', 'disabled') : $h($hl['forwarders_enabled'] ? 'enabled' : 'disabled (normal outside production)'); ?></td></tr>
                            <tr><th scope="row">Email encryption keys</th><td><?php echo $yes($hl['pii_keys'], 'set', 'missing'); ?></td></tr>
                            <tr><th scope="row">WEBHOOKS_KEY</th><td><?php echo $yes($hl['webhooks_key'], 'set', 'missing'); ?></td></tr>
                            <tr><th scope="row">PRO_TRIAL_HASH_KEY</th><td><?php echo $yes($hl['trial_hash_key'], 'set', 'missing'); ?></td></tr>
                            <tr><th scope="row">Attachments directory</th><td><?php echo $yes($hl['attachments_writable'], 'writable', 'not writable'); ?> · <?php echo htmlspecialchars((string)(adminFormatBytes($hl['disk_free'])), ENT_QUOTES, 'UTF-8'); ?> free</td></tr>
                            <tr><th scope="row">Composer dependencies</th><td><?php echo $yes($hl['vendor'], 'installed', 'missing'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

<?php elseif ($view === 'logs'): ?>
            <h1 class="ms-admin__title">Logs</h1>

            <form method="get" class="ms-admin__filters" id="filterForm">
                <input type="hidden" name="view" value="logs">
                <label>Level
                    <select name="level" class="ms-admin__input">
                        <option value=""<?php echo $level === null ? ' selected' : ''; ?>>Any</option>
<?php foreach ($validLevels as $lvl): ?>
                        <option value="<?php echo htmlspecialchars((string)($lvl), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $level === $lvl ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)($lvl), ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label>Search
                    <input type="search" name="q" class="ms-admin__input ms-admin__input--wide" value="<?php echo htmlspecialchars((string)($q ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="text, user_id, JSON" maxlength="200">
                </label>
                <label>Since <input type="date" name="since" class="ms-admin__input" value="<?php echo htmlspecialchars((string)($sinceRaw), ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label>Until <input type="date" name="until" class="ms-admin__input" value="<?php echo htmlspecialchars((string)($untilRaw), ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label>Per page <input type="number" name="limit" class="ms-admin__input" value="<?php echo htmlspecialchars((string)(int)$limit, ENT_QUOTES, 'UTF-8'); ?>" min="1" max="2000"></label>
                <button type="submit" class="ms-btn ms-btn--primary">Apply</button>
                <a class="ms-btn ms-btn--quiet" href="log_viewer.php?view=logs">Reset</a>
                <button type="button" class="ms-btn ms-btn--secondary" id="tailBtn" aria-pressed="false">Start live tail</button>
            </form>

            <p class="ms-admin__lede" id="logSummary">
<?php if ($matched === null): ?>
                The log could not be read; see the PHP error log.
<?php else: ?>
                <?php echo htmlspecialchars($num($matched), ENT_QUOTES, 'UTF-8'); ?> matching row(s)<?php if ($matched > 0): ?>, showing <?php echo htmlspecialchars($num($offset + 1), ENT_QUOTES, 'UTF-8'); ?>–<?php echo htmlspecialchars($num($offset + count($rows)), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>.
                <a href="<?php echo htmlspecialchars((string)($logsUrl(['format' => 'json', 'page' => $page > 1 ? $page : null])), ENT_QUOTES, 'UTF-8'); ?>">JSON</a>
<?php endif; ?>
            </p>

            <div class="ms-admin__scroll">
                <table class="ms-admin__table" id="logs">
                    <thead><tr><th>Time</th><th>Level</th><th>Message / context</th></tr></thead>
                    <tbody>
<?php foreach ($rows as $r): ?>
<?php
    $ctx = (string)($r['context'] ?? '');
    $pretty = $ctx;
    if ($ctx !== '') {
        $decoded = json_decode($ctx, true);
        if (is_array($decoded)) {
            $pretty = (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($r['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="ms-admin__level ms-admin__level--<?php echo htmlspecialchars((string)(strtolower((string)$r['log_level'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($r['log_level']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="ms-admin__wrap">
                                <?php echo htmlspecialchars((string)($r['message']), ENT_QUOTES, 'UTF-8'); ?>
<?php if ($ctx !== ''): ?>
                                <details class="ms-admin__ctx"><summary>Context</summary><pre><?php echo htmlspecialchars((string)($pretty), ENT_QUOTES, 'UTF-8'); ?></pre></details>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
<?php if ($rows === [] && $matched !== null): ?>
                        <tr><td colspan="3" class="ms-admin__empty">No log rows match.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>

<?php if ($matched !== null && $matched > $limit): ?>
            <nav class="ms-admin__pager" aria-label="Log pages">
<?php if ($page > 1): ?>
                <a class="ms-btn ms-btn--secondary" href="<?php echo htmlspecialchars((string)($logsUrl(['page' => $page - 1 > 1 ? $page - 1 : null])), ENT_QUOTES, 'UTF-8'); ?>">Newer</a>
<?php endif; ?>
                <span>Page <?php echo htmlspecialchars((string)(int)$page, ENT_QUOTES, 'UTF-8'); ?> of <?php echo htmlspecialchars((string)(int)ceil($matched / $limit), ENT_QUOTES, 'UTF-8'); ?></span>
<?php if ($offset + $limit < $matched): ?>
                <a class="ms-btn ms-btn--secondary" href="<?php echo htmlspecialchars((string)($logsUrl(['page' => $page + 1])), ENT_QUOTES, 'UTF-8'); ?>">Older</a>
<?php endif; ?>
            </nav>
<?php endif; ?>

<?php else: ?>
            <h1 class="ms-admin__title">Log types</h1>
            <p class="ms-admin__lede">
                Every distinct log message (first 255 characters). Hiding a type stops
                <code>logMessage()</code> from writing it from now on; rows already written stay.
            </p>
            <div class="ms-admin__filters">
                <label>Filter <input type="search" id="typeFilter" class="ms-admin__input ms-admin__input--wide" placeholder="Part of the message"></label>
                <label>Limit <input type="number" id="adminLimit" class="ms-admin__input" value="500" min="10" max="5000"></label>
                <button type="button" class="ms-btn ms-btn--secondary" id="reloadAdmin">Reload</button>
                <span class="ms-admin__empty" id="typesStatus" role="status"></span>
            </div>

            <section class="ms-admin__section ms-admin__section--first">
                <h2 class="ms-admin__heading">Unique log entries</h2>
                <div class="ms-admin__scroll">
                    <table class="ms-admin__table" id="adminTable">
                        <thead><tr><th>Last seen</th><th>Count</th><th>Log key</th><th>Visibility</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <section class="ms-admin__section">
                <h2 class="ms-admin__heading">Hidden overrides</h2>
                <div class="ms-admin__scroll">
                    <table class="ms-admin__table" id="hiddenTable">
                        <thead><tr><th>Log key</th><th>Description</th><th>Hidden</th><th>Updated</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>
<?php endif; ?>

<script>
(function() {
    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/[&"'<>]/g, function(c) {
            return {'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'}[c];
        });
    }
    function prettyContext(ctx) {
        try { return JSON.stringify(JSON.parse(ctx), null, 4); } catch (e) { return ctx; }
    }

    // --- Logs: live tail (first page of the current filters, every 3 s) ---
    var tailBtn = document.getElementById('tailBtn');
    var form = document.getElementById('filterForm');
    if (tailBtn && form) {
        var tailing = false;
        var timer = null;
        tailBtn.addEventListener('click', function() {
            tailing = !tailing;
            tailBtn.textContent = tailing ? 'Stop live tail' : 'Start live tail';
            tailBtn.setAttribute('aria-pressed', tailing ? 'true' : 'false');
            if (tailing) { tailTick(); } else if (timer) { clearTimeout(timer); }
        });

        function tailTick() {
            if (!tailing) return;
            var params = new URLSearchParams(new FormData(form));
            params.set('format', 'json');
            params.delete('page');
            fetch(location.pathname + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(res) { return res.json(); })
                .then(function(j) {
                    if (!j || !j.rows) return;
                    var tbody = document.querySelector('#logs tbody');
                    tbody.innerHTML = '';
                    j.rows.forEach(function(r) {
                        var tr = document.createElement('tr');
                        var lvl = String(r.log_level || '');
                        tr.innerHTML = '<td>' + escapeHtml(r.created_at) + '</td>' +
                            '<td><span class="ms-admin__level ms-admin__level--' + escapeHtml(lvl.toLowerCase()) + '">' + escapeHtml(lvl) + '</span></td>' +
                            '<td class="ms-admin__wrap">' + escapeHtml(r.message) +
                            (r.context ? '<details class="ms-admin__ctx"><summary>Context</summary><pre>' + escapeHtml(prettyContext(r.context)) + '</pre></details>' : '') +
                            '</td>';
                        tbody.appendChild(tr);
                    });
                    var summary = document.getElementById('logSummary');
                    if (summary) summary.textContent = 'Live: ' + (j.total != null ? j.total : j.count) + ' matching row(s), newest ' + j.rows.length + ' shown. Updated ' + new Date().toLocaleTimeString() + '.';
                })
                .catch(function(e) { console.error('Tail error', e); })
                .then(function() { if (tailing) timer = setTimeout(tailTick, 3000); });
        }
    }

    // --- Log types ---
    var adminTable = document.getElementById('adminTable');
    if (!adminTable) return;
    var adminLimit = document.getElementById('adminLimit');
    var typeFilter = document.getElementById('typeFilter');
    var status = document.getElementById('typesStatus');
    document.getElementById('reloadAdmin').addEventListener('click', loadAdmin);
    typeFilter.addEventListener('input', applyFilter);

    function applyFilter() {
        var needle = typeFilter.value.toLowerCase();
        adminTable.querySelectorAll('tbody tr').forEach(function(tr) {
            tr.hidden = needle !== '' && (tr.getAttribute('data-key') || '').toLowerCase().indexOf(needle) === -1;
        });
    }

    function loadAdmin() {
        var limit = parseInt(adminLimit.value, 10) || 500;
        status.textContent = 'Loading…';
        Promise.all([
            fetch(location.pathname + '?action=unique_logs&limit=' + encodeURIComponent(limit), { credentials: 'same-origin' }).then(function(r) { return r.json(); }),
            fetch(location.pathname + '?action=get_hidden', { credentials: 'same-origin' }).then(function(r) { return r.json(); })
        ]).then(function(results) {
            var uniqueJson = results[0];
            var hiddenJson = results[1];
            var hiddenMap = {};
            if (hiddenJson && hiddenJson.rows) {
                hiddenJson.rows.forEach(function(h) { hiddenMap[h.log_key] = h; });
            }

            var tbody = adminTable.querySelector('tbody');
            tbody.innerHTML = '';
            (uniqueJson && uniqueJson.rows ? uniqueJson.rows : []).forEach(function(r, i) {
                var key = r.log_key;
                var h = hiddenMap[key];
                var isHidden = h ? (h.hidden == 1) : false;
                var tr = document.createElement('tr');
                tr.setAttribute('data-key', key || '');
                var radioName = 'hide_' + i;
                tr.innerHTML = '<td>' + escapeHtml(r.last_seen) + '</td>' +
                    '<td>' + escapeHtml(r.cnt) + '</td>' +
                    '<td class="ms-admin__wrap">' + escapeHtml(key) + '</td>' +
                    '<td>' +
                    '<label><input type="radio" name="' + radioName + '" value="0"' + (!isHidden ? ' checked' : '') + '> Show</label> ' +
                    '<label><input type="radio" name="' + radioName + '" value="1"' + (isHidden ? ' checked' : '') + '> Hide</label>' +
                    '</td>';
                tbody.appendChild(tr);
                tr.querySelectorAll('input[type=radio]').forEach(function(radio) {
                    radio.addEventListener('change', function(ev) {
                        setHidden(key, ev.target.value === '1' ? 1 : 0);
                    });
                });
            });
            applyFilter();

            var ht = document.querySelector('#hiddenTable tbody');
            ht.innerHTML = '';
            (hiddenJson && hiddenJson.rows ? hiddenJson.rows : []).forEach(function(h) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td class="ms-admin__wrap">' + escapeHtml(h.log_key) + '</td>' +
                    '<td>' + escapeHtml(h.description || '') + '</td>' +
                    '<td>' + (h.hidden == 1 ? 'Yes' : 'No') + '</td>' +
                    '<td>' + escapeHtml(h.updated_at) + '</td>';
                ht.appendChild(tr);
            });
            status.textContent = '';
        }).catch(function(e) {
            console.error('Admin load error', e);
            status.textContent = 'Could not load the log types.';
        });
    }

    function setHidden(key, hidden) {
        var fd = new URLSearchParams();
        fd.append('action', 'set_hidden');
        fd.append('log_key', key);
        fd.append('hidden', hidden ? '1' : '0');
        status.textContent = 'Saving…';
        fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(j) {
                status.textContent = j && j.success ? 'Saved.' : 'Could not save.';
                setTimeout(loadAdmin, 300);
            })
            .catch(function(e) { console.error('setHidden error', e); status.textContent = 'Could not save.'; });
    }

    loadAdmin();
})();
</script>
<?php require __DIR__ . '/partials/admin_foot.php'; ?>
