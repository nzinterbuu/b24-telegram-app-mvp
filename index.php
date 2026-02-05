<?php
require_once __DIR__ . '/lib/bootstrap.php';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>GreyTG for Bitrix24</title>
  <script src="//api.bitrix24.com/api/v1/"></script>
  <link rel="stylesheet" href="public/css/style.css" />
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Tenants</h2>
    <p class="small">Select a tenant for messaging, or create a new one (any name).</p>
    <div class="row">
      <div>
        <label>Selected tenant</label>
        <select id="tenant_select" onchange="App.onTenantSelectChange()">
          <option value="">— Select tenant —</option>
        </select>
      </div>
      <div>
        <button type="button" class="secondary" onclick="App.loadTenants()">Refresh list</button>
      </div>
    </div>
    <div id="tenants_table_wrap" class="tenants-table-wrap"></div>
    <div style="margin-top:12px;">
      <button type="button" onclick="App.showCreateTenant()">Create new tenant</button>
    </div>
    <div id="create_tenant_form" class="create-tenant-form" style="display:none;">
      <hr/>
      <label>Name (any name)</label>
      <input id="new_tenant_name" placeholder="e.g. Sales team" />
      <label>Callback URL (optional)</label>
      <input id="new_tenant_callback" placeholder="https://..." />
      <div style="margin-top:8px;">
        <button type="button" onclick="App.createTenant()">Create</button>
        <button type="button" class="secondary" onclick="App.hideCreateTenant()">Cancel</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Connect / disconnect Telegram account</h2>
    <input type="hidden" id="tenant_id" />
    <div class="row">
      <div>
        <label>Grey API token — «токен» (if required by your Grey API)</label>
        <input id="api_token" placeholder="Bearer ..." />
      </div>
      <div>
        <label>Phone (E.164) — for OTP start</label>
        <input id="phone" placeholder="+77001234567" />
      </div>
    </div>
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
      <button onclick="App.saveSettings()">Save</button>
      <button class="secondary" onclick="App.refreshStatus()">Check status</button>
      <button onclick="App.startOtp()">Start OTP</button>
      <button onclick="App.resendOtp()">Resend OTP</button>
      <button class="secondary" onclick="App.verifyOtp()">Verify OTP</button>
      <button class="danger" onclick="App.logout()">Disconnect (logout)</button>
    </div>
    <div class="row" style="margin-top:10px;">
      <div>
        <label>OTP code</label>
        <input id="otp" placeholder="12345" />
      </div>
      <div>
        <label>2FA password (optional)</label>
        <input id="tfa" type="password" placeholder="Telegram 2FA password" />
      </div>
    </div>
    <hr/>
    <div class="small">Status / responses</div>
    <pre id="status_out">{}</pre>
  </div>

  <div class="card">
    <h2>Message history (inbound & outbound)</h2>
    <p class="small">Recent Telegram messages received by the webhook and sent from the app. Use this to verify that inbound messages are reaching the app.</p>
    <div style="margin-bottom:8px;">
      <button type="button" class="secondary" onclick="App.loadMessageLog()">Refresh</button>
    </div>
    <div id="message_log_wrap" class="message-log-wrap">
      <p class="small">Loading…</p>
    </div>
  </div>

  <div class="card">
    <h2>Inbound webhook (receive messages)</h2>
    <div class="small">
      Set the Grey tenant <strong>callback_url</strong> to this URL so incoming Telegram messages are received:
    </div>
    <p style="margin:8px 0;"><code><?= htmlspecialchars(cfg('PUBLIC_URL')."/webhook/grey_inbound.php") ?></code></p>
    <div class="small" style="margin-top:8px;">
      When you <strong>Save</strong> settings (tenant + token), the app sets this URL as the tenant callback via Grey API so incoming messages are received. They appear on the <strong>Deal</strong> (timeline), trigger an <strong>IM notification</strong>, and (if Open Line is configured) in <strong>Contact Center</strong>.
    </div>
  </div>
</div>

<script src="public/js/app.js"></script>
<script>
BX24.init(function() {
  BX24.resizeWindow(980, 900);
  App.loadTenants()
    .then(function() { return App.loadSettings(); })
    .then(function() { return App.loadMessageLog(); })
    .catch(function(err) { document.getElementById('status_out').textContent = String(err); });
});
</script>
</body>
</html>
