<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';
require_once __DIR__ . '/../lib/grey.php';

function normalize_phone(?string $phone): ?string {
  if (!$phone) return null;
  $p = preg_replace('/[^0-9+]/', '', $phone);
  if ($p === '') return null;
  if ($p[0] !== '+') $p = '+' . $p;
  return $p;
}

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $dealId = (int)($req['deal_id'] ?? 0);
  $text = trim((string)($req['text'] ?? ''));
  if (!$dealId) throw new Exception("deal_id is required");
  if ($text === '') throw new Exception("Message text is empty");

  $u = b24_call($auth, 'user.current');
  $userId = (int)($u['result']['ID'] ?? 0);
  $s = db_get_user_settings($auth, $userId);
  if (empty($s['tenant_id'])) throw new Exception("Tenant ID not set in Settings.");

  $deal = b24_call($auth, 'crm.deal.get', ['id'=>$dealId]);
  $dealFields = $deal['result'] ?? [];
  $contactId = (int)($dealFields['CONTACT_ID'] ?? 0);
  if (!$contactId) throw new Exception("Deal has no CONTACT_ID. Attach a contact to the deal.");

  $c = b24_call($auth, 'crm.contact.get', ['id'=>$contactId]);
  $phones = $c['result']['PHONE'] ?? [];
  $phone = $phones ? ($phones[0]['VALUE'] ?? null) : null;
  $phone = normalize_phone($phone);
  if (!$phone) throw new Exception("No client phone found on deal contact.");

  // Send via Grey TG API (peer can be phone E.164 according to API docs)
  $sent = grey_call($s['tenant_id'], $s['api_token'] ?? null, '/messages/send', 'POST', [
    'peer' => $phone,
    'text' => $text,
    'allow_import_contact' => true
  ]);

  // Add timeline comment
  b24_call($auth, 'crm.timeline.comment.add', [
    'fields' => [
      'ENTITY_TYPE' => 'deal',
      'ENTITY_ID' => $dealId,
      'COMMENT' => "Telegram OUT to {$phone}:\n{$text}"
    ]
  ]);

  json_response(['ok'=>true,'phone'=>$phone,'grey'=>$sent]);

} catch (Throwable $e) {
  json_response(['ok'=>false,'error'=>$e->getMessage(),'message'=>$e->getMessage()], 400);
}
