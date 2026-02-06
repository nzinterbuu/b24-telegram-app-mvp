<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  b24_call('user.current', [], $auth);

  $name = trim((string)($req['name'] ?? ''));
  if ($name === '') throw new Exception('Tenant name is required.');

  $callbackUrl = trim((string)($req['callback_url'] ?? ''));
  $body = ['name' => $name];
  if ($callbackUrl !== '') $body['callback_url'] = $callbackUrl;

  $res = grey_app_call('/tenants', 'POST', $body);
  $tenant = is_array($res) && isset($res['id']) ? $res : ($res['tenant'] ?? $res);
  if (!is_array($tenant)) $tenant = ['id' => $res['id'] ?? null, 'name' => $name];

  json_response(['ok' => true, 'tenant' => $tenant]);
} catch (Throwable $e) {
  json_response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
