/**
 * Simple view — shared UI (loaded by app/Views/partials/simple_sidebar.php, so
 * it runs on every page an admin in Simple view opens, including full-CRM pages).
 *
 *   SimpleUI.confirm({title, lines, details, confirmText, tone, input}) → Promise<{ok, value}>
 *   SimpleUI.toast(message, 'success' | 'error')
 *   SimpleUI.post(url, fields)        form POST (csrf_token added) → JSON
 *   SimpleUI.postJson(url, object)    JSON POST (X-CSRF-Token header) → JSON
 *
 * The guard at the bottom puts a plain-English confirmation in front of every
 * approval / irreversible form on full-CRM pages (and replaces their terse
 * browser confirm() popups), so nothing happens without a clear "Yes".
 *
 * Styles are self-contained (injected CSS), so this looks the same on every
 * page whatever its Tailwind config.
 */
(function () {
  'use strict';
  if (window.SimpleUI) return;

  var CSRF = (document.querySelector('meta[name="simple-csrf"]') || {}).content || window.SIMPLE_CSRF || '';

  var css = '' +
    '.sui-ov{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(2px);z-index:9998;display:flex;align-items:center;justify-content:center;padding:24px;animation:suiFade .15s ease-out}' +
    '.sui-box{background:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.25);width:100%;max-width:480px;font-family:Inter,system-ui,sans-serif;color:#0f172a;animation:suiPop .18s ease-out;overflow:hidden}' +
    '.sui-head{display:flex;gap:14px;align-items:flex-start;padding:24px 24px 8px}' +
    '.sui-ic{flex:none;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-family:"Material Symbols Outlined";font-size:24px}' +
    '.sui-ic.primary{background:#e8edf9;color:#163274}.sui-ic.danger{background:#fdecec;color:#c62828}.sui-ic.ok{background:#e7f6ee;color:#15803d}' +
    '.sui-title{font-family:Manrope,Inter,sans-serif;font-weight:800;font-size:19px;line-height:1.3;margin:2px 0 0}' +
    '.sui-body{padding:4px 24px 8px 82px;font-size:15px;line-height:1.55;color:#334155}' +
    '.sui-body ul{margin:8px 0 0;padding-left:20px;list-style:disc}.sui-body li{margin:4px 0}' +
    '.sui-det{white-space:pre-line;margin:10px 0 0;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;color:#0f172a}' +
    '.sui-lab{display:block;font-size:14px;font-weight:600;color:#0f172a;margin:14px 0 6px}' +
    '.sui-in{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:15px;font-family:inherit;resize:vertical}' +
    '.sui-in:focus{outline:none;border-color:#163274;box-shadow:0 0 0 3px rgba(22,50,116,.15)}' +
    '.sui-err{color:#c62828;font-size:13px;margin-top:6px;display:none}' +
    '.sui-foot{display:flex;justify-content:flex-end;gap:10px;padding:18px 24px 22px}' +
    '.sui-btn{border:0;border-radius:10px;padding:11px 18px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}' +
    '.sui-btn.ghost{background:#f1f5f9;color:#334155}.sui-btn.ghost:hover{background:#e2e8f0}' +
    '.sui-btn.primary{background:#163274;color:#fff}.sui-btn.primary:hover{background:#0f2557}' +
    '.sui-btn.danger{background:#c62828;color:#fff}.sui-btn.danger:hover{background:#a51f1f}' +
    '.sui-btn[disabled]{opacity:.6;cursor:wait}' +
    '.sui-toasts{position:fixed;right:24px;bottom:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;font-family:Inter,system-ui,sans-serif}' +
    '.sui-toast{display:flex;gap:10px;align-items:flex-start;max-width:420px;padding:14px 16px;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,.18);font-size:15px;line-height:1.45;color:#fff;animation:suiPop .2s ease-out}' +
    '.sui-toast.success{background:#15803d}.sui-toast.error{background:#b91c1c}' +
    '.sui-toast .mi{font-family:"Material Symbols Outlined";font-size:22px;line-height:1}' +
    '@keyframes suiFade{from{opacity:0}to{opacity:1}}@keyframes suiPop{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── Confirmation box ──────────────────────────────────────────────────────
  function confirmBox(o) {
    o = o || {};
    var tone = o.tone === 'danger' ? 'danger' : 'primary';
    var icon = o.icon || (tone === 'danger' ? 'warning' : 'help');
    return new Promise(function (resolve) {
      var ov = document.createElement('div');
      ov.className = 'sui-ov';
      ov.setAttribute('role', 'dialog');
      ov.setAttribute('aria-modal', 'true');
      var lines = (o.lines || []).map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('');
      var input = '';
      if (o.input) {
        input = '<label class="sui-lab" for="sui-in">' + esc(o.input.label) + '</label>' +
          (o.input.multiline
            ? '<textarea id="sui-in" class="sui-in" rows="3" placeholder="' + esc(o.input.placeholder || '') + '"></textarea>'
            : '<input id="sui-in" class="sui-in" type="text" placeholder="' + esc(o.input.placeholder || '') + '" value="' + esc(o.input.value || '') + '">') +
          '<div class="sui-err" id="sui-err">' + esc(o.input.requiredText || 'Please fill this in.') + '</div>';
      }
      ov.innerHTML =
        '<div class="sui-box">' +
          '<div class="sui-head"><div class="sui-ic ' + tone + '">' + esc(icon) + '</div>' +
          '<h2 class="sui-title">' + esc(o.title || 'Are you sure?') + '</h2></div>' +
          '<div class="sui-body">' +
            (o.text ? '<div>' + esc(o.text) + '</div>' : '') +
            (o.details ? '<div class="sui-det">' + esc(o.details) + '</div>' : '') +
            (lines ? '<ul>' + lines + '</ul>' : '') +
            input +
          '</div>' +
          '<div class="sui-foot">' +
            '<button type="button" class="sui-btn ghost" data-x="no">' + esc(o.cancelText || 'No, go back') + '</button>' +
            '<button type="button" class="sui-btn ' + tone + '" data-x="yes">' + esc(o.confirmText || 'Yes, continue') + '</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      var inEl = ov.querySelector('#sui-in');
      var yes = ov.querySelector('[data-x="yes"]');
      (inEl || yes).focus();

      function close(ok) {
        var v = inEl ? inEl.value.trim() : '';
        if (ok && o.input && ((o.input.required && !v) || v.length < (o.input.minLength || 0))) {
          ov.querySelector('#sui-err').style.display = 'block';
          inEl.focus();
          return;
        }
        document.removeEventListener('keydown', onKey, true);
        ov.remove();
        resolve({ ok: ok, value: v });
      }
      function onKey(e) {
        // Keys belong to the box while it is open: don't let them reach the page
        // (e.g. Escape would also close the "Add a person" form underneath).
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); return; }
        if (e.key !== 'Enter') return;
        var a = document.activeElement;
        if (a && a.tagName === 'BUTTON' && ov.contains(a)) return;   // Enter presses the focused button itself
        if (a === inEl && inEl.tagName === 'TEXTAREA') return;        // new line in a note
        e.preventDefault(); e.stopPropagation(); close(true);
      }
      document.addEventListener('keydown', onKey, true);
      ov.addEventListener('click', function (e) {
        if (e.target === ov) close(false);
        var x = e.target.closest('[data-x]');
        if (x) close(x.getAttribute('data-x') === 'yes');
      });
    });
  }

  // ── Toasts ────────────────────────────────────────────────────────────────
  function toast(msg, type) {
    type = type === 'error' ? 'error' : 'success';
    var wrap = document.querySelector('.sui-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'sui-toasts'; document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'sui-toast ' + type;
    t.setAttribute('role', 'status');
    t.innerHTML = '<span class="mi">' + (type === 'error' ? 'error' : 'check_circle') + '</span><span>' + esc(msg) + '</span>';
    wrap.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, type === 'error' ? 8000 : 4500);
  }

  // ── Requests ──────────────────────────────────────────────────────────────
  var NET_ERR = 'That didn\'t go through — please check the internet connection and try again. Nothing was changed.';

  function handle(res) {
    // Not signed in any more (session ended / account changed): the server redirects to /login
    if (res.redirected && /\/login/.test(res.url)) {
      return Promise.resolve({ success: false, error: 'You have been signed out. Please refresh the page and sign in again — nothing was changed.' });
    }
    return res.text().then(function (txt) {
      var data = null;
      try { data = JSON.parse(txt); } catch (e) { /* not JSON */ }
      if (data && typeof data === 'object') {
        if (data.success === false && !data.error && data.message) data.error = data.message;
        return data;
      }
      if (res.status === 403) return { success: false, error: 'The page has expired or the network blocked it. Please refresh the page and try again. Nothing was changed.' };
      // A server error may have happened half-way, so don't promise nothing changed
      if (res.status >= 500) return { success: false, error: 'Something went wrong on the server. Please refresh the page to see whether it went through before trying again.' };
      return { success: false, error: NET_ERR };
    });
  }

  function post(url, fields) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(handle, function () { return { success: false, error: NET_ERR }; });
  }

  function postJson(url, obj) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(obj || {})
    }).then(handle, function () { return { success: false, error: NET_ERR }; });
  }

  window.SimpleUI = { confirm: confirmBox, toast: toast, post: post, postJson: postJson, esc: esc, csrf: CSRF };

  // ── Guard for full-CRM pages ──────────────────────────────────────────────
  // Plain-English confirmation in front of approvals and irreversible actions.
  var RULES = [
    { re: /\/transactions\/\d+\/approve$/, title: 'Approve this booking?', confirmText: 'Yes, approve',
      lines: ['It will count in sales and in the agent\'s numbers.', 'It gets locked — the agent can no longer change it.', 'This can\'t be undone from here.'] },
    { re: /\/transactions\/\d+\/void$/, title: 'Cancel (void) this booking?', confirmText: 'Yes, void it', tone: 'danger',
      lines: ['It is removed from sales and profit.', 'A reversal entry is recorded so the books stay correct.', 'This can\'t be undone.'] },
    { re: /\/transactions\/\d+\/refund$/, title: 'Record this refund?', confirmText: 'Yes, record refund', tone: 'danger',
      lines: ['The refunded amount is taken off this booking\'s profit.', 'Make sure the money was actually returned to the customer\'s card.', 'This can\'t be undone from here.'] },
    { re: /\/emails\/message\/\d+\/approve$/, title: 'Send this email to the customer?', confirmText: 'Yes, send it',
      lines: ['It goes out right away from the Base Fare mailbox.', 'Once sent, it can\'t be called back.'] },
    { re: /\/emails\/message\/\d+\/reject$/, title: 'Send this email back to the agent?', confirmText: 'Yes, send it back',
      lines: ['The customer will not receive it.', 'The agent sees your note and can write a new one.'] },
    { re: /\/invoices\/\d+\/approve$/, title: 'Approve and email this invoice?', confirmText: 'Yes, approve & send',
      lines: ['The invoice is emailed to the customer right away.', 'Once sent, it can\'t be called back.'] },
    { re: /\/invoices\/\d+\/reject$/, title: 'Send this invoice back to the agent?', confirmText: 'Yes, send it back',
      lines: ['The customer will not receive it.', 'The agent sees your note and can fix it.'] },
    { re: /\/invoices\/\d+\/send$/, title: 'Email this invoice to the customer?', confirmText: 'Yes, send it',
      lines: ['It goes out right away and can\'t be called back.'] },
    { re: /\/etickets\/\d+\/send$/, title: 'Email this e-ticket to the customer?', confirmText: 'Yes, send it',
      lines: ['It goes out right away and can\'t be called back.'] }
    // Not here on purpose: acceptance resend/cancel and user removal are fetch()
    // calls with their own browser confirm(), not form posts, so a form rule
    // would never fire.
  ];

  function ruleFor(form) {
    var action = form.getAttribute('action') || '';
    try { action = new URL(action, location.href).pathname; } catch (e) { /* keep raw */ }
    for (var i = 0; i < RULES.length; i++) if (RULES[i].re.test(action)) return RULES[i];
    return null;
  }

  // Capture phase: runs before the page's own onsubmit handlers (their terse
  // confirm() and their double-click guards), so after "Yes" this does the
  // double-click guarding itself: the form is marked as sending, its buttons
  // are disabled, and any further submit of it is swallowed.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post') return;
    var rule = ruleFor(form);
    if (!rule) return;

    e.preventDefault();
    e.stopImmediatePropagation();
    if (form.dataset.suiSending === '1') return;     // already confirmed and on its way
    var submitter = e.submitter;

    confirmBox({ title: rule.title, lines: rule.lines, confirmText: rule.confirmText, tone: rule.tone }).then(function (r) {
      if (!r.ok || form.dataset.suiSending === '1') return;
      form.dataset.suiSending = '1';
      if (submitter && submitter.name) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
        form.appendChild(h);
      }
      form.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.disabled = true; b.style.opacity = '.6'; });
      if (submitter && !form.contains(submitter)) { submitter.disabled = true; }
      HTMLFormElement.prototype.submit.call(form);   // no second submit event / confirm()
    });
  }, true);

  // Coming back with the browser's Back button restores the page as it was:
  // let the forms be used again.
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    document.querySelectorAll('form[data-sui-sending]').forEach(function (f) {
      delete f.dataset.suiSending;
      f.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.disabled = false; b.style.opacity = ''; });
    });
  });
})();
