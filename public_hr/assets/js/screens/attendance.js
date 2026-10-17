// Attendance for admins: the month register, closing the month for payroll,
// holidays and opening leave balances.
//
// Facts only (John, 16 Oct): punch in and punch out, from CRM clock-ins or
// the Trio HR button. No late marks, no "absent". A day with no punch is
// decided by an admin at month end; then the month is locked for payroll.

import { html, mount, $, $$, icon } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import * as att from '../core/att.js';
import { go } from '../app.js';

const ym = (q) => (/^\d{4}-(0[1-9]|1[0-2])$/.test(q.get('month') || '') ? q.get('month') : '');

function views(cur, month) {
  const m = month ? `?month=${month}` : '';
  return html`<nav class="views views--seg" aria-label="Attendance views">
    <a href="#/attendance${m}" ${cur === 'register' ? html`aria-current="true"` : ''}>${icon('cal', 'i--sm')}Register</a>
    <a href="#/attendance/close${m}" ${cur === 'close' ? html`aria-current="true"` : ''}>${icon('lock', 'i--sm')}Close the month</a>
    <a href="#/attendance/holidays" ${cur === 'holidays' ? html`aria-current="true"` : ''}>${icon('sun', 'i--sm')}Holidays</a>
    <a href="#/attendance/balances" ${cur === 'balances' ? html`aria-current="true"` : ''}>${icon('chart', 'i--sm')}Leave balances</a>
  </nav>`;
}

function monthBar(base, d, extra = '') {
  return html`<div class="monthbar">
    <a class="btn btn--sm" href="${base}?month=${d.prev}${extra}" aria-label="Earlier month">${icon('back', 'i--sm')}</a>
    <b>${d.label}</b>
    ${d.next ? html`<a class="btn btn--sm" href="${base}?month=${d.next}${extra}" aria-label="Later month">${icon('chev', 'i--sm')}</a>` : html`<span class="btn btn--sm btn--quiet" aria-hidden="true"></span>`}
  </div>`;
}

function lockBanner(d) {
  if (d.lock.status !== 'locked') return '';
  return html`<div class="banner banner--ok">${icon('lock')}<div><b>${d.label} is locked for payroll.</b>Locked by ${d.lock.locked_by || 'an admin'} on ${f.day(d.lock.locked_at)}. Nothing in it can change until it is unlocked.</div></div>`;
}

// ---- the register ----------------------------------------------------------------

export async function register({ main, query }) {
  mount(main, skeleton('list'));
  const month = ym(query);
  const centre = /^[A-Z]{3}$/.test(query.get('centre') || '') ? query.get('centre') : '';
  let d;
  try { d = await api.get('/attendance?' + new URLSearchParams({ ...(month ? { month } : {}), ...(centre ? { centre } : {}) })); } catch (err) { mount(main, errorView(err)); return; }
  const t = d.totals;
  const extra = centre ? `&centre=${centre}` : '';
  const cell = (c, x, p) => html`<td class="c c-${c.s}${c.h ? ' is-hol' : ''}${x.day === d.today ? ' is-today' : ''}" title="${c.title}"><a href="#/people/${p.id}?tab=attendance&month=${d.month}" tabindex="-1">${c.t}</a></td>`;
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Attendance</h1><p class="ph__s">Punch in and punch out only: CRM clock-ins for the floor, the Trio HR button for everyone else</p></div>
      <div class="ph__a"><a class="btn btn--pri" href="#/attendance/close?month=${d.month}">${icon('lock')}Close ${d.label.split(' ')[0]}</a></div></div>
    ${views('register', d.month)}
    <div class="row row--wrap">
      ${monthBar('#/attendance', d, extra)}
      <div class="views" role="group" aria-label="Centre">
        <a href="#/attendance?month=${d.month}" ${!d.centre ? html`aria-current="true"` : ''}>Every centre</a>
        ${d.centres.map((c) => html`<a href="#/attendance?month=${d.month}&centre=${c.code}" ${d.centre === c.code ? html`aria-current="true"` : ''}>${c.name}</a>`)}
      </div>
    </div>
    ${lockBanner(d)}
    <div class="kpis">
      <a class="card kpi" href="#/attendance/close?month=${d.month}"><div class="k">${icon('check', 'i--sm')}Ready for payroll</div><div class="v tnum">${t.ready} <small>of ${t.people}</small></div><div class="d">Nothing left to sort</div></a>
      <a class="card kpi ${t.missing_out ? 't-warn' : ''}" href="#/attendance/close?month=${d.month}"><div class="k">${icon('clock', 'i--sm')}Punch-outs missing</div><div class="v tnum">${t.missing_out}</div><div class="d">${t.missing_out ? 'No hours count until fixed' : 'None'}</div></a>
      <a class="card kpi" href="#/attendance/close?month=${d.month}"><div class="k">${icon('alert', 'i--sm')}No-punch days to decide</div><div class="v tnum">${t.no_punch}</div><div class="d">Weekly off, unpaid, or worked</div></a>
      <a class="card kpi" href="#/floor"><div class="k">${icon('moon', 'i--sm')}Punched in now</div><div class="v tnum">${t.in}</div><div class="d">Live</div></a>
    </div>
    <div class="card reg-grid">
      <div class="tablewrap reg-wrap"><table class="reg tnum">
        <thead><tr><th class="who">Person</th>${d.days.map((x) => html`<th class="${x.holiday ? 'is-hol' : ''}${x.day === d.today ? ' is-today' : ''}" title="${x.holiday ? `Holiday: ${x.holiday}` : f.dayLong(x.day)}"><b>${x.n}</b><small>${x.dow.slice(0, 2)}</small></th>`)}
          <th class="sum">Worked</th><th class="sum">Hours</th><th class="sum">Leave</th><th class="sum">No punch</th></tr></thead>
        <tbody>${d.people.map((p) => html`<tr>
          <th class="who" scope="row"><a href="#/people/${p.id}?tab=attendance&month=${d.month}"><b>${p.name}</b><small>${p.via === 'hr' ? 'Trio HR punch' : 'CRM clock-in'}${p.issues ? ` · ${f.plural(p.issues, 'thing')} to sort` : ''}</small></a></th>
          ${p.days.map((c, k) => cell(c, d.days[k], p))}
          <td class="sum">${p.sum.worked + p.sum.worked_marked}</td><td class="sum">${Math.round(p.sum.minutes / 60)}</td>
          <td class="sum">${p.sum.paid_leave + p.sum.unpaid_leave || '—'}</td><td class="sum ${p.sum.no_punch ? 'is-todo' : ''}">${p.sum.no_punch || '—'}</td>
        </tr>`)}</tbody>
      </table></div>
    </div>
    <div class="card reg-list">${d.people.map((p) => html`<a class="listrow" href="#/people/${p.id}?tab=attendance&month=${d.month}">
      <span class="av av--sm">${p.initials}</span>
      <div><b>${p.name}</b><small>${p.sum.worked + p.sum.worked_marked} days · ${Math.round(p.sum.minutes / 60)} h${p.sum.paid_leave + p.sum.unpaid_leave ? ` · ${p.sum.paid_leave + p.sum.unpaid_leave} leave` : ''}${p.sum.no_punch ? ` · ${p.sum.no_punch} no punch` : ''}</small>
        <span class="ministrip" aria-hidden="true">${p.days.filter((c) => c.s !== 'upcoming' && c.s !== 'not_employed').map((c) => html`<i class="c-${c.s}"></i>`)}</span></div>
      <span class="r">${p.issues ? html`<span class="pill pill--sm p-warn">${p.issues} to sort</span>` : html`<span class="pill pill--sm p-ok">Ready</span>`}</span></a>`)}</div>
    <div class="reg-key">
      <span><i class="c-worked"></i>Hours worked</span><span><i class="c-in"></i>In now</span><span><i class="c-missing_out"></i>? Punch-out missing</span>
      <span><i class="c-leave"></i>CA/SI/EA Leave</span><span><i class="c-holiday"></i>H Holiday</span><span><i class="c-no_punch"></i>– No punch · WO weekly off · LOP unpaid · W worked</span>
    </div>
    <p class="meta">Hours are punch-in to punch-out, in India time; breaks are listed on each person's page. A day runs 6 am to 6 am, so a punch after midnight counts for the evening before.
      Nobody is marked late or absent: days with no punch are decided when you close the month.</p>`);
}

// ---- close the month ----------------------------------------------------------------

function issueBlock(p, i, d) {
  if (i.kind === 'missing_out') {
    return html`<div class="issue">
      <div class="issue__t">${icon('clock', 'i--sm')}<div><b>${i.text}</b><small>${i.last_seen ? `Last active in the CRM at ${f.time(i.last_seen)}. ` : ''}They were asked in Trio Mail. You can also set it yourself.</small></div></div>
      <form class="issue__a" data-setout="${i.ref}" data-emp="${p.id}" novalidate>
        <label class="sr" for="so-${i.ref}">Punch-out time</label>
        <input class="input input--sm" type="time" id="so-${i.ref}" name="time" value="${i.last_seen ? i.last_seen.slice(11, 16) : ''}" required>
        <button class="btn btn--sm" type="submit">Set punch-out</button>
      </form></div>`;
  }
  if (i.kind === 'pending_fix' || i.kind === 'pending_leave') {
    return html`<div class="issue"><div class="issue__t">${icon(i.kind === 'pending_fix' ? 'clock' : 'sun', 'i--sm')}<div><b>${i.text}</b></div></div>
      <div class="issue__a"><a class="btn btn--sm" href="#/approvals?id=${i.request_id}">Decide</a></div></div>`;
  }
  if (i.kind === 'no_punch') {
    const n = i.days.length;
    return html`<div class="issue issue--days" data-emp="${p.id}">
      <div class="issue__t">${icon('alert', 'i--sm')}<div><b>${f.plural(n, 'day')} with no punch</b>
        <small>${p.days_off_left ? `${f.plural(p.days_off_left, 'weekly off')} left this month (${d.allowance} a month in Settings).` : `All ${d.allowance} weekly offs for the month are used.`} Pick the days, then decide.</small></div></div>
      <div class="daychips" role="group" aria-label="Days with no punch">${i.days.map((x) => html`<button type="button" class="chip" data-day="${x}" aria-pressed="true">${f.day(x)}</button>`)}</div>
      <div class="issue__a">
        ${Object.entries(att.MARK).map(([k, label]) => html`<button class="btn btn--sm ${k === 'day_off' && n <= p.days_off_left ? 'btn--pri' : ''}" type="button" data-mark="${k}">${label}</button>`)}
      </div></div>`;
  }
  return html`<div class="issue"><div class="issue__t">${icon('info', 'i--sm')}<div><b>${i.text}</b></div></div></div>`;
}

export async function close({ main, query }) {
  mount(main, skeleton('list'));
  const month = ym(query);
  let d;
  try { d = await api.get('/attendance/close' + (month ? `?month=${month}` : '')); } catch (err) { mount(main, errorView(err)); return; }
  const todo = d.people.filter((p) => !p.ready);
  const ready = d.people.filter((p) => p.ready);
  const locked = d.lock.status === 'locked';
  const pct = d.total ? Math.round((d.ready / d.total) * 100) : 100;
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Close ${d.label} for payroll</h1>
      <p class="ph__s">${locked ? 'Locked' : `${d.ready} of ${d.total} people ready`} · days worked and paid leave go to payroll once the month is locked</p></div>
      <div class="ph__a">${locked
        ? html`<button class="btn" type="button" id="unlock">${icon('lock')}Unlock ${d.label.split(' ')[0]}</button>`
        : html`<button class="btn btn--pri" type="button" id="lock" ${d.can_lock ? '' : 'disabled'}>${icon('lock')}Lock ${d.label.split(' ')[0]}</button>`}</div></div>
    ${views('close', d.month)}
    ${monthBar('#/attendance/close', d)}
    ${lockBanner(d)}
    ${!locked ? html`<div class="card card__b readybar">
      <div class="bar"><i data-w="${pct}"></i></div>
      <span><b class="tnum">${d.ready} of ${d.total}</b> ready${todo.length ? ` · ${f.plural(todo.length, 'person', 'people')} to sort` : ''}${!d.ended ? ` · ${d.label.split(' ')[0]} isn't over yet, so it can be locked from ${f.day(nextDay(d.to))}` : ''}</span>
    </div>` : ''}
    ${d.lock.unlock_reason && !locked ? html`<div class="banner banner--warn">${icon('info')}<div><b>Unlocked by ${d.lock.unlocked_by || 'an admin'} on ${f.day(d.lock.unlocked_at)}.</b>Reason: ${d.lock.unlock_reason}. Lock it again once the change is made.</div></div>` : ''}
    ${offsTodo(todo) && d.ended && !locked ? html`<div class="card card__b bulkoff">
      <div>${icon('sun')}<div><b>Most of what's left is weekly offs</b><span>${f.plural(offsTodo(todo), 'person', 'people')} have no-punch days. Mark them as weekly offs, up to ${d.allowance} each, earliest first. Anything beyond that stays for you to decide.</span></div></div>
      <button class="btn btn--pri" type="button" id="bulkoff">Use weekly offs for everyone</button></div>` : ''}
    ${todo.length ? html`<h2 class="sect">To sort</h2>
      <div class="stack">${todo.map((p) => html`<div class="card closecard" data-person="${p.id}">
        <div class="card__h"><div class="who"><span class="av av--sm">${p.initials}</span><div><a href="#/people/${p.id}?tab=attendance&month=${d.month}"><b>${p.name}</b></a><small>${p.designation || ''}</small></div></div>
          <span class="sub tnum">${p.sum.worked} days worked · ${Math.round(p.sum.minutes / 60)} h</span></div>
        <div class="card__b issues">${p.issues.map((i) => issueBlock(p, i, d))}</div>
      </div>`)}</div>` : ''}
    ${ready.length ? html`<h2 class="sect">Ready${locked ? ', as locked' : ''}</h2>
      <div class="card"><div class="tablewrap"><table class="rtable tnum">
        <thead><tr><th>Person</th><th class="r">Worked</th><th class="r hide-sm">Hours</th><th class="r hide-sm">Paid leave</th><th class="r hide-sm">Holidays</th><th class="r hide-sm">Weekly offs</th><th class="r">Unpaid</th><th class="r">Paid days</th></tr></thead>
        <tbody>${ready.map((p) => html`<tr>
          <td><a href="#/people/${p.id}?tab=attendance&month=${d.month}"><b>${p.name}</b></a>${p.issues.length ? html`<small class="meta t-warn"> · ${p.issues[0].text}</small>` : ''}</td>
          <td class="r">${p.sum.worked + p.sum.worked_marked}</td><td class="r hide-sm">${Math.round(p.sum.minutes / 60)}</td>
          <td class="r hide-sm">${p.sum.paid_leave}</td><td class="r hide-sm">${p.sum.holidays}${p.sum.holiday_worked ? html`<small class="meta"> (+${p.sum.holiday_worked} worked)</small>` : ''}</td>
          <td class="r hide-sm">${p.sum.day_off}</td><td class="r">${p.sum.unpaid_days}</td><td class="r"><b>${p.sum.paid_days}</b><small class="meta"> of ${p.sum.days}</small></td>
        </tr>`)}</tbody></table></div></div>` : ''}
    <p class="meta">Paid days are the days of the month minus unpaid ones (unpaid leave and days you marked unpaid). Holidays worked are shown as a fact; what they pay is your call.</p>`);

  main.addEventListener('click', async (e) => {
    const chip = e.target.closest('[data-day]');
    if (chip) { chip.setAttribute('aria-pressed', String(chip.getAttribute('aria-pressed') !== 'true')); return; }
    const mk = e.target.closest('[data-mark]');
    if (mk) {
      const box = mk.closest('[data-emp]');
      const days = $$('[data-day][aria-pressed="true"]', box).map((b) => b.dataset.day);
      if (!days.length) { toast('Pick at least one day first.', { tone: 'bad' }); return; }
      busy(mk, async () => {
        try {
          const r = await api.post('/attendance/mark', { employee_id: Number(box.dataset.emp), days, mark: mk.dataset.mark });
          toast(r.message);
          go(location.hash, { replace: true });
        } catch (err) { toast(err.message, { tone: 'bad' }); }
      });
    }
  });
  main.addEventListener('submit', (e) => {
    const form = e.target.closest('[data-setout]');
    if (!form) return;
    e.preventDefault();
    if (!form.time.value) { toast('Pick the time they left.', { tone: 'bad' }); form.time.focus(); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/attendance/set-out', { employee_id: Number(form.dataset.emp), ref: form.dataset.setout, time: form.time.value });
        toast(r.message);
        go(location.hash, { replace: true });
      } catch (err) { toast(err.message, { tone: 'bad' }); }
    });
  });
  $('#bulkoff', main)?.addEventListener('click', async () => {
    const days = todo.reduce((a, p) => a + Math.min(p.days_off_left, (p.issues.find((i) => i.kind === 'no_punch')?.days.length) || 0), 0);
    const r = await confirmSheet({
      title: `Mark weekly offs for ${d.label}?`,
      text: `Each person's days with no punch become weekly offs, up to ${d.allowance} a month, earliest first. Days beyond that, and days with leave still waiting, stay for you. You can change any day afterwards on the person's page.`,
      summary: [['People', String(offsTodo(todo))], ['Days marked as weekly offs', String(days)]],
      confirm: 'Mark weekly offs',
      onConfirm: () => api.post('/attendance/weekly-offs', { month: d.month }),
    });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
  $('#lock', main)?.addEventListener('click', async () => {
    const totals = ready.reduce((a, p) => ({ paid: a.paid + p.sum.paid_days, unpaid: a.unpaid + p.sum.unpaid_days }), { paid: 0, unpaid: 0 });
    const r = await confirmSheet({
      title: `Lock ${d.label} for payroll?`,
      text: 'Punches, fixes, leave and your decisions for the month are frozen. Payroll reads these numbers. You can unlock it later with a reason.',
      summary: [['People', String(d.total)], ['Paid days, everyone', String(totals.paid)], ['Unpaid days, everyone', String(totals.unpaid)]],
      confirm: `Lock ${d.label.split(' ')[0]}`,
      onConfirm: () => api.post('/attendance/lock', { month: d.month }),
    });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
  $('#unlock', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({
      title: `Unlock ${d.label}?`, text: 'Every admin is told, with your reason. Lock it again once the change is made.',
      input: { label: 'Why does it need to change?', kind: 'textarea', placeholder: 'For example: Aman\'s leave for 12 Sep was approved late' },
      confirm: 'Unlock', tone: 'danger',
      onConfirm: (reason) => api.post('/attendance/unlock', { month: d.month, reason }),
    });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
}

/** How many people still have undecided no-punch days within their weekly-off allowance. */
function offsTodo(todo) {
  return todo.filter((p) => p.days_off_left > 0 && p.issues.some((i) => i.kind === 'no_punch')).length;
}

function nextDay(ymd) {
  const [y, m, dd] = ymd.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, dd + 1)).toISOString().slice(0, 10);
}

// ---- holidays -------------------------------------------------------------------------

export async function holidays({ main, query }) {
  mount(main, skeleton('list'));
  const year = /^\d{4}$/.test(query.get('year') || '') ? query.get('year') : f.todayIST().slice(0, 4);
  let d;
  try { d = await api.get(`/holidays?year=${year}`); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Holidays</h1><p class="ph__s">Paid days off for everyone, or for one centre. Leave never uses them up.</p></div></div>
    ${views('holidays')}
    <div class="monthbar">
      <a class="btn btn--sm" href="#/attendance/holidays?year=${d.year - 1}" aria-label="Earlier year">${icon('back', 'i--sm')}</a><b>${d.year}</b>
      <a class="btn btn--sm" href="#/attendance/holidays?year=${d.year + 1}" aria-label="Later year">${icon('chev', 'i--sm')}</a></div>
    <div class="grid2">
      <div class="card">${d.holidays.length ? html`<div class="tablewrap"><table class="rtable">
        <thead><tr><th>Date</th><th>Holiday</th><th class="hide-sm">Centres</th><th><span class="sr">Remove</span></th></tr></thead>
        <tbody>${d.holidays.map((h) => html`<tr class="${h.past ? 'is-past' : ''}">
          <td class="nowrap">${f.day(h.day)}</td><td><b>${h.name}</b></td><td class="hide-sm">${h.centre_names}</td>
          <td class="r">${h.locked ? html`<span class="pill pill--sm p-neutral" title="That month is locked for payroll">${icon('lock', 'i--sm')}Locked</span>`
            : html`<button class="btn btn--sm btn--quiet" type="button" data-del="${h.id}" data-name="${h.name}">Remove</button>`}</td></tr>`)}</tbody></table></div>`
        : html`<div class="empty"><div class="dot d-neutral">${icon('sun')}</div><b>No holidays in ${d.year} yet</b><p>Add the year's holidays so nobody uses leave on them.</p></div>`}</div>
      <form class="card form" id="addh" novalidate><div class="card__h"><h2>Add a holiday</h2></div><div class="card__b">
        <div class="field"><label for="h-day">Date</label><input class="input" type="date" id="h-day" name="day" required><div class="err" role="alert"></div></div>
        <div class="field"><label for="h-name">Name</label><input class="input" id="h-name" name="name" maxlength="80" placeholder="For example: Diwali" required><div class="err" role="alert"></div></div>
        <fieldset class="field"><legend class="flabel">Which centres?</legend>
          <div class="checks-inline">${d.centres.map((c) => html`<label class="check-inline"><input type="checkbox" name="centres" value="${c.code}" checked>${c.name}</label>`)}</div>
          <div class="help">Leave all ticked for a holiday everywhere.</div></fieldset>
        <button class="btn btn--pri" type="submit">${icon('plus')}Add the holiday</button>
      </div></form>
    </div>`);
  const form = $('#addh', main);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/holidays', { day: form.day.value, name: form.name.value.trim(), centres: $$('[name="centres"]:checked', form).map((x) => x.value) });
        toast(r.message);
        go(`#/attendance/holidays?year=${(form.day.value || '').slice(0, 4) || d.year}`, { replace: true });
      } catch (err) { if (!showErrors(form, err)) toast(err.message, { tone: 'bad' }); }
    });
  });
  main.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-del]');
    if (!b) return;
    const r = await confirmSheet({ title: `Remove ${b.dataset.name}?`, text: 'Anyone who had that day off will show "No punch" for it instead.', confirm: 'Remove', tone: 'danger',
      onConfirm: () => api.del(`/holidays/${b.dataset.del}`) });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
}

function showErrors(form, err) {
  let shown = false;
  for (const [k, v] of Object.entries(err.fields || {})) {
    const box = form[k]?.closest?.('.field')?.querySelector('.err');
    if (box) { box.textContent = v; shown = true; }
  }
  return shown;
}

// ---- opening leave balances ------------------------------------------------------------

export async function balances({ main, query }) {
  mount(main, skeleton('list'));
  const year = /^\d{4}$/.test(query.get('year') || '') ? query.get('year') : f.todayIST().slice(0, 4);
  let d;
  try { d = await api.get(`/leave/balances?year=${year}`); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Leave balances</h1><p class="ph__s">Each person's opening balance for ${d.year}. Used leave comes from approved requests.</p></div>
      <div class="ph__a"><button class="btn btn--pri" type="button" id="saveb" disabled>${icon('check')}Save balances</button></div></div>
    ${views('balances')}
    <div class="monthbar">
      <a class="btn btn--sm" href="#/attendance/balances?year=${d.year - 1}" aria-label="Earlier year">${icon('back', 'i--sm')}</a><b>${d.year}</b>
      <a class="btn btn--sm" href="#/attendance/balances?year=${d.year + 1}" aria-label="Later year">${icon('chev', 'i--sm')}</a></div>
    <div class="card baltable-wrap"><div class="tablewrap"><table class="rtable baltable tnum">
      <thead><tr><th>Person</th>${d.types.map((t) => html`<th class="r">${t.name}<small>opening · used</small></th>`)}</tr></thead>
      <tbody>${d.people.map((p) => html`<tr>
        <td><a href="#/people/${p.id}?tab=leave"><b>${p.name}</b></a><small class="meta">${p.designation ? ' · ' + p.designation : ''}</small></td>
        ${p.balances.map((b, k) => html`<td class="r"><span class="balcell">
          <label class="sr" for="b-${p.id}-${b.code}">${p.name}: opening ${d.types[k].name}</label>
          <input class="input input--sm input--num" type="number" min="0" max="365" step="0.5" id="b-${p.id}-${b.code}" data-emp="${p.id}" data-type="${b.code}" data-was="${b.opening}" value="${b.opening}">
          <small class="meta">· ${f.bal(b.used)} used</small></span></td>`)}
      </tr>`)}</tbody></table></div></div>
    <p class="meta">Half days are allowed (0.5). Balances left = opening + earned − used. Changing an opening balance never changes leave already approved.</p>`);
  const save = $('#saveb', main);
  const changed = () => $$('input[data-emp]', main).filter((i) => i.value !== '' && Number(i.value) !== Number(i.dataset.was));
  main.addEventListener('input', () => {
    const n = changed().length;
    save.disabled = !n;
    save.lastChild.textContent = n ? `Save ${f.plural(n, 'change')}` : 'Save balances';
  });
  save.addEventListener('click', () => busy(save, async () => {
    try {
      const rows = changed().map((i) => ({ employee_id: Number(i.dataset.emp), leave_type: i.dataset.type, opening: Number(i.value) }));
      const r = await api.post('/leave/balances', { year: d.year, rows });
      toast(r.message);
      go(location.hash, { replace: true });
    } catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
}
