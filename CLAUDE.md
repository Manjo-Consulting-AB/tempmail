# CLAUDE.md

This file gives Claude Code (and other AI assistants) the context needed to work effectively in this repository.

## What this is

TempMail (manjo.me) is a disposable/temporary email address web service written in plain PHP (no framework, no build step). Users get throwaway inboxes; incoming mail is fetched over IMAP and shown in a web UI. There's also a paid "Pro" tier (magic-link login, saved profile, RSS feed of messages, Buy Me a Coffee billing) and a newer, mostly separate "Client Agent" subsystem that end users install on their own mail server to filter mail locally, driven by RSA-signed webhooks from this backend.

There is no `README.md` at the repo root — this file and `documentaion/` are the closest thing to onboarding docs. Several docs under `documentaion/` and `client/` are written in Swedish, as are many code comments; expect to read/write Swedish comments when touching existing code, but prefer English for new comments unless matching surrounding style.

## Tech stack

- **Language**: PHP, procedural with some classes. No framework (no Laravel/Symfony).
- **Database**: MySQL/MariaDB via PDO (`$pdo` global set up in `config.php`).
- **Mail parsing**: `php-mime-mail-parser/php-mime-mail-parser` and `zbateson/mail-mime-parser` (see `composer.json`). IMAP access uses the PHP `imap` extension where available, with `LegacyImapFallback.php` and `python_imap_fallback.py` as fallbacks when it isn't (e.g. some Docker images).
- **Frontend**: vanilla JS (`assets/js/app.js`, class `TempMailApp`) and vanilla CSS (`assets/css/style.css`). No Node/npm, no TypeScript, no bundler. A service worker lives at `assets/js/sw.js`.
- **Deploy target**: traditional shared PHP hosting (see `.htaccess`, which blocks `.env|.log|.sql|.backup` and Docker files from being served). Docker is used for local/dev only.

## Commands

There is no build step and no automated test suite (no PHPUnit/Pest, no `tests/` directory).

```bash
composer install                 # install PHP dependencies (vendor/ is gitignored)
php check_parser.php             # manual smoke test: verifies MIME parser classes load
php prod_diagnostics.php         # manual CLI diagnostics: env/DB/IMAP/attachments/vendor + dry-run ImapProcessor
php cron/run_imap_once.php       # manually trigger one IMAP fetch cycle
```

CI (`.github/workflows/main.yml`, "Säkerhetsscanning", daily + on-demand) runs:
- `semgrep scan --config p/php --config p/security-audit --error` — treat semgrep findings as build-breaking; check `.semgrepignore` before assuming a file is covered (it currently excludes `client/backend/api.php` and `client/agent/agent.php`).
- `composer audit --locked`

There is no linter/formatter config (no `.eslintrc`, `.prettierrc`, `tsconfig`, `phpcs.xml`) — match existing style by hand.

## Configuration

`config.php` is the central entry point: it locates and loads a `.env.{environment}` file (searching several candidate directories, preferring a "secure" directory outside the webroot), populates `$_ENV`/`putenv`, builds the `$config` array (db, imap, app, cron, attachments), and opens the global `$pdo` connection. Every top-level script does `require_once 'config.php';` after `define('TEMPMAIL_APP', true);` — that define guards includes against direct access.

No `.env.example` is committed. Key env vars referenced in code (names only): `TEMPMAIL_ENV_FILE`, `ENV_FILE_PATH`, `TEMPMAIL_ENV_DIR`, `SECURE_ENV_DIR`, `DOCKER_ENV`, `APP_ENV`, `ENV`, `EMAIL_DOMAIN`, `BASE_URL`, `LOG_RETENTION_DAYS`, `CRON_HTTP_SECRET`, `SMTP_MAX_PER_RUN`, `SMTP_BATCH_SIZE`, `SMTP_PER_MINUTE`, `BMAC_WEBHOOK_SECRET`, `DB_HOST`, `DB_PORT`, `DB_SOCKET`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `IMAP_SERVER`, `IMAP_USER`, `IMAP_PASSWORD`, `IMAP_ENABLED`, `DEBUG_MODE`, `LOG_LEVEL`, `ATTACHMENT_TTL`, `ATTACHMENT_DOWNLOAD_SECRET`, `CLIENT_AGENT_PRIVATE_ROOT`.

Never commit `.env.*` files, real secrets, or values for the vars above — `.gitignore` already excludes `.env.*`, `vendor/`, and generated client-agent secrets under `client/agent/`.

## Architecture

- **`config.php`** — env loading, `$config`, global `$pdo`. Required by nearly everything.
- **`index.php`** — main web UI and the POST-based AJAX/API surface (address generation/validation, inbox reads against `temp_emails`). This is the largest and most central file (~69KB); look here first for how request handling and DB access conventions work before adding new endpoints.
- **`php_imap_processor.php`** (`ImapProcessor` class) + **`MailParser.php`** (`MailParser` class) — fetch mail via IMAP and parse MIME into DB rows + `attachments/`. `LegacyImapFallback.php` / `python_imap_fallback.py` back these when the `imap` extension is unavailable. Triggered via `run_imap_processor.php` / `cron/run_imap_once.php`.
- **`cron/`** — scheduled jobs: `cleanup.php` (expire old addresses), `process-webhook-deliveries.php`, `requeue-webhook-delivery.php`, `rotate_signing_keys.php`, `send-digests.php`.
- **Pro tier** — `pro_login.php` / `pro_auth.php` (magic-link auth), `pro.php` / `pro_profile.php` / `pro_profile_page.php` (dashboard/settings), `pro_feed.php` (RSS), `pro_contact.php`, `pro_logout.php`, `bmac_handler.php` (Buy Me a Coffee webhook, HMAC-SHA256 verified).
- **Attachments** — `files.php` / `download_attachment.php` are signed-URL proxies for downloading files out of `attachments/` (not directly web-served).
- **Client Agent subsystem** — a mostly separate feature: `client/agent/` (agent.php, install.php, bootstrap.php) is code end users install on their own mail server; it verifies RSA-signed webhooks and stores AES-256-GCM-encrypted local secrets. `client/backend/` (api.php, bootstrap.php) is the manjo.me-side API managing filter scripts/lists (tables documented in `documentaion/backend/DATA_MODEL.md`: `mailfilter_scripts`, `mailfilter_whitelist`/`blacklist`, `mailfilter_reported_senders`, `mailfilter_webhook_log`). Root-level `client_agent_*.php` files (`api`, `download`, `install_download`, `manage`) are the public entry points into this subsystem. Read `documentaion/agent/ARCHITECTURE.md` and `documentaion/backend/*.md` before changing this area — it has its own security model (RSA signing, key rotation via `cron/rotate_signing_keys.php`) distinct from the rest of the app.
- **`blog.php`** — renders markdown files from `blog/*.md` as a minimal blog.
- **`log_viewer.php`** — standalone admin UI over a `system_logs` DB table.
- **`debug_logger.php`** — JSON-lines file logger independent of the DB, used by mail-parsing code so a logging failure can't cascade into a parsing failure.

## Conventions worth knowing

- Database access uses PDO with prepared statements (`$pdo->prepare(...)->execute([...])`) — follow this pattern for any new query; do not interpolate user input into SQL.
- User-supplied identifiers (e.g. the temp address in `index.php`) are validated with a strict regex (`^[a-f0-9]{8,16}$`) before being used in queries or lookups — apply the same "validate then look up" pattern for new address/ID handling.
- `detectSuspiciousPatterns()` + `logMessage('WARNING', ...)` is used in `index.php` to flag and log potentially malicious input on POST actions before they're processed further.
- This codebase has had repeated rounds of Semgrep-driven security hardening (see git log: "Sec issues fixed in index", "nosemgrep" commits) — when Semgrep flags something, prefer fixing the underlying issue over adding a `// nosemgrep` suppression; only suppress with a comment explaining why it's a false positive.
- Several files/dirs use Swedish names or comments (`documentaion/` is a real, intentional typo in the directory name — don't "fix" it without checking references first, since it's used as a path elsewhere).
