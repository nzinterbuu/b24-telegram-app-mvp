<?php
require_once __DIR__ . '/lib/bootstrap.php';

function deal_tab_placement_options_id(): ?int {
  $raw = $_GET['PLACEMENT_OPTIONS'] ?? $_GET['placement_options'] ?? null;
  if ($raw === null || $raw === '') return null;
  $raw = (string)$raw;
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $raw), true);
    $data = is_string($decoded) ? json_decode($decoded, true) : null;
  }
  if (!is_array($data)) return null;
  $id = $data['ID'] ?? $data['id'] ?? $data['ENTITY_ID'] ?? $data['entityId'] ?? $data['dealId'] ?? $data['OWNER_ID'] ?? null;
  return $id !== null ? (int)$id : null;
}

function deal_tab_id_from_referer(): ?int {
  $referer = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
  if ($referer === '') return null;
  // Bitrix24 deal URL: https://portal.bitrix24.ru/crm/deal/details/8/ or .../crm/deal/details/8
  if (preg_match('~/crm/deal/details/(\d+)(?:/|$|\?)~i', $referer, $m)) return (int)$m[1];
  if (preg_match('~/deal/details/(\d+)(?:/|$|\?)~i', $referer, $m)) return (int)$m[1];
  return null;
}

$dealIdFromServer = isset($_GET['ID']) ? (int)$_GET['ID'] : (isset($_GET['id']) ? (int)$_GET['id'] : (isset($_REQUEST['ENTITY_ID']) ? (int)$_REQUEST['ENTITY_ID'] : null));
if ($dealIdFromServer === null) $dealIdFromServer = deal_tab_placement_options_id();
if ($dealIdFromServer === null) $dealIdFromServer = deal_tab_id_from_referer();
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
    <input type="hidden" id="deal_id" name="deal_id" value="<?= (int)($dealIdFromServer ?? 0) ?>" />
    <div id="ctx" class="small"></div>
    <div id="phone_row" class="small" style="display:none; margin-top:8px;">
      <label>Phone (E.164, e.g. +79001234567)</label>
      <input type="text" id="deal_phone" placeholder="+79001234567" style="width:100%; max-width:240px;" />
    </div>
    <label>Message</label>
    <textarea id="deal_text" rows="4" placeholder="Write message..."></textarea>
    <div style="margin-top:10px;">
      <button id="deal_send_btn" onclick="send()">Send</button>
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

function getDealIdFromPlacementOptions() {
  try {
    var params = new URLSearchParams(window.location.search);
    var raw = params.get('PLACEMENT_OPTIONS') || params.get('placement_options');
    if (!raw) return null;
    var data = null;
    try { data = JSON.parse(raw); } catch (e1) {}
    if (!data) {
      try {
        var decoded = atob(raw.replace(/-/g, '+').replace(/_/g, '/'));
        data = JSON.parse(decoded);
      } catch (e2) { return null; }
    }
    var id = data && (data.ID !== undefined ? data.ID : data.id !== undefined ? data.id : data.ENTITY_ID !== undefined ? data.ENTITY_ID : data.entityId !== undefined ? data.entityId : data.dealId !== undefined ? data.dealId : data.OWNER_ID);
    return id != null ? (parseInt(id, 10) || null) : null;
  } catch (e) { return null; }
}

function getDealIdFromReferrer() {
  try {
    var r = document.referrer || '';
    if (!r) return null;
    var m = r.match(/\/crm\/deal\/details\/(\d+)(?:\/|$|\?)/i) || r.match(/\/deal\/details\/(\d+)(?:\/|$|\?)/i);
    return m ? parseInt(m[1], 10) : null;
  } catch (e) { return null; }
}

function setDealIdInput(val) {
  dealId = val ? (parseInt(val, 10) || null) : null;
  var el = document.getElementById('deal_id');
  if (el) el.value = dealId ? String(dealId) : '';
}

function updateCtx() {
  var ctx = document.getElementById('ctx');
  var phoneRow = document.getElementById('phone_row');
  if (dealId) {
    ctx.textContent = 'Deal: ' + dealId;
    if (phoneRow) phoneRow.style.display = 'none';
  } else {
    ctx.textContent = 'Deal ID not detected. Enter recipient phone below or open this tab from a Deal card.';
    if (phoneRow) phoneRow.style.display = 'block';
  }
}

BX24.init(function() {
  BX24.resizeWindow(800, 600);
  if (!dealId) dealId = getDealIdFromUrl();
  if (!dealId) dealId = getDealIdFromPlacementOptions();
  if (!dealId) dealId = getDealIdFromReferrer();

  function applyDealIdAndUpdate() {
    var el = document.getElementById('deal_id');
    if (el) el.value = dealId ? String(dealId) : '';
    updateCtx();
  }

  if (typeof BX24.placement.getOptions === 'function') {
    var opts = BX24.placement.getOptions();
    if (opts && !dealId) {
      var raw = opts.ID || opts.id || opts.ENTITY_ID || opts.entityId || opts.dealId || opts.OWNER_ID;
      if (raw != null) dealId = parseInt(raw, 10) || null;
    }
    applyDealIdAndUpdate();
  } else {
    BX24.placement.info(function(info) {
      if (!dealId && info) {
        var opt = (info && info.options) || info || {};
        var raw = opt.ID || opt.id || opt.ENTITY_ID || opt.entityId || opt.dealId || opt.OWNER_ID;
        if (raw != null) dealId = parseInt(raw, 10) || null;
      }
      if (!dealId) dealId = getDealIdFromUrl();
      if (!dealId) dealId = getDealIdFromPlacementOptions();
      if (!dealId) dealId = getDealIdFromReferrer();
      applyDealIdAndUpdate();
    });
    applyDealIdAndUpdate();
  }
});

async function send(){
  var out = document.getElementById('out');
  try {
    var dealIdEl = document.getElementById('deal_id');
    var currentDealId = dealIdEl ? (parseInt(dealIdEl.value, 10) || null) : dealId;
    var text = document.getElementById('deal_text').value.trim();
    if (!text) {
      out.textContent = 'Error: Message text is empty.';
      return;
    }
    var body = { text: text, deal_id: currentDealId || '' };
    if (currentDealId) {
      body.deal_id = currentDealId;
    } else {
      var phone = (document.getElementById('deal_phone') || {}).value.trim();
      if (!phone) {
        out.textContent = 'Error: Enter recipient phone (or open this tab from a Deal card).';
        return;
      }
      body.phone = phone;
    }
    var placementOpts = new URLSearchParams(window.location.search).get('PLACEMENT_OPTIONS') || new URLSearchParams(window.location.search).get('placement_options');
    if (placementOpts) body.placement_options = placementOpts;
    var data = await api('ajax/deal_send.php', body);
    out.textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
  }
}
</script>
</body>
</html>
