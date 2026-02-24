# Deal menu + first-message Open Lines notification

## Feature 1 — Telegram Chat in Deal card menu

**Goal:** Show "Telegram Chat" as an item in the Deal card’s standard menu (toolbar/sidebar with Activity, Comment, Messages, etc.) and open the chat UI from there.

### Placement

- **Code:** `CRM_DEAL_DETAIL_TOOLBAR`  
  Adds a menu item in the Deal card toolbar (Bitrix24 opens the handler in a slider/popup when the user clicks it).
- **Handler:** `deal_menu.php` — same UX as the Deal tab: deal context from `PLACEMENT_OPTIONS`, send via `ajax/deal_send.php`.

### Implementation

- **install.php**  
  - Unbind (idempotent): `CRM_DEAL_DETAIL_TOOLBAR` → `deal_menu.php`, plus existing Deal tab and Contact Center.  
  - Bind: `CRM_DEAL_DETAIL_TOOLBAR` → `deal_menu.php` (TITLE "Telegram Chat", GROUP_NAME "communication", OPTIONS width/height).  
  - Deal tab `CRM_DEAL_DETAIL_TAB` → `deal_tab.php` kept as fallback.
- **uninstall.php**  
  - Unbind `CRM_DEAL_DETAIL_TOOLBAR` → `deal_menu.php`.
- **deal_menu.php** (new)  
  - Reads deal ID from `PLACEMENT_OPTIONS` (same parsing as `deal_tab.php`).  
  - Renders the same Telegram chat UI (message input, Send).  
  - Uses `ajax/deal_send.php` with `deal_id` and optional `placement_options`.

### Constraints

- Toolbar placement is supported in Bitrix24; exact position (top dropdown vs right menu) depends on portal layout. If your portal shows it in a dropdown, "Telegram Chat" appears there; otherwise in the deal card menu area.

---

## Feature 2 — Auto-open Open Lines chat on first inbound message

**Goal:** On the **first** inbound Telegram message from a customer (peer), inject into Open Lines and notify the operator with a **clickable link** that opens that Open Lines chat in Bitrix24.

### Constraints

- The inbound webhook (Grey → our server) is server-to-server and cannot open the user’s browser directly.
- So we use **Bitrix24 mechanisms:** inject message via `imconnector.send.messages`, then send **im.notify** with a **deep link** to the Open Lines chat. The operator clicks the link to open the chat in Messenger/Contact Center.

### First-message detection

- **ol_map_get_or_create** (in `lib/bootstrap.php`) now returns **`is_new`**: `true` when the row was just inserted (first time we see this portal+tenant_id+peer), `false` when the row already existed.
- Inbound flow already: resolves portal (tenant → user_settings), `LINE_ID` from portal_settings, gets/creates external IDs via `ol_map_get_or_create`, calls `imconnector.send.messages` with OAuth. No new tables; `ol_map` is unchanged (we only use the insert-vs-select result).

### Notify and deep link (first inbound only)

- After a successful `imconnector.send.messages`, if **`ext['is_new']`** and we have **`olChatId`** (from Bitrix response):
  - **Target user (MVP):** Deal responsible (`ASSIGNED_BY_ID`) if we have a deal and contact; else first user with `user_settings` for this tenant; else first admin (`user.get` filter ADMIN).
  - **Deep link:** `https://{portal}/online/im/chat/{olChatId}/`  
    (Bitrix24 web interface uses `/online/im/chat/{id}/` to open an IM chat by ID; Open Lines sessions expose a chat ID in the same way.)
  - **im.notify** (OAuth): `to` = target user, `message` = short text + “Open chat: {url}”, `message_out` = 'Y'.
- If `im.notify` fails, we log and continue (inbound still succeeds).

### Operator replies (unchanged)

- **openlines/handler.php** already handles `OnImConnectorMessageAdd`, resolves portal via `b24_portal_from_event` (then fallback to first portal), resolves peer via `ol_map_resolve_to_peer`, sends to Grey, then **imconnector.send.status.delivery** with message.id array and optional `im`. No code changes.

---

## File-by-file summary

| File | Change |
|------|--------|
| **install.php** | `install_unbind_placements`: add `CRM_DEAL_DETAIL_TOOLBAR` → `deal_menu.php`. Bind `CRM_DEAL_DETAIL_TOOLBAR` → `deal_menu.php` (TITLE, GROUP_NAME, OPTIONS). Response includes `deal_menu`. Same in the request-auth install path. |
| **uninstall.php** | Unbind `CRM_DEAL_DETAIL_TOOLBAR` → `deal_menu.php`; response includes `deal_menu`. |
| **deal_menu.php** (new) | Handler for Deal toolbar placement: parses deal ID from `PLACEMENT_OPTIONS`, same UI as deal_tab (message input, Send), calls `ajax/deal_send.php`. |
| **lib/bootstrap.php** | `ol_map_get_or_create`: return value now includes `'is_new' => true` when a new row is inserted, `'is_new' => false` when row existed. |
| **webhook/grey_inbound.php** | After successful Open Lines inject, if `ext['is_new']` and `olChatId`: compute notify user (assigned → tenant user → admin), build `https://{portal}/online/im/chat/{olChatId}/`, call `im.notify` with that link in the message body. |
| **openlines/handler.php** | No changes (portal resolution and delivery already correct). |

---

## DB / migrations

- **No new tables.**  
- **ol_map:** no schema change; we only use the fact that `ol_map_get_or_create` either inserts (first message) or selects (subsequent).  
- **portal_settings:** already has `line_id`, `openlines_last_inject_*`; no change.

---

## Test checklist

### Feature 1 — Deal menu

1. **Reinstall** the app (or run install again) so `CRM_DEAL_DETAIL_TOOLBAR` is bound.
2. Open a **Deal** card in CRM.
3. Find **“Telegram Chat”** in the Deal card menu (toolbar dropdown or right-side menu, depending on portal).
4. Click it: a **slider/popup** opens with the Telegram chat UI (deal # shown, message field, Send).
5. Send a message: it goes via `deal_send.php` to the contact phone; reply appears in Deal timeline as before.
6. **Fallback:** Deal **tab** “Telegram Chat” still works and opens the same UI in the tab.

### Feature 2 — First inbound + Open Lines

1. **Open Line** is configured for the portal (app → Open Lines → select line → Save).
2. From **Telegram**, send a **first** message (a peer that has never written before) to the connected bot.
3. **In Bitrix24:**  
   - A new chat appears in **Messenger → Chats** (Open Lines) for that customer.  
   - The **responsible** (or tenant user, or admin) receives an **im.notify** with text like “New Telegram chat from … Open chat: https://…/online/im/chat/12345/”.
4. **Click “Open chat”** (or the URL): the Open Lines chat opens in the web interface.
5. Reply from that **Open Lines** chat: message is delivered to **Telegram** and status shows delivered in Bitrix24 (handler + delivery status unchanged).

### Operator reply from Open Lines

1. Open the same conversation from **Messenger → Chats** (or via the notification link).
2. Send a reply as operator.
3. Confirm the message appears in **Telegram** and in Bitrix24 as delivered.
