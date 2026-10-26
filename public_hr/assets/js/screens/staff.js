// The staff app (phone-first): Home (with the punch), Attendance, Leave, Pay, More.
// Everything here is the signed-in person's own data only.

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { letterSheet } from '../core/letter.js';
import { slipTitle, whyList } from '../core/payslip.js';
import * as att from '../core/att.js';
import { go, signOut } from '../app.js';

const REQ_PILL = { pending: 'p-warn', approved: 'p-ok', partly_approved: 'p-ok', sent_back: 'p-bad', withdrawn: 'p-neutral' };
const NEED_DOT = { warn: 'd-warn', bad: 'd-bad', gold: 'd-gold', info: 'd-info' };

const reqRow = (r, withdraw = false) => html`<div class="listrow">
  <div><b>${r.summary}</b><small>${r.status === 'sent_back' && r.note ? `Reason: ${r.note}` : `${r.type_label} · asked ${f.stamp(r.created_at).toLowerCase() === 'today' ? 'today' : f.day(r.created_at)}`}</small></div>
  <span class="r">${withdraw && r.status === 'pending' ? html`<button class="btn btn--sm btn--quiet" type="button" data-withdraw="${r.id}">Withdraw</button>` : ''}
    ${withdraw ? html`<a class="btn btn--sm btn--quiet" href="#/me/mail/new?about=${r.id}">Ask HR</a>` : ''}
    <span class="pill pill--sm ${REQ_PILL[r.status]}">${r.status === 'pending' ? 'Waiting' : r.status_label}</span></span></div>`;

function wireWithdraw(root) {
  root.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-withdraw]');
    if (!b) return;
    const r = await confirmSheet({
      title: 'Withdraw this request?', text: 'It is removed from the approvals list. You can ask again later.',
      confirm: 'Withdraw', tone: 'danger', onConfirm: () => api.post(`/me/requests/${b.dataset.withdraw}/withdraw`),
    });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
}

// ---- Home -----------------------------------------------------------------------------

let punchTimer = null;

/**
 * The punch card. For people whose punch is the Trio HR button: a big
 * Punch in / Punch out with a live timer (office network only). For agents:
 * their CRM clock-in, shown as it happens.
 */
function punchCard(p) {
  const today = f.dayLong(p.today);
  const done = p.today_items.filter((i) => i.out);
  const doneLine = done.length ? done.map((i) => att.span(i)).join(' · ') + ` · ${f.hm(p.minutes_today)}` : '';
  const special = p.holiday ? html`<div class="note">${icon('sun', 'i--sm')}<span>Today is a holiday: ${p.holiday}.</span></div>`
    : p.leave ? html`<div class="note">${icon('sun', 'i--sm')}<span>You're on ${f.lower(p.leave)} today.</span></div>` : '';
  if (p.via === 'crm') {
    const o = p.open;
    const brk = o && o.breaks.find((b) => !b.end);
    return html`<div class="punchcard">
      <div class="label">${today}</div>
      ${o ? html`<div class="t tnum" data-since="${o.in}">${f.hm(o.running)}</div>
        <div class="state"><i class="${brk ? 's-on_break' : 's-working'}"></i><span>${brk ? `On a ${brk.type} break since ${f.time(brk.start)}` : `Clocked in on the CRM at ${f.time(o.in)}`}</span></div>`
      : html`<div class="t">${doneLine ? 'Done for today' : 'Not clocked in'}</div>
        <div class="state"><i></i><span>${doneLine || 'Clock in on the CRM as usual. It shows here by itself.'}</span></div>`}
      ${special}
      <div class="note">${icon('info', 'i--sm')}<span>Your CRM clock-in is your punch. Nothing else to do.</span></div>
    </div>`;
  }
  const o = p.open;
  const net = p.network || { ok: false, text: '' };
  return html`<div class="punchcard">
    <div class="label">${today}</div>
    ${o ? html`<div class="t tnum" data-since="${o.in}">${f.hm(o.running)}</div>
      <div class="state"><i class="s-working"></i><span>Punched in at ${f.time(o.in)}</span></div>`
    : html`<div class="t">${doneLine ? 'Punched out' : 'Not punched in'}</div>
      <div class="state"><i></i><span>${doneLine || 'Punch in when you start work.'}</span></div>`}
    ${special}
    <button class="punchbtn ${o ? 'is-out' : ''}" type="button" data-punch="${o ? 'out' : 'in'}" ${net.ok ? '' : 'disabled'}>
      ${icon('clock')}${o ? 'Punch out' : 'Punch in'}</button>
    <div class="net ${net.ok ? 'is-ok' : 'is-off'}">${icon(net.ok ? 'check' : 'lock', 'i--sm')}<span>${net.text}</span></div>
  </div>`;
}

function tickPunch(main) {
  clearInterval(punchTimer);
  const el = $('[data-since]', main);
  if (!el) return;
  const since = f.ts(el.dataset.since);
  punchTimer = setInterval(() => {
    if (!document.body.contains(el)) { clearInterval(punchTimer); return; }
    el.textContent = f.hm(Math.max(0, Math.floor((Date.now() - since) / 60000)));
  }, 30000);
}

function wirePunch(main) {
  main.addEventListener('click', (e) => {
    const b = e.target.closest('[data-punch]');
    if (!b || b.disabled) return;
    busy(b, async () => {
      try {
        const r = await api.post('/me/punch', { action: b.dataset.punch });
        toast(r.message);
        go('#/me', { replace: true });
      } catch (err) { toast(err.message, { tone: 'bad' }); }
    });
  });
}

export async function home({ main, head }) {
  head({ title: '' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me'); } catch (err) { mount(main, errorView(err)); return; }
  const free = (b) => Math.max(0, b.available - b.pending);
  const leaveLine = d.leave.filter((b) => b.total > 0).map((b) => `${f.bal(free(b))} ${f.lower(b.name.replace(' leave', ''))}`).join(' · ');
  if (d.leaver) {
    // Someone who has left: read-only, no punch, no asking.
    mount(main, html`
      <h1 class="p-greet">${d.greeting}, ${d.first}</h1>
      <div class="banner banner--info">${icon('info')}<div><b>You have left Trio.</b>Until ${f.day(d.leaver.until)} you can still see and download your letters, documents and payslips here. For anything else, write to HR from your Inbox.</div></div>
      <a class="bigbtn" href="#/me/mail/new">${icon('edit')}Write to HR</a>
      <div class="tile tile--list">
        <a class="listrow" href="#/me/letters">${icon('file')}<div><b>My letters</b><small>Relieving letter, experience certificate and more</small></div><span class="r">${icon('chev', 'chev')}</span></a>
        <a class="listrow" href="#/me/documents">${icon('folder')}<div><b>My documents</b><small>What you gave HR</small></div><span class="r">${icon('chev', 'chev')}</span></a>
        <a class="listrow" href="#/me/pay">${icon('wallet')}<div><b>Pay</b><small>Payslips and tax forms</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      </div>`);
    return;
  }
  mount(main, html`
    <h1 class="p-greet">${d.greeting}, ${d.first}</h1>
    ${d.punch ? punchCard(d.punch) : ''}
    ${d.needs.length ? html`<div class="tile tile--list">${d.needs.map((n) => html`<a class="needp" href="${n.href}">
      <span class="dot dot--sm ${NEED_DOT[n.tone] || 'd-neutral'}">${icon(n.icon, 'i--sm')}</span>
      <div><b>${n.title}</b><span>${n.text}</span></div>${icon('chev', 'chev')}</a>`)}</div>` : ''}
    <div class="tile2">
      <a class="tile tile--link" href="#/me/leave"><span class="label">Leave left</span><div class="v tnum">${d.leave[0] ? f.bal(free(d.leave[0])) : '0'} <small class="muted">${d.leave[0] ? f.lower(d.leave[0].name.replace(' leave', '')) : ''}</small></div><span class="meta">${leaveLine}</span></a>
      <a class="tile tile--link" href="#/me/pay" id="paytile"><span class="label">Latest payslip</span><div class="v tnum">—</div><span class="meta">Pay and tax</span></a>
    </div>
    <a class="bigbtn" href="#/me/leave/new">${icon('sun')}Ask for leave</a>
    ${d.requests.length ? html`<div class="tile tile--list"><div class="listhead"><b>Your requests</b><a href="#/me/requests">See all</a></div>${d.requests.slice(0, 4).map((r) => reqRow(r))}</div>` : ''}`);
  tickPunch(main);
  wirePunch(main);
  api.get('/me/pay', { background: true }).then((p) => {
    const t = $('#paytile', main);
    const s = p.payslips[0];
    if (!t || !s) { if (t) $('.meta', t).textContent = 'None yet'; return; }
    t.href = `#/me/pay/${s.id}`;
    $('.v', t).textContent = f.rupees(s.net);
    $('.meta', t).textContent = s.kind === 'settlement' ? `Final settlement · ${s.month}` : s.month;
  }).catch(() => {});
}

// ---- Attendance -------------------------------------------------------------------------

function dayBadge(d) {
  const fixable = d.items.find((i) => i.can_fix);
  if (fixable) return html`<a class="btn btn--sm btn--gold" href="#/me/fix/${fixable.ref}">Fix</a>`;
  const asked = d.items.find((i) => i.fix);
  if (asked) return html`<span class="pill pill--sm p-warn">Fix asked</span>`;
  if (d.state === 'missing_out') return html`<span class="pill pill--sm p-bad">No punch-out</span>`;
  if (d.state === 'in') return html`<span class="pill pill--sm p-ok">In now</span>`;
  if (d.state === 'leave') return html`<span class="pill pill--sm p-info">Leave</span>`;
  if (d.state === 'holiday' || (d.state === 'upcoming' && d.holiday)) return html`<span class="pill pill--sm p-gold">Holiday</span>`;
  if (d.state === 'no_punch' && d.mark) return html`<span class="pill pill--sm p-neutral">${d.mark.label}</span>`;
  return '';
}

function dayMain(d) {
  if (d.items.length) {
    return html`<b class="tnum">${d.items.map((i) => att.span(i)).join(' · ')}</b>
      <small>${d.minutes ? f.hm(d.minutes) : d.state === 'in' ? 'Still in' : 'Hours not counted until the punch-out is fixed'}${d.break_mins ? ` · breaks ${f.hm(d.break_mins)}` : ''}${d.holiday ? ` · holiday: ${d.holiday}` : ''}${d.items.some((i) => i.note) ? ` · ${f.lowerFirst(d.items.find((i) => i.note).note)}` : ''}</small>`;
  }
  if (d.state === 'leave') return html`<b>${d.leave.name}</b><small>${d.leave.paid ? 'Paid' : 'Unpaid'}</small>`;
  if (d.state === 'holiday') return html`<b>${d.holiday}</b><small>Company holiday</small>`;
  if (d.state === 'upcoming') return html`<b>${d.leave ? d.leave.name : d.holiday}</b><small>${d.leave ? 'Approved' : 'Company holiday'}</small>`;
  if (d.state === 'today') return html`<b class="muted">Today</b><small>No punch yet</small>`;
  return html`<b class="muted">No punch</b><small>${d.pending_leave ? 'Leave asked, not decided yet' : d.mark ? `Decided by HR: ${f.lower(d.mark.label)}` : 'HR decides at month end'}</small>`;
}

export async function attendance({ main, head, query }) {
  head({ title: 'Attendance' });
  mount(main, skeleton('list'));
  const month = /^\d{4}-\d{2}$/.test(query.get('month') || '') ? query.get('month') : '';
  let d;
  try { d = await api.get('/me/attendance' + (month ? `?month=${month}` : '')); } catch (err) { mount(main, errorView(err)); return; }
  const s = d.sum;
  mount(main, html`
    <div class="row"><a class="btn btn--sm" href="#/me/attendance?month=${d.prev}" aria-label="Earlier month">${icon('back', 'i--sm')}</a>
      <b class="spacer center">${d.label}</b>
      ${d.next ? html`<a class="btn btn--sm" href="#/me/attendance?month=${d.next}" aria-label="Later month">${icon('chev', 'i--sm')}</a>` : html`<span class="btn btn--sm btn--quiet" aria-hidden="true"></span>`}</div>
    ${s ? html`<div class="bal bal--3">
      <div><b class="tnum">${s.worked}</b><span>${s.worked === 1 ? 'day' : 'days'} worked</span></div>
      <div><b class="tnum">${Math.round(s.minutes / 60)}</b><span>hours</span></div>
      <div><b class="tnum">${s.paid_leave + s.unpaid_leave + s.holidays}</b><span>leave and holidays</span></div>
    </div>` : ''}
    ${d.locked ? html`<div class="banner banner--ok">${icon('lock')}<div><b>Closed for payroll.</b>HR has locked ${d.label}. Something wrong? Write to HR.</div></div>` : ''}
    ${d.days.length ? html`<div class="tile tile--list">${d.days.map((x) => html`<div class="pnight st-${x.state}">
        <div class="dd"><b>${f.dayNum(x.day)}</b><small>${f.weekdayShort(x.day)}</small></div>
        <div class="m">${dayMain(x)}</div>
        <div>${dayBadge(x)}</div>
      </div>`)}</div>` : html`<div class="tile"><div class="empty"><b>Nothing yet</b><p>No punches in ${d.label}.</p></div></div>`}
    <p class="meta">${d.via?.via === 'crm' ? 'From your CRM clock-ins.' : 'From your punches on Trio HR.'} A day runs 6 am to 6 am India time, so a punch after midnight counts for the evening before.
      Forgot to punch out? Tap Fix within ${f.plural(d.fix_days || 7, 'day')}. Days with no punch are decided by HR at month end.</p>`);
}

export async function fix({ main, head, params, query }) {
  head({ title: 'When did you leave?', back: '#/me/attendance' });
  mount(main, skeleton('list'));
  let n;
  try { n = await api.get(`/me/night/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const thread = /^\d+$/.test(query.get('thread') || '') ? Number(query.get('thread')) : null;
  if (!n.fixable) {
    mount(main, html`<div class="tile"><div class="empty"><b>${n.open_now ? 'You are still in' : 'This day can\'t be fixed here'}</b>
      <p>${n.open_now ? (n.kind === 'crm' ? 'Clock out on the CRM when you leave.' : 'Punch out on Home when you leave.') : `It is older than ${f.plural(n.fix_days, 'day')}, already asked about, or closed for payroll. Write to HR if it needs changing.`}</p>
      <a class="btn" href="#/me/mail/new?topic=attendance">Write to HR</a></div></div>`);
    return;
  }
  const hhmm = (s) => (s ? s.slice(11, 16) : '');
  const seen = hhmm(n.last_seen);
  mount(main, html`
    <div class="tile">
      <span class="label">${f.dayLong(n.day)}</span>
      <div class="v tnum">${n.kind === 'crm' ? 'Clocked in' : 'Punched in'} at ${f.time(n.in)}</div>
      <span class="meta">There's no ${n.kind === 'crm' ? 'clock-out' : 'punch-out'}, so no hours count for this day yet. Nothing is guessed.</span>
    </div>
    <form class="form" id="fx" novalidate>
      <div class="field"><label class="flabel" for="f-time">What time did you leave?</label>
        ${seen ? html`<div class="timepick"><button type="button" data-t="${seen}" aria-pressed="false">${f.t12(seen)}</button></div>
          <p class="hint">Your last activity on the CRM that day was at ${f.time(n.last_seen)}.</p>` : ''}
        <input class="input" type="time" id="f-time" name="time" value="" required>
        <div class="err" role="alert"></div></div>
      <div class="field"><span class="flabel">What happened? <span class="opt">(optional)</span></span>
        <div class="chips">${['I forgot to punch out', 'The CRM or internet was down', 'Power cut'].map((c) => html`<button type="button" class="chip" data-reason="${c}">${c}</button>`)}</div>
        <textarea class="textarea" name="note" id="f-note" rows="2" placeholder="Add anything your admin should know"></textarea></div>
      <button class="bigbtn" type="submit">${icon('send')}Send for approval</button>
    </form>`);
  const form = $('#fx', main);
  $$('[data-t]', main).forEach((b) => b.addEventListener('click', () => {
    b.setAttribute('aria-pressed', 'true');
    form.time.value = b.dataset.t;
  }));
  form.time.addEventListener('input', () => $$('[data-t]', main).forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.t === form.time.value))));
  $$('[data-reason]', main).forEach((b) => b.addEventListener('click', () => {
    $$('[data-reason]', main).forEach((x) => x.setAttribute('aria-pressed', 'false'));
    b.setAttribute('aria-pressed', 'true');
    form.note.value = b.dataset.reason;
  }));
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!form.time.value) { showFieldErrors(form, { time: 'Pick the time you left.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/me/fix', { night: n.ref, time: form.time.value, note: form.note.value.trim(), ...(thread ? { thread } : {}) });
        toast(r.message);
        go(thread ? `#/me/mail/${thread}` : '#/me', { replace: true });
      } catch (err) {
        if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
      }
    });
  });
}

// ---- Leave --------------------------------------------------------------------------------

export async function leave({ main, head }) {
  head({ title: 'Leave' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/leave'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="bal">${d.balances.map((b) => html`<div><b class="tnum">${f.bal(Math.max(0, b.available - b.pending))}</b><span>${f.lower(b.name.replace(' leave', ''))} left${b.pending ? ` · ${f.bal(b.pending)} waiting for approval` : ''}</span></div>`)}</div>
    <a class="bigbtn" href="#/me/leave/new">${icon('plus')}Ask for leave</a>
    <div class="tile tile--list"><div class="listhead"><b>Your leave</b></div>
      ${d.requests.length ? d.requests.map((r) => reqRow({ ...r, type_label: 'Leave' }, true)) : html`<div class="listrow"><div class="muted">You haven't asked for leave yet.</div></div>`}</div>
    ${d.holidays && d.holidays.length ? html`<div class="tile tile--list"><div class="listhead"><b>Coming holidays</b></div>
      ${d.holidays.map((h) => html`<div class="listrow">${icon('sun')}<div><b>${h.name}</b><small>${f.dayLong(h.day)}</small></div></div>`)}</div>` : ''}
    <p class="meta">Leave years run January to December. Company holidays are already days off, so leave never uses them up.</p>`);
  wireWithdraw(main);
}

export async function leaveNew({ main, head }) {
  head({ title: 'Ask for leave', back: '#/me/leave' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/leave'); } catch (err) { mount(main, errorView(err)); return; }
  const bal = Object.fromEntries(d.balances.map((b) => [b.code, b.available - b.pending]));
  const tonight = d.first_night || d.today; // the first night that hasn't begun
  mount(main, html`
    <form class="form" id="lf" novalidate>
      <div class="field"><span class="flabel">What kind of leave?</span>
        <div class="typegrid">${d.types.map((t) => html`<button type="button" data-type="${t.code}" aria-pressed="false" ${t.needs_balance && bal[t.code] <= 0 ? raw('disabled') : ''}>
          <b>${t.name}</b><span>${t.needs_balance ? `${f.bal(Math.max(0, bal[t.code] ?? 0))} left` : 'Not paid'}${t.min_notice_days ? ` · ${t.min_notice_days === 1 ? "1 day's" : t.min_notice_days + " days'"} notice` : ''}</span></button>`)}</div>
        <input type="hidden" name="leave_type"><div class="err" role="alert"></div></div>
      <div class="form__grid">
        <div class="field"><label for="f-from">First night</label><input class="input" type="date" id="f-from" name="from" value="${tonight}" min="${tonight}"><div class="err" role="alert"></div></div>
        <div class="field"><label for="f-to">Last night</label><input class="input" type="date" id="f-to" name="to" value="${tonight}" min="${tonight}"><div class="err" role="alert"></div></div>
      </div>
      <div class="banner banner--info" id="lsum">${icon('cal')}<div></div></div>
      <div class="field"><label for="f-note">Note for your admin <span class="opt">(optional)</span></label>
        <textarea class="textarea" id="f-note" name="note" rows="2" placeholder="For example: my sister's wedding in Ludhiana"></textarea></div>
      <button class="bigbtn" type="submit">${icon('send')}Send for approval</button>
    </form>`);
  const form = $('#lf', main);
  const summary = () => {
    const a = form.from.value, b = form.to.value || a;
    if (!a) return;
    const n = Math.round((Date.parse(b) - Date.parse(a)) / 86400000) + 1;
    $('#lsum div', main).textContent = n > 0 ? `${f.plural(n, 'night')}: ${n === 1 ? f.day(a) : `${f.day(a)} to ${f.day(b)}`}. Each night is the shift that starts that evening.` : 'The last night is before the first.';
  };
  form.from.addEventListener('change', () => { if (form.to.value < form.from.value) form.to.value = form.from.value; form.to.min = form.from.value; summary(); });
  form.to.addEventListener('change', summary);
  summary();
  $$('[data-type]', main).forEach((b) => b.addEventListener('click', () => {
    $$('[data-type]', main).forEach((x) => x.setAttribute('aria-pressed', 'false'));
    b.setAttribute('aria-pressed', 'true');
    form.leave_type.value = b.dataset.type;
    form.from.min = b.dataset.type === 'sick' ? (d.sick_from || '') : tonight;
    if (form.from.value < form.from.min) { form.from.value = form.from.min; form.to.min = form.from.min; if (form.to.value < form.from.value) form.to.value = form.from.value; summary(); }
  }));
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const data = formData(form);
    if (!data.leave_type) { showFieldErrors(form, { leave_type: 'Pick a kind of leave.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/me/leave', data);
        toast(r.message);
        go('#/me/leave', { replace: true });
      } catch (err) {
        if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
        else if (err.message) toast(err.message, { tone: 'bad' });
      }
    });
  });
}

// ---- Pay --------------------------------------------------------------------------------------

export async function pay({ main, head }) {
  head({ title: 'Pay' });
  mount(main, skeleton('list'));
  let d;
  let p = null;
  try { [d, p] = await Promise.all([api.get('/me/pay'), api.get('/me/profile').catch(() => null)]); } catch (err) { mount(main, errorView(err)); return; }
  const y = d.ytd;
  mount(main, html`
    ${d.payslips.length ? html`
      <div class="tile2">
        <div class="tile"><span class="label">Paid this tax year</span><div class="v tnum">${f.rupees(y.net)}</div><span class="meta">${d.tax_year}, after deductions</span></div>
        <div class="tile"><span class="label">Income tax so far</span><div class="v tnum">${f.rupees(y.tds)}</div><span class="meta">On ${f.rupees(y.taxable)} taxable pay</span></div>
      </div>
      <div class="tile tile--list"><div class="listhead"><b>Payslips</b></div>
        ${d.payslips.map((s) => html`<a class="listrow" href="#/me/pay/${s.id}">${icon('file')}<div><b>${s.kind === 'settlement' ? `Final settlement · ${s.month}` : s.month}</b><small>${s.number}</small></div>
          <span class="r"><b class="tnum">${f.rupees(s.net)}</b>${icon('chev', 'chev')}</span></a>`)}</div>`
      : html`<div class="tile"><div class="empty">
        <div class="dot d-gold">${icon('wallet')}</div>
        <b>Your payslips will appear here</b>
        <p>Once HR pays a month's salary through Trio HR, its payslip shows here, with a "Why?" on every line.</p>
      </div></div>`}
    ${d.advances.length ? html`<div class="tile tile--list"><div class="listhead"><b>Advances</b></div>
      ${d.advances.map((a) => html`<div class="listrow">${icon('wallet')}<div><b>${f.rupees(a.remaining)} left of ${f.rupees(a.amount)}</b><small>${f.rupees(a.monthly)} a month comes off your salary · ${a.reason}</small></div></div>`)}</div>` : ''}
    <div class="tile tile--list">
      <a class="listrow" href="#/me/bank">${icon('wallet')}<div><b>Bank account for salary</b><small>${p?.bank ? `${p.bank.account}${p.bank.ifsc ? ' · ' + p.bank.ifsc : ''}` : 'Not added yet'}</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/mail/new">${icon('receipt')}<div><b>Tax: ${d.regime === 'old' ? 'old regime' : 'new regime'}</b><small>To change your regime, or tell HR about income from a previous job this year, write to HR.</small></div><span class="r">${icon('chev', 'chev')}</span></a>
    </div>`);
}

export async function payslip({ main, head, params }) {
  head({ title: 'Payslip', back: '#/me/pay' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/me/payslips/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const s = d.payslip;
  mount(main, html`
    <div class="tile slipnet"><span class="label">${slipTitle(s)}</span><div class="v tnum">${f.rupees(s.net)}</div>
      <span class="meta">Paid to your bank account${s.bank ? ` ${s.bank}` : ''} · ${f.bal(s.paid_days)} of ${s.days_in_month} days paid</span></div>
    <div class="tile"><span class="label">Earnings · ${f.rupees(s.gross)}</span><div class="whys">${whyList(s.earnings)}</div></div>
    <div class="tile"><span class="label">Deductions · ${f.rupees(s.total_deductions)}</span>
      <div class="whys">${s.deductions.length ? whyList(s.deductions, '− ') : html`<p class="muted">Nothing was deducted.</p>`}</div></div>
    <p class="meta center">Tap a line to see why. ${s.number}</p>
    <a class="btn btn--block" href="print.html?payslip=${s.id}&as=me" target="_blank" rel="noopener">${icon('down')}Download or print</a>
    <a class="btn btn--block btn--quiet" href="#/me/mail/new">Something looks wrong? Write to HR</a>`);
}

// ---- More ----------------------------------------------------------------------------------------

export function more({ main, head, session }) {
  head({ title: 'More' });
  mount(main, html`
    <div class="tile tile--list">
      <a class="listrow" href="#/me/profile">${icon('user')}<div><b>My details</b><small>Contact, address, emergency contact</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/bank">${icon('wallet')}<div><b>Bank account</b><small>Where your salary goes</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/requests">${icon('inbox')}<div><b>My requests</b><small>Everything you have asked for</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/pay">${icon('wallet')}<div><b>Pay</b><small>Payslips and your bank account</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/letters">${icon('file')}<div><b>My letters</b><small>Appointment letter, certificates · ask for a letter</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/documents">${icon('folder')}<div><b>My documents</b><small>ID proofs and certificates</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/policies">${icon('shield')}<div><b>Policies</b><small>Read and sign</small></div><span class="r">${icon('chev', 'chev')}</span></a>
      <a class="listrow" href="#/me/security">${icon('lock')}<div><b>Password and devices</b><small>Where you're signed in</small></div><span class="r">${icon('chev', 'chev')}</span></a>
    </div>
    <div class="tile tile--list">
      <a class="listrow" href="${session.crm_url}" target="_blank" rel="noopener">${icon('ext')}<div><b>Open the CRM</b><small>Clock in, bookings, call logs</small></div></a>
      ${['owner', 'admin'].includes(session.account.role) ? html`<a class="listrow" href="#/today">${icon('home')}<div><b>Back to the admin console</b></div></a>` : ''}
      <button class="listrow" type="button" id="so">${icon('out')}<div><b>Sign out</b></div></button>
    </div>
    <div class="tile"><span class="label">Coming soon</span><p class="meta">Your incentive statement.</p></div>`);
  $('#so', main).addEventListener('click', signOut);
}

export async function requests({ main, head }) {
  head({ title: 'My requests', back: '#/me/more' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/requests'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, d.requests.length ? html`<div class="tile tile--list">${d.requests.map((r) => reqRow(r, true))}</div>`
    : html`<div class="tile"><div class="empty"><b>Nothing yet</b><p>Leave, fixes and changes you ask for show here with their answer.</p></div></div>`);
  wireWithdraw(main);
}

export async function profile({ main, head }) {
  head({ title: 'My details', back: '#/me/more' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/profile'); } catch (err) { mount(main, errorView(err)); return; }
  const v = d.fields;
  const pending = d.pending.find((x) => x.type === 'profile_change');
  const fld = (name, label, type = 'text', extra = '') => html`<div class="field ${type === 'textarea' ? 'wide' : ''}"><label for="f-${name}">${label}</label>
    ${type === 'textarea' ? html`<textarea class="textarea" id="f-${name}" name="${name}" rows="2">${v[name] || ''}</textarea>`
      : type === 'gender' ? html`<select class="select" id="f-${name}" name="${name}"><option value="">Choose…</option>${Object.entries(d.genders).map(([k, l]) => html`<option value="${k}" ${v.gender === k ? 'selected' : ''}>${l}</option>`)}</select>`
      : html`<input class="input" id="f-${name}" name="${name}" type="${type}" value="${v[name] || ''}" ${raw(extra)}>`}<div class="err" role="alert"></div></div>`;
  mount(main, html`
    <div class="tile"><span class="label">Job · from HR</span>
      <dl class="kv"><div><dt>Job title</dt><dd>${d.job.designation || '—'}</dd></div><div><dt>Centre</dt><dd>${d.job.centre || '—'}</dd></div>
      <div><dt>Team leader</dt><dd>${d.job.team_leader || '—'}</dd></div><div><dt>Joined</dt><dd>${d.job.joined ? f.day(d.job.joined) : '—'}</dd></div>
      <div><dt>Employee code</dt><dd>${d.job.code}</dd></div><div><dt>Type</dt><dd>${d.job.type}</dd></div></dl></div>
    ${pending ? html`<div class="banner banner--warn">${icon('clock')}<div><b>A change is waiting for approval</b>${pending.summary}. You can edit again once it is decided.</div></div>` : ''}
    <form class="form" id="pf" novalidate>
      <div class="form__grid">
        ${fld('full_name', 'Full name')}${fld('preferred_name', 'Preferred name')}
        ${fld('phone', 'Mobile', 'tel', 'inputmode="tel" autocomplete="tel"')}${fld('personal_email', 'Personal email', 'email', 'autocomplete="email"')}
        ${fld('date_of_birth', 'Date of birth', 'date')}${fld('gender', 'Gender', 'gender')}
        ${fld('address', 'Address', 'textarea')}
        ${fld('emergency_name', 'Emergency contact')}${fld('emergency_relation', 'Relationship')}
        ${fld('emergency_phone', 'Emergency phone', 'tel', 'inputmode="tel"')}
      </div>
      <button class="bigbtn" type="submit" ${pending ? raw('disabled') : ''}>${icon('send')}Send changes for approval</button>
      <p class="meta">Changes show on your record once an admin approves them. Name and date of birth should match your PAN or Aadhaar.</p>
    </form>`);
  const form = $('#pf', main);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const data = formData(form);
    const changed = Object.fromEntries(Object.entries(data).filter(([k, val]) => (v[k] || '') !== val));
    if (!Object.keys(changed).length) { toast('Nothing has changed.'); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/me/profile', changed);
        toast(r.message);
        go('#/me', { replace: true });
      } catch (err) {
        if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
      }
    });
  });
}

export async function bank({ main, head }) {
  head({ title: 'Bank account', back: '#/me/more' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/profile'); } catch (err) { mount(main, errorView(err)); return; }
  const pending = d.pending.find((x) => x.type === 'bank_change');
  mount(main, html`
    <div class="tile"><span class="label">Salary goes to</span>
      <div class="v tnum">${d.bank ? d.bank.account : 'No account yet'}</div>
      <span class="meta">${d.bank?.ifsc ? `IFSC ${d.bank.ifsc}` : 'Add your account so your salary can be paid.'}</span></div>
    ${pending ? html`<div class="banner banner--warn">${icon('clock')}<div><b>A new account is waiting for approval</b>${pending.summary}. Your salary keeps going to the current account until it is approved.</div></div>`
    : html`<form class="form" id="bf" novalidate>
      <div class="field"><label for="f-h">Name on the account</label><input class="input" id="f-h" name="bank_holder" autocomplete="name"><div class="help">Exactly as the bank has it. It must be your own account.</div><div class="err" role="alert"></div></div>
      <div class="field"><label for="f-a">Account number</label><input class="input tnum" id="f-a" name="bank_account" inputmode="numeric" autocomplete="off"><div class="err" role="alert"></div></div>
      <div class="field"><label for="f-a2">Type the account number again</label><input class="input tnum" id="f-a2" name="bank_account_confirm" inputmode="numeric" autocomplete="off"><div class="err" role="alert"></div></div>
      <div class="form__grid">
        <div class="field"><label for="f-i">IFSC</label><input class="input" id="f-i" name="bank_ifsc" autocomplete="off" placeholder="HDFC0001234"><div class="err" role="alert"></div></div>
        <div class="field"><label for="f-b">Bank</label><input class="input" id="f-b" name="bank_name" placeholder="HDFC Bank"><div class="err" role="alert"></div></div>
      </div>
      <div class="banner banner--info">${icon('lock')}<div>Your account number is encrypted. An admin checks it, and salary moves to it ${f.plural(d.cooling_days ?? 3, 'day')} after approval, so a mistake can't send your pay to the wrong place.</div></div>
      <button class="bigbtn" type="submit">${icon('send')}Send for approval</button>
    </form>`}`);
  const form = $('#bf', main);
  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    const data = formData(form);
    const errs = {};
    for (const [k, msg] of [['bank_holder', 'Write the name on the account.'], ['bank_account', 'Enter the account number.'], ['bank_ifsc', 'Enter the IFSC.'], ['bank_name', 'Write the bank name.']]) if (!data[k]) errs[k] = msg;
    if (!errs.bank_account && data.bank_account.replace(/\D/g, '') !== data.bank_account_confirm.replace(/\D/g, '')) errs.bank_account_confirm = "The two account numbers don't match.";
    if (showFieldErrors(form, errs)) return;
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/me/bank', data);
        toast(r.message);
        go('#/me', { replace: true });
      } catch (err) {
        if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
      }
    });
  });
}

export async function security({ main, head, session }) {
  head({ title: 'Password and devices', back: '#/me/more' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/devices'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="tile tile--list"><div class="listhead"><b>Signed in on</b></div>
      ${d.devices.map((x) => html`<div class="listrow">${icon(/computer|Mac|Chromebook/.test(x.device) ? 'laptop' : 'device')}<div><b>${x.device}</b><small>${x.current ? 'This device · ' : ''}since ${f.day(x.since)} · last used ${f.ago(x.seen)}</small></div>
        <span class="r">${x.current ? html`<span class="pill pill--sm p-ok">This one</span>` : html`<button class="btn btn--sm" type="button" data-off="${x.id}">Sign out</button>`}</span></div>`)}</div>
    ${session.has_password ? '' : html`<div class="tile"><b>Set a password</b>
      <p class="meta">You open Trio HR from the CRM, so you haven't needed one. Set a password to use Trio HR on your phone, and to change bank details or open your ID documents.</p>
      <button class="btn btn--pri" type="button" id="setpw">${icon('mail')}Email me a link to set one</button></div>`}
    <form class="tile form" id="pw" novalidate ${session.has_password ? '' : raw('hidden')}>
      <b>Change password</b>
      <div class="field"><label for="f-cur">Current password</label><input class="input" type="password" id="f-cur" name="current" autocomplete="current-password"><div class="err" role="alert"></div></div>
      <div class="field"><label for="f-new">New password</label><input class="input" type="password" id="f-new" name="password" autocomplete="new-password"><div class="help">At least 10 characters.</div><div class="err" role="alert"></div></div>
      <button class="btn btn--pri" type="submit">Change password</button>
    </form>`);
  main.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-off]');
    if (!b) return;
    const r = await confirmSheet({ title: 'Sign out that device?', text: 'Anyone using it will need your password to get back in.', confirm: 'Sign it out', onConfirm: () => api.del(`/me/devices/${b.dataset.off}`) });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
  $('#setpw', main)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    try { toast((await api.post('/password/forgot', { email: session.account.email })).message); }
    catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
  const form = $('#pw', main);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/me/password', { current: form.current.value, password: form.password.value });
        toast(r.message);
        form.reset();
      } catch (err) {
        if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
      }
    });
  });
}

// ---- Policies -------------------------------------------------------------------------------

const POL_PILL = { to_sign: ['p-warn', 'Please sign'], signed: ['p-ok', 'Signed'], withdrawn: ['p-neutral', 'Withdrawn'] };

export async function policies({ main, head }) {
  head({ title: 'Policies', back: '#/me/more' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/policies'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, d.policies.length ? html`<div class="tile tile--list">${d.policies.map((x) => html`<a class="listrow" href="#/me/policies/${x.id}">
      ${icon(x.kind === 'consent' ? 'check' : 'shield')}<div><b>${x.title}</b><small>${x.state === 'to_sign' ? (x.summary || '') : `${x.state === 'signed' ? 'Signed' : 'Withdrawn'} ${f.day(x.at)}`}</small></div>
      <span class="r"><span class="pill pill--sm ${POL_PILL[x.state][0]}">${POL_PILL[x.state][1]}</span></span></a>`)}</div>`
    : html`<div class="tile"><div class="empty"><b>Nothing to sign</b><p>New policies show up here and on Home.</p></div></div>`);
}

export async function policy({ main, head, params }) {
  head({ title: 'Policy', back: '#/me/policies' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/me/policies/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const signed = d.ack && !d.ack.withdrawn_at;
  mount(main, html`
    <h1 class="p-title">${d.title}</h1>
    <p class="meta">${d.version > 1 ? `Version ${d.version} · published` : 'Published'} ${f.day(d.published_at)}</p>
    <div class="tile"><div class="doc">${raw(d.html)}</div></div>
    ${signed ? html`<div class="banner banner--ok">${icon('check')}<div><b>${d.kind === 'consent' ? 'You gave your consent' : 'You signed this'} on ${f.day(d.ack.at)}.</b>As “${d.ack.name}”.</div></div>
      ${d.kind === 'consent' ? html`<button class="btn btn--block" type="button" id="withdraw">Withdraw my consent</button>` : ''}`
      : html`<form class="tile form" id="sf" novalidate>
        ${d.ack?.withdrawn_at ? html`<p class="meta">You withdrew your consent on ${f.day(d.ack.withdrawn_at)}. You can give it again below.</p>` : ''}
        <div class="field"><label class="check-row"><input type="checkbox" name="agree"> ${d.kind === 'consent' ? 'I have read this and I give my consent.' : 'I have read and understood this, and I will follow it.'}</label><div class="err" role="alert"></div></div>
        <div class="field"><label for="sn">Type your full name to sign</label><input class="input" id="sn" name="typed_name" autocomplete="name" placeholder="${d.my_name}"><div class="err" role="alert"></div></div>
        <button class="bigbtn" type="submit">${icon('check')}Sign</button></form>`}`);
  $('#sf', main)?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    if (!form.agree.checked) { showFieldErrors(form, { agree: 'Tick the box to confirm.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post(`/me/policies/${params[0]}/sign`, { agree: true, typed_name: form.typed_name.value })).message); go('#/me', { replace: true }); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
  $('#withdraw', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({ title: 'Withdraw your consent?', text: d.code === 'night_consent' ? 'HR is told straight away and will change your roster. This is never held against you.' : 'HR is told straight away. This is never held against you.', confirm: 'Withdraw', tone: 'danger', onConfirm: () => api.post(`/me/policies/${params[0]}/withdraw`) });
    if (r) { toast(r.message); go('#/me/policies', { replace: true }); }
  });
}

// ---- Letters ---------------------------------------------------------------------------------

export async function letters({ main, head }) {
  head({ title: 'My letters', back: '#/me/more' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/letters'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    ${d.letters.length ? html`<div class="tile tile--list">${d.letters.map((l) => html`<a class="listrow" href="#/me/letters/${l.id}">${icon('file')}
      <div><b>${l.name}</b><small>${l.number} · ${f.day(l.issued_at)}${l.ack_at ? ' · acknowledged' : ''}</small></div>
      <span class="r">${l.to_ack ? html`<span class="pill pill--sm p-warn">Please read</span>` : icon('chev', 'chev')}</span></a>`)}</div>`
      : html`<div class="tile"><div class="empty"><b>No letters yet</b><p>Letters HR issues to you show up here.</p></div></div>`}
    ${d.requestable.length ? html`<a class="bigbtn" href="#/me/letters/new">${icon('plus')}Ask for a letter</a>` : ''}`);
}

// One line under each letter a staff member can ask for.
const LETTER_HINTS = {
  experience: 'Your job and dates at Trio',
  salary_certificate: 'Your pay, for a bank or a visa',
  address_proof: 'Your address as Trio has it',
};

export async function letterNew({ main, head }) {
  head({ title: 'Ask for a letter', back: '#/me/letters' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/letters'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`<form class="form" id="lr" novalidate>
    <div class="field"><span class="flabel">Which letter?</span>
      <div class="typegrid">${d.requestable.map((t) => html`<button type="button" data-tpl="${t.code}" aria-pressed="false"><b>${t.name}</b>${LETTER_HINTS[t.code] ? html`<span>${LETTER_HINTS[t.code]}</span>` : ''}</button>`)}</div>
      <input type="hidden" name="template_code"><div class="err" role="alert"></div></div>
    <div class="field"><label for="lr-p">What is it for?</label><input class="input" id="lr-p" name="purpose" placeholder="For example: a bank loan, a visa, a new house"><div class="err" role="alert"></div></div>
    <button class="bigbtn" type="submit">${icon('send')}Send for approval</button>
    <p class="meta">An admin approves it${d.needs_check ? ', a second admin checks it,' : ''} and it appears in My letters, ready to download.</p></form>`);
  const form = $('#lr', main);
  $$('[data-tpl]', main).forEach((b) => b.addEventListener('click', () => {
    $$('[data-tpl]', main).forEach((x) => x.setAttribute('aria-pressed', 'false'));
    b.setAttribute('aria-pressed', 'true');
    form.template_code.value = b.dataset.tpl;
  }));
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!form.template_code.value) { showFieldErrors(form, { template_code: 'Pick a letter.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post('/me/letters/request', formData(form))).message); go('#/me', { replace: true }); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

export async function letter({ main, head, params }) {
  head({ title: 'Letter', back: '#/me/letters' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/me/letters/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const l = d.letter;
  mount(main, html`
    <div class="ltrbox ltrbox--phone">${letterSheet(d, { qr: true })}</div>
    <a class="btn btn--block" href="print.html?letter=${l.id}&as=me" target="_blank" rel="noopener">${icon('down')}Download or print</a>
    ${l.needs_ack && !l.ack_at ? html`<form class="tile form" id="ak" novalidate>
      <b>Please confirm you have read it</b>
      <div class="field"><label class="check-row"><input type="checkbox" name="agree"> I have read this letter and I accept it.</label><div class="err" role="alert"></div></div>
      <div class="field"><label for="ak-n">Type your full name</label><input class="input" id="ak-n" name="typed_name" autocomplete="name" placeholder="${d.person.name}"><div class="err" role="alert"></div></div>
      <button class="bigbtn" type="submit">${icon('check')}Acknowledge</button></form>`
      : l.ack_at ? html`<div class="banner banner--ok">${icon('check')}<div>You acknowledged this on ${f.day(l.ack_at)}.</div></div>` : ''}`);
  $('#ak', main)?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    if (!form.agree.checked) { showFieldErrors(form, { agree: 'Tick the box to confirm.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post(`/me/letters/${l.id}/ack`, { agree: true, typed_name: form.typed_name.value })).message); go(`#/me/letters/${l.id}`, { replace: true }); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- Documents ------------------------------------------------------------------------------

const DOC_PILL = { verified: ['p-ok', 'Checked'], pending: ['p-warn', 'Waiting for a check'], rejected: ['p-bad', 'Sent back'] };

export async function documents({ main, head, session }) {
  head({ title: 'My documents', back: '#/me/more' });
  const viaCrm = session.via === 'crm';
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/documents'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    ${d.documents.length ? html`<div class="tile tile--list">${d.documents.map((x) => html`<div class="listrow">
      ${icon(x.mime && x.mime !== 'application/pdf' ? 'image' : 'file')}<div><b>${x.title}</b><small>${x.title !== x.kind_label ? `${x.kind_label} · ` : ''}added ${f.day(x.at)}${x.reject_note ? ` · ${x.reject_note}` : ''}</small></div>
      <span class="r row"><span class="pill pill--sm ${DOC_PILL[x.status][0]}">${DOC_PILL[x.status][1]}</span>${viaCrm ? '' : html`<a class="btn btn--sm" href="api/documents/${x.id}/file" target="_blank" rel="noopener">Open</a>`}</span></div>`)}</div>
      ${viaCrm ? html`<div class="banner banner--info">${icon('lock')}<div>You opened Trio HR from the CRM. To open your ID documents, sign in with your Trio HR password (More → Password and devices).</div></div>` : ''}`
      : html`<div class="tile"><div class="empty"><b>No documents yet</b><p>Add your Aadhaar, PAN card, certificates and a bank proof.</p></div></div>`}
    <form class="tile form" id="upf" novalidate>
      <b>Add a document</b>
      <div class="field"><label for="u-kind">What is it?</label><select class="select" id="u-kind" name="kind">${Object.entries(d.kinds).map(([k, l]) => html`<option value="${k}">${l}</option>`)}</select></div>
      <div class="field"><label for="u-file">Photo or PDF</label><input class="input input--file" id="u-file" name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"><div class="help">Up to 8 MB. Make sure every corner is in the photo.</div><div class="err" role="alert"></div></div>
      <button class="bigbtn" type="submit">${icon('plus')}Upload</button>
      <p class="meta">Your files are encrypted. Only you and HR admins can open them, and every time an admin opens one it is recorded.</p></form>`);
  const form = $('#upf', main);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const file = form.file.files[0];
    if (!file) { showFieldErrors(form, { file: 'Choose a photo or PDF first.' }); return; }
    if (file.size > 8 * 1024 * 1024) { showFieldErrors(form, { file: 'That file is over 8 MB. Try a smaller photo.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.upload('/me/documents', file, { 'X-Doc-Kind': form.kind.value })).message); go('#/me/documents', { replace: true }); }
      catch (err) { showFieldErrors(form, { file: err.message }); }
    });
  });
}
