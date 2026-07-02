# WEBHOOK_SENDER.md - Signering & Utskick av Webhooks

## Syfte

Beskriver hur manjo.me ska konstruera, signera och skicka webhook-anrop till användarens script (`mailfilter_{script_id}.php?action=update_lists`). Mottagarsidans verifieringslogik finns redan implementerad (se tidigare levererad `API_SPECIFICATION.md` och `PHP_FUNCTIONS.md`) — detta dokument beskriver bara sändar-sidan.

---

## Payload-konstruktion

```php
function build_webhook_payload($script_id, $whitelist_patterns, $blacklist_patterns, $greylist_days) {
    return [
        "api_version" => "1.0",
        "script_id" => $script_id,
        "timestamp" => gmdate('Y-m-d\TH:i:s\Z'), // UTC, ISO 8601
        "action" => "update_lists",
        "data" => [
            "whitelist" => $whitelist_patterns,
            "blacklist" => $blacklist_patterns,
            "greylist_days" => $greylist_days
        ]
    ];
}
```

**Viktigt**: `whitelist`/`blacklist` ska skickas som platta arrayer av strängar (mönster), inte som objekt med id/added_at — mottagarscriptet bryr sig bara om mönstren.

```php
$whitelist_patterns = array_column($whitelist_rows, 'pattern');
$blacklist_patterns = array_column($blacklist_rows, 'pattern');
```

---

## Signering

**Kritiskt**: JSON-serialiseringen måste använda exakt samma flaggor som mottagarscriptet använder vid rekonstruktion, annars misslyckas signaturverifieringen.

```php
function sign_payload($payload, $encrypted_private_key, $master_key) {
    // 1. Dekryptera privat nyckel (endast i minnet, för denna operation)
    $private_key_pem = decrypt_private_key($encrypted_private_key, $master_key);

    // 2. Serialisera payload (UTAN signature-fältet ännu)
    $json_payload = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS
    );

    // 3. Signera
    $signature = '';
    $success = openssl_sign(
        $json_payload,
        $signature,
        $private_key_pem,
        'sha256WithRSAEncryption'
    );

    // 4. Nollställ privat nyckel ur minnet så snart som möjligt
    unset($private_key_pem);

    if (!$success) {
        throw new Exception("Failed to sign webhook payload: " . openssl_error_string());
    }

    // 5. Lägg till signatur i payload
    $payload['signature'] = base64_encode($signature);

    return $payload;
}

function decrypt_private_key($encrypted_data, $master_key) {
    $decoded = json_decode(base64_decode($encrypted_data), true);
    $iv = base64_decode($decoded['iv']);

    $private_key_pem = openssl_decrypt(
        $decoded['cipher'],
        'aes-256-cbc',
        $master_key,
        0,
        $iv
    );

    if ($private_key_pem === false) {
        throw new Exception("Failed to decrypt private key");
    }

    return $private_key_pem;
}
```

---

## Utskick (HTTP POST)

```php
function send_webhook($script_id, $target_host, $target_path, $signed_payload) {
    $url = "https://{$target_host}{$target_path}?action=update_lists";

    $json_body = json_encode(
        $signed_payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // sekunder
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // ALDRIG false i produktion
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($http_code === 200),
        'http_code' => $http_code,
        'response_body' => $response_body,
        'error' => $curl_error ?: null
    ];
}
```

---

## Fullständigt Flöde

```php
function trigger_webhook_for_script($script_id) {
    // 1. Hämta script-data
    $script = get_mailfilter_script($script_id); // från DB
    $whitelist = get_whitelist_patterns($script_id);
    $blacklist = get_blacklist_patterns($script_id);

    // 2. Bygg payload
    $payload = build_webhook_payload(
        $script_id,
        $whitelist,
        $blacklist,
        $script['greylist_days']
    );

    // 3. Signera
    $master_key = get_master_key_from_secrets_manager();
    $signed_payload = sign_payload($payload, $script['rsa_private_key'], $master_key);

    // 4. Skicka
    $result = send_webhook(
        $script_id,
        $script['target_host'],
        $script['target_path'],
        $signed_payload
    );

    // 5. Logga resultatet
    log_webhook_attempt($script_id, $payload['timestamp'], $result);

    // 6. Uppdatera script-raden om lyckad
    if ($result['success']) {
        update_script_webhook_status($script_id, $payload['timestamp']);
    }

    return $result;
}
```

---

## Felhantering & Retry

**Princip**: Om webhooken misslyckas (timeout, 4xx/5xx, DNS-fel) ska manjo.me INTE retry:a aggressivt i onödan — det strider mot kravet om minimal trafik och risk för att belasta användarens server om den är nere.

**Rekommendation**:
- Vid fel: logga i `mailfilter_webhook_log` med felmeddelande
- Visa tydligt i UI: "Senaste synk misslyckades: [tidpunkt, felmeddelande]"
- Ge användaren en manuell "Försök igen"-knapp (kopplad till `/sync`-endpointen, se LIST_MANAGEMENT_API.md)
- INGEN automatisk bakgrunds-retry-loop som upprepade gånger anropar användarens server

**Anledning**: Eftersom själva mailfiltreringen sker autonomt via cron på användarens server oavsett om webhooken kommer fram, är konsekvensen av en missad webhook bara att listorna är "gamla" tills nästa lyckade synk — inte kritiskt nog för aggressiv retry-logik.

---

## Loggning (mailfilter_webhook_log)

Varje försök, lyckat eller ej, ska skapa en rad:

```php
function log_webhook_attempt($script_id, $payload_timestamp, $result) {
    insert_row('mailfilter_webhook_log', [
        'script_id' => $script_id,
        'sent_at' => gmdate('Y-m-d H:i:s'),
        'payload_timestamp' => $payload_timestamp,
        'http_status_code' => $result['http_code'],
        'response_body' => substr($result['response_body'] ?? '', 0, 2000), // trunkera
        'success' => $result['success'],
        'error_message' => $result['error']
    ]);
}
```

---

## Checklista för Implementering

- [ ] Payload-konstruktion med korrekt JSON-flaggor (matchar mottagarsidan exakt)
- [ ] Privat nyckel dekrypteras endast tillfälligt i minnet, aldrig loggad
- [ ] RSA-signering med SHA256
- [ ] HTTPS-only utskick, SSL-verifiering aktiverad (verifyPeer/verifyHost = true)
- [ ] Timeout satt rimligt (10–15 sekunder), ingen aggressiv retry
- [ ] Alla försök loggas i mailfilter_webhook_log
- [ ] UI visar senaste synk-status och felmeddelande vid behov
- [ ] Manuell "Försök igen"-funktion kopplad till /sync-endpoint

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
