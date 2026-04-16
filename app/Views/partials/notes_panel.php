<?php
/**
 * notes_panel.php — Shared Notes Timeline Panel
 *
 * Required variables:
 *   $notes          — Collection of RecordNote objects (eager-loaded with 'user')
 *   $notePostUrl    — POST endpoint e.g. '/acceptance/5/note'
 *   $recordId       — int
 *   $currentUserId  — int
 *   $currentRole    — string
 *
 * The panel shows a scrollable timeline of all notes/actions.
 * All users can add notes. The modal blocker JS is included here and gates
 * the page until the agent types at least one character and submits.
 */

use App\Models\RecordNote;

$notes        = $notes ?? collect([]);
$csrfToken    = $_SESSION['csrf_token'] ?? '';
$showMandatoryModal = !in_array($currentRole ?? 'agent', ['admin', 'manager']);
?>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- MANDATORY NOTE MODAL BLOCKER (agents only)                            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<?php if ($showMandatoryModal): ?>
<div id="note-modal-overlay"
  style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.82);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:1rem;width:min(540px,95vw);box-shadow:0 25px 60px rgba(0,0,0,0.35);overflow:hidden;">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#1e3a6e,#2563eb);padding:1.25rem 1.5rem;display:flex;align-items:center;gap:.75rem;">
      <span class="material-symbols-outlined" style="color:#fff;font-size:1.5rem;">history_edu</span>
      <div>
        <div style="color:#fff;font-weight:800;font-size:1rem;font-family:Manrope,sans-serif;">Mandatory Access Note</div>
        <div style="color:#bfdbfe;font-size:.75rem;margin-top:.1rem;">You must log a note before viewing this record</div>
      </div>
    </div>
    <!-- Body -->
    <div style="padding:1.25rem 1.5rem;">
      <p style="font-size:.8rem;color:#475569;margin-bottom:.75rem;">
        Your name <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Unknown') ?></strong> and timestamp will be
        logged regardless of content. Briefly describe why you are viewing or what action you took.
      </p>
      <div style="position:relative;">
        <textarea id="modal-note-text" rows="4"
          placeholder="e.g. Reviewed at customer request. Confirmed booking details. No changes made."
          style="width:100%;border:2px solid #e2e8f0;border-radius:.6rem;padding:.75rem;font-size:.85rem;resize:none;outline:none;box-sizing:border-box;transition:border-color .2s;"
          oninput="document.getElementById('modal-submit-btn').disabled=this.value.trim().length===0;this.style.borderColor=this.value.trim().length?'#2563eb':'#e2e8f0';"
        ></textarea>
      </div>
      <div id="modal-note-error" style="display:none;color:#dc2626;font-size:.75rem;margin-top:.4rem;font-weight:600;"></div>
      <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
        <button id="modal-submit-btn" disabled
          onclick="submitModalNote()"
          style="background:#2563eb;color:#fff;border:none;border-radius:.6rem;padding:.6rem 1.5rem;font-size:.85rem;font-weight:700;cursor:pointer;opacity:.6;transition:opacity .2s,background .2s;"
          onmouseover="if(!this.disabled)this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
          <span class="material-symbols-outlined" style="font-size:1rem;vertical-align:middle;margin-right:.3rem;">send</span>
          Log Note &amp; Continue
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Re-enable button when text has content
document.getElementById('modal-note-text').addEventListener('input', function() {
  var btn = document.getElementById('modal-submit-btn');
  btn.disabled = this.value.trim().length === 0;
  btn.style.opacity = btn.disabled ? '.6' : '1';
});

function submitModalNote() {
  var text = document.getElementById('modal-note-text').value.trim();
  var btn  = document.getElementById('modal-submit-btn');
  var err  = document.getElementById('modal-note-error');
  if (!text) { err.textContent = 'Please enter a note.'; err.style.display = 'block'; return; }
  btn.disabled = true;
  btn.textContent = 'Saving…';

  fetch('<?= htmlspecialchars($notePostUrl) ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?= htmlspecialchars($csrfToken) ?>' },
    body: 'note=' + encodeURIComponent(text) + '&action=viewed'
  })
  .then(function(r) { return r.json(); })
  .then(function(res) {
    if (res.success) {
      // Remove overlay
      document.getElementById('note-modal-overlay').style.display = 'none';
      // Prepend to timeline
      notesPanel.prependNote(res);
    } else {
      err.textContent = res.error || 'Error saving note.';
      err.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Log Note & Continue';
    }
  })
  .catch(function() {
    err.textContent = 'Network error. Please try again.';
    err.style.display = 'block';
    btn.disabled = false; btn.textContent = 'Log Note & Continue';
  });
}
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- NOTES TIMELINE PANEL                                                  -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mt-6">
  <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="material-symbols-outlined text-primary-600">history_edu</span>
      <h2 class="font-bold text-slate-900" style="font-family:Manrope,sans-serif;">Notes &amp; Activity Log</h2>
      <span class="text-xs text-slate-400 font-medium ml-1">(<?= $notes->count() ?> entries)</span>
    </div>
  </div>

  <!-- Timeline list -->
  <div id="notes-timeline" class="divide-y divide-slate-50 max-h-[520px] overflow-y-auto">
    <?php if ($notes->isEmpty()): ?>
      <div class="px-6 py-8 text-center">
        <span class="material-symbols-outlined text-slate-300 text-4xl">chat_bubble_outline</span>
        <p class="text-slate-400 text-sm mt-2">No notes yet. Be the first to add one.</p>
      </div>
    <?php else: ?>
      <?php foreach ($notes as $n):
        $badge = \App\Models\RecordNote::actionBadge($n->action);
      ?>
      <div class="px-6 py-4 hover:bg-slate-50/60 transition-colors" id="note-entry-<?= $n->id ?>">
        <div class="flex items-start gap-3">
          <!-- Avatar -->
          <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-black text-white"
            style="background:<?= $badge['color'] === 'emerald' ? '#059669' : ($badge['color'] === 'rose' ? '#e11d48' : ($badge['color'] === 'blue' ? '#2563eb' : ($badge['color'] === 'amber' ? '#d97706' : '#7c3aed'))) ?>;">
            <?= strtoupper(substr($n->user->name ?? 'U', 0, 1)) ?>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-bold text-slate-800"><?= htmlspecialchars($n->user->name ?? 'Unknown') ?></span>
              <span class="text-[10px] text-slate-400 uppercase tracking-wider"><?= ucfirst($n->user->role ?? '') ?></span>
              <!-- Badge -->
              <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-bold
                <?= $badge['color'] === 'emerald' ? 'bg-emerald-100 text-emerald-700' :
                   ($badge['color'] === 'rose'    ? 'bg-rose-100 text-rose-700' :
                   ($badge['color'] === 'blue'    ? 'bg-blue-100 text-blue-700' :
                   ($badge['color'] === 'amber'   ? 'bg-amber-100 text-amber-700' : 'bg-violet-100 text-violet-700'))) ?>">
                <span class="material-symbols-outlined text-[11px]"><?= htmlspecialchars($badge['icon']) ?></span>
                <?= htmlspecialchars($badge['label']) ?>
              </span>
              <span class="text-[11px] text-slate-400 ml-auto"><?= $n->created_at->format('M d, Y g:i A') ?></span>
            </div>
            <p class="text-sm text-slate-600 mt-1 break-words"><?= nl2br(htmlspecialchars($n->note)) ?></p>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Add Note Form -->
  <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/30">
    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Add a Note</label>
    <div class="flex gap-2 items-end">
      <textarea id="new-note-text" rows="2"
        placeholder="Add a note, update, or action description…"
        class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-primary-600 bg-white"></textarea>
      <button onclick="notesPanel.submit()" id="add-note-btn"
        class="inline-flex items-center gap-1.5 text-white text-sm font-bold rounded-lg px-4 py-2 transition-colors flex-shrink-0"
        style="background:#163274;" onmouseover="this.style.background='#1e40af'" onmouseout="this.style.background='#163274'">
        <span class="material-symbols-outlined text-base">send</span> Post
      </button>
    </div>
    <div id="note-submit-error" class="hidden text-rose-600 text-xs font-medium mt-1"></div>
  </div>
</div>

<script>
var notesPanel = {
  endpoint: '<?= htmlspecialchars($notePostUrl) ?>',
  prependNote: function(n) {
    var timeline = document.getElementById('notes-timeline');
    // Remove empty state if present
    var empty = timeline.querySelector('.text-center');
    if (empty) empty.parentNode.remove ? empty.parentNode.remove() : empty.remove();

    var colors = {emerald:'#059669',rose:'#e11d48',blue:'#2563eb',amber:'#d97706',violet:'#7c3aed'};
    var badgeColorMap = {
      created:'emerald',approved:'emerald',viewed:'blue',note:'blue',
      edited:'amber',voided:'rose',refunded:'rose',cancelled:'rose',credited:'violet'
    };
    var badgeLabelMap = {
      created:'Created',approved:'Approved',viewed:'Viewed',note:'Note',
      edited:'Edited',voided:'Voided',refunded:'Refunded',cancelled:'Cancelled',credited:'Credited'
    };
    var badgeIconMap = {
      created:'add_circle',approved:'verified',viewed:'visibility',note:'chat_bubble',
      edited:'edit',voided:'block',refunded:'money_off',cancelled:'cancel',credited:'savings'
    };
    var color = badgeColorMap[n.action] || 'blue';
    var label = badgeLabelMap[n.action] || n.action;
    var icon  = badgeIconMap[n.action]  || 'chat_bubble';
    var avatar = (n.user_name || 'U').charAt(0).toUpperCase();
    var bgColor = colors[color] || '#2563eb';
    var badgeClass = color === 'emerald' ? 'bg-emerald-100 text-emerald-700' :
                     color === 'rose'    ? 'bg-rose-100 text-rose-700' :
                     color === 'blue'    ? 'bg-blue-100 text-blue-700' :
                     color === 'amber'   ? 'bg-amber-100 text-amber-700' :
                                           'bg-violet-100 text-violet-700';

    var html = '<div class="px-6 py-4 hover:bg-slate-50/60 transition-colors bg-blue-50/30" id="note-entry-' + n.id + '">' +
      '<div class="flex items-start gap-3">' +
        '<div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-black text-white" style="background:' + bgColor + ';">' + avatar + '</div>' +
        '<div class="flex-1 min-w-0">' +
          '<div class="flex items-center gap-2 flex-wrap">' +
            '<span class="text-sm font-bold text-slate-800">' + (n.user_name||'Unknown') + '</span>' +
            '<span class="text-[10px] text-slate-400 uppercase tracking-wider">' + (n.user_role||'') + '</span>' +
            '<span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-bold ' + badgeClass + '">' +
              '<span class="material-symbols-outlined text-[11px]">' + icon + '</span>' + label +
            '</span>' +
            '<span class="text-[11px] text-slate-400 ml-auto">' + (n.created_at||'just now') + '</span>' +
          '</div>' +
          '<p class="text-sm text-slate-600 mt-1 break-words">' + (n.note||'').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') + '</p>' +
        '</div>' +
      '</div>' +
    '</div>';

    // Insert at top of timeline
    timeline.insertAdjacentHTML('afterbegin', html);
  },
  submit: function() {
    var text = document.getElementById('new-note-text').value.trim();
    var err  = document.getElementById('note-submit-error');
    var btn  = document.getElementById('add-note-btn');
    err.classList.add('hidden');
    if (!text) { err.textContent = 'Note cannot be empty.'; err.classList.remove('hidden'); return; }
    btn.disabled = true;

    fetch(this.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?= htmlspecialchars($csrfToken) ?>' },
      body: 'note=' + encodeURIComponent(text) + '&action=note'
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        notesPanel.prependNote(res);
        document.getElementById('new-note-text').value = '';
      } else {
        err.textContent = res.error || 'Error posting note.';
        err.classList.remove('hidden');
      }
      btn.disabled = false;
    })
    .catch(function() {
      err.textContent = 'Network error. Try again.';
      err.classList.remove('hidden');
      btn.disabled = false;
    });
  }
};
</script>
