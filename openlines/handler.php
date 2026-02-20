<?php
/**
 * Bitrix24 Open Lines event handler.
 * Called by Bitrix24 on OnImConnectorMessageAdd when an operator sends a message in the connector chat.
 * Resolves external_user_id/external_chat_id to tenant_id/peer via ol_map and sends the message to Grey.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

function pick(array $a, array $keys) {
  foreach ($keys as $k) {
    if (isset($a[$k])) return $a[$k];
  }
  return null;
}

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
  $payload = $_POST;
}
if (cfg('DEBUG')) {
  log_debug('openlines handler payload', ['payload' => $payload]);
}

$event = pick($payload, ['event', 'EVENT']);
if ($event !== 'OnImConnectorMessageAdd' && $event !== 'ONIMCONNECTORMESSAGEADD') {
  json_response(['ok' => false, 'error' => 'Unsupported event']);
  exit;
}

$data = $payload['data'] ?? $payload['DATA'] ?? $payload;
$connector = (string)pick($data, ['connector', 'CONNECTOR']);
$lineId = (string)pick($data, ['line', 'LINE', 'line_id']);
$messages = $data['MESSAGES'] ?? $data['messages'] ?? null;
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

if ($connector === '' || $lineId === '' || $text === '' || $externalUserId === '' || $externalChatId === '') {
  log_debug('openlines handler missing data', [
    'connector' => $connector,
    'line_id' => $lineId,
    'has_text' => $text !== '',
    'external_user_id' => $externalUserId,
    'external_chat_id' => $externalChatId,
  ]);
  json_response(['ok' => false, 'error' => 'Missing connector, line, message text, or user/chat id']);
  exit;
}

$connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
if ($connector !== $connectorId) {
  json_response(['ok' => true, 'skipped' => 'connector mismatch']);
  exit;
}

$portal = b24_portal_from_event($payload);
if (!$portal) {
  $portal = b24_get_first_portal();
}
if (!$portal) {
  log_debug('openlines handler no portal');
  json_response(['ok' => false, 'error' => 'No portal (webhook/OAuth not configured)']);
  exit;
}

log_debug('openlines handler resolve peer', ['portal' => $portal, 'external_user_id' => $externalUserId, 'external_chat_id' => $externalChatId]);

$mapping = ol_map_resolve_to_peer($portal, $externalUserId, $externalChatId);
if (!$mapping) {
  log_debug('openlines handler no ol_map', [
    'portal' => $portal,
    'line_id' => $lineId,
    'external_user_id' => $externalUserId,
    'external_chat_id' => $externalChatId,
  ]);
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
  log_debug('openlines handler no api_token for tenant', ['tenant_id' => $tenantId]);
  json_response(['ok' => false, 'error' => 'No API token for this tenant. Configure the app for this Telegram connection.']);
  exit;
}

try {
  $sent = grey_call($tenantId, $apiToken, '/messages/send', 'POST', [
    'peer' => $peer,
    'text' => $text,
    'allow_import_contact' => true,
  ]);
} catch (Throwable $e) {
  log_debug('openlines handler Grey send failed', ['e' => $e->getMessage()]);
  json_response(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

message_log_insert('out', $portal, $tenantId, $peer, $text, null, 'openlines', null);

// Confirm delivery to Bitrix24 (forward 'im' from incoming event; message.id must be array of IDs per API)
$msgId = pick($message, ['id', 'ID']) ?: ('ol_' . time());
$msgIdArray = is_array($msgId) ? $msgId : [$msgId];
$deliveryItem = [
  'message' => ['id' => $msgIdArray],
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
  }
} catch (Throwable $e) {
  log_debug('imconnector.send.status.delivery failed', ['e' => $e->getMessage()]);
}

json_response(['ok' => true, 'peer' => $peer, 'grey' => $sent]);
