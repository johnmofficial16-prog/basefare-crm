// Public: is this Trio letter genuine? Anyone with the code can check,
// without signing in (e.g. a bank or a background-check agency).

import { html, mount, $, icon, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { busy } from '../core/ui.js';

export function render({ main, params }) {
  const code = (params[0] || '').toUpperCase();
  mount(main, html`
  <div class="verifypage">
    <div class="verifycard">
      <div class="verifycard__brand"><span class="logo-tile"><img src="assets/img/trio-logo.png" alt=""></span>
        <span><b>Trio Tours &amp; Travels</b><small>Letter check</small></span></div>
      <h1>Is this letter genuine?</h1>
      <p class="lead">Enter the code printed next to the QR code on the letter.</p>
      <form class="form" id="vf" novalidate>
        <div class="field"><label for="v-code">Code</label>
          <input class="input input--big tnum" id="v-code" name="code" value="${code}" placeholder="XXXX-XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false">
          <div class="err" role="alert"></div></div>
        <button class="btn btn--pri btn--lg btn--block" type="submit">Check the letter</button>
      </form>
      <div id="vres" aria-live="polite"></div>
      <p class="meta">Only the letter's type, number, date and the person's name are shown.</p>
    </div>
  </div>`);
  const form = $('#vf', main);
  const check = () => busy($('[type="submit"]', form), async () => {
    const value = form.code.value.trim();
    if (!value) { showFieldErrors(form, { code: 'Type the code printed on the letter.' }); return; }
    showFieldErrors(form, null);
    const out = $('#vres', main);
    try {
      const r = await api.post('/verify', { code: value });
      mount(out, !r.found
        ? html`<div class="banner banner--bad">${icon('alert')}<div><b>No letter found with this code.</b>Check the code and try again. If it still isn't found, the letter may not be genuine.</div></div>`
        : r.valid
          ? html`<div class="banner banner--ok">${icon('check')}<div><b>Genuine: ${r.kind}</b>Number ${r.number}, issued to ${r.name} on ${r.issued_on} by ${r.issuer}.${r.contact ? ` Questions about it: ${r.contact}.` : ''}</div></div>`
          : html`<div class="banner banner--warn">${icon('alert')}<div><b>This letter was cancelled.</b>${r.kind} ${r.number} for ${r.name} was issued on ${r.issued_on} but is no longer valid.</div></div>`);
    } catch (err) {
      mount(out, html`<div class="banner banner--bad">${icon('alert')}<div>${err.message}</div></div>`);
    }
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); check(); });
  if (code) check();
}
