# SECURITY.md - Säkerhetsanalys

## Hot-Modell

### Vad skyddar vi emot?

| Hot | Mitigering | Risknivå |
|-----|-----------|----------|
| Manjo.me hacked → lösenord stöld | Lösenord sparas ALDRIG på manjo.me | ✓ Eliminerad |
| Webhook från falsk källa | RSA-signering + timestamp-check | ✓ Eliminerad |
| Replay-attack på webhook | Timestamp + anti-replay-logik | ✓ Eliminerad |
| Användarens server hacked → lösenord läst | Kryptering med lokal hemlig nyckel | ✓ Mitigerad (beroende på nyckelskydd) |
| Svagade IMAP-lösenord | Utanför vår kontroll (användarens ansvar) | ⚠ Extern risk |
| Man-in-the-Middle (MITM) på webhook | HTTPS + RSA-signering | ✓ Mitigerad |
| Hemlig nyckel stjälen från server | Sparas utanför www-root (chmod 0600) | ✓ Mitigerad |

---

## Säkerhetseggenskaper

### 1. Lösenord aldrig exponerat för manjo.me ✓

**Antagande**: Manjo.me kan hackas, men lösenorden är redan borta från början.

**Implementering**:
- Användar laddar ner tom settings.json **template**
- Användare fyller i lösenordet **lokalt** (aldrig skickat till manjo.me)
- Användare krypterar själv med `setup_mailfilter.php` (lokal körning)
- Manjo.me ser aldrig lösenordet i klartext

**Risker avslagna**:
- ✓ Databaskompromiss på manjo.me = ingen lösenord att stjäla
- ✓ Intern manjo.me-anställd kan inte se lösenord
- ✓ API-logg på manjo.me innehåller aldrig lösenord

---

### 2. RSA-signering av Webhooks ✓

**Antagande**: Manjo.me kan autentiseras med asymmetrisk kryptering.

**Implementering**:

```
1. Manjo.me genererar RSA-4096 keypair
2. Privat nyckel: Sparas på manjo.me (skyddad, ej exponerad för users)
3. Publik nyckel: Lagras i mailfilter_*.json (distribuerad med scriptet)
4. Webhook: Signeras med privat nyckel
5. Script: Verifierar med publik nyckel
```

**Säkerhetsfördel**:
- ✓ Only manjo.me kan skapa giltiga webhooks
- ✓ Publickey ligger synlig (inte hemlig) → OK att distribuera
- ✓ Även om scriptet hackad kan man inte skapa webhook utan privkey

**Möjlig attack**:
- ✗ Om manjo.me:s privat nyckel stjäls kan falska webhooks skapas
- **Mitigering**: Användaren kan regenerera RSA-nycklar i sitt settings

---

### 3. Anti-Replay-Skydd ✓

**Antagande**: Webhooks med samma timestamp är replays.

**Implementering**:

```php
// Lagrat värde från förra webhook
$last_webhook_timestamp = "2026-06-29T14:30:00Z"

// Ny webhook kommer med timestamp
$received_timestamp = "2026-06-29T14:31:00Z"

// Validering
if ($received_timestamp <= $last_webhook_timestamp) {
    REJECT!  // Samma eller äldre = replay
}
```

**Beskyddar mot**:
- ✓ Attacker replayer samma webhook 100 gånger
- ✓ MITM som fångar och replayer webhook senare

**Gränser**:
- ⚠ Förutsätter server-klocka är ungefär rätt
- ⚠ Timeout på 1 timme betyder gammla webhooks tillåts

---

### 4. Hemlig Nyckel utanför www-root ✓

**Antagande**: www-root är potentiellt exponerad, ~/.manjo inte.

**Implementering**:

```
/home/user/public_html/
    └─ mailfilter_abc123xyz.php      (www-root, potentiellt läsbar)

/home/user/.manjo/
    ├─ mailfilter_abc123xyz.json     (hemlig, chmod 0600)
    └─ mailfilter_abc123xyz.key      (hemlig, chmod 0600)
```

**Skydd**:
- ✓ Om www-root läses → script läses men inte nyckeln
- ✓ Script kan inte dekryptera utan nyckel
- ✓ chmod 0600 = bara ägaren kan läsa

**Svaghet**:
- ⚠ Om server kompromitterad kan både läsas
- **Mitigering**: Använd SSH-key för logins, disable root, firewall

---

### 5. HTTPS för Webhooks ✓

**Antagande**: All kommunikation från manjo.me → script är över HTTPS.

**Implementering**:
```
POST https://user.example.se/mailfilter_abc123xyz.php?action=update_lists
```

**Beskyddar mot**:
- ✓ MITM kan inte läsa webhook-payload (encrypted)
- ✓ MITM kan inte modifiera (signerad)

**Krav från hosting**:
- ✓ SSL-cert installerat
- ✓ HTTP omdirigerat till HTTPS

---

## Möjliga Angrepp & Svar

### Attack 1: Attacker hijackar subdomain

**Scenario**: Attacker får kontrolla över user.example.se, skapar egen webhook-handler.

**Försvar**:
- `public_key` från tidigare webhook matchar inte
- Script rej signature verification
- Attacker behöver manjo.me:s privat RSA-nyckel (omöjligt)

**Slutsats**: ✓ SKYDDAD

---

### Attack 2: Attacker stjäl hemlig nyckel från server

**Scenario**: Server hacks, ~/.manjo/mailfilter_*.key blir läsbar.

**Risker**:
- Attacker kan dekryptera lösenordet
- Attacker kan ansluta till IMAP och läsa mail

**Försvar**:
- Användarens systemsäkerhet (ssh-keys, firewall, etc)
- Regular backups så användaren kan regenerera

**Slutsats**: ⚠ RISK (men användarens systemsäkerhet, inte vår kod)

---

### Attack 3: Falska Webhooks från MITM

**Scenario**: MITM fångar webhook och modifierar payload.

**Försvar**:
- RSA-signatur blir ogiltig
- Script rej modifierad webhook

**Slutsats**: ✓ SKYDDAD

---

### Attack 4: Replay av gammal webhook

**Scenario**: Attacker får gammal webhook (från 3 dagar sedan), replayer den.

**Försvar**:
- Timestamp-check: "inte äldre än 1 timme"
- Anti-replay-logik: "timestamp måste > förra webhook"

**Slutsats**: ✓ SKYDDAD (om timestamp <= 1 timme gammal)

---

## Säkerhetskrav för Implementering

### PHP-Version
- **Krav**: PHP 7.4+ (för `openssl_verify()`)
- **Version**: PHP 8.x rekommenderad

### Extensions
- `openssl` - RSA-signering och dekryptering
- `imap` - IMAP-anslutning
- `json` - JSON-parsing

Verifiera:
```php
if (!extension_loaded('openssl')) {
    die("ERROR: OpenSSL extension not loaded");
}
```

### File Permissions

**Settings.json**:
```bash
chmod 0600 ~/.manjo/mailfilter_*.json
```
Endast ägare kan läsa.

**Hemlig nyckel**:
```bash
chmod 0600 ~/.manjo/mailfilter_*.key
```
Endast ägare kan läsa.

**Loggfiler**:
```bash
chmod 0600 ~/.manjo/logs/mailfilter_*.log
```
Endast ägare kan läsa (innehåller känslig info).

---

## OWASP Top 10 Mapping

| OWASP Top 10 | Sårbarhet | Vår Försvar |
|---|---|---|
| A1: Injection | SQL/Command injection i IMAP | Använder imap_*() API, ingen shell |
| A2: Broken Auth | Svagt lösenordskydd | Lokalt lagrat, krypterat |
| A3: Sensitive Data | Lösenord exponerat | Aldrig skickat till manjo.me |
| A4: XML External | Inte relevant | N/A |
| A5: Broken Access | Hemlig nyckel läsbar | chmod 0600, utanför www-root |
| A6: CSRF | Webhook-CSRF | RSA-signering + timestamp |
| A7: XSS | Inte relevant (CLI+API) | N/A |
| A8: Insecure Deserialization | Inte relevant | N/A |
| A9: Known Vulnerabilities | PHP extensions outdated | Använd senaste stabil version |
| A10: Logging & Monitoring | Ingen audit log | Vi loggar allt lokalt |

---

## Antaganden & Begränsningar

### Antaganden

1. **Manjo.me kan hackas** → Vi skyddar genom att aldrig exponera lösenord
2. **Användarens server kan hackas** → Vi skyddar hemlig nyckel utanför www-root
3. **Nätverket kan attackeras** → Vi skyddar med HTTPS + RSA-signering
4. **Klockskev på server** → Vi tillåter ±5 minuter för timestamp-check
5. **IMAP-lösenord är svagt** → Användarens ansvar (utanför vår kontroll)

### Begränsningar

1. **Hemlig nyckel på samma server** → Om server helt kompromitterad är allt läsbart
   - **Mitigering**: Använd SSH-keys för login, disable root, fail2ban

2. **Settings.json på samma server** → IMAP-host och email synlig
   - **Mitigering**: Inte känslig info (inte hemligt), bara metadata

3. **Loggar innehåller avsändares email** → Om loggfiler lästas finns info om mail
   - **Mitigering**: chmod 0600 på loggar, manuell borttagning

4. **Webhook-timestamp ±5 minuter slackness** → Kan exploateras av lokal attacker
   - **Mitigering**: Strikt tidscheck på servern

---

## Rekommendationer för Användare

### För Paranoid-Användare

- [ ] Använd `setup_mailfilter.php` för lokal kryptering
- [ ] Säkerställ hemlig nyckel är STARK (minst 32 tecken)
- [ ] Lagra hemlig nyckel på en SÄKER plats (t.ex. password manager)
- [ ] Backuppa: `~/.manjo/mailfilter_*.json` och `.key`
- [ ] Säkerställ SSH-nyckelbaserad login (disable password login)
- [ ] Installera fail2ban eller liknande
- [ ] Regelbundna säkerhetsupdateringar av PHP och OS

### För Vanliga Användare

- [ ] Acceptera att lösenord lagras i klartext (enkelt men mindre säkert)
- [ ] Säkerställ HTTPS fungerar på servern
- [ ] Regelbundna säkerhetsupdateringar

### For Both

- [ ] Lagra hemlig nyckel på flera platser (USB-sticka, password manager)
- [ ] Säkerställ backups av settings-filer
- [ ] Övervaka loggfiler för misstänkt aktivitet
- [ ] Ändra IMAP-lösenord regelbundet
- [ ] Använd 2FA på manjo.me för att skydda lister

---

## Incidenthantering

### Om hemlig nyckel lästas

**Åtgärd**:
1. Anslut till server omedelbar
2. Regenerera hemlig nyckel: `php setup_mailfilter.php --script-id abc123xyz`
3. Ändra IMAP-lösenord (Manjo.me eller mailserver)
4. Uppdatera settings.json med nytt lösenord
5. Kontrollera loggfiler för misstänkt aktivitet

### Om webhook-signeringar misslyckas

**Åtgärd**:
1. Kontrollera klockskev på server: `date`
2. Regenerera RSA-nycklar på manjo.me
3. Uppdatera public_key i settings.json
4. Test webhook igen

### Om IMAP-anslutning misslyckas

**Åtgärd**:
1. Verifiera lösenord är korrekt
2. Verifiera IMAP-server och port
3. Kontrollera loggfil för detaljerat felmeddelande
4. Test manuell IMAP-anslutning: `telnet imap.host.com 993`

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-29
