// Staff conversations, for Trio HR's admins. Staff write to each other in the
// CRM (CRM → Messages); here admins read every conversation, read-only, and
// each open is recorded in the audit log.

import { html, mount, $, icon } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go } from '../app.js';
import { fileChips } from './mail.js';

const stamp = (s) => (f.stamp(s) === 'Today' ? f.time(s) : f.day(s));

// ---- staff: one conversation ---------------------------------------------------------------

const messageList = (msgs, readOnly = false) => html`<ol class="msgs msgs--p" aria-label="Messages">${msgs.map((m) => html`
  <li class="msg ${m.mine ? 'msg--me' : 'msg--them'}">
    <div class="msg__h"><b>${m.mine ? 'You' : readOnly ? m.author : m.first}</b><time>${f.day(m.at)}, ${f.time(m.at)}</time></div>
    ${m.body ? html`<div class="msg__b">${m.body}</div>` : ''}${fileChips(m.files, readOnly ? 'api/chats/files' : undefined)}</li>`)}</ol>`;

// ---- admins: every conversation, read-only --------------------------------------------------

export async function adminList({ main, query }) {
  mount(main, skeleton('list'));
  const q = query.get('q') || '';
  let d;
  try { d = await api.get('/chats' + (q ? `?q=${encodeURIComponent(q)}` : '')); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <a class="crumb" href="#/mail">${icon('back', 'i--sm')}Trio Mail</a>
    <div class="ph"><div class="ph__t"><h1>Staff conversations</h1><p class="ph__s">Messages staff send each other in the CRM · ${f.plural(d.total, 'conversation')} in all · every time one is opened here is recorded</p></div></div>
    <form class="mailfilter" id="cs" role="search"><label class="sr" for="cs-q">Search</label>
      <input class="input" id="cs-q" name="q" type="search" value="${q}" placeholder="Search a name, subject or words"></form>
    <div class="card">${d.items.length ? html`<div class="tablewrap"><table class="rtable">
      <thead><tr><th>Between</th><th>Subject</th><th class="r hide-sm">Messages</th><th class="r">Last message</th></tr></thead>
      <tbody>${d.items.map((t) => html`<tr data-href="#/mail/staff/${t.id}">
        <td><a href="#/mail/staff/${t.id}"><b>${t.members.join(', ')}</b></a></td>
        <td>${t.subject}<small class="meta">${t.preview}</small></td>
        <td class="r hide-sm tnum">${t.messages}</td><td class="r nowrap">${stamp(t.last_at)}</td></tr>`)}</tbody></table></div>`
      : html`<div class="empty"><div class="dot d-neutral">${icon('mail')}</div><b>${q ? 'Nothing matches' : 'No conversations yet'}</b><p>${q ? 'Try another name or word.' : 'When staff write to each other in the CRM (Messages), the conversations appear here.'}</p></div>`}</div>`);
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
        ${t.members.map((m) => html`<div class="minirow"><span class="av av--sm">${m.initials}</span><div>${m.id ? html`<a class="name" href="#/people/${m.id}">${m.name}</a>` : html`<b>${m.name}</b>`}<small>${m.designation || ''}${m.centre ? ` · ${m.centre}` : ''}</small></div></div>`)}
      </div></div>
    </div>`);
}
