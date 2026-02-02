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

  // Bitrix24: optional fixed webhook mode (for local/testing).
  // Leave empty for OAuth (recommended).
  'B24_WEBHOOK_URL' => 'https://b24-u0gkt4.bitrix24.ru/rest/1/1bsik1fwzw9bloyz/',

  // Enable debug logging to logs/app.log
  'DEBUG' => true,
];
