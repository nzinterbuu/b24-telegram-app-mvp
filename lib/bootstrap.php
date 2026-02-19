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
  $pdo->exec("CREATE TABLE IF NOT EXISTS portal_settings (
      portal TEXT PRIMARY KEY,
      line_id TEXT NOT NULL DEFAULT ''
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ol_map (
      portal TEXT NOT NULL,
      line_id TEXT NOT NULL,
      tenant_id TEXT NOT NULL,
      peer TEXT NOT NULL,
      external_user_id TEXT NOT NULL,
      external_chat_id TEXT NOT NULL,
      PRIMARY KEY (portal, line_id, tenant_id, peer)
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ol_map_external ON ol_map(portal, line_id, external_user_id, external_chat_id)");
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

/**
 * Always log inbound callback payload. Safe for Render.com and other PaaS:
 * - Writes to PHP error_log (stderr) so it appears in Render Dashboard → Logs.
 * - Tries to write to logs/app.log; if not writable (e.g. read-only filesystem), uses system temp dir.
 */
function log_inbound_payload($payload): void {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  $line = '[' . gmdate('c') . '] INBOUND CALLBACK PAYLOAD ' . $json . "\n";

  // 1) Send to PHP error log (stderr) — always works on Render and shows in Dashboard → Logs
  @error_log('INBOUND CALLBACK PAYLOAD ' . $json);

  // 2) Try to append to logs/app.log; fallback to system temp dir if logs/ isn't writable (e.g. on Render)
  $logDir = __DIR__ . '/../logs';
  if (!@is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
  }
  $logFile = (@is_dir($logDir) && @is_writable($logDir)) ? ($logDir . '/app.log') : (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/b24-telegram-inbound.log');
  @file_put_contents($logFile, $line, FILE_APPEND);
}

/** Get Open Line ID for portal (from DB or config fallback). */
function get_portal_line_id(string $portal): ?string {
  $pdo = ensure_db();
  $stmt = $pdo->prepare("SELECT line_id FROM portal_settings WHERE portal = ?");
  $stmt->execute([$portal]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $lineId = $row && $row['line_id'] !== '' ? $row['line_id'] : null;
  if ($lineId === null) {
    $lineId = cfg('OPENLINES_LINE_ID');
    $lineId = ($lineId !== null && $lineId !== '') ? (string)$lineId : null;
  }
  return $lineId;
}

/** Set Open Line ID for portal. */
function set_portal_line_id(string $portal, string $lineId): void {
  $pdo = ensure_db();
  $stmt = $pdo->prepare("INSERT INTO portal_settings (portal, line_id) VALUES (?,?) ON CONFLICT(portal) DO UPDATE SET line_id=excluded.line_id");
  $stmt->execute([$portal, $lineId]);
}

/** Get or create ol_map entry: (portal, line_id, tenant_id, peer) <-> (external_user_id, external_chat_id). */
function ol_map_get_or_create(string $portal, string $lineId, string $tenantId, string $peer): array {
  $pdo = ensure_db();
  $peer = trim($peer);
  $stmt = $pdo->prepare("SELECT external_user_id, external_chat_id FROM ol_map WHERE portal=? AND line_id=? AND tenant_id=? AND peer=?");
  $stmt->execute([$portal, $lineId, $tenantId, $peer]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    return ['external_user_id' => $row['external_user_id'], 'external_chat_id' => $row['external_chat_id']];
  }
  $externalUserId = preg_replace('/[^0-9a-zA-Z_]/', '_', $peer);
  if ($externalUserId === '') $externalUserId = 'u_' . bin2hex(random_bytes(4));
  $externalChatId = 'tg_' . preg_replace('/[^0-9a-zA-Z]/', '_', $peer);
  $ins = $pdo->prepare("INSERT OR REPLACE INTO ol_map (portal, line_id, tenant_id, peer, external_user_id, external_chat_id) VALUES (?,?,?,?,?,?)");
  $ins->execute([$portal, $lineId, $tenantId, $peer, $externalUserId, $externalChatId]);
  return ['external_user_id' => $externalUserId, 'external_chat_id' => $externalChatId];
}

/** Resolve (portal, line_id, external_user_id, external_chat_id) to (tenant_id, peer) for sending to Grey. */
function ol_map_resolve_to_grey(string $portal, string $lineId, string $externalUserId, string $externalChatId): ?array {
  $pdo = ensure_db();
  $stmt = $pdo->prepare("SELECT tenant_id, peer FROM ol_map WHERE portal=? AND line_id=? AND external_user_id=? AND external_chat_id=?");
  $stmt->execute([$portal, $lineId, $externalUserId, $externalChatId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ? ['tenant_id' => $row['tenant_id'], 'peer' => $row['peer']] : null;
}
