# Open Lines peer_send fix — file summary and test steps

## Problem

Open Lines replies were not delivered to Telegram. Grey returned `invalid_peer` when we sent the raw numeric Telegram ID (e.g. `356961013`). Grey expects a **sendable** peer: E.164 phone or @username. The Deal path works because it uses the contact’s E.164 phone.

## Solution

- Store both **peer_raw** (e.g. Telegram numeric id) and **peer_send** (E.164 or @username) in `ol_map`.
- Use **only peer_send** for Grey `/messages/send`. If `peer_send` is missing, report `imconnector.send.status.undelivered` with a clear error.

---

## File-by-file summary

### `lib/bootstrap.php`

- **Migration:** If `ol_map` has no `peer_send` column, add it: `ALTER TABLE ol_map ADD COLUMN peer_send TEXT`.
- **ol_map_get_or_create(portal, tenantId, peer, ?peerSend):** Optional 4th argument `peer_send`. On INSERT/UPDATE, stores `peer_send`.
- **ol_map_update_peer_send(portal, tenantId, peer, peerSend):** New helper to set `peer_send` for an existing row (used by CRM fallback).
- **ol_map_find_by_chat_id:** Returns `tenant_id`, `peer` (raw), `peer_raw`, `peer_send`. Handler uses `peer_send` for sending.

### `lib/grey.php`

- **grey_get_send_peer(?phone, ?senderUsername):** Returns a sendable peer: E.164 from phone if valid, else @username, else null. Used by inbound to compute `peer_send`.
- **grey_peer_likely_sendable / grey_normalize_peer:** Used to validate E.164 / @username format (inbound and CRM fallback).

### `webhook/grey_inbound.php`

- **Before OL mapping:** `peerRaw = phone ?: chatId`, `peerSend = grey_get_send_peer(isPhoneNumber ? phone : null, senderUsername)`.
- **ol_map_get_or_create(portal, tenantId, peerRaw, peerSend).**
- **CRM fallback:** If `peer_send` is still null and we have a CRM `contactId`, fetch contact’s PHONE via `crm.contact.get`; if E.164 sendable, call `ol_map_update_peer_send` and set `peerSend` for logging.
- **Logs:** `[Inbound->OL] map saved ... peer_raw=... peer_send=...`; if no sendable peer, log “Inbound OL map: no sendable peer”.

### `openlines/handler.php`

- **Lookup:** `ol_map_find_by_chat_id(portal, external_chat_id)` → get `peer_send` and `peer_raw`.
- **If peer_send is null/empty:** Call `imconnector.send.status.undelivered` with CONNECTOR, LINE, MESSAGES (message id, chat id, im); log “No peer_send”; return 200 with error “Recipient has no sendable identifier (no phone/username). Send a new message from Telegram with phone or @username so the chat can receive replies.”
- **If peer_send is set:** Call Grey `/messages/send` with `peer => peer_send` (not `peer`). On success call `imconnector.send.status.delivery`; on Grey error call `imconnector.send.status.undelivered`.
- **message_log_insert:** Use `peerSend` for the outbound line.
- Removed the old “peer not sendable” check based on `grey_peer_likely_sendable($peer)`; rejection is now solely “no peer_send”.

### `openlines/diag_chat.php`

- **Query:** Selects `peer_send` and `peer` (as peer_raw). Shows portal, tenant_id, external_chat_id, **peer_raw**, **peer_send**, external_user_id, created_at, **updated_at (last inbound/update)**.
- **Display:** “peer_send” shows the value used for Grey or “—” with note “No sendable peer; replies will be undelivered” when empty.

---

## Test steps

1. **Inbound creates chat and sets peer_send when phone or @username present**
   - Send a message from Telegram **with a phone number** (or from an account that exposes phone in Grey payload). Check logs: `[Inbound->OL] map saved ... peer_raw=... peer_send=+7...` (or similar E.164).
   - Or send from an account with **@username**. Check logs: `peer_send=@username`.
   - Open `openlines/diag_chat.php?token=...&portal=...&external_chat_id=tg_c_...`: `peer_raw` and `peer_send` should be set; `peer_send` should be E.164 or @username.

2. **Reply from Open Lines uses peer_send and Grey delivers**
   - In Bitrix Open Lines/Contact Center, reply in the chat that has `peer_send` set.
   - Expect: Grey `/messages/send` is called with `peer` = that E.164 or @username; message is delivered in Telegram; Bitrix shows “delivered” (imconnector.send.status.delivery).

3. **Missing peer_send → undelivered with clear error**
   - Use a chat that was linked when Grey did **not** provide phone or @username (e.g. only numeric Telegram id). Diag page should show `peer_send` = “—” or empty.
   - Reply from Open Lines. Expect: Handler does **not** call Grey with numeric id; calls `imconnector.send.status.undelivered`; response 200 with error “Recipient has no sendable identifier (no phone/username). Send a new message from Telegram with phone or @username so the chat can receive replies.”

4. **Optional: CRM fallback**
   - If Grey sends no phone/username but we find or create a CRM contact with a phone: after saving the OL map, inbound should call `crm.contact.get`, get E.164, call `ol_map_update_peer_send`, and log “peer_send enriched from CRM contact phone”. Next reply from Open Lines should then use that phone and deliver.

---

## Deliverables checklist

- [x] Open Lines replies are delivered to Telegram via Grey when `peer_send` is set (no invalid_peer).
- [x] `ol_map` stores `peer_send`; handler uses only `peer_send` for sending.
- [x] If `peer_send` is missing, Bitrix message is marked undelivered with the new error message (not silent).
- [x] Diagnostics page shows peer_raw, peer_send, last update.
- [x] Optional CRM fallback: enrich `peer_send` from contact phone when Grey payload has no phone/username.
