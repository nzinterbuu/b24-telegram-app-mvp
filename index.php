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
    <div id="create_tenant_form" class="create-tenant-form" style="display:none;" data-callback-url="<?= htmlspecialchars(cfg('PUBLIC_URL') . '/webhook/grey_inbound.php') ?>">
      <hr/>
      <label>Name (any name)</label>
      <input id="new_tenant_name" placeholder="e.g. Sales team" />
      <label>Callback URL (optional — default: app webhook)</label>
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
    <p class="small">Recent Telegram messages received by the webhook and sent from the app. Use this to verify that inbound messages are reaching the app. If inbound rows show “No Bitrix24 tokens” or “Missing Bitrix24 auth”, re-install the app from Bitrix24 (open the app and run the installer again) so OAuth tokens are saved for the webhook.</p>
    <div style="margin-bottom:8px;">
      <button type="button" class="secondary" onclick="App.loadMessageLog()">Refresh</button>
    </div>
    <div id="message_log_wrap" class="message-log-wrap">
      <p class="small">Loading…</p>
    </div>
  </div>

  <div class="card">
    <h2>Open Lines (Contact Center)</h2>
    <p class="small">Configure the Telegram connector in <strong>Bitrix24 → Open Lines → Connectors</strong>. Open the Telegram (GreyTG) connector there to select an Open Line and connect. Incoming Telegram messages will create chats in Contact Center; operators reply from Open Lines.</p>
    <div id="openlines_status_wrap" class="openlines-status-wrap" style="margin-top:12px;padding:10px;background:#f5f5f5;border-radius:6px;">
      <strong>Status</strong>
      <p class="small" id="openlines_status_text">Load settings to see stored line and last injection.</p>
      <p class="small" style="margin-top:8px;"><a href="pages/openlines_diagnostics.php">Open Lines diagnostics</a> — line ID, connector status, test message.</p>
    </div>
  </div>

  <div class="card">
    <h2>Inbound webhook (receive messages)</h2>
    <div class="small">
      The app sets the Grey tenant <strong>callback_url</strong> automatically when you connect Telegram (Save after Verify). URL:
    </div>
    <p style="margin:8px 0;"><code><?= htmlspecialchars(cfg('PUBLIC_URL')."/webhook/grey_inbound.php") ?></code></p>
    <div id="callback_status" class="small" style="margin-top:8px; padding:6px 10px; background:#f8f9fa; border-radius:4px;">Callback status: load settings to see.</div>
    <div class="small" style="margin-top:8px;">
      The Grey TG API only accepts <strong>callback_url</strong> when <strong>creating</strong> a tenant (not when saving settings). New tenants created in this app get this URL by default. For an <strong>existing</strong> tenant, set this URL in Grey TG admin or create a new tenant. Messages appear on the <strong>Deal</strong> (timeline), trigger an <strong>IM notification</strong>, and (if Open Line is configured) in <strong>Contact Center</strong>.
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
