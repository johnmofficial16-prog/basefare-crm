// Trio Mail between colleagues. Staff with a CRM login write to colleagues
// picked from a list (never a typed address); nothing leaves Trio HR.
// Admins read every conversation, read-only, from Trio Mail → Staff
// conversations; each open is recorded in the audit log.

import { html, raw, mount, $, $$, icon, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go } from '../app.js';
import { fileChips } from './mail.js';
import { attachments } from './inbox.js';

const stamp = (s) => (f.stamp(s) === 'Today' ? f.time(s) : f.day(s));

/** HR | Colleagues, at the top of the staff Inbox. */
export function mailTabs(active, counts = {}) {
  const tab = (key, href, label, n) => html`<a href="${href}" ${key === active ? raw('aria-current="true"') : ''}>${label}${n ? html` <em>${n}</em>` : ''}</a>`;
  return html`<nav class="views views--seg mailtabs" aria-label="Inbox">${tab('hr', '#/me/mail', 'HR', counts.hr)}${tab('colleagues', '#/me/chats', 'Colleagues', counts.colleagues)}</nav>`;
}

function noAccess(main, err) {
  mount(main, html`${mailTabs('colleagues')}<div class="tile"><div class="empty"><div class="dot d-neutral">${icon('users')}</div>
    <b>Not available</b><p>${err.message}</p><a class="btn" href="#/me/mail">Back to your HR messages</a></div></div>`);
}

// ---- staff: list ----------------------------------------------------------------------

export async function list({ main, head }) {
  head({ title: 'Inbox' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/chats'); } catch (err) { if (err.status === 403) { noAccess(main, err); return; } mount(main, errorView(err)); return; }
  let hrUnread = 0;
  try { hrUnread = Math.max(0, (await api.get('/me/mail/unread', { background: true })).unread - d.unread); } catch {}
  mount(main, html`
    ${mailTabs('colleagues', { hr: hrUnread, colleagues: d.unread })}
    <a class="bigbtn" href="#/me/chats/new">${icon('edit')}Message a colleague</a>
    <div class="tile tile--list"><div class="listhead"><b>Conversations with colleagues</b></div>
      ${d.threads.length ? d.threads.map((t) => html`<a class="listrow mrow-p ${t.unread ? 'is-unread' : ''}" href="#/me/chats/${t.id}">
          <span class="av av--sm">${t.with_initials}</span>
          <div><b>${t.with.join(', ')}</b><small><span class="chatsubj">${t.subject}</span> · ${t.from_me ? 'You: ' : t.last_by ? `${t.last_by}: ` : ''}${t.preview}</small></div>
          <span class="r"><span class="meta">${stamp(t.last_at)}</span>${t.unread ? html`<br><span class="pill pill--sm p-gold">New</span>` : ''}</span></a>`)
      : html`<div class="empty"><b>No conversations yet</b><p>Write to anyone at Trio who uses the CRM: pick them from the list.</p></div>`}</div>`);
}

// ---- staff: write -----------------------------------------------------------------------

export async function compose({ main, head, query }) {
  head({ title: 'Message a colleague', back: '#/me/chats' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/colleagues'); } catch (err) { if (err.status === 403) { noAccess(main, err); return; } mount(main, errorView(err)); return; }
  const byId = new Map(d.people.map((p) => [p.id, p]));
  const picked = [];
  const pre = Number(query.get('to'));
  if (byId.has(pre)) picked.push(pre);
  const centres = [...new Set(d.people.map((p) => p.centre || 'Other'))];
  mount(main, html`
    <form class="form" id="cf" novalidate>
      <div class="field"><label for="cf-to">To</label>
        <div class="tochips" id="cf-chips"></div>
        <select class="select" id="cf-to">
          <option value="">Pick a colleague…</option>
          ${centres.map((c) => html`<optgroup label="${c}">${d.people.filter((p) => (p.centre || 'Other') === c).map((p) => html`<option value="${p.id}">${p.name}${p.designation ? ` · ${p.designation}` : ''}</option>`)}</optgroup>`)}
        </select>
        <div class="err" role="alert" data-for="to"></div></div>
      <div class="field"><label for="cf-s">Subject <span class="opt">(optional)</span></label><input class="input" id="cf-s" name="subject" maxlength="160"></div>
      <div class="field"><label for="cf-b">Message</label><textarea class="textarea" id="cf-b" name="body" rows="6" placeholder="Write in English, Hindi or Hinglish"></textarea><div class="err" role="alert"></div></div>
      <div class="att" id="cf-files"></div>
      <div class="row"><label class="btn btn--sm btn--quiet filebtn">${icon('clip', 'i--sm')}Photo or PDF<input type="file" id="cf-file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr"></label></div>
      <button class="bigbtn" type="submit">${icon('send')}Send</button>
    </form>`);
  const form = $('#cf', main);
  const sel = $('#cf-to', form);
  const files = attachments(form, '#cf-file', '#cf-files');
  const draw = () => {
    mount($('#cf-chips', form), html`${picked.map((id) => html`<span class="tochip"><span class="av av--xs">${byId.get(id).initials}</span>${byId.get(id).name}
      <button type="button" class="iconbtn iconbtn--xs" data-unpick="${id}" aria-label="Remove ${byId.get(id).name}">${icon('x', 'i--sm')}</button></span>`)}`);
    $$('option', sel).forEach((o) => { if (o.value) o.disabled = picked.includes(Number(o.value)); });
    sel.disabled = picked.length >= d.max_to;
    sel.options[0].textContent = picked.length ? (picked.length >= d.max_to ? `Up to ${d.max_to} people` : 'Add someone else…') : 'Pick a colleague…';
  };
  draw();
  sel.addEventListener('change', () => {
    const id = Number(sel.value);
    if (id && !picked.includes(id)) picked.push(id);
    sel.value = '';
    $('[data-for="to"]', form).textContent = '';
    draw();
  });
  $('#cf-chips', form).addEventListener('click', (e) => {
    const b = e.target.closest('[data-unpick]');
    if (!b) return;
    picked.splice(picked.indexOf(Number(b.dataset.unpick)), 1);
    draw();
  });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const errs = {};
    if (!picked.length) { $('[data-for="to"]', form).textContent = 'Pick at least one colleague from the list.'; sel.focus(); return; }
    if (!form.body.value.trim()) errs.body = 'Write your message.';
    if (showFieldErrors(form, errs)) return;
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/me/chats', { to: picked, subject: form.subject.value.trim(), body: form.body.value, files: files.ids() });
        toast(r.message);
        go(`#/me/chats/${r.id}`, { replace: true });
      } catch (err) {
        if (err.fields?.to) $('[data-for="to"]', form).textContent = err.fields.to;
        else if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
      }
    });
  });
}

// ---- staff: one conversation ---------------------------------------------------------------

const messageList = (msgs, readOnly = false) => html`<ol class="msgs msgs--p" aria-label="Messages">${msgs.map((m) => html`
  <li class="msg ${m.mine ? 'msg--me' : 'msg--them'}">
    <div class="msg__h"><b>${m.mine ? 'You' : readOnly ? m.author : m.first}</b><time>${f.day(m.at)}, ${f.time(m.at)}</time></div>
    <div class="msg__b">${m.body}</div>${fileChips(m.files)}</li>`)}</ol>`;

export async function thread({ main, head, params }) {
  head({ title: 'Colleagues', back: '#/me/chats' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/me/chats/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const t = d.thread;
  const others = t.members.filter((m) => !m.me);
  mount(main, html`
    <h1 class="p-title">${t.subject}</h1>
    <p class="meta">With ${others.map((m) => m.name + (m.active ? '' : ' (no longer at Trio)')).join(', ')}</p>
    ${messageList(d.messages)}
    ${t.can_reply ? html`<form class="tile form" id="rf" novalidate>
      <div class="field"><label for="rf-b">Reply</label><textarea class="textarea" id="rf-b" name="body" rows="3" placeholder="Write here"></textarea><div class="err" role="alert"></div></div>
      <div class="att" id="rf-files"></div>
      <div class="row"><label class="btn btn--sm btn--quiet filebtn">${icon('clip', 'i--sm')}Photo or PDF<input type="file" id="rf-file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr"></label>
        <button class="btn btn--pri push" type="submit">${icon('send')}Send</button></div>
    </form>` : html`<div class="banner banner--info">${icon('info')}<div>Nobody else in this conversation is at Trio any more, so it is closed.</div></div>`}`);
  $('.msgs li:last-child', main)?.scrollIntoView({ block: 'nearest' });
  const form = $('#rf', main);
  if (!form) return;
  const files = attachments(form, '#rf-file', '#rf-files');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!form.body.value.trim()) { showFieldErrors(form, { body: 'Write your reply.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        await api.post(`/me/chats/${t.id}/reply`, { body: form.body.value, files: files.ids() });
        go(`#/me/chats/${t.id}`, { replace: true });
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- admins: every conversation, read-only --------------------------------------------------

export async function adminList({ main, query }) {
  mount(main, skeleton('list'));
  const q = query.get('q') || '';
  let d;
  try { d = await api.get('/chats' + (q ? `?q=${encodeURIComponent(q)}` : '')); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <a class="crumb" href="#/mail">${icon('back', 'i--sm')}Trio Mail</a>
    <div class="ph"><div class="ph__t"><h1>Staff conversations</h1><p class="ph__s">Messages between colleagues inside Trio HR · ${f.plural(d.total, 'conversation')} in all · every time one is opened here is recorded</p></div></div>
    <form class="mailfilter" id="cs" role="search"><label class="sr" for="cs-q">Search</label>
      <input class="input" id="cs-q" name="q" type="search" value="${q}" placeholder="Search a name, subject or words"></form>
    <div class="card">${d.items.length ? html`<div class="tablewrap"><table class="rtable">
      <thead><tr><th>Between</th><th>Subject</th><th class="r hide-sm">Messages</th><th class="r">Last message</th></tr></thead>
      <tbody>${d.items.map((t) => html`<tr data-href="#/mail/staff/${t.id}">
        <td><a href="#/mail/staff/${t.id}"><b>${t.members.join(', ')}</b></a></td>
        <td>${t.subject}<small class="meta">${t.preview}</small></td>
        <td class="r hide-sm tnum">${t.messages}</td><td class="r nowrap">${stamp(t.last_at)}</td></tr>`)}</tbody></table></div>`
      : html`<div class="empty"><div class="dot d-neutral">${icon('mail')}</div><b>${q ? 'Nothing matches' : 'No conversations yet'}</b><p>${q ? 'Try another name or word.' : 'When colleagues write to each other in Trio HR, the conversations appear here.'}</p></div>`}</div>`);
  let wait = null;
  $('#cs-q', main).addEventListener('input', (e) => { clearTimeout(wait); wait = setTimeout(() => go('#/mail/staff' + (e.target.value.trim() ? `?q=${encodeURIComponent(e.target.value.trim())}` : ''), { replace: true }), 450); });
  $('#cs', main).addEventListener('submit', (e) => e.preventDefault());
  main.addEventListener('click', (e) => { const tr = e.target.closest('tr[data-href]'); if (tr && !e.target.closest('a')) go(tr.dataset.href); });
}

export async function adminThread({ main, params }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/chats/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const t = d.thread;
  mount(main, html`
    <a class="crumb" href="#/mail/staff">${icon('back', 'i--sm')}Staff conversations</a>
    <div class="ph"><div class="ph__t"><h1>${t.subject}</h1><p class="ph__s">Started ${f.day(t.started)} · read-only · opened ${f.plural(d.opens, 'time')} by admins</p></div></div>
    <div class="grid2">
      <div class="card card__b">${messageList(d.messages, true)}</div>
      <div class="card"><div class="card__h"><h2>In this conversation</h2></div><div class="card__b card__b--tight">
        ${t.members.map((m) => html`<div class="minirow"><span class="av av--sm">${m.initials}</span><div><a class="name" href="#/people/${m.id}">${m.name}</a><small>${m.designation || ''}${m.centre ? ` · ${m.centre}` : ''}</small></div></div>`)}
      </div></div>
    </div>`);
}
