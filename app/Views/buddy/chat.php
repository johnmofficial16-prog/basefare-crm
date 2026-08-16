<?php /** Agent Buddy — personal work buddy chat (AI_BUDDY_PLAN.md P1) */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Buddy — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script src="/assets/js/error-beacon.js"></script>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>
  body { font-family: Inter, sans-serif; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400; }
  #chat-log { scroll-behavior: smooth; }
  .msg { white-space: pre-wrap; word-break: break-word; }
</style>
</head>
<body class="bg-background text-on-surface min-h-screen">

<div class="max-w-3xl mx-auto px-4 py-6 flex flex-col" style="min-height: 100vh;">

  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-full bg-primary text-white flex items-center justify-center">
        <span class="material-symbols-outlined">emoji_people</span>
      </div>
      <div>
        <h1 class="font-headline font-extrabold text-xl text-primary">Your Buddy</h1>
        <p class="text-[11px] text-on-surface-variant">knows your numbers · keeps your flow on track · cheers the wins</p>
      </div>
    </div>
    <a href="/dashboard" class="text-sm text-primary hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined text-base">arrow_back</span> Dashboard
    </a>
  </div>

  <!-- Nudge chips -->
  <div id="nudges" class="flex flex-wrap gap-2 mb-3"></div>

  <!-- Chat -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-100 flex flex-col flex-1" style="min-height: 55vh; max-height: 70vh;">
    <div id="chat-log" class="flex-1 overflow-y-auto p-4 space-y-3"></div>
    <form id="chat-form" class="border-t border-slate-100 p-3 flex gap-2">
      <input id="chat-input" type="text" maxlength="500" autocomplete="off"
             placeholder="Ask about your day, your month, your open bookings…"
             class="flex-1 border border-slate-200 rounded-xl px-4 py-2.5 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/40 focus:border-primary">
      <button id="chat-send" type="submit"
              class="bg-primary text-white rounded-xl px-5 py-2.5 text-sm font-bold hover:bg-primary-container disabled:opacity-50">
        Send
      </button>
    </form>
  </div>

  <p class="text-[11px] text-on-surface-variant mt-3">
    Your buddy only sees <strong>your own</strong> sales and flow data — never customers' personal details, never other agents.
  </p>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrfToken) ?>';
const log = document.getElementById('chat-log');
const input = document.getElementById('chat-input');
const sendBtn = document.getElementById('chat-send');

const NUDGE_LABELS = {
  sale_praise_t1: '👏 Nice sale', sale_praise_t2: '🎉 Big sale',
  eticket_lag: '🎫 E-ticket pending', acceptance_lag: '⏳ Acceptance open',
  departure_24h: '✈️ Departs soon', dry_spell: '💪 Check-in',
};

function addMsg(role, text, degraded) {
  const div = document.createElement('div');
  div.className = 'msg text-sm rounded-xl px-4 py-3 max-w-[85%] ' + (role === 'user'
    ? 'bg-primary text-white ml-auto'
    : 'bg-slate-50 text-on-surface');
  div.textContent = text;
  if (degraded) {
    const tag = document.createElement('div');
    tag.className = 'text-[10px] mt-2 opacity-70';
    tag.textContent = 'AI unavailable — showing your raw numbers';
    div.appendChild(tag);
  }
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
  return div;
}

async function boot() {
  try {
    const h = await (await fetch('/buddy/history')).json();
    (h.messages || []).forEach(m => addMsg(m.role === 'user' ? 'user' : 'model', m.content));
    (h.nudges || []).forEach(n => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'text-xs bg-white border border-slate-200 rounded-full px-3 py-1.5 shadow-sm hover:border-primary';
      chip.textContent = NUDGE_LABELS[n.type] || n.type;
      chip.onclick = () => { input.value = 'Tell me about: ' + (NUDGE_LABELS[n.type] || n.type); input.focus(); };
      document.getElementById('nudges').appendChild(chip);
    });

    // Once-per-business-day greeting (server decides; no-op if already greeted).
    const g = await (await fetch('/buddy/greeting', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: '{}',
    })).json();
    if (g.greeted && g.reply) addMsg('model', g.reply, g.ai === false);
    else if ((h.messages || []).length === 0) {
      addMsg('model', "Hey! I'm your buddy — I keep an eye on your sales, your open bookings and your wins. Ask me anything about your numbers.");
    }
  } catch (e) { /* page still usable for chat */ }
}
boot();

document.getElementById('chat-form').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const text = input.value.trim();
  if (!text || sendBtn.disabled) return;

  addMsg('user', text);
  input.value = '';
  sendBtn.disabled = true;
  const pending = addMsg('model', '…');

  try {
    const res = await fetch('/buddy/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ message: text }),
    });
    const data = await res.json();
    pending.remove();
    if (data.success) addMsg('model', data.reply, data.ai === false);
    else addMsg('model', data.error || 'Something went wrong — try again.');
  } catch (e) {
    pending.remove();
    addMsg('model', 'Network hiccup — try again.');
  } finally {
    sendBtn.disabled = false;
    input.focus();
  }
});
</script>
</body>
</html>
