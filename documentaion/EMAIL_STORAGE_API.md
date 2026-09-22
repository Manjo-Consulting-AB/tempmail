# Email Storage API

**Status:** contract definition plus the service that implements it, verified against the code on
2026-09-22.
**Scope:** the two types the Email Storage service exchanges with its callers (the normalized
incoming email it accepts and the result it returns, §3–§6) and the service itself (`EmailStorage`,
§7).
**Epic:** #169 (email persistence consolidation), steps 2/10 (#190, the contract) and 3/10 (#191,
the service).

This document describes types and a service that exist and are loadable today. No ingestion path
has been changed to use them yet — #192–#195 migrate the callers — so merging the service changes
nothing at runtime. The current state of all three ingestion paths is in
`documentaion/EMAIL_STORAGE_ARCHITECTURE.md` (#189) and is not repeated here.

---

## 1. What this is for

Today each of the three ingestion paths (IMAP polling, the DirectAdmin pipe, the Python fallback)
builds its own `stored_emails` row inline, with its own parsing, its own retention lookup and its
own divergences — the architecture document inventories those differences. Epic #169 replaces that
with one persistence entrypoint.

A single entrypoint needs a single input shape and a single output shape. That is all this issue
defines: `IncomingEmail` (plus `EmailAttachment`) going in, `StorageResult` coming back. Defining
them first means the service, the adapters and the call sites can be written against a frozen
contract instead of against each other.

---

## 2. Loading the contract

There is no Composer autoloader for project code (`composer.json` has no `autoload` section; only
`vendor/` packages are autoloaded), and none is added here. The types load by explicit require, the
same way `MailParser` and the other top-level classes do:

```php
require_once __DIR__ . '/EmailStorage/IncomingEmail.php';
```

`EmailStorage/IncomingEmail.php` requires `EmailStorage/EmailAttachment.php` itself, so that one
require is enough for both. `EmailStorage/StorageResult.php` is a standalone require. Like
`MailParser.php` — and unlike the web scripts — these files carry no `defined('TEMPMAIL_APP')`
guard, because they are class libraries, not page entrypoints; each file declares
`strict_types=1`.

| Type | File |
|---|---|
| `IncomingEmail` | `EmailStorage/IncomingEmail.php` |
| `EmailAttachment` | `EmailStorage/EmailAttachment.php` |
| `StorageResult` | `EmailStorage/StorageResult.php` |

The service (#191) is loaded the same way, and it requires the three types plus its attachment
helper itself:

```php
require_once __DIR__ . '/EmailStorage/EmailStorage.php';
```

Unlike the contract types, **the two service files carry the `TEMPMAIL_APP` guard** and answer a
direct HTTP request with an empty `403`, because they are callable components rather than plain
value objects — see §7.1.

| Class | File |
|---|---|
| `EmailStorage` | `EmailStorage/EmailStorage.php` |
| `AttachmentStorage` | `EmailStorage/AttachmentStorage.php` |

---

## 3. `IncomingEmail` — normalized input

An immutable value object (`final`, `public readonly` properties, no methods) holding everything
the current paths already resolve for one message. The names mirror the `stored_emails` columns the
service will write, so the mapping stays obvious.

| Property | Type | Null? | Meaning |
|---|---|---|---|
| `toAddress` | `string` | no | Recipient as the full address (`local@domain`). Every path resolves this before it persists anything, so it is required. |
| `receivedAt` | `DateTimeImmutable` | no | When the message was received. Required: all three paths set it (IMAP internal date on A, the pipe clock on B, the `Date:` header with a `now()` fallback on C). |
| `fromAddress` | `?string` | yes | Sender. Path A writes `''` when the header is absent, path C may leave it missing; adapters normalize "absent" to `null`. |
| `subject` | `?string` | yes | Subject, already MIME-decoded by the adapter. The `'(no subject)'` fallback stays where it is today — it is a persistence decision, not part of the contract. |
| `bodyText` | `?string` | yes | Plain-text body, already sanitized by the adapter (`sanitizeSavedBody()` on A/B). `null` when the message had no text part. |
| `bodyHtml` | `?string` | yes | HTML body, same sanitizing. `null` when the message had no HTML part. |
| `tempEmailId` | `?int` | yes | `temp_emails.id`. Both the IMAP path and the Python path continue after the address lookup fails, leaving this `null` while still storing the email. |
| `proUserId` | `?int` | yes | `temp_emails.pro_user_id` when the address is Pro-owned. `null` for a temporary address or a failed lookup. |
| `expiresAt` | `?DateTimeImmutable` | yes | The expiry the adapter resolved. `null` whenever `tempEmailId` is `null`. The service re-derives the retention it writes and falls back to this value (§7.3). |
| `messageId` | `?string` | yes | The `Message-ID` header, the field an exact duplicate rule would key on. **`stored_emails` has no column for it today** (architecture doc §4), and the service does not use it (§7.4); the field exists so a later step can use it without changing this contract. |
| `attachments` | `list<EmailAttachment>` | no | Defaults to `[]`. See §4. |
| `rawMessage` | `?string` | yes | The raw RFC822 message, when the adapter has it to hand. Optional: the current persistence design needs the decoded attachment bytes, which `attachments` already carries, so nothing requires this field. It is here for a caller that would otherwise have to re-read the message. |

### Timestamps

Both timestamps are `DateTimeImmutable` rather than strings so the adapter does not have to choose a
format: `stored_emails.received_at`/`expires_at` are SQL DATETIME, and formatting them as
`'Y-m-d H:i:s'` is the service's decision, made once. This also keeps the three current
timestamp divergences (IMAP internal date vs. pipe clock vs. `Date:` header) visible at the call
site instead of hidden in three `date()` calls.

---

## 4. `EmailAttachment` — normalized attachment

One attachment, already decoded to bytes. Property names are the camelCase spelling of the keys
`MailParser::parseRawMessage()` returns, and `fromArray()` / `toArray()` convert between the two
shapes so an adapter can map 1:1 without renaming:

| Property | Type | MailParser key | Null? | Meaning |
|---|---|---|---|---|
| `filename` | `string` | `filename` | no | Falls back to `'attachment.bin'`, the same default `MailParser::saveAttachments()` applies. |
| `data` | `string` | `data` | no | The decoded binary content. |
| `mimeType` | `?string` | `mime_type` | yes | The part's content type. The `'application/octet-stream'` default lives in the persistence code, not here. |
| `contentId` | `?string` | `content_id` | yes | The `cid:` value for inline parts, brackets already trimmed by `MailParser`. |

`fromArray()` treats an empty string for `mimeType`/`contentId` as absent (`null`) and matches
MailParser's fallbacks for `filename`/`data`, so a malformed part produces the same persistence
outcome it does today. It does not otherwise validate: rejecting a malformed attachment is the
service's business.

---

## 5. `StorageResult` — service outcome

Also immutable, built through named constructors. It holds **no PDO object and no exception**: a
driver-level failure crosses this boundary as `STATUS_FAILED` plus a message, never as a live
object.

| Status constant | Value | Meaning |
|---|---|---|
| `StorageResult::STATUS_STORED` | `stored` | A new `stored_emails` row was written. |
| `StorageResult::STATUS_DUPLICATE` | `duplicate` | The message was already stored; nothing was written. |
| `StorageResult::STATUS_REJECTED` | `rejected` | Refused, do not retry — the recipient is unknown or expired. On the DirectAdmin pipe this is the permanent-bounce (`exit(1)`) case. |
| `StorageResult::STATUS_FAILED` | `failed` | Internal error; the same message may succeed on retry. |

| Property | Type | Null? | Meaning |
|---|---|---|---|
| `status` | `string` | no | One of the constants above. |
| `storedEmailId` | `?int` | yes | The new `stored_emails.id`. Set only for `STATUS_STORED`. |
| `attachmentWarnings` | `list<string>` | no | Non-fatal attachment problems, defaults to `[]`. An entry means that attachment was skipped or partially written while the email itself was stored — the shape the current paths already tolerate (architecture doc §5). |
| `message` | `?string` | yes | Diagnostic text, mainly for `rejected`/`failed`. Free text: callers branch on `status`, never on this string. |

Constructors:

```php
StorageResult::stored(int $emailId, array $attachmentWarnings = [], string $message = '')
StorageResult::duplicate(string $message = '')
StorageResult::rejected(string $message = '')
StorageResult::failed(string $message = '')
```

Plus `isStored()` and `hasAttachmentWarnings()` for the two checks every caller makes.

---

## 6. What the contract deliberately does not do

- **No SQL, no `$pdo`, no table or column names.** Nothing in the contract types opens, queries or
  knows about a database. The service owns every statement (§7).
- **No validation and no rejection rules.** The types accept what the adapters resolve. Which
  recipient is unknown or expired, what counts as a duplicate and how long a message is retained are
  all decided by the service — the types invent none of them.
- **No behaviour change.** The types are not called by any ingestion path, and neither is the
  service that consumes them (#192–#195 migrate the callers), so merging either changes nothing at
  runtime.
- **No schema change.** `messageId` has no column behind it; adding one is a later step's decision
  (§7.4).

---

## 7. The service — `EmailStorage` (#191)

One call does everything the three paths do inline today:

```php
$emailStorage = new EmailStorage($pdo, !empty($config['app']['debug_mode']));
$result = $emailStorage->store($email);            // $email is an IncomingEmail
```

`AttachmentStorage` is constructed inside `EmailStorage` (writing to `attachments/` with the
`attachments/...` relative path `file_path` already uses) and is not part of the caller-facing API.

### 7.1 What it owns, and the guard

The service owns: recipient validation, the `temp_emails` / `pro_users.address_ttl_days` ownership
and retention lookup, the opt-in duplicate rule (§7.4), the transactional `stored_emails` insert,
attachment persistence, the `emails_processed` / `attachments_processed` counters that belong to
persistence (§7.6), and the `StorageResult`. All `stored_emails` INSERT logic for this path exists
only here.

Both service files are guarded with `TEMPMAIL_APP` and answer a direct HTTP request with an empty
`403` — verified with `php -S` + `curl` — and a direct `include` without the constant defines
nothing and outputs nothing. It is an internal component: not an HTTP endpoint, no public route, no
output of any kind.

### 7.2 Options

Both are off by default, because the behaviour they change is behaviour a current path depends on.

| Option | Default | What it does |
|---|---|---|
| `detect_duplicates` | `false` | Apply the Python fallback's duplicate rule (§7.4). Off: every call writes a row, as IMAP and `parse.php` do today. |
| `reject_unknown_recipient` | `false` | Return `rejected` unless the recipient resolves to a live, unexpired `temp_emails` row — the DirectAdmin pipe's permanent-bounce behaviour. Off: an address lookup that comes up empty still stores the email with `temp_email_id` NULL, as path A and path C do today. |

```php
$result = $emailStorage->store($email, [
    EmailStorage::OPTION_DETECT_DUPLICATES => true,        // the Python bridge (#194)
    EmailStorage::OPTION_REJECT_UNKNOWN_RECIPIENT => true, // the pipe (#193)
]);
```

### 7.3 Ownership context, retention and value normalization

The service resolves ownership itself, with the same lookup every path does today
(`SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1`, the local
part lowercased): the resolved row is authoritative for `temp_email_id` / `pro_user_id`, and the
DTO's own fields are the fallback for an adapter that already looked the address up and whose row
has since been deleted. So an adapter may pass either the raw address or the ownership context it
resolved — neither loses information.

**Retention is computed here, not passed in** (this resolves open item 2 of #190): when the address
is Pro-owned, `expires_at` is `received_at` + `pro_users.address_ttl_days` (clamped to 1…18250),
exactly as all three paths compute it; otherwise it is the address' own `temp_emails.expires_at`,
and the DTO's `expiresAt` is the last fallback. A failing TTL lookup logs a `WARNING` and falls
back rather than failing the store, as today.

Two persistence-time normalizations happen here, so the value a duplicate check compares is the
value the row receives: a `null` **or empty** subject becomes `'(no subject)'` (the pipe's rule),
and a `null` sender becomes `''` (both PHP paths). Bodies are stored exactly as the adapter
sanitized them — `null` stays `NULL`; the empty-body coercion stays with the adapter.

A recipient that is not a full `local@domain` address, or whose local part is empty, longer than 64
characters or outside `sanitizeLocalPart()`'s charset, is `rejected` regardless of the options: no
lookup could find a row for it and no other path can produce one.

Nothing about the mail domain is decided here. `to_address` is stored exactly as the adapter
supplied it; building `local@$config['email']['domain']` stays at the call site, as it is today.

### 7.4 The duplicate rule (opt-in, no schema change)

`stored_emails` has **no Message-ID column** and no path reads the `Message-ID` header, so the only
duplicate rule that exists is the Python fallback's — and that is the rule implemented here,
unchanged:

```sql
SELECT COUNT(*) FROM stored_emails
WHERE from_address = ? AND to_address = ? AND subject = ?
  AND ABS(TIMESTAMPDIFF(MINUTE, received_at, ?)) < 5
```

It runs against the values this call would write (§7.3) and only when `detect_duplicates` is set. A
hit returns `duplicate` having written nothing — no row, no file, no statistics — leaving the
message for the caller to deal with (the Python path leaves it on the server). A duplicate check
that cannot be completed at all is `failed`, not `stored`: writing there would silently defeat the
opt-in, and a caller that asks for the check can retry.

IMAP and `parse.php` therefore keep calling **without** it and keep storing everything they see, as
today (architecture doc §4). The Python bridge (#194) is the caller that passes it.

**`messageId` remains unused**: an exact, Message-ID-based rule would need a new `stored_emails`
column and an approved schema change, which is a separate decision (architecture doc §7 step 3) —
not something the storage consolidation can slip in.

### 7.5 The failure state machine

Decided to preserve today's behaviour: both intake paths keep the email when attachments fail
(logged as `WARNING`), and making storage all-or-nothing would drop legitimate mail and make
`parse.php` bounce. So the granularity is: the email is atomic, each attachment is atomic, and a
failed attachment never takes the email with it.

| Step | Scope | On failure |
|---|---|---|
| `stored_emails` INSERT | one transaction, opened and committed by the service | rolled back — nothing is written, no attachment is attempted, the result is `failed` with no `storedEmailId`, and no statistic moves |
| each attachment (`attachments/` file + `email_attachments` row) | its own transaction, or a `SAVEPOINT` when the caller already holds one open | that attachment's row is rolled back and its file unlinked, so nothing of it remains; the entry is added to `attachmentWarnings` and the result stays `stored` |
| `email_stats` counters | outside the transaction, after the commit | logged; the result is unchanged |
| webhooks | **not here at all** | callers dispatch after `store()` returns — which is what keeps every webhook and network call out of the storage transaction |

If the caller already has a transaction open, the service does not open a second one (that would
throw) and does not roll the caller's back from inside: the email insert joins the caller's
transaction, and each attachment takes a savepoint inside it. A caller that wraps `store()` in its
own transaction therefore still gets per-attachment rollback, but the email row's fate is its own.

Attachment persistence itself — file naming, the `attachments/...` relative path, the
`content_id` column self-heal, the explicit binds — is reused from `MailParser::saveAttachments()`
unchanged in a narrow helper (`AttachmentStorage`), because that method takes the whole array at
once and cannot report which element failed, so its DB-failure path leaves the file it just wrote
behind on disk. `MailParser::saveAttachments()` is untouched and still serves the paths that have
not migrated yet; #192–#195 retire the overlap.

### 7.6 Statistics

`emails_processed` +1 per stored email, `attachments_processed` + `n` per stored attachments, both
best-effort through the existing global `updateStat()` (a failure is logged and never changes the
result). Nothing is counted for `duplicate`, `rejected` or `failed`. `emails_total` is **not**
written here: it counts messages *examined*, matched or not, which is intake and stays with the IMAP
path.

One deliberate divergence from path A: `ImapProcessor::saveEmail()` increments `emails_processed`
even when its insert fails, while the service counts only rows it actually wrote. That counter
should describe stored mail; the old behaviour is a bug, not something to preserve.

### 7.7 What the service does not do

- No webhook, Pushover, HTTP or other network call of any kind (no `curl`, no queue write). The
  webhook payload and `dispatchWebhooks()` stay with the caller, after `store()`.
- No MIME parsing, no body sanitizing, no address-set/IMAP work, no mailbox housekeeping:
  intake stays intake (architecture doc §6).
- No DirectAdmin forwarder changes, no cleanup, no schema creation of its own (the only DDL it can
  issue is the pre-existing `content_id` self-heal, exactly as `MailParser` does it).
- No user-facing output, no HTTP route.

---

## 8. Open items handed to later steps

1. **`rawMessage` is unused.** No caller passes it and the service ignores it; it can be removed
   from the constructor without touching anything else.
2. **The path differences #192–#195 must decide, not the service.** Which timestamp a path passes
   (IMAP internal date vs. pipe clock vs. `Date:` header), whether an adapter keeps coercing a
   message with no body at all to an empty string (the pipe does; the service stores `NULL`), and
   which options each caller passes are all call-site decisions this service deliberately does not
   make.

---

## Files inspected / written

- `MailParser.php` (`parseRawMessage()`, `saveAttachments()`, `normalizeCid()`)
- `php_imap_processor.php` (`saveEmail()`, `sanitizeSavedBody()`)
- `parse.php` (steps 4–6)
- `python_imap_fallback.py` (`email_exists_in_database()` — the duplicate rule in §7.4)
- `documentaion/EMAIL_STORAGE_ARCHITECTURE.md` (#189)
- `composer.json` (no `autoload` section)
- `EmailStorage/EmailStorage.php`, `EmailStorage/AttachmentStorage.php` (#191)
