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

function pick($a, array $keys) {
  if (!is_array($a)) return null;
  foreach ($keys as $k) {
    if (isset($a[$k])) return $a[$k];
  }
  return null;
}

/** Safe handler log to stderr (Render). No secrets. */
function handler_log(string $msg, array $ctx = []): void {
  $safe = [];
  $allowed = ['method', 'content_type', 'payload_keys', 'parse_mode', 'event', 'connector', 'line_id', 'message_id', 'external_user_id', 'external_chat_id', 'portal', 'grey_ok', 'delivery_ok', 'delivery_sent', 'error'];
  foreach ($allowed as $k) {
    if (array_key_exists($k, $ctx)) $safe[$k] = $ctx[$k];
  }
  @error_log('[OpenLines Handler] ' . $msg . ($safe ? ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE) : ''));
}

header('Content-Type: application/json; charset=utf-8');

// ——— 1) Parse input: JSON or application/x-www-form-urlencoded / multipart ———
$contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input');
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
if ($event !== 'OnImConnectorMessageAdd' && $event !== 'ONIMCONNECTORMESSAGEADD') {
  handler_log('Unsupported event', ['event' => $event]);
  json_response(['ok' => false, 'error' => 'Unsupported event']);
  exit;
}

$data = $payload['data'] ?? $payload['DATA'] ?? $payload;
if (!is_array($data)) $data = [];

$connector = (string)pick($data, ['connector', 'CONNECTOR']);
$lineId = (string)pick($data, ['line', 'LINE', 'line_id', 'LINE_ID']);
$messages = $data['MESSAGES'] ?? $data['messages'] ?? null;

$firstMsg = null;
$message = [];
$chat = [];
$im = null;
$user = [];

if (is_array($messages) && !empty($messages)) {
  $firstMsg = $messages[0];
  $message = is_array($firstMsg['message'] ?? null) ? $firstMsg['message'] : (is_array($firstMsg['MESSAGE'] ?? null) ? $firstMsg['MESSAGE'] : []);
  $chat = is_array($firstMsg['chat'] ?? null) ? $firstMsg['chat'] : (is_array($firstMsg['CHAT'] ?? null) ? $firstMsg['CHAT'] : []);
  $im = $firstMsg['im'] ?? $firstMsg['IM'] ?? null;
} else {
  $message = is_array($data['message'] ?? null) ? $data['message'] : (is_array($data['MESSAGE'] ?? null) ? $data['MESSAGE'] : []);
  $chat = is_array($data['chat'] ?? null) ? $data['chat'] : (is_array($data['CHAT'] ?? null) ? $data['CHAT'] : []);
  $im = $data['im'] ?? $data['IM'] ?? null;
}
$user = is_array($data['user'] ?? null) ? $data['user'] : (is_array($data['USER'] ?? null) ? $data['USER'] : []);
if (empty($chat)) $chat = [];

$text = trim((string)pick($message, ['text', 'TEXT', 'message', 'MESSAGE']));
$externalUserId = (string)pick($user, ['id', 'ID', 'external_id']);
$externalChatId = (string)pick($chat, ['id', 'ID', 'external_id']);
if ($externalUserId === '') $externalUserId = (string)pick($message, ['user_id', 'USER_ID']);
if ($externalChatId === '') $externalChatId = (string)pick($message, ['chat_id', 'CHAT_ID']);

// Message id from event (Bitrix outbound message id) — must be sent back in delivery status as array
$messageIdRaw = pick($message, ['id', 'ID']);
if (is_array($messageIdRaw)) {
  $messageIds = array_values($messageIdRaw);
} else {
  $messageIds = $messageIdRaw !== null && $messageIdRaw !== '' ? [$messageIdRaw] : [];
}

handler_log('Extracted', ['connector' => $connector, 'line_id' => $lineId, 'message_id' => $messageIds, 'external_user_id' => $externalUserId, 'external_chat_id' => $externalChatId]);

if ($connector === '' || $lineId === '' || $text === '' || $externalUserId === '' || $externalChatId === '') {
  handler_log('Missing data', ['connector' => $connector, 'line_id' => $lineId, 'has_text' => $text !== '', 'external_user_id' => $externalUserId, 'external_chat_id' => $externalChatId]);
  json_response(['ok' => false, 'error' => 'Missing connector, line, message text, or user/chat id']);
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

handler_log('Resolve peer', ['portal' => $portal, 'external_user_id' => $externalUserId, 'external_chat_id' => $externalChatId]);

$mapping = ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId);
if (!$mapping) {
  handler_log('No ol_map', ['portal' => $portal, 'external_user_id' => $externalUserId, 'external_chat_id' => $externalChatId]);
  json_response(['ok' => false, 'error' => 'No mapping for this chat. Send a message from Telegram first so the chat is linked.']);
  exit;
}

$tenantId = $mapping['tenant_id'];
$peer = $mapping['peer'];

$pdo = ensure_db();
$stmt = $pdo->prepare("SELECT user_id, api_token FROM user_settings WHERE portal=? AND tenant_id=? LIMIT 1");
$stmt->execute([$portal, $tenantId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$apiToken = $row ? ($row['api_token'] ?? null) : null;

if (!$apiToken) {
  handler_log('No api_token for tenant', ['tenant_id' => $tenantId]);
  json_response(['ok' => false, 'error' => 'No API token for this tenant. Configure the app for this Telegram connection.']);
  exit;
}

$greyOk = false;
$sent = null;
try {
  $sent = grey_call($tenantId, $apiToken, '/messages/send', 'POST', [
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

message_log_insert('out', $portal, $tenantId, $peer, $text, null, 'openlines', null);

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
