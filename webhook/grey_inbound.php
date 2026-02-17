<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

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
  
  // Second pass: check sender_id and chat_id from message object (they might be phone numbers)
  // This handles structure like: { message: { sender_id: "+123...", chat_id: "...", text: "..." } }
  if (!$phone && isset($payload['message']) && is_array($payload['message'])) {
    $msg = $payload['message'];
    
    // Check sender_id - might be a phone number
    if (isset($msg['sender_id'])) {
      $senderId = $msg['sender_id'];
      $senderIdStr = is_string($senderId) ? $senderId : (is_numeric($senderId) ? (string)$senderId : null);
      
      if ($senderIdStr !== null) {
        // Try to normalize as phone number
        $p = normalize_phone($senderIdStr);
        
        if ($p) {
          // Check if it looks like a phone number (not just a numeric Telegram ID)
          // Telegram user IDs are typically 8-10 digits, phone numbers are 10+ digits
          // If it starts with + or has 11+ digits (after removing non-digits), it's likely a phone number
          $digitsOnly = preg_replace('/[^0-9]/', '', $senderIdStr);
          $digitCount = strlen($digitsOnly);
          
          if (strpos($senderIdStr, '+') === 0 || $digitCount >= 11) {
            // Looks like a phone number
            $phone = $p;
            $chatId = $senderIdStr;
          } elseif (!$phone && $digitCount >= 10) {
            // Might be a phone number (10+ digits), use it
            $phone = $p;
            $chatId = $senderIdStr;
          } else {
            // Probably a Telegram user ID, store as chatid only
            $chatId = $senderIdStr;
          }
        } else {
          // Couldn't normalize as phone, but store as chatid
          $chatId = $senderIdStr;
        }
      }
    }
    
    // Check chat_id - might be a phone number
    if (!$phone && isset($msg['chat_id'])) {
      $chatIdVal = $msg['chat_id'];
      $chatIdValStr = is_string($chatIdVal) ? $chatIdVal : (is_numeric($chatIdVal) ? (string)$chatIdVal : null);
      
      if ($chatIdValStr !== null) {
        $p = normalize_phone($chatIdValStr);
        
        if ($p) {
          $digitsOnly = preg_replace('/[^0-9]/', '', $chatIdValStr);
          $digitCount = strlen($digitsOnly);
          
          if (strpos($chatIdValStr, '+') === 0 || $digitCount >= 11) {
            // Looks like a phone number
            $phone = $p;
            if (!$chatId) $chatId = $chatIdValStr;
          } elseif (!$phone && $digitCount >= 10) {
            // Might be a phone number, use it
            $phone = $p;
            if (!$chatId) $chatId = $chatIdValStr;
          } else {
            // Probably a Telegram chat ID, store as chatid only
            if (!$chatId) $chatId = $chatIdValStr;
          }
        } else {
          if (!$chatId) $chatId = $chatIdValStr;
        }
      }
    }
    
    // If we still don't have a phone but have a chatid, try using chatid as phone (fallback)
    // This handles cases where Grey API sends phone numbers in chatid/sender_id fields
    if (!$phone && $chatId) {
      $p = normalize_phone($chatId);
      if ($p) {
        // Only use if it has enough digits to be a phone number
        $digitsOnly = preg_replace('/[^0-9]/', '', $chatId);
        if (strlen($digitsOnly) >= 10) {
          $phone = $p;
        }
      }
    }
  }
  
  // Third pass: if still no phone found, try other chatid fields as fallback
  if (!$phone) {
    foreach ($try as $a) {
      if (!is_array($a)) continue;
      
      $cid = pick($a, ['peer_id','sender_id','chat_id','user_id']);
      if ($cid && !$phone) {
        if (is_string($cid)) {
          $p = normalize_phone((string)$cid);
          if ($p) {
            $chatId = $chatId ?? $cid;
            $phone = $p;
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
              $phone = $p;
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
              $phone = $p;
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

  // Check if we have at least text and either phone or chatid
  if ($text === '') {
    $errorDetails = [
      'received_keys' => array_keys($payload),
      'parsed_phone' => $phone,
      'parsed_chatid' => $chatId,
      'parsed_text' => 'missing',
    ];
    
    log_debug('parse_inbound_payload failed: no text', $errorDetails);
    throw new Exception("Cannot parse inbound payload: message text is missing. Received keys: " . implode(', ', array_keys($payload)));
  }
  
  // If no phone but we have chatid, use chatid as identifier (Telegram user ID)
  // This handles cases where Grey API only provides Telegram user ID, not phone number
  if (!$phone && $chatId) {
    // Use chatid as the identifier - format it as a pseudo-phone for compatibility
    // Store the original chatid separately for reference
    $phone = $chatId; // Will be used as identifier, but we'll handle it specially
    log_debug('Using Telegram user ID as identifier (no phone available)', ['chatid' => $chatId]);
  }
  
  // Final check: we need either phone or chatid to proceed
  if (!$phone && !$chatId) {
    $errorDetails = [
      'received_keys' => array_keys($payload),
      'parsed_phone' => null,
      'parsed_chatid' => null,
      'parsed_text' => $text ? '(found)' : '(missing)',
    ];
    
    if (isset($payload['message']) && is_array($payload['message'])) {
      $errorDetails['message_keys'] = array_keys($payload['message']);
      $errorDetails['message_values'] = [];
      foreach (['sender_id', 'chat_id', 'sender_username', 'text', 'from', 'peer'] as $key) {
        if (isset($payload['message'][$key])) {
          $val = $payload['message'][$key];
          if (is_scalar($val)) {
            $errorDetails['message_values'][$key] = $val;
          }
        }
      }
    }
    
    log_debug('parse_inbound_payload failed: no phone or chatid', $errorDetails);
    
    $errorMsg = "Cannot parse inbound payload: need phone or chatid (Telegram user ID). " .
      "Received keys: " . implode(', ', array_keys($payload)) . ". " .
      "Parsed phone: null, chatid: null, text: " . ($text ? '(found)' : 'null');
    
    if (isset($payload['message']['sender_id'])) {
      $errorMsg .= ". sender_id: " . json_encode($payload['message']['sender_id']);
    }
    if (isset($payload['message']['chat_id'])) {
      $errorMsg .= ". chat_id: " . json_encode($payload['message']['chat_id']);
    }
    
    throw new Exception($errorMsg);
  }

  // Log if both phone and chatid are present (for debugging)
  if (cfg('DEBUG') && $chatId && $phone) {
    log_debug('inbound: phone prioritized over chatid', ['phone' => $phone, 'chatid' => $chatId]);
  }

  // tenant_id might be in payload for multi-tenant (for logging)
  $tenantId = pick($payload, ['tenant_id','tenantId','tenant','connection_id']);

  // Step 1: If we have a chat_id (Telegram user ID) but no phone number, try to get phone from Grey API
  $resolvedPhone = null;
  if (!$phone && $chatId && $tenantId) {
    try {
      // Get API token for this tenant
      $pdo = ensure_db();
      $stmt = $pdo->prepare("SELECT api_token FROM user_settings WHERE tenant_id=? LIMIT 1");
      $stmt->execute([$tenantId]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $apiToken = $row ? ($row['api_token'] ?? null) : null;
      
      if ($apiToken) {
        // Try to get user info from Grey API using chat_id
        // Common endpoints: /users/{user_id}, /contacts/{user_id}, /chats/{chat_id}
        // Try multiple possible endpoints
        $endpoints = [
          '/users/' . rawurlencode($chatId),
          '/contacts/' . rawurlencode($chatId),
          '/chats/' . rawurlencode($chatId),
        ];
        
        foreach ($endpoints as $endpoint) {
          try {
            $userInfo = grey_call($tenantId, $apiToken, $endpoint, 'GET');
            
            // Try to extract phone number from response
            // Check common fields: phone, phone_number, contact_phone, user.phone, etc.
            $phoneFields = ['phone', 'phone_number', 'contact_phone', 'user_phone'];
            foreach ($phoneFields as $field) {
              if (isset($userInfo[$field]) && !empty($userInfo[$field])) {
                $resolvedPhone = normalize_phone((string)$userInfo[$field]);
                if ($resolvedPhone) {
                  log_debug('Got phone from Grey API', ['chat_id' => $chatId, 'endpoint' => $endpoint, 'phone' => $resolvedPhone]);
                  break 2; // Break out of both loops
                }
              }
            }
            
            // Check nested user object
            if (!$resolvedPhone && isset($userInfo['user']) && is_array($userInfo['user'])) {
              foreach ($phoneFields as $field) {
                if (isset($userInfo['user'][$field]) && !empty($userInfo['user'][$field])) {
                  $resolvedPhone = normalize_phone((string)$userInfo['user'][$field]);
                  if ($resolvedPhone) {
                    log_debug('Got phone from Grey API (nested user)', ['chat_id' => $chatId, 'endpoint' => $endpoint, 'phone' => $resolvedPhone]);
                    break 2;
                  }
                }
              }
            }
            
            // Check contact object
            if (!$resolvedPhone && isset($userInfo['contact']) && is_array($userInfo['contact'])) {
              foreach ($phoneFields as $field) {
                if (isset($userInfo['contact'][$field]) && !empty($userInfo['contact'][$field])) {
                  $resolvedPhone = normalize_phone((string)$userInfo['contact'][$field]);
                  if ($resolvedPhone) {
                    log_debug('Got phone from Grey API (contact)', ['chat_id' => $chatId, 'endpoint' => $endpoint, 'phone' => $resolvedPhone]);
                    break 2;
                  }
                }
              }
            }
          } catch (Throwable $e) {
            // Endpoint doesn't exist or failed, try next one
            log_debug('Grey API endpoint failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            continue;
          }
        }
      }
    } catch (Throwable $e) {
      log_debug('Failed to get phone from Grey API', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
    }
  }
  
  // Use resolved phone if found
  if ($resolvedPhone) {
    $phone = $resolvedPhone;
    log_debug('Using phone resolved from Grey API', ['original_chat_id' => $chatId, 'resolved_phone' => $phone]);
  }

  // Determine if $phone is actually a phone number or a Telegram user ID
  $isPhoneNumber = false;
  if ($phone) {
    $phoneStr = (string)$phone;
    $digitsOnly = preg_replace('/[^0-9]/', '', $phoneStr);
    // Consider it a phone number if it starts with + and has 10+ digits, or has 11+ digits total
    $isPhoneNumber = (strpos($phoneStr, '+') === 0 && strlen($digitsOnly) >= 10) || strlen($digitsOnly) >= 11;
  }
  
  // Get sender username if available for better contact naming
  $senderUsername = null;
  if (isset($payload['message']['sender_username']) && !empty($payload['message']['sender_username'])) {
    $senderUsername = trim((string)$payload['message']['sender_username']);
  }

  // Find or create contact by phone or Telegram user ID
  $contactId = 0;
  
  if ($isPhoneNumber) {
    // Real phone number - search by phone
    $contactList = b24_call('crm.contact.list', [
      'filter' => ['PHONE' => $phone],
      'select' => ['ID','NAME']
    ]);
    $contactId = !empty($contactList['result'][0]['ID']) ? (int)$contactList['result'][0]['ID'] : 0;
  } else {
    // Telegram user ID - search by name pattern or create new
    // Try to find existing contact with this Telegram ID in name or notes
    $searchName = 'Telegram ' . $phone;
    $contactList = b24_call('crm.contact.list', [
      'filter' => ['%NAME' => $searchName],
      'select' => ['ID','NAME']
    ]);
    $contactId = !empty($contactList['result'][0]['ID']) ? (int)$contactList['result'][0]['ID'] : 0;
  }

  if (!$contactId) {
    // Create new contact
    $contactName = $senderUsername 
      ? 'Telegram @' . $senderUsername . ' (' . $phone . ')'
      : 'Telegram ' . $phone;
    
    $contactFields = [
      'NAME' => $contactName
    ];
    
    if ($isPhoneNumber) {
      // Add phone number if it's a real phone
      $contactFields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
    } else {
      // For Telegram user IDs, store in COMMENTS or use a custom approach
      // Store the Telegram ID in the name for now
      $contactFields['COMMENTS'] = 'Telegram User ID: ' . $phone;
    }
    
    $addC = b24_call('crm.contact.add', [
      'fields' => $contactFields
    ]);
    $contactId = (int)($addC['result'] ?? 0);
  }

  // Step 2: If we have a phone number, try to find active deals by phone number first
  $dealId = 0;
  $assigned = 0;
  $foundDealContactId = null;
  
  if ($isPhoneNumber && $phone) {
    // Search for active deals that have this phone number as contact phone
    // First, find all contacts with this phone number
    $contactsWithPhone = b24_call('crm.contact.list', [
      'filter' => ['PHONE' => $phone],
      'select' => ['ID']
    ]);
    
    if (!empty($contactsWithPhone['result']) && is_array($contactsWithPhone['result'])) {
      $contactIds = array_map(function($c) { return (int)$c['ID']; }, $contactsWithPhone['result']);
      
      // Search for active deals linked to any of these contacts
      // Filter by STAGE_SEMANTIC_ID != 'WON' and != 'LOST' to get active deals
      $dealList = b24_call('crm.deal.list', [
        'filter' => [
          'CONTACT_ID' => $contactIds,
          '!STAGE_SEMANTIC_ID' => ['WON', 'LOST'] // Exclude won/lost deals
        ],
        'select' => ['ID', 'ASSIGNED_BY_ID', 'CONTACT_ID', 'STAGE_SEMANTIC_ID'],
        'order' => ['ID' => 'DESC'],
        'start' => 0
      ]);
      
      if (!empty($dealList['result']) && is_array($dealList['result'])) {
        // Found active deal(s) - use the most recent one
        $foundDeal = $dealList['result'][0];
        $dealId = (int)$foundDeal['ID'];
        $assigned = !empty($foundDeal['ASSIGNED_BY_ID']) ? (int)$foundDeal['ASSIGNED_BY_ID'] : 0;
        $foundDealContactId = !empty($foundDeal['CONTACT_ID']) ? (int)$foundDeal['CONTACT_ID'] : null;
        
        // Use the contact from the found deal if available
        if ($foundDealContactId && in_array($foundDealContactId, $contactIds)) {
          $contactId = $foundDealContactId;
          log_debug('Using contact from found deal', ['contact_id' => $contactId, 'deal_id' => $dealId]);
        }
        
        log_debug('Found active deal by phone number', ['phone' => $phone, 'deal_id' => $dealId, 'contact_id' => $contactId, 'contact_ids' => $contactIds]);
      }
    }
  }
  
  // If no active deal found by phone, try by contact ID
  if (!$dealId) {
    $dealList = b24_call('crm.deal.list', [
      'filter' => [
        'CONTACT_ID' => $contactId,
        '!STAGE_SEMANTIC_ID' => ['WON', 'LOST'] // Only active deals
      ],
      'select' => ['ID','ASSIGNED_BY_ID'],
      'order' => ['ID' => 'DESC'],
      'start' => 0
    ]);
    $dealId = !empty($dealList['result'][0]['ID']) ? (int)$dealList['result'][0]['ID'] : 0;
    $assigned = !empty($dealList['result'][0]['ASSIGNED_BY_ID']) ? (int)$dealList['result'][0]['ASSIGNED_BY_ID'] : 0;
  }
  
  // If still no deal found, create a new one
  if (!$dealId) {
    $dealTitle = $isPhoneNumber 
      ? 'Telegram: ' . $phone
      : 'Telegram User: ' . ($senderUsername ? '@' . $senderUsername : $phone);
    
    $addD = b24_call('crm.deal.add', [
      'fields' => [
        'TITLE' => $dealTitle,
        'CONTACT_ID' => $contactId
      ]
    ]);
    $dealId = (int)($addD['result'] ?? 0);
    log_debug('Created new deal', ['phone' => $phone, 'contact_id' => $contactId, 'deal_id' => $dealId]);
  }

  // 1) Deal timeline — always add so the message is visible on the deal
  $fromLabel = $isPhoneNumber ? $phone : ($senderUsername ? '@' . $senderUsername : 'User ' . $phone);
  b24_call('crm.timeline.comment.add', [
    'fields' => [
      'ENTITY_TYPE' => 'deal',
      'ENTITY_ID' => $dealId,
      'COMMENT' => "Telegram IN from {$fromLabel}:\n{$text}"
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
      $fromLabel = $isPhoneNumber ? $phone : ($senderUsername ? '@' . $senderUsername : 'User ' . $phone);
      b24_call('im.notify', [
        'to' => $notifyUserId,
        'message' => "Telegram from {$fromLabel}:\n{$text}\n[Deal #{$dealId}]",
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
      // Use $phone as peer (it contains either phone number or Telegram user ID)
      $peer = $phone; // This is the identifier to use for ol_map
      $ext = ol_map_get_or_create($portal, $lineId, (string)$tenantId, $peer);
      $messageId = 'tg_' . $peer . '_' . time() . '_' . bin2hex(random_bytes(4));
      $userName = $senderUsername 
        ? '@' . $senderUsername 
        : ($isPhoneNumber ? 'Telegram ' . $phone : 'Telegram User ' . $phone);
      if (strlen($userName) > 25) $userName = substr($userName, 0, 22) . '…';
      
      $userData = [
        'id' => $ext['external_user_id'],
        'name' => $userName,
        'last_name' => '',
      ];
      
      // Only add phone field if it's a real phone number
      if ($isPhoneNumber) {
        $userData['phone'] = $phone;
        $userData['skip_phone_validate'] = 'Y';
      }
      
      b24_call('imconnector.send.messages', [
        'CONNECTOR' => $connectorId,
        'LINE' => $lineId,
        'MESSAGES' => [
          [
            'user' => $userData,
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
  // Log with peer identifier (phone or Telegram user ID)
  $peerForLog = $phone ?: $chatId;
  message_log_insert('in', $portalForLog, $tenantId ? (string)$tenantId : null, $peerForLog, $text, $dealId, 'webhook', null);

  json_response(['ok' => true, 'contact_id' => $contactId, 'deal_id' => $dealId]);

} catch (Throwable $e) {
  log_debug('inbound error', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
  $portalForLog = b24_get_first_portal() ?: 'unknown';
  try {
    message_log_insert('in', $portalForLog, null, isset($phone) ? $phone : '', isset($text) ? $text : '', null, 'webhook', $e->getMessage());
  } catch (Throwable $t) { /* ignore */ }
  json_response(['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
