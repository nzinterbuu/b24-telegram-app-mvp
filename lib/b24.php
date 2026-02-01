<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function b24_call(array $auth, string $method, array $params = []): array {
  if (!empty(cfg('B24_WEBHOOK_URL'))) {
    $url = rtrim(cfg('B24_WEBHOOK_URL'), '/') . '/' . $method . '.json';
  } else {
    $domain = $auth['domain'] ?? null;
    $token  = $auth['access_token'] ?? null;
    if (!$domain || !$token) throw new Exception("Missing Bitrix24 auth. Please reopen app inside Bitrix24.");
    $url = "https://{$domain}/rest/{$method}.json";
    $params['auth'] = $token;
  }

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
  return $auth['domain'] ?? 'unknown';
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
