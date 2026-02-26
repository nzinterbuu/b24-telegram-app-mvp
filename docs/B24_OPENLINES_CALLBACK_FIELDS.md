# Bitrix24 Open Lines connector callback — MCP findings

## 1) `imconnector.connector.data.set` — DATA fields

**Source:** `imconnector.connector.data.set` (REST API).

| Field    | Type   | Purpose |
|----------|--------|--------|
| **id**   | string | Identifier of the account connected to this connector (e.g. `grey_tg`, `123`). |
| **url**  | string | **Main link** — "ссылки на чат". For REST connectors this is the endpoint Bitrix uses for the connector (outbound events / callback). Must be the **event callback URL** where Bitrix POSTs operator replies (`OnImConnectorMessageAdd`). |
| **url_im** | string | Used in the **widget** ("url_im используется в виджете"). If not set, **url** is used. Typically the Contact Center / IM widget URL (e.g. contact_center.php). |
| **name** | string | Channel name shown in the widget. |

**Conclusion:** Set **DATA.url** to the **event callback URL** (`PUBLIC_URL + '/openlines/handler.php'`). Set **DATA.url_im** to the settings/widget URL (e.g. contact_center.php) so the widget works; do not overwrite **url** with the settings page.

---

## 2) Event callback URL for outbound (operator reply)

- **Event:** `OnImConnectorMessageAdd` — "При получении новых сообщений" (new message from Open Lines; fired when operator sends a message in the connector chat).
- **How Bitrix invokes the callback:**
  1. **event.bind** — `event.bind({ event: 'OnImConnectorMessageAdd', handler: 'https://your-app/openlines/handler.php' })`. The **handler** parameter is the URL to which Bitrix sends the event (POST).
  2. **DATA.url** — For REST connectors, Open Lines may also use the URL stored in `imconnector.connector.data.set` DATA.**url** as the endpoint for sending outbound messages. So **DATA.url must be the handler URL** so Bitrix can POST operator replies to it.

**Required:** Both `event.bind(OnImConnectorMessageAdd, handlerUrl)` and `imconnector.connector.data.set` with **DATA.url = handlerUrl** for the correct CONNECTOR and LINE.

---

## 3) Settings / config UI URL

- **Not** in connector DATA. The settings UI is the **placement** where the connector is configured: Open Lines → Connectors → [Connector] opens the **PLACEMENT_HANDLER** URL registered in **imconnector.register** (e.g. `openlines/settings.php`).
- **url_im** in DATA is for the **chat widget** (e.g. contact center iframe), not the connector settings page.

---

## 4) Incoming message endpoint

- Incoming messages (from Telegram to Bitrix) are sent **by our app** to Bitrix via **imconnector.send.messages** (we call Bitrix REST, Bitrix does not POST to us for incoming). So there is no separate "incoming message endpoint" on our side — we push into Bitrix from the Grey webhook.

---

## 5) HTTP response and timeouts

- **event.bind** docs do not specify a required HTTP code or timeout. Best practice: return **200 OK** quickly; respond with a JSON body. Non-2xx or timeout may cause Bitrix to treat the delivery as failed and show "сообщение не доставлено" (message not delivered).
- Always return **200** and indicate errors in the body (e.g. `{ "ok": false, "error": "..." }`) so Bitrix does not retry unnecessarily.

---

## 6) What causes "message not delivered" (сообщение не доставлено)

- **Handler URL not reached** — Bitrix cannot access the callback URL (wrong URL, not HTTPS, firewall/Cloudflare/Render, SSL error).
- **Callback URL not set** — DATA.url or event.bind handler wrong/missing for this LINE/CONNECTOR.
- **Connector not active** — Connector must be activated with **imconnector.activate**(CONNECTOR, LINE, ACTIVE=1) for the selected LINE.
- **Wrong CONNECTOR or LINE** — CONNECTOR id must match what we use in imconnector.send.messages (e.g. `telegram_grey`); LINE must be the one shown in Open Lines UI.
- **Non-200 or timeout** — Handler returns error code or does not respond in time; Bitrix marks as not delivered.

---

## 7) Parameter names (case-sensitive)

- **imconnector.connector.data.set:** `CONNECTOR` (string), `LINE` (integer), `DATA` (object with **id**, **url**, **url_im**, **name**).
- **event.bind:** **event** (string, e.g. `OnImConnectorMessageAdd`), **handler** (string, full URL).
- **imconnector.activate:** `CONNECTOR`, `LINE`, `ACTIVE` (1 or 0).
