/**
 * buddy-widget.js — Aisha, the AI work buddy, on every CRM page.
 *
 * Self-contained: builds its own DOM + injects its own CSS (no Tailwind
 * dependency, no framework). Loaded with `defer` on every authed view (same
 * mass-include pattern as error-beacon.js). On /buddy it mounts inline as a
 * full page instead of floating (the page provides #aisha-page).
 *
 * FAIL-INVISIBLE CONTRACT: the widget renders NOTHING unless GET /buddy/boot
 * returns valid JSON with ok:true. Logged-out pages, customer-facing pages,
 * the TV board (PIN session, no user), or any server hiccup → no orb, no
 * errors, no broken page. The widget can only ever add itself on top of a
 * working page.
 *
 * v2 (23 Oct 2026) — rebuilt from the usage report, a 50-question exam and a
 * research pass on in-app assistants:
 *  - light theme in the CRM's own colours, 16px text, 44px controls;
 *  - the Tonight card: the shift at a glance, straight from the CRM (no AI),
 *    every number a link to the screen it came from;
 *  - starter chips on an empty chat, follow-up chips after each answer;
 *  - nudges carry a tag and the button that does what they ask ("Create the
 *    e-ticket" opens that booking's e-ticket form, filled in), plus Done;
 *    reminders get Snooze;
 *  - quiet mode (1 hour / rest of the shift) — only reminders and messages a
 *    person wrote get through;
 *  - per-message thumbs with a reason, copy, timestamps, day dividers;
 *  - "Thinking… 4s" with a Stop button, honest notices when she can't
 *    answer, and a Try again;
 *  - "What Aisha knows" — everything she remembers, each item forgettable;
 *  - keyboard: Alt+A opens/closes, Esc closes and hands focus back; the panel
 *    is a labelled dialog, the transcript a polite live log; focus is never
 *    pulled out of the page after an answer; no endless animation, and none
 *    at all under prefers-reduced-motion;
 *  - nudges are no longer spoken aloud (the greeting still is, per the
 *    client's spec), so she never talks over a customer call.
 *
 * Endpoints (same-origin; CSRF via header from /buddy/boot):
 *   GET  /buddy/boot     → {ok, csrf, name, admin, role, mode, nudges, quiet_until, voice, ai, starters}
 *   GET  /buddy/history  → {messages:[{id, role, content, at, kind, tag?, action?, nudge_id?}], starters}
 *   GET  /buddy/today    → the Tonight card
 *   POST /buddy/greeting → once-per-arrival greeting (server decides)
 *   POST /buddy/chat     → {reply, ai, message_id, suggestions, retry, budget}
 *   POST /buddy/feed     → {messages, pending_left, hold_seconds?, next_reminder_in?} — Aisha initiates
 *   POST /buddy/feedback → {message_id, value, reason?}
 *   POST /buddy/settings → {quiet?, voice?}
 *   GET  /buddy/memory   · POST /buddy/memory/forget
 *   POST /buddy/nudge/{id}/{done|snooze|open} · POST /buddy/new · POST /buddy/event
 */
(function () {
  'use strict';
  if (window.__buddyWidget) return;   // idempotent under double-include
  window.__buddyWidget = true;

  var CSRF = null;

  fetch('/buddy/boot', { headers: { 'Accept': 'application/json' } })
    .then(function (r) {
      if (!r.ok) throw 0;
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('json') === -1) throw 0;   // gate redirect → HTML → hide
      return r.json();
    })
    .then(function (d) {
      if (!d || !d.ok || !d.csrf) return;
      CSRF = d.csrf;
      mount(d);
    })
    .catch(function () { /* no session / gated / error → stay invisible */ });

  // ── tiny helpers ─────────────────────────────────────────────────────────
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function post(url, body, opts) {
    return fetch(url, Object.assign({
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
      body: JSON.stringify(body || {}),
    }, opts || {}));
  }
  function getJson(url) {
    return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (!r.ok || ct.indexOf('json') === -1) throw 0;
      return r.json();
    });
  }
  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function stored(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function parseAt(at) {
    if (!at) return new Date();
    var d = new Date(String(at).replace(' ', 'T'));
    return isNaN(d.getTime()) ? new Date() : d;
  }
  // en-US on purpose: the CRM writes times as "6:00 PM" everywhere, whatever
  // locale the browser happens to be set to.
  function clock(d) {
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
  function dayLabel(d) {
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var that = new Date(d.getTime()); that.setHours(0, 0, 0, 0);
    var diff = Math.round((today - that) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
  }
  function money(v) {
    var n = Number(v) || 0;
    var s = Math.abs(n) >= 1000 ? Math.round(Math.abs(n)).toLocaleString('en-US') : Math.abs(n).toFixed(0);
    return (n < 0 ? '−$' : '$') + s;
  }
  var ICON = {
    spark: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5l1.9 4.6 4.6 1.9-4.6 1.9L12 16.5l-1.9-4.6L5.5 10l4.6-1.9L12 3.5Z" fill="currentColor"/><path d="M18.5 15.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8.8-1.9Z" fill="currentColor" opacity=".75"/></svg>',
    moon: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    voiceOn: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    voiceOff: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m16 9.5 5 5m0-5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    more: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="5.5" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="18.5" cy="12" r="1.7" fill="currentColor"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6.5 6.5 11 11m0-11-11 11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    send: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    up: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7.5 11v8.5H5a1 1 0 0 1-1-1V12a1 1 0 0 1 1-1h2.5Zm0 0 3.6-6.4a1.6 1.6 0 0 1 3 .9l-.6 3.5h5a1.8 1.8 0 0 1 1.8 2.1l-1.2 6.4a2.2 2.2 0 0 1-2.2 1.9H7.5" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    copy: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="2.2" stroke="currentColor" stroke-width="1.7"/><path d="M15.5 8.5V6.2a1.7 1.7 0 0 0-1.7-1.7H6.2a1.7 1.7 0 0 0-1.7 1.7v7.6c0 .94.76 1.7 1.7 1.7h2.3" stroke="currentColor" stroke-width="1.7"/></svg>',
    mic: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    chev: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    back: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 6 9 12l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  };

  function mount(boot) {
    injectCss();

    var PAGE = document.getElementById('aisha-page');   // full-page mode on /buddy
    var MODE = boot.mode === 'admin' ? 'admin' : 'agent';
    var SELLS = MODE === 'agent' && (boot.role === 'agent' || boot.role === 'supervisor');
    var EP = MODE === 'admin'
      ? { history: '/buddy/admin/history', chat: '/buddy/admin/chat', greeting: '/buddy/admin/greeting',
          feed: null, confirm: '/buddy/admin/confirm-action', cancel: '/buddy/admin/cancel-action' }
      : { history: '/buddy/history', chat: '/buddy/chat', greeting: '/buddy/greeting',
          feed: '/buddy/feed', confirm: null, cancel: null };
    var MAXLEN = 800;
    var firstName = String(boot.name || '').trim().split(/\s+/)[0] || '';

    var opened = !!PAGE, loaded = false, busy = false, abortCtl = null;
    var quietUntil = boot.quiet_until ? new Date(boot.quiet_until) : null;
    var aiStatus = boot.ai === 'basic' ? 'basic' : 'ok';
    var starters = boot.starters || [];

    function logEvent(name, detail) {
      try { post('/buddy/event', { event: name, detail: detail || '' }, { keepalive: true }).catch(function () {}); } catch (e) {}
    }

    // ── Orb ─────────────────────────────────────────────────────────────────
    var orb = null, badge = null, nudgeCount = 0;
    if (!PAGE) {
      orb = el('button', 'aw-orb');
      orb.type = 'button';
      orb.setAttribute('aria-label', 'Open Aisha, your AI work buddy (Alt+A)');
      orb.setAttribute('aria-expanded', 'false');
      orb.setAttribute('aria-controls', 'aw-panel');
      orb.innerHTML = '<span class="aw-orb-core">' + ICON.spark + '</span><span class="aw-orb-ring" aria-hidden="true"></span>';
      badge = el('span', 'aw-badge');
      badge.setAttribute('aria-hidden', 'true');
      document.body.appendChild(orb);
    }
    function setBadge(n) {
      nudgeCount = Math.max(0, n | 0);
      if (!orb) return;
      if (nudgeCount > 0) {
        badge.textContent = nudgeCount > 9 ? '9+' : String(nudgeCount);
        if (!badge.parentNode) orb.appendChild(badge);
        orb.setAttribute('aria-label', 'Open Aisha — ' + nudgeCount + ' new (Alt+A)');
      } else {
        if (badge.parentNode) badge.parentNode.removeChild(badge);
        orb.setAttribute('aria-label', 'Open Aisha, your AI work buddy (Alt+A)');
      }
    }
    function orbState(s) {
      if (!orb) return;
      orb.classList.toggle('aw-orb-thinking', s === 'thinking');
      orb.classList.toggle('aw-orb-quiet', isQuiet());
    }
    setBadge(boot.nudges | 0);

    // Drag the orb anywhere (John, 22 Oct: "it comes over things"). Press and
    // drag to move it; a plain tap opens the chat. The spot is remembered per
    // browser as a share of the window, so it survives resizes.
    var ORB = 56, EDGE = 12;
    function clampPos(x, y) {
      return [Math.min(Math.max(EDGE, x), window.innerWidth - ORB - EDGE),
              Math.min(Math.max(EDGE, y), window.innerHeight - ORB - EDGE)];
    }
    function placeOrb(x, y) {
      var c = clampPos(x, y);
      orb.style.left = c[0] + 'px'; orb.style.top = c[1] + 'px';
      orb.style.right = 'auto'; orb.style.bottom = 'auto';
    }
    function applySaved() {
      if (!orb) return;
      try {
        var v = JSON.parse(stored('bwOrbPos') || 'null');
        if (v && typeof v.fx === 'number' && typeof v.fy === 'number') placeOrb(v.fx * window.innerWidth, v.fy * window.innerHeight);
      } catch (e) {}
    }
    if (orb) {
      applySaved();
      window.addEventListener('resize', function () { applySaved(); if (opened) placePanel(); if (toast && toastShown) placeNear(toast, true); });
      var drag = null;
      orb.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        var r = orb.getBoundingClientRect();
        drag = { sx: e.clientX, sy: e.clientY, ox: r.left, oy: r.top, moved: false, id: e.pointerId };
      });
      window.addEventListener('pointermove', function (e) {
        if (!drag || e.pointerId !== drag.id) return;
        var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
        if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 6) return;
        if (!drag.moved) { drag.moved = true; orb.classList.add('aw-orb-drag'); try { orb.setPointerCapture(drag.id); } catch (er) {} }
        placeOrb(drag.ox + dx, drag.oy + dy);
        e.preventDefault();
      });
      var endDrag = function () {
        if (!drag) return;
        if (drag.moved) {
          orb.__dragged = true;   // swallow the click that follows a drag
          orb.classList.remove('aw-orb-drag');
          var r = orb.getBoundingClientRect();
          store('bwOrbPos', JSON.stringify({ fx: r.left / window.innerWidth, fy: r.top / window.innerHeight }));
          if (toast && toastShown) placeNear(toast, true);
        }
        drag = null;
      };
      window.addEventListener('pointerup', endDrag);
      window.addEventListener('pointercancel', endDrag);
    }
    // Open a box next to the orb, growing toward the side with room and never
    // past the window edge. `clear` puts it above/below the orb, not over it.
    function placeNear(box, clear) {
      if (!orb) return;
      var r = orb.getBoundingClientRect();
      if (!orb.style.left) { box.style.left = box.style.top = box.style.right = box.style.bottom = ''; return; }
      var w = box.offsetWidth, h = box.offsetHeight;
      var right = r.left + r.width / 2 > window.innerWidth / 2;
      var below = r.top + r.height / 2 > window.innerHeight / 2;
      var x = right ? r.right - w : r.left;
      var y = clear ? (below ? r.top - 12 - h : r.bottom + 12) : (below ? r.bottom - h : r.top);
      x = Math.min(Math.max(EDGE, x), window.innerWidth - w - EDGE);
      y = Math.min(Math.max(EDGE, y), window.innerHeight - h - EDGE);
      box.style.left = x + 'px'; box.style.top = y + 'px';
      box.style.right = 'auto'; box.style.bottom = 'auto';
    }
    function narrow() { return window.innerWidth < 640; }
    function placePanel() {
      if (PAGE) return;
      if (narrow()) { panel.style.left = panel.style.top = panel.style.right = panel.style.bottom = ''; return; }
      placeNear(panel);
    }

    // ── Panel ───────────────────────────────────────────────────────────────
    var panel = el('section', 'aw-panel' + (PAGE ? ' aw-page' : ''));
    panel.id = 'aw-panel';
    panel.setAttribute('role', PAGE ? 'region' : 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', 'aw-title');
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    var MIC = MODE === 'admin' && !!SR;
    panel.innerHTML =
      '<header class="aw-head">' +
        '<span class="aw-ava" aria-hidden="true">' + ICON.spark + '</span>' +
        '<div class="aw-id"><h2 class="aw-title" id="aw-title">Aisha' + (MODE === 'admin' ? ' <span class="aw-chip-mode">Super</span>' : '') + '</h2>' +
        '<p class="aw-status"><span class="aw-dot" aria-hidden="true"></span><span class="aw-status-txt"></span></p></div>' +
        '<div class="aw-tools">' +
          (MODE === 'agent' ? '<button type="button" class="aw-icon aw-quiet" aria-haspopup="menu" aria-expanded="false" title="Quiet mode" aria-label="Quiet mode">' + ICON.moon + '</button>' : '') +
          '<button type="button" class="aw-icon aw-voice" aria-pressed="true" title="Aisha speaks her greeting" aria-label="Voice">' + ICON.voiceOn + '</button>' +
          '<button type="button" class="aw-icon aw-more" aria-haspopup="menu" aria-expanded="false" title="More" aria-label="More options">' + ICON.more + '</button>' +
          (PAGE ? '' : '<button type="button" class="aw-icon aw-close" title="Close (Esc)" aria-label="Close Aisha">' + ICON.close + '</button>') +
        '</div>' +
      '</header>' +
      '<div class="aw-menu aw-menu-quiet" role="menu" hidden></div>' +
      '<div class="aw-menu aw-menu-more" role="menu" hidden></div>' +
      (SELLS ? '<div class="aw-card" hidden></div>' : '') +
      '<div class="aw-body">' +
        '<div class="aw-log" role="log" aria-live="polite" aria-relevant="additions" tabindex="-1"></div>' +
        '<div class="aw-mem" hidden></div>' +
      '</div>' +
      '<p class="aw-sr" aria-live="polite"></p>' +
      '<form class="aw-compose">' +
        (MIC ? '<button type="button" class="aw-icon aw-mic" aria-label="Talk to Aisha" title="Talk to Aisha">' + ICON.mic + '</button>' : '') +
        '<textarea class="aw-input" rows="1" maxlength="' + MAXLEN + '" aria-label="Message Aisha" placeholder="' +
          (MODE === 'admin' ? 'Ask about anyone on the team…' : 'Message Aisha…') + '"></textarea>' +
        '<button type="submit" class="aw-send" aria-label="Send" disabled>' + ICON.send + '</button>' +
      '</form>';
    (PAGE || document.body).appendChild(panel);

    var log = panel.querySelector('.aw-log');
    var mem = panel.querySelector('.aw-mem');
    var card = panel.querySelector('.aw-card');
    var input = panel.querySelector('.aw-input');
    var sendBtn = panel.querySelector('.aw-send');
    var statusTxt = panel.querySelector('.aw-status-txt');
    var srLine = panel.querySelector('.aw-sr');
    var quietBtn = panel.querySelector('.aw-quiet');
    var voiceBtn = panel.querySelector('.aw-voice');
    var moreBtn = panel.querySelector('.aw-more');
    var quietMenu = panel.querySelector('.aw-menu-quiet');
    var moreMenu = panel.querySelector('.aw-menu-more');

    if (!PAGE) { panel.setAttribute('inert', ''); panel.setAttribute('aria-hidden', 'true'); }
    else { panel.classList.add('aw-open'); }

    function isQuiet() { return !!(quietUntil && quietUntil.getTime() > Date.now()); }
    function setStatus(text) {
      var t = text;
      if (!t) {
        if (aiStatus === 'basic') t = 'Basic mode until 6 PM';
        else if (isQuiet()) t = 'Quiet until ' + clock(quietUntil) + ' · reminders still on';
        else t = MODE === 'admin' ? 'Your assistant · sees the whole team' : 'Your AI work buddy';
      }
      statusTxt.textContent = t;
      panel.classList.toggle('aw-is-quiet', isQuiet());
      panel.classList.toggle('aw-is-basic', aiStatus === 'basic');
      if (quietBtn) quietBtn.classList.toggle('aw-on', isQuiet());
      orbState(busy ? 'thinking' : '');
    }
    setStatus();

    // ── Open / close ────────────────────────────────────────────────────────
    function openPanel(viaUser) {
      if (PAGE || opened) return;
      opened = true;
      panel.removeAttribute('inert');
      panel.removeAttribute('aria-hidden');
      placePanel();
      panel.classList.add('aw-open');
      orb.classList.add('aw-orb-hidden');
      orb.setAttribute('aria-expanded', 'true');
      hideToast();
      setBadge(0);
      if (!loaded && !loading) load(); else if (loaded) { refreshCard(); scrollEnd(); }
      feedNow();
      if (viaUser) setTimeout(function () { input.focus(); }, 60);
      logEvent('open');
    }
    function closePanel() {
      if (PAGE || !opened) return;
      var hadFocus = panel.contains(document.activeElement);
      opened = false;
      closeMenus();
      panel.classList.remove('aw-open');
      panel.setAttribute('inert', '');
      panel.setAttribute('aria-hidden', 'true');
      orb.classList.remove('aw-orb-hidden');
      orb.setAttribute('aria-expanded', 'false');
      if (hadFocus) orb.focus();
      logEvent('close');
    }
    if (orb) {
      orb.addEventListener('click', function () {
        if (orb.__dragged) { orb.__dragged = false; return; }
        if (opened) closePanel(); else openPanel(true);
      });
    }
    var closeBtn = panel.querySelector('.aw-close');
    if (closeBtn) closeBtn.addEventListener('click', closePanel);

    document.addEventListener('keydown', function (e) {
      if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'a' || e.key === 'A' || e.code === 'KeyA')) {
        e.preventDefault();
        if (PAGE) { input.focus(); return; }
        if (opened) closePanel(); else openPanel(true);
        return;
      }
      if (e.key === 'Escape') {
        if (!quietMenu.hidden || !moreMenu.hidden) { closeMenus(); return; }
        if (speaking) { stopSpeaking(); speechDone(); return; }
        if (opened && !PAGE && (panel.contains(document.activeElement) || document.activeElement === document.body)) closePanel();
      }
    });

    // ── Menus ───────────────────────────────────────────────────────────────
    function closeMenus() {
      [quietMenu, moreMenu].forEach(function (m) { m.hidden = true; });
      if (quietBtn) quietBtn.setAttribute('aria-expanded', 'false');
      moreBtn.setAttribute('aria-expanded', 'false');
    }
    function menuItem(menu, label, hint, fn) {
      var b = el('button', 'aw-menu-item');
      b.type = 'button';
      b.setAttribute('role', 'menuitem');
      b.appendChild(el('span', 'aw-menu-label', label));
      if (hint) b.appendChild(el('span', 'aw-menu-hint', hint));
      b.addEventListener('click', function () { closeMenus(); fn(); });
      menu.appendChild(b);
      return b;
    }
    function openMenu(menu, btn, build) {
      var wasOpen = !menu.hidden;
      closeMenus();
      if (wasOpen) return;
      menu.innerHTML = '';
      build(menu);
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      var first = menu.querySelector('button');
      if (first) first.focus();
    }
    document.addEventListener('pointerdown', function (e) {
      if (!quietMenu.contains(e.target) && !moreMenu.contains(e.target) &&
          e.target !== quietBtn && e.target !== moreBtn &&
          !(quietBtn && quietBtn.contains(e.target)) && !moreBtn.contains(e.target)) closeMenus();
    }, true);

    if (quietBtn) {
      quietBtn.addEventListener('click', function () {
        openMenu(quietMenu, quietBtn, function (m) {
          m.appendChild(el('p', 'aw-menu-note', 'No pop-ups from Aisha. Reminders you set and messages from a person still come through.'));
          menuItem(m, 'Quiet for 1 hour', '', function () { setQuiet(60); });
          menuItem(m, 'Quiet until the shift ends', '6 PM', function () { setQuiet('shift'); });
          if (isQuiet()) menuItem(m, 'Turn quiet off', 'until ' + clock(quietUntil), function () { setQuiet('off'); });
        });
      });
    }
    function setQuiet(v) {
      post('/buddy/settings', { quiet: v }).then(function (r) { return r.json(); }).then(function (d) {
        quietUntil = d && d.quiet_until ? new Date(d.quiet_until) : null;
        setStatus();
        notice(isQuiet() ? 'Quiet mode on until ' + clock(quietUntil) + '. Your reminders still come through.' : 'Quiet mode off.', 'info');
        if (!isQuiet()) feedNow();
      }).catch(function () { notice('Could not change quiet mode — try again.', 'error'); });
    }

    moreBtn.addEventListener('click', function () {
      openMenu(moreMenu, moreBtn, function (m) {
        if (MODE === 'agent') menuItem(m, 'What Aisha knows about you', '', showMemory);
        menuItem(m, 'Start a new chat', '', newChat);
        if (!PAGE) menuItem(m, 'Open as a full page', '', function () { window.location.href = '/buddy'; });
        if (boot.admin) menuItem(m, 'Maintenance buddy', 'system health', function () { window.location.href = '/buddy/maintenance'; });
        m.appendChild(el('p', 'aw-menu-note', 'Shortcut: Alt+A opens and closes Aisha.'));
      });
    });

    function newChat() {
      post('/buddy/new', { kind: MODE }).then(function () {
        log.innerHTML = '';
        lastDay = '';
        hideMemory();
        welcome();
        logEvent('new_chat');
      }).catch(function () { notice('Could not start a new chat — try again.', 'error'); });
    }

    // ── Voice ───────────────────────────────────────────────────────────────
    // Default ON (client spec: agents wear headsets, Aisha greets aloud). The
    // greeting is the only thing she says unprompted; nudges stay silent so
    // she never talks over a customer call. The admin's conversation mode
    // speaks replies.
    var voiceOn = boot.voice === false ? false : true;
    var legacy = stored('bwVoiceOn');
    if (legacy !== null) voiceOn = legacy === '1';
    function paintVoice() {
      voiceBtn.innerHTML = voiceOn ? ICON.voiceOn : ICON.voiceOff;
      voiceBtn.setAttribute('aria-pressed', voiceOn ? 'true' : 'false');
      voiceBtn.title = voiceOn ? 'Voice on — click to mute' : 'Voice off — click to turn on';
      voiceBtn.classList.toggle('aw-on', voiceOn);
    }
    paintVoice();
    voiceBtn.addEventListener('click', function () {
      voiceOn = !voiceOn;
      paintVoice();
      store('bwVoiceOn', voiceOn ? '1' : '0');
      post('/buddy/settings', { voice: voiceOn }).catch(function () {});
      if (!voiceOn) { stopSpeaking(); speechDone(); }
    });

    var ttsOk = ('speechSynthesis' in window) && typeof window.SpeechSynthesisUtterance === 'function';
    var serverVoice = null;
    var chosenVoice = null;
    function pickVoice() {
      try {
        var v = window.speechSynthesis.getVoices() || [];
        chosenVoice =
          v.find(function (x) { return /female|zira|aria|jenny|samantha|google uk english female/i.test(x.name) && x.lang.indexOf('en') === 0; }) ||
          v.find(function (x) { return x.lang.indexOf('en') === 0; }) || v[0] || null;
      } catch (e) { chosenVoice = null; }
    }
    if (ttsOk) {
      pickVoice();
      if ('onvoiceschanged' in window.speechSynthesis) window.speechSynthesis.onvoiceschanged = pickVoice;
    }
    var speaking = false, onAishaDoneSpeaking = null, currentAudio = null;
    function speechDone() {
      speaking = false;
      panel.classList.remove('aw-is-speaking');
      if (typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
    }
    /** Barge-in: cut her off mid-sentence, instantly, on every engine. */
    function stopSpeaking() {
      try { if (currentAudio) { currentAudio.onended = null; currentAudio.pause(); } } catch (e) {}
      try { if (ttsOk) window.speechSynthesis.cancel(); } catch (e) {}
      speaking = false;
      panel.classList.remove('aw-is-speaking');
    }
    function browserSpeak(text) {
      if (!ttsOk) { speechDone(); return; }
      try {
        window.speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(String(text).slice(0, 600));
        if (chosenVoice) u.voice = chosenVoice;
        u.rate = 1.04;
        u.onend = u.onerror = speechDone;
        speaking = true;
        window.speechSynthesis.speak(u);
      } catch (e) { speechDone(); }
    }
    function playUrl(url, fallbackText) {
      try {
        if (currentAudio) { currentAudio.onended = null; currentAudio.pause(); }
        currentAudio = new Audio(url);
        currentAudio.onended = currentAudio.onerror = speechDone;
        currentAudio.play().catch(function () { browserSpeak(fallbackText); });
      } catch (e) { browserSpeak(fallbackText); }
    }
    // Autoplay unlock: browsers block audio before the first gesture on the
    // page. A line she wants to say before that is parked (sessionStorage, so
    // it survives navigation for 10 minutes) and released on the first click
    // or key press.
    var interacted = false, speechQueue = [];
    function parkSpeech(text, kind, audioUrl) {
      try { sessionStorage.setItem('bwPendingSpeech', JSON.stringify({ t: text, k: kind, u: audioUrl || null, at: Date.now() })); } catch (e) {}
    }
    function clearParkedSpeech() { try { sessionStorage.removeItem('bwPendingSpeech'); } catch (e) {} }
    (function unpark() {
      try {
        var raw = sessionStorage.getItem('bwPendingSpeech');
        if (!raw) return;
        var p = JSON.parse(raw);
        if (p && p.t && Date.now() - (p.at || 0) < 600000) speechQueue.push([p.t, p.k, p.u]);
        else clearParkedSpeech();
      } catch (e) {}
    })();
    function markInteracted() {
      if (interacted) return;
      interacted = true;
      if (speechQueue.length) {
        var q = speechQueue[0];
        speechQueue = [];
        clearParkedSpeech();
        speak(q[0], q[1], q[2]);
      }
    }
    document.addEventListener('pointerdown', markInteracted, true);
    document.addEventListener('keydown', markInteracted, true);

    /** @returns true when she WILL speak (the admin conversation waits for the end cue). */
    function speak(text, kind, audioUrl) {
      if (!voiceOn) return false;
      if (kind !== 'greeting' && MODE !== 'admin') return false;
      if (!interacted) {
        if (speechQueue.length < 1) { speechQueue.push([text, kind, audioUrl]); parkSpeech(text, kind, audioUrl); }
        return false;
      }
      clearParkedSpeech();
      stopFiller();
      var payload = plain(text).slice(0, 600);
      speaking = true;
      panel.classList.add('aw-is-speaking');
      if (audioUrl) { playUrl(audioUrl, payload); return true; }
      if (serverVoice === false) { browserSpeak(payload); return true; }
      post('/buddy/tts', { text: payload })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok && d.url) { serverVoice = true; playUrl(d.url, payload); }
          else { serverVoice = false; browserSpeak(payload); }
        })
        .catch(function () { browserSpeak(payload); });
      return true;
    }

    // ── Admin conversation mode (mic) ───────────────────────────────────────
    // One click starts a CONVERSATION: listen → send → she answers aloud →
    // the mic reopens by itself. The mic is closed while she talks (she would
    // hear herself); barge-in is a click. Two silent minutes end it.
    var micBtn = panel.querySelector('.aw-mic');
    var convo = false, listening = false, rec = null, restartTimer = null, lastHeard = 0, convoStartedAt = 0;
    function setMicUi() {
      if (!micBtn) return;
      micBtn.classList.toggle('aw-mic-on', listening);
      micBtn.classList.toggle('aw-mic-convo', convo && !listening);
      micBtn.setAttribute('aria-pressed', convo ? 'true' : 'false');
      micBtn.title = convo ? 'Conversation on — click to end' : 'Talk to Aisha';
    }
    function stopRec() {
      listening = false;
      try { if (rec) { rec.onresult = rec.onend = rec.onerror = null; rec.stop(); } } catch (e) {}
      setMicUi();
    }
    function convoOff() { convo = false; clearTimeout(restartTimer); stopRec(); }
    function startListening() {
      if (!convo || listening || speaking || busy) return;
      try {
        rec = new SR();
        rec.lang = 'en-IN';
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        rec.onresult = function (ev) {
          lastHeard = Date.now();
          var text = (((ev.results || [])[0] || [])[0] || {}).transcript || '';
          stopRec();
          if (text.trim()) { input.value = text.trim(); syncInput(); sendCurrent(); }
          else if (convo) restartTimer = setTimeout(startListening, 300);
        };
        rec.onerror = function (ev) {
          listening = false;
          setMicUi();
          var code = ev && ev.error ? ev.error : 'unknown';
          if (code === 'not-allowed' || code === 'service-not-allowed') {
            convoOff();
            notice('The browser is blocking the microphone for this site. Click the icon left of the address bar → Site settings → allow Microphone, then reload.', 'error');
          }
          if (code === 'audio-capture') {
            convoOff();
            notice("No microphone found — plug one in or check your input settings.", 'error');
          }
        };
        rec.onend = function () {
          listening = false;
          setMicUi();
          if (!convo || speaking || busy) return;
          if (Date.now() - Math.max(lastHeard, convoStartedAt) > 120000) { convoOff(); return; }
          restartTimer = setTimeout(startListening, 350);
        };
        rec.start();
        listening = true;
        setMicUi();
      } catch (e) {
        listening = false;
        convoOff();
        notice('Voice input could not start in this browser — typing still works.', 'error');
      }
    }
    var FILLERS = ['Hmm, give me a few seconds on that.', 'Let me look into that for a moment.', 'One second, checking that for you.'];
    var fillerUrls = [], fillerAudio = null, fillerTimer = null;
    function prefetchFillers() {
      if (fillerUrls.length || !voiceOn) return;
      FILLERS.forEach(function (line) {
        post('/buddy/tts', { text: line }).then(function (r) { return r.json(); })
          .then(function (d) { if (d && d.ok && d.url) fillerUrls.push(d.url); }).catch(function () {});
      });
    }
    function playFiller() {
      if (!convo || !voiceOn || speaking) return;
      try {
        if (fillerUrls.length) { fillerAudio = new Audio(fillerUrls[Math.floor(Math.random() * fillerUrls.length)]); fillerAudio.play().catch(function () {}); }
        else if (ttsOk) { var u = new SpeechSynthesisUtterance(FILLERS[0]); if (chosenVoice) u.voice = chosenVoice; window.speechSynthesis.speak(u); }
      } catch (e) {}
    }
    function stopFiller() {
      clearTimeout(fillerTimer); fillerTimer = null;
      try { if (fillerAudio) { fillerAudio.pause(); fillerAudio = null; } } catch (e) {}
    }
    function armFiller() { if (!convo || !voiceOn) return; stopFiller(); fillerTimer = setTimeout(playFiller, 2500); }
    onAishaDoneSpeaking = function () {
      if (convo) { clearTimeout(restartTimer); restartTimer = setTimeout(startListening, 400); }
    };
    if (micBtn) {
      micBtn.addEventListener('click', function () {
        if (convo) { convoOff(); stopFiller(); return; }
        convo = true; convoStartedAt = Date.now(); lastHeard = 0;
        stopSpeaking(); prefetchFillers(); startListening(); setMicUi();
        logEvent('mic');
      });
    }

    // ── Tonight card ────────────────────────────────────────────────────────
    var cardCollapsed = stored('awCardCollapsed') === '1';
    var cardTimer = null;
    function refreshCard() {
      if (!card) return;
      getJson('/buddy/today').then(renderCard).catch(function () {});
      clearTimeout(cardTimer);
      cardTimer = setTimeout(function () { if (opened) refreshCard(); }, 300000);
    }
    function renderCard(d) {
      if (!d || d.ok === false) return;
      // The card can grow (a reminder line, the goal bar); keep the newest
      // message in view if that is where the agent was.
      var atEnd = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
      setTimeout(function () { if (atEnd) scrollEnd(); }, 0);
      card.innerHTML = '';
      card.hidden = false;
      card.classList.toggle('aw-card-collapsed', cardCollapsed);
      var head = el('button', 'aw-card-head');
      head.type = 'button';
      head.setAttribute('aria-expanded', cardCollapsed ? 'false' : 'true');
      head.innerHTML = '<span class="aw-card-title">Tonight</span>';
      var waiting = (d.etickets_waiting | 0) + (d.auths_waiting | 0);
      var summary = (d.sales | 0) + ' sale' + ((d.sales | 0) === 1 ? '' : 's') + ' · ' + money(d.net_mco) + ' net MCO' +
        (waiting ? ' · ' + waiting + ' waiting' : '');
      head.appendChild(el('span', 'aw-card-sum', cardCollapsed ? summary : (d.shift || '')));
      var chev = el('span', 'aw-card-chev'); chev.innerHTML = ICON.chev; head.appendChild(chev);
      head.addEventListener('click', function () {
        cardCollapsed = !cardCollapsed;
        store('awCardCollapsed', cardCollapsed ? '1' : '0');
        renderCard(d);
      });
      card.appendChild(head);
      if (cardCollapsed) return;

      var stats = el('div', 'aw-stats');
      function stat(value, label, href, tone) {
        var a = el('a', 'aw-stat' + (tone ? ' aw-stat-' + tone : ''));
        a.href = href;
        a.appendChild(el('b', '', value));
        a.appendChild(el('span', '', label));
        a.addEventListener('click', function () { logEvent('card_link', label); });
        stats.appendChild(a);
      }
      stat(String(d.sales | 0), (d.sales | 0) === 1 ? 'sale' : 'sales', '/transactions');
      stat(money(d.net_mco), 'net MCO', '/dashboard', (Number(d.net_mco) < 0 ? 'bad' : ''));
      if ((d.etickets_waiting | 0) > 0) {
        stat(String(d.etickets_waiting | 0), (d.etickets_waiting | 0) === 1 ? 'e-ticket due' : 'e-tickets due', d.etickets_link || '/etickets', 'warn');
      } else if ((d.auths_waiting | 0) > 0) {
        stat(String(d.auths_waiting | 0), 'to record', '/acceptance', 'warn');
      } else {
        stat('0', 'waiting', '/etickets', 'ok');
      }
      card.appendChild(stats);

      if (d.goal) {
        var g = d.goal;
        var pct = g.target > 0 ? Math.min(100, Math.round(g.done / g.target * 100)) : 0;
        var goal = el('div', 'aw-goal');
        var label = g.metric === 'revenue'
          ? money(g.done) + ' of your ' + money(g.target) + ' goal'
          : (g.done | 0) + ' of your ' + (g.target | 0) + '-sale goal';
        goal.appendChild(el('span', 'aw-goal-label', label + (g.on_track ? ' · on track' : ' · ' + g.days_left + ' days left')));
        var bar = el('span', 'aw-goal-bar'); bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-valuenow', String(pct)); bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', '100');
        bar.setAttribute('aria-label', 'Monthly goal progress');
        var fill = el('i'); fill.style.width = pct + '%'; bar.appendChild(fill);
        goal.appendChild(bar);
        card.appendChild(goal);
      }
      if (d.next_reminder) {
        card.appendChild(el('div', 'aw-next-rem', '⏰ ' + d.next_reminder.at + ' — ' + d.next_reminder.what));
      }
    }

    // ── Messages ────────────────────────────────────────────────────────────
    var lastDay = '';
    function scrollEnd() { log.scrollTop = log.scrollHeight; }
    function dayDivider(d) {
      var label = dayLabel(d);
      if (label === lastDay) return;
      lastDay = label;
      var div = el('div', 'aw-day');
      div.appendChild(el('span', '', label));
      log.appendChild(div);
    }
    function clearChips() {
      log.querySelectorAll('.aw-chips').forEach(function (c) { c.parentNode.removeChild(c); });
    }
    function chipsRow(list, kind) {
      clearChips();
      if (!list || !list.length) return;
      var row = el('div', 'aw-chips');
      row.setAttribute('aria-label', kind === 'starter' ? 'Try asking' : 'Suggested follow-ups');
      list.slice(0, 4).forEach(function (t) {
        var c = el('button', 'aw-chip', t);
        c.type = 'button';
        c.addEventListener('click', function () {
          logEvent(kind, t);
          input.value = t;
          syncInput();
          sendCurrent();
        });
        row.appendChild(c);
      });
      log.appendChild(row);
      scrollEnd();
    }

    /**
     * One message bubble. m: {id, role, content, at, kind, tag, action,
     * nudge_id, done, degraded, feedback}.
     */
    function addMsg(m) {
      if (m.id) {
        var seen = log.querySelector('.aw-row[data-id="' + m.id + '"]');
        if (seen) return seen;
      }
      var ai = m.role !== 'user';
      var at = parseAt(m.at);
      dayDivider(at);
      var row = el('div', 'aw-row ' + (ai ? 'aw-ai' : 'aw-me') + (m.kind === 'nudge' ? ' aw-nudge' : ''));
      if (m.id) row.setAttribute('data-id', String(m.id));
      var bubble = el('div', 'aw-bubble');
      if (ai && m.tag) bubble.appendChild(el('span', 'aw-tag', m.tag));
      var text = el('div', 'aw-text');
      if (ai) text.innerHTML = rich(m.content); else text.textContent = m.content;
      bubble.appendChild(text);

      if (ai && m.degraded) bubble.appendChild(el('span', 'aw-degraded', aiStatus === 'basic' ? 'Basic mode' : 'Couldn’t answer properly'));

      if (ai && m.nudge_id && (m.action || m.kind === 'nudge')) {
        var acts = el('div', 'aw-acts');
        if (m.action && m.action.href) {
          var go = el('a', 'aw-btn aw-btn-primary', m.action.label);
          go.href = m.action.href;
          go.addEventListener('click', function () { post('/buddy/nudge/' + m.nudge_id + '/open', {}, { keepalive: true }).catch(function () {}); });
          acts.appendChild(go);
        }
        if (m.snooze || m.tag === 'Reminder') {
          var sn = el('button', 'aw-btn', 'Snooze 15 min');
          sn.type = 'button';
          sn.addEventListener('click', function () {
            sn.disabled = true;
            post('/buddy/nudge/' + m.nudge_id + '/snooze').then(function (r) {
              if (!r.ok) throw 0;
              acts.innerHTML = ''; acts.appendChild(el('span', 'aw-acted', 'Snoozed — back in 15 minutes'));
              feedNow();
            }).catch(function () { sn.disabled = false; notice('Could not snooze that — try again.', 'error'); });
          });
          acts.appendChild(sn);
        }
        var done = el('button', 'aw-btn', m.done ? 'Done ✓' : 'Done');
        done.type = 'button';
        done.disabled = !!m.done;
        done.addEventListener('click', function () {
          done.disabled = true;
          post('/buddy/nudge/' + m.nudge_id + '/done').then(function (r) {
            if (!r.ok) throw 0;
            done.textContent = 'Done ✓'; row.classList.add('aw-done');
          }).catch(function () { done.disabled = false; notice('Could not save that — try again.', 'error'); });
        });
        acts.appendChild(done);
        bubble.appendChild(acts);
        if (m.done) row.classList.add('aw-done');
      }
      row.appendChild(bubble);

      var meta = el('div', 'aw-meta');
      meta.appendChild(el('time', '', clock(at)));
      if (ai && m.id && m.kind !== 'notice' && m.kind !== 'fallback') meta.appendChild(feedbackBar(m));
      if (ai) {
        var cp = el('button', 'aw-mini', null);
        cp.type = 'button';
        cp.innerHTML = ICON.copy;
        cp.title = 'Copy'; cp.setAttribute('aria-label', 'Copy message');
        cp.addEventListener('click', function () {
          try {
            navigator.clipboard.writeText(plain(m.content)).then(function () { cp.classList.add('aw-on'); setTimeout(function () { cp.classList.remove('aw-on'); }, 1200); });
            logEvent('copy');
          } catch (e) {}
        });
        meta.appendChild(cp);
      }
      row.appendChild(meta);
      if (ai) {
        var prev = log.querySelector('.aw-latest');
        if (prev) prev.classList.remove('aw-latest');
        row.classList.add('aw-latest');
      }
      log.appendChild(row);
      scrollEnd();
      return row;
    }

    function feedbackBar(m) {
      var wrap = el('span', 'aw-fb');
      var up = el('button', 'aw-mini'); up.type = 'button'; up.innerHTML = ICON.up;
      up.title = 'Good answer'; up.setAttribute('aria-label', 'Good answer'); up.setAttribute('aria-pressed', 'false');
      var down = el('button', 'aw-mini aw-down'); down.type = 'button'; down.innerHTML = ICON.up;
      down.title = 'Not good'; down.setAttribute('aria-label', 'Not a good answer'); down.setAttribute('aria-pressed', 'false');
      function mark(v) {
        up.classList.toggle('aw-on', v === 1); up.setAttribute('aria-pressed', v === 1 ? 'true' : 'false');
        down.classList.toggle('aw-on', v === -1); down.setAttribute('aria-pressed', v === -1 ? 'true' : 'false');
      }
      if (m.feedback === 1 || m.feedback === -1) mark(m.feedback);
      function send(v, reason) {
        mark(v);
        post('/buddy/feedback', { value: v, kind: MODE, message_id: m.id, reason: reason || '' }).catch(function () {});
      }
      up.addEventListener('click', function () { send(1); });
      down.addEventListener('click', function () {
        send(-1);
        var row = wrap.closest('.aw-row');
        if (!row || row.querySelector('.aw-reasons')) return;
        var rs = el('div', 'aw-reasons');
        rs.appendChild(el('span', 'aw-reasons-q', 'What was wrong?'));
        ['Wrong info', 'Not helpful', 'Too long', 'Didn’t understand me'].forEach(function (r) {
          var b = el('button', 'aw-chip aw-chip-sm', r); b.type = 'button';
          b.addEventListener('click', function () {
            send(-1, r);
            rs.innerHTML = ''; rs.appendChild(el('span', 'aw-acted', 'Thanks — noted.'));
          });
          rs.appendChild(b);
        });
        row.appendChild(rs);
        scrollEnd();
      });
      wrap.appendChild(up); wrap.appendChild(down);
      return wrap;
    }

    /** A system line — not Aisha speaking. Errors, confirmations, tips. */
    function notice(text, tone, retryText) {
      var n = el('div', 'aw-notice aw-notice-' + (tone || 'info'));
      n.setAttribute('role', tone === 'error' ? 'alert' : 'status');
      n.appendChild(el('span', '', text));
      if (retryText) {
        var b = el('button', 'aw-link', 'Try again');
        b.type = 'button';
        b.addEventListener('click', function () {
          if (n.parentNode) n.parentNode.removeChild(n);
          input.value = retryText; syncInput(); sendCurrent(); logEvent('retry');
        });
        n.appendChild(b);
      }
      log.appendChild(n);
      scrollEnd();
      return n;
    }

    function welcome() {
      if (log.querySelector('.aw-row')) return;
      var w = el('div', 'aw-welcome');
      w.appendChild(el('p', 'aw-welcome-hi', MODE === 'admin'
        ? 'Aisha here — ask about anyone on the team.'
        : (firstName ? 'Hi ' + firstName + ', I’m Aisha.' : 'Hi, I’m Aisha.')));
      w.appendChild(el('p', 'aw-welcome-sub', MODE === 'admin'
        ? 'Stats, dry spells, e-ticket lag, what an agent’s buddy has been hearing. I can send nudges too — you confirm each one.'
        : 'Ask me about your night, any of your bookings, how to do something in the CRM, or a tricky customer. I can set reminders too.'));
      log.appendChild(w);
      chipsRow(starters, 'starter');
    }

    // ── History ─────────────────────────────────────────────────────────────
    var loading = false, arrivedWhileLoading = [];
    function load() {
      loading = true;
      refreshCard();
      getJson(EP.history)
        .then(function (h) {
          if (h.starters) starters = h.starters;
          var msgs = h.messages || [];
          msgs.forEach(function (m) { addMsg(m); });
          arrivedWhileLoading.forEach(function (m) { addMsg(m); msgs.push(m); });
          if (!msgs.length) welcome();
          else if (msgs[msgs.length - 1].role !== 'user' && msgs[msgs.length - 1].kind !== 'chat') chipsRow(starters, 'starter');
          scrollEnd();
        })
        .catch(function () { notice('Could not load our chat — close and reopen me.', 'error'); })
        .finally(function () { loading = false; loaded = true; arrivedWhileLoading = []; });
    }

    // ── Compose ─────────────────────────────────────────────────────────────
    var form = panel.querySelector('.aw-compose');
    function syncInput() {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 132) + 'px';
      sendBtn.disabled = busy || !input.value.trim();
    }
    input.addEventListener('input', syncInput);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendCurrent(); }
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); sendCurrent(); });

    var typingRow = null, typingTimer = null, typingStart = 0;
    function showTyping() {
      typingRow = el('div', 'aw-row aw-ai aw-typing');
      typingRow.setAttribute('aria-hidden', 'true');
      var b = el('div', 'aw-bubble');
      b.innerHTML = '<span class="aw-dots"><i></i><i></i><i></i></span>';
      var label = el('span', 'aw-typing-txt', 'Thinking…');
      b.appendChild(label);
      var stop = el('button', 'aw-link aw-stop', 'Stop');
      stop.type = 'button';
      stop.hidden = true;
      stop.addEventListener('click', function () { if (abortCtl) abortCtl.abort(); logEvent('stop'); });
      b.appendChild(stop);
      typingRow.appendChild(b);
      log.appendChild(typingRow);
      scrollEnd();
      srLine.textContent = 'Aisha is thinking';
      typingStart = Date.now();
      typingTimer = setInterval(function () {
        var s = Math.round((Date.now() - typingStart) / 1000);
        if (s >= 1) stop.hidden = false;
        label.textContent = s < 3 ? 'Thinking…' : (s < 8 ? 'Checking… ' + s + 's' : 'Still on it… ' + s + 's');
      }, 500);
    }
    function hideTyping() {
      clearInterval(typingTimer);
      if (typingRow && typingRow.parentNode) typingRow.parentNode.removeChild(typingRow);
      typingRow = null;
      srLine.textContent = '';
    }
    function setBusy(b) {
      busy = b;
      sendBtn.disabled = b || !input.value.trim();
      panel.classList.toggle('aw-is-busy', b);
      orbState(b ? 'thinking' : '');
      setStatus(b ? 'Thinking…' : '');
    }

    function sendCurrent() {
      var text = input.value.trim();
      if (!text || busy) return;
      if (text.length > MAXLEN) { notice('That’s a bit long — keep it under ' + MAXLEN + ' characters.', 'error'); return; }
      hideMemory();
      stopSpeaking();
      stopRec();
      armFiller();
      clearChips();
      var w = log.querySelector('.aw-welcome'); if (w) w.parentNode.removeChild(w);
      addMsg({ role: 'user', content: text, at: null });
      input.value = '';
      syncInput();
      setBusy(true);
      showTyping();
      abortCtl = ('AbortController' in window) ? new AbortController() : null;
      var hadFocus = panel.contains(document.activeElement);

      post(EP.chat, { message: text }, abortCtl ? { signal: abortCtl.signal } : null)
        .then(function (r) {
          var ct = r.headers.get('content-type') || '';
          if (ct.indexOf('json') === -1) throw new Error('session');
          return r.json();
        })
        .then(function (d) {
          hideTyping(); stopFiller();
          if (d.success) {
            if (d.budget) { aiStatus = 'basic'; setStatus(); }
            addMsg({ id: d.message_id, role: 'model', content: d.reply, at: null, kind: d.ai === false ? 'fallback' : 'chat', degraded: d.ai === false });
            if (d.retry) notice('Aisha was busy for a moment.', 'info', text);
            var willSpeak = speak(d.reply, 'reply');
            if (!willSpeak && typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
            if (d.pending_action && EP.confirm) renderConfirm(d.pending_action);
            if (d.suggestions && d.suggestions.length) chipsRow(d.suggestions, 'chip');
            refreshCard();
          } else {
            notice(d.error || 'Something went wrong — try again.', 'error', text);
            if (typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
          }
        })
        .catch(function (err) {
          hideTyping(); stopFiller();
          if (err && err.name === 'AbortError') notice('Stopped. Ask again whenever you like.', 'info');
          else if (err && err.message === 'session') notice('Your CRM session has ended — reload the page to sign in again.', 'error');
          else notice('Couldn’t reach Aisha — check your connection.', 'error', text);
          if (typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
        })
        .finally(function () {
          setBusy(false);
          abortCtl = null;
          // Only take focus back if it was in the chat to begin with: v1
          // yanked the cursor out of whatever CRM field the agent had moved to.
          if (hadFocus || document.activeElement === document.body) input.focus();
        });
    }

    // Admin confirm gate: the model can only PARK an action; these buttons are
    // the human click that executes or discards it.
    function renderConfirm(summary) {
      var row = el('div', 'aw-row aw-ai');
      var b = el('div', 'aw-bubble');
      b.appendChild(el('span', 'aw-tag', 'Needs your OK'));
      b.appendChild(el('div', 'aw-text', summary));
      var acts = el('div', 'aw-acts');
      var yes = el('button', 'aw-btn aw-btn-primary', 'Confirm & send'); yes.type = 'button';
      var no = el('button', 'aw-btn', 'Cancel'); no.type = 'button';
      acts.appendChild(yes); acts.appendChild(no);
      b.appendChild(acts);
      row.appendChild(b);
      log.appendChild(row);
      scrollEnd();
      function finish(url) {
        yes.disabled = no.disabled = true;
        post(url).then(function (r) { return r.json(); })
          .then(function (d) { acts.innerHTML = ''; acts.appendChild(el('span', 'aw-acted', d.detail || d.error || 'Done.')); })
          .catch(function () { acts.innerHTML = ''; acts.appendChild(el('span', 'aw-acted', 'That didn’t go through — ask again.')); });
      }
      yes.addEventListener('click', function () { finish(EP.confirm); });
      no.addEventListener('click', function () { finish(EP.cancel); });
    }

    // ── What Aisha knows ────────────────────────────────────────────────────
    function hideMemory() {
      if (mem.hidden) return;
      mem.hidden = true; log.hidden = false;
    }
    function showMemory() {
      mem.innerHTML = '';
      mem.hidden = false; log.hidden = true;
      var head = el('div', 'aw-mem-head');
      var back = el('button', 'aw-link aw-back'); back.type = 'button';
      back.innerHTML = ICON.back + '<span>Back to chat</span>';
      back.addEventListener('click', function () { hideMemory(); input.focus(); });
      head.appendChild(back);
      mem.appendChild(head);
      mem.appendChild(el('h3', 'aw-mem-title', 'What Aisha knows about you'));
      mem.appendChild(el('p', 'aw-mem-sub', 'Things you’ve told Aisha that she keeps between chats. Remove anything you like — it’s yours.'));
      var body = el('div', 'aw-mem-body', 'Loading…');
      mem.appendChild(body);
      back.focus();
      getJson('/buddy/memory').then(function (d) {
        body.innerHTML = '';
        var any = false;
        if (d.preferred_name) { any = true; body.appendChild(memRow('Calls you', d.preferred_name, null)); }
        if (d.own_goal) {
          any = true;
          var gtxt = [];
          if (d.own_goal.sales) gtxt.push(d.own_goal.sales + ' sales');
          if (d.own_goal.revenue) gtxt.push(money(d.own_goal.revenue) + ' revenue');
          body.appendChild(memRow('Your goal this month', gtxt.join(' and ') || 'set', null));
        }
        (d.facts || []).forEach(function (f) { any = true; body.appendChild(memRow(null, f.fact, f)); });
        if (!(d.facts || []).length) body.appendChild(el('p', 'aw-mem-empty', (any ? 'No saved notes yet. ' : 'Nothing yet. ') + 'Tell her something like “remember I like short answers”.'));
        if ((d.facts || []).length > 1) {
          var all = el('button', 'aw-btn aw-btn-quiet', 'Forget everything');
          all.type = 'button';
          all.addEventListener('click', function () {
            if (!window.confirm('Forget everything Aisha remembers about you?')) return;
            post('/buddy/memory/forget', { all: 1 }).then(function () { showMemory(); }).catch(function () {});
          });
          body.appendChild(all);
        }
      }).catch(function () { body.textContent = 'Could not load — try again in a moment.'; });
    }
    function memRow(label, value, fact) {
      var row = el('div', 'aw-mem-row');
      var txt = el('div', 'aw-mem-txt');
      if (label) txt.appendChild(el('span', 'aw-mem-label', label));
      txt.appendChild(el('span', 'aw-mem-val', value));
      if (fact && fact.saved) txt.appendChild(el('span', 'aw-mem-date', 'Saved ' + fact.saved));
      row.appendChild(txt);
      if (fact && fact.id) {
        var x = el('button', 'aw-btn aw-btn-quiet', 'Forget');
        x.type = 'button';
        x.setAttribute('aria-label', 'Forget: ' + value);
        x.addEventListener('click', function () {
          x.disabled = true;
          post('/buddy/memory/forget', { fact_id: fact.id }).then(function () {
            row.classList.add('aw-gone'); row.innerHTML = ''; row.appendChild(el('span', 'aw-acted', 'Forgotten.'));
          }).catch(function () { x.disabled = false; });
        });
        row.appendChild(x);
      }
      return row;
    }

    // ── Toast (panel closed) ────────────────────────────────────────────────
    var toast = null, toastTimer = null, toastShown = false;
    function showToast(m) {
      if (opened || PAGE) return;
      if (!toast) {
        toast = el('div', 'aw-toast');
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        document.body.appendChild(toast);
      }
      toast.innerHTML = '';
      var head = el('div', 'aw-toast-head');
      var ava = el('span', 'aw-ava aw-ava-sm'); ava.innerHTML = ICON.spark; ava.setAttribute('aria-hidden', 'true');
      head.appendChild(ava);
      head.appendChild(el('b', '', 'Aisha'));
      if (m.tag) head.appendChild(el('span', 'aw-tag', m.tag));
      var x = el('button', 'aw-icon aw-toast-x'); x.type = 'button'; x.innerHTML = ICON.close;
      x.setAttribute('aria-label', 'Dismiss');
      x.addEventListener('click', function (e) { e.stopPropagation(); hideToast(); logEvent('toast_close', m.type || ''); });
      head.appendChild(x);
      toast.appendChild(head);
      var p = el('p', 'aw-toast-text', plain(m.content));
      toast.appendChild(p);
      var acts = el('div', 'aw-acts');
      if (m.action && m.action.href) {
        var go = el('a', 'aw-btn aw-btn-primary', m.action.label);
        go.href = m.action.href;
        go.addEventListener('click', function () { if (m.nudge_id) post('/buddy/nudge/' + m.nudge_id + '/open', {}, { keepalive: true }).catch(function () {}); });
        acts.appendChild(go);
      }
      var reply = el('button', 'aw-btn', m.action ? 'Open chat' : 'Reply');
      reply.type = 'button';
      reply.addEventListener('click', function () { hideToast(); openPanel(true); logEvent('toast_open', m.type || ''); });
      acts.appendChild(reply);
      if (speaking) {
        var hush = el('button', 'aw-btn aw-btn-quiet', 'Stop voice');
        hush.type = 'button';
        hush.addEventListener('click', function () { stopSpeaking(); speechDone(); hush.parentNode && hush.parentNode.removeChild(hush); });
        acts.appendChild(hush);
      }
      toast.appendChild(acts);
      toast.classList.add('aw-toast-show');
      toastShown = true;
      placeNear(toast, true);
      clearTimeout(toastTimer);
      // A reminder stays until dismissed — it's something they asked for.
      if (m.type !== 'reminder') toastTimer = setTimeout(hideToast, 15000);
    }
    function hideToast() {
      if (toast) toast.classList.remove('aw-toast-show');
      toastShown = false;
      clearTimeout(toastTimer);
    }

    /** One Aisha-initiated message: into the log if built, toast + badge if closed. */
    function deliver(m, speakIt, audioUrl) {
      // Speak first, so the toast knows to offer "Stop voice".
      if (speakIt) speak(m.content, 'greeting', audioUrl);
      if (document.hidden && m.type === 'reminder') document.title = '⏰ Reminder from Aisha';
      if (loading) {
        arrivedWhileLoading.push(m);
      } else if (loaded) {
        var dup = m.id && log.querySelector('.aw-row[data-id="' + m.id + '"]');
        if (!dup) {
          var w = log.querySelector('.aw-welcome'); if (w) w.parentNode.removeChild(w);
          addMsg(m);
          if (m.kind === 'greeting') chipsRow(starters, 'starter');
        }
      }
      if (!opened) { showToast(m); setBadge(nudgeCount + 1); }
    }

    // ── Feed: Aisha initiates ───────────────────────────────────────────────
    // Adaptive interval: the trigger cron only mints nudges every 15 minutes,
    // so quiet periods back off toward 5 minutes and anything suggesting news
    // (a delivery, a backlog, the agent returning to the tab, opening the
    // chat) snaps back to 75 seconds. The server paces delivery itself
    // (hold_seconds) and the widget waits that out instead of polling a gate.
    var FEED_MIN_MS = 75000, FEED_MAX_MS = 300000;
    var feedMs = FEED_MIN_MS, feedTimer = null, pollBusy = false;
    // When the next personal reminder falls due (server tells us on every
    // poll). The widget wakes for it whatever the pacing, and even in a
    // background tab — "remind me at 2:30" must not wait for the agent to
    // come back to this tab, or for a 20-minute cooldown to run out.
    var nextReminderAt = null, baseTitle = document.title;
    function untilNext(ms) {
      if (!nextReminderAt) return ms;
      return Math.max(3000, Math.min(ms, nextReminderAt - Date.now() + 1500));
    }
    function scheduleFeed(ms) { if (!EP.feed) return; clearTimeout(feedTimer); feedTimer = setTimeout(pollFeed, untilNext(ms)); }
    function backOff() { feedMs = Math.min(Math.round(feedMs * 1.6), FEED_MAX_MS); }
    function feedNow() { if (!EP.feed) return; feedMs = FEED_MIN_MS; scheduleFeed(1500); }
    function pollFeed() {
      if (!EP.feed) return;
      var reminderDue = nextReminderAt && Date.now() >= nextReminderAt - 2000;
      if ((document.hidden && !reminderDue) || pollBusy) { scheduleFeed(feedMs); return; }
      pollBusy = true;
      post(EP.feed, { open: opened ? 1 : 0 })
        .then(function (r) {
          var ct = r.headers.get('content-type') || '';
          if (!r.ok || ct.indexOf('json') === -1) throw 0;
          return r.json();
        })
        .then(function (d) {
          if (!d || !d.ok) { backOff(); return; }
          var msgs = d.messages || [];
          msgs.forEach(function (m) {
            deliver({ id: m.id, role: 'model', content: m.content, at: m.at, kind: 'nudge', tag: m.tag,
                      action: m.action, nudge_id: m.nudge_id, snooze: m.snooze, type: m.type }, false);
          });
          nextReminderAt = d.next_reminder_in != null ? Date.now() + d.next_reminder_in * 1000 : null;
          if (d.hold_seconds) feedMs = Math.min(Math.max(FEED_MIN_MS, d.hold_seconds * 1000), 30 * 60000);
          else if (msgs.length || d.pending_left) feedMs = FEED_MIN_MS;
          else backOff();
        })
        .catch(function () { backOff(); })
        .finally(function () { pollBusy = false; scheduleFeed(feedMs); });
    }

    // ── Greeting ────────────────────────────────────────────────────────────
    // "Did they just open the app?" — sessionStorage is wiped when the browser
    // closes, so its absence matches what the client means by opening it
    // again. The server's cooldown handles second tabs and reloads.
    var freshArrival = false;
    try {
      if (!sessionStorage.getItem('bwArrived')) { freshArrival = true; sessionStorage.setItem('bwArrived', String(Date.now())); }
    } catch (e) {}
    if (EP.greeting) {
      post(EP.greeting, { fresh: freshArrival ? 1 : 0 })
        .then(function (r) { return r.json(); })
        .then(function (g) {
          if (g.greeted && g.reply) {
            deliver({ id: g.message_id, role: 'model', content: g.reply, at: null, kind: 'greeting', degraded: g.ai === false }, true, g.audio_url);
          }
        })
        .catch(function () {})
        .finally(function () { if (EP.feed) setTimeout(pollFeed, 8000); });
    }
    if (EP.feed) {
      scheduleFeed(feedMs);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { document.title = baseTitle; feedNow(); }
      });
    }
    // Quiet mode ends on its own: repaint when it does.
    setInterval(function () { if (quietUntil && !isQuiet()) { quietUntil = null; setStatus(); } }, 60000);

    if (PAGE) { load(); setTimeout(function () { input.focus(); }, 100); }
  }

  // ── Markdown, deliberately tiny ────────────────────────────────────────────
  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /**
   * HTML is escaped FIRST and only a tiny whitelist is reintroduced. That
   * order is not optional: admin messages are relayed verbatim, so this would
   * otherwise be an HTML injection point into another user's page.
   */
  function rich(text) {
    var s = escHtml(text);
    s = s.replace(/\\([\\$*_`#\[\]()~>+\-.!])/g, '$1');       // undo md escapes
    s = s.replace(/^\s{0,3}#{1,6}\s*(.+)$/gm, '<b>$1</b>');    // headings
    s = s.replace(/^\s*[\*\-•]\s+/gm, '• ');                  // bullets
    s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');            // bold
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');          // inline code
    return s;
  }
  /** Speech and toasts must never show or pronounce syntax. */
  function plain(text) {
    return String(text)
      .replace(/\\([\\$*_`#])/g, '$1')
      .replace(/^\s{0,3}#{1,6}\s*/gm, '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/`([^`\n]+)`/g, '$1')
      .replace(/^\s*[\*\-•]\s+/gm, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function injectCss() {
    var css =
':root{--aw-ink:rgb(var(--bf-on-surface, 25 28 29));--aw-ink2:rgb(var(--bf-slate-600, 74 79 92));--aw-ink3:rgb(var(--bf-slate-500, 107 112 128));--aw-line:rgb(var(--bf-slate-200, 226 229 238));--aw-soft:rgb(var(--bf-slate-100, 245 246 250));--aw-bg:rgb(var(--bf-white, 255 255 255));' +
'--aw-pri:rgb(var(--bf-primary, 22 50 116));--aw-pri2:rgb(var(--bf-primary-container, 49 74 141));--aw-pri-soft:rgb(var(--bf-indigo-50, 238 241 251));--aw-acc1:rgb(var(--bf-indigo-500, 79 107 237));--aw-acc2:rgb(var(--bf-violet-500, 139 92 246));' +
'--aw-ok:rgb(var(--bf-emerald-700, 15 123 79));--aw-ok-soft:rgb(var(--bf-emerald-50, 232 246 239));--aw-warn:rgb(var(--bf-yellow-800, 138 83 0));--aw-warn-soft:rgb(var(--bf-yellow-50, 255 244 224));--aw-bad:rgb(var(--bf-red-700, 180 35 24));--aw-bad-soft:rgb(var(--bf-rose-50, 253 236 235))}' +
'.aw-orb,.aw-panel,.aw-toast{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}' +
'.aw-panel *,.aw-toast *{box-sizing:border-box}' +
/* orb */
'.aw-orb{position:fixed;right:24px;bottom:24px;z-index:9990;width:56px;height:56px;border-radius:50%;border:0;padding:0;cursor:pointer;background:transparent;touch-action:none;transition:transform .2s ease,opacity .2s ease}' +
'.aw-orb:hover{transform:translateY(-2px)}' +
'.aw-orb:focus-visible{outline:3px solid var(--aw-acc1);outline-offset:3px}' +
'.aw-orb-core{position:absolute;inset:0;border-radius:50%;display:flex;align-items:center;justify-content:center;color:rgb(var(--bf-white, 255 255 255));background:linear-gradient(140deg,var(--aw-acc1),var(--aw-acc2));box-shadow:0 10px 26px rgb(var(--bf-indigo-500, 79 107 237) / .38),0 2px 6px rgb(var(--bf-primary, 22 50 116) / .25)}' +
'.aw-orb-core svg{width:28px;height:28px}' +
'.aw-orb-ring{position:absolute;inset:-4px;border-radius:50%;border:2px solid transparent;pointer-events:none}' +
'.aw-orb-thinking .aw-orb-ring{border-color:rgb(var(--bf-indigo-500, 79 107 237) / .25);border-top-color:var(--aw-acc1);animation:aw-spin 1s linear infinite}' +
'.aw-orb-quiet .aw-orb-core{background:linear-gradient(140deg,rgb(var(--bf-slate-400, 138 144 166)),rgb(var(--bf-slate-400, 166 161 201)));box-shadow:0 6px 18px rgb(var(--bf-slate-700, 60 64 90) / .25)}' +
'.aw-orb-drag{cursor:grabbing;transform:scale(1.06);transition:none}' +
'.aw-orb-hidden{opacity:0;pointer-events:none;transform:scale(.7)}' +
'.aw-badge{position:absolute;top:-4px;right:-4px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:rgb(var(--bf-red-600, 217 45 32));color:rgb(var(--bf-white, 255 255 255));font:700 12px/22px Inter,sans-serif;text-align:center;box-shadow:0 0 0 2px rgb(var(--bf-white, 255 255 255));animation:aw-pop .35s ease}' +
'@keyframes aw-spin{to{transform:rotate(360deg)}}' +
'@keyframes aw-pop{0%{transform:scale(.4)}70%{transform:scale(1.15)}100%{transform:scale(1)}}' +
/* panel */
'.aw-panel{position:fixed;right:24px;bottom:24px;z-index:9991;width:min(420px,calc(100vw - 32px));height:min(680px,calc(100vh - 48px));display:flex;flex-direction:column;background:var(--aw-soft);color:var(--aw-ink);border-radius:20px;overflow:hidden;box-shadow:0 28px 70px rgb(var(--bf-indigo-950, 16 24 64) / .22),0 0 0 1px rgb(var(--bf-indigo-950, 16 24 64) / .07);opacity:0;visibility:hidden;transform:translateY(12px) scale(.985);transition:opacity .2s ease,transform .2s ease,visibility 0s linear .2s}' +
'.aw-panel.aw-open{opacity:1;visibility:visible;transform:none;transition:opacity .2s ease,transform .2s ease}' +
'.aw-panel.aw-page{position:relative;right:auto;bottom:auto;width:100%;height:100%;min-height:520px;border-radius:20px;box-shadow:0 1px 3px rgb(var(--bf-indigo-950, 16 24 64) / .08),0 0 0 1px var(--aw-line)}' +
'.aw-head{display:flex;align-items:center;gap:12px;padding:14px 12px 14px 16px;background:var(--aw-bg);border-bottom:1px solid var(--aw-line);position:relative}' +
'.aw-ava{flex:0 0 auto;width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:rgb(var(--bf-white, 255 255 255));background:linear-gradient(140deg,var(--aw-acc1),var(--aw-acc2))}' +
'.aw-ava svg{width:22px;height:22px}' +
'.aw-ava-sm{width:28px;height:28px}.aw-ava-sm svg{width:16px;height:16px}' +
'.aw-is-quiet .aw-ava{background:linear-gradient(140deg,rgb(var(--bf-slate-400, 138 144 166)),rgb(var(--bf-slate-400, 166 161 201)))}' +
'.aw-is-speaking .aw-ava{box-shadow:0 0 0 4px rgb(var(--bf-indigo-500, 79 107 237) / .2)}' +
'.aw-id{flex:1;min-width:0}' +
'.aw-title{margin:0;font:800 17px/1.2 Manrope,Inter,sans-serif;color:var(--aw-ink);display:flex;align-items:center;gap:8px}' +
'.aw-chip-mode{font:700 12px/1 Inter,sans-serif;color:var(--aw-pri);background:var(--aw-pri-soft);border-radius:6px;padding:4px 7px}' +
'.aw-status{margin:3px 0 0;display:flex;align-items:center;gap:6px;font-size:13px;line-height:1.3;color:var(--aw-ink3);white-space:nowrap;overflow:hidden}' +
'.aw-status-txt{overflow:hidden;text-overflow:ellipsis}' +
'.aw-dot{flex:0 0 auto;width:8px;height:8px;border-radius:50%;background:rgb(var(--bf-emerald-500, 18 183 106))}' +
'.aw-is-busy .aw-dot{background:var(--aw-acc1)}.aw-is-quiet .aw-dot{background:rgb(var(--bf-slate-400, 152 162 179))}.aw-is-basic .aw-dot{background:rgb(var(--bf-amber-500, 247 144 9))}' +
'.aw-tools{display:flex;align-items:center;gap:2px}' +
'.aw-icon{width:40px;height:40px;border:0;border-radius:12px;background:transparent;color:var(--aw-ink2);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s}' +
'.aw-icon svg{width:22px;height:22px}' +
'.aw-icon:hover{background:var(--aw-soft);color:var(--aw-ink)}' +
'.aw-icon:focus-visible,.aw-btn:focus-visible,.aw-chip:focus-visible,.aw-mini:focus-visible,.aw-stat:focus-visible,.aw-card-head:focus-visible,.aw-menu-item:focus-visible,.aw-link:focus-visible,.aw-send:focus-visible{outline:3px solid var(--aw-acc1);outline-offset:2px}' +
'.aw-icon.aw-on{color:var(--aw-pri)}' +
'.aw-quiet.aw-on{background:rgb(var(--bf-slate-100, 238 240 246));color:#5b4fc4}' +
/* menus */
'.aw-menu{position:absolute;top:66px;right:12px;z-index:3;width:min(300px,calc(100% - 24px));background:var(--aw-bg);border:1px solid var(--aw-line);border-radius:14px;box-shadow:0 16px 40px rgb(var(--bf-indigo-950, 16 24 64) / .18);padding:6px}' +
'.aw-menu[hidden]{display:none}' +
'.aw-menu-item{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-height:44px;padding:10px 12px;border:0;border-radius:10px;background:transparent;color:var(--aw-ink);font:500 15px/1.3 Inter,sans-serif;text-align:left;cursor:pointer}' +
'.aw-menu-item:hover{background:var(--aw-soft)}' +
'.aw-menu-hint{font-size:13px;color:var(--aw-ink3)}' +
'.aw-menu-note{margin:4px 8px 6px;font-size:13px;line-height:1.45;color:var(--aw-ink3)}' +
/* tonight card */
'.aw-card{background:var(--aw-bg);border-bottom:1px solid var(--aw-line);padding:4px 14px 12px}' +
'.aw-card[hidden]{display:none}' +
'.aw-card-collapsed{padding-bottom:4px}' +
'.aw-card-head{display:flex;align-items:center;gap:10px;width:100%;min-height:40px;border:0;background:transparent;padding:4px 0;cursor:pointer;text-align:left;color:var(--aw-ink)}' +
'.aw-card-title{font:800 13px/1 Manrope,Inter,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:var(--aw-pri)}' +
'.aw-card-sum{flex:1;min-width:0;font-size:13px;color:var(--aw-ink3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
'.aw-card-chev{width:20px;height:20px;color:var(--aw-ink3);transition:transform .2s}.aw-card-chev svg{width:20px;height:20px}' +
'.aw-card-collapsed .aw-card-chev{transform:rotate(-90deg)}' +
'.aw-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}' +
'.aw-stat{display:flex;flex-direction:column;gap:2px;padding:10px 12px;border-radius:12px;background:var(--aw-soft);border:1px solid var(--aw-line);text-decoration:none;color:var(--aw-ink);transition:border-color .15s,background .15s;min-width:0}' +
'.aw-stat:hover{border-color:rgb(var(--bf-slate-300, 197 203 224));background:rgb(var(--bf-slate-100, 238 240 246))}' +
'.aw-stat b{font:800 20px/1.15 Manrope,Inter,sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
'.aw-stat span{font-size:13px;color:var(--aw-ink3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
'.aw-stat-warn{background:var(--aw-warn-soft);border-color:rgb(var(--bf-orange-200, 246 217 166))}.aw-stat-warn b{color:var(--aw-warn)}' +
'.aw-stat-ok b{color:var(--aw-ok)}.aw-stat-bad b{color:var(--aw-bad)}' +
'.aw-goal{margin-top:10px;display:flex;flex-direction:column;gap:6px}' +
'.aw-goal-label{font-size:13px;color:var(--aw-ink2)}' +
'.aw-goal-bar{display:block;height:6px;border-radius:3px;background:rgb(var(--bf-slate-200, 228 231 240));overflow:hidden}' +
'.aw-goal-bar i{display:block;height:100%;border-radius:3px;background:linear-gradient(90deg,var(--aw-acc1),var(--aw-acc2))}' +
'.aw-next-rem{margin-top:10px;font-size:13px;color:var(--aw-ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
/* log */
'.aw-body{flex:1;min-height:0;position:relative;display:flex;flex-direction:column}' +
'.aw-log{flex:1;min-height:0;overflow-y:auto;padding:16px 14px 8px;display:flex;flex-direction:column;gap:12px;overscroll-behavior:contain}' +
'.aw-log:focus{outline:none}' +
'.aw-log[hidden],.aw-mem[hidden]{display:none}' +
'.aw-day{display:flex;justify-content:center;margin:4px 0}' +
'.aw-day span{font-size:12.5px;font-weight:600;color:var(--aw-ink3);background:rgb(var(--bf-slate-100, 236 238 244));border-radius:999px;padding:4px 10px}' +
'.aw-row{display:flex;flex-direction:column;gap:4px;max-width:88%;animation:aw-in .2s ease}' +
'.aw-ai{align-self:flex-start}.aw-me{align-self:flex-end;align-items:flex-end}' +
'@keyframes aw-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}' +
'.aw-bubble{padding:11px 14px;border-radius:18px;font-size:16px;line-height:1.5;word-break:break-word}' +
'.aw-ai .aw-bubble{background:var(--aw-bg);color:var(--aw-ink);border:1px solid var(--aw-line);border-top-left-radius:6px}' +
'.aw-me .aw-bubble{background:var(--aw-pri);color:rgb(var(--bf-white, 255 255 255));border-top-right-radius:6px}' +
'.aw-text{white-space:pre-wrap}' +
'.aw-text b{font-weight:700}' +
'.aw-text code{font:600 14px/1.4 ui-monospace,Menlo,Consolas,monospace;background:var(--aw-soft);border-radius:5px;padding:1px 5px}' +
'.aw-nudge .aw-bubble{border-color:rgb(var(--bf-violet-200, 214 220 242));box-shadow:inset 3px 0 0 var(--aw-acc1)}' +
'.aw-done .aw-bubble{opacity:.72}' +
'.aw-tag{display:inline-block;margin-bottom:6px;font:700 12px/1 Inter,sans-serif;letter-spacing:.02em;color:var(--aw-pri);background:var(--aw-pri-soft);border-radius:6px;padding:5px 8px}' +
'.aw-degraded{display:inline-block;margin-top:8px;font-size:12.5px;color:var(--aw-warn);background:var(--aw-warn-soft);border-radius:6px;padding:3px 8px}' +
'.aw-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}' +
'.aw-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 14px;border-radius:10px;border:1px solid var(--aw-line);background:var(--aw-bg);color:var(--aw-ink);font:600 14px/1.2 Inter,sans-serif;text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s}' +
'.aw-btn:hover{background:var(--aw-soft);border-color:rgb(var(--bf-slate-300, 201 207 226))}' +
'.aw-btn:disabled{opacity:.6;cursor:default}' +
'.aw-btn-primary{background:var(--aw-pri);border-color:var(--aw-pri);color:rgb(var(--bf-white, 255 255 255))}' +
'.aw-btn-primary:hover{background:var(--aw-pri2);border-color:var(--aw-pri2)}' +
'.aw-btn-quiet{color:var(--aw-ink2)}' +
'.aw-acted{font-size:14px;color:var(--aw-ok);font-weight:600}' +
'.aw-meta{display:flex;align-items:center;gap:2px;padding:0 4px;font-size:12.5px;color:var(--aw-ink3)}' +
'.aw-meta time{margin-right:6px}' +
'.aw-fb{display:inline-flex;gap:0}' +
'.aw-mini{width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:#8a90a3;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s,opacity .15s}' +
'.aw-mini svg{width:17px;height:17px}' +
'.aw-mini:hover{background:rgb(var(--bf-slate-100, 236 238 244));color:var(--aw-ink)}' +
'.aw-mini.aw-on{color:var(--aw-pri);background:var(--aw-pri-soft)}' +
'.aw-down svg{transform:rotate(180deg)}' +
'.aw-down.aw-on{color:var(--aw-bad);background:var(--aw-bad-soft)}' +
'@media (hover:hover){.aw-ai .aw-mini{opacity:0}.aw-ai:hover .aw-mini,.aw-ai:focus-within .aw-mini,.aw-mini.aw-on,.aw-latest .aw-mini{opacity:1}}' +
'.aw-reasons{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:4px}' +
'.aw-reasons-q{font-size:13px;color:var(--aw-ink3);margin-right:2px}' +
'.aw-chips{display:flex;flex-wrap:wrap;gap:8px;padding:2px 0 4px}' +
'.aw-chip{min-height:38px;padding:8px 14px;border-radius:999px;border:1px solid rgb(var(--bf-slate-300, 211 217 239));background:var(--aw-bg);color:var(--aw-pri);font:600 14px/1.2 Inter,sans-serif;cursor:pointer;transition:background .15s,border-color .15s}' +
'.aw-chip:hover{background:var(--aw-pri-soft);border-color:rgb(var(--bf-slate-300, 184 194 230))}' +
'.aw-chip-sm{min-height:32px;padding:6px 11px;font-size:13px}' +
'.aw-notice{align-self:center;max-width:94%;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px;text-align:center;font-size:14px;line-height:1.45;color:var(--aw-ink2);background:rgb(var(--bf-slate-100, 236 238 244));border-radius:12px;padding:8px 12px}' +
'.aw-notice-error{color:var(--aw-bad);background:var(--aw-bad-soft)}' +
'.aw-link{border:0;background:transparent;padding:4px 6px;border-radius:8px;color:var(--aw-pri);font:700 14px/1.2 Inter,sans-serif;cursor:pointer;text-decoration:underline;text-underline-offset:2px}' +
'.aw-typing .aw-bubble{display:flex;align-items:center;gap:10px;padding:10px 14px}' +
'.aw-dots{display:inline-flex;gap:4px}' +
'.aw-dots i{width:7px;height:7px;border-radius:50%;background:var(--aw-acc1);animation:aw-dot 1.2s infinite}' +
'.aw-dots i:nth-child(2){animation-delay:.15s}.aw-dots i:nth-child(3){animation-delay:.3s}' +
'@keyframes aw-dot{0%,60%,100%{opacity:.35;transform:none}30%{opacity:1;transform:translateY(-3px)}}' +
'.aw-typing-txt{font-size:14px;color:var(--aw-ink3)}' +
'.aw-stop{text-decoration:none;border:1px solid var(--aw-line);padding:4px 10px}' +
'.aw-welcome{padding:6px 4px 2px}' +
'.aw-welcome-hi{margin:0 0 6px;font:800 20px/1.25 Manrope,Inter,sans-serif;color:var(--aw-ink)}' +
'.aw-welcome-sub{margin:0;font-size:15px;line-height:1.5;color:var(--aw-ink2)}' +
/* memory */
'.aw-mem{flex:1;min-height:0;overflow-y:auto;padding:12px 16px 16px}' +
'.aw-mem-head{margin-bottom:6px}' +
'.aw-back{display:inline-flex;align-items:center;gap:4px;text-decoration:none;padding:6px 8px 6px 2px}.aw-back svg{width:20px;height:20px}' +
'.aw-mem-title{margin:4px 0 6px;font:800 19px/1.25 Manrope,Inter,sans-serif}' +
'.aw-mem-sub{margin:0 0 14px;font-size:15px;line-height:1.5;color:var(--aw-ink2)}' +
'.aw-mem-body{display:flex;flex-direction:column;gap:8px;font-size:15px}' +
'.aw-mem-row{display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;background:var(--aw-bg);border:1px solid var(--aw-line)}' +
'.aw-mem-txt{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}' +
'.aw-mem-label{font-size:13px;color:var(--aw-ink3)}' +
'.aw-mem-val{font-size:16px;line-height:1.4;color:var(--aw-ink);word-break:break-word}' +
'.aw-mem-date{font-size:12.5px;color:var(--aw-ink3)}' +
'.aw-mem-empty{margin:0;font-size:15px;color:var(--aw-ink2);line-height:1.5}' +
/* compose */
'.aw-compose{display:flex;align-items:flex-end;gap:8px;padding:10px 12px 12px;background:var(--aw-bg);border-top:1px solid var(--aw-line)}' +
'.aw-input{flex:1;min-height:46px;max-height:132px;resize:none;border:1px solid rgb(var(--bf-slate-300, 207 213 230));border-radius:14px;padding:11px 14px;font:400 16px/1.45 Inter,sans-serif;color:var(--aw-ink);background:var(--aw-bg);outline:none;transition:border-color .15s,box-shadow .15s}' +
'.aw-input::placeholder{color:#8a90a3}' +
'.aw-input:focus{border-color:var(--aw-acc1);box-shadow:0 0 0 3px rgb(var(--bf-indigo-500, 79 107 237) / .18)}' +
'.aw-send{flex:0 0 auto;width:46px;height:46px;border:0;border-radius:14px;background:var(--aw-pri);color:rgb(var(--bf-white, 255 255 255));display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,opacity .15s}' +
'.aw-send svg{width:22px;height:22px}' +
'.aw-send:hover{background:var(--aw-pri2)}' +
'.aw-send:disabled{opacity:.35;cursor:default}' +
'.aw-mic{width:46px;height:46px;border:1px solid rgb(var(--bf-slate-300, 207 213 230));border-radius:14px}' +
'.aw-mic-on{background:rgb(var(--bf-red-600, 217 45 32));border-color:rgb(var(--bf-red-600, 217 45 32));color:rgb(var(--bf-white, 255 255 255))}' +
'.aw-mic-convo{background:var(--aw-ok-soft);border-color:#9fd8bd;color:var(--aw-ok)}' +
'.aw-sr{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}' +
/* toast */
'.aw-toast{position:fixed;right:24px;bottom:96px;z-index:9990;width:min(360px,calc(100vw - 32px));background:var(--aw-bg);color:var(--aw-ink);border:1px solid var(--aw-line);border-radius:16px;padding:12px 14px 14px;box-shadow:0 18px 48px rgb(var(--bf-indigo-950, 16 24 64) / .2);opacity:0;visibility:hidden;transform:translateY(8px);transition:opacity .2s ease,transform .2s ease,visibility 0s linear .2s}' +
'.aw-toast-show{opacity:1;visibility:visible;transform:none;transition:opacity .2s ease,transform .2s ease}' +
'.aw-toast-head{display:flex;align-items:center;gap:8px;font-size:15px}' +
'.aw-toast-head b{font:800 15px/1 Manrope,Inter,sans-serif}' +
'.aw-toast-head .aw-tag{margin:0}' +
'.aw-toast-x{margin-left:auto;width:36px;height:36px}' +
'.aw-toast-text{margin:8px 0 0;font-size:16px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}' +
'.aw-toast .aw-acts{margin-top:12px}' +
/* small screens: a full sheet */
'@media (max-width:639px){.aw-panel:not(.aw-page){left:0!important;top:0!important;right:0!important;bottom:0!important;width:100%;height:100%;border-radius:0}' +
'.aw-row{max-width:92%}.aw-stat b{font-size:18px}.aw-toast{right:12px;left:12px;width:auto}}' +
'@media (prefers-reduced-motion:reduce){.aw-orb,.aw-panel,.aw-toast,.aw-row,.aw-badge,.aw-card-chev{animation:none!important;transition:none!important}.aw-dots i,.aw-orb-thinking .aw-orb-ring{animation:none!important}}';
    var s = document.createElement('style');
    s.setAttribute('data-aisha', 'v2');
    s.textContent = css;
    document.head.appendChild(s);
  }
})();
