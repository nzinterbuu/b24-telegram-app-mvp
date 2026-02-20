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
    if (el('openline_select') && data.line_id !== undefined) {
      el('openline_select').value = data.line_id || '';
    }
    var statusEl = el('openlines_status_text');
    if (statusEl) {
      var parts = [];
      parts.push('Line: ' + (data.line_id ? data.line_id : '—'));
      var cs = data.connector_status;
      if (cs) {
        if (cs.error) parts.push('Connector: error — ' + cs.error);
        else parts.push('Connector: ' + (cs.active ? 'active' : 'not active'));
      }
      var inj = data.openlines_last_inject;
      if (inj && inj.at) {
        var when = new Date(inj.at * 1000).toISOString().replace('T', ' ').slice(0, 19);
        if (inj.success) {
          parts.push('Last injection: ' + when + (inj.session_id ? ' — session ' + inj.session_id + ', chat ' + (inj.chat_id || '—') : ''));
        } else {
          parts.push('Last injection: ' + when + ' — ' + (inj.error || 'failed'));
        }
      } else {
        parts.push('Last injection: none yet');
      }
      statusEl.textContent = parts.join(' · ');
    }
    setText('status_out', JSON.stringify(data.status || {}, null, 2));
  } catch (e) {
    setText('status_out', 'Error: ' + (e && e.message ? e.message : String(e)));
  }
}

async function loadOpenLines(){
  var sel = el('openline_select');
  if (!sel) return;
  try {
    var data = await api('ajax/openlines_list.php', {});
    var lines = data.lines || [];
    var current = sel.value || '';
    sel.innerHTML = '<option value="">— None (Deal tab only) —</option>';
    lines.forEach(function(l) {
      var opt = document.createElement('option');
      opt.value = l.ID || l.id || '';
      opt.textContent = (l.LINE_NAME || l.line_name || 'Line ' + (l.ID || l.id)) + ' (ID: ' + (l.ID || l.id) + ')';
      if (String(opt.value) === String(current)) opt.selected = true;
      sel.appendChild(opt);
    });
  } catch (e) {
    sel.innerHTML = '<option value="">— Error loading lines —</option>';
  }
}

async function saveOpenLine(){
  try {
    var lineId = el('openline_select') ? el('openline_select').value : '';
    var data = await api('ajax/openlines_save.php', { line_id: lineId });
    setText('status_out', JSON.stringify(data, null, 2));
    if (data.ok) loadSettings();
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

function escapeHtml(s) {
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

async function loadMessageLog() {
  var wrap = el('message_log_wrap');
  if (!wrap) return;
  try {
    var data = await api('ajax/get_message_log.php', { limit: 80 });
    var messages = data.messages || [];
    if (messages.length === 0) {
      wrap.innerHTML = '<p class="small">No messages yet. Inbound messages appear here when the webhook is called; outbound when you send from Deal tab or Contact Center.</p>';
      return;
    }
    var rows = messages.map(function(m) {
      var dir = m.direction === 'in' ? 'IN' : 'OUT';
      var dirCls = m.direction === 'in' ? 'msg-in' : 'msg-out';
      var when = m.created_at ? new Date(m.created_at * 1000).toLocaleString() : '—';
      var src = m.source || '—';
      var err = m.error_text ? '<span class="msg-err">' + escapeHtml(m.error_text) + '</span>' : '';
      return '<tr class="' + dirCls + '"><td>' + when + '</td><td>' + dir + '</td><td>' + escapeHtml(m.peer || '—') + '</td><td>' + escapeHtml((m.text_preview || '').substring(0, 120)) + (m.text_preview && m.text_preview.length > 120 ? '…' : '') + '</td><td>' + (m.deal_id ? '#' + m.deal_id : '—') + '</td><td>' + escapeHtml(src) + '</td><td>' + err + '</td></tr>';
    });
    wrap.innerHTML = '<table class="message-log-table"><thead><tr><th>Time</th><th>Dir</th><th>Peer</th><th>Text</th><th>Deal</th><th>Source</th><th>Error</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>';
  } catch (e) {
    wrap.innerHTML = '<p class="status-err">Error: ' + (e && e.message ? e.message : String(e)) + '</p>';
  }
}

window.App = {
  loadSettings, saveSettings, refreshStatus, startOtp, resendOtp, verifyOtp, logout, sendFromDeal,
  loadTenants, onTenantSelectChange, showCreateTenant, hideCreateTenant, createTenant,
  loadOpenLines, saveOpenLine,
  loadMessageLog
};
