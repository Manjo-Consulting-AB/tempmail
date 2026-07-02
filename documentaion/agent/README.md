# Mailfilter-System Implementation Guide

## Projektöversikt

**Mailfilter** är ett autonomt mailhanteringssystem som körs på användarens server och möjliggör:
- Automatisk filtrering av inkommande mail baserat på vitlista/grålista/svartlista
- Lokal kontroll över alla lösenord och känslig data
- Minimal kommunikation med manjo.me
- Säker webhook-baserad listauppdatering

## Arkitektur

### Systemkomponenter

```
manjo.me (extern)
    ↓
    ├─→ [Webhook: update_lists] →  mailfilter_abc123xyz.php (cron + webhook)
    └─→ [API: report_senders] ←    (Alt 2: Avslappnad flow)

User's Server
    ├─ /home/user/.manjo/
    │   ├─ mailfilter_abc123xyz.json      (Settings + pubkey)
    │   ├─ mailfilter_abc123xyz.key       (Hemlig nyckel för kryptering)
    │   └─ logs/
    │       └─ mailfilter_abc123xyz_2026-06.log
    │
    └─ /home/user/public_html/
        └─ mailfilter_abc123xyz.php       (Huvudscript)
```

### Körningsmodeller

**Modell 1: Cron (Paranoid/Avslappnad)**
```
Cron: */30 * * * * php /home/user/public_html/mailfilter_abc123xyz.php
```
- Läser mail från IMAP
- Applicerar filter (vita/grå/svartlista)
- Raderar enligt regler
- Loggning

**Modell 2: Webhook från manjo.me**
```
POST https://user.example.se/mailfilter_abc123xyz.php?action=update_lists
```
- Mottar signerad JSON med nya lister
- Verifierar RSA-signatur
- Uppdaterar settings.json
- Loggning

---

## Filstruktur & Namngivning

### Settings-fil (`~/.manjo/mailfilter_abc123xyz.json`)

```json
{
  "script_id": "abc123xyz",
  "user_email": "tony@example.com",
  "imap_host": "imap.manjo.se",
  "imap_port": 993,
  "imap_password": "base64_encrypted_password_or_plaintext",
  "imap_password_encrypted": false,
  "mailbox": "INBOX",
  "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
  "whitelist": ["tony@example.com", "boss@company.com"],
  "blacklist": ["spam@evil.com"],
  "greylist_days": 30,
  "logging_enabled": true,
  "last_run": "2026-06-29T14:30:00Z",
  "last_webhook_timestamp": "1970-01-01T00:00:00Z"
}
```

### Loggfiler (`~/.manjo/logs/mailfilter_abc123xyz_2026-06.log`)

```
[2026-06-29T14:30:00Z] CRON_RUN: Starting mailfilter for abc123xyz
[2026-06-29T14:30:01Z] IMAP_CONNECT: Connected to imap.manjo.se:993
[2026-06-29T14:30:05Z] FILTER_INBOX: Found 45 unfoldered messages
[2026-06-29T14:30:06Z] MAIL_WHITELISTED: tony@example.com (5 emails)
[2026-06-29T14:30:07Z] MAIL_GREYLIST_DELETED: 3 emails (28+ days old)
[2026-06-29T14:30:08Z] MAIL_BLACKLIST_DELETED: 2 emails
[2026-06-29T14:30:09Z] CRON_COMPLETE: 10 emails processed, 5 deleted
```

---

## Utvecklingsvägar

### Sprint 1: Core Loop (Cron)
1. ✓ Settings-fil läsning
2. ✓ IMAP-anslutning och autentisering
3. ✓ Inbox-läsning (unfolderade mail)
4. ✓ Listbeslut (vita/grå/svart)
5. ✓ IMAP-borttagning
6. ✓ Loggning

### Sprint 2: Webhook + Signering
1. ✓ Webhook-mottagning (`?action=update_lists`)
2. ✓ RSA-signaturverifiering
3. ✓ Anti-replay (timestamp-check)
4. ✓ Settings-uppdatering
5. ✓ HTTP-response

### Sprint 3: Setup & Kryptering
1. ✓ Setup-script (`setup_mailfilter.php`)
2. ✓ Lokal lösenordskryptering (AES-256-CBC)
3. ✓ Hemlig-nyckel-hantering
4. ✓ Dekryptering vid körning

---

## Nästa Steg

Läs dokumenten i denna ordning:
1. **ARCHITECTURE.md** - Detaljerad filstruktur och flowdiagram
2. **API_SPECIFICATION.md** - Webhook-format och signering
3. **IMPLEMENTATION_GUIDE.md** - Steg-för-steg kodning
4. **PHP_FUNCTIONS.md** - Detaljerade funktionsbeskrivningar
5. **SECURITY.md** - Säkerhetsantaganden och risker
6. **SETUP_INSTRUCTIONS.md** - För slutanvändaren

---

## Tekniska Krav

- **PHP**: 7.4+
- **PHP-extensions**: imap, openssl, json
- **Server**: Linux/Unix (för ~/.manjo-katalog)
- **Cron**: Standard crontab
- **IMAP**: Stöds av användarens mailserver
- **RSA-nycklar**: Genereras av manjo.me (4096-bits)

---

## Säkerhetsöversikt

- ✓ Lösenord aldrig skickat till manjo.me
- ✓ Webhook-signeringar med RSA-4096
- ✓ Anti-replay-skydd (timestamp-check)
- ✓ Lokal kryptering av lösenord (AES-256-CBC)
- ✓ Hemlig nyckel utanför www-root
- ✓ Settings-fil utanför www-root

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-29  
**Ansvarig**: Tony (Gentlemen of Sweden)
