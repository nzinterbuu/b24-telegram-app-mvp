# Outbound Open Lines → Telegram fix

## Summary

Operator replies from Bitrix24 Open Lines (Messenger → Chats) are delivered to Telegram via Grey and marked as delivered in Bitrix (no pending state). The handler uses **external_chat_id** as the primary lookup key (Bitrix often sends internal user id like `1` for the operator). Mapping is resolved by **ol_map_find_by_chat_id(portal, external_chat_id)** first; **external_user_id** is optional. Inbound stores the same `chat.id`/`user.id` in Open Lines and in `ol_map`, so outbound events that echo back `data.MESSAGES[0].chat.id` find the correct peer.

---

## File-by-file changes

| File | Change |
|------|--------|
| **lib/bootstrap.php** | Added index **idx_ol_map_portal_chat** on `(portal, external_chat_id)`. **ol_map_find_by_chat_id($portal, $externalChatId)** for lookup by chat id only. Also member_map table. |
| **lib/b24.php** | **b24_portal_from_event($payload)**: resolve portal by `member_id` → `member_map.domain`, then `member_id` → `b24_oauth_tokens.portal`, then `domain`/`DOMAIN` from payload (including under `data`/`DATA`). No “first portal” inside this function. **b24_set_member_map($memberId, $domain)**: upsert into `member_map`. **b24_save_oauth_tokens()**: after saving tokens, if `member_id` present, call `b24_set_member_map()` so install/OAuth populates `member_map`. |
| **openlines/handler.php** | **extract_external_ids($payload)** returns external_chat_id (required), external_user_id (optional, only if `tg_u_...`), b24_message_id. Lookup: **ol_map_find_by_chat_id** first, then **ol_map_resolve_to_peer**. When no mapping: **imconnector.send.status.undelivered**. Parse JSON/form, delivery on success, 200 always. |
| **ajax/openlines_save.php** | After `imconnector.connector.data.set`, call **event.bind** for `OnImConnectorMessageAdd` with handler URL `PUBLIC_URL . '/openlines/handler.php'` so Bitrix always uses the current handler URL when saving the Open Line. |
| **ajax/debug_openlines_event.php** | POST event_payload to test handler. |
| **webhook/grey_inbound.php** | After **ol_map_get_or_create()**, log **`[Inbound->OL] map saved`** with portal, peer, external_chat_id, external_user_id. |
| **ajax/debug_ol_map.php** | GET ?token=...&portal=...&external_chat_id=... or &peer=... (DEBUG_OL_MAP_TOKEN). Returns ol_map rows. |
| **config.example.php** | Optional **DEBUG_OL_MAP_TOKEN**. |

---

## Test plan

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
