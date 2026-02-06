<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  b24_call('user.current', [], $auth);

  $raw = grey_app_call('/tenants', 'GET');
  $list = [];
  if (is_array($raw)) {
    if (isset($raw[0])) $list = $raw;
    else $list = $raw['items'] ?? $raw['tenants'] ?? $raw['data'] ?? [];
  }
  if (!is_array($list)) $list = [];

  $tenants = [];
  foreach ($list as $t) {
    $id = $t['id'] ?? $t['tenant_id'] ?? null;
    $name = $t['name'] ?? $id ?? '—';
    $tenant = ['id' => $id, 'name' => $name, 'callback_url' => $t['callback_url'] ?? null, 'created_at' => $t['created_at'] ?? null];
    if ($id) {
      try {
        $st = grey_call($id, null, '/status', 'GET');
        $tenant['status'] = $st;
      } catch (Throwable $e) {
        $tenant['status'] = ['error' => $e->getMessage()];
      }
    }
    $tenants[] = $tenant;
  }

  json_response(['tenants' => $tenants]);
} catch (Throwable $e) {
  json_response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
