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
 *
 * People who already answered but left the written questions empty get a
 * short "one more minute" card instead (StaffSurveyService::more()), at
 * sign-in or when idle. It can be declined; it never blocks them.
 *
 * Getting people to write: follow-up questions appear under low scores, every
 * box has tap-to-start phrases and voice typing (English or Hindi), any
 * language is welcome, and an empty page gets one gentle nudge before moving on.
 *
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
if ($__ssvState['done'] && !$__ssvState['more']) {
    return;
}
$__ssvCfg = [
    'key'         => \App\Services\StaffSurveyService::KEY,
    'user'        => $__ssvUser,
    'csrf'        => $_SESSION['csrf_token'] ?? '',
    'mode'        => $__ssvState['done'] ? 'more' : 'survey',
    'due'         => !empty($_SESSION['staff_survey_due']),
    'skipsLeft'   => $__ssvState['skips_left'],
    'snoozedUntil' => $__ssvState['snoozed_until'] ? strtotime($__ssvState['snoozed_until']) * 1000 : 0,
    'idleMs'      => \App\Services\StaffSurveyService::IDLE_MINUTES * 60000,
    'first'       => explode(' ', trim((string) ($_SESSION['user_name'] ?? '')))[0] ?? '',
    'pages'       => \App\Services\StaffSurveyService::PAGES,
    'more'        => $__ssvState['more'],
];
?>
<style>
#ssv{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(10,20,45,.55);backdrop-filter:blur(3px);font-family:Inter,system-ui,sans-serif;color:rgb(var(--bf-on-surface))}
#ssv.is-on{display:flex}
#ssv .ssv-card{width:min(720px,100%);max-height:calc(100vh - 32px);display:flex;flex-direction:column;background:rgb(var(--bf-white));border-radius:20px;box-shadow:0 30px 80px -20px rgba(10,20,45,.55);overflow:hidden;animation:ssvIn .3s ease both}
@keyframes ssvIn{from{transform:translateY(12px)}to{transform:none}} /* no fade: the card is never see-through */
#ssv .ssv-head{padding:22px 28px 14px;border-bottom:1px solid rgb(var(--bf-slate-100))}
#ssv .ssv-top{display:flex;align-items:center;justify-content:space-between;gap:12px}
#ssv .ssv-kicker{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgb(var(--bf-primary))}
#ssv h2{font-family:Manrope,Inter,sans-serif;font-size:24px;font-weight:800;color:rgb(var(--bf-primary));margin:6px 0 4px;line-height:1.2}
#ssv .ssv-intro{font-size:15px;color:rgb(var(--bf-on-surface-variant));margin:0;line-height:1.5}
#ssv .ssv-bar{height:6px;border-radius:99px;background:rgb(var(--bf-slate-100));margin-top:14px;overflow:hidden}
#ssv .ssv-bar i{display:block;height:100%;background:rgb(var(--bf-primary));border-radius:inherit;transition:width .3s ease}
#ssv .ssv-body{padding:8px 28px 8px;overflow-y:auto;flex:1}
#ssv .ssv-q{padding:18px 0;border-bottom:1px solid rgb(var(--bf-slate-100))}
#ssv .ssv-q:last-child{border-bottom:0}
#ssv .ssv-q label,#ssv .ssv-qt{display:block;font-size:16px;font-weight:600;margin-bottom:10px;line-height:1.4}
#ssv .ssv-ctx{display:block;font-size:13px;font-weight:600;color:rgb(var(--bf-slate-500));margin-bottom:4px}
#ssv .ssv-scale{display:flex;gap:8px;flex-wrap:wrap}
#ssv .ssv-scale button{min-width:48px;height:48px;padding:0 12px;border-radius:12px;border:1.5px solid rgb(var(--bf-slate-300));background:rgb(var(--bf-white));font-size:16px;font-weight:700;color:rgb(var(--bf-primary));cursor:pointer;transition:all .12s ease}
#ssv .ssv-scale.is-nps button{min-width:44px;height:44px;font-size:15px}
#ssv .ssv-scale button:hover{border-color:rgb(var(--bf-primary))}
#ssv .ssv-scale button[aria-pressed="true"]{background:rgb(var(--bf-primary));border-color:rgb(var(--bf-primary));color:rgb(var(--bf-white))}
#ssv .ssv-ends{display:flex;justify-content:space-between;font-size:13px;color:rgb(var(--bf-slate-500));margin-top:6px;max-width:100%}
#ssv .ssv-chips{display:flex;flex-wrap:wrap;gap:8px}
#ssv .ssv-chips button{padding:10px 14px;border-radius:999px;border:1.5px solid rgb(var(--bf-slate-300));background:rgb(var(--bf-white));font-size:15px;color:rgb(var(--bf-on-surface));cursor:pointer;text-align:left;line-height:1.3;transition:all .12s ease}
#ssv .ssv-chips button:hover{border-color:rgb(var(--bf-primary))}
#ssv .ssv-chips button[aria-pressed="true"]{background:rgb(var(--bf-blue-50));border-color:rgb(var(--bf-primary));color:rgb(var(--bf-primary));font-weight:600}
/* Written answers */
#ssv .ssv-write{position:relative}
#ssv textarea{width:100%;min-height:84px;border:1.5px solid rgb(var(--bf-slate-300));border-radius:12px;padding:12px 14px 12px;font:inherit;font-size:16px;line-height:1.45;resize:vertical;outline:none;box-sizing:border-box;background:rgb(var(--bf-white))}
#ssv textarea:focus{border-color:rgb(var(--bf-primary));box-shadow:0 0 0 3px rgb(var(--bf-primary) / .15)}
#ssv .ssv-start{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
#ssv .ssv-start button{padding:6px 11px;border-radius:999px;border:1px dashed rgb(var(--bf-slate-300));background:rgb(var(--bf-slate-50));font-size:14px;color:rgb(var(--bf-primary-container));cursor:pointer;line-height:1.3}
#ssv .ssv-start button:hover{border-style:solid;border-color:rgb(var(--bf-primary));background:rgb(var(--bf-indigo-50))}
#ssv .ssv-tools{display:flex;align-items:center;gap:10px;margin-top:6px;min-height:28px;flex-wrap:wrap}
#ssv .ssv-mic{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:999px;border:1.5px solid rgb(var(--bf-slate-300));background:rgb(var(--bf-white));font-size:14px;font-weight:600;color:rgb(var(--bf-primary));cursor:pointer}
#ssv .ssv-mic .material-symbols-outlined{font-size:18px}
#ssv .ssv-mic.is-rec{background:rgb(var(--bf-red-700));border-color:rgb(var(--bf-red-700));color:rgb(var(--bf-white));animation:ssvPulse 1.2s ease-in-out infinite}
@keyframes ssvPulse{50%{box-shadow:0 0 0 6px rgb(var(--bf-red-700) / .18)}}
#ssv .ssv-lang{background:none;border:0;padding:4px 2px;font-size:14px;color:rgb(var(--bf-slate-500));cursor:pointer}
#ssv .ssv-lang span{padding:2px 4px;border-radius:6px}
#ssv .ssv-lang span.is-on{color:rgb(var(--bf-primary));font-weight:700;background:rgb(var(--bf-blue-50))}
#ssv .ssv-cheer{margin-left:auto;font-size:13px;font-weight:600;color:rgb(var(--bf-green-800))}
#ssv .ssv-cheer.is-mid{color:rgb(var(--bf-yellow-800))}
#ssv .ssv-follow{margin-top:14px;padding:14px 16px;border-radius:14px;background:rgb(var(--bf-violet-50));border:1px solid rgb(var(--bf-slate-200));animation:ssvIn .25s ease both}
#ssv .ssv-follow label{font-size:15px;margin-bottom:8px}
#ssv .ssv-follow textarea{min-height:68px}
#ssv .ssv-err{display:none;color:rgb(var(--bf-red-700));font-size:14px;font-weight:600;margin-top:8px}
#ssv .ssv-q.has-err .ssv-err{display:block}
#ssv .ssv-q.has-err .ssv-qt,#ssv .ssv-q.has-err > label{color:rgb(var(--bf-red-700))}
#ssv .ssv-nudge{display:none;margin:0 28px;padding:14px 16px;border-radius:14px;background:rgb(var(--bf-yellow-50));border:1px solid rgb(var(--bf-amber-200));color:rgb(var(--bf-yellow-900));font-size:15px;line-height:1.45}
#ssv .ssv-nudge.is-on{display:block;animation:ssvIn .25s ease both}
#ssv .ssv-nudge b{display:block;font-size:16px;color:rgb(var(--bf-yellow-950));margin-bottom:2px}
#ssv .ssv-nudge .ssv-nb{display:flex;gap:10px;margin-top:10px;flex-wrap:wrap}
#ssv .ssv-foot{display:flex;align-items:center;gap:12px;padding:16px 28px;border-top:1px solid rgb(var(--bf-slate-100));background:rgb(var(--bf-slate-50));flex-wrap:wrap}
#ssv .ssv-foot .ssv-sp{flex:1}
#ssv .ssv-btn{height:46px;padding:0 22px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;border:1.5px solid transparent;font-family:Manrope,Inter,sans-serif}
#ssv .ssv-btn.is-sm{height:40px;padding:0 16px;font-size:14px}
#ssv .ssv-pri{background:rgb(var(--bf-primary));color:rgb(var(--bf-white))}
#ssv .ssv-pri:hover{background:rgb(var(--bf-primary-container))}
#ssv .ssv-pri[disabled]{opacity:.6;cursor:wait}
#ssv .ssv-sec{background:rgb(var(--bf-white));border-color:rgb(var(--bf-slate-300));color:rgb(var(--bf-primary))}
#ssv .ssv-link{background:none;border:0;color:rgb(var(--bf-on-surface-variant));font-size:14px;font-weight:600;cursor:pointer;text-decoration:underline;padding:6px 2px}
#ssv .ssv-later{font-size:13px;color:rgb(var(--bf-slate-500))}
#ssv .ssv-msg{display:none;margin:12px 28px 0;padding:12px 14px;border-radius:12px;background:rgb(var(--bf-rose-100));color:rgb(var(--bf-red-900));font-size:14px;font-weight:600}
#ssv .ssv-msg.is-on{display:block}
#ssv .ssv-why{display:grid;gap:10px;padding:18px 0 8px}
#ssv .ssv-why div{display:flex;gap:12px;align-items:flex-start;font-size:15px;color:rgb(var(--bf-on-surface-variant));line-height:1.45}
#ssv .ssv-why .material-symbols-outlined{font-size:22px;color:rgb(var(--bf-primary));flex:none}
#ssv .ssv-done{padding:44px 28px;text-align:center}
#ssv .ssv-done .material-symbols-outlined{font-size:56px;color:rgb(var(--bf-green-800))}
#ssv .ssv-done p{font-size:16px;color:rgb(var(--bf-on-surface-variant));margin:8px auto 0;max-width:460px;line-height:1.5}
#ssv :focus-visible{outline:3px solid rgb(var(--bf-yellow-500));outline-offset:2px}
@media (max-width:640px){#ssv .ssv-head,#ssv .ssv-body,#ssv .ssv-foot{padding-left:18px;padding-right:18px}#ssv .ssv-nudge{margin:0 18px}#ssv h2{font-size:21px}}
body.ssv-lock{overflow:hidden}
</style>
<div id="ssv" role="dialog" aria-modal="true" aria-labelledby="ssvTitle" aria-hidden="true"></div>
<script>
(function () {
  if (window.__staffSurvey) return;
  window.__staffSurvey = true;
  var CFG = <?= json_encode($__ssvCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var root = document.getElementById('ssv');
  var MORE = CFG.mode === 'more';
  var DRAFT = 'ssv:' + CFG.key + ':' + CFG.user + (MORE ? ':more' : '');
  // In "more" mode the whole card is one page of written questions.
  var pages = MORE ? [{ title: '', intro: '', questions: CFG.more.questions }] : CFG.pages;
  var page = -1;             // -1 = the welcome page
  var open = false;
  var sending = false;
  var answers = {};
  var lastInput = Date.now();
  var opener = null;
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var rec = null, recBox = null;
  var speechLang = 'en-IN';
  // How they take it: time per page, voice and starter use, nudges. Sent with the answers.
  var meta = { secs: 0, pages: [], voice: 0, starters: 0, nudged: 0 };
  var pageStart = 0;
  var nudgedPages = {};

  try { answers = JSON.parse(localStorage.getItem(DRAFT) || '{}') || {}; } catch (e) { answers = {}; }
  if (answers.__meta) { meta = answers.__meta; delete answers.__meta; }
  function saveDraft() { try { var d = JSON.parse(JSON.stringify(answers)); d.__meta = meta; localStorage.setItem(DRAFT, JSON.stringify(d)); } catch (e) {} }

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    if (attrs) for (var k in attrs) { if (k === 'class') n.className = attrs[k]; else n.setAttribute(k, attrs[k]); }
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function icon(name) { return el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, name); }
  function forced() { return !MORE && CFG.skipsLeft <= 0; }
  function filled(v) { return typeof v === 'string' && v.trim() !== ''; }

  // ---- time on each page (only while the survey is on screen) --------------------
  function clockIn() { pageStart = Date.now(); }
  function clockOut() {
    if (!pageStart || page < 0 || page >= pages.length) { pageStart = 0; return; }
    var s = Math.round((Date.now() - pageStart) / 1000);
    pageStart = 0;
    if (s <= 0 || s > 3600) return; // left the screen open: don't count it
    meta.pages[page] = (meta.pages[page] || 0) + s;
    meta.secs = (meta.secs || 0) + s;
    saveDraft();
  }

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

  // A written answer: tap-to-start phrases, the box, voice typing and a little encouragement.
  function writeBox(q, labelEl) {
    var wrap = el('div', { class: 'ssv-write' });
    var qid = 'ssvq-' + q.key;
    var ta = el('textarea', { id: qid, maxlength: '1500', placeholder: q.placeholder || '' });
    ta.value = answers[q.key] || '';
    if (q.starters && q.starters.length) {
      var st = el('div', { class: 'ssv-start', role: 'group', 'aria-label': 'Tap to start your answer' });
      q.starters.forEach(function (s) {
        var b = el('button', { type: 'button' }, s);
        b.addEventListener('click', function () {
          var phrase = s.replace(/…$/, '').trim();
          var openEnded = s.slice(-1) === '…';
          var cur = ta.value.trim();
          ta.value = cur ? cur + (/[.!?]$/.test(cur) ? ' ' : '. ') + phrase + (openEnded ? ' ' : '') : phrase + (openEnded ? ' ' : '');
          meta.starters = (meta.starters || 0) + 1;
          update();
          ta.focus();
          try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) {}
        });
        st.appendChild(b);
      });
      wrap.appendChild(st);
    }
    wrap.appendChild(ta);
    var tools = el('div', { class: 'ssv-tools' });
    if (SR) {
      var mic = el('button', { type: 'button', class: 'ssv-mic', 'aria-pressed': 'false' });
      mic.appendChild(icon('mic'));
      mic.appendChild(el('span', null, 'Speak instead'));
      mic.addEventListener('click', function () { toggleVoice(ta, mic, update); });
      tools.appendChild(mic);
      // Voice language: English or Hindi (Hinglish comes through fine in either).
      var lang = el('button', { type: 'button', class: 'ssv-lang', title: 'Switch the voice typing language' });
      lang.appendChild(el('span', { 'data-l': 'en-IN' }, 'English'));
      lang.appendChild(document.createTextNode(' / '));
      lang.appendChild(el('span', { 'data-l': 'hi-IN' }, 'हिंदी'));
      lang.addEventListener('click', function () {
        speechLang = speechLang === 'en-IN' ? 'hi-IN' : 'en-IN';
        markLang();
        stopVoice();
      });
      tools.appendChild(lang);
      setTimeout(markLang, 0);
    }
    var cheer = el('span', { class: 'ssv-cheer', 'aria-live': 'polite' });
    tools.appendChild(cheer);
    wrap.appendChild(tools);
    function update() {
      answers[q.key] = ta.value;
      saveDraft();
      var n = ta.value.trim().length;
      cheer.className = 'ssv-cheer' + (n > 0 && n < 25 ? ' is-mid' : '');
      cheer.textContent = n === 0 ? '' : n < 25 ? 'Good start. A little more helps.' : n < 90 ? 'That’s useful, thank you.' : 'Brilliant. This is what makes a difference.';
      var box = wrap.closest('.ssv-q');
      if (box) box.classList.remove('has-err');
      hideNudge();
    }
    ta.addEventListener('input', update);
    if (ta.value) setTimeout(update, 0);
    return wrap;
  }

  // ---- voice typing (same browser feature Aisha uses) ----------------------------
  function markLang() {
    root.querySelectorAll('.ssv-lang span').forEach(function (x) { x.classList.toggle('is-on', x.getAttribute('data-l') === speechLang); });
  }
  function stopVoice() {
    if (rec) { try { rec.onend = null; rec.stop(); } catch (e) {} }
    rec = null;
    if (recBox) { recBox.mic.classList.remove('is-rec'); recBox.mic.setAttribute('aria-pressed', 'false'); recBox.mic.lastChild.textContent = 'Speak instead'; }
    recBox = null;
  }
  function toggleVoice(ta, mic, update) {
    if (recBox && recBox.mic === mic) { stopVoice(); return; }
    stopVoice();
    try { rec = new SR(); } catch (e) { return; }
    rec.lang = speechLang;
    rec.continuous = true;
    rec.interimResults = false;
    recBox = { mic: mic, ta: ta };
    mic.classList.add('is-rec');
    mic.setAttribute('aria-pressed', 'true');
    mic.lastChild.textContent = 'Listening… tap to stop';
    meta.voice = (meta.voice || 0) + 1;
    rec.onresult = function (ev) {
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        if (!ev.results[i].isFinal) continue;
        var said = ev.results[i][0].transcript.trim();
        if (!said) continue;
        var cur = ta.value.replace(/\s+$/, '');
        ta.value = cur ? cur + ' ' + said : said.charAt(0).toUpperCase() + said.slice(1);
      }
      update();
    };
    rec.onerror = function (ev) {
      var code = ev && ev.error;
      stopVoice();
      if (code === 'not-allowed' || code === 'service-not-allowed') {
        mic.lastChild.textContent = 'Microphone blocked. Please type instead.';
      }
    };
    rec.onend = function () { stopVoice(); };
    try { rec.start(); } catch (e) { stopVoice(); }
  }

  function question(q) {
    var box = el('div', { class: 'ssv-q', 'data-q': q.key });
    var qid = 'ssvq-' + q.key;
    if (q.type === 'text') {
      var lab = el('label', { for: qid });
      if (q.context) lab.appendChild(el('span', { class: 'ssv-ctx' }, q.context));
      lab.appendChild(document.createTextNode(q.label));
      box.appendChild(lab);
      box.appendChild(writeBox(q, lab));
    } else {
      box.appendChild(el('div', { class: 'ssv-qt', id: qid }, q.label));
      var group = el('div', { class: (q.type === 'scale' || q.type === 'nps') ? 'ssv-scale' + (q.type === 'nps' ? ' is-nps' : '') : 'ssv-chips', role: 'group', 'aria-labelledby': qid });
      var values = q.type === 'scale' ? [1, 2, 3, 4, 5] : q.type === 'nps' ? [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10] : q.options;
      var follow = q.followup ? el('div', { class: 'ssv-follow', 'data-follow': q.followup.key }) : null;
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
          if (follow) showFollow(q, follow, true);
        });
        group.appendChild(b);
      });
      box.appendChild(group);
      if (q.type === 'scale' || q.type === 'nps') {
        var ends = el('div', { class: 'ssv-ends', 'aria-hidden': 'true' });
        ends.appendChild(el('span', null, q.low)); ends.appendChild(el('span', null, q.high));
        box.appendChild(ends);
      }
      if (follow) {
        box.appendChild(follow);
        showFollow(q, follow, false);
      }
    }
    box.appendChild(el('div', { class: 'ssv-err', role: 'alert' }));
    return box;
  }

  // The follow-up under a question: shown only for the answers that open it.
  function followOpen(f, v) { return v !== undefined && v !== null && f.when.indexOf(v) !== -1; }
  function showFollow(q, holder, justAnswered) {
    var f = q.followup;
    var on = followOpen(f, answers[q.key]);
    if (!on) { if (holder.firstChild) { stopVoice(); holder.textContent = ''; } holder.style.display = 'none'; return; }
    holder.style.display = '';
    if (holder.firstChild) return;
    var lab = el('label', { for: 'ssvq-' + f.key }, f.label);
    holder.appendChild(lab);
    holder.appendChild(writeBox(f, lab));
    if (justAnswered) holder.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
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
    if (MORE) {
      var no = el('button', { type: 'button', class: 'ssv-link' }, 'No thanks');
      no.addEventListener('click', declineMore);
      out.push(no);
    } else if (!forced()) {
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

  function why(rows) {
    var w = el('div', { class: 'ssv-why' });
    rows.forEach(function (r) { var d = el('div'); d.appendChild(icon(r[0])); d.appendChild(el('span', null, r[1])); w.appendChild(d); });
    return w;
  }

  // Written answers that will count: text questions, and follow-ups their answer opened.
  function writtenCount() {
    var n = 0;
    pages.forEach(function (pg) {
      pg.questions.forEach(function (q) {
        if (q.type === 'text' && filled(answers[q.key])) n++;
        if (q.followup && followOpen(q.followup, answers[q.key]) && filled(answers[q.followup.key])) n++;
      });
    });
    return n;
  }

  function draw() {
    root.textContent = '';
    var card = el('div', { class: 'ssv-card' });
    if (page === -1) {
      card.appendChild(head('Staff survey', (CFG.first ? CFG.first + ', ' : '') + 'tell us how it’s going',
        'A few minutes about your work here, how you feel and where you want to grow.', null));
      var b = el('div', { class: 'ssv-body' });
      b.appendChild(why([
        ['touch_app', 'Tap your answers. ' + pages.length + ' short pages, about 5 minutes. If you stop half-way, your progress is kept.'],
        ['edit_note', 'The few lines you write are what we learn the most from. Ticks tell us what; your words tell us why.'],
        ['translate', 'Write the way you speak: English, Hindi, Punjabi or Hinglish. Or tap the microphone and just say it.']
      ]));
      card.appendChild(b);
      var start = el('button', { type: 'button', class: 'ssv-btn ssv-pri' }, Object.keys(answers).length ? 'Carry on' : 'Start');
      start.addEventListener('click', function () { go(0); });
      card.appendChild(foot(laterButtons().concat([el('span', { class: 'ssv-sp' }), start])));
    } else if (page === pages.length) {
      var d = el('div', { class: 'ssv-done' });
      d.appendChild(icon('task_alt'));
      d.appendChild(el('h2', { id: 'ssvTitle' }, 'Thank you' + (CFG.first ? ', ' + CFG.first : '') + '!'));
      var n = sentWritten;
      d.appendChild(el('p', null, MORE
        ? 'Got it. Your words have been added to your answers.'
        : n >= 3 ? 'Your answers have been sent, including ' + n + ' in your own words. That is exactly what helps us plan what comes next.'
        : 'Your answers have been sent. They help us plan what comes next for you and the team.'));
      card.appendChild(d);
      var close = el('button', { type: 'button', class: 'ssv-btn ssv-pri' }, 'Back to work');
      close.addEventListener('click', hide);
      card.appendChild(foot([el('span', { class: 'ssv-sp' }), close]));
    } else {
      var pg = pages[page];
      if (MORE) {
        card.appendChild(head('One more minute', (CFG.first ? CFG.first + ', ' : '') + 'thank you for answering the survey',
          'A few written questions were left empty, and they are the part we learn the most from. A line or two is plenty, in any language, or tap the microphone and say it.', null));
      } else {
        card.appendChild(head('Page ' + (page + 1) + ' of ' + pages.length, pg.title, pg.intro, Math.round(((page + 1) / pages.length) * 100)));
      }
      var msg = el('div', { class: 'ssv-msg', role: 'alert', id: 'ssvMsg' });
      card.appendChild(msg);
      var body = el('div', { class: 'ssv-body' });
      pg.questions.forEach(function (q) { body.appendChild(question(q)); });
      card.appendChild(body);
      card.appendChild(nudgeBox());
      var btns = laterButtons().concat([el('span', { class: 'ssv-sp' })]);
      if (!MORE) {
        var back = el('button', { type: 'button', class: 'ssv-btn ssv-sec' }, 'Back');
        back.addEventListener('click', function () { go(page - 1); });
        btns.push(back);
      }
      var last = page === pages.length - 1;
      var next = el('button', { type: 'button', class: 'ssv-btn ssv-pri', id: 'ssvNext' }, MORE ? 'Send' : last ? 'Send my answers' : 'Next');
      next.addEventListener('click', function () { advance(false); });
      btns.push(next);
      card.appendChild(foot(btns));
    }
    root.appendChild(card);
    var firstFocus = root.querySelector('.ssv-body button, .ssv-body textarea, .ssv-foot .ssv-pri');
    if (firstFocus) firstFocus.focus({ preventScroll: true });
  }

  // ---- the gentle nudge: once per page, when every written box on it is empty ----
  function nudgeBox() {
    var n = el('div', { class: 'ssv-nudge', id: 'ssvNudge', role: 'status' });
    n.appendChild(el('b', null, MORE ? 'Nothing written yet' : 'Before you move on: one line?'));
    n.appendChild(el('span', null, MORE
      ? 'Write a line in any box above, or press "No thanks" if you would rather not.'
      : 'Even a few words here tells us more than all the ticks. Any language is fine, or tap the microphone.'));
    if (!MORE) {
      var nb = el('div', { class: 'ssv-nb' });
      var write = el('button', { type: 'button', class: 'ssv-btn ssv-pri is-sm' }, 'Write a line');
      write.addEventListener('click', function () { hideNudge(); var t = root.querySelector('.ssv-body textarea'); if (t) { t.scrollIntoView({ block: 'center', behavior: 'smooth' }); t.focus({ preventScroll: true }); } });
      var on = el('button', { type: 'button', class: 'ssv-btn ssv-sec is-sm' }, 'Skip writing');
      on.addEventListener('click', function () { hideNudge(); advance(true); });
      nb.appendChild(write); nb.appendChild(on);
      n.appendChild(nb);
    }
    return n;
  }
  function hideNudge() { var n = document.getElementById('ssvNudge'); if (n) n.classList.remove('is-on'); }
  function pageTextBoxes() { return Array.prototype.slice.call(root.querySelectorAll('.ssv-body textarea')); }

  function advance(skipNudge) {
    if (!checkPage()) return;
    var boxes = pageTextBoxes();
    var wroteSomething = boxes.some(function (t) { return t.value.trim() !== ''; });
    if (boxes.length && !wroteSomething) {
      if (MORE) { var n0 = document.getElementById('ssvNudge'); if (n0) n0.classList.add('is-on'); return; }
      if (!skipNudge && !nudgedPages[page]) {
        nudgedPages[page] = true;
        meta.nudged = (meta.nudged || 0) + 1;
        saveDraft();
        var n = document.getElementById('ssvNudge');
        if (n) { n.classList.add('is-on'); n.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        return;
      }
    }
    stopVoice();
    if (page === pages.length - 1) send(); else go(page + 1);
  }

  function go(i) {
    clockOut();
    stopVoice();
    page = Math.max(-1, Math.min(pages.length, i));
    draw();
    clockIn();
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

  var sentWritten = 0;
  function send() {
    if (sending) return;
    sending = true;
    clockOut();
    clockIn(); // keep counting if the send fails and they stay on the page
    var btn = document.getElementById('ssvNext');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    var payload = {};
    Object.keys(answers).forEach(function (k) { payload[k] = answers[k]; });
    post(MORE ? '/survey/more' : '/survey', { answers: payload, meta: meta }).then(function (r) {
      if (r.status === 200 && r.data && r.data.ok) {
        sentWritten = writtenCount();
        try { localStorage.removeItem(DRAFT); } catch (e) {}
        go(pages.length);
        return;
      }
      if (r.status === 422 && r.data && r.data.fields && Object.keys(r.data.fields).length) {
        var keys = Object.keys(r.data.fields);
        for (var i = 0; i < pages.length; i++) {
          if (pages[i].questions.some(function (q) { return keys.indexOf(q.key) !== -1 || (q.followup && keys.indexOf(q.followup.key) !== -1); })) { go(i); break; }
        }
        keys.forEach(function (k) { var box = root.querySelector('[data-q="' + k + '"]'); if (box) flagError(box, r.data.fields[k]); });
        showMsg(r.data.error || 'A few questions still need an answer.');
        return;
      }
      if (r.status === 422 && r.data) { showMsg(r.data.error); return; }
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
      if (b && page < pages.length) { b.disabled = false; b.textContent = MORE ? 'Send' : 'Send my answers'; }
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

  function declineMore() {
    // Whatever the server says, don't ask again on this page load.
    CFG.snoozedUntil = Infinity;
    CFG.due = false;
    hide();
    post('/survey/more/skip', {}).catch(function () {});
  }

  // ---- showing and hiding -------------------------------------------------------
  function show() {
    if (open) return;
    open = true;
    opener = document.activeElement;
    root.classList.add('is-on');
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ssv-lock');
    go(MORE ? 0 : (Object.keys(answers).length ? firstUnfinished() : -1));
  }
  function hide() {
    clockOut();
    stopVoice();
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

  // A hidden tab isn't time spent on the survey.
  document.addEventListener('visibilitychange', function () {
    if (!open) return;
    if (document.hidden) clockOut(); else clockIn();
  });

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
