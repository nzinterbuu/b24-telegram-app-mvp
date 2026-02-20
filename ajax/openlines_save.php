<?php
/**
 * Save Open Line ID for the portal and activate connector for that line.
 * Calls imconnector.activate + imconnector.connector.data.set (OAuth).
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $lineId = isset($req['line_id']) ? trim((string)$req['line_id']) : '';

  if (empty($auth['access_token']) && empty($auth['AUTH_ID'])) {
    json_response(['ok' => false, 'error' => 'Auth required']);
    exit;
  }

  $portal = portal_key($auth);
  $public = rtrim(cfg('PUBLIC_URL'), '/');
  $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';

  if ($lineId !== '') {
    b24_call('imconnector.activate', [
      'CONNECTOR' => $connectorId,
      'LINE' => (int)$lineId,
      'ACTIVE' => 1,
    ], $auth);
    b24_call('imconnector.connector.data.set', [
      'CONNECTOR' => $connectorId,
      'LINE' => (int)$lineId,
      'DATA' => [
        'id' => 'grey_tg',
        'url' => $public,
        'url_im' => $public . '/contact_center.php',
        'name' => 'Telegram (GreyTG)',
      ],
    ], $auth);
    set_portal_line_id($portal, $lineId);
    log_debug('Open Line saved and connector activated', ['portal' => $portal, 'line_id' => $lineId]);
  } else {
    set_portal_line_id($portal, '');
    log_debug('Open Line cleared', ['portal' => $portal]);
  }

  json_response(['ok' => true, 'line_id' => $lineId]);
} catch (Throwable $e) {
  log_debug('openlines_save error', ['e' => $e->getMessage()]);
  json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
