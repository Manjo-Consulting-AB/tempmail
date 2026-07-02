# FILE_GENERATION.md - Generering av Nedladdningsbara Filer

## Syfte

När en användare skapar ett nytt mailfilter-script på manjo.me ska tre filer kunna laddas ner:

1. `mailfilter_{script_id}.json` — settings-template (med placeholders)
2. `mailfilter_{script_id}.php` — huvudscriptet (identiskt för alla användare förutom script_id i filnamnet)
3. `setup_mailfilter.php` — krypteringsverktyget (helt identiskt för alla, kan vara en statisk fil som inte behöver genereras per user)

---

## Fil 1: settings-template (JSON)

Genereras dynamiskt per script_id. Innehåller redan kända värden (script_id, public_key) och placeholders för det användaren själv ska fylla i.

```php
function generate_settings_template($script_id, $public_key_pem) {
    $template = [
        "script_id" => $script_id,
        "api_version" => "1.0",

        "imap" => [
            "user_email" => "FYLL_I_DIN_EPOSTADRESS",
            "host" => "FYLL_I_IMAP_SERVER",
            "port" => 993,
            "password" => "FYLL_I_LÖSENORD_HÄR",
            "password_encrypted" => false,
            "mailbox" => "INBOX"
        ],

        "security" => [
            "public_key" => $public_key_pem
        ],

        "filters" => [
            "whitelist" => [],
            "blacklist" => []
        ],

        "greylist" => [
            "enabled" => true,
            "days" => 30
        ],

        "runtime" => [
            "logging_enabled" => true,
            "last_run" => null,
            "last_webhook_timestamp" => "1970-01-01T00:00:00Z"
        ]
    ];

    return json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
```

**Viktigt**: whitelist/blacklist/greylist.days i denna fil är bara start-värden. Den verkliga källan till sanning för listorna är manjo.me:s databas (se LIST_MANAGEMENT_API.md) — dessa synkas till scriptet via webhook efter att användaren satt upp sitt konto och gjort sina första liständringar.

---

## Fil 2: Huvudscriptet (PHP)

Detta är **samma kod för alla användare** — den enda skillnaden är filnamnet (`mailfilter_{script_id}.php`), som scriptet själv läser av för att veta sitt eget script_id (se den tidigare levererade dokumentationen `PHP_FUNCTIONS.md` för fullständig kod).

**Genereringslogik på manjo.me**:

```php
function generate_main_script($script_id) {
    $template_path = '/path/to/static/mailfilter_template.php';
    $content = file_get_contents($template_path);
    
    // Filen behöver INTE innehålla script_id hårdkodat, eftersom
    // scriptet läser sitt eget filnamn vid körning (basename(__FILE__)).
    // Vi byter bara ut filnamnet vid nedladdning.
    
    return $content;
}

function get_download_filename($script_id) {
    return "mailfilter_{$script_id}.php";
}
```

**Rekommendation**: Håll en (1) statisk mall-fil (`mailfilter_template.php`) i kodbasen på manjo.me som uppdateras vid behov (buggfixar, nya features). Alla nedladdningar serveras från samma mall — script_id-specifikt är bara filnamnet.

**Versionshantering**: Om mallen uppdateras i framtiden bör befintliga användare kunna se "Din scriptversion är X, senaste är Y — ladda ner uppdaterad fil" i sitt konto-UI. Detta är en framtida funktion, inte ett krav för v1.

---

## Fil 3: setup_mailfilter.php

Helt statisk fil (se tidigare levererad `SETUP_SCRIPT.md`), identisk för alla användare. Behöver inte genereras dynamiskt — kan serveras direkt som statisk nedladdning.

---

## Nedladdningsflöde (UI-perspektiv)

```
1. User klickar "Skapa nytt mailfilter-script" på manjo.me
2. User fyller i: label (t.ex. "Min jobbmail"), target_host (t.ex. "manjo.se")
3. Backend:
   a. Genererar script_id + RSA-keypair (se KEY_MANAGEMENT.md)
   b. Sparar rad i mailfilter_scripts
   c. Genererar settings-template (Fil 1)
4. Frontend visar "Ladda ner dina filer":
   - [Ladda ner settings.json]
   - [Ladda ner mailfilter_{script_id}.php]
   - [Ladda ner setup_mailfilter.php]
5. Frontend visar installationsinstruktioner (se IMPLEMENTATION_DOCS/SETUP_SCRIPT.md 
   för exakt instruktionstext som redan finns framtagen för slutanvändaren)
```

---

## API-Endpoints som krävs

### POST /api/mailfilter/create

**Request**:
```json
{
  "label": "Min jobbmail",
  "target_host": "manjo.se",
  "target_path": "/mailfilter_{script_id}.php"
}
```

Notera: `target_path` kan sättas av användaren efter att de sett sitt genererade script_id (eftersom filnamnet beror på det), eller uppdateras i ett efterföljande steg/PATCH.

**Response**:
```json
{
  "script_id": "abc123xyz",
  "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
  "download_urls": {
    "settings_json": "/api/mailfilter/abc123xyz/download/settings",
    "main_script": "/api/mailfilter/abc123xyz/download/script",
    "setup_script": "/api/mailfilter/download/setup"
  }
}
```

### GET /api/mailfilter/{script_id}/download/settings

Returnerar `mailfilter_{script_id}.json` som nedladdningsbar fil (Content-Disposition: attachment).

### GET /api/mailfilter/{script_id}/download/script

Returnerar `mailfilter_{script_id}.php` som nedladdningsbar fil.

### GET /api/mailfilter/download/setup

Returnerar den statiska `setup_mailfilter.php`.

---

## Checklista för Implementering

- [ ] Settings-template-generator med korrekt placeholders
- [ ] Statisk mall för huvudscript (en fil att underhålla, inte per-user-kod)
- [ ] Statisk setup_mailfilter.php tillgänglig för nedladdning
- [ ] POST /api/mailfilter/create implementerad
- [ ] GET download-endpoints implementerade med korrekt Content-Disposition-headers
- [ ] target_host/target_path kan sättas/uppdateras av användaren efter skapande

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
