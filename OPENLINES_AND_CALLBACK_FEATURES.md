# Open Lines + Grey callback features

## Feature 1 — Set/update Grey TG tenant callback automatically

- **Grey API:** `PATCH /tenants/{tenant_id}` with body `{ "callback_url": "string" }` (Swagger: grey-tg.onrender.com/docs). Field name: `callback_url`. To clear: `callback_url: ""`.
- **lib/grey.php:** Added `grey_update_tenant($tenantId, $patchPayload)` — calls `grey_app_call('/tenants/'.rawurlencode($tenantId), 'PATCH', $patchPayload)` (uses app-level server token from `GREY_API_SERVER_TOKEN`).
- **lib/bootstrap.php:** `user_settings` table: added columns `callback_set_at` (INTEGER), `callback_error` (TEXT); migration with ALTER for existing DBs.
- **lib/b24.php:** `db_get_user_settings` returns `callback_set_at`, `callback_error`. `db_save_user_settings` accepts and persists them; on CONFLICT only `tenant_id`, `api_token`, `phone` are updated (callback fields left unchanged). Added `db_update_callback_status($portal, $userId, $callbackSetAt, $callbackError)`.
- **ajax/save_settings.php:** After saving tenant_id/api_token/phone, if tenant_id and api_token are present: calls `grey_update_tenant(tenant_id, ['callback_url' => PUBLIC_URL.'/webhook/grey_inbound.php'])`. On success: `db_update_callback_status(portal, userId, time(), null)`. On failure: `db_update_callback_status(portal, userId, null, error_message)`, logs error (no full token), returns `callback_error` in JSON.
- **ajax/grey_logout.php:** After Grey `POST /logout`, calls `grey_update_tenant(tenant_id, ['callback_url' => ''])`, then `db_update_callback_status(portal, userId, null, null)` and `db_save_user_settings` with tenant_id/api_token cleared (disconnect).
- **Diagnostics:** `ajax/get_settings.php` already returns `callback_set_at` and `callback_error` (from `db_get_user_settings`). Main app shows "Callback: set at … / not set" and "Last error: …" in the Inbound webhook card (element `#callback_status`).

---

## Feature 2 — Configure Open Lines only from Bitrix24 Open Lines section

- **Bitrix:** `imconnector.register` parameter `PLACEMENT_HANDLER` is the URL opened when the admin clicks the connector in Open Lines → Connectors (slider UI). `imconnector.connector.data.set` DATA: `id`, `url`, `url_im`, `name` (links for chat/widget). Connect/activate flow is: admin opens connector → our settings page → select line → we call `imconnector.activate` + `connector.data.set` and persist LINE_ID.
- **openlines/settings.php (new):** Dedicated connector settings page. Lists Open Lines via `ajax/openlines_list.php`, dropdown to select line; "Connect" calls `ajax/openlines_save.php` with `line_id` (activates connector, sets DATA, persists line_id); "Disconnect" calls `openlines_save.php` with `line_id: ''` (deactivates connector for current line, clears stored line_id). Shows status (stored line, connector active). Opened from Open Lines → Connectors → Telegram (GreyTG).
- **install.php:** `PLACEMENT_HANDLER` changed from `contact_center.php` to `openlines/settings.php`. No `imconnector.activate` on install; activation only from Open Lines settings UI.
- **ajax/openlines_save.php:** When disconnecting (`line_id === ''`): gets current `get_portal_line_id($portal)`, calls `imconnector.activate(CONNECTOR, LINE, ACTIVE=0)`, then `set_portal_line_id($portal, '')`.
- **Main app UI:** Removed Open Line selector and "Save" from index.php. Replaced with read-only text: "Configure in Bitrix24 → Open Lines → Connectors → Telegram (GreyTG)". Kept status (stored line, last injection) and link to Open Lines diagnostics.
- **public/js/app.js:** Removed `loadOpenLines`, `saveOpenLine`, `showOpenLinesMessage`; removed openline_select handling from `loadSettings`; removed `App.loadOpenLines()` from init.
- **Inbound:** Unchanged: `webhook/grey_inbound.php` uses `get_portal_line_id($portal)`; if no line_id, logs and skips injection (Deal tab and notifications still work).

---

## File-by-file summary

| File | Change |
|------|--------|
| **lib/grey.php** | Added `grey_update_tenant($tenantId, $patchPayload)` (PATCH /tenants/{id}). |
| **lib/bootstrap.php** | user_settings: added `callback_set_at`, `callback_error`; ALTER migration. |
| **lib/b24.php** | db_get_user_settings returns callback_set_at, callback_error; db_save_user_settings accepts them (CONFLICT updates only tenant_id, api_token, phone); added db_update_callback_status. |
| **ajax/save_settings.php** | After save: if tenant_id+api_token, call grey_update_tenant(callback_url); update callback_set_at/callback_error; return callback_set, callback_error. |
| **ajax/grey_logout.php** | After /logout: grey_update_tenant(callback_url: ''); db_update_callback_status(null,null); clear tenant_id/api_token in user_settings. |
| **ajax/openlines_save.php** | On disconnect: imconnector.activate(ACTIVE=0) for current line, then set_portal_line_id(portal,''). |
| **openlines/settings.php** | **New.** Connector settings page: list lines, Connect/Disconnect, persist line_id, show status. |
| **install.php** | PLACEMENT_HANDLER → openlines/settings.php (was contact_center.php). |
| **index.php** | Open Lines card: removed selector and Save; read-only "Configure in Open Lines → Connectors"; added #callback_status for callback diagnostics. |
| **public/js/app.js** | Removed loadOpenLines, saveOpenLine, showOpenLinesMessage, openline_select; added callback_status text in loadSettings; removed loadOpenLines from init. |

---

## Test checklist

1. **Connect Telegram → callback URL is updated on Grey tenant**  
   In the app: select tenant, enter API token, phone, Save; complete OTP (Start OTP → Verify). After Save (with tenant_id + api_token), check Grey tenant (e.g. Grey admin or GET /tenants/{id}) — `callback_url` should be `PUBLIC_URL/webhook/grey_inbound.php`. Main app "Callback status" should show "set at &lt;date&gt;".

2. **Disconnect Telegram → callback cleared**  
   Click "Disconnect (logout)". Check Grey tenant — `callback_url` should be empty. Stored tenant_id/api_token should be cleared; callback status in app should show "not set".

3. **Configure connector from Open Lines section → connector active and LINE_ID saved**  
   In Bitrix24 go to Open Lines → Connectors, open Telegram (GreyTG). In the opened settings page select an Open Line and click Connect. Stored line_id should appear in status; connector should be active for that line. Main app "Open Lines" card status should show the same line.

4. **Inbound Telegram → chat appears in Open Lines**  
   With connector connected and callback set, send a message from Telegram. Chat should appear in Contact Center (Open Lines). Inbound injection uses portal → get_portal_line_id → imconnector.send.messages.

5. **Disconnect connector from Open Lines → inbound no longer creates chats**  
   In Open Lines → Connectors → Telegram (GreyTG), click Disconnect. Stored line_id cleared; imconnector.activate(ACTIVE=0) called. Send a new message from Telegram — it should still create Deal/timeline and notifications but not create an Open Lines chat (injection skipped when line_id is empty).
