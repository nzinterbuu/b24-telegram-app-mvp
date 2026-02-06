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
  if ($lineId !== null) {
    set_portal_line_id($portal, $lineId);
  }

  $callbackSet = false;
  if ($tenantId !== null && $tenantId !== '') {
    $callbackUrl = rtrim(cfg('PUBLIC_URL'), '/') . '/webhook/grey_inbound.php';
    try {
      grey_call($tenantId, $apiToken, '/callback', 'PUT', ['callback_url' => $callbackUrl]);
      $callbackSet = true;
    } catch (Throwable $e) {
      log_debug('tenant callback set failed', ['tenant_id' => $tenantId, 'e' => $e->getMessage()]);
    }
  }

  json_response(['ok' => true, 'user_id' => $userId, 'callback_set' => $callbackSet]);
} catch (Throwable $e) {
  json_response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
