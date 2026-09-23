<?php

declare(strict_types=1);

/**
 * CLI diagnostics: why is (or isn't) a Pro webhook queued for mail to an address?
 *
 * Walks the same gates ImapProcessor::dispatchWebhooks() applies when parse.php
 * stores a message, in the same order, and reports the first one that stops a
 * hook, plus what the delivery queue and the logs show for the account.
 * Read-only: writes nothing, sends nothing.
 *
 * Usage: php check_webhook_dispatch.php <address>   (local part or full address)
 *        php check_webhook_dispatch.php             (the most recently stored mail's address)
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script is intended to be run from the CLI.\n");
    exit(1);
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';

$blocked = false;

function report(string $status, string $label): void {
    echo "[{$status}] {$label}\n";
}

function stop(string $label): void {
    global $blocked;
    $blocked = true;
    report('STOPP', $label);
}

// ---------------------------------------------------------------------
// 1. Which address?
// ---------------------------------------------------------------------

$arg = strtolower(trim((string)($argv[1] ?? '')));
if ($arg === '') {
    $row = $pdo->query('SELECT to_address FROM stored_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        report('FEL', 'no stored mail found and no address given');
        exit(2);
    }
    $arg = strtolower((string)$row['to_address']);
    report('INFO', "no address given, using the latest stored mail's recipient: {$arg}");
}
$local = explode('@', $arg)[0];
// Two shapes are both legitimate local parts, told apart only by is_personal on
// the temp_emails row looked up below: an anonymous temp address is always
// ^[a-f0-9]{8,16}$, but a personal address can be any name sanitizeLocalPart()
// (config.php) accepts on creation — a-z, 0-9, ., -, _, 3-64 chars. Gate on
// that wider shape here so a real personal address (e.g. "duo") isn't rejected
// as invalid before the lookup even runs.
if (!preg_match('/^[a-z0-9._-]{3,64}$/', $local)) {
    report('FEL', 'not a valid address local part (temp: ^[a-f0-9]{8,16}$, personal: a-z 0-9 . _ - , 3-64 chars)');
    exit(2);
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM stored_emails WHERE to_address LIKE ?');
$stmt->execute([$local . '@%']);
report('INFO', "stored mails for {$local}: " . (int)$stmt->fetchColumn());

// ---------------------------------------------------------------------
// 2. Address row and owner (PostStorageWebhooks returns early without one)
// ---------------------------------------------------------------------

$hasPushoverColumn = tableHasColumn('temp_emails', 'pushover_enabled');
$hasHooksPausedColumn = tableHasColumn('temp_emails', 'hooks_paused');
$cols = 'id, pro_user_id, is_personal, expires_at'
    . ($hasPushoverColumn ? ', pushover_enabled' : '')
    . ($hasHooksPausedColumn ? ', hooks_paused' : '');
$stmt = $pdo->prepare("SELECT {$cols} FROM temp_emails WHERE unique_address = ? LIMIT 1");
$stmt->execute([$local]);
$address = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$address) {
    stop('the address has no temp_emails row');
    exit(1);
}
$addressLine = sprintf(
    'temp_emails id=%d pro_user_id=%s is_personal=%d pushover_enabled=%s expires_at=%s',
    (int)$address['id'],
    $address['pro_user_id'] === null ? 'NULL' : (string)$address['pro_user_id'],
    (int)$address['is_personal'],
    $hasPushoverColumn ? (string)$address['pushover_enabled'] : 'column missing',
    (string)($address['expires_at'] ?? 'NULL')
);
if ($hasHooksPausedColumn) {
    $addressLine .= sprintf(' hooks_paused=%d', (int)$address['hooks_paused']);
}
report('INFO', $addressLine);
if ($address['pro_user_id'] === null) {
    stop('the address belongs to no account, so no webhook is ever queued for it');
    exit(1);
}
$userId = (int)$address['pro_user_id'];

// ---------------------------------------------------------------------
// 3. Entitlement
// ---------------------------------------------------------------------

$typeCols = tableHasColumn('pro_users', 'account_type') ? 'account_type, pro_expires_at' : 'pro_expires_at';
$stmt = $pdo->prepare("SELECT {$typeCols} FROM pro_users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
report('INFO', sprintf(
    'pro_users id=%d account_type=%s pro_expires_at=%s',
    $userId,
    (string)($user['account_type'] ?? 'column missing'),
    (string)($user['pro_expires_at'] ?? 'NULL')
));
if (!proUserIsPro($userId)) {
    stop('proUserIsPro() is false: webhooks are only queued for Pro accounts');
}

// ---------------------------------------------------------------------
// 4. Webhooks and the per-hook gates
// ---------------------------------------------------------------------

// Which dispatch path applies, using the same three-object test as
// ImapProcessor::hookRoutingAvailable(). Without all three the routing is not
// available and the output below is the pre-routing report, unchanged.
$hasIncludeTemporaryColumn = tableHasColumn('pro_webhooks', 'include_temporary');
$hasLinkTable = tableHasColumn('pro_webhook_addresses', 'webhook_id');
$routingAvailable = $hasLinkTable && $hasIncludeTemporaryColumn && $hasHooksPausedColumn;

$hookCols = 'id, name, kind, filter_mode, config' . ($hasIncludeTemporaryColumn ? ', include_temporary' : '');
$stmt = $pdo->prepare("SELECT {$hookCols} FROM pro_webhooks WHERE user_id = ? ORDER BY id");
$stmt->execute([$userId]);
$hooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($hooks === []) {
    stop('the account has no webhooks at all');
}

$pushoverEligible = $hasPushoverColumn
    && (int)$address['is_personal'] === 1
    && (int)($address['pushover_enabled'] ?? 0) === 1;

$wouldQueue = 0;

if ($routingAvailable) {
    // Per-hook address routing (epic #251): for a personal address, the links
    // under the hook decide, plus the address' own pause; for a temporary
    // address, the hook's include_temporary switch does.
    $linkedHookIds = [];
    if ((int)$address['is_personal'] === 1) {
        $stmt = $pdo->prepare('SELECT webhook_id FROM pro_webhook_addresses WHERE temp_email_id = ?');
        $stmt->execute([(int)$address['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $linkedId) {
            $linkedHookIds[(int)$linkedId] = true;
        }
    }

    foreach ($hooks as $h) {
        $kind = (string)($h['kind'] ?? 'generic');
        $label = sprintf('webhook %d "%s" (%s, filter_mode=%s)', (int)$h['id'], (string)$h['name'], $kind, (string)$h['filter_mode']);
        if ($h['filter_mode'] !== 'all') {
            report('SKIP', "{$label}: paused (only filter_mode='all' is dispatched)");
            continue;
        }
        if ((int)$address['is_personal'] === 1) {
            if ((int)$address['hooks_paused'] === 1) {
                report('SKIP', "{$label}: hooks are paused for this address (temp_emails.hooks_paused = 1)");
                continue;
            }
            if (!isset($linkedHookIds[(int)$h['id']])) {
                report('SKIP', "{$label}: not linked to this address (no pro_webhook_addresses row)");
                continue;
            }
        } elseif ((int)($h['include_temporary'] ?? 0) !== 1) {
            report('SKIP', "{$label}: include_temporary is off, so it does not fire for temporary addresses");
            continue;
        }
        if ($kind === 'pushover') {
            $cfg = json_decode((string)$h['config'], true) ?: [];
            if (empty($cfg['token']) || empty($cfg['user'])) {
                report('VARN', "{$label}: would be queued, but its token/user key is missing, so sending will fail");
            }
        }
        report('OK', "{$label}: would be queued");
        $wouldQueue++;
    }
} else {
    foreach ($hooks as $h) {
        $kind = (string)($h['kind'] ?? 'generic');
        $label = sprintf('webhook %d "%s" (%s, filter_mode=%s)', (int)$h['id'], (string)$h['name'], $kind, (string)$h['filter_mode']);
        if ($h['filter_mode'] !== 'all') {
            report('SKIP', "{$label}: paused (only filter_mode='all' is dispatched)");
            continue;
        }
        if ($kind === 'pushover') {
            if (!$hasPushoverColumn) {
                report('SKIP', "{$label}: temp_emails.pushover_enabled missing, run migrate_address_pushover_state.php");
                continue;
            }
            if ((int)$address['is_personal'] !== 1) {
                report('SKIP', "{$label}: Pushover only fires for personal addresses, this one is temporary");
                continue;
            }
            if (!$pushoverEligible) {
                report('SKIP', "{$label}: Pushover is off for this address (turn it on per address under Personal addresses)");
                continue;
            }
            $cfg = json_decode((string)$h['config'], true) ?: [];
            if (empty($cfg['token']) || empty($cfg['user'])) {
                report('VARN', "{$label}: would be queued, but its token/user key is missing, so sending will fail");
            }
        }
        report('OK', "{$label}: would be queued");
        $wouldQueue++;
    }
}
if ($hooks !== [] && $wouldQueue === 0) {
    stop('no webhook passes the gates, so nothing is queued for this address');
}

// ---------------------------------------------------------------------
// 5. What actually happened: the queue and the logs
// ---------------------------------------------------------------------

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS n, MAX(created_at) AS latest FROM pro_webhook_deliveries WHERE user_id = ? GROUP BY status');
$stmt->execute([$userId]);
$byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($byStatus === []) {
    report('INFO', 'pro_webhook_deliveries: no rows for this account, ever (or since cleanup)');
}
foreach ($byStatus as $r) {
    report('INFO', sprintf('pro_webhook_deliveries: %d %s, latest %s', (int)$r['n'], (string)$r['status'], (string)$r['latest']));
}

$stmt = $pdo->prepare('SELECT id, status, attempts, next_attempt_at, last_error FROM pro_webhook_deliveries WHERE user_id = ? ORDER BY id DESC LIMIT 5');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    report('INFO', sprintf(
        '  delivery %d: %s, attempts=%d, next=%s, error=%s',
        (int)$r['id'],
        (string)$r['status'],
        (int)$r['attempts'],
        (string)($r['next_attempt_at'] ?? 'NULL'),
        substr((string)($r['last_error'] ?? ''), 0, 200)
    ));
}

try {
    $stmt = $pdo->query("SELECT created_at, log_level, message FROM system_logs WHERE message LIKE '%ebhook%' OR message LIKE '%post-storage listener%' ORDER BY id DESC LIMIT 10");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        report('LOGG', sprintf('%s %s %s', (string)$r['created_at'], (string)$r['log_level'], substr((string)$r['message'], 0, 200)));
    }
} catch (Exception $e) {
    report('INFO', 'system_logs could not be read: ' . $e->getMessage());
}

echo $blocked ? "\nResult: the first STOPP/SKIP above is why nothing is queued.\n" : "\nResult: every gate passes; a new mail to this address should queue a delivery.\n";
exit($blocked ? 1 : 0);
