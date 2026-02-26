<?php
/**
 * Protected test hook: simulates OnImConnectorMessageAdd with real ol_map data and runs the same
 * send-to-Grey path (by POSTing to openlines/handler.php). Use to verify outbound logic without Bitrix.
 *
 * POST JSON: { "auth": {...}, "external_chat_id": "tg_c_..." (optional), "text": "Test message" (optional) }
 * If external_chat_id omitted, uses first ol_map row for the portal.
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
  if ($lineId === null || $lineId === '') {
    json_response(['ok' => false, 'error' => 'No Open Line configured for this portal']);
    exit;
  }

  $pdo = ensure_db();
  $externalChatId = trim((string)($req['external_chat_id'] ?? ''));
  $text = trim((string)($req['text'] ?? 'Test from test_openlines_outbound'));

  if ($externalChatId !== '') {
    $stmt = $pdo->prepare("SELECT tenant_id, peer, external_user_id, external_chat_id FROM ol_map WHERE portal=? AND external_chat_id=? LIMIT 1");
    $stmt->execute([$portal, $externalChatId]);
  } else {
    $stmt = $pdo->prepare("SELECT tenant_id, peer, external_user_id, external_chat_id FROM ol_map WHERE portal=? LIMIT 1");
    $stmt->execute([$portal]);
  }
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    json_response(['ok' => false, 'error' => 'No ol_map row found for this portal' . ($externalChatId ? ' and external_chat_id' : '')]);
    exit;
  }

  $externalUserId = $row['external_user_id'] ?? '';
  $externalChatId = $row['external_chat_id'];

  $tokens = b24_get_oauth_tokens($portal);
  $memberId = $tokens['member_id'] ?? null;

  $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
  $payload = [
    'event' => 'OnImConnectorMessageAdd',
    'member_id' => $memberId,
    'data' => [
      'connector' => $connectorId,
      'line' => $lineId,
      'LINE' => (int)$lineId,
      'MESSAGES' => [
        [
          'message' => ['text' => $text, 'id' => ['test_ol_' . time()]],
          'chat' => ['id' => $externalChatId],
          'user' => ['id' => $externalUserId],
        ],
      ],
    ],
  ];

  $public = rtrim(cfg('PUBLIC_URL'), '/');
  $handlerUrl = $public . '/openlines/handler.php';
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
      'content' => $body,
      'timeout' => 30,
    ],
  ]);
  $response = @file_get_contents($handlerUrl, false, $ctx);
  $code = 200;
  if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
    $code = (int)$m[0];
  }
  $decoded = is_string($response) ? json_decode($response, true) : null;

  log_debug('test_openlines_outbound', [
    'portal' => $portal,
    'external_chat_id' => $externalChatId,
    'handler_http_code' => $code,
    'handler_response' => $decoded,
  ]);

  json_response([
    'ok' => $decoded['ok'] ?? false,
    'handler_http_code' => $code,
    'handler_response' => $decoded,
    'payload_preview' => ['event' => $payload['event'], 'external_chat_id' => $externalChatId, 'text_len' => strlen($text)],
  ]);
} catch (Throwable $e) {
  log_debug('test_openlines_outbound error', ['e' => $e->getMessage()]);
  json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
