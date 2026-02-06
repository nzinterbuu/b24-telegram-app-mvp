<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/b24.php';

$public = rtrim(cfg('PUBLIC_URL'), '/');

function install_unbind_placements(array $auth, string $public): void {
  foreach (
    [['PLACEMENT' => 'CRM_DEAL_DETAIL_TAB', 'HANDLER' => $public . '/deal_tab.php'], ['PLACEMENT' => 'CONTACT_CENTER', 'HANDLER' => $public . '/contact_center.php']]
    as $p
  ) {
    try {
      b24_call('placement.unbind', $p, $auth);
    } catch (Throwable $e) {
      // Ignore if nothing was bound
    }
  }
}

// Handle AJAX from install wizard: JS got auth via BX24.getAuth() and POSTs it here
$json = read_json();
if (!empty($json['auth']) && is_array($json['auth'])) {
  $auth = $json['auth'];
  try {
    $adminCheck = b24_call('user.admin', [], $auth);
    if (empty($adminCheck['result'])) {
      json_response(['ok' => false, 'error' => 'Only a Bitrix24 administrator can install this app. Please log in as an administrator (or ask your portal admin to install the app), then try again.'], 403);
      exit;
    }
    install_unbind_placements($auth, $public);
    $res1 = b24_call('placement.bind', [
      'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
      'HANDLER'   => $public . '/deal_tab.php',
      'TITLE'     => 'Telegram Chat',
      'DESCRIPTION' => 'Chat with client via Telegram',
      'OPTIONS'   => ['width' => 800, 'height' => 600]
    ], $auth);
    $res2 = b24_call('placement.bind', [
      'PLACEMENT' => 'CONTACT_CENTER',
      'HANDLER'   => $public . '/contact_center.php',
      'TITLE'     => 'Telegram (GreyTG)',
      'DESCRIPTION' => 'Chat with customer via Telegram',
      'OPTIONS'   => ['width' => 980, 'height' => 900]
    ], $auth);

    // Register Open Lines connector: handler URL for events = openlines/handler.php
    $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
    $handlerUrl = $public . '/openlines/handler.php';
    try {
      $iconSvg = 'data:image/svg+xml;charset=US-ASCII,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0088cc"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.69 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.79-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>');
      b24_call('imconnector.register', [
        'ID' => $connectorId,
        'NAME' => 'Telegram (GreyTG)',
        'ICON' => ['DATA_IMAGE' => $iconSvg, 'COLOR' => '#0088cc'],
        'PLACEMENT_HANDLER' => $public . '/contact_center.php',
        'CHAT_GROUP' => 'N',
      ], $auth);
      // Bind event so Bitrix24 calls our handler when operator sends a message in Open Line
      b24_call('event.bind', [
        'event' => 'OnImConnectorMessageAdd',
        'handler' => $handlerUrl,
      ], $auth);
    } catch (Throwable $ex) {
      log_debug('imconnector.register/event.bind failed (optional)', ['e' => $ex->getMessage()]);
    }

    $lineId = cfg('OPENLINES_LINE_ID');
    $lineId = ($lineId !== null && $lineId !== '') ? (string)$lineId : '';
    if ($lineId !== '') {
      try {
        b24_call('imconnector.activate', [
          'CONNECTOR' => $connectorId,
          'LINE' => (int)$lineId,
          'ACTIVE' => 1,
        ], $auth);
        b24_call('imconnector.connector.data.set', [
          'CONNECTOR' => $connectorId,
          'LINE' => (int)$lineId,
          'DATA' => [
            'id' => 'grey_tg',
            'url' => $public,
            'url_im' => $public . '/contact_center.php',
            'name' => 'Telegram (GreyTG)',
          ],
        ], $auth);
      } catch (Throwable $ex) {
        log_debug('imconnector.activate/connector.data.set failed', ['e' => $ex->getMessage()]);
      }
    }

    $norm = b24_normalize_auth($auth);
    $domain = $norm['domain'] ?? null;
    $accessToken = $norm['access_token'] ?? null;
    $refreshToken = $norm['refresh_token'] ?? null;
    if ($domain && $accessToken && $refreshToken) {
      $expiresIn = (int)($json['expires_in'] ?? 3600) ?: 3600;
      b24_save_oauth_tokens($domain, [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_at' => time() + $expiresIn,
        'member_id' => $norm['member_id'] ?? null,
      ]);
      if ($lineId !== '') {
        set_portal_line_id($domain, $lineId);
      }
    }

    json_response(['ok' => true, 'deal_tab' => $res1, 'contact_center' => $res2]);
    exit;
  } catch (Throwable $e) {
    log_debug("install ajax error", ['e' => $e->getMessage()]);
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    exit;
  }
}

// Try Bitrix24 POST/REQUEST auth (some deployments send auth on load)
$data = $json ?: [];
$auth = $data['auth'] ?? $_REQUEST;
$triedRequestAuth = false;
if (!empty($auth) && (isset($auth['domain']) || isset($auth['DOMAIN']) || isset($auth['access_token']) || isset($auth['AUTH_ID']))) {
  $triedRequestAuth = true;
  try {
    $adminCheck = b24_call('user.admin', [], $auth);
    if (empty($adminCheck['result'])) {
      throw new Exception('Only a Bitrix24 administrator can install this app. Please log in as an administrator.');
    }
    install_unbind_placements($auth, $public);
    $res1 = b24_call('placement.bind', [
      'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
      'HANDLER'   => $public . '/deal_tab.php',
      'TITLE'     => 'Telegram Chat',
      'DESCRIPTION' => 'Chat with client via Telegram',
      'OPTIONS'   => ['width' => 800, 'height' => 600]
    ], $auth);
    $res2 = b24_call('placement.bind', [
      'PLACEMENT' => 'CONTACT_CENTER',
      'HANDLER'   => $public . '/contact_center.php',
      'TITLE'     => 'Telegram (GreyTG)',
      'DESCRIPTION' => 'Chat with customer via Telegram',
      'OPTIONS'   => ['width' => 980, 'height' => 900]
    ], $auth);

    $connectorId = cfg('OPENLINES_CONNECTOR_ID') ?: 'telegram_grey';
    $handlerUrl = $public . '/openlines/handler.php';
    try {
      $iconSvg = 'data:image/svg+xml;charset=US-ASCII,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0088cc"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.69 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.79-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>');
      b24_call('imconnector.register', [
        'ID' => $connectorId,
        'NAME' => 'Telegram (GreyTG)',
        'ICON' => ['DATA_IMAGE' => $iconSvg, 'COLOR' => '#0088cc'],
        'PLACEMENT_HANDLER' => $public . '/contact_center.php',
        'CHAT_GROUP' => 'N',
      ], $auth);
      b24_call('event.bind', ['event' => 'OnImConnectorMessageAdd', 'handler' => $handlerUrl], $auth);
    } catch (Throwable $ex) {
      log_debug('imconnector.register/event.bind failed (optional)', ['e' => $ex->getMessage()]);
    }

    $lineId = cfg('OPENLINES_LINE_ID');
    $lineId = ($lineId !== null && $lineId !== '') ? (string)$lineId : '';
    if ($lineId !== '') {
      try {
        b24_call('imconnector.activate', [
          'CONNECTOR' => $connectorId,
          'LINE' => (int)$lineId,
          'ACTIVE' => 1,
        ], $auth);
        b24_call('imconnector.connector.data.set', [
          'CONNECTOR' => $connectorId,
          'LINE' => (int)$lineId,
          'DATA' => [
            'id' => 'grey_tg',
            'url' => $public,
            'url_im' => $public . '/contact_center.php',
            'name' => 'Telegram (GreyTG)',
          ],
        ], $auth);
      } catch (Throwable $ex) {
        log_debug('imconnector.activate/connector.data.set failed', ['e' => $ex->getMessage()]);
      }
    }

    $norm = b24_normalize_auth(is_array($auth) ? $auth : []);
    $domain = $norm['domain'] ?? null;
    $accessToken = $norm['access_token'] ?? null;
    $refreshToken = $norm['refresh_token'] ?? null;
    if ($domain && $accessToken && $refreshToken) {
      $expiresIn = (int)($_REQUEST['AUTH_EXPIRES'] ?? $data['AUTH_EXPIRES'] ?? 3600);
      b24_save_oauth_tokens($domain, [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_at' => time() + $expiresIn,
        'member_id' => $norm['member_id'] ?? $_REQUEST['member_id'] ?? $data['member_id'] ?? null,
      ]);
      if ($lineId !== '') {
        set_portal_line_id($domain, $lineId);
      }
    }

    // Success with request auth – output minimal page that calls installFinish
    header('Content-Type: text/html; charset=utf-8');
    echo install_finish_html();
    exit;
  } catch (Throwable $e) {
    log_debug("install request auth error", ['e' => $e->getMessage()]);
  }
}

// Show install wizard: use BX24.getAuth() (full privileges in user session)
header('Content-Type: text/html; charset=utf-8');
echo install_wizard_html($public, $triedRequestAuth);

function install_finish_html(): string {
  return '<!DOCTYPE html><html><head><meta charset="utf-8"><script src="//api.bitrix24.com/api/v1/"></script></head><body><script>
BX24.init(function() { BX24.installFinish(); });
</script><p>Installation complete. Closing...</p></body></html>';
}

function install_wizard_html(string $public, bool $showRetryNote): string {
  $retryNote = $showRetryNote ? '<p class="note">Retrying with your session credentials...</p>' : '';
  return '<!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Install GreyTG</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
body{font-family:system-ui;max-width:480px;margin:40px auto;padding:20px;background:#f5f5f5;}
.card{background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);}
h2{margin:0 0 16px;font-size:18px;}
.reqs{font-size:13px;color:#333;margin-bottom:16px;padding:10px;background:#f8f9fa;border-radius:4px;}
.note{color:#856404;background:#fff3cd;padding:8px 12px;border-radius:4px;font-size:14px;margin-bottom:16px;}
#msg{color:#0c5460;background:#d1ecf1;padding:8px 12px;border-radius:4px;font-size:14px;margin-top:12px;}
#msg.err{color:#721c24;background:#f8d7da;}
</style>
</head><body>
<div class="card">
<h2>Install GreyTG for Bitrix24</h2>
<p class="reqs"><strong>Required:</strong> You must be a <strong>Bitrix24 administrator</strong>. When adding the app, grant these permissions: <strong>basic</strong>, <strong>crm</strong>, <strong>imopenlines</strong>, <strong>contact_center</strong>, <strong>placement</strong>, <strong>im</strong>.</p>
' . $retryNote . '
<p>Completing installation...</p>
<div id="msg"></div>
</div>
<script>
(function(){
  var msg = document.getElementById("msg");
  function setMsg(t, err){ msg.textContent = t; msg.className = err ? "err" : ""; }
  if (typeof BX24 === "undefined") {
    setMsg("BX24 SDK not loaded. Check that the install page opens inside Bitrix24.", true);
    return;
  }
  BX24.init(function(){
    function getAuthSync() {
      var auth = BX24.getAuth();
      if (auth && auth.access_token && auth.domain) return auth;
      var params = new URLSearchParams(window.location.search);
      var domain = params.get("domain") || params.get("DOMAIN");
      var token = params.get("auth") || params.get("AUTH_ID");
      if (domain && token) {
        return {domain: domain, access_token: token, refresh_token: params.get("REFRESH_ID") || ""};
      }
      return null;
    }
    var auth = getAuthSync();
    if (!auth || !auth.access_token || !auth.domain) {
      BX24.getAuth(function(authFromCallback) {
        if (authFromCallback && authFromCallback.access_token && authFromCallback.domain) {
          proceedInstall(authFromCallback);
        } else {
          setMsg("Could not get Bitrix24 auth. Ensure you are logged in as administrator.", true);
        }
      });
      return;
    }
    proceedInstall(auth);
  });
  
  function proceedInstall(auth) {
    setMsg("Registering placements...");
    var expiresIn = (typeof auth.expires_in === "number" ? auth.expires_in : 3600);
    fetch("' . htmlspecialchars($public) . '/install.php", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({auth: auth, expires_in: expiresIn})
    })
    .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, json: j}; }); })
    .then(function(o){
      if (o.ok && o.json.ok) {
        setMsg("Success! Finalizing...");
        BX24.installFinish();
      } else {
        setMsg("Error: " + (o.json.error || "Unknown"), true);
      }
    })
    .catch(function(e){
      setMsg("Error: " + (e.message || String(e)), true);
    });
  }
})();
</script>
</body></html>';
}
