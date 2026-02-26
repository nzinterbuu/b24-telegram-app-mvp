<?php
/**
 * Debug page: show Open Lines mapping for a chat (portal, external_chat_id → tenant_id, peer).
 * GET: token (required, DEBUG_OL_MAP_TOKEN), portal (required), external_chat_id (required).
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/grey.php';

$token = trim($_GET['token'] ?? '');
$expected = getenv('DEBUG_OL_MAP_TOKEN') ?: cfg('DEBUG_OL_MAP_TOKEN', '');
if ($expected === '' || $token !== $expected) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Forbidden. Set DEBUG_OL_MAP_TOKEN and pass ?token=...';
  exit;
}

$portal = trim($_GET['portal'] ?? '');
$externalChatId = trim($_GET['external_chat_id'] ?? '');
if ($portal === '' || $externalChatId === '') {
  header('Content-Type: text/html; charset=utf-8');
  echo '<p>Missing <code>portal</code> or <code>external_chat_id</code>. Use ?portal=b24-xxx.bitrix24.ru&external_chat_id=tg_c_...</code></p>';
  exit;
}

$pdo = ensure_db();
$stmt = $pdo->prepare("SELECT tenant_id, peer, peer_send, external_user_id, external_chat_id, created_at, updated_at FROM ol_map WHERE portal=? AND external_chat_id=? LIMIT 1");
$stmt->execute([$portal, $externalChatId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$peerRaw = $row ? trim((string)($row['peer'] ?? '')) : '';
$peerSend = $row && isset($row['peer_send']) && $row['peer_send'] !== '' ? trim((string)$row['peer_send']) : null;
$hasSendable = $peerSend !== null && $peerSend !== '';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>OL Chat Diag</title>
  <style>
    body { font-family: sans-serif; margin: 16px; }
    table { border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
    th { background: #f0f0f0; }
    .ok { color: green; }
    .err { color: #c00; }
    code { background: #f5f5f5; padding: 1px 4px; }
  </style>
</head>
<body>
  <h2>Open Lines chat diagnostics</h2>
  <p><strong>Portal:</strong> <?= htmlspecialchars($portal) ?></p>
  <p><strong>external_chat_id:</strong> <code><?= htmlspecialchars($externalChatId) ?></code></p>
  <?php if (!$row): ?>
    <p class="err">No ol_map row found for this portal and external_chat_id. Send a message from Telegram first so the chat is linked.</p>
  <?php else: ?>
    <table>
      <tr><th>portal</th><td><code><?= htmlspecialchars($portal) ?></code></td></tr>
      <tr><th>tenant_id</th><td><?= htmlspecialchars($row['tenant_id'] ?? '') ?></td></tr>
      <tr><th>external_chat_id</th><td><code><?= htmlspecialchars($row['external_chat_id'] ?? '') ?></code></td></tr>
      <tr><th>peer_raw</th><td><code><?= htmlspecialchars($peerRaw) ?></code></td></tr>
      <tr><th>peer_send</th><td class="<?= $hasSendable ? 'ok' : 'err' ?>"><code><?= $hasSendable ? htmlspecialchars($peerSend) : '—' ?></code> <?= $hasSendable ? '(used for Grey /messages/send)' : '— No sendable peer; replies will be undelivered.' ?></td></tr>
      <tr><th>external_user_id</th><td><code><?= htmlspecialchars($row['external_user_id'] ?? '') ?></code></td></tr>
      <tr><th>created_at</th><td><?= isset($row['created_at']) && $row['created_at'] !== null ? date('Y-m-d H:i:s', (int)$row['created_at']) : '—' ?></td></tr>
      <tr><th>updated_at (last inbound/update)</th><td><?= isset($row['updated_at']) && $row['updated_at'] !== null ? date('Y-m-d H:i:s', (int)$row['updated_at']) : '—' ?></td></tr>
    </table>
    <p><small>If Bitrix still shows “сообщение не доставлено” after a reply, check server logs for <code>[OpenLines Handler] Delivery status failed</code> or <code>message_id</code> (should be non-empty). Delivery is reported via <code>imconnector.send.status.delivery</code> with <code>im</code> from the event.</small></p>
  <?php endif; ?>
</body>
</html>
