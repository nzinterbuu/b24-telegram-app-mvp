<?php
// Copy to config.php and edit.

return [
  // Public base URL of this app (no trailing slash)
  'PUBLIC_URL' => 'https://YOUR_PUBLIC_APP_URL',

  // Grey TG API base URL (no trailing slash), e.g. https://api.yourdomain.com
  'GREY_API_BASE' => 'https://YOUR_GREY_API_BASE',

  // If Grey API requires an API token header, set header name here, e.g. 'Authorization'
  // and in code we will send "Header: Bearer <token>" if token starts with "Bearer ".
  // If empty string, the token is not sent.
  'GREY_API_TOKEN_HEADER' => 'Authorization',

  // Bitrix24 OAuth (required). From Bitrix24 app registration. Use env vars in production.
  'B24_CLIENT_ID' => getenv('B24_CLIENT_ID') ?: '',
  'B24_CLIENT_SECRET' => getenv('B24_CLIENT_SECRET') ?: '',

  // Enable debug logging to logs/app.log
  'DEBUG' => true,
];
