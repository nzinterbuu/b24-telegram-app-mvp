<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Update a Grey tenant (e.g. callback_url). Uses app-level server token.
 * PATCH /tenants/{tenant_id} — body: { "name": "string", "callback_url": "string" } (Swagger: grey-tg.onrender.com/docs).
 */
function grey_update_tenant(string $tenantId, array $patchPayload): array {
  $path = '/tenants/' . rawurlencode(trim($tenantId));
  return grey_app_call($path, 'PATCH', $patchPayload);
}

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

/**
 * Resolve Grey tenant credentials for outbound (Open Lines, deal send, etc.).
 * Primary key is (portal, tenant_id) in user_settings; we fallback to any row with this tenant_id
 * to be robust if portal was changed or not stored consistently.
 */
function grey_get_tenant_credentials(string $portal, string $tenantId): ?array {
  $tenantId = trim($tenantId);
  $portal = trim($portal);
  if ($tenantId === '') return null;
  $pdo = ensure_db();

  // 1) Preferred: exact portal + tenant_id match
  if ($portal !== '') {
    $stmt = $pdo->prepare("SELECT portal, tenant_id, api_token, user_id, phone FROM user_settings WHERE portal=? AND tenant_id=? LIMIT 1");
    $stmt->execute([$portal, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['api_token'])) {
      return [
        'portal' => $row['portal'],
        'tenant_id' => $row['tenant_id'],
        'api_token' => $row['api_token'],
        'user_id' => (int)($row['user_id'] ?? 0),
        'phone' => $row['phone'] ?? null,
      ];
    }
  }

  // 2) Fallback: any tenant_id match (portal-agnostic) — Grey tenant is global, not per Bitrix portal
  $stmt = $pdo->prepare("SELECT portal, tenant_id, api_token, user_id, phone FROM user_settings WHERE tenant_id=? LIMIT 1");
  $stmt->execute([$tenantId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row && !empty($row['api_token'])) {
    if (cfg('DEBUG')) {
      log_debug('grey_get_tenant_credentials fallback by tenant_id', [
        'requested_portal' => $portal,
        'stored_portal' => $row['portal'],
        'tenant_id' => $tenantId,
      ]);
    }
    return [
      'portal' => $row['portal'],
      'tenant_id' => $row['tenant_id'],
      'api_token' => $row['api_token'],
      'user_id' => (int)($row['user_id'] ?? 0),
      'phone' => $row['phone'] ?? null,
    ];
  }

  return null;
}
