<?php /** Maintenance Buddy — admin/dev system-health assistant (AI_BUDDY_PLAN.md P0.5) */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maintenance Buddy — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>
  body { font-family: Inter, sans-serif; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400; }
  #chat-log { scroll-behavior: smooth; }
  .msg pre, .msg { white-space: pre-wrap; word-break: break-word; }
</style>
</head>
<body class="bg-background text-on-surface min-h-screen">

<div class="max-w-5xl mx-auto px-4 py-6">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
      <span class="material-symbols-outlined text-primary text-3xl">smart_toy</span>
      <div>
        <h1 class="font-headline font-extrabold text-2xl text-primary">Maintenance Buddy</h1>
        <p class="text-xs text-on-surface-variant">System health assistant · read-only · every tool call is audited</p>
      </div>
    </div>
    <a href="/dashboard" class="text-sm text-primary hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined text-base">arrow_back</span> Dashboard
    </a>
  </div>

  <!-- Status cards (deterministic — same numbers the AI sees) -->
  <div id="cards" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100" id="card-errors">
      <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Errors (24h)</p>
      <p class="text-2xl font-bold mt-1" id="card-errors-n">…</p>
      <p class="text-[11px] text-on-surface-variant mt-1" id="card-errors-d"></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100" id="card-backups">
      <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Backups</p>
      <p class="text-sm font-semibold mt-2" id="card-backups-v">…</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100" id="card-crons">
      <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Cron jobs</p>
      <p class="text-sm font-semibold mt-2" id="card-crons-v">…</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100" id="card-pulse">
      <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Pulse</p>
      <p class="text-sm font-semibold mt-2" id="card-pulse-v">…</p>
    </div>
  </div>

  <!-- Chat -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-100 flex flex-col" style="height: 60vh;">
    <div id="chat-log" class="flex-1 overflow-y-auto p-4 space-y-3">
      <div class="msg text-sm text-on-surface-variant bg-slate-50 rounded-xl px-4 py-3 max-w-[85%]">
        Ask me about system health — errors, backups, migrations, cron jobs.
        Try: <em>"anything unusual in the last 24 hours?"</em>
      </div>
    </div>
    <form id="chat-form" class="border-t border-slate-100 p-3 flex gap-2">
      <input id="chat-input" type="text" maxlength="2000" autocomplete="off"
             placeholder="Ask about errors, backups, crons…"
             class="flex-1 border border-slate-200 rounded-xl px-4 py-2.5 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 focus:border-primary">
      <button id="chat-send" type="submit"
              class="bg-primary text-white rounded-xl px-5 py-2.5 text-sm font-bold hover:bg-primary-container disabled:opacity-50">
        Send
      </button>
    </form>
  </div>

  <p class="text-[11px] text-on-surface-variant mt-3">
    Answers are grounded in live tool queries only. If the AI layer is down you still get the deterministic digest.
  </p>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrfToken) ?>';
const log = document.getElementById('chat-log');
const input = document.getElementById('chat-input');
const sendBtn = document.getElementById('chat-send');

function addMsg(role, text, degraded) {
  const div = document.createElement('div');
  div.className = 'msg text-sm rounded-xl px-4 py-3 max-w-[85%] ' + (role === 'user'
    ? 'bg-primary text-white ml-auto'
    : 'bg-slate-50 text-on-surface');
  div.textContent = text;
  if (degraded) {
    const tag = document.createElement('div');
    tag.className = 'text-[10px] mt-2 opacity-70';
    tag.textContent = 'deterministic fallback — AI layer unavailable';
    div.appendChild(tag);
  }
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
  return div;
}

// ── History ──────────────────────────────────────────────────────────────
fetch('/buddy/maintenance/history')
  .then(r => r.json())
  .then(d => { (d.messages || []).forEach(m => addMsg(m.role === 'user' ? 'user' : 'model', m.content)); })
  .catch(() => {});

// ── Status cards ─────────────────────────────────────────────────────────
fetch('/buddy/maintenance/digest')
  .then(r => r.json())
  .then(d => {
    const g = d.digest || {};
    const e = g.errors || {};
    document.getElementById('card-errors-n').textContent = e.total ?? '—';
    document.getElementById('card-errors-d').textContent =
      Object.entries(e.by_severity || {}).map(([k, v]) => k + ' ' + v).join(' · ');

    document.getElementById('card-backups-v').textContent = (g.backups || {}).verdict || '—';

    const jobs = ((g.crons || {}).jobs || []);
    const overdue = jobs.filter(j => j.overdue);
    document.getElementById('card-crons-v').textContent =
      overdue.length ? ('OVERDUE: ' + overdue.map(j => j.job).join(', ')) : (jobs.length + ' jobs traced, none overdue');
    if (overdue.length) document.getElementById('card-crons').classList.add('ring-2', 'ring-red-300');

    const p = g.pulse || {};
    document.getElementById('card-pulse-v').textContent =
      (p.clocked_in_now ?? '—') + ' clocked in · ' + (p.txns_business_day ?? '—') + ' txns today';
  })
  .catch(() => {});

// ── Chat ─────────────────────────────────────────────────────────────────
document.getElementById('chat-form').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const text = input.value.trim();
  if (!text || sendBtn.disabled) return;

  addMsg('user', text);
  input.value = '';
  sendBtn.disabled = true;
  const pending = addMsg('model', 'Thinking…');

  try {
    const res = await fetch('/buddy/maintenance/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ message: text }),
    });
    const data = await res.json();
    pending.remove();
    if (data.success) addMsg('model', data.reply, data.ai === false);
    else addMsg('model', 'Error: ' + (data.error || 'request failed'));
  } catch (e) {
    pending.remove();
    addMsg('model', 'Network error — try again.');
  } finally {
    sendBtn.disabled = false;
    input.focus();
  }
});
</script>
</body>
</html>
