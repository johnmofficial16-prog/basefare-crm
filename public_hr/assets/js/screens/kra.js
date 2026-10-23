// KRA & incentives — the rules for the month and each person's cost and target.
// Everything staff see on their CRM "My KRA" page, and every incentive, follows
// what is saved here (KraEngine in the CRM reads the same tables).

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go } from '../app.js';

const usd = (n) => '$' + f.num(Math.round(Number(n)));

export async function render({ main, query }) {
  const month = query.get('month') || '';
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/kra/settings' + (month ? '?month=' + encodeURIComponent(month) : '')); }
  catch (err) { mount(main, errorView(err)); return; }

  mount(main, html`
    <div class="ph">
      <div class="ph__t"><h1>KRA &amp; incentives</h1>
        <p class="ph__s">The rules every KRA and incentive follows. Staff see their own progress and incentive on the CRM's "My KRA" page.</p></div>
      <div class="ph__a">
        <label class="sr" for="kra-month">Month</label>
        <select class="select" id="kra-month">${d.months.map((m) => html`<option value="${m.value}" ${m.value === d.month ? 'selected' : ''}>${m.label}</option>`)}</select>
      </div>
    </div>
    ${!d.saved ? html`<div class="banner banner--warn">${icon('info')}<div><b>Not switched on yet.</b>Staff don't see any KRA until you save the rules below. The numbers filled in are suggestions to start from.</div></div>`
      : d.inherited ? html`<div class="banner banner--info">${icon('info')}<div><b>Carried over from ${d.from_month}.</b>${d.month_name} uses the same rules until you save a change here.</div></div>` : ''}
    <div class="stack">
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
      </div>
    </div>`);

  $('#kra-month', main).addEventListener('change', (e) => go('#/kra?month=' + e.target.value));

  const rules = $('#kra-rules', main);
  rules.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', rules), async () => {
      try {
        toast((await api.patch('/kra/settings', { month: d.month, ...formData(rules) })).message);
        render({ main, query });
      } catch (err) { if (!showFieldErrors(rules, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });

  const people = $('#kra-people', main);
  people.addEventListener('submit', (e) => {
    e.preventDefault();
    const list = $$('tbody tr[data-id]', people).map((tr) => {
      const id = tr.dataset.id;
      return { id: Number(id), cost: $(`[name="cost_${id}"]`, tr)?.value ?? '', target: $(`[name="target_${id}"]`, tr)?.value ?? '' };
    });
    busy($('[type="submit"]', people), async () => {
      try {
        toast((await api.patch('/kra/people', { month: d.month, people: list })).message);
        render({ main, query });
      } catch (err) { if (!showFieldErrors(people, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}
