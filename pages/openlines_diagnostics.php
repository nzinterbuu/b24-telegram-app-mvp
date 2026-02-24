<?php
/**
 * Open Lines diagnostics page: stored LINE_ID, connector status, test send.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Open Lines diagnostics</title>
  <script src="//api.bitrix24.com/api/v1/"></script>
  <link rel="stylesheet" href="../public/css/style.css" />
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Open Lines diagnostics</h2>
    <p class="small">Portal, stored line, connector status, and last injection. Use "Send test message" to create a test chat in Open Lines.</p>
    <div id="diag_out" class="small" style="margin:12px 0; white-space:pre-wrap;">Loading…</div>
    <button type="button" id="btn_refresh" onclick="loadDiag()">Refresh</button>
    <button type="button" id="btn_test" onclick="sendTest()" style="margin-left:8px;">Send test message to Open Lines</button>
    <div id="test_result" class="small" style="margin-top:12px;"></div>
  </div>
</div>

<script>
function getAuth() {
  var auth = (typeof BX24 !== 'undefined' && BX24.getAuth) ? BX24.getAuth() : null;
  if (auth && auth.access_token && auth.domain) return Promise.resolve(auth);
  return new Promise(function(resolve) {
    if (typeof BX24 !== 'undefined' && BX24.getAuth) BX24.getAuth(resolve);
    else resolve(null);
  });
}

function apiBase() {
  var p = window.location.pathname || '';
  if (p.indexOf('/pages/') !== -1) return '..';
  return '';
}

async function api(path, body) {
  var auth = await getAuth();
  if (!auth || !auth.access_token) throw new Error('Bitrix24 auth not available.');
  var base = apiBase();
  var url = (base ? base + '/' : '') + path;
  var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ auth: auth, ...(body || {}) }) });
  var json = await res.json().catch(function() { return {}; });
  if (!res.ok) throw new Error(json.error || json.message || ('HTTP ' + res.status));
  return json;
}

function el(id) { return document.getElementById(id); }

async function loadDiag() {
  var out = el('diag_out');
  out.textContent = 'Loading…';
  try {
    var data = await api('ajax/openlines_diagnostics.php');
    var lines = [
      'Portal: ' + (data.portal || '—'),
      'Stored LINE_ID: ' + (data.line_id || '— (none)'),
      'Connector ID: ' + (data.connector_id || '—'),
      'Connector registered: ' + (data.connector_registered === true ? 'Yes' : (data.connector_registered === false ? 'No' : '—')),
      'Connector active for line: ' + (data.connector_active === true ? 'Yes' : (data.connector_active === false ? 'No' : '—')),
    ];
    if (data.connector_error) lines.push('Connector error: ' + data.connector_error);
    if (data.last_inject) {
      lines.push('Last injection: ' + (data.last_inject.success ? 'OK' : 'Failed') + (data.last_inject.at ? ' at ' + new Date(data.last_inject.at * 1000).toISOString() : ''));
      if (data.last_inject.session_id) lines.push('  session_id: ' + data.last_inject.session_id);
      if (data.last_inject.chat_id) lines.push('  chat_id: ' + data.last_inject.chat_id);
      if (data.last_inject.error) lines.push('  error: ' + data.last_inject.error);
    } else {
      lines.push('Last injection: none');
    }
    out.textContent = lines.join('\n');
  } catch (e) {
    out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
  }
}

async function sendTest() {
  var resultEl = el('test_result');
  resultEl.textContent = 'Sending…';
  try {
    var data = await api('ajax/openlines_test_send.php');
    resultEl.textContent = data.message || 'OK. Check Messenger → Chats for "Diagnostics Test Chat".';
    resultEl.style.color = '#155724';
    if (data.session_id) resultEl.textContent += ' Session: ' + data.session_id;
    loadDiag();
  } catch (e) {
    resultEl.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
    resultEl.style.color = '#721c24';
  }
}

BX24.init(function() {
  loadDiag();
});
</script>
</body>
</html>
