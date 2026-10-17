// Today (admin home), the full "Tonight on the floor" list and compliance dates.

import { html, mount, $, icon, applyDyn } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { setLive, go } from '../app.js';

const TONE_DOT = { bad: 'd-bad', warn: 'd-warn', info: 'd-info', ok: 'd-ok', gold: 'd-gold', neutral: 'd-neutral' };
const STATES = [
  ['working', 'Working'], ['on_break', 'On a break'], ['asking', 'Waiting to clock in'],
  ['finished', 'Done for the night'], ['on_leave', 'On leave'], ['not_in', 'No clock-in tonight'],
];

function clockLine(c) {
  return html`${c.date} · Mohali ${c.ist} · New York ${c.ny}`;
}

function kpi(k) {
  return html`<a class="card kpi ${k.tone === 'warn' ? 't-warn' : ''}" href="${k.href}">
    <div class="k">${icon(k.icon, 'i--sm')}${k.label}</div>
    <div class="v tnum">${k.value}${k.of ? html` <small>${k.of}</small>` : ''}</div>
    <div class="d">${k.note}</div></a>`;
}

function needRow(n) {
  return html`<div class="need ${n.tone === 'bad' ? 'is-urgent' : ''}">
    <div class="dot ${TONE_DOT[n.tone] || 'd-neutral'}">${icon(n.icon)}</div>
    <div class="need__t"><b>${n.title}</b><span>${n.text}</span></div>
    <a class="btn btn--sm ${n.tone === 'bad' ? 'btn--pri' : ''}" href="${n.href}" ${n.external ? html`target="_blank" rel="noopener"` : ''}>${n.label}${n.external ? icon('ext', 'i--sm') : ''}</a>
  </div>`;
}

function floorCard(fl) {
  const c = fl.counts;
  const total = Math.max(1, c.working + c.on_break + c.finished + c.not_in + c.asking + c.on_leave);
  const seg = (k) => Math.round((c[k] / total) * 1000) / 10;
  const groups = fl.groups;
  const names = (list, max = 3) => list.slice(0, max).map((p, i) => html`${i ? ', ' : ''}${p.employee_id ? html`<a href="#/people/${p.employee_id}">${p.name}</a>` : p.name}`);
  return html`
    <div class="card" id="floorcard">
      <div class="card__h"><h2>Tonight on the floor</h2><a href="#/floor">Everyone, live</a></div>
      <div class="card__b">
        <div class="floorbar" role="img" aria-label="${c.working} working, ${c.on_break} on a break, ${c.finished} done, ${c.not_in + c.asking} no clock-in, ${c.on_leave} on leave">
          <i class="c-working" data-w="${seg('working')}"></i><i class="c-on_break" data-w="${seg('on_break')}"></i><i class="c-finished" data-w="${seg('finished')}"></i>
          <i class="c-asking" data-w="${seg('asking')}"></i><i class="c-on_leave" data-w="${seg('on_leave')}"></i><i class="c-not_in" data-w="${seg('not_in')}"></i>
        </div>
        <div class="legend tnum">
          <span><i class="c-working"></i>Working<b>${c.working}</b></span>
          <span><i class="c-on_break"></i>On a break<b>${c.on_break}</b></span>
          <span><i class="c-finished"></i>Done<b>${c.finished}</b></span>
          <span><i class="c-on_leave"></i>On leave<b>${c.on_leave}</b></span>
          <span><i class="c-not_in"></i>No clock-in<b>${c.not_in + c.asking}</b></span>
        </div>
        ${groups.asking.length || groups.on_leave.length ? html`
        <div class="floornote">
          ${groups.asking.length ? html`<div><b>Waiting to clock in:</b> ${names(groups.asking)}. Decide in the CRM live board.</div>` : ''}
          ${groups.on_leave.length ? html`<div><b>On leave:</b> ${names(groups.on_leave, 4)}.</div>` : ''}
        </div>` : ''}
      </div>
    </div>`;
}

function complianceCard(items) {
  const due = items.filter((i) => i.tone !== 'neutral').length;
  return html`<div class="card">
    <div class="card__h"><h2>Compliance</h2><span class="row">${due ? html`<span class="pill ${items.some((i) => i.tone === 'bad') ? 'p-bad' : 'p-warn'}">${f.plural(due, 'date')} due soon</span>` : ''}<a href="#/compliance">All dates</a></span></div>
    <div class="card__b card__b--tight">
      ${items.length ? items.slice(0, 4).map((i) => html`<div class="minirow">
        <div class="dot dot--sm ${i.tone === 'bad' ? 'd-bad' : i.tone === 'warn' ? 'd-warn' : 'd-neutral'}">${icon(i.tone === 'neutral' ? 'cal' : 'clock', 'i--sm')}</div>
        <div>${i.title}<small>Due ${f.day(i.due_on)}${i.note ? ` · ${i.note}` : ''}</small></div>
        <span class="r pill ${i.tone === 'bad' ? 'p-bad' : i.tone === 'warn' ? 'p-warn' : 'p-neutral'}">${i.days < 0 ? `${f.plural(-i.days, 'day')} late` : i.days === 0 ? 'Today' : f.plural(i.days, 'day')}</span>
      </div>`) : html`<div class="minirow">${icon('check')}<div>Nothing due. All clear.</div></div>`}
      ${items.length > 4 ? html`<div class="minirow"><a class="linkbtn" href="#/compliance">See all ${items.length} dates</a></div>` : ''}
    </div></div>`;
}

function momentsCard(list) {
  if (!list.length) return '';
  return html`<div class="card">
    <div class="card__h"><h2>People moments</h2></div>
    <div class="card__b card__b--tight">${list.map((m) => html`<div class="minirow">
      <span class="av av--sm">${m.initials}</span>
      <div><a class="name" href="#/people/${m.employee_id}">${m.title}</a><small>${m.when}</small></div>
      ${m.kind === 'birthday' ? html`<span class="r">${icon('gift')}</span>` : ''}
    </div>`)}</div></div>`;
}

function feedCard(items) {
  return html`<div class="card">
    <div class="card__h"><h2>What happened</h2><span class="sub">Recorded automatically</span></div>
    <div class="card__b card__b--tight">
      ${items.length ? html`<div class="feed">${items.map((i) => html`<div class="fr">
        <time datetime="${i.at}">${f.stamp(i.at) === 'Today' ? f.time(i.at) : f.stamp(i.at)}</time>
        <span>${i.employee_id ? html`<a href="#/people/${i.employee_id}">${i.text}</a>` : i.text}${i.source === 'crm' ? html`<span class="src">CRM</span>` : ''}</span>
      </div>`)}</div>` : html`<p class="muted">Approvals, invites and changes will show here as they happen.</p>`}
    </div></div>`;
}

export async function render({ main, isCurrent }) {
  mount(main, skeleton());
  let d;
  const load = async () => {
    try {
      d = await api.get('/today');
    } catch (err) {
      d = null;
      mount(main, errorView(err, true));
      $('[data-retry]', main)?.addEventListener('click', load);
      setLive('off');
      return;
    }
    if (!isCurrent()) return;
    mount(main, html`
      <div class="ph"><div class="ph__t">
        <h1 class="greet">${d.greeting}, ${d.first}</h1>
        <p class="ph__s" id="clock">${clockLine(d.clock)}</p>
      </div></div>
      <div class="kpis">${d.kpis.map(kpi)}</div>
      <div class="grid2">
        <div class="stack">
          <div class="card">
            <div class="card__h"><h2>Needs you</h2><span class="sub">${d.needs.length ? `${f.plural(d.needs.length, 'thing')} · most urgent first` : ''}</span></div>
            ${d.needs.length ? html`<div>${d.needs.map(needRow)}</div>`
              : html`<div class="allclear"><div class="dot d-ok">${icon('check')}</div><div><b>All clear</b><div class="muted">Nothing is waiting for a decision.</div></div></div>`}
          </div>
          ${feedCard(d.feed)}
        </div>
        <div class="stack">
          <div id="floorslot">${floorCard(d.floor)}</div>
          ${complianceCard(d.compliance)}
          ${momentsCard(d.moments)}
        </div>
      </div>`);
    setLive('ok', d.clock.ist);
  };
  await load();

  // Live: tonight's floor every 15 seconds. Background calls don't keep an idle session alive.
  const timer = setInterval(async () => {
    if (!isCurrent() || document.hidden || !d) return;
    try {
      const r = await api.get('/floor', { background: true });
      const slot = $('#floorslot', main);
      if (slot) mount(slot, floorCard(r.floor));
      const c = $('#clock', main);
      if (c) mount(c, clockLine(r.clock));
      setLive('ok', r.clock.ist);
    } catch {
      setLive('off');
    }
  }, 15000);
  return () => clearInterval(timer);
}

// ---- #/floor --------------------------------------------------------------------

export async function floorPage({ main, isCurrent }) {
  mount(main, skeleton('list'));
  const draw = (d) => {
    const by = {};
    for (const p of d.people) (by[p.state] ||= []).push(p);
    mount(main, html`
      <a class="crumb" href="#/today">${icon('back', 'i--sm')}Today</a>
      <div class="ph"><div class="ph__t"><h1>Tonight on the floor</h1>
        <p class="ph__s">Night of ${f.dayLong(d.day)} · live from CRM clock-ins and Trio HR punches, refreshed every 15 seconds</p></div>
        <div class="ph__a"><a class="btn" href="${d.crm_url}/attendance/admin" target="_blank" rel="noopener">${icon('ext')}CRM live board</a></div></div>
      <div class="grid2 grid2--even">
        ${STATES.filter(([k]) => by[k]?.length).map(([k, label]) => html`<div class="card">
          <div class="card__h"><h2><span class="row"><i class="legend-dot c-${k}"></i>${label}</span></h2><span class="sub tnum">${by[k].length}</span></div>
          <div class="card__b card__b--tight">${by[k].map((p) => html`<div class="minirow">
            <span class="av av--sm">${p.initials}<i class="live s-${p.state}"></i></span>
            <div>${p.employee_id ? html`<a class="name" href="#/people/${p.employee_id}">${p.name}</a>` : html`<b>${p.name}</b>`}<small>${p.detail}</small></div>
            ${p.badge ? html`<span class="r pill pill--sm ${p.badge.tone}">${p.badge.text}</span>` : ''}
          </div>`)}</div></div>`)}
      </div>
      <p class="meta">Facts only: who clocked in and when. Nobody is marked late or absent here; days with no punch are decided at month end in Attendance.</p>`);
  };
  const load = async (background = false) => {
    try {
      const d = await api.get('/floor/full', { background });
      if (isCurrent()) { draw(d); setLive('ok', d.clock.ist); }
    } catch (err) {
      if (!background) mount(main, errorView(err));
      setLive('off');
    }
  };
  await load();
  const t = setInterval(() => {
    if (!isCurrent()) { clearInterval(t); return; }
    if (!document.hidden) load(true);
  }, 15000);
  return () => clearInterval(t);
}

// ---- #/compliance -------------------------------------------------------------------

export async function compliancePage({ main }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/compliance'); } catch (err) { mount(main, errorView(err)); return; }
  const row = (i) => html`<tr>
    <td><b>${i.title}</b>${i.note ? html`<small class="meta">${i.note}</small>` : ''}</td>
    <td class="nowrap">${f.day(i.due_on)}</td>
    <td>${i.status === 'done' ? html`<span class="pill p-ok">${icon('check', 'i--sm')}Done ${f.day(i.done_at)}</span>`
      : html`<span class="pill ${i.tone === 'bad' ? 'p-bad' : i.tone === 'warn' ? 'p-warn' : 'p-neutral'}">${i.days < 0 ? `${f.plural(-i.days, 'day')} late` : i.days === 0 ? 'Due today' : `In ${f.plural(i.days, 'day')}`}</span>`}</td>
    <td class="hide-sm meta">${i.source || ''}</td>
    <td class="num">${i.status === 'open' ? html`<button class="btn btn--sm" type="button" data-done="${i.id}" data-title="${i.title}">Mark as done</button>` : ''}</td>
  </tr>`;
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Compliance dates</h1>
      <p class="ph__s">Deadlines the law sets for Trio, kept as data with their source. Your CA confirms each one.</p></div></div>
    <div class="banner banner--info">${icon('info')}<div><b>These dates come from our research, not from the CA yet.</b>Each one is checked with Trio's CA before Trio HR runs payroll. The full compliance centre (filings, challans, registers) arrives in Phase 4.</div></div>
    <div class="card"><div class="tablewrap"><table class="rtable">
      <thead><tr><th>What</th><th>Due</th><th>Status</th><th class="hide-sm">Source</th><th></th></tr></thead>
      <tbody>${d.items.map(row)}</tbody></table></div></div>`);
  main.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-done]');
    if (!b) return;
    const ok = await confirmSheet({
      title: `Mark "${b.dataset.title}" as done?`,
      text: 'Only do this once it has actually been filed or paid. It is recorded with your name.',
      confirm: 'Yes, it is done',
      onConfirm: () => api.post(`/compliance/${b.dataset.done}/done`),
    });
    if (ok) { toast('Marked as done.'); go('#/compliance', { replace: true }); }
  });
}
