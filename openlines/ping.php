<?php
/**
 * Health check for Open Lines handler reachability.
 * Always returns 200 "pong". Use to verify Bitrix can reach our server (e.g. from same host as handler).
 * Grep Render logs for: [OL PING]
 */
@error_log('[OL PING] hit ' . date('c'));
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'pong' => true, 'ping' => 'openlines']);
