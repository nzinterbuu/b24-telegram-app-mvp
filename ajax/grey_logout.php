<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $u = b24_call('user.current', [], $auth);
  $userId = (int)($u['result']['ID'] ?? 0);
  $s = db_get_user_settings($auth, $userId);
  if (empty($s['tenant_id'])) throw new Exception("Tenant ID is not set. Save settings first.");

  $tenantId = $s['tenant_id'];
  $portal = portal_key($auth);

  $body = [];
  $res = grey_call($tenantId, $s['api_token'] ?? null, '/logout', 'POST', $body);
  if (!is_array($res)) $res = [];

  try {
    grey_update_tenant($tenantId, ['callback_url' => '']);
  } catch (Throwable $e) {
    log_debug('Grey callback clear on disconnect failed', ['e' => $e->getMessage()]);
  }

  db_update_callback_status($portal, $userId, null, null);
  db_save_user_settings($auth, $userId, [
    'tenant_id' => null,
    'api_token' => null,
    'phone' => $s['phone'],
  ]);

  json_response($res);
} catch (Throwable $e) {
  json_response(['error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
