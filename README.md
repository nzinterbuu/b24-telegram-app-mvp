# Bitrix24 Marketplace App (MVP): Telegram via Grey TG API

This is an **MVP** Bitrix24 app package you can deploy on any HTTPS host (your server, Render/Fly/Heroku, or local via ngrok),
then upload the ZIP to Bitrix24 (Developer area) for testing.

## What it implements
- App UI (Left menu page) with:
  - Connect (save Grey `tenant_id` + API token) and check auth status
  - Start OTP / Verify OTP / Resend / Logout (Telegram session)
- CRM Deal detail tab (`CRM_DEAL_DETAIL_TAB`): send a message to the deal's client phone (from Contact/Company)
- Contact Center tile (`CONTACT_CENTER`): opens the same chat UI (MVP)
- Inbound webhook endpoint (to be used as Grey tenant `callback_url`):
  - Creates/updates CRM Contact by phone
  - Creates a new Deal if no existing Deal found for that Contact (MVP rule)
  - Adds a CRM timeline comment and notifies the assigned user

> Notes:
> - You must set `config.php` values (PUBLIC_URL, GREY_API_BASE, GREY_API_TOKEN_HEADER, B24_CLIENT_ID, B24_CLIENT_SECRET).
> - Bitrix24 auth uses **OAuth 2.0** (no webhook). Tokens are stored and refreshed automatically.
> - Inbound webhook uses stored OAuth tokens — install the app in Bitrix24 first.
> - Some Bitrix24 Open Lines connector functionality is non-trivial; this MVP uses CRM timeline + IM notify.
> - You can extend to full Open Lines connector using `imconnector.*` (scope `imopenlines`).

## Quick start (local dev)
1) Put this folder on a PHP 8.1+ host with HTTPS (or use ngrok).
2) Copy `config.example.php` -> `config.php` and edit.
3) Register the app in Bitrix24 **Developer resources** (or Partner cabinet for marketplace):
   - Set the application URL: `https://YOUR_PUBLIC_APP_URL/index.php`
   - Set redirect URL: `https://YOUR_PUBLIC_APP_URL/oauth.php`
   - Scopes: basic, crm, imopenlines, contact_center, placement, im
   - Copy `client_id` and `client_secret` to config.php as B24_CLIENT_ID and B24_CLIENT_SECRET
4) Install the app in your Bitrix24 portal (OAuth tokens are saved during install).
5) Open the app from left menu, go to **Settings**, enter:
   - Tenant ID (UUID) and API token (if your Grey API requires it)
   - Phone number, start OTP and verify

## Endpoints
- /install.php (registers placements)
- /uninstall.php (unregisters placements)
- /webhook/grey_inbound.php (set as Grey callback_url)

## Security
This MVP stores tokens in `storage.sqlite` (SQLite) in the app folder.
For production: move to managed DB and encrypt secrets at rest.
