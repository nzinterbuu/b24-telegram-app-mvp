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
    <h2>Settings: connect Telegram account</h2>
    <div class="row">
      <div>
        <label>Grey tenant_id (UUID) — «номер подключения»</label>
        <input id="tenant_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
      </div>
      <div>
        <label>Grey API token — «токен» (if required)</label>
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
    <h2>Send from Deal (MVP)</h2>
    <div class="row">
      <div>
        <label>Deal ID (from placement context)</label>
        <input id="deal_id" placeholder="123" />
      </div>
      <div>
        <label>Message</label>
        <textarea id="deal_text" rows="3" placeholder="Hello from Bitrix24"></textarea>
      </div>
    </div>
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
      <button onclick="App.sendFromDeal()">Send</button>
    </div>
    <hr/>
    <div class="small">Response</div>
    <pre id="deal_out">{}</pre>
  </div>

  <div class="card">
    <h2>Inbound webhook</h2>
    <div class="small">
      Set Grey tenant callback_url to:
      <code><?= htmlspecialchars(cfg('PUBLIC_URL')."/webhook/grey_inbound.php") ?></code>
    </div>
  </div>
</div>

<script src="public/js/app.js"></script>
<script>
BX24.init(function() {
  BX24.resizeWindow(980, 900);
  App.loadSettings().catch(err => document.getElementById('status_out').textContent = String(err));
});
</script>
</body>
</html>
