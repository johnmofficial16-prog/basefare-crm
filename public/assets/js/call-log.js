/**
 * Call Logs — shared form behaviour for the /call-logs page and the sidebar
 * quick-log modal. Plain JS, no dependencies.
 *
 *   CallLog.mount(rootEl, { csrf, bookings, onSaved(log, stats, wasEdit), onCancelEdit() })
 *     → { load(log), reset(), focus() }
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function digits(s) {
    var d = String(s || '').replace(/\D+/g, '');
    return d.length > 10 ? d.slice(-10) : d;
  }
  function pad(n) { return String(n).padStart(2, '0'); }
  function localIso(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function toast(msg, kind) {
    var t = document.createElement('div');
    t.className = 'cl-toast' + (kind === 'err' ? ' cl-toast-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2600);
  }

  function mount(root, cfg) {
    cfg = cfg || {};
    var form = root.querySelector('form');
    var state = { editId: null };
    var lookupTimer = null, lastLookup = '';

    var phone = form.querySelector('[name=phone]');
    var nameIn = form.querySelector('[name=customer_name]');
    var airlineOther = form.querySelector('[name=airline_other]');
    var hint = root.querySelector('[data-cl-hint]');
    var errBox = root.querySelector('[data-cl-error]');
    var saveBtn = root.querySelector('[data-cl-save]');
    var editBar = root.querySelector('[data-cl-editbar]');
    var linkSel = form.querySelector('[name=transaction_id]');
    var fuInput = form.querySelector('[name=follow_up_at]');

    // ── Chip groups ───────────────────────────────────────────────────────
    function groupEl(name) { return form.querySelector('[data-group="' + name + '"]'); }
    function selectChip(name, value) {
      var g = groupEl(name);
      if (!g) return;
      var found = false;
      Array.prototype.forEach.call(g.querySelectorAll('[data-value]'), function (b) {
        var on = b.getAttribute('data-value') === value;
        b.classList.toggle('cl-on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (on) found = true;
      });
      form.querySelector('[name="' + name + '"]').value = found ? value : '';
      clearFieldError(name);
      if (name === 'outcome') onOutcome(found ? value : '');
      return found;
    }
    Array.prototype.forEach.call(form.querySelectorAll('[data-group]'), function (g) {
      var name = g.getAttribute('data-group');
      g.addEventListener('click', function (e) {
        var b = e.target.closest('[data-value]');
        if (!b) return;
        e.preventDefault();
        var v = b.getAttribute('data-value');
        // Tapping the selected chip again un-selects it.
        if (b.classList.contains('cl-on') && name !== 'direction') v = '';
        selectChip(name, v);
        if (name === 'airline' && airlineOther) airlineOther.value = '';
      });
    });
    if (airlineOther) {
      airlineOther.addEventListener('input', function () {
        if (airlineOther.value.trim()) selectChip('airline', '__none__');
        form.querySelector('[name=airline]').value = airlineOther.value.trim();
        clearFieldError('airline');
      });
    }

    // ── Outcome-dependent panels ──────────────────────────────────────────
    function onOutcome(v) {
      Array.prototype.forEach.call(form.querySelectorAll('[data-when-outcome]'), function (p) {
        p.classList.toggle('hidden', p.getAttribute('data-when-outcome') !== v);
      });
      if (v === 'follow_up' && fuInput && !fuInput.value) setFollowUp(60);
      if (v === 'booked') autoLinkBooking();
    }
    function setFollowUp(mins) {
      var d = new Date(Date.now() + mins * 60000);
      d.setSeconds(0, 0);
      fuInput.value = localIso(d);
      markFuButton();
    }
    function markFuButton(active) {
      Array.prototype.forEach.call(form.querySelectorAll('[data-fu]'), function (b) {
        b.classList.toggle('cl-on', b === active);
      });
    }
    Array.prototype.forEach.call(form.querySelectorAll('[data-fu]'), function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        var v = b.getAttribute('data-fu');
        if (v === 'next7') {
          // Next 7:00 PM — the start of the next US-daytime shift.
          var d = new Date();
          d.setHours(19, 0, 0, 0);
          if (d.getTime() <= Date.now()) d.setDate(d.getDate() + 1);
          fuInput.value = localIso(d);
        } else {
          setFollowUp(parseInt(v, 10));
        }
        markFuButton(b);
        clearFieldError('follow_up_at');
      });
    });
    if (fuInput) fuInput.addEventListener('input', function () { markFuButton(null); });

    // ── Booking link ──────────────────────────────────────────────────────
    function autoLinkBooking() {
      if (!linkSel || linkSel.value) return;
      var d = digits(phone.value);
      if (d.length < 7) return;
      (cfg.bookings || []).some(function (b) {
        if (b.digits && b.digits === d) { linkSel.value = String(b.id); return true; }
        return false;
      });
    }

    // ── Repeat-caller lookup ──────────────────────────────────────────────
    phone.addEventListener('input', function () {
      clearFieldError('phone');
      clearTimeout(lookupTimer);
      lookupTimer = setTimeout(lookup, 450);
    });
    function lookup() {
      var d = digits(phone.value);
      if (!hint) return;
      if (d.length < 7) { hint.classList.add('hidden'); lastLookup = ''; return; }
      if (d === lastLookup) return;
      lastLookup = d;
      fetch('/api/call-logs/lookup?phone=' + encodeURIComponent(d), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (digits(phone.value) !== d) return;
          if (!res.found) { hint.classList.add('hidden'); return; }
          var parts = [];
          if (res.calls && res.calls.length) {
            var c = res.calls[0];
            parts.push('<b>You spoke ' + (res.calls.length > 1 ? res.calls.length + '× ' : '') + 'before</b> — last ' + esc(c.ago) + ': ' + esc(c.reason) + ' · ' + esc(c.airline) + ' · ' + esc(c.outcome));
          }
          if (res.others) parts.push('Also called the team ' + res.others + '× this month');
          if (res.bookings && res.bookings.length) {
            parts.push('Your booking' + (res.bookings.length > 1 ? 's' : '') + ': ' + res.bookings.map(function (b) {
              return '<a class="underline" href="/transactions/' + b.id + '" target="_blank">' + esc(b.pnr) + '</a> ' + esc(b.airline || '') + ' (' + esc(b.date) + ')';
            }).join(', '));
          }
          hint.innerHTML = '<span class="material-symbols-outlined text-[16px] align-[-3px]">history</span> ' + parts.join(' · ');
          hint.classList.remove('hidden');
          if (res.name && nameIn && !nameIn.value) nameIn.value = res.name;
          autoLinkBooking();
        })
        .catch(function () {});
    }

    // ── Notes toggle ──────────────────────────────────────────────────────
    var notesToggle = root.querySelector('[data-cl-notes-toggle]');
    var notesWrap = root.querySelector('[data-cl-notes]');
    if (notesToggle && notesWrap) {
      notesToggle.addEventListener('click', function (e) {
        e.preventDefault();
        notesWrap.classList.toggle('hidden');
        if (!notesWrap.classList.contains('hidden')) notesWrap.querySelector('textarea').focus();
      });
    }

    // ── Errors ────────────────────────────────────────────────────────────
    function fieldBox(name) {
      return form.querySelector('[data-field="' + name + '"]');
    }
    function clearFieldError(name) {
      var box = fieldBox(name);
      if (box) box.classList.remove('cl-bad');
      if (errBox) errBox.classList.add('hidden');
    }
    function showError(msg, field) {
      if (errBox) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
      var box = field && fieldBox(field);
      if (box) {
        box.classList.remove('cl-bad'); void box.offsetWidth; box.classList.add('cl-bad');
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        var f = box.querySelector('input,button');
        if (f) f.focus({ preventScroll: true });
      }
    }

    // Client-side check first, so the agent hears about everything missing
    // in one go instead of one round-trip per field.
    function missing() {
      var need = [];
      if (digits(phone.value).length < 7) need.push(['phone', 'number']);
      if (!form.querySelector('[name=reason]').value) need.push(['reason', 'reason']);
      if (!form.querySelector('[name=airline]').value) need.push(['airline', 'airline']);
      if (!form.querySelector('[name=outcome]').value) need.push(['outcome', 'outcome']);
      else if (form.querySelector('[name=outcome]').value === 'follow_up' && !fuInput.value) need.push(['follow_up_at', 'call-back time']);
      return need;
    }

    // ── Submit ────────────────────────────────────────────────────────────
    var busy = false;
    function submit() {
      if (busy) return;
      var need = missing();
      if (need.length) {
        need.forEach(function (n) { var b = fieldBox(n[0]); if (b) { b.classList.remove('cl-bad'); void b.offsetWidth; b.classList.add('cl-bad'); } });
        showError('Still needed: ' + need.map(function (n) { return n[1]; }).join(', ') + '.', need[0][0]);
        return;
      }
      busy = true;
      saveBtn.disabled = true;
      var body = new URLSearchParams(new FormData(form));
      body.set('csrf_token', cfg.csrf);
      var url = state.editId ? '/call-logs/' + state.editId + '/update' : '/call-logs';
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': cfg.csrf },
        body: body.toString()
      })
        .then(function (r) {
          return r.text().then(function (t) {
            try { return JSON.parse(t); } catch (e) {
              throw new Error(r.status === 403 ? 'Blocked (403) — refresh the page and try again.' : 'Server error — try again.');
            }
          });
        })
        .then(function (res) {
          if (!res.success) { showError(res.error || 'Could not save.', res.field); return; }
          var wasEdit = !!state.editId;
          reset();
          toast(wasEdit ? 'Call updated ✓'
            : (res.log.outcome === 'booked' ? '🎉 Booked! Call #' + res.stats.calls + ' this shift'
              : 'Logged ✓ — ' + res.stats.calls + ' call' + (res.stats.calls === 1 ? '' : 's') + ' this shift'));
          if (cfg.onSaved) cfg.onSaved(res.log, res.stats, wasEdit);
        })
        .catch(function (e) { showError(e.message || 'Network error — try again.'); })
        .then(function () { busy = false; saveBtn.disabled = false; });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
    form.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var inTextarea = e.target.tagName === 'TEXTAREA';
      if (inTextarea && !(e.ctrlKey || e.metaKey)) return;
      // Enter always saves — even with focus still on the chip just clicked
      // (otherwise Enter would "press" that chip again and un-select it).
      // Keyboard users toggle chips with Space.
      e.preventDefault();
      submit();
    });

    // ── Reset / load (edit) ───────────────────────────────────────────────
    function reset() {
      var dir = form.querySelector('[name=direction]').value || 'inbound';
      form.reset();
      ['reason', 'airline', 'outcome'].forEach(function (n) { selectChip(n, ''); });
      selectChip('direction', dir);           // keep the agent's in/out choice
      if (linkSel) linkSel.value = '';
      if (fuInput) fuInput.value = '';
      markFuButton(null);
      if (notesWrap) notesWrap.classList.add('hidden');
      if (hint) hint.classList.add('hidden');
      if (errBox) errBox.classList.add('hidden');
      lastLookup = '';
      state.editId = null;
      if (editBar) editBar.classList.add('hidden');
      saveBtn.querySelector('[data-cl-save-label]').textContent = 'Save call';
      phone.focus();
    }
    function load(log) {
      reset();
      state.editId = log.id;
      selectChip('direction', log.direction);
      phone.value = log.phone;
      if (nameIn) nameIn.value = log.customer_name || '';
      selectChip('reason', log.reason);
      if (!selectChip('airline', log.airline) && airlineOther) {
        airlineOther.value = log.airline;
        form.querySelector('[name=airline]').value = log.airline;
      }
      selectChip('outcome', log.outcome);
      if (linkSel && log.transaction_id) linkSel.value = String(log.transaction_id);
      if (fuInput) fuInput.value = log.follow_up_at || '';
      if (log.notes && notesWrap) {
        notesWrap.classList.remove('hidden');
        notesWrap.querySelector('textarea').value = log.notes;
      }
      if (editBar) {
        editBar.classList.remove('hidden');
        editBar.querySelector('[data-cl-edit-when]').textContent = log.time;
      }
      saveBtn.querySelector('[data-cl-save-label]').textContent = 'Update call';
      root.scrollIntoView({ block: 'start', behavior: 'smooth' });
      phone.focus({ preventScroll: true });
    }
    var cancelEdit = root.querySelector('[data-cl-cancel-edit]');
    if (cancelEdit) cancelEdit.addEventListener('click', function (e) { e.preventDefault(); reset(); if (cfg.onCancelEdit) cfg.onCancelEdit(); });

    selectChip('direction', 'inbound');
    return { load: load, reset: reset, focus: function () { phone.focus(); } };
  }

  window.CallLog = { mount: mount, esc: esc, toast: toast };
})();
