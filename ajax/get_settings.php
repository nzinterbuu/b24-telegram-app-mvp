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
  $portal = portal_key($auth);
  $s['line_id'] = get_portal_line_id($portal);
  $s['openlines_last_inject'] = get_portal_openlines_last_inject($portal);
  $s['connector_status'] = null;
  if (!empty($s['line_id'])) {
    try {
      $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
      $r = b24_call('imconnector.status', ['CONNECTOR' => $connectorId, 'LINE' => (int)$s['line_id']], $auth);
      $res = $r['result'] ?? $r['RESULT'] ?? [];
      $s['connector_status'] = ['active' => (isset($res['register']) && $res['register']) || (isset($res['REGISTER']) && $res['REGISTER']) || !empty($res)];
    } catch (Throwable $e) {
      $s['connector_status'] = ['active' => false, 'error' => $e->getMessage()];
    }
  }
  $status = null;
  if (!empty($s['tenant_id'])) {
    try { $status = grey_call($s['tenant_id'], $s['api_token'] ?? null, '/status', 'GET'); }
    catch (Throwable $e) { $status = ['error'=>$e->getMessage()]; }
  }
  json_response(['user_id'=>$userId] + $s + ['status'=>$status]);
} catch (Throwable $e) {
  json_response(['error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
