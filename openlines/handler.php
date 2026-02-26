<?php
/**
 * Bitrix24 Open Lines event handler.
 * Called by Bitrix24 on OnImConnectorMessageAdd when an operator sends a message in the connector chat.
 * Parses JSON or form body, resolves portal (member_id → member_map/oauth), resolves peer via ol_map,
 * sends to Grey, then imconnector.send.status.delivery to clear "pending". Returns 200 always.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

// Hard access log at very first line — any request must produce a log (diagnose "message not delivered" / Bitrix not reaching us). Grep: [OL HANDLER]
$raw = file_get_contents('php://input');
@error_log('[OL HANDLER] hit ' . date('c') . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' content_type=' . ($_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '') . ' remote=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' body_len=' . (is_string($raw) ? strlen($raw) : 0));

function pick($a, array $keys) {
  if (!is_array($a)) return null;
  foreach ($keys as $k) {
    if (isset($a[$k])) return $a[$k];
  }
  return null;
}

/**
 * Extract external ids and Bitrix message id from OnImConnectorMessageAdd payload.
 * Bitrix may send internal user id (e.g. 1) for operator — so external_user_id is optional.
 * external_chat_id is the stable key we sent in imconnector.send.messages (chat.id).
 * Key paths: data.MESSAGES[0].chat.id, data.MESSAGES[0].user.id, data.MESSAGES[0].message.id (and DATA/MESSAGE/CHAT/USER variants).
 */
function extract_external_ids(array $payload): array {
  $data = $payload['data'] ?? $payload['DATA'] ?? $payload;
  if (!is_array($data)) $data = [];
  $messages = $data['MESSAGES'] ?? $data['messages'] ?? null;
  $firstMsg = null;
  $message = [];
  $chat = [];
  $user = [];
  if (is_array($messages) && !empty($messages)) {
    $firstMsg = $messages[0];
    $message = is_array($firstMsg['message'] ?? null) ? $firstMsg['message'] : (is_array($firstMsg['MESSAGE'] ?? null) ? $firstMsg['MESSAGE'] : []);
    $chat = is_array($firstMsg['chat'] ?? null) ? $firstMsg['chat'] : (is_array($firstMsg['CHAT'] ?? null) ? $firstMsg['CHAT'] : []);
    $user = is_array($firstMsg['user'] ?? null) ? $firstMsg['user'] : (is_array($firstMsg['USER'] ?? null) ? $firstMsg['USER'] : []);
  } else {
    $firstMsg = null;
    $message = is_array($data['message'] ?? null) ? $data['message'] : (is_array($data['MESSAGE'] ?? null) ? $data['MESSAGE'] : []);
    $chat = is_array($data['chat'] ?? null) ? $data['chat'] : (is_array($data['CHAT'] ?? null) ? $data['CHAT'] : []);
    $user = [];
  }
  if (empty($chat) && is_array($firstMsg)) {
    $chat = is_array($firstMsg['chat'] ?? null) ? $firstMsg['chat'] : (is_array($firstMsg['CHAT'] ?? null) ? $firstMsg['CHAT'] : []);
  }
  if (empty($user)) {
    $user = is_array($data['user'] ?? null) ? $data['user'] : (is_array($data['USER'] ?? null) ? $data['USER'] : []);
  }

  $externalChatId = trim((string)pick($chat, ['id', 'ID', 'external_id']));
  if ($externalChatId === '') $externalChatId = trim((string)pick($message, ['chat_id', 'CHAT_ID']));

  $externalUserIdRaw = pick($user, ['id', 'ID', 'external_id']);
  $externalUserId = '';
  if ($externalUserIdRaw !== null && $externalUserIdRaw !== '') {
    $s = (string)$externalUserIdRaw;
    if (strpos($s, 'tg_u_') === 0 || preg_match('/^tg_[a-f0-9_]+$/i', $s)) {
      $externalUserId = $s;
    }
  }
  if ($externalUserId === '') $externalUserId = trim((string)pick($message, ['user_id', 'USER_ID']));
  if ($externalUserId !== '' && strpos($externalUserId, 'tg_u_') !== 0 && !preg_match('/^tg_/i', $externalUserId)) {
    $externalUserId = '';
  }

  $messageIdRaw = pick($message, ['id', 'ID']);
  if (is_array($messageIdRaw)) {
    $b24MessageId = array_values($messageIdRaw);
  } else {
    $b24MessageId = $messageIdRaw !== null && $messageIdRaw !== '' ? [$messageIdRaw] : [];
  }

  return [
    'external_chat_id' => $externalChatId,
    'external_user_id' => $externalUserId === '' ? null : $externalUserId,
    'b24_message_id' => $b24MessageId,
  ];
}

/** Safe handler log to stderr (Render). No secrets. */
function handler_log(string $msg, array $ctx = []): void {
  $safe = [];
  $allowed = ['method', 'content_type', 'payload_keys', 'parse_mode', 'event', 'data_keys', 'connector', 'line_id', 'message_id', 'external_user_id', 'external_chat_id', 'portal', 'grey_ok', 'delivery_ok', 'delivery_sent', 'error'];
  foreach ($allowed as $k) {
    if (array_key_exists($k, $ctx)) $safe[$k] = $ctx[$k];
  }
  @error_log('[OpenLines Handler] ' . $msg . ($safe ? ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE) : ''));
}

header('Content-Type: application/json; charset=utf-8');

// ——— 1) Parse input: JSON or application/x-www-form-urlencoded / multipart ———
$contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
$payload = null;
$parseMode = 'none';

if (stripos($contentType, 'application/json') !== false && $raw !== '') {
  $payload = is_string($raw) ? json_decode($raw, true) : null;
  $parseMode = is_array($payload) ? 'json' : 'json_fail';
} else {
  if (!empty($_POST)) {
    $payload = $_POST;
    $parseMode = 'post';
    // Bitrix may send nested data as JSON string or base64
    if (isset($payload['data']) && is_string($payload['data'])) {
          $dec = json_decode($payload['data'], true);
          if (is_array($dec)) $payload['data'] = $dec;
        } elseif (isset($payload['DATA']) && is_string($payload['DATA'])) {
          $dec = json_decode($payload['DATA'], true);
          if (is_array($dec)) $payload['DATA'] = $dec;
        }
      }
  if (is_array($payload) && empty($payload) && $raw !== '') {
    parse_str($raw, $parsed);
    if (!empty($parsed)) {
      $payload = $parsed;
      $parseMode = 'form';
      if (isset($payload['data']) && is_string($payload['data'])) {
        $dec = json_decode($payload['data'], true);
        if (is_array($dec)) $payload['data'] = $dec;
      }
      if (isset($payload['DATA']) && is_string($payload['DATA'])) {
        $dec = json_decode($payload['DATA'], true);
        if (is_array($dec)) $payload['DATA'] = $dec;
      }
    }
  }
}

if (!is_array($payload)) {
  handler_log('Parse failed', ['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'content_type' => $contentType, 'payload_keys' => []]);
  json_response(['ok' => false, 'error' => 'Invalid payload']);
  exit;
}

handler_log('Request', ['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'content_type' => $contentType, 'parse_mode' => $parseMode, 'payload_keys' => array_keys($payload)]);

$event = pick($payload, ['event', 'EVENT']);
$data = $payload['data'] ?? $payload['DATA'] ?? $payload;
if (!is_array($data)) $data = [];
handler_log('Event/data', ['event' => $event, 'data_keys' => array_keys($data)]);

if ($event !== 'OnImConnectorMessageAdd' && $event !== 'ONIMCONNECTORMESSAGEADD') {
  handler_log('Unsupported event', ['event' => $event]);
  json_response(['ok' => false, 'error' => 'Unsupported event']);
  exit;
}

$connector = (string)pick($data, ['connector', 'CONNECTOR']);
$lineId = (string)pick($data, ['line', 'LINE', 'line_id', 'LINE_ID']);
$messages = $data['MESSAGES'] ?? $data['messages'] ?? null;
$firstMsg = is_array($messages) && !empty($messages) ? $messages[0] : null;
$im = $firstMsg['im'] ?? $firstMsg['IM'] ?? $data['im'] ?? $data['IM'] ?? null;

$extracted = extract_external_ids($payload);
$externalChatId = $extracted['external_chat_id'];
$externalUserId = $extracted['external_user_id'] ?? '';
$messageIds = $extracted['b24_message_id'];

$message = [];
if (is_array($firstMsg)) {
  $message = is_array($firstMsg['message'] ?? null) ? $firstMsg['message'] : (is_array($firstMsg['MESSAGE'] ?? null) ? $firstMsg['MESSAGE'] : []);
} else {
  $message = is_array($data['message'] ?? null) ? $data['message'] : (is_array($data['MESSAGE'] ?? null) ? $data['MESSAGE'] : []);
}
$text = trim((string)pick($message, ['text', 'TEXT', 'message', 'MESSAGE']));

handler_log('Extracted', ['connector' => $connector, 'line_id' => $lineId, 'message_id' => $messageIds, 'external_user_id' => $externalUserId ?: '(none)', 'external_chat_id' => $externalChatId]);

if ($connector === '' || $lineId === '' || $text === '' || $externalChatId === '') {
  handler_log('Missing data', ['connector' => $connector, 'line_id' => $lineId, 'has_text' => $text !== '', 'external_chat_id' => $externalChatId]);
  json_response(['ok' => false, 'error' => 'Missing connector, line, message text, or external chat id']);
  exit;
}

$connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
if ($connector !== $connectorId) {
  handler_log('Connector mismatch', ['connector' => $connector]);
  json_response(['ok' => true, 'skipped' => 'connector mismatch']);
  exit;
}

// ——— Portal resolution: member_id → member_map / oauth, then domain from payload, then first portal fallback ———
$portal = b24_portal_from_event($payload);
if (!$portal) {
  $portal = b24_get_first_portal();
  if ($portal) handler_log('Using first portal fallback', ['portal' => $portal]);
}
if (!$portal) {
  handler_log('No portal', []);
  json_response(['ok' => false, 'error' => 'No portal (member_id/domain not resolved). Reinstall app or check member_map.']);
  exit;
}

handler_log('Resolve peer', ['portal' => $portal, 'external_chat_id' => $externalChatId, 'external_user_id' => $externalUserId ?: '(none)']);

$mapping = ol_map_find_by_chat_id($portal, $externalChatId);
if (!$mapping && $externalUserId !== '') {
  $mapping = ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId);
}
if (!$mapping) {
  handler_log('No ol_map', ['portal' => $portal, 'external_chat_id' => $externalChatId]);
  try {
    $auth = b24_get_stored_auth($portal);
    if ($auth && !empty($messageIds) && $externalChatId !== '') {
      @b24_call('imconnector.send.status.undelivered', [
        'CONNECTOR' => $connectorId,
        'LINE' => (int)$lineId,
        'MESSAGES' => [
          array_filter([
            'message' => ['id' => $messageIds],
            'chat' => ['id' => $externalChatId],
            'im' => is_array($im) ? $im : null,
          ]),
        ],
      ], $auth);
      handler_log('undelivered sent (no mapping)', []);
    }
  } catch (Throwable $e2) {
    handler_log('undelivered status failed', ['error' => $e2->getMessage()]);
  }
  json_response(['ok' => false, 'error' => 'No mapping for this chat. Send a message from Telegram first so the chat is linked.']);
  exit;
}

$tenantId = $mapping['tenant_id'];
$peer = $mapping['peer'];

$creds = grey_get_tenant_credentials($portal, (string)$tenantId);
$apiToken = $creds['api_token'] ?? null;
// When using portal fallback, creds may have a different tenant_id; use it for Grey so send works
$sendTenantId = isset($creds['tenant_id']) ? (string)$creds['tenant_id'] : (string)$tenantId;

if (!$apiToken) {
  handler_log('No api_token for tenant', ['tenant_id' => $tenantId, 'portal' => $portal]);
  // Try to clear pending in Bitrix so operator sees an error instead of endless \"pending\"
  try {
    $auth = b24_get_stored_auth($portal);
    if ($auth && !empty($messageIds) && $externalChatId !== '') {
      @b24_call('imconnector.send.status.undelivered', [
        'CONNECTOR' => $connectorId,
        'LINE' => (int)$lineId,
        'MESSAGES' => [
          array_filter([
            'message' => ['id' => $messageIds],
            'chat' => ['id' => $externalChatId],
            'im' => is_array($im) ? $im : null,
          ]),
        ],
      ], $auth);
      handler_log('undelivered sent (no api_token)', ['tenant_id' => $tenantId]);
    }
  } catch (Throwable $e2) {
    handler_log('undelivered status failed (no api_token)', ['error' => $e2->getMessage()]);
  }
  json_response(['ok' => false, 'error' => 'No API token for this tenant. Open the app in Bitrix24 → Settings, then save the API token for your Grey Telegram connection (tenant_id: ' . $tenantId . ').']);
  exit;
}

$greyOk = false;
$sent = null;
try {
  $sent = grey_call($sendTenantId, $apiToken, '/messages/send', 'POST', [
    'peer' => $peer,
    'text' => $text,
    'allow_import_contact' => true,
  ]);
  $greyOk = true;
  handler_log('Grey send OK', ['grey_ok' => true]);
} catch (Throwable $e) {
  handler_log('Grey send failed', ['error' => $e->getMessage()]);
  try {
    $auth = b24_get_stored_auth($portal);
    if ($auth && !empty($messageIds) && $externalChatId !== '') {
      @b24_call('imconnector.send.status.undelivered', [
        'CONNECTOR' => $connectorId,
        'LINE' => (int)$lineId,
        'MESSAGES' => [
          array_filter([
            'message' => ['id' => $messageIds],
            'chat' => ['id' => $externalChatId],
            'im' => is_array($im) ? $im : null,
          ]),
        ],
      ], $auth);
    }
  } catch (Throwable $e2) {
    handler_log('undelivered status failed', ['error' => $e2->getMessage()]);
  }
  json_response(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

message_log_insert('out', $portal, $sendTenantId, $peer, $text, null, 'openlines', null);

// ——— Mark delivered in Bitrix: message.id must be array; forward 'im' from event ———
$deliveryOk = false;
if (!empty($messageIds)) {
  $deliveryItem = [
    'message' => ['id' => $messageIds],
    'chat' => ['id' => $externalChatId],
  ];
  if (is_array($im)) {
    $deliveryItem['im'] = $im;
  }
  try {
    $auth = b24_get_stored_auth($portal);
    if ($auth) {
      b24_call('imconnector.send.status.delivery', [
        'CONNECTOR' => $connectorId,
        'LINE' => (int)$lineId,
        'MESSAGES' => [$deliveryItem],
      ], $auth);
      $deliveryOk = true;
      handler_log('Delivery status OK', ['delivery_ok' => true]);
    } else {
      handler_log('No auth for delivery', []);
    }
  } catch (Throwable $e) {
    handler_log('Delivery status failed', ['error' => $e->getMessage()]);
  }
}

json_response(['ok' => true, 'peer' => $peer, 'grey' => $sent, 'delivery_sent' => $deliveryOk]);
