# Outbound Open Lines → Telegram fix

## Summary

Operator replies from Bitrix24 Open Lines (Messenger → Chats) are delivered to Telegram via Grey and marked as delivered in Bitrix (no pending state). The handler uses **external_chat_id** as the primary lookup key (Bitrix often sends internal user id like `1` for the operator). Mapping is resolved by **ol_map_find_by_chat_id(portal, external_chat_id)** first; **external_user_id** is optional. Inbound stores the same `chat.id`/`user.id` in Open Lines and in `ol_map`, so outbound events that echo back `data.MESSAGES[0].chat.id` find the correct peer.

---

## Why Bitrix shows “message cannot be dispatched”

- **Handler URL not set or not reachable:** Bitrix sends outbound events to the URL from **event.bind**(`OnImConnectorMessageAdd`, handlerUrl). We also set **imconnector.connector.data.set** `DATA.url` to the same handler URL so the connector has an explicit endpoint. If the handler URL was never set (e.g. event.bind failed or only ran at install for another line), or the server is unreachable (non-HTTPS, wrong host, firewall/Cloudflare/Render blocking), Bitrix will not deliver the event and shows “cannot be dispatched”.
- **Connector not active:** The Open Line must be activated with **imconnector.activate** (ACTIVE=1) for the chosen LINE. Saving in Open Lines settings does both **imconnector.activate** and **imconnector.connector.data.set** and **event.bind**.
- **Diagnostics:** Open Lines settings page now shows connector status (imconnector.status), handler URL, and a **Re-apply config** button to re-send activate + connector data + event.bind for the current line.

---

## File-by-file changes (latest)

| File | Change |
|------|--------|
| **lib/bootstrap.php** | Added index **idx_ol_map_portal_chat** on `(portal, external_chat_id)`. **ol_map_find_by_chat_id($portal, $externalChatId)** for lookup by chat id only. Also member_map table. |
| **lib/b24.php** | **b24_portal_from_event($payload)**: resolve portal by `member_id` → `member_map.domain`, then `member_id` → `b24_oauth_tokens.portal`, then `domain`/`DOMAIN` from payload (including under `data`/`DATA`). No “first portal” inside this function. **b24_set_member_map($memberId, $domain)**: upsert into `member_map`. **b24_save_oauth_tokens()**: after saving tokens, if `member_id` present, call `b24_set_member_map()` so install/OAuth populates `member_map`. |
| **openlines/handler.php** | **First line:** logs **`[OL HANDLER] hit`** + ISO date + method, content_type, remote, body_len (grep logs for this). Then parse JSON/form; log event + data keys; **extract_external_ids**; **ol_map_find_by_chat_id** → **ol_map_resolve_to_peer**; Grey send; **imconnector.send.status.delivery** / **undelivered**; always 200 + JSON. |
| **openlines/ping.php** | Health check: always 200, body `{"ok":true,"pong":true}`. Logs **`[OL PING] hit`** + ISO date. Use to verify Bitrix can reach the server. |
| **docs/B24_OPENLINES_CALLBACK_FIELDS.md** | MCP findings: DATA.url = event callback URL; event.bind(OnImConnectorMessageAdd, handler); what causes “message not delivered”. |
| **openlines/settings.php** | **Diagnostics** panel: expected event callback URL, settings URL, clickable **ping URL** (opens in new tab, should show pong); Re-apply connector config button. |
| **ajax/openlines_save.php** | **imconnector.activate** + **imconnector.connector.data.set** with **DATA.url = handler URL** (`/openlines/handler.php`), url_im = contact_center.php, then **event.bind**(OnImConnectorMessageAdd, handlerUrl). Ensures outbound handler URL is set in both DATA and event. |
| **ajax/get_settings.php** | Returns **openlines_handler_url** and enriched **connector_status** (configured, error, status, connector_id) from imconnector.status for current line. |
| **openlines/settings.php** | Shows handler URL, connector detail (configured/status), and **Re-apply config** button that re-sends openlines_save with current line_id. |
| **ajax/test_openlines_outbound.php** | Protected POST: builds minimal OnImConnectorMessageAdd from real ol_map row (portal + optional external_chat_id), POSTs to handler URL, returns handler response. Confirms send path without Bitrix. |
| **lib/grey.php** | **grey_normalize_peer($peer)** for E.164 / @username / numeric id. **grey_peer_likely_sendable($peer)** to reject digits-only before Grey send. |
| **webhook/grey_inbound.php** | Resolve phone from Grey API when chatId but no phone; then **peer = grey_normalize_peer(phone ?: chatId)** for ol_map (same format as Deal). After **ol_map_get_or_create()**, log **`[Inbound->OL] map saved`** with portal, peer, external_chat_id. |
| **openlines/handler.php** | Get peer from ol_map; **grey_normalize_peer**; reject if !**grey_peer_likely_sendable** (undelivered + 200). Send to Grey; **imconnector.send.status.delivery** on success, **undelivered** on failure or invalid peer. |
| **openlines/diag_chat.php** | GET ?token=...&portal=...&external_chat_id=... (DEBUG_OL_MAP_TOKEN). Shows tenant_id, stored peer, normalized peer, Grey sendable? |
| **ajax/deal_send.php**, **ajax/send_message.php** | Use **grey_normalize_peer** for peer sent to Grey. |
| **ajax/debug_openlines_event.php** | POST event_payload to test handler. |
| **ajax/debug_ol_map.php** | GET ?token=...&portal=...&external_chat_id=... or &peer=... (DEBUG_OL_MAP_TOKEN). Returns ol_map rows. |
| **config.example.php** | Optional **DEBUG_OL_MAP_TOKEN**. |

---

## Step-by-step reproduction + fix confirmation checklist

1. **Open Lines settings → Diagnostics shows correct handler URL**  
   Open Open Lines → Connectors → Telegram (GreyTG). In **Diagnostics**, confirm “Event callback (outbound)” URL is `PUBLIC_URL/openlines/handler.php` (HTTPS). If connector is not active or URL looks wrong, click **Re-apply connector config** (with the correct line selected) and refresh.

2. **Click ping URL → logs show [OL PING]**  
   In Diagnostics, click the **ping** link (or open `PUBLIC_URL/openlines/ping.php`). Page must return 200 and show “pong”. In server (e.g. Render) logs, grep for **`[OL PING]`** — you must see a line like `[OL PING] hit 2025-02-13T...`. If not, Bitrix cannot reach your host.

3. **Send a reply in Open Lines → logs show handler hit**  
   Send a message from Telegram so a chat exists in Open Lines; then reply from Open Lines (Messenger → Chats). In server logs, grep for **`[OL HANDLER]`** — you must see a hit immediately (method=POST, body_len>0). If **`[OL HANDLER]`** never appears, Bitrix is not calling our event callback (wrong URL, connector inactive, or network/SSL).

4. **Grey history shows outbound**  
   After a successful reply, Grey TG message history shows the outbound message; our handler log shows “Grey send OK” and “Delivery status OK”.

5. **Bitrix no longer shows “message not delivered”**  
   The message in Bitrix Open Lines chat shows as delivered, not “сообщение не доставлено”.

---

## Peer format (invalid_peer fix)

**Grey expects peer as:** E.164 phone (e.g. `+79001234567`), or `@username`, or numeric user/chat id. The **Deal path** uses **E.164 phone** from CRM contact (normalize_phone → Grey). Open Lines outbound must use the **same peer string** stored when the chat was created (inbound).

**Changes:**
- **lib/grey.php**: **grey_normalize_peer($peer)** — normalizes to E.164 when input looks like phone; leaves @username and numeric id as-is. **grey_peer_likely_sendable($peer)** — true for E.164 or @username (digits-only often causes invalid_peer).
- **webhook/grey_inbound.php**: Try to **resolve phone from Grey API** when we have chatId but no phone (before using chatId as identifier). Store **peer = grey_normalize_peer(phone ?: chatId)** so ol_map has E.164 or @ when possible.
- **openlines/handler.php**: Resolve **peer** from ol_map only; **grey_normalize_peer** before send; **reject** when !grey_peer_likely_sendable (log, call **imconnector.send.status.undelivered**, return 200 + error). On Grey send failure, call undelivered (already done).
- **ajax/deal_send.php**, **ajax/send_message.php**: Use **grey_normalize_peer** for peer sent to Grey (same format as Open Lines).
- **openlines/diag_chat.php**: GET `?token=...&portal=...&external_chat_id=...` shows mapped tenant_id, stored peer, normalized peer, and whether peer is Grey-sendable (DEBUG_OL_MAP_TOKEN required).

**Test checklist (peer fix):**
1. **Inbound creates chat** — Send a message from Telegram (with phone in payload or resolvable from Grey). Inbound runs; ol_map stores peer in E.164 or @username when possible.
2. **Reply in Open Lines sends to Grey** — Reply from Open Lines; handler uses ol_map.peer; Grey send succeeds; confirm in Grey history.
3. **Bitrix marks message delivered** — Handler calls imconnector.send.status.delivery; Bitrix shows delivered.
4. **Existing chats with digits-only peer** — Open `openlines/diag_chat.php?token=...&portal=...&external_chat_id=tg_c_...`. If “Grey sendable? No”, send a **new** message from Telegram so the chat is re-linked with E.164 or @username.

---

## Test plan (original)

1. **Inbound Telegram creates OL chat and stores mapping**  
   Send a message from Telegram. In Bitrix24 (Messenger → Chats) a new chat appears. Logs show `[Inbound->OL] map saved` with portal, peer, external_chat_id, external_user_id. Optional: `ajax/debug_ol_map.php?token=...&portal=...&external_chat_id=tg_c_...` to confirm the row.

2. **Reply in OL triggers handler and finds mapping by external_chat_id**  
   Reply from Open Lines. Handler log shows `Extracted` with `external_chat_id` (e.g. `tg_c_...`) and no "No ol_map" — mapping found by chat id.

3. **Grey send succeeds**  
   Handler log shows `Grey send OK`. Message appears in Telegram.

4. **Delivery status succeeds and pending clears**  
   Handler calls `imconnector.send.status.delivery` after Grey success; log should show `delivery_ok` or `delivery_sent`. In Bitrix24 the message should no longer show as “pending” and should show as delivered.

---

## Optional verification

- **Portal resolution**: If events include `member_id`, ensure install or OAuth save has stored it (so `member_map` or `b24_oauth_tokens.member_id` is set). Otherwise handler falls back to first portal and logs “Using first portal fallback”.
- **Debug mappings**: Set `DEBUG_OL_MAP_TOKEN`; call `GET /ajax/debug_ol_map.php?token=...&portal=...&external_chat_id=...` or `&peer=...`.
- **imconnector.send.status.undelivered**: Called when mapping missing or Grey fails; handler catches if unsupported.
