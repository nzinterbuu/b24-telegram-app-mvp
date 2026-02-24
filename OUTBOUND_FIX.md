# Outbound Open Lines → Telegram fix

## Summary

Operator replies from Bitrix24 Open Lines (Messenger → Chats) are now delivered to Telegram via Grey and marked as delivered in Bitrix (no pending state). The handler parses both JSON and form-encoded payloads, resolves portal by `member_id`, resolves Telegram peer from `ol_map`, sends to Grey, then calls `imconnector.send.status.delivery` with the correct message id array and chat/session identifiers.

---

## File-by-file changes

| File | Change |
|------|--------|
| **lib/bootstrap.php** | In `ensure_db()`, added table **member_map** (`member_id` TEXT PRIMARY KEY, `domain` TEXT NOT NULL, `updated_at` INTEGER). |
| **lib/b24.php** | **b24_portal_from_event($payload)**: resolve portal by `member_id` → `member_map.domain`, then `member_id` → `b24_oauth_tokens.portal`, then `domain`/`DOMAIN` from payload (including under `data`/`DATA`). No “first portal” inside this function. **b24_set_member_map($memberId, $domain)**: upsert into `member_map`. **b24_save_oauth_tokens()**: after saving tokens, if `member_id` present, call `b24_set_member_map()` so install/OAuth populates `member_map`. |
| **openlines/handler.php** | Rewritten: parse JSON or x-www-form-urlencoded/multipart; decode nested `data`/`DATA` if string; safe **handler_log()** (method, content_type, parse_mode, payload_keys, event, connector, line_id, message_id, external ids, portal, grey_ok, delivery_ok, error). Only handle `OnImConnectorMessageAdd`. Extract connector, line_id, message id (as array for Bitrix API), external_user_id, external_chat_id, text. Resolve portal via **b24_portal_from_event()**, fallback to first portal with log. Resolve peer via **ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId)**. Send to Grey `/messages/send`. On Grey failure: try **imconnector.send.status.undelivered** (if API exists). On success: call **imconnector.send.status.delivery** with MESSAGES[].message.id as array, chat.id, and forward `im` from event. Always return HTTP 200 with JSON. |
| **ajax/openlines_save.php** | After `imconnector.connector.data.set`, call **event.bind** for `OnImConnectorMessageAdd` with handler URL `PUBLIC_URL . '/openlines/handler.php'` so Bitrix always uses the current handler URL when saving the Open Line. |
| **ajax/debug_openlines_event.php** | New dev tool. POST JSON: `{ "event_payload": { ... Bitrix event shape ... }, "auth": { "member_id", "domain" } (optional) }`. Forwards to `openlines/handler.php` and returns handler response for testing without Bitrix UI. |

---

## Test checklist

1. **Inbound creates Open Lines chat**  
   Send a message from Telegram to the bot; in Bitrix24 (Messenger → Chats) a new chat should appear for that conversation.

2. **Operator reply triggers handler log**  
   Reply from Open Lines (operator message). Check logs for `[OpenLines Handler]` with `event`, `connector`, `line_id`, `message_id`, `external_chat_id`, `portal`, `grey_ok`, `delivery_ok`. If Bitrix uses form-encoded body, log should show `parse_mode: post` or `form`.

3. **Grey send succeeds**  
   In the same handler log, `grey_ok` should be true. Message should appear in Telegram.

4. **Bitrix delivery status call succeeds and message shows delivered**  
   Handler calls `imconnector.send.status.delivery` after Grey success; log should show `delivery_ok` or `delivery_sent`. In Bitrix24 the message should no longer show as “pending” and should show as delivered.

---

## Optional verification

- **Portal resolution**: If events include `member_id`, ensure install or OAuth save has stored it (so `member_map` or `b24_oauth_tokens.member_id` is set). Otherwise handler falls back to first portal and logs “Using first portal fallback”.
- **Debug tool**: `POST /ajax/debug_openlines_event.php` with a sample `event_payload` (and optional `auth.member_id`/`auth.domain`) to simulate outbound without Bitrix.
- **imconnector.send.status.undelivered**: Used on Grey failure; if Bitrix does not support this method, the handler catches the error and still returns 200 with `ok: false`.
