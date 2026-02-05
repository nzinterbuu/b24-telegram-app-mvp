<?php
require_once __DIR__ . '/lib/bootstrap.php';
$dealIdFromServer = isset($_GET['ID']) ? (int)$_GET['ID'] : (isset($_GET['id']) ? (int)$_GET['id'] : (isset($_REQUEST['ENTITY_ID']) ? (int)$_REQUEST['ENTITY_ID'] : null));
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
      <button id="deal_send_btn" onclick="send()" disabled>Send</button>
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

var dealId = <?= json_encode($dealIdFromServer) ?>;

function getDealIdFromUrl() {
  try {
    var params = new URLSearchParams(window.location.search);
    var v = params.get('ID') || params.get('id') || params.get('deal_id') || params.get('ENTITY_ID') || params.get('entityId');
    return v ? (parseInt(v, 10) || null) : null;
  } catch (e) { return null; }
}

function getDealIdFromReferrer() {
  try {
    var r = document.referrer || '';
    if (!r) return null;
    var m = r.match(/\/(?:crm\/)?deal(?:\/details)?\/(\d+)(?:\/|$|\?)/i) || r.match(/\/(\d+)\/(?:\?|$)/);
    return m ? parseInt(m[1], 10) : null;
  } catch (e) { return null; }
}

function refreshDealId() {
  if (dealId) return dealId;
  dealId = getDealIdFromUrl();
  if (!dealId) dealId = getDealIdFromReferrer();
  return dealId;
}

function updateCtx() {
  refreshDealId();
  var ctx = document.getElementById('ctx');
  var btn = document.getElementById('deal_send_btn');
  if (dealId) {
    ctx.textContent = 'Deal: ' + dealId;
    if (btn) btn.disabled = false;
  } else {
    ctx.textContent = 'Deal ID not found. Open this tab from a Deal card.';
    if (btn) btn.disabled = true;
  }
}

BX24.init(function() {
  BX24.resizeWindow(800, 600);
  if (!dealId) dealId = getDealIdFromUrl();
  if (!dealId) dealId = getDealIdFromReferrer();
  BX24.placement.info(function(info){
    if (!dealId && info) {
      var opt = info.options || {};
      var raw = opt.ID || opt.id || opt.ENTITY_ID || opt.entityId || opt.dealId || info.ID || info.id || info.ENTITY_ID || opt.OWNER_ID;
      dealId = raw ? (parseInt(raw, 10) || null) : null;
    }
    if (!dealId) dealId = getDealIdFromUrl();
    if (!dealId) dealId = getDealIdFromReferrer();
    updateCtx();
  });
  updateCtx();
});

async function send(){
  var out = document.getElementById('out');
  try {
    refreshDealId();
    if (!dealId) {
      out.textContent = 'Error: Deal ID not found. Open this tab from a Deal card and try again.';
      return;
    }
    var text = document.getElementById('deal_text').value;
    var data = await api('ajax/send_from_deal.php', { deal_id: dealId, text: text });
    out.textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
  }
}
</script>
</body>
</html>
