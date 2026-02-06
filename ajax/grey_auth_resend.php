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

  // Grey API has no /auth/resend; request new code via /auth/start (same as Start OTP)
  $body = ['phone' => ($s['phone'] ?? '')];
  $res = grey_call($s['tenant_id'], $s['api_token'] ?? null, '/auth/start', 'POST', $body);
  json_response($res);
} catch (Throwable $e) {
  json_response(['error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
