<?php
/**
 * Customer Emails — Compose (AI draft → preview/edit → submit).
 *
 * @var string $role
 * @var bool   $canApprove    Manager/admin: submit sends immediately.
 * @var bool   $aiConfigured
 */
$activePage = 'emails';
$csrf = $_SESSION['csrf_token'] ?? '';
$submitLabel = $canApprove ? 'Approve &amp; Send' : 'Submit for Approval';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Compose Customer Email — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config = { darkMode: "class", theme: { extend: {
  fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
  colors: { primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' }, gold: { DEFAULT: '#c9a84c' } }
}}}
</script>
</head>
<body class="bg-slate-50 font-sans min-h-screen">

<?php require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="flex items-center gap-3 mb-6">
    <a href="/emails" class="text-slate-400 hover:text-primary"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
      <span class="material-symbols-outlined text-2xl">edit_note</span> Compose Customer Email
    </h1>
  </div>

  <?php if (!$aiConfigured): ?>
    <div class="mb-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm font-semibold">
      AI drafting is not configured (missing VERTEX_API_KEY). You can still write the email manually.
    </div>
  <?php endif; ?>

  <form method="POST" action="/emails/compose" id="composeForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
    <input type="hidden" name="ai_model"   id="f_ai_model"/>
    <input type="hidden" name="ai_subject" id="f_ai_subject"/>
    <input type="hidden" name="ai_body"    id="f_ai_body"/>
    <input type="hidden" name="intent"     id="f_intent_hidden"/>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- LEFT: intent + grounding -->
      <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">1 · What to send</div>

        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1">Link a booking (optional — grounds the AI in real facts)</label>
          <select id="bookingPicker" class="w-full rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary">
            <option value="">No booking — free-standing email</option>
          </select>
          <input type="hidden" name="transaction_id" id="f_transaction_id"/>
          <p class="text-[11px] text-slate-400 mt-1">When linked, the AI may only use that booking's verified PNR, amounts, and dates.</p>
          <div class="mt-2 flex items-center gap-2">
            <button type="button" id="insertItinBtn" class="hidden inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary/5 text-primary border border-primary/20 text-xs font-bold rounded-lg hover:bg-primary/10 disabled:opacity-50">
              <span class="material-symbols-outlined text-sm" id="insertItinIcon">flight</span> Insert flight itinerary
            </button>
            <span id="insertItinMsg" class="hidden text-[11px] font-semibold"></span>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Customer name</label>
            <input type="text" name="customer_name" id="f_customer_name" class="w-full rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"/>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Customer email <span class="text-rose-500">*</span></label>
            <input type="email" name="customer_email" id="f_customer_email" required class="w-full rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"/>
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1">Email type</label>
          <select name="category" id="f_category" class="w-full rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary">
            <option value="custom">General / custom</option>
            <option value="follow_up">Follow-up</option>
            <option value="payment_reminder">Payment reminder</option>
            <option value="apology">Apology</option>
            <option value="doc_request">Document request</option>
            <option value="itinerary_change">Itinerary change</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1">Describe what you want to say</label>
          <textarea id="f_intent" rows="5" placeholder="e.g. Let the customer know their refund of the full amount has been approved and will arrive in 5-7 business days."
                    class="w-full rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"></textarea>
        </div>

        <button type="button" id="genBtn" <?= $aiConfigured ? '' : 'disabled' ?>
                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gold text-primary font-extrabold rounded-xl hover:brightness-95 transition-all text-sm disabled:opacity-40 disabled:cursor-not-allowed">
          <span class="material-symbols-outlined text-base" id="genIcon">auto_awesome</span>
          <span id="genLabel">Generate Draft with AI</span>
        </button>
        <p id="genError" class="hidden text-xs font-semibold text-rose-600"></p>
      </div>

      <!-- RIGHT: preview + edit -->
      <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">2 · Review &amp; edit</div>
          <span class="text-[11px] text-slate-400">You can edit everything before sending.</span>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1">Subject <span class="text-rose-500">*</span></label>
          <input type="text" name="final_subject" id="f_subject" required class="w-full rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"/>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1">Body <span class="text-rose-500">*</span></label>
          <textarea name="final_body" id="f_body" rows="12" required
                    class="w-full rounded-lg border-slate-300 text-sm font-mono leading-relaxed focus:ring-primary focus:border-primary"></textarea>
          <p class="text-[10px] text-slate-400 mt-1">Tip: <code>**bold**</code> · <code>- bullet</code> · <code>## heading</code> — formatting renders in the email.</p>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 mb-1">Formatted preview <span class="font-normal text-slate-400">(what the customer sees)</span></label>
          <div id="bodyPreview" class="rounded-lg border border-slate-200 bg-slate-50/60 px-4 py-3 text-sm text-slate-700 leading-relaxed min-h-[72px]"><span class="text-slate-400">Your formatted email will appear here…</span></div>
        </div>

        <div id="placeholderWarn" class="hidden px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold">
          ⚠ This draft contains <span id="phCount"></span> unfilled placeholder(s) like <code>[[PLACEHOLDER: …]]</code>. Replace them before sending.
        </div>

        <button type="submit" id="submitBtn"
                class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 bg-primary text-white font-extrabold rounded-xl hover:bg-primary-500 shadow-lg shadow-primary/20 transition-all text-sm">
          <span class="material-symbols-outlined text-base"><?= $canApprove ? 'send' : 'schedule_send' ?></span>
          <?= $submitLabel ?>
        </button>
        <p class="text-[11px] text-center text-slate-400">
          <?= $canApprove
              ? 'This will be sent to the customer immediately.'
              : 'This will be queued for manager approval before it reaches the customer.' ?>
        </p>
      </div>
    </div>
  </form>
</main>

<script>
const CSRF = <?= json_encode($csrf) ?>;

// ── Load booking options for the grounding picker ──────────────────────────
fetch('/emails/booking-options')
  .then(r => r.json())
  .then(d => {
    if (!d.success) return;
    const sel = document.getElementById('bookingPicker');
    window._bookings = {};
    d.options.forEach(o => {
      window._bookings[o.id] = o;
      const opt = document.createElement('option');
      opt.value = o.id; opt.textContent = o.label;
      sel.appendChild(opt);
    });
  }).catch(()=>{});

document.getElementById('bookingPicker').addEventListener('change', function() {
  const id = this.value;
  document.getElementById('f_transaction_id').value = id;
  const b = window._bookings && window._bookings[id];
  if (b) {
    if (b.customer_name)  document.getElementById('f_customer_name').value  = b.customer_name;
    if (b.customer_email) document.getElementById('f_customer_email').value = b.customer_email;
  }
  // Show the "Insert flight itinerary" button only when a booking is linked.
  document.getElementById('insertItinBtn').classList.toggle('hidden', !id);
  document.getElementById('insertItinMsg').classList.add('hidden');
});

// ── Insert the real flight itinerary (Markdown table) from the linked booking ─
document.getElementById('insertItinBtn').addEventListener('click', async function() {
  const id  = document.getElementById('f_transaction_id').value;
  const msg = document.getElementById('insertItinMsg');
  const icon = document.getElementById('insertItinIcon');
  if (!id) return;
  msg.classList.add('hidden');
  this.disabled = true; icon.textContent = 'progress_activity'; icon.classList.add('animate-spin');
  try {
    const res = await fetch('/emails/itinerary/' + id);
    const d = await res.json();
    if (!d.success) {
      msg.textContent = d.error || 'Could not load itinerary.';
      msg.className = 'text-[11px] font-semibold text-rose-600';
      msg.classList.remove('hidden');
      return;
    }
    const body = document.getElementById('f_body');
    const block = '## Flight Itinerary\n\n' + d.markdown + '\n';
    body.value = body.value.trim() ? (body.value.replace(/\s+$/,'') + '\n\n' + block) : block;
    checkPlaceholders(); renderPreview();
    msg.textContent = 'Itinerary inserted — review it below.';
    msg.className = 'text-[11px] font-semibold text-emerald-600';
    msg.classList.remove('hidden');
  } catch (e) {
    msg.textContent = 'Network error loading itinerary.';
    msg.className = 'text-[11px] font-semibold text-rose-600';
    msg.classList.remove('hidden');
  } finally {
    this.disabled = false; icon.textContent = 'flight'; icon.classList.remove('animate-spin');
  }
});

// ── Placeholder detector ───────────────────────────────────────────────────
function checkPlaceholders() {
  const body = document.getElementById('f_body').value;
  const m = body.match(/\[\[\s*PLACEHOLDER/gi);
  const warn = document.getElementById('placeholderWarn');
  if (m && m.length) {
    document.getElementById('phCount').textContent = m.length;
    warn.classList.remove('hidden');
  } else {
    warn.classList.add('hidden');
  }
}
// ── Live formatted preview (mirrors the server-side Markdown renderer) ──────
function mdToHtml(md){
  const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const inline = s => esc(s)
    .replace(/\*\*([^*]+?)\*\*/g,'<strong style="color:#0f1e3c;">$1</strong>')
    .replace(/\*([^*]+?)\*/g,'<em>$1</em>');
  const isRow = s => s.startsWith('|') && (s.match(/\|/g)||[]).length>=2;
  const isSep = s => /^\|(\s*:?-+:?\s*\|)+\s*$/.test(s);
  const cells = s => s.replace(/^\|/,'').replace(/\|$/,'').split('|').map(x=>x.trim());
  const lines = md.replace(/\r\n?/g,'\n').split('\n');
  let out=[], para=[], list=null, items=[];
  const fp=()=>{ if(para.length){ out.push('<p style="margin:0 0 10px;">'+para.join('<br>')+'</p>'); para=[]; } };
  const fl=()=>{ if(list){ out.push('<'+list+' style="margin:0 0 10px;padding-left:20px;">'+items.map(i=>'<li style="margin:0 0 4px;">'+i+'</li>').join('')+'</'+list+'>'); list=null; items=[]; } };
  for(let i=0;i<lines.length;i++){
    const t=lines[i].trim(); let m;
    if(t===''){ fp(); fl(); continue; }
    if(isRow(t) && i+1<lines.length && isSep(lines[i+1].trim())){
      fp(); fl();
      const head=cells(t); i+=2; const rows=[];
      while(i<lines.length && isRow(lines[i].trim())){ rows.push(cells(lines[i].trim())); i++; }
      i--;
      const th=head.map(h=>'<th style="text-align:left;padding:7px 9px;background:#0f1e3c;color:#fff;font-size:10px;text-transform:uppercase;letter-spacing:.4px;">'+inline(h)+'</th>').join('');
      const tb=rows.map((r,ri)=>'<tr style="background:'+(ri%2?'#f8fafc':'#fff')+';">'+head.map((_,ci)=>'<td style="padding:6px 9px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#1e293b;">'+inline(r[ci]||'')+'</td>').join('')+'</tr>').join('');
      out.push('<table style="width:100%;border-collapse:collapse;margin:0 0 10px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;"><thead><tr>'+th+'</tr></thead><tbody>'+tb+'</tbody></table>');
      continue;
    }
    if(m=t.match(/^(#{1,3})\s+(.*)$/)){ fp(); fl(); out.push('<div style="font-weight:800;color:#0f1e3c;margin:12px 0 6px;">'+inline(m[2])+'</div>'); continue; }
    if(m=t.match(/^[-*]\s+(.*)$/)){ fp(); if(list!=='ul'){ fl(); list='ul'; } items.push(inline(m[1])); continue; }
    if(m=t.match(/^\d+[.)]\s+(.*)$/)){ fp(); if(list!=='ol'){ fl(); list='ol'; } items.push(inline(m[1])); continue; }
    fl(); para.push(inline(t));
  }
  fp(); fl();
  return out.join('');
}
function renderPreview(){
  const md = document.getElementById('f_body').value;
  const box = document.getElementById('bodyPreview');
  box.innerHTML = md.trim() ? mdToHtml(md) : '<span class="text-slate-400">Your formatted email will appear here…</span>';
}
document.getElementById('f_body').addEventListener('input', () => { checkPlaceholders(); renderPreview(); });

// ── Generate draft (AJAX → AI) ─────────────────────────────────────────────
document.getElementById('genBtn').addEventListener('click', async function() {
  const intent = document.getElementById('f_intent').value.trim();
  const errEl = document.getElementById('genError');
  errEl.classList.add('hidden');
  if (!intent) { errEl.textContent = 'Please describe what you want to say first.'; errEl.classList.remove('hidden'); return; }

  const btn = this, label = document.getElementById('genLabel'), icon = document.getElementById('genIcon');
  btn.disabled = true; label.textContent = 'Generating…'; icon.textContent = 'progress_activity'; icon.classList.add('animate-spin');

  try {
    const res = await fetch('/emails/draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        csrf_token: CSRF,
        intent,
        category: document.getElementById('f_category').value,
        customer_name: document.getElementById('f_customer_name').value || '',
        transaction_id: document.getElementById('f_transaction_id').value || ''
      })
    });
    const d = await res.json();
    if (!d.success) { errEl.textContent = d.error || 'Could not generate a draft.'; errEl.classList.remove('hidden'); return; }

    document.getElementById('f_subject').value    = d.subject;
    document.getElementById('f_body').value       = d.body;
    document.getElementById('f_ai_subject').value = d.subject;
    document.getElementById('f_ai_body').value    = d.body;
    document.getElementById('f_ai_model').value   = d.ai_model || '';
    document.getElementById('f_intent_hidden').value = intent;
    checkPlaceholders();
    renderPreview();
  } catch (e) {
    errEl.textContent = 'Network error reaching the AI. Please try again.'; errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false; label.textContent = 'Regenerate Draft'; icon.textContent = 'auto_awesome'; icon.classList.remove('animate-spin');
  }
});

// Keep the hidden intent in sync if the agent writes manually (no AI).
document.getElementById('composeForm').addEventListener('submit', function() {
  if (!document.getElementById('f_intent_hidden').value) {
    document.getElementById('f_intent_hidden').value = document.getElementById('f_intent').value;
  }
});
</script>
</body>
</html>
