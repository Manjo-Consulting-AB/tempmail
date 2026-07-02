# KEY_MANAGEMENT.md - RSA-Nyckelgenerering & Lagring

## Syfte

Varje script_id (= varje mailkonto en användare kopplar) ska ha sitt **eget unika RSA-4096-keypair**. Detta isolerar risken: om en nyckel någonsin läcker påverkas bara ett script, inte alla användares konton.

---

## Generering av script_id

**Krav**: Unikt, URL-säkert, inte gissningsbart, lämpligt som filnamnsdel.

**Förslag**:
```php
function generate_script_id() {
    // 12 tecken, lowercase alfanumeriskt, kryptografiskt slumpmässigt
    $bytes = random_bytes(9);
    $id = rtrim(strtr(base64_encode($bytes), '+/', 'ab'), '=');
    $id = strtolower(preg_replace('/[^a-z0-9]/', '', $id));
    return substr($id, 0, 12);
}
```

**Kollisionskontroll**: Innan script_id sparas, kontrollera mot `mailfilter_scripts.script_id` att det inte redan finns. Om kollision (osannolikt men möjligt), generera om.

---

## RSA-Keypair-generering

**Krav**: 4096-bit RSA, SHA-256 för signering.

```php
function generate_rsa_keypair() {
    $config = [
        "private_key_bits" => 4096,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ];

    $res = openssl_pkey_new($config);

    if (!$res) {
        throw new Exception("RSA key generation failed: " . openssl_error_string());
    }

    openssl_pkey_export($res, $private_key);

    $key_details = openssl_pkey_get_details($res);
    $public_key = $key_details['key'];

    return [
        'private_key' => $private_key,
        'public_key' => $public_key,
    ];
}
```

---

## Lagring av Privat Nyckel (Kritiskt)

**Krav**: Den privata nyckeln får ALDRIG:
- Exponeras via något API-svar
- Loggas i klartext
- Visas i något UI (inte ens för administratörer)
- Lagras i klartext i databasen

**Rekommenderad lagring**: Kryptera den privata nyckeln med en master-key innan den sparas i `mailfilter_scripts.rsa_private_key`.

```php
function encrypt_private_key_for_storage($private_key_pem, $master_key) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt(
        $private_key_pem,
        'aes-256-cbc',
        $master_key,
        0,
        $iv
    );
    return base64_encode(json_encode([
        'cipher' => $encrypted,
        'iv' => base64_encode($iv)
    ]));
}
```

**Master-key**: Bör hämtas från en secrets manager / miljövariabel, ALDRIG hårdkodad i kod eller incheckad i Git.

**Dekryptering** sker endast vid signeringstillfället (se WEBHOOK_SENDER.md), och resultatet hålls bara i minnet under signeringsoperationen.

---

## Lagring av Publik Nyckel

Publik nyckel är **inte hemlig** — den distribueras öppet till användarens script och inkluderas i settings.json. Lagras i klartext i `mailfilter_scripts.rsa_public_key`.

---

## Flöde vid Skapande av Nytt Script

```
1. User initierar "Lägg till nytt mailkonto" på manjo.me
2. Backend:
   a. Generera script_id (unikt)
   b. Generera RSA-4096 keypair
   c. Kryptera privat nyckel med master-key
   d. Spara rad i mailfilter_scripts:
      - script_id
      - rsa_public_key (klartext)
      - rsa_private_key (krypterad)
      - user_id
      - target_host, target_path (fylls i av användaren senare)
3. Returnera till frontend: script_id + publik nyckel
   (privat nyckel returneras ALDRIG till frontend)
4. Trigga fil-generering (se FILE_GENERATION.md)
```

---

## Nyckelrotation (Framtida funktion, bör finnas som möjlighet)

Om en användare misstänker att deras script är komprometterat, ska de kunna trigga regenerering:

```
1. User klickar "Regenerera nycklar" på manjo.me
2. Backend genererar nytt RSA-keypair för befintligt script_id
3. Gammal publik nyckel ersätts i databasen
4. Ny settings.json (med ny publik nyckel) måste laddas ner och 
   manuellt uppdateras av användaren på sin server
   (eftersom scriptet inte kan lita på en webhook för att byta ut 
   sin egen public_key — kräver manuell åtgärd för säkerhets skull)
```

**Viktigt**: Nyckelbyte kan INTE ske via webhook, eftersom webhooken signeras med den gamla nyckeln men scriptet skulle behöva verifiera med den nya. Detta måste vara en manuell fil-utbytesprocess.

---

## Checklista för Implementering

- [ ] script_id-generator med kollisionskontroll
- [ ] RSA-4096 keypair-generering
- [ ] Master-key hanteras via secrets manager (ej hårdkodad)
- [ ] Privat nyckel krypteras innan databaslagring
- [ ] Privat nyckel exponeras ALDRIG via API/UI/loggar
- [ ] Publik nyckel returneras vid script-skapande
- [ ] Nyckelrotation-funktion (manuell fil-ombyte, ej webhook)

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
