# Email Storage API

**Status:** contract definition plus the service that implements it, verified against the code on
2026-09-22.
**Scope:** the two types the Email Storage service exchanges with its callers (the normalized
incoming email it accepts and the result it returns, §3–§6) and the service itself (`EmailStorage`,
§7, plus the post-storage listeners in §7.8).
**Epic:** #169 (email persistence consolidation), steps 2/10 (#190, the contract), 3/10 (#191,
the service), 8/10 (#196, post-storage processing) and 10/10 (#199, which corrected §1 to the
post-migration tense and §7.1/§7.5 to the removed parser helper). Updated for #212, which removed
every intake path but the DirectAdmin pipe.

This document describes types and a service that exist and are loadable today. `parse.php` now goes
through the service (#192–#195 migrated the call sites, #196 moved their downstream processing onto
it, and #212 removed the other intake paths), and it is the only caller. The current state of the
intake is in `documentaion/EMAIL_STORAGE_ARCHITECTURE.md` (#189) and is not repeated here.

---

## 1. What this is for

Each of the three ingestion paths the epic started from used to build its own `stored_emails` row
inline, with its own parsing, its own retention lookup and its own divergences — the architecture
document inventories those differences. Epic #169 replaced that with one persistence entrypoint, and
`parse.php` is the only caller left: it is the one path #212 did not remove.

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
| `PostStorageWebhooks` (the one consumer, §7.8) | `EmailStorage/PostStorageWebhooks.php` |

---

## 3. `IncomingEmail` — normalized input

An immutable value object (`final`, `public readonly` properties, no methods) holding everything
the current paths already resolve for one message. The names mirror the `stored_emails` columns the
service will write, so the mapping stays obvious.

| Property | Type | Null? | Meaning |
|---|---|---|---|
| `toAddress` | `string` | no | Recipient as the full address (`local@domain`). The adapter resolves this before it persists anything, so it is required. |
| `receivedAt` | `DateTimeImmutable` | no | When the message was received. Required: the pipe sets it to its own clock. |
| `fromAddress` | `?string` | yes | Sender. The adapter normalizes "absent" to `null`. |
| `subject` | `?string` | yes | Subject, already MIME-decoded by the adapter. The `'(no subject)'` fallback stays where it is today — it is a persistence decision, not part of the contract. |
| `bodyText` | `?string` | yes | Plain-text body, already sanitized by the adapter. `null` when the message had no text part. |
| `bodyHtml` | `?string` | yes | HTML body, same sanitizing. `null` when the message had no HTML part. |
| `tempEmailId` | `?int` | yes | `temp_emails.id`. An adapter whose address lookup fails may leave this `null` while still storing the email. |
| `proUserId` | `?int` | yes | `temp_emails.pro_user_id` when the address is Pro-owned. `null` for a temporary address or a failed lookup. |
| `expiresAt` | `?DateTimeImmutable` | yes | The expiry the adapter resolved. `null` whenever `tempEmailId` is `null`. The service re-derives the retention it writes and falls back to this value (§7.3). |
| `messageId` | `?string` | yes | The `Message-ID` header, the field an exact duplicate rule would key on. Stored in `stored_emails.message_id` when that column exists (`migrate_message_id.php`, #228); a database where the migration has not run gets the eight-column INSERT and keeps working. The service normalizes it first — one pair of surrounding `<>` and the surrounding whitespace are stripped — and stores `NULL` when what remains is empty, longer than 255 bytes, or contains whitespace or control characters. The duplicate rule does not use it yet (§7.4). |
| `attachments` | `list<EmailAttachment>` | no | Defaults to `[]`. See §4. |
| `rawMessage` | `?string` | yes | The raw RFC822 message, when the adapter has it to hand. Optional: the current persistence design needs the decoded attachment bytes, which `attachments` already carries, so nothing requires this field. It is here for a caller that would otherwise have to re-read the message. |

### Timestamps

Both timestamps are `DateTimeImmutable` rather than strings so the adapter does not have to choose a
format: `stored_emails.received_at`/`expires_at` are SQL DATETIME, and formatting them as
`'Y-m-d H:i:s'` is the service's decision, made once. This also keeps the timestamp decision visible
at the call site instead of hidden in a `date()` call.

---

## 4. `EmailAttachment` — normalized attachment

One attachment, already decoded to bytes. Property names are the camelCase spelling of the keys
`MailParser::parseRawMessage()` returns, and `fromArray()` / `toArray()` convert between the two
shapes so an adapter can map 1:1 without renaming:

| Property | Type | MailParser key | Null? | Meaning |
|---|---|---|---|---|
| `filename` | `string` | `filename` | no | Falls back to `'attachment.bin'`, the default this codebase has always applied to a nameless part. |
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
- **No behaviour of its own beyond persistence.** The types carry no rules — every verdict the
  service returns is one a path already made (architecture doc §5) — and the service performs no
  webhook or network work (§7.8).
- **No schema change.** `messageId` has no column behind it; adding one is a later step's decision
  (§7.4).

---

## 7. The service — `EmailStorage` (#191)

One call does everything the intake used to do inline:

```php
$emailStorage = new EmailStorage($pdo, !empty($config['app']['debug_mode']));
$result = $emailStorage->store($email);            // $email is an IncomingEmail
```

`AttachmentStorage` is constructed inside `EmailStorage` (writing to `attachments/` with the
`attachments/...` relative path `file_path` already uses) and is not part of the caller-facing API.

`store()` is not quite the only caller-facing method: `persistAttachments(int $emailId, array
$attachments)` is public too, added by #195 for the one adapter whose attachments could not travel in
the DTO — it extracted them over a live connection, which needs the email row to exist first. That
adapter was removed in #212, so nothing outside the service calls it today. It is the same
persistence `store()` uses for the DTO's attachments, so an attachment reaches `email_attachments` by
one route whichever caller hands it over (§7.6).

### 7.1 What it owns, and the guard

The service owns: recipient validation, the `temp_emails` / `pro_users.address_ttl_days` ownership
and retention lookup, the opt-in duplicate rules (§7.4), the transactional `stored_emails` insert,
attachment persistence, the `emails_processed` / `attachments_processed` counters that belong to
persistence (§7.6), and the `StorageResult`. All `stored_emails` INSERT logic for this path exists
only here.

Both service files are guarded with `TEMPMAIL_APP` and answer a direct HTTP request with an empty
`403` — verified with `php -S` + `curl` — and a direct `include` without the constant defines
nothing and outputs nothing. It is an internal component: not an HTTP endpoint, no public route, no
output of any kind.

### 7.2 Options

All are off by default, because the behaviour they change is behaviour a current path depends on.

| Option | Default | What it does |
|---|---|---|
| `detect_duplicates` | `false` | Apply the heuristic duplicate rule the Python fallback used (§7.4). Off: every call writes a row, as `parse.php` does today. No application caller passes this option today. |
| `reject_unknown_recipient` | `false` | Return `rejected` unless the recipient resolves to a live, unexpired `temp_emails` row — the DirectAdmin pipe's permanent-bounce behaviour, and the option `parse.php` passes. Off: an address lookup that comes up empty still stores the email with `temp_email_id` NULL, as the removed intake paths did. |
| `deduplicate_message_id` | `false` | Apply the exact, Message-ID-based rule (§7.4): a `stored_emails` row already stored for the same `to_address` with the same normalized `message_id` makes the message a `duplicate`. **`parse.php` passes it**, so a message Exim redelivers after a deferred run (`exit 75`) is stored once. Inactive until `migrate_message_id.php` has run (#228) — with no `message_id` column there is nothing to compare, so the email is stored; a lookup that fails is `failed`, not `stored`. |

```php
$result = $emailStorage->store($email, [
    EmailStorage::OPTION_DETECT_DUPLICATES => true,        // no application caller today
    EmailStorage::OPTION_REJECT_UNKNOWN_RECIPIENT => true, // parse.php (#193)
    EmailStorage::OPTION_DEDUPLICATE_MESSAGE_ID => true,   // parse.php (#212, step 18)
]);
```

### 7.3 Ownership context, retention and value normalization

The service resolves ownership itself, with the same lookup the intake does today
(`SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = ? LIMIT 1`, the local
part lowercased): the resolved row is authoritative for `temp_email_id` / `pro_user_id`, and the
DTO's own fields are the fallback for an adapter that already looked the address up and whose row
has since been deleted. So an adapter may pass either the raw address or the ownership context it
resolved — neither loses information.

**Retention is computed here, not passed in** (this resolves open item 2 of #190): when the address
is Pro-owned, `expires_at` is `received_at` + `pro_users.address_ttl_days` (clamped to 1…18250),
exactly as the intake computed it; otherwise it is the address' own `temp_emails.expires_at`,
and the DTO's `expiresAt` is the last fallback. A failing TTL lookup logs a `WARNING` and falls
back rather than failing the store, as today.

Two persistence-time normalizations happen here, so the value a duplicate check compares is the
value the row receives: a `null` **or empty** subject becomes `'(no subject)'` (the pipe's rule),
and a `null` sender becomes `''`. Bodies are stored exactly as the adapter
sanitized them — `null` stays `NULL`; the empty-body coercion stays with the adapter.

A recipient that is not a full `local@domain` address, or whose local part is empty, longer than 64
characters or outside `sanitizeLocalPart()`'s charset, is `rejected` regardless of the options: no
lookup could find a row for it and nothing else can produce one.

Nothing about the mail domain is decided here. `to_address` is stored exactly as the adapter
supplied it; building `local@$config['email']['domain']` stays at the call site, as it is today.

### 7.4 The duplicate rules (both opt-in)

There are two, and they stay separate options.

**The heuristic rule** (`detect_duplicates`) is the one the Python fallback used, unchanged:

```sql
SELECT COUNT(*) FROM stored_emails
WHERE from_address = ? AND to_address = ? AND subject = ?
  AND ABS(TIMESTAMPDIFF(MINUTE, received_at, ?)) < 5
```

It runs against the values this call would write (§7.3) and only when `detect_duplicates` is set. A
hit returns `duplicate` having written nothing — no row, no file, no statistics — leaving the message
for the caller to deal with. A duplicate check that cannot be completed at all is `failed`, not
`stored`: writing there would silently defeat the opt-in, and a caller that asks for the check can
retry.

`parse.php` therefore calls **without** it and stores everything it sees, as it always has. No
application caller passes this option today.

**The exact rule** (`deduplicate_message_id`, #212 step 18) keys on the Message-ID instead: a message
is a **duplicate** when a `stored_emails` row already exists with the same `to_address` and the same
normalized `message_id`:

```sql
SELECT COUNT(*) FROM stored_emails WHERE to_address = ? AND message_id = ?
```

- It is scoped **per recipient** on purpose: one message sent to two of our addresses is stored for
  both.
- There is **no time window**. Rows disappear with their retention, and that bounds the rule.
- A message whose Message-ID is not usable — normalized to `NULL` (§3) — is never a duplicate.
- **`parse.php` turns it on.** That path exits 75 on a temporary failure and Exim redelivers the same
  message (#215); without the rule the mail would be stored twice.
- It is **inactive until `migrate_message_id.php` has run** (#228). With no `message_id` column to
  look in there is nothing to compare, so the email is stored — the same deploy-order tolerance the
  INSERT itself has. A lookup that fails is `failed`, for the reason above: the pipe then defers
  (`exit 75`) and Exim retries the delivery.

A `duplicate` from either rule behaves the same way: nothing is written, no statistic moves, and no
post-storage listener runs (§7.8) — the existing flow returns before `notifyStored()`.

### 7.5 The failure state machine

Decided to preserve the intake's behaviour: the pipe keeps the email when attachments fail
(logged as `WARNING`), and making storage all-or-nothing would drop legitimate mail and make
`parse.php` bounce. So the granularity is: the email is atomic, each attachment is atomic, and a
failed attachment never takes the email with it.

| Step | Scope | On failure |
|---|---|---|
| `stored_emails` INSERT | one transaction, opened and committed by the service | rolled back — nothing is written, no attachment is attempted, the result is `failed` with no `storedEmailId`, and no statistic moves |
| each attachment (`attachments/` file + `email_attachments` row) | its own transaction, or a `SAVEPOINT` when the caller already holds one open | that attachment's row is rolled back and its file unlinked, so nothing of it remains; the entry is added to `attachmentWarnings` and the result stays `stored` |
| `email_stats` counters | outside the transaction, after the commit | logged; the result is unchanged |
| post-storage listeners | outside the transaction, after the commit, each in its own try/catch | logged; the email stays stored and the result is unchanged (§7.8) |

If the caller already has a transaction open, the service does not open a second one (that would
throw) and does not roll the caller's back from inside: the email insert joins the caller's
transaction, and each attachment takes a savepoint inside it. A caller that wraps `store()` in its
own transaction therefore still gets per-attachment rollback, but the email row's fate is its own.

Attachment persistence itself — file naming, the `attachments/...` relative path, the
`content_id` column self-heal, the explicit binds — is reused unchanged from the parser's previous
array-in-one-call helper in a narrow class (`AttachmentStorage`), because that helper could not
report which element failed, so its DB-failure path left the file it had just written behind on
disk. That helper (`MailParser::saveAttachments()`) had no caller left once #192–#195 had migrated
the intake paths and was removed by the #199 storage-boundary audit, so nothing outside
`AttachmentStorage` writes an `email_attachments` row any more
(`documentaion/EMAIL_STORAGE_ARCHITECTURE.md` §3, §4).

### 7.6 Statistics

`emails_processed` +1 per stored email, `attachments_processed` + `n` per stored attachments, both
best-effort through the existing global `updateStat()` (a failure is logged and never changes the
result). Nothing is counted for `duplicate`, `rejected` or `failed`. `emails_total` is **not**
written here: it counted messages *examined*, matched or not, which was intake — the path that wrote
it was removed in #212.

One deliberate divergence from the intake this replaced: the old IMAP path's `saveEmail()`
incremented `emails_processed` even when its insert failed, while the service counts only rows it
actually wrote. That counter should describe stored mail; the old behaviour is a bug, not something
to preserve.

### 7.7 What the service does not do

- No webhook, Pushover, HTTP or other network call of any kind (no `curl`, no queue write). The
  webhook payload and `dispatchWebhooks()` live in a registered listener, not in the service (§7.8).
- No MIME parsing, no body sanitizing, no IMAP or mailbox housekeeping work: intake stays intake
  (architecture doc §6).
- No DirectAdmin forwarder changes, no cleanup, no schema creation of its own (the only DDL it can
  issue is the pre-existing `content_id` self-heal, exactly as `MailParser` does it).
- No user-facing output, no HTTP route.

### 7.8 Post-storage processing (#196)

Everything that *follows* from a stored email has one integration point: a list of plain callables on
the service, appended through `onStored()` and invoked once per `stored` result.

```php
$emailStorage->onStored(function (array $stored): void { /* … */ });
```

The service runs them at the end of `store()`, after the email row and each attachment are committed,
so nothing a listener does can be rolled back with the email or hold the persistence transaction
open. Each listener is called inside its own `try/catch`: a listener that throws is logged as a
`WARNING` and the result, the stored email and the listeners after it are unaffected. A caller that
holds a transaction open around `store()` has committed nothing yet, so the listeners are skipped
there and the skip is logged.

The context a listener receives is the normalized set of values the service wrote — `stored_email_id`,
`to_address`, `from_address`, `subject`, `body_text`, `body_html`, `received_at`, `expires_at`,
`temp_email_id`, `pro_user_id` — so a listener never needs the row or the schema to do its work, and
no ingestion path needs database knowledge to trigger downstream processing.

The service names no consumer. The one that exists is **`PostStorageWebhooks`**
(`EmailStorage/PostStorageWebhooks.php`), the Pro webhook consumer: `attach()` registers a listener
that builds the unchanged payload (`to`, `from`, `subject`, `body` = HTML or text, `received_at`,
`temp_email_id`) and calls the existing `ImapProcessor::dispatchWebhooks()`. Entitlement and the
Pushover per-address filtering (#174) stay inside that method, untouched. Nothing the service does
would change if that file were deleted; a caller would simply stop being notified.

`parse.php` is the path that attaches the consumer, so the pipe dispatches webhooks through it.
`emails_processed` / `attachments_processed` are **not** listeners: they are persistence counters
and stay in the service (§7.6).

---

## 8. Open items handed to later steps

1. **`rawMessage` is unused.** No caller passes it and the service ignores it; it can be removed
   from the constructor without touching anything else.
2. **The call-site differences #192–#195 must decide, not the service.** Which timestamp the adapter
   passes, whether it keeps coercing a message with no body at all to an empty string (the pipe does;
   the service stores `NULL`), and which options the caller passes are all call-site decisions this
   service deliberately does not make.

---

## Files inspected / written

- `MailParser.php` (`parseRawMessage()`; the `saveAttachments()`/`normalizeCid()` pair was removed by
  #199, see §7.5)
- `php_imap_processor.php` (`dispatchWebhooks()`, the webhook dispatch that remains)
- `parse.php` (the intake)
- `documentaion/EMAIL_STORAGE_ARCHITECTURE.md` (#189)
- `composer.json` (no `autoload` section)
- `EmailStorage/EmailStorage.php`, `EmailStorage/AttachmentStorage.php` (#191)
