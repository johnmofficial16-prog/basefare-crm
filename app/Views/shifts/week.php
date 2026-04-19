<?php
/**
 * @var string   $weekStart   Monday of the current week (YYYY-MM-DD)
 * @var array    $weekDates   Array of 7 date strings [Mon .. Sun]
 * @var \Illuminate\Support\Collection $agents   Active agents from DB
 * @var array    $grid        2D array [$agentId][$date] = ShiftSchedule model
 * @var \Illuminate\Database\Eloquent\Collection $templates  All shift templates
 */

use App\Models\ShiftTemplate;

// Pre-compute the previous and next week Monday dates for navigation
$prevWeek = (new DateTime($weekStart))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTime($weekStart))->modify('+7 days')->format('Y-m-d');
$weekEnd  = end($weekDates);

// Helper: Map template name to badge colors (Tailwind inline styles)
function shiftBadgeStyle(string $name): string {
    return match(strtolower(trim($name))) {
        'morning' => 'background:#E3F2FD;color:#1565C0',
        'evening' => 'background:#FFF8E1;color:#F57F17',
        'night'   => 'background:#E8EAF6;color:#283593',
        'split'   => 'background:#F3E5F5;color:#6A1B9A',
        default   => 'background:#E8F5E9;color:#2E7D32',
    };
}

// Helper: Get initials from a name
function initials(string $name): string {
    $parts = explode(' ', $name);
    return strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Shift Scheduling - Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "on-primary-fixed-variant":"#2a4386","outline":"#737784","on-secondary-container":"#4a6774","surface-bright":"#f8f9fa","inverse-primary":"#b3c5ff","on-secondary-fixed-variant":"#2e4b57","on-secondary-fixed":"#001f2a","surface-container-high":"#e7e8e9","on-tertiary-fixed":"#380d00","on-primary":"#ffffff","surface-container-low":"#f3f4f5","primary-fixed-dim":"#b3c5ff","outline-variant":"#c3c6d5","secondary-fixed-dim":"#adcbda","primary-fixed":"#dbe1ff","primary":"#163274","on-error-container":"#93000a","surface-container-lowest":"#ffffff","primary-container":"#314a8d","surface-container":"#edeeef","on-surface-variant":"#434653","background":"#f8f9fa","tertiary-container":"#8b2e01","on-tertiary-container":"#ffaa8a","error":"#ba1a1a","on-tertiary-fixed-variant":"#802900","on-tertiary":"#ffffff","on-primary-fixed":"#00174a","tertiary":"#651f00","tertiary-fixed-dim":"#ffb59a","surface-container-highest":"#e1e3e4","on-secondary":"#ffffff","on-primary-container":"#a8bcff","secondary-container":"#c6e4f4","inverse-on-surface":"#f0f1f2","tertiary-fixed":"#ffdbcf","on-background":"#191c1d","secondary":"#466270","inverse-surface":"#2e3132","secondary-fixed":"#c9e7f7","surface-variant":"#e1e3e4","on-surface":"#191c1d","on-error":"#ffffff","surface-dim":"#d9dadb","error-container":"#ffdad6","surface-tint":"#435b9f","surface":"#f8f9fa"
      },
      fontFamily: {"headline":["Manrope"],"body":["Inter"],"label":["Inter"]},
      borderRadius: {"DEFAULT":"0.25rem","lg":"0.5rem","xl":"0.75rem","full":"9999px"}
    }
  }
}
</script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.glass-effect{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
.no-scrollbar::-webkit-scrollbar{display:none}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased overflow-x-hidden">

<!-- Shared Admin Sidebar -->
<?php $activePage = 'shifts'; require __DIR__ . '/../partials/admin_sidebar.php'; ?>

<!-- Main Content -->
<main class="ml-60 pt-8 pb-20 px-10 min-h-screen bg-surface">

  <!-- Page Header -->
  <header class="flex flex-col md:flex-row justify-between items-end md:items-center mb-10 gap-6">
    <div class="space-y-1">
      <h1 class="text-4xl font-headline font-extrabold text-primary tracking-tight">Shift Scheduling</h1>
      <p class="text-on-surface-variant font-medium opacity-70">Manage your team's weekly schedules.</p>
    </div>
    <div class="flex items-center gap-4 bg-surface-container-lowest p-2 rounded-xl shadow-sm border border-outline-variant/15">
      <a href="/shifts/week?week=<?= $prevWeek ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-surface-container-low transition-colors rounded-lg text-sm font-semibold text-primary">
        <span class="material-symbols-outlined text-lg">chevron_left</span>Prev Week
      </a>
      <div class="px-6 py-2 bg-primary/5 rounded-lg border border-primary/10">
        <span class="font-headline font-bold text-primary">
          Week of <?= (new DateTime($weekStart))->format('M d') ?> – <?= (new DateTime($weekEnd))->format('M d, Y') ?>
        </span>
      </div>
      <a href="/shifts/week?week=<?= $nextWeek ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-surface-container-low transition-colors rounded-lg text-sm font-semibold text-primary">
        Next Week<span class="material-symbols-outlined text-lg">chevron_right</span>
      </a>
    </div>
    <div class="flex items-center gap-3">
      <button id="bulkAssignBtn" class="bg-gradient-to-r from-secondary to-secondary-container text-white px-6 py-3 rounded-lg font-headline font-bold text-sm shadow-xl shadow-secondary/20 hover:opacity-90 transition-all active:scale-95 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg">group_add</span> Bulk Assign
      </button>
      <button id="publishWeekBtn" class="bg-gradient-to-r from-primary to-primary-container text-white px-8 py-3 rounded-lg font-headline font-bold text-sm shadow-xl shadow-primary/20 hover:opacity-90 transition-all active:scale-95 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg">publish</span> Publish Week
      </button>
    </div>
  </header>

  <!-- Flash messages (success/error from POST) -->
  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="mb-6 p-4 bg-green-50 text-green-800 rounded-xl border border-green-200 font-semibold text-sm">
      <?= htmlspecialchars($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="mb-6 p-4 bg-red-50 text-red-800 rounded-xl border border-red-200 font-semibold text-sm">
      <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- Schedule Grid -->
  <div class="bg-surface-container-low rounded-2xl overflow-hidden shadow-[0_20px_40px_rgba(22,50,116,0.04)]">
    <div class="overflow-x-auto no-scrollbar">
      <table class="w-full border-collapse text-left">
        <thead>
          <tr class="bg-surface-container-lowest/80 backdrop-blur-sm">
            <th class="px-6 py-5 font-headline font-extrabold text-primary uppercase text-[11px] tracking-widest border-b border-outline-variant/10">Agent</th>
            <?php foreach ($weekDates as $date):
              $dt = new DateTime($date);
            ?>
            <th class="px-6 py-5 font-headline font-extrabold text-on-surface-variant uppercase text-[11px] tracking-widest border-b border-outline-variant/10">
              <?= $dt->format('D') ?> <span class="block font-medium opacity-50 tracking-normal capitalize text-xs"><?= $dt->format('M d') ?></span>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody class="divide-y divide-outline-variant/10">
          <?php if ($agents->isEmpty()): ?>
          <tr>
            <td colspan="8" class="px-6 py-10 text-center text-on-surface-variant font-medium opacity-60">
              No active agents found. Add agents via User Management.
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($agents as $agent): 
            $initials = initials($agent->name);
          ?>
          <tr class="hover:bg-surface-container-lowest/50 transition-colors" data-agent-id="<?= $agent->id ?>">
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-primary-fixed flex items-center justify-center text-primary font-bold text-xs"><?= $initials ?></div>
                <span class="font-headline font-bold text-on-surface"><?= htmlspecialchars($agent->name) ?></span>
              </div>
            </td>
            <?php foreach ($weekDates as $date): 
              $schedule = $grid[$agent->id][$date] ?? null;
            ?>
            <td class="px-6 py-4" 
                data-agent="<?= $agent->id ?>" 
                data-date="<?= $date ?>">
              <?php if ($schedule && $schedule->template): ?>
                <div class="relative group/cell" data-assigned="1" data-start="<?= $schedule->shift_start ?>" data-end="<?= $schedule->shift_end ?>" data-template="<?= $schedule->template_id ?>">
                  <span class="block px-3 py-2 rounded-lg text-[11px] font-bold leading-tight cursor-pointer"
                        style="<?= shiftBadgeStyle($schedule->template->name) ?>">
                    <?= htmlspecialchars($schedule->template->name) ?><br/>
                    <?= date('g:iA', strtotime($schedule->shift_start)) ?>–<?= date('g:iA', strtotime($schedule->shift_end)) ?>
                  </span>
                  <!-- Cell action buttons -->
                  <button onclick="deleteShift(<?= $agent->id ?>, '<?= $date ?>')" 
                          class="absolute -top-1 -right-1 w-4 h-4 bg-red-400 text-white rounded-full text-[8px] hidden group-hover/cell:flex items-center justify-center font-bold">✕</button>
                </div>
              <?php elseif ($schedule): ?>
                <div class="relative group/cell" data-assigned="1" data-start="<?= $schedule->shift_start ?>" data-end="<?= $schedule->shift_end ?>">
                  <span class="block px-3 py-2 rounded-lg text-[11px] font-bold leading-tight cursor-pointer" style="background:#E8F5E9;color:#2E7D32">
                    Custom<br/>
                    <?= date('g:iA', strtotime($schedule->shift_start)) ?>–<?= date('g:iA', strtotime($schedule->shift_end)) ?>
                  </span>
                  <button onclick="deleteShift(<?= $agent->id ?>, '<?= $date ?>')" 
                          class="absolute -top-1 -right-1 w-4 h-4 bg-red-400 text-white rounded-full text-[8px] hidden group-hover/cell:flex items-center justify-center font-bold">✕</button>
                </div>
              <?php else: ?>
                <button onclick="openAssignModal(<?= $agent->id ?>, '<?= $date ?>', '<?= htmlspecialchars($agent->name) ?>')"
                        class="w-full px-3 py-2 rounded-lg text-[11px] font-semibold text-slate-400 hover:bg-surface-container hover:text-primary transition-all text-left border-2 border-dashed border-outline-variant/30 hover:border-primary/30">
                  + Assign
                </button>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Shift Templates Legend -->
  <section class="mt-12 grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-2 bg-white/40 p-8 rounded-2xl border border-outline-variant/15 glass-effect">
      <h3 class="font-headline font-extrabold text-primary mb-6 flex items-center gap-2">
        <span class="material-symbols-outlined">auto_awesome</span> Shift Templates
      </h3>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php foreach ($templates as $t): ?>
        <div class="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant/10 shadow-sm flex flex-col items-center text-center gap-2 hover:translate-y-[-2px] transition-transform">
          <div class="w-3 h-3 rounded-full" style="background:<?= match(strtolower($t->name)) { 'morning' => '#1565C0', 'evening' => '#F57F17', 'night' => '#283593', 'split' => '#6A1B9A', default => '#2E7D32' } ?>"></div>
          <span class="text-[11px] font-extrabold uppercase tracking-wider text-on-surface-variant"><?= htmlspecialchars($t->name) ?></span>
          <span class="text-xs font-medium opacity-60">
            <?= date('g:iA', strtotime($t->start_time)) ?> – <?= date('g:iA', strtotime($t->end_time)) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bg-primary-container text-white p-8 rounded-2xl flex flex-col justify-between relative overflow-hidden group">
      <div class="relative z-10">
        <h3 class="font-headline font-extrabold text-lg mb-2">Scheduling Insights</h3>
        <?php
          $scheduledCount  = array_sum(array_map('count', $grid));
          $totalSlots      = $agents->count() * 7;
          $coverage        = $totalSlots > 0 ? round(($scheduledCount / $totalSlots) * 100) : 0;
        ?>
        <p class="text-on-primary-container text-sm leading-relaxed mb-6 opacity-90">
          <?= $scheduledCount ?> of <?= $totalSlots ?> agent-days scheduled this week (<?= $coverage ?>% coverage).
        </p>
      </div>
      <div class="flex items-center gap-4 relative z-10">
        <div class="flex-1 bg-white/20 h-2 rounded-full overflow-hidden">
          <div class="bg-white h-full rounded-full" style="width:<?= $coverage ?>%"></div>
        </div>
        <span class="font-bold text-lg"><?= $coverage ?>%</span>
      </div>
      <div class="absolute -right-4 -bottom-4 opacity-10 group-hover:scale-110 transition-transform">
        <span class="material-symbols-outlined text-[120px]">travel_explore</span>
      </div>
    </div>
  </section>
</main>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- BULK ASSIGN MODAL                                                    -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="bulkAssignModal" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">

    <!-- Header -->
    <div class="flex items-center justify-between px-8 pt-8 pb-4 border-b border-outline-variant/15">
      <div>
        <h3 class="font-headline font-extrabold text-2xl text-primary flex items-center gap-2">
          <span class="material-symbols-outlined">group_add</span> Bulk Assign Shift
        </h3>
        <p class="text-sm text-on-surface-variant mt-0.5 opacity-70">Assign a shift to multiple people across multiple days in one click.</p>
      </div>
      <button onclick="closeBulkModal()" class="w-9 h-9 rounded-full bg-surface-container flex items-center justify-center hover:bg-surface-container-high transition-colors">
        <span class="material-symbols-outlined text-on-surface-variant">close</span>
      </button>
    </div>

    <!-- Scrollable body -->
    <div class="overflow-y-auto flex-1 px-8 py-6 space-y-7">

      <!-- STEP 1: Shift Template -->
      <div>
        <p class="text-[11px] font-extrabold uppercase tracking-widest text-primary mb-3 flex items-center gap-1.5">
          <span class="w-5 h-5 rounded-full bg-primary text-white text-[10px] font-black flex items-center justify-center">1</span>
          Select Shift
        </p>
        <select id="bulkTemplate" class="w-full rounded-xl bg-surface-container-low border border-outline-variant/20 focus:ring-2 focus:ring-primary/20 text-sm font-semibold py-3 px-4">
          <option value="">– Select a shift template –</option>
          <?php foreach ($templates as $t): ?>
          <option value="<?= $t->id ?>" data-start="<?= $t->start_time ?>" data-end="<?= $t->end_time ?>">
            <?= htmlspecialchars($t->name) ?> (<?= date('g:iA', strtotime($t->start_time)) ?>–<?= date('g:iA', strtotime($t->end_time)) ?>)
          </option>
          <?php endforeach; ?>
          <option value="__custom__">⚙ Custom times…</option>
        </select>
        <!-- Custom time row (hidden by default) -->
        <div id="bulkCustomTimes" class="hidden mt-3 grid grid-cols-2 gap-4">
          <div>
            <label class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant mb-1 block">Start Time</label>
            <input type="time" id="bulkStart" class="w-full rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-2 focus:ring-primary/20 text-sm py-2.5 px-3"/>
          </div>
          <div>
            <label class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant mb-1 block">End Time</label>
            <input type="time" id="bulkEnd" class="w-full rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-2 focus:ring-primary/20 text-sm py-2.5 px-3"/>
          </div>
        </div>
      </div>

      <!-- STEP 2: People -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <p class="text-[11px] font-extrabold uppercase tracking-widest text-primary flex items-center gap-1.5">
            <span class="w-5 h-5 rounded-full bg-primary text-white text-[10px] font-black flex items-center justify-center">2</span>
            Select People
          </p>
          <div class="flex gap-2">
            <button type="button" onclick="bulkSelectAllPeople(true)" class="text-[11px] font-bold text-primary hover:underline">Select All</button>
            <span class="text-outline-variant">·</span>
            <button type="button" onclick="bulkSelectAllPeople(false)" class="text-[11px] font-bold text-on-surface-variant hover:underline">Clear</button>
          </div>
        </div>
        <div id="bulkPeopleList" class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-44 overflow-y-auto pr-1">
          <?php foreach ($agents as $agent):
            $initBulk = initials($agent->name);
          ?>
          <label class="flex items-center gap-2 px-3 py-2.5 rounded-xl border border-outline-variant/20 bg-surface-container-low hover:bg-primary/5 hover:border-primary/30 cursor-pointer transition-all has-[:checked]:bg-primary/8 has-[:checked]:border-primary/40 group">
            <input type="checkbox" name="bulk_agents" value="<?= $agent->id ?>" class="bulk-agent-cb rounded accent-primary w-4 h-4 flex-shrink-0"/>
            <div class="w-6 h-6 rounded-full bg-primary-fixed flex items-center justify-center text-primary font-black text-[9px] flex-shrink-0"><?= $initBulk ?></div>
            <span class="text-xs font-semibold text-on-surface leading-tight truncate"><?= htmlspecialchars($agent->name) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <p id="bulkPeopleCount" class="text-[11px] text-on-surface-variant mt-2 opacity-60">0 people selected</p>
      </div>

      <!-- STEP 3: Days -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <p class="text-[11px] font-extrabold uppercase tracking-widest text-primary flex items-center gap-1.5">
            <span class="w-5 h-5 rounded-full bg-primary text-white text-[10px] font-black flex items-center justify-center">3</span>
            Select Days
          </p>
          <div class="flex gap-2">
            <button type="button" onclick="bulkSelectAllDays(true)" class="text-[11px] font-bold text-primary hover:underline">All Week</button>
            <span class="text-outline-variant">·</span>
            <button type="button" onclick="bulkSelectAllDays(false)" class="text-[11px] font-bold text-on-surface-variant hover:underline">Clear</button>
          </div>
        </div>
        <div class="grid grid-cols-7 gap-2">
          <?php foreach ($weekDates as $date):
            $dtBulk = new DateTime($date);
          ?>
          <label class="flex flex-col items-center gap-1.5 px-1 py-2.5 rounded-xl border border-outline-variant/20 bg-surface-container-low hover:bg-primary/5 hover:border-primary/30 cursor-pointer transition-all has-[:checked]:bg-primary/8 has-[:checked]:border-primary/40">
            <input type="checkbox" name="bulk_days" value="<?= $date ?>" class="bulk-day-cb rounded accent-primary w-4 h-4"/>
            <span class="text-[10px] font-extrabold uppercase text-on-surface-variant tracking-wider"><?= $dtBulk->format('D') ?></span>
            <span class="text-[9px] text-on-surface-variant opacity-50"><?= $dtBulk->format('M d') ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <p id="bulkDayCount" class="text-[11px] text-on-surface-variant mt-2 opacity-60">0 days selected</p>
      </div>

      <!-- Summary + Error -->
      <div id="bulkSummaryBox" class="hidden bg-primary/5 border border-primary/15 rounded-xl px-5 py-4">
        <p class="text-sm font-bold text-primary" id="bulkSummaryText"></p>
      </div>
      <div id="bulkError" class="hidden bg-red-50 border border-red-200 rounded-xl px-5 py-4 text-sm text-red-700 font-semibold"></div>

    </div>

    <!-- Footer -->
    <div class="px-8 pb-8 pt-4 border-t border-outline-variant/15 flex gap-3">
      <button id="bulkApplyBtn" class="flex-1 bg-gradient-to-r from-primary to-primary-container text-white py-3.5 rounded-xl font-headline font-bold text-sm hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-base">bolt</span>
        Apply to Selected
      </button>
      <button onclick="closeBulkModal()" class="flex-1 bg-surface-container text-on-surface-variant py-3.5 rounded-xl font-bold text-sm hover:bg-surface-container-high transition-all">
        Cancel
      </button>
    </div>
  </div>
</div>

<!-- Assign Shift Modal -->
<div id="assignModal" class="hidden fixed inset-0 bg-black/30 backdrop-blur-sm z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">
    <h3 class="font-headline font-extrabold text-xl text-primary mb-1">Assign Shift</h3>
    <p id="modalSubtitle" class="text-sm text-on-surface-variant mb-6"></p>
    <form id="assignForm" class="space-y-4">
      <input type="hidden" id="modalAgentId" name="agent_id"/>
      <input type="hidden" id="modalDate" name="shift_date"/>
      <div>
        <label class="text-xs font-bold uppercase tracking-widest text-on-surface-variant mb-1 block">Shift Template</label>
        <select id="modalTemplate" name="template_id" class="w-full rounded-lg bg-surface-container-low border-0 focus:ring-2 focus:ring-primary/20 text-sm font-medium">
          <option value="">– Custom times –</option>
          <?php foreach ($templates as $t): ?>
          <option value="<?= $t->id ?>" data-start="<?= $t->start_time ?>" data-end="<?= $t->end_time ?>">
            <?= htmlspecialchars($t->name) ?> (<?= date('g:iA', strtotime($t->start_time)) ?>–<?= date('g:iA', strtotime($t->end_time)) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="text-xs font-bold uppercase tracking-widest text-on-surface-variant mb-1 block">Start Time</label>
          <input type="time" id="modalStart" name="shift_start" class="w-full rounded-lg bg-surface-container-low border-0 focus:ring-2 focus:ring-primary/20 text-sm" required/>
        </div>
        <div>
          <label class="text-xs font-bold uppercase tracking-widest text-on-surface-variant mb-1 block">End Time</label>
          <input type="time" id="modalEnd" name="shift_end" class="w-full rounded-lg bg-surface-container-low border-0 focus:ring-2 focus:ring-primary/20 text-sm" required/>
        </div>
      </div>
      <div id="modalError" class="hidden text-sm text-red-700 bg-red-50 p-3 rounded-lg"></div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-gradient-to-r from-primary to-primary-container text-white py-3 rounded-lg font-bold text-sm hover:opacity-90 active:scale-95 transition-all">Save Shift</button>
        <button type="button" onclick="closeModal()" class="flex-1 bg-surface-container text-on-surface-variant py-3 rounded-lg font-bold text-sm hover:bg-surface-container-high transition-all">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
// ─── BULK ASSIGN LOGIC ────────────────────────────────────────────────────────

const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?? '' ?>';

document.getElementById('bulkAssignBtn').addEventListener('click', () => {
  document.getElementById('bulkAssignModal').classList.remove('hidden');
  updateBulkSummary();
});

function closeBulkModal() {
  document.getElementById('bulkAssignModal').classList.add('hidden');
  document.getElementById('bulkError').classList.add('hidden');
  document.getElementById('bulkSummaryBox').classList.add('hidden');
}

// Show/hide custom time fields based on template selection
document.getElementById('bulkTemplate').addEventListener('change', function() {
  const isCustom = this.value === '__custom__';
  document.getElementById('bulkCustomTimes').classList.toggle('hidden', !isCustom);
  updateBulkSummary();
});

function bulkSelectAllPeople(checked) {
  document.querySelectorAll('.bulk-agent-cb').forEach(cb => cb.checked = checked);
  updateBulkSummary();
}

function bulkSelectAllDays(checked) {
  document.querySelectorAll('.bulk-day-cb').forEach(cb => cb.checked = checked);
  updateBulkSummary();
}

function updateBulkSummary() {
  const people  = document.querySelectorAll('.bulk-agent-cb:checked').length;
  const days    = document.querySelectorAll('.bulk-day-cb:checked').length;
  const total   = people * days;
  document.getElementById('bulkPeopleCount').textContent = people + ' people selected';
  document.getElementById('bulkDayCount').textContent    = days + ' days selected';

  const box = document.getElementById('bulkSummaryBox');
  if (people > 0 && days > 0) {
    box.classList.remove('hidden');
    document.getElementById('bulkSummaryText').textContent =
      `✅ This will create/overwrite ${total} shift assignment${total !== 1 ? 's' : ''} (${people} people × ${days} days).`;
  } else {
    box.classList.add('hidden');
  }
}

// Re-compute summary on any checkbox change
document.querySelectorAll('.bulk-agent-cb, .bulk-day-cb').forEach(cb => {
  cb.addEventListener('change', updateBulkSummary);
});

document.getElementById('bulkApplyBtn').addEventListener('click', async function() {
  const err = document.getElementById('bulkError');
  err.classList.add('hidden');

  // Gather template / times
  const templateSel = document.getElementById('bulkTemplate');
  const templateId  = (templateSel.value && templateSel.value !== '__custom__') ? parseInt(templateSel.value) : null;

  let shiftStart, shiftEnd;
  if (templateSel.value === '__custom__') {
    shiftStart = document.getElementById('bulkStart').value;
    shiftEnd   = document.getElementById('bulkEnd').value;
  } else if (templateId) {
    const opt  = templateSel.options[templateSel.selectedIndex];
    shiftStart = opt.dataset.start;
    shiftEnd   = opt.dataset.end;
  }

  // Validate
  if (!shiftStart || !shiftEnd) {
    err.textContent = 'Please select a shift template or enter custom times.';
    err.classList.remove('hidden'); return;
  }

  const selectedAgents = [...document.querySelectorAll('.bulk-agent-cb:checked')].map(cb => parseInt(cb.value));
  const selectedDays   = [...document.querySelectorAll('.bulk-day-cb:checked')].map(cb => cb.value);

  if (selectedAgents.length === 0) {
    err.textContent = 'Please select at least one person.';
    err.classList.remove('hidden'); return;
  }
  if (selectedDays.length === 0) {
    err.textContent = 'Please select at least one day.';
    err.classList.remove('hidden'); return;
  }

  // Build entries cartesian product: every person × every day
  const entries = [];
  selectedAgents.forEach(agentId => {
    selectedDays.forEach(date => {
      entries.push({
        agent_id:    agentId,
        shift_date:  date,
        shift_start: shiftStart.substring(0, 5),
        shift_end:   shiftEnd.substring(0, 5),
        template_id: templateId
      });
    });
  });

  // Optimistic UI
  const btn = document.getElementById('bulkApplyBtn');
  const ogHTML = btn.innerHTML;
  btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">sync</span> Applying…';
  btn.disabled = true;

  try {
    const r = await fetch('/shifts/week/publish', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ week_start: '<?= $weekDates[0] ?? '' ?>', entries })
    });
    const data = await r.json();
    if (data.success) {
      closeBulkModal();
      window.location.reload();
    } else {
      err.textContent = data.message || 'Could not apply shifts. Please try again.';
      err.classList.remove('hidden');
    }
  } catch(e) {
    err.textContent = 'Network error. Please try again.';
    err.classList.remove('hidden');
  } finally {
    btn.innerHTML = ogHTML;
    btn.disabled = false;
  }
});

// ─── END BULK ASSIGN LOGIC ────────────────────────────────────────────────────

// Pre-fill times from template selector
document.getElementById('modalTemplate').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  if (opt.dataset.start) {
    document.getElementById('modalStart').value = opt.dataset.start.substring(0, 5);
    document.getElementById('modalEnd').value   = opt.dataset.end.substring(0, 5);
  }
});

function openAssignModal(agentId, date, agentName) {
  document.getElementById('modalAgentId').value = agentId;
  document.getElementById('modalDate').value = date;
  document.getElementById('modalSubtitle').textContent = agentName + ' · ' + date;
  document.getElementById('assignModal').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('assignModal').classList.add('hidden');
  document.getElementById('modalError').classList.add('hidden');
  document.getElementById('assignForm').reset();
}

document.getElementById('assignForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = this.querySelector('[type=submit]');
  btn.textContent = 'Saving…';
  btn.disabled = true;

  const payload = {
    agent_id:    parseInt(document.getElementById('modalAgentId').value),
    shift_date:  document.getElementById('modalDate').value,
    shift_start: document.getElementById('modalStart').value,
    shift_end:   document.getElementById('modalEnd').value,
    template_id: document.getElementById('modalTemplate').value || null,
  };

  try {
    const r = await fetch('/shifts/cell/update', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
      },
      body: JSON.stringify(payload)
    });
    const data = await r.json();
    if (data.success) {
      window.location.reload(); // Reload to reflect the new DB state
    } else {
      const err = document.getElementById('modalError');
      err.textContent = data.message || 'Could not save shift.';
      err.classList.remove('hidden');
    }
  } catch(err) {
    document.getElementById('modalError').textContent = 'Network error. Please try again.';
    document.getElementById('modalError').classList.remove('hidden');
  } finally {
    btn.textContent = 'Save Shift';
    btn.disabled = false;
  }
});

async function deleteShift(agentId, date) {
  if (!confirm('Remove this shift assignment?')) return;
  const r = await fetch('/shifts/cell/delete', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
    },
    body: JSON.stringify({
      agent_id: agentId,
      shift_date: date
    })
  });
  const data = await r.json();
  if (data.success) window.location.reload();
  else alert(data.message || 'Could not delete shift.');
}

// Publish Week — collect the entire current grid and POST to /shifts/week/publish
document.getElementById('publishWeekBtn').addEventListener('click', async function() {
  const rows  = document.querySelectorAll('tbody tr[data-agent-id]');
  const entries = [];

  rows.forEach(row => {
    const agentId = parseInt(row.dataset.agentId);
    row.querySelectorAll('td[data-date]').forEach(cell => {
      const date = cell.dataset.date;
      const dataDiv = cell.querySelector('.group\\/cell[data-assigned="1"]');
      
      if (dataDiv) {
        entries.push({
          agent_id: agentId,
          shift_date: date,
          shift_start: dataDiv.dataset.start,
          shift_end: dataDiv.dataset.end,
          template_id: dataDiv.dataset.template || null
        });
      }
    });
  });

  if (entries.length === 0) {
    alert('No shifts to publish.');
    return;
  }

  const btn = document.getElementById('publishWeekBtn');
  const ogText = btn.innerHTML;
  btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">sync</span> Publishing...';
  btn.disabled = true;

  try {
    const r = await fetch('/shifts/week/publish', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
      },
      body: JSON.stringify({
        week_start: '<?= $weekDates[0] ?? '' ?>',
        entries: entries
      })
    });
    const data = await r.json();
    if (data.success) {
      alert('Week published successfully! The schedule is now live and enforced.');
      window.location.reload();
    } else {
      alert(data.message || 'Error publishing week.');
    }
  } catch (err) {
    alert('Network error during publish.');
  } finally {
    btn.innerHTML = ogText;
    btn.disabled = false;
  }
});
</script>

<footer class="fixed bottom-0 right-0 left-60 pb-6 text-slate-400 font-label text-xs uppercase tracking-widest flex justify-center pointer-events-none">
  <span>© 2026 Base Fare CRM. All rights reserved.</span>
</footer>
</body>
</html>
