# Open Lines: delivery status + clean text

## Part A — Bitrix delivery status (“сообщение не доставлено”)

### A1) Bitrix API (from b24-dev-mcp)

- **imopenlines.imconnector.send.status.delivery** confirms delivery to the external system.
- **Parameters:** CONNECTOR (connector id), LINE (open line id), MESSAGES (array of items).
- **Each MESSAGES item:** `im` (forward from incoming event: `chat_id`, `message_id`), `message` => `id` => array (IDs in external system), `chat` => `id` (external chat id).
- The **message id** Bitrix uses for matching can appear in the event as `data.MESSAGES[0].message.id` or **`data.MESSAGES[0].im.message_id`** (or `data.im.message_id`). We must forward the **`im`** object from the event in the delivery call.

### A2) Changes in `openlines/handler.php`

1. **Message ID extraction** (`extract_external_ids`):
   - Read **`im`** from `data.MESSAGES[0].im` or `data.im` (and IM variants).
   - **`b24_message_id`** is now filled from **`im.message_id`** / `im.messageId` first; fallback to `message.id` / `message.ID`.
   - Return **`im`** in the extraction result so the delivery call can forward it.

2. **Use extracted `im` for delivery:**
   - `$im` is set from `$extracted['im']` with fallback to `$firstMsg['im']` / `$data['im']`.

3. **Delivery call:**
   - Build MESSAGES item with `message.id` (array), `chat.id`, and **`im`** (from event).
   - Call `imconnector.send.status.delivery` with CONNECTOR, LINE, MESSAGES.
   - Log: `delivery_payload_keys`, `message_id_count`, `has_im`, `delivery_result` (from Bitrix response). On failure log `Delivery status failed` with error.

4. **Diagnostics:** `openlines/diag_chat.php` shows a note: if Bitrix still shows “сообщение не доставлено”, check logs for `Delivery status failed` and that `message_id` is non-empty.

---

## Part B — Clean outgoing text (no BBCode, no operator prefix)

### B1) Where the text comes from

- In the handler, text is taken from the event: `data.MESSAGES[0].message.text` (or TEXT / message / MESSAGE). Bitrix sends rich text with BBCode and can prepend the operator (e.g. `[b]bitrix24@edna.ru:[/b] [br]bbbbbb`).

### B2) `lib/text_sanitize.php`

- **sanitize_openlines_text(string $text): string**
  - Replaces `[br]` with newline.
  - Strips BBCode: `[b]`, `[/b]`, `[i]`, `[u]`, `[s]`, `[code]`, `[quote]`, `[url=...]...[/url]`, and any remaining `[tag]` / `[/tag]`.
  - Removes leading operator prefix: `something@domain:` or `Name:` at the start (regex).
  - Collapses spaces and multiple newlines, trims.
- Used **only** for Open Lines outbound; Deal send path is unchanged.

### B3) Handler

- **Before Grey send:** `$text = sanitize_openlines_text($rawText);`
- Grey is called with `$text`; `message_log_insert` uses `$text`.
- Debug log: `raw_len`, `clean_len`, `preview` (first 50 chars, no PII).

### B4) Deal path

- Deal send (e.g. `ajax/deal_send.php`, `ajax/send_from_deal.php`) is **not** changed; no sanitizer there unless you add it later.

---

## Part C — Regression test checklist

1. **Inbound:** Telegram message → Open Lines chat appears in Bitrix.
2. **Reply text:** From Open Lines, operator sends a message (with or without formatting). Customer receives **plain text** in Telegram (e.g. `bbbbbb`), no `[b]`, `[br]`, no `user@domain:` prefix.
3. **Delivery status:** After replying, Bitrix Open Lines UI shows the message as **delivered** (no “сообщение не доставлено”).
4. **Logs:** Confirm `[OpenLines Handler] Delivery status OK` with `message_id_count` ≥ 1 and `has_im` true when applicable; confirm `imconnector.send.status.delivery` is called with non-empty message id (from `im.message_id` or `message.id`) and returns success.

---

## File-by-file summary

| File | Change |
|------|--------|
| **openlines/handler.php** | Extract `im` and message id from `im.message_id` / `message.id`; use extracted `im` for delivery; add `sanitize_openlines_text($rawText)` before Grey send; log outbound text preview and delivery payload/result. |
| **lib/text_sanitize.php** | New: `sanitize_openlines_text()` — strip BBCode, remove leading operator prefix, normalize whitespace. |
| **openlines/diag_chat.php** | Note for “сообщение не доставлено”: check logs for delivery status and message_id. |

Deal send scripts are unchanged.
