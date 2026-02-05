<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/b24.php';

try {
  $req = read_json();
  $auth = $req['auth'] ?? [];
  $portal = portal_key($auth);
  $limit = min(100, max(10, (int)($req['limit'] ?? 50)));

  $pdo = ensure_db();
  $stmt = $pdo->prepare("SELECT id, direction, tenant_id, peer, text_preview, deal_id, source, created_at, error_text FROM message_log WHERE portal = ? ORDER BY created_at DESC LIMIT ?");
  $stmt->execute([$portal, $limit]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $list = [];
  foreach ($rows as $r) {
    $list[] = [
      'id' => (int)$r['id'],
      'direction' => $r['direction'],
      'tenant_id' => $r['tenant_id'] ?: null,
      'peer' => $r['peer'],
      'text_preview' => $r['text_preview'],
      'deal_id' => $r['deal_id'] ? (int)$r['deal_id'] : null,
      'source' => $r['source'] ?: null,
      'created_at' => (int)$r['created_at'],
      'error_text' => $r['error_text'] ?: null,
    ];
  }

  json_response(['ok' => true, 'messages' => $list]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
