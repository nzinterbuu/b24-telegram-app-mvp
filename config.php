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

  // Bitrix24 OAuth (required). From Bitrix24 app registration (partner cabinet or local app settings).
  'B24_CLIENT_ID' => '',
  'B24_CLIENT_SECRET' => '',

  // Enable debug logging to logs/app.log
  'DEBUG' => true,
];
