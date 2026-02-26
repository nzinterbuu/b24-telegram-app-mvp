<?php
/**
 * Health check for Open Lines handler reachability.
 * Always returns 200. Use to verify Bitrix can reach our server (e.g. from same host as handler).
 */
@error_log('[OpenLines] ping hit');
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ping' => 'openlines']);
