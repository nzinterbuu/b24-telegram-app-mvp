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
| **openlines/handler.php** | **Hard access log** at very start (method, content_type, remote ip, body length) so any request produces a log. Parse JSON and form; log event + data keys; **extract_external_ids**; **ol_map_find_by_chat_id** then **ol_map_resolve_to_peer**; Grey send; **imconnector.send.status.delivery** / **undelivered**; always 200 + JSON. |
| **openlines/ping.php** | Health check: always 200, logs “ping hit”. Use to verify Bitrix can reach the server (same host as handler). |
| **ajax/openlines_save.php** | **imconnector.activate** + **imconnector.connector.data.set** with **DATA.url = handler URL** (`/openlines/handler.php`), url_im = contact_center.php, then **event.bind**(OnImConnectorMessageAdd, handlerUrl). Ensures outbound handler URL is set in both DATA and event. |
| **ajax/get_settings.php** | Returns **openlines_handler_url** and enriched **connector_status** (configured, error, status, connector_id) from imconnector.status for current line. |
| **openlines/settings.php** | Shows handler URL, connector detail (configured/status), and **Re-apply config** button that re-sends openlines_save with current line_id. |
| **ajax/test_openlines_outbound.php** | Protected POST: builds minimal OnImConnectorMessageAdd from real ol_map row (portal + optional external_chat_id), POSTs to handler URL, returns handler response. Confirms send path without Bitrix. |
| **ajax/debug_openlines_event.php** | POST event_payload to test handler. |
| **webhook/grey_inbound.php** | After **ol_map_get_or_create()**, log **`[Inbound->OL] map saved`** with portal, peer, external_chat_id, external_user_id. |
| **ajax/debug_ol_map.php** | GET ?token=...&portal=...&external_chat_id=... or &peer=... (DEBUG_OL_MAP_TOKEN). Returns ol_map rows. |
| **config.example.php** | Optional **DEBUG_OL_MAP_TOKEN**. |

---

## Test checklist (outbound “cannot be dispatched” fix)

1. **Open Lines settings: connector active + handler URL**  
   Open the connector settings page (Open Lines → Connectors → Telegram GreyTG). Confirm Status shows “Connector: active” and “Handler: https://…/openlines/handler.php”. If not, click **Re-apply config** (with a line selected) and refresh.

2. **Reachability**  
   Open `https://YOUR_PUBLIC_URL/openlines/ping.php` in a browser or curl — must return 200 and `{"ok":true,"ping":"openlines"}`. Server logs should show “ping hit”.

3. **Reply in Open Lines produces handler logs**  
   Send a message from Telegram so a chat exists; reply from Open Lines. Immediately check logs: you must see `[OpenLines Handler] HIT method=POST ...` and then `Event/data`, `Extracted`, etc. If HIT never appears, Bitrix is not reaching the handler (check handler URL in Bitrix, HTTPS, firewall, event.bind).

4. **Grey message history shows outbound**  
   After a successful reply, Grey side should show the outbound message; our DB message_log and handler log show “Grey send OK” and “Delivery status OK”.

5. **Bitrix message no longer “cannot be dispatched”**  
   Once the handler is reached and delivery status is sent, the message in Bitrix should show as delivered, not “message cannot be dispatched”.

6. **Test hook (optional)**  
   POST to `ajax/test_openlines_outbound.php` with auth and optional `external_chat_id` / `text`. Response includes handler_http_code and handler_response; use to verify send path without Bitrix.

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
