<?php
require_once __DIR__ . '/lib/bootstrap.php';
$peerFromUrl = isset($_REQUEST['peer']) ? trim((string)$_REQUEST['peer']) : '';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Telegram — Contact Center</title>
  <script src="//api.bitrix24.com/api/v1/"></script>
  <link rel="stylesheet" href="public/css/style.css" />
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Chat with customer (Telegram)</h2>
    <p class="small">Send and receive messages via Telegram. Select a tenant and connect your Telegram account in the <strong>app</strong> (left menu) first.</p>
    <div class="card" style="margin-bottom:12px; padding:10px; background:#f8f9fa; border-radius:6px;">
      <strong>How to answer an incoming message</strong>
      <ul class="small" style="margin:8px 0 0 0; padding-left:18px;">
        <li><strong>From the Deal:</strong> Open the deal (from the notification or CRM), go to the <strong>Telegram chat</strong> tab, type your reply and click Send. The message goes to the contact’s phone linked to that deal.</li>
        <li><strong>From here:</strong> Enter the customer’s phone (e.g. <code>+79001234567</code>) or Telegram <code>@username</code> in <strong>To</strong>, type your message and click Send. Use the same phone/username that appears in the inbound notification or timeline.</li>
      </ul>
    </div>
    <div class="row">
      <div>
        <label>To (phone E.164 or @username)</label>
        <input id="peer" placeholder="+79001234567 or @username" value="<?= htmlspecialchars($peerFromUrl) ?>" />
      </div>
    </div>
    <label>Message</label>
    <textarea id="msg_text" rows="4" placeholder="Type your message..."></textarea>
    <div style="margin-top:10px;">
      <button type="button" onclick="sendMsg()">Send</button>
    </div>
    <hr/>
    <div class="small">Response</div>
    <pre id="out">{}</pre>
  </div>
  <div class="card">
    <p class="small">Inbound messages from the customer are delivered to the CRM timeline and as notifications when the Grey tenant callback is set to the app webhook.</p>
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
async function api(path, body) {
  body = body || {};
  var auth = await getAuth();
  if (!auth || !auth.access_token) throw new Error('Bitrix24 auth not available.');
  var res = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ auth: auth, ...body }) });
  var json = await res.json().catch(function() { return {}; });
  if (!res.ok) throw new Error(json.message || json.error || ('HTTP ' + res.status));
  return json;
}

async function sendMsg() {
  var out = document.getElementById('out');
  try {
    var peer = document.getElementById('peer').value.trim();
    var text = document.getElementById('msg_text').value;
    var data = await api('ajax/send_message.php', { peer: peer, text: text });
    out.textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
  }
}

BX24.init(function() {
  BX24.resizeWindow(980, 900);
});
</script>
</body>
</html>
