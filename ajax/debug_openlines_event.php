<?php
/**
 * Dev tool: simulate OnImConnectorMessageAdd by sending a sample payload to the Open Lines handler.
 * POST JSON: { "event_payload": { ... Bitrix event shape ... }, "auth": { ... } (optional, for member_id) }
 * Forwards to openlines/handler.php and returns the handler response.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body) || empty($body['event_payload'])) {
  json_response(['ok' => false, 'error' => 'Missing event_payload. Send JSON: { "event_payload": { "event": "OnImConnectorMessageAdd", "data": { "connector": "telegram_grey", "line": "LINE_ID", "MESSAGES": [ { "message": { "id": "MSG_ID", "text": "Hello" }, "chat": { "id": "tg_c_..." }, "user": { "id": "tg_u_..." } } ] } } }']);
  exit;
}

$eventPayload = $body['event_payload'];
if (isset($body['auth']['member_id'])) {
  $eventPayload['member_id'] = $body['auth']['member_id'];
}
if (isset($body['auth']['domain'])) {
  $eventPayload['domain'] = $body['auth']['domain'];
}

$base = rtrim(cfg('PUBLIC_URL'), '/');
$handlerUrl = $base . '/openlines/handler.php';

$ctx = stream_context_create([
  'http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => json_encode($eventPayload, JSON_UNESCAPED_UNICODE),
    'timeout' => 30,
  ],
]);

$response = @file_get_contents($handlerUrl, false, $ctx);
if ($response === false) {
  $err = error_get_last();
  json_response(['ok' => false, 'error' => 'Failed to call handler: ' . ($err['message'] ?? 'unknown')]);
  exit;
}

$decoded = json_decode($response, true);
if (is_array($decoded)) {
  json_response($decoded);
} else {
  json_response(['ok' => false, 'raw_response' => substr($response, 0, 500)]);
}
