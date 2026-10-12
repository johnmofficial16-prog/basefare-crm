<?php
/**
 * Simple view — Approvals: everything waiting on an admin, one place.
 * Every button opens a plain-English confirmation (SimpleUI.confirm) first.
 * @var array $a  bookings, emails, invoices, clockins, shifts
 * @var string $currency, $today
 */
$pageTitle = 'Approvals';
require __DIR__ . '/_head.php';

$total = count($a['bookings']) + count($a['emails']) + count($a['invoices']) + count($a['clockins']) + count($a['shifts']);

/** Markdown-ish email body → readable plain text. */
$plain = function (?string $md): string {
    $t = (string) $md;
    $t = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $t);
    $t = preg_replace('/[*_`#>]+/', '', $t);
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
};
$ago = fn($d) => $d ? \Carbon\Carbon::parse($d)->diffForHumans() : '';

$section = function (string $id, string $icon, string $title, string $help, int $n) {
    echo '<section id="' . $id . '" class="mb-10" data-section>';
    echo '<div class="flex items-end justify-between mb-4"><div>';
    echo '<h2 class="font-headline text-[21px] font-bold flex items-center gap-2"><span class="material-symbols-outlined text-primary">' . $icon . '</span>'
        . sv_e($title) . ' <span class="ml-1 px-2.5 py-0.5 rounded-full bg-slate-200 text-slate-700 text-[13px] font-bold" data-count>' . $n . '</span></h2>';
    echo '<p class="text-[14px] text-slate-500 mt-1">' . sv_e($help) . '</p></div></div>';
};
?>

<header class="mb-8">
  <h1 class="font-headline text-[30px] font-extrabold tracking-tight">Approvals</h1>
  <p class="text-[15px] text-slate-500 mt-1">Things the team has sent for a check. Nothing happens until you press a button and confirm.</p>
</header>

<div id="all-done" class="card p-12 text-center <?= $total ? 'hidden' : '' ?>">
  <span class="material-symbols-outlined text-[56px] text-emerald-500">verified</span>
  <p class="font-headline text-[22px] font-bold mt-2">All caught up</p>
  <p class="text-[15px] text-slate-500 mt-1">Nothing is waiting for your approval. New requests will show up here.</p>
  <a href="/simple" class="inline-block mt-5 text-[15px] font-semibold text-primary hover:underline">Back to Home</a>
</div>

<?php /* ── Bookings ───────────────────────────────────────────── */ ?>
<?php if ($a['bookings']): $section('bookings', 'confirmation_number', 'Bookings to approve',
    'Approving a booking confirms the sale: it counts in the numbers and the agent can no longer change it.', count($a['bookings'])); ?>
  <div class="space-y-3">
  <?php foreach ($a['bookings'] as $t): $cur = $t->currency ?: $currency; ?>
    <article class="card p-5 flex items-center gap-5" data-item>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <h3 class="text-[17px] font-bold"><?= sv_e($t->customer_name) ?></h3>
          <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 text-[12px] font-semibold"><?= sv_e($t->typeLabel()) ?></span>
        </div>
        <p class="text-[14px] text-slate-500 mt-1">
          PNR <span class="font-mono font-semibold text-slate-700"><?= sv_e($t->pnr) ?></span>
          <?= $t->airline ? ' · ' . sv_e($t->airline) : '' ?>
          · by <strong class="text-slate-700"><?= sv_e($t->agent->name ?? 'Unknown') ?></strong> · <?= sv_e($ago($t->created_at)) ?>
        </p>
      </div>
      <div class="text-right">
        <div class="text-[18px] font-extrabold"><?= sv_e(sv_money((float) $t->total_amount, $cur)) ?></div>
        <div class="text-[13px] text-slate-500">Profit <?= sv_e(sv_money((float) $t->profit_mco, $cur)) ?></div>
      </div>
      <div class="flex items-center gap-2">
        <a href="/transactions/<?= $t->id ?>" class="px-4 py-2.5 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50 no-underline">See details</a>
        <button type="button" class="px-5 py-2.5 rounded-xl bg-primary text-white text-[14px] font-bold hover:bg-[#0f2557]"
          data-act="booking" data-id="<?= $t->id ?>"
          data-summary="<?= sv_e($t->customer_name . ' · PNR ' . $t->pnr . ' · ' . sv_money((float) $t->total_amount, $cur) . ' · by ' . ($t->agent->name ?? 'Unknown')) ?>">Approve</button>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* ── Customer emails ────────────────────────────────────── */ ?>
<?php if ($a['emails']): $section('emails', 'mail', 'Customer emails to check',
    'Agents wrote these to customers. "Send it" emails the customer right away; "Send back" returns it to the agent with your note.', count($a['emails'])); ?>
  <div class="space-y-3">
  <?php foreach ($a['emails'] as $m): $to = $m->thread->customer_email ?? ''; $body = $plain($m->final_body); ?>
    <article class="card p-5" data-item>
      <div class="flex items-start gap-5">
        <div class="flex-1 min-w-0">
          <h3 class="text-[17px] font-bold"><?= sv_e($m->final_subject ?: '(no subject)') ?></h3>
          <p class="text-[14px] text-slate-500 mt-1">To <strong class="text-slate-700"><?= sv_e($to) ?></strong> · written by <strong class="text-slate-700"><?= sv_e($m->author->name ?? 'Unknown') ?></strong> · <?= sv_e($ago($m->created_at)) ?></p>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" class="px-4 py-2.5 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50"
            data-act="email-reject" data-id="<?= $m->id ?>" data-who="<?= sv_e($m->author->name ?? 'the agent') ?>">Send back</button>
          <button type="button" class="px-5 py-2.5 rounded-xl bg-primary text-white text-[14px] font-bold hover:bg-[#0f2557]"
            data-act="email-approve" data-id="<?= $m->id ?>" data-to="<?= sv_e($to) ?>" data-subject="<?= sv_e($m->final_subject) ?>">Send it</button>
        </div>
      </div>
      <details class="mt-3 group">
        <summary class="cursor-pointer text-[14px] font-semibold text-primary list-none flex items-center gap-1">
          <span class="material-symbols-outlined text-[18px] group-open:rotate-90 transition-transform">chevron_right</span> Read the email
        </summary>
        <div class="mt-2 p-4 rounded-xl bg-slate-50 border border-slate-200 text-[15px] leading-relaxed whitespace-pre-line text-slate-700"><?= sv_e($body) ?></div>
        <a href="/emails/<?= (int) $m->thread_id ?>" class="inline-block mt-2 text-[13px] text-slate-500 hover:text-primary">Open the whole conversation</a>
      </details>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* ── Invoices ───────────────────────────────────────────── */ ?>
<?php if ($a['invoices']): $section('invoices', 'request_quote', 'Invoices to check',
    'Approving emails the invoice to the customer right away.', count($a['invoices'])); ?>
  <div class="space-y-3">
  <?php foreach ($a['invoices'] as $inv): $cur = $inv->currency ?: $currency; ?>
    <article class="card p-5 flex items-center gap-5" data-item>
      <div class="flex-1 min-w-0">
        <h3 class="text-[17px] font-bold"><?= sv_e($inv->customer_name) ?> <span class="text-[14px] font-semibold text-slate-400 ml-1"><?= sv_e($inv->invoice_no) ?></span></h3>
        <p class="text-[14px] text-slate-500 mt-1"><?= sv_e($inv->purposeLabel()) ?><?= $inv->pnr ? ' · PNR ' . sv_e($inv->pnr) : '' ?> · by <strong class="text-slate-700"><?= sv_e($inv->creator->name ?? 'Unknown') ?></strong> · <?= sv_e($ago($inv->created_at)) ?></p>
      </div>
      <div class="text-[18px] font-extrabold"><?= sv_e(sv_money((float) $inv->total_amount, $cur)) ?></div>
      <div class="flex items-center gap-2">
        <a href="/invoices/<?= $inv->id ?>" class="px-4 py-2.5 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50 no-underline">See invoice</a>
        <button type="button" class="px-4 py-2.5 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50"
          data-act="invoice-reject" data-id="<?= $inv->id ?>" data-who="<?= sv_e($inv->creator->name ?? 'the agent') ?>">Send back</button>
        <button type="button" class="px-5 py-2.5 rounded-xl bg-primary text-white text-[14px] font-bold hover:bg-[#0f2557]"
          data-act="invoice-approve" data-id="<?= $inv->id ?>" data-to="<?= sv_e($inv->customer_email) ?>"
          data-summary="<?= sv_e($inv->invoice_no . ' · ' . $inv->customer_name . ' · ' . sv_money((float) $inv->total_amount, $cur)) ?>">Approve &amp; send</button>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* ── Late clock-ins ──────────────────────────────────────── */ ?>
<?php if ($a['clockins']): $section('clockins', 'schedule', 'Late clock-in requests',
    'These people tried to clock in outside their shift time and are waiting for permission.', count($a['clockins'])); ?>
  <div class="space-y-3">
  <?php foreach ($a['clockins'] as $p): ?>
    <article class="card p-5 flex items-center gap-4" data-item>
      <span class="w-11 h-11 rounded-full bg-amber-100 text-amber-800 font-bold flex items-center justify-center"><?= sv_e(sv_initials($p['name'])) ?></span>
      <div class="flex-1">
        <h3 class="text-[17px] font-bold"><?= sv_e($p['name']) ?></h3>
        <p class="text-[14px] text-slate-500">Wants to start work today</p>
      </div>
      <button type="button" class="px-4 py-2.5 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50"
        data-act="clockin-deny" data-id="<?= $p['id'] ?>" data-name="<?= sv_e($p['name']) ?>">Don't allow</button>
      <button type="button" class="px-5 py-2.5 rounded-xl bg-primary text-white text-[14px] font-bold hover:bg-[#0f2557]"
        data-act="clockin-allow" data-id="<?= $p['id'] ?>" data-name="<?= sv_e($p['name']) ?>">Allow</button>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* ── Shift schedules ────────────────────────────────────── */ ?>
<?php if ($a['shifts']): $section('shifts', 'calendar_month', 'Shift schedules to approve',
    'A team leader changed shifts for a week. Approving makes the new times live for the team.', count($a['shifts'])); ?>
  <div class="space-y-3">
  <?php foreach ($a['shifts'] as $sh): $wk = \Carbon\Carbon::parse($sh['week_start']); ?>
    <article class="card p-5 flex items-center gap-4" data-item>
      <span class="w-11 h-11 rounded-xl bg-slate-100 text-primary flex items-center justify-center"><span class="material-symbols-outlined">calendar_month</span></span>
      <div class="flex-1">
        <h3 class="text-[17px] font-bold">Week of <?= sv_e($wk->format('l, M j')) ?></h3>
        <p class="text-[14px] text-slate-500"><?= (int) $sh['count'] ?> shift change<?= $sh['count'] > 1 ? 's' : '' ?> by <strong class="text-slate-700"><?= sv_e($sh['submitted_by']) ?></strong></p>
      </div>
      <a href="/shifts/week?week=<?= sv_e($sh['week_start']) ?>" class="px-4 py-2.5 rounded-xl border border-slate-300 text-[14px] font-semibold text-slate-700 hover:bg-slate-50 no-underline">See the shifts</a>
      <button type="button" class="px-5 py-2.5 rounded-xl bg-primary text-white text-[14px] font-bold hover:bg-[#0f2557]"
        data-act="shift" data-week="<?= sv_e($sh['week_start']) ?>" data-sup="<?= (int) $sh['submitted_by_id'] ?>"
        data-summary="<?= sv_e('Week of ' . $wk->format('M j') . ' · ' . $sh['count'] . ' change(s) by ' . $sh['submitted_by']) ?>">Approve</button>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var TODAY = <?= json_encode($today) ?>;

  function done(btn, res) {
    if (!res.success) { SimpleUI.toast(res.error || 'Something went wrong. Nothing was changed.', 'error'); return; }
    SimpleUI.toast(res.message || 'Done.');
    var item = btn.closest('[data-item]');
    var section = btn.closest('[data-section]');
    item.classList.add('fade-out');
    setTimeout(function () {
      item.remove();
      var left = section.querySelectorAll('[data-item]').length;
      section.querySelector('[data-count]').textContent = left;
      if (!left) section.remove();
      if (!document.querySelector('[data-section]')) document.getElementById('all-done').classList.remove('hidden');
      var badge = document.querySelector('a[href="/simple/approvals"] [aria-label$="waiting"]');
      if (badge) { var n = parseInt(badge.textContent, 10) - 1; if (n > 0) badge.textContent = n; else badge.remove(); }
    }, 350);
  }

  function busy(btn, on) { btn.disabled = on; btn.style.opacity = on ? .6 : ''; }

  var actions = {
    booking: function (b) {
      return SimpleUI.confirm({
        title: 'Approve this booking?', details: b.dataset.summary, confirmText: 'Yes, approve',
        lines: ['It will count in sales and in the agent\'s numbers.', 'It gets locked — the agent can no longer change it.', 'This can\'t be undone from here.']
      }).then(function (r) { return r.ok && SimpleUI.post('/simple/api/booking/' + b.dataset.id + '/approve', {}); });
    },
    'email-approve': function (b) {
      return SimpleUI.confirm({
        title: 'Send this email to the customer?', details: b.dataset.subject + '\nTo: ' + b.dataset.to, confirmText: 'Yes, send it',
        lines: ['It goes out right away from the Base Fare mailbox.', 'Once sent, it can\'t be called back.']
      }).then(function (r) { return r.ok && SimpleUI.post('/simple/api/email/' + b.dataset.id + '/approve', {}); });
    },
    'email-reject': function (b) {
      return SimpleUI.confirm({
        title: 'Send this email back?', confirmText: 'Send it back', icon: 'undo',
        text: 'The customer won\'t get it. ' + b.dataset.who + ' will see your note and can write a new one.',
        input: { label: 'What should ' + b.dataset.who + ' change?', placeholder: 'e.g. Please add the new flight time', multiline: true, required: true, requiredText: 'Please write a short note so they know what to fix.' }
      }).then(function (r) { return r.ok && SimpleUI.post('/simple/api/email/' + b.dataset.id + '/reject', { reason: r.value }); });
    },
    'invoice-approve': function (b) {
      return SimpleUI.confirm({
        title: 'Approve and email this invoice?', details: b.dataset.summary + '\nTo: ' + b.dataset.to, confirmText: 'Yes, approve & send',
        lines: ['The invoice is emailed to the customer right away.', 'Once sent, it can\'t be called back.']
      }).then(function (r) { return r.ok && SimpleUI.post('/simple/api/invoice/' + b.dataset.id + '/approve', {}); });
    },
    'invoice-reject': function (b) {
      return SimpleUI.confirm({
        title: 'Send this invoice back?', confirmText: 'Send it back', icon: 'undo',
        text: 'The customer won\'t get it. ' + b.dataset.who + ' will see your note and can fix it.',
        input: { label: 'What should ' + b.dataset.who + ' change?', placeholder: 'e.g. The amount should be CAD 450', multiline: true, required: true, requiredText: 'Please write a short note so they know what to fix.' }
      }).then(function (r) { return r.ok && SimpleUI.post('/simple/api/invoice/' + b.dataset.id + '/reject', { reason: r.value }); });
    },
    'clockin-allow': function (b) {
      return SimpleUI.confirm({
        title: 'Let ' + b.dataset.name + ' start work?', confirmText: 'Yes, allow', icon: 'schedule',
        text: 'They\'ll be able to clock in now, even though it\'s outside their shift time.',
        input: { label: 'Reason (saved in the attendance record)', placeholder: 'e.g. Shift changed today', required: true, requiredText: 'Please add a short reason.' }
      }).then(function (r) { return r.ok && SimpleUI.postJson('/attendance/override', { agent_id: +b.dataset.id, date: TODAY, reason: r.value }); });
    },
    'clockin-deny': function (b) {
      return SimpleUI.confirm({
        title: 'Don\'t let ' + b.dataset.name + ' clock in today?', confirmText: 'Yes, don\'t allow', tone: 'danger',
        text: 'They won\'t be able to start work outside their shift time today.',
        input: { label: 'Reason (they will see this)', placeholder: 'e.g. Not scheduled today', required: true, requiredText: 'Please add a short reason.' }
      }).then(function (r) { return r.ok && SimpleUI.postJson('/attendance/deny', { agent_id: +b.dataset.id, date: TODAY, reason: r.value }); });
    },
    shift: function (b) {
      return SimpleUI.confirm({
        title: 'Approve these shift changes?', details: b.dataset.summary, confirmText: 'Yes, approve',
        lines: ['The new shift times go live for the team straight away.', 'You can still change shifts later from the Shift Schedule.']
      }).then(function (r) { return r.ok && SimpleUI.postJson('/shifts/week/approve', { week_start: b.dataset.week, supervisor_id: +b.dataset.sup || null }); });
    }
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn || btn.disabled) return;
    var fn = actions[btn.dataset.act];
    if (!fn) return;
    busy(btn, true);
    fn(btn).then(function (res) {
      if (res) done(btn, res);
    }).finally(function () { busy(btn, false); });
  });
})();
</script>

<?php require __DIR__ . '/_foot.php'; ?>
