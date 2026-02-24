<?php
/**
 * Diagnostic endpoint to inspect ol_map entries. For debugging only.
 * Protected by DEBUG_OL_MAP_TOKEN (env or config). Query by portal + external_chat_id or portal + peer.
 *
 * GET params: token (required), portal (required), external_chat_id OR peer.
 * Example: ?token=xxx&portal=b24-xxx.bitrix24.ru&external_chat_id=tg_c_abc...
 * Example: ?token=xxx&portal=b24-xxx.bitrix24.ru&peer=+79001234567
 */
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_DEBUG_TOKEN'] ?? '';
$expected = getenv('DEBUG_OL_MAP_TOKEN') ?: cfg('DEBUG_OL_MAP_TOKEN', '');
if ($expected === '' || $token !== $expected) {
  http_response_code(403);
  json_response(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$portal = trim($_GET['portal'] ?? '');
$externalChatId = trim($_GET['external_chat_id'] ?? '');
$peer = trim($_GET['peer'] ?? '');

if ($portal === '') {
  json_response(['ok' => false, 'error' => 'Missing portal']);
  exit;
}

$pdo = ensure_db();
$rows = [];

if ($externalChatId !== '') {
  $stmt = $pdo->prepare("SELECT portal, tenant_id, peer, external_user_id, external_chat_id, created_at, updated_at FROM ol_map WHERE portal=? AND external_chat_id=?");
  $stmt->execute([$portal, $externalChatId]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = $row;
  }
} elseif ($peer !== '') {
  $stmt = $pdo->prepare("SELECT portal, tenant_id, peer, external_user_id, external_chat_id, created_at, updated_at FROM ol_map WHERE portal=? AND peer=?");
  $stmt->execute([$portal, $peer]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = $row;
  }
} else {
  json_response(['ok' => false, 'error' => 'Provide either external_chat_id or peer']);
  exit;
}

json_response(['ok' => true, 'portal' => $portal, 'query' => ['external_chat_id' => $externalChatId ?: null, 'peer' => $peer ?: null], 'rows' => $rows]);
