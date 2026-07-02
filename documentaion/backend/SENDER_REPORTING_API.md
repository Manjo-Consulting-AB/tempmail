# SENDER_REPORTING_API.md - Mottagning av Avsändarrapporter (Alt 2)

## Syfte

För den "avslappnade" användarmodellen (Alt 2): scriptet på användarens server rapporterar in vilka avsändare som inkommit sedan senaste rapporten. Manjo.me lagrar dessa som "pending" och presenterar dem för användaren, som sedan kan vit-/svartlista dem.

**Notera**: Detta är ett **inkommande** API-anrop, dvs användarens script anropar manjo.me — motsatt riktning jämfört med webhook-utskicket. Detta initieras alltid av scriptet (vid cron-körning), aldrig genom att manjo.me pollar/anropar scriptet.

---

## Endpoint: POST /api/mailfilter/{script_id}/report-senders

**Anropas av**: Användarens script (mailfilter_{script_id}.php), inte av en inloggad webb-session.

**Autentisering**: Detta anrop går i motsatt riktning mot webhooken, vilket innebär att vi behöver ett separat sätt för scriptet att autentisera sig mot manjo.me. Eftersom scriptet redan har ett unikt script_id och vi har dess publika nyckel sparad — men INTE dess privata nyckel (den finns bara på manjo.me, för webhook-signering åt andra hållet) — kan scriptet inte signera detta anrop med RSA på samma sätt.

**Rekommenderad lösning**: Generera en separat, enkel API-nyckel (random token, t.ex. 32 bytes hex) per script_id vid skapandet, sparas i `mailfilter_scripts` (ny kolumn `report_api_key`) och skickas med i settings.json till scriptet. Scriptet skickar denna som en header:

```
Authorization: Bearer {report_api_key}
```

**Affärsbeslut att stämma av**: Detta innebär en ny hemlighet (utöver lösenordet) som lagras i scriptets settings.json. Den är dock lågrisk eftersom den bara ger rättighet att RAPPORTERA avsändar-email-adresser (inte läsa mail-innehåll eller agera på kontot) — värsta scenariot vid läckage är att någon kan spamma in falska avsändaradresser i en användares "pending"-lista, vilket användaren själv granskar innan något görs.

---

## Request Format

```json
{
  "script_id": "abc123xyz",
  "timestamp": "2026-06-30T08:00:00Z",
  "senders": [
    {"sender_email": "newsletter@somesite.com", "count": 3, "latest_date": "2026-06-30T07:45:00Z"},
    {"sender_email": "noreply@anotherservice.com", "count": 1, "latest_date": "2026-06-29T22:10:00Z"}
  ]
}
```

**Headers**:
```
Authorization: Bearer {report_api_key}
Content-Type: application/json
```

---

## Validering

1. Verifiera `Authorization`-header mot `report_api_key` för givet `script_id`.
2. `senders`-arrayen får inte överstiga rimlig storlek per anrop (rekommendation: max 200 rader per anrop — om scriptet har fler kan det dela upp i flera anrop eller manjo.me kan trunkera och returnera en varning).
3. `sender_email` valideras som syntaktiskt giltig email-adress.

---

## Affärslogik vid Mottagning

```php
function handle_sender_report($script_id, $senders) {
    foreach ($senders as $entry) {
        $existing = find_reported_sender($script_id, $entry['sender_email']);

        if ($existing) {
            // Redan rapporterad tidigare (oavsett status) — uppdatera räknare/datum
            // OBS: om status redan är 'whitelisted'/'blacklisted'/'ignored', 
            // rör vi INTE status — bara statistik
            update_reported_sender(
                $existing['id'],
                $entry['count'],
                $entry['latest_date']
            );
        } else {
            // Ny avsändare — skapa som 'pending'
            insert_reported_sender(
                $script_id,
                $entry['sender_email'],
                $entry['count'],
                $entry['latest_date'],
                status: 'pending'
            );
        }
    }
}
```

**Viktigt**: Om en avsändare redan är whitelistad/blacklistad sedan tidigare ska nya rapporter INTE flytta tillbaka den till "pending" — bara öka räknaren för statistik/visning ("du har fått 12 mail till från denna redan vitlistade avsändare").

---

## Response Format

```json
{
  "status": "ok",
  "received": 2,
  "new_pending": 1,
  "already_known": 1
}
```

---

## UI-konsekvens (för utvecklarens medvetenhet, ej del av detta API)

Manjo.me behöver ett gränssnitt där användaren ser sina `pending`-avsändare och kan:
- Klicka "Vitlista" → flyttar pattern till whitelist-tabellen, status → 'whitelisted', triggar webhook
- Klicka "Svartlista" → flyttar pattern till blacklist-tabellen, status → 'blacklisted', triggar webhook
- Klicka "Ignorera" → status → 'ignored' (förblir i grålistan/standardhantering på scriptet, men slutar visas i pending-vyn)

Detta är samma underliggande whitelist/blacklist-tabeller och samma webhook-trigger-logik som beskrivs i LIST_MANAGEMENT_API.md — `report-senders`-flödet är bara en alternativ ingångspunkt för att FÅ FÖRSLAG på vad som ska läggas till, själva listhanteringen är identisk.

---

## Frekvens & Belastning

Scriptet (cron) avgör själv hur ofta det rapporterar — detta styrs av användarens egna cron-inställningar (var 30:e minut, var 6:e timme, etc.), inte av manjo.me. Manjo.me ska bara ta emot och svara snabbt, ingen quota/rate-limiting bör krävas i v1 givet den låga förväntade volymen, men en enkel rate-limit (t.ex. max 1 anrop/minut per script_id) rekommenderas som skydd mot felkonfigurerade script.

---

## Checklista för Implementering

- [ ] Ny kolumn `report_api_key` i `mailfilter_scripts`, genereras vid skapande
- [ ] `report_api_key` inkluderas i settings.json-template (se FILE_GENERATION.md, kräver uppdatering av det dokumentet med detta fält)
- [ ] POST /api/mailfilter/{script_id}/report-senders med Bearer-token-autentisering
- [ ] Validering av payload (storlek, email-format)
- [ ] Upsert-logik som inte överskriver redan kategoriserad status
- [ ] Enkel rate-limiting (rekommenderat, ej kritiskt för v1)
- [ ] UI-vy för pending-avsändare med vitlista/svartlista/ignorera-actions (kopplas mot befintliga whitelist/blacklist-endpoints)

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
