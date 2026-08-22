(() => {
  'use strict';
  const API = (window.TSCA_API_BASE_URL || 'http://127.0.0.1:8081/api/v1').replace(/\/+$/, '');
  let authToken = localStorage.getItem('tscaToken') || '';
  let currentUser = JSON.parse(localStorage.getItem('tscaUser') || 'null');
  const authScreen = document.getElementById('authScreen');
  const appShell = document.getElementById('appShell');
  const authForm = document.getElementById('authForm');
  const authStatus = document.getElementById('authStatus');
  const authUsername = document.getElementById('authUsername');
  const authPassword = document.getElementById('authPassword');
  const mainContent = document.getElementById('mainContent');
  const navBtns = document.querySelectorAll('[data-nav]');
  const userBadge = document.getElementById('userBadge');
  async function api(path, opts = {}) {
    const headers = { Accept: 'application/json', ...(opts.headers || {}) };
    if (authToken) headers['Authorization'] = 'Bearer ' + authToken;
    const res = await fetch(API + path, { ...opts, headers });
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('text/csv')) return res;
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) throw new Error(data.error || 'HTTP ' + res.status);
    return data;
  }
  function showApp() {
    authScreen.classList.add('hidden');
    appShell.classList.remove('hidden');
    if (userBadge && currentUser) { userBadge.textContent = currentUser.full_name || currentUser.username; }
    applyRoleVisibility();
    navigate('dashboard');
  }
  function logout() {
    if (authToken) api('/registry/auth/logout', { method: 'POST' }).catch(() => {});
    authToken = ''; currentUser = null;
    localStorage.removeItem('tscaToken'); localStorage.removeItem('tscaUser');
    appShell.classList.add('hidden'); authScreen.classList.remove('hidden'); authPassword.value = '';
  }
  authForm.addEventListener('submit', async e => {
    e.preventDefault();
    const username = authUsername.value.trim();
    const password = authPassword.value;
    if (!username || !password) { authStatus.textContent = 'Please enter username and password.'; authStatus.classList.remove('hidden'); return; }
    try {
      const data = await api('/registry/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: username, password: password }) });
      authToken = data.data.token; currentUser = data.data.user;
      localStorage.setItem('tscaToken', authToken); localStorage.setItem('tscaUser', JSON.stringify(currentUser));
      showApp();
    } catch (err) {
      try {
        const data = await api('/registry/auth/key-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: password }) });
        authToken = data.data.token; currentUser = data.data.user;
        localStorage.setItem('tscaToken', authToken); localStorage.setItem('tscaUser', JSON.stringify(currentUser));
        showApp();
      } catch (err2) {
        authStatus.textContent = err.message;
        authStatus.classList.remove('hidden');
      }
    }
  });
  function applyRoleVisibility() {
    if (!currentUser) return;
    const role = currentUser.role;
    const levels = { 'DATA_ENTRY': 1, 'SUPERVISOR': 2, 'ADMINISTRATOR': 3 };
    const userLevel = levels[role] || 0;
    document.querySelectorAll('[data-min-role]').forEach(el => {
      const required = levels[el.dataset.minRole] || 0;
      el.style.display = userLevel >= required ? '' : 'none';
    });
    document.querySelectorAll('[data-hide-role]').forEach(el => {
      el.style.display = el.dataset.hideRole === role ? 'none' : '';
    });
  }
  navBtns.forEach(b => b.addEventListener('click', () => navigate(b.dataset.nav)));
  window.__regNav = navigate; window.__regParticipants = renderParticipants; window.__regProfile = renderProfile; window.__regLogout = logout;
  function navigate(view) {
    navBtns.forEach(b => b.classList.toggle('active', b.dataset.nav === view));
    ({dashboard:renderDashboard,participants:renderParticipants,register:renderRegister,screening:renderScreening,events:renderEvents,reports:renderReports,'admin-users':renderAdminUsers,'pending-review':renderPendingReview,'registrations':renderRegistrations})[view]?.();
  }
  function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
  function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '\u2014'; }
  function gBadge(g) { return '<span class="badge badge-'+esc(g)+'">'+esc(g)+'</span>'; }
  function rBadge(r) { const c=r==='reactive'?'result-reactive':r==='AS'?'result-as':r==='AA'?'result-aa':r==='SS'?'result-ss':''; return '<span class="badge '+(c?'badge-'+c:'')+'">'+esc(r)+'</span>'; }
  function sBadge(s) { return '<span class="badge badge-'+esc(s)+'">'+esc(s)+'</span>'; }
  function roleBadge(r) { const c=r==='ADMINISTRATOR'?'badge-admin':r==='SUPERVISOR'?'badge-supervisor':'badge-data-entry'; return '<span class="badge '+c+'">'+esc(r.replace(/_/g,' '))+'</span>'; }
  window.togglePw = function(input, btn) {
    const icon = btn.querySelector('i');
    if (input.type === 'password') { input.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
    else { input.type = 'password'; icon.className = 'fa-solid fa-eye'; }
  };
  function renderDashboard() {
    mainContent.innerHTML = '<div class="reg-loading">Loading dashboard...</div>';
    const showFU = currentUser && ['ADMINISTRATOR','SUPERVISOR'].includes(currentUser.role);
    const loadAll = [api('/registry/participants/stats'), api('/registry/screenings/stats')];
    if (showFU) loadAll.push(api('/registry/follow-ups'));
    loadAll.push(api('/registry/reports/summary'));
    if (currentUser && currentUser.role === 'ADMINISTRATOR') loadAll.push(api('/registry/stats'));
    Promise.all(loadAll).then(R => {
      const p=R[0].data, s=R[1].data; let fi=2;
      const f=showFU?R[fi++].data:{pending:0,referrals_needed:0}; const m=R[fi].data;
      const isAdmin = currentUser && currentUser.role === 'ADMINISTRATOR';
      const regStats = isAdmin && R[fi+1] ? R[fi+1].data : null;
      let h='<div class="section-header"><h2 style="margin:0">Dashboard</h2></div>'
        +'<div class="reg-card"><h2>Quick Actions</h2><div class="action-grid">'
        +'<button class="action-btn-reg" data-nav="register"><i class="fa-solid fa-user-plus"></i> Register Participant</button>'
        +'<button class="action-btn-reg" data-nav="screening"><i class="fa-solid fa-flask"></i> Record Screening</button>'
        +'<button class="action-btn-reg" data-nav="events"><i class="fa-solid fa-calendar-plus"></i> Create Event</button>'
        +'<button class="action-btn-reg" data-nav="reports"><i class="fa-solid fa-chart-bar"></i> Reports</button>'
        +'</div></div>'
        +'<div class="reg-card"><h2>Overview</h2><div class="stat-grid">'
        +'<div class="stat-box"><strong>'+p.total_participants+'</strong><span>Participants</span></div>'
        +'<div class="stat-box"><strong>'+s.total_screenings+'</strong><span>Screenings</span></div>'
        +'<div class="stat-box"><strong>'+s.screened_today+'</strong><span>Today</span></div>'
        +(showFU?'<div class="stat-box"><strong>'+f.pending+'</strong><span>Pending Follow-ups</span></div><div class="stat-box"><strong>'+f.referrals_needed+'</strong><span>Referrals</span></div>':'')
        +'<div class="stat-box"><strong>'+m.events.total+'</strong><span>Events</span></div>'
        +'<div class="stat-box"><strong>'+p.minors+'</strong><span>Minors</span></div>'
        +'<div class="stat-box"><strong>'+p.registered_today+'</strong><span>Reg. Today</span></div>'
        +(regStats?'<div class="stat-box" style="cursor:pointer" onclick="window.__regNav(\'registrations\')"><strong>'+regStats.total+'</strong><span>Public Signups</span></div>':'')
        +'</div></div>'
        +'<div class="reg-card"><h2>Result Distribution</h2>'
        +(s.total_screenings===0?'<div class="empty-state"><p>No screenings yet.</p></div>':resultBars(s))
        +'</div>';
      mainContent.innerHTML=h;
      mainContent.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.nav)));
    }).catch(e=>{mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';});
  }
  function resultBars(s) {
    const t=Math.max(s.total_screenings,1);
    return [['AA',s.aa],['AS',s.as],['SS',s.ss],['SC',s.sc],['Reactive',s.reactive],['Non-Reactive',s.non_reactive],['Unknown',s.unknown_type]]
    .filter(([,v])=>v>0).map(([l,c])=>{const pct=Math.round(c/t*100);return '<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;font-size:.85rem"><span>'+esc(l)+'</span><span>'+c+' ('+pct+'%)</span></div><div style="background:#e5e7eb;border-radius:4px;height:8px;margin-top:4px"><div style="background:var(--reg-accent);height:100%;width:'+pct+'%;border-radius:4px"></div></div></div>';}).join('');
  }
  async function renderParticipants(page) {
    page=page||1; mainContent.innerHTML='<div class="reg-loading">Loading...</div>';
    try{const q=document.getElementById('searchInput')?.value||'';const d=document.getElementById('filterDistrict')?.value||'';const g=document.getElementById('filterGender')?.value||'';
    const params=new URLSearchParams({page:String(page),limit:'15'});if(q)params.set('search',q);if(d)params.set('district',d);if(g)params.set('gender',g);
    const res=await api('/registry/participants?'+params);const items=res.data.items,pg=res.data.pagination;
    let h='<div class="section-header"><h2 style="margin:0">Participants</h2><button class="btn-primary" data-nav="register"><i class="fa-solid fa-plus"></i> Register</button></div>'
      +'<div class="reg-card"><div class="toolbar"><input id="searchInput" placeholder="Search TSCA ID, name, phone..." value="'+esc(q)+'">'
      +'<select id="filterDistrict"><option value="">All Districts</option><option value="Gulu"'+(d==='Gulu'?' selected':'')+'>Gulu</option><option value="Amuru"'+(d==='Amuru'?' selected':'')+'>Amuru</option><option value="Kitgum"'+(d==='Kitgum'?' selected':'')+'>Kitgum</option><option value="Lira"'+(d==='Lira'?' selected':'')+'>Lira</option><option value="Arua"'+(d==='Arua'?' selected':'')+'>Arua</option></select>'
      +'<select id="filterGender"><option value="">All</option><option value="male"'+(g==='male'?' selected':'')+'>Male</option><option value="female"'+(g==='female'?' selected':'')+'>Female</option></select>'
      +'<button onclick="window.__regParticipants(1)">Search</button></div>';
    h+='<div class="table-wrap"><table class="data-table"><thead><tr><th>TSCA ID</th><th>Name</th><th>Age</th><th>Gender</th><th>Phone</th><th>District</th><th>Minor</th><th>Registered</th><th>Action</th></tr></thead><tbody>';
    items.forEach(p=>{h+='<tr><td><strong>'+esc(p.tsca_id)+'</strong></td><td>'+esc(p.first_name)+' '+esc(p.last_name)+'</td><td>'+(p.age??'\u2014')+'</td><td>'+gBadge(p.gender)+'</td><td>'+esc(p.phone||'\u2014')+'</td><td>'+esc(p.district||'\u2014')+'</td><td>'+(p.is_minor?'<span class="badge badge-minor">Yes</span>':'No')+'</td><td>'+fmtDate(p.created_at)+'</td><td><button class="btn-secondary" style="padding:6px 10px;font-size:.8rem" onclick="window.__regProfile('+p.id+')">View</button></td></tr>';});
    h+='</tbody></table></div><div class="mobile-cards">';
    items.forEach(p=>{h+='<div class="record-card"><div class="card-header"><div><span class="card-id">'+esc(p.tsca_id)+'</span><div class="card-name">'+esc(p.first_name)+' '+esc(p.last_name)+'</div></div>'+(p.is_minor?'<span class="badge badge-minor">Minor</span>':'')+'</div><div class="card-meta"><span><i class="fa-solid fa-cake-candles"></i> '+(p.age??'\u2014')+' yrs</span><span>'+gBadge(p.gender)+'</span><span><i class="fa-solid fa-phone"></i> '+esc(p.phone||'\u2014')+'</span><span><i class="fa-solid fa-location-dot"></i> '+esc(p.district||'\u2014')+'</span></div><div class="card-actions"><button class="btn-primary" style="padding:8px 14px;font-size:.85rem" onclick="window.__regProfile('+p.id+')">View Record</button></div></div>';});
    if(!items.length)h+='<div class="empty-state"><p>No participants found.</p></div>';h+='</div>';
    if(pg.pages>1)h+='<div class="pagination"><button '+(pg.page<=1?'disabled':'')+' onclick="window.__regParticipants('+(pg.page-1)+')">Prev</button><span>Page '+pg.page+' of '+pg.pages+' ('+pg.total+' total)</span><button '+(pg.page>=pg.pages?'disabled':'')+' onclick="window.__regParticipants('+(pg.page+1)+')">Next</button></div>';
    h+='</div>';mainContent.innerHTML=h;
    document.getElementById('searchInput')?.addEventListener('keydown',e=>{if(e.key==='Enter')window.__regParticipants(1);});
    document.getElementById('filterDistrict')?.addEventListener('change',()=>window.__regParticipants(1));
    document.getElementById('filterGender')?.addEventListener('change',()=>window.__regParticipants(1));
    mainContent.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.nav)));
    }catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  function renderRegister() {
    mainContent.innerHTML='<div class="section-header"><h2 style="margin:0">Register Participant</h2></div>'
      +'<div class="reg-card"><div id="regAlert"></div><form id="registerForm"><div class="form-grid">'
      +'<div class="form-group"><label>First Name <span class="required">*</span></label><input name="first_name" required></div>'
      +'<div class="form-group"><label>Last Name <span class="required">*</span></label><input name="last_name" required></div>'
      +'<div class="form-group"><label>Age</label><input name="age" type="number" min="0" max="150" id="partAge"></div>'
      +'<div class="form-group"><label>Gender <span class="required">*</span></label><select name="gender" required><option value="">Select...</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>'
      +'<div class="form-group"><label>Phone</label><input name="phone" type="tel" placeholder="+256 7XX XXX XXX"></div>'
      +'<div class="form-group"><label>National ID</label><input name="national_id"></div>'
      +'<div class="form-group"><label>Date of Birth</label><input name="date_of_birth" type="date"></div>'
      +'<div class="form-group"><label>District</label><select name="district"><option value="">Select...</option><option>Gulu</option><option>Amuru</option><option>Kitgum</option><option>Lira</option><option>Arua</option><option>Adjumani</option><option>Moroto</option><option>Napak</option></select></div>'
      +'<div class="form-group"><label>Sub-County</label><input name="sub_county"></div>'
      +'<div class="form-group"><label>Village</label><input name="village"></div>'
      +'<div class="form-group full"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>'
      +'</div>'
      +'<div id="guardianSection" class="guardian-fields" style="margin-top:16px;border-top:2px solid var(--reg-border);padding-top:16px">'
      +'<h3 style="grid-column:1/-1;color:var(--reg-warning)"><i class="fa-solid fa-shield-halved"></i> Guardian (Minor)</h3>'
      +'<div class="form-group"><label>Guardian Name</label><input name="guardian_name"></div>'
      +'<div class="form-group"><label>Guardian Phone</label><input name="guardian_phone" type="tel"></div>'
      +'<div class="form-group"><label>Relationship</label><select name="guardian_relationship"><option value="">Select...</option><option>Father</option><option>Mother</option><option>Guardian</option><option>Other</option></select></div>'
      +'</div>'
      +'<div class="form-actions"><button type="submit" class="btn-primary" id="regSubmitBtn">Register Participant</button>'
      +'<button type="button" class="btn-secondary" onclick="window.__regNav(\'participants\')">Cancel</button></div></form></div>';
    document.getElementById('partAge')?.addEventListener('input',function(){document.getElementById('guardianSection').classList.toggle('show',parseInt(this.value)<18&&parseInt(this.value)>=0);});
    document.getElementById('registerForm').addEventListener('submit',async e=>{e.preventDefault();const btn=document.getElementById('regSubmitBtn');btn.disabled=true;btn.textContent='Registering...';try{const payload=Object.fromEntries(new FormData(e.target).entries());if(!payload.age)delete payload.age;const res=await api('/registry/participants',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});document.getElementById('regAlert').innerHTML='<div class="reg-alert reg-alert-success">Registered! TSCA ID: <strong>'+esc(res.data.tsca_id)+'</strong></div>';e.target.reset();setTimeout(()=>window.__regProfile(res.data.id),1500);}catch(err){document.getElementById('regAlert').innerHTML='<div class="reg-alert reg-alert-error">'+esc(err.message)+'</div>';}finally{btn.disabled=false;btn.textContent='Register Participant';}});
  }
  async function renderScreening() {
    mainContent.innerHTML='<div class="reg-loading">Loading...</div>';
    try{const pRes=await api('/registry/participants?limit=100');const eRes=await api('/registry/events?limit=100');const parts=pRes.data.items,evts=eRes.data.items;
    let h='<div class="section-header"><h2 style="margin:0">Record Screening</h2></div>'
      +'<div class="reg-card"><div id="scrAlert"></div><form id="screeningForm"><div class="form-grid">'
      +'<div class="form-group"><label>Participant <span class="required">*</span></label><select name="participant_id" required><option value="">Select...</option>'
      +parts.map(p=>'<option value="'+p.id+'">'+esc(p.tsca_id)+' - '+esc(p.first_name)+' '+esc(p.last_name)+'</option>').join('')+'</select></div>'
      +'<div class="form-group"><label>Outreach Event</label><select name="event_id"><option value="">None</option>'
      +evts.map(e=>'<option value="'+e.id+'">'+esc(e.event_name)+' ('+esc(e.district)+')</option>').join('')+'</select></div>'
      +'<div class="form-group"><label>Screening Date <span class="required">*</span></label><input name="screening_date" type="date" value="'+new Date().toISOString().split('T')[0]+'" required></div>'
      +'<div class="form-group"><label>Screening Site</label><input name="screening_site" placeholder="e.g. Gulu Hospital"></div>'
      +'<div class="form-group"><label>Test Type <span class="required">*</span></label><select name="test_type" required><option value="">Select...</option><option value="rapid_test">Rapid Test</option><option value="hemoglobin_electrophoresis">Hemoglobin Electrophoresis</option><option value="hplc">HPLC</option><option value="other">Other</option></select></div>'
      +'<div class="form-group"><label>Result <span class="required">*</span></label><select name="result" required><option value="">Select...</option><option value="reactive">Reactive</option><option value="non_reactive">Non-Reactive</option><option value="AA">AA</option><option value="AS">AS</option><option value="SS">SS</option><option value="SC">SC</option><option value="unknown">Unknown</option></select></div>'
      +'<div class="form-group"><label>Health Worker Name</label><input name="health_worker_name"></div>'
      +'<div class="form-group"><label>Health Worker ID</label><input name="health_worker_id"></div>'
      +'<div class="form-group full"><label>Counselor Notes</label><textarea name="counselor_notes" rows="2"></textarea></div>'
      +'</div><div class="form-actions"><button type="submit" class="btn-primary" id="scrSubmitBtn">Save Screening</button></div></form></div>';
    mainContent.innerHTML=h;
    document.getElementById('screeningForm').addEventListener('submit',async e=>{e.preventDefault();const btn=document.getElementById('scrSubmitBtn');btn.disabled=true;btn.textContent='Saving...';try{const payload=Object.fromEntries(new FormData(e.target).entries());if(!payload.event_id)delete payload.event_id;const pid=payload.participant_id;delete payload.participant_id;const res=await api('/registry/participants/'+pid+'/screenings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});document.getElementById('scrAlert').innerHTML='<div class="reg-alert reg-alert-success">Screening recorded! ID: '+res.data.id+'</div>';e.target.reset();}catch(err){document.getElementById('scrAlert').innerHTML='<div class="reg-alert reg-alert-error">'+esc(err.message)+'</div>';}finally{btn.disabled=false;btn.textContent='Save Screening';}});
    }catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  async function renderProfile(id) {
    mainContent.innerHTML='<div class="reg-loading">Loading profile...</div>';
    try{const[pRes,sRes,fRes]=await Promise.all([api('/registry/participants/'+id),api('/registry/participants/'+id+'/screenings'),api('/registry/participants/'+id+'/follow-ups')]);
    const p=pRes.data,screens=sRes.data,follows=fRes.data;const initials=(p.first_name[0]||'')+(p.last_name[0]||'');
    let h='<div class="section-header"><h2 style="margin:0">Participant Profile</h2><button class="btn-secondary" onclick="window.__regNav(\'participants\')"><i class="fa-solid fa-arrow-left"></i> Back</button></div>'
      +'<div class="reg-card"><div class="profile-header"><div class="profile-avatar">'+esc(initials)+'</div><div class="profile-info"><h2>'+esc(p.first_name)+' '+esc(p.last_name)+'</h2><div class="tsca-id">'+esc(p.tsca_id)+'</div></div></div>'
      +'<div class="profile-grid">'
      +'<div class="profile-field"><label>Age</label><span>'+(p.age??'\u2014')+'</span></div>'
      +'<div class="profile-field"><label>Gender</label><span>'+gBadge(p.gender)+'</span></div>'
      +'<div class="profile-field"><label>Phone</label><span>'+esc(p.phone||'\u2014')+'</span></div>'
      +'<div class="profile-field"><label>National ID</label><span>'+esc(p.national_id||'\u2014')+'</span></div>'
      +'<div class="profile-field"><label>District</label><span>'+esc(p.district||'\u2014')+'</span></div>'
      +'<div class="profile-field"><label>Sub-County</label><span>'+esc(p.sub_county||'\u2014')+'</span></div>'
      +'<div class="profile-field"><label>Village</label><span>'+esc(p.village||'\u2014')+'</span></div>'
      +'<div class="profile-field"><label>Minor</label><span>'+(p.is_minor?'<span class="badge badge-minor">Yes</span>':'No')+'</span></div>'
      +(p.is_minor?'<div class="profile-field"><label>Guardian</label><span>'+esc(p.guardian_name||'\u2014')+' ('+esc(p.guardian_relationship||'')+')</span></div><div class="profile-field"><label>Guardian Phone</label><span>'+esc(p.guardian_phone||'\u2014')+'</span></div>':'')
      +'</div></div>';
    h+='<div class="reg-card"><div class="section-header"><h3 style="margin:0">Screenings ('+screens.length+')</h3><button class="btn-primary" style="padding:8px 14px;font-size:.85rem" onclick="window.__regNav(\'screening\')"><i class="fa-solid fa-plus"></i> Add</button></div>';
    if(screens.length){h+='<div class="timeline">';screens.forEach(s=>{h+='<div class="timeline-item"><div class="tl-date">'+fmtDate(s.screening_date)+'</div><div class="tl-content"><h4>'+rBadge(s.result)+' - '+esc(s.test_type)+'</h4><p>'+(s.screening_site?'Site: '+esc(s.screening_site)+' | ':'')+(s.health_worker_name?'Worker: '+esc(s.health_worker_name):'')+'</p>'+(s.counselor_notes?'<p style="margin-top:4px;font-style:italic">'+esc(s.counselor_notes)+'</p>':'')+(s.event_name?'<p style="margin-top:4px"><i class="fa-solid fa-calendar"></i> '+esc(s.event_name)+'</p>':'')+'</div></div>';});h+='</div>';}else h+='<div class="empty-state"><p>No screenings recorded.</p></div>';
    h+='</div>';
    h+='<div class="reg-card"><div class="section-header"><h3 style="margin:0">Follow-ups / Referrals ('+follows.length+')</h3><button class="btn-primary" style="padding:8px 14px;font-size:.85rem" onclick="window.__regAddFollowUp('+p.id+')"><i class="fa-solid fa-plus"></i> Add</button></div>';
    if(follows.length){h+='<div class="timeline">';follows.forEach(f=>{h+='<div class="timeline-item"><div class="tl-date">'+fmtDate(f.follow_up_date)+'</div><div class="tl-content"><h4>'+sBadge(f.follow_up_status)+'</h4>'+(f.referral_needed?'<p><span class="badge badge-referral">Referral to '+esc(f.referral_facility||'\u2014')+'</span></p>':'')+(f.follow_up_outcome?'<p>Outcome: '+esc(f.follow_up_outcome)+'</p>':'')+(f.counselor_notes?'<p style="font-style:italic">'+esc(f.counselor_notes)+'</p>':'')+(f.next_follow_up_date?'<p>Next: '+fmtDate(f.next_follow_up_date)+'</p>':'')+'</div></div>';});h+='</div>';}else h+='<div class="empty-state"><p>No follow-ups recorded.</p></div>';
    h+='</div>';mainContent.innerHTML=h;}catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  window.__regAddFollowUp = async function(pid) {
    mainContent.innerHTML='<div class="section-header"><h2 style="margin:0">Add Follow-up</h2></div>'
      +'<div class="reg-card"><div id="fuAlert"></div><form id="followUpForm"><div class="form-grid">'
      +'<div class="form-group"><label>Follow-up Date <span class="required">*</span></label><input name="follow_up_date" type="date" value="'+new Date().toISOString().split('T')[0]+'" required></div>'
      +'<div class="form-group"><label>Referral Needed</label><select name="referral_needed"><option value="false">No</option><option value="true">Yes</option></select></div>'
      +'<div class="form-group"><label>Referral Facility</label><input name="referral_facility"></div>'
      +'<div class="form-group"><label>Status</label><select name="follow_up_status"><option value="pending">Pending</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="lost_to_follow_up">Lost to Follow-up</option></select></div>'
      +'<div class="form-group full"><label>Referral Reason</label><textarea name="referral_reason" rows="2"></textarea></div>'
      +'<div class="form-group full"><label>Outcome</label><textarea name="follow_up_outcome" rows="2"></textarea></div>'
      +'<div class="form-group full"><label>Counseling Notes</label><textarea name="counselor_notes" rows="2"></textarea></div>'
      +'<div class="form-group"><label>Next Follow-up Date</label><input name="next_follow_up_date" type="date"></div>'
      +'</div><div class="form-actions"><button type="submit" class="btn-primary">Save Follow-up</button><button type="button" class="btn-secondary" onclick="window.__regProfile('+pid+')">Cancel</button></div></form></div>';
    document.getElementById('followUpForm').addEventListener('submit',async e=>{e.preventDefault();try{const payload=Object.fromEntries(new FormData(e.target).entries());payload.referral_needed=payload.referral_needed==='true';await api('/registry/participants/'+pid+'/follow-ups',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});document.getElementById('fuAlert').innerHTML='<div class="reg-alert reg-alert-success">Follow-up saved!</div>';setTimeout(()=>window.__regProfile(pid),1000);}catch(err){document.getElementById('fuAlert').innerHTML='<div class="reg-alert reg-alert-error">'+esc(err.message)+'</div>';}});
  };
  async function renderEvents() {
    mainContent.innerHTML='<div class="reg-loading">Loading events...</div>';
    try{const res=await api('/registry/events?limit=50');const items=res.data.items;
    let h='<div class="section-header"><h2 style="margin:0">Outreach Events</h2><button class="btn-primary" onclick="window.__regNewEvent()"><i class="fa-solid fa-plus"></i> Create Event</button></div><div class="reg-card">';
    if(items.length){h+='<div class="table-wrap"><table class="data-table"><thead><tr><th>Event</th><th>District</th><th>Date</th><th>Team Lead</th><th>Partners</th></tr></thead><tbody>';items.forEach(e=>{h+='<tr><td><strong>'+esc(e.event_name)+'</strong></td><td>'+esc(e.district)+'</td><td>'+fmtDate(e.event_date)+'</td><td>'+esc(e.team_lead||'\u2014')+'</td><td>'+esc(e.partners||'\u2014')+'</td></tr>';});h+='</tbody></table></div>';h+='<div class="mobile-cards">';items.forEach(e=>{h+='<div class="record-card"><div class="card-name">'+esc(e.event_name)+'</div><div class="card-meta"><span><i class="fa-solid fa-location-dot"></i> '+esc(e.district)+'</span><span><i class="fa-solid fa-calendar"></i> '+fmtDate(e.event_date)+'</span><span><i class="fa-solid fa-user"></i> '+esc(e.team_lead||'\u2014')+'</span></div>'+(e.partners?'<p style="font-size:.85rem;color:var(--reg-muted);margin-top:6px">Partners: '+esc(e.partners)+'</p>':'')+'</div>';});h+='</div>';}else h+='<div class="empty-state"><p>No events created yet.</p></div>';
    h+='</div>';mainContent.innerHTML=h;}catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  window.__regNewEvent = function() {
    mainContent.innerHTML='<div class="section-header"><h2 style="margin:0">Create Outreach Event</h2></div>'
      +'<div class="reg-card"><div id="evtAlert"></div><form id="eventForm"><div class="form-grid">'
      +'<div class="form-group"><label>Event Name <span class="required">*</span></label><input name="event_name" required placeholder="e.g. Gulu Community Screening"></div>'
      +'<div class="form-group"><label>District <span class="required">*</span></label><select name="district" required><option value="">Select...</option><option>Gulu</option><option>Amuru</option><option>Kitgum</option><option>Lira</option><option>Arua</option><option>Adjumani</option></select></div>'
      +'<div class="form-group"><label>Location</label><input name="location" placeholder="e.g. Gulu Main Stadium"></div>'
      +'<div class="form-group"><label>Event Date <span class="required">*</span></label><input name="event_date" type="date" value="'+new Date().toISOString().split('T')[0]+'" required></div>'
      +'<div class="form-group"><label>Team Lead</label><input name="team_lead"></div>'
      +'<div class="form-group"><label>Partners</label><input name="partners" placeholder="e.g. UNICEF, WHO"></div>'
      +'<div class="form-group full"><label>Description</label><textarea name="description" rows="2"></textarea></div>'
      +'</div><div class="form-actions"><button type="submit" class="btn-primary">Create Event</button><button type="button" class="btn-secondary" onclick="window.__regNav(\'events\')">Cancel</button></div></form></div>';
    document.getElementById('eventForm').addEventListener('submit',async e=>{e.preventDefault();try{const payload=Object.fromEntries(new FormData(e.target).entries());await api('/registry/events',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});document.getElementById('evtAlert').innerHTML='<div class="reg-alert reg-alert-success">Event created!</div>';e.target.reset();setTimeout(()=>window.__regNav('events'),1000);}catch(err){document.getElementById('evtAlert').innerHTML='<div class="reg-alert reg-alert-error">'+esc(err.message)+'</div>';}});
  };
  async function renderPendingReview() {
    mainContent.innerHTML='<div class="reg-loading">Loading pending reviews...</div>';
    try{const res=await api('/registry/screenings/pending-review');const items=res.data;
    let h='<div class="section-header"><h2 style="margin:0">Pending Screening Reviews</h2><button class="btn-secondary" onclick="window.__regNav(\'dashboard\')"><i class="fa-solid fa-arrow-left"></i> Back</button></div><div class="reg-card">';
    if(items.length){h+='<div class="table-wrap"><table class="data-table"><thead><tr><th>TSCA ID</th><th>Name</th><th>Date</th><th>Test</th><th>Result</th><th>Site</th><th>Action</th></tr></thead><tbody>';
    items.forEach(s=>{h+='<tr><td>'+esc(s.tsca_id)+'</td><td>'+esc(s.first_name)+' '+esc(s.last_name)+'</td><td>'+fmtDate(s.screening_date)+'</td><td>'+esc(s.test_type)+'</td><td>'+rBadge(s.result)+'</td><td>'+esc(s.screening_site||'\u2014')+'</td><td><button class="btn-secondary" style="padding:6px 10px;font-size:.8rem" onclick="window.__regReviewScreening('+s.id+',\'VALIDATED\')"><i class="fa-solid fa-check"></i> Approve</button> <button class="btn-secondary" style="padding:6px 10px;font-size:.8rem;color:#dc2626" onclick="window.__regReviewScreening('+s.id+',\'REJECTED\')"><i class="fa-solid fa-xmark"></i> Reject</button></td></tr>';});
    h+='</tbody></table></div>';}else h+='<div class="empty-state"><p>No screenings pending review.</p></div>';
    h+='</div>';mainContent.innerHTML=h;}catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  window.__regReviewScreening = async function(id,status) {
    try{await api('/registry/screenings/'+id+'/review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({review_status:status})});renderPendingReview();}catch(e){alert('Review failed: '+e.message);}
  };
  async function renderAdminUsers() {
    mainContent.innerHTML='<div class="reg-loading">Loading users...</div>';
    try{const res=await api('/registry/users');const users=res.data;
    let h='<div class="section-header"><h2 style="margin:0">User Management</h2><button class="btn-primary" onclick="window.__regNewUser()"><i class="fa-solid fa-user-plus"></i> Add User</button></div>'
      +'<div class="reg-card"><div class="table-wrap"><table class="data-table"><thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead><tbody>';
    users.forEach(u=>{h+='<tr><td><strong>'+esc(u.username)+'</strong></td><td>'+esc(u.full_name)+'</td><td>'+roleBadge(u.role)+'</td><td><span class="badge badge-'+(u.status==='ACTIVE'?'active':'inactive')+'">'+esc(u.status)+'</span></td><td>'+(u.last_login_at?fmtDate(u.last_login_at):'Never')+'</td><td>'+(u.role!=='ADMINISTRATOR'?'<button class="btn-secondary" style="padding:5px 8px;font-size:.78rem" onclick="window.__regToggleUser('+u.id+',\''+u.status+'\')">'+(u.status==='ACTIVE'?'Disable':'Enable')+'</button> ':'')+(u.role!=='ADMINISTRATOR'?'<button class="btn-secondary" style="padding:5px 8px;font-size:.78rem;color:#dc2626" onclick="window.__regDeleteUser('+u.id+')">Delete</button>':'')+'</td></tr>';});
    h+='</tbody></table></div></div>';mainContent.innerHTML=h;}catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  window.__regNewUser = function() {
    mainContent.innerHTML='<div class="section-header"><h2 style="margin:0">Create User</h2></div>'
      +'<div class="reg-card"><div id="userAlert"></div><form id="userForm"><div class="form-grid">'
      +'<div class="form-group"><label>Username <span class="required">*</span></label><input name="username" required></div>'
      +'<div class="form-group"><label>Full Name <span class="required">*</span></label><input name="full_name" required></div>'
      +'<div class="form-group"><label>Password <span class="required">*</span></label><div class="pw-wrap"><input name="password" type="password" required minlength="6"><button type="button" class="pw-toggle" onclick="togglePw(this.previousElementSibling,this)"><i class="fa-solid fa-eye"></i></button></div></div>'
      +'<div class="form-group"><label>Role <span class="required">*</span></label><select name="role" required><option value="">Select...</option><option value="DATA_ENTRY">Data Entry</option><option value="SUPERVISOR">Supervisor</option><option value="ADMINISTRATOR">Administrator</option></select></div>'
      +'</div><div class="form-actions"><button type="submit" class="btn-primary">Create User</button><button type="button" class="btn-secondary" onclick="window.__regNav(\'admin-users\')">Cancel</button></div></form></div>';
    document.getElementById('userForm').addEventListener('submit',async e=>{e.preventDefault();try{const payload=Object.fromEntries(new FormData(e.target).entries());await api('/registry/users',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});document.getElementById('userAlert').innerHTML='<div class="reg-alert reg-alert-success">User created!</div>';setTimeout(()=>window.__regNav('admin-users'),1000);}catch(err){document.getElementById('userAlert').innerHTML='<div class="reg-alert reg-alert-error">'+esc(err.message)+'</div>';}});
  };
  window.__regToggleUser = async function(id,status) {
    try{const action=status==='ACTIVE'?'disable':'enable';await api('/registry/users/'+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:id})});renderAdminUsers();}catch(e){alert('Failed: '+e.message);}
  };
  window.__regDeleteUser = async function(id) {
    if(!confirm('Delete this user?'))return;
    try{await api('/registry/users/'+id,{method:'DELETE'});renderAdminUsers();}catch(e){alert('Failed: '+e.message);}
  };
  async function renderRegistrations(page) {
    page=page||1; mainContent.innerHTML='<div class="reg-loading">Loading registrations...</div>';
    try{const q=document.getElementById('regSearchInput')?.value||'';const t=document.getElementById('regFilterType')?.value||'';
    const params=new URLSearchParams({page:String(page),limit:'15'});if(q)params.set('search',q);if(t)params.set('subscription_type',t);
    const res=await api('/registry?'+params);const items=res.data.items,pg=res.data.pagination;
    let h='<div class="section-header"><h2 style="margin:0">Public Registrations</h2><button class="btn-secondary" onclick="window.__regNav(\'dashboard\')"><i class="fa-solid fa-arrow-left"></i> Back</button></div>'
      +'<div class="reg-card"><div class="toolbar"><input id="regSearchInput" placeholder="Search name, email, registry number..." value="'+esc(q)+'">'
      +'<select id="regFilterType"><option value="">All Types</option><option value="newsletter"'+(t==='newsletter'?' selected':'')+'>Newsletter</option><option value="volunteer"'+(t==='volunteer'?' selected':'')+'>Volunteer</option><option value="member"'+(t==='member'?' selected':'')+'>Member</option></select>'
      +'<button onclick="window.__regRegistrations(1)">Search</button></div>';
    h+='<div class="table-wrap"><table class="data-table"><thead><tr><th>Registry #</th><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Status</th><th>Date</th></tr></thead><tbody>';
    items.forEach(r=>{h+='<tr><td><strong>'+esc(r.registry_number)+'</strong></td><td>'+esc(r.full_name)+'</td><td>'+esc(r.email)+'</td><td>'+esc(r.phone||'\u2014')+'</td><td><span class="badge badge-'+esc(r.subscription_type)+'">'+esc(r.subscription_type)+'</span></td><td><span class="badge badge-'+(r.status==='active'?'active':'inactive')+'">'+esc(r.status)+'</span></td><td>'+fmtDate(r.created_at)+'</td></tr>';});
    h+='</tbody></table></div><div class="mobile-cards">';
    items.forEach(r=>{h+='<div class="record-card"><div class="card-header"><div><span class="card-id">'+esc(r.registry_number)+'</span><div class="card-name">'+esc(r.full_name)+'</div></div><span class="badge badge-'+esc(r.subscription_type)+'">'+esc(r.subscription_type)+'</span></div><div class="card-meta"><span><i class="fa-solid fa-envelope"></i> '+esc(r.email)+'</span><span><i class="fa-solid fa-phone"></i> '+esc(r.phone||'\u2014')+'</span><span><i class="fa-solid fa-calendar"></i> '+fmtDate(r.created_at)+'</span></div></div>';});
    if(!items.length)h+='<div class="empty-state"><p>No registrations found.</p></div>';h+='</div>';
    if(pg.pages>1)h+='<div class="pagination"><button '+(pg.page<=1?'disabled':'')+' onclick="window.__regRegistrations('+(pg.page-1)+')">Prev</button><span>Page '+pg.page+' of '+pg.pages+' ('+pg.total+' total)</span><button '+(pg.page>=pg.pages?'disabled':'')+' onclick="window.__regRegistrations('+(pg.page+1)+')">Next</button></div>';
    h+='</div>';mainContent.innerHTML=h;
    document.getElementById('regSearchInput')?.addEventListener('keydown',e=>{if(e.key==='Enter')window.__regRegistrations(1);});
    document.getElementById('regFilterType')?.addEventListener('change',()=>window.__regRegistrations(1));
    mainContent.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.nav)));
    }catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  window.__regRegistrations = renderRegistrations;
  async function renderReports() {
    mainContent.innerHTML='<div class="reg-loading">Loading reports...</div>';
    try{const[sumRes,distRes,demoRes,refRes]=await Promise.all([api('/registry/reports/summary'),api('/registry/reports/results'),api('/registry/reports/demographics'),api('/registry/reports/referrals')]);
    const sum=sumRes.data,dist=distRes.data,demo=demoRes.data,ref=refRes.data;
    let h='<div class="section-header"><h2 style="margin:0">Reports</h2><div><a href="'+API+'/registry/export/participants" target="_blank" class="btn-secondary" style="text-decoration:none;padding:8px 14px;font-size:.85rem;margin-right:8px"><i class="fa-solid fa-download"></i> Export Participants CSV</a><a href="'+API+'/registry/export/screenings" target="_blank" class="btn-secondary" style="text-decoration:none;padding:8px 14px;font-size:.85rem"><i class="fa-solid fa-download"></i> Export Screenings CSV</a></div></div>';
    h+='<div class="reg-card"><h2>Summary</h2><div class="stat-grid"><div class="stat-box"><strong>'+sum.participants.total+'</strong><span>Participants</span></div><div class="stat-box"><strong>'+sum.screenings.total+'</strong><span>Screenings</span></div><div class="stat-box"><strong>'+sum.screenings.today+'</strong><span>Screened Today</span></div><div class="stat-box"><strong>'+sum.follow_ups.total+'</strong><span>Follow-ups</span></div><div class="stat-box"><strong>'+sum.follow_ups.referrals+'</strong><span>Referrals</span></div><div class="stat-box"><strong>'+sum.events.total+'</strong><span>Events</span></div></div></div>';
    if(dist.length){h+='<div class="reg-card"><h2>Result Distribution</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Result</th><th>Count</th></tr></thead><tbody>';dist.forEach(d=>{h+='<tr><td>'+rBadge(d.result)+'</td><td><strong>'+d.count+'</strong></td></tr>';});h+='</tbody></table></div></div>';}
    if(demo.gender?.length){h+='<div class="reg-card"><h2>Demographics</h2><h3>Gender</h3><div class="stat-grid">';demo.gender.forEach(g=>{h+='<div class="stat-box"><strong>'+g.count+'</strong><span>'+g.gender+'</span></div>';});h+='</div>';if(demo.districts?.length){h+='<h3 style="margin-top:16px">By District</h3><div class="stat-grid">';demo.districts.forEach(d=>{h+='<div class="stat-box"><strong>'+d.count+'</strong><span>'+esc(d.district||'\u2014')+'</span></div>';});h+='</div>';}h+='</div>';}
    if(ref.referrals?.length){h+='<div class="reg-card"><h2>Referrals</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>TSCA ID</th><th>Name</th><th>Date</th><th>Facility</th><th>Status</th></tr></thead><tbody>';ref.referrals.forEach(r=>{h+='<tr><td>'+esc(r.tsca_id)+'</td><td>'+esc(r.first_name)+' '+esc(r.last_name)+'</td><td>'+fmtDate(r.follow_up_date)+'</td><td>'+esc(r.referral_facility||'\u2014')+'</td><td>'+sBadge(r.follow_up_status)+'</td></tr>';});h+='</tbody></table></div></div>';}
    mainContent.innerHTML=h;}catch(e){mainContent.innerHTML='<div class="reg-alert reg-alert-error">'+esc(e.message)+'</div>';}
  }
  if (authToken && currentUser) {
    api('/registry/auth/me').then(()=>showApp()).catch(()=>{localStorage.removeItem('tscaToken');localStorage.removeItem('tscaUser');authToken='';currentUser=null;});
  }
})();
