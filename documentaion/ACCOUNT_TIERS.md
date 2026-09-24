# ACCOUNT_TIERS.md — Kontotyper: Regular och Pro

Statusdokument för införandet av ett gratis **Regular**-konto på manjo.me. Målet
är att en besökare ska kunna registrera ett eget konto utan voucherkod, få en
temporär adress, och senare kunna uppgradera till Pro.

Dokumentet är specifikationen som issue-nedbrytningen utgår ifrån. Ändras
designen ska den ändras här först.

## 1. Bakgrund och grundprincip

Sedan adresskapandet låstes bakom inloggning skickas varje besökare som klickar
"Get Email Address" (`index.php`) till `pro_login.php`. Där finns ingen väg in
utan en voucherkod — tjänsten har i praktiken ingen registrering alls.

**Grundprincipen**: kontot är strypmekanismen. Adresser skapas bara av
registrerade konton, och kontotypen avgör vad kontot får göra. Ett gratiskonto är
inte en gäst utan ett riktigt konto som kan uppgraderas.

**Kärnproblemet är inte registreringssidan.** Koden har idag ingen kontotyp:

- `pro_users` + `pro_expires_at` är hela modellen, och `pro_expires_at IS NULL`
  tolkas som livstids-Pro (`pro_auth.php`, `request_login_link`).
- All feature-gating är i praktiken `isset($_SESSION['pro_user_id'])` — dvs
  **inloggad == Pro**. Personliga adresser, webhooks, digests, RSS, client agent
  och TTL 1–7 dagar kontrollerar bara att någon är inloggad, aldrig vad kontot
  är berättigat till.

Släpps fria konton på utan entitlement får varje gratisanvändare allt som
jämförelsetabellen på förstasidan listar som Pro-only. Därför är ordningen
**entitlement först, registrering sen**.

**Ingår**
- Kontotyp i datamodellen och en delad entitlement-hjälpare.
- Gating av alla Pro-funktioner mot den hjälparen.
- Självbetjäningsregistrering av Regular-konton med e-postverifiering.
- Pro-registrering som fortsatt kräver voucherkod.
- Degradering av utgångna Pro-konton till Regular i stället för radering.
- Inaktivitetsstädning av gratiskonton.

**Ingår inte**
- Betallösning för publik Pro-registrering. Förbereds med en flagga
  (`PRO_SELF_SIGNUP_ENABLED`, default av) men aktiveras inte.
- Captcha. Bot-skyddet är e-postverifiering, IP-rate limiting och
  domänblockering.
- Anonymt adresskapande. Det är redan avstängt och återinförs inte.
- Byte av tabellnamnet `pro_users` eller sessionsnyckeln
  `$_SESSION['pro_user_id']`. Båda rymmer nu även Regular-konton; namnen behålls
  för att undvika en mekanisk ändring på ett fyrtiotal anropsställen.

### 1.1 Konsekvenser av avgränsningen

Två följder är avsiktliga och ska inte "fixas" utan att designen ändras här
först:

1. **En Regular-användare får färre adresser än en anonym besökare fick.** Precis
   som en inloggad användare idag ersätts den gamla temporära adressen när en ny
   genereras. Det är ett medvetet val: kontot är strypmekanismen.
2. **`pro_users` innehåller icke-Pro-användare.** Tabellnamnet ljuger. Samma sak
   gäller `pro_user_id` i `temp_emails` och sessionen. Läs dem som "konto".

## 2. Datamodell

Fyra kolumner läggs till på `pro_users`. Projektet har ingen
migrationsprocess — schemaändringar görs antingen lazy med `SHOW COLUMNS` före
`ALTER TABLE` (se `pro_profile.php`, `password_changed_at`) eller med
`CREATE TABLE IF NOT EXISTS` (se `magic_link_requests` i `pro_auth.php`). Här
används i stället ett engångsskript, `migrate_account_types.php`, eftersom
befintliga rader måste backfillas och det inte hör hemma i en webbrequest.

| Fält | Typ | Beskrivning |
|---|---|---|
| `account_type` | ENUM('regular','pro') NOT NULL DEFAULT 'regular' | Kontotyp. Befintliga rader backfillas till `'pro'` |
| `email_verified_at` | DATETIME NULL | Sätts när verifieringslänken används. Befintliga rader backfillas till `NOW()` |
| `last_login_at` | DATETIME NULL | Uppdateras vid varje lyckad inloggning. Underlag för inaktivitetsstädning |
| `inactivity_warned_at` | DATETIME NULL | Sätts när varningsmail om inaktivitet skickats |

`pro_expires_at` behåller sin betydelse: `NULL` = ingen bortre gräns,
ett framtida datum = aktiv till dess, ett passerat datum = utgången.

### 2.1 Entitlement-regeln

En användare är Pro när:

```
account_type = 'pro' AND (pro_expires_at IS NULL OR pro_expires_at > NOW())
```

Regeln implementeras **en gång**, i `proUserIsPro(int $userId): bool` i
`config.php`, med per-request static cache. Ingen annan kod får upprepa villkoret.

**Legacy-fallback**: saknas kolumnen `account_type` (migrationen inte körd än)
faller `proUserIsPro()` tillbaka på dagens logik — `pro_expires_at IS NULL` eller
framtida datum räknas som Pro. Det gör att koden kan driftsättas före
migrationen utan att någon degraderas.

### 2.2 Var Pro-status skrivs

Dessa ställen måste sätta `account_type = 'pro'` när de beviljar Pro, annars blir
kontot Regular trots betald eller inlöst Pro:

- `pro_auth.php` — voucher-inlösen, både uppdaterings- och nyskapandegrenen.
- `bmac_handler.php` — Buy Me a Coffee-webhooken, både uppdaterings- och
  nyskapandegrenen.
- `paddle_webhook.php` (logiken i `paddle_sync.php`) — Paddle Billing. Sätter
  `account_type = 'pro'` och `pro_expires_at` = slutet på betald period
  (prenumeration) eller `NULL` (lifetime). Skapar aldrig konton: en betalning
  som inte kan kopplas till en befintlig rad (via `custom_data.pro_user_id`
  eller kundens e-post) sparas okopplad. Betald tid läggs ovanpå
  den Pro-tid kontot redan hade (trial, voucher, BMAC): återstående tid vid
  övertagandet sparas som bonus och `pro_expires_at` = betald period + bonus.
  Förnyelser lägger inte till bonusen igen, och den förbrukas när den betalda
  tiden har upphört.

## 3. Entitlement-matris

"Inloggad" betyder att `$_SESSION['pro_user_id']` är satt. "Pro" betyder att
`proUserIsPro()` returnerar true.

| Funktion | Utloggad | Regular | Pro |
|---|---|---|---|
| Skapa temporär adress | ✗ | ✓ (en aktiv åt gången) | ✓ (en aktiv åt gången) |
| Adressens livslängd | — | 24 h | 1–7 dygn, valbart |
| Läsa inkorg via delad länk | ✓ | ✓ | ✓ |
| Skapa personlig adress | ✗ | ✗ | ✓ (max 10) |
| Lista/radera egna personliga adresser | ✗ | ✓ | ✓ |
| Webhooks | ✗ | ✗ | ✓ |
| Digest-mail | ✗ | ✗ | ✓ |
| RSS-feed | ✗ | ✗ | ✓ |
| Client agent | ✗ | ✗ | ✓ |
| Rotera signeringsnycklar | ✗ | ✗ | ✓ |
| Lösenord, 2FA, profilinställningar | ✗ | ✓ | ✓ |

**Notera raden "Lista/radera egna personliga adresser".** Den är öppen för
Regular med flit: ett degraderat konto måste kunna se och ta bort sina adresser
under grace-perioden (avsnitt 6.1). Endast *skapandet* kräver Pro.

**Notera raden "RSS-feed".** Den täcker båda slagen av flöde: kontots flöde
(`pro_users.feed_token`, alla adresser) och per-adress-flödena
(`temp_emails.feed_token`, ett per personlig adress, #160). Båda är Pro-only och
serveras av samma endpoint. Raden "Lista/radera egna personliga adresser" visar
bara en boolean `feed_enabled`, aldrig själva token — därför kan ett degraderat
konto se och radera adresser utan att komma åt en giltig flödes-URL.

Enhetligt felsvar från gatade JSON-endpoints:

```php
['success' => false, 'error' => 'Pro required', 'pro_required' => true]
```

## 4. Registrering

Ny sida `register.php` med två lägen, styrda av `?plan=regular|pro`. Båda postar
till samma endpoint, `register_account` i `pro_auth.php`.

### 4.1 Flöde

1. Besökaren fyller i e-post, lösenord och lösenordsbekräftelse. I pro-läget
   dessutom en voucherkod.
2. Endpointet validerar, rate limit-kontrollerar och skapar en rad i `pro_users`
   med `email_verified_at = NULL`, `address_ttl_days = 1` och `password_hash`
   satt via `password_hash(..., PASSWORD_DEFAULT)`.
3. I pro-läget löses voucherkoden in i samma flöde via
   `redeemVoucherForEmail()`. Går inlösen inte igenom skapas inget Pro-konto.
4. Ett verifieringsmail skickas med en engångstoken (samma `login_tokens`-tabell
   och samma tokenformat som magic link).
5. Länken går till `pro_login.php?token=…`, som redan konsumerar token och
   loggar in. Är kontot overifierat sätts `email_verified_at = NOW()` där.

Verifiering och första inloggning är alltså samma klick. Ingen separat
"verifierad, logga nu in"-sida behövs.

### 4.2 Anti-missbruk

Öppen gratisregistrering på en tempmail-tjänst är en magnet för kontofarmning.
Voucherkravet är det som skyddar idag; när det faller bort krävs:

- **E-postverifiering.** Ett overifierat konto kan inte logga in på något sätt.
- **IP-rate limiting** i tabellen `registration_requests`, byggd exakt som
  `magic_link_requests` i `pro_auth.php` (fönsterräkning, fail-open vid DB-fel,
  `flagMaliciousActivity()` vid överskridande).
- **Egen domän blockeras.** Samma kontroll som redan finns i `getOrCreateProUser()`
  och i voucher-inlösen: e-post på `EMAIL_DOMAIN` avvisas.
- **Blocklista för kända engångsdomäner**, i `config.php`. Ironiskt men
  nödvändigt — annars går farmningen via en annan tempmail-tjänst.
- **`detectSuspiciousPatterns()`** på inputen, som på övriga POST-actions.

### 4.3 Befintlig e-postadress

Svaret till klienten är alltid detsamma, oavsett utfall — annars blir endpointet
en kontoenumerator.

| Läge | Åtgärd |
|---|---|
| Ingen rad finns | Skapa konto, skicka verifieringsmail |
| Verifierat konto finns | Skapa inget. Skicka ett "du har redan ett konto"-mail med länk till inloggning |
| Overifierat konto, yngre än 24 h | Skicka om verifieringsmailet. Skapa inget nytt |
| Overifierat konto, äldre än 24 h | Skriv över raden med de nya uppgifterna och skicka nytt verifieringsmail |

Sista raden är skyddet mot e-postsquatting: utan den kan vem som helst
registrera någon annans adress och permanent blockera den riktiga ägaren.

## 5. Inloggning

Inloggningen blir **tiernutral**. Gatingen sitter på funktionerna, inte på
dörren. Det tar också bort en befintlig inkonsekvens: magic link kontrollerar
idag Pro-status innan länk skickas, medan lösenordsinloggning inte kontrollerar
något alls.

| Väg | Krav efter ändringen |
|---|---|
| Magic link (`request_login_link`) | Kontot finns och `email_verified_at IS NOT NULL` |
| Lösenord (`password_login`) | Samma, plus korrekt lösenord och ev. 2FA |
| Verifieringslänk (`pro_login.php?token=`) | Giltig, oanvänd token |

Svaret från `request_login_link` förblir generiskt (`['success' => true]`) i
samtliga fall, inklusive okänt och overifierat konto.

`last_login_at` sätts i alla tre vägarna — magic link i `pro_login.php`,
lösenordsinloggning efter lyckad verifiering, och `verify_2fa` när utmaningen
klarats.

## 6. Livscykel

### 6.1 Utgången Pro → degradering, inte radering

`cleanupExpiredProUsers()` i `cron/cleanup.php` raderar idag kontot helt efter
sju dagars grace. Med en Regular-nivå är rätt beteende att degradera:

1. Vid `pro_expires_at < NOW()`: sätt `account_type = 'regular'`,
   `digest_enabled = 0`, `address_ttl_days = 1`, `feed_token = NULL`, och
   pausa eller ta bort webhooks. Även kontots per-adress-flöden (#160) nollas
   i samma steg: `temp_emails.feed_token = NULL` för användarens personliga
   adresser, så gamla flödes-URL:er slutar fungera. Antalet nollade rader
   loggas som `address_feed_tokens_cleared`.
2. **`password_hash` nollställs inte längre.** Dagens kod gör det, vilket skulle
   låsa ut en Regular-användare från lösenordsinloggning.
3. **Kontot raderas aldrig.**
4. Personliga adresser behålls till `pro_expires_at + 7 dagar` och raderas
   därefter tillsammans med sina DirectAdmin-forwarders. Dagens sjudagarsgrace
   flyttas alltså från kontot till adresserna.

Rutinen ska vara idempotent: en redan degraderad användare behandlas inte om vid
varje körning.

### 6.2 Inaktiva gratiskonton

Utan städning växer `pro_users` obegränsat, och lagringsminimeringen blir svår
att försvara. `cleanupInactiveRegularAccounts()` i `cron/cleanup.php`:

- Underlag: `COALESCE(last_login_at, created_at)`.
- Vid 11 månader utan inloggning: varningsmail, `inactivity_warned_at` sätts.
- Vid 12 månader: kontot raderas med sina adresser och forwarders.
- Konton med `account_type = 'pro'` rörs aldrig, oavsett inaktivitet.

Trösklarna är konfigurerbara via `REGULAR_INACTIVITY_DAYS` och
`REGULAR_INACTIVITY_WARN_DAYS`.

## 7. Ingångar i gränssnittet

- `index.php`: knappen "Get Email Address" pekar för utloggade på
  `register.php`, med text som skiljer "skapa konto" från "logga in".
- Under jämförelsetabellen Pro vs Regular: två länkar — "Create a free account"
  (`register.php?plan=regular`) och "Get Pro with a code" (`register.php?plan=pro`).
- Tabellens `Regular`-kolumn beskriver idag den *anonyma* upplevelsen ("Public —
  anyone with the address can read emails"). Efter ändringen betyder Regular ett
  registrerat gratiskonto, och kolumnen skrivs om. Raderna Spam protection och
  Attachments påstår dessutom en skillnad som inte finns i koden; de skrivs om så
  att de är sanna.
- `partials/nav.php`: "Sign up"-länk för utloggade.
- `pro_login.php`: "Getting Started" skrivs om — steg 1 är inte längre "lös in en
  kod".

## 8. Uppgradering och betallösning

Uppgradering från Regular till Pro sker med voucher, från profilsidan, via samma
`redeemVoucherForEmail()` som registreringen använder.

Påslaget av betallösningen ska vara en env-ändring, inte en kodändring. Därför
styrs kravet på voucherkod i `register.php?plan=pro` av en enda flagga,
`PRO_SELF_SIGNUP_ENABLED` (default av). När den slås på öppnas publik
Pro-registrering och betalflödet tar vid.

`bmac_handler.php` fortsätter fungera som idag för befintliga köpare, med
tillägget att den nu även sätter `account_type = 'pro'`.

Provperioden (avsnitt 10) är påslagen redan nu, oberoende av
`PRO_SELF_SIGNUP_ENABLED` - betallösningen ska finnas på plats innan de första
provperioderna löper ut, inte innan provperioden själv får börja ge Pro.

## 9. Utrullningsordning

Ordningen är säkerhetskritisk i ett avseende: gating utan migration skulle
degradera samtliga användare.

1. Spec + migration (`migrate_account_types.php`). Kör migrationen på servern
   **innan** gating-koden driftsätts. Kod utan migration är säker tack vare
   legacy-fallbacken; migration utan kod är också säker.
2. Entitlement-gating och tiernutral inloggning. Osynligt för nuvarande
   användare — alla är `pro` efter backfillen.
3. Registrering och UI-ingångar. Här blir registreringen publik.
4. Degradering och inaktivitetsstädning.
5. Uppgraderingsvägen, när betallösningen närmar sig.

## 10. Provperiod för Pro (#267)

Varje nytt Regular-konto får **60 dagar Pro** för att hinna vänja sig vid
systemet, men bara **en gång per e-postadress, någonsin**. Att radera kontot
och registrera samma adress igen startar inte om provperioden - de 60 dagarna
räknas alltid från den dag adressen **först** sågs. Ett konto som raderas dag
20 och registreras om dag 30 får därför 30 dagars Pro kvar; registreras det om
dag 70 får det ingen alls.

Fyra vägar registrerar en adress i `pro_trial_claims`, via
`proTrialRecordClaim()` i `pro_trial.php`:

- första verifieringen i `pro_login.php`, när `email_verified_at` går från
  NULL till satt;
- ett kontos skapande via voucher (`redeemVoucherForEmail()` i `pro_auth.php`),
  eftersom kontot då redan är verifierat;
- ett kontos skapande via Buy Me a Coffee (`bmac_handler.php`), av samma skäl;
- en bekräftad e-postbytesbegäran (`update_email`-flödet i `pro_auth.php`).

**Bara den första av dessa - första verifieringen - beviljar en provperiod**
(`proTrialGrantOnVerification()`). De övriga tre registrerar bara adressen, så
att den räknas som sedd utan att ge Pro. En adress registreras aldrig bara för
att ett formulär skickas in - annars skulle vem som helst kunna göra slut på
någon annans provperiod genom att fylla i deras adress.

Det lagrade värdet är aldrig adressen själv utan en engångshash:
`hash_hmac('sha256', normaliserad_adress, PRO_TRIAL_HASH_KEY)`, 64 gemena
hexadecimala tecken. Normaliseringen (`proTrialNormalizeEmail()`) trimmar,
gör om till gemener, delar på **sista** `@`, klipper lokaldelen vid första
`+` och tar bort **alla** punkter ur lokaldelen innan den sätts ihop igen -
samma regel oavsett domän, och resultatet behöver inte vara en levererbar
adress. `PRO_TRIAL_HASH_KEY` måste vara minst 32 tecken och får **aldrig
ändras** efter att den satts: en ny nyckel gör alla lagrade hashar
omatchningsbara, vilket tyst skulle låta samma adresser göra anspråk på en ny
provperiod.

`PRO_TRIAL_DAYS` (default 60) styr längden på provperioden. `0` slår av
provperioden helt, men adresser registreras fortfarande i
`pro_trial_claims` (så att en senare påslagning räknar rätt från början).

En beviljad provperiod är bara `account_type = 'pro'` med `pro_expires_at`
satt till `first_seen_at + PRO_TRIAL_DAYS` - samma fält som allt annat Pro.
Den löper därför ut genom den befintliga degraderingen i §6.1
(`cleanupExpiredProUsers()`); ingen ny utgångskod behövdes.

`pro_trial_claims` (skapad av `migrate_trial_claims.php`) har medvetet
**ingen koppling** till `pro_users` - varken främmande nyckel eller annan
länk - så att raden överlever kontoradering. En claim-rad hålls i **fem år**
efter `first_seen_at` (`$config['trial']['claim_retention_days']`, default
1825) och städas därefter av `cleanupExpiredTrialClaims()` i
`cron/cleanup.php`; efter det räknas adressen som aldrig sedd.

Provperioden är **fail-closed**: en saknad eller för kort
`PRO_TRIAL_HASH_KEY`, en saknad `pro_trial_claims`-tabell eller ett
databasfel registrerar ingen adress och beviljar ingen provperiod, men
blockerar aldrig en inloggning eller verifiering (fail-open på
inloggningen, precis som §5).

Utrullningsordning: kör `migrate_trial_claims.php` och sätt
`PRO_TRIAL_HASH_KEY` **innan** steg 2-koden (första verifieringen beviljar
provperioder) driftsätts. Utan dem loggas ett fel och ingen provperiod
beviljas, men inget annat går sönder.
