<?php
/**
 * Mobile Admin Quick Panel
 * Single-page app using Vanilla JS to fetch data from MobileAdminController APIs.
 */
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport"/>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>Mobile Admin - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa",surface:"#f8f9fa","surface-container-lowest":"#ffffff","surface-container-low":"#f3f4f5","surface-container":"#edeeef","on-surface":"#191c1d","on-surface-variant":"#434653","outline-variant":"#c3c6d5",error:"#ba1a1a","error-container":"#ffdad6"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}}
</script>
<style>
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
  .tab-content { display: none; }
  .tab-content.active { display: block; }
  .nav-btn { color: #a1a1aa; }
  .nav-btn.active { color: #163274; }
  
  /* Hide scrollbar for a cleaner mobile look */
  ::-webkit-scrollbar { display: none; }
  * { -ms-overflow-style: none; scrollbar-width: none; }
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased pb-20">

<!-- Header -->
<header class="bg-white px-4 py-3 flex items-center justify-between shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-2">
    <div class="w-8 h-8 rounded bg-primary text-white flex items-center justify-center font-bold text-sm">BF</div>
    <h1 class="font-headline font-bold text-lg text-primary">Admin Panel</h1>
  </div>
  <a href="/logout" class="text-xs font-semibold text-on-surface-variant hover:text-primary">Exit</a>
</header>

<main class="p-4" id="app-container">
  
  <!-- DASHBOARD TAB -->
  <section id="tab-dashboard" class="tab-content active space-y-4">
    <h2 class="text-xl font-headline font-bold text-primary mb-1">Today's Overview</h2>
    <p class="text-[11px] text-slate-400 mb-2">Current shift: <span id="dash-shift-label" class="font-semibold text-slate-500">…</span></p>
    <div class="grid grid-cols-2 gap-3">
      <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
        <p class="text-xs text-slate-500 font-bold uppercase mb-1">Profit (MCO)</p>
        <p class="text-xl font-headline font-extrabold text-emerald-600" id="dash-today-profit">...</p>
      </div>
      <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
        <p class="text-xs text-slate-500 font-bold uppercase mb-1">Txns</p>
        <p class="text-xl font-headline font-extrabold text-primary" id="dash-today-txns">...</p>
      </div>
    </div>
    
    <div class="grid grid-cols-2 gap-3 mt-2">
      <div class="bg-amber-50 rounded-xl p-3 border border-amber-100">
        <p class="text-[10px] text-amber-700 font-bold uppercase">Pending Txns</p>
        <p class="text-lg font-bold text-amber-800" id="dash-pending-txns">...</p>
      </div>
      <div class="bg-blue-50 rounded-xl p-3 border border-blue-100">
        <p class="text-[10px] text-blue-700 font-bold uppercase">Pending Acc</p>
        <p class="text-lg font-bold text-blue-800" id="dash-pending-acc">...</p>
      </div>
    </div>

    <h3 class="text-sm font-bold text-slate-400 uppercase mt-6 mb-2">Today's Leaderboard</h3>
    <div id="dash-leaderboard" class="space-y-2"></div>
  </section>

  <!-- LIVE BOARD TAB -->
  <section id="tab-live" class="tab-content space-y-4">
    <h2 class="text-xl font-headline font-bold text-primary mb-2 flex items-center gap-2">
      <span class="material-symbols-outlined text-red-500">sensors</span> Live Shift
    </h2>
    <div class="bg-gradient-to-br from-primary to-primary-container text-white rounded-2xl p-5 shadow-md">
      <p class="text-xs font-bold opacity-80 uppercase tracking-wide">Shift Profit</p>
      <div class="flex items-end gap-2 mt-1">
        <span class="text-4xl font-headline font-extrabold" id="live-profit">...</span>
        <span class="text-lg font-medium opacity-80 mb-1" id="live-currency">USD</span>
      </div>
      <p class="text-sm font-medium mt-2"><span id="live-txns" class="font-bold">...</span> Approved Transactions</p>
    </div>

    <h3 class="text-sm font-bold text-slate-400 uppercase mt-6 mb-2">Live Feed</h3>
    <div id="live-feed" class="space-y-3"></div>
  </section>

  <!-- ATTENDANCE TAB -->
  <section id="tab-attendance" class="tab-content space-y-4">
    <h2 class="text-xl font-headline font-bold text-primary mb-2">Attendance</h2>
    
    <div class="grid grid-cols-2 gap-3">
      <div class="bg-green-50 rounded-xl p-3 flex justify-between items-center border border-green-100">
        <span class="text-xs font-bold text-green-800">Clocked In</span>
        <span class="text-lg font-headline font-extrabold text-green-700" id="att-in">...</span>
      </div>
      <div class="bg-amber-50 rounded-xl p-3 flex justify-between items-center border border-amber-100">
        <span class="text-xs font-bold text-amber-800">On Break</span>
        <span class="text-lg font-headline font-extrabold text-amber-700" id="att-break">...</span>
      </div>
      <div class="bg-blue-50 rounded-xl p-3 flex justify-between items-center border border-blue-100">
        <span class="text-xs font-bold text-blue-800">Completed</span>
        <span class="text-lg font-headline font-extrabold text-blue-700" id="att-completed">...</span>
      </div>
      <div class="bg-red-50 rounded-xl p-3 flex justify-between items-center border border-red-100">
        <span class="text-xs font-bold text-red-800">Pending</span>
        <span class="text-lg font-headline font-extrabold text-red-700" id="att-override">...</span>
      </div>
    </div>

    <div id="att-override-container" class="mt-4 hidden">
      <h3 class="text-sm font-bold text-red-500 uppercase mb-2 flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">warning</span> Override Queue
      </h3>
      <div id="att-override-list" class="space-y-3"></div>
    </div>

    <div class="mt-6">
      <h3 class="text-sm font-bold text-slate-400 uppercase mb-2">Currently Working</h3>
      <div id="att-working-list" class="space-y-2"></div>
    </div>

    <div class="mt-6">
      <h3 class="text-sm font-bold text-slate-400 uppercase mb-2">On Break</h3>
      <div id="att-break-list" class="space-y-2"></div>
    </div>

    <div class="mt-6">
      <h3 class="text-sm font-bold text-slate-400 uppercase mb-2">Absent / Not Clocked In</h3>
      <div id="att-absent-list" class="space-y-2"></div>
    </div>

    <div class="mt-6">
      <h3 class="text-sm font-bold text-slate-400 uppercase mb-2">Completed Today</h3>
      <div id="att-completed-list" class="space-y-2"></div>
    </div>
  </section>

  <!-- TRANSACTIONS TAB -->
  <section id="tab-transactions" class="tab-content space-y-4">
    <h2 class="text-xl font-headline font-bold text-primary mb-2">Transactions</h2>
    
    <!-- Filter Pills -->
    <div class="flex gap-2 overflow-x-auto pb-2">
      <button onclick="loadTransactions('all')" class="txn-filter active shrink-0 px-4 py-1.5 rounded-full text-xs font-bold bg-primary text-white">All</button>
      <button onclick="loadTransactions('pending')" class="txn-filter shrink-0 px-4 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">Pending</button>
      <button onclick="loadTransactions('approved')" class="txn-filter shrink-0 px-4 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">Approved</button>
      <button onclick="loadTransactions('voided')" class="txn-filter shrink-0 px-4 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">Voided</button>
    </div>

    <div id="txn-list" class="space-y-3"></div>
    
    <button onclick="loadMoreTransactions()" id="txn-load-more" class="w-full py-3 bg-slate-100 text-slate-600 rounded-xl text-sm font-bold hidden">Load More</button>
  </section>

  <!-- ACTIONS TAB -->
  <section id="tab-actions" class="tab-content space-y-4">
    <h2 class="text-xl font-headline font-bold text-primary mb-2">Action Queue</h2>
    
    <div>
      <h3 class="text-sm font-bold text-amber-600 uppercase mb-2 flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">pending_actions</span> Pending Approvals
      </h3>
      <div id="act-txns-list" class="space-y-3"></div>
    </div>

    <div class="mt-6">
      <h3 class="text-sm font-bold text-blue-600 uppercase mb-2 flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">schedule</span> Pending Acceptances
      </h3>
      <div id="act-acc-list" class="space-y-3"></div>
    </div>
  </section>

</main>

<!-- Bottom Nav Bar -->
<nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 pb-safe shadow-[0_-4px_15px_rgba(0,0,0,0.05)] z-50">
  <div class="flex justify-around items-center p-2">
    <button onclick="switchTab('dashboard', this)" class="nav-btn active flex flex-col items-center p-2 w-16">
      <span class="material-symbols-outlined text-[28px]">dashboard</span>
      <span class="text-[10px] font-bold mt-1">Dash</span>
    </button>
    <button onclick="switchTab('live', this)" class="nav-btn flex flex-col items-center p-2 w-16">
      <span class="material-symbols-outlined text-[28px]">vital_signs</span>
      <span class="text-[10px] font-bold mt-1">Live</span>
    </button>
    <button onclick="switchTab('attendance', this)" class="nav-btn flex flex-col items-center p-2 w-16">
      <span class="material-symbols-outlined text-[28px]">how_to_reg</span>
      <span class="text-[10px] font-bold mt-1">Users</span>
    </button>
    <button onclick="switchTab('transactions', this)" class="nav-btn flex flex-col items-center p-2 w-16">
      <span class="material-symbols-outlined text-[28px]">receipt_long</span>
      <span class="text-[10px] font-bold mt-1">Txns</span>
    </button>
    <button onclick="switchTab('actions', this)" class="nav-btn flex flex-col items-center p-2 w-16 relative">
      <span class="material-symbols-outlined text-[28px]">task_alt</span>
      <span class="text-[10px] font-bold mt-1">Action</span>
      <span id="nav-action-badge" class="absolute top-1 right-2 w-3 h-3 bg-red-500 rounded-full hidden"></span>
    </button>
  </div>
</nav>

<script>
const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';

// ── TAB NAVIGATION ────────────────────────────────────────────────────────
function switchTab(tabId, btnElement) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + tabId).classList.add('active');
  
  document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('active', 'text-primary'));
  if (btnElement) btnElement.classList.add('active');

  if (tabId === 'dashboard') loadDashboard();
  if (tabId === 'live') loadLiveBoard();
  if (tabId === 'attendance') loadAttendance();
  if (tabId === 'transactions') loadTransactions();
  if (tabId === 'actions') loadActions();
}

// ── DASHBOARD ─────────────────────────────────────────────────────────────
async function loadDashboard() {
  try {
    const r = await fetch('/api/admin/mobile/data');
    const d = await r.json();
    
    if (d.shift_label) document.getElementById('dash-shift-label').textContent = d.shift_label;
    document.getElementById('dash-today-profit').textContent = '+' + d.today_profit.toFixed(2);
    document.getElementById('dash-today-txns').textContent = d.today_txns;
    document.getElementById('dash-pending-txns').textContent = d.pending_txns;
    document.getElementById('dash-pending-acc').textContent = d.pending_acc;

    // Leaderboard
    const lb = document.getElementById('dash-leaderboard');
    lb.innerHTML = d.leaderboard.map((item, idx) => `
      <div class="bg-white rounded-xl p-3 flex items-center justify-between shadow-sm border border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center font-bold text-primary text-xs">
            ${idx+1}
          </div>
          <div>
            <p class="font-bold text-sm text-slate-800">${item.name}</p>
            <p class="text-xs text-slate-500">${item.txn_count} txns</p>
          </div>
        </div>
        <p class="font-bold text-emerald-600">+${item.currency} ${item.profit.toFixed(2)}</p>
      </div>
    `).join('');

    // Badge update
    const badge = document.getElementById('nav-action-badge');
    if (d.pending_txns > 0 || d.pending_acc > 0) {
      badge.classList.remove('hidden');
    } else {
      badge.classList.add('hidden');
    }

  } catch(e) { console.error(e); }
}

// ── LIVE BOARD ────────────────────────────────────────────────────────────
async function loadLiveBoard() {
  try {
    const r = await fetch('/api/admin/mobile/data');
    const d = await r.json();

    document.getElementById('live-profit').textContent = '+' + d.today_profit.toFixed(0);
    document.getElementById('live-txns').textContent = d.today_txns;

    const feed = document.getElementById('live-feed');
    feed.innerHTML = d.recent.map(item => `
      <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-100 flex gap-3">
        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 shrink-0">
          <span class="material-symbols-outlined">paid</span>
        </div>
        <div class="flex-1">
          <p class="text-xs text-slate-400">${item.created_at} — ${item.agent}</p>
          <p class="font-bold text-sm text-slate-800">Approved: ${item.type || 'Booking'}</p>
          <p class="font-bold text-emerald-600">+${item.mco.toFixed(2)}</p>
        </div>
      </div>
    `).join('');

  } catch(e) { console.error(e); }
}

// ── ATTENDANCE ────────────────────────────────────────────────────────────
async function loadAttendance() {
  try {
    const r = await fetch('/attendance/admin/data');
    const d = await r.json();

    document.getElementById('att-in').textContent = d.in_count;
    document.getElementById('att-break').textContent = d.break_count;
    document.getElementById('att-completed').textContent = d.completed_count || 0;
    document.getElementById('att-override').textContent = d.pending_count;

    // Overrides
    const ovc = document.getElementById('att-override-container');
    const ovl = document.getElementById('att-override-list');
    if (d.pending_count > 0) {
      ovc.classList.remove('hidden');
      ovl.innerHTML = d.pending_agents.map(a => `
        <div class="bg-red-50 rounded-xl p-3 border border-red-200">
          <div class="flex justify-between items-center mb-2">
            <p class="font-bold text-red-900">${a.name}</p>
            <span class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">Blocked</span>
          </div>
          <input type="text" id="ov-reason-${a.id}" placeholder="Reason..." class="w-full rounded bg-white border-red-200 text-xs py-1.5 px-2 mb-2">
          <div class="flex gap-2">
            <button onclick="apprOv(${a.id})" class="flex-1 bg-red-600 text-white text-xs font-bold py-2 rounded">Approve</button>
            <button onclick="denyOv(${a.id})" class="flex-1 bg-red-200 text-red-800 text-xs font-bold py-2 rounded">Deny</button>
          </div>
        </div>
      `).join('');
    } else {
      ovc.classList.add('hidden');
    }

    // Working
    document.getElementById('att-working-list').innerHTML = d.in_agents.map(i => `
      <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-100 flex items-center justify-between">
        <div>
          <p class="font-bold text-sm text-slate-800">${i.name}</p>
          <p class="text-[10px] text-slate-400">In since ${new Date(i.clock_in).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</p>
        </div>
        <button onclick="clkOut(${i.id}, '${i.name.replace(/'/g, "\\'")}')" class="w-8 h-8 rounded-full bg-red-50 text-red-600 flex items-center justify-center">
          <span class="material-symbols-outlined text-[18px]">logout</span>
        </button>
      </div>
    `).join('');

    // Break
    document.getElementById('att-break-list').innerHTML = (d.break_agents || []).map(a => {
      const startTs = new Date(a.start).getTime() / 1000;
      const elapsed = Math.round((Math.floor(Date.now()/1000) - startTs) / 60);
      return `
      <div class="bg-amber-50 rounded-xl p-3 shadow-sm border border-amber-200 flex items-center justify-between">
        <div>
          <p class="font-bold text-sm text-amber-900">${a.name}</p>
          <p class="text-[10px] text-amber-700 font-bold">${a.type} break · ${elapsed}m</p>
        </div>
        <button onclick="endBrk(${a.id}, '${a.name.replace(/'/g, "\\'")}')" class="px-3 py-1.5 rounded-lg bg-orange-100 text-orange-700 text-xs font-bold">End</button>
      </div>
      `;
    }).join('') || '<p class="text-xs text-slate-400">No one on break</p>';

    // Absent
    document.getElementById('att-absent-list').innerHTML = (d.absent_agents || []).map(a => `
      <div class="bg-slate-50 rounded-xl p-3 shadow-sm border border-slate-200 flex items-center justify-between">
        <p class="font-bold text-sm text-slate-700">${a.name}</p>
        <button onclick="clkIn(${a.id}, '${a.name.replace(/'/g, "\\'")}')" class="px-3 py-1.5 rounded-lg bg-primary/10 text-primary text-xs font-bold flex items-center gap-1">
          <span class="material-symbols-outlined text-[14px]">login</span> Clock In
        </button>
      </div>
    `).join('') || '<p class="text-xs text-slate-400">None</p>';

    // Completed
    document.getElementById('att-completed-list').innerHTML = (d.completed_agents || []).map(a => `
      <div class="bg-blue-50 rounded-xl p-3 shadow-sm border border-blue-100">
        <p class="font-bold text-sm text-blue-900">${a.name}</p>
        <p class="text-[10px] text-blue-700">${Math.floor(a.work_mins/60)}h ${a.work_mins%60}m worked · Out at ${new Date(a.clock_out).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</p>
      </div>
    `).join('') || '<p class="text-xs text-slate-400">None</p>';

  } catch(e) { console.error(e); }
}

async function apprOv(id) {
  const rsn = document.getElementById('ov-reason-'+id).value.trim();
  if(rsn.length<3) return alert('Need reason');
  await post('/attendance/override', {agent_id: id, date: new Date().toISOString().split('T')[0], reason: rsn});
  loadAttendance();
}
async function denyOv(id) {
  const rsn = prompt('Deny reason:');
  if(!rsn || rsn.length<3) return;
  await post('/attendance/deny', {agent_id: id, date: new Date().toISOString().split('T')[0], reason: rsn});
  loadAttendance();
}
async function clkOut(id, name) {
  if(!confirm('Force clock out '+name+'?')) return;
  await post('/attendance/admin/clock-out', {agent_id: id});
  loadAttendance();
}
async function endBrk(id, name) {
  if(!confirm('Force end break for '+name+'?')) return;
  await post('/attendance/admin/force-end-break', {agent_id: id});
  loadAttendance();
}

// ── TRANSACTIONS ──────────────────────────────────────────────────────────
let txnPage = 1;
let txnStatus = 'all';

async function loadTransactions(status = txnStatus, page = 1) {
  txnStatus = status;
  txnPage = page;
  
  // update UI filters
  document.querySelectorAll('.txn-filter').forEach(el => {
    el.classList.remove('bg-primary', 'text-white');
    el.classList.add('bg-slate-100', 'text-slate-600');
  });
  event && event.currentTarget && event.currentTarget.classList.add('bg-primary', 'text-white');
  event && event.currentTarget && event.currentTarget.classList.remove('bg-slate-100', 'text-slate-600');

  try {
    const r = await fetch(`/api/admin/mobile/transactions?status=${status}&page=${page}`);
    const d = await r.json();

    const html = d.data.map(t => `
      <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-100" onclick="toggleTxnDetail(${t.id})">
        <div class="flex justify-between items-start mb-2">
          <div>
            <p class="font-bold text-sm text-slate-800">${t.customer_name}</p>
            <p class="text-xs font-mono font-bold text-primary">${t.pnr}</p>
          </div>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded ${t.status==='approved'?'bg-green-100 text-green-700':t.status==='pending_review'?'bg-amber-100 text-amber-700':'bg-red-100 text-red-700'}">${t.status}</span>
        </div>
        <div class="flex justify-between items-end">
          <div>
            <p class="text-[10px] text-slate-400">${t.agent} · ${t.created_at}</p>
          </div>
          <p class="font-bold text-sm ${t.mco>=0?'text-emerald-600':'text-red-600'}">${t.mco>=0?'+':''}${t.currency} ${t.mco.toFixed(2)}</p>
        </div>
        
        <div id="txn-detail-${t.id}" class="hidden mt-3 pt-3 border-t border-slate-100">
          <p class="text-xs text-slate-600 mb-1"><strong>Type:</strong> ${t.type}</p>
          <p class="text-xs text-slate-600 mb-1"><strong>Total:</strong> ${t.amount.toFixed(2)} ${t.currency}</p>
          <p class="text-xs text-slate-600 mb-2"><strong>Gateway:</strong> ${t.gateway_status || 'Pending'}</p>
          <a href="/transactions/${t.id}" class="text-xs font-bold text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span> Open Full View</a>
        </div>
      </div>
    `).join('');

    const list = document.getElementById('txn-list');
    if (page === 1) list.innerHTML = html;
    else list.innerHTML += html;

    const btn = document.getElementById('txn-load-more');
    if (page < d.last_page) btn.classList.remove('hidden');
    else btn.classList.add('hidden');

  } catch(e) { console.error(e); }
}

function loadMoreTransactions() {
  loadTransactions(txnStatus, txnPage + 1);
}

// ── ACTIONS ───────────────────────────────────────────────────────────────
async function loadActions() {
  try {
    const r = await fetch('/api/admin/mobile/actions');
    const d = await r.json();

    // Pending TXNs
    document.getElementById('act-txns-list').innerHTML = d.pending_txns.map(t => `
      <div class="bg-amber-50 rounded-xl p-3 shadow-sm border border-amber-200">
        <div class="flex justify-between items-start mb-2">
          <div>
            <p class="font-bold text-sm text-amber-900">${t.customer_name}</p>
            <p class="text-xs font-mono font-bold text-primary">${t.pnr}</p>
          </div>
          <p class="font-bold text-sm text-emerald-600">+${t.currency} ${t.mco.toFixed(2)}</p>
        </div>
        
        <div class="bg-white rounded-lg p-2 mt-2 border border-amber-100 space-y-2">
          <button onclick="approveTxn(${t.id})" class="w-full bg-emerald-600 text-white text-xs font-bold py-2 rounded flex justify-center items-center gap-1">
            <span class="material-symbols-outlined text-[14px]">check_circle</span> Approve
          </button>
          
          <div class="pt-2 border-t border-slate-100 mt-2">
            <select id="gw-stat-${t.id}" class="w-full text-xs p-1.5 border rounded mb-1" onchange="toggleGw(${t.id})">
              <option value="charge_successful" ${t.gateway_status==='charge_successful'?'selected':''}>✓ Charge Successful</option>
              <option value="charge_declined" ${t.gateway_status==='charge_declined'?'selected':''}>✗ Charge Declined</option>
            </select>
            <input type="text" id="gw-id-${t.id}" placeholder="Gateway ID (req for success)" value="${t.gateway_txn_id}" class="w-full text-xs p-1.5 border rounded mb-1 ${t.gateway_status==='charge_declined'?'hidden':''}">
            <button onclick="saveGw(${t.id})" class="w-full bg-slate-800 text-white text-xs font-bold py-1.5 rounded">Save Gateway</button>
          </div>
        </div>
      </div>
    `).join('') || '<p class="text-xs text-slate-400">No pending transactions.</p>';

    // Pending Acceptances
    document.getElementById('act-acc-list').innerHTML = d.pending_acc.map(a => `
      <div class="bg-blue-50 rounded-xl p-3 shadow-sm border border-blue-200" onclick="toggleAccDetail(${a.id})">
        <div class="flex justify-between items-center">
          <div>
            <p class="font-bold text-sm text-blue-900">${a.customer_name}</p>
            <p class="text-[10px] text-blue-700">${a.pnr} · ${a.amount} ${a.currency}</p>
          </div>
          <span class="material-symbols-outlined text-blue-400">expand_more</span>
        </div>
        <div id="acc-detail-${a.id}" class="hidden mt-3 pt-3 border-t border-blue-100">
           <p class="text-xs text-blue-800 mb-1"><strong>Agent:</strong> ${a.agent}</p>
           <p class="text-xs text-blue-800 mb-2"><strong>Time:</strong> ${a.created_at}</p>
           <a href="/acceptance/${a.id}" class="text-xs font-bold text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span> Open Full View</a>
        </div>
      </div>
    `).join('') || '<p class="text-xs text-slate-400">No pending acceptances.</p>';

  } catch(e) { console.error(e); }
}

function toggleGw(id) {
  const sel = document.getElementById('gw-stat-'+id).value;
  const inp = document.getElementById('gw-id-'+id);
  if(sel === 'charge_successful') inp.classList.remove('hidden');
  else inp.classList.add('hidden');
}

async function approveTxn(id) {
  if(!confirm('Approve transaction?')) return;
  await post('/transactions/'+id+'/approve', {});
  loadActions();
}

async function saveGw(id) {
  const stat = document.getElementById('gw-stat-'+id).value;
  const gid  = document.getElementById('gw-id-'+id).value;
  if(stat==='charge_successful' && !gid) return alert('Gateway ID required for success.');
  await post('/transactions/'+id+'/gateway', {gateway_status: stat, gateway_transaction_id: gid});
  alert('Gateway saved!');
  loadActions();
}

// ── UTILS ─────────────────────────────────────────────────────────────────
async function post(url, data) {
  return fetch(url, { 
    method: 'POST', 
    body: JSON.stringify(data), 
    headers: { 
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken
    } 
  });
}

function toggleTxnDetail(id) {
  const el = document.getElementById('txn-detail-'+id);
  el.classList.toggle('hidden');
}

function toggleAccDetail(id) {
  const el = document.getElementById('acc-detail-'+id);
  el.classList.toggle('hidden');
}

async function clkIn(id, name) {
  if(!confirm('Force clock in '+name+'?')) return;
  await post('/attendance/admin/clock-in', {agent_id: id});
  loadAttendance();
}

// Auto-refresh loops
setInterval(() => {
  if (document.getElementById('tab-dashboard').classList.contains('active')) loadDashboard();
  if (document.getElementById('tab-live').classList.contains('active')) loadLiveBoard();
  if (document.getElementById('tab-attendance').classList.contains('active')) loadAttendance();
}, 30000);

// Init
document.addEventListener('DOMContentLoaded', () => loadDashboard());
</script>
</body>
</html>
