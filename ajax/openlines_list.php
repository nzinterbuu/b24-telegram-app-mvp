<?php
/**
 * List Open Lines configs for the current portal (for Open Line selection UI).
 * Uses imopenlines.config.list.get (OAuth).
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  if (empty($auth['access_token']) && empty($auth['AUTH_ID'])) {
    json_response(['ok' => false, 'error' => 'Auth required']);
    exit;
  }

  $result = b24_call('imopenlines.config.list.get', [
    'PARAMS' => [
      'select' => ['ID', 'LINE_NAME', 'ACTIVE'],
      'order' => ['ID' => 'ASC'],
    ],
    'OPTIONS' => ['QUEUE' => 'N'],
  ], $auth);

  $list = $result['result'] ?? [];
  if (!is_array($list)) {
    $list = [];
  }
  // Bitrix may return associative array (id => config); normalize to indexed for dropdown
  if (!empty($list) && !isset($list[0]) && !array_key_exists(0, $list)) {
    $list = array_values($list);
  }

  json_response(['ok' => true, 'lines' => $list]);
} catch (Throwable $e) {
  log_debug('openlines_list error', ['e' => $e->getMessage()]);
  json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
