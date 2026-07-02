# DATA_MODEL.md - Databasstruktur

## Tabell: mailfilter_scripts

Ett rad per script_id (= ett mailkonto hos en användare).

| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK, autoincrement | Internt ID |
| `user_id` | INT, FK → users.id | Vilken manjo.me-användare äger scriptet |
| `script_id` | VARCHAR(20), UNIQUE | Det publika script_id (t.ex. "abc123xyz") |
| `label` | VARCHAR(100), NULLABLE | Användarens egen benämning, t.ex. "Min jobbmail" |
| `rsa_public_key` | TEXT | Publik RSA-nyckel (distribueras till scriptet) |
| `rsa_private_key` | TEXT, ENCRYPTED AT REST | Privat RSA-nyckel (ALDRIG exponerad via API) |
| `target_host` | VARCHAR(255) | Domän/URL där scriptet är installerat, t.ex. "manjo.se" |
| `target_path` | VARCHAR(255) | Sökväg till scriptet, t.ex. "/mailfilter_abc123xyz.php" |
| `greylist_days` | INT, DEFAULT 30 | Antal dagar innan grålistad mail raderas |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |
| `last_webhook_sent_at` | DATETIME, NULLABLE | Senaste lyckade webhook |
| `last_webhook_timestamp` | VARCHAR(30), NULLABLE | Samma timestamp som skickades (för felsökning) |

**Säkerhetsanmärkning**: `rsa_private_key` ska krypteras i databasen (t.ex. med en master-key i KMS/secrets manager), inte lagras i klartext även internt.

---

## Tabell: mailfilter_whitelist

| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `script_id` | VARCHAR(20), FK → mailfilter_scripts.script_id | |
| `pattern` | VARCHAR(255) | Email, domän (`@example.com`) eller wildcard (`user@*.company.com`) |
| `added_at` | DATETIME | |
| `added_via` | ENUM('manual', 'sender_report') | Om det lades till manuellt eller via Alt 2-flödet |

---

## Tabell: mailfilter_blacklist

Samma struktur som whitelist:

| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `script_id` | VARCHAR(20), FK | |
| `pattern` | VARCHAR(255) | |
| `added_at` | DATETIME | |
| `added_via` | ENUM('manual', 'sender_report') | |

---

## Tabell: mailfilter_reported_senders (Alt 2-flödet)

Lagrar avsändare som scriptet rapporterat in men som ännu inte kategoriserats av användaren.

| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `script_id` | VARCHAR(20), FK | |
| `sender_email` | VARCHAR(255) | |
| `first_seen_at` | DATETIME | |
| `last_seen_at` | DATETIME | |
| `message_count` | INT, DEFAULT 1 | Hur många mail från denna avsändare sedan senast |
| `status` | ENUM('pending', 'whitelisted', 'blacklisted', 'ignored') | DEFAULT 'pending' |

**Logik**: När user kategoriserar en pending-rad flyttas mönstret till whitelist/blacklist-tabellen och status uppdateras.

---

## Tabell: mailfilter_webhook_log

För felsökning och spårbarhet av skickade webhooks.

| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `script_id` | VARCHAR(20), FK | |
| `sent_at` | DATETIME | |
| `payload_timestamp` | VARCHAR(30) | Timestamp som skickades i payloaden |
| `http_status_code` | INT, NULLABLE | Svar från användarens server |
| `response_body` | TEXT, NULLABLE | |
| `success` | BOOLEAN | |
| `error_message` | TEXT, NULLABLE | |

---

## Relationer (översikt)

```
users (befintlig tabell)
   └── mailfilter_scripts (1 user kan ha flera script_id)
          ├── mailfilter_whitelist (många rader)
          ├── mailfilter_blacklist (många rader)
          ├── mailfilter_reported_senders (många rader)
          └── mailfilter_webhook_log (många rader, historik)
```

---

## Index-rekommendationer

```sql
CREATE UNIQUE INDEX idx_script_id ON mailfilter_scripts(script_id);
CREATE INDEX idx_whitelist_script ON mailfilter_whitelist(script_id);
CREATE INDEX idx_blacklist_script ON mailfilter_blacklist(script_id);
CREATE INDEX idx_reported_script_status ON mailfilter_reported_senders(script_id, status);
CREATE INDEX idx_webhook_log_script ON mailfilter_webhook_log(script_id, sent_at);
```

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
