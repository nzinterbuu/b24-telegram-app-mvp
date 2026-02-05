<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
  http_response_code(500);
  echo "Missing config.php. Copy config.example.php to config.php and edit.";
  exit;
}
$config = require $configFile;

function cfg(string $key, $default=null) {
  global $config;
  return array_key_exists($key, $config) ? $config[$key] : $default;
}

date_default_timezone_set('UTC');

function json_response($data, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function read_json(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function ensure_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $dbPath = __DIR__ . '/../storage.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
      portal TEXT NOT NULL,
      user_id INTEGER NOT NULL,
      tenant_id TEXT,
      api_token TEXT,
      phone TEXT,
      PRIMARY KEY (portal, user_id)
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS b24_oauth_tokens (
      portal TEXT PRIMARY KEY,
      access_token TEXT NOT NULL,
      refresh_token TEXT NOT NULL,
      expires_at INTEGER NOT NULL,
      member_id TEXT,
      updated_at INTEGER
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS message_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      direction TEXT NOT NULL,
      portal TEXT NOT NULL,
      tenant_id TEXT,
      peer TEXT NOT NULL,
      text_preview TEXT,
      deal_id INTEGER,
      source TEXT,
      created_at INTEGER NOT NULL,
      error_text TEXT
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_message_log_portal_created ON message_log(portal, created_at DESC)");
  return $pdo;
}

/** Log an inbound or outbound message for the message history. */
function message_log_insert(string $direction, string $portal, ?string $tenantId, string $peer, string $text, ?int $dealId = null, ?string $source = null, ?string $errorText = null): void {
  $pdo = ensure_db();
  $preview = $text !== '' ? mb_substr($text, 0, 500) : '';
  $stmt = $pdo->prepare("INSERT INTO message_log (direction, portal, tenant_id, peer, text_preview, deal_id, source, created_at, error_text) VALUES (?,?,?,?,?,?,?,?,?)");
  $stmt->execute([$direction, $portal, $tenantId ?? '', $peer, $preview, $dealId, $source ?? '', time(), $errorText ?? '']);
}

function log_debug(string $msg, array $ctx=[]): void {
  if (!cfg('DEBUG', false)) return;
  $line = '['.gmdate('c').'] '.$msg;
  if ($ctx) $line .= ' '.json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $line .= "\n";
  @mkdir(__DIR__ . '/../logs', 0777, true);
  @file_put_contents(__DIR__ . '/../logs/app.log', $line, FILE_APPEND);
}
