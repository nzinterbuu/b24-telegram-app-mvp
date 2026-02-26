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
 * Normalize peer for Grey API /messages/send.
 * Grey expects: "me", @username, numeric user/chat id, or phone E.164 (e.g. +79001234567).
 * Deal path uses E.164 phone. Use this in inbound storage and outbound so peer format is consistent.
 * Returns: E.164 when input looks like a phone (starts with + or 11+ digits); @username as-is; else as-is (e.g. numeric Telegram id).
 */
function grey_normalize_peer(?string $peer): string {
  $peer = trim((string)$peer);
  if ($peer === '') return '';
  if (preg_match('/^@[\w.]+\s*$/u', $peer)) return $peer;
  $digits = preg_replace('/[^0-9+]/', '', $peer);
  $hasPlus = strpos($peer, '+') !== false;
  if ($hasPlus && strlen($digits) >= 10) {
    if ($digits[0] !== '+') $digits = '+' . ltrim($digits, '0');
    return $digits;
  }
  if (!$hasPlus && strlen($digits) >= 11) {
    return '+' . ltrim($digits, '0');
  }
  return $peer;
}

/**
 * Return true if peer is in a format known to work with Grey send (E.164 or @username). Digits-only may cause invalid_peer.
 */
function grey_peer_likely_sendable(string $peer): bool {
  $peer = trim($peer);
  if ($peer === '') return false;
  if (preg_match('/^@[\w.]+\s*$/u', $peer)) return true;
  if (preg_match('/^\+\d{10,}$/', preg_replace('/\s/', '', $peer))) return true;
  return false;
}

/**
 * Build sendable peer for Grey /messages/send from inbound data.
 * Grey accepts: E.164 phone, @username, or (per API) numeric id — but numeric id often causes invalid_peer, so we prefer E.164 or @username.
 * Returns: E.164 phone if available, else @username if available, else null (no sendable peer).
 */
function grey_get_send_peer(?string $phone, ?string $senderUsername): ?string {
  $phone = $phone !== null && $phone !== '' ? trim($phone) : null;
  $senderUsername = $senderUsername !== null && $senderUsername !== '' ? trim($senderUsername) : null;
  if ($phone !== null) {
    $normalized = grey_normalize_peer($phone);
    if ($normalized !== '' && grey_peer_likely_sendable($normalized)) return $normalized;
  }
  if ($senderUsername !== null) {
    $u = (strpos($senderUsername, '@') === 0) ? $senderUsername : ('@' . $senderUsername);
    return $u;
  }
  return null;
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

  // 1) Preferred: exact portal + tenant_id match (return even if api_token empty — Grey API may not require token, see grey-tg.onrender.com/docs)
  if ($portal !== '') {
    $stmt = $pdo->prepare("SELECT portal, tenant_id, api_token, user_id, phone FROM user_settings WHERE portal=? AND tenant_id=? LIMIT 1");
    $stmt->execute([$portal, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      return [
        'portal' => $row['portal'],
        'tenant_id' => $row['tenant_id'],
        'api_token' => !empty($row['api_token']) ? $row['api_token'] : null,
        'user_id' => (int)($row['user_id'] ?? 0),
        'phone' => $row['phone'] ?? null,
      ];
    }
  }

  // 2) Fallback: any tenant_id match (portal-agnostic) — Grey tenant is global, not per Bitrix portal
  $stmt = $pdo->prepare("SELECT portal, tenant_id, api_token, user_id, phone FROM user_settings WHERE tenant_id=? LIMIT 1");
  $stmt->execute([$tenantId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
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
      'api_token' => !empty($row['api_token']) ? $row['api_token'] : null,
      'user_id' => (int)($row['user_id'] ?? 0),
      'phone' => $row['phone'] ?? null,
    ];
  }

  // 3) Portal fallback: any row on this portal (tenant_id may differ; api_token optional — Grey may not require it)
  if ($portal !== '') {
    $stmt = $pdo->prepare("SELECT portal, tenant_id, api_token, user_id, phone FROM user_settings WHERE portal=? LIMIT 1");
    $stmt->execute([$portal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      log_debug('grey_get_tenant_credentials portal fallback', [
        'requested_tenant_id' => $tenantId,
        'portal' => $portal,
        'using_tenant_id' => $row['tenant_id'],
      ]);
      return [
        'portal' => $row['portal'],
        'tenant_id' => $row['tenant_id'],
        'api_token' => !empty($row['api_token']) ? $row['api_token'] : null,
        'user_id' => (int)($row['user_id'] ?? 0),
        'phone' => $row['phone'] ?? null,
      ];
    }
  }

  return null;
}
