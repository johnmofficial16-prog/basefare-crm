// People directory.

import { html, mount, $, $$, icon } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go, state } from '../app.js';

const HR_PILL = {
  joined: ['p-ok', 'On Trio HR'], invited: ['p-gold', 'Invited'], not_invited: ['p-neutral', 'Not invited'], disabled: ['p-bad', 'Switched off'],
};
const STATUS_PILL = { joining: 'p-info', active: 'p-ok', notice: 'p-warn', left: 'p-neutral' };
const TONIGHT = { working: 'Working now', on_break: 'On a break', asking: 'Waiting to clock in', not_in: 'Not in yet', on_leave: 'On leave', finished: 'Finished tonight' };

// ---- Org chart (#/org): built from team-leader links on each record ------------------------

export async function org({ main }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/people'); } catch (err) { mount(main, errorView(err)); return; }
  const byId = new Map(d.people.map((p) => [p.id, p]));
  const kids = new Map();
  for (const p of d.people) {
    const lead = p.reports_to_id && byId.has(p.reports_to_id) ? p.reports_to_id : null;
    if (!kids.has(lead)) kids.set(lead, []);
    kids.get(lead).push(p);
  }
  const card = (p) => html`<a class="orgcard" href="#/people/${p.id}">
    <span class="av av--sm">${p.initials}${p.tonight ? html`<i class="live s-${p.tonight}"></i>` : ''}</span>
    <span><b>${p.name}</b><small>${p.designation || ''}${p.centre_name ? ' · ' + p.centre_name : ''}</small></span></a>`;
  const branch = (p, depth = 0) => {
    const team = (kids.get(p.id) || []).sort((a, b) => a.name.localeCompare(b.name));
    if (!team.length) return card(p);
    return html`<section class="orgteam ${depth ? 'orgteam--nested' : ''}">
      <div class="orgteam__lead">${card(p)}<span class="meta">${f.plural(team.length, 'person', 'people')} in the team</span></div>
      <div class="orgteam__grid">${team.map((m) => (kids.get(m.id) || []).length ? branch(m, depth + 1) : card(m))}</div>
    </section>`;
  };
  const roots = (kids.get(null) || []).sort((a, b) => (kids.get(b.id)?.length || 0) - (kids.get(a.id)?.length || 0) || a.name.localeCompare(b.name));
  const leads = roots.filter((p) => kids.get(p.id)?.length);
  const solo = roots.filter((p) => !kids.get(p.id)?.length);
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>People</h1><p class="ph__s">${f.plural(d.people.length, 'person', 'people')} · built from each person's team leader</p></div>
      <div class="ph__a"><a class="btn btn--pri" href="#/people/new">${icon('plus')}Add a person</a></div></div>
    <nav class="views views--seg" aria-label="People views"><a href="#/people">${icon('users', 'i--sm')}Directory</a><a href="#/org" aria-current="true">${icon('org', 'i--sm')}Org chart</a></nav>
    <div class="orgchart">${leads.map((p) => branch(p))}
      ${solo.length ? html`<section class="orgteam"><div class="orgteam__lead"><b class="orgteam__k">Not in a team</b><span class="meta">Set a team leader on their record to place them</span></div>
        <div class="orgteam__grid">${solo.map(card)}</div></section>` : ''}</div>`);
}

const countLine = (d) => `${f.plural(d.people.length, 'person', 'people')} shown · ${d.hr_counts.joined} on Trio HR · ${d.hr_counts.invited} invited · ${d.hr_counts.not_invited} not invited yet`;

function chips(counts, hr) {
  return html`${[['', 'Everyone'], ['not_joined', 'Not on Trio HR yet'], ['not_invited', 'Not invited'], ['invited', 'Invited'], ['joined', 'On Trio HR']].map(([v, l]) =>
    html`<button type="button" data-hr="${v}" ${v === hr ? html`aria-current="true"` : ''}>${l}${v && v !== 'not_joined' ? html` <em>${counts[v]}</em>` : ''}</button>`)}`;
}

function table(d) {
  return d.people.length ? html`
    <div class="card"><div class="tablewrap"><table class="rtable">
      <thead><tr><th>Person</th><th class="hide-sm hide-md">Team</th><th class="hide-sm">Centre</th><th class="hide-sm hide-md">Joined</th><th>Trio HR</th><th class="hide-sm">Status</th></tr></thead>
      <tbody>${d.people.map((p) => html`<tr data-id="${p.id}">
        <td><div class="who"><span class="av av--sm">${p.initials}${p.tonight ? html`<i class="live s-${p.tonight}" title="${TONIGHT[p.tonight] || ''}"></i>` : ''}</span>
          <a href="#/people/${p.id}"><b>${p.name}</b><small>${p.designation || 'No job title yet'}${p.tonight && TONIGHT[p.tonight] ? ` · ${TONIGHT[p.tonight]}` : ''}</small></a></div></td>
        <td class="hide-sm hide-md">${p.team || html`<span class="muted">—</span>`}${p.team_leader ? html`<small class="meta"> · ${p.team_leader}</small>` : ''}</td>
        <td class="hide-sm">${p.centre_name || '—'}</td>
        <td class="hide-sm hide-md nowrap">${!p.joined ? '—' : p.status === 'joining' ? html`<span class="pill pill--sm p-info">Joins ${f.day(p.joined)}</span>` : html`${f.day(p.joined)}<small class="meta"> · ${p.tenure}</small>`}</td>
        <td><span class="pill pill--sm ${HR_PILL[p.hr][0]}">${HR_PILL[p.hr][1]}</span></td>
        <td class="hide-sm"><span class="pill pill--sm ${STATUS_PILL[p.status]}">${p.status_label}</span></td>
      </tr>`)}</tbody>
    </table></div></div>`
    : html`<div class="card"><div class="empty"><div class="dot d-neutral">${icon('users')}</div><b>No one matches</b><p>Try another name, or clear the filters.</p><a class="btn" href="#/people">Clear filters</a></div></div>`;
}

export async function render({ main, query, isCurrent }) {
  mount(main, skeleton('list'));
  const q = Object.fromEntries(query.entries());
  let d;
  try {
    d = await api.get('/people?' + new URLSearchParams(q).toString());
  } catch (err) {
    mount(main, errorView(err));
    return;
  }
  const status = q.status || 'current';
  const hr = q.hr || '';
  const counts = d.hr_counts;

  const setQ = (k, v) => {
    const next = new URLSearchParams(location.hash.split('?')[1] || '');
    if (v) next.set(k, v); else next.delete(k);
    go('#/people' + (next.toString() ? '?' + next.toString() : ''), { replace: true });
  };

  mount(main, html`
    <div class="ph">
      <div class="ph__t"><h1>People</h1>
        <p class="ph__s" id="pcount">${countLine(d)}</p></div>
      <div class="ph__a">
        ${d.crm_waiting ? html`<a class="btn" href="#/people/from-crm">${icon('link')}Bring in from the CRM (${d.crm_waiting})</a>` : ''}
        ${counts.not_invited && state.emailOn ? html`<button class="btn" type="button" id="inviteall">${icon('send')}Invite everyone not on Trio HR (${counts.not_invited})</button>` : ''}
        <a class="btn btn--pri" href="#/people/new">${icon('plus')}Add a person</a>
      </div>
    </div>
    <nav class="views views--seg" aria-label="People views"><a href="#/people" aria-current="true">${icon('users', 'i--sm')}Directory</a><a href="#/org">${icon('org', 'i--sm')}Org chart</a></nav>
    <div class="toolbar">
      <label class="searchbox"><span class="sr">Search people</span>${icon('search')}<input class="input" type="search" id="q" value="${q.q || ''}" placeholder="Name, code, phone or email" autocomplete="off"></label>
      <div class="field"><label class="sr" for="fs">Status</label><select class="select" id="fs">
        ${[['current', 'Current staff'], ['joining', 'Joining'], ['active', 'Working'], ['notice', 'On notice'], ['left', 'Left'], ['all', 'Everyone']].map(([v, l]) => html`<option value="${v}" ${v === status ? 'selected' : ''}>${l}</option>`)}
      </select></div>
      <div class="field"><label class="sr" for="fc">Centre</label><select class="select" id="fc">
        <option value="">All centres</option>${d.centres.map((c) => html`<option value="${c.code}" ${c.code === q.centre ? 'selected' : ''}>${c.name}</option>`)}
      </select></div>
      ${d.teams.length ? html`<div class="field"><label class="sr" for="ft">Team</label><select class="select" id="ft">
        <option value="">All teams</option>${d.teams.map((t) => html`<option ${t === q.team ? 'selected' : ''}>${t}</option>`)}
      </select></div>` : ''}
    </div>
    <nav class="views" aria-label="Trio HR account" id="pchips">${chips(counts, hr)}</nav>
    <div id="ptable" aria-live="polite">${table(d)}</div>`);

  // Search as you type: only the table and counts refresh, so the box keeps what you type.
  let t;
  let seq = 0;
  $('#q', main).addEventListener('input', (e) => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const next = new URLSearchParams(location.hash.split('?')[1] || '');
      const v = e.target.value.trim();
      if (v) next.set('q', v); else next.delete('q');
      history.replaceState(null, '', '#/people' + (next.toString() ? '?' + next.toString() : ''));
      const mine = ++seq;
      let nd;
      try { nd = await api.get('/people?' + next.toString()); } catch (err) { toast(err.message, { tone: 'bad' }); return; }
      if (mine !== seq || !isCurrent()) return;
      mount($('#ptable', main), table(nd));
      mount($('#pcount', main), countLine(nd));
      mount($('#pchips', main), chips(nd.hr_counts, next.get('hr') || ''));
    }, 300);
  });
  if (q.q) { const i = $('#q', main); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }
  $('#fs', main).addEventListener('change', (e) => setQ('status', e.target.value === 'current' ? '' : e.target.value));
  $('#fc', main).addEventListener('change', (e) => setQ('centre', e.target.value));
  $('#ft', main)?.addEventListener('change', (e) => setQ('team', e.target.value));
  main.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-hr]');
    if (chip) { setQ('hr', chip.dataset.hr); return; }
    const tr = e.target.closest('tr[data-id]');
    if (tr && !e.target.closest('a')) go(`#/people/${tr.dataset.id}`);
  });
  $('#inviteall', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({
      title: `Invite ${f.plural(counts.not_invited, 'person', 'people')} to Trio HR?`,
      text: 'Each person gets an email with a link to set their password and check their own details. Anyone without an email address on file is skipped, and you will see who.',
      confirm: 'Send the invitations',
      onConfirm: () => api.post('/people/invite-all'),
    });
    if (r) { toast(r.message); go(location.hash, { replace: true }); }
  });
}

// ---- Bring in staff from the CRM (#/people/from-crm) --------------------------------------

/**
 * Every active CRM login that has no HR record yet, prefilled from the CRM.
 * Tick who to bring in, check the centre, start date and job, then bring them
 * in (and optionally invite them) in one go.
 */
export async function fromCrm({ main, isCurrent }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/people/from-crm'); } catch (err) { mount(main, errorView(err)); return; }
  if (!isCurrent()) return;
  const today = f.todayIST();
  mount(main, html`
    <a class="crumb" href="#/people">${icon('back', 'i--sm')}People</a>
    <div class="ph"><div class="ph__t"><h1>Bring in staff from the CRM</h1>
      <p class="ph__s">${d.people.length ? `${f.plural(d.people.length, 'CRM login')} without a Trio HR record. Details come from the CRM; check them before bringing people in.` : 'Everyone with a CRM login is already in Trio HR.'}</p></div></div>
    ${d.people.length ? html`<form class="card" id="bf" novalidate>
      <div class="card__h"><label class="check-row"><input type="checkbox" id="all" checked> Select everyone</label><span class="meta" id="bcount"></span></div>
      <div class="rtable-wrap"><table class="rtable bring">
        <thead><tr><th><span class="sr">Bring in</span></th><th>Person</th><th>Job title</th><th>Centre</th><th>Started</th><th class="hide-md">Team leader</th></tr></thead>
        <tbody>${d.people.map((p, i) => html`<tr data-row="${i}">
          <td><input type="checkbox" class="pick" data-i="${i}" checked aria-label="Bring in ${p.name}"></td>
          <td><b>${p.name}</b><small class="block">${p.email}${p.email_taken ? ' · email already used in HR' : ''} · CRM: ${p.role}</small><div class="rowerr" role="alert" data-err="${i}"></div></td>
          <td><label class="sr" for="d-${i}">Job title for ${p.name}</label><input class="input input--sm" id="d-${i}" data-f="designation" value="${p.designation}" maxlength="80"></td>
          <td><label class="sr" for="c-${i}">Centre for ${p.name}</label><select class="select select--sm" id="c-${i}" data-f="centre">
            <option value="">Pick…</option>${d.centres.map((c) => html`<option value="${c.code}" ${c.code === p.centre ? 'selected' : ''}>${c.name}</option>`)}</select></td>
          <td><label class="sr" for="j-${i}">Start date for ${p.name}</label><input class="input input--sm" type="date" id="j-${i}" data-f="date_joined" value="${p.date_joined || ''}" max="${today}"></td>
          <td class="hide-md">${p.team_leader ? p.team_leader.name : html`<span class="muted">Not in HR yet</span>`}</td>
        </tr>`)}</tbody></table></div>
      <div class="actbar">
        ${state.emailOn ? html`<label class="check-row"><input type="checkbox" name="invite" checked> Also invite them to Trio HR by email</label>`
          : html`<span class="meta">They open Trio HR from the CRM's Trio HR button. No invite needed.</span>`}
        <button class="btn btn--pri push" type="submit">${icon('check')}<span>Bring in</span></button>
      </div>
    </form>
    <p class="meta pagenote">Centre comes from the CRM's centre settings, start date from when their CRM login was made, and job title from their CRM role. Change anything that's wrong. Nothing in the CRM changes.</p>`
    : html`<div class="card"><div class="empty"><div class="dot d-ok">${icon('check')}</div><b>All linked</b><p>New CRM logins show up here until they're brought in.</p></div></div>`}`);
  const form = $('#bf', main);
  if (!form) return;
  const picks = () => $$('.pick', form).filter((x) => x.checked);
  const count = () => {
    const n = picks().length;
    $('#bcount', form).textContent = `${n} of ${d.people.length} selected`;
    $('[type="submit"] span', form).textContent = n ? `Bring in ${f.plural(n, 'person', 'people')}` : 'Pick someone first';
    $('[type="submit"]', form).disabled = !n;
    $('#all', form).checked = n === d.people.length;
  };
  $('#all', form).addEventListener('change', (e) => { $$('.pick', form).forEach((x) => { x.checked = e.target.checked; }); count(); });
  form.addEventListener('change', (e) => { if (e.target.classList.contains('pick')) count(); });
  count();
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    $$('[data-err]', form).forEach((x) => { x.textContent = ''; });
    const rows = picks().map((x) => {
      const i = Number(x.dataset.i);
      const tr = $(`[data-row="${i}"]`, form);
      return { i, crm_user_id: d.people[i].crm_user_id, ...Object.fromEntries($$('[data-f]', tr).map((el) => [el.dataset.f, el.value.trim()])) };
    });
    const missing = rows.find((r) => !r.centre || !r.date_joined || !r.designation);
    if (missing) {
      $(`[data-err="${missing.i}"]`, form).textContent = 'Fill in the job title, centre and start date.';
      $(`[data-row="${missing.i}"] [data-f="${!missing.designation ? 'designation' : !missing.centre ? 'centre' : 'date_joined'}"]`, form)?.focus();
      return;
    }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/people/from-crm', { people: rows.map(({ i, ...x }) => x), invite: !!form.invite?.checked });
        toast(r.message);
        go('#/people', { replace: true });
      } catch (err) {
        let shown = false;
        for (const [k, msg] of Object.entries(err.fields || {})) {
          const row = rows[Number(k.split('.')[1])];
          if (row) { $(`[data-err="${row.i}"]`, form).textContent = msg; shown = true; }
        }
        toast(err.message, { tone: 'bad' });
        if (!shown) $('[type="submit"]', form).focus();
      }
    });
  });
}
