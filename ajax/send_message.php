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
  $peer = trim((string)($req['peer'] ?? ''));
  $text = trim((string)($req['text'] ?? ''));
  if ($peer === '') throw new Exception("Peer (phone or contact) is required.");
  if ($text === '') throw new Exception("Message text is empty.");

  $u = b24_call('user.current', [], $auth);
  $userId = (int)($u['result']['ID'] ?? 0);
  $s = db_get_user_settings($auth, $userId);
  if (empty($s['tenant_id'])) throw new Exception("Select a tenant in the app first.");

  $peerNormalized = (strpos($peer, '+') !== false || preg_match('/^\d+$/', $peer)) ? normalize_phone($peer) : $peer;
  if (!$peerNormalized && $peer !== '') $peerNormalized = $peer;

  $sent = grey_call($s['tenant_id'], $s['api_token'] ?? null, '/messages/send', 'POST', [
    'peer' => $peerNormalized ?? $peer,
    'text' => $text,
    'allow_import_contact' => true
  ]);

  message_log_insert('out', portal_key($auth), $s['tenant_id'], $peerNormalized ?? $peer, $text, null, 'contact_center', null);

  json_response(['ok' => true, 'peer' => $peer, 'grey' => $sent]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()], 400);
}
