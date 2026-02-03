<?php
require_once __DIR__ . '/lib/bootstrap.php';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Telegram Chat (Deal)</title>
  <script src="//api.bitrix24.com/api/v1/"></script>
  <link rel="stylesheet" href="public/css/style.css" />
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Telegram chat</h2>
    <div class="small">Send a Telegram message to the client phone linked to this deal.</div>
    <hr/>
    <div id="ctx" class="small"></div>
    <label>Message</label>
    <textarea id="deal_text" rows="4" placeholder="Write message..."></textarea>
    <div style="margin-top:10px;">
      <button onclick="send()">Send</button>
    </div>
    <hr/>
    <pre id="out">{}</pre>
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
  var res = await fetch(path, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({auth: auth, ...body})});
  var json = await res.json().catch(function(){ return {}; });
  if (!res.ok) throw new Error(json.message || json.error || ('HTTP '+res.status));
  return json;
}

var dealId = null;

BX24.init(function() {
  BX24.resizeWindow(800, 600);
  BX24.placement.info(function(info){
    dealId = info.options && (info.options.ID || info.options.ENTITY_ID || info.options.entityId);
    document.getElementById('ctx').textContent = 'Deal: ' + dealId;
  });
});

async function send(){
  var out = document.getElementById('out');
  try {
    var text = document.getElementById('deal_text').value;
    var data = await api('ajax/send_from_deal.php', {deal_id: String(dealId||''), text: text});
    out.textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
  }
}
</script>
</body>
</html>
