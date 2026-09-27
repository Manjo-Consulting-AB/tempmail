# Epic: MCP server for Pro accounts — manage Sticky and Timed addresses from an AI client

> **DRAFT** — the body of a GitHub epic, not yet filed. Once the decisions in §3 are made it becomes the epic and its sub-issues, and this file is deleted.

A Pro account can connect its own AI client (Claude Code, Claude Desktop, and later Claude.ai and other MCP clients) to Mail Shield and, through it, **list, create and delete its Sticky and Timed addresses and read the mail they have received**. Nothing else: no sending, no settings, no webhooks, no billing.

**Status: waiting on the decisions in §3.** Everything else below follows from the code as it is today.

## 1. Scope

| Tool | What it does | Kind |
|---|---|---|
| `list_addresses` | The account's Sticky addresses and its Timed address, with expiry and pause/closed state | read |
| `list_messages` | Newest messages for one of the account's addresses: id, from, subject, received time, text preview, attachment count | read |
| `get_message` | One message as plain text, plus attachment metadata and a signed, time-limited download link per attachment | read |
| `create_sticky_address` | A Sticky address with a chosen local part (max 10, same rules as the site) | write |
| `create_timed_address` | A new Timed address with the account's own lifetime (1–7 days) | write, **destructive** (see §2.2) |
| `delete_address` | Deletes one Sticky or Timed address together with its mail | write, destructive |

Out of scope for this epic: deleting single messages, marking read, sending mail, webhooks/RSS/Pushover settings, the per-address hook pause, the Client Agent, and anything Regular (free) accounts can do.

## 2. How the code works today — facts the design has to respect

### 2.1 Everything lives inside `index.php`'s action switch
`create_personal`, `list_personal`, `delete_personal`, `generate`, `get_emails` and `get_email` are inline `case` blocks that read `$_SESSION['pro_user_id']` directly. `requireSameOriginRequest()` (line 65) rejects every POST that does not come from the site itself, and the creation rate limit is a closure (`$creationLimited`) local to that file. None of it can be called from a second entry point. **Step 1 is to extract it** (sub-issue 1).

### 2.2 An account has exactly one Timed address
`generate` for a signed-in user deletes the account's existing non-personal address — **with all its mail and attachments** — inside the same transaction as the insert, and only then removes the old forwarder. So `create_timed_address` is not "add one more": it replaces the current one. The MCP tool must say so, carry `destructiveHint: true`, and refuse when a Timed address already exists unless the call passes `replace_existing: true`.

### 2.3 There is no "delete Timed address" action
The site only lets a Timed address expire (or be replaced). `delete_address` for a Timed address is new behaviour: delete its `stored_emails`/`email_attachments` through `deleteStoredEmailsForTempEmail()` + `unlinkAttachmentFiles()`, then `deleteDirectAdminForwarder()` — the same order `generate` uses. A deleted Sticky address goes through the existing path unchanged, **including `addressCooldownAdd()`** (the address cool-off; `tests/address_cooldown_test.php` scans for it).

### 2.4 Ownership of a message is not checked by owner id today
`get_email` checks owner id only for **Sticky** addresses; for a Timed address it relies on `hasSessionAddressAccess()` — "this session has already listed that address". MCP has no such session state, so the extracted service must decide access to both kinds by `temp_emails.pro_user_id = <token's user>`, for the address and for the message's `to_address`, in one query.

### 2.5 Rules that already apply and must hold for MCP too
- Pro entitlement only through `proUserIsPro()`; suspension through `proUserIsSuspended()`. A suspended account gets nothing.
- Sticky creation: `sanitizeLocalPart()`, `isValidNewLocalPartSyntax()`, `isReservedLocalPart()`, the cool-off holder check, max 10, fail-closed forwarder creation.
- Creation rate limits `personal_user` / `gen_user` from `abuse_guard.php`, in the **same buckets** as the site, so MCP is not a way around them.
- Message bodies are attacker-controlled. The site sends HTML only after `purifyEmailHtml()`; MCP sends **no HTML at all**, only `body_text` or `emailHtmlToText()`.
- Never log a user's email address or a credential (CLAUDE.md, `tests/email_log_ref_test.php`). Service-domain addresses may be logged.
- `getVisitorIp()` for anything IP-based, never raw `REMOTE_ADDR`.

## 3. Decisions needed before implementation

1. **How clients authenticate.**
   - **A. Personal access tokens (recommended first).** The user creates a token on `pro_profile_page.php` and pastes it into the client's config as `Authorization: Bearer …`. Works with Claude Code, Claude Desktop, Cursor and most MCP clients that accept custom headers. Small: one table, one migration, a card on the profile page.
   - **B. OAuth 2.1 as the MCP authorization spec describes** (Mail Shield as an authorization server + protected resource metadata at `/.well-known/oauth-protected-resource`). Needed for Claude.ai connectors and clients that only do OAuth. Considerably larger: consent screen, client registration, codes with PKCE, refresh tokens, audience binding.
   - Recommendation: **A now, B as a later epic.** The tools and service layer are the same either way.
2. **Scopes.** Recommendation: two, `read` (the three read tools) and `write` (the three write tools), chosen when the token is created; default `read` only.
3. **When an account loses Pro.** The site keeps `list_personal` and `delete_personal` open to a degraded account. Recommendation: the MCP endpoint is **Pro-only throughout** (the site stays available for clean-up); tokens are kept but refused until Pro returns.
4. **Library or hand-written protocol.** The server needs only `initialize`, `ping`, `tools/list` and `tools/call` over one HTTP POST endpoint. Recommendation: **write the JSON-RPC handling ourselves** (~300 lines, no new Composer dependency to audit, no framework on shared hosting), with the protocol version pinned in one constant. Reconsider if we ever add resources, prompts or streaming.
5. **Where the Timed address' lifetime comes from.** Recommendation: the account's `address_ttl_days` (as the site), no per-call override.
6. **Copy.** The brief (§8 Automation, §9 inventory) says there is no public API and shows `API — Coming`. Does the landing card become `MCP` / `Connect your AI assistant`, or does the badge stay until the feature has run for a while? Owner decision; the brief is updated in the same change either way.

## 4. Sub-issues

- [ ] **1/6 — Extract an address and mailbox service out of `index.php`** (no behaviour change)
  New `mailbox_service.php` with functions that take `PDO $pdo, int $userId` explicitly and return arrays instead of echoing: `mailboxListAddresses`, `mailboxCreateSticky`, `mailboxCreateTimed`, `mailboxDeleteSticky`, `mailboxListMessages`, `mailboxGetMessage`, and the creation rate limit moved out of the `$creationLimited` closure. `index.php`'s cases become thin wrappers that keep their exact JSON answers. The anonymous (logged-out) `generate` path and `hasSessionAddressAccess()` stay as they are. Test: `tests/mailbox_service_test.php` on SQLite, plus running the existing `address_cooldown_test.php`, `abuse_guard_test.php` and `webhook_routing_test.php` unchanged.
- [ ] **2/6 — Personal access tokens**
  Table `mcp_access_tokens` (`id`, `pro_user_id`, `name`, `token_hash` UNIQUE, `token_prefix`, `scopes`, `created_at`, `last_used_at`, `expires_at` nullable, `revoked_at`) via `migrate_mcp_tokens.php`, and `check_mcp_tokens.php`. Token = `msk_` + 256 random bits; only `sha256` is stored (unkeyed, like `feed_token_hash`), shown once. Max 10 active per account. A "Connected apps" card on `pro_profile_page.php`: create (name, scopes, optional expiry), list (name, prefix, created, last used), revoke. All tokens are removed when the password or email changes, on account deletion and on suspension — the same places `pro_remember.php` clears devices. Fail closed without the table.
- [ ] **3/6 — The MCP endpoint `mcp.php`**
  Streamable HTTP, POST only, answering with `application/json` (no SSE, no sessions — shared hosting cannot hold connections); `GET` → 405. Order per request: method and content-type → `Origin` check (absent, or our own origin) → Bearer token lookup by hash → not revoked/expired → `proUserIsPro()` and not `proUserIsSuspended()` → per-token rate limit (new `mcp_token` counter in `abuse_counters`) → JSON-RPC dispatch. 401 with `WWW-Authenticate: Bearer` for a missing or bad token; 403 for no Pro or a missing scope. `.htaccess`: pass `Authorization` through to PHP (`CGIPassAuth On` or `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`), plus `/mcp` → `mcp.php`. `robots.txt` disallows it. Logging: token id and user id only, never the token or the request body. Tests: `tests/mcp_endpoint_test.php` runs the real file through PHP's built-in server like `feed_token_test.php`.
- [ ] **4/6 — Read tools: `list_addresses`, `list_messages`, `get_message`**
  On the service from 1/6. Each has `readOnlyHint: true`. `list_messages`: `limit` 1–50 (default 20), newest first, only messages that have not expired (same condition as `get_emails`). `get_message`: plain text only, truncated at a fixed length with `truncated: true`; attachments as `filename`, `content_type`, `size`, and a `generateSignedAttachmentUrl()` link. The tool descriptions and the result state that message content comes from outside senders and is data, not instructions. Tests cover another account's address and message ids (not found, not forbidden, so ids reveal nothing), an expired message, and a message with HTML only.
- [ ] **5/6 — Write tools: `create_sticky_address`, `create_timed_address`, `delete_address`**
  Require the `write` scope. `create_timed_address` refuses without `replace_existing: true` while a Timed address exists (§2.2); `delete_address` takes the full or local address and requires `confirm: true`; both carry `destructiveHint: true`. `delete_address` on a Timed address is the new path in §2.3. Errors are the site's own messages (`Address already taken`, `Maximum of 10 sticky addresses allowed`, `Too many new addresses in a short time…`) as tool errors (`isError: true`), not protocol errors. Tests: the 10-address cap, a reserved local part, someone else's cool-off, rate limits shared with the site, forwarder failure leaving nothing behind, and the cool-off scan extended to the new deletion path.
- [ ] **6/6 — Documentation, copy and legal**
  CLAUDE.md (commands, the new tables, the endpoint under Architecture); the brief (§8 Automation card, §9 inventory) as decided in §3.6; a help text on the "Connected apps" card with config examples for Claude Code and Claude Desktop; an FAQ entry; `privacy.php`: mail content now goes to an AI client **the user chose and connected**, the token table and what it stores, and `last_used_at`; `terms.php` if the acceptable-use wording needs to cover automated access.

## 5. Order
- 1/6 first, on its own PR — it touches the most-used file and must be behaviour-neutral.
- 2/6 can run in parallel with 1/6.
- 3/6 after 2/6. 4/6 after 1/6 and 3/6. 5/6 after 4/6.
- 6/6 PR A (CLAUDE.md, profile help, FAQ) goes with 3/6–5/6; PR B (brief, landing copy, privacy) is merged only after 5/6 is deployed, because copy may promise only what ships.
- Migrations run after the deploy job is green.

## 6. Rules that apply to every sub-issue
- `php -l` on every touched PHP file; Semgrep (`redesign-pr.yml`) is build-breaking — fix, don't suppress.
- Prepared statements only; validate every address and id before looking it up.
- Never log an email address of a user or a token value.
- Frontend work (the profile card) follows `documentaion/REDESIGN_BRIEF.md` §11, §12, §14.
- CLAUDE.md is updated in the same PR when a command, migration, env var or architecture fact changes.
- Before 3/6 starts, check the current MCP specification version and pin it; the transport and authorization parts of the spec have changed between versions.
