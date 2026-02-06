<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Normalize auth from Bitrix24 formats: iframe POST uses DOMAIN/AUTH_ID, OAuth event uses domain/access_token */
function b24_normalize_auth(array $auth): array {
  return [
    'domain'       => $auth['domain'] ?? $auth['DOMAIN'] ?? null,
    'access_token' => $auth['access_token'] ?? $auth['AUTH_ID'] ?? null,
    'refresh_token' => $auth['refresh_token'] ?? $auth['REFRESH_ID'] ?? null,
    'member_id'    => $auth['member_id'] ?? null,
  ];
}

function b24_get_oauth_tokens(string $portal): ?array {
  $pdo = ensure_db();
  $stmt = $pdo->prepare("SELECT access_token, refresh_token, expires_at, member_id FROM b24_oauth_tokens WHERE portal=?");
  $stmt->execute([$portal]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function b24_save_oauth_tokens(string $portal, array $data): void {
  $pdo = ensure_db();
  $stmt = $pdo->prepare("INSERT INTO b24_oauth_tokens (portal, access_token, refresh_token, expires_at, member_id, updated_at)
    VALUES (?,?,?,?,?,?)
    ON CONFLICT(portal) DO UPDATE SET access_token=excluded.access_token, refresh_token=excluded.refresh_token, expires_at=excluded.expires_at, member_id=excluded.member_id, updated_at=excluded.updated_at");
  $stmt->execute([
    $portal,
    $data['access_token'] ?? '',
    $data['refresh_token'] ?? '',
    (int)($data['expires_at'] ?? 0),
    $data['member_id'] ?? null,
    time(),
  ]);
}

function b24_delete_oauth_tokens(string $portal): void {
  $pdo = ensure_db();
  $stmt = $pdo->prepare("DELETE FROM b24_oauth_tokens WHERE portal=?");
  $stmt->execute([$portal]);
}

function b24_refresh_oauth_tokens(string $portal): array {
  $row = b24_get_oauth_tokens($portal);
  if (!$row || empty($row['refresh_token'])) {
    throw new Exception("No refresh token for portal {$portal}");
  }
  $clientId = cfg('B24_CLIENT_ID');
  $clientSecret = cfg('B24_CLIENT_SECRET');
  if (!$clientId || !$clientSecret) {
    throw new Exception("B24_CLIENT_ID and B24_CLIENT_SECRET required for token refresh");
  }
  $tokenUrl = 'https://oauth.bitrix.info/oauth/token/?' . http_build_query([
    'grant_type' => 'refresh_token',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'refresh_token' => $row['refresh_token'],
  ]);
  $ch = curl_init($tokenUrl);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
  $raw = curl_exec($ch);
  curl_close($ch);
  $data = json_decode($raw, true);
  if (!is_array($data) || isset($data['error'])) {
    throw new Exception($data['error_description'] ?? $data['error'] ?? 'Token refresh failed');
  }
  $expiresAt = time() + (int)($data['expires_in'] ?? 3600);
  b24_save_oauth_tokens($portal, [
    'access_token' => $data['access_token'] ?? '',
    'refresh_token' => $data['refresh_token'] ?? $row['refresh_token'],
    'expires_at' => $expiresAt,
    'member_id' => $data['member_id'] ?? $row['member_id'],
  ]);
  return ['domain' => $portal, 'access_token' => $data['access_token'] ?? ''];
}

/** Get auth from stored tokens for a portal; refreshes if expired (1 min buffer). */
function b24_get_stored_auth(string $portal): ?array {
  $row = b24_get_oauth_tokens($portal);
  if (!$row) return null;
  $expiresAt = (int)($row['expires_at'] ?? 0);
  if (time() >= $expiresAt - 60) {
    try {
      $refreshed = b24_refresh_oauth_tokens($portal);
      return $refreshed;
    } catch (Throwable $e) {
      log_debug('token refresh failed', ['portal' => $portal, 'e' => $e->getMessage()]);
      return null;
    }
  }
  return ['domain' => $portal, 'access_token' => $row['access_token']];
}

/** Get first portal with stored tokens (for server-side calls like inbound webhook). */
function b24_get_first_portal(): ?string {
  $pdo = ensure_db();
  $stmt = $pdo->query("SELECT portal FROM b24_oauth_tokens LIMIT 1");
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ? $row['portal'] : null;
}

function b24_call(string $method, array $params = [], ?array $auth = null): array {
  // Webhook-first: if B24_WEBHOOK_URL is set, use it (no auth needed)
  $webhook = rtrim(getenv('B24_WEBHOOK_URL') ?: cfg('B24_WEBHOOK_URL', ''), '/');
  if ($webhook !== '') {
    $url = $webhook . '/' . $method . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new Exception("cURL error: ".$err);
    $data = json_decode($raw, true);
    if ($code >= 400) {
      throw new Exception($data['error_description'] ?? $data['error'] ?? ("Bitrix24 HTTP ".$code));
    }
    return is_array($data) ? $data : ['raw'=>$raw];
  }

  // Fallback: OAuth (for UI calls from iframe)
  if (!$auth) {
    throw new Exception("B24_WEBHOOK_URL is not set and no auth provided. Set B24_WEBHOOK_URL in config/env for server-side calls.");
  }
  $auth = b24_normalize_auth($auth);
  $domain = $auth['domain'] ?? null;
  $token  = $auth['access_token'] ?? null;

  // If no token, try stored OAuth tokens by portal (for server-side e.g. inbound webhook)
  if ((!$domain || !$token)) {
    $portal = $auth['portal'] ?? $auth['domain'] ?? $auth['DOMAIN'] ?? null;
    if ($portal) {
      $stored = b24_get_stored_auth((string)$portal);
      if ($stored) {
        $domain = $stored['domain'];
        $token = $stored['access_token'];
      }
    }
  }
  if (!$domain || !$token) {
    $portal = $auth['portal'] ?? $auth['domain'] ?? $auth['DOMAIN'] ?? null;
    if ($portal) {
      throw new Exception("No Bitrix24 tokens for this portal. Re-install the app from Bitrix24 (open app from left menu and run installer) to save OAuth tokens.");
    }
    throw new Exception("Missing Bitrix24 auth. Please open the app from inside Bitrix24.");
  }

  $url = "https://{$domain}/rest/{$method}.json";
  $params['auth'] = $token;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) throw new Exception("cURL error: ".$err);
  $data = json_decode($raw, true);
  if ($code >= 400) {
    throw new Exception($data['error_description'] ?? $data['error'] ?? ("Bitrix24 HTTP ".$code));
  }
  return is_array($data) ? $data : ['raw'=>$raw];
}

function portal_key(array $auth): string {
  $norm = b24_normalize_auth($auth);
  return $norm['domain'] ?? $auth['portal'] ?? 'unknown';
}

function db_get_user_settings(array $auth, int $userId): array {
  $portal = portal_key($auth);
  $pdo = ensure_db();
  $stmt = $pdo->prepare("SELECT tenant_id, api_token, phone FROM user_settings WHERE portal=? AND user_id=?");
  $stmt->execute([$portal, $userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: ['tenant_id'=>null,'api_token'=>null,'phone'=>null];
}

function db_save_user_settings(array $auth, int $userId, array $settings): void {
  $portal = portal_key($auth);
  $pdo = ensure_db();
  $stmt = $pdo->prepare("INSERT INTO user_settings (portal, user_id, tenant_id, api_token, phone)
    VALUES (?,?,?,?,?)
    ON CONFLICT(portal,user_id) DO UPDATE SET tenant_id=excluded.tenant_id, api_token=excluded.api_token, phone=excluded.phone");
  $stmt->execute([$portal, $userId, $settings['tenant_id'] ?? null, $settings['api_token'] ?? null, $settings['phone'] ?? null]);
}
