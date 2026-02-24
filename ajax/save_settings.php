<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $u = b24_call('user.current', [], $auth);
  $userId = (int)($u['result']['ID'] ?? 0);

  $tenantId = $req['tenant_id'] ?? null;
  $apiToken = isset($req['api_token']) ? trim((string)$req['api_token']) : null;
  $phone = isset($req['phone']) ? trim((string)$req['phone']) : null;
  $lineId = isset($req['line_id']) ? trim((string)$req['line_id']) : null;

  db_save_user_settings($auth, $userId, [
    'tenant_id' => $tenantId,
    'api_token' => $apiToken,
    'phone' => $phone,
  ]);

  $portal = portal_key($auth);
  // Safe debug log for saved Grey tenant (no full token)
  if (cfg('DEBUG')) {
    $tail = $apiToken && strlen($apiToken) >= 4 ? substr($apiToken, -4) : null;
    $ctx = [
      'portal' => $portal,
      'user_id' => $userId,
      'tenant_id' => $tenantId,
      'token_tail' => $tail,
      'has_token' => $apiToken !== null && $apiToken !== '',
    ];
    @error_log('[Settings] saved grey tenant ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));
  }
  if ($lineId !== null) {
    set_portal_line_id($portal, $lineId);
  }

  // Grey TG API (https://grey-tg.onrender.com/docs) does not expose an endpoint to update callback_url.
  // callback_url can only be set when creating a tenant (POST /tenants). For existing tenants, set it in Grey TG admin or create a new tenant with the URL.
  $callbackUrl = rtrim(cfg('PUBLIC_URL'), '/') . '/webhook/grey_inbound.php';

  json_response([
    'ok' => true,
    'user_id' => $userId,
    'callback_set' => false,
    'callback_url' => $callbackUrl,
    'callback_note' => 'Grey TG API does not support updating callback for existing tenants. Set this URL when creating a tenant, or in Grey TG admin.',
  ]);
} catch (Throwable $e) {
  json_response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
