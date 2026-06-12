<?php
/**
 * Customer Emails — Thread view (conversation + reply).
 *
 * @var \App\Models\CustomerEmailThread $thread  (with messages.author, messages.approver)
 * @var \Illuminate\Support\Collection  $notes
 * @var string $role
 * @var bool   $canApprove
 * @var array  $flash
 */
use App\Models\CustomerEmailMessage;

$activePage = 'emails';
$csrf = $_SESSION['csrf_token'] ?? '';

function ceMsgStatusBadge(string $status): string {
    [$label, $cls] = match ($status) {
        'sent'             => ['Sent',            'bg-emerald-100 text-emerald-700'],
        'pending_approval' => ['Pending approval','bg-amber-100 text-amber-700'],
        'approved'         => ['Approved',        'bg-blue-100 text-blue-700'],
        'rejected'         => ['Rejected',        'bg-rose-100 text-rose-700'],
        'failed'           => ['Send failed',     'bg-rose-100 text-rose-700'],
        'received'         => ['Reply received',  'bg-violet-100 text-violet-700'],
        default            => [ucfirst($status),  'bg-slate-100 text-slate-500'],
    };
    return '<span class="inline-flex items-center px-2.5 py-1 text-[10px] font-bold rounded-full ' . $cls . '">' . $label . '</span>';
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Conversation — <?= htmlspecialchars($thread->customer_name ?: 'Customer') ?></title>
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

<main class="ml-60 pt-6 pb-20 px-8 max-w-4xl">

  <?php
  $flashMap = [
    'sent'       => ['emerald', 'Email sent to the customer.'],
    'submitted'  => ['amber',   'Email submitted for manager approval.'],
    'approved'   => ['emerald', 'Email approved and sent.'],
    'rejected'   => ['rose',    'Email rejected.'],
    'send_error' => ['rose',    'The email could not be sent. Check the error log and try again.'],
  ];
  foreach ($flashMap as $k => [$c, $msg]):
    if (!empty($flash[$k])): ?>
      <div class="mb-4 px-4 py-3 rounded-xl bg-<?= $c ?>-50 border border-<?= $c ?>-200 text-<?= $c ?>-700 text-sm font-semibold"><?= $msg ?></div>
  <?php endif; endforeach; ?>

  <div class="flex items-center gap-3 mb-1">
    <a href="/emails" class="text-slate-400 hover:text-primary"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-headline font-extrabold text-primary tracking-tight"><?= htmlspecialchars($thread->customer_name ?: 'Customer') ?></h1>
  </div>
  <p class="text-sm text-slate-500 ml-9 mb-6"><?= htmlspecialchars($thread->customer_email) ?> · <?= htmlspecialchars($thread->subject ?: 'No subject') ?></p>

  <!-- Conversation -->
  <div class="space-y-4 mb-8">
    <?php foreach ($thread->messages as $m):
      $inbound = $m->direction === CustomerEmailMessage::DIR_INBOUND;
      $align   = $inbound ? 'border-l-4 border-violet-300' : 'border-l-4 border-primary/30';
    ?>
      <div class="bg-white border border-slate-200 <?= $align ?> rounded-xl p-5">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
            <span class="material-symbols-outlined text-base"><?= $inbound ? 'call_received' : 'call_made' ?></span>
            <?= $inbound
                ? 'Customer · ' . htmlspecialchars($m->sender_email ?: $thread->customer_email)
                : 'Agent · ' . htmlspecialchars($m->author->name ?? 'Unknown') ?>
          </div>
          <div class="flex items-center gap-2">
            <?= ceMsgStatusBadge($m->status) ?>
            <span class="text-[11px] text-slate-400"><?= $m->created_at->format('M j, g:i A') ?></span>
          </div>
        </div>
        <?php if ($m->final_subject): ?>
          <div class="text-sm font-bold text-primary mb-1"><?= htmlspecialchars($m->final_subject) ?></div>
        <?php endif; ?>
        <?php if ($inbound): /* customer text is untrusted — escape verbatim */ ?>
          <div class="text-sm text-slate-700 whitespace-pre-wrap leading-relaxed"><?= htmlspecialchars($m->final_body) ?></div>
        <?php else: /* our outbound — render the same safe Markdown the customer received */ ?>
          <div class="text-sm text-slate-700 leading-relaxed"><?= \App\Services\EmailMarkdown::toEmailHtml($m->final_body) ?></div>
        <?php endif; ?>

        <?php if ($m->status === 'rejected' && $m->rejected_reason): ?>
          <div class="mt-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
            Rejected: <?= htmlspecialchars($m->rejected_reason) ?>
          </div>
        <?php endif; ?>
        <?php if ($m->status === 'failed' && $m->error): ?>
          <div class="mt-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs font-mono">
            <?= htmlspecialchars($m->error) ?>
          </div>
        <?php endif; ?>

        <?php if ($m->status === 'pending_approval' && $canApprove): ?>
          <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
            <form method="POST" action="/emails/message/<?= $m->id ?>/approve" class="inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
              <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-lg hover:bg-emerald-700">
                <span class="material-symbols-outlined text-sm">check</span> Approve &amp; Send
              </button>
            </form>
            <button type="button" onclick="document.getElementById('reject-<?= $m->id ?>').classList.toggle('hidden')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-bold rounded-lg hover:bg-rose-100">
              <span class="material-symbols-outlined text-sm">close</span> Reject
            </button>
          </div>
          <form method="POST" action="/emails/message/<?= $m->id ?>/reject" id="reject-<?= $m->id ?>" class="hidden mt-2 flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
            <input type="text" name="reason" placeholder="Reason for rejection" required
                   class="flex-1 rounded-lg border-slate-300 text-xs focus:ring-primary focus:border-primary"/>
            <button class="px-3 py-2 bg-rose-600 text-white text-xs font-bold rounded-lg hover:bg-rose-700">Confirm reject</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Reply box -->
  <?php if ($thread->status !== 'closed' && ($role ?? '') !== 'csa'): ?>
  <div class="bg-white border border-slate-200 rounded-xl p-5">
    <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 mb-3">Reply</div>
    <form method="POST" action="/emails/<?= $thread->id ?>/reply" id="replyForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="ai_model"   id="r_ai_model"/>
      <input type="hidden" name="ai_subject" id="r_ai_subject"/>
      <input type="hidden" name="ai_body"    id="r_ai_body"/>
      <input type="hidden" name="transaction_id" value="<?= (int) $thread->transaction_id ?>"/>

      <div class="flex gap-2 mb-3">
        <input type="text" id="r_intent" placeholder="Tell the AI what to reply (optional)…"
               class="flex-1 rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"/>
        <select id="r_category" name="category" class="rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary">
          <option value="custom">Custom</option>
          <option value="follow_up">Follow-up</option>
          <option value="apology">Apology</option>
          <option value="doc_request">Doc request</option>
        </select>
        <button type="button" id="rGenBtn" class="inline-flex items-center gap-1.5 px-4 py-2 bg-gold text-primary text-sm font-bold rounded-lg hover:brightness-95 whitespace-nowrap">
          <span class="material-symbols-outlined text-base" id="rGenIcon">auto_awesome</span> Draft
        </button>
      </div>
      <input type="hidden" name="intent" id="r_intent_hidden"/>
      <input type="text" name="final_subject" id="r_subject" placeholder="Subject" required
             value="Re: <?= htmlspecialchars($thread->subject ?: '') ?>"
             class="w-full mb-2 rounded-lg border-slate-300 text-sm focus:ring-primary focus:border-primary"/>
      <textarea name="final_body" id="r_body" rows="6" required placeholder="Write your reply…"
                class="w-full rounded-lg border-slate-300 text-sm font-mono leading-relaxed focus:ring-primary focus:border-primary"></textarea>
      <p id="rGenError" class="hidden text-xs font-semibold text-rose-600 mt-1"></p>
      <div class="flex items-center justify-between mt-3">
        <p class="text-[11px] text-slate-400">
          <?= $canApprove ? 'Sends immediately on submit.' : 'Queued for manager approval.' ?>
        </p>
        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white text-sm font-bold rounded-xl hover:bg-primary-500">
          <span class="material-symbols-outlined text-base"><?= $canApprove ? 'send' : 'schedule_send' ?></span>
          <?= $canApprove ? 'Send reply' : 'Submit for approval' ?>
        </button>
      </div>
    </form>
  </div>
  <?php else: ?>
    <div class="text-center text-sm text-slate-400 py-4">This conversation is closed.</div>
  <?php endif; ?>

  <!-- Audit trail -->
  <?php if (count($notes)): ?>
  <div class="mt-8">
    <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 mb-3">Audit trail</div>
    <div class="space-y-2">
      <?php foreach ($notes as $n): ?>
        <div class="flex items-start gap-3 text-xs text-slate-500">
          <span class="material-symbols-outlined text-sm text-slate-300">history</span>
          <div>
            <span class="text-slate-700"><?= htmlspecialchars($n->note) ?></span>
            <span class="text-slate-400"> · <?= $n->created_at->format('M j, Y g:i A') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</main>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const rGen = document.getElementById('rGenBtn');
if (rGen) {
  rGen.addEventListener('click', async function() {
    const intent = document.getElementById('r_intent').value.trim();
    const errEl = document.getElementById('rGenError'); errEl.classList.add('hidden');
    if (!intent) { errEl.textContent = 'Describe what to reply first.'; errEl.classList.remove('hidden'); return; }
    const icon = document.getElementById('rGenIcon');
    rGen.disabled = true; icon.textContent = 'progress_activity'; icon.classList.add('animate-spin');
    try {
      const res = await fetch('/emails/draft', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF, intent, category: document.getElementById('r_category').value,
          customer_name: <?= json_encode($thread->customer_name ?? '') ?>,
          transaction_id: document.querySelector('#replyForm [name=transaction_id]').value || '' })
      });
      const d = await res.json();
      if (!d.success) { errEl.textContent = d.error || 'Could not generate.'; errEl.classList.remove('hidden'); return; }
      document.getElementById('r_subject').value = d.subject;
      document.getElementById('r_body').value = d.body;
      document.getElementById('r_ai_subject').value = d.subject;
      document.getElementById('r_ai_body').value = d.body;
      document.getElementById('r_ai_model').value = d.ai_model || '';
      document.getElementById('r_intent_hidden').value = intent;
    } catch(e) { errEl.textContent = 'Network error.'; errEl.classList.remove('hidden'); }
    finally { rGen.disabled = false; icon.textContent = 'auto_awesome'; icon.classList.remove('animate-spin'); }
  });
}
</script>
</body>
</html>
