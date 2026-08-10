<?php
/**
 * Performance hold banner — shown only when a merchant hold is in force AND the
 * viewer is not an admin. $holdNotice is set by PerformanceController from
 * PerformanceHold::notice(); when there's no hold it is null and nothing renders.
 *
 * @var string|null $holdNotice
 */
if (empty($holdNotice)) {
    return;
}
?>
<div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3.5">
  <span class="material-symbols-outlined text-amber-600 text-xl shrink-0 mt-0.5">info</span>
  <div>
    <p class="text-sm font-semibold text-amber-900 leading-snug"><?= htmlspecialchars($holdNotice) ?></p>
    <p class="text-xs text-amber-700/90 mt-1">
      Your bookings and customer records are unaffected and remain recorded in full.
    </p>
  </div>
</div>
