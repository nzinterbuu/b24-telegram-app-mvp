/* global BX24 */
function getAuth() {
  var auth = (typeof BX24 !== 'undefined' && BX24.getAuth) ? BX24.getAuth() : null;
  if (auth && auth.access_token && auth.domain) return Promise.resolve(auth);
  return new Promise(function(resolve) {
    if (typeof BX24 !== 'undefined' && BX24.getAuth) BX24.getAuth(resolve);
    else resolve(null);
  });
}

async function api(path, body = {}) {
  var auth = await getAuth();
  if (!auth || !auth.access_token) throw new Error('Bitrix24 auth not available. Open the app from Bitrix24.');
  var res = await fetch(path, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({auth: auth, ...body})
  });
  var json = await res.json().catch(function() { return {}; });
  if (!res.ok) throw new Error(json.message || json.error || ('HTTP ' + res.status));
  return json;
}

function el(id){ return document.getElementById(id); }
function setText(id, txt){ el(id).textContent = txt; }

async function loadSettings(){
  try {
    var data = await api('ajax/get_settings.php', {});
    el('tenant_id').value = data.tenant_id || '';
    el('api_token').value = data.api_token || '';
    el('phone').value = data.phone || '';
    setText('status_out', JSON.stringify(data.status || {}, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function saveSettings(){
  try {
    var payload = {
      tenant_id: el('tenant_id').value.trim(),
      api_token: el('api_token').value.trim(),
      phone: el('phone').value.trim(),
    };
    var data = await api('ajax/save_settings.php', payload);
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function refreshStatus(){
  try {
    var data = await api('ajax/grey_status.php', {});
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function startOtp(){
  try {
    var data = await api('ajax/grey_auth_start.php', {});
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function resendOtp(){
  try {
    var data = await api('ajax/grey_auth_resend.php', {});
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function verifyOtp(){
  try {
    var data = await api('ajax/grey_auth_verify.php', {
      code: el('otp').value.trim(),
      password: el('tfa').value.trim(),
    });
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function logout(){
  try {
    var data = await api('ajax/grey_logout.php', {});
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function sendFromDeal(){
  try {
    var data = await api('ajax/send_from_deal.php', {
      deal_id: el('deal_id').value.trim(),
      text: el('deal_text').value
    });
    setText('deal_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('deal_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

window.App = {
  loadSettings, saveSettings, refreshStatus, startOtp, resendOtp, verifyOtp, logout, sendFromDeal
};
