// Policies and consents (admin): list, write a draft, publish, see who signed.

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, confirmSheet, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go } from '../app.js';

const STATE_PILL = { signed: ['p-ok', 'Signed'], missing: ['p-warn', 'Not yet'], withdrawn: ['p-bad', 'Withdrew'] };

export async function list({ main }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/policies'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <div class="ph"><div class="ph__t"><h1>Policies</h1><p class="ph__s">Staff read and sign each version on their phone. A new version asks everyone to sign again.</p></div>
      <div class="ph__a"><a class="btn btn--pri" href="#/policies/new">${icon('plus')}New policy</a></div></div>
    <div class="card"><div>${d.policies.map((p) => {
      const pct = p.of ? Math.round((p.signed / p.of) * 100) : 0;
      return html`<a class="listrow polrow" href="#/policies/${p.live ? p.live.id : p.id}">
        <span class="dot dot--sm ${p.kind === 'consent' ? 'd-info' : 'd-gold'}">${icon(p.kind === 'consent' ? 'check' : 'file', 'i--sm')}</span>
        <div class="polrow__t"><b>${p.title}</b><small>${p.audience}${p.live ? ` · version ${p.live.version}, published ${f.day(p.live.published_at)}` : ' · not published yet'}${p.draft && p.live ? ` · draft of version ${p.draft.version}` : ''}</small></div>
        <span class="polrow__m">${p.live ? html`<span class="meter meter--sm"><i data-w="${pct}"></i></span><small class="tnum">${p.signed} of ${p.of} signed${p.withdrawn ? ` · ${p.withdrawn} withdrew` : ''}</small>`
          : html`<span class="pill pill--sm p-neutral">Draft</span>`}</span>
        ${icon('chev', 'chev')}</a>`; })}</div></div>
    <p class="meta">The starting texts are drafts written for Trio. Have them checked by Trio's lawyer or CA before relying on them.</p>`);
}

function editor(p, audiences) {
  return html`<form class="form" id="pf" novalidate>
    <div class="form__grid">
      <div class="field wide"><label for="p-title">Title</label><input class="input" id="p-title" name="title" value="${p.title || ''}"><div class="err" role="alert"></div></div>
      <div class="field wide"><label for="p-summary">One-line summary <span class="opt">(optional)</span></label><input class="input" id="p-summary" name="summary" value="${p.summary || ''}"><div class="help">Shown in the list staff see.</div><div class="err" role="alert"></div></div>
      <div class="field"><label for="p-aud">Who signs it</label><select class="select" id="p-aud" name="audience">${Object.entries(audiences).map(([k, l]) => html`<option value="${k}" ${k === (p.audience || 'all') ? 'selected' : ''}>${l}</option>`)}</select></div>
      <div class="field"><label for="p-kind">Kind</label><select class="select" id="p-kind" name="kind">
        <option value="policy" ${p.kind !== 'consent' ? 'selected' : ''}>Policy (sign once per version)</option>
        <option value="consent" ${p.kind === 'consent' ? 'selected' : ''}>Consent (staff can withdraw it)</option></select></div>
    </div>
    <div class="editor">
      <div class="field"><label for="p-body">Text</label>
        <textarea class="textarea textarea--code" id="p-body" name="body" rows="18">${p.body || ''}</textarea>
        <div class="help">Formatting: start a line with <b># </b>for a heading, <b>- </b>for a list item, and wrap words in <b>**</b> to make them bold. Leave a blank line between paragraphs.</div>
        <div class="err" role="alert"></div></div>
      <div class="field field--preview"><span class="flabel">How staff will see it</span><div class="doc doc--preview" id="p-prev"></div></div>
    </div></form>`;
}

/** The same light formatting the server applies, for the live preview only. */
function previewHtml(text) {
  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inline = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  const out = [];
  let para = [];
  let list = [];
  const fp = () => { if (para.length) { out.push('<p>' + para.map(inline).join('<br>') + '</p>'); para = []; } };
  const fl = () => { if (list.length) { out.push('<ul>' + list.map((l) => '<li>' + inline(l) + '</li>').join('') + '</ul>'); list = []; } };
  for (const line of text.trim().split(/\r?\n/)) {
    const t = line.trimEnd();
    if (!t.trim()) { fp(); fl(); }
    else if (t.trimStart().startsWith('# ')) { fp(); fl(); out.push('<h3>' + inline(t.trimStart().slice(2)) + '</h3>'); }
    else if (t.trimStart().startsWith('- ')) { fp(); list.push(t.trimStart().slice(2)); }
    else { fl(); para.push(t); }
  }
  fp(); fl();
  return out.join('');
}

function wireEditor(main) {
  const body = $('#p-body', main);
  const prev = $('#p-prev', main);
  const draw = () => { prev.innerHTML = previewHtml(body.value); }; // escaped above: only our own tags
  body.addEventListener('input', draw);
  draw();
}

export async function create({ main }) {
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/policies'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <a class="crumb" href="#/policies">${icon('back', 'i--sm')}Policies</a>
    <div class="ph"><div class="ph__t"><h1>New policy</h1><p class="ph__s">Saved as a draft. Nobody sees it until you publish.</p></div></div>
    <div class="card"><div class="card__b">${editor({}, d.audiences)}</div>
      <div class="actbar"><span class="push"></span><a class="btn btn--quiet" href="#/policies">Cancel</a><button class="btn btn--pri" type="button" id="save">Save draft</button></div></div>`);
  wireEditor(main);
  $('#save', main).addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const form = $('#pf', main);
    try { const r = await api.post('/policies', formData(form)); toast(r.message); go(`#/policies/${r.id}`, { replace: true }); }
    catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
  }));
}

export async function detail({ main, params, query }) {
  const id = params[0];
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/policies/${id}`); } catch (err) { mount(main, errorView(err)); return; }
  const p = d.policy;
  const show = query.get('show') || 'missing';
  const people = d.people.filter((x) => show === 'all' || x.state === show);
  const counts = { signed: d.people.filter((x) => x.state === 'signed').length, missing: d.people.filter((x) => x.state === 'missing').length, withdrawn: d.people.filter((x) => x.state === 'withdrawn').length };
  const versions = d.versions.length > 1 ? html`<div class="views">${d.versions.map((v) => html`<a href="#/policies/${v.id}" ${String(v.id) === String(id) ? raw('aria-current="true"') : ''}>v${v.version} <em>${v.status === 'published' ? 'live' : v.status}</em></a>`)}</div>` : '';

  if (p.status === 'draft') {
    mount(main, html`
      <a class="crumb" href="#/policies">${icon('back', 'i--sm')}Policies</a>
      <div class="ph"><div class="ph__t"><h1>${p.title} <span class="pill p-neutral">Draft · version ${p.version}</span></h1>
        <p class="ph__s">Last saved ${f.ago(p.updated_at)}. Staff can't see it until you publish.</p></div></div>
      ${versions}
      <div class="card"><div class="card__b">${editor(p, d.audiences)}</div>
        <div class="actbar"><button class="btn" type="button" id="save">Save draft</button><span class="push"></span>
          <button class="btn btn--pri" type="button" id="publish">${icon('send')}Publish and ask staff to sign</button></div></div>`);
    wireEditor(main);
    const form = $('#pf', main);
    const save = async () => { await api.patch(`/policies/${id}`, formData(form)); };
    $('#save', main).addEventListener('click', (e) => busy(e.currentTarget, async () => {
      try { await save(); toast('Draft saved.'); } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    }));
    $('#publish', main).addEventListener('click', (e) => busy(e.currentTarget, async () => {
      try { await save(); } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); return; }
      const r = await confirmSheet({
        title: `Publish "${form.title.value}"${p.version > 1 ? ` version ${p.version}` : ''}?`,
        text: `Everyone it applies to is asked to read and sign it, in Trio HR and in the CRM.${p.version > 1 ? ' The previous version is retired; signatures on it stay on record.' : ''} A published version can't be edited.`,
        confirm: 'Publish', onConfirm: () => api.post(`/policies/${id}/publish`),
      });
      if (r) { toast(r.message); go(`#/policies/${id}`, { replace: true }); }
    }));
    return;
  }

  mount(main, html`
    <a class="crumb" href="#/policies">${icon('back', 'i--sm')}Policies</a>
    <div class="ph"><div class="ph__t"><h1>${p.title} <span class="pill ${p.status === 'published' ? 'p-ok' : 'p-neutral'}">${p.status === 'published' ? 'Live' : 'Retired'} · version ${p.version}</span></h1>
      <p class="ph__s">${p.kind === 'consent' ? 'Consent · staff can withdraw it' : 'Policy'} · published ${f.day(p.published_at)}${p.published_by ? ` by ${p.published_by}` : ''}</p></div>
      <div class="ph__a">${p.status === 'published' ? html`<button class="btn" type="button" id="remind">${icon('bell')}Remind who hasn't signed</button>` : ''}
        <button class="btn" type="button" id="newv">${icon('edit')}Write the next version</button></div></div>
    ${versions}
    <div class="grid2">
      <div class="card"><div class="card__h"><h2>The text</h2></div><div class="card__b"><div class="doc">${raw(p.html)}</div></div></div>
      <div class="stack">
        ${d.stats ? html`<div class="card kpi"><div class="k">${icon('check', 'i--sm')}Signed</div><div class="v tnum">${d.stats.signed} <small>of ${d.stats.audience}</small></div>
          <div class="meter"><i data-w="${d.stats.audience ? (d.stats.signed / d.stats.audience) * 100 : 0}"></i></div></div>` : ''}
        <div class="card"><div class="card__h"><nav class="views">${[['missing', 'Not yet', counts.missing], ['signed', 'Signed', counts.signed], ...(counts.withdrawn ? [['withdrawn', 'Withdrew', counts.withdrawn]] : []), ['all', 'Everyone', d.people.length]].map(([k, l, n]) =>
          html`<a href="#/policies/${id}?show=${k}" ${k === show ? raw('aria-current="true"') : ''}>${l} <em>${n}</em></a>`)}</nav></div>
          <div class="card__b card__b--tight">${people.length ? people.map((x) => html`<div class="minirow"><span class="av av--sm">${x.initials}</span>
            <div><a class="name" href="#/people/${x.id}">${x.name}</a><small>${x.designation || ''}</small></div>
            <span class="r"><span class="pill pill--sm ${STATE_PILL[x.state][0]}">${STATE_PILL[x.state][1]}</span>${x.at ? html`<small class="meta"> ${f.day(x.at)}</small>` : ''}</span></div>`)
            : html`<p class="muted">Nobody here.</p>`}</div></div>
      </div>
    </div>`);
  $('#remind', main)?.addEventListener('click', async () => {
    const r = await confirmSheet({ title: `Remind ${f.plural(counts.missing, 'person', 'people')} to sign?`, text: 'They get a notice in Trio HR and the CRM, and an email.', confirm: 'Send reminders', onConfirm: () => api.post(`/policies/${id}/remind`) });
    if (r) toast(r.message);
  });
  $('#newv', main).addEventListener('click', (e) => busy(e.currentTarget, async () => {
    try { const r = await api.post(`/policies/${id}/new-version`); toast(r.message); go(`#/policies/${r.id}`); } catch (err) { toast(err.message, { tone: 'bad' }); }
  }));
}
