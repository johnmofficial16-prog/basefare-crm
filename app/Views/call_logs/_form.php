<?php
/**
 * Call-log form — shared by /call-logs and the sidebar quick-log modal.
 * Behaviour lives in public/assets/js/call-log.js (CallLog.mount).
 *
 * Included from inside the sidebar on every agent page, so it only reads
 * cl-prefixed variables and uses prefixed loop variables: it must never
 * clobber the host page's own variables.
 *
 * @var array $clFavAirlines  quick-chip airlines (agent's most used first)
 * @var array $clBookings     [{id,label,digits}] recent own bookings to link
 */
use App\Models\CallLog;
$clFavAirlines = $clFavAirlines ?? CallLog::COMMON_AIRLINES;
$clBookings    = $clBookings ?? [];
?>
<div data-cl-root>
  <div data-cl-editbar class="hidden mb-3 flex items-center justify-between rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">
    <span><span class="material-symbols-outlined text-[18px] align-[-4px]">edit</span> Editing the call from <b data-cl-edit-when></b></span>
    <button type="button" data-cl-cancel-edit class="text-xs font-bold underline">Cancel</button>
  </div>

  <form autocomplete="off" novalidate class="space-y-4">
    <!-- 1 · Who -->
    <div class="grid grid-cols-1 sm:grid-cols-[auto_1fr_1fr] gap-3 items-start">
      <div data-group="direction" class="cl-seg" role="group" aria-label="Call direction">
        <input type="hidden" name="direction" value="inbound">
        <button type="button" data-value="inbound" title="Customer called us"><span class="material-symbols-outlined text-[18px]">call_received</span>In</button>
        <button type="button" data-value="outbound" title="We called the customer"><span class="material-symbols-outlined text-[18px]">call_made</span>Out</button>
      </div>
      <div data-field="phone" class="cl-field">
        <label class="cl-label">Customer number <span class="text-rose-500">*</span></label>
        <input name="phone" type="tel" inputmode="tel" maxlength="32" placeholder="(212) 555-0100" class="cl-input text-lg font-semibold tracking-wide" autofocus>
      </div>
      <div class="cl-field">
        <label class="cl-label">Name <span class="font-normal text-slate-400">(optional)</span></label>
        <input name="customer_name" type="text" maxlength="120" placeholder="Customer name" class="cl-input">
      </div>
    </div>
    <div data-cl-hint class="hidden -mt-1 rounded-lg bg-indigo-50 border border-indigo-100 px-3 py-2 text-[13px] text-indigo-900"></div>

    <!-- 2 · Why -->
    <div data-field="reason">
      <div class="cl-label">Why did they call? <span class="text-rose-500">*</span></div>
      <div data-group="reason" class="flex flex-wrap gap-2">
        <input type="hidden" name="reason" value="">
        <?php foreach (CallLog::REASONS as $_clK => [$_clL, $_clI]): ?>
        <button type="button" class="cl-chip" data-value="<?= $_clK ?>" aria-pressed="false">
          <span class="material-symbols-outlined text-[17px]"><?= $_clI ?></span><?= htmlspecialchars($_clL) ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 3 · Airline -->
    <div data-field="airline">
      <div class="cl-label">Airline <span class="text-rose-500">*</span></div>
      <div data-group="airline" class="flex flex-wrap gap-2 items-center">
        <input type="hidden" name="airline" value="">
        <?php foreach ($clFavAirlines as $_clA): ?>
        <button type="button" class="cl-chip" data-value="<?= htmlspecialchars($_clA) ?>" aria-pressed="false"><?= htmlspecialchars($_clA) ?></button>
        <?php endforeach; ?>
        <button type="button" class="cl-chip cl-chip-muted" data-value="Not decided" aria-pressed="false">Not decided</button>
        <input name="airline_other" list="cl-airlines" maxlength="80" placeholder="Other airline…" class="cl-input !w-44 !py-1.5 text-sm">
      </div>
      <datalist id="cl-airlines">
        <?php foreach (CallLog::AIRLINE_SUGGESTIONS as $_clA): ?><option value="<?= htmlspecialchars($_clA) ?>"><?php endforeach; ?>
      </datalist>
    </div>

    <!-- 4 · Outcome -->
    <div data-field="outcome">
      <div class="cl-label">How did it end? <span class="text-rose-500">*</span></div>
      <div data-group="outcome" class="flex flex-wrap gap-2">
        <input type="hidden" name="outcome" value="">
        <?php foreach (CallLog::OUTCOMES as $_clK => [$_clL, $_clI, $_clC]): ?>
        <button type="button" class="cl-chip cl-out-<?= $_clC ?>" data-value="<?= $_clK ?>" aria-pressed="false">
          <span class="material-symbols-outlined text-[17px]"><?= $_clI ?></span><?= htmlspecialchars($_clL) ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 4a · Booked → link booking -->
    <div data-when-outcome="booked" class="hidden rounded-lg bg-emerald-50 border border-emerald-100 p-3">
      <label class="cl-label !text-emerald-900">Link the booking <span class="font-normal opacity-70">(optional — auto-picked when the number matches)</span></label>
      <select name="transaction_id" class="cl-input bg-white">
        <option value="">— Not saved yet / skip —</option>
        <?php foreach ($clBookings as $_clB): ?>
        <option value="<?= (int) $_clB['id'] ?>"><?= htmlspecialchars($_clB['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- 4b · Follow-up → when -->
    <div data-when-outcome="follow_up" data-field="follow_up_at" class="hidden rounded-lg bg-amber-50 border border-amber-100 p-3">
      <div class="cl-label !text-amber-900">Remind me to call back <span class="font-normal opacity-70">— your bell will ring</span></div>
      <div class="flex flex-wrap gap-2 items-center">
        <button type="button" class="cl-chip" data-fu="30">In 30 min</button>
        <button type="button" class="cl-chip" data-fu="60">In 1 hour</button>
        <button type="button" class="cl-chip" data-fu="180">In 3 hours</button>
        <button type="button" class="cl-chip" data-fu="next7">Next shift (7 PM)</button>
        <input type="datetime-local" name="follow_up_at" class="cl-input !w-auto !py-1.5 text-sm bg-white">
      </div>
    </div>

    <!-- 5 · Notes + save -->
    <div data-cl-notes class="hidden">
      <textarea name="notes" rows="2" maxlength="2000" placeholder="Anything worth remembering — route, dates, fare quoted…" class="cl-input"></textarea>
    </div>

    <div data-cl-error role="alert" class="hidden rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700"></div>

    <div class="flex items-center justify-between gap-3 pt-1">
      <button type="button" data-cl-notes-toggle class="text-sm font-semibold text-slate-500 hover:text-primary">
        <span class="material-symbols-outlined text-[18px] align-[-4px]">sticky_note_2</span> Add a note
      </button>
      <div class="flex items-center gap-3">
        <span class="hidden sm:inline text-xs text-slate-400"><kbd class="cl-kbd">Enter</kbd> to save</span>
        <button type="submit" data-cl-save class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-primary-container disabled:opacity-60">
          <span class="material-symbols-outlined text-[18px]">check</span><span data-cl-save-label>Save call</span>
        </button>
      </div>
    </div>
  </form>
</div>
