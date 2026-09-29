<?php
/**
 * Admin — Fare Terms templates
 *
 * @var array       $templates     slug → template parts (defaults + overrides)
 * @var array       $cabinMap      cabin → default slug
 * @var array       $customised    slug → bool (differs from shipped default)
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */
use App\Services\FareTermsService;

$e = fn($s) => htmlspecialchars((string) $s);

$partMeta = [
    'fare_rules'    => ['Fare Rules', 'Shown in Ticket Conditions on the authorization form, receipt and e-ticket.', 3],
    'endorsements'  => ['Endorsements', 'The endorsement box, e.g. NON END/NON REF/NON RRT.', 1],
    'refund_clause' => ['Policy refund clause', 'Becomes clause 2 of the authorization policy and of the e-ticket policy.', 4],
    'checkbox'      => ['Customer checkbox (authorization form)', 'The refund sentence the customer must tick before signing. Stored on each form as evidence.', 2],
    'eticket_ack'   => ['E-ticket acknowledgement phrase', 'Completes "I acknowledge that this e-ticket is …".', 1],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Fare Terms — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'Manrope', 'sans-serif'] },
      colors: {
        primary: { DEFAULT: '#0f1e3c', 50: '#f0f4ff', 100: '#dde8ff', 500: '#1a3a6b', 600: '#0f1e3c' },
        gold: { DEFAULT: '#c9a84c', light: '#f5e6c0' }
      }
    }
  }
}
</script>
<style>
.field-label { display:block; font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.25rem; }
.field-input { width:100%; border:1px solid #e2e8f0; border-radius:0.5rem; padding:0.5rem 0.75rem; font-size:0.8125rem; background:#f8fafc; outline:none; transition:all .15s; }
.field-input:focus { box-shadow:0 0 0 2px rgba(15,30,60,0.2); border-color:rgba(15,30,60,0.4); background:#fff; }
.blank { background:#e0e7ff; color:#3730a3; border-radius:4px; padding:0 3px; font-weight:600; }
</style>
</head>
<body class="bg-[#f8f9fa] font-sans text-slate-900 antialiased min-h-screen">

<?php $activePage = 'fare_terms'; require __DIR__ . '/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <div class="mb-6">
    <h1 class="text-2xl font-extrabold text-primary tracking-tight flex items-center gap-2" style="font-family:Manrope">
      <span class="material-symbols-outlined text-2xl">gavel</span> Fare Terms
    </h1>
    <p class="text-sm text-slate-500 mt-0.5 max-w-3xl">
      The wording behind each fare type on the authorization form and e-ticket. Agents only pick a fare type and fill in the blanks.
      Changes apply to forms created from now on — forms already sent keep the wording the customer saw.
    </p>
  </div>

  <?php if ($flashSuccess): ?>
  <div class="mb-5 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-semibold text-emerald-800 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">check_circle</span> <?= $e($flashSuccess) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm font-semibold text-red-700 flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span> <?= $e($flashError) ?>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-6 max-w-6xl">
    <!-- Cabin defaults -->
    <form method="POST" action="/admin/fare-terms/cabin-map" class="xl:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token'] ?? '') ?>">
      <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50">
        <h2 class="font-bold text-slate-900 text-sm" style="font-family:Manrope">Default fare type by cabin</h2>
        <p class="text-xs text-slate-500">Pre-selected when the agent sets the cabin (highest cabin on the itinerary). The agent can always change it.</p>
      </div>
      <div class="p-5 grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach (FareTermsService::CABINS as $cabin): ?>
        <label class="block">
          <span class="field-label"><?= $e($cabin) ?></span>
          <select name="cabin[<?= $e($cabin) ?>]" class="field-input">
            <?php foreach (FareTermsService::TYPES as $slug): ?>
            <option value="<?= $e($slug) ?>" <?= ($cabinMap[$cabin] ?? '') === $slug ? 'selected' : '' ?>><?= $e($templates[$slug]['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="px-5 pb-5">
        <button class="inline-flex items-center gap-1.5 bg-primary text-white text-sm font-semibold rounded-lg px-4 py-2 hover:opacity-90">
          <span class="material-symbols-outlined text-base">save</span> Save cabin defaults
        </button>
      </div>
    </form>

    <!-- How blanks work -->
    <div class="bg-indigo-50/60 border border-indigo-200 rounded-xl p-5 text-xs text-slate-700 leading-relaxed">
      <div class="font-bold text-indigo-800 text-sm mb-2 flex items-center gap-1.5"><span class="material-symbols-outlined text-base">help</span> Blanks</div>
      <p class="mb-2">Write a blank in double braces and the agent gets an input box for it:</p>
      <p class="font-mono bg-white border border-indigo-100 rounded px-2 py-1 mb-1">{{cancel_fee}}</p>
      <p class="mb-2">Names ending <span class="font-mono">_fee</span>, <span class="font-mono">_amount</span>, <span class="font-mono">_hours</span>, <span class="font-mono">_days</span> get a number box.</p>
      <p class="mb-1">A dropdown — list options once, reuse by name:</p>
      <p class="font-mono bg-white border border-indigo-100 rounded px-2 py-1 mb-2">{{name_change:Not Allowed|Allowed}}</p>
      <p><span class="font-mono">{{currency}}</span> fills in from the form. The same blank used in several parts is asked once.</p>
    </div>
  </div>

  <!-- Templates -->
  <div class="space-y-5 max-w-6xl">
    <?php foreach (FareTermsService::TYPES as $slug): $tpl = $templates[$slug]; ?>
    <form id="tpl-<?= $e($slug) ?>" method="POST" action="/admin/fare-terms/template/<?= $e($slug) ?>"
          class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden scroll-mt-6" data-tpl-form>
      <input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token'] ?? '') ?>">
      <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between gap-3">
        <div>
          <h2 class="font-bold text-slate-900 text-sm flex items-center gap-2" style="font-family:Manrope">
            <?= $e($tpl['label']) ?>
            <?php if ($customised[$slug]): ?>
            <span class="text-[10px] font-bold uppercase tracking-wider bg-amber-100 text-amber-800 rounded-full px-2 py-0.5">Edited</span>
            <?php else: ?>
            <span class="text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-500 rounded-full px-2 py-0.5">Default</span>
            <?php endif; ?>
          </h2>
          <p class="text-xs text-slate-500"><?= $e($tpl['hint']) ?></p>
        </div>
        <div class="flex items-center gap-2">
          <?php if ($customised[$slug]): ?>
          <button type="submit" formaction="/admin/fare-terms/template/<?= $e($slug) ?>/reset"
                  onclick="return confirm('Reset <?= $e($tpl['label']) ?> to the default wording?')"
                  class="text-xs font-semibold text-slate-500 hover:text-rose-600 border border-slate-200 rounded-lg px-3 py-1.5">Reset to default</button>
          <?php endif; ?>
          <button class="inline-flex items-center gap-1.5 bg-primary text-white text-xs font-semibold rounded-lg px-3 py-1.5 hover:opacity-90">
            <span class="material-symbols-outlined text-sm">save</span> Save
          </button>
        </div>
      </div>
      <div class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="space-y-3">
          <?php foreach ($partMeta as $part => [$label, $help, $rows]): ?>
          <label class="block">
            <span class="field-label"><?= $e($label) ?></span>
            <textarea name="<?= $e($part) ?>" rows="<?= (int) $rows ?>" data-part="<?= $e($part) ?>"
                      class="field-input font-mono text-xs leading-relaxed"><?= $e($tpl[$part]) ?></textarea>
            <span class="block text-[10px] text-slate-400 mt-0.5"><?= $e($help) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div>
          <div class="field-label">Preview <span class="normal-case font-normal text-slate-400">(blanks highlighted with sample values)</span></div>
          <div class="border border-slate-200 rounded-lg p-4 bg-slate-50 text-xs text-slate-700 space-y-3" data-preview></div>
        </div>
      </div>
    </form>
    <?php endforeach; ?>
  </div>
</main>

<script>
(function () {
  var RE = /\{\{\s*([a-z0-9_]+)\s*(?::([^}]*))?\}\}/gi;
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function sample(key, opts, seen) {
    if (key === 'currency') return 'USD';
    if (opts) { seen[key] = opts.split('|')[0].trim(); }
    if (seen[key]) return seen[key];
    if (/_(hours)$/.test(key)) return '24';
    if (/_(days)$/.test(key)) return '7';
    if (/_(fee|amount)$/.test(key)) return '150.00';
    return key.replace(/_/g, ' ');
  }
  function fill(text, seen) {
    // First pass registers dropdown options wherever they are declared
    String(text).replace(RE, function (_, k, o) { if (o) seen[k.toLowerCase()] = o.split('|')[0].trim(); return ''; });
    return esc(text).replace(/\{\{\s*([a-z0-9_]+)\s*(?::([^}]*))?\}\}/gi, function (_, k, o) {
      return '<span class="blank">' + esc(sample(k.toLowerCase(), o, seen)) + '</span>';
    }).replace(/\n/g, '<br>');
  }
  function draw(form) {
    var get = function (p) { return form.querySelector('[data-part="' + p + '"]').value; };
    var seen = {};
    ['fare_rules','endorsements','refund_clause','checkbox','eticket_ack'].forEach(function (p) { fill(get(p), seen); });
    form.querySelector('[data-preview]').innerHTML =
      '<div><div class="font-bold text-slate-500 text-[10px] uppercase mb-1">Endorsements</div><div class="font-mono text-red-600">' + fill(get('endorsements'), seen) + '</div></div>' +
      '<div><div class="font-bold text-slate-500 text-[10px] uppercase mb-1">Fare Rules</div><div>' + fill(get('fare_rules'), seen) + '</div></div>' +
      '<div><div class="font-bold text-slate-500 text-[10px] uppercase mb-1">Policy clause 2</div><div>2. ' + fill(get('refund_clause'), seen) + '</div></div>' +
      '<div class="p-2 bg-amber-50 border border-amber-200 rounded"><div class="font-bold text-amber-700 text-[10px] uppercase mb-1">Customer ticks (authorization)</div>&#9745; ' + fill(get('checkbox'), seen) + '</div>' +
      '<div class="p-2 bg-slate-800 text-slate-100 rounded"><div class="font-bold text-amber-300 text-[10px] uppercase mb-1">E-ticket acknowledgement</div>I acknowledge that this e-ticket is <strong>' + fill(get('eticket_ack'), seen) + '</strong>.</div>';
  }
  document.querySelectorAll('[data-tpl-form]').forEach(function (form) {
    draw(form);
    form.addEventListener('input', function () { draw(form); });
  });
})();
</script>
</body>
</html>
