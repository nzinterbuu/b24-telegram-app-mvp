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

function statusLabel(s) {
  if (!s) return '—';
  if (s.error) return s.error;
  if (s.authorized) return 'Authorized' + (s.phone ? ' (' + s.phone + ')' : '');
  return 'Not authorized';
}

async function loadTenants(){
  try {
    var data = await api('ajax/get_tenants.php', {});
    var tenants = data.tenants || [];
    var sel = el('tenant_select');
    var currentId = el('tenant_id').value;
    sel.innerHTML = '<option value="">— Select tenant —</option>';
    tenants.forEach(function(t) {
      var opt = document.createElement('option');
      opt.value = t.id || '';
      opt.textContent = (t.name || t.id || '—') + ' (' + (t.id ? t.id.substring(0, 8) + '…' : '') + ')';
      if (t.id === currentId) opt.selected = true;
      sel.appendChild(opt);
    });

    var wrap = el('tenants_table_wrap');
    if (tenants.length === 0) {
      wrap.innerHTML = '<p class="small">No tenants. Create one above.</p>';
    } else {
      var tbl = '<table><thead><tr><th>Name</th><th>ID</th><th>Status</th></tr></thead><tbody>';
      tenants.forEach(function(t) {
        var st = statusLabel(t.status);
        var cls = t.status && t.status.authorized ? 'status-ok' : (t.status && t.status.error ? 'status-err' : '');
        tbl += '<tr><td>' + (t.name || '—') + '</td><td><code>' + (t.id || '—') + '</code></td><td class="' + cls + '">' + st + '</td></tr>';
      });
      tbl += '</tbody></table>';
      wrap.innerHTML = tbl;
    }
  } catch (e) {
    el('tenants_table_wrap').innerHTML = '<p class="status-err">Error: ' + (e && e.message ? e.message : String(e)) + '</p>';
  }
}

function onTenantSelectChange(){
  var sel = el('tenant_select');
  el('tenant_id').value = sel.value || '';
  if (sel.value) App.saveSettings();
}

function showCreateTenant(){
  el('create_tenant_form').style.display = 'block';
  el('new_tenant_name').value = '';
  el('new_tenant_callback').value = '';
}
function hideCreateTenant(){
  el('create_tenant_form').style.display = 'none';
}

async function createTenant(){
  var name = el('new_tenant_name').value.trim();
  if (!name) {
    setText('status_out', 'Error: Enter a tenant name.');
    return;
  }
  try {
    var payload = { name: name };
    var cb = el('new_tenant_callback').value.trim();
    if (cb) payload.callback_url = cb;
    var data = await api('ajax/create_tenant.php', payload);
    hideCreateTenant();
    var t = data.tenant || data;
    el('tenant_id').value = t.id || '';
    await loadTenants();
    var sel = el('tenant_select');
    sel.value = t.id || '';
    setText('status_out', JSON.stringify(data, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function loadSettings(){
  try {
    var data = await api('ajax/get_settings.php', {});
    el('tenant_id').value = data.tenant_id || '';
    el('api_token').value = data.api_token || '';
    el('phone').value = data.phone || '';
    if (el('tenant_select')) {
      var sel = el('tenant_select');
      if (sel.options.length) {
        var found = false;
        for (var i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === data.tenant_id) { sel.selectedIndex = i; found = true; break; }
        }
        if (!found && data.tenant_id) sel.innerHTML = '<option value="">— Select tenant —</option><option value="' + data.tenant_id + '" selected>Saved: ' + data.tenant_id + '</option>' + (sel.options.length > 2 ? '' : '');
      }
    }
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
  loadSettings, saveSettings, refreshStatus, startOtp, resendOtp, verifyOtp, logout, sendFromDeal,
  loadTenants, onTenantSelectChange, showCreateTenant, hideCreateTenant, createTenant
};
