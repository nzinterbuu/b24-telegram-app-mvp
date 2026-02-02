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
async function api(path, body={}) {
  const auth = await new Promise(resolve => BX24.getAuth(resolve));
  const res = await fetch(path, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({auth, ...body})});
  const json = await res.json().catch(()=> ({}));
  if (!res.ok) throw new Error(json.message || json.error || ('HTTP '+res.status));
  return json;
}

let dealId = null;

BX24.init(function() {
  BX24.resizeWindow(800, 600);
  BX24.placement.info(function(info){
    dealId = info.options && (info.options.ID || info.options.ENTITY_ID || info.options.entityId);
    document.getElementById('ctx').textContent = 'Deal: ' + dealId;
  });
});

async function send(){
  const text = document.getElementById('deal_text').value;
  const data = await api('ajax/send_from_deal.php', {deal_id: String(dealId||''), text});
  document.getElementById('out').textContent = JSON.stringify(data, null, 2);
}
</script>
</body>
</html>
