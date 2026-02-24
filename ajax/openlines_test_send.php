<?php
/**
 * Send a test message to Open Lines via imconnector.send.messages (diagnostics).
 * Creates a dummy chat visible in Contact Center / Messenger → Chats.
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
  if (!$lineId || $lineId === '') {
    json_response(['ok' => false, 'error' => 'Open Line not configured. Select an Open Line in the app and save.']);
    exit;
  }

  $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
  $testUserId = 'tg_test_' . sha1($portal . '|test');
  $testChatId = 'tg_test_c_' . sha1($portal . '|test');
  $messageId = 'tg_test_msg_' . time();

  $result = b24_call('imconnector.send.messages', [
    'CONNECTOR' => $connectorId,
    'LINE' => (int)$lineId,
    'MESSAGES' => [
      [
        'user' => [
          'id' => $testUserId,
          'name' => 'Test',
          'last_name' => 'User',
          'skip_phone_validate' => 'Y',
        ],
        'message' => [
          'id' => $messageId,
          'date' => (string)time(),
          'text' => 'Test message from Open Lines diagnostics at ' . gmdate('Y-m-d H:i:s') . ' UTC.',
        ],
        'chat' => [
          'id' => $testChatId,
          'name' => 'Diagnostics Test Chat',
        ],
      ],
    ],
  ], $auth);

  $res = $result['result'] ?? $result['DATA']['RESULT'] ?? $result['RESULT'] ?? null;
  $first = is_array($res) && isset($res[0]) ? $res[0] : (is_array($res) ? $res : []);
  $sessionId = $first['session']['ID'] ?? $first['session']['id'] ?? null;
  $olChatId = $first['session']['CHAT_ID'] ?? $first['session']['chat_id'] ?? null;

  json_response([
    'ok' => true,
    'message' => 'Test message sent to Open Lines. Check Messenger → Chats for "Diagnostics Test Chat".',
    'session_id' => $sessionId,
    'chat_id' => $olChatId,
  ]);
} catch (Throwable $e) {
  log_debug('openlines_test_send error', ['e' => $e->getMessage()]);
  json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
