# Bitrix24 Marketplace App (MVP): Telegram via Grey TG API

This is an **MVP** Bitrix24 app package you can deploy on any HTTPS host (your server, Render/Fly/Heroku, or local via ngrok),
then upload the ZIP to Bitrix24 (Developer area) for testing.

## What it implements
- **App** (left menu — opens `index.php`):
  - **Tenant management:** list tenants and status, select tenant, refresh list, create new tenant (any name)
  - **Connect / disconnect Telegram:** save API token, phone; Start OTP, Verify, Resend; Disconnect (logout)
- **Deal tab** (`CRM_DEAL_DETAIL_TAB` — inside a Bitrix24 Deal): send a Telegram message to the deal’s contact phone
- **Contact Center** (`CONTACT_CENTER` — inside Bitrix24 Contact Center): chat with customer (send message to phone or @username); inbound messages go to CRM timeline and notifications
- **Replying to incoming messages:** When a customer sends a Telegram message, you get a timeline comment on the deal and (if assigned) an IM notification. To answer: **either** open that **Deal** → **Telegram chat** tab, type your reply and Send (no need to enter the phone), **or** open **Contact Center**, enter the customer’s phone or @username in **To**, type the message and Send.
- **Inbound webhook** (Grey tenant `callback_url`):
  - Creates/updates CRM Contact by phone
  - Creates a new Deal if no existing Deal found for that Contact (MVP rule)
  - Adds a **CRM timeline comment** on the deal (so you see the message on the Deal)
  - Sends an **IM notification** to the deal’s assignee, or to a user with app settings, or to an admin
  - If **Open Lines** is configured (`OPENLINES_LINE_ID` in config): also sends the message into the Open Line so it appears in **Contact Center** and you can reply from there

> Notes:
> - You must set `config.php` values (PUBLIC_URL, GREY_API_BASE, GREY_API_TOKEN_HEADER, B24_CLIENT_ID, B24_CLIENT_SECRET).
> - Bitrix24 auth uses **OAuth 2.0** (no webhook). Tokens are stored and refreshed automatically.
> - Inbound webhook uses stored OAuth tokens — install the app in Bitrix24 first.
> - Some Bitrix24 Open Lines connector functionality is non-trivial; this MVP uses CRM timeline + IM notify.
> - You can extend to full Open Lines connector using `imconnector.*` (scope `imopenlines`).

## Quick start (local dev)
1) Put this folder on a PHP 8.1+ host with HTTPS (or use ngrok).
2) Copy `config.example.php` -> `config.php` and edit.
3) Register the app in Bitrix24 **Developer resources** (or Partner cabinet for marketplace). Optionally set `GREY_API_SERVER_TOKEN` and `GREY_API_TOKEN_HEADER` in config if your Grey API requires auth for listing/creating tenants.
   - Set the application URL: `https://YOUR_PUBLIC_APP_URL/index.php`
   - Set redirect URL: `https://YOUR_PUBLIC_APP_URL/oauth.php`
   - Scopes: basic, crm, imopenlines, contact_center, placement, im
   - Copy `client_id` and `client_secret` to config.php as B24_CLIENT_ID and B24_CLIENT_SECRET
4) **Install the app** in your Bitrix24 portal. **You must be a Bitrix24 administrator** to install (the installer registers placements, which requires admin rights). When Bitrix24 asks for permissions, grant all requested scopes. OAuth tokens are saved during install.
5) Open the app from left menu: select a tenant (or create one with any name), then enter API token if required and phone; start OTP and verify

## Rights required when installing in Bitrix24

- **User role:** You must install the app while logged in as a **Bitrix24 administrator**. Only admins can register placements (deal tab, contact center). If you see "The request requires higher privileges than provided by the access token", log in as an admin and install again.
- **Permissions (scopes)** to grant when adding the app:
  - **basic** — current user info
  - **crm** — deals, contacts, timeline
  - **imopenlines** — open lines (for future use)
  - **contact_center** — contact center placement
  - **placement** — register deal tab and contact center widgets
  - **im** — send IM notifications

## Receiving incoming messages
1. **Tenant callback** is set automatically when you **Save** settings in the app (select tenant, enter API token/phone, then Save). The app calls the Grey API `PUT /tenants/{tenant_id}/callback` with the webhook URL. You can also set it manually in Grey; the URL is `https://YOUR_PUBLIC_APP_URL/webhook/grey_inbound.php` (see app → Inbound webhook).
2. Incoming messages then:
   - **Deal:** Shown as a timeline comment on the deal (and a new deal is created if needed). Open the deal and use the **Telegram chat** tab to reply.
   - **Notification:** You get an IM notification; open the deal from the link to reply.
   - **Contact Center / Open Lines (optional):** In Bitrix24 go to **Contact Center** → add an open channel → choose **Telegram (GreyTG)** (registered on install). After the line is created, copy its **Open Line ID** into `config.php` as `OPENLINES_LINE_ID`. Then incoming messages will also appear in that open line so you can chat in Contact Center.

## Endpoints
- /install.php (registers placements and Open Lines connector)
- /uninstall.php (unregisters placements)
- /webhook/grey_inbound.php (set as Grey tenant callback_url)

## Logs (including on Render.com)
Inbound callback payloads are always logged so you can debug webhook data.
- **Render.com:** Open your service → **Logs** tab. Inbound payloads appear as `INBOUND CALLBACK PAYLOAD {...}` (they go to stderr). The app also tries to write to `logs/app.log`; if the filesystem is read-only, it falls back to the system temp directory.
- **Local / VPS:** Check `logs/app.log` in the project folder.

## Security
This MVP stores tokens in `storage.sqlite` (SQLite) in the app folder.
For production: move to managed DB and encrypt secrets at rest.
