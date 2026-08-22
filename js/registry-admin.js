(() => {
  'use strict';
  const API = (window.TSCA_API_BASE_URL || 'http://127.0.0.1:8081/api/v1').replace(/\/$/, '');
  const authCard = document.getElementById('authCard');
  const dashboard = document.getElementById('dashboard');
  const authForm = document.getElementById('authForm');
  const keyInput = document.getElementById('adminKey');
  const authStatus = document.getElementById('authStatus');
  const rows = document.getElementById('rows');
  const listStatus = document.getElementById('listStatus');
  const search = document.getElementById('search');
  const type = document.getElementById('type');
  const status = document.getElementById('status');
  let adminKey = sessionStorage.getItem('tscaRegistryAdminKey') || '';

  const request = async (path, options = {}) => {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    headers['X-Registry-Admin-Key'] = adminKey;
    const response = await fetch(`${API}${path}`, { ...options, headers });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) throw new Error(data.error || 'Request failed.');
    return data;
  };

  const showAuthError = message => { authStatus.textContent = message; authStatus.classList.remove('hidden'); };

  authForm.addEventListener('submit', async e => {
    e.preventDefault();
    adminKey = keyInput.value.trim();
    try {
      await request('/registry/stats');
      sessionStorage.setItem('tscaRegistryAdminKey', adminKey);
      authCard.classList.add('hidden');
      dashboard.classList.remove('hidden');
      await refreshAll();
    } catch (error) {
      showAuthError(error.message);
      sessionStorage.removeItem('tscaRegistryAdminKey');
    }
  });

  const refreshAll = async () => {
    try {
      const stats = await request('/registry/stats');
      const s = stats.data;
      document.getElementById('statTotal').textContent = s.total ?? 0;
      document.getElementById('statActive').textContent = s.active ?? 0;
      document.getElementById('statVolunteer').textContent = s.volunteer ?? 0;
      document.getElementById('statMember').textContent = s.member ?? 0;
      await loadRows();
    } catch (error) {
      listStatus.textContent = error.message;
      listStatus.classList.remove('hidden');
    }
  };

  const loadRows = async () => {
    listStatus.classList.add('hidden');
    const params = new URLSearchParams({ limit: '100' });
    if (search.value.trim()) params.set('search', search.value.trim());
    if (type.value) params.set('subscription_type', type.value);
    if (status.value) params.set('status', status.value);
    const result = await request(`/registry?${params.toString()}`);
    rows.innerHTML = result.data.items.map(record => `
      <tr>
        <td><strong>${esc(record.registry_number)}</strong></td>
        <td>${esc(record.full_name)}</td>
        <td>${esc(record.email)}</td>
        <td>${esc(record.phone || '—')}</td>
        <td>${esc(record.subscription_type)}</td>
        <td><span class="status ${record.status === 'inactive' ? 'inactive' : ''}">${esc(record.status)}</span></td>
        <td>${esc(new Date(record.created_at).toLocaleString())}</td>
        <td><button type="button" data-id="${record.id}" class="toggle" style="padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;cursor:pointer">${record.status === 'active' ? 'Deactivate' : 'Activate'}</button></td>
      </tr>`).join('') || '<tr><td colspan="8">No registry records found.</td></tr>';
  };

  rows.addEventListener('click', async e => {
    const button = e.target.closest('.toggle');
    if (!button) return;
    const recordId = button.dataset.id;
    const newStatus = button.textContent.trim() === 'Deactivate' ? 'inactive' : 'active';
    try {
      await request(`/registry/${recordId}`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({status:newStatus}) });
      await refreshAll();
    } catch (error) {
      listStatus.textContent = error.message;
      listStatus.classList.remove('hidden');
    }
  });

  document.getElementById('refresh').addEventListener('click', refreshAll);
  [type, status].forEach(control => control.addEventListener('change', loadRows));
  let searchTimer;
  search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadRows, 300); });

  if (adminKey) {
    keyInput.value = adminKey;
    request('/registry/stats').then(() => { authCard.classList.add('hidden'); dashboard.classList.remove('hidden'); refreshAll(); }).catch(() => sessionStorage.removeItem('tscaRegistryAdminKey'));
  }

  function esc(value) { return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
})();
