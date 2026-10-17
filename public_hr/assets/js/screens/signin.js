// Sign in, accept an invitation, forgotten password, reset.

import { html, mount, $, icon, formData, showFieldErrors } from '../core/dom.js';
import { api, ApiError } from '../core/api.js';
import { busy, toast } from '../core/ui.js';
import { setSession, go, homeHash } from '../app.js';

function frame(main, panel) {
  mount(main, html`
  <div class="auth">
    <section class="auth__brand" aria-hidden="true">
      <div class="auth__logo"><span class="logo-tile"><img src="assets/img/trio-logo.png" alt=""></span>
        <span><b>Trio HR</b><small>Trio Tours &amp; Travels</small></span></div>
      <p class="auth__quote">Your people, every night, <em>in one calm place.</em></p>
      <div class="auth__fine"><span>Trio Tours and Travels Pvt Ltd · Mohali</span><span>Trio HR has its own sign-in. It is separate from your CRM login.</span></div>
    </section>
    <section class="auth__panel">${panel}</section>
  </div>`);
}

function pwField(name, label, autocomplete, help = '') {
  return html`
    <div class="field">
      <label for="f-${name}">${label}</label>
      <div class="pwwrap">
        <input class="input input--big" id="f-${name}" name="${name}" type="password" autocomplete="${autocomplete}" required>
        <button type="button" data-peek="f-${name}" aria-label="Show password">${icon('eye')}</button>
      </div>
      ${help}
      <div class="err" role="alert"></div>
    </div>`;
}

function wirePeek(root) {
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-peek]');
    if (!b) return;
    const input = document.getElementById(b.dataset.peek);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    mount(b, icon(show ? 'eyeoff' : 'eye'));
  });
}

function strength(pw) {
  let s = 0;
  if (pw.length >= 10) s++;
  if (pw.length >= 14) s++;
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
  if (/\d/.test(pw) || /[^A-Za-z0-9]/.test(pw)) s++;
  return pw.length < 10 ? 0 : Math.min(3, s);
}

function wireMeter(root) {
  const input = $('#f-password', root);
  const bar = $('.pwmeter i', root);
  const note = $('[data-meter-note]', root);
  if (!input || !bar) return;
  input.addEventListener('input', () => {
    const s = strength(input.value);
    bar.className = s >= 3 ? 'm3' : s === 2 ? 'm2' : '';
    bar.style.width = (input.value ? [15, 45, 70, 100][s] : 0) + '%';
    note.textContent = !input.value ? 'At least 10 characters. A short sentence you will remember works well.'
      : s === 0 ? `${10 - input.value.length > 0 ? 10 - input.value.length + ' more characters needed' : 'Add a few more characters'}`
      : s === 1 ? 'OK. Longer is stronger.' : s === 2 ? 'Good.' : 'Strong.';
  });
}

const newPasswordHelp = html`<div class="pwmeter" aria-hidden="true"><i></i></div><div class="help" data-meter-note>At least 10 characters. A short sentence you will remember works well.</div>`;

function afterSignIn(s, fallbackMsg) {
  forgetLink();
  setSession(s);
  let next = null;
  try { next = sessionStorage.getItem('trio-hr-next'); sessionStorage.removeItem('trio-hr-next'); } catch {}
  const admin = ['owner', 'admin'].includes(s.account.role);
  const ok = next && (admin ? !next.startsWith('#/me') || s.employee : next.startsWith('#/me'));
  if (fallbackMsg) toast(fallbackMsg);
  go(ok ? next : homeHash(), { replace: true });
}

// ---- sign in -----------------------------------------------------------------

export function signin({ main }) {
  frame(main, html`
    <h1>Sign in</h1>
    <p class="lead">Welcome back. Use your Trio HR email and password, not your CRM login.</p>
    <form class="form" id="f" novalidate>
      <div class="banner banner--bad formerr" id="formerr" role="alert">${icon('alert')}<div></div></div>
      <div class="field">
        <label for="f-email">Email</label>
        <input class="input input--big" id="f-email" name="email" type="email" autocomplete="username" inputmode="email" required>
        <div class="err" role="alert"></div>
      </div>
      ${pwField('password', 'Password', 'current-password')}
      <button class="btn btn--pri btn--lg btn--block" type="submit">Sign in</button>
    </form>
    <div class="auth__alt"><a href="#/forgot">Forgot your password?</a><span class="muted">New here? Use the link in your invitation email.</span></div>
    <div class="auth__alt"><a href="#/verify">Check a Trio letter is genuine</a></div>`);
  const form = $('#f', main);
  wirePeek(form);
  $('#f-email', main).focus();
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('#formerr', main);
    err.classList.remove('is-on');
    const data = formData(form);
    const fields = {};
    if (!data.email) fields.email = 'Enter your email.';
    if (!data.password) fields.password = 'Enter your password.';
    if (showFieldErrors(form, fields)) return;
    await busy($('[type="submit"]', form), async () => {
      try {
        const s = await api.post('/session', { email: data.email, password: form.password.value });
        if (s.challenge) { codeStep(main, s); return; }
        afterSignIn(s);
      } catch (ex) {
        if (ex instanceof ApiError && ex.fields) { showFieldErrors(form, ex.fields); return; }
        $('div', err).textContent = ex.message;
        err.classList.add('is-on');
        form.password.value = '';
        form.password.focus();
      }
    });
  });
}

// ---- emailed code (staff on a new phone, when the owner has switched it on) ------

function codeStep(main, info) {
  let challenge = info.challenge;
  frame(main, html`
    <h1>Check your email</h1>
    <p class="lead">We sent a 6-digit code to ${info.to}. It works for 10 minutes.</p>
    <form class="form" id="cf" novalidate>
      <div class="field">
        <label for="f-code">Code</label>
        <input class="input input--big tnum" id="f-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required>
        <div class="err" role="alert"></div>
      </div>
      <label class="check-row"><input type="checkbox" name="remember" checked> Remember this phone, so I don't need a code next time</label>
      <button class="btn btn--pri btn--lg btn--block" type="submit">Continue</button>
    </form>
    <div class="auth__alt"><button class="linkbtn" type="button" id="resend">Send a new code</button><a href="#/signin">Use another account</a></div>`);
  const form = $('#cf', main);
  $('#f-code', main).focus();
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = form.code.value.replace(/\D/g, '');
    if (code.length !== 6) { showFieldErrors(form, { code: 'Type the 6 digits from the email.' }); return; }
    await busy($('[type="submit"]', form), async () => {
      try {
        afterSignIn(await api.post('/session/verify', { challenge, code, remember: form.remember.checked }));
      } catch (ex) {
        if (ex.status === 410) { toast(ex.message, { tone: 'bad' }); go('#/signin', { replace: true }); return; }
        showFieldErrors(form, { code: ex.message });
        form.code.select();
      }
    });
  });
  $('#resend', main).addEventListener('click', async (e) => {
    await busy(e.currentTarget, async () => {
      try {
        const r = await api.post('/session/resend', { challenge });
        challenge = r.challenge;
        toast(r.message);
      } catch (ex) { toast(ex.message, { tone: 'bad' }); }
    });
  });
}

// ---- forgot --------------------------------------------------------------------

export function forgot({ main }) {
  frame(main, html`
    <h1>Forgot your password?</h1>
    <p class="lead">Enter your email and we'll send a link to choose a new one. The link works for 1 hour.</p>
    <form class="form" id="f" novalidate>
      <div class="banner banner--ok formerr" id="done" role="status">${icon('check')}<div></div></div>
      <div class="field">
        <label for="f-email">Email</label>
        <input class="input input--big" id="f-email" name="email" type="email" autocomplete="username" required>
        <div class="err" role="alert"></div>
      </div>
      <button class="btn btn--pri btn--lg btn--block" type="submit">Send the link</button>
    </form>
    <div class="auth__alt"><a href="#/signin">${'← Back to sign in'}</a></div>`);
  const form = $('#f', main);
  $('#f-email', main).focus();
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = form.email.value.trim();
    if (showFieldErrors(form, email ? null : { email: 'Enter your email.' })) return;
    await busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post('/password/forgot', { email });
        $('#done div', main).textContent = r.message;
        $('#done', main).classList.add('is-on');
      } catch (ex) {
        showFieldErrors(form, ex.fields || { email: ex.message });
      }
    });
  });
}

// ---- welcome (invitation) and reset ---------------------------------------------------

function newPasswordForm(main, { title, lead, button }) {
  frame(main, html`
    <h1>${title}</h1>
    <p class="lead">${lead}</p>
    <form class="form" id="f" novalidate>
      <div class="banner banner--bad formerr" id="formerr" role="alert">${icon('alert')}<div></div></div>
      ${pwField('password', 'Choose a password', 'new-password', newPasswordHelp)}
      ${pwField('confirm', 'Type it again', 'new-password')}
      <button class="btn btn--pri btn--lg btn--block" type="submit">${button}</button>
    </form>`);
  const form = $('#f', main);
  wirePeek(form);
  wireMeter(main);
  $('#f-password', main).focus();
  return form;
}

function wireNewPassword(main, form, submit) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const pw = form.password.value;
    const fields = {};
    if (pw.length < 10) fields.password = 'Use at least 10 characters.';
    else if (pw !== form.confirm.value) fields.confirm = "The two passwords don't match.";
    if (showFieldErrors(form, fields)) return;
    await busy($('[type="submit"]', form), async () => {
      try {
        await submit(pw);
      } catch (ex) {
        if (ex.fields) { showFieldErrors(form, ex.fields); return; }
        const err = $('#formerr', main);
        $('div', err).textContent = ex.message;
        err.classList.add('is-on');
      }
    });
  });
}

function deadLink(main, message, kind = 'welcome') {
  forgetLink();
  frame(main, html`
    <h1>This link can't be used</h1>
    <p class="lead">${message}</p>
    <a class="btn btn--pri btn--lg" href="${kind === 'reset' ? '#/forgot' : '#/signin'}">${kind === 'reset' ? 'Ask for a new link' : 'Go to sign in'}</a>
    <div class="auth__alt"><span class="muted">${kind === 'reset' ? 'Reset links work once, for 1 hour.' : 'Need a new invitation? Ask the HR admin to send it again.'}</span></div>`);
}

// The link's token is taken out of the address (so it never sits in history) but kept for this
// tab only, so reloading the page still works.
function rememberLink(kind, token) { try { sessionStorage.setItem('trio-hr-link', JSON.stringify({ kind, token })); } catch {} }
function recallLink(kind) {
  try { const v = JSON.parse(sessionStorage.getItem('trio-hr-link') || 'null'); return v && v.kind === kind ? v.token : null; } catch { return null; }
}
function forgetLink() { try { sessionStorage.removeItem('trio-hr-link'); } catch {} }

export async function welcome({ main, params }) {
  const token = params[0] || recallLink('welcome');
  if (!token) { deadLink(main, 'For safety this page needs the link from your invitation email. Please open that link again.'); return; }
  rememberLink('welcome', token);
  history.replaceState(null, '', '#/welcome'); // keep the token out of history
  let info;
  try {
    info = await api.post('/invite/check', { token });
  } catch (ex) {
    deadLink(main, ex.message);
    return;
  }
  const form = newPasswordForm(main, {
    title: `Welcome, ${info.name}`,
    lead: `Set a password for ${info.email} to start using Trio HR. You'll use this email and password to sign in.`,
    button: 'Set password and continue',
  });
  wireNewPassword(main, form, async (password) => {
    const s = await api.post('/invite/accept', { token, password });
    afterSignIn(s, 'Your Trio HR account is ready.');
  });
}

export async function reset({ main, params }) {
  const token = params[0] || recallLink('reset');
  if (!token) { deadLink(main, 'For safety this page needs the link from your email. Please open that link again.', 'reset'); return; }
  rememberLink('reset', token);
  history.replaceState(null, '', '#/reset');
  try {
    await api.post('/password/check', { token });
  } catch (ex) {
    deadLink(main, ex.message, 'reset');
    return;
  }
  const form = newPasswordForm(main, {
    title: 'Choose a new password',
    lead: 'After this, every other device signed in to your account will be signed out.',
    button: 'Save new password',
  });
  wireNewPassword(main, form, async (password) => {
    try {
      const s = await api.post('/password/reset', { token, password });
      afterSignIn(s, 'Password changed. You are signed in.');
    } catch (ex) {
      if (ex.status === 410) { deadLink(main, ex.message, 'reset'); return; }
      throw ex;
    }
  });
}

// ---- From the CRM ("Trio HR" in the CRM sidebar) --------------------------------------

/** #/from-crm/<token>: a one-time link the CRM signed. Swap it for a Trio HR session. */
export async function fromCrm({ main, params, query }) {
  const token = params[0];
  const to = query.get('to');
  if (to && /^\/me(\/[a-z0-9-]{1,30}){0,3}$/.test(to)) { try { sessionStorage.setItem('trio-hr-next', '#' + to); } catch {} }
  // Take the token out of the address bar and history straight away.
  history.replaceState(null, '', '#/from-crm');
  frame(main, html`<div class="center"><span class="spin spin--lg" aria-hidden="true"></span><h1>Opening Trio HR…</h1><p class="lead">Signing you in from the CRM.</p></div>`);
  try {
    const s = await api.post('/session/crm', { token });
    afterSignIn(s);
  } catch (err) {
    if (err.reason === 'handoff_other') {
      frame(main, html`<h1>Someone else is signed in</h1>
        <div class="banner banner--warn">${icon('info')}<div>${err.message}</div></div>
        <button class="btn btn--pri btn--block" type="button" id="soother">Sign them out of Trio HR</button>`);
      $('#soother', main).addEventListener('click', (e) => busy(e.currentTarget, async () => {
        try { await api.del('/session'); } catch {}
        setSession(null);
        frame(main, html`<h1>Signed out</h1><p class="lead">Now go back to the CRM and click <b>Trio HR</b> again to open your own.</p>`);
      }));
      return;
    }
    frame(main, html`<h1>Sign in to Trio HR</h1>
      <div class="banner banner--warn">${icon('info')}<div>${err.message || "That link didn't work."}</div></div>
      <p class="lead">You can open Trio HR from the CRM again, or sign in with your Trio HR email and password.</p>
      <a class="btn btn--pri btn--block" href="#/signin">Sign in</a>`);
  }
}
