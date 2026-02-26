<?php
/**
 * Open Lines connector settings page. Opened from Bitrix24 Open Lines → Connectors → Telegram (GreyTG).
 * Single place to connect/disconnect the connector for a line and persist LINE_ID.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
$public = rtrim(cfg('PUBLIC_URL'), '/');
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Telegram (GreyTG) — Open Line</title>
  <script src="//api.bitrix24.com/api/v1/"></script>
  <link rel="stylesheet" href="<?= htmlspecialchars($public) ?>/public/css/style.css" />
  <style>
    .ol-status { margin: 12px 0; padding: 10px; background: #f5f5f5; border-radius: 6px; font-size: 14px; }
    .ol-status.ok { background: #d4edda; color: #155724; }
    .ol-status.err { background: #f8d7da; color: #721c24; }
  </style>
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Telegram (GreyTG) — Open Line</h2>
    <p class="small">Select an Open Line to connect this connector. Incoming Telegram messages will create chats in Contact Center. Operators reply from Open Lines.</p>
    <div id="message" class="ol-status" style="display:none;"></div>
    <div class="row">
      <div>
        <label>Open Line</label>
        <select id="line_select">
          <option value="">— Select line —</option>
        </select>
      </div>
      <div>
        <button type="button" id="btn_connect" onclick="connect()">Connect</button>
        <button type="button" id="btn_disconnect" class="secondary" onclick="disconnect()" style="display:none;">Disconnect</button>
      </div>
    </div>
    <div id="status_wrap" class="ol-status" style="margin-top:12px;">
      <strong>Status</strong>
      <p id="status_text" class="small">Loading…</p>
      <p id="handler_url" class="small" style="word-break:break-all;margin-top:6px;"></p>
      <p id="connector_detail" class="small" style="margin-top:4px;"></p>
    </div>
    <div id="diagnostics_panel" class="ol-status" style="margin-top:16px; padding:12px;">
      <strong>Diagnostics</strong>
      <p class="small" style="margin-top:6px;">Expected URLs (from PUBLIC_URL):</p>
      <ul class="small" style="margin:4px 0; padding-left:20px;">
        <li>Event callback (outbound): <code id="url_callback" style="word-break:break-all;"></code></li>
        <li>Settings UI: <code id="url_settings" style="word-break:break-all;"></code></li>
        <li>Ping (reachability): <a id="url_ping" href="#" target="_blank" rel="noopener">open ping URL</a> — should return 200 and "pong"; logs <code>[OL PING]</code></li>
      </ul>
      <p class="small" style="margin-top:8px;">Bitrix uses the event callback URL to POST operator replies. If that URL is wrong or unreachable, Bitrix shows "message not delivered" and our handler never logs <code>[OL HANDLER]</code>.</p>
    </div>
    <div style="margin-top:12px;">
      <button type="button" id="btn_reapply" class="secondary" onclick="reapplyConfig()" style="display:none;">Re-apply connector config</button>
    </div>
  </div>
</div>

<script>
var BASE = '<?= htmlspecialchars($public) ?>';

function getAuth() {
  var auth = (typeof BX24 !== 'undefined' && BX24.getAuth) ? BX24.getAuth() : null;
  if (auth && auth.access_token && auth.domain) return Promise.resolve(auth);
  return new Promise(function(resolve) {
    if (typeof BX24 !== 'undefined' && BX24.getAuth) BX24.getAuth(resolve);
    else resolve(null);
  });
}

async function api(path, body) {
  var auth = await getAuth();
  if (!auth || !auth.access_token) throw new Error('Bitrix24 auth not available.');
  var res = await fetch(BASE + '/' + path.replace(/^\//, ''), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ auth: auth, ...(body || {}) })
  });
  var json = await res.json().catch(function() { return {}; });
  if (!res.ok) throw new Error(json.error || json.message || ('HTTP ' + res.status));
  return json;
}

function el(id) { return document.getElementById(id); }

function showMessage(text, isError) {
  var m = el('message');
  m.textContent = text || '';
  m.style.display = text ? 'block' : 'none';
  m.className = 'ol-status' + (isError ? ' err' : ' ok');
  if (text) setTimeout(function() { m.style.display = 'none'; }, 5000);
}

async function loadLines() {
  var sel = el('line_select');
  try {
    var data = await api('ajax/openlines_list.php');
    var lines = data.lines || [];
    var current = (window._currentLineId || '').toString();
    sel.innerHTML = '<option value="">— Select line —</option>';
    lines.forEach(function(l) {
      var id = (l.ID || l.id || '').toString();
      var name = (l.LINE_NAME || l.line_name || 'Line ' + id);
      var opt = document.createElement('option');
      opt.value = id;
      opt.textContent = name + ' (ID: ' + id + ')';
      if (id === current) opt.selected = true;
      sel.appendChild(opt);
    });
  } catch (e) {
    sel.innerHTML = '<option value="">— Error loading —</option>';
  }
}

async function loadStatus() {
  var st = el('status_text');
  var handlerEl = el('handler_url');
  var detailEl = el('connector_detail');
  var btnReapply = el('btn_reapply');
  try {
    var data = await api('ajax/get_settings.php');
    var lineId = data.line_id || '';
    window._currentLineId = lineId;
    var parts = [];
    parts.push('Stored line: ' + (lineId ? lineId : '— (none)'));
    if (data.connector_status) {
      if (data.connector_status.error) {
        parts.push('Connector: error — ' + data.connector_status.error);
      } else {
        parts.push('Connector: ' + (data.connector_status.active ? 'active' : 'not active'));
      }
    }
    st.textContent = parts.join(' · ');
    handlerEl.textContent = data.openlines_handler_url ? 'Handler: ' + data.openlines_handler_url : '';
    var urlCallback = el('url_callback');
    var urlSettings = el('url_settings');
    var urlPing = el('url_ping');
    if (urlCallback) urlCallback.textContent = data.openlines_handler_url || '—';
    if (urlSettings) urlSettings.textContent = data.openlines_settings_url || '—';
    if (urlPing && data.openlines_ping_url) {
      urlPing.href = data.openlines_ping_url;
      urlPing.textContent = data.openlines_ping_url;
    }
    var detailParts = [];
    if (data.connector_status) {
      if (data.connector_status.configured != null) detailParts.push('configured=' + data.connector_status.configured);
      if (data.connector_status.status != null) detailParts.push('status=' + data.connector_status.status);
      if (data.connector_status.connector_id) detailParts.push('connector=' + data.connector_status.connector_id);
    }
    detailEl.textContent = detailParts.length ? detailParts.join(' · ') : '';
    btnReapply.style.display = lineId ? 'inline-block' : 'none';
    var btnDisconnect = el('btn_disconnect');
    btnDisconnect.style.display = lineId ? 'inline-block' : 'none';
  } catch (e) {
    st.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
    handlerEl.textContent = '';
    detailEl.textContent = '';
    btnReapply.style.display = 'none';
  }
}

async function reapplyConfig() {
  var lineId = (el('line_select').value || window._currentLineId || '').trim();
  if (!lineId) {
    showMessage('Select an Open Line first.', true);
    return;
  }
  showMessage('', false);
  try {
    var data = await api('ajax/openlines_save.php', { line_id: lineId });
    if (data.ok) {
      showMessage('Config re-applied (activate + connector data + event.bind).', false);
      loadStatus();
      loadLines();
    } else {
      showMessage(data.error || data.message || 'Failed', true);
    }
  } catch (e) {
    showMessage(e && e.message ? e.message : String(e), true);
  }
}

async function connect() {
  var lineId = (el('line_select').value || '').trim();
  if (!lineId) {
    showMessage('Select an Open Line first.', true);
    return;
  }
  showMessage('', false);
  try {
    var data = await api('ajax/openlines_save.php', { line_id: lineId });
    if (data.ok) {
      showMessage('Connector connected for this line.', false);
      loadStatus();
      loadLines();
    } else {
      showMessage(data.error || data.message || 'Failed', true);
    }
  } catch (e) {
    showMessage(e && e.message ? e.message : String(e), true);
  }
}

async function disconnect() {
  showMessage('', false);
  try {
    var data = await api('ajax/openlines_save.php', { line_id: '' });
    if (data.ok) {
      showMessage('Connector disconnected.', false);
      window._currentLineId = '';
      loadStatus();
      loadLines();
    } else {
      showMessage(data.error || data.message || 'Failed', true);
    }
  } catch (e) {
    showMessage(e && e.message ? e.message : String(e), true);
  }
}

BX24.init(function() {
  if (typeof BX24 !== 'undefined' && BX24.resizeWindow) BX24.resizeWindow(520, 420);
  loadLines();
  loadStatus();
});
</script>
</body>
</html>
