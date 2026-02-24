<?php
/**
 * Deal card menu/slider handler (placement CRM_DEAL_DETAIL_TOOLBAR).
 * Renders the same Telegram chat UI as deal_tab; receives deal context via PLACEMENT_OPTIONS.
 */
require_once __DIR__ . '/lib/bootstrap.php';

function extract_deal_id_from_placement_options($opts): int {
  if (!$opts) return 0;
  if (is_array($opts)) {
    return (int)($opts['ID'] ?? $opts['id'] ?? $opts['ENTITY_ID'] ?? $opts['entityId'] ?? 0);
  }
  if (is_string($opts)) {
    $s = urldecode($opts);
    $j = json_decode($s, true);
    if (is_array($j)) return (int)($j['ID'] ?? $j['id'] ?? $j['ENTITY_ID'] ?? $j['entityId'] ?? 0);
    $b = base64_decode($s, true);
    if ($b !== false) {
      $j2 = json_decode($b, true);
      if (is_array($j2)) return (int)($j2['ID'] ?? $j2['id'] ?? $j2['ENTITY_ID'] ?? $j2['entityId'] ?? 0);
    }
  }
  return 0;
}

$placement = $_REQUEST['PLACEMENT'] ?? '';
$deal_id = 0;
if (isset($_REQUEST['PLACEMENT_OPTIONS'])) {
  $opts = $_REQUEST['PLACEMENT_OPTIONS'];
  if (is_string($opts)) {
    $opts = urldecode($opts);
    $opts = json_decode($opts, true) ?: (function ($s) {
      $b = base64_decode(str_replace(['-', '_'], ['+', '/'], $s), true);
      return $b ? json_decode($b, true) : null;
    })($opts);
  }
  $deal_id = extract_deal_id_from_placement_options($opts);
}
if ($deal_id <= 0 && isset($_GET['deal_id'])) {
  $deal_id = (int)$_GET['deal_id'];
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Telegram Chat</title>
  <script src="//api.bitrix24.com/api/v1/"></script>
  <link rel="stylesheet" href="public/css/style.css" />
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Telegram chat</h2>
    <div style="font-size:12px;opacity:.7">Deal #<?= (int)$deal_id ?></div>
    <div class="small">Send a Telegram message to the client phone linked to this deal.</div>
    <hr/>
    <input type="hidden" id="deal_id" name="deal_id" value="<?= (int)$deal_id ?>" />
    <div id="ctx" class="small"></div>
    <div id="phone_row" class="small" style="display:none; margin-top:8px;">
      <label>Phone (E.164)</label>
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

var dealId = <?= json_encode((int)$deal_id) ?>;

function updateCtx() {
  var ctx = document.getElementById('ctx');
  var phoneRow = document.getElementById('phone_row');
  if (dealId) {
    if (ctx) ctx.textContent = 'Deal: ' + dealId;
    if (phoneRow) phoneRow.style.display = 'none';
  } else {
    if (ctx) ctx.textContent = 'Deal ID not detected. Enter recipient phone below or open from Deal card menu.';
    if (phoneRow) phoneRow.style.display = 'block';
  }
}

function setDealId(id) {
  if (!id) return;
  var el = document.getElementById('deal_id');
  if (!el) return;
  el.value = String(id);
  dealId = parseInt(id, 10) || null;
  updateCtx();
}

BX24.init(function () {
  if (typeof BX24.fitWindow === 'function') BX24.fitWindow();
  updateCtx();
  var existing = document.getElementById('deal_id') && document.getElementById('deal_id').value;
  if (existing && existing !== '' && existing !== '0') return;
  if (BX24.placement && BX24.placement.getOptions) {
    BX24.placement.getOptions(function (opts) {
      var id = opts && (opts.ID || opts.id || opts.ENTITY_ID || opts.entityId || opts.OWNER_ID || null);
      setDealId(id);
    });
  }
});

async function send(){
  var out = document.getElementById('out');
  try {
    var dealIdEl = document.getElementById('deal_id');
    var currentDealId = dealIdEl ? (parseInt(dealIdEl.value, 10) || null) : dealId;
    var text = document.getElementById('deal_text').value.trim();
    if (!text) { out.textContent = 'Error: Message text is empty.'; return; }
    var body = { text: text, deal_id: currentDealId || '' };
    if (currentDealId) body.deal_id = currentDealId;
    else {
      var phone = (document.getElementById('deal_phone') || {}).value.trim();
      if (!phone) { out.textContent = 'Error: Enter recipient phone (or open from Deal card menu).'; return; }
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
