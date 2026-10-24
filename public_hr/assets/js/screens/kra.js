// KRA & incentives — insights, daily boosters, the monthly payout statement and
// the rules. Everything staff see on their CRM "My KRA" page, and every
// incentive, follows what is saved here (KraEngine in the CRM reads the same tables).

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, toast, busy, openDrawer, confirmSheet } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go } from '../app.js';

const usd = (n) => (Number(n) < 0 ? '−$' : '$') + f.num(Math.abs(Math.round(Number(n))));
/** "today", "yesterday", or "on Tue 3 Nov" — reads naturally in the middle of a sentence. */
const when = (s) => { const t = f.stamp(s); return t === 'Today' || t === 'Yesterday' ? t.toLowerCase() : 'on ' + t; };
const TABS = [['insights', 'Insights'], ['boosters', 'Boosters'], ['payouts', 'Payouts'], ['settings', 'Settings']];

export async function render(ctx) {
  const { main, query } = ctx;
  const tab = TABS.some(([k]) => k === query.get('tab')) ? query.get('tab') : 'insights';
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>KRA &amp; incentives</h1>
      <p class="ph__s">Everyone's progress, the incentives it earns, and the rules behind them. Staff see their own on the CRM's "My KRA" page.</p></div>
      <div class="ph__a" id="kra-acts"></div></div>
    <nav class="tabs" aria-label="KRA sections">${TABS.map(([k, l]) => html`<a href="#/kra?tab=${k}" ${k === tab ? raw('aria-current="page"') : ''}>${l}</a>`)}</nav>
    <div class="stack" id="kra-tab"></div>`);
  const slot = $('#kra-tab', main);
  const acts = $('#kra-acts', main);
  await ({ insights, boosters, payouts, settings })[tab](slot, acts, query, ctx);
}

function monthPicker(acts, months, current, tab) {
  mount(acts, html`<label class="sr" for="kra-month">Month</label>
    <select class="select" id="kra-month">${months.map((m) => html`<option value="${m.value}" ${m.value === current ? 'selected' : ''}>${m.label}</option>`)}</select>`);
  $('#kra-month', acts).addEventListener('change', (e) => go(`#/kra?tab=${tab}&month=${e.target.value}`));
}

const notLive = html`<div class="banner banner--warn">${icon('info')}<div><b>Not switched on yet.</b>Save the rules on the Settings tab and staff start seeing their KRA.</div></div>`;

// ---- Insights ------------------------------------------------------------------

async function insights(slot, acts, query) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/kra/insights' + (query.get('month') ? '?month=' + encodeURIComponent(query.get('month')) : '')); }
  catch (err) { mount(slot, errorView(err)); return; }
  monthPicker(acts, d.months, d.month, 'insights');
  if (!d.live) { mount(slot, notLive); return; }
  const fl = d.floor, m = d.money, fu = d.funnel;
  const over = m.scale < 1;
  mount(slot, html`
    <div class="kpis kra-kpis">
      <div class="card kpi"><div class="k">Floor Net MCO</div><div class="v">${usd(fl.mco)}</div>
        <div class="d">${fl.target > 0 ? html`${fl.progress}% of ${usd(fl.target)}` : 'No targets yet'}${d.is_current ? html` · heading for <b>${usd(fl.projected)}</b>` : ''}</div></div>
      <div class="card kpi ${over ? 't-warn' : ''}"><div class="k">${d.closed ? 'Incentives paid (locked)' : d.is_current ? 'Incentives so far' : 'Incentives earned'}</div><div class="v">${f.rupees(m.so_far)}</div>
        <div class="d">${d.is_current ? html`Projected ${f.rupees(m.projected)} · cap ${f.rupees(m.cap)}` : html`Cap ${f.rupees(m.cap)}`}${over ? html` · would be scaled to ${Math.round(m.scale * 100)}%` : html` · within the cap`}</div></div>
      <div class="card kpi"><div class="k">People past Level 1</div><div class="v">${fl.past_l1} <small>of ${fl.set_up}</small></div>
        <div class="d">${fl.sellers - fl.set_up > 0 ? `${fl.sellers - fl.set_up} without a target yet` : 'Everyone has a target'}</div></div>
      <div class="card kpi"><div class="k">Booster tonight</div>${d.booster
        ? html`<div class="v">${f.rupees(d.booster.spent)} <small>of ${f.rupees(d.booster.budget)}</small></div><div class="d">${f.plural(d.booster.sales, 'sale', 'sales')} · ${usd(d.booster.mco)} MCO</div>`
        : html`<div class="v">—</div><div class="d"><a href="#/kra?tab=boosters">Start one on a slow night</a></div>`}</div>
    </div>
    ${!m.breakeven_set ? html`<div class="banner banner--info">${icon('info')}<div><b>Break-even not set.</b>The cap is worked out from all profit until you add the monthly break-even on the Settings tab.</div></div>` : ''}

    <div class="card"><div class="card__h"><h2>Where sales are lost</h2><span class="sub">${d.month_name}, everyone selling</span></div><div class="card__b">
      <div class="kra-funnel">
        <div><span>Calls logged</span><b>${f.num(fu.calls)}</b></div>
        <div><span>Card authorizations sent</span><b>${f.num(fu.auths)}</b>${fu.auths ? html`<em>${Math.round(fu.signed / fu.auths * 100)}% signed by the customer</em>` : ''}</div>
        <div><span>Sales approved</span><b>${f.num(fu.sales)}</b>${fu.calls && fu.sales ? html`<em>1 in ${Math.max(1, Math.round(fu.calls / fu.sales))} logged calls</em>` : ''}</div>
        <div><span>Profit per sale</span><b>${fu.pps === null ? '—' : usd(fu.pps)}</b></div>
      </div>
    </div></div>

    ${d.actions.length ? html`<div class="card"><div class="card__h"><h2>What to do this week</h2></div><div class="card__b">
      <ol class="kra-actions">${d.actions.map((a) => html`<li>${a}</li>`)}</ol></div></div>` : ''}

    <div class="card"><div class="card__h"><h2>Everyone</h2><span class="sub">Incentive amounts are visible only here</span></div>
      <div class="tablewrap"><table class="rtable kra-table">
        <thead><tr><th>Person</th><th>To target</th><th>${d.is_current ? 'Month end at this pace' : 'Month end'}</th><th>Weakest area</th><th class="num">Incentive</th><th></th></tr></thead>
        <tbody>${d.people.map((p) => html`<tr>
          <td><b>${p.name}</b><div class="kra-meta">${p.role}${p.leader ? ' · ' + p.leader : ''}</div></td>
          <td>${p.progress === null ? html`<span class="kra-meta">${p.set_up || p.role_key === 'manager' ? '—' : 'No target'}</span>`
            : html`<div class="kra-prog"><span><i data-w="${p.progress}"></i></span>${p.progress}%</div>`}</td>
          <td>${usd(p.projected)}${p.level_at_pace !== null ? html`<div class="kra-meta">${p.level_at_pace === 0 ? 'below Level 1' : 'Level ' + p.level_at_pace}</div>` : ''}</td>
          <td>${p.weakest || html`<span class="kra-meta">—</span>`}${p.quality_hit ? html`<div><span class="pill pill--sm p-warn">Refunds over the limit</span></div>` : ''}</td>
          <td class="num">${f.rupees(p.incentive)}</td>
          <td><button class="btn btn--sm" type="button" data-fb="${p.id}" data-name="${p.name}">Feedback${p.feedback ? html` <span class="kra-meta">(${p.feedback})</span>` : ''}</button></td>
        </tr>`)}</tbody></table></div></div>`);

  $$('[data-fb]', slot).forEach((b) => b.addEventListener('click', () => feedbackDrawer(Number(b.dataset.fb), b.dataset.name, () => insights(slot, acts, query))));
}

async function feedbackDrawer(id, name, after) {
  let d;
  try { d = await api.get('/kra/feedback?id=' + id); } catch (err) { toast(err.message, { tone: 'bad' }); return; }
  const layer = openDrawer({
    title: 'Feedback for ' + name,
    body: html`<form class="form" id="fb-form" novalidate>
      <div class="field"><label for="fb-body">What should ${name.split(' ')[0]} keep doing, or do differently?</label>
        <textarea class="textarea" id="fb-body" name="body" rows="5" maxlength="2000"></textarea><div class="err" role="alert"></div>
        <div class="help">Shows on their KRA page in the CRM and rings their bell.</div></div>
      <div class="row"><button class="btn btn--pri" type="submit">Send feedback</button></div></form>
      ${d.feedback.length ? html`<h3 class="kra-group">Earlier feedback</h3><div class="stack kra-fb">${d.feedback.map((x) => html`<div class="card card__b"><p>${x.body}</p><div class="kra-meta">${f.stamp(x.at)}</div></div>`)}</div>` : ''}`,
  });
  const form = $('#fb-form', document);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post('/kra/feedback', { id, ...formData(form) })).message); layer.close(); after?.(); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

// ---- Boosters ---------------------------------------------------------------------

const BOOST_STATUS = { active: ['Running', 'p-ok'], scheduled: ['Scheduled', 'p-neutral'], ended: ['Ended', 'p-neutral'], stopped: ['Stopped', 'p-neutral'], budget_used: ['Budget used', 'p-warn'] };

async function boosters(slot, acts) {
  mount(acts, html``);
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/kra/boosters'); } catch (err) { mount(slot, errorView(err)); return; }
  if (!d.live) { mount(slot, notLive); return; }
  mount(slot, html`
    <div class="card"><div class="card__h"><h2>Start a booster</h2><span class="sub">For a slow night — paid on approved sales, within the budget</span></div><div class="card__b">
      <form class="form" id="boost" novalidate><div class="form__grid">
        <div class="field"><label for="b-amount">Amount per sale (₹)</label><input class="input" id="b-amount" name="amount" type="number" min="1" step="1" value="300" inputmode="numeric"><div class="err" role="alert"></div></div>
        <div class="field"><label for="b-min">Only sales over (MCO $) <span class="opt">(optional)</span></label><input class="input" id="b-min" name="min_mco" type="number" min="0" step="1" value="150" inputmode="numeric"><div class="help">Leave blank to pay on every sale.</div><div class="err" role="alert"></div></div>
        <div class="field"><label for="b-until">Until</label><input class="input" id="b-until" name="until" type="datetime-local" value="${d.default_until}"><div class="err" role="alert"></div></div>
        <div class="field"><label for="b-scope">Who</label><select class="select" id="b-scope" name="scope"><option value="all">Everyone selling</option>${d.teams.map((t) => html`<option value="${t.value}">${t.label}</option>`)}</select><div class="err" role="alert"></div></div>
        <div class="field"><label for="b-budget">Budget for the night (₹)</label><input class="input" id="b-budget" name="budget" type="number" min="1" step="1" value="6000" inputmode="numeric"><div class="help">It stops paying once this is used up.</div><div class="err" role="alert"></div></div>
      </div>
      <div class="row"><button class="btn btn--pri" type="submit">Start booster</button><span class="kra-meta">Staff see it on their KRA page straight away and Aisha tells them.</span></div></form>
    </div></div>
    <div class="card"><div class="card__h"><h2>This month's boosters</h2></div>
      ${d.boosters.length ? html`<div class="tablewrap"><table class="rtable kra-table">
        <thead><tr><th>Booster</th><th>Who</th><th>When</th><th class="num">Paid</th><th>Brought in</th><th></th></tr></thead>
        <tbody>${d.boosters.map((b) => html`<tr>
          <td><b>${b.rule}</b><div><span class="pill pill--sm ${BOOST_STATUS[b.status][1]}">${BOOST_STATUS[b.status][0]}</span></div></td>
          <td>${b.who}</td>
          <td>${f.stamp(b.starts_at)}, ${f.time(b.starts_at)}<div class="kra-meta">until ${f.stamp(b.ends_at)}, ${f.time(b.ends_at)}</div></td>
          <td class="num">${f.rupees(b.spent)}<div class="kra-meta">of ${f.rupees(b.budget)}</div></td>
          <td>${f.plural(b.sales, 'sale', 'sales')}<div class="kra-meta">${usd(b.mco)} MCO</div></td>
          <td>${b.status === 'active' ? html`<button class="btn btn--sm" type="button" data-stop="${b.id}">Stop</button>` : ''}</td>
        </tr>`)}</tbody></table></div>` : html`<div class="card__b"><p class="kra-meta">No boosters this month yet.</p></div>`}
    </div>`);

  const form = $('#boost', slot);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post('/kra/boosters', formData(form))).message); boosters(slot, acts); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
  $$('[data-stop]', slot).forEach((b) => b.addEventListener('click', () => confirmSheet({
    title: 'Stop this booster?', text: 'Sales already made under it are still paid. Nobody earns from it after now.',
    confirm: 'Stop booster', tone: 'danger',
    onConfirm: async () => { toast((await api.post(`/kra/boosters/${b.dataset.stop}/stop`)).message); boosters(slot, acts); },
  })));
}

// ---- Payouts -------------------------------------------------------------------------

async function payouts(slot, acts, query) {
  mount(slot, skeleton('list'));
  const qm = query.get('month') || '';
  let d;
  try { d = await api.get('/kra/payouts' + (qm ? '?month=' + encodeURIComponent(qm) : '')); } catch (err) { mount(slot, errorView(err)); return; }
  monthPicker(acts, d.months, d.month, 'payouts');
  if (d.state === 'not_live') { mount(slot, notLive); return; }
  const banner = d.state === 'closed'
    ? html`<div class="banner banner--info">${icon('lock')}<div><b>Closed ${when(d.closed_at)}.</b>These figures are locked for payroll${d.scale < 1 ? ` (the cap scaled everyone to ${Math.round(d.scale * 100)}%)` : ''}.</div></div>`
    : d.state === 'running'
      ? html`<div class="banner banner--info">${icon('info')}<div><b>${d.month_name} is still running.</b>This is what has been earned so far. It can be closed after ${f.dayLong(d.cutoff)}, once late refunds are in.</div></div>`
      : html`<div class="banner ${d.can_close ? 'banner--info' : 'banner--warn'}">${icon('info')}<div><b>${d.can_close ? 'Ready to close.' : 'Wait for late refunds.'}</b>${d.can_close
        ? 'Closing locks these figures for payroll. Refunds after this point are not taken off.'
        : `Refunds that come in before you close are taken off. Wait until after ${f.dayLong(d.cutoff)} so the late ones are in.`}</div></div>`;
  const capNote = d.state !== 'closed' && d.scale < 1
    ? html`<div class="banner banner--warn">${icon('info')}<div><b>Over the cap.</b>Incentives add up to ${f.rupees(d.before_cap)} but the cap is ${f.rupees(d.cap)}, so everyone is scaled to ${Math.round(d.scale * 100)}%.</div></div>` : '';
  mount(slot, html`${banner}${capNote}
    <div class="card"><div class="card__h"><h2>Payout statement · ${d.month_name}</h2><span class="sub">Total ${f.rupees(d.total)}</span></div>
      ${d.rows.length ? html`<div class="tablewrap"><table class="rtable kra-table">
        <thead><tr><th>Person</th><th class="num">Net MCO</th><th class="num">Monthly</th><th class="num">Team</th><th class="num">Spot rewards</th><th class="num">Boosters</th><th class="num">Total</th></tr></thead>
        <tbody>${d.rows.map((r) => html`<tr><td><b>${r.name}</b><div class="kra-meta">${r.role}</div></td>
          <td class="num">${usd(r.net_mco)}</td><td class="num">${f.rupees(r.monthly)}</td><td class="num">${f.rupees(r.team)}</td>
          <td class="num">${f.rupees(r.spot)}</td><td class="num">${f.rupees(r.booster)}</td><td class="num"><b>${f.rupees(r.total)}</b></td></tr>`)}</tbody>
      </table></div>` : html`<div class="card__b"><p class="kra-meta">Nobody earned an incentive this month.</p></div>`}
      <div class="card__b"><div class="row">
        ${d.state === 'open' && d.can_close ? html`<button class="btn btn--pri" type="button" id="close-m">Close ${d.month_name} and lock</button>` : ''}
        ${d.state === 'closed' ? html`<button class="btn" type="button" id="reopen-m">Reopen</button>` : ''}
        ${d.rows.length ? html`<button class="btn" type="button" id="csv">${icon('down')}Download for payroll (CSV)</button>` : ''}
      </div></div>
    </div>`);

  $('#csv', slot)?.addEventListener('click', () => {
    const lines = [['Name', 'Role', 'Net MCO ($)', 'Monthly (INR)', 'Team (INR)', 'Spot rewards (INR)', 'Boosters (INR)', 'Total (INR)']]
      .concat(d.rows.map((r) => [r.name, r.role, Math.round(r.net_mco), r.monthly, r.team, r.spot, r.booster, r.total]))
      .map((row) => row.map((v) => `"${String(v).replace(/^[=+\-@\t\r]/, "'$&").replace(/"/g, '""')}"`).join(','));
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\r\n')], { type: 'text/csv' }));
    a.download = `kra-incentives-${d.month}${d.state === 'closed' ? '' : '-draft'}.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  });
  $('#close-m', slot)?.addEventListener('click', () => confirmSheet({
    title: `Close ${d.month_name}?`, text: `${f.rupees(d.total)} for ${f.plural(d.rows.filter((r) => r.total > 0).length, 'person', 'people')}. The figures are then locked for payroll.`,
    confirm: 'Close and lock',
    onConfirm: async () => { toast((await api.post('/kra/payouts/close', { month: d.month })).message); payouts(slot, acts, query); },
  }));
  $('#reopen-m', slot)?.addEventListener('click', () => confirmSheet({
    title: `Reopen ${d.month_name}?`, text: 'The statement goes back to live figures (refunds since closing will be taken off) until you close it again.',
    confirm: 'Reopen', tone: 'danger',
    onConfirm: async () => { toast((await api.post('/kra/payouts/reopen', { month: d.month })).message); payouts(slot, acts, query); },
  }));
}

// ---- Settings ------------------------------------------------------------------------

async function settings(slot, acts, query) {
  mount(slot, skeleton('list'));
  const month = query.get('month') || '';
  let d;
  try { d = await api.get('/kra/settings' + (month ? '?month=' + encodeURIComponent(month) : '')); }
  catch (err) { mount(slot, errorView(err)); return; }
  monthPicker(acts, d.months, d.month, 'settings');

  mount(slot, html`
    ${!d.saved ? html`<div class="banner banner--warn">${icon('info')}<div><b>Not switched on yet.</b>Staff don't see any KRA until you save the rules below. The numbers filled in are suggestions to start from.</div></div>`
      : d.inherited ? html`<div class="banner banner--info">${icon('info')}<div><b>Carried over from ${d.from_month}.</b>${d.month_name} uses the same rules until you save a change here.</div></div>` : ''}
    <div class="card"><div class="card__h"><h2>Rules for ${d.month_name}</h2><span class="sub">Levels, rates and limits</span></div><div class="card__b">
      <form class="form" id="kra-rules" novalidate>
        ${d.groups.map((g) => html`
          <h3 class="kra-group">${g.title}</h3>
          <div class="form__grid">${g.fields.map((x) => html`<div class="field">
            <label for="k-${x.key}">${x.label} <span class="opt">(${x.unit})</span></label>
            <input class="input" id="k-${x.key}" name="${x.key}" type="number" step="any" min="${x.min}" max="${x.max}" value="${x.value}" inputmode="decimal" autocomplete="off">
            <div class="err" role="alert"></div></div>`)}</div>`)}
        <div class="row"><button class="btn btn--pri" type="submit">Save rules for ${d.month_name}</button></div>
      </form></div></div>

    <div class="card"><div class="card__h"><h2>People and targets</h2><span class="sub">Monthly cost in ₹ · target in $ Net MCO (leave blank to work it out from cost)</span></div>
      <form id="kra-people" novalidate>
        <div class="tablewrap"><table class="rtable kra-people">
          <thead><tr><th>Person</th><th>Monthly cost (₹)</th><th>Target (Net MCO $)</th><th>Level 1 · Target · Level 3</th></tr></thead>
          <tbody>${d.people.map((p) => html`<tr data-id="${p.id}">
            <td><b>${p.name}</b><div class="kra-meta">${p.role}${p.leader ? ' · ' + p.leader : ''}</div></td>
            <td><div class="field"><label class="sr" for="cost_${p.id}">Monthly cost for ${p.name}</label>
              <input class="input" id="cost_${p.id}" name="cost_${p.id}" type="number" min="0" step="1" value="${p.cost ?? ''}" placeholder="₹ per month" inputmode="numeric"><div class="err" role="alert"></div></div></td>
            <td>${p.role_key === 'manager' ? html`<span class="kra-meta">Sum of their teams' targets</span>`
              : html`<div class="field"><label class="sr" for="target_${p.id}">Target for ${p.name}</label>
              <input class="input" id="target_${p.id}" name="target_${p.id}" type="number" min="0" step="1" value="${p.target ?? ''}" placeholder="${p.levels ? 'auto · ' + usd(p.levels.l2) : 'auto'}" inputmode="numeric"><div class="err" role="alert"></div></div>`}</td>
            <td>${p.role_key === 'manager' ? html`<span class="kra-meta">—</span>`
              : p.levels ? html`${usd(p.levels.l1)} · <b>${usd(p.levels.l2)}</b> · ${usd(p.levels.l3)}`
              : html`<span class="pill pill--sm p-warn">Needs a cost or target</span>`}</td>
          </tr>`)}</tbody>
        </table></div>
        <div class="card__b"><div class="row"><button class="btn btn--pri" type="submit">Save costs and targets</button>
          <span class="kra-meta">Levels shown use the rules as last saved.</span></div></div>
      </form>
    </div>`);

  const again = () => settings(slot, acts, query);
  const rules = $('#kra-rules', slot);
  rules.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', rules), async () => {
      try { toast((await api.patch('/kra/settings', { month: d.month, ...formData(rules) })).message); again(); }
      catch (err) { if (!showFieldErrors(rules, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
  const people = $('#kra-people', slot);
  people.addEventListener('submit', (e) => {
    e.preventDefault();
    const list = $$('tbody tr[data-id]', people).map((tr) => {
      const id = tr.dataset.id;
      return { id: Number(id), cost: $(`[name="cost_${id}"]`, tr)?.value ?? '', target: $(`[name="target_${id}"]`, tr)?.value ?? '' };
    });
    busy($('[type="submit"]', people), async () => {
      try { toast((await api.patch('/kra/people', { month: d.month, people: list })).message); again(); }
      catch (err) { if (!showFieldErrors(people, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}
