<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $u = b24_call($auth, 'user.current');
  $userId = (int)($u['result']['ID'] ?? 0);

  db_save_user_settings($auth, $userId, [
    'tenant_id' => $req['tenant_id'] ?? null,
    'api_token' => $req['api_token'] ?? null,
    'phone' => $req['phone'] ?? null,
  ]);
  json_response(['ok'=>true,'user_id'=>$userId]);
} catch (Throwable $e) {
  json_response(['error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
