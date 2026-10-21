<?php
/**
 * Notepad — a slide-in panel with the signed-in user's own notes, on every CRM
 * page. Opened from the sidebar's "Notepad" item (href="#notepad") or Alt+N.
 * Included once by each sidebar partial. Notes are private to their writer.
 */
if (!empty($GLOBALS['__notepadIncluded']) || empty($_SESSION['user_id'])) {
    return;
}
$GLOBALS['__notepadIncluded'] = true;
?>
<link href="<?= \App\Services\Asset::url('assets/css/notepad.css') ?>" rel="stylesheet"/>
<div id="np" class="np" hidden data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>" role="dialog" aria-modal="false" aria-labelledby="np-title">
  <div class="np-head">
    <button type="button" class="np-icon" id="np-back" hidden aria-label="Back to all notes"><span class="material-symbols-outlined">arrow_back</span></button>
    <h2 id="np-title"><span class="material-symbols-outlined">edit_note</span> Notepad</h2>
    <button type="button" class="np-new" id="np-new"><span class="material-symbols-outlined">add</span> New note</button>
    <button type="button" class="np-icon" id="np-close" aria-label="Close the notepad (Esc)"><span class="material-symbols-outlined">close</span></button>
  </div>
  <div class="np-list" id="np-list">
    <div class="np-search"><span class="material-symbols-outlined">search</span><input type="search" id="np-q" placeholder="Search your notes" aria-label="Search your notes"></div>
    <div id="np-items" class="np-items"></div>
  </div>
  <div class="np-edit" id="np-edit" hidden>
    <input id="np-t" class="np-t" maxlength="120" placeholder="Title (optional)" aria-label="Title">
    <textarea id="np-b" class="np-b" placeholder="Write anything: a PNR, a call-back, a reminder…" aria-label="Note"></textarea>
    <div class="np-foot">
      <span class="np-status" id="np-status" role="status"></span>
      <button type="button" class="np-icon" id="np-pin" aria-pressed="false" title="Pin to the top"><span class="material-symbols-outlined">push_pin</span></button>
      <button type="button" class="np-icon np-del" id="np-del" title="Delete this note"><span class="material-symbols-outlined">delete</span></button>
    </div>
  </div>
</div>
<script src="<?= \App\Services\Asset::url('assets/js/notepad.js') ?>" defer></script>
