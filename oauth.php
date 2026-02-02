<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/b24.php';

$clientId = cfg('B24_CLIENT_ID');
$clientSecret = cfg('B24_CLIENT_SECRET');
$publicUrl = rtrim(cfg('PUBLIC_URL'), '/');
$redirectUri = $publicUrl . '/oauth.php';

if (!$clientId || !$clientSecret) {
  http_response_code(500);
  echo 'OAuth not configured. Set B24_CLIENT_ID and B24_CLIENT_SECRET in config.php';
  exit;
}

// Error from Bitrix24
$error = $_GET['error'] ?? null;
if ($error) {
  $desc = $_GET['error_description'] ?? $error;
  http_response_code(400);
  echo '<!doctype html><meta charset="utf-8"><h2>Authorization failed</h2><p>' . htmlspecialchars($desc) . '</p><p><a href="' . htmlspecialchars($publicUrl . '/index.php') . '">Back to app</a></p>';
  exit;
}

// OAuth callback with code
$code = $_GET['code'] ?? null;
$domain = $_GET['domain'] ?? null;
$memberId = $_GET['member_id'] ?? null;

if (!$code) {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta charset="utf-8"><pre>OAuth redirect – no authorization code received.

The app uses OAuth 2.0. Install and open the app from Bitrix24; 
tokens are obtained via the install flow or BX24.getAuth() in the iframe.

If you invoked OAuth manually, ensure the redirect returns with ?code=...</pre>';
  exit;
}

// Exchange code for tokens
$tokenUrl = 'https://oauth.bitrix.info/oauth/token/?' . http_build_query([
  'grant_type' => 'authorization_code',
  'client_id' => $clientId,
  'client_secret' => $clientSecret,
  'code' => $code,
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($ch);
$code_http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
  http_response_code(502);
  echo 'Failed to reach Bitrix24 OAuth server.';
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || isset($data['error'])) {
  $err = $data['error_description'] ?? $data['error'] ?? 'Unknown OAuth error';
  log_debug('oauth token error', ['response' => $data]);
  http_response_code(400);
  echo '<!doctype html><meta charset="utf-8"><h2>Token exchange failed</h2><p>' . htmlspecialchars($err) . '</p>';
  exit;
}

// Extract portal domain from client_endpoint (e.g. https://portal.bitrix24.com/rest/ -> portal.bitrix24.com)
$clientEndpoint = $data['client_endpoint'] ?? '';
$portal = $domain;
if (!$portal && preg_match('#https?://([^/]+)#', $clientEndpoint, $m)) {
  $portal = $m[1];
}
if (!$portal) {
  $portal = $data['member_id'] ?? $memberId ?? 'unknown';
}

$accessToken = $data['access_token'] ?? '';
$refreshToken = $data['refresh_token'] ?? '';
$expiresIn = (int)($data['expires_in'] ?? 3600);
$expiresAt = time() + $expiresIn;

if (!$accessToken || !$refreshToken) {
  http_response_code(500);
  echo 'Invalid token response from Bitrix24.';
  exit;
}

// Store tokens
b24_save_oauth_tokens($portal, [
  'access_token' => $accessToken,
  'refresh_token' => $refreshToken,
  'expires_at' => $expiresAt,
  'member_id' => $data['member_id'] ?? $memberId,
]);

// Redirect to app
header('Location: ' . $publicUrl . '/index.php');
exit;
