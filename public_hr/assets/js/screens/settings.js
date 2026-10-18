// Settings (company, centres, rules, law, admins, security, health, audit log)
// and "My account".

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, openDrawer, toast, busy, linkSheet } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go, state } from '../app.js';

const TABS = [['company', 'Company'], ['centres', 'Centres'], ['rules', 'Rules'], ['law', 'Law table'], ['admins', 'Admins'],
  ['security', 'Security'], ['health', 'Health'], ['audit', 'Audit log']];
const STATES = ['Punjab', 'Jharkhand', 'Haryana', 'Chandigarh', 'Delhi', 'Uttar Pradesh', 'West Bengal', 'Maharashtra', 'Karnataka', 'Other'];

const field = (name, label, value, o = {}) => html`<div class="field ${o.wide ? 'wide' : ''}">
  <label for="s-${o.idp || ''}${name}">${label}${o.opt ? html` <span class="opt">(optional)</span>` : ''}</label>
  ${o.textarea ? html`<textarea class="textarea" id="s-${o.idp || ''}${name}" name="${name}" rows="2">${value ?? ''}</textarea>`
    : o.options ? html`<select class="select" id="s-${o.idp || ''}${name}" name="${name}"><option value="">Choose…</option>${o.options.map((x) => html`<option ${x === value ? 'selected' : ''}>${x}</option>`)}</select>`
    : html`<input class="input" id="s-${o.idp || ''}${name}" name="${name}" type="${o.type || 'text'}" value="${value ?? ''}" ${o.min !== undefined ? html`min="${o.min}" max="${o.max}"` : ''} autocomplete="off">`}
  ${o.help ? html`<div class="help">${o.help}</div>` : ''}<div class="err" role="alert"></div></div>`;

async function saveForm(form, btn, method, path, after) {
  await busy(btn, async () => {
    try {
      const r = await api[method](path, formData(form));
      toast(r.message || 'Saved.');
      after?.(r);
    } catch (err) {
      if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' });
    }
  });
}

export async function render({ main, query }) {
  const tab = TABS.some(([k]) => k === query.get('tab')) ? query.get('tab') : 'company';
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/settings'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Settings</h1><p class="ph__s">Company details, rules and the law table Trio HR works from.</p></div></div>
    <nav class="tabs" aria-label="Settings sections">${TABS.map(([k, l]) => html`<a href="#/settings?tab=${k}" ${k === tab ? raw('aria-current="page"') : ''}>${l}</a>`)}</nav>
    <div class="stack" id="tab"></div>`);
  const slot = $('#tab', main);
  const tabs = { company, centres, rules, law, admins, security, health, audit };
  await tabs[tab](slot, d, query);
}

function company(slot, d) {
  const c = d.company;
  mount(slot, html`<div class="card"><div class="card__h"><h2>Company</h2><span class="sub">Shown on letters and payslips</span></div><div class="card__b">
    <form class="form" id="cf" novalidate><div class="form__grid">
      ${field('name', 'Short name', c.name)}
      ${field('legal_name', 'Registered name', c.legal_name, { help: 'Exactly as on the MCA register.' })}
      ${field('cin', 'CIN', c.cin, { opt: true })}
      ${field('tan', 'TAN', c.tan, { opt: true, help: 'For TDS returns. From the CA.' })}
      ${field('pan', 'Company PAN', c.pan, { opt: true })}
      ${field('phone', 'Phone', c.phone, { opt: true, type: 'tel' })}
      ${field('address', 'Registered office', c.address, { textarea: true, wide: true })}
      ${field('signatory_name', 'Authorised signatory', c.signatory_name, { help: 'Signs letters. Letters cannot be issued until this is filled in.' })}
      ${field('signatory_title', 'Signatory’s designation', c.signatory_title, { help: 'For example: Director.' })}
      ${field('hr_email', 'HR email', c.hr_email, { opt: true, type: 'email', help: 'Where staff can write to HR.' })}
    </div>
    <div class="row"><button class="btn btn--pri" type="submit">Save company details</button></div></form></div></div>`);
  const form = $('#cf', slot);
  form.addEventListener('submit', (e) => { e.preventDefault(); saveForm(form, $('[type="submit"]', form), 'patch', '/settings/company'); });
}

function centres(slot, d) {
  mount(slot, html`<div class="grid2 grid2--even">${Object.entries(d.centres).map(([code, c]) => html`
    <div class="card"><div class="card__h"><h2>${c.name}</h2><span class="pill p-neutral">${code}</span></div><div class="card__b">
      <form class="form" data-centre="${code}" novalidate><div class="form__grid">
        ${field('name', 'Name', c.name, { idp: `${code}-` })}
        ${field('city', 'City', c.city, { opt: true, idp: `${code}-` })}
        ${field('state', 'State', c.state, { options: STATES, help: 'Decides which state’s labour rules apply.', idp: `${code}-` })}
        ${field('shop_reg', 'Shop registration no.', c.shop_reg, { opt: true, idp: `${code}-` })}
        ${field('address', 'Address', c.address, { textarea: true, wide: true, opt: true, idp: `${code}-` })}
      </div><div class="row"><button class="btn" type="submit">Save ${c.name}</button></div></form>
    </div></div>`)}</div>`);
  $$('form[data-centre]', slot).forEach((form) => form.addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = $('[type="submit"]', form);
    busy(btn, async () => {
      try { toast((await api.patch('/settings/centres', { code: form.dataset.centre, ...formData(form) })).message); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  }));
}

function rules(slot, d) {
  mount(slot, html`<div class="card"><div class="card__h"><h2>Rules</h2><span class="sub">Numbers Trio HR uses in its checks</span></div><div class="card__b">
    <form class="form" id="rf" novalidate><div class="form__grid">
      ${d.rules.map((r) => field(r.key, r.label, r.value, { type: 'number', min: r.min, max: r.max, help: r.help }))}
    </div><div class="row"><button class="btn btn--pri" type="submit">Save rules</button></div></form></div></div>`);
  const form = $('#rf', slot);
  form.addEventListener('submit', (e) => { e.preventDefault(); saveForm(form, $('[type="submit"]', form), 'patch', '/settings/rules'); });
}

async function law(slot) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/law'); } catch (err) { mount(slot, errorView(err)); return; }
  const val = (x) => {
    if (!x) return '—';
    const u = x.unit || '';
    if (u.startsWith('₹')) return '₹' + f.num(Number(x.value)) + u.slice(1);
    return x.value + (!u ? '' : /^(%|(st|nd|rd|th)\b)/.test(u) ? u : ' ' + u); // "7th of …", "50% of …"
  };
  mount(slot, html`<div class="stack">
    <div class="banner banner--info">${icon('info')}<div><b>The law as data.</b>Every rate and limit Trio HR uses, with the date it applies from and its source. A change is added as a new row from its date; older values stay on file, so past months keep their rules. Trio's CA confirms these before payroll goes live.</div></div>
    <div class="row"><span class="spacer"></span><button class="btn btn--pri" type="button" id="addlaw">${icon('plus')}Record a new value</button></div>
    <div class="card"><div class="tablewrap"><table class="rtable lawtable">
      <thead><tr><th>Rule</th><th>Applies</th><th>Value today</th><th>Since</th><th class="hide-sm">Source</th></tr></thead>
      <tbody>${d.groups.map((g) => {
        // A rule with only a future value shows that value's start date instead of a blank.
        const up = g.current ? null : g.history.find((h) => h.status === 'upcoming');
        const others = g.history.filter((h) => h !== up);
        const note = (g.current || up)?.note;
        return html`<tr class="norow">
        <td><b>${g.label}</b>${note ? html`<small class="meta">${note}</small>` : ''}
          ${others.length ? html`<details class="lawhist"><summary>${f.plural(others.length, 'other value')}</summary>${others.map((h) => html`<div>${val(h)} · from ${f.day(h.from)} <span class="pill pill--sm ${h.status === 'upcoming' ? 'p-info' : 'p-neutral'}">${h.status === 'upcoming' ? 'Upcoming' : 'Earlier'}</span></div>`)}</details>` : ''}</td>
        <td>${g.scope}</td>
        <td class="strong nowrap">${g.current ? val(g.current) : html`<span class="pill pill--sm p-info">Not in force yet</span>`}</td>
        <td class="nowrap">${g.current ? f.day(g.current.from) : up ? `Starts ${f.day(up.from)}` : '—'}</td>
        <td class="hide-sm meta">${g.current?.source || g.history[0]?.source || ''}</td>
      </tr>`;
      })}</tbody></table></div></div></div>`);
  $('#addlaw', slot).addEventListener('click', () => {
    const layer = openDrawer({
      title: 'Record a new value',
      body: html`<form class="form" id="lf" novalidate>
        <p class="muted">Use this when a rate or limit changes, for example a new PF ceiling. The old value stays on file for earlier months.</p>
        <div class="field"><label for="l-key">Which rule</label><select class="select" id="l-key" name="key">
          ${d.groups.map((g) => html`<option value="${g.key}" data-label="${g.label}" data-scope="${g.scope}" data-unit="${g.current?.unit || ''}">${g.label} (${g.scope})</option>`)}
        </select><div class="err" role="alert"></div></div>
        <input type="hidden" name="label"><input type="hidden" name="scope"><input type="hidden" name="unit">
        <div class="form__grid">
          ${field('value', 'New value', '', { help: 'Numbers only for amounts, e.g. 25000.' })}
          ${field('effective_from', 'Applies from', f.todayIST(), { type: 'date' })}
          ${field('source', 'Source', '', { wide: true, help: 'The notification or circular, e.g. S.O. 5109(E).' })}
          ${field('note', 'Note', '', { wide: true, opt: true })}
        </div></form>`,
      foot: html`<button class="btn btn--quiet" type="button" data-close>Cancel</button><button class="btn btn--pri" type="button" id="lsave">Record it</button>`,
    });
    const form = $('#lf', layer.el);
    const sync = () => { const o = form.key.selectedOptions[0]; form.label.value = o.dataset.label; form.scope.value = o.dataset.scope; form.unit.value = o.dataset.unit; };
    form.key.addEventListener('change', sync);
    sync();
    $('[data-close]', layer.el).addEventListener('click', () => layer.close());
    $('#lsave', layer.el).addEventListener('click', (e) => busy(e.currentTarget, async () => {
      try { toast((await api.post('/law', formData(form))).message); layer.close(); go('#/settings?tab=law', { replace: true }); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    }));
  });
}

async function admins(slot, d) {
  mount(slot, skeleton('list'));
  let a;
  try { a = await api.get('/admins'); } catch (err) { mount(slot, errorView(err)); return; }
  mount(slot, html`
    ${!d.is_owner ? html`<div class="banner banner--info">${icon('info')}<div>Only an owner can add admins or change their access.</div></div>` : ''}
    <div class="card"><div class="card__h"><h2>Owners and admins</h2>${d.is_owner ? html`<button class="btn btn--sm btn--pri" type="button" id="addadmin">${icon('plus', 'i--sm')}Add an admin</button>` : ''}</div>
      <div>${a.admins.map((x) => html`<div class="listrow">
        <span class="av av--sm">${x.initials}</span>
        <div><b>${x.name}</b><small>${x.email} · ${x.status === 'invited' ? `invited ${f.ago(x.invited_at)}` : x.last_login ? `last signed in ${f.ago(x.last_login)}` : 'not signed in yet'}</small></div>
        <span class="r row">
          <span class="pill pill--sm ${x.role === 'owner' ? 'p-navy' : 'p-gold'}">${x.role === 'owner' ? 'Owner' : 'Admin'}</span>
          ${x.status !== 'active' ? html`<span class="pill pill--sm ${x.status === 'invited' ? 'p-info' : 'p-bad'}">${x.status === 'invited' ? 'Invited' : 'Switched off'}</span>` : ''}
          ${d.is_owner && x.id !== state.session?.account?.id ? html`<button class="btn btn--sm btn--icon" type="button" data-admin="${x.id}" aria-label="Change ${x.name}">${icon('more', 'i--sm')}</button>` : x.id === state.session?.account?.id ? html`<span class="meta">You</span>` : ''}
        </span></div>`)}</div></div>
    <p class="meta">Owners can do everything. Admins can do everything except manage admins and security. Nobody approves their own requests or checks their own letters.</p>`);
  $('#addadmin', slot)?.addEventListener('click', () => {
    const layer = openDrawer({
      title: 'Add an admin',
      body: html`<form class="form" id="af" novalidate>
        ${field('name', 'Name', '')}${field('email', 'Email', '', { type: 'email', help: state.emailOn ? 'Their invitation goes here. Use their own address, not a shared one.' : 'They sign in with this. Use their own address, not a shared one.' })}
        <div class="field"><span class="flabel">Access</span><div class="radio-cards">
          <label class="radio-card"><input type="radio" name="role" value="admin" checked><span><b>Admin</b><small>Runs HR day to day</small></span></label>
          <label class="radio-card"><input type="radio" name="role" value="owner"><span><b>Owner</b><small>Everything, including admins and security</small></span></label>
        </div></div></form>`,
      foot: html`<button class="btn btn--quiet" type="button" data-close>Cancel</button><button class="btn btn--pri" type="button" id="asave">${state.emailOn ? 'Send the invitation' : 'Add and get their link'}</button>`,
    });
    const form = $('#af', layer.el);
    $('[data-close]', layer.el).addEventListener('click', () => layer.close());
    $('#asave', layer.el).addEventListener('click', (e) => busy(e.currentTarget, async () => {
      try {
        const r = await api.post('/admins', formData(form));
        layer.close();
        if (r.link) await linkSheet(r.link); else toast(r.message);
        go('#/settings?tab=admins', { replace: true });
      }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    }));
  });
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-admin]');
    if (!b) return;
    const x = a.admins.find((y) => y.id === Number(b.dataset.admin));
    const { openMenu } = await import('../core/ui.js');
    const act = (body, msg) => async () => {
      const r = await confirmSheet({ title: msg, confirm: 'Yes', onConfirm: () => api.patch(`/admins/${x.id}`, body) });
      if (r) { toast(r.message); go('#/settings?tab=admins', { replace: true }); }
    };
    const items = [];
    const showLink = async (path) => { try { const r = await api.post(path); if (r.link) await linkSheet(r.link); else toast(r.message); } catch (err) { toast(err.message, { tone: 'bad' }); } };
    if (x.status === 'invited') items.push({ label: state.emailOn ? 'Send the invitation again' : 'Get their set-up link', icon: state.emailOn ? 'send' : 'link', onClick: () => showLink(`/admins/${x.id}/invite`) });
    if (x.status === 'active' && x.id !== state.session?.account?.id) items.push({ label: 'New sign-in link (forgotten password)', icon: 'link', onClick: () => showLink(`/admins/${x.id}/signin-link`) });
    if (x.role === 'admin') items.push({ label: 'Make an owner', icon: 'key', onClick: act({ role: 'owner' }, `Make ${x.name} an owner?`) });
    if (x.role === 'owner') items.push({ label: 'Make an admin', icon: 'user', onClick: act({ role: 'admin' }, `Make ${x.name} an admin instead of an owner?`) });
    if (x.status === 'active') items.push({ label: 'Switch off access', icon: 'lock', onClick: act({ status: 'disabled' }, `Switch off ${x.name}'s access? They are signed out at once.`) });
    if (x.status === 'disabled') items.push({ label: 'Switch access back on', icon: 'check', onClick: act({ status: 'active' }, `Give ${x.name} access again?`) });
    openMenu(b, items);
  });
}

function security(slot, d) {
  const s = d.security;
  const row = (key, title, text) => html`<label class="switchrow">
    <span><b>${title}</b><small>${text}</small></span>
    <input type="checkbox" class="switch" name="${key}" ${s[key] ? 'checked' : ''} ${d.is_owner ? '' : raw('disabled')}></label>`;
  mount(slot, html`
    <div class="banner banner--ok">${icon('shield')}<div><b>How sign-in works</b>Email and password. Looking at bank or ID details asks for the password again and is recorded. A sign-in from a new device sends that person an alert email. Admins are signed out after 30 minutes without activity.</div></div>
    <div class="card"><div class="card__h"><h2>Switches</h2>${!d.is_owner ? html`<span class="sub">Only an owner can change these</span>` : ''}</div><div class="card__b">
      <form id="secf" class="switches">
        ${row('letters_need_check', 'A second admin checks every letter before it is issued', 'Nobody can check a letter they prepared. Recommended.')}
        ${row('staff_email_code', 'Ask staff for an emailed code on a new phone', 'Off by default. When on, a 6-digit code is emailed the first time someone signs in on a phone; the phone is then remembered.')}
      </form></div></div>`);
  if (!d.is_owner) return;
  $('#secf', slot).addEventListener('change', async (e) => {
    const input = e.target;
    input.disabled = true;
    try { toast((await api.patch('/settings/security', { [input.name]: input.checked })).message); }
    catch (err) { input.checked = !input.checked; toast(err.message, { tone: 'bad' }); }
    finally { input.disabled = false; }
  });
}

async function health(slot) {
  mount(slot, skeleton('list'));
  let d;
  try { d = await api.get('/health'); } catch (err) { mount(slot, errorView(err)); return; }
  const o = d.outbox;
  mount(slot, html`
    <div class="card"><div class="card__h"><h2>System health</h2></div><div class="card__b card__b--tight">
      ${d.checks.map((c) => html`<div class="minirow"><span class="dot dot--sm ${c.ok ? 'd-ok' : 'd-warn'}">${icon(c.ok ? 'check' : 'alert', 'i--sm')}</span><div><b>${c.label}</b><small>${c.text}</small></div></div>`)}
    </div></div>
    <div class="card"><div class="card__h"><h2>Email outbox</h2><span class="sub">${o.counts.sent} sent · ${o.counts.queued} waiting · ${o.counts.failed} failed</span>
      ${o.counts.retryable ? html`<button class="btn btn--sm" type="button" id="retry">${icon('refresh', 'i--sm')}Try the failed ones again</button>` : ''}</div>
      <div class="tablewrap"><table class="rtable"><thead><tr><th>To</th><th>Subject</th><th>Status</th><th class="hide-sm">When</th></tr></thead><tbody>
      ${o.recent.map((m) => html`<tr class="norow"><td>${m.to_email}</td><td>${m.subject}</td>
        <td><span class="pill pill--sm ${m.status === 'sent' ? 'p-ok' : m.status === 'failed' ? 'p-bad' : 'p-warn'}">${m.status === 'sent' ? 'Sent' : m.status === 'failed' ? 'Failed' : 'Waiting'}</span>${m.last_error ? html`<small class="meta">${m.last_error}</small>` : ''}</td>
        <td class="hide-sm nowrap">${f.stamp(m.created_at)} ${f.time(m.created_at)}</td></tr>`)}
      </tbody></table></div></div>`);
  $('#retry', slot)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    try { toast((await api.post('/health/retry')).message); go('#/settings?tab=health', { replace: true }); }
    catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
}

async function audit(slot, d, query) {
  const kind = query.get('kind') || '';
  const page = Number(query.get('page') || 1);
  mount(slot, skeleton('list'));
  let r;
  try { r = await api.get(`/audit?page=${page}${kind ? '&kind=' + kind : ''}`); } catch (err) { mount(slot, errorView(err)); return; }
  const link = (k, p = 1) => `#/settings?tab=audit${k ? '&kind=' + k : ''}${p > 1 ? '&page=' + p : ''}`;
  mount(slot, html`
    <nav class="views" aria-label="Kinds of entry">${[['', 'Everything'], ['views', 'Looked at private details'], ['changes', 'Changes'], ['requests', 'Requests'], ['signins', 'Sign-ins']].map(([k, l]) =>
      html`<a href="${link(k)}" ${k === kind ? raw('aria-current="true"') : ''}>${l}</a>`)}</nav>
    <div class="card"><div class="tablewrap"><table class="rtable"><thead><tr><th>When</th><th>Who</th><th>What</th><th class="hide-sm">About</th></tr></thead><tbody>
      ${r.rows.length ? r.rows.map((x) => html`<tr class="norow ${x.sensitive ? 'is-sensitive' : ''}">
        <td class="nowrap">${f.stamp(x.at)}<small class="meta">${f.time(x.at)}</small></td>
        <td>${x.who}</td><td>${x.sensitive ? icon('eye', 'i--sm') : ''} ${x.what}</td>
        <td class="hide-sm">${x.employee_id ? html`<a href="#/people/${x.employee_id}">${x.about}</a>` : ''}</td></tr>`)
        : html`<tr class="norow"><td colspan="4" class="muted">Nothing recorded yet.</td></tr>`}
    </tbody></table></div></div>
    <div class="row"><span class="meta">${f.plural(r.total, 'entry', 'entries')}</span><span class="spacer"></span>
      ${page > 1 ? html`<a class="btn btn--sm" href="${link(kind, page - 1)}">Newer</a>` : ''}${page < r.pages ? html`<a class="btn btn--sm" href="${link(kind, page + 1)}">Older</a>` : ''}</div>`);
}

// ---- My account (#/account) ------------------------------------------------------------

export async function account({ main }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/account'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>My account</h1><p class="ph__s">${d.name} · ${d.email} · ${d.role === 'owner' ? 'Owner' : d.role === 'admin' ? 'Admin' : 'Staff'}</p></div></div>
    <div class="grid2">
      <div class="stack">
        <div class="card"><div class="card__h"><h2>Signed in on</h2><button class="btn btn--sm" type="button" id="others">Sign out everywhere else</button></div>
          <div>${d.devices.map((x) => html`<div class="listrow">${icon(/computer|Mac|Chromebook/.test(x.device) ? 'laptop' : 'device')}<div><b>${x.device}</b><small>${x.current ? 'This device · ' : ''}since ${f.day(x.since)} · last used ${f.ago(x.seen)}</small></div>
            <span class="r">${x.current ? html`<span class="pill pill--sm p-ok">This one</span>` : html`<button class="btn btn--sm" type="button" data-off="${x.id}">Sign out</button>`}</span></div>`)}</div></div>
        <div class="card"><div class="card__h"><h2>Remembered devices</h2><span class="sub">No new-device alert from these</span></div>
          <div>${d.remembered.length ? d.remembered.map((x) => html`<div class="listrow">${icon(/computer|Mac|Chromebook/.test(x.device) ? 'laptop' : 'device')}<div><b>${x.device}</b><small>remembered ${f.day(x.since)} · last used ${f.ago(x.used)}</small></div>
            <span class="r"><button class="btn btn--sm btn--quiet" type="button" data-forget="${x.id}">Forget</button></span></div>`) : html`<div class="listrow muted">None yet.</div>`}</div></div>
      </div>
      <form class="card" id="pw" novalidate><div class="card__h"><h2>Change password</h2></div><div class="card__b form">
        <div class="field"><label for="a-cur">Current password</label><input class="input" type="password" id="a-cur" name="current" autocomplete="current-password"><div class="err" role="alert"></div></div>
        <div class="field"><label for="a-new">New password</label><input class="input" type="password" id="a-new" name="password" autocomplete="new-password"><div class="help">At least 10 characters. Other devices are signed out.</div><div class="err" role="alert"></div></div>
        <div class="row"><button class="btn btn--pri" type="submit">Change password</button></div>
        ${d.password_changed_at ? html`<p class="meta">Last changed ${f.day(d.password_changed_at)}.</p>` : ''}
      </div></form>
    </div>`);
  main.addEventListener('click', async (e) => {
    const off = e.target.closest('[data-off]');
    const forget = e.target.closest('[data-forget]');
    if (off) {
      const r = await confirmSheet({ title: 'Sign out that device?', confirm: 'Sign it out', onConfirm: () => api.del(`/account/devices/${off.dataset.off}`) });
      if (r) { toast(r.message); go('#/account', { replace: true }); }
    } else if (forget) {
      const r = await confirmSheet({ title: 'Forget that device?', text: 'Signing in from it will send a new-device alert again.', confirm: 'Forget it', onConfirm: () => api.del(`/account/remembered/${forget.dataset.forget}`) });
      if (r) { toast(r.message); go('#/account', { replace: true }); }
    } else if (e.target.closest('#others')) {
      const r = await confirmSheet({ title: 'Sign out of every other device?', confirm: 'Sign them out', onConfirm: () => api.post('/account/signout-others') });
      if (r) { toast(r.message); go('#/account', { replace: true }); }
    }
  });
  const form = $('#pw', main);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('[type="submit"]', form), async () => {
      try { toast((await api.post('/account/password', { current: form.current.value, password: form.password.value })).message); form.reset(); }
      catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}
