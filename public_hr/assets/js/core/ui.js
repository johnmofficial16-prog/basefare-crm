// Toasts, confirm sheets, drawers, menus and the Ctrl K palette.
// Every overlay traps focus, closes on Escape and returns focus to its opener.

import { html, raw, esc, mount, $, $$, icon } from './dom.js';
import { ApiError } from './api.js';

// ---- toasts ------------------------------------------------------------------

export function toast(message, { tone = 'ok', sub = '', ms = 5200 } = {}) {
  if (!message) return;
  const box = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className = `toast is-${tone}`;
  mount(el, html`${icon(tone === 'bad' ? 'alert' : 'check')}<div>${message}${sub ? html`<small>${sub}</small>` : ''}</div><button class="x" type="button" aria-label="Dismiss">${icon('x', 'i--sm')}</button>`);
  $('.x', el).addEventListener('click', () => el.remove());
  box.appendChild(el);
  setTimeout(() => el.remove(), tone === 'bad' ? ms * 2 : ms);
}

export function toastError(err) {
  const ref = err instanceof ApiError && err.ref ? `Reference ${err.ref}` : '';
  toast(err?.message || 'Something went wrong.', { tone: 'bad', sub: ref });
}

// ---- overlay stack ---------------------------------------------------------------

const stack = [];

function trap(e) {
  const top = stack[stack.length - 1];
  if (!top) return;
  if (e.key === 'Escape') { e.preventDefault(); top.close(); return; }
  if (e.key !== 'Tab') return;
  const items = $$('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])', top.el)
    .filter((x) => x.offsetParent !== null);
  if (!items.length) return;
  const first = items[0], last = items[items.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
document.addEventListener('keydown', trap);

function openLayer(el, { scrim = true, onClose, canClose } = {}) {
  const opener = document.activeElement;
  let sc = null;
  if (scrim) {
    sc = document.createElement('div');
    sc.className = 'scrim';
    document.body.appendChild(sc);
    requestAnimationFrame(() => sc.classList.add('is-on'));
  }
  document.body.appendChild(el);
  requestAnimationFrame(() => el.classList.add('is-on'));
  const layer = {
    el,
    close(force = false) {
      const i = stack.indexOf(layer);
      if (i === -1) return;
      if (!force && canClose && !canClose()) return; // e.g. a confirmation that is still saving
      stack.splice(i, 1);
      el.classList.remove('is-on');
      sc?.classList.remove('is-on');
      setTimeout(() => { el.remove(); sc?.remove(); }, 220);
      onClose?.();
      if (opener && document.contains(opener)) opener.focus();
    },
  };
  sc?.addEventListener('click', () => layer.close());
  stack.push(layer);
  return layer;
}

/**
 * Keep keyboard focus inside an element that is already on the page (the
 * phone menu): Tab cycles inside it, Esc closes it, focus returns afterwards.
 */
export function holdFocus(el, { onClose } = {}) {
  const opener = document.activeElement;
  const layer = {
    el,
    close() {
      const i = stack.indexOf(layer);
      if (i === -1) return;
      stack.splice(i, 1);
      onClose?.();
      if (opener && document.contains(opener)) opener.focus();
    },
  };
  stack.push(layer);
  return layer;
}

export function closeAll() { while (stack.length) stack[stack.length - 1].close(true); }

// ---- confirm sheet ---------------------------------------------------------------

/**
 * A plain-English confirmation. `onConfirm(value)` runs the action; if it
 * throws, the error shows inside the sheet and it stays open.
 * Resolves with onConfirm's result, or null when cancelled.
 */
export function confirmSheet({ title, text = '', summary = [], confirm = 'Confirm', cancel = 'Cancel', cancelValue, tone = 'pri', input = null, chips = [], onConfirm }) {
  return new Promise((resolve) => {
    const el = document.createElement('div');
    el.className = 'sheet';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-labelledby', 'sheet-t');
    const field = input ? html`
      <div class="field">
        <label for="sheet-in">${input.label}</label>
        ${chips.length ? html`<div class="chips">${chips.map((c) => html`<button type="button" class="chip" data-chip="${c}">${c}</button>`)}</div>` : ''}
        ${input.kind === 'textarea'
          ? html`<textarea class="textarea" id="sheet-in" rows="3" placeholder="${input.placeholder || ''}"></textarea>`
          : html`<input class="input" id="sheet-in" type="${input.kind === 'password' ? 'password' : 'text'}" autocomplete="${input.kind === 'password' ? 'current-password' : 'off'}" placeholder="${input.placeholder || ''}">`}
        ${input.help ? html`<div class="help">${input.help}</div>` : ''}
        <div class="err" role="alert"></div>
      </div>` : html`<div class="field"><div class="err" role="alert"></div></div>`;
    mount(el, html`
      <div class="sheet__b">
        <h2 id="sheet-t">${title}</h2>
        ${text ? html`<p>${text}</p>` : ''}
        ${summary.length ? html`<div class="sumbox tnum">${summary.map(([k, v]) => html`<div><span>${k}</span><b>${v}</b></div>`)}</div>` : ''}
        ${field}
      </div>
      <div class="sheet__f">
        <button type="button" class="btn btn--quiet" data-act="cancel">${cancel}</button>
        <button type="button" class="btn ${tone === 'danger' ? 'btn--danger' : tone === 'gold' ? 'btn--gold' : 'btn--pri'}" data-act="ok">${confirm}</button>
      </div>`);
    let done = false;
    let saving = false;
    let cancelled = false;
    // Closed by the Cancel button → cancelValue (when given); by Esc or a tap outside → null.
    const layer = openLayer(el, { canClose: () => !saving, onClose: () => { if (!done) resolve(cancelled && cancelValue !== undefined ? cancelValue : null); } });
    const inp = $('#sheet-in', el);
    const errBox = $('.err', el);
    const fieldEl = errBox.closest('.field');
    const ok = $('[data-act="ok"]', el);
    const cancelBtn = $('[data-act="cancel"]', el);
    // Enter must never confirm a removal by accident: destructive sheets start on Cancel.
    (inp || (tone === 'danger' ? cancelBtn : ok)).focus();
    $$('[data-chip]', el).forEach((b) => b.addEventListener('click', () => {
      $$('[data-chip]', el).forEach((x) => x.setAttribute('aria-pressed', 'false'));
      b.setAttribute('aria-pressed', 'true');
      inp.value = b.dataset.chip;
      inp.focus();
    }));
    cancelBtn.addEventListener('click', () => { if (saving) return; cancelled = true; layer.close(); });
    if (inp && input.kind !== 'textarea') inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } });
    ok.addEventListener('click', async () => {
      const value = inp ? inp.value.trim() : null;
      if (input?.required && (!value || value.length < (input.minLength || 1))) {
        fieldEl.classList.add('has-err');
        errBox.textContent = input.requiredText || 'Please fill this in.';
        inp.focus();
        return;
      }
      fieldEl.classList.remove('has-err');
      ok.classList.add('is-busy');
      ok.disabled = true;
      cancelBtn.disabled = true;
      saving = true;
      const label = ok.innerHTML;
      ok.innerHTML = '<span class="spin"></span>' + esc(ok.textContent);
      try {
        const result = onConfirm ? await onConfirm(value) : true;
        done = true;
        saving = false;
        layer.close();
        resolve(result ?? true);
      } catch (err) {
        saving = false;
        cancelBtn.disabled = false;
        fieldEl.classList.add('has-err');
        errBox.textContent = err?.message || 'Something went wrong.';
        if (err?.ref) errBox.textContent += ` (Reference ${err.ref})`;
        ok.classList.remove('is-busy');
        ok.disabled = false;
        ok.innerHTML = label;
        (inp || ok).focus();
      }
    });
  });
}

/**
 * A one-time sign-in link for an admin to hand over (Trio HR sends no email).
 * r = { link, setup, name, valid }. Shown once; making a new one cancels it.
 */
export function linkSheet(r) {
  return new Promise((resolve) => {
    const el = document.createElement('div');
    el.className = 'sheet';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-labelledby', 'sheet-t');
    mount(el, html`
      <div class="sheet__b">
        <h2 id="sheet-t">${r.setup ? `${r.name}'s set-up link` : `New sign-in link for ${r.name}`}</h2>
        <p>Send this to ${r.name} yourself, on WhatsApp or in person. ${r.setup ? 'They open it to choose a password.' : 'They open it to choose a new password.'} It works once, for ${r.valid}. Don't post it in a group: anyone with the link can use it.</p>
        <div class="field"><label for="sheet-link">Link</label>
          <input class="input" id="sheet-link" readonly value="${r.link}"></div>
      </div>
      <div class="sheet__f">
        <button type="button" class="btn btn--quiet" data-act="close">Done</button>
        <button type="button" class="btn btn--pri" data-act="copy">${icon('link')}Copy the link</button>
      </div>`);
    const layer = openLayer(el, { onClose: () => resolve(true) });
    const inp = $('#sheet-link', el);
    const copy = $('[data-act="copy"]', el);
    copy.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(r.link); } catch { inp.select(); document.execCommand('copy'); }
      copy.textContent = 'Copied';
    });
    $('[data-act="close"]', el).addEventListener('click', () => layer.close());
    inp.addEventListener('focus', () => inp.select());
    copy.focus();
  });
}

// ---- drawer ----------------------------------------------------------------------

export function openDrawer({ title, body, foot = '', wide = false, onClose }) {
  const el = document.createElement('aside');
  el.className = 'drawer' + (wide ? ' drawer--wide' : '');
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-modal', 'true');
  el.setAttribute('aria-labelledby', 'drawer-t');
  mount(el, html`
    <div class="drawer__h"><h2 id="drawer-t">${title}</h2>
      <button type="button" class="iconbtn" data-act="close" aria-label="Close">${icon('x')}</button></div>
    <div class="drawer__b">${body}</div>
    ${foot ? html`<div class="drawer__f">${foot}</div>` : ''}`);
  const layer = openLayer(el, { onClose });
  $('[data-act="close"]', el).addEventListener('click', () => layer.close());
  setTimeout(() => ($('input, select, textarea', el) || $('[data-act="close"]', el)).focus(), 60);
  return layer;
}

// ---- menu --------------------------------------------------------------------------

export function openMenu(anchor, items, header = null) {
  const el = document.createElement('div');
  el.className = 'menu';
  el.setAttribute('role', 'menu');
  mount(el, html`${header ? html`<div class="menu__who">${header}</div><hr>` : ''}${items.map((it, i) => it === '-' ? raw('<hr>')
    : it.href ? html`<a role="menuitem" href="${it.href}" data-i="${i}" ${it.external ? raw('target="_blank" rel="noopener"') : ''}>${icon(it.icon || 'arrow')}${it.label}${it.external ? icon('ext', 'i--sm') : ''}</a>`
    : html`<button role="menuitem" type="button" data-i="${i}">${icon(it.icon || 'arrow')}${it.label}</button>`)}`);
  const layer = openLayer(el, { scrim: false });
  const r = anchor.getBoundingClientRect();
  const w = 240;
  el.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left)) + 'px';
  const below = r.bottom + 6;
  el.style.top = (below + 260 > window.innerHeight ? Math.max(8, r.top - 6 - el.offsetHeight) : below) + 'px';
  const away = (e) => { if (!el.contains(e.target) && e.target !== anchor) { layer.close(); document.removeEventListener('mousedown', away); } };
  setTimeout(() => document.addEventListener('mousedown', away), 0);
  $$('[data-i]', el).forEach((b) => b.addEventListener('click', () => {
    const it = items[Number(b.dataset.i)];
    layer.close();
    document.removeEventListener('mousedown', away);
    it.onClick?.();
  }));
  $('[role="menuitem"]', el)?.focus();
  return layer;
}

// ---- dropdown panel (the bell) ------------------------------------------------------

/** A panel under its button on wide screens, a full-width sheet on phones. */
export function openPanel(anchor, view, { onMount, onClose, label = 'Panel' } = {}) {
  const el = document.createElement('div');
  el.className = 'panel';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-label', label);
  el.tabIndex = -1;
  mount(el, view);
  const phone = window.innerWidth < 640;
  const layer = openLayer(el, { scrim: phone, onClose });
  if (!phone) {
    const r = anchor.getBoundingClientRect();
    el.style.top = (r.bottom + 8) + 'px';
    el.style.left = Math.max(8, Math.min(window.innerWidth - el.offsetWidth - 8, r.right - el.offsetWidth)) + 'px';
    const away = (e) => {
      if (!el.contains(e.target) && !anchor.contains(e.target)) { layer.close(); document.removeEventListener('mousedown', away); }
    };
    setTimeout(() => document.addEventListener('mousedown', away), 0);
  } else {
    el.classList.add('panel--sheet');
  }
  onMount?.(el, layer);
  const x = el.querySelector('[data-close]');
  x?.addEventListener('click', () => layer.close());
  (el.querySelector('a[href], button:not([disabled])') || el).focus();
  return layer;
}

// ---- Ctrl K palette ----------------------------------------------------------------

export function openPalette({ actions, search }) {
  const el = document.createElement('div');
  el.className = 'palette';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-modal', 'true');
  el.setAttribute('aria-label', 'Search Trio HR');
  mount(el, html`
    <div class="palette__in">${icon('search')}<input type="text" placeholder="Search people, or type what you want to do…" aria-label="Search" autocomplete="off" spellcheck="false"><span class="kbd">Esc</span></div>
    <div class="palette__list" role="listbox"></div>
    <div class="palette__foot"><span>↑ ↓ to move</span><span>Enter to open</span><span>Esc to close</span></div>`);
  const layer = openLayer(el);
  const input = $('input', el);
  const list = $('.palette__list', el);
  let results = [];
  let active = 0;
  let seq = 0;

  const render = (people, q) => {
    const ql = q.toLowerCase();
    const acts = actions.filter((a) => !ql || a.label.toLowerCase().includes(ql) || (a.keywords || '').includes(ql)).slice(0, q ? 6 : 8);
    results = [...people.map((p) => ({ href: `#/people/${p.id}`, label: p.name, hint: p.detail, avatar: p.initials })), ...acts];
    active = Math.min(active, Math.max(0, results.length - 1));
    mount(list, results.length ? html`
      ${people.length ? html`<div class="palette__k">People</div>` : ''}
      ${results.map((r, i) => html`
        ${i === people.length && acts.length ? html`<div class="palette__k">${q ? 'Actions' : 'Go to'}</div>` : ''}
        <a class="palette__item ${i === active ? 'is-active' : ''}" href="${r.href}" data-i="${i}" id="pal-${i}" role="option" aria-selected="${i === active}">
          ${r.avatar ? html`<span class="av av--sm">${r.avatar}</span>` : html`<span class="dot dot--sm d-neutral">${icon(r.icon || 'arrow', 'i--sm')}</span>`}
          <span><b>${r.label}</b>${r.hint ? html`<small>${r.hint}</small>` : ''}</span>
          ${icon('chev', 'go i--sm')}
        </a>`)}`
      : html`<div class="empty"><b>Nothing found</b><p>Try a first name, an employee code like TT-0012, or a phone number.</p></div>`);
  };
  const go = (i) => { const r = results[i]; if (!r) return; layer.close(); location.hash = r.href; };
  const update = async () => {
    const q = input.value.trim();
    const mine = ++seq;
    let people = [];
    if (q.length >= 2) {
      try { people = (await search(q)).people || []; } catch { people = []; }
    }
    if (mine === seq) { shown = people; render(people, q); point(); }
  };
  let shown = [];
  const point = () => { if (results[active]) input.setAttribute('aria-activedescendant', `pal-${active}`); else input.removeAttribute('aria-activedescendant'); };
  const highlight = () => {
    $$('.palette__item', list).forEach((a) => {
      const on = Number(a.dataset.i) === active;
      a.classList.toggle('is-active', on);
      a.setAttribute('aria-selected', String(on));
      if (on) a.scrollIntoView({ block: 'nearest' });
    });
    point();
  };
  let wait = null;
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-controls', 'pal-list');
  input.setAttribute('aria-expanded', 'true');
  list.id = 'pal-list';
  input.addEventListener('input', () => {
    active = 0;
    clearTimeout(wait);
    // Actions filter at once; the people search waits until typing pauses.
    render(shown, input.value.trim());
    wait = setTimeout(update, 220);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(results.length - 1, active + 1); highlight(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); highlight(); }
    if (e.key === 'Enter') { e.preventDefault(); go(active); }
  });
  list.addEventListener('click', (e) => { const a = e.target.closest('[data-i]'); if (a) { e.preventDefault(); go(Number(a.dataset.i)); } });
  render([], '');
  input.focus();
  return layer;
}

// ---- small helpers ---------------------------------------------------------------

/** Put a button into its busy state while `fn` runs. */
export async function busy(btn, fn) {
  if (!btn || btn.disabled) return;
  const label = btn.innerHTML;
  btn.disabled = true;
  btn.classList.add('is-busy');
  btn.innerHTML = '<span class="spin"></span>' + esc(btn.textContent.trim());
  try { return await fn(); }
  finally { btn.disabled = false; btn.classList.remove('is-busy'); btn.innerHTML = label; }
}

export function skeleton(kind = 'page') {
  if (kind === 'list') return html`<div class="card card__b"><div class="sk sk--line"></div><div class="sk sk--line"></div><div class="sk sk--line"></div><div class="sk sk--line"></div></div>`;
  return html`<div class="sk sk--title"></div><div class="kpis"><div class="sk sk--card"></div><div class="sk sk--card"></div><div class="sk sk--card"></div><div class="sk sk--card"></div></div><div class="sk sk--card"></div>`;
}

export function errorView(err, retry) {
  return html`<div class="card"><div class="errorbox">
    <div class="dot d-bad">${icon('alert')}</div>
    <b>That didn't load</b>
    <p class="muted">${err?.message || 'Something went wrong.'}${err?.ref ? ` Reference ${err.ref}.` : ''}</p>
    ${retry ? html`<button type="button" class="btn" data-retry>${icon('refresh')}Try again</button>` : ''}
  </div></div>`;
}
