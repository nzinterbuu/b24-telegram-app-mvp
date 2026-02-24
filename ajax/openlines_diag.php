<?php
/**
 * Diagnostic endpoint for Open Lines / Grey mapping.
 *
 * GET params:
 *  - token (required): must match DEBUG_OL_MAP_TOKEN (env or config)
 *  - portal (required): Bitrix24 portal domain, e.g. b24-u0gkt4.bitrix24.ru
 *  - external_chat_id (optional): if set, show ol_map rows for this chat id
 *
 * Response:
 *  {
 *    ok: true,
 *    portal: "...",
 *    line_id: "...",
 *    tenants: [
 *      { tenant_id, user_id, phone, has_token, token_tail }
 *    ],
 *    ol_map: [
 *      { tenant_id, peer, external_user_id, external_chat_id, created_at, updated_at }
 *    ]
 *  }
 */
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_DEBUG_TOKEN'] ?? '';
$expected = getenv('DEBUG_OL_MAP_TOKEN') ?: cfg('DEBUG_OL_MAP_TOKEN', '');
if ($expected === '' || $token !== $expected) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$portal = trim($_GET['portal'] ?? '');
$externalChatId = trim($_GET['external_chat_id'] ?? '');

if ($portal === '') {
  echo json_encode(['ok' => false, 'error' => 'Missing portal'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$pdo = ensure_db();

// 1) line_id for this portal
$lineId = get_portal_line_id($portal);

// 2) Grey tenants (user_settings) for this portal
$tenants = [];
$stmt = $pdo->prepare("SELECT user_id, tenant_id, api_token, phone FROM user_settings WHERE portal=?");
$stmt->execute([$portal]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $token = $row['api_token'] ?? '';
  $tenants[] = [
    'user_id' => (int)($row['user_id'] ?? 0),
    'tenant_id' => $row['tenant_id'] ?? null,
    'phone' => $row['phone'] ?? null,
    'has_token' => $token !== null && $token !== '',
    'token_tail' => ($token && strlen($token) >= 4) ? substr($token, -4) : null,
  ];
}

// 3) ol_map rows for this portal + external_chat_id (if provided)
$olMapRows = [];
if ($externalChatId !== '') {
  $stmt = $pdo->prepare("SELECT tenant_id, peer, external_user_id, external_chat_id, created_at, updated_at FROM ol_map WHERE portal=? AND external_chat_id=?");
  $stmt->execute([$portal, $externalChatId]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $olMapRows[] = $row;
  }
}

echo json_encode([
  'ok' => true,
  'portal' => $portal,
  'line_id' => $lineId,
  'tenants' => $tenants,
  'ol_map' => $olMapRows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

