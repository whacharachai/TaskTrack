/* TaskTrack - vanilla JS, no dependencies */
'use strict';

const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const today = () => new Date().toISOString().slice(0, 10);
const n2 = n => (Math.round(Number(n) * 100) / 100).toLocaleString();

let ME = null, TASKS = [], USERS = [], DETAIL = null, ENTRIES = [], CAL = new Date();

async function api(path, opt = {}) {
  const o = { method: opt.method || 'GET', credentials: 'same-origin', headers: {} };
  if (opt.form) o.body = opt.form;
  else if (opt.body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opt.body); }
  const r = await fetch('api/' + path, o);
  if (r.status === 401) { location.href = 'index.html'; throw new Error('expired'); }
  const ct = r.headers.get('content-type') || '';
  if (!ct.includes('json')) { if (!r.ok) throw new Error(r.statusText); return null; }
  const d = await r.json();
  if (!r.ok || d.error) throw new Error(d.error || 'request failed');
  return d;
}
const guard = fn => (...a) => Promise.resolve(fn(...a)).catch(e => alert(e.message));

/* ---------- shell ---------- */
function show(view) {
  document.querySelectorAll('main section').forEach(s => s.classList.toggle('hidden', s.id !== 'view-' + view));
  document.querySelectorAll('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.view === view));
  if (view === 'calendar') guard(loadCalendar)();
  if (view === 'timeline') renderTimeline();
  if (view === 'report') guard(renderReport)();
  if (view === 'users') renderUsers();
}

async function boot() {
  try {
    const d = await api('auth.php');
    ME = d.user;
  } catch { return; }
  $('#who').textContent = `${ME.username} (${ME.role})`;
  document.querySelectorAll('.admin-only').forEach(el => el.classList.toggle('hidden', !ME.admin));
  $('#logout').onclick = guard(async () => { await api('auth.php', { method: 'DELETE' }); location.href = 'index.html'; });
  document.querySelectorAll('#tabs button').forEach(b => b.onclick = () => show(b.dataset.view));
  $('#detail-close').onclick = () => { DETAIL = null; $('#task-detail').classList.add('hidden'); };
  $('#task-reset').onclick = () => resetTaskForm();
  $('#task-form').onsubmit = guard(saveTask);
  $('#progress-form').onsubmit = guard(saveProgress);
  $('#file-form').onsubmit = guard(uploadFiles);
  $('#user-form').onsubmit = guard(saveUser);
  $('#cal-prev').onclick = () => { CAL.setMonth(CAL.getMonth() - 1); guard(loadCalendar)(); };
  $('#cal-next').onclick = () => { CAL.setMonth(CAL.getMonth() + 1); guard(loadCalendar)(); };
  if (ME.admin) { USERS = await api('users.php'); fillUserSelect(); renderUsers(); }
  await reload();
}

async function reload() {
  TASKS = await api('tasks.php');
  fillParentSelect();
  renderTasks(); renderTimeline(); guard(renderReport)();
}

/* ---------- tasks ---------- */
function depth(t) { let d = 0, cur = t; while (cur && cur.parent_id && d < 20) { cur = TASKS.find(x => x.id === cur.parent_id); d++; } return d; }
const ordered = () => [...TASKS].sort((a, b) => depth(a) - depth(b) || (a.start_date || '9999').localeCompare(b.start_date || '9999') || a.id - b.id);

function fillParentSelect() {
  const sel = $('#parent-select'), cur = sel.value;
  sel.innerHTML = '<option value="">— none (top task) —</option>' +
    ordered().filter(t => !DETAIL || t.id !== DETAIL.id).map(t => `<option value="${t.id}">${'— '.repeat(depth(t))}${esc(t.name)}</option>`).join('');
  sel.value = cur;
}

function fillUserSelect() {
  $('#user-select').innerHTML = USERS.map(u => `<option value="${u.id}">${esc(u.username)} (${u.role})</option>`).join('');
}

function renderTasks() {
  const rows = ordered().map(t => `<tr data-id="${t.id}">
    <td style="padding-left:${8 + depth(t) * 16}px">${esc(t.name)}${t.roles.includes('user') ? '' : ' <span class="tag">admin only</span>'}</td>
    <td>${t.start_date || '—'} → ${t.end_date || '—'}</td>
    <td class="num">${n2(t.done_amount)} / ${n2(t.total_amount)} ${esc(t.unit)}</td>
    <td style="min-width:120px"><div class="bar"><i style="width:${t.percent}%"></i></div></td>
    <td class="num">${t.percent}%</td>
    <td>${t.file_count ? '📎' + t.file_count : ''} ${t.children.length ? '⚑' + t.children.length : ''}</td>
    <td class="num">${ME.admin ? `<button data-edit="${t.id}" class="link">edit</button> <button data-del="${t.id}" class="link">del</button>` : ''}</td>
  </tr>`).join('');
  $('#task-list').innerHTML = `<table><thead><tr><th>Task</th><th>Period</th><th>Done / Total</th><th></th><th>%</th><th></th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  $('#task-list').onclick = guard(async e => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const id = +tr.dataset.id;
    if (e.target.dataset.del) { if (!confirm('Delete task and its sub-tasks?')) return; await api('tasks.php?id=' + id, { method: 'DELETE' }); DETAIL = null; $('#task-detail').classList.add('hidden'); await reload(); return; }
    if (e.target.dataset.edit) { if (!ME.admin) return; await openTask(id, true); return; }
    await openTask(id);
  });
}

function resetTaskForm() {
  const f = $('#task-form');
  f.reset();
  f.elements.id.value = '';
  f.querySelectorAll('[name=roles]').forEach(c => c.checked = c.value === 'user');
  $('#task-form-title').textContent = 'New task';
  $('#task-msg').textContent = '';
  fillParentSelect();
}

async function openTask(id, edit) {
  DETAIL = await api('tasks.php?id=' + id);
  if (edit) {
    const f = $('#task-form');
    f.elements.id.value = DETAIL.id;
    f.elements.name.value = DETAIL.name;
    f.elements.start_date.value = DETAIL.start_date || '';
    f.elements.end_date.value = DETAIL.end_date || '';
    f.elements.total_amount.value = DETAIL.total_amount;
    f.elements.unit.value = DETAIL.unit || '';
    f.elements.remark.value = DETAIL.remark || '';
    f.elements.status.value = DETAIL.status;
    f.elements.parent_id.value = DETAIL.parent_id || '';
    f.querySelectorAll('[name=roles]').forEach(c => c.checked = DETAIL.roles.includes(c.value));
    [...$('#user-select').options].forEach(o => o.selected = !!DETAIL.users[o.value]);
    $('#task-form-title').textContent = 'Edit task #' + DETAIL.id;
    fillParentSelect();
    f.elements.parent_id.value = DETAIL.parent_id || '';
    $('#task-form').scrollIntoView({ behavior: 'smooth' });
  }
  $('#task-detail').classList.remove('hidden');
  $('#progress-form').elements.task_id.value = DETAIL.id;
  $('#progress-form').elements.done_date.value = today();
  $('#file-form').elements.task_id.value = DETAIL.id;
  const files = DETAIL.files.map(f => `<a href="api/files.php?download=${f.id}">${esc(f.filename)}</a> <span class="hint">${(f.size / 1024).toFixed(0)} KB</span> <button data-fdel="${f.id}" class="link">x</button>`).join(' <br>');
  $('#detail-files').innerHTML = files || '<span class="hint">no attachments</span>';
  $('#detail-files').onclick = guard(async e => {
    const id = e.target.dataset.fdel;
    if (!id) return;
    if (!confirm('Delete attachment?')) return;
    await api('files.php?id=' + id, { method: 'DELETE' });
    DETAIL = await api('tasks.php?id=' + DETAIL.id);
    await reload(); await openTask(DETAIL.id);
  });
  renderDetail();
}

function renderDetail() {
  const t = DETAIL;
  const kids = t.children.map(id => TASKS.find(x => x.id === id)).filter(Boolean);
  const sub = kids.length ? `<p class="hint">includes sub-tasks: ${kids.map(k => esc(k.name)).join(', ')}</p>` : '';
  $('#detail-body').innerHTML = `
    <h2>${esc(t.name)} ${sub}</h2>
    <p>${t.start_date || '—'} → ${t.end_date || '—'} · ${n2(t.done_amount)} / ${n2(t.total_amount)} ${esc(t.unit)} · <b>${t.percent}%</b>
       ${ME.admin ? `<button id="detail-edit" class="link">edit</button>` : ''}</p>
    <p class="hint">own progress ${n2(t.own_done)} ${esc(t.unit)} · visible to ${t.roles.join(', ')}${Object.keys(t.users).length ? ' + ' + Object.values(t.users).map(u => esc(u.username)).join(', ') : ''}</p>
    ${t.remark ? `<p>${esc(t.remark)}</p>` : ''}
    <table><thead><tr><th>Date</th><th>Done</th><th>Remark</th><th>By</th><th></th></tr></thead><tbody>
    ${t.progress.map(g => `<tr><td>${g.done_date}</td><td class="num">${n2(g.done_amount)} ${esc(t.unit)}</td><td>${esc(g.remark)}</td><td>${esc(g.username || '')}</td>
      <td class="num">${ME.admin || g.user_id === ME.id ? `<button data-gdel="${g.id}" class="link">del</button>` : ''}</td></tr>`).join('')}
    </tbody></table>`;
  $('#detail-body').onclick = guard(async e => {
    if (e.target.id === 'detail-edit') await openTask(t.id, true);
    const gid = e.target.dataset.gdel;
    if (gid) {
      if (!confirm('Delete progress entry?')) return;
      await api('progress.php?id=' + gid, { method: 'DELETE' });
      DETAIL = await api('tasks.php?id=' + t.id);
      await reload(); renderDetail();
    }
  });
}

async function saveTask(e) {
  e.preventDefault();
  const f = e.target, b = {};
  ['id', 'name', 'start_date', 'end_date', 'total_amount', 'unit', 'remark', 'status'].forEach(k => b[k] = f.elements[k].value);
  b.parent_id = f.elements.parent_id.value;
  b.roles = [...f.querySelectorAll('[name=roles]:checked')].map(c => c.value);
  b.users = [...$('#user-select').selectedOptions].map(o => +o.value);
  await api('tasks.php', { method: b.id ? 'PUT' : 'POST', body: b });
  resetTaskForm();
  await reload();
}

async function saveProgress(e) {
  e.preventDefault();
  const f = e.target;
  await api('progress.php', { method: 'POST', body: { task_id: f.elements.task_id.value, done_amount: f.elements.done_amount.value, done_date: f.elements.done_date.value, remark: f.elements.remark.value } });
  f.elements.remark.value = '';
  DETAIL = await api('tasks.php?id=' + f.elements.task_id.value);
  await reload(); renderDetail();
  if (!$('#view-calendar').classList.contains('hidden')) guard(loadCalendar)();
  if (!$('#view-report').classList.contains('hidden')) guard(renderReport)();
}

async function uploadFiles(e) {
  e.preventDefault();
  const f = e.target, input = f.querySelector('input[type=file]');
  if (!input.files.length) return alert('choose a file first');
  const fd = new FormData();
  fd.append('task_id', f.elements.task_id.value);
  [...input.files].forEach(file => fd.append('files[]', file));
  await api('files.php', { method: 'POST', form: fd });
  f.reset();
  await openTask(+f.elements.task_id.value);
  await reload();
}

/* ---------- timeline ---------- */
function renderTimeline() {
  const withDates = TASKS.filter(t => t.start_date || t.end_date);
  if (!withDates.length) { $('#timeline').innerHTML = '<p class="hint">no dated tasks</p>'; return; }
  const day = 86400000;
  const dates = withDates.flatMap(t => [t.start_date, t.end_date]).filter(Boolean).map(d => new Date(d + 'T00:00:00').getTime());
  const min = Math.min(...dates) - 3 * day, max = Math.max(...dates) + 3 * day;
  const todayPos = Math.max(0, Math.min(100, (Date.now() - min) / (max - min) * 100));
  const row = t => {
    const a = t.start_date ? new Date(t.start_date + 'T00:00:00').getTime() : min;
    const b = t.end_date ? new Date(t.end_date + 'T00:00:00').getTime() : a;
    const l = (a - min) / (max - min) * 100, w = Math.max(1.2, (b - a) / (max - min) * 100);
    return `<div class="tl-row" data-id="${t.id}"><div class="tl-name" style="padding-left:${depth(t) * 14}px">${esc(t.name)}</div>
      <div class="tl-track"><div class="tl-bar${t.percent >= 100 ? ' full' : ''}" style="left:${l}%;width:${w}%" title="${t.percent}%"><i style="width:${t.percent}%"></i></div></div></div>`;
  };
  const marks = [];
  for (let d = new Date(min); d.getTime() <= max; d.setDate(d.getDate() + Math.ceil((max - min) / 6 / day))) {
    marks.push(`<div class="tl-mark" style="left:${(d - min) / (max - min) * 100}%">${d.toISOString().slice(5, 10)}</div>`);
  }
  $('#timeline').innerHTML = `<div class="tl-legend"><span class="tl-name"></span><div class="tl-track">${marks.join('')}<div class="tl-today" style="left:${todayPos}%"></div></div></div>` +
    ordered().filter(t => t.start_date || t.end_date).map(row).join('');
  $('#timeline').onclick = e => { const r = e.target.closest('.tl-row'); if (r) guard(() => openTask(+r.dataset.id))(); };
}

/* ---------- calendar ---------- */
async function loadCalendar() {
  // ponytail: one detail request per task, fine for tens of tasks
  ENTRIES = [];
  const details = await Promise.all(TASKS.map(t => api('tasks.php?id=' + t.id).catch(() => null)));
  details.forEach((d, i) => (d ? d.progress : []).forEach(g => ENTRIES.push({ ...g, task: TASKS[i] })));
  const y = CAL.getFullYear(), m = CAL.getMonth();
  $('#cal-title').textContent = CAL.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  const first = new Date(y, m, 1), startDow = (first.getDay() + 6) % 7;
  const days = new Date(y, m + 1, 0).getDate();
  const byDay = {};
  ENTRIES.forEach(e => (byDay[e.done_date] = byDay[e.done_date] || []).push(e));
  let cells = '';
  for (let i = 0; i < startDow; i++) cells += '<div class="cal-cell pad"></div>';
  for (let d = 1; d <= days; d++) {
    const ds = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const list = byDay[ds] || [];
    cells += `<div class="cal-cell${ds === today() ? ' now' : ''}" data-date="${ds}"><b>${d}</b>${list.map(e =>
      `<div class="cal-ev" data-tid="${e.task_id}" title="${esc(e.task.name)}: ${n2(e.done_amount)} ${esc(e.task.unit)} — ${esc(e.remark || '')}">${esc(e.task.name.slice(0, 14))} ${n2(e.done_amount)}</div>`).join('')}</div>`;
  }
  $('#calendar').innerHTML = `<div class="cal-grid">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(d => `<div class="cal-h">${d}</div>`).join('')}${cells}</div>`;
  $('#calendar').onclick = guard(async e => {
    const ev = e.target.closest('.cal-ev');
    if (ev) return void (await openTask(+ev.dataset.tid));
    const c = e.target.closest('.cal-cell[data-date]');
    if (!c || !TASKS.length) return;
    await openTask(TASKS[0].id);
    $('#progress-form').elements.done_date.value = c.dataset.date;
    $('#progress-form').scrollIntoView({ behavior: 'smooth' });
    $('#progress-form').elements.done_amount.focus();
  });
}

/* ---------- report ---------- */
async function renderReport() {
  const details = await Promise.all(TASKS.map(t => api('tasks.php?id=' + t.id).catch(() => null)));
  $('#report-table').innerHTML = `<table><thead><tr><th>Task</th><th>Period</th><th>Total</th><th>Done</th><th>%</th><th>Entries</th><th>Files</th></tr></thead><tbody>
    ${ordered().map(t => `<tr><td style="padding-left:${8 + depth(t) * 14}px">${esc(t.name)}</td><td>${t.start_date || '—'} → ${t.end_date || '—'}</td>
    <td class="num">${n2(t.total_amount)} ${esc(t.unit)}</td><td class="num">${n2(t.done_amount)}</td><td class="num">${t.percent}%</td>
    <td class="num">${t.progress_count}</td><td class="num">${t.file_count}</td></tr>`).join('')}</tbody></table>`;
  lineChart(details.filter(Boolean));
  $('#bars').innerHTML = ordered().map(t => `<div class="bar-row"><span>${esc(t.name)}</span><div class="bar"><i style="width:${t.percent}%"></i></div><b>${t.percent}%</b></div>`).join('');
}

// hand-rolled SVG line chart: one line per task, x = progress date, y = cumulative % of that task
function lineChart(details) {
  const W = 900, H = 320, P = 45, w = W - P * 2, h = H - P * 2;
  const series = details.map(d => {
    const pts = d.progress.map(g => ({ t: new Date(g.done_date + 'T00:00:00').getTime(), p: d.total_amount > 0 ? Math.min(100, g.done_amount / d.total_amount * 100) : 0 }));
    return { name: d.name, pts };
  }).filter(s => s.pts.length);
  if (!series.length) { $('#chart').innerHTML = '<p class="hint">no progress reported yet</p>'; return; }
  const t0 = Math.min(...series.flatMap(s => s.pts.map(p => p.t))), t1 = Math.max(...series.flatMap(s => s.pts.map(p => p.t)));
  const span = Math.max(86400000, t1 - t0);
  const X = t => P + (t - t0) / span * w, Y = p => P + h - p / 100 * h;
  const colors = ['#2f6fd0', '#d0532f', '#2f9d55', '#8b3fd0', '#c9a227', '#d02f7a', '#3aa8c1', '#666'];
  let grid = '';
  for (let v = 0; v <= 100; v += 25) grid += `<line x1="${P}" y1="${Y(v)}" x2="${P + w}" y2="${Y(v)}" stroke="#e2e6ec"/><text x="${P - 8}" y="${Y(v) + 4}" text-anchor="end" class="ax">${v}%</text>`;
  const nowX = Date.now() >= t0 && Date.now() <= t0 + span ? `<line x1="${X(Date.now())}" y1="${P}" x2="${X(Date.now())}" y2="${P + h}" stroke="#c0392b" stroke-dasharray="4 3"/><text x="${X(Date.now())}" y="${P - 6}" class="ax" text-anchor="middle">today</text>` : '';
  const lines = series.map((s, i) => `<polyline fill="none" stroke="${colors[i % colors.length]}" stroke-width="2" points="${s.pts.map(p => `${X(p.t)},${Y(p.p)}`).join(' ')}"/>${s.pts.map(p => `<circle cx="${X(p.t)}" cy="${Y(p.p)}" r="3" fill="${colors[i % colors.length]}"><title>${esc(s.name)} ${new Date(p.t).toISOString().slice(0, 10)}: ${p.p.toFixed(1)}%</title></circle>`).join('')}`).join('');
  $('#chart').innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="chart">${grid}${nowX}${lines}
    <text x="${P}" y="${P + h + 22}" class="ax">${new Date(t0).toISOString().slice(0, 10)}</text>
    <text x="${P + w}" y="${P + h + 22}" class="ax" text-anchor="end">${new Date(t0 + span).toISOString().slice(0, 10)}</text></svg>
    <div class="legend">${series.map((s, i) => `<span><i style="background:${colors[i % colors.length]}"></i>${esc(s.name)}</span>`).join('')}</div>`;
}

/* ---------- users ---------- */
async function saveUser(e) {
  e.preventDefault();
  const f = e.target, b = { username: f.elements.username.value, password: f.elements.password.value, role: f.elements.role.value };
  if (!b.password) return alert('password required for a new user');
  await api('users.php', { method: 'POST', body: b });
  f.reset();
  USERS = await api('users.php');
  fillUserSelect();
  renderUsers();
}

function renderUsers() {
  $('#user-list').innerHTML = `<table><thead><tr><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead><tbody>
    ${USERS.map(u => `<tr><td>${esc(u.username)}</td><td><select data-uid="${u.id}"><option value="user"${u.role === 'user' ? ' selected' : ''}>user</option><option value="admin"${u.role === 'admin' ? ' selected' : ''}>admin</option></select></td>
    <td>${u.created_at || ''}</td><td class="num"><button data-pwd="${u.id}" class="link">set password</button> <button data-udel="${u.id}" class="link">del</button></td></tr>`).join('')}
    </tbody></table>`;
  $('#user-list').onclick = guard(async e => {
    const pid = e.target.dataset.pwd, did = e.target.dataset.udel;
    if (pid) {
      const pw = prompt('New password');
      if (!pw) return;
      await api('users.php', { method: 'PUT', body: { id: +pid, password: pw } });
    } else if (did) {
      if (!confirm('Delete user?')) return;
      await api('users.php?id=' + did, { method: 'DELETE' });
    } else return;
    USERS = await api('users.php'); fillUserSelect(); renderUsers();
  });
  $('#user-list').onchange = guard(async e => {
    const id = e.target.dataset.uid;
    if (!id) return;
    await api('users.php', { method: 'PUT', body: { id: +id, role: e.target.value } });
    USERS = await api('users.php'); renderUsers();
  });
}

boot();