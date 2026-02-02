<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';

// Grey inbound payload format is not documented in the attached Swagger PDF.
// This handler is written defensively: it tries common fields.
function pick(array $a, array $keys) {
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

try {
  $payload = read_json();

  // Use stored OAuth tokens. Resolve portal: by tenant_id from payload, or first portal.
  $tenantId = pick($payload, ['tenant_id','tenantId','tenant','connection_id']);
  $portal = null;
  if ($tenantId) {
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

  $phone = normalize_phone((string)pick($payload, ['phone','from_phone','from','peer','sender','user_phone']));
  $text = (string)pick($payload, ['text','message','body','content','msg']);
  if (!$phone || $text === '') throw new Exception("Cannot parse inbound payload (need phone + text).");

  // Find or create contact by phone
  $contactList = b24_call($auth, 'crm.contact.list', [
    'filter' => ['PHONE' => $phone],
    'select' => ['ID','NAME']
  ]);
  $contactId = !empty($contactList['result'][0]['ID']) ? (int)$contactList['result'][0]['ID'] : 0;

  if (!$contactId) {
    $addC = b24_call($auth, 'crm.contact.add', [
      'fields' => [
        'NAME' => 'Telegram',
        'PHONE' => [['VALUE'=>$phone,'VALUE_TYPE'=>'WORK']]
      ]
    ]);
    $contactId = (int)($addC['result'] ?? 0);
  }

  // If no deal exists for this contact -> create one (as requested)
  $dealList = b24_call($auth, 'crm.deal.list', [
    'filter' => ['CONTACT_ID' => $contactId],
    'select' => ['ID','ASSIGNED_BY_ID'],
    'order' => ['ID'=>'DESC'],
    'start' => 0
  ]);
  $dealId = !empty($dealList['result'][0]['ID']) ? (int)$dealList['result'][0]['ID'] : 0;
  $assigned = !empty($dealList['result'][0]['ASSIGNED_BY_ID']) ? (int)$dealList['result'][0]['ASSIGNED_BY_ID'] : 0;

  if (!$dealId) {
    $addD = b24_call($auth, 'crm.deal.add', [
      'fields' => [
        'TITLE' => 'Telegram: '.$phone,
        'CONTACT_ID' => $contactId
      ]
    ]);
    $dealId = (int)($addD['result'] ?? 0);
  }

  b24_call($auth, 'crm.timeline.comment.add', [
    'fields' => [
      'ENTITY_TYPE' => 'deal',
      'ENTITY_ID' => $dealId,
      'COMMENT' => "Telegram IN from {$phone}:\n{$text}"
    ]
  ]);

  if ($assigned) {
    b24_call($auth, 'im.notify', [
      'to' => $assigned,
      'message' => "Telegram message from {$phone}:\n{$text}\nDeal #{$dealId}"
    ]);
  }

  json_response(['ok'=>true,'contact_id'=>$contactId,'deal_id'=>$dealId]);

} catch (Throwable $e) {
  log_debug("inbound error", ['e'=>$e->getMessage()]);
  json_response(['ok'=>false,'error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
