# Email storage architecture

**Status:** current-state documentation of the *final* flow, verified against the code on 2026-09-23,
at the end of epic #169 (steps 1/10–10/10, #199 the last code step). Epic #212 has since removed the
IMAP and Python intake paths, leaving the DirectAdmin pipe as the only one (§1, §2, §9).
**Scope:** the lifecycle of *incoming* mail from the moment it is fetched/piped in until the
`stored_emails` and `email_attachments` rows exist and downstream work (attachments, stats,
webhooks) has run.
**Epic:** #169 (email persistence consolidation).

This document describes what the code does today, after the consolidation. §2 is the one
historical section — the pre-migration inventory of #189, kept because it records *why* the
service looks the way it does. Everything else describes the code as it stands.

Almost nothing here is enforced by tests. `tests/email_storage_test.php` drives the service and the
pipe adapter (with SQLite standing in for MySQL, and MailParser's attachments skipped when
Composer's packages are absent), and `tests/pushover_routing_test.php` covers the per-address
Pushover routing; the Exim pipe and a real MySQL schema are *not* covered, and every statement about
them below is derived from reading the files listed at the bottom. #198 is the human production
check.

---

## 1. Final flow

One service stores incoming mail. One adapter does the intake — reading the message, deciding which
address it belongs to, parsing MIME and sanitizing the body — and hands the service a normalized
`IncomingEmail`. The service is the only thing that writes `stored_emails`, the only thing that
writes `email_attachments` + the file in `attachments/`, and the only place downstream processing is
triggered from.

```
                       intake (adapter)                      persistence (service)        downstream
  ┌───────────────────────────────────────────────┐   ┌──────────────────────────┐   ┌──────────────────┐
  │ parse.php (Exim pipe, raw message on stdin)   │   │                          │   │ PostStorageHook  │
  │  MailParser::parseRawMessage()                │──▶│  EmailStorage::store()   │──▶│  → dispatch      │
  └───────────────────────────────────────────────┘   │   · recipient validation │   │    Webhooks()    │
                                                      │   · ownership + TTL      │   │  (Pro webhooks)  │
                                                      │   · opt-in duplicate     │   └──────────────────┘
                                                      │   · stored_emails INSERT │              │
                                                      │   · attachments          │              ▼
                                                      │   · emails_processed     │   cron/process-webhook-
                                                      └──────────────────────────┘   deliveries.php
```

| # | Path | Entrypoint | Trigger | Parser | Attachments | Webhooks | HTTP-reachable? |
|---|------|-----------|---------|--------|-------------|----------|-----------------|
| Pipe | DirectAdmin pipe | `parse.php` (Exim pipes the raw message to stdin) | per-message, MTA-driven | `MailParser::parseRawMessage()` | `MailParser` only | yes — the service post-storage listener | no — refuses non-CLI SAPI |

The pipe is selected per-address by whether that address' DirectAdmin forwarder points at `parse.php`
(`DA_FORWARDER_ENABLED` + the forwarder destination in `$config['directadmin']`). It is the only
intake path: the other paths this document used to describe were removed in #212, and §2 and §9 keep
their record as history.

### 1.1 The service boundary

`EmailStorage::store(IncomingEmail $email, array $options = [])`
(`EmailStorage/EmailStorage.php:126`) owns:

- recipient validation — the recipient must be a full `local@domain` with a local part
  `sanitizeLocalPart()` would accept, else `rejected`;
- the `temp_emails` ownership lookup (`id`, `expires_at`, `pro_user_id`) and the
  `pro_users.address_ttl_days` retention calculation, clamped to 1…18250 days;
- the opt-in duplicate rule (§5);
- the transactional `stored_emails` INSERT and the `lastInsertId()` for it;
- attachment persistence through `AttachmentStorage` — file plus row, per attachment, all-or-nothing;
- the `emails_processed` / `attachments_processed` counters;
- the `StorageResult` (`stored` / `duplicate` / `rejected` / `failed`);
- the post-storage listeners (below).

It performs no MIME parsing, no body sanitizing, no mailbox housekeeping and no network call of any
kind. Both service files are guarded with `TEMPMAIL_APP` and answer a direct HTTP request with an
empty `403`.

### 1.2 Post-storage processing

A stored email has exactly one integration point: `EmailStorage::onStored()`
(`EmailStorage/EmailStorage.php:114`) takes plain callables, and `store()` runs them once per
`stored` result — after the email row and each attachment are committed, outside every transaction
the service opened, each in its own `try/catch`, and only when the caller has no transaction open.

The one consumer is `PostStorageWebhooks::attach()` (`EmailStorage/PostStorageWebhooks.php`), the Pro
webhook consumer. It builds the payload (`to`, `from`, `subject`, `body` = HTML or text,
`received_at`, `temp_email_id`) and calls `ImapProcessor::dispatchWebhooks()`, which only *queues*;
entitlement (`proUserIsPro()`) and the Pushover per-address filter (`pushoverEnabledForAddress()`,
#174) live inside it, and delivery happens later from `cron/process-webhook-deliveries.php`.
`parse.php` attaches this consumer, so the pipe dispatches through it.

### 1.3 What the adapters keep

Intake stays with the adapter, deliberately: the stdin read, the recipient derivation and validation,
the MIME parse and the body sanitizing. `emails_total`, which counted messages *examined* rather than
stored, was written only by the removed IMAP path.

---

## 2. Historical: the pre-migration inventory (#189)

Kept because it is the record of *why* the service exists and of which divergences were retired and
which were deliberately kept. Every path used to build its own `stored_emails` row inline, with its
own parsing, retention lookup and their own divergences. The listing below is the state at #189,
before #192–#195 moved the callers.

**The paths described in this section, and their fallbacks, were removed in #212.** Everything below
is a record of the pre-#212 state, not of the code today; §1 is the current flow.

| Path | `stored_emails` write (then) | Attachment writes (then) | Downstream (then) |
|---|---|---|---|
| A | `ImapProcessor::saveEmail()`, `php_imap_processor.php:256` | `MailParser::saveAttachments()` and `LegacyImapFallback::savePartsRecursive()` | its own `dispatchWebhooks()` call |
| B | `parse.php` step 5, `:253` | `MailParser::saveAttachments()` — no legacy fallback (needs a live IMAP connection) | its own `dispatchWebhooks()` call, ported in #188 |
| C | `python_imap_fallback.py:197` (`save_email_to_database()`) | none | **none** before #196 |

Other divergences the inventory recorded, and their fate:

- `received_at`: IMAP internal date (A), the pipe's own clock (B), the `Date:` header (C). **Kept** —
  `parse.php:253-255` still uses the pipe clock; the service stores whatever `receivedAt` the
  adapter supplies.
- `to_address` domain: built from a hardcoded `@manjo.me` on A and C, from
  `$config['email']['domain']` on B. **Kept** — the service stores the address the adapter built.
- Duplicate check: only C had one. **Kept as an opt-in** (§5).
- Attachment fallback: only A had one (`LegacyImapFallback`, which needs the live connection).
  **Kept** — it now hands what it extracts to `EmailStorage::persistAttachments()`
  (`php_imap_processor.php:663-686`, #195).
- Rejection: only B could refuse a message (MTA bounce). **Kept as an opt-in**, passed by B (§6).
- Body sanitizing and the HTML-vs-text split: **kept** with the adapters.
- The `content_id` self-heal `ALTER TABLE`: was duplicated in `MailParser` and
  `LegacyImapFallback`; now only in `AttachmentStorage` (§3).

---

## 3. Direct write inventory (final)

`stored_emails` — the **only** write in the repository:

| Location | Statement | Kind |
|---|---|---|
| `EmailStorage/EmailStorage.php:69` (`INSERT_SQL`), executed by `insertEmail()` | `INSERT INTO stored_emails (to_address, from_address, subject, body_text, body_html, received_at, expires_at, temp_email_id) VALUES (?,?,?,?,?,?,?,?)` | ingestion, the service |

`email_attachments` — the **only** writes:

| Location | Variants | Kind |
|---|---|---|
| `EmailStorage/AttachmentStorage.php:118` / `:120` (`AttachmentStorage::save()`) | with `content_id` / without, behind the column probe | ingestion, the service |

Attachment *files* under `attachments/` are written in exactly one place for ingestion:
`AttachmentStorage::save()` (`file_put_contents`), which unlinks the file again if its row does not
commit.

The `content_id` self-heal (`ALTER TABLE email_attachments ADD COLUMN ...`,
`AttachmentStorage.php:190`) is likewise the only one left; the copies in `MailParser` and in the
removed IMAP fallback went with their write paths (#195, #199). It is guarded by an
`information_schema` probe, deliberately without `IF NOT EXISTS`, because older shared-hosting MySQL
rejects that clause. `index.php:732` probes the same column read-only.

### 3.1 Documented non-ingestion exceptions

These touch the same two tables without being ingestion, are out of the storage boundary on purpose,
and are listed so the inventory is complete:

| Location | Statement | Why it is not ingestion |
|---|---|---|
| `config.php:1602` | `DELETE FROM stored_emails WHERE (expires_at < NOW()) OR (...)` | expiry sweep |
| `cron/cleanup.php:73, 131, 399, 623` | `DELETE FROM stored_emails ...` | address/email cleanup |
| `cron/cleanup.php:186` | `DELETE FROM email_attachments WHERE email_id = ?` | cleanup, paired with the attachment files it unlinks |
| `pro_auth.php:1339, 1343` | `DELETE FROM email_attachments` / `DELETE FROM stored_emails` | account deletion |
| `index.php:255, 304` | deletes the `temp_emails` row a FK cascades from | address deletion; the cascade is the schema's, not a statement here |
| `cron/send-digests.php:198` | `UPDATE stored_emails SET digest_included_at = ?` | read-side bookkeeping for the digest |

Read-side consumers of these rows: `index.php` (inbox), `inbox.php`, `files.php` (signed URLs over
`email_attachments.file_path`), `pro_feed.php`, `cron/send-digests.php`.

There is no `CREATE TABLE` for either table in the repository — the tables are created outside the
codebase, so the schema is not owned here.

---

## 4. The #199 audit

Repository-wide search for `INSERT INTO stored_emails`, `INSERT INTO email_attachments` and any
equivalent helper that bypasses the service, across PHP, Python, cron/CLI scripts and compatibility
fallbacks.

**Result: one finding, fixed.** `MailParser::saveAttachments()` was the last direct writer outside
the service. It had had no caller since #192–#195 migrated the three intake paths, but it still
carried the `email_attachments` INSERT and the attachment file write, and it took a `PDO` to do it.
It was removed in #199 together with the helpers only it used (`ensureContentIdColumn()`,
`tableHasColumn()`, `normalizeCid()`), and `MailParser` no longer takes a PDO — it is a parser and
nothing else. The `content_id` self-heal it duplicated is the one `AttachmentStorage` owns (§3).

The IMAP attachment fallback this audit looked at was already extraction-only after #195 and was not
touched by #199; it was removed in #212 with the other paths.

**No public HTTP endpoint for storage exists.** The storage entrypoints are:

| Entrypoint | Guard |
|---|---|
| `EmailStorage/EmailStorage.php`, `EmailStorage/AttachmentStorage.php` | `TEMPMAIL_APP` check at the top of the file: a direct request gets an empty `403` |
| `parse.php:91-98` | `php_sapi_name() !== 'cli'` → `403 Forbidden`, `exit(1)`, before any stdin read |

`parse.php` is listed in `robots.txt` (`Disallow: /parse.php`, next to `/cron/`). `.htaccess` carries
no per-file rule for it — it blocks `.env|.log|.sql|.backup` and the Docker files only — so, exactly
like `cron/`, the protections that matter are the SAPI refusal and the `TEMPMAIL_APP` guards, with
`robots.txt` a crawler hint rather than access control. That is the posture #199 was asked to
confirm, and it is unchanged.

---

## 5. Duplicate handling

| Path | Check |
|---|---|
| The pipe — `parse.php` | **none.** One pipe delivery = one row. |

`EmailStorage::OPTION_DETECT_DUPLICATES` remains part of the service's API — same `from_address`,
`to_address` and `subject`, with `received_at` within ±5 minutes (`EmailStorage.php:436-444`) — but
nothing in the application passes it today: `parse.php` passes only
`OPTION_REJECT_UNKNOWN_RECIPIENT`. A duplicate check that *cannot be completed* is `failed`, not
`stored`, so the check cannot be silently defeated.

`stored_emails` has **no Message-ID column** and nothing reads the `Message-ID` header, so a check
cannot be exact. `IncomingEmail::$messageId` exists for a future exact rule but is unused; adding the
column is a separate, approved schema decision.

---

## 6. Failure and rollback behaviour

The service is transactional where the intake was not: the `stored_emails` INSERT is atomic (rolled
back on failure, no attachment attempted, `failed` with no id, no counter moved), and each attachment
is atomic on its own (its row rolled back and its file unlinked, reported as an entry in
`attachmentWarnings` while the result stays `stored`). A caller already holding a transaction open
gets a savepoint per attachment and owns the email row's fate. `email_stats` and the post-storage
listeners run outside the transaction, after the commit, and can fail without affecting the email.

What the pipe reports on a failure the service can produce:

| Failure | `parse.php` |
|---|---|
| unusable recipient | `rejected` → `exit(1)`, MTA bounce |
| unknown / expired recipient | `rejected` (`OPTION_REJECT_UNKNOWN_RECIPIENT`) → `exit(1)` |
| `stored_emails` INSERT fails | `failed` → `exit(75)`, nothing printed, Exim defers and retries |
| attachment fails | `attachmentWarnings`, logged `WARNING`; `exit(0)` — the email is already stored and must not bounce |
| stats update fails | caught inside `updateStat()`, logged |
| webhook enqueue fails | caught by the listener, logged; email untouched |
| duplicate | n/a — nothing in the application passes `OPTION_DETECT_DUPLICATES` (§5) |

The pipe is the only intake path, and it is the only one that can either refuse a message (MTA
bounce) or defer it (`exit(75)`, `EX_TEMPFAIL`) so Exim retries.

`parse.php`'s exit codes are the intake contract: `0` stored; `1` permanent rejection (empty stdin,
undeterminable/invalid recipient, unknown recipient, expired recipient, service `rejected`), with the
reason on **STDERR** and in `system_logs` so Exim can bounce; `75` temporary failure (`temp_emails`
lookup threw, `MailParser` threw, store threw or returned `failed`, uncaught exception), with
**nothing** printed so Exim defers and retries. `config.php` honours `TEMPMAIL_PIPE_INTAKE`, which
only `parse.php` defines, by exiting 75 silently on a DB connection failure.

---

## 7. Persistence versus downstream

| Operation | Kind |
|---|---|
| stdin read, recipient derivation/validation | intake |
| MIME parse (`MailParser::parseRawMessage()`) | intake |
| Body sanitising / HTML-vs-text split (`$stripDataUris`) | intake, before persistence |
| `temp_emails` (+ `pro_users.address_ttl_days`) lookup → `temp_email_id`, `expires_at` | persistence metadata, in the service |
| `INSERT INTO stored_emails` | **persistence**, in the service |
| Attachment byte write to `attachments/` + `INSERT INTO email_attachments` | **persistence**, in the service |
| `content_id` column self-heal `ALTER TABLE` | persistence, schema side effect, in the service |
| `INSERT INTO email_stats` (`emails_processed`, `emails_total`, `attachments_processed`) | downstream, non-blocking. `emails_processed` and `attachments_processed` are written by the service; `emails_total` is written by nothing now that the IMAP path is gone |
| `INSERT INTO pro_webhook_deliveries` (the listener's `dispatchWebhooks()` call) | downstream, non-blocking, outside the storage transaction (queues only; delivery is `cron/process-webhook-deliveries.php`) |
| `digest_included_at`, RSS reads, `stored_emails` expiry deletes | downstream consumers, outside this lifecycle |

---

## 8. Known defects not addressed by #169

Recorded so a later step is not surprised by them. None of these is a storage-boundary violation and
none was in this epic's scope. Items 1, 2 and 4 were resolved by removal in #212 — the paths they
describe no longer exist. Items 3 and 5 remain.

1. **The Python fallback's address gate could never pass** (its extraction regex was hex-only and
   hardcoded the domain, and its gate compared against a dict, so no address could match).
   **Resolved by removal in #212** — the fallback is gone and the defect is moot.
2. **Asymmetric loss on failure** — one path deleted a message from the mailbox even when the store
   failed, while another left it. **Resolved by removal in #212** — the paths it compares are gone.
3. **No `Message-ID` capture**, so duplicate detection stays heuristic (§5). *Remains.*
4. **Two paths could both store the same message** while a forwarder was switched over (§5).
   **Resolved by removal in #212** — there is one intake path.
5. `MailParser::normalizeCid()` is gone; the inbox builds its own content-id map at display time
   (`index.php:763-816`), which is where inline `cid:` resolution has always actually happened.
   *Remains.*

---

## 9. Epic #169, step by step

The intake paths this epic routed through the service — IMAP polling and the Python fallback — were
removed in #212. The table below is the record of how they were consolidated first.

| Step | Issue | State |
|---|---|---|
| 1. Architecture map (this document) | #189 | done |
| 2. The storage contract (`IncomingEmail`, `EmailAttachment`, `StorageResult`) | #190 | done — `documentaion/EMAIL_STORAGE_API.md` |
| 3. The `EmailStorage` service | #191 | done |
| 4. IMAP intake (A) routed through it | #192 | done |
| 5. DirectAdmin pipe (B) routed through it | #193 | done |
| 6. Python fallback (C) routed through it via `python_imap_bridge.php` | #194 | done |
| 7. Legacy IMAP parts fallback made extraction-only | #195 | done |
| 8. One post-storage integration point (`onStored()` + the webhook consumer) | #196 | done |
| 9. Test coverage for the service and the adapters | #197 | done — `tests/email_storage_test.php` |
| 10. Repository-wide storage boundary audit | #199 | this step |

Still open, from the epic's own dependency list:

- **Verify the DirectAdmin cut-over end-to-end (#35) and retire or demote IMAP polling.** The second
  half was settled by removal in #212, which leaves the pipe as the only intake path.
- **Retire or repair the Python fallback** (#169 step 7) — settled by removal in #212.

---

## Files inspected

- `EmailStorage/EmailStorage.php`, `AttachmentStorage.php`, `IncomingEmail.php`, `StorageResult.php`,
  `PostStorageWebhooks.php`
- `php_imap_processor.php`, `parse.php` (the intake and the webhook dispatch that remain)
- `MailParser.php`
- `config.php` (`updateStat()`, `sanitizeLocalPart()`, expiry deletes)
- `cron/cleanup.php`, `cron/send-digests.php`, `pro_auth.php` (non-ingestion writers)
- `.htaccess`, `robots.txt`, `documentaion/EMAIL_STORAGE_API.md`
- `tests/email_storage_test.php`, `tests/pushover_routing_test.php`
