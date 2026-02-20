# Open Lines (Contact Center) Extension — What Changed

## Summary

Telegram inbound messages now create chats in Bitrix24 Open Lines (Contact Center) as well as in the Deal tab. Operators can reply from Contact Center; replies are sent to Telegram via Grey and delivery is confirmed to Bitrix24.

### Main changes

1. **Per-portal Open Line**  
   Open Line is chosen per portal in the app UI and stored in the DB. No hardcoded `OPENLINES_LINE_ID`.

2. **Stable external IDs**  
   `ol_map` uses deterministic IDs: `tg_u_`/`tg_c_` + SHA1(portal|tenant_id|peer). Key is `(portal, tenant_id, peer)` (no `line_id` in key).

3. **Inbound injection**  
   Webhook resolves portal (from tenant → user_settings), gets `line_id` from `get_portal_line_id($portal)`, gets external IDs from `ol_map_get_or_create($portal, $tenantId, $peer)`, and calls `imconnector.send.messages` with **OAuth** for that portal. Uses `skip_phone_validate => 'Y'`.

4. **Operator replies**  
   `openlines/handler.php` resolves portal via `b24_portal_from_event($payload)` (member_id → portal), resolves peer via `ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId)`, sends to Grey, then confirms with `imconnector.send.status.delivery` (message.id as array, optional `im` from event).

5. **Install**  
   Install only runs `imconnector.register` and `event.bind`. Activation and `connector.data.set` run when the admin saves an Open Line in the app (`ajax/openlines_save.php`).

---

## File-by-file changes

### New files

| File | Purpose |
|------|--------|
| `ajax/openlines_list.php` | Lists Open Lines via `imopenlines.config.list.get` for the Open Line dropdown. |
| `ajax/openlines_save.php` | Saves selected line for portal: updates DB, then `imconnector.activate` + `imconnector.connector.data.set`. |
| `OPENLINES_CHANGES.md` | This summary. |

### Modified files

| File | Changes |
|------|--------|
| **lib/bootstrap.php** | `portal_settings`: added `updated_at`. `ol_map`: new schema (portal, tenant_id, peer, external_user_id, external_chat_id, created_at, updated_at), PK (portal, tenant_id, peer); migration from old schema with `line_id`. `set_portal_line_id`: writes `updated_at`. `ol_map_get_or_create($portal, $tenantId, $peer)`: stable IDs `tg_u_`.sha1(...), `tg_c_`.sha1(...); no `line_id`. New `ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId)`. `ol_map_resolve_to_grey` kept as wrapper around `ol_map_resolve_to_peer`. |
| **lib/b24.php** | New `b24_portal_from_event($payload)` to resolve portal from `member_id` (or domain) for event handlers. |
| **webhook/grey_inbound.php** | Portal from tenant_id via user_settings, else first portal. `get_portal_line_id($portal)` only (no config fallback). Logs "Open Lines not configured" when no line. `ol_map_get_or_create($portal, $tenantId, $peer)` (3 args). `imconnector.send.messages` called with **OAuth** (`b24_get_stored_auth($portal)`). Always `skip_phone_validate => 'Y'`. Logs "Open Lines inbound injected" and errors. |
| **openlines/handler.php** | Portal via `b24_portal_from_event($payload)` then fallback to first portal. Resolve peer with `ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId)`. Support both payload shapes: `data.MESSAGES[0]` and flat `data.message`/`data.chat`. Delivery: `message.id` as array; forward `im` from event when present; use OAuth for `imconnector.send.status.delivery`. Extra debug log for resolve. |
| **install.php** | Removed activation and `connector.data.set` and any `set_portal_line_id` during install. Kept `imconnector.register` and `event.bind`. OAuth (and `member_id`) still saved. |
| **index.php** | New card "Open Lines (Contact Center)" with dropdown and Save button. Init calls `App.loadOpenLines()` after `loadSettings`. |
| **public/js/app.js** | `loadOpenLines()`: fetches `ajax/openlines_list.php`, fills `#openline_select`. `saveOpenLine()`: posts to `ajax/openlines_save.php`. `loadSettings` sets `openline_select` from `data.line_id`. `App` exports `loadOpenLines`, `saveOpenLine`. |

### Unchanged but relevant

- **config.php / config.example.php** — `OPENLINES_LINE_ID` is no longer required; per-portal line is in DB.
- **contact_center.php**, **deal_tab.php** — No changes.
- **README.md** — Can be updated to describe Open Line selection in the app and that install no longer requires a line.

---

## API usage (from Bitrix24 docs)

- **imconnector.send.messages**: CONNECTOR, LINE, MESSAGES[].user (id, name, last_name, phone?, skip_phone_validate), MESSAGES[].message (id, date, text), MESSAGES[].chat (id, name). OAuth required.
- **imconnector.send.status.delivery**: CONNECTOR, LINE, MESSAGES[] with `message.id` as **array** of IDs, `chat.id`; optionally forward incoming `im`.
- **OnImConnectorMessageAdd**: CONNECTOR, LINE, MESSAGES[] (or flat message/chat); may include `im` for delivery confirmation.
- **imopenlines.config.list.get**: PARAMS (select, order, filter), OPTIONS (QUEUE). Returns list of open line configs (ID, LINE_NAME, etc.).

---

## Logging (no secrets)

- `log_debug('Open Lines not configured for portal', ['portal' => $portal])`
- `log_debug('Open Lines inbound injected', ['portal', 'line_id', 'peer'])`
- `log_debug('imconnector.send.messages failed', ['e', 'portal'])`
- `log_debug('openlines handler resolve peer', ['portal', 'external_user_id', 'external_chat_id'])`
- `log_debug('openlines handler no ol_map', ...)`
- `log_debug('Open Line saved and connector activated', ['portal', 'line_id'])`

Enable `DEBUG` in config for these.
