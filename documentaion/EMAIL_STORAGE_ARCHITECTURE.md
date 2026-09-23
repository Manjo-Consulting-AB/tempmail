# Email storage architecture

**Status:** current-state documentation, verified against the code on 2026-09-22.
**Scope:** the lifecycle of *incoming* mail from the moment it is fetched/piped in until the
`stored_emails` and `email_attachments` rows exist and downstream work (attachments, stats,
webhooks) has run.
**Epic:** #169 (email persistence consolidation), step 1/10.

This document describes what the code does today. It proposes no runtime, schema or behaviour
changes; the "Migration dependency/order" section at the end only orders the later steps of #169
by their dependencies.

Nothing here is enforced by tests — the three ingestion paths have no automated coverage, so every
statement below is derived from reading the files listed at the bottom.

---

## 1. Current ingestion paths

There are three supported ways an incoming message becomes a row in `stored_emails`. They are not
three equal peers: two of them are the *intake* paths (IMAP polling and the DirectAdmin pipe) and the
third is a degraded substitute for the first when the PHP `imap` extension is missing.

| # | Path | Entrypoint | Trigger | Parser | Attachments | Webhooks |
|---|------|-----------|---------|--------|-------------|----------|
| A | PHP IMAP polling | `cron/run_imap_once.php`, `run_imap_processor.php` → `ImapProcessor::processEmails()` | cron / manual | `ext/imap` headers + `ImapProcessor::getMessageBody()`, then `MailParser` for attachments | `MailParser`, falling back to `LegacyImapFallback` | yes — the service's post-storage hook (#196) |
| B | DirectAdmin pipe | `parse.php` (invoked by Exim with the raw message on stdin) | per-message, MTA-driven | `MailParser::parseRawMessage()` for everything | `MailParser` only | yes — the same hook (#196; ported to this path in #188) |
| C | Python IMAP fallback | `ImapProcessor::processEmailsWithCurl()` → `runPythonImapScript()` → `python_imap_fallback.py` | only when `!extension_loaded('imap')` | Python `email` module | **none** | yes since #196 — **none** before it |

Selection between A and C is a single branch at `php_imap_processor.php:37-39`:

```php
if (!extension_loaded('imap')) {
    return $this->processEmailsWithCurl();
}
```

B is independent of A/C: it is selected per-address by whether that address's DirectAdmin forwarder
points at `parse.php` (`DA_FORWARDER_ENABLED` + the forwarder destination in
`$config['directadmin']`). A and B are intended to run in parallel until #35 verifies B end-to-end;
the same message can therefore be picked up by both if a forwarder is switched over while the
catch-all inbox still receives it (see §4, duplicate handling).

### Entrypoints and access control

- `cron/run_imap_once.php` — real runs (`setDryRun(false)`), CLI or HTTP gated by `CRON_HTTP_SECRET`
  / localhost.
- `run_imap_processor.php` — a verbose debug script. It also runs for real: `setDryRun(true)` only
  suppresses `imap_delete`/`imap_expunge` (`php_imap_processor.php:129,133,140`), **not** the DB
  writes. Same access control as above.
- `parse.php` — refuses to run outside the CLI SAPI (`parse.php:88-95`), so an HTTP POST body cannot
  be injected as forged incoming mail.

---

## 2. Path detail

The per-path sections below inventory the three paths as they stood when this document was written
(#189), which is what makes the divergences between them visible. Persistence has since moved into
the shared service (#192–#195) and downstream processing onto its post-storage hook (#196), so read
the "stored_emails write", "attachment handling", "statistics" and "downstream processing"
paragraphs as the behaviour the service now reproduces once — `documentaion/EMAIL_STORAGE_API.md`
§7 is authoritative for those.

### 2.1 Path A — IMAP polling (`ImapProcessor`)

**Address set.** `ImapProcessor::getValidAddresses()` (`php_imap_processor.php:71-79`) selects
`unique_address FROM temp_emails WHERE expires_at > NOW()` and builds the full addresses itself:

```php
$full = array_map(fn($u) => $u . '@manjo.me', $unique);   // line 77
```

Note this hardcodes `@manjo.me` rather than reading `$config['email']['domain']` — unlike
`parse.php:197`, which does read the config. The `@manjo.me` literal recurs in
`fetchEmailsFromServer()`, `findRealDestinationFromHeaders()` and `python_imap_fallback.py`.

**Fetch and match.** `fetchEmailsFromServer()` (`:109-142`) runs `imap_search(..., 'ALL')` on INBOX,
lowercases each `To:` header into `local@host` form and intersects it with the address set. The first
match wins (`$matching[0]`) — a message addressed to several known addresses is filed under exactly
one of them.

**Metadata calculation.** For a match, `getMessageBody()` (`:144-197`) walks the MIME structure
recursively, decoding base64 (`encoding == 3`) and quoted-printable (`encoding == 4`), prefers the
HTML part over the plain-text part, and returns a single string. It does **not** use `MailParser` for
headers or body — only for attachments.

`saveEmail()` (`:199-317`) then builds the row:

| Column | Source |
|---|---|
| `from_address` | `$header->from[0]->mailbox . '@' . $header->from[0]->host`; `''` when absent |
| `subject` | `decodeMimeHeader($header->subject)`, or `'(no subject)'` |
| `received_at` | `date('Y-m-d H:i:s', $header->udate ?? time())` — the IMAP server's internal date, not the `Date:` header |
| body | `sanitizeSavedBody($body)`, then split: if `preg_match('/<[^>]+>/')` matches, the whole string goes to `body_html` and a `strip_tags()`/`html_entity_decode()` reduction to `body_text`; otherwise the string is `body_text` and `body_html` stays `NULL` |
| `to_address` | the matched `$matching[0]` |
| `expires_at`, `temp_email_id` | see below |

**Address lookup and metadata calculation from `temp_emails`.** Immediately before the insert
(`:231-254`) the local part of `to_address` is looked up:

```sql
SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1
```

- `temp_email_id` ← `id`
- `expires_at` ← recomputed from `pro_users.address_ttl_days` when `pro_user_id` is set
  (`SELECT COALESCE(address_ttl_days, 1) ... ; $ttl = max(1, min(365*50, $ttl))`,
  base = `received_at`), otherwise the address's own `temp_emails.expires_at` is used.
- The whole lookup is inside `try/catch` and logs `WARNING` on failure, leaving `expires_at` and
  `temp_email_id` `NULL` — the email is still saved.

**`stored_emails` write.** `INSERT INTO stored_emails (to_address, from_address, subject, body_text,
body_html, received_at, expires_at, temp_email_id) VALUES (?,?,?,?,?,?,?,?)` at `:256-258`.
`lastInsertId()` is captured immediately after the insert and before anything else (`:260-261`),
because attachment inserts would otherwise change it.

**Attachment handling.** `saveAttachmentsFromMessage()` (`:539-600`) is called only when the insert
succeeded and both `$imapConnection` and `$messageNumber` are non-null (`:288`). It re-reads the raw
message (`imap_fetchheader()` + `imap_body()`), then:

1. `MailParser::parseRawMessage()` + `MailParser::saveAttachments()` — used when the ZBateson library
   is present *and* it actually extracted attachments.
2. `LegacyImapFallback::savePartsRecursive()` — called whenever MailParser saved **0** attachments
   (`if (!$usedMailParser || $saved === 0)`, `:573`). It is part of the IMAP path, not a
   MailParser-only helper; it needs the live IMAP connection because it fetches each part with
   `imap_fetchbody()`.

The `cid:`-to-attachment-id mapping is returned and logged but deliberately not substituted into the
body (`:294-299`); inline `cid:` references are rewritten to signed URLs at display time in
`index.php`.

**Statistics.** `updateStat('emails_processed', 1)` right after the insert (`:274`); the private
`ImapProcessor::updateStat()` and the global `updateStat()` in `config.php:1540` both do
`INSERT INTO email_stats ... ON DUPLICATE KEY UPDATE stat_value = stat_value + VALUES(stat_value)`.
`updateStat('attachments_processed', $saved)` runs once per message in `saveAttachmentsFromMessage()`
(`:591-597`), guarded by `function_exists('updateStat')` and a `\Throwable` catch. `emails_total` is
incremented once per message *examined*, matched or not (`:130,134`).

**Downstream processing.** If `pro_user_id` was resolved, the Pro webhook consumer runs from the
service's post-storage hook (#196), which builds the payload from the values the service wrote and
calls `dispatchWebhooks((int)$proUserId, $payload)`. It only *queues*: the entitlement check
(`proUserIsPro()`) and the `filter_mode = 'all'` filter live inside `dispatchWebhooks()`
(`:342-398`), Pushover hooks are additionally gated per destination address by
`pushoverEnabledForAddress()` (`:424-447`, #174), and the actual HTTP delivery is done later by
`dispatchDelivery()` (`:602-664`) from `cron/process-webhook-deliveries.php`. Webhook enqueue
failures never affect the saved email.

**IMAP message deletion.** The message is deleted after `saveEmail()` returns — on success *and*
failure, and also for unmatched messages (`:129,133`), then `imap_expunge()` (`:140`). The
consequence: a message whose insert failed is still deleted from the mailbox and is lost, unless
`dryRun` is set.

### 2.2 Path B — DirectAdmin pipe (`parse.php`)

`parse.php` is a single procedural script (no class). Its numbered steps in the file are the clearest
description of the flow.

**Preconditions.** `ini_set('error_log', __DIR__ . '/debug_logs/parse_php_errors.log')` before
`config.php` is loaded (`:64`) — Exim's pipe transport with `return_output` treats any stdout/stderr
output as a permanent failure, so PHP's own error log must not reach stderr. Then `config.php`,
`vendor/autoload.php` (required, else `MailParser` silently returns null fields — see the comment at
`:69-81`) and `MailParser.php`.

**Recipient derivation** (`:141-176`), in priority order: `$argv[1]` → `LOCAL_PART` (`$_SERVER` then
`getenv()`) → `RECIPIENT` (local part before `@`). The chosen source is logged. Validation is
`sanitizeLocalPart($localPart, 1, 64)` — the broad charset from `config.php:547`, *not* the
`^[a-f0-9]{8,16}$` auto-generated-only regex that the header comment still cites; that regex was
widened in #35 so personal aliases (`tony`, `crew-1`) are accepted.

**Address lookup** (`:180-195`):

```sql
SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1
```

Missing row → `parseReject()` (`exit(1)`). `strtotime(expires_at) < time()` → `parseReject()`.
`$toAddress` is then built as `$localPart . '@' . ($config['email']['domain'] ?? 'manjo.me')`
(`:197-198`).

**Parsing.** `new MailParser($pdo, $config, ...)` + `parseRawMessage($raw)` (`:202-216`) supplies
`from`, `subject` (with the same `'(no subject)'` fallback as path A), `body_html` and `body_text`.
Both bodies are then passed through a local `$stripDataUris` closure (`:218-228`) that mirrors
`ImapProcessor::sanitizeSavedBody()`. `received_at` is `date('Y-m-d H:i:s')` — **the pipe's own clock,
not the message's `Date:` header**, unlike both other paths.

**`stored_emails` write.** `INSERT INTO stored_emails (...)` with the same eight columns as path A
(`:252-259`). `expires_at` starts from the address's own `temp_emails.expires_at` and is overridden
from `pro_users.address_ttl_days` when `pro_user_id` is set (`:236-250`) — the same calculation as
`ImapProcessor::saveEmail()`. `lastInsertId()` is read at `:265`.

**Attachment handling.** Only `MailParser::saveAttachments()` on `$parsed['attachments']`
(`:268-280`). There is **no `LegacyImapFallback` fallback** here, because that class needs a live
IMAP connection — the structurally different part of this path. `email_attachments` rows are
therefore created from the ZBateson parse only.

**Statistics.** `updateStat('emails_processed', 1)` (`:266`, the global `config.php` function), and
`updateStat('attachments_processed', $attachmentsSaved)` when attachments were saved (`:274`). There
is no `emails_total` increment on this path.

**Downstream processing.** Pro webhooks come from the service's post-storage hook (#196); the payload
is the one #188 ported to this path — same keys as path A — and it is what this path went live
without, which had silently stopped every Pro webhook (Pushover included) for switched-over
addresses. The payload is built by `EmailStorage/PostStorageWebhooks.php`, not by this script.

**Exit codes.** `parseReject()` → `exit(1)` (permanent: empty stdin, undeterminable/invalid recipient,
unknown recipient, expired recipient). `parseFail()` → `exit(2)` (internal: `temp_emails` lookup
threw, `MailParser` threw, `stored_emails` insert threw or returned false). Success → `exit(0)`.
Both helpers write the reason to **STDERR** as well as `system_logs`, which is deliberate (the MTA
needs a reason) even though the header comment warns about output on the pipe.

### 2.3 Path C — Python IMAP fallback (`python_imap_fallback.py`)

**Invocation.** `ImapProcessor::runPythonImapScript()` (`php_imap_processor.php:709-720`) writes
`json_encode($addresses)` — the `['unique' => [...], 'full' => [...]]` array from
`getValidAddresses()` — to a temp file, then `shell_exec`s
`$config['python_path'] ?? '/usr/bin/python3'` with
`$config['python_imap_script'] ?? '/usr/local/bin/python_imap_fallback.py'`, and reads
`new_emails` from the script's stdout JSON. The script's `stderr` logging is inherited, not captured.

**Own DB connection.** `connect_to_database()` (`:22-41`) opens a second connection with
`mysql.connector` straight from `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`. It inherits
the parent's environment (populated by `config.php`), so no credentials are passed on the command
line and `$pdo` is not shared. IMAP credentials come the same way from
`IMAP_SERVER`/`IMAP_USER`/`IMAP_PASSWORD`, with `parse_imap_server()` translating the PHP
`{host:993/imap/ssl}INBOX` form into host+port (`:43-50`).

**Search.** `UNSEEN`; when that returns nothing it falls back to `SINCE <two days ago>` (`:269-287`).
In the fallback branch every candidate is re-examined, which is where the duplicate check earns its
keep.

**Address lookup.** `extract_recipient_address()` (`:80-98`) searches `To` then
`Delivered-To`/`X-Original-To`/`X-Envelope-To` for `([a-f0-9]+)@manjo\.me` — a **hex-only** pattern, so
a personal alias such as `tony` is never recognised on this path even though it is a valid address
everywhere else. The regex also hardcodes the domain.

The gate that follows, `if not recipient_address or recipient_address not in addresses`
(`:307`), tests membership against the JSON **dict**: `runPythonImapScript()` passes
`{"unique": [...], "full": [...]}`, so `in` is a dict-key test and no address can ever match. As
written, the fallback skips every message as "unknown address" (`:308`) and returns `new_emails = 0`.
This is a latent defect in the current code; it is recorded here because later #169 steps will run
through this path, not to propose a fix here.

**Metadata.** `from_address` is the raw `From:` header (display name included — unlike path A, which
builds `mailbox@host`), `subject` is the raw `Subject:` header with no `(no subject)` fallback,
`received_at` comes from `parsedate_to_datetime(Date)` with `datetime.now()` as fallback, and bodies
are the first `text/plain` / `text/html` parts found by `get_email_content()` (`:100-122`) with no
`data:`-URI stripping and no HTML→text reduction. `to_address` is hardcoded
`f'{recipient_address}@manjo.me'` (`:331`), not `$config['email']['domain']`.

**`stored_emails` write.** `save_email_to_database()` (`:145-222`): duplicate check first (§4), then
the same `temp_emails` → `pro_users.address_ttl_days` `expires_at` calculation (`:159-193`), then

```sql
INSERT INTO stored_emails (from_address, to_address, subject, body_text, body_html, received_at, expires_at, temp_email_id)
```

(column order differs from the PHP paths; the placeholder order matches). `temp_email_id` is passed as
`locals().get('temp_email_id', None)`, i.e. the value only exists when the lookup found a row.
`db_conn.commit()` follows immediately (`:212`).

**Attachments: none.** The script never touches `email_attachments` and never writes to
`attachments/`. `body_html`/`body_text` still carry any inline `data:` URIs, since the stripping step
exists only in the PHP paths.

**Statistics.** `update_stat(db_conn, 'emails_processed', 1)` (`:216`), same
`ON DUPLICATE KEY UPDATE` shape against `email_stats`. No `emails_total`, no
`attachments_processed`.

**Downstream processing.** Since #196 this path gets the same Pro webhook dispatch as the other two:
the bridge that stores the message attaches the service's post-storage consumer. Before #196 it had
none — no webhook enqueue, no Pushover gating.

**Message deletion.** `imap.store(msg_id, '+FLAGS', '\\Deleted')` only after
`save_email_to_database()` returned `True` (`:339-345`), then `imap.expunge()` only when
`new_emails_count > 0` (`:352-354`). A crashed or skipped message stays on the server. (Under the
current gate defect, that means every message stays.)

**Return value.** A JSON object on stdout, consumed by `runPythonImapScript()` via
`json_decode(trim($out))`.

---

## 3. Direct write inventory

`stored_emails` — every ingestion write:

| Location | Columns written |
|---|---|
| `php_imap_processor.php:256` (`ImapProcessor::saveEmail()`) | `to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id` |
| `parse.php:253` (step 5) | `to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id` |
| `python_imap_fallback.py:197` (`save_email_to_database()`) | `from_address, to_address, subject, body_text, body_html, received_at, expires_at, temp_email_id` |

`email_attachments` — every ingestion write. All four statements live behind a
`content_id`-column probe, which is why each appears twice:

| Location | Variants |
|---|---|
| `MailParser.php:266` / `:279` (`MailParser::saveAttachments()`) | with `content_id` / without |
| `LegacyImapFallback.php:137` / `:149` (`LegacyImapFallback::savePartsRecursive()`) | with `content_id` / without |

Both classes `ALTER TABLE email_attachments ADD COLUMN content_id VARCHAR(255) NULL AFTER
mime_type` on first use when the probe says the column is missing (`MailParser.php:413`,
`LegacyImapFallback.php:333`). That is a schema mutation performed from inside the ingestion path,
guarded only by an `information_schema` `SELECT COUNT(*)` (`tableHasColumn()`); no
`IF NOT EXISTS`, deliberately, because older shared-hosting MySQL rejects the clause.

Other writers to the same tables exist but are **not** ingestion and are out of this epic's scope —
listed so the inventory is complete:

- Deletes: `config.php:1602` (expiry sweep), `cron/cleanup.php:73,131,186,399,623` (address/email
  cleanup plus attachment files), `pro_auth.php:1339,1343` (account deletion).
- Update: `cron/send-digests.php:198` (`UPDATE stored_emails SET digest_included_at = ?`).
- Schema: no `CREATE TABLE` for either table exists in the repository — the tables are created
  outside the codebase.

Reading paths that depend on these writes: `index.php` (inbox), `inbox.php`, `files.php` /
`download_attachment.php` (signed URLs over `email_attachments.file_path`), `pro_feed.php`,
`cron/send-digests.php`.

---

## 4. Duplicate handling

| Path | Check |
|---|---|
| A — IMAP | **none.** A message is saved every time it is seen. `imap_delete`/`imap_expunge` after the attempt is the only thing preventing a re-fetch. |
| B — `parse.php` | **none.** One pipe delivery = one row. If a forwarder was switched over while the catch-all still receives the same message, A and B can both save it. |
| C — Python | `email_exists_in_database()` (`:124-143`): `SELECT COUNT(*) FROM stored_emails WHERE from_address = ? AND to_address = ? AND subject = ? AND ABS(TIMESTAMPDIFF(MINUTE, received_at, %s)) < 5`. A hit makes `save_email_to_database()` return `False`, which also means the message is **not** flagged `\Deleted`. |

`stored_emails` has **no Message-ID column** (and the code never reads the `Message-ID` header), so
none of the checks can be exact. The Python check is the only one, is heuristic (`subject` equality
plus a ±5-minute window), and is defeated by path differences: `received_at` is the IMAP internal
date on A, the pipe clock on B and the `Date:` header on C, so the same message ingested by two paths
can fall outside the window.

---

## 5. Failure and rollback behaviour

**No transactions.** `beginTransaction()` is used only in `ImapProcessor::dispatchDelivery()`
(`:605,617,623,637,652`) for the webhook queue. Neither the email insert nor the attachment inserts
are wrapped in a transaction on any path, and there is no compensating delete. There is consequently
no rollback anywhere in ingestion.

| Failure | Path A | Path B (`parse.php`) | Path C (Python) |
|---|---|---|---|
| Body/header parse problem | per-message `try/catch` logs `ERROR`; message still deleted from mailbox | `parseFail()` (`exit(2)`) if `MailParser` throws | ignored per part; message skipped |
| `temp_emails` lookup | `WARNING`, `expires_at`/`temp_email_id` left `NULL`, email still saved | lookup throwing → `parseFail()` `exit(2)`; a missing/expired row → `parseReject()` `exit(1)` **before** any write | `WARNING`, `expires_at` `NULL`, email still saved |
| `stored_emails` INSERT fails | `saveEmail()` returns false → no attachments, no webhooks; `ERROR` logged; message is deleted anyway | `parseFail()` `exit(2)` — non-zero exit so the MTA can bounce | `ERROR` logged, returns `False`, message **not** deleted |
| Attachment write/insert fails | logged (`LegacyImapFallback`/`MailParser` `ERROR`), the email row is kept; already-written files are left on disk | same, `WARNING` at `parse.php:278` | n/a — no attachments |
| Stats update fails | caught, logged | caught inside `updateStat()` | caught, logged |
| Webhook enqueue fails | caught, logged; email untouched | caught, logged; email untouched | caught, logged; email untouched since #196 (n/a before it) |

The asymmetry to keep in mind: **B is the only path that can refuse a message** (MTA bounce), and
**C is the only path that can retry** (it leaves the message on the server). A loses the message on a
failed insert; C loses nothing but also makes no progress.

---

## 6. Persistence versus downstream processing

| Operation | Kind |
|---|---|
| Address-set query, IMAP fetch/search, stdin read, recipient derivation/validation | intake |
| MIME parse (path A `getMessageBody()`; B and A's attachments via `MailParser::parseRawMessage()`; C via `email.get_payload`) | intake |
| Body sanitising/HTML-vs-text split (`sanitizeSavedBody()` / `$stripDataUris`) | intake, before persistence |
| `temp_emails` (`+ pro_users.address_ttl_days`) lookup → `temp_email_id`, `expires_at` | persistence metadata |
| `INSERT INTO stored_emails` | **persistence** |
| Attachment byte write to `attachments/` + `INSERT INTO email_attachments` | **persistence** (dependent on the email id from the previous step) |
| `content_id` column self-heal `ALTER TABLE` | persistence, schema side effect |
| `INSERT INTO email_stats` (`emails_processed`, `emails_total`, `attachments_processed`) | downstream, non-blocking |
| `INSERT INTO pro_webhook_deliveries` (the post-storage consumer's `dispatchWebhooks()` call) | downstream, non-blocking, and outside the storage transaction (queues only; HTTP delivery is `dispatchDelivery()` from cron) |
| `imap_delete` / `imap_expunge` / `imap.store`+expunge | post-persistence mailbox housekeeping |
| `digest_included_at`, RSS reads, `stored_emails` expiry deletes | downstream consumers, outside this lifecycle |

---

## 7. Migration dependency/order for Epic #169

The later steps build on this map. Ordering follows the hard dependencies in the current code; each
item names what must exist before it can be done.

1. **Nothing (this document).** The map itself, plus the defect observations it records: the Python
   address gate (`python_imap_fallback.py:307`) can never pass, `parse.php` has no attachment
   fallback, and no path is transactional. Any later step that assumes these behave as intended will
   be wrong.
2. **A single shared persistence entrypoint** (one function that performs the `temp_emails`/TTL
   lookup, the `stored_emails` insert and the attachment save for all three paths). *Depends on:*
   step 1 only. *Blocks:* everything below, because every later change should land once instead of
   three times.
3. **Message-ID capture and a duplicate check in the shared entrypoint.** *Depends on:* step 2 (one
   place to check) and a schema change adding a `Message-ID` column, which can precede or accompany
   this step. *Blocks:* any path cut-over, since overlapping intake (A + B) is currently
   indistinguishable from a genuine repeat delivery.
4. **Transactional scope around `stored_emails` + `email_attachments` (or a documented decision to
   keep them independent).** *Depends on:* step 2. *Blocks:* step 6, because a cut-over should not
   multiply the current partial-failure modes.
5. **Path parity work** — give `parse.php` the `LegacyImapFallback` equivalent it lacks (or decide it
   is unnecessary given the ZBateson dependency is now required there), and give the Python path
   attachments, or decide to retire it. (Its webhooks arrived with #196, from the shared post-storage
   hook.) *Depends on:* steps 2-4.
6. **Verify the DirectAdmin cut-over end-to-end (#35) and retire or demote IMAP polling.**
   *Depends on:* steps 3-5. *Blocked by:* confirming which of the two intake paths owns a message —
   i.e. step 3.
7. **Retire the Python fallback** (or repair it) once no environment needs it. *Depends on:* step 5.

---

## Files inspected

- `php_imap_processor.php`
- `parse.php`
- `python_imap_fallback.py`
- `LegacyImapFallback.php`
- `MailParser.php`
- `config.php` (`updateStat()`, `sanitizeLocalPart()`, expiry deletes)
- `run_imap_processor.php`, `cron/run_imap_once.php` (entrypoints)
- `cron/cleanup.php`, `cron/send-digests.php`, `pro_auth.php` (non-ingestion writers)
- `AGENTS.md`, `CLAUDE.md` (context; note `AGENTS.md` is stale on `parse.php` webhooks — it still
  says they are unported, which #188 fixed)
