<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** App-level Grey API calls (no tenant in path): GET /tenants, POST /tenants. Optional server token from GREY_API_SERVER_TOKEN. */
function grey_app_call(string $path, string $method = 'GET', ?array $body = null): array {
  $base = rtrim(cfg('GREY_API_BASE'), '/');
  $url = $base . $path;

  $headers = ['Accept: application/json'];
  $tokenHeader = trim((string)cfg('GREY_API_TOKEN_HEADER', ''));
  $serverToken = trim((string)cfg('GREY_API_SERVER_TOKEN', ''));
  if ($tokenHeader !== '' && $serverToken !== '') {
    $headers[] = $tokenHeader . ': ' . $serverToken;
  }
  if ($method !== 'GET') $headers[] = 'Content-Type: application/json';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  }
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) throw new Exception("Grey API cURL error: " . $err);
  $data = json_decode($raw, true);
  if ($code >= 400) {
    $msg = 'Grey API error';
    if (is_array($data)) {
      $d = $data['detail'] ?? $data['message'] ?? $data['error'] ?? null;
      if (is_string($d)) $msg = $d;
      elseif (is_array($d)) $msg = $d['msg'] ?? json_encode($d);
    }
    throw new Exception($msg);
  }
  return is_array($data) ? $data : ['raw' => $raw];
}

function grey_call(string $tenantId, ?string $apiToken, string $path, string $method='GET', ?array $body=null): array {
  $base = rtrim(cfg('GREY_API_BASE'), '/');
  $url = $base . "/tenants/" . rawurlencode($tenantId) . $path;

  $headers = ['Accept: application/json'];
  $tokenHeader = trim((string)cfg('GREY_API_TOKEN_HEADER',''));
  if ($tokenHeader !== '' && $apiToken) {
    $headers[] = $tokenHeader . ': ' . $apiToken;
  }
  if ($method !== 'GET') $headers[] = 'Content-Type: application/json';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  }
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) throw new Exception("Grey API cURL error: ".$err);
  $data = json_decode($raw, true);
  if ($code >= 400) {
    $msg = 'Grey API error';
    if (is_array($data)) {
      $d = $data['detail'] ?? $data['message'] ?? $data['error'] ?? null;
      if (is_string($d)) $msg = $d;
      elseif (is_array($d)) $msg = $d['msg'] ?? json_encode($d);
    }
    throw new Exception($msg);
  }
  return is_array($data) ? $data : ['raw'=>$raw];
}
