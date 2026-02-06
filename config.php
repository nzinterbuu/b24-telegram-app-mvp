<?php
// Copy to config.php and edit.

return [
  // Public base URL of this app (no trailing slash)
  'PUBLIC_URL' => 'https://b24-telegram-app-mvp.onrender.com',

  // Grey TG API base URL (no trailing slash), e.g. https://api.yourdomain.com
  'GREY_API_BASE' => 'https://grey-tg.onrender.com',

  // If Grey API requires an API token header, set header name here, e.g. 'Authorization'
  // and in code we will send "Header: Bearer <token>" if token starts with "Bearer ".
  // If empty string, the token is not sent.
  'GREY_API_TOKEN_HEADER' => '',

  // Optional: server token for list/create tenants (used with GREY_API_TOKEN_HEADER).
  'GREY_API_SERVER_TOKEN' => getenv('GREY_API_SERVER_TOKEN') ?: '',

  // Bitrix24 OAuth (required). From Bitrix24 app registration. Prefer env vars (e.g. Render).
  'B24_CLIENT_ID' => getenv('B24_CLIENT_ID') ?: '',
  'B24_CLIENT_SECRET' => getenv('B24_CLIENT_SECRET') ?: '',
  'B24_WEBHOOK_URL' => getenv('B24_WEBHOOK_URL') ?: '',

  // Enable debug logging to logs/app.log
  'DEBUG' => true,
];
