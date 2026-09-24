<?php
/**
 * Quick log — a "Log a call" button in the agent sidebar that opens the call-log
 * form in a modal on any page (Alt+L). On /call-logs itself, Alt+L just focuses
 * the inline form.
 *
 * Fail-soft: if anything here throws (e.g. the call_logs migration hasn't run
 * yet) the button is simply not rendered — it must never break the page.
 */
$_clq = null;
try {
    $_clqSvc = new \App\Services\CallLogService();
    $_clqUid = (int) ($_SESSION['user_id'] ?? 0);
    $_clqToday = $_clqSvc->shiftDateFor();
    $_clq = [
        'count'   => \App\Models\CallLog::where('agent_id', $_clqUid)->where('shift_date', $_clqToday)->count(),
        'onPage'  => ($activePage ?? '') === 'call_logs',
    ];
    if (!$_clq['onPage']) {
        $clFavAirlines = $_clqSvc->favouriteAirlines($_clqUid);
        $clBookings = \App\Models\Transaction::where('agent_id', $_clqUid)
            ->where('created_at', '>=', \Carbon\Carbon::now()->subHours(36)->format('Y-m-d H:i:s'))
            ->orderByDesc('created_at')->limit(25)
            ->get(['id', 'pnr', 'customer_name', 'customer_phone', 'airline'])
            ->map(fn ($t) => [
                'id'     => $t->id,
                'label'  => ($t->pnr ?: '#' . $t->id) . ' — ' . $t->customer_name . ($t->airline ? ' · ' . $t->airline : ''),
                'digits' => \App\Models\CallLog::normalisePhone((string) $t->customer_phone),
            ])->toArray();
    }
} catch (\Throwable $e) {
    error_log('[call_log_quick] disabled: ' . $e->getMessage());
    $_clq = null;
}
if ($_clq === null) {
    return;
}
?>
<div class="px-4 pt-4">
  <?php if ($_clq['onPage']): ?>
  <button type="button" onclick="var p=document.querySelector('[name=phone]'); if(p){p.focus(); p.scrollIntoView({block:'center'});}"
  <?php else: ?>
  <button type="button" id="clq-open"
  <?php endif; ?>
    class="w-full flex items-center justify-between gap-2 rounded-xl bg-primary px-4 py-3 text-white shadow-md shadow-primary/20 hover:bg-primary-container transition-colors">
    <span class="flex items-center gap-2 text-sm font-bold"><span class="material-symbols-outlined text-[20px]">add_call</span>Log a call</span>
    <span class="flex items-center gap-1">
      <span id="clq-count" class="min-w-[26px] rounded-full bg-white/20 px-2 py-0.5 text-center text-xs font-extrabold" title="Calls logged this shift"><?= (int) $_clq['count'] ?></span>
    </span>
  </button>
  <div class="mt-1 text-center text-[10px] font-semibold text-slate-400">Alt + L from anywhere</div>
</div>

<?php if (!$_clq['onPage']): ?>
<link href="<?= \App\Services\Asset::url('assets/css/call-log.css') ?>" rel="stylesheet"/>
<div id="clq-modal" class="cl-modal hidden" role="dialog" aria-modal="true" aria-labelledby="clq-title">
  <div class="cl-modal-card">
    <div class="flex items-center justify-between mb-4">
      <h2 id="clq-title" class="font-headline font-extrabold text-lg text-primary"><span class="material-symbols-outlined align-[-5px]">add_call</span> Log a call</h2>
      <div class="flex items-center gap-3">
        <a href="/call-logs" class="text-xs font-semibold text-slate-500 hover:text-primary">Open call logs →</a>
        <button type="button" id="clq-close" class="text-slate-400 hover:text-slate-700" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
      </div>
    </div>
    <?php require __DIR__ . '/../call_logs/_form.php'; ?>
  </div>
</div>
<script src="<?= \App\Services\Asset::url('assets/js/call-log.js') ?>"></script>
<script>
(function () {
  var modal = document.getElementById('clq-modal');
  if (!modal || !window.CallLog) return;
  // Lift out of the sidebar's stacking context so the backdrop covers everything.
  document.body.appendChild(modal);
  var api = CallLog.mount(modal.querySelector('[data-cl-root]'), {
    csrf: <?= json_encode($_SESSION['csrf_token'] ?? '') ?>,
    bookings: <?= json_encode($clBookings ?? []) ?>,
    onSaved: function (log, stats) {
      document.getElementById('clq-count').textContent = stats.calls;
      close();
    }
  });
  function open() { modal.classList.remove('hidden'); setTimeout(api.focus, 30); }
  function close() { modal.classList.add('hidden'); }
  document.getElementById('clq-open').addEventListener('click', open);
  document.getElementById('clq-close').addEventListener('click', close);
  modal.addEventListener('mousedown', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
    if (e.altKey && (e.key === 'l' || e.key === 'L')) { e.preventDefault(); open(); }
  });
})();
</script>
<?php else: ?>
<script>
document.addEventListener('keydown', function (e) {
  if (e.altKey && (e.key === 'l' || e.key === 'L')) {
    var p = document.querySelector('[data-cl-root] [name=phone]');
    if (p) { e.preventDefault(); p.focus(); p.scrollIntoView({ block: 'center' }); }
  }
});
</script>
<?php endif; ?>
