# MANJO_ME_README.md - Mailfilter Backend Implementation Guide

## Syfte

Detta dokument beskriver vad som ska byggas på **manjo.me** för att stödja Mailfilter-systemet som körs på användarnas egna servrar (t.ex. manjo.se). Manjo.me agerar som kontrollpanel: genererar script-paket, hanterar RSA-nycklar, låter användaren administrera vit-/svartlistor, och skickar signerade webhooks till användarens script.

**OBS**: Detta dokument beskriver funktionalitet/krav/dataflöden — inte visuell design. Design tas fram separat med utvecklaren.

---

## Relation till Server-sidan (manjo.se-scriptet)

Manjo.me känner **aldrig** till användarens IMAP-lösenord. Manjo.me:s ansvar är begränsat till:

1. Generera ett unikt `script_id` per användare/mailkonto
2. Generera ett RSA-4096 keypair per script_id
3. Tillhandahålla nedladdningsbara filer (settings-template + script + setup-script)
4. Låta användaren administrera whitelist/blacklist/greylist_days
5. Skicka signerade webhooks till användarens script vid liständringar
6. (Alt 2) Ta emot rapporterade avsändare från scriptet och presentera för användaren

---

## Komponenter att Bygga

| Komponent | Beskrivning | Dokument |
|---|---|---|
| Script-ID & Nyckelgenerering | Unikt ID + RSA-keypair per användare | KEY_MANAGEMENT.md |
| Fil-generator | Skapar nedladdningsbara settings.json + .php-filer | FILE_GENERATION.md |
| Lista-administration (UI-backend) | CRUD för whitelist/blacklist/greylist_days | LIST_MANAGEMENT_API.md |
| Webhook-sender | Signerar och skickar till användarens server | WEBHOOK_SENDER.md |
| Sender-rapport-mottagare (Alt 2) | Tar emot rapporterade avsändare från script | SENDER_REPORTING_API.md |
| Datamodell | Databastabeller som krävs | DATA_MODEL.md |

---

## Två Användarflöden (Repetition)

### Flöde 1: Paranoid (manuell listhantering)
```
User loggar in på manjo.me → lägger till avsändare/domän i vit/svart-lista →
klickar Spara → manjo.me signerar payload → webhook till användarens script →
script uppdaterar lokal settings.json
```

### Flöde 2: Avslappnad (scriptet rapporterar avsändare)
```
Cron körs på användarens server → scriptet samlar in avsändare sedan senaste
rapporten → POST till manjo.me API → manjo.me lagrar/presenterar för user →
user mark vit/svart → samma webhook-flöde som ovan
```

**Viktigt**: Flöde 2 initieras av scriptet (push från användarens server), INTE av att manjo.me pollar/anropar scriptet i onödan. Detta är i linje med kravet om minimal trafik till manjo.me.

---

## Säkerhetsprinciper (gäller alla komponenter)

1. **Lösenord rör aldrig manjo.me** — inga fält, inga loggar, ingen databas innehåller IMAP-lösenord.
2. **RSA privat nyckel lämnar aldrig manjo.me** — signering sker server-side på manjo.me.
3. **Varje script_id har sitt eget unika RSA-keypair** — kompromettering av en användares script påverkar inte andra.
4. **All webhook-trafik signeras** — se WEBHOOK_SENDER.md för implementation.
5. **Timestamp inkluderas i varje webhook** — för anti-replay-skydd på mottagarsidan (redan implementerat i scriptet).

---

## Läsordning för Utvecklaren

1. **DATA_MODEL.md** — Databasstruktur som krävs
2. **KEY_MANAGEMENT.md** — RSA-nyckelgenerering och lagring
3. **FILE_GENERATION.md** — Generering av nedladdningsbara script-paket
4. **LIST_MANAGEMENT_API.md** — Backend-endpoints för vit/svart-listhantering
5. **WEBHOOK_SENDER.md** — Signering och utskick av webhooks
6. **SENDER_REPORTING_API.md** — Mottagning av avsändarrapporter (Alt 2)

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
