<?php
/**
 * Staff survey pop-up — included once per page by notification_bell.php
 * (which every sidebar includes).
 *
 * Shows for agents, CSAs, supervisors and managers who haven't answered the
 * current survey (StaffSurveyService::KEY):
 *   - right after each sign-in ($_SESSION['staff_survey_due']),
 *   - when they go idle (no mouse/keyboard for IDLE_MINUTES), unless snoozed,
 *   - on every page once "Not now" has been used MAX_SKIPS times.
 * Self-contained: its own scoped CSS and JS. Renders nothing on any error, so
 * it can never break a page (e.g. before the migration has run).
 */
if (!empty($GLOBALS['__staffSurveyIncluded'])) {
    return;
}
$GLOBALS['__staffSurveyIncluded'] = true;

$__ssvRole = (string) ($_SESSION['role'] ?? '');
$__ssvUser = (int) ($_SESSION['user_id'] ?? 0);
if (!$__ssvUser || !\App\Services\StaffSurveyService::appliesTo($__ssvRole)) {
    return;
}
try {
    $__ssvState = \App\Services\StaffSurveyService::state($__ssvUser);
} catch (\Throwable $e) {
    error_log('[staff survey] skipped: ' . $e->getMessage());
    return;
}
if ($__ssvState['done']) {
    return;
}
$__ssvCfg = [
    'key'         => \App\Services\StaffSurveyService::KEY,
    'user'        => $__ssvUser,
    'csrf'        => $_SESSION['csrf_token'] ?? '',
    'due'         => !empty($_SESSION['staff_survey_due']),
    'skipsLeft'   => $__ssvState['skips_left'],
    'snoozedUntil' => $__ssvState['snoozed_until'] ? strtotime($__ssvState['snoozed_until']) * 1000 : 0,
    'idleMs'      => \App\Services\StaffSurveyService::IDLE_MINUTES * 60000,
    'first'       => explode(' ', trim((string) ($_SESSION['user_name'] ?? '')))[0] ?? '',
    'pages'       => \App\Services\StaffSurveyService::PAGES,
];
?>
<style>
#ssv{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(10,20,45,.55);backdrop-filter:blur(3px);font-family:Inter,system-ui,sans-serif;color:#191c1d}
#ssv.is-on{display:flex}
#ssv .ssv-card{width:min(720px,100%);max-height:calc(100vh - 32px);display:flex;flex-direction:column;background:#fff;border-radius:20px;box-shadow:0 30px 80px -20px rgba(10,20,45,.55);overflow:hidden;animation:ssvIn .3s ease both}
@keyframes ssvIn{from{transform:translateY(12px)}to{transform:none}} /* no fade: the card is never see-through */
#ssv .ssv-head{padding:22px 28px 14px;border-bottom:1px solid #eef1f6}
#ssv .ssv-top{display:flex;align-items:center;justify-content:space-between;gap:12px}
#ssv .ssv-kicker{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#163274}
#ssv h2{font-family:Manrope,Inter,sans-serif;font-size:24px;font-weight:800;color:#163274;margin:6px 0 4px;line-height:1.2}
#ssv .ssv-intro{font-size:15px;color:#434653;margin:0}
#ssv .ssv-bar{height:6px;border-radius:99px;background:#eef1f6;margin-top:14px;overflow:hidden}
#ssv .ssv-bar i{display:block;height:100%;background:#163274;border-radius:inherit;transition:width .3s ease}
#ssv .ssv-body{padding:8px 28px 8px;overflow-y:auto;flex:1}
#ssv .ssv-q{padding:18px 0;border-bottom:1px solid #f1f3f7}
#ssv .ssv-q:last-child{border-bottom:0}
#ssv .ssv-q label,#ssv .ssv-qt{display:block;font-size:16px;font-weight:600;margin-bottom:10px;line-height:1.4}
#ssv .ssv-opt{font-weight:400;color:#6b7280;font-size:14px}
#ssv .ssv-scale{display:flex;gap:8px;flex-wrap:wrap}
#ssv .ssv-scale button{min-width:48px;height:48px;padding:0 12px;border-radius:12px;border:1.5px solid #d6dbe6;background:#fff;font-size:16px;font-weight:700;color:#163274;cursor:pointer;transition:all .12s ease}
#ssv .ssv-scale.is-nps button{min-width:44px;height:44px;font-size:15px}
#ssv .ssv-scale button:hover{border-color:#163274}
#ssv .ssv-scale button[aria-pressed="true"]{background:#163274;border-color:#163274;color:#fff}
#ssv .ssv-ends{display:flex;justify-content:space-between;font-size:13px;color:#6b7280;margin-top:6px;max-width:100%}
#ssv .ssv-chips{display:flex;flex-wrap:wrap;gap:8px}
#ssv .ssv-chips button{padding:10px 14px;border-radius:999px;border:1.5px solid #d6dbe6;background:#fff;font-size:15px;color:#191c1d;cursor:pointer;text-align:left;line-height:1.3;transition:all .12s ease}
#ssv .ssv-chips button:hover{border-color:#163274}
#ssv .ssv-chips button[aria-pressed="true"]{background:#e8edf9;border-color:#163274;color:#163274;font-weight:600}
#ssv textarea{width:100%;min-height:84px;border:1.5px solid #d6dbe6;border-radius:12px;padding:12px 14px;font:inherit;font-size:16px;resize:vertical;outline:none;box-sizing:border-box}
#ssv textarea:focus{border-color:#163274;box-shadow:0 0 0 3px rgba(22,50,116,.15)}
#ssv .ssv-err{display:none;color:#b3261e;font-size:14px;font-weight:600;margin-top:8px}
#ssv .ssv-q.has-err .ssv-err{display:block}
#ssv .ssv-q.has-err .ssv-qt,#ssv .ssv-q.has-err label{color:#b3261e}
#ssv .ssv-foot{display:flex;align-items:center;gap:12px;padding:16px 28px;border-top:1px solid #eef1f6;background:#fafbfd;flex-wrap:wrap}
#ssv .ssv-foot .ssv-sp{flex:1}
#ssv .ssv-btn{height:46px;padding:0 22px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;border:1.5px solid transparent;font-family:Manrope,Inter,sans-serif}
#ssv .ssv-pri{background:#163274;color:#fff}
#ssv .ssv-pri:hover{background:#314a8d}
#ssv .ssv-pri[disabled]{opacity:.6;cursor:wait}
#ssv .ssv-sec{background:#fff;border-color:#d6dbe6;color:#163274}
#ssv .ssv-link{background:none;border:0;color:#434653;font-size:14px;font-weight:600;cursor:pointer;text-decoration:underline;padding:6px 2px}
#ssv .ssv-later{font-size:13px;color:#6b7280}
#ssv .ssv-msg{display:none;margin:12px 28px 0;padding:12px 14px;border-radius:12px;background:#fdecea;color:#8a1c14;font-size:14px;font-weight:600}
#ssv .ssv-msg.is-on{display:block}
#ssv .ssv-done{padding:44px 28px;text-align:center}
#ssv .ssv-done .material-symbols-outlined{font-size:56px;color:#1d6a44}
#ssv .ssv-done p{font-size:16px;color:#434653;margin:8px auto 0;max-width:460px;line-height:1.5}
#ssv :focus-visible{outline:3px solid #f2b705;outline-offset:2px}
@media (max-width:640px){#ssv .ssv-head,#ssv .ssv-body,#ssv .ssv-foot{padding-left:18px;padding-right:18px}#ssv h2{font-size:21px}}
body.ssv-lock{overflow:hidden}
</style>
<div id="ssv" role="dialog" aria-modal="true" aria-labelledby="ssvTitle" aria-hidden="true"></div>
<script>
(function () {
  if (window.__staffSurvey) return;
  window.__staffSurvey = true;
  var CFG = <?= json_encode($__ssvCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var root = document.getElementById('ssv');
  var DRAFT = 'ssv:' + CFG.key + ':' + CFG.user;
  var pages = CFG.pages;
  var page = -1;             // -1 = the welcome page
  var open = false;
  var sending = false;
  var answers = {};
  var lastInput = Date.now();
  var opener = null;

  try { answers = JSON.parse(localStorage.getItem(DRAFT) || '{}') || {}; } catch (e) { answers = {}; }
  function saveDraft() { try { localStorage.setItem(DRAFT, JSON.stringify(answers)); } catch (e) {} }

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    if (attrs) for (var k in attrs) { if (k === 'class') n.className = attrs[k]; else n.setAttribute(k, attrs[k]); }
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function forced() { return CFG.skipsLeft <= 0; }

  // ---- drawing --------------------------------------------------------------
  function head(kicker, title, intro, progress) {
    var h = el('div', { class: 'ssv-head' });
    var top = el('div', { class: 'ssv-top' });
    top.appendChild(el('span', { class: 'ssv-kicker' }, kicker));
    h.appendChild(top);
    h.appendChild(el('h2', { id: 'ssvTitle' }, title));
    if (intro) h.appendChild(el('p', { class: 'ssv-intro' }, intro));
    if (progress !== null) {
      var bar = el('div', { class: 'ssv-bar', 'aria-hidden': 'true' });
      var i = el('i'); i.style.width = progress + '%';
      bar.appendChild(i); h.appendChild(bar);
    }
    return h;
  }

  function question(q) {
    var box = el('div', { class: 'ssv-q', 'data-q': q.key });
    var qid = 'ssvq-' + q.key;
    if (q.type === 'text') {
      var lab = el('label', { for: qid }, q.label + ' ');
      lab.appendChild(el('span', { class: 'ssv-opt' }, '(optional)'));
      box.appendChild(lab);
      var ta = el('textarea', { id: qid, maxlength: '1500', placeholder: q.placeholder || '' });
      ta.value = answers[q.key] || '';
      ta.addEventListener('input', function () { answers[q.key] = ta.value; saveDraft(); });
      box.appendChild(ta);
    } else {
      box.appendChild(el('div', { class: 'ssv-qt', id: qid }, q.label));
      var group = el('div', { class: (q.type === 'scale' || q.type === 'nps') ? 'ssv-scale' + (q.type === 'nps' ? ' is-nps' : '') : 'ssv-chips', role: 'group', 'aria-labelledby': qid });
      var values = q.type === 'scale' ? [1, 2, 3, 4, 5] : q.type === 'nps' ? [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10] : q.options;
      values.forEach(function (v) {
        var b = el('button', { type: 'button' }, String(v));
        var on = q.type === 'multi' ? (answers[q.key] || []).indexOf(v) !== -1 : answers[q.key] === v;
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (q.type === 'scale' || q.type === 'nps') {
          var name = v === values[0] ? q.low : v === values[values.length - 1] ? q.high : '';
          b.setAttribute('aria-label', v + (name ? ' (' + name + ')' : ''));
        }
        b.addEventListener('click', function () {
          if (q.type === 'multi') {
            var cur = (answers[q.key] || []).slice();
            var at = cur.indexOf(v);
            if (at !== -1) cur.splice(at, 1);
            else if (cur.length >= (q.max || 3)) { flagError(box, 'You can pick up to ' + (q.max || 3) + '. Untick one first.'); return; }
            else cur.push(v);
            answers[q.key] = cur;
          } else {
            answers[q.key] = v;
          }
          saveDraft();
          Array.prototype.forEach.call(group.children, function (x) {
            var val = (q.type === 'scale' || q.type === 'nps') ? Number(x.textContent) : x.textContent;
            var sel = q.type === 'multi' ? answers[q.key].indexOf(val) !== -1 : answers[q.key] === val;
            x.setAttribute('aria-pressed', sel ? 'true' : 'false');
          });
          box.classList.remove('has-err');
        });
        group.appendChild(b);
      });
      box.appendChild(group);
      if (q.type === 'scale' || q.type === 'nps') {
        var ends = el('div', { class: 'ssv-ends', 'aria-hidden': 'true' });
        ends.appendChild(el('span', null, q.low)); ends.appendChild(el('span', null, q.high));
        box.appendChild(ends);
      }
    }
    box.appendChild(el('div', { class: 'ssv-err', role: 'alert' }));
    return box;
  }

  function flagError(box, msg) {
    box.classList.add('has-err');
    box.querySelector('.ssv-err').textContent = msg;
  }

  function foot(buttons) {
    var f = el('div', { class: 'ssv-foot' });
    buttons.forEach(function (b) { f.appendChild(b); });
    return f;
  }

  function laterButtons() {
    var out = [];
    if (!forced()) {
      var later = el('button', { type: 'button', class: 'ssv-link' }, 'Not now');
      later.addEventListener('click', skip);
      out.push(later);
      out.push(el('span', { class: 'ssv-later' }, 'You can put it off ' + (CFG.skipsLeft === 1 ? 'one more time.' : CFG.skipsLeft + ' more times.')));
    } else {
      var so = el('a', { href: '/logout', class: 'ssv-link' }, 'Sign out');
      out.push(so);
    }
    return out;
  }

  function draw() {
    root.textContent = '';
    var card = el('div', { class: 'ssv-card' });
    if (page === -1) {
      card.appendChild(head('Staff survey', (CFG.first ? CFG.first + ', ' : '') + 'tell us how it’s going',
        'A few minutes about your work here, how you feel and where you want to grow. Your answers help shape training, incentives and the way we work.', null));
      var b = el('div', { class: 'ssv-body' });
      var p = el('p', { class: 'ssv-intro' }, pages.length + ' short pages · about 5 minutes · your progress is kept if you stop half-way.');
      p.style.padding = '18px 0';
      b.appendChild(p);
      card.appendChild(b);
      var start = el('button', { type: 'button', class: 'ssv-btn ssv-pri' }, Object.keys(answers).length ? 'Carry on' : 'Start');
      start.addEventListener('click', function () { go(0); });
      card.appendChild(foot(laterButtons().concat([el('span', { class: 'ssv-sp' }), start])));
    } else if (page === pages.length) {
      var d = el('div', { class: 'ssv-done' });
      d.appendChild(el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, 'task_alt'));
      d.appendChild(el('h2', { id: 'ssvTitle' }, 'Thank you' + (CFG.first ? ', ' + CFG.first : '') + '!'));
      d.appendChild(el('p', null, 'Your answers have been sent. They help us plan what comes next for you and the team.'));
      card.appendChild(d);
      var close = el('button', { type: 'button', class: 'ssv-btn ssv-pri' }, 'Back to work');
      close.addEventListener('click', hide);
      card.appendChild(foot([el('span', { class: 'ssv-sp' }), close]));
    } else {
      var pg = pages[page];
      card.appendChild(head('Page ' + (page + 1) + ' of ' + pages.length, pg.title, pg.intro, Math.round(((page + 1) / pages.length) * 100)));
      var msg = el('div', { class: 'ssv-msg', role: 'alert', id: 'ssvMsg' });
      card.appendChild(msg);
      var body = el('div', { class: 'ssv-body' });
      pg.questions.forEach(function (q) { body.appendChild(question(q)); });
      card.appendChild(body);
      var back = el('button', { type: 'button', class: 'ssv-btn ssv-sec' }, 'Back');
      back.addEventListener('click', function () { go(page - 1); });
      var last = page === pages.length - 1;
      var next = el('button', { type: 'button', class: 'ssv-btn ssv-pri', id: 'ssvNext' }, last ? 'Send my answers' : 'Next');
      next.addEventListener('click', function () { if (checkPage()) { if (last) send(); else go(page + 1); } });
      card.appendChild(foot(laterButtons().concat([el('span', { class: 'ssv-sp' }), back, next])));
    }
    root.appendChild(card);
    var firstFocus = root.querySelector('.ssv-body button, .ssv-body textarea, .ssv-foot .ssv-pri');
    if (firstFocus) firstFocus.focus({ preventScroll: true });
  }

  function go(i) {
    page = Math.max(-1, Math.min(pages.length, i));
    draw();
    var b = root.querySelector('.ssv-body');
    if (b) b.scrollTop = 0;
  }

  function checkPage() {
    var ok = true, firstBad = null;
    pages[page].questions.forEach(function (q) {
      if (q.type === 'text') return;
      var v = answers[q.key];
      var missing = q.type === 'multi' ? !(v && v.length) : (v === undefined || v === null || v === '');
      if (missing) {
        var box = root.querySelector('[data-q="' + q.key + '"]');
        flagError(box, q.type === 'multi' ? 'Please pick at least one.' : 'Please pick an answer.');
        ok = false; firstBad = firstBad || box;
      }
    });
    if (firstBad) firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return ok;
  }

  // ---- talking to the server ----------------------------------------------------
  function post(url, data) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': CFG.csrf },
      body: JSON.stringify(data)
    }).then(function (r) {
      var json = (r.headers.get('Content-Type') || '').indexOf('application/json') !== -1;
      return (json ? r.json() : Promise.resolve(null)).then(function (d) { return { status: r.status, data: d }; });
    });
  }

  function showMsg(text) {
    var m = document.getElementById('ssvMsg');
    if (m) { m.textContent = text; m.classList.add('is-on'); m.scrollIntoView({ block: 'nearest' }); }
  }

  function send() {
    if (sending) return;
    sending = true;
    var btn = document.getElementById('ssvNext');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    post('/survey', { answers: answers }).then(function (r) {
      if (r.status === 200 && r.data && r.data.ok) {
        try { localStorage.removeItem(DRAFT); } catch (e) {}
        go(pages.length);
        return;
      }
      if (r.status === 422 && r.data && r.data.fields) {
        var keys = Object.keys(r.data.fields);
        for (var i = 0; i < pages.length; i++) {
          if (pages[i].questions.some(function (q) { return keys.indexOf(q.key) !== -1; })) { go(i); break; }
        }
        keys.forEach(function (k) { var box = root.querySelector('[data-q="' + k + '"]'); if (box) flagError(box, r.data.fields[k]); });
        showMsg(r.data.error || 'A few questions still need an answer.');
        return;
      }
      if (!r.data && (r.status === 403 || r.status === 406 || r.status === 429)) {
        showMsg('The internet firewall stopped this from sending. Your answers are kept on this computer. Please try again in a minute.');
      } else if (r.status === 401 || (r.data && r.data.error && /sign/i.test(r.data.error))) {
        showMsg('You were signed out. Your answers are kept on this computer. Sign in again and the survey will open where you left off.');
      } else {
        showMsg((r.data && r.data.error) || 'That didn’t send. Your answers are kept. Please try again.');
      }
    }).catch(function () {
      showMsg('Can’t reach the CRM. Check the internet connection and try again. Your answers are kept.');
    }).then(function () {
      sending = false;
      var b = document.getElementById('ssvNext');
      if (b && page < pages.length) { b.disabled = false; b.textContent = 'Send my answers'; }
    });
  }

  function skip() {
    post('/survey/skip', {}).then(function (r) {
      if (r.data && r.data.ok) {
        CFG.skipsLeft = r.data.skips_left;
        CFG.snoozedUntil = r.data.snoozed_ms || Date.now() + 30 * 60000;
        CFG.due = false;
        hide();
      } else if (r.data && r.status === 409) {
        CFG.skipsLeft = 0;
        draw();
      } else {
        // Couldn't record the skip (offline, firewall): let them carry on for now.
        CFG.snoozedUntil = Date.now() + 10 * 60000;
        hide();
      }
    }).catch(function () { CFG.snoozedUntil = Date.now() + 10 * 60000; hide(); });
  }

  // ---- showing and hiding -------------------------------------------------------
  function show() {
    if (open) return;
    open = true;
    opener = document.activeElement;
    root.classList.add('is-on');
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ssv-lock');
    go(Object.keys(answers).length ? firstUnfinished() : -1);
  }
  function hide() {
    open = false;
    root.classList.remove('is-on');
    root.setAttribute('aria-hidden', 'true');
    root.textContent = '';
    document.body.classList.remove('ssv-lock');
    lastInput = Date.now();
    if (opener && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
  }
  function firstUnfinished() {
    for (var i = 0; i < pages.length; i++) {
      var missing = pages[i].questions.some(function (q) {
        if (q.type === 'text') return false;
        var v = answers[q.key];
        return q.type === 'multi' ? !(v && v.length) : (v === undefined || v === null || v === '');
      });
      if (missing) return i;
    }
    return pages.length - 1;
  }

  // Keep keyboard focus inside the survey while it is open.
  document.addEventListener('keydown', function (e) {
    if (!open) return;
    if (e.key === 'Escape') { e.preventDefault(); return; }
    if (e.key !== 'Tab') return;
    var items = Array.prototype.filter.call(root.querySelectorAll('button:not([disabled]), textarea, a[href]'), function (x) { return x.offsetParent !== null; });
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }, true);

  // Idle: no mouse or keyboard for a while (and not snoozed) brings it up.
  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach(function (ev) {
    window.addEventListener(ev, function () { lastInput = Date.now(); }, { passive: true, capture: true });
  });
  setInterval(function () {
    if (open) return;
    var now = Date.now();
    if (now - lastInput >= CFG.idleMs && now >= CFG.snoozedUntil) show();
  }, 10000);

  // Straight after sign-in, or every page once "Not now" is used up.
  if (CFG.due || forced()) {
    var start = function () { setTimeout(show, 1200); };
    if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
  }
})();
</script>
