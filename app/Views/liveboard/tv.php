<?php
// Ensure CSRF token exists for PIN form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Base Fare — Live Scoreboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
    html, body { margin: 0; padding: 0; height: 100%; background: #f8fafc; }

    /* ── Announcement banner ── */
    #ann-banner {
      transform: translateY(110%);
      transition: transform 0.55s cubic-bezier(0.16, 1, 0.3, 1);
    }
    #ann-banner.show { transform: translateY(0); }

    /* ── New-item flash ── */
    @keyframes flash-in {
      0%   { background: rgba(59,130,246,.15); transform: scale(1.015); }
      100% { background: #ffffff;   transform: scale(1); }
    }
    .flash { animation: flash-in 2.5s ease-out forwards; }

    /* ── Scrollbar hide ── */
    .no-scroll { overflow: hidden; }
    #feed-wrap { overflow-y: auto; scrollbar-width: none; }
    #feed-wrap::-webkit-scrollbar { display: none; }
    #lb-wrap   { overflow-y: auto; scrollbar-width: none; }
    #lb-wrap::-webkit-scrollbar { display: none; }
  </style>
</head>
<body class="no-scroll text-slate-900">

<?php if (!$pinVerified): ?>
<!-- ═══════════════════════ PIN SCREEN ═══════════════════════ -->
<div class="min-h-screen flex items-center justify-center" style="background:#f1f5f9;">
  <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:48px 40px; width:360px; text-align:center; box-shadow:0 15px 35px rgba(0,0,0,.05);">
    <div style="width:64px;height:64px;background:#f1f5f9;border:radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 24px;">🔒</div>
    <h1 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 8px;">Live Scoreboard</h1>
    <p style="color:#64748b;font-size:14px;margin:0 0 28px;">Enter the TV PIN to unlock.</p>

    <?php if (!empty($pinError)): ?>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:10px 14px;color:#ef4444;font-size:13px;margin-bottom:20px;">
        <?= htmlspecialchars($pinError) ?>
      </div>
    <?php endif; ?>

    <form action="/liveboard/score/auth" method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="password" name="pin" autofocus placeholder="••••"
             style="width:100%;background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;padding:14px;text-align:center;font-size:28px;letter-spacing:.4em;color:#0f172a;outline:none;margin-bottom:16px;display:block;">
      <button type="submit"
              style="width:100%;background:#2563eb;border:none;border-radius:12px;padding:14px;font-size:15px;font-weight:700;color:white;cursor:pointer;box-shadow:0 4px 6px rgba(37,99,235,.2);">
        Unlock Dashboard
      </button>
    </form>
  </div>
</div>
<?php return; endif; ?>

<!-- ═══════════════════════ TV DASHBOARD ═══════════════════════ -->
<div style="display:flex;flex-direction:column;height:100vh;">

  <!-- TOP BAR -->
  <header style="background:#ffffff;border-bottom:1px solid #e2e8f0;padding:16px 32px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;box-shadow:0 1px 3px rgba(0,0,0,.05);">
    <!-- Brand -->
    <div style="display:flex;align-items:center;gap:14px;">
      <div style="width:46px;height:46px;background:#2563eb;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:17px;box-shadow:0 4px 10px rgba(37,99,235,.3);">BF</div>
      <div>
        <div style="font-weight:900;font-size:20px;letter-spacing:-.02em;color:#0f172a;">Base Fare</div>
        <div style="color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Live Sales Board</div>
      </div>
    </div>

    <!-- Stats -->
    <div style="display:flex;align-items:center;gap:36px;">
      <div style="text-align:center;">
        <div style="color:#64748b;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:2px;">Today's Profit</div>
        <div id="stat-profit" style="font-size:28px;font-weight:900;color:#10b981;">USD 0</div>
      </div>
      <div style="text-align:center;">
        <div style="color:#64748b;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:2px;">Transactions</div>
        <div id="stat-txns" style="font-size:28px;font-weight:900;color:#0f172a;">0</div>
      </div>
      <div style="text-align:center;">
        <div style="color:#64748b;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:2px;">Authorizations</div>
        <div id="stat-accs" style="font-size:28px;font-weight:900;color:#8b5cf6;">0</div>
      </div>
      <!-- Clock -->
      <div style="text-align:right;padding-left:28px;border-left:1px solid #e2e8f0;">
        <div id="clock-time" style="font-size:30px;font-weight:900;letter-spacing:-.02em;color:#0f172a;">--:--</div>
        <div id="clock-date" style="color:#64748b;font-size:12px;font-weight:600;">---</div>
      </div>
    </div>
  </header>

  <!-- MAIN AREA -->
  <main style="flex:1;display:flex;gap:24px;padding:24px;min-height:0;">

    <!-- LEADERBOARD -->
    <section style="flex:1;background:#ffffff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;display:flex;flex-direction:column;min-height:0;box-shadow:0 4px 6px rgba(0,0,0,.02);">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-shrink:0;">
        <h2 style="margin:0;font-size:18px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;">
          <span style="font-size:22px;">🏆</span> Today's Leaderboard
        </h2>
        <div style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:999px;padding:5px 12px;font-size:11px;font-weight:700;color:#475569;display:flex;align-items:center;gap:6px;">
          <span style="width:7px;height:7px;border-radius:50%;background:#10b981;display:inline-block;animation:pulse 1.5s infinite;"></span> Live
        </div>
      </div>
      <div id="lb-wrap" style="flex:1;overflow-y:auto;">
        <div id="lb-container" style="display:flex;flex-direction:column;gap:12px;">
          <div style="color:#64748b;text-align:center;padding:40px 0;font-size:14px;">Waiting for today's first sale...</div>
        </div>
      </div>
    </section>

    <!-- FEED -->
    <section style="width:420px;background:#ffffff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;display:flex;flex-direction:column;min-height:0;box-shadow:0 4px 6px rgba(0,0,0,.02);">
      <h2 style="margin:0 0 20px;font-size:18px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;flex-shrink:0;">
        <span style="font-size:22px;">⚡</span> Live Feed
      </h2>
      <div style="flex:1;position:relative;overflow:hidden;">
        <div style="position:absolute;bottom:0;left:0;right:0;height:80px;background:linear-gradient(to top,#ffffff,transparent);z-index:5;pointer-events:none;"></div>
        <div id="feed-wrap" style="height:100%;overflow-y:auto;">
          <div id="feed-container" style="display:flex;flex-direction:column;gap:12px;">
            <div style="color:#64748b;text-align:center;padding:40px 0;font-size:14px;">No events yet today.</div>
          </div>
        </div>
      </div>
    </section>

  </main>
</div>

<!-- ANNOUNCEMENT BANNER -->
<div id="ann-banner" style="position:fixed;bottom:0;left:0;right:0;background:rgba(37,99,235,.95);backdrop-filter:blur(16px);border-top:1px solid rgba(96,165,250,.3);padding:24px 40px;z-index:100;display:flex;align-items:center;gap:28px;box-shadow:0 -8px 40px rgba(37,99,235,.25);">
  <div id="ann-emoji" style="width:72px;height:72px;background:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:36px;flex-shrink:0;">🎉</div>
  <div>
    <div id="ann-type" style="color:#bfdbfe;font-size:12px;font-weight:800;letter-spacing:.15em;text-transform:uppercase;margin-bottom:6px;">NEW SALE!</div>
    <div id="ann-msg" style="color:#ffffff;font-size:38px;font-weight:900;line-height:1.1;"></div>
  </div>
</div>

<!-- AUDIO UNLOCK OVERLAY -->
<div id="audio-unlock" style="position:fixed;inset:0;background:rgba(248,250,252,.95);backdrop-filter:blur(6px);z-index:200;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;">
  <div style="width:90px;height:90px;background:#2563eb;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:24px;animation:pulse-grow 1.8s ease-in-out infinite;">
    <svg width="38" height="38" fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z" clip-rule="evenodd"></path></svg>
  </div>
  <h2 style="color:#0f172a;font-size:28px;font-weight:900;margin:0 0 10px;">Click anywhere to start</h2>
  <p style="color:#64748b;font-size:16px;margin:0;">Browser requires one click to enable voice announcements.</p>
</div>

<style>
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
@keyframes pulse-grow { 0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(37,99,235,.3)} 50%{transform:scale(1.05);box-shadow:0 0 0 14px rgba(37,99,235,0)} }
</style>

<script>
// ── CLOCK ──────────────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('clock-time').textContent = now.toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',hour12:true});
  document.getElementById('clock-date').textContent  = now.toLocaleDateString('en-US', {weekday:'long',month:'short',day:'numeric'});
}
setInterval(updateClock, 1000);
updateClock();

// ── VOICE ──────────────────────────────────────────────────────
let voiceReady = false, selectedVoice = null;

document.getElementById('audio-unlock').addEventListener('click', function () {
  this.style.display = 'none';
  voiceReady = true;
  const synth = window.speechSynthesis;
  const pick = () => {
    const v = synth.getVoices();
    selectedVoice = v.find(x => x.name.includes('Natural') && x.lang.startsWith('en'))
                 || v.find(x => (x.name.includes('Aria') || x.name.includes('Jenny') || x.name.includes('Samantha')) && x.lang.startsWith('en'))
                 || v.find(x => /zira|female|google uk english female/i.test(x.name) && x.lang.startsWith('en'))
                 || v.find(x => x.lang.startsWith('en'))
                 || v[0];
  };
  pick();
  if ('onvoiceschanged' in speechSynthesis) speechSynthesis.onvoiceschanged = pick;
  const u = new SpeechSynthesisUtterance(' ');
  synth.speak(u);
});

function announce(msg, typeLabel, emoji) {
  document.getElementById('ann-emoji').textContent = emoji || '🎉';
  document.getElementById('ann-type').textContent  = typeLabel;
  document.getElementById('ann-msg').textContent   = msg;
  const banner = document.getElementById('ann-banner');
  banner.classList.add('show');

  if (voiceReady) {
    const synth = window.speechSynthesis;
    synth.cancel();
    const u = new SpeechSynthesisUtterance(msg);
    if (selectedVoice) u.voice = selectedVoice;
    u.rate = 1.05; u.pitch = 1.25;
    u.onend = () => setTimeout(() => banner.classList.remove('show'), 2500);
    synth.speak(u);
  } else {
    setTimeout(() => banner.classList.remove('show'), 6000);
  }
}

// ── FEED POLLING ───────────────────────────────────────────────
let lastEventId = null, firstLoad = true;

const MEDALS = ['🥇','🥈','🥉'];
const RANK_COLORS = [
  'background:#fef3c7;color:#d97706;border:1px solid #fde68a;',
  'background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;',
  'background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;',
];

function renderLeaderboard(lb) {
  const el = document.getElementById('lb-container');
  if (!lb.length) {
    el.innerHTML = '<div style="color:#64748b;text-align:center;padding:40px 0;font-size:14px;">Waiting for today\'s first sale...</div>';
    return;
  }
  el.innerHTML = lb.map((a, i) => {
    const rankStyle = RANK_COLORS[i] || 'background:#f8fafc;color:#64748b;border:1px solid #f1f5f9;';
    const isFirst = i === 0;
    return `
      <div style="background:#ffffff;border:1px solid ${isFirst?'#f59e0b':'#e2e8f0'};border-radius:16px;padding:16px 20px;display:flex;align-items:center;gap:16px;${isFirst?'box-shadow:0 4px 15px rgba(245,158,11,.15);':''}">
        <div style="width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;flex-shrink:0;${rankStyle}">${i < 3 ? MEDALS[i] : i+1}</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:800;font-size:18px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${a.display}</div>
          <div style="color:#64748b;font-size:12px;margin-top:2px;">${a.txn_count} txn${a.txn_count!==1?'s':''}${a.acc_count>0?' · '+a.acc_count+' auth'+( a.acc_count!==1?'s':''):''}</div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-weight:900;font-size:22px;color:#10b981;">${a.profit > 0 ? '+'+a.profit.toLocaleString() : '0'}</div>
          <div style="color:#64748b;font-size:11px;">USD</div>
        </div>
      </div>
    `;
  }).join('');
}

function renderFeed(events) {
  const el = document.getElementById('feed-container');
  if (!events.length) {
    el.innerHTML = '<div style="color:#64748b;text-align:center;padding:40px 0;font-size:14px;">No events yet today.</div>';
    return;
  }
  el.innerHTML = events.map((ev, idx) => {
    const isTxn   = ev.kind === 'transaction';
    const accent  = isTxn ? '#2563eb' : '#8b5cf6';
    const badge   = isTxn ? 'Recorded' : 'Approved';
    const timeStr = ev.time ? new Date(ev.time).toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit'}) : '';
    return `
      <div class="${idx===0&&!firstLoad?'flash':''}" style="background:#ffffff;border:1px solid #e2e8f0;border-left:4px solid ${accent};border-radius:14px;padding:14px 16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <span style="font-weight:700;font-size:14px;color:#0f172a;">${ev.agent_name}</span>
          <span style="color:#64748b;font-size:12px;">${timeStr}</span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <span style="color:${accent};font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">${badge}: ${ev.label}</span>
          ${ev.profit !== null ? `<span style="color:#10b981;font-weight:900;font-size:14px;">+${ev.profit.toLocaleString()}</span>` : ''}
        </div>
      </div>
    `;
  }).join('');
}

async function fetchFeed() {
  try {
    const res = await fetch('/api/liveboard/feed');
    if (res.status === 403) { window.location.reload(); return; }
    const data = await res.json();

    document.getElementById('stat-profit').textContent = 'USD ' + data.total_profit.toLocaleString();
    document.getElementById('stat-txns').textContent   = data.total_txns;
    document.getElementById('stat-accs').textContent   = data.total_accs;

    renderLeaderboard(data.leaderboard || []);
    renderFeed(data.events || []);

    if (!firstLoad && data.last_event_id && data.last_event_id !== lastEventId) {
      const ev = data.events[0];
      const amountStr = ev.profit ? `for ${ev.profit} dollars` : '';
      if (ev.kind === 'transaction') {
        announce(`${ev.agent_name} just closed a ${ev.label} ${amountStr}!`, 'NEW SALE!', '🎉');
      } else {
        announce(`Authorization ${amountStr} for ${ev.agent_name} was just approved!`, 'AUTHORIZED!', '✅');
      }
    }

    lastEventId = data.last_event_id;
    firstLoad   = false;
  } catch (e) {
    console.error('Feed error', e);
  }
}

fetchFeed();
setInterval(fetchFeed, 15000);
</script>
</body>
</html>
