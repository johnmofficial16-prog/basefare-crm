<?php
/**
 * Simple view — Team: everyone in the CRM, grouped by centre, with the
 * everyday people actions (add, reset password, turn login off/on).
 * @var array $people, $leaders
 * @var string $currency, $monthLabel
 */
$pageTitle = 'Team';
require __DIR__ . '/_head.php';

$groups = [];
foreach ($people as $p) {
    $groups[$p['centre'] ?: 'OTHER'][] = $p;
}
$groupOrder = ['DMR', 'MOH', 'JSR', 'OTHER'];
$groupLabel = \App\Services\CentreService::LABELS + ['OTHER' => 'Not in a centre'];

$nowLabel = [
    'in'       => ['Working now', 'bg-emerald-500', 'text-emerald-700'],
    'on_break' => ['On a break', 'bg-amber-400', 'text-amber-700'],
    'finished' => ['Finished for today', 'bg-slate-400', 'text-slate-500'],
    'asking'   => ['Waiting to clock in', 'bg-amber-400', 'text-amber-700'],
    'not_in'   => ['Not in today', 'bg-slate-200', 'text-slate-400'],
];
$working = count(array_filter($people, fn($p) => in_array($p['now'], ['in', 'on_break'], true)));
$off     = count(array_filter($people, fn($p) => !$p['active']));
$avatarBg = ['#163274', '#0f766e', '#7c3aed', '#c2410c', '#2563eb', '#be185d', '#15803d'];
?>

<header class="mb-6 flex items-end justify-between gap-6">
  <div>
    <h1 class="font-headline text-[30px] font-extrabold tracking-tight">Team</h1>
    <p class="text-[15px] text-slate-500 mt-1">Everyone who uses the CRM. <?= count($people) ?> people · <?= $working ?> at work now<?= $off ? ' · ' . $off . ' with login turned off' : '' ?>.</p>
  </div>
  <div class="flex items-center gap-3">
    <a href="/shifts/week" class="inline-flex items-center gap-2 px-4 py-3 rounded-xl border border-slate-300 bg-white text-[15px] font-semibold text-slate-700 hover:bg-slate-50 no-underline">
      <span class="material-symbols-outlined text-[20px]">calendar_month</span> Shift schedule
    </a>
    <button type="button" id="btn-add" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-primary text-white text-[15px] font-bold hover:bg-[#0f2557]">
      <span class="material-symbols-outlined text-[20px]">person_add</span> Add a person
    </button>
  </div>
</header>

<div class="mb-6 relative max-w-md">
  <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
  <input id="search" type="search" placeholder="Find someone by name or email" aria-label="Find someone"
    class="w-full pl-11 pr-4 py-3 rounded-xl border border-slate-300 bg-white text-[15px] focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
</div>

<?php foreach ($groupOrder as $g): if (empty($groups[$g])) continue; ?>
<section class="mb-8" data-group>
  <h2 class="font-headline text-[18px] font-bold mb-3 text-slate-700"><?= sv_e($groupLabel[$g] ?? $g) ?> <span class="text-slate-400 font-semibold text-[15px]">· <?= count($groups[$g]) ?></span></h2>
  <div class="grid grid-cols-3 gap-4">
    <?php foreach ($groups[$g] as $p): [$nl, $dot, $txt] = $p['active'] ? $nowLabel[$p['now']] : ['Login turned off', 'bg-rose-500', 'text-rose-700']; ?>
    <article class="card p-5 flex flex-col <?= $p['active'] ? '' : 'opacity-75' ?>" data-person data-search="<?= sv_e(mb_strtolower($p['name'] . ' ' . $p['email'])) ?>">
      <div class="flex items-start gap-3">
        <span class="w-12 h-12 rounded-full text-[#fff] font-bold text-[15px] flex items-center justify-center flex-none"
              style="background:<?= $avatarBg[$p['id'] % count($avatarBg)] ?>"><?= sv_e(sv_initials($p['name'])) ?></span>
        <div class="min-w-0 flex-1">
          <h3 class="text-[16px] font-bold truncate"><?= sv_e($p['name']) ?></h3>
          <p class="text-[14px] text-slate-500"><?= sv_e($p['job']) ?><?= $p['reports'] ? ' · reports to ' . sv_e($p['reports']) : '' ?></p>
          <p class="text-[13px] font-semibold mt-1 flex items-center gap-1.5 <?= $txt ?>" data-state><span class="w-2 h-2 rounded-full <?= $dot ?>"></span><?= $nl ?></p>
        </div>
      </div>
      <div class="mt-4 grid grid-cols-2 gap-2 text-center">
        <div class="rounded-lg bg-slate-50 py-2">
          <div class="text-[16px] font-extrabold"><?= (int) $p['bookings'] ?></div>
          <div class="text-[12px] text-slate-500">bookings in <?= sv_e($monthLabel) ?></div>
        </div>
        <div class="rounded-lg bg-slate-50 py-2">
          <div class="text-[16px] font-extrabold"><?= sv_e(sv_money((float) $p['profit'], $currency)) ?></div>
          <div class="text-[12px] text-slate-500">profit in <?= sv_e($monthLabel) ?></div>
        </div>
      </div>
      <div class="mt-4 pt-4 border-t border-slate-100 flex flex-wrap gap-2 text-[13px] font-semibold">
        <a href="/performance/agent/<?= $p['id'] ?>" class="px-3 py-2 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 no-underline">Performance</a>
        <button type="button" class="px-3 py-2 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200" data-act="reset" data-id="<?= $p['id'] ?>" data-name="<?= sv_e($p['name']) ?>" data-email="<?= sv_e($p['email']) ?>">New password</button>
        <button type="button" class="px-3 py-2 rounded-lg <?= $p['active'] ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?>"
          data-act="toggle" data-id="<?= $p['id'] ?>" data-name="<?= sv_e($p['name']) ?>" data-active="<?= $p['active'] ? '1' : '0' ?>"><?= $p['active'] ? 'Turn off login' : 'Turn login back on' ?></button>
        <a href="/users/<?= $p['id'] ?>/edit" class="px-3 py-2 rounded-lg text-slate-500 hover:text-primary no-underline" title="Change name, email, job or who they report to">Edit</a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<p id="no-match" class="hidden text-[15px] text-slate-500">Nobody matches that search.</p>

<!-- Add a person -->
<div id="add-modal" class="hidden fixed inset-0 z-50 bg-slate-900/45 flex items-center justify-center p-6" role="dialog" aria-modal="true" aria-labelledby="add-title">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-[560px] max-h-[92vh] overflow-y-auto">
    <form id="add-form" class="p-7" novalidate>
      <div class="flex items-start justify-between">
        <div>
          <h2 id="add-title" class="font-headline text-[22px] font-extrabold">Add a person</h2>
          <p class="text-[14px] text-slate-500 mt-1">Creates a login so they can use the CRM.</p>
        </div>
        <button type="button" data-close class="text-slate-400 hover:text-slate-700" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
      </div>

      <label class="block text-[14px] font-semibold mt-6 mb-1.5" for="f-name">Full name</label>
      <input id="f-name" name="name" type="text" autocomplete="off" class="w-full px-3.5 py-3 rounded-xl border border-slate-300 text-[15px] focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary" placeholder="e.g. Priya Sharma">

      <label class="block text-[14px] font-semibold mt-4 mb-1.5" for="f-email">Work email</label>
      <input id="f-email" name="email" type="email" autocomplete="off" class="w-full px-3.5 py-3 rounded-xl border border-slate-300 text-[15px] focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary" placeholder="name@base-fare.com">
      <p class="text-[13px] text-slate-500 mt-1">They sign in with this email.</p>

      <p class="block text-[14px] font-semibold mt-5 mb-2">What will they do?</p>
      <div class="grid grid-cols-2 gap-2" id="f-roles">
        <?php foreach ([
            ['agent', 'Travel agent', 'Makes bookings and talks to customers'],
            ['supervisor', 'Team leader', 'Looks after a small group of agents'],
            ['manager', 'Manager', 'Runs a centre and its team'],
            ['csa', 'Customer support', 'Answers customer questions; doesn\'t make bookings'],
        ] as [$val, $lbl, $sub]): ?>
        <label class="role-opt relative flex flex-col gap-0.5 p-3 rounded-xl border-2 border-slate-200 cursor-pointer hover:border-slate-300">
          <input type="radio" name="role" value="<?= $val ?>" class="sr-only" <?= $val === 'agent' ? 'checked' : '' ?>>
          <span class="text-[15px] font-bold"><?= $lbl ?></span>
          <span class="text-[13px] text-slate-500 leading-snug"><?= $sub ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <div id="f-reports-wrap">
        <label class="block text-[14px] font-semibold mt-5 mb-1.5" for="f-reports">Who do they report to?</label>
        <select id="f-reports" name="reports_to_id" class="w-full px-3.5 py-3 rounded-xl border border-slate-300 text-[15px] bg-white focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
          <option value="">Choose a person…</option>
          <?php foreach ($leaders as $l): ?>
          <option value="<?= $l['id'] ?>" data-role="<?= $l['role'] ?>"><?= sv_e($l['name']) ?> — <?= sv_e($l['job']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <label class="block text-[14px] font-semibold mt-5 mb-1.5" for="f-pass">First password</label>
      <div class="flex gap-2">
        <input id="f-pass" name="password" type="text" autocomplete="off" class="flex-1 px-3.5 py-3 rounded-xl border border-slate-300 text-[15px] font-mono focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
        <button type="button" id="f-gen" class="px-4 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50">New one</button>
      </div>
      <p class="text-[13px] text-slate-500 mt-1">Made up for you. You'll see it again at the end to pass on.</p>

      <p id="f-error" class="hidden mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-[14px]"></p>

      <div class="mt-7 flex justify-end gap-3">
        <button type="button" data-close class="px-5 py-3 rounded-xl bg-slate-100 text-slate-700 text-[15px] font-bold hover:bg-slate-200">Cancel</button>
        <button type="submit" id="f-submit" class="px-6 py-3 rounded-xl bg-primary text-white text-[15px] font-bold hover:bg-[#0f2557]">Continue</button>
      </div>
    </form>

    <div id="add-done" class="hidden p-7 text-center">
      <span class="material-symbols-outlined text-[52px] text-emerald-500">check_circle</span>
      <h2 class="font-headline text-[22px] font-extrabold mt-1" id="done-title">Login created</h2>
      <p class="text-[15px] text-slate-500 mt-1">Send them these details so they can sign in:</p>
      <div class="mt-4 text-left p-4 rounded-xl bg-slate-50 border border-slate-200 text-[15px] leading-relaxed font-mono" id="done-details"></div>
      <div class="mt-6 flex justify-center gap-3">
        <button type="button" id="done-copy" class="px-5 py-3 rounded-xl border border-slate-300 text-[15px] font-semibold hover:bg-slate-50">Copy details</button>
        <button type="button" id="done-close" class="px-6 py-3 rounded-xl bg-primary text-white text-[15px] font-bold hover:bg-[#0f2557]">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var SIGNIN = location.origin + '/login';

  function genPass() {
    var words = ['Sky', 'Fly', 'Jet', 'Wing', 'Cloud', 'Star', 'Sun', 'Blue', 'Gold', 'Moon'];
    var w = function () { return words[Math.floor(Math.random() * words.length)]; };
    var n = Math.floor(1000 + Math.random() * 9000);
    return w() + w() + '-' + n;
  }

  // ── Search ──
  var search = document.getElementById('search');
  search.addEventListener('input', function () {
    var q = search.value.trim().toLowerCase(), any = false;
    document.querySelectorAll('[data-group]').forEach(function (g) {
      var vis = 0;
      g.querySelectorAll('[data-person]').forEach(function (c) {
        var show = !q || c.dataset.search.indexOf(q) !== -1;
        c.style.display = show ? '' : 'none';
        if (show) vis++;
      });
      g.style.display = vis ? '' : 'none';
      if (vis) any = true;
    });
    document.getElementById('no-match').classList.toggle('hidden', any);
  });

  // ── Per-person actions ──
  document.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]');
    if (!b) return;
    if (b.dataset.act === 'reset') resetPassword(b);
    if (b.dataset.act === 'toggle') toggleLogin(b);
  });

  function resetPassword(b) {
    var pass = genPass();
    SimpleUI.confirm({
      title: 'Give ' + b.dataset.name + ' a new password?', icon: 'key', confirmText: 'Yes, change it',
      lines: ['Their old password stops working straight away.', 'This is a one-time password: they choose their own the first time they sign in.'],
      input: { label: 'New password', value: pass, required: true, requiredText: 'Please enter a password (at least 8 characters).' }
    }).then(function (r) {
      if (!r.ok) return;
      if (r.value.length < 8) { SimpleUI.toast('The password needs at least 8 characters. Nothing was changed.', 'error'); return; }
      SimpleUI.post('/users/' + b.dataset.id + '/reset-password', { new_password: r.value }).then(function (res) {
        if (!res.success) { SimpleUI.toast(res.error || 'Couldn\'t change the password.', 'error'); return; }
        SimpleUI.confirm({
          title: 'Password changed', icon: 'check_circle', confirmText: 'Copy details', cancelText: 'Close',
          text: 'Send ' + b.dataset.name + ' these details:',
          details: 'Sign in at: ' + SIGNIN + '\nEmail: ' + b.dataset.email + '\nPassword: ' + r.value
        }).then(function (c) {
          if (c.ok) copy('Sign in at: ' + SIGNIN + '\nEmail: ' + b.dataset.email + '\nPassword: ' + r.value);
        });
      });
    });
  }

  function toggleLogin(b) {
    var on = b.dataset.active === '1';
    SimpleUI.confirm(on ? {
      title: 'Turn off ' + b.dataset.name + '\'s login?', tone: 'danger', icon: 'block', confirmText: 'Yes, turn it off',
      lines: ['They\'ll be signed out and can\'t sign in again.', 'Their bookings and history stay exactly as they are.', 'You can turn it back on at any time.']
    } : {
      title: 'Turn ' + b.dataset.name + '\'s login back on?', icon: 'lock_open', confirmText: 'Yes, turn it on',
      lines: ['They\'ll be able to sign in again with their current password.']
    }).then(function (r) {
      if (!r.ok) return;
      SimpleUI.post('/users/' + b.dataset.id + '/toggle-status', {}).then(function (res) {
        if (!res.success) { SimpleUI.toast(res.error || 'Couldn\'t change the login.', 'error'); return; }
        SimpleUI.toast(on ? b.dataset.name + ' can no longer sign in.' : b.dataset.name + ' can sign in again.');
        setTimeout(function () { location.reload(); }, 900);
      });
    });
  }

  function copy(text) {
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(
      function () { SimpleUI.toast('Copied — paste it into WhatsApp or an email.'); },
      function () { SimpleUI.toast('Couldn\'t copy automatically — please select the text and copy it.', 'error'); });
  }

  // ── Add a person ──
  var modal = document.getElementById('add-modal'), form = document.getElementById('add-form');
  var done = document.getElementById('add-done'), err = document.getElementById('f-error');
  var sel = document.getElementById('f-reports');

  function role() { return (form.querySelector('input[name="role"]:checked') || {}).value; }
  function syncRole() {
    form.querySelectorAll('.role-opt').forEach(function (l) {
      var on = l.querySelector('input').checked;
      l.style.borderColor = on ? '#163274' : '';
      l.style.background = on ? '#f2f5fc' : '';
    });
    var r = role(), needs = r === 'agent' || r === 'supervisor';
    document.getElementById('f-reports-wrap').style.display = needs ? '' : 'none';
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.value) return;
      o.hidden = r === 'supervisor' && o.dataset.role !== 'manager';   // team leaders report to a manager
    });
    if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = '';
  }
  function open() {
    form.reset(); form.classList.remove('hidden'); done.classList.add('hidden'); err.classList.add('hidden');
    document.getElementById('f-pass').value = genPass();
    syncRole();
    modal.classList.remove('hidden');
    document.getElementById('f-name').focus();
  }
  function close() { modal.classList.add('hidden'); }

  document.getElementById('btn-add').addEventListener('click', open);
  document.getElementById('f-gen').addEventListener('click', function () { document.getElementById('f-pass').value = genPass(); });
  form.querySelectorAll('input[name="role"]').forEach(function (r) { r.addEventListener('change', syncRole); });
  modal.querySelectorAll('[data-close]').forEach(function (x) { x.addEventListener('click', close); });
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden') && !document.querySelector('.sui-ov')) close(); });

  function fail(msg) { err.textContent = msg; err.classList.remove('hidden'); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.classList.add('hidden');
    var name = document.getElementById('f-name').value.trim(), email = document.getElementById('f-email').value.trim(),
        pass = document.getElementById('f-pass').value.trim(), r = role();
    var needs = r === 'agent' || r === 'supervisor';
    if (name.length < 2) return fail('Please enter their full name.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return fail('Please enter a valid email address.');
    if (needs && !sel.value) return fail('Please choose who they report to.');
    if (pass.length < 8) return fail('The password needs at least 8 characters.');

    var jobLabel = form.querySelector('input[name="role"]:checked').closest('label').querySelector('span').textContent;
    var lines = ['Job: ' + jobLabel];
    if (needs) lines.push('Reports to: ' + sel.selectedOptions[0].textContent.split(' — ')[0]);
    lines.push('They\'ll be able to sign in straight away with this email and password.');

    SimpleUI.confirm({ title: 'Create a login for ' + name + '?', details: email, lines: lines, icon: 'person_add', confirmText: 'Yes, create login' })
      .then(function (c) {
        if (!c.ok) return;
        var btn = document.getElementById('f-submit');
        btn.disabled = true;
        SimpleUI.post('/simple/api/team/add', { name: name, email: email, role: r, reports_to_id: needs ? sel.value : '', password: pass })
          .then(function (res) {
            btn.disabled = false;
            if (!res.success) return fail(res.error || 'Couldn\'t create the login.');
            form.classList.add('hidden');
            done.classList.remove('hidden');
            document.getElementById('done-title').textContent = res.message;
            var details = 'Sign in at: ' + SIGNIN + '\nEmail: ' + res.email + '\nPassword: ' + pass;
            var box = document.getElementById('done-details');
            box.textContent = details;
            box.style.whiteSpace = 'pre-line';
            document.getElementById('done-copy').onclick = function () { copy(details); };
            document.getElementById('done-close').onclick = function () { location.reload(); };
          });
      });
  });
})();
</script>

<?php require __DIR__ . '/_foot.php'; ?>
