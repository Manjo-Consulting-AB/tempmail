<?php
/**
 * Voucher admin (voucher_service.php, epic #359 step 3/4; the schema is
 * migrate_vouchers.php, the read-only audit check_vouchers.php).
 *
 * The one place an admin creates a voucher by hand, lists the existing ones,
 * turns one on or off and reads who redeemed it. Every rule — which durations
 * and use counts are allowed, what an actor may touch, never logging a code —
 * lives in voucher_service.php; this page only collects input, calls the
 * service and renders its answer.
 *
 * Access: the account ids in ADMIN_USER_IDS, exactly like log_viewer.php.
 * State changes are same-origin POSTs followed by a redirect (post/redirect/
 * get), and the code a create produced is shown once, in the session flash,
 * and never again — it is a credential.
 *
 * Every value printed into HTML goes through htmlspecialchars() at the echo
 * site, as on the other admin pages. A helper closure would be easier to read,
 * but Semgrep cannot see through one and reports every echo of a value that
 * ever touched $_GET or $_POST as unsanitised.
 */

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/voucher_service.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// A suspended account is signed out before anything trusts the session.
if (function_exists('proSessionEndIfSuspended')) {
    proSessionEndIfSuspended();
}
$adminId = (int) ($_SESSION['pro_user_id'] ?? 0);
if (!isAdminUser($adminId)) {
    error_log(sprintf('voucher_admin.php access denied: logged_in=%s', $adminId > 0 ? 'yes' : 'no'));
    http_response_code(403);
    echo "Access denied\n";
    exit;
}

header('X-Robots-Tag: noindex, nofollow');

/**
 * Whether migrate_vouchers.php has run: both tables and every column this page
 * reads. Mirrors check_vouchers.php's checks — without them voucherList() and
 * voucherCreate() fail, which the admin would see as an empty page instead of
 * as "run the migration".
 */
function voucherAdminAvailable(PDO $pdo): bool
{
    if (!tableHasColumn('vouchers', 'id') || !tableHasColumn('redemption_log', 'id')) {
        return false;
    }
    foreach (['duration_days', 'is_active', 'expires_at', 'max_uses', 'current_uses', 'created_at', 'source', 'note'] as $column) {
        if (!tableHasColumn('vouchers', $column)) {
            return false;
        }
    }
    return tableHasColumn('redemption_log', 'redeemed_at');
}

/** The status a row is labelled with. Inactive wins over expired, as in the filter. */
function voucherAdminStatusLabel(array $voucher): string
{
    if (!$voucher['is_active']) {
        return 'Inactive';
    }
    if ($voucher['expires_at'] !== null && strtotime((string) $voucher['expires_at']) <= time()) {
        return 'Expired';
    }
    if ($voucher['max_uses'] !== null && $voucher['current_uses'] >= $voucher['max_uses']) {
        return 'Used up';
    }
    return 'Active';
}

/** The one-line summary of a voucher the flash shows next to its code. */
function voucherAdminSummary(array $voucher): string
{
    $parts = [
        $voucher['duration_days'] === null ? 'Lifetime' : (int) $voucher['duration_days'] . ' days of Pro',
    ];
    if ($voucher['max_uses'] !== null) {
        $parts[] = (int) $voucher['max_uses'] . ' account' . ((int) $voucher['max_uses'] === 1 ? '' : 's');
    }
    if ($voucher['expires_at'] !== null) {
        $parts[] = 'redeemable until ' . date('Y-m-d', strtotime((string) $voucher['expires_at']));
    }
    return implode(' · ', $parts);
}

$statusFilters = ['', 'active', 'inactive', 'expired', 'used_up'];
$available = voucherAdminAvailable($pdo);
$actor = ['type' => 'admin', 'id' => $adminId];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!requireSameOriginRequest()) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }

    $postId = (int) ($_POST['id'] ?? 0);
    $postView = (string) ($_POST['view'] ?? '');

    $flash = ['message' => 'Nothing done.', 'code' => null];
    $action = (string) ($_POST['action'] ?? '');
    if (!$available) {
        $flash['message'] = 'Vouchers are not available (run migrate_vouchers.php).';
    } else {
        try {
            if ($action === 'create') {
                // Lifetime is an explicit radio choice: duration_days => null.
                // An empty days field with the days radio selected is an
                // error, never a silent lifetime code.
                $spec = [];
                $error = null;
                $duration = (string) ($_POST['duration'] ?? '');
                $rawDays = trim((string) ($_POST['days'] ?? ''));
                if ($duration === 'lifetime') {
                    $spec['duration_days'] = null;
                } elseif ($duration === 'days') {
                    if ($rawDays === '' || !ctype_digit($rawDays)) {
                        $error = 'Enter the number of days, or choose Lifetime.';
                    } else {
                        $spec['duration_days'] = (int) $rawDays;
                    }
                } else {
                    $error = 'Choose Days of Pro or Lifetime.';
                }

                $rawMaxUses = trim((string) ($_POST['max_uses'] ?? ''));
                if ($error === null) {
                    if ($rawMaxUses === '' || !ctype_digit($rawMaxUses)) {
                        $error = 'Enter how many accounts may redeem the code.';
                    } else {
                        $spec['max_uses'] = (int) $rawMaxUses;
                    }
                }

                // A date is stored as that day 23:59:59 — the last moment the
                // code may be redeemed, not the end of the Pro time it grants.
                $rawUntil = trim((string) ($_POST['redeemable_until'] ?? ''));
                if ($error === null && $rawUntil !== '') {
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawUntil, $m) === 1
                        && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                        $expiresAt = $rawUntil . ' 23:59:59';
                        if (strtotime($expiresAt) <= time()) {
                            $error = 'Redeemable until must be a future date.';
                        } else {
                            $spec['expires_at'] = $expiresAt;
                        }
                    } else {
                        $error = 'Enter a valid date (YYYY-MM-DD) or leave it empty.';
                    }
                }

                $rawCode = trim((string) ($_POST['code'] ?? ''));
                if ($error === null && $rawCode !== '') {
                    $spec['code'] = $rawCode;
                }
                $rawNote = (string) ($_POST['note'] ?? '');
                if ($rawNote !== '') {
                    $spec['note'] = $rawNote;
                }

                if ($error !== null) {
                    $flash['message'] = $error;
                } else {
                    $result = voucherCreate($pdo, $spec, $actor);
                    if ($result['ok'] ?? false) {
                        $voucher = $result['voucher'];
                        $flash['message'] = voucherAdminSummary($voucher);
                        $flash['code'] = (string) $voucher['code'];
                    } else {
                        $flash['message'] = (string) ($result['error'] ?? 'Could not create voucher');
                    }
                }
            } elseif ($action === 'deactivate' || $action === 'activate') {
                $result = voucherSetActive($pdo, $postId, $action === 'activate', $actor);
                $flash['message'] = ($result['ok'] ?? false)
                    ? ($action === 'activate' ? 'Code reactivated.' : 'Code deactivated.')
                    : (string) ($result['error'] ?? 'Could not update voucher');
            }
        } catch (Throwable $e) {
            // The exception class only: a PDOException can quote the code it
            // rejected, and this page must never write a code to the log.
            logMessage('ERROR', 'voucher_admin.php action failed', ['action' => $action, 'exception' => get_class($e)]);
            $flash['message'] = 'The action failed; see the log.';
        }
    }

    $_SESSION['voucher_admin_flash'] = $flash;
    // The redirect target is built here, from an id and a fixed route — never
    // from anything the request supplied.
    $back = ($postView === 'redemptions' && $postId > 0)
        ? 'voucher_admin.php?' . http_build_query(['view' => 'redemptions', 'id' => $postId])
        : 'voucher_admin.php';
    header('Location: ' . $back, true, 303);
    exit;
}

$flash = $_SESSION['voucher_admin_flash'] ?? null;
unset($_SESSION['voucher_admin_flash']);
if (!is_array($flash)) {
    $flash = null;
}

$view = (string) ($_GET['view'] ?? '');
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, $statusFilters, true)) {
    $status = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$redemptionId = (int) ($_GET['id'] ?? 0);

$perPage = 50;
$rows = [];
$hasNext = false;
$listError = null;
$voucher = null;
$redemptions = [];
if ($available && $view !== 'redemptions') {
    // One row over the page size tells us whether a Next link is needed,
    // without a second COUNT query.
    $list = voucherList($pdo, $status === '' ? [] : ['status' => $status], $perPage + 1, ($page - 1) * $perPage);
    if ($list['ok'] ?? false) {
        $rows = $list['vouchers'];
        $hasNext = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
    } else {
        $listError = (string) ($list['error'] ?? 'Could not list vouchers');
    }
}
if ($available && $view === 'redemptions') {
    $voucher = voucherFetch($pdo, $redemptionId);
    if ($voucher !== null) {
        $redemptionResult = voucherRedemptions($pdo, $redemptionId);
        $redemptions = $redemptionResult['redemptions'] ?? [];
        if (!($redemptionResult['ok'] ?? false)) {
            $listError = (string) ($redemptionResult['error'] ?? 'Could not list redemptions');
        }
    }
}

/** A list URL carrying the current filter and page, unless $params overrides them. */
$listUrl = static function (array $params) use ($status, $page): string {
    if (!array_key_exists('status', $params)) {
        $params['status'] = $status;
    }
    if (!array_key_exists('page', $params)) {
        $params['page'] = $page;
    }
    if ((int) ($params['page'] ?? 0) <= 1) {
        $params['page'] = '';
    }
    if ((string) ($params['status'] ?? '') === '') {
        $params['status'] = '';
    }
    $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '');
    return 'voucher_admin.php' . ($params === [] ? '' : '?' . http_build_query($params));
};

$msAdminTab = 'vouchers';
$msAdminTitle = 'Vouchers';
require __DIR__ . '/partials/admin_head.php';
?>
    <h1 class="ms-admin__title">Vouchers</h1>
    <p class="ms-admin__lede">
        Create a code, see how many times it has been used, turn it off, and read who redeemed it.
        Every account may redeem a code once.
    </p>
<?php if ($flash !== null): ?>
    <div class="ms-admin__notice ms-admin__flash" role="status">
        <p><?php echo htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8'); ?></p>
<?php if (($flash['code'] ?? null) !== null): ?>
        <p><code class="ms-admin__code" id="newVoucherCode"><?php echo htmlspecialchars((string) $flash['code'], ENT_QUOTES, 'UTF-8'); ?></code>
            <button type="button" class="ms-btn ms-btn--secondary" id="copyVoucherCode">Copy</button></p>
        <p class="ms-admin__hint">This page shows the code once. Copy it now.</p>
<?php endif; ?>
    </div>
<?php if (($flash['code'] ?? null) !== null): ?>
    <script>
    (function () {
        var button = document.getElementById('copyVoucherCode');
        var code = document.getElementById('newVoucherCode');
        if (!button || !code || !navigator.clipboard) return;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(code.textContent).then(function () {
                button.textContent = 'Copied';
            });
        });
    })();
    </script>
<?php endif; ?>
<?php endif; ?>
<?php if (!$available): ?>
    <p class="ms-admin__notice">Vouchers are not available (run <code>migrate_vouchers.php</code>).</p>
<?php elseif ($view === 'redemptions'): ?>

    <section class="ms-admin__section ms-admin__section--first">
        <p class="ms-admin__lede"><a href="voucher_admin.php">&larr; All codes</a></p>
        <h2 class="ms-admin__heading">Redemptions<?php echo $voucher !== null ? ' — ' . htmlspecialchars((string) $voucher['code'], ENT_QUOTES, 'UTF-8') : ''; ?></h2>
<?php if ($voucher === null): ?>
        <p class="ms-admin__notice">Voucher not found.</p>
<?php else: ?>
        <p class="ms-admin__lede">
            <?php echo htmlspecialchars(voucherAdminSummary($voucher), ENT_QUOTES, 'UTF-8'); ?> &middot; Status: <?php echo htmlspecialchars(voucherAdminStatusLabel($voucher), ENT_QUOTES, 'UTF-8'); ?>
        </p>
<?php if ($listError !== null): ?>
        <p class="ms-admin__notice"><?php echo htmlspecialchars($listError, ENT_QUOTES, 'UTF-8'); ?></p>
<?php elseif ($redemptions === []): ?>
        <p class="ms-admin__empty">Nobody has redeemed this code.</p>
<?php else: ?>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Account</th><th>Redeemed</th></tr></thead>
                <tbody>
<?php foreach ($redemptions as $redemption): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars((string) (int) $redemption['user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $redemption['redeemed_at'] === null ? '—' : htmlspecialchars((string) $redemption['redeemed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
<?php endif; ?>
    </section>

<?php else: ?>

    <section class="ms-admin__section ms-admin__section--first">
        <h2 class="ms-admin__heading">Create a code</h2>
        <form method="post" class="ms-admin__filters">
            <input type="hidden" name="action" value="create">
            <div class="ms-admin__group">
                <span>Duration</span>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration" id="durationDays" value="days" checked>
                    <label class="form-check-label" for="durationDays">Days of Pro</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration" id="durationLifetime" value="lifetime">
                    <label class="form-check-label" for="durationLifetime">Lifetime</label>
                </div>
                <input type="number" class="ms-admin__input" name="days" min="1" max="3650" value="30" aria-label="Days of Pro">
            </div>
            <label for="maxUses">Accounts
                <input type="number" class="ms-admin__input" name="max_uses" id="maxUses" min="1" max="10000" value="1" required>
                <span class="ms-admin__hint">Each account can redeem a code once.</span>
            </label>
            <label for="redeemableUntil">Redeemable until
                <input type="date" class="ms-admin__input" name="redeemable_until" id="redeemableUntil">
                <span class="ms-admin__hint">Optional. The last day it can be redeemed.</span>
            </label>
            <label for="customCode">Custom code
                <input type="text" class="ms-admin__input ms-admin__input--wide" name="code" id="customCode" maxlength="64" autocomplete="off">
                <span class="ms-admin__hint">Optional. Empty generates one.</span>
            </label>
            <label for="voucherNote">Note
                <input type="text" class="ms-admin__input ms-admin__input--wide" name="note" id="voucherNote" maxlength="255">
                <span class="ms-admin__hint">Optional, internal.</span>
            </label>
            <button type="submit" class="ms-btn ms-btn--primary">Create code</button>
        </form>
    </section>

    <section class="ms-admin__section">
        <h2 class="ms-admin__heading">Codes</h2>
        <form method="get" class="ms-admin__filters">
            <label>Status
                <select name="status" class="ms-admin__input">
<?php foreach (['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'expired' => 'Expired', 'used_up' => 'Used up'] as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $status === (string) $value ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
<?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="ms-btn ms-btn--primary">Apply</button>
        </form>
<?php if ($listError !== null): ?>
        <p class="ms-admin__notice"><?php echo htmlspecialchars($listError, ENT_QUOTES, 'UTF-8'); ?></p>
<?php elseif ($rows === []): ?>
        <p class="ms-admin__empty">No codes<?php echo $status !== '' ? ' with that status' : ''; ?>.</p>
<?php else: ?>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Code</th><th>Pro time</th><th>Uses</th><th>Redeemable until</th><th>Status</th><th>Source</th><th>Created</th><th>Note</th><th></th></tr></thead>
                <tbody>
<?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="ms-admin__mono"><?php echo htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['duration_days'] === null ? 'Lifetime' : (int) $row['duration_days'] . ' days', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) (int) $row['current_uses'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($row['max_uses'] === null ? '∞' : (string) (int) $row['max_uses'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['expires_at'] === null ? '—' : htmlspecialchars(date('Y-m-d', strtotime((string) $row['expires_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(voucherAdminStatusLabel($row), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $row['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['created_at'] === null ? '—' : htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['note'] === null ? '' : htmlspecialchars((string) $row['note'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <form method="post" class="ms-admin__inline">
                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($row['is_active'] ? 'deactivate' : 'activate', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) (int) $row['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="ms-btn ms-btn--secondary"><?php echo htmlspecialchars($row['is_active'] ? 'Deactivate' : 'Reactivate', ENT_QUOTES, 'UTF-8'); ?></button>
                            </form>
                            <a class="ms-btn ms-btn--secondary" href="<?php echo htmlspecialchars($listUrl(['view' => 'redemptions', 'id' => (int) $row['id'], 'status' => null, 'page' => null]), ENT_QUOTES, 'UTF-8'); ?>">Redemptions</a>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php if ($page > 1 || $hasNext): ?>
        <nav class="ms-admin__pager" aria-label="Voucher pages">
<?php if ($page > 1): ?>
            <a class="ms-btn ms-btn--secondary" href="<?php echo htmlspecialchars($listUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
<?php endif; ?>
            <span>Page <?php echo htmlspecialchars((string) $page, ENT_QUOTES, 'UTF-8'); ?></span>
<?php if ($hasNext): ?>
            <a class="ms-btn ms-btn--secondary" href="<?php echo htmlspecialchars($listUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
<?php endif; ?>
        </nav>
<?php endif; ?>
<?php endif; ?>
    </section>

<?php endif; ?>
<?php require __DIR__ . '/partials/admin_foot.php'; ?>
