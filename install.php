<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/b24.php';

$public = rtrim(cfg('PUBLIC_URL'), '/');

function install_unbind_placements(array $auth, string $public): void {
  foreach (
    [['PLACEMENT' => 'CRM_DEAL_DETAIL_TAB', 'HANDLER' => $public . '/deal_tab.php'], ['PLACEMENT' => 'CONTACT_CENTER', 'HANDLER' => $public . '/index.php']]
    as $p
  ) {
    try {
      b24_call($auth, 'placement.unbind', $p);
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
    $adminCheck = b24_call($auth, 'user.admin', []);
    if (empty($adminCheck['result'])) {
      json_response(['ok' => false, 'error' => 'Only a Bitrix24 administrator can install this app. Please log in as an administrator (or ask your portal admin to install the app), then try again.'], 403);
      exit;
    }
    install_unbind_placements($auth, $public);
    $res1 = b24_call($auth, 'placement.bind', [
      'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
      'HANDLER'   => $public . '/deal_tab.php',
      'TITLE'     => 'Telegram Chat',
      'DESCRIPTION' => 'Chat with client via Telegram',
      'OPTIONS'   => ['width' => 800, 'height' => 600]
    ]);
    $res2 = b24_call($auth, 'placement.bind', [
      'PLACEMENT' => 'CONTACT_CENTER',
      'HANDLER'   => $public . '/index.php',
      'TITLE'     => 'Telegram (GreyTG)',
      'DESCRIPTION' => 'Telegram connector (MVP)',
      'OPTIONS'   => ['width' => 980, 'height' => 900]
    ]);

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
    $adminCheck = b24_call($auth, 'user.admin', []);
    if (empty($adminCheck['result'])) {
      throw new Exception('Only a Bitrix24 administrator can install this app. Please log in as an administrator.');
    }
    install_unbind_placements($auth, $public);
    $res1 = b24_call($auth, 'placement.bind', [
      'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
      'HANDLER'   => $public . '/deal_tab.php',
      'TITLE'     => 'Telegram Chat',
      'DESCRIPTION' => 'Chat with client via Telegram',
      'OPTIONS'   => ['width' => 800, 'height' => 600]
    ]);
    $res2 = b24_call($auth, 'placement.bind', [
      'PLACEMENT' => 'CONTACT_CENTER',
      'HANDLER'   => $public . '/index.php',
      'TITLE'     => 'Telegram (GreyTG)',
      'DESCRIPTION' => 'Telegram connector (MVP)',
      'OPTIONS'   => ['width' => 980, 'height' => 900]
    ]);

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
    var auth = BX24.getAuth();
    if (!auth || !auth.access_token || !auth.domain) {
      var params = new URLSearchParams(window.location.search);
      var domain = params.get("domain") || params.get("DOMAIN");
      var token = params.get("auth") || params.get("AUTH_ID");
      if (domain && token) {
        auth = {domain: domain, access_token: token, refresh_token: params.get("REFRESH_ID") || ""};
      }
    }
    if (!auth || !auth.access_token || !auth.domain) {
      setMsg("Could not get Bitrix24 auth. Ensure you are logged in as administrator.", true);
      return;
    }
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
  });
})();
</script>
</body></html>';
}
