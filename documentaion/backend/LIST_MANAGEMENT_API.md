# LIST_MANAGEMENT_API.md - Vit-/Svartlistehantering (Backend)

## Syfte

Beskriver de backend-endpoints som krävs för att låta en inloggad användare administrera whitelist/blacklist/greylist_days för sina mailfilter-script. UI/design tas fram separat — detta dokument fokuserar på datakontrakt och affärslogik.

---

## Endpoints

### GET /api/mailfilter/{script_id}

Hämtar fullständig status för ett script.

**Response**:
```json
{
  "script_id": "abc123xyz",
  "label": "Min jobbmail",
  "target_host": "manjo.se",
  "target_path": "/mailfilter_abc123xyz.php",
  "greylist_days": 30,
  "whitelist": [
    {"id": 1, "pattern": "tony@example.com", "added_at": "2026-06-01T10:00:00Z"},
    {"id": 2, "pattern": "@trusted-domain.com", "added_at": "2026-06-15T08:30:00Z"}
  ],
  "blacklist": [
    {"id": 5, "pattern": "spam@evil.com", "added_at": "2026-06-10T12:00:00Z"}
  ],
  "last_webhook_sent_at": "2026-06-29T14:30:00Z",
  "last_run_reported": null
}
```

**Auth**: Kräver att inloggad user äger script_id (kontroll mot `mailfilter_scripts.user_id`).

---

### POST /api/mailfilter/{script_id}/whitelist

Lägger till ett mönster i whitelist.

**Request**:
```json
{
  "pattern": "boss@company.com"
}
```

**Validering**:
- Pattern måste vara antingen: exakt email (`user@domain.com`), domän-wildcard (`@domain.com`), eller subdomän-wildcard (`user@*.domain.com`)
- Samma pattern får inte finnas i både whitelist och blacklist samtidigt för samma script_id (om det redan finns i blacklist, ta bort därifrån vid tillägg till whitelist, eller returnera fel — se affärsbeslut nedan)

**Affärsbeslut att stämma av med dig**: Om en pattern redan finns i blacklist och användaren lägger till samma pattern i whitelist — ska den automatiskt flyttas, eller ska systemet neka och be användaren ta bort den manuellt först? Rekommendation: automatiskt flytta (med tydlig bekräftelse i UI), eftersom det är minst förvirrande för slutanvändaren.

**Response**: `201 Created` + den skapade raden, eller `409 Conflict` om pattern redan finns i listan.

**Side-effect**: Triggar webhook-utskick (se WEBHOOK_SENDER.md) om listan faktiskt ändrades.

---

### DELETE /api/mailfilter/{script_id}/whitelist/{id}

Tar bort en rad från whitelist.

**Response**: `204 No Content`

**Side-effect**: Triggar webhook-utskick.

---

### POST /api/mailfilter/{script_id}/blacklist

Samma kontrakt som whitelist-POST ovan, fast mot blacklist-tabellen.

---

### DELETE /api/mailfilter/{script_id}/blacklist/{id}

Samma kontrakt som whitelist-DELETE ovan.

---

### PATCH /api/mailfilter/{script_id}/settings

Uppdaterar greylist_days (och ev. label/target_host i framtiden).

**Request**:
```json
{
  "greylist_days": 14
}
```

**Validering**: `greylist_days` ska vara ett positivt heltal, rimligt intervall t.ex. 1–365.

**Response**: `200 OK` + uppdaterat objekt.

**Side-effect**: Triggar webhook-utskick.

---

### POST /api/mailfilter/{script_id}/sync

Manuell "tvinga skicka webhook nu"-knapp, för felsökning eller om en tidigare webhook misslyckades. Skickar nuvarande state (whitelist + blacklist + greylist_days) oavsett om något ändrats sedan sist.

**Response**: `200 OK` med resultat av webhook-anropet (se WEBHOOK_SENDER.md för svarsformat).

---

## Affärslogik: Webhook-trigger

**Princip**: En webhook skickas till användarens script **endast** vid faktisk förändring (tillägg/borttagning i listor, eller ändring av greylist_days), inte vid varje GET. Detta är i linje med kravet om att minimera trafik till/från manjo.me och belastning på användarens server.

**Rekommenderad implementation**: Debounce/batch — om en användare gör flera ändringar i snabb följd (t.ex. lägger till 5 avsändare i rad), skicka EN webhook efter en kort fördröjning (t.ex. 5–10 sekunder) snarare än en webhook per ändring. Detta kräver en kö eller en "pending changes"-flagga med fördröjd processning (t.ex. via en scheduled job/queue worker).

**Enkel variant för v1** (om kö/fördröjning är för komplext att bygga initialt): Skicka webhook direkt vid varje förändring. Detta är funktionellt korrekt men kan resultera i fler webhooks än nödvändigt om användaren gör många ändringar snabbt. Kan optimeras senare.

---

## Checklista för Implementering

- [ ] GET /api/mailfilter/{script_id} med ägarskap-kontroll
- [ ] POST/DELETE whitelist-endpoints
- [ ] POST/DELETE blacklist-endpoints
- [ ] PATCH settings-endpoint (greylist_days)
- [ ] POST sync-endpoint för manuell webhook-trigger
- [ ] Pattern-validering (email/domän/wildcard-format)
- [ ] Affärsbeslut: konflikthantering whitelist vs blacklist (se ovan)
- [ ] Webhook-trigger vid förändring (direkt eller debounced — beslut för v1)

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-30
