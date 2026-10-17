// The print page: one letter on an exact A4 sheet. "Print or save as PDF" waits
// for the fonts, so the PDF always looks like the screen.

import { html, mount, $, icon } from './core/dom.js';
import { letterSheet } from './core/letter.js';

const params = new URLSearchParams(location.search);
const id = Number(params.get('letter'));
const mine = params.get('as') === 'me';

async function load() {
  const bar = $('#bar');
  if (!id) { mount(bar, html`<b>No letter chosen.</b>`); return; }
  let res;
  try {
    res = await fetch(`api/${mine ? 'me/letters' : 'letters'}/${id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
  } catch {
    mount(bar, html`<b>Can't reach Trio HR.</b> Check the connection and reload.`);
    return;
  }
  const data = await res.json().catch(() => null);
  if (!res.ok || !data) {
    mount(bar, res.status === 401
      ? html`<b>Please sign in to Trio HR first.</b> <a href="./#/signin">Sign in</a>, then open the letter again.`
      : html`<b>${data?.error?.message || "This letter couldn't be opened."}</b>`);
    return;
  }
  const l = data.letter;
  document.title = `${l.number ? l.number.replace(/\//g, '-') : 'DRAFT'} ${l.subject} - ${data.person.name}`;
  mount($('#page'), html`<div class="ltrbox">${letterSheet(data, { qr: true })}</div>`);
  const state = l.status === 'void' ? (l.number ? ` · ${l.number} · cancelled` : ' · discarded draft') : l.number ? ` · ${l.number}` : ' · draft, not issued';
  mount(bar, html`
    <span><b>${l.template}</b> · ${data.person.name}${state}</span>
    <span class="spacer"></span>
    ${l.status === 'void' ? html`<b>Cancelled letters can't be printed.</b>`
      : html`<button class="btn btn--pri" type="button" id="print" disabled>${icon('down')}Print or save as PDF</button>`}`);
  await document.fonts.ready;
  const btn = $('#print');
  if (btn) {
    btn.disabled = false;
    btn.addEventListener('click', () => window.print());
  }
}

load();
