# Email Storage API

**Status:** contract definition, verified against the code on 2026-09-22.
**Scope:** the two types the Email Storage service exchanges with its callers — the normalized
incoming email it accepts and the result it returns.
**Epic:** #169 (email persistence consolidation), step 2/10.

This document describes types that exist and are loadable today. It describes no behaviour: the
service that consumes them is #191, and no ingestion path has been changed to use them yet. The
current state of all three ingestion paths is in `documentaion/EMAIL_STORAGE_ARCHITECTURE.md` (#189)
and is not repeated here.

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
| `expiresAt` | `?DateTimeImmutable` | yes | The expiry the adapter resolved. `null` whenever `tempEmailId` is `null`. |
| `messageId` | `?string` | yes | The `Message-ID` header, the field duplicate detection will key on. **`stored_emails` has no column for it today** (architecture doc §4); the field exists so a later step can use it without changing this contract. |
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

- **No SQL, no `$pdo`, no table or column names.** Nothing in these files opens, queries or knows
  about a database. The service owns every statement.
- **No validation and no rejection rules.** The types accept what the adapters resolve. Which
  recipient is unknown or expired, what counts as a duplicate and how long a message is retained are
  all decided by the service — this issue invents none of them.
- **No behaviour change.** No ingestion path calls these types yet (#191 wires them up), so merging
  this changes nothing at runtime.
- **No schema change.** `messageId` has no column behind it; adding one is a later step's decision.

---

## 7. Open items handed to later steps

1. **The duplicate rule.** `messageId` gives duplicate detection its first exact key, but the column
   does not exist yet, and the `Message-ID` header must actually be read and passed by the adapters
   (no current path reads it). Until then the only check remains the Python path's ±5-minute
   heuristic (architecture doc §4).
2. **`expiresAt` versus a recomputed TTL.** Today every path computes `expires_at` from
   `pro_users.address_ttl_days` when the address is Pro-owned. The DTO carries the adapter's value;
   whether the service re-derives it or trusts the adapter is #191's decision.
3. **`rawMessage` is unused.** If no step needs it, it can be removed from the constructor without
   touching anything else — no caller passes it yet.

---

## Files inspected

- `MailParser.php` (`parseRawMessage()`, `saveAttachments()`, `normalizeCid()`)
- `php_imap_processor.php` (`saveEmail()`, `sanitizeSavedBody()`)
- `parse.php` (steps 4–6)
- `documentaion/EMAIL_STORAGE_ARCHITECTURE.md` (#189)
- `composer.json` (no `autoload` section)
