<?php
/**
 * Attachment chips for one message (thread view + approval queue).
 *
 * @var \Illuminate\Support\Collection $atts  CustomerEmailAttachment models
 */
if ($atts && count($atts)): ?>
<div class="mt-3 flex flex-wrap gap-2">
  <?php foreach ($atts as $a): ?>
  <a href="/emails/attachment/<?= (int) $a->id ?>" target="_blank" rel="noopener"
     class="inline-flex items-center gap-1.5 max-w-[280px] px-2.5 py-1.5 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-700 hover:border-primary hover:text-primary transition-colors">
    <span class="material-symbols-outlined text-sm text-slate-500"><?= $a->icon() ?></span>
    <span class="truncate font-semibold" title="<?= htmlspecialchars($a->original_name) ?>"><?= htmlspecialchars($a->original_name) ?></span>
    <span class="text-slate-400 flex-none"><?= $a->humanSize() ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
