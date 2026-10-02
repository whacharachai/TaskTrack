/* TaskTrack - vanilla JS, no dependencies */
'use strict';

const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const today = () => new Date().toISOString().slice(0, 10);
const n2 = n => (Math.round(Number(n) * 100) / 100).toLocaleString();
const bytes = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';

let ME = null, TASKS = [], USERS = [], PROJECTS = [], PROJECT = null;
let DETAIL = null, ENTRIES = [], CAL = new Date(), MESSAGES = [], LAST_ID = 0, CHAT_TIMER = null;
const CHUNK = 2 * 1024 * 1024; // 2 MB keeps each request inside the default PHP post_max_size

async function api(path, opt = {}) {
  const o = { method: opt.method || 'GET', credentials: 'same-origin', headers: {} };
  if (opt.form) o.body = opt.form;
  else if (opt.raw) { o.body = opt.raw;
    o.headers['Content-Type'] = 'application/octet-stream';
    for (const [k, v] of Object.entries(opt.headers || {})) o.headers[k] = v; }
  else if (opt.body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opt.body); }
  const r = await fetch('api/' + path, o);
  if (r.status === 401) { location.href = 'index.html'; throw new Error('expired'); }
  const ct = r.headers.get('content-type') || '';
  // a PHP fatal answers with an HTML page or nothing at all, so show that text instead of
  // letting response.json() die with "Unexpected end of JSON input"
  if (!ct.includes('json')) {
    const raw = (await r.text().catch(() => '')).trim();
    if (!r.ok) throw new Error(`${r.status} ${r.statusText}${raw ? ' — ' + raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) : ''}`);
    return null;
  }
  const text = await r.text();
  let d;
  try { d = JSON.parse(text); } catch { throw new Error(`${r.status} ${r.statusText} — bad JSON from ${path}: ${text.slice(0, 200)}`); }
  if (!r.ok || d.error) throw new Error(d.error || 'request failed');
  return d;
}
const guard = fn => (...a) => Promise.resolve(fn(...a)).catch(e => alert(e.message));

/* ================= chunked upload ================= */
// start -> send slices -> finish. Resume uses the server's confirmed byte offset, so a dropped
// connection or a closed tab continues from where it stopped instead of restarting the file.
async function sendFile(file, scope, onProgress) {
  // a previous tab may have left this exact file half-sent; reuse it instead of starting over
  const pending = await api(`upload.php?action=status&${new URLSearchParams({ ...scope, _: Date.now() })}`).catch(() => []);
  const match = (pending || []).find(u => u.name === file.name && u.total_size === file.size);
  let up = match
    ? { upload_id: match.upload_id, received: match.received }
    : await api('upload.php?action=start', { method: 'POST', body: { ...scope, name: file.name, total_size: file.size } });
  let id = up.upload_id, offset = up.received;
  onProgress(offset / file.size);
  while (offset < file.size) {
    const end = Math.min(offset + CHUNK, file.size);
    const res = await api(`upload.php?action=chunk&id=${id}`, {
      method: 'POST',
      raw: file.slice(offset, end),
      headers: { 'X-Chunk-Offset': String(offset) },
    });
    if (res.retry) { offset = res.received; continue; }   // server disagreed on the offset, resync
    offset = res.received;
    onProgress(offset / file.size);
  }
  return (await api(`upload.php?action=finish&id=${id}`, { method: 'POST', body: {} })).file;
}

async function uploadFiles(files, scope, statusEl) {
  const out = [];
  const list = [...files];
  for (let i = 0; i < list.length; i++) {
    const f = list[i];
    statusEl.textContent = `uploading ${f.name} (${i + 1}/${list.length})…`;
    out.push(await sendFile(f, scope, pc => statusEl.textContent = `uploading ${f.name} — ${Math.round(pc * 100)}%`));
  }
  statusEl.textContent = '';
  return out;
}

/* ================= shell ================= */
function show(view) {
  document.querySelectorAll('main section').forEach(s => s.classList.toggle('hidden', s.id !== 'view-' + view));
  document.querySelectorAll('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.view === view));
  if (view === 'calendar') guard(loadCalendar)();
  if (view === 'timeline') renderTimeline();
  if (view === 'chat' && PROJECT) guard(loadChat)();
  if (view === 'projects') renderProjects();
  if (view === 'users') renderUsers();
  startChatPolling(view === 'chat');
}

async function boot() {
  try { ME = (await api('auth.php')).user; } catch { return; }
  $('#who').innerHTML = `<b>${esc(ME.username)}</b> · ${esc(ME.role)}`;
  $('#who').className = 'pill';
  document.querySelectorAll('.admin-only').forEach(el => el.classList.toggle('hidden', !ME.admin));
  document.querySelectorAll('.write-only').forEach(el => el.classList.toggle('hidden', !ME.can_write));
  $('#logout').onclick = guard(async () => { await api('auth.php', { method: 'DELETE' }); location.href = 'index.html'; });
  document.querySelectorAll('#tabs button').forEach(b => b.onclick = () => show(b.dataset.view));
  $('#project-select').onchange = () => setProject(+$('#project-select').value);
  $('#detail-close').onclick = () => { DETAIL = null; $('#task-detail').classList.add('hidden'); };
  $('#task-reset').onclick = () => resetTaskForm();
  $('#project-reset').onclick = () => resetProjectForm();
  $('#task-form').onsubmit = guard(saveTask);
  $('#progress-form').onsubmit = guard(saveProgress);
  $('#chat-form').onsubmit = guard(sendChat);
  $('#chat-older').onclick = guard(loadOlderChat);
  let olderPending = false;
$('#chat-list').onscroll = guard(async () => {
  const l = $('#chat-list');
  if (l.scrollTop > 40 || olderPending) return;
  if ($('#chat-older').classList.contains('hidden')) return;
  olderPending = true;
  try { await loadOlderChat(); } finally { olderPending = false; }
});
  $('#project-form').onsubmit = guard(saveProject);
  $('#user-form').onsubmit = guard(saveUser);
  $('#cal-prev').onclick = () => { CAL.setMonth(CAL.getMonth() - 1); guard(loadCalendar)(); };
  $('#cal-next').onclick = () => { CAL.setMonth(CAL.getMonth() + 1); guard(loadCalendar)(); };
  if (ME.admin) USERS = await api('users.php');
  renderUserChecks();
  await loadProjects();
  document.addEventListener('visibilitychange', () => startChatPolling(!document.hidden && !$('#view-chat').classList.contains('hidden')));
}

// one checkbox per account so the current assignment is visible at a glance
function checkedUserIds() {
  return [...$('#project-form').querySelectorAll('[name=users]:checked')].map(c => +c.value);
}
// no argument = a brand new project, which defaults to everyone except admins (admins see all anyway)
function renderUserChecks(ids) {
  const on = new Set((ids ?? USERS.filter(u => u.role !== 'admin').map(u => u.id)).map(String));
  $('#project-user-list').innerHTML = USERS.length
    ? USERS.map(u => `<label class="pick"><input type="checkbox" name="users" value="${u.id}"${on.has(String(u.id)) ? ' checked' : ''}>
        ${esc(u.username)} <span class="tag">${esc(u.role)}</span></label>`).join('')
    : '<span class="hint">no users yet — add them on the Users tab</span>';
}

async function loadProjects() {
  PROJECTS = await api('projects.php');
  const sel = $('#project-select');
  const keep = PROJECT && PROJECTS.some(p => p.id === PROJECT) ? PROJECT : (PROJECTS[0] ? PROJECTS[0].id : null);
  sel.innerHTML = PROJECTS.map(p => `<option value="${p.id}">${esc(p.name)}${p.status === 'archived' ? ' (archived)' : ''}</option>`).join('');
  if (!PROJECTS.length) sel.innerHTML = '<option value="">no project yet</option>';
  renderProjects(); // keep the Projects table in step with the selector after a save or delete
  await setProject(keep);
}

async function setProject(id) {
  PROJECT = id || null;
  $('#project-select').value = PROJECT || '';
  MESSAGES = cachedChat(PROJECT); // paint the cached page at once, then let the poll top it up
  LAST_ID = MESSAGES.reduce((n, m) => Math.max(n, m.id), 0);
  $('#chat-older').classList.toggle('hidden', !MESSAGES.length);
  if (MESSAGES.length) renderChat(); else $('#chat-list').innerHTML = '<p class="hint">select a project to open its chat room</p>';
  await reload();
  if (!$('#view-chat').classList.contains('hidden')) { await loadChat(); startChatPolling(true); }
}

async function reload() {
  // tasks live in projects, so with no project selected there is nothing to list
  TASKS = PROJECT ? await api('tasks.php?project_id=' + PROJECT) : [];
  // a plain user never sees the form, and it also needs a project before it can save anything
  $('#task-form').classList.toggle('hidden', !PROJECT || !ME.can_write);
  fillParentSelect();
  renderTasks(); renderTimeline();
}

/* ================= tasks ================= */
function depth(t) { let d = 0, cur = t; while (cur && cur.parent_id && d < 20) { cur = TASKS.find(x => x.id === cur.parent_id); d++; } return d; }
const ordered = () => [...TASKS].sort((a, b) => depth(a) - depth(b) || (a.start_date || '9999').localeCompare(b.start_date || '9999') || a.id - b.id);

function fillParentSelect() {
  const sel = $('#parent-select'), cur = sel.value;
  sel.innerHTML = '<option value="">— none (top task) —</option>' +
    ordered().filter(t => !DETAIL || t.id !== DETAIL.id).map(t => `<option value="${t.id}">${'— '.repeat(depth(t))}${esc(t.name)}</option>`).join('');
  sel.value = cur;
}

function renderTasks() {
  if (!TASKS.length) { $('#task-list').innerHTML = '<p class="hint">no tasks in this project yet</p>'; return; }
  const rows = ordered().map(t => `<tr data-id="${t.id}">
    <td style="padding-left:${8 + depth(t) * 16}px">${esc(t.name)}${t.project_id ? '' : ' <span class="tag">unassigned</span>'}</td>
    <td>${t.start_date || '—'} → ${t.end_date || '—'}</td>
    <td class="num">${n2(t.done_amount)} / ${n2(t.total_amount)} ${esc(t.unit)}</td>
    <td style="min-width:120px"><div class="bar"><i style="width:${t.percent}%"></i></div></td>
    <td class="num">${t.percent}%</td>
    <td>${t.file_count ? `<span class="chip">${t.file_count} file</span>` : ''} ${t.children.length ? `<span class="chip">${t.children.length} sub</span>` : ''}</td>
    <td class="num">${ME.can_write ? `<button data-edit="${t.id}" class="link">edit</button>` : ''} ${ME.admin ? `<button data-del="${t.id}" class="link">del</button>` : ''}</td>
  </tr>`).join('');
  $('#task-list').innerHTML = `<table><thead><tr><th>Task</th><th>Period</th><th>Done / Total</th><th></th><th>%</th><th></th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  $('#task-list').onclick = guard(async e => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const id = +tr.dataset.id;
    if (e.target.dataset.del) {
      if (!confirm('Delete task and its sub-tasks?')) return;
      await api('tasks.php?id=' + id, { method: 'DELETE' });
      DETAIL = null; $('#task-detail').classList.add('hidden');
      await reload(); return;
    }
    if (e.target.dataset.edit) { await openTask(id, true); return; }
    await openTask(id);
  });
}

function resetTaskForm() {
  const f = $('#task-form');
  f.reset();
  f.elements.id.value = '';
  $('#task-form-title').textContent = 'New task';
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
    fillParentSelect();
    f.elements.parent_id.value = DETAIL.parent_id || '';
    $('#task-form-title').textContent = 'Edit task #' + DETAIL.id;
    $('#task-form').scrollIntoView({ behavior: 'smooth' });
  }
  $('#task-detail').classList.remove('hidden');
  $('#progress-form').elements.task_id.value = DETAIL.id;
  $('#progress-form').elements.done_date.value = today();
  $('#task-files').value = '';
  $('#task-upload').textContent = '';
  renderDetail();
}

function renderDetail() {
  const t = DETAIL;
  const kids = t.children.map(id => TASKS.find(x => x.id === id)).filter(Boolean);
  const sub = kids.length ? `<p class="hint">includes sub-tasks: ${kids.map(k => esc(k.name)).join(', ')}</p>` : '';
  const files = t.files.map(f => `<a href="${f.url}" class="chip">${esc(f.name)} · ${bytes(f.size)}</a> <button data-fdel="${f.id}" class="link">x</button>`).join(' ');
  $('#detail-body').innerHTML = `
    <h2>${esc(t.name)} ${sub}</h2>
    <p>${t.start_date || '—'} → ${t.end_date || '—'} · ${n2(t.done_amount)} / ${n2(t.total_amount)} ${esc(t.unit)} · <b>${t.percent}%</b>
       ${ME.can_write ? '<button id="detail-edit" class="link">edit</button>' : ''}</p>
    <p class="hint">own entries add up to ${n2(t.own_done)} ${esc(t.unit)} · visible to everyone with access to this project</p>
    ${t.remark ? `<p>${esc(t.remark)}</p>` : ''}
    <table><thead><tr><th>Date</th><th>Done that day</th><th>Remark</th><th>By</th><th></th></tr></thead><tbody>
    ${t.progress.map(g => `<tr><td>${g.done_date}</td><td class="num">${n2(g.done_amount)} ${esc(t.unit)}</td><td>${esc(g.remark)}</td><td>${esc(g.username || '')}</td>
      <td class="num">${ME.can_write && (ME.admin || g.user_id === ME.id) ? `<button data-gedit="${g.id}" class="link">edit</button> <button data-gdel="${g.id}" class="link">del</button>` : ''}</td></tr>`).join('')}
    </tbody></table>`;
  $('#detail-files').innerHTML = files || '<span class="hint">no attachments</span>';
  $('#detail-files').onclick = guard(async e => {
    const id = e.target.dataset.fdel;
    if (!id || !confirm('Delete this file?')) return;
    await api('upload.php?id=' + id, { method: 'DELETE' });
    DETAIL = await api('tasks.php?id=' + DETAIL.id);
    await reload(); renderDetail();
  });
  $('#detail-body').onclick = guard(async e => {
    if (e.target.id === 'detail-edit') await openTask(t.id, true);
    const ge = e.target.dataset.gedit;
    if (ge) return void (await editProgress(+ge));
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
  b.project_id = PROJECT;
  await api('tasks.php', { method: b.id ? 'PUT' : 'POST', body: b });
  resetTaskForm();
  await reload();
}

// reuses the report form, so one set of inputs covers adding and editing a day's amount
async function editProgress(gid) {
  const g = DETAIL.progress.find(x => x.id === gid);
  if (!g) return;
  const amt = prompt('Done on ' + g.done_date + ' (entered 0 or more)', g.done_amount);
  if (amt === null) return;
  const when = prompt('Date (YYYY-MM-DD)', g.done_date);
  if (when === null) return;
  const rem = prompt('Remark', g.remark || '');
  if (rem === null) return;
  await api('progress.php', { method: 'PUT', body: { id: gid, done_amount: amt, done_date: when, remark: rem } });
  DETAIL = await api('tasks.php?id=' + DETAIL.id);
  await reload(); renderDetail();
  if (!$('#view-calendar').classList.contains('hidden')) guard(loadCalendar)();
}

async function saveProgress(e) {
  e.preventDefault();
  const f = e.target;
  await api('progress.php', { method: 'POST', body: { task_id: f.elements.task_id.value, done_amount: f.elements.done_amount.value, done_date: f.elements.done_date.value, remark: f.elements.remark.value } });
  f.elements.remark.value = '';
  DETAIL = await api('tasks.php?id=' + f.elements.task_id.value);
  await reload(); renderDetail();
  if (!$('#view-calendar').classList.contains('hidden')) guard(loadCalendar)();
}

// attaching to a task is an immediate file input, not part of the task form submit
$('#task-files').onchange = guard(async e => {
  if (!e.target.files.length || !DETAIL) return;
  await uploadFiles(e.target.files, { task_id: DETAIL.id }, $('#task-upload'));
  DETAIL = await api('tasks.php?id=' + DETAIL.id);
  await reload(); renderDetail();
});

/* ================= chat ================= */
// the last page is kept in localStorage so switching back to a room paints immediately, then
// the poll fills in whatever arrived since
const CHAT_PAGE = 30;
const chatCacheKey = pid => `tt_chat_${pid}`;

function cacheChat() {
  try { localStorage.setItem(chatCacheKey(PROJECT), JSON.stringify(MESSAGES.slice(-60))); } catch {}
}
function cachedChat(pid) {
  try { return JSON.parse(localStorage.getItem(chatCacheKey(pid)) || '[]'); } catch { return []; }
}

async function loadChat() {
  if (!PROJECT) return;
  const msgs = await api(`chat.php?project_id=${PROJECT}&since=${LAST_ID}`);
  if (!msgs.length) { if (!MESSAGES.length) $('#chat-list').innerHTML = '<p class="hint">no messages yet — say hello</p>'; return; }
  msgs.forEach(m => { MESSAGES.push(m); LAST_ID = Math.max(LAST_ID, m.id); });
  cacheChat();
  renderChat();
}

// one page of history above the oldest message currently held
async function loadOlderChat() {
  if (!PROJECT || !MESSAGES.length) return;
  const list = $('#chat-list'), heightBefore = list.scrollHeight;
  const old = await api(`chat.php?project_id=${PROJECT}&before=${MESSAGES[0].id}&limit=${CHAT_PAGE}`);
  if (!old.length) { $('#chat-older').classList.add('hidden'); return; }
  MESSAGES = old.concat(MESSAGES.filter(m => !old.some(o => o.id === m.id)));
  cacheChat();
  renderChat(true);
  list.scrollTop += list.scrollHeight - heightBefore; // keep the reader's place
  if (old.length < CHAT_PAGE) $('#chat-older').classList.add('hidden');
}

function nearBottom() {
  const l = $('#chat-list');
  return l.scrollHeight - l.scrollTop - l.clientHeight < 220;
}

function renderChat(keepPlace) {
  $('#chat-list').innerHTML = MESSAGES.map(m => {
    const mine = m.user_id === ME.id;
    const im = m.files.filter(f => f.thumb);
    const other = m.files.filter(f => !f.thumb);
    return `<div class="chat-msg${mine ? ' mine' : ''}" data-id="${m.id}">
      <div class="chat-head"><b>${esc(m.username || '?')}</b><span class="hint">${m.created_at}</span>
        ${m.can_delete ? `<button data-msgdel="${m.id}" class="link">del</button>` : ''}</div>
      ${m.body ? `<div class="chat-body">${esc(m.body)}</div>` : ''}
      ${im.length ? `<div class="chat-imgs">${im.map(f => `<a href="${f.url}" target="_blank" title="${esc(f.name)}"><img src="${f.thumb}" loading="lazy" alt="${esc(f.name)}"></a>`).join('')}</div>` : ''}
      ${other.length ? `<div class="chat-files">${other.map(f => `<a class="chip" href="${f.url}">${esc(f.name)} · ${bytes(f.size)}</a>`).join(' ')}</div>` : ''}
    </div>`;
  }).join('');
  const list = $('#chat-list');
  if (keepPlace) return; // paging in history: the caller restores the scroll offset itself
  if (list.scrollHeight - list.scrollTop - list.clientHeight < 220) list.scrollTop = list.scrollHeight;
}

function startChatPolling(on) {
  clearInterval(CHAT_TIMER);
  CHAT_TIMER = null;
  if (on && PROJECT) CHAT_TIMER = setInterval(() => { if (!document.hidden) guard(loadChat)(); }, 5000);
}

async function sendChat(e) {
  e.preventDefault();
  if (!PROJECT) return alert('select a project first');
  const f = e.target, text = f.elements.body.value.trim(), input = $('#chat-files');
  if (!text && !input.files.length) return;
  const files = [];
  try {
    if (input.files.length) files.push(...await uploadFiles(input.files, { project_id: PROJECT }, $('#chat-upload')));
  } catch (err) {
    $('#chat-msg').textContent = err.message;
    return; // keep the text so nothing typed is lost
  }
  await api('chat.php', { method: 'POST', body: { project_id: PROJECT, body: text, files: files.map(x => x.id) } });
  f.elements.body.value = '';
  input.value = '';
  $('#chat-msg').textContent = '';
  await loadChat(); // appends just the new message; older pages stay where they were
}
$('#chat-list').onclick = guard(async e => {
  const id = e.target.dataset.msgdel;
  if (!id || !confirm('Delete this message?')) return;
  await api('chat.php?id=' + id, { method: 'DELETE' });
  MESSAGES = MESSAGES.filter(m => m.id !== +id);
  cacheChat();
  renderChat();
});

/* ================= timeline ================= */
function renderTimeline() {
  const withDates = TASKS.filter(t => t.start_date || t.end_date);
  if (!withDates.length) { $('#timeline').innerHTML = '<p class="hint">no dated tasks in this project</p>'; return; }
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
  for (let d = new Date(min); d.getTime() <= max; d.setDate(d.getDate() + Math.ceil((max - min) / 6 / day)))
    marks.push(`<div class="tl-mark" style="left:${(d - min) / (max - min) * 100}%">${d.toISOString().slice(5, 10)}</div>`);
  $('#timeline').innerHTML = `<div class="tl-legend"><span class="tl-name"></span><div class="tl-track">${marks.join('')}<div class="tl-today" style="left:${todayPos}%"></div></div></div>` +
    ordered().filter(t => t.start_date || t.end_date).map(row).join('');
  $('#timeline').onclick = e => { const r = e.target.closest('.tl-row'); if (r) guard(() => openTask(+r.dataset.id))(); };
}

/* ================= calendar ================= */
async function loadCalendar() {
  if (!TASKS.length) { $('#calendar').innerHTML = '<p class="hint">no tasks in this project</p>'; return; }
  // ponytail: one detail request per task, fine for tens of tasks
  ENTRIES = [];
  const details = await Promise.all(TASKS.map(t => api('tasks.php?id=' + t.id).catch(() => null)));
  details.forEach((d, i) => (d ? d.progress : []).forEach(g => ENTRIES.push({ ...g, task: TASKS[i] })));
  const y = CAL.getFullYear(), m = CAL.getMonth();
  $('#cal-title').textContent = CAL.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  const startDow = new Date(y, m, 1).getDay(); // week starts Sunday
  const days = new Date(y, m + 1, 0).getDate();
  // "01/10" or "01/10/2027" when the year differs from the one being shown
  const byDay = {};
  ENTRIES.forEach(e => (byDay[e.done_date] = byDay[e.done_date] || []).push({ entry: e }));
  // start/end markers come from the task itself, not from progress, so a task that has never
  // been reported on still shows up on the days it was meant to run
  TASKS.forEach(t => {
    if (t.start_date) (byDay[t.start_date] = byDay[t.start_date] || []).push({ mark: 'start', task: t });
    if (t.end_date) (byDay[t.end_date] = byDay[t.end_date] || []).push({ mark: 'end', task: t });
  });
  let cells = '';
  for (let i = 0; i < startDow; i++) cells += '<div class="cal-cell pad"></div>';
  for (let d = 1; d <= days; d++) {
    const ds = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const list = byDay[ds] || [];
    cells += `<div class="cal-cell${ds === today() ? ' now' : ''}" data-date="${ds}"><b>${d}</b>${list.map(x => x.mark
      ? `<div class="cal-ev cal-mark cal-${x.mark}" data-tid="${x.task.id}" title="${esc(x.task.name)} ${x.mark === 'start' ? 'starts' : 'ends'} ${esc(x.mark === 'start' ? x.task.start_date : x.task.end_date)}">${esc(x.task.name)} ${x.mark}</div>`
      : `<div class="cal-ev" data-tid="${x.entry.task_id}" title="${esc(x.entry.task.name)}: ${n2(x.entry.done_amount)} ${esc(x.entry.task.unit)} — ${esc(x.entry.remark || '')}">${esc(x.entry.task.name.slice(0, 14))} ${n2(x.entry.done_amount)}</div>`).join('')}</div>`;
  }
  $('#calendar').innerHTML = `<div class="cal-grid">${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => `<div class="cal-h">${d}</div>`).join('')}${cells}</div>`;
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

/* ================= projects ================= */
async function saveProject(e) {
  e.preventDefault();
  const f = e.target, b = {};
  ['id', 'name', 'remark', 'status'].forEach(k => b[k] = f.elements[k].value);
  b.users = checkedUserIds();
  const r = await api('projects.php', { method: b.id ? 'PUT' : 'POST', body: b });
  resetProjectForm();
  await loadProjects();
  $('#project-select').value = r.id;
  await setProject(r.id);
}

function resetProjectForm() {
  const f = $('#project-form');
  f.reset();
  f.elements.id.value = '';
  renderUserChecks(); // back to the default: everyone except admins
  $('#project-form-title').textContent = 'New project';
  $('#project-msg').textContent = '';
}

function renderProjects() {
  $('#project-list').innerHTML = `<table><thead><tr><th>Project</th><th>Status</th><th>Visible to</th><th>Tasks</th><th>Messages</th><th></th></tr></thead><tbody>
    ${PROJECTS.map(p => `<tr data-id="${p.id}">
      <td>${esc(p.name)}${p.remark ? `<div class="hint">${esc(p.remark)}</div>` : ''}</td>
      <td>${p.status}</td>
      <td class="hint">${Object.values(p.users).map(u => esc(u.username)).join(', ') || '—'}</td>
      <td class="num">${p.task_count}</td><td class="num">${p.message_count}</td>
      <td class="num"><button data-pedit="${p.id}" class="link">edit</button> <button data-pdel="${p.id}" class="link">del</button>
        <button data-popen="${p.id}" class="link">open</button></td></tr>`).join('')}
    </tbody></table>`;
  $('#project-list').onclick = guard(async e => {
    const open = e.target.dataset.popen, ed = e.target.dataset.pedit, del = e.target.dataset.pdel;
    if (open) { $('#project-select').value = open; await setProject(+open); show('tasks'); return; }
    if (ed) {
      const p = PROJECTS.find(x => x.id === +ed);
      const f = $('#project-form');
      f.elements.id.value = p.id;
      f.elements.name.value = p.name;
      f.elements.remark.value = p.remark || '';
      f.elements.status.value = p.status;
      renderUserChecks(Object.keys(p.users).map(Number));
      $('#project-form-title').textContent = 'Edit project #' + p.id;
      f.scrollIntoView({ behavior: 'smooth' });
      return;
    }
    if (del) {
      if (!confirm('Delete this project? Its tasks become unassigned; uploaded files are kept.')) return;
      await api('projects.php?id=' + del, { method: 'DELETE' });
      await loadProjects();
    }
  });
}

/* ================= users ================= */
async function saveUser(e) {
  e.preventDefault();
  const f = e.target, b = { username: f.elements.username.value, password: f.elements.password.value, role: f.elements.role.value };
  if (!b.password) return alert('password required for a new user');
  await api('users.php', { method: 'POST', body: b });
  f.reset();
  USERS = await api('users.php');
  renderUserChecks(checkedUserIds());
  renderUsers();
}

function renderUsers() {
  $('#user-list').innerHTML = `<table><thead><tr><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead><tbody>
    ${USERS.map(u => `<tr><td>${esc(u.username)}</td><td><select data-uid="${u.id}">${['admin', 'worker', 'user'].map(r => `<option value="${r}"${u.role === r ? ' selected' : ''}>${r}</option>`).join('')}</select></td>
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
    USERS = await api('users.php'); renderUserChecks(checkedUserIds()); renderUsers();
  });
  $('#user-list').onchange = guard(async e => {
    const id = e.target.dataset.uid;
    if (!id) return;
    await api('users.php', { method: 'PUT', body: { id: +id, role: e.target.value } });
    USERS = await api('users.php'); renderUserChecks(checkedUserIds()); renderUsers();
  });
}

boot();