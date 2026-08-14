<?php
/**
 * Centre Settings + Marketing Spend — admin.
 *
 * @var array  $cfg     ['moh_manager_id','jsr_manager_id','dmr_member_ids','overrides']
 * @var array  $map     [user_id => centre]
 * @var \Illuminate\Support\Collection $users
 * @var \Illuminate\Support\Collection $spends
 * @var ?string $flashSuccess $flashError
 */
use App\Services\CentreService;

$activePage = 'analytics';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['csrf_token'] ?? '';

$nameOf = [];
foreach ($users as $u) { $nameOf[(int)$u->id] = $u->name . ' (' . $u->role . ')'; }

// resolved counts + member name lists per centre
$members = [CentreService::DMR=>[], CentreService::MOH=>[], CentreService::JSR=>[]];
foreach ($map as $uid=>$centre) { if (isset($members[$centre])) $members[$centre][] = $nameOf[(int)$uid] ?? ('#'.$uid); }
$JS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Centre Settings — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="/assets/js/error-beacon.js"></script>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"]}}}};
</script>
<style>.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}.fld{margin-top:.25rem;width:100%;border:1px solid #e2e8f0;border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;background:#f8fafc}</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-8 pb-20 px-10 max-w-5xl">

  <div class="flex items-center justify-between mb-6">
    <div>
      <a href="/analytics" class="text-xs font-semibold text-slate-400 hover:text-primary inline-flex items-center gap-1 mb-1"><span class="material-symbols-outlined text-sm">arrow_back</span> Back to analytics</a>
      <h1 class="text-3xl font-headline font-extrabold text-primary tracking-tight">Centres &amp; Marketing Spend</h1>
    </div>
  </div>

  <?php if (!empty($flashSuccess)): ?>
  <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-700 flex items-center gap-2"><span class="material-symbols-outlined text-base">check_circle</span><?= $h($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
  <div class="mb-4 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-sm font-semibold text-rose-700 flex items-center gap-2"><span class="material-symbols-outlined text-base">error</span><?= $h($flashError) ?></div>
  <?php endif; ?>

  <!-- Centre config -->
  <form method="POST" action="/analytics/settings" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-6">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"/>
    <h2 class="font-headline font-extrabold text-slate-900 mb-1">Centre Definition</h2>
    <p class="text-sm text-slate-400 mb-5">MOH and JSR derive from their manager's team (auto-updates as the team changes). DMR is an explicit list and takes priority — its members are pulled out of MOH even if they report to the MOH manager.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
      <div>
        <label class="text-[11px] font-bold uppercase tracking-wide text-slate-500">MOH Manager (e.g. Cyrus)</label>
        <select name="moh_manager_id" class="fld">
          <option value="0">— none —</option>
          <?php foreach ($users as $u): if (!in_array($u->role, ['manager','admin','supervisor'])) continue; ?>
          <option value="<?= (int)$u->id ?>" <?= $cfg['moh_manager_id']===(int)$u->id?'selected':'' ?>><?= $h($u->name) ?> (<?= $h($u->role) ?>)</option>
          <?php endforeach; ?>
        </select>
        <p class="text-[11px] text-slate-400 mt-1">MOH = this person + everyone under them, minus DMR.</p>
      </div>
      <div>
        <label class="text-[11px] font-bold uppercase tracking-wide text-slate-500">JSR Manager (e.g. Thomas Jaan)</label>
        <select name="jsr_manager_id" class="fld">
          <option value="0">— none —</option>
          <?php foreach ($users as $u): if (!in_array($u->role, ['manager','admin','supervisor'])) continue; ?>
          <option value="<?= (int)$u->id ?>" <?= $cfg['jsr_manager_id']===(int)$u->id?'selected':'' ?>><?= $h($u->name) ?> (<?= $h($u->role) ?>)</option>
          <?php endforeach; ?>
        </select>
        <p class="text-[11px] text-slate-400 mt-1">JSR = this person + everyone under them.</p>
      </div>
    </div>

    <div class="mb-5">
      <label class="text-[11px] font-bold uppercase tracking-wide text-slate-500">DMR Members (explicit — no manager)</label>
      <select name="dmr_member_ids[]" multiple size="6" class="fld">
        <?php foreach ($users as $u): if (!in_array($u->role, ['agent','supervisor','csa'])) continue; ?>
        <option value="<?= (int)$u->id ?>" <?= in_array((int)$u->id, $cfg['dmr_member_ids'], true)?'selected':'' ?>><?= $h($u->name) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="text-[11px] text-slate-400 mt-1">Ctrl/Cmd-click to select multiple (e.g. Travis, Noah, Max, Tia).</p>
    </div>

    <!-- Overrides -->
    <div class="mb-5">
      <div class="flex items-center justify-between mb-2">
        <label class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Manual Overrides (add/remove specific agents)</label>
        <button type="button" onclick="addOverride()" class="text-[11px] font-bold text-primary hover:underline flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Add override</button>
      </div>
      <div id="ovrRows" class="space-y-2"></div>
      <p class="text-[11px] text-slate-400 mt-1">An override forces a specific user into a centre, regardless of their reporting line.</p>
    </div>

    <button type="submit" class="px-6 py-2.5 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary-container transition-all">Save Configuration</button>
  </form>

  <!-- Resolved preview -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
    <?php foreach (CentreService::ALL as $code): ?>
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200">
      <div class="flex items-center gap-2 mb-2"><span class="w-3 h-3 rounded-full" style="background:<?= CentreService::COLORS[$code] ?>"></span><h3 class="font-bold text-slate-900"><?= $h(CentreService::LABELS[$code]) ?></h3><span class="ml-auto text-[11px] font-bold text-slate-400"><?= count($members[$code]) ?></span></div>
      <p class="text-[12px] text-slate-500 leading-relaxed"><?= $members[$code] ? $h(implode(', ', $members[$code])) : '<span class="text-slate-300">no members</span>' ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Marketing spend -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/60 flex items-center gap-2">
      <span class="material-symbols-outlined text-primary">payments</span>
      <h2 class="font-headline font-extrabold text-slate-900">Marketing Spend</h2>
    </div>
    <form method="POST" action="/analytics/spend" class="p-6 grid grid-cols-2 md:grid-cols-7 gap-3 items-end border-b border-slate-100">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"/>
      <div><label class="text-[10px] font-bold uppercase text-slate-500">Centre</label>
        <select name="centre" class="fld"><?php foreach (CentreService::ALL as $code): ?><option value="<?= $code ?>"><?= $code ?></option><?php endforeach; ?></select></div>
      <div><label class="text-[10px] font-bold uppercase text-slate-500">From</label><input type="date" name="period_start" required class="fld"/></div>
      <div><label class="text-[10px] font-bold uppercase text-slate-500">To</label><input type="date" name="period_end" required class="fld"/></div>
      <div><label class="text-[10px] font-bold uppercase text-slate-500">Amount</label><input type="number" step="0.01" min="0.01" name="amount" required class="fld"/></div>
      <div><label class="text-[10px] font-bold uppercase text-slate-500">Currency</label>
        <select name="currency" class="fld"><?php foreach (['USD','CAD','GBP','EUR'] as $cc): ?><option value="<?= $cc ?>"><?= $cc ?></option><?php endforeach; ?></select></div>
      <div><label class="text-[10px] font-bold uppercase text-slate-500">Channel</label><input type="text" name="channel" placeholder="Google Ads" class="fld"/></div>
      <button type="submit" class="px-4 py-2.5 bg-primary text-white rounded-lg text-sm font-bold hover:bg-primary-container transition-all">Add</button>
    </form>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
          <th class="px-6 py-3 font-bold">Centre</th><th class="px-6 py-3 font-bold">Period</th><th class="px-6 py-3 font-bold text-right">Amount</th><th class="px-6 py-3 font-bold">Channel</th><th class="px-6 py-3 font-bold">By</th><th class="px-6 py-3"></th>
        </tr></thead>
        <tbody>
          <?php if ($spends->isEmpty()): ?>
          <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">No marketing spend recorded yet.</td></tr>
          <?php else: foreach ($spends as $s): ?>
          <tr class="border-b border-slate-50">
            <td class="px-6 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold text-white" style="background:<?= CentreService::COLORS[$s->centre] ?? '#64748b' ?>"><?= $h($s->centre) ?></span></td>
            <td class="px-6 py-3 text-slate-500 text-[13px]"><?= $h($s->period_start->format('M j')) ?> – <?= $h($s->period_end->format('M j, Y')) ?></td>
            <td class="px-6 py-3 text-right font-bold text-slate-700"><?= $h($s->currency) ?> <?= number_format($s->amount, 2) ?></td>
            <td class="px-6 py-3 text-slate-500 text-[13px]"><?= $h($s->channel ?: '—') ?></td>
            <td class="px-6 py-3 text-slate-400 text-[13px]"><?= $h($s->creator->name ?? '—') ?></td>
            <td class="px-6 py-3 text-right">
              <form method="POST" action="/analytics/spend/<?= (int)$s->id ?>/delete" onsubmit="return confirm('Remove this spend entry?')">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"/>
                <button type="submit" class="text-slate-300 hover:text-rose-500"><span class="material-symbols-outlined text-lg">delete</span></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
const USERS = <?= json_encode($users->map(fn($u)=>['id'=>(int)$u->id,'name'=>$u->name.' ('.$u->role.')'])->values(), $JS) ?>;
const OVERRIDES = <?= json_encode((object)$cfg['overrides'], $JS) ?>;
const CENTRES = <?= json_encode(CentreService::ALL, $JS) ?>;

function ovrRowHtml(uid, centre){
  let opts = '<option value="0">— user —</option>' + USERS.map(u=>'<option value="'+u.id+'"'+(u.id==uid?' selected':'')+'>'+escapeHtml(u.name)+'</option>').join('');
  let cOpts = CENTRES.map(c=>'<option value="'+c+'"'+(c===centre?' selected':'')+'>'+c+'</option>').join('');
  return '<div class="grid grid-cols-[1fr_120px_40px] gap-2 items-center">'+
    '<select name="override_user[]" class="fld !mt-0">'+opts+'</select>'+
    '<select name="override_centre[]" class="fld !mt-0">'+cOpts+'</select>'+
    '<button type="button" onclick="this.parentElement.remove()" class="text-slate-300 hover:text-rose-500"><span class="material-symbols-outlined text-lg">close</span></button></div>';
}
function addOverride(uid, centre){ const w=document.getElementById('ovrRows'); const d=document.createElement('div'); d.innerHTML=ovrRowHtml(uid||0, centre||'DMR'); w.appendChild(d.firstChild); }
function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }
document.addEventListener('DOMContentLoaded', function(){
  const keys=Object.keys(OVERRIDES||{});
  if(keys.length){ keys.forEach(uid=>addOverride(parseInt(uid), OVERRIDES[uid])); }
});
</script>
</body>
</html>
