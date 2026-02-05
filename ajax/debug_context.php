<?php
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$parsedInput = $rawInput ? (json_decode($rawInput, true) ?: ['_raw' => $rawInput]) : null;

$placementOptionsRaw = $_GET['PLACEMENT_OPTIONS'] ?? $_GET['placement_options'] ?? null;
$placementOptionsDecoded = null;
if ($placementOptionsRaw !== null && $placementOptionsRaw !== '') {
  $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], (string)$placementOptionsRaw), true);
  $placementOptionsDecoded = is_string($decoded) ? json_decode($decoded, true) : null;
  if (!is_array($placementOptionsDecoded)) {
    $placementOptionsDecoded = json_decode((string)$placementOptionsRaw, true) ?: ['_decode_failed' => true, 'raw_length' => strlen((string)$placementOptionsRaw)];
  }
}

$out = [
  'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
  'GET' => $_GET,
  'POST' => $_POST,
  'REQUEST' => $_REQUEST,
  'php_input_parsed' => $parsedInput,
  'headers' => [
    'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? null,
    'HTTP_CONTENT_TYPE' => $_SERVER['HTTP_CONTENT_TYPE'] ?? null,
    'HTTP_USER_AGENT' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 120) . '...' : null,
  ],
  'PLACEMENT_OPTIONS' => [
    'in_GET' => isset($_GET['PLACEMENT_OPTIONS']) || isset($_GET['placement_options']),
    'raw_length' => $placementOptionsRaw !== null ? strlen((string)$placementOptionsRaw) : 0,
    'decoded' => $placementOptionsDecoded,
  ],
  'extracted_deal_id' => null,
];
if (is_array($placementOptionsDecoded)) {
  $out['extracted_deal_id'] = $placementOptionsDecoded['ID'] ?? $placementOptionsDecoded['id'] ?? $placementOptionsDecoded['ENTITY_ID'] ?? $placementOptionsDecoded['entityId'] ?? $placementOptionsDecoded['dealId'] ?? $placementOptionsDecoded['OWNER_ID'] ?? null;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
