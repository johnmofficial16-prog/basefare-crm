// Payroll (admin): the monthly runs, final settlements, salaries, advances,
// tax set-up and settings; and one run's page — review every line with its
// reasons, lock it (payslips are made), download the ICICI file, mark it paid.

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy, openDrawer } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { REGIME } from '../core/payslip.js';
import { go, setTitle } from '../app.js';

const TABS = [['runs', 'Runs'], ['salaries', 'Salaries'], ['advances', 'Advances'], ['tax', 'Tax set-up'], ['settings', 'Settings']];
const RUN_PILL = { draft: ['Draft', 'p-warn'], locked: ['Locked · not paid yet', 'p-navy'], paid: ['Paid', 'p-ok'] };
const ADV_PILL = { active: ['Being recovered', 'p-warn'], closed: ['Recovered', 'p-ok'], cancelled: ['Closed', 'p-neutral'] };

const blockers = (l) => l.checks.filter((c) => c.block);
const gross = (t) => (t.earned || 0) + (t.incentive || 0) + (t.additions || 0);
const thisMonth = () => f.todayIST().slice(0, 7);
/** Draw the current screen again from scratch (fresh listeners). */
const again = () => go(location.hash, { replace: true });

/** Save a file the server sent as text. */
function download(filename, content, type) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([content], { type }));
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

/** Run a call that may ask for the admin's password again (bank details). */
async function withPassword(call, { title, text, confirm }) {
  try { return await call({}); } catch (err) {
    if (err.reason !== 'reauth_required') { toast(err.message, { tone: 'bad' }); return null; }
  }
  return confirmSheet({
    title, text, confirm,
    input: { kind: 'password', label: 'Your Trio HR password', required: true, requiredText: 'Enter your password.' },
    onConfirm: (password) => call({ password }),
  });
}

export async function render(ctx) {
  const { main, query } = ctx;
  const tab = TABS.some(([k]) => k === query.get('tab')) ? query.get('tab') : 'runs';
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Payroll</h1>
      <p class="ph__s">Salaries from the locked attendance month, incentives from the closed KRA month, tax worked out for each person. Every figure carries its reason.</p></div>
      <div class="ph__a" id="pay-acts"></div></div>
    <nav class="tabs" aria-label="Payroll sections">${TABS.map(([k, l]) => html`<a href="#/payroll?tab=${k}" ${k === tab ? raw('aria-current="page"') : ''}>${l}</a>`)}</nav>
    <div class="stack" id="pay-tab"></div>`);
  await ({ runs, salaries, advances, tax, settings })[tab]($('#pay-tab', main), $('#pay-acts', main), query);
}

/** Old "Tax (TDS)" link. */
export function taxPage(ctx) { go('#/payroll?tab=tax', { replace: true }); }

// ---- Runs ------------------------------------------------------------------------------

async function runs(slot, acts) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/payroll'); } catch (err) { mount(slot, errorView(err)); return; }
  if (d.can_start.length) {
    mount(acts, html`<label class="sr" for="pay-month">Month to pay</label>
      <select class="select" id="pay-month">${d.can_start.map((m) => html`<option value="${m.value}">${m.label}</option>`)}</select>
      <button class="btn btn--pri" type="button" id="pay-start">${icon('plus')}Start payroll</button>`);
    $('#pay-start', acts).addEventListener('click', (e) => busy(e.currentTarget, async () => {
      try { const r = await api.post('/payroll/runs', { month: $('#pay-month', acts).value }); toast(r.message); go(`#/payroll/runs/${r.id}`); }
      catch (err) { toast(err.message, { tone: 'bad' }); }
    }));
  }
  const due = d.settlements_due;
  mount(slot, html`
    ${d.setup.length ? html`<div class="banner banner--gold">${icon('cog')}<div><b>Before the first payroll</b>
      <ul class="pay-gaps">${d.setup.map((g) => html`<li><a href="${g.href}">${g.text}</a></li>`)}</ul></div></div>` : ''}
    ${!d.can_start.length && d.next_open ? html`<div class="banner banner--info">${icon('lock')}<div><b>${d.next_open.label} can't start yet.</b>
      Payroll uses final days, so lock attendance for the month first.</div><div class="acts"><a class="btn btn--sm" href="#/attendance/close?month=${d.next_open.value}">Close the month</a></div></div>` : ''}
    ${due.length ? html`<div class="card"><div class="card__h"><h2>Final settlements due</h2><span class="sub">Pay within 2 working days of the last day</span></div>
      <div class="tablewrap"><table class="rtable">
        <thead><tr><th>Person</th><th>Last day</th><th>Pay by</th><th></th></tr></thead>
        <tbody>${due.map((s) => html`<tr class="norow">
          <td><b>${s.name}</b></td><td class="nowrap">${f.day(s.last_day)}</td>
          <td class="nowrap">${f.day(s.due)} ${s.late ? html`<span class="pill pill--sm p-bad">Late</span>` : ''}</td>
          <td class="num">${s.run_id ? html`<a class="btn btn--sm" href="#/payroll/runs/${s.run_id}">Open (${RUN_PILL[s.run_status]?.[0].toLowerCase() || s.run_status})</a>`
            : html`<button class="btn btn--sm btn--pri" type="button" data-settle="${s.employee_id}" data-name="${s.name}">Start settlement</button>`}</td></tr>`)}</tbody>
      </table></div></div>` : ''}
    ${d.runs.length ? html`<div class="card"><div class="card__h"><h2>Payroll runs</h2></div><div class="tablewrap"><table class="rtable">
      <thead><tr><th>Month</th><th>Run</th><th class="num hide-sm">People</th><th class="num">Net pay</th><th>Status</th><th class="hide-sm">Pay date</th></tr></thead>
      <tbody>${d.runs.map((r) => html`<tr data-href="#/payroll/runs/${r.id}">
        <td><a href="#/payroll/runs/${r.id}"><b>${r.month_name}</b></a></td>
        <td>${r.kind === 'regular' ? 'Monthly salaries' : html`Final settlement<small class="meta">${r.person || ''}</small>`}</td>
        <td class="num hide-sm">${r.people}</td><td class="num"><b>${f.rupees(r.net)}</b></td>
        <td><span class="pill pill--sm ${RUN_PILL[r.status][1]}">${RUN_PILL[r.status][0]}</span></td>
        <td class="hide-sm nowrap">${r.paid_at ? `Paid ${f.day(r.paid_at)}` : f.day(r.pay_date)}</td></tr>`)}</tbody>
    </table></div></div>`
    : html`<div class="card"><div class="empty"><div class="dot d-gold">${icon('wallet')}</div><b>No payroll yet</b>
      <p>Lock a month's attendance, then start its payroll here. Nothing is paid until you lock the run and upload the bank file.</p></div></div>`}`);
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-settle]');
    if (b) {
      const r = await confirmSheet({
        title: `Start the final settlement for ${b.dataset.name}?`,
        text: 'It works out pay up to their last day from live attendance, any incentive months not yet paid, and recovers what is left of any advance.',
        confirm: 'Start it', onConfirm: () => api.post('/payroll/settlements', { employee_id: Number(b.dataset.settle) }),
      });
      if (r) { toast(r.message); go(`#/payroll/runs/${r.id}`); }
      return;
    }
    const tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a')) go(tr.dataset.href);
  });
}

// ---- One run ------------------------------------------------------------------------------

const LINE_VIEWS = [['all', 'Everyone'], ['fix', 'To sort'], ['notes', 'With notes'], ['held', 'Held'], ['unpaid', 'Not paid yet']];
const inLineView = (l, v) => v === 'all' || (v === 'fix' ? blockers(l).length > 0 : v === 'notes' ? l.checks.length > 0 : v === 'held' ? l.held : !l.paid_at && !l.held);

function lineStatus(l) {
  const b = blockers(l).length;
  if (b) return html`<span class="pill pill--sm p-bad">${b === 1 ? '1 to sort' : `${b} to sort`}</span>`;
  if (l.paid_at) return html`<span class="pill pill--sm p-ok">Paid</span>`;
  if (l.held) return html`<span class="pill pill--sm p-warn">Held</span>`;
  if (l.in_file) return html`<span class="pill pill--sm p-info">In bank file</span>`;
  if (l.checks.length) return html`<span class="pill pill--sm p-neutral">${f.plural(l.checks.length, 'note')}</span>`;
  return '';
}

export async function run({ main, params, query }) {
  const id = Number(params[0]);
  const here = `#/payroll/runs/${id}`;
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/payroll/runs/${id}`); } catch (err) { mount(main, errorView(err)); return; }
  const r = d.run;
  const t = r.totals;
  const name = r.kind === 'regular' ? `Payroll · ${r.month_name}` : `Final settlement · ${d.lines[0]?.name || ''}`;
  setTitle(name);
  const views = LINE_VIEWS.filter(([k]) => k === 'all' || (k === 'unpaid' ? r.status === 'locked' : d.lines.some((l) => inLineView(l, k))));
  const want = query.get('view');
  const view = views.some(([k]) => k === want) ? want : (r.blocking ? 'fix' : 'all');
  const rows = d.lines.filter((l) => inLineView(l, view));
  const pill = RUN_PILL[r.status];
  const reload = () => go(`${here}${view !== 'all' ? '?view=' + view : ''}`, { replace: true });

  let actions;
  if (r.status === 'draft') {
    actions = html`
      ${r.blocking ? html`<div class="banner banner--bad">${icon('alert')}<div><b>${f.plural(r.blocking, 'problem')} to sort before locking</b>Shown in red below. Fix the record, or hold that person's pay, then recalculate.</div></div>`
        : html`<div class="banner banner--ok">${icon('check')}<div><b>Ready to lock</b>Check the figures, then lock. Locking freezes them and makes the payslips.</div></div>`}
      <div class="row row--wrap">
        <button class="btn btn--pri" type="button" id="lock" ${r.blocking ? raw('disabled') : ''}>${icon('lock')}Lock and make payslips</button>
        <button class="btn" type="button" id="recalc">${icon('refresh')}Recalculate</button>
        <span class="spacer"></span>
        <button class="btn btn--quiet" type="button" id="discard">Discard this draft</button>
      </div>`;
  } else if (r.status === 'locked') {
    actions = html`
      <div class="banner banner--info">${icon('info')}<div><b>Locked on ${f.day(r.locked_at)}${r.locked_by ? ` by ${r.locked_by}` : ''}</b>
        Download the ICICI file and upload it in ICICI corporate internet banking. When ICICI confirms the payment, mark it paid: each person's payslip is then published to their phone.
        ${r.unpaid_held ? ` ${f.plural(r.unpaid_held, 'person is', 'people are')} held: release them on their line to pay them separately.` : ''}</div></div>
      <div class="row row--wrap">
        ${d.bank_ready ? html`<button class="btn ${r.not_in_file ? 'btn--pri' : ''}" type="button" id="bankfile" ${r.unpaid ? '' : raw('disabled')}>${icon('down')}${r.not_in_file || !r.unpaid ? 'Download the ICICI file' : 'Download it again…'}</button>`
          : html`<a class="btn btn--pri" href="#/payroll?tab=settings">${icon('cog')}Add ICICI details first</a>`}
        <button class="btn ${r.unpaid && !r.not_in_file ? 'btn--pri' : ''}" type="button" id="markpaid" ${r.unpaid ? '' : raw('disabled')}>${icon('check')}Mark as paid</button>
        ${d.is_owner ? html`<button class="btn btn--quiet" type="button" id="register">${icon('down')}Salary register (CSV)</button>` : ''}
      </div>`;
  } else {
    actions = html`
      <div class="banner banner--ok">${icon('check')}<div><b>Paid${r.paid_at ? ` on ${f.day(r.paid_at)}` : ''}${r.paid_by ? ` · marked by ${r.paid_by}` : ''}</b>
        ${r.bank_ref ? `Bank reference ${r.bank_ref}. ` : ''}Payslips are published to each person's phone.</div></div>
      ${d.is_owner ? html`<div class="row"><button class="btn" type="button" id="register">${icon('down')}Salary register (CSV)</button></div>` : ''}`;
  }

  mount(main, html`
    <a class="crumb" href="#/payroll">${icon('back', 'i--sm')}Payroll</a>
    <div class="ph"><div class="ph__t"><h1>${name} <span class="pill ${pill[1]}">${pill[0]}</span></h1>
      <p class="ph__s">${r.kind === 'regular' ? `Salaries for ${r.month_name}${r.incentive_month ? ` · incentives for ${r.incentive_month}${r.incentives_closed ? '' : ' (not closed yet)'}` : ''}` : 'Pay up to the last working day'}
        · pay date ${f.day(r.pay_date)}${r.prepared_by ? ` · started by ${r.prepared_by}` : ''}</p></div></div>
    ${(r.status === 'draft' ? r.checks : []).map((c) => html`<div class="banner banner--warn">${icon('info')}<div>${c.text}</div></div>`)}
    <div class="kpis">
      <div class="card kpi"><div class="k">People</div><div class="v tnum">${t.people || 0}</div><div class="d">${t.held ? `${t.held} held` : 'None held'}</div></div>
      <div class="card kpi"><div class="k">Gross pay</div><div class="v tnum">${f.rupees(gross(t))}</div><div class="d">Incentives ${f.rupees(t.incentive || 0)}</div></div>
      <div class="card kpi"><div class="k">Deductions</div><div class="v tnum">${f.rupees((t.deductions || 0) + (t.tds || 0))}</div><div class="d">Income tax ${f.rupees(t.tds || 0)}</div></div>
      <div class="card kpi"><div class="k">Net to pay</div><div class="v tnum">${f.rupees(t.net || 0)}</div><div class="d">${t.held ? 'Held pay included' : 'To bank accounts'}</div></div>
    </div>
    <div class="card"><div class="card__b stack">${actions}</div></div>
    ${views.length > 1 ? html`<nav class="views" aria-label="Lines">${views.map(([k, l]) => html`<a href="${here}?view=${k}" ${k === view ? raw('aria-current="true"') : ''}>${l} <em>${d.lines.filter((x) => inLineView(x, k)).length}</em></a>`)}</nav>` : ''}
    <div class="card"><div class="tablewrap"><table class="rtable payt">
      <thead><tr><th>Person</th><th class="num hide-sm">Paid days</th><th class="num hide-sm">Salary earned</th><th class="num hide-md">Incentive</th>
        <th class="num hide-md">Deductions</th><th class="num hide-md">Income tax</th><th class="num">Net pay</th><th></th></tr></thead>
      <tbody>${rows.length ? rows.map((l) => html`<tr data-line="${l.id}" class="${blockers(l).length ? 'is-bad' : ''}">
        <td><div class="who"><span class="av av--sm">${l.initials}</span><div><b>${l.name}</b><small>${[l.code, l.centre].filter(Boolean).join(' · ')}</small></div></div></td>
        <td class="num hide-sm">${f.bal(l.paid_days)}<small class="meta">of ${l.days_in_month}</small></td>
        <td class="num hide-sm">${f.rupees(l.earned)}</td>
        <td class="num hide-md">${l.incentive ? f.rupees(l.incentive) : '—'}</td>
        <td class="num hide-md">${l.deductions ? f.rupees(l.deductions) : '—'}</td>
        <td class="num hide-md">${l.tds ? f.rupees(l.tds) : '—'}</td>
        <td class="num"><b>${f.rupees(l.net)}</b></td>
        <td class="num">${lineStatus(l)}</td></tr>`) : html`<tr class="norow"><td colspan="8" class="muted">Nobody in this list.</td></tr>`}</tbody>
    </table></div></div>`);

  main.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-line]');
    if (tr) lineDrawer(d, d.lines.find((l) => l.id === Number(tr.dataset.line)), reload);
  });
  $('#recalc', main)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    try { toast((await api.post(`/payroll/runs/${id}/recalculate`)).message); reload(); } catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
  $('#discard', main)?.addEventListener('click', async () => {
    const x = await confirmSheet({
      title: 'Discard this draft?', text: 'The figures and any additions or deductions you entered are removed. You can start the month again.',
      confirm: 'Discard', tone: 'danger', onConfirm: () => api.post(`/payroll/runs/${id}/discard`),
    });
    if (x) { toast(x.message); go('#/payroll', { replace: true }); }
  });
  $('#lock', main)?.addEventListener('click', async () => {
    const frozen = r.kind === 'regular'
      ? `Attendance for ${r.month_name} can't be reopened after this${r.incentive_month ? (r.incentives_closed ? `, nor KRA incentives for ${r.incentive_month}` : `. KRA incentives for ${r.incentive_month} aren't closed, so they are paid next month instead`) : ''}.`
      : 'The attendance and incentive figures it used are frozen with it.';
    const x = await confirmSheet({
      title: `Lock ${r.kind === 'regular' ? `payroll for ${r.month_name}` : 'this settlement'}?`,
      text: `The figures are worked out once more with the latest records, then frozen, and each person gets a numbered payslip (published to them when you mark it paid). ${frozen}`,
      summary: [['People', String(t.people || 0)], ['Held', String(t.held || 0)], ['Net to pay', f.rupees(t.net || 0)]],
      confirm: 'Lock it', onConfirm: () => api.post(`/payroll/runs/${id}/lock`),
    });
    if (x) { toast(x.message); go(here, { replace: true }); }
  });
  $('#bankfile', main)?.addEventListener('click', () => bankFile(id, r, null, reload));
  $('#markpaid', main)?.addEventListener('click', () => markPaid(id, r, null, reload));
  $('#register', main)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    try { const x = await api.post(`/payroll/runs/${id}/register`); download(x.filename, x.content, 'text/csv'); toast('Salary register downloaded.'); }
    catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
}

async function bankFile(id, r, line, after) {
  const call = (extra) => api.post(`/payroll/runs/${id}/bank-file`, { ...extra, ...(line ? { employee_id: line.employee_id } : {}) });
  let x = await withPassword(async (extra) => {
    try { return await call(extra); } catch (err) {
      if (err.reason === 'already_in_file') return { already: err.message, extra };
      throw err;
    }
  }, {
    title: line ? `ICICI file for ${line.name}` : 'Download the ICICI file?',
    text: 'It holds bank account numbers, so enter your own password. The download is recorded.',
    confirm: 'Download',
  });
  if (x?.already) {
    x = await confirmSheet({
      title: 'Download the same payments again?', text: `${x.already} Uploading both files would pay ${line ? 'them' : 'everyone'} twice.`,
      input: { kind: 'text', label: 'Why the first upload failed', required: true, minLength: 3, requiredText: 'Give a short reason.' },
      confirm: 'Download again', tone: 'danger',
      onConfirm: (reason) => call({ ...x.extra, again: true, reason }),
    });
  }
  if (!x) return;
  download(x.filename, x.content, 'text/plain');
  toast(`Downloaded: ${f.rupees(x.total)} to ${f.plural(x.people, 'person', 'people')}, dated ${f.day(x.pay_date)}.`);
  after?.();
}

function markPaid(id, r, line, after) {
  const who = line ? line.name : `everyone not held (${f.plural(r.unpaid, 'person', 'people')})`;
  const layer = openDrawer({
    title: line ? `Mark ${line.name} paid` : 'Mark as paid',
    body: html`<form class="form" id="paid-f" novalidate>
      <p>Only after ICICI confirms the money went out. This marks ${who} paid, publishes their payslips and tells each person.</p>
      <div class="field"><label for="pd-on">Paid on</label><input class="input" id="pd-on" name="paid_on" type="date" value="${f.todayIST()}" min="${String(r.locked_at || '').slice(0, 10)}" max="${f.todayIST()}"><div class="err" role="alert"></div></div>
      <div class="field"><label for="pd-ref">Bank reference <span class="opt">(optional)</span></label><input class="input" id="pd-ref" name="bank_ref" maxlength="60" autocomplete="off"><div class="err" role="alert"></div></div>
      <div class="row"><button class="btn btn--pri" type="submit">${icon('check')}Mark paid</button></div></form>`,
  });
  const form = $('#paid-f', layer.el);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try {
        toast((await api.post(`/payroll/runs/${id}/paid`, { ...formData(form), ...(line ? { employee_id: line.employee_id } : {}) })).message);
        layer.close();
        after();
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// Where each problem on a line is put right.
const FIX = {
  no_salary: [() => '#/payroll?tab=salaries', 'Set a salary'],
  no_bank: [(l) => `#/people/${l.employee_id}`, 'Open their record'],
  bad_bank: [(l) => `#/people/${l.employee_id}`, 'Open their record'],
  no_pan: [(l) => `#/people/${l.employee_id}`, 'Open their record'],
  attendance: [() => '#/attendance', 'Open attendance'],
};

function lineDrawer(d, l, after) {
  if (!l) return;
  const r = d.run;
  const draft = r.status === 'draft';
  const item = (x, sign) => html`<details class="why">
    <summary><span>${x.label}</span><b class="tnum">${sign}${f.rupees(x.amount)}</b></summary>
    <p>${x.why || 'No more detail.'}</p>
    ${draft && x.adjustment ? html`<button class="linkbtn" type="button" data-unadj="${x.adjustment}">Remove this ${sign ? 'deduction' : 'addition'}</button>` : ''}</details>`;
  const ded = l.deductions + l.tds;
  const foot = draft
    ? html`<button class="btn" type="button" data-adj="addition">${icon('plus')}Add to pay</button>
      <button class="btn" type="button" data-adj="deduction">${icon('plus')}Deduct</button>
      <span class="spacer"></span>
      <button class="btn ${l.held ? 'btn--pri' : 'btn--quiet'}" type="button" data-hold="${l.held ? 0 : 1}">${l.held ? 'Release their pay' : 'Hold their pay'}</button>`
    : r.status === 'locked' && !l.paid_at
      ? (l.held ? html`<button class="btn btn--pri" type="button" data-hold="0">Release their pay</button>`
        : html`${d.bank_ready ? html`<button class="btn" type="button" data-one="bank">${icon('down')}ICICI file for ${l.name.split(' ')[0]}</button>` : ''}
          <button class="btn btn--pri" type="button" data-one="paid">${icon('check')}Mark ${l.name.split(' ')[0]} paid</button>`)
      : '';
  const layer = openDrawer({
    title: l.name,
    wide: true,
    body: html`<div class="stack">
      <p class="meta">${[l.code, l.centre].filter(Boolean).join(' · ')}${l.bank_hint ? ` · bank ${l.bank_hint}` : ''}</p>
      ${l.checks.map((c) => html`<div class="banner ${c.block ? 'banner--bad' : 'banner--warn'}">${icon('alert')}<div>${c.text}</div>
        ${FIX[c.code] ? html`<div class="acts"><a class="btn btn--sm" href="${FIX[c.code][0](l)}">${FIX[c.code][1]}</a></div>` : ''}</div>`)}
      ${l.held ? html`<div class="banner banner--warn">${icon('lock')}<div><b>Pay held</b>${l.hold_reason || ''} It stays out of the bank file until released.</div></div>` : ''}
      ${l.paid_at ? html`<div class="banner banner--ok">${icon('check')}<div><b>Paid on ${f.day(l.paid_at)}</b></div></div>`
        : l.in_file ? html`<div class="banner banner--info">${icon('down')}<div><b>In an ICICI file</b>Downloaded on ${f.day(l.in_file)}. Mark them paid once ICICI confirms.</div></div>` : ''}
      <div class="slipsum">
        <div><span>Gross</span><b class="tnum">${f.rupees(l.earned + l.incentive + l.additions)}</b></div>
        <div><span>Deductions</span><b class="tnum">− ${f.rupees(ded)}</b></div>
        <div class="is-net"><span>Net pay</span><b class="tnum">${f.rupees(l.net)}</b></div>
      </div>
      <h3 class="slip__h">Earnings</h3>
      <div class="whys">${l.earnings.length ? l.earnings.map((x) => item(x, '')) : html`<p class="muted">Nothing earned.</p>`}</div>
      <h3 class="slip__h">Deductions</h3>
      <div class="whys">${l.deduction_lines.length ? l.deduction_lines.map((x) => item(x, '− ')) : html`<p class="muted">No deductions.</p>`}</div>
      ${l.tax ? html`<p class="meta">${REGIME[l.tax.regime] || REGIME.new}. Taxable pay for the year is projected at ${f.rupees(l.tax.projected || 0)}; tax for the year ${f.rupees(l.tax.annual || 0)}.
        <a href="#/payroll?tab=tax">Tax set-up</a></p>` : ''}
      ${l.payslip_id ? html`<a class="btn btn--block" href="print.html?payslip=${l.payslip_id}" target="_blank" rel="noopener">${icon('file')}Open the payslip</a>` : ''}
    </div>`,
    foot,
  });
  const el = layer.el;
  el.addEventListener('click', async (e) => {
    const un = e.target.closest('[data-unadj]');
    const adj = e.target.closest('[data-adj]');
    const hold = e.target.closest('[data-hold]');
    const one = e.target.closest('[data-one]');
    if (un) {
      const x = await confirmSheet({ title: 'Remove it?', text: 'The line is recalculated without it.', confirm: 'Remove', tone: 'danger',
        onConfirm: () => api.del(`/payroll/adjustments/${un.dataset.unadj}`) });
      if (x) { toast(x.message); layer.close(); after(); }
    } else if (adj) {
      layer.close();
      adjustDrawer(d, l, adj.dataset.adj, after);
    } else if (hold) {
      const on = hold.dataset.hold === '1';
      const x = await confirmSheet(on
        ? { title: `Hold ${l.name}'s pay?`, text: 'Their line stays in the run but out of the bank file, and they are not told. Release it later to pay them separately.',
          input: { kind: 'text', label: 'Why', required: true, minLength: 3, requiredText: 'Give a short reason.' }, confirm: 'Hold',
          onConfirm: (reason) => api.post(`/payroll/lines/${l.id}/hold`, { hold: true, reason }) }
        : { title: `Release ${l.name}'s pay?`, text: draft ? 'Their pay goes back into the bank file.' : 'You can then download a bank file just for them and mark them paid.',
          confirm: 'Release', onConfirm: () => api.post(`/payroll/lines/${l.id}/hold`, { hold: false }) });
      if (x) { toast(x.message); layer.close(); after(); }
    } else if (one?.dataset.one === 'bank') {
      layer.close();
      bankFile(r.id, r, l, after);
    } else if (one?.dataset.one === 'paid') {
      layer.close();
      markPaid(r.id, r, l, after);
    }
  });
}

function adjustDrawer(d, l, kind, after) {
  const labels = d.adj_labels[kind];
  const layer = openDrawer({
    title: kind === 'addition' ? `Add to ${l.name.split(' ')[0]}'s pay` : `Deduct from ${l.name.split(' ')[0]}'s pay`,
    body: html`<form class="form" id="adj-f" novalidate>
      <div class="field"><label for="aj-label">What it is</label><select class="select" id="aj-label" name="label">${labels.map((x) => html`<option>${x}</option>`)}</select><div class="err" role="alert"></div></div>
      <div class="field"><label for="aj-amt">Amount (₹)</label><input class="input" id="aj-amt" name="amount" type="number" min="1" step="1" inputmode="numeric"><div class="err" role="alert"></div></div>
      <div class="field"><label for="aj-why">Reason</label><input class="input" id="aj-why" name="reason" maxlength="255" autocomplete="off">
        <div class="help">Shown on their payslip as the "Why?" for this line.</div><div class="err" role="alert"></div></div>
      ${kind === 'addition' ? html`<label class="check-row"><input type="checkbox" name="taxable" checked> Taxable (most additions are; untick only for a genuine reimbursement)</label>` : ''}
      ${kind === 'deduction' ? html`<p class="meta">Deductions other than tax have to stay within the deduction limit in Payroll settings; net pay can't go below zero.</p>` : ''}
      <div class="row"><button class="btn btn--pri" type="submit">Add and recalculate</button></div></form>`,
  });
  const form = $('#adj-f', layer.el);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try {
        toast((await api.post(`/payroll/runs/${d.run.id}/adjustments`, { employee_id: l.employee_id, kind, ...formData(form) })).message);
        layer.close();
        after();
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- Salaries -------------------------------------------------------------------------------

async function salaries(slot, acts) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/payroll/salaries'); } catch (err) { mount(slot, errorView(err)); return; }
  mount(slot, html`
    ${d.missing ? html`<div class="banner banner--warn">${icon('alert')}<div><b>${f.plural(d.missing, 'person has', 'people have')} no salary yet.</b>Payroll can't lock until everyone has one (or their pay is held).</div></div>` : ''}
    <div class="card"><div class="card__h"><h2>Monthly salaries</h2><span class="sub">${f.rupees(d.total)} a month in all · opening this list is recorded</span></div>
      <div class="tablewrap"><table class="rtable">
        <thead><tr><th>Person</th><th class="hide-sm">Centre</th><th class="num">Monthly</th><th class="hide-sm">Since</th><th class="hide-sm">Next change</th></tr></thead>
        <tbody>${d.people.map((p) => html`<tr data-sal="${p.id}">
          <td><div class="who"><span class="av av--sm">${p.initials}</span><div><b>${p.name}</b><small>${[p.code, p.designation].filter(Boolean).join(' · ')}</small></div></div></td>
          <td class="hide-sm">${p.centre || '—'}</td>
          <td class="num">${p.monthly === null ? html`<span class="pill pill--sm p-warn">Not set</span>` : html`<b>${f.rupees(p.monthly)}</b>`}</td>
          <td class="hide-sm nowrap">${p.since ? f.day(p.since) : '—'}</td>
          <td class="hide-sm nowrap">${p.next ? `${f.rupees(p.next.monthly)} from ${f.day(p.next.from)}` : '—'}</td></tr>`)}</tbody>
      </table></div></div>`);
  slot.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-sal]');
    if (tr) salaryDrawer(d.people.find((p) => p.id === Number(tr.dataset.sal)), again);
  });
}

function salaryDrawer(p, after) {
  const layer = openDrawer({
    title: p.name,
    body: html`<form class="form" id="sal-f" novalidate>
      <p class="meta">${p.monthly === null ? 'No salary yet.' : `Now ${f.rupees(p.monthly)} a month, since ${f.day(p.since)}.`} A change applies from the date you pick; earlier months keep their salary.</p>
      <div class="field"><label for="sl-m">Monthly salary (₹, before any deduction)</label><input class="input" id="sl-m" name="monthly" type="number" min="1000" step="1" inputmode="numeric" value="${p.monthly ?? ''}"><div class="err" role="alert"></div></div>
      <div class="field"><label for="sl-from">From</label><input class="input" id="sl-from" name="from" type="date" value="${thisMonth()}-01"><div class="err" role="alert"></div>
        <div class="help">Pay is worked out per day, so a change from the 16th counts from the 16th.</div></div>
      <div class="field"><label for="sl-note">Note <span class="opt">(optional)</span></label><input class="input" id="sl-note" name="note" maxlength="255" placeholder="e.g. Annual increment"><div class="err" role="alert"></div></div>
      <div class="row"><button class="btn btn--pri" type="submit">Save</button></div>
    </form>
    ${p.history.length ? html`<h3 class="slip__h">History</h3><div class="timeline">${p.history.map((h) => html`<div class="tl">
      <time>${f.day(h.from)}</time><div class="rail"><i></i></div><div class="t">${f.rupees(h.monthly)} a month${h.note ? html`<small>${h.note}</small>` : ''}</div></div>`)}</div>` : ''}`,
  });
  const form = $('#sal-f', layer.el);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post(`/payroll/salaries/${p.id}`, formData(form))).message); layer.close(); after(); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- Advances ---------------------------------------------------------------------------------

async function advances(slot, acts) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/payroll/advances'); } catch (err) { mount(slot, errorView(err)); return; }
  mount(acts, html`<button class="btn btn--pri" type="button" id="adv-new">${icon('plus')}Record an advance</button>`);
  $('#adv-new', acts).addEventListener('click', () => advanceDrawer(d.people, again));
  mount(slot, html`
    <div class="banner banner--info">${icon('info')}<div>An advance is recovered from salary each month, starting from the month you choose, and always within the deduction limit. A final settlement recovers whatever is left.</div></div>
    ${d.advances.length ? html`<div class="card"><div class="tablewrap"><table class="rtable">
      <thead><tr><th>Person</th><th class="num">Advance</th><th class="num hide-sm">A month</th><th class="hide-sm">From</th><th class="num">Left</th><th>Status</th><th></th></tr></thead>
      <tbody>${d.advances.map((a) => html`<tr class="norow">
        <td><b>${a.name}</b><small class="meta">${a.reason}</small></td>
        <td class="num">${f.rupees(a.amount)}</td><td class="num hide-sm">${f.rupees(a.monthly)}</td><td class="hide-sm nowrap">${a.start}</td>
        <td class="num"><b>${f.rupees(a.remaining)}</b></td>
        <td><span class="pill pill--sm ${(ADV_PILL[a.status] || ADV_PILL.cancelled)[1]}">${(ADV_PILL[a.status] || ADV_PILL.cancelled)[0]}</span></td>
        <td class="num">${a.status === 'active' ? html`<button class="btn btn--sm btn--quiet" type="button" data-close-adv="${a.id}" data-name="${a.name}">Close</button>` : ''}</td></tr>`)}</tbody>
    </table></div></div>`
    : html`<div class="card"><div class="empty"><div class="dot d-neutral">${icon('wallet')}</div><b>No advances</b><p>Advances you record show here with what is left to recover.</p></div></div>`}`);
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-close-adv]');
    if (!b) return;
    const r = await confirmSheet({
      title: `Close ${b.dataset.name}'s advance?`, text: 'Nothing more is recovered for it. Use this when it was repaid another way, or written off.',
      input: { kind: 'text', label: 'Why', required: true, minLength: 3, requiredText: 'Give a short reason.' },
      confirm: 'Close it', tone: 'danger', onConfirm: (reason) => api.post(`/payroll/advances/${b.dataset.closeAdv}/close`, { reason }),
    });
    if (r) { toast(r.message); again(); }
  });
}

function advanceDrawer(people, after) {
  const layer = openDrawer({
    title: 'Record an advance',
    body: html`<form class="form" id="adv-f" novalidate>
      <div class="field"><label for="av-p">Person</label><select class="select" id="av-p" name="employee_id"><option value="">Choose…</option>
        ${people.map((p) => html`<option value="${p.id}">${p.name}</option>`)}</select><div class="err" role="alert"></div></div>
      <div class="form__grid">
        <div class="field"><label for="av-a">Advance (₹)</label><input class="input" id="av-a" name="amount" type="number" min="100" step="1" inputmode="numeric"><div class="err" role="alert"></div></div>
        <div class="field"><label for="av-m">Recover a month (₹)</label><input class="input" id="av-m" name="monthly" type="number" min="100" step="1" inputmode="numeric"><div class="err" role="alert"></div></div>
      </div>
      <div class="field"><label for="av-s">First month to recover from</label><input class="input" id="av-s" name="start_month" type="month" value="${thisMonth()}"><div class="err" role="alert"></div></div>
      <div class="field"><label for="av-r">Reason</label><input class="input" id="av-r" name="reason" maxlength="255" autocomplete="off"><div class="err" role="alert"></div></div>
      <p class="meta">They are told in Trio HR how much comes off each month.</p>
      <div class="row"><button class="btn btn--pri" type="submit">Record it</button></div></form>`,
  });
  const form = $('#adv-f', layer.el);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = formData(form);
    if (!v.employee_id) { showFieldErrors(form, { employee_id: 'Choose who it is for.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post('/payroll/advances', { ...v, employee_id: Number(v.employee_id) })).message); layer.close(); after(); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- Tax set-up --------------------------------------------------------------------------------

function taxYears() {
  const ym = thisMonth();
  const y = Number(ym.slice(0, 4));
  const start = Number(ym.slice(5, 7)) >= 4 ? y : y - 1;
  return [start, start - 1].map((s) => `${s}-${String(s + 1).slice(-2)}`);
}

async function tax(slot, acts, query) {
  mount(slot, skeleton('list'));
  const years = taxYears();
  const year = years.includes(query.get('year')) ? query.get('year') : years[0];
  let d;
  try { d = await api.get('/payroll/tax?year=' + year); } catch (err) { mount(slot, errorView(err)); return; }
  mount(acts, html`<label class="sr" for="tax-year">Tax year</label>
    <select class="select" id="tax-year">${years.map((y) => html`<option value="${y}" ${y === year ? 'selected' : ''}>Tax year ${y}</option>`)}</select>`);
  $('#tax-year', acts).addEventListener('change', (e) => go(`#/payroll?tab=tax&year=${e.target.value}`));
  const noPan = d.people.filter((p) => !p.pan || p.pan_status !== 'valid').length;
  mount(slot, html`
    <div class="banner banner--info">${icon('info')}<div>Everyone is on the new tax regime unless you change it here. Income and tax from a previous employer this year, and (old regime only) declared deductions, change the tax taken each month.</div></div>
    ${noPan ? html`<div class="banner banner--warn">${icon('alert')}<div><b>${f.plural(noPan, 'person has', 'people have')} no checked PAN.</b>Where tax is due, at least ${d.table.no_pan_pct}% is taken until a PAN is added and checked on their record.</div></div>` : ''}
    <div class="card"><div class="tablewrap"><table class="rtable">
      <thead><tr><th>Person</th><th class="hide-sm">PAN</th><th>Regime</th><th class="num hide-sm">Previous employer</th><th class="num hide-sm">Deductions declared</th><th class="num">Paid this year</th></tr></thead>
      <tbody>${d.people.map((p) => html`<tr data-tax="${p.id}">
        <td><b>${p.name}</b>${p.note ? html`<small class="meta">${p.note}</small>` : ''}</td>
        <td class="hide-sm">${p.pan ? html`${p.pan} ${p.pan_status === 'valid' ? '' : html`<span class="pill pill--sm p-warn">Not checked</span>`}` : html`<span class="pill pill--sm p-bad">None</span>`}</td>
        <td>${p.regime === 'old' ? 'Old' : 'New'}</td>
        <td class="num hide-sm">${p.prev_income ? html`${f.rupees(p.prev_income)}<small class="meta">tax ${f.rupees(p.prev_tds)}</small>` : '—'}</td>
        <td class="num hide-sm">${p.deductions ? f.rupees(p.deductions) : '—'}</td>
        <td class="num">${f.rupees(p.paid_taxable)}<small class="meta">tax ${f.rupees(p.paid_tds)}</small></td></tr>`)}</tbody>
    </table></div></div>`);
  slot.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-tax]');
    if (tr) taxDrawer(d.tax_year, d.people.find((p) => p.id === Number(tr.dataset.tax)), again);
  });
}

function taxDrawer(year, p, after) {
  const layer = openDrawer({
    title: `${p.name} · tax year ${year}`,
    body: html`<form class="form" id="tax-f" novalidate>
      <fieldset class="fieldset"><legend>Tax regime</legend>
        <div class="radio-cards">
          <label class="radio-card"><input type="radio" name="regime" value="new" ${p.regime !== 'old' ? 'checked' : ''}><span><b>New regime</b><small>The default. Lower rates, few deductions.</small></span></label>
          <label class="radio-card"><input type="radio" name="regime" value="old" ${p.regime === 'old' ? 'checked' : ''}><span><b>Old regime</b><small>Only if they chose it in writing; deductions below then count.</small></span></label>
        </div></fieldset>
      <div class="form__grid">
        <div class="field"><label for="tx-pi">Previous employer income this year (₹)</label><input class="input" id="tx-pi" name="prev_income" type="number" min="0" step="1" inputmode="numeric" value="${p.prev_income || ''}"><div class="err" role="alert"></div></div>
        <div class="field"><label for="tx-pt">Tax already deducted there (₹)</label><input class="input" id="tx-pt" name="prev_tds" type="number" min="0" step="1" inputmode="numeric" value="${p.prev_tds || ''}"><div class="err" role="alert"></div></div>
      </div>
      <div class="field"><label for="tx-d">Deductions declared for the year (₹, old regime)</label><input class="input" id="tx-d" name="deductions" type="number" min="0" step="1" inputmode="numeric" value="${p.deductions || ''}">
        <div class="help">The total of investments and other deductions they declared, as the CA accepts them.</div><div class="err" role="alert"></div></div>
      <div class="field"><label for="tx-n">Note <span class="opt">(optional)</span></label><input class="input" id="tx-n" name="note" maxlength="255" value="${p.note || ''}"><div class="err" role="alert"></div></div>
      <div class="row"><button class="btn btn--pri" type="submit">Save</button></div></form>`,
  });
  const form = $('#tax-f', layer.el);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.patch(`/payroll/tax/${p.id}`, { tax_year: year, ...formData(form) })).message); layer.close(); after(); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- Settings -----------------------------------------------------------------------------------

const slabRow = (upper, pct) => html`<div class="slabrow" data-slab>
  <input class="input" type="number" min="0" step="1" inputmode="numeric" data-upper value="${upper ?? ''}" placeholder="and above" aria-label="Income up to, in rupees (empty: and above)">
  <input class="input" type="number" min="0" max="50" step="any" inputmode="decimal" data-pct value="${pct}" aria-label="Rate in percent">
  <button class="iconbtn iconbtn--sm" type="button" data-unslab aria-label="Remove this slab">${icon('x', 'i--sm')}</button></div>`;

function regimeFields(key, x) {
  return html`<fieldset class="fieldset" data-regime="${key}"><legend>${REGIME[key]}</legend>
    <div class="form__grid">
      <div class="field"><label for="tt-${key}-std">Standard deduction (₹)</label><input class="input" id="tt-${key}-std" type="number" min="0" data-k="std" value="${x.std}" inputmode="numeric"></div>
      <div class="field"><label for="tt-${key}-rl">Rebate up to taxable income of (₹)</label><input class="input" id="tt-${key}-rl" type="number" min="0" data-k="rebate_limit" value="${x.rebate_limit}" inputmode="numeric"></div>
      <div class="field"><label for="tt-${key}-rm">Rebate, at most (₹)</label><input class="input" id="tt-${key}-rm" type="number" min="0" data-k="rebate_max" value="${x.rebate_max}" inputmode="numeric"></div>
    </div>
    <div class="slabs"><div class="slabrow slabrow--h"><span>Income up to (₹)</span><span>Rate %</span><span></span></div>
      ${x.slabs.map(([u, p]) => slabRow(u, p))}</div>
    <div><button class="btn btn--sm" type="button" data-addslab>${icon('plus')}Add a slab</button>
      <span class="meta">Leave the last slab's amount empty: it is "and above".</span></div></fieldset>`;
}

async function settings(slot, acts) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/payroll/settings'); } catch (err) { mount(slot, errorView(err)); return; }
  const s = d.settings;
  const t = d.tax;
  mount(slot, html`
    <form class="card" id="ps-f" novalidate><div class="card__h"><h2>Payslip and payment</h2></div><div class="card__b form">
      <h3 class="slip__h">How salary shows on the payslip</h3>
      <div class="form__grid">
        <div class="field"><label for="ps-b">Basic (% of salary)</label><input class="input" id="ps-b" name="basic" type="number" min="10" max="90" step="1" value="${s.split.basic}" inputmode="numeric"><div class="err" role="alert"></div></div>
        <div class="field"><label for="ps-h">House rent allowance (%)</label><input class="input" id="ps-h" name="hra" type="number" min="0" max="60" step="1" value="${s.split.hra}" inputmode="numeric"><div class="err" role="alert"></div></div>
      </div>
      <p class="meta" id="ps-sp">Special allowance: the rest, ${s.split.special}%.</p>
      <h3 class="slip__h">ICICI bank file</h3>
      <div class="form__grid">
        <div class="field"><label for="ps-c">ICICI Corporate ID</label><input class="input" id="ps-c" name="corp_id" maxlength="40" value="${s.icici.corp_id || ''}" autocomplete="off" spellcheck="false"><div class="err" role="alert"></div></div>
        <div class="field"><label for="ps-a">Salary account (12 digits)</label><input class="input" id="ps-a" name="debit_account" maxlength="12" value="${s.icici.debit_account || ''}" inputmode="numeric" autocomplete="off"><div class="err" role="alert"></div></div>
      </div>
      <div class="form__grid">
        <div class="field"><label for="ps-d">Pay day (of the next month)</label><input class="input" id="ps-d" name="pay_day" type="number" min="1" max="7" step="1" value="${s.pay_day}" inputmode="numeric">
          <div class="help">The law now says by the 7th.</div><div class="err" role="alert"></div></div>
        <div class="field"><label for="ps-cap">Deduction limit (% of pay)</label><input class="input" id="ps-cap" name="deduction_cap_pct" type="number" min="10" max="50" step="1" value="${s.deduction_cap_pct}" inputmode="numeric">
          <div class="help">Advances and other deductions (not tax) stay within this. The law allows up to 50%.</div><div class="err" role="alert"></div></div>
      </div>
      <h3 class="slip__h">Punjab State Development Tax</h3>
      <div class="switches">${Object.entries(d.centres).map(([code, nm]) => html`<label class="switchrow">
        <span><b>${nm}</b><small>Deduct it from people at this centre who pay income tax</small></span>
        <input type="checkbox" class="switch" name="psdt_${code}" ${s.psdt[code] ? 'checked' : ''}></label>`)}</div>
      <div class="field"><label for="ps-pa">Amount a month (₹)</label><input class="input input--sm" id="ps-pa" name="psdt_amount" type="number" min="0" max="2500" step="1" value="${s.psdt_amount}" inputmode="numeric"><div class="err" role="alert"></div></div>
      <div class="row"><button class="btn btn--pri" type="submit">Save payroll settings</button></div>
    </div></form>

    <form class="card" id="tt-f" novalidate><div class="card__h"><h2>Tax table</h2><span class="sub">Rates for income tax on salary · check with the CA every April</span></div><div class="card__b form">
      <div class="field"><label for="tt-src">Source</label><input class="input" id="tt-src" data-k="source" maxlength="200" value="${t.source || ''}"></div>
      <div class="form__grid">
        <div class="field"><label for="tt-cess">Health and education cess (%)</label><input class="input" id="tt-cess" data-k="cess_pct" type="number" min="0" max="20" step="any" value="${t.cess_pct}" inputmode="decimal"></div>
        <div class="field"><label for="tt-np">Rate when there is no PAN (%)</label><input class="input" id="tt-np" data-k="no_pan_pct" type="number" min="0" max="50" step="any" value="${t.no_pan_pct}" inputmode="decimal"></div>
      </div>
      ${regimeFields('new', t.new)}
      ${regimeFields('old', t.old)}
      <p class="pay-err" role="alert" id="tt-err"></p>
      <div class="row"><button class="btn btn--pri" type="submit">Save the tax table</button></div>
    </div></form>`);

  const ps = $('#ps-f', slot);
  const sp = () => { const b = Number(ps.basic.value) || 0; const h = Number(ps.hra.value) || 0; $('#ps-sp', ps).textContent = `Special allowance: the rest, ${Math.max(0, 100 - b - h)}%.`; };
  ps.basic.addEventListener('input', sp);
  ps.hra.addEventListener('input', sp);
  ps.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', ps), async () => {
      try { toast((await api.patch('/payroll/settings', formData(ps))).message); again(); }
      catch (err) { if (!showFieldErrors(ps, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });

  const tt = $('#tt-f', slot);
  tt.addEventListener('click', (e) => {
    const add = e.target.closest('[data-addslab]');
    const del = e.target.closest('[data-unslab]');
    if (add) {
      const box = $('.slabs', add.closest('[data-regime]'));
      const last = $$('[data-slab]', box).pop();
      const tmp = document.createElement('div');
      mount(tmp, slabRow('', ''));
      box.insertBefore(tmp.firstElementChild, last || null);
    } else if (del) {
      const box = del.closest('.slabs');
      if ($$('[data-slab]', box).length > 1) del.closest('[data-slab]').remove();
    }
  });
  tt.addEventListener('submit', (e) => {
    e.preventDefault();
    const num = (el) => (el.value.trim() === '' ? null : Number(el.value));
    const table = { source: $('[data-k="source"]', tt).value.trim(), cess_pct: num($('[data-k="cess_pct"]', tt)), no_pan_pct: num($('[data-k="no_pan_pct"]', tt)) };
    for (const fs of $$('[data-regime]', tt)) {
      table[fs.dataset.regime] = {
        std: num($('[data-k="std"]', fs)), rebate_limit: num($('[data-k="rebate_limit"]', fs)), rebate_max: num($('[data-k="rebate_max"]', fs)),
        slabs: $$('[data-slab]', fs).map((row) => [num($('[data-upper]', row)), num($('[data-pct]', row))]),
      };
    }
    const errBox = $('#tt-err', tt);
    errBox.textContent = '';
    busy($('[type="submit"]', tt), async () => {
      try { toast((await api.patch('/payroll/tax-table', { table })).message); again(); }
      catch (err) { errBox.textContent = err.message; toast(err.message, { tone: 'bad' }); }
    });
  });
}
