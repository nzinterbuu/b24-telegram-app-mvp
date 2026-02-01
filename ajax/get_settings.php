<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $u = b24_call($auth, 'user.current');
  $userId = (int)($u['result']['ID'] ?? 0);

  $s = db_get_user_settings($auth, $userId);
  $status = null;
  if (!empty($s['tenant_id'])) {
    try { $status = grey_call($s['tenant_id'], $s['api_token'] ?? null, '/status', 'GET'); }
    catch (Throwable $e) { $status = ['error'=>$e->getMessage()]; }
  }
  json_response(['user_id'=>$userId] + $s + ['status'=>$status]);
} catch (Throwable $e) {
  json_response(['error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
