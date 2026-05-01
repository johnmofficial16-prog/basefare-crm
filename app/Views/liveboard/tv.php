<?php
// PIN Protection Screen
if (!$pinVerified):
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TV Scoreboard - PIN Required</title>
  <link href="/assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="bg-slate-900 h-screen flex items-center justify-center font-sans text-slate-100">
  <div class="bg-slate-800 p-8 rounded-3xl shadow-2xl max-w-sm w-full border border-slate-700 text-center">
    <div class="w-16 h-16 bg-slate-700 rounded-2xl flex items-center justify-center mx-auto mb-6">
      <span class="text-3xl">🔒</span>
    </div>
    <h2 class="text-xl font-bold text-white mb-2">Live Scoreboard</h2>
    <p class="text-sm text-slate-400 mb-6">Enter the TV PIN to view the live dashboard.</p>
    
    <?php if ($pinError): ?>
      <div class="bg-rose-500/10 text-rose-400 text-sm p-3 rounded-xl mb-6 border border-rose-500/20">
        <?= htmlspecialchars($pinError) ?>
      </div>
    <?php endif; ?>

    <form action="/liveboard/score/auth" method="POST">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
      <input type="password" name="pin" autofocus placeholder="• • • •" 
             class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-center text-2xl tracking-[0.5em] text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 mb-6">
      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 px-4 rounded-xl transition-colors">
        Unlock Dashboard
      </button>
    </form>
  <script>
    // --- CLOCK ---
    function updateClock() {
      const now = new Date();
      document.getElementById('clock-time').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute:'2-digit', hour12: true });
      document.getElementById('clock-date').textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
    }
    setInterval(updateClock, 1000);
    updateClock();

    // --- VOICE (Web Speech API) ---
    let voiceReady = false;
    let selectedVoice = null;

    document.getElementById('audio-unlock').addEventListener('click', function() {
      this.classList.add('hidden');
      voiceReady = true;
      
      // Initialize speech synthesis and find a female english voice if possible
      const synth = window.speechSynthesis;
      const findVoice = () => {
        const voices = synth.getVoices();
        if (voices.length > 0) {
          selectedVoice = voices.find(v => v.lang.startsWith('en') && (v.name.includes('Female') || v.name.includes('Samantha') || v.name.includes('Google US English'))) || voices[0];
        }
      };
      findVoice();
      if (speechSynthesis.onvoiceschanged !== undefined) {
        speechSynthesis.onvoiceschanged = findVoice;
      }
      
      // Silent speak to unlock context
      const u = new SpeechSynthesisUtterance('');
      synth.speak(u);
    });

    function announce(msg, typeLabel) {
      if (!voiceReady) return;
      
      // Visual Banner
      const banner = document.getElementById('announcement-banner');
      document.getElementById('ann-type').textContent = typeLabel;
      document.getElementById('ann-msg').textContent = msg;
      banner.classList.add('show');
      
      // Audio
      const synth = window.speechSynthesis;
      const utterance = new SpeechSynthesisUtterance(msg);
      if (selectedVoice) utterance.voice = selectedVoice;
      utterance.rate = 0.95;
      utterance.pitch = 1.1;
      
      utterance.onend = function() {
        setTimeout(() => banner.classList.remove('show'), 2000);
      };
      
      synth.speak(utterance);
    }

    // --- DATA FEED POLLING ---
    let lastEventId = null;
    let firstLoad = true;

    async function fetchFeed() {
      try {
        const res = await fetch('/api/liveboard/feed');
        if (res.status === 403) {
          window.location.reload(); // PIN expired
          return;
        }
        const data = await res.json();
        
        // Update Totals
        document.getElementById('stat-profit').textContent = data.currency + ' ' + data.total_profit.toLocaleString();
        document.getElementById('stat-txns').textContent = data.total_txns;

        // Render Leaderboard
        const lbHtml = data.leaderboard.map((a, i) => `
          <div class="bg-slate-800/50 rounded-2xl p-4 flex items-center gap-4 border border-slate-700/50 transition-all ${i===0 ? 'ring-1 ring-amber-500/50 shadow-[0_0_15px_rgba(245,158,11,0.2)]' : ''}">
            <div class="w-10 h-10 rounded-full flex items-center justify-center font-black text-xl shrink-0 ${i===0 ? 'bg-amber-500 text-white' : (i===1 ? 'bg-slate-400 text-white' : (i===2 ? 'bg-amber-700 text-white' : 'bg-slate-800 text-slate-500'))}">
              ${i+1}
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-bold text-white truncate text-lg">${a.display}</p>
              <p class="text-xs text-slate-400 mt-0.5">${a.txn_count} Txns ${a.acc_count > 0 ? `· ${a.acc_count} Accs` : ''}</p>
            </div>
            <div class="text-right shrink-0">
              <p class="font-black text-emerald-400 text-xl tracking-tight">${a.profit > 0 ? '+'+a.profit.toLocaleString() : '0'}</p>
            </div>
          </div>
        `).join('');
        document.getElementById('leaderboard-container').innerHTML = lbHtml;

        // Check for new event
        if (!firstLoad && data.last_event_id && data.last_event_id !== lastEventId) {
          // Find the new event to announce
          const ev = data.events[0];
          let msg = '';
          let typeLabel = 'NEW SALE!';
          if (ev.kind === 'transaction') {
            msg = `${ev.agent_name} just closed a ${ev.label}!`;
          } else {
            typeLabel = 'AUTHORIZATION APPROVED!';
            msg = `A ${ev.label} authorization was just signed for ${ev.agent_name}!`;
          }
          announce(msg, typeLabel);
        }

        lastEventId = data.last_event_id;
        firstLoad = false;

        // Render Feed
        const feedHtml = data.events.map(ev => `
          <div class="bg-slate-800/30 rounded-2xl p-4 border border-slate-700/30 border-l-4 ${ev.kind === 'transaction' ? 'border-l-blue-500' : 'border-l-violet-500'} ${data.last_event_id === ev.id && !firstLoad ? 'new-item' : ''}">
            <div class="flex items-center justify-between mb-1">
              <p class="font-bold text-white text-sm">${ev.agent_name}</p>
              <p class="text-xs text-slate-500">${new Date(ev.time).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'})}</p>
            </div>
            <div class="flex items-center justify-between">
              <p class="text-xs font-semibold ${ev.kind === 'transaction' ? 'text-blue-400' : 'text-violet-400'} uppercase tracking-wider">${ev.kind === 'transaction' ? 'Recorded' : 'Approved'}: ${ev.label}</p>
              ${ev.profit !== null ? `<p class="font-black text-emerald-400 text-sm">+${ev.profit.toLocaleString()}</p>` : ''}
            </div>
          </div>
        `).join('');
        document.getElementById('feed-container').innerHTML = feedHtml;

      } catch (err) {
        console.error('Feed error:', err);
      }
    }

    // Start polling every 15 seconds
    fetchFeed();
    setInterval(fetchFeed, 15000);

  </script>
</body>
</html>
<?php return; endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Live Sales Scoreboard</title>
  <link href="/assets/css/tailwind.css" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background-color: #0f172a; overflow: hidden; }
    .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.05); }
    .neon-text { text-shadow: 0 0 10px rgba(99, 102, 241, 0.5); }
    .neon-text-green { text-shadow: 0 0 10px rgba(16, 185, 129, 0.5); }
    
    /* Announcement Banner */
    #announcement-banner {
      transform: translateY(100%);
      transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }
    #announcement-banner.show {
      transform: translateY(0);
    }

    /* Pulse animation for new items */
    @keyframes pulse-row {
      0% { background-color: rgba(99, 102, 241, 0.4); transform: scale(1.02); }
      100% { background-color: transparent; transform: scale(1); }
    }
    .new-item { animation: pulse-row 3s ease-out forwards; }
  </style>
</head>
<body class="text-slate-200 h-screen flex flex-col">

  <!-- TOP BAR -->
  <header class="glass px-8 py-4 flex items-center justify-between shrink-0 border-b border-slate-800">
    <div class="flex items-center gap-4">
      <div class="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center font-bold text-xl text-white shadow-[0_0_15px_rgba(79,70,229,0.5)]">BF</div>
      <div>
        <h1 class="text-2xl font-black text-white tracking-tight uppercase">Base Fare</h1>
        <p class="text-indigo-400 text-sm font-semibold tracking-widest uppercase">Live Scoreboard</p>
      </div>
    </div>
    <div class="flex items-center gap-8">
      <div class="text-center">
        <p class="text-[10px] uppercase tracking-widest text-slate-400 font-bold mb-1">Today's Sales</p>
        <p class="text-3xl font-black text-white neon-text-green" id="stat-profit">USD 0</p>
      </div>
      <div class="text-center">
        <p class="text-[10px] uppercase tracking-widest text-slate-400 font-bold mb-1">Transactions</p>
        <p class="text-3xl font-black text-white" id="stat-txns">0</p>
      </div>
      <div class="text-right ml-4 border-l border-slate-700 pl-8">
        <p class="text-3xl font-black text-white tracking-tight" id="clock-time">--:--</p>
        <p class="text-sm text-slate-400 font-medium" id="clock-date">---</p>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main class="flex-1 p-8 flex gap-8 min-h-0">
    
    <!-- LEADERBOARD (Left) -->
    <section class="flex-1 glass rounded-3xl p-6 flex flex-col min-h-0 border-t border-white/5">
      <div class="flex items-center justify-between mb-6 shrink-0">
        <h2 class="text-xl font-bold text-white flex items-center gap-2">
          <span class="text-amber-400 text-2xl">🏆</span> Today's Leaderboard
        </h2>
        <div class="px-3 py-1 bg-slate-800 rounded-full text-xs font-semibold text-slate-300 border border-slate-700 flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Live
        </div>
      </div>
      
      <div class="flex-1 overflow-hidden">
        <div class="flex flex-col gap-3" id="leaderboard-container">
          <!-- Rendered by JS -->
        </div>
      </div>
    </section>

    <!-- RECENT EVENTS (Right) -->
    <section class="w-[450px] glass rounded-3xl p-6 flex flex-col min-h-0 border-t border-white/5">
      <h2 class="text-xl font-bold text-white mb-6 shrink-0 flex items-center gap-2">
        <span class="text-blue-400 text-2xl">⚡</span> Live Feed
      </h2>
      
      <div class="flex-1 overflow-hidden relative">
        <!-- Fading gradient at bottom -->
        <div class="absolute bottom-0 left-0 w-full h-24 bg-gradient-to-t from-[#141b2c] to-transparent z-10 pointer-events-none"></div>
        <div class="flex flex-col gap-4" id="feed-container">
          <!-- Rendered by JS -->
        </div>
      </div>
    </section>

  </main>

  <!-- ANNOUNCEMENT BANNER (Hidden by default) -->
  <div id="announcement-banner" class="fixed bottom-0 left-0 w-full bg-indigo-600/90 backdrop-blur-xl border-t border-indigo-400/30 p-8 shadow-[0_-10px_50px_rgba(79,70,229,0.3)] z-50 flex items-center justify-center gap-8">
    <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center text-4xl shadow-inner shrink-0 animate-bounce">
      🎉
    </div>
    <div>
      <p class="text-indigo-200 text-lg font-bold uppercase tracking-widest mb-1" id="ann-type">NEW SALE!</p>
      <p class="text-5xl font-black text-white tracking-tight" id="ann-msg">David Smith just closed a booking!</p>
    </div>
  </div>

  <!-- INITIALIZATION OVERLAY (To unlock Audio Context) -->
  <div id="audio-unlock" class="fixed inset-0 bg-slate-900/90 backdrop-blur-sm z-[100] flex flex-col items-center justify-center cursor-pointer">
    <div class="w-24 h-24 bg-indigo-600 rounded-full flex items-center justify-center text-white mb-6 animate-pulse">
      <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z" clip-rule="evenodd"></path></svg>
    </div>
    <h2 class="text-3xl font-bold text-white mb-2">Click anywhere to start</h2>
    <p class="text-slate-400 text-lg">Browser requires a click to enable voice announcements.</p>
  </div>
