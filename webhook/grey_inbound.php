<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';

// Grey inbound payload format may vary. We accept common shapes and nested message objects.
function pick($a, array $keys) {
  if (!is_array($a)) return null;
  foreach ($keys as $k) if (isset($a[$k])) return $a[$k];
  return null;
}

function normalize_phone(?string $phone): ?string {
  if (!$phone) return null;
  $p = preg_replace('/[^0-9+]/', '', $phone);
  if ($p === '') return null;
  if ($p[0] !== '+') $p = '+' . $p;
  return $p;
}

/** Extract phone and text from payload (top-level or nested in message/event). Grey may send: tenant_id, event, message (with message containing from/peer and text). */
function parse_inbound_payload(array $payload): array {
  $phone = null;
  $text = '';
  $try = [
    $payload,
    $payload['message'] ?? [],
    $payload['event'] ?? [],
    $payload['event']['message'] ?? [],
    $payload['data'] ?? [],
  ];
  foreach ($try as $a) {
    if (!is_array($a)) continue;
    $p = normalize_phone((string)pick($a, ['phone','from_phone','from','peer','sender','user_phone','sender_phone','contact_phone','peer_id','sender_id']));
    if (!$p && isset($a['from']) && is_array($a['from'])) {
      $p = normalize_phone((string)pick($a['from'], ['phone','phone_number','number','id','username']));
    }
    if (!$p && isset($a['from']) && is_string($a['from'])) {
      $p = normalize_phone($a['from']);
    }
    if (!$p && isset($a['peer_id'])) {
      $p = normalize_phone((string)$a['peer_id']);
    }
    $tRaw = pick($a, ['text','message','body','content','msg','message_text','data']);
    $t = is_string($tRaw) ? trim($tRaw) : '';
    if ($p) $phone = $phone ?? $p;
    if ($t !== '') $text = $text !== '' ? $text : $t;
  }
  return ['phone' => $phone, 'text' => $text];
}

try {
  $payload = read_json();
  if (cfg('DEBUG')) {
    log_debug('inbound payload', ['payload' => $payload]);
  }

  $parsed = parse_inbound_payload($payload);
  $phone = $parsed['phone'];
  $text = $parsed['text'];

  // tenant_id might be in payload for multi-tenant
  $tenantId = pick($payload, ['tenant_id','tenantId','tenant','connection_id']);
  $portal = null;
  if ($tenantId && is_array($payload)) {
    $pdo = ensure_db();
    $stmt = $pdo->prepare("SELECT portal FROM user_settings WHERE tenant_id=? LIMIT 1");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $portal = $row['portal'];
  }
  if (!$portal) {
    $portal = b24_get_first_portal();
  }
  if (!$portal) {
    throw new Exception("No Bitrix24 portal connected. Install the app in Bitrix24 first.");
  }
  $auth = ['portal' => $portal];

  if (!$phone || $text === '') {
    throw new Exception("Cannot parse inbound payload (need phone + text). Received keys: " . implode(', ', array_keys($payload)));
  }

  // Find or create contact by phone
  $contactList = b24_call($auth, 'crm.contact.list', [
    'filter' => ['PHONE' => $phone],
    'select' => ['ID','NAME']
  ]);
  $contactId = !empty($contactList['result'][0]['ID']) ? (int)$contactList['result'][0]['ID'] : 0;

  if (!$contactId) {
    $addC = b24_call($auth, 'crm.contact.add', [
      'fields' => [
        'NAME' => 'Telegram ' . preg_replace('/\+/', '', $phone),
        'PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']]
      ]
    ]);
    $contactId = (int)($addC['result'] ?? 0);
  }

  $dealList = b24_call($auth, 'crm.deal.list', [
    'filter' => ['CONTACT_ID' => $contactId],
    'select' => ['ID','ASSIGNED_BY_ID'],
    'order' => ['ID' => 'DESC'],
    'start' => 0
  ]);
  $dealId = !empty($dealList['result'][0]['ID']) ? (int)$dealList['result'][0]['ID'] : 0;
  $assigned = !empty($dealList['result'][0]['ASSIGNED_BY_ID']) ? (int)$dealList['result'][0]['ASSIGNED_BY_ID'] : 0;

  if (!$dealId) {
    $addD = b24_call($auth, 'crm.deal.add', [
      'fields' => [
        'TITLE' => 'Telegram: ' . $phone,
        'CONTACT_ID' => $contactId
      ]
    ]);
    $dealId = (int)($addD['result'] ?? 0);
  }

  // 1) Deal timeline — always add so the message is visible on the deal
  b24_call($auth, 'crm.timeline.comment.add', [
    'fields' => [
      'ENTITY_TYPE' => 'deal',
      'ENTITY_ID' => $dealId,
      'COMMENT' => "Telegram IN from {$phone}:\n{$text}"
    ]
  ]);

  // 2) Notify: assigned user, or first user with settings for this portal, or user 1
  $notifyUserId = $assigned;
  if (!$notifyUserId) {
    $pdo = ensure_db();
    $stmt = $pdo->prepare("SELECT user_id FROM user_settings WHERE portal=? LIMIT 1");
    $stmt->execute([$portal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $notifyUserId = (int)$row['user_id'];
  }
  if (!$notifyUserId) {
    $u = b24_call($auth, 'user.get', ['filter' => ['ADMIN' => 'Y'], 'limit' => 1]);
    $notifyUserId = !empty($u['result'][0]['ID']) ? (int)$u['result'][0]['ID'] : 0;
  }
  if ($notifyUserId) {
    try {
      b24_call($auth, 'im.notify', [
        'to' => $notifyUserId,
        'message' => "Telegram from {$phone}:\n{$text}\n[Deal #{$dealId}]",
        'message_out' => 'Y'
      ]);
    } catch (Throwable $e) {
      log_debug('im.notify failed', ['e' => $e->getMessage()]);
    }
  }

  // 3) Open Lines / Contact Center — if line is configured, add message so it appears in Contact Center
  $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
  $lineId = cfg('OPENLINES_LINE_ID');
  if ($lineId !== null && $lineId !== '') {
    try {
      $messageId = 'tg_' . $phone . '_' . time() . '_' . bin2hex(random_bytes(4));
      $chatId = 'tg_' . preg_replace('/[^0-9a-zA-Z]/', '_', $phone);
      $userName = 'Telegram ' . $phone;
      if (strlen($userName) > 25) $userName = substr($userName, 0, 22) . '…';
      b24_call($auth, 'imconnector.send.messages', [
        'CONNECTOR' => $connectorId,
        'LINE' => $lineId,
        'MESSAGES' => [
          [
            'user' => [
              'id' => $phone,
              'name' => $userName,
              'last_name' => '',
              'phone' => $phone,
              'skip_phone_validate' => 'Y',
            ],
            'message' => [
              'id' => $messageId,
              'date' => (string)time(),
              'text' => $text,
            ],
            'chat' => [
              'id' => $chatId,
              'name' => $userName,
            ],
          ],
        ],
      ]);
    } catch (Throwable $e) {
      log_debug('imconnector.send.messages failed', ['e' => $e->getMessage()]);
    }
  }

  message_log_insert('in', $portal, $tenantId ? (string)$tenantId : null, $phone, $text, $dealId, 'webhook', null);

  json_response(['ok' => true, 'contact_id' => $contactId, 'deal_id' => $dealId]);

} catch (Throwable $e) {
  log_debug('inbound error', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
  $portalForLog = isset($portal) ? $portal : b24_get_first_portal();
  if ($portalForLog) {
    try {
      message_log_insert('in', $portalForLog, null, isset($phone) ? $phone : '', isset($text) ? $text : '', null, 'webhook', $e->getMessage());
    } catch (Throwable $t) { /* ignore */ }
  }
  $isAuthError = (strpos($e->getMessage(), 'Bitrix24') !== false && (strpos($e->getMessage(), 'token') !== false || strpos($e->getMessage(), 'auth') !== false));
  $code = $isAuthError ? 200 : 400;
  if ($isAuthError) {
    header('X-Inbound-Error: auth');
  }
  json_response(['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()], $code);
}
