# Open Lines setup fix — summary

## Problem

- No success notification when saving Open Line in the app.
- Inbound Telegram messages did not create chats in Contact Center / Open Lines (Messenger → Chats).

## Root causes addressed

1. **Save response** — Backend did not return a `message` field; frontend had no toast/notification, so users saw no confirmation.
2. **List response** — Bitrix24 can return open lines as an associative array (id => config); the dropdown expected an indexed array.
3. **Install** — Connector register and event.bind were in one try/catch; "already registered" errors were not treated as idempotent.
4. **Visibility** — No structured logging to stderr for Open Lines injection (Render logs), so failures were hard to debug.

## File-by-file changes

| File | Change |
|------|--------|
| **ajax/openlines_save.php** | Returns `message: "Open line was set successfully."` (or "Open line cleared.") on success. On error returns `ok: false`, `error`, `message` with same text. |
| **index.php** | Added `#openlines_save_message` div for success/error toast. Added link to "Open Lines diagnostics" page. |
| **public/js/app.js** | Added `showOpenLinesMessage(text, isError)` to show success (green) or error (red) in `#openlines_save_message`, auto-hide after 6s. `saveOpenLine()` calls it on success (with `data.message`) or on error (with `data.error` or exception). |
| **ajax/openlines_list.php** | Normalize `result`: if it is an associative array (no key `0`), convert with `array_values()` so the dropdown gets a numeric array. |
| **install.php** | Split connector register and event.bind into separate try/catch. On register catch, ignore errors containing "already" or "exist" (idempotent). No requirement for OPENLINES_LINE_ID at install. |
| **lib/bootstrap.php** | Added `log_openlines($message, $context)` — writes to `error_log` (stderr) with safe keys only (portal, line_id, connector_id, external_user_id, external_chat_id, tenant_id, error, bitrix_error). |
| **webhook/grey_inbound.php** | Before Open Lines block: log "Open Lines not configured" when no line_id. Inside block: call `log_openlines('Inbound inject attempt', ...)` with portal, line_id, connector_id, external ids, tenant_id. When no OAuth: `log_openlines('Injection skipped: no OAuth for portal', ...)`. On success: `log_openlines('Inbound injected OK', ...)`. On catch: `log_openlines('imconnector.send.messages failed', ...)` with error. |
| **pages/openlines_diagnostics.php** (new) | Diagnostics page: loads data from `ajax/openlines_diagnostics.php`, shows portal, stored LINE_ID, connector registered/active, last inject. "Send test message to Open Lines" calls `ajax/openlines_test_send.php`. Uses `apiBase()` so fetch paths work when page is under `pages/`. |
| **ajax/openlines_diagnostics.php** (new) | Returns portal, line_id, connector_id, connector_registered, connector_active, connector_error, last_inject. Uses `imconnector.status` when line_id is set. |
| **ajax/openlines_test_send.php** (new) | Sends one test message via `imconnector.send.messages` with dummy external user/chat (tg_test_*) so a test chat appears in Open Lines. Uses OAuth from request. Returns session_id, chat_id on success. |

## API usage (unchanged, for reference)

- **imopenlines.config.list.get** — PARAMS (select, order), OPTIONS (QUEUE). Returns list of configs (ID, LINE_NAME, etc.). Response may be associative; normalize to array for UI.
- **imconnector.register** — ID, NAME, ICON, PLACEMENT_HANDLER, CHAT_GROUP. Run at install; ignore "already exists" to be idempotent.
- **imconnector.activate** — CONNECTOR, LINE, ACTIVE (1/0). Called from openlines_save when saving a line.
- **imconnector.connector.data.set** — CONNECTOR, LINE, DATA (id, url, url_im, name). Called from openlines_save.
- **imconnector.send.messages** — CONNECTOR, LINE, MESSAGES[].user (id, name, last_name, skip_phone_validate), message (id, date, text), chat (id, name). OAuth required. Used in grey_inbound and openlines_test_send.
- **imconnector.status** — CONNECTOR, LINE. Used in get_settings and openlines_diagnostics.
- **event.bind** — OnImConnectorMessageAdd → openlines/handler.php. Required for operator replies.

## Test checklist

1. **Select Open Line → see success toast**
   - Open the app in Bitrix24.
   - In "Open Lines (Contact Center)", select an Open Line from the dropdown (if empty, create an Open Line in Bitrix24 first).
   - Click **Save**.
   - A green message must appear: **"Open line was set successfully."** (and disappear after a few seconds).
   - If Save fails (e.g. wrong scope), a red message must show the error.

2. **Inbound Telegram → chat appears in Contact Center / Open Lines**
   - Ensure the Grey tenant callback URL is set and the app has OAuth tokens for the portal.
   - Send a new Telegram message to the bot (from a user that has not written before, or from any user if you use the same peer).
   - In Bitrix24 open **Messenger → Chats** (or Contact Center / Open Lines).
   - A new chat must appear for that Telegram user with the message.
   - In Render (or server) logs you should see `[Open Lines] Inbound inject attempt` and then `Inbound injected OK` (or `imconnector.send.messages failed` with error if something is wrong).

3. **Reply in Open Lines → message delivered to Telegram**
   - From the same Open Lines chat, send a reply as operator.
   - The message must appear in Telegram and be marked as delivered in Bitrix24.

4. **Diagnostics**
   - In the app, open **"Open Lines diagnostics"**.
   - Check: Stored LINE_ID, Connector registered/active.
   - Click **"Send test message to Open Lines"**: a test chat must appear in Messenger → Chats named "Diagnostics Test Chat".
