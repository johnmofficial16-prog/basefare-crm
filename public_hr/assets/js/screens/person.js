// One person's record (full page), adding a person, and editing.

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, openDrawer, openMenu, toast, busy, linkSheet } from '../core/ui.js';
import * as f from '../core/fmt.js';
import * as att from '../core/att.js';
import { go, setTitle, state } from '../app.js';

const STATUS_PILL = { joining: 'p-info', active: 'p-ok', notice: 'p-warn', left: 'p-neutral' };
const TONIGHT = {
  working: ['p-ok', 'Working now'], on_break: ['p-gold', 'On a break'], asking: ['p-bad', 'Waiting to clock in'],
  not_in: ['p-neutral', 'No clock-in tonight'], on_leave: ['p-info', 'On leave tonight'], finished: ['p-neutral', 'Done for the night'],
};
const REQ_PILL = { pending: 'p-warn', approved: 'p-ok', partly_approved: 'p-ok', sent_back: 'p-bad', withdrawn: 'p-neutral' };
const TABS = [['overview', 'Overview'], ['attendance', 'Attendance'], ['leave', 'Leave'], ['requests', 'Requests'], ['documents', 'Letters & documents'], ['private', 'Private details'], ['timeline', 'Timeline']];
const DOC_PILL = { verified: ['p-ok', 'Verified'], pending: ['p-warn', 'Waiting for a check'], rejected: ['p-bad', 'Sent back'] };
const LETTER_PILL = { draft: 'p-neutral', pending_check: 'p-warn', issued: 'p-ok', acknowledged: 'p-ok', void: 'p-bad' };
const PAN_STATUS = { not_checked: ['p-neutral', 'Not checked yet'], valid: ['p-ok', 'Checked: valid'], inoperative: ['p-bad', 'Inoperative'] };

const kv = (rows) => html`<dl class="kv">${rows.filter(Boolean).map(([k, v]) => html`<div><dt>${k}</dt><dd>${v === null || v === undefined || v === '' ? html`<span class="muted">Not added yet</span>` : v}</dd></div>`)}</dl>`;

function age(dob) {
  if (!dob) return '';
  const [y, m, d] = dob.split('-').map(Number);
  const t = f.todayIST().split('-').map(Number);
  let a = t[0] - y;
  if (t[1] < m || (t[1] === m && t[2] < d)) a--;
  return ` (${a})`;
}

// ---- record ----------------------------------------------------------------------

export async function render({ main, params, query }) {
  const id = params[0];
  const tab = TABS.some(([k]) => k === query.get('tab')) ? query.get('tab') : 'overview';
  mount(main, skeleton());
  let d;
  try {
    d = await api.get(`/people/${id}`);
  } catch (err) {
    mount(main, errorView(err));
    return;
  }
  const p = d.person;
  const hrState = d.account ? d.account.status : 'none';
  const pendingReq = d.requests.filter((r) => r.status === 'pending').length;
  setTitle(p.full_name);

  mount(main, html`
    <a class="crumb" href="#/people">${icon('back', 'i--sm')}People</a>
    <div class="hero">
      <span class="av av--xl">${p.initials}${d.tonight ? html`<i class="live s-${d.tonight.state}"></i>` : ''}</span>
      <div class="hero__t">
        <h1>${p.full_name}
          <span class="pill ${STATUS_PILL[p.status]}">${p.status_label}</span>
          ${hrState === 'active' ? html`<span class="pill p-ok">${icon('check', 'i--sm')}On Trio HR</span>` : hrState === 'invited' ? html`<span class="pill p-gold">Invited ${f.ago(d.account.invited_at)}</span>` : hrState === 'disabled' ? html`<span class="pill p-neutral">${icon('lock', 'i--sm')}Sign-in switched off</span>` : ''}
        </h1>
        <p class="sub">${p.designation || 'No job title yet'} · ${p.centre_name || 'No centre'}${p.team_leader ? html` · Team leader <a href="#/people/${p.team_leader.id}">${p.team_leader.name}</a>` : ''}</p>
        <div class="facts">
          <span>${icon('file', 'i--sm')}${p.employee_code}</span>
          ${p.tenure ? html`<span>${icon('cal', 'i--sm')}With Trio ${p.tenure}</span>` : ''}
          ${p.phone ? html`<span>${icon('phone', 'i--sm')}${p.phone}</span>` : ''}
          ${p.work_email || p.personal_email ? html`<span>${icon('mail', 'i--sm')}${p.work_email || p.personal_email}</span>` : ''}
        </div>
      </div>
      <div class="ph__a">
        ${hrState !== 'active' ? html`<button class="btn btn--gold" type="button" id="invite">${icon(state.emailOn ? 'send' : 'link')}${state.emailOn ? (hrState === 'invited' ? 'Send invite again' : 'Invite to Trio HR') : 'Get a set-up link'}</button>` : ''}
        <button class="btn btn--pri" type="button" id="edit">${icon('edit')}Edit</button>
        <button class="btn btn--icon" type="button" id="more" aria-label="More actions">${icon('more')}</button>
      </div>
    </div>
    ${p.missing.length && p.status !== 'left' ? html`<div class="banner banner--gold">${icon('info')}<div><b>Still missing: ${p.missing.join(', ')}.</b>${hrState === 'active' ? `${p.first} can add most of these from their phone.` : `Once ${p.first} joins Trio HR, they can add most of these themselves.`}</div></div>` : ''}
    <nav class="tabs" aria-label="Record sections">${TABS.map(([k, l]) => html`<a href="#/people/${id}?tab=${k}" ${k === tab ? raw('aria-current="page"') : ''}>${l}${k === 'requests' && pendingReq ? html`<em>${pendingReq}</em>` : ''}</a>`)}</nav>
    <div id="tab"></div>`);

  $('#edit', main).addEventListener('click', () => editDrawer(d));
  $('#invite', main)?.addEventListener('click', () => invite(d));
  $('#more', main).addEventListener('click', (e) => openMenu(e.currentTarget, [
    { label: 'Edit job and contact details', icon: 'edit', onClick: () => editDrawer(d) },
    { label: 'Add or change private details', icon: 'lock', onClick: () => privateDrawer(d) },
    { label: 'See attendance', icon: 'clock', href: `#/people/${id}?tab=attendance` },
    ...(d.account && d.account.role === 'employee' && d.account.status === 'active' ? [{ label: 'New sign-in link (forgotten password)', icon: 'link', onClick: () => newLink(d) }] : []),
    ...(d.account && d.account.role === 'employee' ? [d.account.status === 'disabled'
      ? { label: 'Switch Trio HR sign-in back on', icon: 'check', onClick: () => accessSheet(d, true) }
      : { label: 'Switch off Trio HR sign-in', icon: 'lock', onClick: () => accessSheet(d, false) }] : []),
  ]));

  const slot = $('#tab', main);
  const tabs = { overview, attendance, leave, requests, documents, private: priv, timeline };
  await tabs[tab](slot, d, id, query);
}

// One drawer at a time: a double click mustn't open the form twice.
let drawerOpen = false;

/** Switch someone's sign-in off (they are signed out everywhere) or back on. */
async function accessSheet(d, on) {
  const p = d.person;
  const r = await confirmSheet({
    title: on ? `Let ${p.first} sign in to Trio HR again?` : `Switch off ${p.first}'s Trio HR sign-in?`,
    text: on ? 'They can sign in with their old password. If they never set one, send the invite again.'
      : 'They are signed out everywhere at once and cannot sign in until an admin switches it back on. Their records stay as they are.',
    confirm: on ? 'Switch it back on' : 'Switch it off', tone: on ? 'pri' : 'danger',
    onConfirm: () => api.post(`/people/${p.id}/access`, { on }),
  });
  if (r) { toast(r.message); go(location.hash, { replace: true }); }
}

function overview(slot, d) {
  const p = d.person;
  const a = d.attendance;
  mount(slot, html`
  <div class="grid2">
    <div class="stack">
      <div class="card"><div class="card__h"><h2>Job</h2></div><div class="card__b">${kv([
        ['Job title', p.designation], ['Team', p.team], ['Team leader', p.team_leader ? html`<a href="#/people/${p.team_leader.id}">${p.team_leader.name}</a>` : null],
        ['Centre', p.centre_name], ['Employment', p.type_label + (p.contract_end ? ` until ${f.day(p.contract_end)}` : '')],
        ['Joined', p.date_joined ? `${f.day(p.date_joined)} · ${p.tenure}` : null],
        ['Notice period', p.notice_days !== null ? f.plural(p.notice_days, 'day') : null],
        p.last_working_day ? ['Last working day', f.day(p.last_working_day)] : null,
        ['Employee code', p.employee_code],
      ])}</div></div>
      <div class="card"><div class="card__h"><h2>Contact</h2></div><div class="card__b">${kv([
        ['Work email', p.work_email], ['Personal email', p.personal_email], ['Phone', p.phone],
        ['Date of birth', p.date_of_birth ? f.day(p.date_of_birth) + age(p.date_of_birth) : null], ['Gender', p.gender_label],
        ['Address', p.address],
      ])}</div></div>
      <div class="card"><div class="card__h"><h2>Emergency contact</h2></div><div class="card__b">${kv([
        ['Name', p.emergency_name], ['Relationship', p.emergency_relation], ['Phone', p.emergency_phone],
      ])}</div></div>
      <div class="card"><div class="card__h"><h2>Statutory</h2><a href="#/people/${p.id}?tab=private">Private details</a></div><div class="card__b">${kv([
        ['PAN', d.private.pan.set ? html`••••••${d.private.pan.hint} <span class="pill pill--sm ${PAN_STATUS[p.pan_status || 'not_checked'][0]}">${PAN_STATUS[p.pan_status || 'not_checked'][1]}</span>` : null],
        ['UAN (PF)', p.uan || html`<span class="muted">Only if PF applies</span>`],
        ['ESIC number', p.esic_ip || html`<span class="muted">Only if ESI applies</span>`],
      ])}</div></div>
    </div>
    <div class="stack">
      ${a && a.now && a.now.items.length ? html`<div class="card"><div class="card__h"><h2>Today</h2><span class="pill ${att.DAY[a.now.state]?.pill || 'p-neutral'}">${att.DAY[a.now.state]?.label}</span></div>
        <div class="card__b">${kv(a.now.items.map((i) => [i.kind === 'crm' ? 'CRM clock-in' : 'Trio HR punch', `${att.span(i)}${i.minutes ? ' · ' + f.hm(i.minutes) : i.running !== null ? ` · ${f.hm(i.running)} so far` : ''}`]))}</div></div>`
        : d.tonight && d.tonight.state === 'asking' ? html`<div class="card"><div class="card__h"><h2>Today</h2><span class="pill ${TONIGHT.asking[0]}">${TONIGHT.asking[1]}</span></div>
        <div class="card__b"><p class="meta">The CRM's clock-in gate stopped ${p.first}. Decide in the CRM live board.</p></div></div>` : ''}
      ${a && a.month ? html`<div class="card"><div class="card__h"><h2>${a.month.label}</h2><a href="#/people/${p.id}?tab=attendance">Day by day</a></div>
        <div class="card__b">
          <div class="kv kv--3 tnum"><div><dt>Days worked</dt><dd class="strong">${a.month.worked}</dd></div><div><dt>Hours</dt><dd class="strong">${Math.round(a.month.minutes / 60)}</dd></div><div><dt>Average day</dt><dd class="strong">${a.month.avg_minutes ? f.hm(a.month.avg_minutes) : '—'}</dd></div></div>
          <div class="label strip-k">Last 14 days · ${f.lower(a.via.label)}</div>
          <div class="strip" aria-label="Last 14 days">${a.strip.map((x) => html`<div class="st-${x.state}" title="${f.day(x.day)}: ${x.text}"><i></i><span>${f.dayNum(x.day)}</span><span class="sr">${f.day(x.day)}: ${x.text}</span></div>`)}</div>
          ${att.legend()}
        </div></div>` : ''}
      ${d.sales ? salesCard(d) : ''}
      <div class="card"><div class="card__h"><h2>Messages</h2><a href="#/mail/new?person=${p.id}">Write to ${p.first}</a></div>
        <div class="card__b list-tight">${d.mail.length ? d.mail.map((t) => html`<a class="listrow" href="#/mail/${t.id}">${icon(t.waiting ? 'clock' : 'mail')}<div><b>${t.subject}</b><small>${t.status === 'done' ? 'Done' : t.waiting ? 'Waiting on HR' : 'Open'} · ${f.day(t.at)}</small></div><span class="r">${icon('chev', 'chev')}</span></a>`)
          : html`<p class="muted">No messages with ${p.first} yet.</p>`}</div></div>
      <div class="card"><div class="card__h"><h2>CRM login</h2>${d.crm ? html`<span class="pill pill--sm ${d.crm.status === 'active' ? 'p-ok' : 'p-bad'}">${d.crm.status === 'active' ? 'Active' : d.crm.status === 'deleted' ? 'Deleted' : 'Switched off'}</span>` : ''}</div>
        <div class="card__b">${d.crm ? kv([
          ['Signs in as', d.crm.email], ['Role in the CRM', d.crm.role], ['CRM team leader', d.crm.lead],
        ]) : html`<p class="muted">Not linked to a CRM login. Link one with Edit if ${p.first} uses the CRM.</p>`}</div></div>
      ${p.reports.length ? html`<div class="card"><div class="card__h"><h2>Team</h2><span class="sub">${f.plural(p.reports.length, 'person', 'people')}</span></div>
        <div class="card__b card__b--tight">${p.reports.map((r) => html`<div class="minirow"><span class="av av--sm">${r.initials}</span><div><a class="name" href="#/people/${r.id}">${r.name}</a><small>${r.designation || ''}</small></div></div>`)}</div></div>` : ''}
    </div>
  </div>`);
}

/** Last six months of sales from the CRM: approved bookings and Net MCO, as Performance counts them. */
function salesCard(d) {
  const s = d.sales;
  const money = (n) => `${s.currency} ${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Math.round(n))}`;
  const best = Math.max(1, ...s.months.map((m) => Math.max(0, m.net)));
  const total = s.months.reduce((a, m) => a + m.bookings, 0);
  return html`<div class="card"><div class="card__h"><h2>Sales from the CRM</h2><span class="sub">Last 6 months</span></div>
    <div class="card__b">${total ? html`<table class="mini tnum"><thead><tr><th>Month</th><th class="r">Bookings</th><th class="r">Net MCO</th><th><span class="sr">Bar</span></th></tr></thead>
      <tbody>${s.months.map((m) => html`<tr><td>${m.label}</td><td class="r">${m.bookings}</td><td class="r ${m.net < 0 ? 'down' : ''}">${money(m.net)}</td>
        <td class="barcell"><div class="bar bar--thin"><i data-w="${Math.max(0, (m.net / best) * 100)}"></i></div></td></tr>`)}</tbody></table>
      <p class="meta">Approved bookings only. Net MCO is profit after refund losses, as on the CRM's Performance page.</p>`
      : html`<p class="muted">No approved bookings in the last 6 months.</p>`}</div></div>`;
}

async function attendance(slot, d, id, query) {
  const month = /^\d{4}-\d{2}$/.test(query.get('month') || '') ? query.get('month') : '';
  mount(slot, skeleton('list'));
  let m;
  try { m = await api.get(`/people/${id}/attendance` + (month ? `?month=${month}` : '')); } catch (err) { mount(slot, errorView(err)); return; }
  const s = m.sum;
  const first = d.person.first;
  const canDecide = (x) => x.state === 'no_punch' && !m.locked;
  mount(slot, html`
    <div class="grid2">
    <div class="stack">
    <div class="card">
      <div class="card__h"><h2>${m.label}</h2>
        <span class="row"><a class="btn btn--sm" href="#/people/${id}?tab=attendance&month=${m.prev}">${icon('back', 'i--sm')}Earlier</a>
        ${m.next ? html`<a class="btn btn--sm" href="#/people/${id}?tab=attendance&month=${m.next}">Later${icon('chev', 'i--sm')}</a>` : ''}</span></div>
      ${m.locked ? html`<div class="card__b"><div class="banner banner--ok">${icon('lock')}<div><b>Locked for payroll.</b>Unlock the month in Attendance → Close the month to change anything.</div></div></div>` : ''}
      ${m.days.length ? html`<div>${m.days.map((x) => html`<div class="night st-${x.state}">
        <div class="d"><b>${f.day(x.day)}</b><small>${att.DAY[x.state]?.label || ''}</small></div>
        <div class="s">${x.items.length ? x.items.map((i) => html`<div class="tnum">${att.span(i)}
              <small>${i.minutes ? f.hm(i.minutes) : i.state === 'open' ? `${f.hm(i.running)} so far` : 'No hours until the punch-out is fixed'}${i.break_mins ? ` · breaks ${f.hm(i.break_mins)}` : ''} · ${i.kind === 'crm' ? 'CRM clock-in' : 'Trio HR punch'}${i.by_admin ? ' by an admin' : ''}${i.note ? ` · ${f.lowerFirst(i.note)}` : ''}</small></div>`)
          : html`<span class="${x.state === 'no_punch' ? 'muted' : ''}">${x.text}</span>`}
          ${x.items.length && (x.holiday || (x.leave && x.state !== 'leave')) ? html`<small class="meta">${x.holiday ? `Holiday: ${x.holiday}` : `On approved ${f.lower(x.leave.name)}`}</small>` : ''}</div>
        <div class="r">${x.items.map((i) => i.fix ? html`<a class="pill pill--sm p-warn" href="#/approvals?id=${i.fix.id}">Fix asked: out ${f.time(i.fix.out)}</a>`
            : i.state === 'missing' ? html`<span class="pill pill--sm p-bad">No punch-out</span>` : i.state === 'open' ? html`<span class="pill pill--sm p-ok">In now</span>` : '')}
          ${x.state === 'leave' ? html`<span class="pill pill--sm p-info">${x.leave.name}</span>` : ''}
          ${x.state === 'holiday' ? html`<span class="pill pill--sm p-gold">Holiday</span>` : ''}
          ${x.mark ? html`<span class="pill pill--sm p-neutral">${x.mark.label}</span>` : ''}
          ${x.pending_leave && x.state !== 'leave' ? html`<a class="pill pill--sm p-warn" href="#/approvals?id=${x.pending_leave}">Leave asked</a>` : ''}
          ${canDecide(x) ? html`<button class="btn btn--sm btn--quiet" type="button" data-decide="${x.day}" aria-label="Decide ${f.day(x.day)}">${x.mark ? 'Change' : 'Decide'}</button>` : ''}</div>
      </div>`)}</div>` : html`<div class="empty"><b>Nothing this month</b><p>No punches, leave or holidays in ${m.label}.</p></div>`}
    </div>
    <p class="meta">Facts from punches only: ${m.via.via === 'crm' ? `${first}'s CRM clock-ins` : `${first}'s Trio HR punches`}. Hours are punch-in to punch-out; breaks are listed apart.
      A day runs 6 am to 6 am India time. Days with no punch are decided at month end: weekly off, unpaid, or worked with the punch missed.</p>
    </div>
    <div class="stack">
      ${s ? html`<div class="card"><div class="card__h"><h2>The month in numbers</h2></div><div class="card__b">${kv([
        ['Days worked', String(s.worked + s.worked_marked) + (s.worked_marked ? ` (${s.worked_marked} with the punch missed)` : '')],
        ['Hours', f.hm(s.minutes)],
        ['Paid leave', f.plural(s.paid_leave, 'day')],
        ['Holidays', f.plural(s.holidays, 'day') + (s.holiday_worked ? ` · worked ${s.holiday_worked}` : '')],
        ['Weekly offs', f.plural(s.day_off, 'day')],
        ['Unpaid', f.plural(s.unpaid_days, 'day')],
        ['Still to decide', s.no_punch ? html`<b class="t-warn">${f.plural(s.no_punch, 'day')} with no punch</b>` : 'Nothing'],
      ])}</div></div>` : ''}
      <div class="card"><div class="card__h"><h2>How ${first} punches</h2></div><div class="card__b">
        <p><b>${m.via.label}</b><br><span class="meta">${m.via.why}</span></p>
        <div class="seg" role="group" aria-label="How ${first} punches">
          <button type="button" data-via="auto" aria-pressed="${!m.via.set}">From their CRM role</button>
          <button type="button" data-via="crm" aria-pressed="${m.via.set === 'crm'}" ${m.via.can_crm ? '' : 'disabled'}>CRM clock-in</button>
          <button type="button" data-via="hr" aria-pressed="${m.via.set === 'hr'}">Trio HR button</button>
        </div>
        <p class="meta">The Trio HR button works on the office network only, the same addresses the CRM allows.</p>
      </div></div>
    </div>
    </div>`);
  slot.addEventListener('click', async (e) => {
    const v = e.target.closest('[data-via]');
    if (v && !v.disabled && v.getAttribute('aria-pressed') !== 'true') {
      try { const r = await api.post(`/people/${id}/punch-via`, { via: v.dataset.via }); toast(r.message); go(location.hash, { replace: true }); }
      catch (err) { toast(err.message, { tone: 'bad' }); }
      return;
    }
    const b = e.target.closest('[data-decide]');
    if (!b) return;
    const day = b.dataset.decide;
    openMenu(b, [
      ...Object.entries(att.MARK).map(([k, label]) => ({ label, onClick: () => decide(k) })),
      { label: 'Not decided', onClick: () => decide('clear') },
    ], `${f.day(day)}: no punch`);
    async function decide(mark) {
      try { const r = await api.post('/attendance/mark', { employee_id: id, days: [day], mark }); toast(r.message); go(location.hash, { replace: true }); }
      catch (err) { toast(err.message, { tone: 'bad' }); }
    }
  });
}

function leave(slot, d) {
  const reqs = d.requests.filter((r) => r.type === 'leave');
  mount(slot, html`
    <div class="kpis">${d.leave.map((b) => html`<div class="card kpi"><div class="k">${b.name}</div>
      <div class="v tnum">${f.bal(b.available)} <small>of ${f.bal(b.total)}</small></div>
      <div class="d">${f.bal(b.used)} used${b.pending ? ` · ${f.bal(b.pending)} waiting` : ''}</div></div>`)}</div>
    <div class="card"><div class="card__h"><h2>Leave requests</h2></div>
      ${reqs.length ? html`<div>${reqs.map(reqRow)}</div>` : html`<div class="empty"><b>No leave asked for yet</b></div>`}</div>
    <p class="meta">Balances for ${f.todayIST().slice(0, 4)}. Accrual rules, holidays and comp-off arrive with the Leave module in Phase 2.</p>`);
}

const reqRow = (r) => html`<div class="listrow">
  <div><b>${r.summary}</b><small>${r.type_label} · asked ${f.day(r.created_at)}${r.decided_at ? ` · decided ${f.day(r.decided_at)}` : ''}</small></div>
  <span class="r">${r.status === 'pending' ? html`<a class="btn btn--sm" href="#/approvals?id=${r.id}">Review</a>` : html`<span class="pill pill--sm ${REQ_PILL[r.status]}">${r.status_label}</span>`}</span></div>`;

function requests(slot, d) {
  mount(slot, html`<div class="card"><div class="card__h"><h2>Requests</h2><span class="sub">Everything ${d.person.first} has asked for</span></div>
    ${d.requests.length ? html`<div>${d.requests.map(reqRow)}</div>` : html`<div class="empty"><b>No requests yet</b><p>Leave, attendance fixes and profile changes ${d.person.first} sends from their phone will show here.</p></div>`}</div>`);
}

function documents(slot, d, id) {
  const p = d.person;
  mount(slot, html`
    <div class="grid2">
      <div class="card"><div class="card__h"><h2>Letters</h2><a href="#/letters/new?person=${p.id}">New letter for ${p.first}</a></div>
        ${d.letters.length ? html`<div>${d.letters.map((l) => html`<a class="listrow" href="#/letters/${l.id}">${icon('file')}
          <div><b>${l.name}</b><small>${l.number || 'Not numbered yet'}${l.issued_at ? ` · issued ${f.day(l.issued_at)}` : ''}${l.ack_at ? ` · acknowledged ${f.day(l.ack_at)}` : ''}</small></div>
          <span class="r"><span class="pill pill--sm ${LETTER_PILL[l.status]}">${l.status_label}</span></span></a>`)}</div>`
          : html`<div class="empty"><b>No letters yet</b><p>Appointment, confirmation and experience letters for ${p.first} show here.</p></div>`}</div>
      <div class="card"><div class="card__h"><h2>Documents</h2><span class="sub">Encrypted · every opening is recorded</span></div>
        ${d.documents.length ? html`<div>${d.documents.map((x) => html`<div class="listrow">
          ${icon(x.mime === 'application/pdf' ? 'file' : 'image')}
          <div><b>${x.title} <span class="pill pill--sm ${DOC_PILL[x.status][0]}">${DOC_PILL[x.status][1]}</span></b><small>${x.title !== x.kind_label ? `${x.kind_label} · ` : ''}${x.file_name} · ${Math.max(1, Math.round(x.size / 1024))} KB · ${x.uploaded_by ? `added by ${x.uploaded_by}, ` : 'added '}${f.day(x.at)}${x.reject_note ? ` · ${x.reject_note}` : ''}</small></div>
          <span class="r row">
            <a class="btn btn--sm" href="api/documents/${x.id}/file" target="_blank" rel="noopener">Open</a>
            <button class="btn btn--sm btn--quiet btn--icon" type="button" data-remove="${x.id}" aria-label="Remove ${x.title}">${icon('x', 'i--sm')}</button></span></div>`)}</div>`
          : html`<div class="empty"><b>No documents yet</b><p>Once ${p.first} joins Trio HR, they can upload their own from their phone.</p></div>`}
        <form class="card__b form upform" id="upf" novalidate>
          <b>Add a document</b>
          <div class="form__grid">
            <div class="field"><label for="u-kind">What is it?</label><select class="select" id="u-kind" name="kind">${Object.entries(d.doc_kinds).map(([k, l]) => html`<option value="${k}">${l}</option>`)}</select></div>
            <div class="field"><label for="u-title">Title <span class="opt">(optional)</span></label><input class="input" id="u-title" name="title"></div>
            <div class="field wide"><label for="u-file">File</label><input class="input input--file" id="u-file" name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"><div class="help">PDF or photo, up to 8 MB.</div><div class="err" role="alert"></div></div>
          </div>
          <div class="row"><button class="btn btn--pri" type="submit">${icon('plus')}Upload</button></div>
        </form></div>
    </div>`);
  const form = $('#upf', slot);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const file = form.file.files[0];
    if (!file) { showFieldErrors(form, { file: 'Choose a file first.' }); return; }
    if (file.size > 8 * 1024 * 1024) { showFieldErrors(form, { file: 'That file is over 8 MB.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.upload(`/people/${id}/documents`, file, { 'X-Doc-Kind': form.kind.value, 'X-Doc-Title': form.title.value.trim() });
        toast(r.message);
        go(`#/people/${id}?tab=documents`, { replace: true });
      } catch (err) { showFieldErrors(form, { file: err.message }); }
    });
  });
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-remove]');
    if (!b) return;
    const r = await confirmSheet({ title: 'Remove this document?', text: "It disappears from the record. The encrypted file is kept for the audit trail.", confirm: 'Remove', tone: 'danger', onConfirm: () => api.del(`/documents/${b.dataset.remove}`) });
    if (r) { toast(r.message); go(`#/people/${id}?tab=documents`, { replace: true }); }
  });
}

function priv(slot, d, id) {
  const fields = ['pan', 'aadhaar', 'bank_account', 'bank_ifsc', 'bank_name', 'bank_holder'];
  mount(slot, html`
    <div class="grid2">
      <div class="card"><div class="card__h"><h2>Private details</h2><button class="btn btn--sm" type="button" id="setpriv">${icon('edit', 'i--sm')}Add or change</button></div>
        <div class="card__b">
          ${!d.vault ? html`<div class="banner banner--warn">${icon('alert')}<div><b>The HR vault key isn't set up.</b>Private details can't be opened or saved until it is.</div></div>` : ''}
          ${fields.map((k) => { const s = d.private[k]; return html`<div class="secret">
            <div><div class="label">${s.label}</div><div class="v" data-val="${k}">${s.set ? (s.hint ? (k === 'bank_ifsc' ? `${s.hint}•••••••` : `••••••${s.hint}`) : '••••••••') : html`<span class="muted">Not added</span>`}</div></div>
            <div class="r">${s.set ? html`<button class="btn btn--sm" type="button" data-reveal="${k}">${icon('eye', 'i--sm')}Show</button>` : ''}</div></div>`; })}
          <p class="meta">Encrypted with a key only Trio HR holds. Every look is recorded.</p>
        </div></div>
      <div class="card"><div class="card__h"><h2>Who has looked</h2></div><div class="card__b card__b--tight">
        ${d.access.length ? d.access.map((a) => html`<div class="minirow">${icon('eye', 'i--sm')}<div>${a.who} · ${f.lower(a.what)}<small>${f.day(a.at)}, ${f.time(a.at)}</small></div></div>`)
          : html`<p class="muted">No one has opened ${d.person.first}'s private details yet.</p>`}
      </div></div>
    </div>`);
  $('#setpriv', slot).addEventListener('click', () => privateDrawer(d));
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-reveal]');
    if (!b) return;
    const field = b.dataset.reveal;
    const here = location.hash;
    const show = (value) => {
      $(`[data-val="${field}"]`, slot).textContent = value;
      b.remove();
      // Hide it again after a minute, if the page is still open.
      setTimeout(() => { if (location.hash === here && document.contains(slot)) go(here, { replace: true }); }, 60000);
    };
    try {
      const r = await api.post(`/people/${id}/reveal`, { field });
      show(r.value);
    } catch (err) {
      if (err.reason !== 'reauth_required') { toast(err.message, { tone: 'bad' }); return; }
      const r = await confirmSheet({
        title: `Show ${f.lower(d.private[field].label)}?`,
        text: `For safety, enter your own Trio HR password. Looking is recorded on ${d.person.first}'s record.`,
        input: { kind: 'password', label: 'Your password', required: true, requiredText: 'Enter your password.' },
        confirm: 'Show it',
        onConfirm: (password) => api.post(`/people/${id}/reveal`, { field, password }),
      });
      if (r) show(r.value);
    }
  });
}

function timeline(slot, d) {
  mount(slot, html`<div class="card"><div class="card__h"><h2>Timeline</h2><span class="sub">Every change, dated and signed</span></div><div class="card__b">
    ${d.timeline.length ? html`<div class="timeline">${d.timeline.map((t) => html`<div class="tl">
      <time>${f.day(t.on || t.at)}</time><div class="rail"><i></i></div>
      <div class="t">${t.text}<small>${t.by ? `By ${t.by} · ` : ''}${f.day(t.at)}, ${f.time(t.at)}</small></div></div>`)}</div>`
      : html`<p class="muted">Nothing recorded yet.</p>`}
  </div></div>`);
}

// ---- actions ------------------------------------------------------------------------

async function invite(d) {
  const p = d.person;
  const email = p.work_email || p.personal_email;
  if (!email) {
    toast(`Add an email address for ${p.first} first.`, { tone: 'bad' });
    editDrawer(d);
    return;
  }
  const again = d.account?.status === 'invited';
  if (!state.emailOn) {
    try {
      const r = await api.post(`/people/${p.id}/invite`);
      if (r.link) await linkSheet(r.link); else toast(r.message);
      go(location.hash, { replace: true });
    } catch (err) { toast(err.message, { tone: 'bad' }); }
    return;
  }
  const r = await confirmSheet({
    title: again ? `Send ${p.first}'s invite again?` : `Invite ${p.first} to Trio HR?`,
    text: `${p.first} gets an email with a link to set a password and check their details. ${again ? 'The earlier link stops working.' : 'The link works for 7 days.'}`,
    summary: [['Sends to', email]],
    confirm: again ? 'Send again' : 'Send the invite',
    onConfirm: () => api.post(`/people/${p.id}/invite`),
  });
  if (r) { toast(r.message); go(location.hash, { replace: true }); }
}

/** A forgotten password: a fresh link for the admin to hand over. */
async function newLink(d) {
  const p = d.person;
  const r = await confirmSheet({
    title: `Make a new sign-in link for ${p.first}?`,
    text: `${p.first} opens it to choose a new password. It works once, for 24 hours. Their current password keeps working until they do.`,
    confirm: 'Make the link',
    onConfirm: () => api.post(`/people/${p.id}/signin-link`),
  });
  if (r && r.link) await linkSheet(r.link);
}

function privateDrawer(d) {
  const p = d.person;
  if (drawerOpen) return;
  drawerOpen = true;
  const layer = openDrawer({
    title: `Private details · ${p.first}`,
    onClose: () => { drawerOpen = false; },
    body: html`<form class="form" id="pf" novalidate>
      <div class="banner banner--info">${icon('lock')}<div><b>Fill in only what you want to change.</b>Empty boxes keep what's on file. Saving needs your password.</div></div>
      <div class="form__grid">
        ${field('pan', 'PAN', { placeholder: 'ABCDE1234F', autocomplete: 'off' })}
        ${field('aadhaar', 'Aadhaar', { placeholder: '12 digits', inputmode: 'numeric', autocomplete: 'off', opt: true })}
        ${field('bank_holder', 'Name on the bank account', { wide: true })}
        ${field('bank_account', 'Account number', { inputmode: 'numeric', autocomplete: 'off' })}
        ${field('bank_ifsc', 'IFSC', { placeholder: 'HDFC0001234', autocomplete: 'off' })}
        ${field('bank_name', 'Bank', { placeholder: 'HDFC Bank', wide: true })}
        ${field('password', 'Your Trio HR password', { type: 'password', autocomplete: 'current-password', wide: true, help: 'Needed to save private details.' })}
      </div></form>`,
    foot: html`<button class="btn btn--quiet" type="button" data-close>Cancel</button><button class="btn btn--pri" type="button" id="psave">Save private details</button>`,
  });
  const form = $('#pf', layer.el);
  $('[data-close]', layer.el).addEventListener('click', () => layer.close());
  $('#psave', layer.el).addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const data = formData(form);
    const body = Object.fromEntries(Object.entries(data).filter(([, v]) => v !== ''));
    const details = Object.keys(body).filter((k) => k !== 'password');
    if (!details.length) { showFieldErrors(form, { pan: 'Fill in at least one detail.' }); return; }
    if (!body.password) { showFieldErrors(form, { password: 'Enter your Trio HR password to save.' }); return; }
    try {
      const r = await api.post(`/people/${p.id}/private`, body);
      layer.close();
      toast(r.changed ? `Saved ${f.plural(r.changed, 'detail')}.` : 'Nothing changed.');
      go(`#/people/${p.id}?tab=private`, { replace: true });
    } catch (err) {
      if (err.fields) showFieldErrors(form, err.fields);
      else if (err.reason === 'reauth_failed' || err.reason === 'reauth_required') showFieldErrors(form, { password: err.message });
      else toast(err.message, { tone: 'bad' });
    }
  }));
}

// ---- add / edit form ------------------------------------------------------------------

function field(name, label, o = {}) {
  const id = 'f-' + name;
  const v = o.value ?? '';
  let control;
  if (o.options) {
    control = html`<select class="select" id="${id}" name="${name}">${o.blank !== false ? html`<option value="">${o.blank || 'Choose…'}</option>` : ''}${o.options.map(([val, lab]) => html`<option value="${val}" ${String(val) === String(v) ? 'selected' : ''}>${lab}</option>`)}</select>`;
  } else if (o.textarea) {
    control = html`<textarea class="textarea" id="${id}" name="${name}" rows="2">${v}</textarea>`;
  } else {
    control = html`<input class="input" id="${id}" name="${name}" type="${o.type || 'text'}" value="${v}" ${o.list ? html`list="${o.list}"` : ''} ${o.placeholder ? html`placeholder="${o.placeholder}"` : ''} ${o.inputmode ? html`inputmode="${o.inputmode}"` : ''} autocomplete="${o.autocomplete || 'off'}">`;
  }
  return html`<div class="field ${o.wide ? 'wide' : ''}" ${o.hidden ? raw('hidden') : ''} data-f="${name}">
    <label for="${id}">${label}${o.opt ? html` <span class="opt">(optional)</span>` : ''}${o.req ? html` <span class="sr">(required)</span>` : ''}</label>
    ${control}${o.help ? html`<div class="help">${o.help}</div>` : ''}<div class="err" role="alert"></div></div>`;
}

function personForm(meta, p = {}, isEdit = false) {
  const leaders = meta.leaders.map((l) => [l.id, l.name + (l.designation ? ` · ${l.designation}` : '')]);
  const crm = [...(isEdit && p.crm_user_id ? [[p.crm_user_id, 'Keep the current CRM login']] : []), ...meta.crm_users.map((u) => [u.id, `${u.name} · ${u.email} · ${u.role}`])];
  return html`<form class="form" id="pform" novalidate>
    <div class="banner banner--bad formerr" id="formerr" role="alert">${icon('alert')}<div></div></div>
    <fieldset class="fieldset"><legend>About them</legend><div class="form__grid">
      ${field('full_name', 'Full name', { value: p.full_name, wide: true, req: true, autocomplete: 'off', help: 'As on their PAN or Aadhaar.' })}
      ${field('preferred_name', 'Preferred name', { value: p.preferred_name, opt: true, help: 'What colleagues call them.' })}
      ${field('gender', 'Gender', { value: p.gender, options: Object.entries(meta.genders), opt: true })}
      ${field('date_of_birth', 'Date of birth', { value: p.date_of_birth, type: 'date', opt: true })}
    </div></fieldset>
    <fieldset class="fieldset"><legend>Job</legend><div class="form__grid">
      ${field('designation', 'Job title', { value: p.designation, list: 'dl-designations', req: true, placeholder: 'Travel agent' })}
      ${field('team', 'Team', { value: p.team, list: 'dl-teams', opt: true })}
      ${field('centre', 'Centre', { value: p.centre, options: meta.centres.map((c) => [c.code, c.name + (c.state ? `, ${c.state}` : '')]), req: true })}
      ${field('reports_to_id', 'Team leader', { value: p.reports_to_id, options: leaders, blank: 'No team leader', opt: true })}
      ${field('employment_type', 'Employment type', { value: p.employment_type || 'permanent', options: Object.entries(meta.types), blank: false })}
      ${field('contract_end', 'Contract ends', { value: p.contract_end, type: 'date', hidden: (p.employment_type || 'permanent') !== 'fixed_term' })}
      ${field('date_joined', 'Joining date', { value: p.date_joined, type: 'date', req: true })}
      ${field('notice_days', 'Notice period (days)', { value: p.notice_days, type: 'number', inputmode: 'numeric', opt: true })}
      ${isEdit ? field('status', 'Status', { value: p.status, options: Object.entries(meta.statuses), blank: false }) : ''}
      ${isEdit ? field('last_working_day', 'Last working day', { value: p.last_working_day, type: 'date', hidden: !['notice', 'left'].includes(p.status) }) : ''}
    </div></fieldset>
    <fieldset class="fieldset"><legend>Contact</legend><div class="form__grid">
      ${field('work_email', 'Work email', { value: p.work_email, type: 'email', help: 'Their Trio HR invitation goes here.' })}
      ${field('personal_email', 'Personal email', { value: p.personal_email, type: 'email', opt: true })}
      ${field('phone', 'Mobile', { value: p.phone, type: 'tel', inputmode: 'tel', opt: true })}
      ${field('address', 'Address', { value: p.address, textarea: true, wide: true, opt: true })}
      ${field('emergency_name', 'Emergency contact', { value: p.emergency_name, opt: true })}
      ${field('emergency_relation', 'Relationship', { value: p.emergency_relation, opt: true })}
      ${field('emergency_phone', 'Emergency phone', { value: p.emergency_phone, type: 'tel', opt: true })}
    </div></fieldset>
    ${isEdit ? html`<fieldset class="fieldset"><legend>Statutory</legend><div class="form__grid">
      ${field('pan_status', 'PAN checked on the income-tax site', { value: p.pan_status || 'not_checked', options: [['not_checked', 'Not checked yet'], ['valid', 'Valid'], ['inoperative', 'Inoperative (not linked to Aadhaar)']], blank: false })}
      ${field('uan', 'UAN (PF)', { value: p.uan, opt: true, inputmode: 'numeric', help: 'Only if PF applies.' })}
      ${field('esic_ip', 'ESIC number', { value: p.esic_ip, opt: true, inputmode: 'numeric', help: 'Only if ESI applies.' })}
    </div></fieldset>` : ''}
    <fieldset class="fieldset"><legend>CRM login</legend><div class="form__grid">
      ${field('crm_user_id', 'Their CRM login', { value: p.crm_user_id, options: crm, blank: 'No CRM login', wide: true, help: 'Links their clock-ins and sales to this record. Logins are still created in the CRM for now.' })}
    </div></fieldset>
    ${isEdit ? html`<fieldset class="fieldset"><legend>When do job changes count from?</legend><div class="form__grid">
      ${field('effective_on', 'Effective date', { value: f.todayIST(), type: 'date', help: 'Used on the timeline for job title, team, centre or status changes.' })}
    </div></fieldset>` : ''}
    <datalist id="dl-designations">${meta.designations.map((x) => html`<option value="${x}">`)}</datalist>
    <datalist id="dl-teams">${meta.teams.map((x) => html`<option value="${x}">`)}</datalist>
  </form>`;
}

function wireForm(form) {
  const type = form.employment_type;
  type?.addEventListener('change', () => { $('[data-f="contract_end"]', form).hidden = type.value !== 'fixed_term'; });
  const st = form.status;
  st?.addEventListener('change', () => { $('[data-f="last_working_day"]', form).hidden = !['notice', 'left'].includes(st.value); });
}

function collect(form, original = null) {
  const data = formData(form);
  const out = {};
  for (const [k, v] of Object.entries(data)) {
    if (original) {
      const before = original[k] === null || original[k] === undefined ? '' : String(original[k]);
      if (k !== 'effective_on' && before === v) continue;
    }
    out[k] = v === '' ? null : v;
  }
  return out;
}

async function editDrawer(d) {
  if (drawerOpen) return;
  drawerOpen = true;
  let meta;
  try { meta = await api.get(`/people/meta?except=${d.person.id}`); } catch (err) { drawerOpen = false; toast(err.message, { tone: 'bad' }); return; }
  const layer = openDrawer({
    title: `Edit ${d.person.first}`,
    wide: true,
    onClose: () => { drawerOpen = false; },
    body: personForm(meta, d.person, true),
    foot: html`<button class="btn btn--quiet" type="button" data-close>Cancel</button><button class="btn btn--pri" type="button" id="save">Save changes</button>`,
  });
  const form = $('#pform', layer.el);
  wireForm(form);
  $('[data-close]', layer.el).addEventListener('click', () => layer.close());
  $('#save', layer.el).addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const body = collect(form, d.person);
    if (Object.keys(body).filter((k) => k !== 'effective_on').length === 0) { layer.close(); toast('Nothing changed.'); return; }
    try {
      const r = await api.patch(`/people/${d.person.id}`, body);
      layer.close();
      toast(r.changed ? `Saved. ${f.plural(r.changed, 'change')} recorded on the timeline.${r.message ? ` ${r.message}` : ''}` : 'Nothing changed.');
      go(location.hash, { replace: true });
    } catch (err) {
      if (!showFieldErrors(form, err.fields)) {
        const box = $('#formerr', form);
        $('div', box).textContent = err.message;
        box.classList.add('is-on');
      }
    }
  }));
}

export async function create({ main }) {
  mount(main, skeleton('list'));
  let meta;
  try { meta = await api.get('/people/meta'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <a class="crumb" href="#/people">${icon('back', 'i--sm')}People</a>
    <div class="ph"><div class="ph__t"><h1>Add a person</h1>
      <p class="ph__s">Just the basics. Once invited, they fill in the rest themselves from their phone.</p></div></div>
    <div class="card"><div class="card__b">${personForm(meta, { date_joined: f.todayIST() })}</div>
      <div class="actbar">
        <label class="check-row"><input type="checkbox" id="sendinvite" checked> ${state.emailOn ? 'Send the Trio HR invitation now' : 'Make their Trio HR set-up link now'}</label>
        <span class="push"></span>
        <a class="btn btn--quiet" href="#/people">Cancel</a>
        <button class="btn btn--pri" type="button" id="add">${icon('plus')}Add person</button>
      </div></div>`);
  const form = $('#pform', main);
  wireForm(form);
  $('#f-full_name', main).focus();
  $('#add', main).addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const body = collect(form);
    const fields = {};
    if (!body.full_name) fields.full_name = 'Full name is needed.';
    if (!body.designation) fields.designation = 'Job title is needed.';
    if (!body.centre) fields.centre = 'Pick a centre.';
    if (!body.date_joined) fields.date_joined = 'Joining date is needed.';
    if (showFieldErrors(form, fields)) return;
    try {
      const r = await api.post('/people', body);
      let msg = `${body.full_name} was added.`;
      let failed = false;
      if ($('#sendinvite', main).checked && (body.work_email || body.personal_email)) {
        try {
          const inv = await api.post(`/people/${r.id}/invite`);
          if (inv.link) await linkSheet(inv.link); else msg += ' ' + inv.message;
        } catch (err) { failed = true; msg += ` The invite didn't go: ${err.message}`; }
      }
      toast(msg, { tone: failed ? 'bad' : 'ok' });
      go(`#/people/${r.id}`, { replace: true });
    } catch (err) {
      if (!showFieldErrors(form, err.fields)) {
        const box = $('#formerr', form);
        $('div', box).textContent = err.message;
        box.classList.add('is-on');
        box.scrollIntoView({ block: 'center' });
      }
    }
  }));
}
