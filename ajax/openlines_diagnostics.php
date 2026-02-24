<?php
/**
 * Return Open Lines diagnostics for the current portal (for diagnostics page).
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

  $portal = portal_key($auth);
  $lineId = get_portal_line_id($portal);
  $lastInject = get_portal_openlines_last_inject($portal);

  $connectorRegistered = null;
  $connectorActive = null;
  $connectorError = null;

  $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
  if ($lineId !== null && $lineId !== '') {
    try {
      $r = b24_call('imconnector.status', ['CONNECTOR' => $connectorId, 'LINE' => (int)$lineId], $auth);
      $res = $r['result'] ?? $r['RESULT'] ?? [];
      $connectorActive = (isset($res['register']) && $res['register']) || (isset($res['REGISTER']) && $res['REGISTER']) || !empty($res);
      $connectorRegistered = true;
    } catch (Throwable $e) {
      $connectorError = $e->getMessage();
      $connectorActive = false;
      $connectorRegistered = false;
    }
  } else {
    $connectorRegistered = false;
    $connectorActive = false;
  }

  json_response([
    'ok' => true,
    'portal' => $portal,
    'line_id' => $lineId,
    'connector_id' => $connectorId,
    'connector_registered' => $connectorRegistered,
    'connector_active' => $connectorActive,
    'connector_error' => $connectorError,
    'last_inject' => $lastInject,
  ]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
