# Vouchers

A voucher is a code that grants Pro time to the account that redeems it. This
document describes the data model, how codes are created today, and the shape a
future seller API is meant to take.

The code lives in:

- `voucher_service.php` — every rule and every write. The admin page and the
  future seller API both call this file rather than writing their own INSERT.
- `voucher_admin.php` — the admin page.
- `migrate_vouchers.php` — the one-time/idempotent migration that creates the
  tables and columns.
- `check_vouchers.php` — the read-only audit.
- `tests/voucher_service_test.php` and `tests/voucher_admin_test.php` — the
  regression suites.

Redemption itself is `redeemVoucherForEmail()` in `pro_auth.php`: the
registration flow, the upgrade flow on the profile page and the standalone
"redeem a code" form all end there.

## 1. The model

Two tables, deliberately without foreign keys (like the rest of the schema —
the deletion paths handle the links themselves):

### `vouchers` — one row per code

| column | meaning |
| --- | --- |
| `id` | primary key. |
| `code` | the credential. UNIQUE and compared whole, case-sensitively. A generated code is `MS-XXXX-XXXX-XXXX`, drawn from an alphabet without `0/O/1/I/L` so it cannot be mistyped into another valid code. |
| `is_active` | `1` = the code may be redeemed. Deactivating stops future redemptions; it does not take back Pro time already granted. |
| `expires_at` | the **last moment the code may be redeemed** — not the end of the Pro time it grants. `NULL` = no last day. |
| `duration_days` | how many days of Pro the code grants. **`NULL` means lifetime Pro** (a `NULL` `pro_expires_at` on the account). |
| `max_uses` | how many **distinct accounts** may redeem the code, each at most once. `NULL` is unlimited and exists only on rows that predate `migrate_vouchers.php` or were hand-inserted — `voucherCreate()` always writes a number, and a batch forces `1`. |
| `current_uses` | redemptions so far. |
| `created_at` | when the row was created. `NULL` for rows that predate the migration (no fake backfill). |
| `source` | `legacy` (a row that predates the admin page), `admin` (created on `voucher_admin.php`) or `issuer` (reserved for the future seller API). |
| `created_by_user_id` | the admin's `pro_users.id` when `source = 'admin'`, otherwise `NULL`. |
| `issuer_id` | the issuer when `source = 'issuer'`, otherwise `NULL`. |
| `external_ref` | the issuer's own identifier for the code, at most 128 printable characters. The UNIQUE key `(issuer_id, external_ref)` is what makes the seller API's retries idempotent. |
| `batch_id` | 16 lowercase hex characters grouping the codes one `voucherCreateBatch()` call created. `NULL` for a single code. |
| `note` | an internal admin note. Never exported in the CSV and never shown to the redeemer. |

### `redemption_log` — one row per redemption

`id`, `user_id`, `voucher_id` and `redeemed_at` (the database clock supplies
it on a fresh table; it is `NULL` on rows that predate the migration). The
UNIQUE key `(user_id, voucher_id)` is what enforces "each account may redeem a
given code once" — independent of `max_uses`.

### What redemption does

Inside one transaction, `redeemVoucherForEmail()` locks the voucher row, checks
in order that it exists, is active, has not passed `expires_at` and still has
uses left, finds or creates the account, and then:

- **Days stack on the account's current end date.** An account with 10 days of
  Pro left that redeems a 30-day code ends up with 40. An account with no Pro
  time (or an expired one) gets 30 days from now.
- **A lifetime code (`duration_days IS NULL`) sets `pro_expires_at` to `NULL`.**
- **A timed code is refused on a lifetime account (#361).** An account with
  `account_type = 'pro'` and `pro_expires_at IS NULL` (lifetime Pro from a
  lifetime voucher or Paddle's `once` price) that redeems a *timed* code gets
  `already_lifetime` and the code is **not** used up — replacing "forever" with
  a date would be a downgrade. A lifetime code on a lifetime account is the
  no-op it always was, and a `regular` account with a `NULL` expiry is still
  upgradable.
- The account's explicit choice matters: every error leaves `current_uses` and
  `redemption_log` untouched, so a refused redemption never burns a code.

## 2. Creating codes

### The admin page

`voucher_admin.php` (behind `ADMIN_USER_IDS`, like the other admin pages) has
two create forms and two views:

- **Create a code** — days of Pro or an explicit Lifetime choice, how many
  accounts may redeem it, an optional last day (stored as that day `23:59:59`),
  an optional custom code and an optional note. The new code is shown **once**,
  in the flash after the create, and never again.
- **Create a batch of single-use codes** — 1–500 codes in one go, with the same
  duration / last-day / note fields. Every code in a batch is `max_uses = 1`.
  The result redirects to `?view=batch&id=<batch_id>`, which lists the codes
  and offers a **Download CSV** link.
- **The list** — every code, newest first, filtered by status and/or batch id.
  A row that belongs to a batch links to that batch. Each row can be
  deactivated/reactivated, and links to its redemptions (account ids and dates,
  never an address).
- **The CSV** is a GET (`?action=batch_csv&id=<batch_id>`) that only reads. It
  is answered as `text/csv; charset=utf-8`, `Content-Disposition: attachment`,
  `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`, with the
  columns `code,pro_time,redeemable_until,status,redeemed`. The batch id is
  validated against `^[a-f0-9]{16}$` before any query. A cell beginning with
  `=`, `+`, `-` or `@` is prefixed with an apostrophe, so a hand-inserted code
  cannot become a formula in a spreadsheet. The note is not a column.

State changes are same-origin POSTs followed by a `303` and a session flash.

### The service API

Every function takes `PDO $pdo` explicitly, never reads `$_SESSION`, never
echoes, and returns `['ok' => true, …]` or `['ok' => false, 'error' => '…']`.

- `voucherCreate(PDO $pdo, array $spec, array $actor): array` — one code.
  `$spec` requires `duration_days` (int `1`–`3650`, or an **explicit** `null`
  for lifetime — a missing key is an error, so lifetime is never chosen by
  accident) and `max_uses` (int `1`–`10000`). Optional: `expires_at`, `code`
  (validated with the redemption regex; a taken one answers "That code already
  exists"), `note` (trimmed, control characters removed, ≤ 255 characters) and
  — issuers only — `external_ref`.
- `voucherCreateBatch(PDO $pdo, array $spec, int $count, array $actor): array` —
  `$count` codes (`1`–`500`) created **in one transaction**, all or none. The
  `$spec` is `voucherCreate()`'s, except that `max_uses` is forced to `1` and
  `code` / `external_ref` are refused when given. Every code goes through the
  same validation and insert path as `voucherCreate()` (they share
  `voucherCreateValidated()`), so the rules cannot drift apart. Returns
  `['ok' => true, 'batch_id' => …, 'vouchers' => [...]]`.
- `voucherSetActive(PDO $pdo, int $voucherId, bool $active, array $actor): array`.
- `voucherList(PDO $pdo, array $filter = [], int $limit = 50, int $offset = 0): array`
  — newest first; `status` (`active` / `inactive` / `expired` / `used_up`),
  `source` and `batch_id` filters.
- `voucherRedemptions(PDO $pdo, int $voucherId): array` — `user_id` +
  `redeemed_at` per redemption, never an address.
- `voucherBatchCsv(PDO $pdo, string $batchId, array $actor): ?string` — the
  batch as CSV, or `null` when the batch does not exist, is not the actor's, or
  the id is malformed (in which case no query runs at all).

### The actor contract

Every write takes `array $actor`:

- `['type' => 'admin', 'id' => int]` — stored as `source = 'admin'` with
  `created_by_user_id = id`. An admin may touch any voucher.
- `['type' => 'issuer', 'id' => int]` — stored as `source = 'issuer'` with
  `issuer_id = id`. An issuer may touch only its own vouchers, and a voucher
  that is not its own answers the same "Voucher not found" as one that does not
  exist, so it cannot enumerate codes.

Anything else is refused. Nothing calls the service with an `issuer` actor yet;
that is the seller API below. The rules it needs (the actor split,
`external_ref` idempotency, an issuer seeing only its own rows, batch creation)
are implemented and tested now, so that API is an entry point and not a second
set of rules.

### Logging

Creation, (de)activation and batch creation each write one INFO line —
`Voucher created`, `Voucher activated` / `Voucher deactivated`,
`Voucher batch created` (with `batch_id`, `count` and the actor, not one line
per code) — carrying ids, `duration_days`, `max_uses` and `source`. **A code is
a credential and is never logged, in any form.** A batch failure logs the
exception class, never the driver message, which can quote the code it
rejected.

## 3. The future seller API (not implemented)

**None of this section exists yet.** There is no `/api` route, no
`voucher_issuers` table and no issuer key. It is written down so the next epic
starts from a decided shape instead of inventing one and reworking the voucher
model — and so that nothing above has to change to support it. Everything the
service already provides (the actor contract, `external_ref` idempotency,
`voucherCreateBatch()`) is what this API would call.

### Issuers and their keys

A new `voucher_issuers` table: `id`, `name`, `status` (active/suspended), the
per-issuer limits (requests per hour/day, codes per day, the maximum number of
codes in one batch), the set of durations the issuer is allowed to hand out,
and `created_at`.

An issuer authenticates with an API key kept the same way `mcp_tokens.php`
keeps an MCP access token: the key would be its own prefix (not `msk_`) plus 64
lowercase hex characters from `random_bytes(32)`, stored **only as
`hash('sha256', $key)`** (unkeyed — the key is already 256 random bits), shown
**once** at creation and revocable afterwards. Nothing can display it again, so
a lost key is revoked and replaced.

### The endpoints

`.htaccess` would rewrite `/api/vouchers…` to a new entry point:

- `POST /api/vouchers` — `Authorization: Bearer <issuer key>`,
  `Content-Type: application/json`, body
  `{ "duration_days": 30 | "lifetime", "max_uses": 1, "count": 100,
  "external_ref": "order-1001" }`. With `count` it calls
  `voucherCreateBatch()`, without it `voucherCreate()`, in both cases with
  `['type' => 'issuer', 'id' => <issuer id>]`. `external_ref` makes a retry
  idempotent: the UNIQUE `(issuer_id, external_ref)` key added in #360 means the
  same reference returns the code the first call created rather than a second
  one.
- `POST /api/vouchers/{id}/deactivate` — `voucherSetActive(…, false, …)`.
- `GET /api/vouchers?external_ref=…` — the issuer's own codes, with their uses.

The request bodies would go through the same validation as the admin page — the
service's, not a copy — and every failure would answer a JSON error, never an
HTML page.

### Limits and logging

Per-issuer rate limits would go through `abuse_guard.php`, like every other
limit in the codebase (fail-open, keyed on the issuer id rather than an IP).
Logging follows the same rule as today: the issuer id, the code id and the
outcome, **never a code and never a key**.

### Out of scope until then

Issuer self-service (sign-up, key rotation in a UI) and settlement/invoicing.
Codes are created by an admin on `voucher_admin.php`; an issuer is onboarded by
hand.
