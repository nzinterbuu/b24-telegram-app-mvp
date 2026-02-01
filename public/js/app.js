/* global BX24 */
async function api(path, body = {}) {
  const auth = await new Promise(resolve => BX24.getAuth(resolve));
  const res = await fetch(path, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({auth, ...body})
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json.message || json.error || ('HTTP '+res.status));
  return json;
}

function el(id){ return document.getElementById(id); }
function setText(id, txt){ el(id).textContent = txt; }

async function loadSettings(){
  const data = await api('ajax/get_settings.php', {});
  el('tenant_id').value = data.tenant_id || '';
  el('api_token').value = data.api_token || '';
  el('phone').value = data.phone || '';
  setText('status_out', JSON.stringify(data.status || {}, null, 2));
}

async function saveSettings(){
  const payload = {
    tenant_id: el('tenant_id').value.trim(),
    api_token: el('api_token').value.trim(),
    phone: el('phone').value.trim(),
  };
  const data = await api('ajax/save_settings.php', payload);
  setText('status_out', JSON.stringify(data, null, 2));
}

async function refreshStatus(){
  const data = await api('ajax/grey_status.php', {});
  setText('status_out', JSON.stringify(data, null, 2));
}

async function startOtp(){
  const data = await api('ajax/grey_auth_start.php', {});
  setText('status_out', JSON.stringify(data, null, 2));
}

async function resendOtp(){
  const data = await api('ajax/grey_auth_resend.php', {});
  setText('status_out', JSON.stringify(data, null, 2));
}

async function verifyOtp(){
  const data = await api('ajax/grey_auth_verify.php', {
    code: el('otp').value.trim(),
    password: el('tfa').value.trim(),
  });
  setText('status_out', JSON.stringify(data, null, 2));
}

async function logout(){
  const data = await api('ajax/grey_logout.php', {});
  setText('status_out', JSON.stringify(data, null, 2));
}

async function sendFromDeal(){
  const data = await api('ajax/send_from_deal.php', {
    deal_id: el('deal_id').value.trim(),
    text: el('deal_text').value
  });
  setText('deal_out', JSON.stringify(data, null, 2));
}

window.App = {
  loadSettings, saveSettings, refreshStatus, startOtp, resendOtp, verifyOtp, logout, sendFromDeal
};
