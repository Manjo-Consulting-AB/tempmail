<?php

declare(strict_types=1);

/**
 * Address and mailbox service (#319, part of epic #318: an MCP server for
 * Pro accounts). The business logic behind create_personal, list_personal,
 * delete_personal, the signed-in branch of generate, and (for the MCP tool
 * surface) listing/reading messages and deleting a Timed address — factored
 * out of index.php so a second entry point (mcp.php) can call it without
 * duplicating ownership checks that would then drift apart.
 *
 * mailboxDeleteTimed() has no counterpart in index.php: the site offers no
 * way to delete a Timed address (it can only expire or be replaced), and the
 * MCP `delete_address` tool (#323) is its only caller.
 *
 * Every function takes PDO $pdo and int $userId explicitly. None of them
 * read $_SESSION or echo: session handling (is anyone signed in, are they
 * Pro) stays the caller's job, and every function returns an array, either
 * ['ok' => true, ...] or ['ok' => false, 'error' => '<message>', ...flags].
 * index.php's own cases wrap these calls and answer exactly the JSON they
 * answered before this file existed.
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/reserved_local_parts.php';
require_once __DIR__ . '/address_cooldown.php';
require_once __DIR__ . '/abuse_guard.php';
// mailboxListAddresses() asks feedTokenColumnsExist(); index.php happens to
// require this too, but mcp.php does not, so the dependency is declared where
// it is used.
require_once __DIR__ . '/feed_token.php';

/**
 * The rate limit on creating addresses (abuse_guard.php), moved out of
 * index.php's $creationLimited closure. $kind is 'personal' or 'generate',
 * $userId is 0 for an anonymous caller. Returns the verdict instead of
 * echoing: ['ok' => true] when the attempt may proceed, or
 * ['ok' => false, 'error' => '<message>', 'rate_limited' => true].
 *
 * Fail-open: without the abuse guard tables, or on a database error, nothing
 * is limited. The first refusal in an hour escalates (an IP is flagged, an
 * account gets a strike on the account track) exactly as before.
 */
function mailboxCreationLimited(PDO $pdo, string $kind, int $userId, string $ip): array
{
    try {
        if (!abuseGuardAvailable()) {
            return ['ok' => true];
        }
        $settings = abuseGuardSettings();
        if ($kind === 'personal') {
            $rules = [['personal_user', (string)$userId, $settings['personal_user_day'], 86400]];
        } elseif ($userId > 0) {
            $rules = [['gen_user', (string)$userId, $settings['generate_user_day'], 86400]];
        } else {
            $rules = [
                ['gen_ip', $ip, $settings['generate_ip_hour'], 3600],
                ['gen_ip', $ip, $settings['generate_ip_day'], 86400],
            ];
        }
        $now = time();
        $result = abuseRateLimit($pdo, $rules, $now);
        if (!$result['limited']) {
            return ['ok' => true];
        }
        $rule = $result['rule'][0] . '/' . $result['rule'][3];
        if ($result['first']) {
            if ($userId > 0) {
                $step = abuseAccountStrike($pdo, $userId, $rule, $now, $settings);
                logMessage('WARNING', 'Address creation rate limit reached by an account', ['user_id' => $userId, 'rule' => $rule, 'step' => $step]);
            } else {
                logMessage('WARNING', 'Address creation rate limit reached by an IP', ['ip' => $ip, 'rule' => $rule]);
                if (function_exists('flagMaliciousActivity')) {
                    flagMaliciousActivity($ip, 'Address creation rate limit exceeded (' . $rule . ')');
                }
            }
        }
        return ['ok' => false, 'error' => 'Too many new addresses in a short time. Please try again later.', 'rate_limited' => true];
    } catch (Throwable $e) {
        logMessage('ERROR', 'Address creation rate limit check failed, allowing the request', ['error' => $e->getMessage()]);
        return ['ok' => true];
    }
}

/**
 * The account's Sticky addresses (with quarantine state from the abuse
 * guard) and its cool-off list of recently released addresses, plus its one
 * Timed address if it has one. index.php's list_personal answers only the
 * `personal` and `cooldown` parts of this — the same JSON it always has —
 * `temporary` is here for a future MCP tool that needs it.
 */
function mailboxListAddresses(PDO $pdo, int $userId): array
{
    global $config;
    $domain = (string)($config['email']['domain'] ?? '');
    try {
        // feed_enabled follows feed_token_hash (#315) so this action, which
        // is deliberately not Pro-gated, never exposes the credential
        // itself - only the Pro-gated address_feed_* actions in
        // pro_profile.php return a token. hooks_paused is a preference, not
        // a credential: it says only whether an address' routing is
        // silenced, never which hooks it is linked to.
        $feedEnabledCol = feedTokenColumnsExist('temp_emails')
            ? ", (feed_token_hash IS NOT NULL) AS feed_enabled"
            : (tableHasColumn('temp_emails', 'feed_token') ? ", (feed_token IS NOT NULL) AS feed_enabled" : "");
        $hooksPausedCol = tableHasColumn('temp_emails', 'hooks_paused') ? ", (hooks_paused = 1) AS hooks_paused" : "";
        $stmt = $pdo->prepare("SELECT id, unique_address AS address, expires_at{$feedEnabledCol}{$hooksPausedCol} FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1 ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Addresses the abuse guard has paused or closed, so the owner sees
        // why mail to them is refused.
        $quarantines = [];
        try {
            if (abuseGuardAvailable()) {
                $quarantines = abuseQuarantinesForUser($pdo, $userId, time());
            }
        } catch (Exception $e) {
            logMessage('WARNING', 'Could not read address quarantines', ['error' => $e->getMessage(), 'user_id' => $userId]);
        }

        $list = [];
        foreach ($rows as $r) {
            $q = $quarantines[strtolower((string)$r['address'])] ?? null;
            $list[] = [
                'id' => $r['id'],
                'address' => $r['address'],
                'full_address' => $r['address'] . '@' . $domain,
                'expires_at' => $r['expires_at'],
                'feed_enabled' => !empty($r['feed_enabled']),
                'hooks_paused' => !empty($r['hooks_paused']),
                'paused_until' => $q !== null ? $q['until'] : null,
                'closed' => $q !== null && $q['closed'],
            ];
        }

        // Recently deleted addresses still reserved for this account.
        $cooldown = [];
        if (tableHasColumn('address_cooldowns', 'local_part')) {
            foreach (addressCooldownList($pdo, $userId) as $c) {
                $c['full_address'] = $c['address'] . '@' . $domain;
                $cooldown[] = $c;
            }
        }

        $timedStmt = $pdo->prepare("SELECT id, unique_address AS address, expires_at FROM temp_emails WHERE pro_user_id = ? AND is_personal = 0 LIMIT 1");
        $timedStmt->execute([$userId]);
        $timedRow = $timedStmt->fetch(PDO::FETCH_ASSOC);
        $timed = $timedRow ? [
            'id' => $timedRow['id'],
            'address' => $timedRow['address'],
            'full_address' => $timedRow['address'] . '@' . $domain,
            'expires_at' => $timedRow['expires_at'],
        ] : null;

        return ['ok' => true, 'personal' => $list, 'cooldown' => $cooldown, 'temporary' => $timed];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Failed to list sticky addresses'];
    }
}

/**
 * Create a Sticky address for a Pro account. Pro-gating and session
 * authentication are the caller's job (they need $_SESSION); everything
 * from here on is the same checks in the same order as before: suspicious
 * input, syntax, reserved names, taken, the cool-off holder, the cap of 10,
 * the rate limit, the insert, the fail-closed forwarder, and clearing the
 * caller's own cool-off reservation.
 */
function mailboxCreateSticky(PDO $pdo, int $userId, string $rawLocal, string $ip = ''): array
{
    global $config;

    $suspicious = function_exists('detectSuspiciousPatterns') ? detectSuspiciousPatterns($rawLocal) : [];
    if (!empty($suspicious)) {
        logMessage('WARNING', 'Suspicious create_personal input', ['patterns' => $suspicious, 'user_id' => $userId]);
        return ['ok' => false, 'error' => 'Invalid request'];
    }

    $local = function_exists('sanitizeLocalPart') ? sanitizeLocalPart($rawLocal, 3, 64) : null;
    if (!$local) {
        return ['ok' => false, 'error' => 'Invalid local part'];
    }

    // A new address must also be a valid unquoted dot-atom: no
    // leading/trailing ".", "-" or "_" and no "..". Only creation is
    // checked; existing addresses keep receiving mail.
    if (!isValidNewLocalPartSyntax($local)) {
        return ['ok' => false, 'error' => 'Invalid local part'];
    }

    // Role, system and brand names (postmaster@, admin@, noreply@ ...) may
    // not be claimed - see reserved_local_parts.php.
    if (isReservedLocalPart($local)) {
        logMessage('INFO', 'create_personal denied: reserved local part', ['user_id' => $userId, 'local' => $local]);
        return ['ok' => false, 'error' => 'This local part is not allowed'];
    }

    $stmt = $pdo->prepare("SELECT id FROM temp_emails WHERE unique_address = ? LIMIT 1");
    $stmt->execute([$local]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Address already taken'];
    }

    // A recently released personal address is reserved for its last owner
    // (address_cooldown.php). Anyone else gets the same answer as for a
    // live address, so the reservation reveals nothing.
    $hasCooldowns = tableHasColumn('address_cooldowns', 'local_part');
    if ($hasCooldowns) {
        try {
            $holder = addressCooldownHolder($pdo, $local);
        } catch (Exception $e) {
            logMessage('ERROR', 'Could not check address cool-off', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Could not create address'];
        }
        if ($holder !== null && $holder !== $userId) {
            logMessage('INFO', 'create_personal denied: address in cool-off', ['user_id' => $userId, 'local' => $local]);
            return ['ok' => false, 'error' => 'Address already taken'];
        }
    }

    // Enforce max 10 personal addresses per pro user.
    try {
        $cntStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM temp_emails WHERE pro_user_id = ? AND is_personal = 1");
        $cntStmt->execute([$userId]);
        $cntRow = $cntStmt->fetch(PDO::FETCH_ASSOC);
        $existingCount = (int)($cntRow['cnt'] ?? 0);
        if ($existingCount >= 10) {
            return ['ok' => false, 'error' => 'Maximum of 10 sticky addresses allowed'];
        }
    } catch (Exception $e) {
        logMessage('WARNING', 'Could not verify personal address count', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Could not verify address quota'];
    }

    $limited = mailboxCreationLimited($pdo, 'personal', $userId, $ip);
    if (!$limited['ok']) {
        return $limited;
    }

    // Create with 50 years TTL.
    $expiresAt = date('Y-m-d H:i:s', strtotime('+50 years'));
    try {
        $ins = $pdo->prepare("INSERT INTO temp_emails (unique_address, expires_at, pro_user_id, is_personal) VALUES (?, ?, ?, 1)");
        $ins->execute([$local, $expiresAt, $userId]);
        if (!createDirectAdminForwarder($local)) {
            $pdo->prepare("DELETE FROM temp_emails WHERE unique_address = ? AND pro_user_id = ? AND is_personal = 1")
                ->execute([$local, $userId]);
            return ['ok' => false, 'error' => 'Mail delivery could not be set up for this address, so it was not created. Please try again in a moment.'];
        }
        // The owner has taken the address back: the reservation ends.
        if ($hasCooldowns) {
            try {
                addressCooldownClear($pdo, $userId, $local);
            } catch (Exception $e) {
                // Harmless: a live temp_emails row blocks others anyway, and
                // the row expires on its own.
                logMessage('WARNING', 'Could not clear address cool-off', ['error' => $e->getMessage(), 'user_id' => $userId]);
            }
        }
        logMessage('INFO', 'Personal address created', ['user_id' => $userId, 'address' => $local]);
        return ['ok' => true, 'address' => $local, 'full_address' => $local . '@' . (string)($config['email']['domain'] ?? ''), 'expires_at' => $expiresAt];
    } catch (Exception $e) {
        logMessage('ERROR', 'Failed creating personal address', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Could not create address'];
    }
}

/**
 * Delete a Sticky address the caller owns: its mail, its attachment files
 * (unlinked only after the commit), its per-hook routing links, and a
 * cool-off reservation added in the same transaction so nobody else can
 * claim it in between.
 */
function mailboxDeleteSticky(PDO $pdo, int $userId, int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid id'];
    }
    try {
        // Ensure the address belongs to this pro user and is a personal
        // address. Another user's id answers the same "not found" as a
        // missing one, so ids reveal nothing.
        $stmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1 LIMIT 1");
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Address not found or not owned by user'];
        }
        $id = (int)$row['id'];
        $unique = $row['unique_address'];

        $pdo->beginTransaction();
        $attachmentFiles = deleteStoredEmailsForTempEmail($pdo, $id);
        // The routing links have no foreign key (#251 step 4), so they go
        // with the address; a surviving row would keep routing this
        // now-reusable id to hooks that are not its own.
        if (tableHasColumn('pro_webhook_addresses', 'webhook_id')) {
            $dl = $pdo->prepare("DELETE FROM pro_webhook_addresses WHERE temp_email_id = ?");
            $dl->execute([$id]);
        }
        $d2 = $pdo->prepare("DELETE FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 1");
        $d2->execute([$id, $userId]);
        // Reserved for this owner for the cool-off period, in the same
        // transaction: no window where someone else can claim it.
        if (tableHasColumn('address_cooldowns', 'local_part')) {
            [$cdMonths, $cdMax] = addressCooldownSettings();
            addressCooldownAdd($pdo, $userId, (string)$unique, $cdMonths, $cdMax);
        }

        $pdo->commit();
        unlinkAttachmentFiles($attachmentFiles);
        deleteDirectAdminForwarder($unique);
        logMessage('INFO', 'Personal address deleted', ['user_id' => $userId, 'address' => $unique]);
        return ['ok' => true, 'deleted_address' => $unique];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logMessage('ERROR', 'Failed deleting personal address', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Delete failed'];
    }
}

/**
 * Delete the account's Timed address (#323). The site has no action for this
 * — a Timed address normally only expires or is replaced by `generate` — so
 * this is the one deletion path that exists for the MCP `delete_address`
 * tool. It follows the order the replacement in mailboxCreateTimed() uses:
 * mail, attachment rows, the per-hook routing links and the address row go
 * in one transaction; the attachment *files* and the DirectAdmin forwarder
 * are handled after the commit, because neither has anything to roll back
 * to and the forwarder call is fail-open.
 *
 * No cool-off reservation: address_cooldowns holds released *Sticky*
 * addresses (see address_cooldown.php), and a Timed address' local part is
 * free the moment it is gone — mailboxCreateTimed() only avoids local parts
 * that are on the list, it never adds one.
 *
 * A wrong local part, another account's address and a Sticky address all
 * answer the same "Address not found", so local parts reveal nothing.
 */
function mailboxDeleteTimed(PDO $pdo, int $userId, string $local): array
{
    $local = strtolower(trim($local));
    if ($local === '' || strlen($local) > 64 || !preg_match('/^[a-z0-9._-]+$/', $local)) {
        return ['ok' => false, 'error' => 'Address not found'];
    }

    try {
        $stmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE unique_address = ? AND pro_user_id = ? AND is_personal = 0 LIMIT 1");
        $stmt->execute([$local, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Address not found'];
        }
        $id = (int)$row['id'];
        $unique = (string)$row['unique_address'];

        $pdo->beginTransaction();
        $attachmentFiles = deleteStoredEmailsForTempEmail($pdo, $id);
        // The routing links have no foreign key (#251 step 4), so they go
        // with the address.
        if (tableHasColumn('pro_webhook_addresses', 'webhook_id')) {
            $dl = $pdo->prepare("DELETE FROM pro_webhook_addresses WHERE temp_email_id = ?");
            $dl->execute([$id]);
        }
        $d2 = $pdo->prepare("DELETE FROM temp_emails WHERE id = ? AND pro_user_id = ? AND is_personal = 0");
        $d2->execute([$id, $userId]);
        $pdo->commit();

        unlinkAttachmentFiles($attachmentFiles);
        deleteDirectAdminForwarder($unique);
        logMessage('INFO', 'Timed address deleted', ['user_id' => $userId, 'address' => $unique]);
        return ['ok' => true, 'deleted_address' => $unique];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logMessage('ERROR', 'Failed deleting Timed address', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Delete failed'];
    }
}

/**
 * Create or replace the account's one Timed address (the signed-in branch
 * of index.php's `generate`): the TTL from the account's address_ttl_days
 * preference when it is Pro (clamped 1-7 days, 24 hours otherwise), inside
 * a transaction that removes any existing Timed address of the account
 * first - a pro-linked account has at most one. Files are unlinked and the
 * old forwarder removed only after the commit, so a rollback (the new
 * address' forwarder could not be created) leaves the previous address
 * intact. The rate limit is the caller's job (index.php checks it once,
 * before either the signed-in or the anonymous branch of `generate`).
 */
function mailboxCreateTimed(PDO $pdo, int $userId): array
{
    global $config;

    $address = generateUniqueString();
    // Never hand out a local part reserved in the cool-off list.
    if (tableHasColumn('address_cooldowns', 'local_part')) {
        for ($i = 0; $i < 5 && addressCooldownHolder($pdo, $address) !== null; $i++) {
            $address = generateUniqueString();
        }
    }

    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    if (proUserIsPro($userId)) {
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(address_ttl_days, 1) AS ttl_days FROM pro_users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['ttl_days'])) {
                $ttlDays = (int)$row['ttl_days'];
                if ($ttlDays < 1) {
                    $ttlDays = 1;
                }
                if ($ttlDays > 7) {
                    $ttlDays = 7;
                }
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$ttlDays days"));
            }
        } catch (Exception $e) {
            logMessage('WARNING', 'Could not fetch pro user TTL, falling back to default', ['error' => $e->getMessage()]);
        }
    }

    $replacedAddresses = [];
    $replacedAttachmentFiles = [];
    $saved = false;
    try {
        $pdo->beginTransaction();
        // Look up the address(es) about to be replaced so their DirectAdmin
        // forwarder can be removed after the transaction commits.
        $oldStmt = $pdo->prepare("SELECT id, unique_address FROM temp_emails WHERE pro_user_id = ? AND is_personal = 0");
        $oldStmt->execute([$userId]);
        $oldRows = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($oldRows as $oldRow) {
            $replacedAddresses[] = $oldRow['unique_address'];
            $replacedAttachmentFiles = array_merge(
                $replacedAttachmentFiles,
                deleteStoredEmailsForTempEmail($pdo, (int)$oldRow['id'])
            );
        }
        $del = $pdo->prepare("DELETE FROM temp_emails WHERE pro_user_id = ? AND is_personal = 0");
        $del->execute([$userId]);
        $saved = saveNewAddressWithExpiry($address, $userId, 0, $expiresAt);
        if ($saved) {
            $pdo->commit();
        } else {
            // The new address could not be set up, so the previous one is
            // kept: rolling back undoes the delete above, leaving its rows,
            // its mail and its forwarder intact (#212).
            $pdo->rollBack();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (!$saved) {
        return ['ok' => false, 'error' => 'Mail delivery could not be set up for a new address, so none was created. Please try again in a moment.'];
    }

    unlinkAttachmentFiles($replacedAttachmentFiles);
    foreach ($replacedAddresses as $replacedAddress) {
        deleteDirectAdminForwarder($replacedAddress);
    }
    logMessage('INFO', 'New temporary address generated via AJAX', ['address' => $address, 'expires_at' => $expiresAt]);
    return ['ok' => true, 'address' => $address, 'full_address' => $address . '@' . (string)($config['email']['domain'] ?? ''), 'expires_at' => $expiresAt];
}

/**
 * List messages for one address the caller owns (either kind), newest
 * first, each row carrying an `attachment_count`. For the MCP tool surface
 * — index.php's own get_emails keeps its existing, broader behaviour (all
 * of a signed-in account's addresses in one list) untouched, see the file
 * header.
 */
function mailboxListMessages(PDO $pdo, int $userId, string $local, int $limit = 50): array
{
    global $config;

    // A local part may be a hex Timed address or an arbitrary Sticky one
    // (sanitizeLocalPart()'s charset): just bound the length before the
    // lookup, which already answers "not found" for anything that does not
    // resolve to an address of this account.
    $local = strtolower(trim($local));
    if ($local === '' || strlen($local) > 64 || !preg_match('/^[a-z0-9._-]+$/', $local)) {
        return ['ok' => false, 'error' => 'Invalid address'];
    }

    $owner = $pdo->prepare("SELECT pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1");
    $owner->execute([$local]);
    $ownerRow = $owner->fetch(PDO::FETCH_ASSOC);
    if (!$ownerRow || (int)($ownerRow['pro_user_id'] ?? 0) !== $userId) {
        // Another user's address (or a missing one) answers the same
        // "not found", so ids and local parts reveal nothing.
        return ['ok' => false, 'error' => 'Address not found'];
    }

    $fullAddress = $local . '@' . (string)($config['email']['domain'] ?? '');
    $cleanupHours = (int)($config['app']['cleanup_hours'] ?? 24);
    // A PHP-computed cutoff (rather than MySQL's DATE_SUB(NOW(), ...)) runs
    // identically on MySQL and on the SQLite tests exercise this against.
    $cutoff = date('Y-m-d H:i:s', time() - $cleanupHours * 3600);
    $now = date('Y-m-d H:i:s');

    try {
        // attachment_count is a correlated subquery rather than a second,
        // IN (...)-shaped query: the SQL stays static and one round trip
        // answers the whole list.
        $stmt = $pdo->prepare(
            "SELECT se.id, se.from_address, se.subject, se.body_text, se.body_html, se.received_at, se.to_address, se.expires_at,
                    (SELECT COUNT(*) FROM email_attachments ea WHERE ea.email_id = se.id) AS attachment_count
             FROM stored_emails se
             WHERE se.to_address = ?
               AND ((se.expires_at IS NULL AND se.received_at > ?) OR (se.expires_at IS NOT NULL AND se.expires_at > ?))
             ORDER BY se.received_at DESC
             LIMIT ?"
        );
        $stmt->execute([$fullAddress, $cutoff, $now, max(1, $limit)]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logMessage('ERROR', 'mailboxListMessages failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
        return ['ok' => false, 'error' => 'Could not list messages'];
    }

    require_once __DIR__ . '/email_html_sanitizer.php';
    $emails = array_map('emailRowForList', $emails);

    return ['ok' => true, 'emails' => $emails];
}

/**
 * Fetch one message the caller owns, shaped for display (purified HTML,
 * signed attachment download URLs with their expiry and size). Ownership is
 * decided the same way as mailboxListMessages: temp_emails.pro_user_id =
 * $userId for the message's to_address, for either address kind. Unlike
 * index.php's own get_email, this does not rewrite cid: references in the
 * body - that rewrite is tightly coupled to get_email's own attachment
 * lookups and duplicating it here would not be clean, see the file header.
 */
function mailboxGetMessage(PDO $pdo, int $userId, int $emailId): array
{
    global $config;

    if ($emailId <= 0) {
        return ['ok' => false, 'error' => 'Message not found'];
    }

    $stmt = $pdo->prepare("SELECT * FROM stored_emails WHERE id = ?");
    $stmt->execute([$emailId]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$email) {
        return ['ok' => false, 'error' => 'Message not found'];
    }

    $toLocal = explode('@', (string)($email['to_address'] ?? ''))[0] ?? '';
    if ($toLocal === '') {
        return ['ok' => false, 'error' => 'Message not found'];
    }
    $owner = $pdo->prepare("SELECT pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1");
    $owner->execute([$toLocal]);
    $ownerRow = $owner->fetch(PDO::FETCH_ASSOC);
    if (!$ownerRow || (int)($ownerRow['pro_user_id'] ?? 0) !== $userId) {
        // Same "not found" whether the message belongs to someone else or
        // the id does not exist: stored_emails.id is a bare sequential
        // integer, so this must never confirm that a given id exists.
        return ['ok' => false, 'error' => 'Message not found'];
    }

    $isExpired = false;
    if (!empty($email['expires_at'])) {
        $expiresTs = strtotime((string)$email['expires_at']);
        if ($expiresTs !== false && $expiresTs <= time()) {
            $isExpired = true;
        }
    } elseif (!empty($email['received_at'])) {
        $cleanupHours = (int)($config['app']['cleanup_hours'] ?? 24);
        $cutoff = date('Y-m-d H:i:s', time() - $cleanupHours * 3600);
        if ($email['received_at'] <= $cutoff) {
            $isExpired = true;
        }
    }
    if ($isExpired) {
        return ['ok' => false, 'error' => 'Message expired'];
    }

    try {
        $hasContentId = tableHasColumn('email_attachments', 'content_id');
        if ($hasContentId) {
            $ast = $pdo->prepare("SELECT id, filename, file_path, mime_type AS content_type, file_size AS size, created_at, content_id FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
        } else {
            $ast = $pdo->prepare("SELECT id, filename, file_path, mime_type AS content_type, file_size AS size, created_at FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
        }
        $ast->execute([$emailId]);
        $attachments = $ast->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $attachments = [];
    }

    // One signature timestamp for every attachment URL of this request, and
    // the matching expiry, so the caller can tell the client when each URL
    // stops working. generateSignedAttachmentUrl() is handed the same TTL so
    // the URL and download_expires_at cannot disagree.
    $signatureTime = time();
    $ttl = (int)($config['attachments']['download_ttl'] ?? 3600);
    foreach ($attachments as &$aRow) {
        $aid = (int)($aRow['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $aRow['download_url'] = function_exists('generateSignedAttachmentUrl')
            ? generateSignedAttachmentUrl($aid, $ttl, $signatureTime)
            : (rtrim((string)($config['email']['base_url'] ?? ''), '/') ?: '') . '/files.php?id=' . $aid;
        $aRow['download_expires_at'] = $signatureTime + $ttl;
    }
    unset($aRow);

    // body_html is attacker-controlled: purify it or drop it - fail closed.
    require_once __DIR__ . '/email_html_sanitizer.php';
    $email = emailRowForDisplay($email);

    return ['ok' => true, 'email' => $email, 'attachments' => $attachments];
}
