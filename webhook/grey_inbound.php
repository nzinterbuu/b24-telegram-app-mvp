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

/** Extract phone and text from payload (top-level or nested in message/event). Grey may send: tenant_id, event, message (with message containing from/peer and text). 
 * Prioritizes phone number fields over chatid fields - if both are present, uses phone number for deal creation. */
function parse_inbound_payload(array $payload): array {
  $phone = null;
  $chatId = null;
  $text = '';
  
  // Build list of arrays to check, including nested structures
  $try = [];
  
  // Top level
  if (is_array($payload)) {
    $try[] = $payload;
  }
  
  // message object (common structure: { tenant_id, event, message: { from, text, ... } })
  if (isset($payload['message']) && is_array($payload['message'])) {
    $try[] = $payload['message'];
    
    // Check nested from object in message
    if (isset($payload['message']['from']) && is_array($payload['message']['from'])) {
      $try[] = $payload['message']['from'];
    }
  }
  
  // event object
  if (isset($payload['event']) && is_array($payload['event'])) {
    $try[] = $payload['event'];
    
    // Check nested message in event
    if (isset($payload['event']['message']) && is_array($payload['event']['message'])) {
      $try[] = $payload['event']['message'];
      
      // Check nested from in event.message
      if (isset($payload['event']['message']['from']) && is_array($payload['event']['message']['from'])) {
        $try[] = $payload['event']['message']['from'];
      }
    }
  }
  
  // data object
  if (isset($payload['data']) && is_array($payload['data'])) {
    $try[] = $payload['data'];
  }
  
  // First pass: prioritize phone number fields
  foreach ($try as $a) {
    if (!is_array($a)) continue;
    
    // Try explicit phone number fields first
    $p = normalize_phone((string)pick($a, ['phone','from_phone','user_phone','sender_phone','contact_phone']));
    
    // Check nested 'from' object
    if (!$p && isset($a['from'])) {
      if (is_array($a['from'])) {
        $p = normalize_phone((string)pick($a['from'], ['phone','phone_number','number']));
        // Also check if 'from' itself is a phone-like string
        if (!$p && isset($a['from']['id'])) {
          $p = normalize_phone((string)$a['from']['id']);
        }
      } elseif (is_string($a['from'])) {
        $p = normalize_phone($a['from']);
      }
    }
    
    // Check 'peer' field (common in Telegram APIs)
    if (!$p) {
      $peer = pick($a, ['peer','from','sender']);
      if ($peer) {
        if (is_string($peer)) {
          $p = normalize_phone($peer);
        } elseif (is_array($peer) && isset($peer['phone'])) {
          $p = normalize_phone((string)$peer['phone']);
        }
      }
    }
    
    if ($p) {
      $phone = $phone ?? $p;
      break; // Found phone number, prioritize it
    }
  }
  
  // Second pass: if no phone found, try chatid fields as fallback
  if (!$phone) {
    foreach ($try as $a) {
      if (!is_array($a)) continue;
      
      $cid = pick($a, ['peer_id','sender_id','chat_id','user_id']);
      if ($cid) {
        if (is_string($cid)) {
          $p = normalize_phone((string)$cid);
          if ($p) {
            $chatId = $chatId ?? $cid;
            $phone = $phone ?? $p;
            break;
          }
        } elseif (is_numeric($cid)) {
          // Numeric IDs might be Telegram user IDs, not phone numbers
          // Only use if it looks like a phone number (starts with + or has 10+ digits)
          $cidStr = (string)$cid;
          if (strlen($cidStr) >= 10 && (preg_match('/^\+?\d{10,}$/', $cidStr))) {
            $p = normalize_phone($cidStr);
            if ($p) {
              $chatId = $chatId ?? $cidStr;
              $phone = $phone ?? $p;
              break;
            }
          }
        }
      }
      
      // Check nested from.id
      if (!$phone && isset($a['from']) && is_array($a['from'])) {
        $cid = pick($a['from'], ['id','username']);
        if ($cid) {
          if (is_string($cid)) {
            $p = normalize_phone((string)$cid);
            if ($p) {
              $chatId = $chatId ?? $cid;
              $phone = $phone ?? $p;
              break;
            }
          }
        }
      }
    }
  }
  
  // Extract text - check all nested structures
  foreach ($try as $a) {
    if (!is_array($a)) continue;
    
    // Try common text fields
    $tRaw = pick($a, ['text','message','body','content','msg','message_text']);
    
    // If found a non-empty string, use it
    if ($tRaw && is_string($tRaw)) {
      $t = trim($tRaw);
      if ($t !== '') {
        $text = $text !== '' ? $text : $t;
        break;
      }
    }
    
    // Check nested message.text
    if (isset($a['message']) && is_string($a['message'])) {
      $t = trim($a['message']);
      if ($t !== '') {
        $text = $text !== '' ? $text : $t;
        break;
      }
    }
  }
  
  return ['phone' => $phone, 'chatid' => $chatId, 'text' => $text];
}

try {
  $payload = read_json();
  if (cfg('DEBUG')) {
    log_debug('inbound payload', ['payload' => $payload]);
  }

  $parsed = parse_inbound_payload($payload);
  $phone = $parsed['phone'];
  $chatId = $parsed['chatid'] ?? null;
  $text = $parsed['text'];

  // Check webhook is configured (required for inbound)
  $webhook = rtrim(getenv('B24_WEBHOOK_URL') ?: cfg('B24_WEBHOOK_URL', ''), '/');
  if ($webhook === '') {
    http_response_code(500);
    json_response(['ok' => false, 'error' => 'B24_WEBHOOK_URL is not set. Inbound requires Bitrix webhook. Set B24_WEBHOOK_URL in config/env.']);
    exit;
  }

  // Enhanced error message with payload structure details
  if (!$phone || $text === '') {
    $errorDetails = [
      'received_keys' => array_keys($payload),
      'parsed_phone' => $phone,
      'parsed_chatid' => $chatId,
      'parsed_text' => $text ? '(found)' : '(missing)',
    ];
    
    // Add nested structure info
    if (isset($payload['message']) && is_array($payload['message'])) {
      $errorDetails['message_keys'] = array_keys($payload['message']);
      if (isset($payload['message']['from'])) {
        $errorDetails['message_from'] = is_array($payload['message']['from']) 
          ? array_keys($payload['message']['from']) 
          : gettype($payload['message']['from']);
      }
    }
    
    if (isset($payload['event']) && is_array($payload['event'])) {
      $errorDetails['event_keys'] = array_keys($payload['event']);
    }
    
    log_debug('parse_inbound_payload failed', $errorDetails);
    
    throw new Exception(
      "Cannot parse inbound payload (need phone + text). " .
      "Received keys: " . implode(', ', array_keys($payload)) . ". " .
      "Parsed phone: " . ($phone ?: 'null') . ", text: " . ($text ? '(found)' : 'null') . ". " .
      "Payload structure: " . json_encode($errorDetails, JSON_UNESCAPED_UNICODE)
    );
  }

  // Log if both phone and chatid are present (for debugging)
  if (cfg('DEBUG') && $chatId && $phone) {
    log_debug('inbound: phone prioritized over chatid', ['phone' => $phone, 'chatid' => $chatId]);
  }

  // tenant_id might be in payload for multi-tenant (for logging)
  $tenantId = pick($payload, ['tenant_id','tenantId','tenant','connection_id']);

  // Find or create contact by phone (webhook mode, no auth needed)
  $contactList = b24_call('crm.contact.list', [
    'filter' => ['PHONE' => $phone],
    'select' => ['ID','NAME']
  ]);
  $contactId = !empty($contactList['result'][0]['ID']) ? (int)$contactList['result'][0]['ID'] : 0;

  if (!$contactId) {
    $addC = b24_call('crm.contact.add', [
      'fields' => [
        'NAME' => 'Telegram ' . preg_replace('/\+/', '', $phone),
        'PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']]
      ]
    ]);
    $contactId = (int)($addC['result'] ?? 0);
  }

  $dealList = b24_call('crm.deal.list', [
    'filter' => ['CONTACT_ID' => $contactId],
    'select' => ['ID','ASSIGNED_BY_ID'],
    'order' => ['ID' => 'DESC'],
    'start' => 0
  ]);
  $dealId = !empty($dealList['result'][0]['ID']) ? (int)$dealList['result'][0]['ID'] : 0;
  $assigned = !empty($dealList['result'][0]['ASSIGNED_BY_ID']) ? (int)$dealList['result'][0]['ASSIGNED_BY_ID'] : 0;

  if (!$dealId) {
    $addD = b24_call('crm.deal.add', [
      'fields' => [
        'TITLE' => 'Telegram: ' . $phone,
        'CONTACT_ID' => $contactId
      ]
    ]);
    $dealId = (int)($addD['result'] ?? 0);
  }

  // 1) Deal timeline — always add so the message is visible on the deal
  b24_call('crm.timeline.comment.add', [
    'fields' => [
      'ENTITY_TYPE' => 'deal',
      'ENTITY_ID' => $dealId,
      'COMMENT' => "Telegram IN from {$phone}:\n{$text}"
    ]
  ]);

  // 2) Notify: assigned user, or first user with settings for this tenant, or admin
  $notifyUserId = $assigned;
  if (!$notifyUserId && $tenantId) {
    $pdo = ensure_db();
    $stmt = $pdo->prepare("SELECT user_id FROM user_settings WHERE tenant_id=? LIMIT 1");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $notifyUserId = (int)$row['user_id'];
  }
  if (!$notifyUserId) {
    $u = b24_call('user.get', ['filter' => ['ADMIN' => 'Y'], 'limit' => 1]);
    $notifyUserId = !empty($u['result'][0]['ID']) ? (int)$u['result'][0]['ID'] : 0;
  }
  if ($notifyUserId) {
    try {
      b24_call('im.notify', [
        'to' => $notifyUserId,
        'message' => "Telegram from {$phone}:\n{$text}\n[Deal #{$dealId}]",
        'message_out' => 'Y'
      ]);
    } catch (Throwable $e) {
      log_debug('im.notify failed', ['e' => $e->getMessage()]);
    }
  }

  // 3) Open Lines / Contact Center — use ol_map (tenant_id + peer → external_user_id/external_chat_id), send to selected line
  $portal = b24_get_first_portal();
  $lineId = $portal ? get_portal_line_id($portal) : null;
  if ($lineId === null) {
    $lineId = cfg('OPENLINES_LINE_ID');
    $lineId = ($lineId !== null && $lineId !== '') ? (string)$lineId : null;
  }
  $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
  if ($portal && $lineId !== null && $lineId !== '' && $tenantId !== null && $tenantId !== '') {
    try {
      $ext = ol_map_get_or_create($portal, $lineId, (string)$tenantId, $phone);
      $messageId = 'tg_' . $phone . '_' . time() . '_' . bin2hex(random_bytes(4));
      $userName = 'Telegram ' . $phone;
      if (strlen($userName) > 25) $userName = substr($userName, 0, 22) . '…';
      b24_call('imconnector.send.messages', [
        'CONNECTOR' => $connectorId,
        'LINE' => $lineId,
        'MESSAGES' => [
          [
            'user' => [
              'id' => $ext['external_user_id'],
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
              'id' => $ext['external_chat_id'],
              'name' => $userName,
            ],
          ],
        ],
      ]);
    } catch (Throwable $e) {
      log_debug('imconnector.send.messages failed', ['e' => $e->getMessage()]);
    }
  }

  $portalForLog = b24_get_first_portal() ?: 'unknown';
  message_log_insert('in', $portalForLog, $tenantId ? (string)$tenantId : null, $phone, $text, $dealId, 'webhook', null);

  json_response(['ok' => true, 'contact_id' => $contactId, 'deal_id' => $dealId]);

} catch (Throwable $e) {
  log_debug('inbound error', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
  $portalForLog = b24_get_first_portal() ?: 'unknown';
  try {
    message_log_insert('in', $portalForLog, null, isset($phone) ? $phone : '', isset($text) ? $text : '', null, 'webhook', $e->getMessage());
  } catch (Throwable $t) { /* ignore */ }
  json_response(['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
