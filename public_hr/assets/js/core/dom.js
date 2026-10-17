// Safe HTML templating. Everything interpolated into html`` is escaped unless
// it is itself the result of html`` or raw(). Under the strict CSP there are
// no inline styles: dynamic sizes are applied by applyDyn() after mounting.

export class Raw {
  constructor(s) { this.s = s; }
  toString() { return this.s; }
}

export const raw = (s) => new Raw(String(s ?? ''));

const MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => MAP[c]);

function part(v) {
  if (v instanceof Raw) return v.s;
  if (Array.isArray(v)) return v.map(part).join('');
  if (v === null || v === undefined || v === false) return '';
  return esc(v);
}

export function html(strings, ...vals) {
  let out = strings[0];
  for (let i = 0; i < vals.length; i++) out += part(vals[i]) + strings[i + 1];
  return new Raw(out);
}

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

export function mount(el, view) {
  el.innerHTML = view instanceof Raw ? view.s : esc(view);
  applyDyn(el);
  return el;
}

/** data-w="42" → width 42% (CSSOM, allowed under CSP). data-pos="30" → left 30%. */
export function applyDyn(root) {
  $$('[data-w]', root).forEach((el) => { el.style.width = clamp(el.dataset.w) + '%'; });
  $$('[data-pos]', root).forEach((el) => { el.style.left = clamp(el.dataset.pos) + '%'; });
}
const clamp = (v) => Math.max(0, Math.min(100, Number(v) || 0));

export function icon(name, cls = '') {
  const safe = String(name || '').replace(/[^a-z-]/g, '');
  return raw(`<svg class="i ${cls}" aria-hidden="true" focusable="false"><use href="#i-${safe}"/></svg>`);
}

/** Delegated event listener. */
export function on(root, event, selector, handler) {
  root.addEventListener(event, (e) => {
    const t = e.target.closest(selector);
    if (t && root.contains(t)) handler(e, t);
  });
}

/** Read a form's named fields into an object (trimmed strings; checkboxes as booleans). */
export function formData(form) {
  const out = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled) continue;
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
    else out[el.name] = el.value.trim();
  }
  return out;
}

/** Show server field errors next to fields; returns true if any were shown. */
export function showFieldErrors(form, fields) {
  $$('.field.has-err', form).forEach((f) => f.classList.remove('has-err'));
  $$('[aria-invalid]', form).forEach((x) => x.removeAttribute('aria-invalid'));
  if (!fields) return false;
  let first = null;
  for (const [name, msg] of Object.entries(fields)) {
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    const field = input?.closest('.field');
    if (!field) continue;
    field.classList.add('has-err');
    const err = $('.err', field);
    if (err) {
      err.textContent = msg;
      err.id ||= `err-${name.replace(/[^\w-]/g, '_')}`;
      if (input.type !== 'hidden') input.setAttribute('aria-describedby', err.id);
    }
    if (input.type !== 'hidden') input.setAttribute('aria-invalid', 'true');
    first ??= input.type === 'hidden' ? field.querySelector('button, input:not([type=hidden])') : input;
  }
  first?.focus();
  return !!first;
}

export function initials(name) {
  const parts = String(name || '').trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}
