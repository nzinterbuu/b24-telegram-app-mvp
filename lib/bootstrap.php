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
  return $pdo;
}

function log_debug(string $msg, array $ctx=[]): void {
  if (!cfg('DEBUG', false)) return;
  $line = '['.gmdate('c').'] '.$msg;
  if ($ctx) $line .= ' '.json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $line .= "\n";
  @mkdir(__DIR__ . '/../logs', 0777, true);
  @file_put_contents(__DIR__ . '/../logs/app.log', $line, FILE_APPEND);
}
