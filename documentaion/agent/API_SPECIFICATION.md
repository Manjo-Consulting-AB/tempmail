# API_SPECIFICATION.md - Webhook & API-Specifikation

## Webhook: Update Lists

### Request Format

**URL:**
```
POST https://user.example.se/mailfilter_abc123xyz.php?action=update_lists
```

**Headers:**
```
Content-Type: application/json
```

**Method:** POST

---

### Request Body (JSON)

```json
{
  "api_version": "1.0",
  "script_id": "abc123xyz",
  "timestamp": "2026-06-29T14:30:00Z",
  "action": "update_lists",
  "data": {
    "whitelist": [
      "tony@example.com",
      "boss@company.com",
      "@trusted-domain.com"
    ],
    "blacklist": [
      "spam@evil.com",
      "ads@spammer.net",
      "@unwanted-newsletter.org"
    ],
    "greylist_days": 30
  },
  "signature": "base64_encoded_rsa_signature_here..."
}
```

**Fältbeskrivningar:**

| Fält | Typ | Beskrivning |
|------|-----|-------------|
| `api_version` | string | "1.0" - versionering för framtida kompatibilitet |
| `script_id` | string | Måste matcha `settings.json` script_id |
| `timestamp` | ISO 8601 | UTC-tid när webhook skapades |
| `action` | string | "update_lists" - typ av webhook |
| `data.whitelist` | array | Array av email/domäner att vitlista (kan innehålla wildcards) |
| `data.blacklist` | array | Array av email/domäner att svartlista |
| `data.greylist_days` | integer | Antal dagar för grålist innan radering |
| `signature` | string | RSA-SHA256 signatur över payload (utan signature-fältet) |

---

### Wildcard-stöd

Både whitelist och blacklist kan innehålla wildcards för domäner:

```json
{
  "whitelist": [
    "tony@example.com",           // Exakt email
    "@trusted-domain.com",        // Alla från domain
    "manager@*.company.com"       // Wildcard i subdomän (optional)
  ]
}
```

**Matching-logik:**
```php
function matches_pattern($sender_email, $pattern) {
    if (strpos($pattern, '@') === 0) {
        // Domain pattern: @domain.com
        $domain = substr($sender_email, strrpos($sender_email, '@') + 1);
        $pattern_domain = substr($pattern, 1);
        return strtolower($domain) === strtolower($pattern_domain);
    } elseif (strpos($pattern, '*') !== false) {
        // Wildcard pattern: manager@*.company.com
        $pattern = str_replace('*', '.*', preg_quote($pattern));
        return preg_match("/^$pattern$/i", $sender_email);
    } else {
        // Exact match
        return strtolower($sender_email) === strtolower($pattern);
    }
}
```

---

## RSA-Signering (Detaljerad)

### 1. Signering (Manjo.me)

**Steg-för-steg:**

```php
// 1. Konstruera payload (utan signature-fältet ännu)
$payload = [
    "api_version" => "1.0",
    "script_id" => "abc123xyz",
    "timestamp" => date('c'),  // ISO 8601 format
    "action" => "update_lists",
    "data" => [
        "whitelist" => [...],
        "blacklist" => [...],
        "greylist_days" => 30
    ]
];

// 2. Serialisera JSON med specifika flags för konsistens
$json_payload = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS
);

// 3. Läs privat nyckel från användarens profil på manjo.me
$private_key = file_get_contents('/path/to/user/private_key.pem');

// 4. Signera med RSA-SHA256
$signature = '';
$success = openssl_sign(
    $json_payload,
    $signature,
    $private_key,
    'sha256WithRSAEncryption'
);

if (!$success) {
    throw new Exception("Failed to sign webhook payload");
}

// 5. Lägg till signature till payload
$payload['signature'] = base64_encode($signature);

// 6. Skicka som JSON
$final_json = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

// 7. POST till script
$ch = curl_init("https://user.example.se/mailfilter_abc123xyz.php?action=update_lists");
curl_setopt($ch, CURLOPT_POSTFIELDS, $final_json);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_exec($ch);
```

---

### 2. Verifiering (Script)

**Steg-för-steg:**

```php
<?php
// 1. Läs raw POST body
$raw_input = file_get_contents('php://input');
$payload = json_decode($raw_input, true);

if (!$payload) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Invalid JSON"]));
}

// 2. Validera required fält
if (empty($payload['script_id']) || empty($payload['signature'])) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Missing required fields"]));
}

// 3. Ladda settings.json
$script_id = $payload['script_id'];
$settings_file = getenv('HOME') . "/.manjo/mailfilter_{$script_id}.json";

if (!file_exists($settings_file)) {
    log_event("WEBHOOK_ERROR: Settings file not found for script_id={$script_id}");
    http_response_code(404);
    die(json_encode(["status" => "error", "message" => "Script not found"]));
}

$settings = json_decode(file_get_contents($settings_file), true);

// 4. Extrahera och ta bort signature från payload
$received_signature = base64_decode($payload['signature']);
unset($payload['signature']);

// 5. Rekonstruera JSON exakt samma sätt (KRITISKT!)
$json_payload = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS
);

// 6. Hämta public key från settings
$public_key = $settings['security']['public_key'];

// 7. Verifiera signatur
$verified = openssl_verify(
    $json_payload,
    $received_signature,
    $public_key,
    'sha256WithRSAEncryption'
);

if ($verified !== 1) {
    log_event("WEBHOOK_ERROR: Signature verification failed for script_id={$script_id}");
    http_response_code(401);
    die(json_encode(["status" => "error", "message" => "Signature verification failed"]));
}

// 8. Signatur OK - fortsätt med timestamp-check
log_event("WEBHOOK: Signature verified successfully");
// ... resten av logiken
?>
```

---

## Anti-Replay-Skydd

### Timestamp-validering

**Regler:**
1. Timestamp får inte vara äldre än 1 timme
2. Timestamp får inte vara <= `last_webhook_timestamp` i settings.json
3. Framtida timestamps > 5 minuter tillåts inte

```php
function validate_timestamp($received_timestamp_str, $last_webhook_timestamp_str) {
    $received_ts = strtotime($received_timestamp_str);
    $last_ts = strtotime($last_webhook_timestamp_str ?? '1970-01-01T00:00:00Z');
    $now = time();
    
    // Check 1: Inte äldre än 1 timme
    if ($now - $received_ts > 3600) {
        log_event("WEBHOOK_REJECTED: Timestamp too old (> 1 hour)");
        return false;
    }
    
    // Check 2: Inte framtida (> 5 minuter in i framtiden)
    if ($received_ts - $now > 300) {
        log_event("WEBHOOK_REJECTED: Timestamp is in the future");
        return false;
    }
    
    // Check 3: Inte <= förra webhook:s timestamp
    if ($received_ts <= $last_ts) {
        log_event("WEBHOOK_REJECTED: Replay attack detected (same/older timestamp)");
        return false;
    }
    
    return true;
}
```

**Uppdatering efter lyckad validering:**
```php
$settings['runtime']['last_webhook_timestamp'] = $payload['timestamp'];
file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
```

---

## Response Format

### HTTP 200 OK - Lyckat

```json
{
  "status": "ok",
  "message": "Lists updated successfully",
  "details": {
    "whitelist_count": 5,
    "blacklist_count": 3,
    "greylist_days": 30,
    "timestamp": "2026-06-29T14:30:00Z"
  }
}
```

### HTTP 400 Bad Request - Timestamp-fel

```json
{
  "status": "error",
  "message": "Timestamp validation failed",
  "reason": "Timestamp too old or already processed"
}
```

### HTTP 401 Unauthorized - Signatur-fel

```json
{
  "status": "error",
  "message": "Signature verification failed",
  "reason": "Invalid RSA signature or tampered data"
}
```

### HTTP 404 Not Found - Script inte funnet

```json
{
  "status": "error",
  "message": "Script not found",
  "reason": "script_id abc123xyz not found on this server"
}
```

### HTTP 500 Internal Server Error - Server-fel

```json
{
  "status": "error",
  "message": "Internal server error",
  "reason": "Failed to write settings.json"
}
```

---

## Webhook-validering Checklist

Scriptet måste validera i denna ordning:

- [ ] Request body är valid JSON
- [ ] Alla required fält är närvarande (api_version, script_id, timestamp, action, data, signature)
- [ ] script_id matchar en befintlig settings.json
- [ ] Signatur verifieras med public key från settings.json
- [ ] Timestamp är inte äldre än 1 timme
- [ ] Timestamp är inte framtida (> 5 min)
- [ ] Timestamp är > last_webhook_timestamp
- [ ] Data-fälten är korrekt formaterade
- [ ] settings.json kan uppdateras (skrivbehörighet)

---

## API Versioning

**api_version** tillåter framtida ändringar:

```php
$api_version = $payload['api_version'];

switch ($api_version) {
    case '1.0':
        // Current implementation
        break;
    case '2.0':
        // Hypothetical future version
        handle_v2($payload);
        break;
    default:
        http_response_code(400);
        die(json_encode(["status" => "error", "message" => "Unsupported API version"]));
}
```

---

## Future Extensions

Dessa webhooks kan läggas till senare:

```
?action=get_statistics
?action=delete_logs
?action=health_check
?action=set_config
```

Samma signerings- och validerings-mekanik gäller för alla.

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-29
