// Approvals: one inbox for every staff request.

import { html, raw, mount, $, $$, icon } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go, refreshNav } from '../app.js';

const TONE = { ok: 'd-ok', warn: 'd-warn', bad: 'd-bad', info: 'd-info' };
const TONE_ICON = { ok: 'check', warn: 'alert', bad: 'alert', info: 'info' };
const STATUS_PILL = { approved: 'p-ok', partly_approved: 'p-ok', sent_back: 'p-bad', withdrawn: 'p-neutral', pending: 'p-warn' };

function hashFor(view, id) {
  const q = new URLSearchParams();
  if (view && view !== 'all') q.set('view', view);
  if (id) q.set('id', id);
  return '#/approvals' + (q.toString() ? '?' + q : '');
}

export async function render({ main, query, isCurrent }) {
  const view = query.get('view') || 'all';
  const wantId = Number(query.get('id')) || null;
  mount(main, skeleton('list'));
  let list;
  try { list = await api.get('/approvals?view=' + encodeURIComponent(view)); } catch (err) { mount(main, errorView(err)); return; }
  if (!isCurrent()) return;

  const wide = window.matchMedia('(min-width: 1181px)').matches;
  let selected = wantId || (wide && list.items[0] ? list.items[0].id : null);
  const routineIds = list.items.filter((i) => i.routine).map((i) => i.id);

  mount(main, html`
    <div class="ph">
      <div class="ph__t"><h1>Approvals</h1><p class="ph__s">Nothing reaches anyone's record or pay until it's approved here.</p></div>
      <div class="ph__a">${routineIds.length ? html`<button class="btn" type="button" id="bulk">${icon('check')}Approve routine ones (${routineIds.length})</button>` : ''}</div>
    </div>
    <nav class="views" aria-label="Kinds of request">${list.views.map((v) => html`<a href="${hashFor(v.key)}" ${v.key === list.view ? raw('aria-current="true"') : ''}>${v.label} <em>${v.count}</em></a>`)}</nav>
    ${list.items.length || wantId ? html`
    <div class="split ${selected ? 'has-detail' : ''}">
      <div class="reqlist-wrap">
        <div class="card reqlist" aria-label="Requests, oldest first">
          ${list.items.length ? list.items.map((i) => html`
          <a class="req ${i.id === selected ? 'is-sel' : ''}" href="${hashFor(list.view, i.id)}" data-id="${i.id}" ${i.id === selected ? raw('aria-current="true"') : ''}>
            <span class="av">${i.person.initials}</span>
            <span class="req__t"><b>${i.person.name}</b><span class="s">${i.summary}</span>
              ${i.flag ? html`<span class="pill pill--sm p-warn">${i.flag}</span>` : i.routine ? html`<span class="pill pill--sm p-ok">Routine</span>` : ''}</span>
            <span class="when ${i.tone ? 't-' + i.tone : ''}">${f.stamp(i.created_at)}<br>${f.stamp(i.created_at) === 'Today' ? f.time(i.created_at) : i.age_days >= 2 ? f.plural(i.age_days, 'day') : ''}</span>
          </a>`) : html`<div class="empty"><b>Nothing else waiting</b></div>`}
        </div>
        <p class="meta">Oldest first.<span class="kbdhint"> Keys: J and K move, A approves.</span></p>
      </div>
      <div class="detail-wrap" id="detail">${selected ? skeleton('list') : ''}</div>
    </div>`
    : html`<div class="card"><div class="empty"><div class="dot d-ok">${icon('check')}</div><b>All clear</b><p>${list.view === 'all' ? 'No requests are waiting.' : `Nothing is waiting in ${list.views.find((v) => v.key === list.view).label}.`} New ones appear here the moment staff send them.</p></div></div>`}`);

  // Selecting a request swaps the detail in place (no full reload, list keeps its scroll).
  let shown = 0;
  const select = (id) => {
    if (!id) return;
    selected = id;
    // Side by side, the list is always there; on one column, Back should return to it.
    if (wide) history.replaceState(null, '', hashFor(list.view, id));
    else history.pushState(null, '', hashFor(list.view, id));
    $$('.req', main).forEach((x) => {
      const on = Number(x.dataset.id) === id;
      x.classList.toggle('is-sel', on);
      if (on) x.setAttribute('aria-current', 'true'); else x.removeAttribute('aria-current');
    });
    $('.split', main)?.classList.add('has-detail');
    $(`.req[data-id="${id}"]`, main)?.scrollIntoView({ block: 'nearest' });
    const mine = ++shown;
    detail($('#detail', main), id, list, view, () => mine === shown && isCurrent());
  };
  main.addEventListener('click', (e) => {
    const a = e.target.closest('.req[data-id]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    select(Number(a.dataset.id));
    if (!wide) window.scrollTo(0, 0);
  });

  if (selected) { const mine = ++shown; await detail($('#detail', main), selected, list, view, () => mine === shown && isCurrent()); }

  $('#bulk', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({
      title: `Approve ${f.plural(routineIds.length, 'routine request')}?`,
      text: 'Routine means every check passed: within balance, enough notice, enough people on the floor, or contact details only. Everyone is told by email.',
      confirm: `Approve ${routineIds.length}`,
      onConfirm: () => api.post('/approvals/bulk', { ids: routineIds }),
    });
    if (r) { toast(r.message); refreshNav(); go(hashFor(view), { replace: true }); }
  });

  // Keyboard: J/K move through the list, A approves the open one.
  const keys = (e) => {
    if (!isCurrent() || e.target.closest('input, textarea, select, .sheet, .drawer') || e.ctrlKey || e.metaKey || e.altKey) return;
    const k = e.key.toLowerCase();
    const ids = list.items.map((i) => i.id);
    const at = ids.indexOf(selected);
    if (k === 'j' && at < ids.length - 1) select(ids[at + 1]);
    else if (k === 'k' && at > 0) select(ids[at - 1]);
    else if (k === 'a') $('[data-act="approve"]', main)?.click();
  };
  document.addEventListener('keydown', keys);
  return () => document.removeEventListener('keydown', keys);
}

function detail(slot, id, list, view, latest = () => true) {
  const next = () => {
    const ids = list.items.map((i) => i.id).filter((x) => x !== id);
    const at = list.items.findIndex((i) => i.id === id);
    return ids[Math.min(at, ids.length - 1)] || null;
  };
  return requestPanel(slot, id, { back: hashFor(view), latest, onDone: () => go(hashFor(view, next()), { replace: true }) });
}

/**
 * One request with its checks and the Approve / Send back buttons. Used by
 * Approvals and inside a Trio Mail conversation. `onDone` runs after a decision.
 */
export async function requestPanel(slot, id, { back = null, latest = () => true, onDone = null, compact = false } = {}) {
  let d;
  try { d = await api.get(`/approvals/${id}`); } catch (err) { if (latest()) mount(slot, errorView(err)); return; }
  if (!latest()) return; // a newer selection is on its way
  const r = d.request;
  const p = d.person;
  const pending = r.status === 'pending';
  const nights = d.days.map((x) => x.day);

  mount(slot, html`
    ${back ? html`<a class="crumb backlink" href="${back}">${icon('back', 'i--sm')}All requests</a>` : ''}
    <div class="card ${compact ? 'card--req' : ''}">
      ${compact ? html`<div class="card__h card__h--req"><span class="label">Filed with this message</span><a href="#/approvals?id=${r.id}">Open in Approvals</a></div>` : ''}
      <div class="card__h" ${compact ? raw('hidden') : ''}>
        <div class="row"><span class="av av--lg">${p.initials}</span>
          <div><h2 class="h-person">${p.name}</h2><span class="sub">${[p.designation, p.centre_name, p.team_leader ? 'Team leader ' + p.team_leader : null, p.tenure ? 'with Trio ' + p.tenure : null].filter(Boolean).join(' · ')}</span></div></div>
        <a href="#/people/${p.id}">Open record</a>
      </div>
      <div class="card__b detail">
        ${!pending ? html`<div class="banner ${r.status === 'sent_back' ? 'banner--bad' : r.status === 'withdrawn' ? 'banner--info' : 'banner--ok'}">${icon(r.status === 'sent_back' ? 'back' : 'check')}<div>
          <b>${r.status_label}${r.decided_by ? ` by ${r.decided_by}` : ''}${r.decided_at ? ` on ${f.day(r.decided_at)}, ${f.time(r.decided_at)}` : ''}</b>${r.decision_note ? r.decision_note : ''}</div></div>` : ''}
        <div>
          <div class="label">${r.type_label} request</div>
          <div class="headline">${d.headline}</div>
          ${d.document ? html`<p><a class="btn" href="api/documents/${d.document.id}/file" target="_blank" rel="noopener">${icon(d.document.mime === 'application/pdf' ? 'file' : 'image')}Open the file</a> <span class="meta">${d.document.name} · opens in a new tab; opening it is recorded</span></p>` : ''}
          ${r.note ? html`<p class="quote">"${r.note}"</p>` : ''}
          <p class="meta">Asked ${f.day(r.created_at)}, ${f.time(r.created_at)} · ${f.ago(r.created_at)}</p>
        </div>
        ${d.checks.length ? html`<div class="checks">${d.checks.map((c) => html`<div class="check">
          <div class="dot dot--sm ${TONE[c.tone] || 'd-neutral'}">${icon(TONE_ICON[c.tone] || 'info', 'i--sm')}</div>
          <div><b>${c.title}</b><span>${c.text}</span>
            ${c.coverage && d.days.some((x) => x.floor) ? html`<div class="covlist">${d.days.map((x) => html`<div class="cov">
              <span>${f.day(x.day)}</span>
              <div class="bar" title="${x.after} of ${x.floor} on the floor; minimum ${x.min}"><i class="${x.ok ? '' : 'is-low'}" data-w="${x.floor ? (x.after / x.floor) * 100 : 0}"></i>${x.floor ? html`<s data-pos="${Math.min(100, (x.min / x.floor) * 100)}"></s>` : ''}</div>
              <b class="tnum">${x.after} of ${x.floor}</b>
              ${x.others.length ? html`<small>Also off: ${x.others.join(', ')}</small>` : ''}
            </div>`)}</div>` : ''}
          </div></div>`)}</div>` : ''}
        ${d.diff.length ? html`<table class="diff"><thead><tr><th>Detail</th><th>Now</th><th>Asked for</th></tr></thead><tbody>
          ${d.diff.map((x) => html`<tr><td>${x.label}</td><td class="from">${x.from}</td><td class="to">${x.to}${x.changed_since ? html` <span class="pill pill--sm p-warn">Changed since they asked</span>` : ''}</td></tr>`)}
        </tbody></table>` : ''}
        ${d.facts.length ? html`<dl class="kv">${d.facts.map((x) => html`<div><dt>${x.label}</dt><dd>${x.value}</dd></div>`)}</dl>` : ''}
        ${pending && r.type === 'leave' && nights.length > 1 ? html`<div class="daypick" id="daypick" hidden>
          <div class="label">Tick the nights to approve</div>
          <div class="chips">${nights.map((n) => html`<button type="button" class="chip" aria-pressed="true" data-night="${n}">${icon('check', 'i--sm')}${f.day(n)}</button>`)}</div>
        </div>` : ''}
      </div>
      ${pending ? html`<div class="actbar">
        <button class="btn btn--pri" type="button" data-act="approve">${icon('check')}<span>${d.approve_label}</span></button>
        ${r.type === 'leave' && nights.length > 1 ? html`<button class="btn" type="button" id="some">Approve some nights…</button>` : ''}
        <button class="btn btn--quiet push" type="button" id="sendback">${icon('back')}Send back</button>
      </div>` : ''}
    </div>`);

  if (!pending) return;
  let picking = false;
  const chosen = () => $$('[data-night]', slot).filter((b) => b.getAttribute('aria-pressed') === 'true').map((b) => b.dataset.night);
  const approveBtn = $('[data-act="approve"]', slot);
  const relabel = () => {
    const n = chosen().length;
    $('span', approveBtn).textContent = picking ? (n ? `Approve ${f.plural(n, 'night')}` : 'Pick at least one night') : d.approve_label;
    approveBtn.disabled = picking && !n;
  };
  $('#some', slot)?.addEventListener('click', (e) => {
    picking = !picking;
    $('#daypick', slot).hidden = !picking;
    e.currentTarget.textContent = picking ? 'Approve all nights instead' : 'Approve some nights…';
    relabel();
  });
  $$('[data-night]', slot).forEach((b) => b.addEventListener('click', () => {
    b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
    relabel();
  }));

  approveBtn.addEventListener('click', async () => {
    const days = picking ? chosen() : null;
    const summary = [['Person', p.name], ['Request', r.summary]];
    if (days) summary.push(['Approving', days.map((x) => f.day(x)).join(', ')]);
    const leaveCheck = d.checks.find((c) => c.title === 'Within balance' || c.title === 'More than the balance');
    const res = await confirmSheet({
      title: confirmTitle(r, p, days, nights),
      text: `${p.first} will be told by email.${leaveCheck && !days ? ' ' + leaveCheck.text.split(' · ')[0] + '.' : ''}${d.checks.some((c) => c.tone === 'warn' || c.tone === 'bad') ? ' Some checks need a look, so make sure you are happy with them.' : ''}`,
      summary,
      confirm: 'Yes, approve',
      onConfirm: () => api.post(`/approvals/${id}/approve`, days ? { days } : {}),
    });
    if (res) { toast(res.message); refreshNav(); onDone?.(res); }
  });

  $('#sendback', slot).addEventListener('click', async () => {
    const res = await confirmSheet({
      title: `Send this back to ${p.first}?`,
      text: `${p.first} sees your reason on their phone and can ask again. Nothing changes on their record.`,
      input: { kind: 'textarea', label: 'Reason', required: true, minLength: 3, requiredText: 'Write a short reason, or pick one.' },
      chips: d.reasons,
      confirm: 'Send back', tone: 'danger',
      onConfirm: (reason) => api.post(`/approvals/${id}/send-back`, { reason }),
    });
    if (res) { toast(res.message); refreshNav(); onDone?.(res); }
  });
}

function confirmTitle(r, p, days, nights) {
  if (r.type === 'leave') {
    const n = days ? days.length : nights.length;
    return days && days.length < nights.length ? `Approve ${f.plural(n, 'night')} of ${p.first}'s leave?` : `Approve ${p.first}'s leave (${f.plural(n, 'night')})?`;
  }
  if (r.type === 'attendance_fix') return `Approve ${p.first}'s clock-out fix?`;
  if (r.type === 'bank_change') return `Approve ${p.first}'s new bank account?`;
  if (r.type === 'profile_change') return `Approve the change to ${p.first}'s details?`;
  return `Approve ${p.first}'s request?`;
}
