// Letters (admin): list with the appointment-letter catch-up, prepare a letter,
// and the letter page with the second-admin check, issue, print and cancel.

import { html, raw, mount, $, $$, icon, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { letterSheet } from '../core/letter.js';
import { go, state, refreshNav, setTitle } from '../app.js';

const PILL = { draft: 'p-neutral', pending_check: 'p-warn', issued: 'p-ok', acknowledged: 'p-ok', void: 'p-bad' };
const VIEWS = [['check', 'Waiting for a check'], ['draft', 'Drafts'], ['issued', 'Issued'], ['void', 'Cancelled'], ['all', 'All']];
const inView = (l, v) => v === 'all' || (v === 'check' ? l.status === 'pending_check' : v === 'issued' ? ['issued', 'acknowledged'].includes(l.status) : l.status === v);

export async function list({ main, query }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/letters'); } catch (err) { mount(main, errorView(err)); return; }
  const want = query.get('status') === 'pending_check' ? 'check' : query.get('view');
  const view = VIEWS.some(([k]) => k === want) ? want : (d.letters.some((l) => l.status === 'pending_check' && !l.mine) ? 'check' : 'all');
  const rows = d.letters.filter((l) => inView(l, view));
  const cu = d.catch_up;
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Letters</h1><p class="ph__s">On Trio's letterhead, numbered and archived.${d.needs_check ? ' A second admin checks each one before it is issued.' : ''}</p></div>
      <div class="ph__a"><a class="btn btn--pri" href="#/letters/new">${icon('plus')}New letter</a></div></div>
    ${cu.missing ? html`<div class="banner banner--gold">${icon('file')}<div><b>${f.plural(cu.missing, 'person', 'people')} still need a written appointment letter.</b>
      The Labour Codes now require one for everyone, including existing staff. ${cu.have} of ${cu.staff} have one${cu.in_progress ? `, ${cu.in_progress} in progress` : ''}.</div>
      <div class="acts"><button class="btn btn--sm btn--pri" type="button" id="catchup">Start letters for the ${cu.missing}</button></div></div>` : ''}
    <nav class="views" aria-label="Letters">${VIEWS.map(([k, l]) => html`<a href="#/letters?view=${k}" ${k === view ? raw('aria-current="true"') : ''}>${l} <em>${d.letters.filter((x) => inView(x, k)).length}</em></a>`)}</nav>
    ${rows.length ? html`<div class="card"><div class="tablewrap"><table class="rtable">
      <thead><tr><th>Letter</th><th>For</th><th>Status</th><th class="hide-sm">Prepared by</th><th class="hide-sm">Updated</th></tr></thead>
      <tbody>${rows.map((l) => html`<tr data-href="#/letters/${l.id}">
        <td><a href="#/letters/${l.id}"><b>${l.template}</b></a><small class="meta">${l.number || 'Not numbered yet'}</small></td>
        <td><div class="who"><span class="av av--sm">${l.person.initials}</span><div><b>${l.person.name}</b><small>${l.person.designation || ''}</small></div></div></td>
        <td><span class="pill pill--sm ${PILL[l.status]}">${l.status === 'pending_check' && !l.mine ? 'Needs your check' : l.status_label}</span></td>
        <td class="hide-sm">${l.prepared_by || ''}${l.mine ? html` <small class="meta">(you)</small>` : ''}</td>
        <td class="hide-sm nowrap">${f.stamp(l.updated_at)}</td></tr>`)}</tbody></table></div></div>`
      : html`<div class="card"><div class="empty"><div class="dot d-neutral">${icon('file')}</div><b>Nothing here</b><p>Letters you prepare, check and issue show up in these lists.</p></div></div>`}`);
  main.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a')) go(tr.dataset.href);
  });
  $('#catchup', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({
      title: `Start appointment letters for ${f.plural(cu.missing, 'person', 'people')}?`,
      text: "Each starts as a draft filled from the person's record. You add each salary, then send them for a second check.",
      confirm: 'Start the letters', onConfirm: () => api.post('/letters/catch-up'),
    });
    if (r) { toast(r.message); go('#/letters?view=draft', { replace: true }); }
  });
}

// ---- prepare ---------------------------------------------------------------------------

function fieldInputs(defs, values) {
  return defs.map((x) => html`<div class="field ${x.type === 'textarea' ? 'wide' : ''}">
    <label for="lf-${x.key}">${x.label}${x.required ? '' : html` <span class="opt">(optional)</span>`}</label>
    ${x.type === 'textarea' ? html`<textarea class="textarea" id="lf-${x.key}" name="${x.key}" rows="3">${values[x.key] || ''}</textarea>`
      : html`<input class="input" id="lf-${x.key}" name="${x.key}" type="${x.type === 'date' ? 'date' : 'text'}" ${x.type === 'money' || x.type === 'number' ? raw('inputmode="numeric"') : ''} value="${values[x.key] || ''}">`}
    <div class="err" role="alert"></div></div>`);
}

const readFields = (form) => Object.fromEntries([...form.querySelectorAll('[name]')].map((el) => [el.name, el.value.trim()]));
const fieldErrors = (fields) => fields ? Object.fromEntries(Object.entries(fields).map(([k, v]) => [k.replace(/^fields\./, ''), v])) : null;

export async function create({ main, query }) {
  mount(main, skeleton('list'));
  let people;
  let letters;
  try {
    [people, letters] = await Promise.all([api.get('/people?status=all'), api.get('/letters')]);
  } catch (err) { mount(main, errorView(err)); return; }
  const person = query.get('person') || '';
  const template = query.get('template') || '';
  mount(main, html`
    <a class="crumb" href="#/letters">${icon('back', 'i--sm')}Letters</a>
    <div class="ph"><div class="ph__t"><h1>New letter</h1><p class="ph__s">Filled from the person's record. It starts as a draft.</p></div></div>
    <div class="card"><div class="card__b form">
      <div class="form__grid">
        <div class="field"><label for="n-person">For</label><select class="select" id="n-person"><option value="">Choose a person…</option>
          ${people.people.map((p) => html`<option value="${p.id}" ${String(p.id) === person ? 'selected' : ''}>${p.name} · ${p.designation || ''}${p.status === 'left' ? ' (left)' : ''}</option>`)}</select></div>
        <div class="field"><label for="n-tpl">Kind of letter</label><select class="select" id="n-tpl"><option value="">Choose…</option>
          ${letters.templates.map((t) => html`<option value="${t.code}" ${t.code === template ? 'selected' : ''}>${t.name}</option>`)}</select></div>
      </div>
      <div class="form" id="nform"></div>
    </div></div>`);
  let drawing = 0;
  const draw = async () => {
    const pid = $('#n-person', main).value;
    const code = $('#n-tpl', main).value;
    const slot = $('#nform', main);
    const mine = ++drawing;
    if (!pid || !code) { mount(slot, ''); return; }
    mount(slot, skeleton('list'));
    let d;
    try { d = await api.get(`/letters/form?person=${pid}&template=${code}`); } catch (err) { if (mine === drawing) mount(slot, errorView(err)); return; }
    if (mine !== drawing) return; // a newer choice is loading
    mount(slot, html`
      ${d.template.fields.length ? html`<form class="form" id="nf" novalidate><div class="form__grid">${fieldInputs(d.template.fields, d.values)}</div></form>`
        : html`<p class="muted">Nothing to add: everything comes from ${d.person.name}'s record.</p>`}
      <div class="row"><span class="spacer"></span><button class="btn btn--pri" type="button" id="nsave">Save the draft</button></div>`);
    $('#nsave', slot).addEventListener('click', (e) => busy(e.currentTarget, async () => {
      const form = $('#nf', slot);
      try {
        const r = await api.post('/letters', { employee_id: Number(pid), template_code: code, fields: form ? readFields(form) : {} });
        toast('Draft saved.');
        go(`#/letters/${r.id}`, { replace: true });
      } catch (err) { if (!form || !showFieldErrors(form, fieldErrors(err.fields))) toast(err.message, { tone: 'bad' }); }
    }));
  };
  $('#n-person', main).addEventListener('change', draw);
  $('#n-tpl', main).addEventListener('change', draw);
  draw();
}

// ---- one letter ----------------------------------------------------------------------------

export async function detail({ main, params }) {
  const id = params[0];
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/letters/${id}`); } catch (err) { mount(main, errorView(err)); return; }
  const l = d.letter;
  setTitle(`${l.template} · ${d.person.name}`);
  const mine = l.prepared_by_id === state.session.account.id;
  const editable = ['draft', 'pending_check'].includes(l.status);
  const printHref = `print.html?letter=${l.id}`;

  let actions;
  if (l.status === 'draft') {
    actions = html`
      ${l.check_note ? html`<div class="banner banner--warn">${icon('back')}<div><b>Sent back by ${l.checked_by || 'the checker'}</b>${l.check_note}</div></div>` : ''}
      ${l.missing.length ? html`<div class="banner banner--warn">${icon('alert')}<div><b>Still needed</b>${l.missing.join(', ')}.</div></div>` : ''}
      <button class="btn btn--pri btn--block" type="button" id="submit" ${l.missing.length ? raw('disabled') : ''}>${icon('send')}${l.needs_check ? 'Send for a second check' : 'Issue this letter'}</button>
      <button class="btn btn--quiet btn--block" type="button" id="void">Discard this draft</button>`;
  } else if (l.status === 'pending_check') {
    actions = mine
      ? html`<div class="banner banner--info">${icon('clock')}<div><b>Waiting for another admin</b>You prepared it, so someone else has to check and issue it. Editing it brings it back to a draft.</div></div>`
      : html`<div class="banner banner--gold">${icon('check')}<div><b>Check this letter</b>Read the letter. If it's right, issue it: it gets a number and ${d.person.name} is told. If you change any details yourself, you become its preparer and another admin has to check it.</div></div>
        <button class="btn btn--pri btn--block" type="button" id="approve">${icon('check')}Approve and issue</button>
        <button class="btn btn--block" type="button" id="return">${icon('back')}Send back with a note</button>`;
  } else if (l.status === 'void') {
    const why = (l.void_reason || '').replace(/[.\s]+$/, '');
    actions = html`<div class="banner banner--bad">${icon('alert')}<div><b>${l.number ? 'Cancelled' : 'Draft discarded'}</b>${why ? `Reason: ${why}. ` : ''}${l.number ? 'Anyone checking its code is told it was cancelled.' : 'It was never issued.'}</div></div>`;
  } else {
    actions = html`
      <a class="btn btn--pri btn--block" href="${printHref}" target="_blank" rel="noopener">${icon('down')}Print or save as PDF</a>
      <div class="kvlist">
        <div><span>Number</span><b>${l.number}</b></div>
        <div><span>Issued</span><b>${f.day(l.issued_at)}</b></div>
        <div><span>Checked by</span><b>${l.checked_by || '—'}</b></div>
        <div><span>Verification code</span><b class="tnum">${l.verify_code}</b></div>
        ${l.needs_ack ? html`<div><span>Acknowledged</span><b>${l.ack_at ? `${f.day(l.ack_at)} by ${l.ack_name}` : 'Not yet'}</b></div>` : ''}
      </div>
      <button class="btn btn--quiet btn--block" type="button" id="void">Cancel this letter…</button>`;
  }

  mount(main, html`
    <a class="crumb" href="#/letters">${icon('back', 'i--sm')}Letters</a>
    <div class="ph"><div class="ph__t"><h1>${l.template} <span class="pill ${PILL[l.status]}">${l.status === 'pending_check' && !mine ? 'Needs your check' : l.status_label}</span></h1>
      <p class="ph__s">For <a href="#/people/${d.person.id}">${d.person.name}</a> · prepared by ${l.prepared_by || 'an admin'}${l.prepared_at ? ` on ${f.day(l.prepared_at)}` : ''}</p></div></div>
    <div class="letterwrap">
      <div class="ltrbox">${letterSheet(d, { qr: true })}</div>
      <aside class="stack lettside">
        <div class="card"><div class="card__b stack">${actions}</div></div>
        ${editable && l.field_defs.length ? html`<form class="card" id="lf" novalidate><div class="card__h"><h2>Details</h2></div><div class="card__b form">
          ${fieldInputs(l.field_defs, l.fields)}
          <button class="btn" type="submit">Save and update the letter</button></div></form>` : ''}
      </aside>
    </div>`);

  $('#lf', main)?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    busy($('[type="submit"]', form), async () => {
      try { const r = await api.patch(`/letters/${id}`, { fields: readFields(form) }); toast(r.missing.length ? `Saved. Still needed: ${r.missing.join(', ')}.` : 'Saved.'); go(`#/letters/${id}`, { replace: true }); }
      catch (err) { if (!showFieldErrors(form, fieldErrors(err.fields))) toast(err.message, { tone: 'bad' }); }
    });
  });
  const submit = async () => {
    try { toast((await api.post(`/letters/${id}/submit`)).message); refreshNav(); go(`#/letters/${id}`, { replace: true }); } catch (err) { toast(err.message, { tone: 'bad' }); }
  };
  $('#submit', main)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    if (l.needs_check) { await submit(); return; }
    // With the second check switched off, this issues the letter at once: say so first.
    const r = await confirmSheet({
      title: `Issue this ${f.lower(l.template)} to ${d.person.name}?`,
      text: `The second check is switched off, so it is issued now: it gets the next letter number and a verification code, and ${d.person.name} is told. Issued letters can't be changed, only cancelled.`,
      confirm: 'Yes, issue it', onConfirm: () => api.post(`/letters/${id}/submit`),
    });
    if (r) { toast(r.message); refreshNav(); go(`#/letters/${id}`, { replace: true }); }
  }));
  $('#approve', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({
      title: `Issue this ${f.lower(l.template)} to ${d.person.name}?`,
      text: `It gets the next letter number and a verification code, and ${d.person.name} is told in Trio HR and the CRM. Issued letters can't be changed, only cancelled.`,
      confirm: 'Yes, issue it', onConfirm: () => api.post(`/letters/${id}/approve`),
    });
    if (r) { toast(r.message); refreshNav(); go(`#/letters/${id}`, { replace: true }); }
  });
  $('#return', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({
      title: 'Send it back?', text: 'Say what needs changing. The admin who prepared it is told.',
      input: { kind: 'textarea', label: 'What needs changing', required: true, minLength: 3, requiredText: 'Write a short note.' },
      confirm: 'Send back', onConfirm: (note) => api.post(`/letters/${id}/return`, { note }),
    });
    if (r) { toast(r.message); refreshNav(); go(`#/letters/${id}`, { replace: true }); }
  });
  $('#void', main)?.addEventListener('click', async () => {
    const issued = ['issued', 'acknowledged'].includes(l.status);
    const r = await confirmSheet({
      title: issued ? `Cancel ${l.number}?` : 'Discard this draft?',
      text: issued ? 'The letter stays in the archive, marked cancelled. Anyone who checks its code is told it is no longer valid.' : 'It is removed from the drafts.',
      input: { kind: 'text', label: 'Why', required: true, minLength: 3, requiredText: 'Give a short reason.' },
      confirm: issued ? 'Cancel the letter' : 'Discard', tone: 'danger',
      onConfirm: (reason) => api.post(`/letters/${id}/void`, { reason }),
    });
    if (r) { toast(r.message); refreshNav(); go(issued ? `#/letters/${id}` : '#/letters', { replace: true }); }
  });
}
