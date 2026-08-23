# 2FA_DESIGN.md — Tvåfaktorsinloggning (TOTP) för TempMail Pro

Statusdokument för införandet av TOTP-baserad 2FA på manjo.me. Målet är att en
Pro-användare som loggar in med lösenord ska kunna skydda sitt konto med en
authenticator-app — 1Password, Authy, Google Authenticator, Microsoft
Authenticator, Bitwarden.

Dokumentet är specifikationen som issue-nedbrytningen utgår ifrån. Ändras
designen ska den ändras här först.

## 1. Omfattning och grundprincip

**Grundprincipen**: magic link är redan en tvåfaktorsinloggning. Den kräver
kunskap om adressen *och* åtkomst till inkorgen — två oberoende faktorer. En
kodfråga ovanpå den skyddar ingenting nytt. Lösenordsinloggning har bara en
faktor, och det är den luckan TOTP täpper till.

**Ingår**
- TOTP enligt RFC 6238 (HMAC-SHA1, 6 siffror, 30 sekunders period) — den
  parameterkombination alla större authenticator-appar stödjer.
- 2FA-utmaning **enbart vid lösenordsinloggning**.
- Aktivering/avaktivering från profilsidan, med QR-kod och manuell nyckel.
- Engångskoder som offline-reserv.
- Valfri "kom ihåg den här webbläsaren i 30 dagar" (trusted device) för
  lösenordsinloggningen.

**Ingår inte**
- Kodfråga vid magic link-inloggning. Se grundprincipen ovan.
- WebAuthn/passkeys. 1Password och Authy hanterar TOTP, vilket är det
  användaren efterfrågat. Passkeys kan byggas senare på samma sessionsmodell.
- SMS-baserad 2FA (svag faktor, kostnad, SIM-swap).
- 2FA för `pro_feed.php` (token-baserad RSS) och client agent-API:t. De
  autentiserar med egna hemligheter och berörs inte.
- Nyckelrotation av krypteringsnyckeln för TOTP-hemligheter.

### 1.1 Konsekvenser av avgränsningen

Två följder är avsiktliga och ska inte "fixas" utan att designen ändras här
först:

1. **Magic link går alltid förbi kodfrågan.** En användare med både lösenord
   och TOTP kan när som helst begära en inloggningslänk och komma in utan kod.
   Skyddsnivån är densamma — inkorgen är den andra faktorn — men den som vill
   ha en hård kodfråga på varje inloggning får inte det med den här modellen.
2. **Ingen kan låsa ute sig själv.** Tappad telefon och förlorade engångskoder
   räcker inte för utelåsning: magic link fungerar fortfarande, och därifrån
   kan användaren stänga av 2FA i profilen. Det är därför ingen fördröjd
   återställningsprocess behövs.

## 2. Datamodell

Tabellerna skapas med `CREATE TABLE IF NOT EXISTS` i en `ensureSchema()`-rutin,
samma mönster som `magic_link_requests` i `pro_auth.php` och
`imap_refresh_state` i `config.php`. Ingen separat migrationsprocess finns i
projektet.

### `pro_user_totp`
En rad per användare som påbörjat eller aktiverat 2FA.

| Fält | Typ | Beskrivning |
|---|---|---|
| `user_id` | INT, PK | FK → `pro_users.id` (logisk, ingen constraint) |
| `secret_enc` | TEXT | TOTP-hemlighet, AES-256-GCM-krypterad, base64(iv‖tag‖ciphertext) |
| `status` | ENUM('pending','active') | `pending` = påbörjad aktivering, ej bekräftad med kod |
| `last_used_step` | BIGINT NULL | Senast godkänt tidssteg — replayskydd |
| `confirmed_at` | DATETIME NULL | När aktiveringen bekräftades |
| `created_at` / `updated_at` | DATETIME | |

### `pro_user_recovery_codes`
| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `user_id` | INT, index | |
| `code_hash` | VARCHAR(255) | `password_hash()` av koden — koden lagras aldrig i klartext |
| `used_at` | DATETIME NULL | Sätts när koden förbrukats (engångskod) |
| `created_at` | DATETIME | |

### `two_factor_attempts`
Brute force-skydd för utmaningen, spegling av `login_attempts`.

| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `user_id` | INT NULL | |
| `ip` | VARCHAR(45) | |
| `success` | TINYINT(1) | |
| `attempt_at` | DATETIME | index (ip, attempt_at), (user_id, attempt_at) |

### `pro_trusted_devices`
| Fält | Typ | Beskrivning |
|---|---|---|
| `id` | INT, PK | |
| `user_id` | INT, index | |
| `selector` | CHAR(32), UNIQUE | Slås upp med, ej hemlig |
| `validator_hash` | CHAR(64) | SHA-256 av validator-delen av cookien |
| `label` | VARCHAR(255) NULL | Kort sammanfattning av User-Agent, för listan i profilen |
| `created_at` / `last_used_at` | DATETIME | |
| `expires_at` | DATETIME | 30 dagar |

## 3. Kryptering av TOTP-hemligheten

Hemligheten är ett lösenordsekvivalent och krypteras i vila med AES-256-GCM och
nyckeln i miljövariabeln `TOTP_ENCRYPTION_KEY` (32 slumpade bytes, base64 i
`.env.{miljö}`).

Till skillnad från `encrypt_webhook_secret()` i `pro_profile.php` får det
**inte** finnas någon fallback till klartext. Saknas nyckeln ska aktivering
avvisas med ett tydligt fel och `logMessage('ERROR', ...)`, och verifiering av
redan aktiverad 2FA ska misslyckas stängt (användaren hänvisas till
engångskoder eller magic link) i stället för att jämföra mot något okrypterat.

Nyckeln får aldrig loggas. Hemligheten får aldrig loggas, aldrig returneras
från något API efter att aktiveringen bekräftats, och aldrig skickas i e-post.

## 4. Sessionsmodell

Idag sätts `$_SESSION['pro_user_id']` direkt när lösenordet eller magic
link-token validerats. Magic link-vägen behåller det beteendet oförändrat. För
lösenordsinloggning införs ett mellanläge:

```
$_SESSION['pending_2fa'] = [
    'user_id'    => (int),
    'email'      => (string),
    'created_at' => (int) time(),
];
```

Regler:
- `pro_user_id` sätts **först** när koden är verifierad. Allt som skyddar
  Pro-ytor läser bara `pro_user_id` — mellanläget ger noll åtkomst.
- Mellanläget lever i 10 minuter, därefter måste inloggningen göras om.
- Vid lyckad kod: `session_regenerate_id(true)`, sätt `pro_user_id` och
  `pro_user_email`, ta bort `pending_2fa`.
- Mellanläget uppstår aldrig i magic link-flödet.

## 5. Flöden

### 5.1 Aktivering
1. Användaren öppnar Säkerhet-sektionen i profilen och startar aktivering.
2. Servern genererar 20 slumpade bytes, base32-kodar dem, sparar krypterat med
   `status='pending'` och returnerar `otpauth://`-URI, en server-renderad
   QR-kod (SVG) och den grupperade nyckeln för manuell inmatning.
3. Användaren skannar i sin app och skickar in en kod.
4. Servern verifierar koden. Vid träff: `status='active'`, `confirmed_at`,
   `last_used_step`, och 10 engångskoder genereras och visas **en enda gång**.
5. Bekräftelsemail till kontots adress: "2FA aktiverades på ditt konto".

UI:t ska vara tydligt med att koden krävs vid inloggning med lösenord, och att
inloggningslänk via e-post fortsätter fungera som förut.

`otpauth`-URI: `otpauth://totp/TempMail%20(manjo.me):{email}?secret={BASE32}&issuer=TempMail%20(manjo.me)&algorithm=SHA1&digits=6&period=30`

### 5.2 Inloggning med lösenord
1. `password_login` verifierar lösenordet som idag.
2. Har användaren aktiv 2FA och ingen giltig trusted device-cookie: sätt
   `pending_2fa`, svara `{"success":true,"requires_2fa":true}` — ingen session.
3. Klienten visar kodfältet. `verify_2fa` tar emot koden.
4. Vid träff: full session enligt §4. Vid miss: räkna upp
   `two_factor_attempts`, generiskt felmeddelande.

### 5.3 Inloggning med magic link
Oförändrad. Länken är i sig två faktorer (kunskap om adressen + åtkomst till
inkorgen), och ger full session direkt även för konton med TOTP aktiverat.
Ingen kodfråga, inget mellanläge.

### 5.4 Engångskoder
Sekundär reserv — magic link är den primära vägen in när telefonen inte finns
till hands. Koderna finns för det fall då även e-posten är otillgänglig.

- 10 koder, 10 tecken ur Crockford-base32 utan tvetydiga tecken, visade som
  `XXXXX-XXXXX`.
- Hashade med `password_hash()`, jämförs med `password_verify()` mot samtliga
  oanvända koder för användaren.
- Förbrukas vid användning (`used_at`). Kvarvarande antal visas i profilen och
  användaren varnas när det är ≤ 3 kvar.
- Nya koder kan genereras men kräver en giltig TOTP-kod och ersätter alla gamla.

### 5.5 Avaktivering
Kräver lösenord (den som har 2FA har per definition ett lösenord — det är
lösenordsinloggningen 2FA skyddar). Vid avaktivering: radera TOTP-raden, alla
engångskoder och alla trusted devices, och skicka notismail.

Har användaren tappat sin authenticator loggar hen in med magic link och
stänger av 2FA här. Ingen separat återställningsprocess behövs.

## 6. Brute force och missbruk

| Yta | Gräns | Åtgärd vid överskridande |
|---|---|---|
| `verify_2fa` per user_id | 5 misslyckade / 15 min | Blockera 15 min, generiskt fel |
| `verify_2fa` per IP | 15 misslyckade / 15 min | Blockera + `flagMaliciousActivity()` |
| `totp_begin_enroll` per user | 10 / timme | Avvisa |
| Engångskod | Ingår i samma räknare som TOTP-koder | |

Övrigt:
- Kodjämförelse med `hash_equals()`, aldrig `==`.
- Driftfönster ±1 steg (±30 s). Ett tidssteg som redan använts godkänns aldrig
  igen (`last_used_step`).
- Felmeddelanden avslöjar aldrig om det var koden, kontot eller spärren som
  fällde försöket.
- Alla POST-endpoints bakom `requireSameOriginRequest()`.
- Den befintliga rate limiten på `request_login_link` blir viktigare med den
  här modellen, eftersom magic link är vägen förbi kodfrågan. Den ska inte
  försvagas.

## 7. Kompatibilitet

Verifieras mot minst: 1Password, Authy, Google Authenticator, Microsoft
Authenticator, Bitwarden. Alla stödjer SHA1/6/30 via `otpauth://`-URI. Avvik
inte från de parametrarna — SHA256 och 8 siffror stöds inte överallt.

## 8. Miljövariabler

| Variabel | Beskrivning |
|---|---|
| `TOTP_ENCRYPTION_KEY` | 32 bytes, base64. Obligatorisk för att 2FA ska kunna aktiveras. |

Läggs till i variabellistan i `CLAUDE.md` och kontrolleras av
`prod_diagnostics.php`. Värden checkas aldrig in.

## 9. Loggning

`logMessage()` med händelserna `2fa_enroll_started`, `2fa_enabled`,
`2fa_disabled`, `2fa_challenge_failed`, `2fa_challenge_locked`,
`2fa_recovery_code_used`, `2fa_recovery_codes_regenerated`,
`2fa_trusted_device_added`, `2fa_trusted_devices_revoked`. Kontext får
innehålla `user_id`, IP och utfall — aldrig hemlighet, kod eller nyckel.
