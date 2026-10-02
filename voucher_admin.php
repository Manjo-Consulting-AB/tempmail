<?php
/**
 * Voucher admin (voucher_service.php, epic #359 steps 3/4 and 4/4; the schema
 * is migrate_vouchers.php, the read-only audit check_vouchers.php, the design
 * document documentaion/VOUCHERS.md).
 *
 * The one place an admin creates a voucher by hand, creates a batch of
 * single-use codes, downloads a batch as CSV, lists the existing ones, turns
 * one on or off and reads who redeemed it. Every rule — which durations and
 * use counts are allowed, what an actor may touch, never logging a code —
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

/**
 * The duration / redeemable-until / note fields both create forms share, as
 * the voucherCreate() spec they make up. Returns [$spec, $error]: $error is
 * null on success, otherwise it is the message the flash shows and $spec is
 * empty.
 *
 * Lifetime stays an explicit radio choice — an empty days field with the days
 * radio selected is an error, never a silent lifetime code — and a date is
 * stored as that day 23:59:59, the last moment the code may be redeemed, not
 * the end of the Pro time it grants.
 */
function voucherAdminCommonSpec(array $post): array
{
    $spec = [];

    $duration = (string) ($post['duration'] ?? '');
    $rawDays = trim((string) ($post['days'] ?? ''));
    if ($duration === 'lifetime') {
        $spec['duration_days'] = null;
    } elseif ($duration === 'days') {
        if ($rawDays === '' || !ctype_digit($rawDays)) {
            return [[], 'Enter the number of days, or choose Lifetime.'];
        }
        $spec['duration_days'] = (int) $rawDays;
    } else {
        return [[], 'Choose Days of Pro or Lifetime.'];
    }

    $rawUntil = trim((string) ($post['redeemable_until'] ?? ''));
    if ($rawUntil !== '') {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawUntil, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $expiresAt = $rawUntil . ' 23:59:59';
            if (strtotime($expiresAt) <= time()) {
                return [[], 'Redeemable until must be a future date.'];
            }
            $spec['expires_at'] = $expiresAt;
        } else {
            return [[], 'Enter a valid date (YYYY-MM-DD) or leave it empty.'];
        }
    }

    $rawNote = (string) ($post['note'] ?? '');
    if ($rawNote !== '') {
        $spec['note'] = $rawNote;
    }

    return [$spec, null];
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
    // Set by an action that must land on a particular view (a new batch).
    $redirect = null;
    $action = (string) ($_POST['action'] ?? '');
    if (!$available) {
        $flash['message'] = 'Vouchers are not available (run migrate_vouchers.php).';
    } else {
        try {
            if ($action === 'create') {
                [$spec, $error] = voucherAdminCommonSpec($_POST);

                $rawMaxUses = trim((string) ($_POST['max_uses'] ?? ''));
                if ($error === null) {
                    if ($rawMaxUses === '' || !ctype_digit($rawMaxUses)) {
                        $error = 'Enter how many accounts may redeem the code.';
                    } else {
                        $spec['max_uses'] = (int) $rawMaxUses;
                    }
                }

                $rawCode = trim((string) ($_POST['code'] ?? ''));
                if ($error === null && $rawCode !== '') {
                    $spec['code'] = $rawCode;
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
            } elseif ($action === 'create_batch') {
                [$spec, $error] = voucherAdminCommonSpec($_POST);
                if ($error !== null) {
                    $flash['message'] = $error;
                } else {
                    $count = (int) trim((string) ($_POST['count'] ?? ''));
                    $result = voucherCreateBatch($pdo, $spec, $count, $actor);
                    if ($result['ok'] ?? false) {
                        $flash['message'] = count($result['vouchers']) . ' single-use codes created. Download the CSV to hand them out.';
                        // The redirect target is built from the batch id the
                        // service returned, never from anything in the request.
                        $redirect = 'voucher_admin.php?' . http_build_query([
                            'view' => 'batch',
                            'id' => (string) $result['batch_id'],
                        ]);
                    } else {
                        $flash['message'] = (string) ($result['error'] ?? 'Could not create the batch');
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
    $back = $redirect ?? (($postView === 'redemptions' && $postId > 0)
        ? 'voucher_admin.php?' . http_build_query(['view' => 'redemptions', 'id' => $postId])
        : 'voucher_admin.php');
    header('Location: ' . $back, true, 303);
    exit;
}

$flash = $_SESSION['voucher_admin_flash'] ?? null;
unset($_SESSION['voucher_admin_flash']);
if (!is_array($flash)) {
    $flash = null;
}

// A batch CSV download is a GET that only reads. It sits behind the same
// admin gate as the rest of the page (above), and the batch id is validated
// against the documented pattern before any query — the service refuses a
// malformed one too, and never touches the database for it.
if ((string) ($_GET['action'] ?? '') === 'batch_csv') {
    $csvBatchId = (string) ($_GET['id'] ?? '');
    $csv = preg_match(VOUCHER_BATCH_ID_PATTERN, $csvBatchId) === 1
        ? voucherBatchCsv($pdo, $csvBatchId, $actor)
        : null;
    if ($csv === null) {
        http_response_code(404);
        echo "Batch not found\n";
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vouchers-' . $csvBatchId . '.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // The bytes are the CSV voucherBatchCsv() built from database rows, and
    // the id was matched against ^[a-f0-9]{16}$ before the call, so nothing
    // from the request reaches this echo. The response is text/csv with
    // nosniff, never HTML.
    echo $csv; // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
    exit;
}

$view = (string) ($_GET['view'] ?? '');
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, $statusFilters, true)) {
    $status = '';
}
// The list's batch filter is a batch id or nothing; anything else is ignored
// rather than passed on.
$batchFilter = (string) ($_GET['batch_id'] ?? '');
if (preg_match(VOUCHER_BATCH_ID_PATTERN, $batchFilter) !== 1) {
    $batchFilter = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$redemptionId = (int) ($_GET['id'] ?? 0);

$perPage = 50;
$rows = [];
$hasNext = false;
$listError = null;
$voucher = null;
$redemptions = [];
$batchId = '';
$batchRows = [];
if ($available && $view !== 'redemptions' && $view !== 'batch') {
    // One row over the page size tells us whether a Next link is needed,
    // without a second COUNT query.
    $listFilter = [];
    if ($status !== '') {
        $listFilter['status'] = $status;
    }
    if ($batchFilter !== '') {
        $listFilter['batch_id'] = $batchFilter;
    }
    $list = voucherList($pdo, $listFilter, $perPage + 1, ($page - 1) * $perPage);
    if ($list['ok'] ?? false) {
        $rows = $list['vouchers'];
        $hasNext = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
    } else {
        $listError = (string) ($list['error'] ?? 'Could not list vouchers');
    }
}
if ($available && $view === 'batch') {
    // A batch is at most VOUCHER_BATCH_MAX codes, which fits one page.
    $batchId = (string) ($_GET['id'] ?? '');
    if (preg_match(VOUCHER_BATCH_ID_PATTERN, $batchId) === 1) {
        $list = voucherList($pdo, ['batch_id' => $batchId], VOUCHER_LIST_MAX_LIMIT);
        if ($list['ok'] ?? false) {
            $batchRows = $list['vouchers'];
        } else {
            $listError = (string) ($list['error'] ?? 'Could not list vouchers');
        }
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
$listUrl = static function (array $params) use ($status, $page, $batchFilter): string {
    if (!array_key_exists('status', $params)) {
        $params['status'] = $status;
    }
    if (!array_key_exists('batch_id', $params)) {
        $params['batch_id'] = $batchFilter;
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
            <?php echo htmlspecialchars(voucherAdminSummary($voucher), ENT_QUOTES, 'UTF-8'); ?> &middot; Status: <?php echo htmlspecialchars(voucherStatusLabel($voucher), ENT_QUOTES, 'UTF-8'); ?>
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

<?php elseif ($view === 'batch'): ?>

    <section class="ms-admin__section ms-admin__section--first">
        <p class="ms-admin__lede"><a href="voucher_admin.php">&larr; All codes</a></p>
        <h2 class="ms-admin__heading">Batch<?php echo $batchId !== '' ? ' ' . htmlspecialchars($batchId, ENT_QUOTES, 'UTF-8') : ''; ?></h2>
<?php if ($batchRows === []): ?>
        <p class="ms-admin__notice">Batch not found.</p>
<?php else: ?>
        <p class="ms-admin__lede">
            <?php echo htmlspecialchars((string) count($batchRows), ENT_QUOTES, 'UTF-8'); ?> single-use codes
            &middot; <?php echo htmlspecialchars($batchRows[0]['duration_days'] === null ? 'Lifetime' : (int) $batchRows[0]['duration_days'] . ' days of Pro', ENT_QUOTES, 'UTF-8'); ?> each
<?php if ($batchRows[0]['expires_at'] !== null): ?>
            &middot; redeemable until <?php echo htmlspecialchars(date('Y-m-d', strtotime((string) $batchRows[0]['expires_at'])), ENT_QUOTES, 'UTF-8'); ?>
<?php endif; ?>
        </p>
        <p>
            <a class="ms-btn ms-btn--primary" href="<?php echo htmlspecialchars('voucher_admin.php?' . http_build_query(['action' => 'batch_csv', 'id' => $batchId]), ENT_QUOTES, 'UTF-8'); ?>">Download CSV</a>
        </p>
<?php if ($listError !== null): ?>
        <p class="ms-admin__notice"><?php echo htmlspecialchars($listError, ENT_QUOTES, 'UTF-8'); ?></p>
<?php else: ?>
        <div class="ms-admin__scroll">
            <table class="ms-admin__table">
                <thead><tr><th>Code</th><th>Pro time</th><th>Uses</th><th>Redeemable until</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
<?php foreach ($batchRows as $row): ?>
                    <tr>
                        <td class="ms-admin__mono"><?php echo htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['duration_days'] === null ? 'Lifetime' : (int) $row['duration_days'] . ' days', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) (int) $row['current_uses'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($row['max_uses'] === null ? '∞' : (string) (int) $row['max_uses'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['expires_at'] === null ? '—' : htmlspecialchars(date('Y-m-d', strtotime((string) $row['expires_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(voucherStatusLabel($row), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['created_at'] === null ? '—' : htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><a class="ms-btn ms-btn--secondary" href="<?php echo htmlspecialchars($listUrl(['view' => 'redemptions', 'id' => (int) $row['id'], 'status' => null, 'page' => null, 'batch_id' => null]), ENT_QUOTES, 'UTF-8'); ?>">Redemptions</a></td>
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
        <h2 class="ms-admin__heading">Create a batch of single-use codes</h2>
        <p class="ms-admin__lede">Every code in a batch works for exactly one account. Up to 500 at a time.</p>
        <form method="post" class="ms-admin__filters">
            <input type="hidden" name="action" value="create_batch">
            <div class="ms-admin__group">
                <span>Duration</span>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration" id="batchDurationDays" value="days" checked>
                    <label class="form-check-label" for="batchDurationDays">Days of Pro</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration" id="batchDurationLifetime" value="lifetime">
                    <label class="form-check-label" for="batchDurationLifetime">Lifetime</label>
                </div>
                <input type="number" class="ms-admin__input" name="days" min="1" max="3650" value="30" aria-label="Days of Pro for the batch">
            </div>
            <label for="batchCount">Codes
                <input type="number" class="ms-admin__input" name="count" id="batchCount" min="1" max="500" value="10" required>
                <span class="ms-admin__hint">1-500. Each code is single-use.</span>
            </label>
            <label for="batchRedeemableUntil">Redeemable until
                <input type="date" class="ms-admin__input" name="redeemable_until" id="batchRedeemableUntil">
                <span class="ms-admin__hint">Optional. The last day they can be redeemed.</span>
            </label>
            <label for="batchNote">Note
                <input type="text" class="ms-admin__input ms-admin__input--wide" name="note" id="batchNote" maxlength="255">
                <span class="ms-admin__hint">Optional, internal. Not exported.</span>
            </label>
            <button type="submit" class="ms-btn ms-btn--primary">Create batch</button>
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
            <label for="batchFilter">Batch
                <input type="text" class="ms-admin__input" name="batch_id" id="batchFilter" maxlength="16" value="<?php echo htmlspecialchars($batchFilter, ENT_QUOTES, 'UTF-8'); ?>">
                <span class="ms-admin__hint">Optional. A 16-character batch id.</span>
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
                <thead><tr><th>Code</th><th>Pro time</th><th>Uses</th><th>Redeemable until</th><th>Status</th><th>Source</th><th>Created</th><th>Note</th><th>Batch</th><th></th></tr></thead>
                <tbody>
<?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="ms-admin__mono"><?php echo htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['duration_days'] === null ? 'Lifetime' : (int) $row['duration_days'] . ' days', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) (int) $row['current_uses'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($row['max_uses'] === null ? '∞' : (string) (int) $row['max_uses'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['expires_at'] === null ? '—' : htmlspecialchars(date('Y-m-d', strtotime((string) $row['expires_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(voucherStatusLabel($row), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $row['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['created_at'] === null ? '—' : htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['note'] === null ? '' : htmlspecialchars((string) $row['note'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
<?php if ($row['batch_id'] !== null): ?>
                            <a href="<?php echo htmlspecialchars($listUrl(['view' => 'batch', 'id' => (string) $row['batch_id'], 'status' => null, 'page' => null, 'batch_id' => null]), ENT_QUOTES, 'UTF-8'); ?>">Batch</a>
<?php else: ?>
                            &mdash;
<?php endif; ?>
                        </td>
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
