# ARCHITECTURE.md - Systemarkitektur

## Filstruktur

### Server-layout för en användare (Tony)

```
/home/tony/
│
├── .manjo/                                  # Hemlig katalog (utanför www)
│   │                                        # Innehåller: inställningar, nycklar, loggar
│   │
│   ├── mailfilter_abc123xyz.json           # Settings + public RSA key
│   │   (NOT i www-root)
│   │
│   ├── mailfilter_abc123xyz.key            # Hemlig nyckel för lösenordskryptering
│   │   (chmod 0600, ALDRIG exponera)
│   │
│   └── logs/
│       ├── mailfilter_abc123xyz_2026-06.log
│       ├── mailfilter_abc123xyz_2026-07.log
│       └── ... (en fil per månad, för alltid)
│
└── public_html/                             # www-root
    ├── mailfilter_abc123xyz.php            # Huvudscriptet (BARA DETTA I www)
    ├── index.html                          # Övrigt webbinnehål
    └── ...
```

### Flera användare på samma server

```
/home/user1/.manjo/
    ├── mailfilter_abc123xyz.json
    ├── mailfilter_abc123xyz.key
    └── logs/mailfilter_abc123xyz_*.log

/home/user2/.manjo/
    ├── mailfilter_def456uvw.json
    ├── mailfilter_def456uvw.key
    └── logs/mailfilter_def456uvw_*.log

/home/user3/.manjo/
    ├── mailfilter_ghi789rst.json
    ├── mailfilter_ghi789rst.key
    └── logs/mailfilter_ghi789rst_*.log

/var/www/public_html/ (eller /home/*/public_html/)
    ├── mailfilter_abc123xyz.php
    ├── mailfilter_def456uvw.php
    ├── mailfilter_ghi789rst.php
    └── ...
```

**Varje script är helt isolerat** och kan inte interferera med andra.

---

## Datastrukturer

### Settings.json Fullständig Format

```json
{
  "script_id": "abc123xyz",
  "api_version": "1.0",
  
  "imap": {
    "user_email": "tony@example.com",
    "host": "imap.manjo.se",
    "port": 993,
    "password": "base64_encrypted_or_plaintext",
    "password_encrypted": false,
    "mailbox": "INBOX"
  },
  
  "security": {
    "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
  },
  
  "filters": {
    "whitelist": [
      "tony@example.com",
      "boss@company.com",
      "@trusted-domain.com"
    ],
    "blacklist": [
      "spam@evil.com",
      "ads@*.com",
      "@newsletter-spam.net"
    ]
  },
  
  "greylist": {
    "enabled": true,
    "days": 30
  },
  
  "runtime": {
    "logging_enabled": true,
    "last_run": "2026-06-29T14:30:00Z",
    "last_webhook_timestamp": "1970-01-01T00:00:00Z"
  }
}
```

---

## Körningsflöden

### Flöde 1: Cron-körning (Lokal filtrering)

```
┌─────────────────────────────────────────┐
│ Cron: */30 * * * * php mailfilter...php │
└────────────┬────────────────────────────┘
             │
             ▼
     ┌───────────────────┐
     │ Load settings.json │
     └────────┬──────────┘
              │
              ▼
     ┌──────────────────────────────┐
     │ Decrypt password (if needed) │
     └────────┬─────────────────────┘
              │
              ▼
     ┌──────────────────────────────┐
     │ Connect IMAP                 │
     │ - imap_open()                │
     │ - imap_mailbox_structure()   │
     └────────┬─────────────────────┘
              │
              ▼
     ┌──────────────────────────────┐
     │ Fetch unfolddered messages   │
     │ - imap_search("UNFLAGGED")   │
     │ (or custom logic)            │
     └────────┬─────────────────────┘
              │
              ▼
     ┌──────────────────────────────┐
     │ For each message:            │
     │ 1. Get sender email          │
     │ 2. Check whitelist           │
     │ 3. Check blacklist           │
     │ 4. Check greylist + age      │
     └────────┬─────────────────────┘
              │
        ┌─────┴──────────────────┐
        │                        │
        ▼                        ▼
   ┌─────────┐          ┌──────────────┐
   │ KEEP    │          │ DELETE/SKIP  │
   │(whiteL) │          │ (blackL/old) │
   └─────────┘          └──────────────┘
        │                        │
        ▼                        ▼
   [Do nothing]         [imap_delete()]
                        [imap_expunge()]
        │                        │
        └─────────┬──────────────┘
                  │
                  ▼
         ┌────────────────────┐
         │ Log all actions    │
         │ - Appended to file │
         │ - Rotated monthly  │
         └────────┬───────────┘
                  │
                  ▼
         ┌────────────────────┐
         │ Close IMAP         │
         │ Save last_run time │
         │ Exit gracefully    │
         └────────────────────┘
```

---

### Flöde 2: Webhook-mottagning (Remote Update)

```
┌──────────────────────────────────────────────────────────┐
│ POST https://user.example.se/mailfilter_abc123xyz.php    │
│ ?action=update_lists                                      │
│                                                           │
│ JSON Body (signerat med manjo.me privat RSA-nyckel):    │
│ {                                                        │
│   "api_version": "1.0",                                  │
│   "script_id": "abc123xyz",                              │
│   "timestamp": "2026-06-29T14:30:00Z",                   │
│   "action": "update_lists",                              │
│   "data": {...},                                         │
│   "signature": "base64_..."                              │
│ }                                                        │
└────────────┬──────────────────────────────────────────────┘
             │
             ▼
     ┌────────────────────────────┐
     │ Parse JSON body            │
     │ Check $_GET['action']      │
     └────────┬───────────────────┘
              │
              ▼
     ┌────────────────────────────┐
     │ Load settings.json         │
     │ Extract public_key         │
     └────────┬───────────────────┘
              │
              ▼
     ┌────────────────────────────┐
     │ Verify RSA signature       │
     │ openssl_verify()           │
     └────────┬───────────────────┘
              │
         ┌────┴────┐
         │          │
      ✓  │          │  ✗
         ▼          ▼
    [Continue]   [HTTP 401]
         │        [Log error]
         │        [Exit]
         │
         ▼
     ┌────────────────────────────┐
     │ Check timestamp            │
     │ - Not older than 1 hour    │
     │ - Not <= last_webhook_time │
     └────────┬───────────────────┘
              │
         ┌────┴────┐
         │          │
      ✓  │          │  ✗
         ▼          ▼
    [Continue]   [HTTP 400]
         │        [Log error]
         │        [Exit]
         │
         ▼
     ┌────────────────────────────┐
     │ Extract data from payload: │
     │ - whitelist[]              │
     │ - blacklist[]              │
     │ - greylist_days            │
     └────────┬───────────────────┘
              │
              ▼
     ┌────────────────────────────┐
     │ Update settings.json:      │
     │ - Replace filters          │
     │ - Update greylist_days     │
     │ - Save last_webhook_time   │
     └────────┬───────────────────┘
              │
              ▼
     ┌────────────────────────────┐
     │ Log successful update      │
     │ HTTP 200 OK                │
     │ Return JSON status         │
     └────────────────────────────┘
```

---

## Krypteringstekniker

### Lösenordskryptering (AES-256-CBC)

**Vid setup (setup_mailfilter.php):**
```
1. User ger: hemlig_nyckel (16+ chars)
2. Script genererar: IV (16 bytes, random)
3. Härnyckel = SHA256(hemlig_nyckel)
4. Encrypted = AES-256-CBC(password, härnyckel, IV)
5. Lagra: { "cipher": base64(encrypted), "iv": base64(IV) }
6. Spara hemlig_nyckel i ~/.manjo/mailfilter_*.key
```

**Vid körning (mailfilter_*.php):**
```
1. Läs hemlig_nyckel från ~/.manjo/mailfilter_*.key
2. Härnyckel = SHA256(hemlig_nyckel)
3. Password = AES-256-CBC-DECRYPT(cipher, härnyckel, IV)
4. Använd password för IMAP-anslutning
```

### RSA-signering (Webhook)

**Manjo.me signerar payload:**
```
1. Skapa JSON: { api_version, script_id, timestamp, action, data }
2. Serialisera: json_encode() med specific flags
3. Signera: openssl_sign($json, $sig, $privkey, 'sha256WithRSAEncryption')
4. Lägg till: signature: base64($sig)
5. Skicka: POST med JSON body
```

**Script verifierar:**
```
1. Läs public_key från settings.json
2. Extrahera signature från JSON, ta bort från payload
3. Serialisera samma JSON igen (EXAKT samma format)
4. Verifiera: openssl_verify($json, $sig, $pubkey, 'sha256WithRSAEncryption')
5. Om ✓ = fortsätt, om ✗ = HTTP 401
```

---

## Dagbeskrivning

### Mailappar efter filtrering

**Vitlistade:**
```
Sender: tony@example.com
Status: KEEP (nothing happens)
Action: None
```

**Grålistade (> greylist_days gamla):**
```
Sender: unknown@example.com
Mottagen: 2026-05-01T10:00:00Z
Today: 2026-06-03T10:00:00Z
Age: 33 dagar > greylist_days (30)
Action: IMAP DELETE + EXPUNGE
```

**Svartlistade:**
```
Sender: spam@evil.com
Status: IMMEDIATE DELETE
Action: IMAP DELETE + EXPUNGE
```

---

## Felhantering

### Try-Catch-struktur

```php
try {
    $settings = load_settings();
    $password = decrypt_password($settings);
    $imap = connect_imap($settings, $password);
    $messages = fetch_messages($imap);
    
    foreach ($messages as $msg) {
        apply_filters($msg, $settings);
    }
    
    log_event("CRON_COMPLETE: OK");
    
} catch (SettingsException $e) {
    log_event("ERROR: " . $e->getMessage());
    exit(1);
} catch (IMAPException $e) {
    log_event("ERROR: IMAP connection failed: " . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    log_event("ERROR: Unexpected error: " . $e->getMessage());
    exit(1);
}
```

---

## Loggrotation

**Filnamn-format:**
```
~/.manjo/logs/mailfilter_abc123xyz_YYYY-MM.log
```

**Exempel:**
```
mailfilter_abc123xyz_2026-06.log  (Juni 2026)
mailfilter_abc123xyz_2026-07.log  (Juli 2026)
mailfilter_abc123xyz_2026-08.log  (Augusti 2026)
```

**Logic:**
```php
$current_month = date('Y-m');
$log_file = getenv('HOME') . "/.manjo/logs/mailfilter_{$script_id}_{$current_month}.log";

// Append mode, aldrig overwrite
file_put_contents($log_file, $entry . "\n", FILE_APPEND);
```

**Användarens ansvar:** Radera gamla filer manuellt om behövligt.

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-29
