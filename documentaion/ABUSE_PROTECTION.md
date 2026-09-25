# Skydd mot missbruk (abuse guard)

Hur Mail Shield mäter användningen och skyddar sig mot missbruk: floder av mejl mot en adress (stora eller små), webhook-floder, massgenerering av adresser och konton som missbrukar tjänsten.

Koden finns i `abuse_guard.php` (biblioteket), `parse.php` (intaget), `index.php` (skapa adresser), `php_imap_processor.php` (webhook-taket), `cron/abuse-guard.php` (återöppning, notiser, timrapport) och `abuse_admin.php` (admin). Tabellerna skapas av `migrate_abuse_guard.php`. Regressionstesterna ligger i `tests/abuse_guard_test.php`.

## 1. Grundidé: karantän genom att ta bort forwardern

En adress tar bara emot mejl så länge dess DirectAdmin-forwarder finns. Domänen har ingen catch-all. Utan forwarder avvisar Exim därför mejlet redan i SMTP-samtalet (vid `RCPT TO`), och `parse.php` startar aldrig. Det kostar ingen PHP-process och ingen databasfråga, och det blir ingen backscatter, eftersom det är den sändande servern som får felet.

**Karantän** betyder alltså att forwardern tas bort (`directAdminRemoveForwarder()`) och läggs tillbaka när tiden gått ut (`createDirectAdminForwarder()`). Adressens rad i `temp_emails`, dess mejl och dess inställningar rörs inte.

Förutsättningen är att domänen **inte har någon catch-all**. Om en catch-all någon gång slås på hamnar mejl till adresser i karantän där i stället.

Under glappet mellan beslutet och att DirectAdmin har tagit bort forwardern (eller om borttagningen misslyckas och väntar på cron) tar `parse.php` emot mejlet och **slänger det tyst** med exit 0. En studs härifrån skulle gå till en avsändaradress som oftast är förfalskad.

## 2. Två spår

| | Adressen utsätts (användaren är offer) | Kontot missbrukar (användaren är förövare) |
|---|---|---|
| Utlösare | För många mejl, för många bytes eller för många bilagebrott till en adress | Gränserna för att skapa adresser passeras upprepade gånger |
| Steg 1 | Varning till ägaren vid 50 % av en gräns (högst en per adress och dygn) | Varning vid första överträdelsen inom 24 h |
| Steg 2 | Karantän: 30 min → 2 h → 12 h (inom 24 h) | Alla kontots adresser i karantän i 6 h (efter 3 överträdelser inom 24 h) |
| Steg 3 | Stängd: fjärde gången inom 24 h stängs adressen tills ägaren raderar den eller admin öppnar den | Förslag om avstängning (efter 2 kontokarantäner inom 7 dygn) |
| Steg 4 | – | **Admin bekräftar** avstängningen i `abuse_admin.php`, eller avfärdar den |

Att bli spammad räknas aldrig mot kontot. Kontot stängs bara i förövarspåret, och bara efter att admin har bekräftat.

## 3. Gränser och env-variabler

Standardvärdena sätts i `abuseGuardSettings()`. Env-variabler i `.env.*` skriver över dem.

| Gräns | Standard | Env |
|---|---|---|
| Guard på/av | på | `ABUSE_GUARD_ENABLED` |
| Mejl per adress och 5 min | 30 | `ABUSE_ADDRESS_MAX_5MIN` |
| Mejl per adress och timme | 150 | `ABUSE_ADDRESS_MAX_HOUR` |
| Bytes per adress och timme | 52428800 (50 MB) | `ABUSE_ADDRESS_MAX_BYTES_HOUR` |
| Bilagebrott per adress och timme | 5 | `ABUSE_ADDRESS_MAX_STRIKES_HOUR` |
| Varningsnivå (andel av en gräns) | 0.5 | `ABUSE_WARN_RATIO` |
| Karantänsteg i minuter | 30,120,720 | `ABUSE_QUARANTINE_STEPS` |
| Riktiga bilagor per mejl | 10 | `MAX_ATTACHMENTS_PER_MESSAGE` |
| Inbäddade bilder per mejl (Content-ID + `image/*`) | 50 | `MAX_INLINE_IMAGES_PER_MESSAGE` |
| Webhook-leveranser per hook och klocktimme | 60 | `WEBHOOK_MAX_PER_HOUR` |
| `generate` per IP och klocktimme (anonym) | 10 | `ABUSE_GENERATE_IP_HOUR` |
| `generate` per IP och dygn (anonym) | 30 | `ABUSE_GENERATE_IP_DAY` |
| `generate` per konto och dygn | 30 | `ABUSE_GENERATE_USER_DAY` |
| `create_personal` per konto och dygn | 20 | `ABUSE_PERSONAL_USER_DAY` |
| Överträdelser inom 24 h innan kontokarantän | 3 | `ABUSE_ACCOUNT_STRIKES_DAY` |
| Kontokarantänens längd i minuter | 360 | `ABUSE_ACCOUNT_QUARANTINE_MINUTES` |
| Kontokarantäner inom 7 dygn innan förslag om avstängning | 2 | `ABUSE_ACCOUNT_QUARANTINES_WEEK` |

Maxstorleken per meddelande (`MAX_MESSAGE_BYTES`, 10 MB) och lagringskvoten (`MAILBOX_QUOTA_BYTES`, 100 MB) gäller som förut.

Trimma gränserna utifrån timrapporten (§8) i stället för att gissa.

## 4. Intaget (`parse.php`)

1. Läser in meddelandet. Ett meddelande över maxstorleken töms från stdin men avvisas inte än.
2. Tar fram och validerar mottagaren och slår upp raden i `temp_emails`, precis som förut. En okänd eller utgången adress avvisas utan att räknas.
3. **Abuse guard:**
   - Är adressen i karantän räknas mejlet och slängs (exit 0).
   - Annars räknas mejlet och dess bytes (`abuseAddressRecord()`), även när det är för stort.
   - Passerar adressen en gräns sätts den i karantän, forwardern tas bort direkt och mejlet slängs (exit 0).
   - Vid 50 % av en gräns skapas en varning.
4. Ett för stort meddelande avvisas nu (exit 1), med samma felmeddelande som förut.
5. Bilagegränsen (`abuseLimitAttachments()`): mejlet lagras med de första 10 bilagorna och 50 inbäddade bilderna, resten släpps och loggas. En överträdelse är ett *bilagebrott* på adressen. Fem på en timme ger karantän, men först efter att mejlet har lagrats.

Allt är fail-open. Saknas tabellerna, eller kastar guarden ett fel, hanteras mejlet exakt som förut.

## 5. Webhooks

`dispatchWebhooks()` köar högst `WEBHOOK_MAX_PER_HOUR` leveranser per hook och klocktimme. Resten hoppas över, och användaren får ett mejl första gången per hook och dygn. Mejlen lagras som vanligt. Stängs en adress av karantän kommer inga mejl in, och då skickas heller inga webhooks.

## 6. Skapa adresser (`index.php`)

`generate` och `create_personal` räknas per IP (anonym besökare) eller per konto. Över gränsen svarar anropet `success: false, rate_limited: true` med en läsbar text, som en vanlig HTTP 200 så att sidornas jQuery-hanterare visar texten. Den första överträdelsen per timme eskalerar:

- **IP:** `flagMaliciousActivity()`. Tre flaggningar ger 24 h IP-blockering, som för annat missbruk.
- **Konto:** `abuseAccountStrike()`, alltså kontospåret i §2.

## 7. Avstängt konto

Avstängning sker bara i `abuse_admin.php`, genom en admins bekräftelse eller ett manuellt id. Då händer följande:

- `pro_users.suspended_at` sätts.
- Kontots alla adresser stängs, och deras forwarders tas bort direkt (eller av cron).
- Inloggning nekas: magic link (`consumeLoginToken()`), lösenord och 2FA.
- En pågående session loggas ut vid nästa anrop (`proSessionEndIfSuspended()`, anropas direkt efter `session_start()` på alla sidor som litar på sessionen).
- RSS-flödena slutar svara.

"Lift suspension" nollställer `suspended_at` och öppnar adresserna igen, det vill säga forwarders skapas på nytt.

## 8. Cron och övervakning

`cron/abuse-guard.php` ska köras **varje minut**:

```
* * * * * php /home/s174280/domains/manjo.me/public_html/cron/abuse-guard.php
```

Varje körning:

1. tar bort forwarders som ännu inte är borttagna (omförsök och kontospåret);
2. återöppnar karantäner vars tid har gått ut;
3. mejlar köade notiser: till ägaren, eller till admins (`ADMIN_USER_IDS`) vid förslag om avstängning;
4. en gång i timmen: loggar de tio adresser som fått mest mejl den senaste timmen (INFO `Abuse guard: busiest addresses in the last hour` i `system_logs`) och rensar räknare äldre än 2 dygn och händelser äldre än 90 dygn.

`php check_abuse_guard.php` visar schemat, gränserna, toppadresserna, aktiva karantäner, öppna förslag och avstängda konton. Den varnar också om cron inte verkar köra.

`check_forwarders.php` listar adresser i karantän för sig. De saknar forwarder med avsikt och räknas inte som drift.

## 9. Tabeller

| Tabell | Innehåll | Rensas |
|---|---|---|
| `abuse_counters` | (scope, subject, window_start): hits, bytes, strikes. `addr` i 5-minutersfönster, övriga (`gen_ip`, `gen_user`, `personal_user`, `hook`) i timfönster | efter 2 dygn |
| `address_quarantines` | en rad per adress i karantän; `quarantined_until` NULL = stängd; `forwarder_removed` | när adressen släpps eller raderas |
| `abuse_events` | händelselogg: varningar, karantäner, beslut, notiser (`notify`, `notified_at`) | efter 90 dygn |
| `pro_users.suspended_at` | satt av admin | – |

Inga foreign keys, som för `address_cooldowns`. Varje raderingsväg för en adress går genom `deleteDirectAdminForwarder()`, som även avslutar adressens karantän (`abuseQuarantineForget()`) och hoppar över DirectAdmin-anropet när forwardern redan är borta.

Räknarna sparar IP-adresser (skapande av anonyma adresser) i två dygn. Det står i integritetspolicyn (`privacy.php`).

## 10. Driftsättning

1. Deploya koden.
2. Kör `php migrate_abuse_guard.php`. Innan dess är guarden avstängd och allt fungerar som förut.
3. Lägg in `cron/abuse-guard.php` varje minut.
4. Kör `php check_abuse_guard.php` och kontrollera att allt är `[OK]`.
5. Följ timrapporten några dagar och justera gränserna vid behov.
