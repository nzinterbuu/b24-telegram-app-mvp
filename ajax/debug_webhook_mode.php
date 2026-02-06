<?php
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$webhook = rtrim(getenv('B24_WEBHOOK_URL') ?: cfg('B24_WEBHOOK_URL', ''), '/');
echo json_encode([
  'B24_WEBHOOK_URL_set' => $webhook !== '',
  'B24_WEBHOOK_URL_value' => $webhook !== '' ? substr($webhook, 0, 50) . '...' : null,
  'mode' => $webhook !== '' ? 'webhook' : 'oauth',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
