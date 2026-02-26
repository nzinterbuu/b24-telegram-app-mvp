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
$stmt = $pdo->prepare("SELECT tenant_id, peer, external_user_id, external_chat_id, created_at, updated_at FROM ol_map WHERE portal=? AND external_chat_id=? LIMIT 1");
$stmt->execute([$portal, $externalChatId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$peerSendable = $row ? grey_peer_likely_sendable(trim((string)$row['peer'])) : false;
$peerNormalized = $row ? grey_normalize_peer($row['peer']) : '';

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
      <tr><th>tenant_id</th><td><?= htmlspecialchars($row['tenant_id'] ?? '') ?></td></tr>
      <tr><th>peer (stored)</th><td><code><?= htmlspecialchars($row['peer'] ?? '') ?></code></td></tr>
      <tr><th>peer (normalized)</th><td><code><?= htmlspecialchars($peerNormalized) ?></code></td></tr>
      <tr><th>Grey sendable?</th><td class="<?= $peerSendable ? 'ok' : 'err' ?>"><?= $peerSendable ? 'Yes (E.164 or @username)' : 'No — replies may get invalid_peer. Re-link chat with a new message from Telegram.' ?></td></tr>
      <tr><th>external_user_id</th><td><code><?= htmlspecialchars($row['external_user_id'] ?? '') ?></code></td></tr>
      <tr><th>created_at</th><td><?= isset($row['created_at']) ? date('Y-m-d H:i:s', (int)$row['created_at']) : '—' ?></td></tr>
      <tr><th>updated_at</th><td><?= isset($row['updated_at']) ? date('Y-m-d H:i:s', (int)$row['updated_at']) : '—' ?></td></tr>
    </table>
  <?php endif; ?>
</body>
</html>
