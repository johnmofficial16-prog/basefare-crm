/**
 * buddy-widget.js — the embedded AI buddy chat, on every CRM page.
 *
 * Self-contained: builds its own DOM + injects its own CSS (no Tailwind
 * dependency, no framework). Loaded with `defer` after tailwind.js on every
 * authed view (same mass-include pattern as error-beacon.js).
 *
 * FAIL-INVISIBLE CONTRACT: the widget renders NOTHING unless GET /buddy/boot
 * returns valid JSON with ok:true. Logged-out pages, customer-facing pages,
 * the TV board (PIN session, no user), or any server hiccup → no orb, no
 * errors, no broken page. The widget can only ever add itself on top of a
 * working page.
 *
 * Endpoints (all same-origin, CSRF via header from /buddy/boot):
 *   GET  /buddy/boot      → {ok, csrf, name, admin, nudges}
 *   GET  /buddy/history   → {messages, nudges}
 *   POST /buddy/greeting  → once-per-business-day greeting (server decides)
 *   POST /buddy/chat      → {reply, ai}
 */
(function () {
  'use strict';
  if (window.__buddyWidget) return;   // idempotent under double-include
  window.__buddyWidget = true;

  var CSRF = null;
  var booted = false;
  var opened = false;
  var greeted = false;

  // ── boot: decide whether this page gets a buddy at all ──────────────────
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
      booted = true;
      mount(d);
    })
    .catch(function () { /* no session / gated / error → stay invisible */ });

  function mount(boot) {
    injectCss();

    // ── Orb ────────────────────────────────────────────────────────────────
    var orb = el('button', 'bw-orb');
    orb.type = 'button';
    orb.setAttribute('aria-label', 'Open your AI buddy');
    orb.innerHTML =
      '<span class="bw-orb-core"></span>' +
      '<svg class="bw-orb-icon" viewBox="0 0 24 24" fill="none">' +
      '<path d="M12 3a7 7 0 0 1 7 7v1.5a6.5 6.5 0 0 1-6.5 6.5H12l-3.6 2.7c-.6.45-1.4-.06-1.4-.8V17A7 7 0 0 1 12 3Z" stroke="currentColor" stroke-width="1.6"/>' +
      '<circle cx="9.2" cy="10.6" r="1.1" fill="currentColor"/><circle cx="14.8" cy="10.6" r="1.1" fill="currentColor"/></svg>';
    var badge = el('span', 'bw-badge');
    var nudgeCount = (boot.nudges | 0);
    if (nudgeCount > 0) { badge.textContent = nudgeCount > 9 ? '9+' : String(nudgeCount); orb.appendChild(badge); }
    document.body.appendChild(orb);

    // ── Panel ──────────────────────────────────────────────────────────────
    var panel = el('div', 'bw-panel');
    panel.innerHTML =
      '<div class="bw-head">' +
        '<div class="bw-ava"><span class="bw-ava-ring"></span><span class="bw-ava-dot"></span></div>' +
        '<div class="bw-head-txt"><div class="bw-title">Buddy</div>' +
        '<div class="bw-sub"><span class="bw-live"></span>knows your numbers · never your customers’ data</div></div>' +
        (boot.admin ? '<a class="bw-maint" href="/buddy/maintenance" title="Maintenance buddy">SYS</a>' : '') +
        '<button type="button" class="bw-close" aria-label="Close">×</button>' +
      '</div>' +
      '<div class="bw-chips"></div>' +
      '<div class="bw-log"></div>' +
      '<form class="bw-form"><input class="bw-in" maxlength="500" autocomplete="off" ' +
        'placeholder="Ask about your day, month, bookings…">' +
        '<button type="submit" class="bw-send" aria-label="Send">' +
        '<svg viewBox="0 0 24 24" fill="none"><path d="M4 12 20 4l-4 8 4 8-16-8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>' +
        '</button></form>';
    document.body.appendChild(panel);

    var log   = panel.querySelector('.bw-log');
    var chips = panel.querySelector('.bw-chips');
    var input = panel.querySelector('.bw-in');
    var send  = panel.querySelector('.bw-send');

    var NUDGE_LABELS = {
      sale_praise_t1: '👏 Nice sale', sale_praise_t2: '🎉 Big sale',
      eticket_lag: '🎫 E-ticket pending', acceptance_lag: '⏳ Acceptance open',
      departure_24h: '✈️ Departs soon', dry_spell: '💪 Check-in'
    };

    orb.addEventListener('click', function () {
      opened = !opened;
      panel.classList.toggle('bw-open', opened);
      orb.classList.toggle('bw-orb-hidden', opened);
      if (opened && !panel.__loaded) { panel.__loaded = true; load(); }
      if (opened) input.focus();
    });
    panel.querySelector('.bw-close').addEventListener('click', function () {
      opened = false;
      panel.classList.remove('bw-open');
      orb.classList.remove('bw-orb-hidden');
    });

    function load() {
      fetch('/buddy/history')
        .then(function (r) { return r.json(); })
        .then(function (h) {
          (h.messages || []).forEach(function (m) { addMsg(m.role === 'user' ? 'user' : 'ai', m.content); });
          (h.nudges || []).forEach(function (n) {
            var c = el('button', 'bw-chip');
            c.type = 'button';
            c.textContent = NUDGE_LABELS[n.type] || n.type;
            c.addEventListener('click', function () {
              input.value = 'Tell me about: ' + (NUDGE_LABELS[n.type] || n.type);
              input.focus();
            });
            chips.appendChild(c);
          });
          if (!greeted) {
            greeted = true;
            var t = typing();
            fetch('/buddy/greeting', { method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: '{}' })
              .then(function (r) { return r.json(); })
              .then(function (g) {
                t.remove();
                if (g.greeted && g.reply) addMsg('ai', g.reply, g.ai === false);
                else if ((h.messages || []).length === 0) {
                  addMsg('ai', "Hey! I'm your buddy — I keep an eye on your sales, your open bookings and your wins. Ask me anything about your numbers.");
                }
              })
              .catch(function () { t.remove(); });
          }
        })
        .catch(function () { addMsg('ai', 'Could not load our chat — try reopening.'); });
    }

    panel.querySelector('.bw-form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var text = input.value.trim();
      if (!text || send.disabled) return;
      addMsg('user', text);
      input.value = '';
      send.disabled = true;
      var t = typing();
      fetch('/buddy/chat', { method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ message: text }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          t.remove();
          if (d.success) addMsg('ai', d.reply, d.ai === false);
          else addMsg('ai', d.error || 'Something went wrong — try again.');
        })
        .catch(function () { t.remove(); addMsg('ai', 'Network hiccup — try again.'); })
        .finally(function () { send.disabled = false; input.focus(); });
    });

    function addMsg(kind, text, degraded) {
      var row = el('div', 'bw-row bw-row-' + kind);
      if (kind === 'ai') {
        var av = el('div', 'bw-msg-ava');
        av.innerHTML = '<span></span>';
        row.appendChild(av);
      }
      var b = el('div', 'bw-msg bw-msg-' + kind);
      b.textContent = text;
      if (degraded) {
        var tag = el('div', 'bw-degraded');
        tag.textContent = 'AI offline — raw numbers';
        b.appendChild(tag);
      }
      row.appendChild(b);
      log.appendChild(row);
      log.scrollTop = log.scrollHeight;
      return row;
    }

    function typing() {
      var row = el('div', 'bw-row bw-row-ai');
      row.innerHTML = '<div class="bw-msg-ava bw-think"><span></span></div>' +
        '<div class="bw-msg bw-msg-ai bw-typing"><i></i><i></i><i></i></div>';
      log.appendChild(row);
      log.scrollTop = log.scrollHeight;
      return row;
    }
  }

  function el(tag, cls) { var e = document.createElement(tag); e.className = cls; return e; }

  function injectCss() {
    var css =
'.bw-orb{position:fixed;right:22px;bottom:22px;z-index:9990;width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;background:transparent;padding:0;transition:transform .25s ease,opacity .2s}' +
'.bw-orb:hover{transform:scale(1.08)}' +
'.bw-orb-hidden{opacity:0;pointer-events:none;transform:scale(.6)}' +
'.bw-orb-core{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 180deg,#163274,#5b8cff,#8b5cf6,#163274);animation:bw-spin 6s linear infinite;filter:saturate(1.2)}' +
'.bw-orb-core::after{content:"";position:absolute;inset:-6px;border-radius:50%;background:inherit;filter:blur(14px);opacity:.55;animation:bw-pulse 2.6s ease-in-out infinite}' +
'.bw-orb-icon{position:absolute;inset:0;margin:auto;width:26px;height:26px;color:#fff;z-index:1}' +
'.bw-badge{position:absolute;top:-3px;right:-3px;z-index:2;min-width:20px;height:20px;border-radius:10px;background:#ef4444;color:#fff;font:700 11px/20px Inter,sans-serif;text-align:center;padding:0 5px;box-shadow:0 0 0 2px #fff}' +
'@keyframes bw-spin{to{transform:rotate(360deg)}}' +
'@keyframes bw-pulse{0%,100%{opacity:.35}50%{opacity:.7}}' +
'.bw-panel{position:fixed;right:22px;bottom:22px;z-index:9991;width:min(390px,calc(100vw - 32px));height:min(560px,calc(100vh - 60px));display:flex;flex-direction:column;border-radius:20px;overflow:hidden;opacity:0;pointer-events:none;transform:translateY(24px) scale(.97);transition:all .28s cubic-bezier(.2,.9,.3,1.2);background:linear-gradient(160deg,rgba(13,20,44,.97),rgba(22,50,116,.95));backdrop-filter:blur(18px);box-shadow:0 24px 70px rgba(10,16,40,.55),0 0 0 1px rgba(120,150,255,.18),0 0 40px rgba(91,140,255,.12);font-family:Inter,sans-serif}' +
'.bw-open{opacity:1;pointer-events:auto;transform:translateY(0) scale(1)}' +
'.bw-head{display:flex;align-items:center;gap:11px;padding:14px 16px;border-bottom:1px solid rgba(120,150,255,.15)}' +
'.bw-ava{position:relative;width:38px;height:38px;flex:0 0 auto}' +
'.bw-ava-ring{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 0deg,#5b8cff,#8b5cf6,#22d3ee,#5b8cff);animation:bw-spin 4s linear infinite}' +
'.bw-ava-dot{position:absolute;inset:4px;border-radius:50%;background:#0d142c}' +
'.bw-ava::after{content:"";position:absolute;inset:12px;border-radius:50%;background:radial-gradient(circle,#7fa4ff 0%,#5b8cff 60%,transparent 70%);animation:bw-pulse 2s ease-in-out infinite}' +
'.bw-head-txt{flex:1;min-width:0}' +
'.bw-title{color:#fff;font-weight:800;font-size:15px;letter-spacing:.3px;font-family:Manrope,Inter,sans-serif}' +
'.bw-sub{color:#93a5d9;font-size:10.5px;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
'.bw-live{width:6px;height:6px;border-radius:50%;background:#34d399;box-shadow:0 0 6px #34d399;animation:bw-pulse 2s infinite;flex:0 0 auto}' +
'.bw-maint{color:#8fb0ff;font:700 10px/1 Inter;letter-spacing:.12em;text-decoration:none;border:1px solid rgba(143,176,255,.4);border-radius:7px;padding:5px 7px;margin-right:2px}' +
'.bw-maint:hover{background:rgba(143,176,255,.15)}' +
'.bw-close{background:none;border:0;color:#93a5d9;font-size:22px;cursor:pointer;line-height:1;padding:2px 6px}' +
'.bw-close:hover{color:#fff}' +
'.bw-chips{display:flex;gap:6px;flex-wrap:wrap;padding:8px 14px 0}' +
'.bw-chips:empty{display:none}' +
'.bw-chip{background:rgba(91,140,255,.14);border:1px solid rgba(120,150,255,.3);color:#c8d6ff;border-radius:999px;font:600 11px/1 Inter;padding:7px 11px;cursor:pointer;transition:all .15s}' +
'.bw-chip:hover{background:rgba(91,140,255,.3);color:#fff}' +
'.bw-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth}' +
'.bw-log::-webkit-scrollbar{width:5px}.bw-log::-webkit-scrollbar-thumb{background:rgba(120,150,255,.3);border-radius:3px}' +
'.bw-row{display:flex;gap:8px;align-items:flex-end;animation:bw-in .25s ease}' +
'.bw-row-user{justify-content:flex-end}' +
'@keyframes bw-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}' +
'.bw-msg-ava{width:24px;height:24px;border-radius:50%;flex:0 0 auto;background:conic-gradient(from 0deg,#5b8cff,#8b5cf6,#22d3ee,#5b8cff);position:relative}' +
'.bw-msg-ava span{position:absolute;inset:2px;border-radius:50%;background:#0d142c}' +
'.bw-msg-ava::after{content:"";position:absolute;inset:7px;border-radius:50%;background:#7fa4ff}' +
'.bw-think{animation:bw-spin 1.2s linear infinite}' +
'.bw-msg{max-width:78%;padding:10px 13px;border-radius:15px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}' +
'.bw-msg-ai{background:rgba(255,255,255,.07);color:#e7ecff;border:1px solid rgba(120,150,255,.14);border-bottom-left-radius:5px}' +
'.bw-msg-user{background:linear-gradient(135deg,#3b62c4,#5b8cff);color:#fff;border-bottom-right-radius:5px;box-shadow:0 4px 14px rgba(91,140,255,.3)}' +
'.bw-degraded{margin-top:7px;font-size:9.5px;color:#93a5d9;letter-spacing:.04em}' +
'.bw-typing{display:flex;gap:4px;align-items:center;padding:13px 15px}' +
'.bw-typing i{width:6px;height:6px;border-radius:50%;background:#8fb0ff;animation:bw-dot 1.2s infinite}' +
'.bw-typing i:nth-child(2){animation-delay:.15s}.bw-typing i:nth-child(3){animation-delay:.3s}' +
'@keyframes bw-dot{0%,60%,100%{transform:translateY(0);opacity:.4}30%{transform:translateY(-5px);opacity:1}}' +
'.bw-form{display:flex;gap:8px;padding:12px 14px;border-top:1px solid rgba(120,150,255,.15)}' +
'.bw-in{flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(120,150,255,.22);border-radius:12px;color:#fff;font:400 13px/1.4 Inter;padding:10px 13px;outline:none;transition:border .15s}' +
'.bw-in::placeholder{color:#7487bd}' +
'.bw-in:focus{border-color:#5b8cff;box-shadow:0 0 0 3px rgba(91,140,255,.18)}' +
'.bw-send{width:40px;height:40px;flex:0 0 auto;border:0;border-radius:12px;cursor:pointer;background:linear-gradient(135deg,#3b62c4,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;transition:all .15s}' +
'.bw-send svg{width:18px;height:18px}' +
'.bw-send:hover{filter:brightness(1.15)}' +
'.bw-send:disabled{opacity:.5;cursor:default}' +
'@media (max-width:480px){.bw-panel{right:8px;bottom:8px;border-radius:16px}}';
    var s = document.createElement('style');
    s.textContent = css;
    document.head.appendChild(s);
  }
})();
