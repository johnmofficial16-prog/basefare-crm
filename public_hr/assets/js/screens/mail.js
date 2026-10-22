// Trio Mail for admins: every conversation with staff, laid out like email.
// Boxes: Waiting on HR (oldest first), Waiting on staff, Done, All.
// A conversation that filed a request shows the request card with Approve /
// Send back right inside it; the decision is posted into the thread.

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy, openDrawer } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go, refreshNav, setTitle, state } from '../app.js';
import { requestPanel } from './approvals.js';

const REQ_PILL = { pending: 'p-warn', approved: 'p-ok', partly_approved: 'p-ok', sent_back: 'p-bad', withdrawn: 'p-neutral' };

function hashFor(box, id, extra = {}) {
  const q = new URLSearchParams();
  if (box && box !== 'waiting') q.set('box', box);
  for (const [k, v] of Object.entries(extra)) if (v) q.set(k, v);
  return (id ? `#/mail/${id}` : '#/mail') + (q.toString() ? '?' + q : '');
}

const size = (n) => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');

export function fileChips(files, base = 'api/mail/files') {
  if (!files?.length) return '';
  return html`<div class="att">${files.map((x) => html`<a class="attchip" href="${base}/${x.id}" target="_blank" rel="noopener">${icon(x.mime === 'application/pdf' ? 'file' : 'image', 'i--sm')}<span>${x.name}</span><small>${size(x.size)}</small></a>`)}</div>`;
}

/** Text with line breaks kept (escaped by html``; CSS keeps the breaks). */
const body = (t) => html`<div class="msg__b">${t}</div>`;

// ---- the inbox -------------------------------------------------------------------

export async function render({ main, query, params, isCurrent }) {
  const box = query.get('box') || 'waiting';
  const topic = query.get('topic') || '';
  const q = query.get('q') || '';
  const wantId = Number(params[0]) || null;
  mount(main, skeleton('list'));
  const load = () => api.get('/mail?' + new URLSearchParams({ box, ...(topic ? { topic } : {}), ...(q ? { q } : {}) }));
  let list;
  try { list = await load(); } catch (err) { mount(main, errorView(err)); return; }
  if (!isCurrent()) return;
  const wide = window.matchMedia('(min-width: 1181px)').matches;
  let selected = wantId || (wide && list.items[0] ? list.items[0].id : null);

  const rows = (items) => items.length ? items.map((i) => html`
    <a class="req mrow ${i.id === selected ? 'is-sel' : ''} ${i.unread ? 'is-unread' : ''}" href="${hashFor(list.box, i.id, { topic, q })}" data-id="${i.id}" ${i.id === selected ? raw('aria-current="true"') : ''}>
      <span class="av">${i.person.initials}</span>
      <span class="req__t"><b>${i.person.name}</b>
        <span class="mrow__s">${i.private ? icon('lock', 'i--sm') : ''}${i.subject}</span>
        <span class="s">${i.last_from === 'staff' ? '' : i.last_from === 'system' ? 'Trio HR: ' : 'You: '}${i.preview}</span>
        <span class="mrow__tags"><span class="pill pill--sm p-neutral">${i.topic_label}</span>${i.request ? html`<span class="pill pill--sm ${REQ_PILL[i.request.status]}">${i.request.type_label}: ${i.request.status === 'pending' ? 'waiting' : f.lower(i.request.status_label)}</span>` : ''}</span></span>
      <span class="when ${i.tone ? 't-' + i.tone : ''}">${f.stamp(i.last_at)}<br>${i.waiting_hours !== null && i.waiting_hours >= 1 ? `${f.plural(i.waiting_hours, 'hour')} waiting` : f.stamp(i.last_at) === 'Today' ? f.time(i.last_at) : ''}</span>
    </a>`) : html`<div class="empty"><div class="dot d-ok">${icon('check')}</div><b>${list.box === 'waiting' ? 'Nobody is waiting on HR' : 'Nothing here'}</b><p>${list.box === 'waiting' ? 'New messages from staff appear here, oldest first.' : 'Try another box, or clear the search.'}</p></div>`;

  mount(main, html`
    <div class="ph">
      <div class="ph__t"><h1>Trio Mail</h1><p class="ph__s">Messages between staff and HR. Anyone waiting more than ${f.plural(list.reply_hours, 'hour')} turns amber; after 48 hours the owner is told.</p></div>
      <div class="ph__a"><a class="btn" href="#/mail/staff">${icon('users')}Staff conversations</a><a class="btn" href="#/announcements">${icon('speaker')}Announcements</a><a class="btn btn--pri" href="#/mail/new">${icon('edit')}Write to someone</a></div>
    </div>
    <div class="mailbar">
      <nav class="views" aria-label="Mail boxes">${list.boxes.map((b) => html`<a href="${hashFor(b.key, null, { topic, q })}" ${b.key === list.box ? raw('aria-current="true"') : ''}>${b.label}${b.count !== null ? html` <em>${b.count}</em>` : ''}</a>`)}</nav>
      <form class="mailfilter" id="mf" role="search">
        <label class="sr" for="mf-t">Topic</label>
        <select class="select select--sm" id="mf-t" name="topic"><option value="">All topics</option>${list.topics.map((t) => html`<option value="${t.key}" ${t.key === topic ? 'selected' : ''}>${t.label}</option>`)}</select>
        <label class="sr" for="mf-q">Search</label>
        <input class="input input--sm" id="mf-q" name="q" type="search" value="${q}" placeholder="Search name, subject or words">
      </form>
    </div>
    <div class="split split--mail ${selected ? 'has-detail' : ''}">
      <div class="reqlist-wrap"><div class="card reqlist" id="mlist" aria-label="Conversations">${rows(list.items)}</div></div>
      <div class="detail-wrap" id="thread">${selected ? skeleton('list') : html`<div class="card"><div class="empty"><div class="dot d-neutral">${icon('mail')}</div><b>Pick a conversation</b><p>Or write to someone with the button above.</p></div></div>`}</div>
    </div>`);

  const mf = $('#mf', main);
  let wait = null;
  const applyFilter = () => go(hashFor(list.box, null, formData(mf)), { replace: true });
  $('#mf-t', mf).addEventListener('change', applyFilter);
  $('#mf-q', mf).addEventListener('input', () => { clearTimeout(wait); wait = setTimeout(applyFilter, 450); });
  mf.addEventListener('submit', (e) => { e.preventDefault(); applyFilter(); });

  let shown = 0;
  const open = (id, push = true) => {
    selected = id;
    if (push) { if (wide) history.replaceState(null, '', hashFor(list.box, id, { topic, q })); else history.pushState(null, '', hashFor(list.box, id, { topic, q })); }
    $$('.mrow', main).forEach((x) => { const on = Number(x.dataset.id) === id; x.classList.toggle('is-sel', on); if (on) { x.setAttribute('aria-current', 'true'); x.classList.remove('is-unread'); } else x.removeAttribute('aria-current'); });
    $('.split', main).classList.add('has-detail');
    const mine = ++shown;
    thread($('#thread', main), id, { box: list.box, latest: () => mine === shown && isCurrent(), reload: () => refreshList() });
  };
  main.addEventListener('click', (e) => {
    const a = e.target.closest('.mrow[data-id]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    open(Number(a.dataset.id));
    if (!wide) window.scrollTo(0, 0);
  });

  // The list refreshes quietly every 30 seconds (never while someone is typing in it).
  const refreshList = async () => {
    try {
      const fresh = await api.get('/mail?' + new URLSearchParams({ box, ...(topic ? { topic } : {}), ...(q ? { q } : {}) }), { background: true });
      if (!isCurrent()) return;
      list.items = fresh.items;
      const holder = $('#mlist', main);
      if (holder) mount(holder, rows(fresh.items));
      $$('.views a em', main).forEach((em, i) => { const b = fresh.boxes[i]; if (b && b.count !== null) em.textContent = b.count; });
      refreshNav(true);
    } catch {}
  };
  const timer = setInterval(() => { if (!document.activeElement?.closest('#mf')) refreshList(); }, 30000);

  if (selected) open(selected, false);
  return () => clearInterval(timer);
}

// ---- one conversation ---------------------------------------------------------

async function thread(slot, id, { box, latest, reload }) {
  let d;
  try { d = await api.get(`/mail/${id}`); } catch (err) { if (latest()) mount(slot, errorView(err)); return; }
  if (!latest()) return;
  const t = d.thread;
  const p = d.person;
  setTitle(`${p.name}: ${t.subject}`);
  const leaveLine = p.leave.filter((b) => b.total > 0).map((b) => `${f.bal(Math.max(0, b.available - b.pending))} ${f.lower(b.name.replace(' leave', ''))}`).join(' · ');
  mount(slot, html`
    <a class="crumb backlink" href="${hashFor(box)}">${icon('back', 'i--sm')}All conversations</a>
    <div class="card mthread">
      <div class="card__h">
        <div class="row"><span class="av av--lg">${p.initials}</span>
          <div><h2 class="h-person">${p.name}</h2><span class="sub">${[p.designation, p.centre_name, p.team_leader ? 'Team leader ' + p.team_leader : null, p.tenure ? 'with Trio ' + p.tenure : null].filter(Boolean).join(' · ')}</span></div></div>
        <a href="#/people/${p.id}">Open record</a>
      </div>
      <div class="card__b">
        <div class="mthead">
          <div class="mthead__t"><span class="label">${t.topic_label}${t.private ? ' · private' : ''}</span><h3 class="mtitle">${t.subject}</h3>
            <span class="meta">Started ${f.day(t.created_at)} by ${t.started_by === 'staff' ? p.first : 'HR'}${leaveLine ? ` · leave left: ${leaveLine}` : ''}</span></div>
          <div class="mthead__a">
            ${t.status === 'done' ? html`<span class="pill p-neutral">Done</span><button class="btn btn--sm" type="button" data-status="open">Reopen</button>`
              : html`${t.waiting_hours !== null ? html`<span class="pill ${t.tone === 'bad' ? 'p-bad' : t.tone === 'warn' ? 'p-warn' : 'p-info'}">Waiting ${t.waiting_hours < 1 ? 'under an hour' : f.plural(t.waiting_hours, 'hour')}</span>` : html`<span class="pill p-ok">Answered</span>`}
                <button class="btn btn--sm" type="button" data-status="done">${icon('check', 'i--sm')}Mark done</button>`}
          </div>
        </div>
        ${t.private ? html`<div class="banner banner--info">${icon('lock')}<div><b>Private.</b>Only HR admins can read this, and every time it is opened is recorded. Handle it in person where you can.</div></div>` : ''}
        ${t.request ? html`<div class="mreq" id="mreq">${skeleton('list')}</div>` : ''}
        <ol class="msgs" aria-label="Messages">${d.messages.map((m) => msgItem(m, p))}</ol>
      </div>
      ${composer(d)}
    </div>
    ${d.others.length ? html`<div class="card"><div class="card__h"><h2>Other conversations with ${p.first}</h2></div>
      <div class="card__b list-tight">${d.others.map((o) => html`<a class="listrow" href="#/mail/${o.id}">${icon('mail')}<div><b>${o.subject}</b><small>${o.status === 'done' ? 'Done' : 'Open'} · ${f.day(o.at)}</small></div><span class="r">${icon('chev', 'chev')}</span></a>`)}</div></div>` : ''}`);

  // The request filed with this conversation, decided right here.
  if (t.request) {
    requestPanel($('#mreq', slot), t.request.id, { compact: true, latest, onDone: () => { reload(); thread(slot, id, { box, latest, reload }); } });
  }
  $$('[data-status]', slot).forEach((b) => b.addEventListener('click', () => busy(b, async () => {
    try {
      const r = await api.post(`/mail/${id}/status`, { status: b.dataset.status });
      toast(r.message);
      reload();
      thread(slot, id, { box, latest, reload });
      refreshNav();
    } catch (err) { toast(err.message, { tone: 'bad' }); }
  })));
  wireComposer(slot, d, async (res) => {
    toast(res.message);
    reload();
    refreshNav();
    await thread(slot, id, { box, latest, reload });
    $('.msgs li:last-child', slot)?.scrollIntoView({ block: 'nearest' });
  });
  const last = $('.msgs li:last-child', slot);
  if (last && window.matchMedia('(min-width: 1181px)').matches) last.scrollIntoView({ block: 'nearest' });
}

function msgItem(m, p) {
  if (m.sender === 'system') {
    return html`<li class="msg msg--sys"><div class="msg__sys">${icon('check', 'i--sm')}<div>${body(m.body)}<small>${f.day(m.at)}, ${f.time(m.at)}</small></div></div></li>`;
  }
  const mine = m.sender === 'hr';
  return html`<li class="msg ${mine ? 'msg--hr' : 'msg--staff'} ${m.internal ? 'msg--note' : ''}">
    <div class="msg__h"><b>${m.internal ? 'Note for admins · ' : ''}${mine ? (m.author || 'HR') : p.name}</b><time>${f.day(m.at)}, ${f.time(m.at)}</time></div>
    ${body(m.body)}${fileChips(m.files)}</li>`;
}

function composer(d) {
  return html`<form class="composer" id="cmp" novalidate>
    <div class="composer__tabs" role="tablist" aria-label="Kind of message">
      <button type="button" role="tab" aria-selected="true" data-mode="reply">${icon('reply', 'i--sm')}Reply to ${d.person.first}</button>
      <button type="button" role="tab" aria-selected="false" data-mode="note">${icon('note', 'i--sm')}Note for admins</button>
    </div>
    <div class="chips composer__quick" id="quick">${d.templates.map((x) => html`<button type="button" class="chip" data-tpl="${x.key}">${x.label}</button>`)}
      ${d.ai ? html`<button type="button" class="chip chip--ai" id="ai">${icon('spark', 'i--sm')}Suggest a reply</button>` : ''}</div>
    <div class="field"><label class="sr" for="cmp-b">Message</label>
      <textarea class="textarea" id="cmp-b" name="body" rows="5" placeholder="Write to ${d.person.first}…"></textarea><div class="err" role="alert"></div></div>
    <div class="att" id="cmp-files"></div>
    <div class="composer__f">
      <label class="btn btn--sm btn--quiet filebtn">${icon('clip', 'i--sm')}Attach<input type="file" id="cmp-file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr"></label>
      <label class="check-row composer__done"><input type="checkbox" name="close" ${d.thread.status === 'done' ? raw('disabled') : ''}> Mark done after sending</label>
      <button class="btn btn--pri push" type="submit">${icon('send')}<span>Send</span></button>
    </div>
    <p class="meta composer__hint" id="cmp-hint">${d.person.has_account ? `${d.person.first} is told on Trio HR and in the CRM bell${state.emailOn ? ', and by email (without the message itself)' : ''}.` : `${d.person.first} hasn't joined Trio HR yet, so they'll see it in the CRM bell and once they join.`}<span class="kbdhint"> Ctrl+Enter sends.</span></p>
  </form>`;
}

/** Composer behaviour, shared by the admin thread. `onSent(result)` after a successful send. */
function wireComposer(slot, d, onSent) {
  const form = $('#cmp', slot);
  if (!form) return;
  const ta = form.body;
  let mode = 'reply';
  const files = [];
  const drawFiles = () => mount($('#cmp-files', form), files.length ? html`${files.map((x, i) => html`<span class="attchip">${icon(x.mime === 'application/pdf' ? 'file' : 'image', 'i--sm')}<span>${x.name}</span><button type="button" class="iconbtn iconbtn--xs" data-rm="${i}" aria-label="Remove ${x.name}">${icon('x', 'i--sm')}</button></span>`)}` : '');
  $$('[data-mode]', form).forEach((b) => b.addEventListener('click', () => {
    mode = b.dataset.mode;
    $$('[data-mode]', form).forEach((x) => x.setAttribute('aria-selected', String(x === b)));
    form.classList.toggle('is-note', mode === 'note');
    ta.placeholder = mode === 'note' ? 'Only admins see this. Staff never do.' : `Write to ${d.person.first}…`;
    $('#quick', form).hidden = mode === 'note';
    $('.composer__done', form).hidden = mode === 'note';
    $('.filebtn', form).hidden = mode === 'note';
    $('#cmp-hint', form).hidden = mode === 'note';
    $('[type="submit"] span', form).textContent = mode === 'note' ? 'Save note' : 'Send';
    ta.focus();
  }));
  $$('[data-tpl]', form).forEach((b) => b.addEventListener('click', () => {
    const tpl = d.templates.find((x) => x.key === b.dataset.tpl);
    ta.value = ta.value.trim() ? `${ta.value.trim()}\n\n${tpl.body}` : tpl.body;
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
  }));
  $('#ai', form)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    try {
      const r = await api.post(`/mail/${d.thread.id}/suggest`, {});
      ta.value = r.body;
      ta.focus();
      toast('Draft written. Read it, change anything, then send.');
    } catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
  $('#cmp-file', form).addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 8 * 1024 * 1024) { toast('That file is over 8 MB. Try a smaller one.', { tone: 'bad' }); return; }
    if (files.length >= 5) { toast('Up to 5 attachments per message.', { tone: 'bad' }); return; }
    try { files.push(await api.upload('/mail/files', file)); drawFiles(); } catch (err) { toast(err.message, { tone: 'bad' }); }
  });
  $('#cmp-files', form).addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { files.splice(Number(b.dataset.rm), 1); drawFiles(); } });
  ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit(); } });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!ta.value.trim()) { showFieldErrors(form, { body: mode === 'note' ? 'Write the note.' : 'Write a message.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const res = await api.post(`/mail/${d.thread.id}/reply`, { body: ta.value, internal: mode === 'note', close: mode === 'reply' && form.close.checked, files: files.map((x) => x.id) });
        await onSent(res);
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- write to someone ---------------------------------------------------------------

export async function compose({ main, query, isCurrent }) {
  const personId = Number(query.get('person')) || null;
  let person = null;
  if (personId) { try { person = (await api.get(`/people/${personId}`)).person; } catch {} }
  if (!isCurrent()) return;
  setTitle('Write to someone');
  mount(main, html`
    <a class="crumb" href="#/mail">${icon('back', 'i--sm')}Trio Mail</a>
    <div class="ph"><div class="ph__t"><h1>Write to someone</h1><p class="ph__s">They see it in their Trio HR inbox and get a nudge in the CRM bell.</p></div></div>
    <form class="card card__b form form--narrow" id="wf" novalidate>
      <div class="field"><label for="wf-who">To</label>
        <input type="hidden" name="employee_id" value="${person ? person.id : ''}">
        <div class="picker" id="picker">
          ${person ? html`<span class="picked"><span class="av av--sm">${person.initials}</span><b>${person.full_name}</b><button type="button" class="linkbtn" id="unpick">Change</button></span>`
            : html`<input class="input" id="wf-who" type="search" placeholder="Type a name" autocomplete="off" aria-autocomplete="list" aria-controls="wf-res"><div class="picker__res" id="wf-res" role="listbox" hidden></div>`}
        </div><div class="err" role="alert"></div></div>
      <div class="field"><label for="wf-s">Subject</label><input class="input" id="wf-s" name="subject" maxlength="160" placeholder="For example: Your PAN card"><div class="err" role="alert"></div></div>
      <div class="field"><label for="wf-b">Message</label><textarea class="textarea" id="wf-b" name="body" rows="7"></textarea><div class="err" role="alert"></div></div>
      <div class="att" id="wf-files"></div>
      <div class="row"><label class="btn btn--sm btn--quiet filebtn">${icon('clip', 'i--sm')}Attach<input type="file" id="wf-file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr"></label>
        <button class="btn btn--pri push" type="submit">${icon('send')}Send</button></div>
    </form>`);
  const form = $('#wf', main);
  const files = [];
  const drawFiles = () => mount($('#wf-files', form), html`${files.map((x, i) => html`<span class="attchip">${icon('file', 'i--sm')}<span>${x.name}</span><button type="button" class="iconbtn iconbtn--xs" data-rm="${i}" aria-label="Remove ${x.name}">${icon('x', 'i--sm')}</button></span>`)}`);
  $('#wf-files', form).addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { files.splice(Number(b.dataset.rm), 1); drawFiles(); } });
  $('#wf-file', form).addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    try { files.push(await api.upload('/mail/files', file)); drawFiles(); } catch (err) { toast(err.message, { tone: 'bad' }); }
  });
  $('#unpick', form)?.addEventListener('click', () => go('#/mail/new', { replace: true }));
  const who = $('#wf-who', form);
  if (who) {
    const res = $('#wf-res', form);
    let seq = 0;
    let t = null;
    who.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const qv = who.value.trim();
        const mine = ++seq;
        if (qv.length < 2) { res.hidden = true; return; }
        let r;
        try { r = await api.get('/search?q=' + encodeURIComponent(qv)); } catch { return; }
        if (mine !== seq) return;
        res.hidden = false;
        mount(res, r.people.length ? html`${r.people.filter((x) => !x.left).map((x) => html`<button type="button" role="option" class="picker__opt" data-pick="${x.id}"><span class="av av--sm">${x.initials}</span><span><b>${x.name}</b><small>${x.detail}</small></span></button>`)}` : html`<div class="picker__none">Nobody by that name.</div>`);
      }, 200);
    });
    res.addEventListener('click', (e) => { const b = e.target.closest('[data-pick]'); if (b) go(`#/mail/new?person=${b.dataset.pick}`, { replace: true }); });
  }
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const data = formData(form);
    const errs = {};
    if (!data.employee_id) errs.employee_id = 'Pick who it goes to.';
    if (!data.subject) errs.subject = 'Add a subject.';
    if (!data.body) errs.body = 'Write the message.';
    if (showFieldErrors(form, errs)) return;
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/mail', { ...data, employee_id: Number(data.employee_id), files: files.map((x) => x.id) });
        toast(r.message);
        go(`#/mail/${r.id}?box=staff`, { replace: true });
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- announcements ---------------------------------------------------------------------

export async function announcements({ main, isCurrent }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/announcements'); } catch (err) { mount(main, errorView(err)); return; }
  if (!isCurrent()) return;
  mount(main, html`
    <a class="crumb" href="#/mail">${icon('back', 'i--sm')}Trio Mail</a>
    <div class="ph"><div class="ph__t"><h1>Announcements</h1><p class="ph__s">One message to everyone, a centre, or chosen people. It shows in their inbox and on Home, with read receipts.</p></div>
      <div class="ph__a"><button class="btn btn--pri" type="button" id="newann">${icon('plus')}New announcement</button></div></div>
    ${d.announcements.length ? html`<div class="card"><div class="card__b list-tight">${d.announcements.map((a) => html`
      <div class="listrow ann ${a.withdrawn ? 'is-off' : ''}">${icon(a.pinned ? 'flag' : 'speaker')}
        <div><b>${a.title}</b><small>${a.audience} · ${f.day(a.at)}${a.by ? ` · by ${a.by}` : ''}${a.withdrawn ? ' · taken down' : a.expires_on ? ` · shows until ${f.day(a.expires_on)}` : ''}</small></div>
        <span class="r row"><button class="btn btn--sm" type="button" data-receipts="${a.id}">${a.seen} of ${a.sent} ${a.seen === 1 ? 'has' : 'have'} read it</button>
          ${a.withdrawn ? '' : html`<button class="btn btn--sm btn--quiet" type="button" data-down="${a.id}">Take down</button>`}</span></div>`)}</div></div>`
      : html`<div class="card"><div class="empty"><div class="dot d-gold">${icon('speaker')}</div><b>No announcements yet</b><p>Use them for news everyone needs: a holiday, a new rule, a celebration.</p></div></div>`}`);

  $('#newann', main).addEventListener('click', () => newAnnouncement(d.centres));
  main.addEventListener('click', async (e) => {
    const r = e.target.closest('[data-receipts]');
    if (r) {
      let x;
      try { x = await api.get(`/announcements/${r.dataset.receipts}`); } catch (err) { toast(err.message, { tone: 'bad' }); return; }
      openDrawer({ title: x.title, body: html`
        <div class="msg__b">${x.body}</div>
        <h3 class="h-sub">Read (${x.read.length})</h3>${x.read.length ? html`<ul class="namelist">${x.read.map((p) => html`<li><b>${p.name}</b><small>${f.day(p.at)}, ${f.time(p.at)}</small></li>`)}</ul>` : html`<p class="meta">Nobody yet.</p>`}
        <h3 class="h-sub">Not read yet (${x.unread.length})</h3>${x.unread.length ? html`<ul class="namelist">${x.unread.map((p) => html`<li><b>${p.name}</b></li>`)}</ul>` : html`<p class="meta">Everyone has read it.</p>`}` });
      return;
    }
    const dn = e.target.closest('[data-down]');
    if (dn) {
      const res = await confirmSheet({ title: 'Take this announcement down?', text: 'Staff stop seeing it straight away. The read receipts stay here.', confirm: 'Take it down', tone: 'danger', onConfirm: () => api.post(`/announcements/${dn.dataset.down}/withdraw`) });
      if (res) { toast(res.message); go('#/announcements', { replace: true }); }
    }
  });
}

function newAnnouncement(centres) {
  const layer = openDrawer({
    title: 'New announcement',
    body: html`<form class="form" id="af" novalidate>
      <div class="field"><label for="af-t">Title</label><input class="input" id="af-t" name="title" maxlength="160" placeholder="For example: Diwali sweets on Friday night"><div class="err" role="alert"></div></div>
      <div class="field"><label for="af-b">Message</label><textarea class="textarea" id="af-b" name="body" rows="6"></textarea><div class="err" role="alert"></div></div>
      <div class="field"><span class="flabel">Who it goes to</span>
        <div class="seg" role="radiogroup"><label><input type="radio" name="to" value="all" checked> Everyone</label><label><input type="radio" name="to" value="centres"> Some centres</label></div>
        <div class="checks-inline" id="af-c" hidden>${centres.map((c) => html`<label class="check-row"><input type="checkbox" name="centre" value="${c.code}"> ${c.name}</label>`)}</div>
        <input type="hidden" name="centres"><div class="err" role="alert"></div></div>
      <div class="form__grid">
        <div class="field"><label for="af-e">Show until <span class="opt">(optional)</span></label><input class="input" type="date" id="af-e" name="expires_on" min="${f.todayIST()}"><div class="err" role="alert"></div></div>
        <div class="field"><span class="flabel">Pin it</span><label class="check-row"><input type="checkbox" name="pinned"> Keep it at the top</label></div>
      </div>
    </form>`,
    foot: html`<button class="btn btn--quiet" type="button" data-cancel>Cancel</button><button class="btn btn--pri" type="button" data-send>${icon('send')}Send</button>`,
  });
  const el = layer.el;
  const form = $('#af', el);
  $$('[name="to"]', form).forEach((r) => r.addEventListener('change', () => { $('#af-c', form).hidden = form.to.value !== 'centres'; }));
  $('[data-cancel]', el).addEventListener('click', () => layer.close());
  $('[data-send]', el).addEventListener('click', (e) => {
    const data = formData(form);
    const centresPicked = $$('[name="centre"]:checked', form).map((x) => x.value);
    const errs = {};
    if (!data.title) errs.title = 'Add a title.';
    if (!data.body) errs.body = 'Write the message.';
    if (data.to === 'centres' && !centresPicked.length) errs.centres = 'Pick at least one centre.';
    if (showFieldErrors(form, errs)) return;
    busy(e.currentTarget, async () => {
      try {
        const r = await api.post('/announcements', { title: data.title, body: data.body, to: data.to, centres: centresPicked, pinned: data.pinned, expires_on: data.expires_on || null });
        layer.close(true);
        toast(r.message);
        go('#/announcements', { replace: true });
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}
