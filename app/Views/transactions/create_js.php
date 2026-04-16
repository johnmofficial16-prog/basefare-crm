// ═══════════════════════════════════════════════════════════════════════════
// TRANSACTION RECORDER — FULL JAVASCRIPT
// ═══════════════════════════════════════════════════════════════════════════

// ── Global State ─────────────────────────────────────────────────────────
const state = {
  step: 1,
  type: '<?= addslashes($pre['type']) ?>',
  passengers: [],
  segments: { main: [], old: [], new: [], other: [] },
  fareItems: [],
  extraCards: [],
};

const TYPE_LABELS = {
  new_booking:'New Booking', exchange:'Exchange / Date Change',
  cancel_refund:'Cancellation & Refund', cancel_credit:'Cancellation & Credit',
  seat_purchase:'Seat Purchase', cabin_upgrade:'Cabin Upgrade',
  name_correction:'Name Correction', other:'Other'
};

const COLOR_MAP = {
  new_booking:'blue', exchange:'violet', cancel_refund:'rose', cancel_credit:'orange',
  seat_purchase:'cyan', cabin_upgrade:'emerald', name_correction:'amber', other:'gray'
};

// ── Utility ──────────────────────────────────────────────────────────────
function _esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtTime(t) {
  t = String(t||'').replace(/[^0-9APap]/g,'');
  if (t.length >= 4) return t.substring(0,2) + ':' + t.substring(2,4);
  if (t.length === 3) return '0' + t[0] + ':' + t.substring(1,3);
  return t;
}

// ═══════════════════════════════════════════════════════════════════════════
// WIZARD — Step Navigation
// ═══════════════════════════════════════════════════════════════════════════
const wizard = {
  goTo: function(n) {
    if (n < 1 || n > 5) return;
    // Hide all panels
    document.querySelectorAll('.step-panel').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('step-' + n).classList.add('active');
    // Update step bar
    for (let i = 1; i <= 5; i++) {
      const btn = document.getElementById('step-btn-' + i);
      const dot = btn.querySelector('span');
      if (btn) {
        if (i <= n) {
          btn.className = btn.className.replace(/bg-slate-100 text-slate-400/g, '').replace(/bg-primary text-white/g, '') + ' bg-primary text-white';
          if (dot) dot.className = dot.className.replace(/bg-slate-200 text-slate-400/g, '').replace(/bg-white\/20/g, '') + ' bg-white/20';
        } else {
          btn.className = btn.className.replace(/bg-primary text-white/g, '') + ' bg-slate-100 text-slate-400';
          if (dot) dot.className = dot.className.replace(/bg-white\/20/g, '') + ' bg-slate-200 text-slate-400';
        }
      }
    }
    state.step = n;
    document.getElementById('sum-step').textContent = n + ' / 5';
    if (n === 5) preview.build();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  next: function() {
    const { valid, msg } = this.validate(state.step);
    if (!valid) { alert(msg); return; }
    this.goTo(state.step + 1);
  },

  prev: function() { this.goTo(state.step - 1); },

  validate: function(step) {
    if (step === 1) {
      if (!state.type) {
        document.getElementById('step1-error').classList.remove('hidden');
        return { valid: false, msg: 'Please select a transaction type.' };
      }
      // "Other" type requires a Charge Title
      if (state.type === 'other') {
        const otherTitle = (document.getElementById('field_other_title') ? document.getElementById('field_other_title').value : '').trim();
        if (!otherTitle) {
          document.getElementById('step1-error').classList.remove('hidden');
          return { valid: false, msg: 'A Charge Title is required when transaction type is "Other". Please fill in the Charge Description box.' };
        }
      }
      document.getElementById('step1-error').classList.add('hidden');
      return { valid: true };
    }
    if (step === 2) {
      const pnr = document.getElementById('field_pnr').value.trim();
      const name = document.getElementById('field_customer_name').value.trim();
      const email = document.getElementById('field_customer_email').value.trim();
      const phone = (document.getElementById('field_customer_phone') ? document.getElementById('field_customer_phone').value : '').trim();
      const errs = [];
      if (!pnr) errs.push('PNR is required.');
      if (!name) errs.push('Customer name is required.');
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errs.push('Valid email is required.');
      if (!phone) errs.push('Customer phone is required.');
      const filledPax = state.passengers.filter(function(p) { return (p.first_name||'').trim() || (p.last_name||'').trim(); });
      if (filledPax.length === 0) {
        document.getElementById('step2-pax-error').classList.remove('hidden');
        errs.push('At least one passenger is required.');
      } else {
        document.getElementById('step2-pax-error').classList.add('hidden');
      }
      if (errs.length) return { valid: false, msg: errs.join(' ') };
      return { valid: true };
    }
    if (step === 3) {
      // Flights are required for booking/exchange/seat types; optional for others
      const needsFlights = ['new_booking', 'exchange', 'seat_purchase'].includes(state.type);
      if (needsFlights) {
        const confirmed = function(segs) {
          return (segs || []).filter(function(s) { return !s._editing && s.from && s.to && s.flight_no; }).length > 0;
        };
        if (!confirmed(state.segments.main) && !confirmed(state.segments.old)) {
          return { valid: false, msg: 'At least one confirmed flight segment is required. Add a segment and click the green ✓ button to confirm it.' };
        }
      }
      return { valid: true };
    }
    if (step === 4) {
      const total = parseFloat(document.getElementById('field_total_amount').value);
      if (!total || total <= 0) {
        document.getElementById('step4-amount-error').classList.remove('hidden');
        return { valid: false, msg: 'Total amount must be greater than 0.' };
      }
      document.getElementById('step4-amount-error').classList.add('hidden');
      const notes = (document.getElementById('field_agent_notes') ? document.getElementById('field_agent_notes').value : '').trim();
      if (!notes) {
        return { valid: false, msg: 'Agent Notes are required. Please describe what was done.' };
      }
      return { valid: true };
    }
    return { valid: true };
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// TYPE MANAGER
// ═══════════════════════════════════════════════════════════════════════════
function selectType(type) {
  state.type = type;
  document.getElementById('field_type').value = type;

  // Visual: update card styles
  document.querySelectorAll('.type-card').forEach(function(card) {
    var t = card.dataset.type;
    var c = card.dataset.color;
    var ringCls = 'ring-'+c+'-500';
    var bgCls = 'bg-'+c+'-50';
    card.classList.remove('selected','border-transparent','ring-blue-500','ring-violet-500','ring-rose-500','ring-orange-500','ring-cyan-500','ring-emerald-500','ring-amber-500','ring-gray-400','bg-blue-50','bg-violet-50','bg-rose-50','bg-orange-50','bg-cyan-50','bg-emerald-50','bg-amber-50','bg-gray-50');
    if (t === type) {
      card.classList.add('selected', 'border-transparent', ringCls, bgCls);
      card.classList.remove('border-slate-200','bg-white');
    } else {
      card.classList.add('border-slate-200','bg-white');
    }
  });

  // Sidebar summary
  document.getElementById('sum-type').textContent = TYPE_LABELS[type] || type;

  // Show/hide type-specific sections
  const itin = ['new_booking','seat_purchase','cabin_upgrade','name_correction'];
  const oldF = ['exchange','cancel_refund','cancel_credit'];
  const newF = ['exchange'];
  const nc   = ['name_correction'];
  const cu   = ['cabin_upgrade'];
  const oth  = ['other'];
  _toggle('sec-itinerary', itin.includes(type));
  _toggle('sec-old-flights', oldF.includes(type));
  _toggle('sec-new-flights', newF.includes(type));
  _toggle('sec-name-correction', nc.includes(type));
  _toggle('sec-cabin-upgrade', cu.includes(type));
  _toggle('sec-other-info', oth.includes(type));
  _toggle('sec-no-type', false); // hide hint
  _toggle('section-other-desc', type === 'other');
}
function _toggle(id, show) {
  const el = document.getElementById(id);
  if (el) el.classList.toggle('hidden', !show);
}

function toggleOtherFlights() {
  const panel   = document.getElementById('other-flights-panel');
  const chevron = document.getElementById('other-flights-chevron');
  if (!panel) return;
  const isHidden = panel.classList.toggle('hidden');
  if (chevron) chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
}

// ═══════════════════════════════════════════════════════════════════════════
// PASSENGER MANAGER
// ═══════════════════════════════════════════════════════════════════════════
const paxMgr = {
  add: function() {
    state.passengers.push({ first_name:'', last_name:'', dob:'', pax_type:'adult', ticket_number:'', frequent_flyer:'' });
    this._render();
  },
  remove: function(idx) {
    state.passengers.splice(idx, 1);
    this._render();
  },
  _update: function(idx, field, val) {
    if (!state.passengers[idx]) return;
    state.passengers[idx][field] = val;
    this._syncCount();
  },
  _syncCount: function() {
    var filled = state.passengers.filter(function(p) { return (p.first_name||'').trim() || (p.last_name||'').trim(); });
    var el = document.getElementById('sum-pax');
    if (el) el.textContent = filled.length;
  },
  _render: function() {
    const el = document.getElementById('pax-list');
    el.innerHTML = state.passengers.map(function(p, i) { return `
      <div class="fare-row grid grid-cols-[2fr_2fr_1fr_1fr_1fr_1fr_auto] gap-2 items-end p-3 bg-slate-50 border border-slate-200 rounded-lg">
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">First Name *</label>
          <input type="text" value="${_esc(p.first_name)}" placeholder="JOHN" title="Passenger first name"
            class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-white uppercase focus:outline-none focus:ring-2 focus:ring-primary/40"
            oninput="paxMgr._update(${i},'first_name',this.value.toUpperCase())">
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Last Name *</label>
          <input type="text" value="${_esc(p.last_name)}" placeholder="SMITH" title="Passenger last name"
            class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-white uppercase focus:outline-none focus:ring-2 focus:ring-primary/40"
            oninput="paxMgr._update(${i},'last_name',this.value.toUpperCase())">
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">DOB</label>
          <input type="date" value="${_esc(p.dob)}" title="Date of Birth"
            class="w-full border border-slate-200 rounded-lg px-1.5 py-1.5 text-[10px] bg-white focus:outline-none focus:ring-2 focus:ring-primary/40"
            onchange="paxMgr._update(${i},'dob',this.value)">
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Type</label>
          <select class="w-full border border-slate-200 rounded-lg px-1.5 py-1.5 text-[10px] bg-white focus:outline-none focus:ring-2 focus:ring-primary/40"
            onchange="paxMgr._update(${i},'pax_type',this.value)">
            <option value="adult" ${p.pax_type==='adult'?'selected':''}>Adult</option>
            <option value="child" ${p.pax_type==='child'?'selected':''}>Child</option>
            <option value="infant" ${p.pax_type==='infant'?'selected':''}>Infant</option>
          </select>
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Ticket #</label>
          <input type="text" value="${_esc(p.ticket_number)}" placeholder="014-..." title="E-ticket number"
            class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-[10px] font-mono bg-white focus:outline-none focus:ring-2 focus:ring-primary/40"
            oninput="paxMgr._update(${i},'ticket_number',this.value)">
        </div>
        <div>
          <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">FF#</label>
          <input type="text" value="${_esc(p.frequent_flyer)}" placeholder="Freq. flyer" title="Frequent flyer number"
            class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-[10px] font-mono bg-white focus:outline-none focus:ring-2 focus:ring-primary/40"
            oninput="paxMgr._update(${i},'frequent_flyer',this.value)">
        </div>
        <button type="button" onclick="paxMgr.remove(${i})"
          class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors self-end">
          <span class="material-symbols-outlined text-sm">delete</span>
        </button>
      </div>`; }).join('');
    this._syncCount();
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// FLIGHT / GDS PARSER (Amadeus + Sabre)
// ═══════════════════════════════════════════════════════════════════════════
const flightMgr = {
  _parseOneLine: function(ln) {
    if (!window.mapCabin) window.mapCabin = function(c) {
      if (!c) return 'Economy';
      c = c.toUpperCase();
      if (['F','A','P'].includes(c)) return 'First';
      if (['C','J','D','I','Z'].includes(c)) return 'Business';
      if (['W','E','T','R'].includes(c)) return 'Premium Economy';
      return 'Economy';
    };
    ln = ln.replace(/\r/g,'').trim();
    if (!ln) return null;
    // Strategy 1: 6-char airport pair (JFKFRA) — Amadeus & Sabre compact
    const re6 = /^\s*\d{0,2}\s*\.?\s*([A-Z0-9]{2})\s*(\d{1,4}[A-Z]?)\s+([A-Z])\s+(\d{2}[A-Z]{3})(?:\s+\d)?\s+([A-Z]{3})([A-Z]{3})\s+[A-Z]{2,3}\d{0,2}\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)(?:\+(\d))?/i;
    let m = re6.exec(ln);
    if (m) return { airline_iata:m[1].toUpperCase(), flight_no:m[1]+m[2], cabin_class:mapCabin(m[3]), date:m[4].toUpperCase(), from:m[5].toUpperCase(), to:m[6].toUpperCase(), dep_time:fmtTime(m[7]), arr_time:fmtTime(m[8]), arr_next_day:!!(m[9]&&parseInt(m[9])>0) };
    // Strategy 2: Space-separated airports — Galileo / Apollo
    const re3 = /^\s*\d{0,2}\s*\.?\s*([A-Z0-9]{2})\s+(\d{1,4}[A-Z]?)\s+([A-Z])\s+(\d{2}[A-Z]{3})(?:\s+[A-Z]{2})?\s+([A-Z]{3})\s+([A-Z]{3})\s+[A-Z]{2,3}\s*\d{0,2}\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)(?:\+(\d))?/i;
    m = re3.exec(ln);
    if (m) return { airline_iata:m[1].toUpperCase(), flight_no:m[1]+m[2], cabin_class:mapCabin(m[3]), date:m[4].toUpperCase(), from:m[5].toUpperCase(), to:m[6].toUpperCase(), dep_time:fmtTime(m[7]), arr_time:fmtTime(m[8]), arr_next_day:!!(m[9]&&parseInt(m[9])>0) };
    // Strategy 3: Minimal fallback
    const reMin = /([A-Z0-9]{2})\s*(\d{1,4}[A-Z]?)\s+([A-Z])\s+(\d{2}[A-Z]{3})\s+([A-Z]{3})\s*([A-Z]{3})\s+(\d{3,4}[APap]?)\s+(\d{3,4}[APap]?)/i;
    m = reMin.exec(ln);
    if (m) return { airline_iata:m[1].toUpperCase(), flight_no:m[1]+m[2], cabin_class:mapCabin(m[3]), date:m[4].toUpperCase(), from:m[5].toUpperCase(), to:m[6].toUpperCase(), dep_time:fmtTime(m[7]), arr_time:fmtTime(m[8]), arr_next_day:false };
    return null;
  },

  parse: function(group, raw) {
    const statusEl = document.getElementById('parse-status-' + group);
    if (!raw.trim()) { state.segments[group]=[]; if(statusEl) statusEl.innerHTML=''; this._render(group); return; }
    const segs = [];
    var self = this;
    raw.split('\n').forEach(function(line) { var seg = self._parseOneLine(line); if (seg) segs.push(seg); });
    if (segs.length) {
      state.segments[group] = segs;
      if (statusEl) statusEl.innerHTML = '<span class="text-emerald-600 font-semibold">✓ Parsed '+segs.length+' segment'+(segs.length>1?'s':'')+'</span>';
    } else {
      state.segments[group] = [];
      if (statusEl) statusEl.innerHTML = '<span class="text-rose-600">⚠ Could not parse — check GDS format</span>';
    }
    this._render(group);
  },

  addManual: function(group) {
    state.segments[group].push({ airline_iata:'', flight_no:'', cabin_class:'Economy', date:'', from:'', to:'', dep_time:'', arr_time:'', arr_next_day:false, _editing:true });
    this._render(group);
  },

  confirmSeg: function(group, idx) {
    const seg = state.segments[group] && state.segments[group][idx];
    if (!seg) return;
    if (!seg.flight_no || !seg.from || !seg.to) {
      alert('Please fill in at least Airline, Flight #, From, and To before confirming.');
      return;
    }
    seg.dep_time = seg.dep_time || '00:00';
    seg._editing = false; // lock into card view
    this._render(group);
  },

  removeSegment: function(group, idx) { state.segments[group].splice(idx, 1); this._render(group); },

  _updateSeg: function(group, idx, field, val) {
    if (!state.segments[group]||!state.segments[group][idx]) return;
    state.segments[group][idx][field] = val;
  },

  _render: function(group) {
    const el = document.getElementById('segs-' + group);
    if (!el) return;
    const segs = state.segments[group];
    if (!segs||!segs.length) { el.innerHTML=''; return; }
    el.innerHTML = segs.map(function(seg, i) {
      const parsed = !!(seg.from && seg.to && seg.dep_time) && !seg._editing;
      const logoUrl = seg.airline_iata ? 'https://www.gstatic.com/flights/airline_logos/35px/'+seg.airline_iata+'.png' : '';
      if (parsed) {
        return `<div class="seg-card flex items-stretch bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="bg-slate-900 px-3 py-3 flex flex-col items-center justify-center gap-1 min-w-[72px]">
            ${logoUrl?'<img src="'+logoUrl+'" class="w-8 h-8 object-contain" onerror="this.style.display=\'none\'">':''}
            <span class="text-[11px] font-black text-white">${_esc(seg.airline_iata)}</span>
          </div>
          <div class="flex-1 p-3 grid grid-cols-[1fr_auto_1fr] gap-2 items-center">
            <div class="text-right"><div class="text-lg font-black text-slate-900">${_esc(seg.dep_time)}</div><div class="text-sm font-bold text-blue-700">${_esc(seg.from)}</div></div>
            <div class="flex flex-col items-center px-1"><div class="text-[9px] font-bold text-slate-500">${_esc(seg.flight_no)}</div><div class="w-14 h-px bg-slate-300 relative my-1.5"><div class="absolute -right-1 -top-1.5 text-blue-600 text-xs">✈</div></div><div class="text-[9px] text-slate-400">${_esc(seg.date)}</div></div>
            <div><div class="flex items-baseline gap-1"><span class="text-lg font-black text-slate-900">${_esc(seg.arr_time)}</span>${seg.arr_next_day?'<span class="px-1 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-bold rounded">+1d</span>':''}</div><div class="text-sm font-bold text-blue-700">${_esc(seg.to)}</div></div>
          </div>
          <button type="button" onclick="flightMgr.removeSegment('${group}',${i})" class="px-2 bg-slate-50 border-l border-slate-200 hover:bg-rose-50 hover:text-rose-600 text-slate-400 flex items-center"><span class="material-symbols-outlined text-sm">close</span></button>
        </div>`;
      } else {
        return `<div class="seg-card p-3 bg-amber-50 border border-amber-200 rounded-xl">
          <div class="grid grid-cols-[1fr_1fr_1fr_1fr_1fr_1fr_1fr_auto_auto] gap-2 items-end">
            <div><label class="block text-[9px] font-bold text-amber-700 uppercase mb-1">Airline *</label><input type="text" value="${_esc(seg.airline_iata)}" maxlength="2" placeholder="AA" class="w-full border border-amber-300 rounded px-2 py-1 text-xs font-mono bg-white uppercase" oninput="flightMgr._updateSeg('${group}',${i},'airline_iata',this.value.toUpperCase())"></div>
            <div><label class="block text-[9px] font-bold text-amber-700 uppercase mb-1">Flight # *</label><input type="text" value="${_esc(seg.flight_no)}" placeholder="AA123" class="w-full border border-amber-300 rounded px-2 py-1 text-xs font-mono bg-white" oninput="flightMgr._updateSeg('${group}',${i},'flight_no',this.value); flightMgr._updateSeg('${group}',${i},'airline_iata',this.value.substring(0,2).toUpperCase())"></div>
            <div><label class="block text-[9px] font-bold text-amber-700 uppercase mb-1">Date</label><input type="text" value="${_esc(seg.date)}" placeholder="12MAR" class="w-full border border-amber-300 rounded px-2 py-1 text-xs font-mono bg-white uppercase" oninput="flightMgr._updateSeg('${group}',${i},'date',this.value.toUpperCase())"></div>
            <div><label class="block text-[9px] font-bold text-amber-700 uppercase mb-1">From *</label><input type="text" value="${_esc(seg.from)}" maxlength="3" placeholder="JFK" class="w-full border border-amber-300 rounded px-2 py-1 text-xs font-mono bg-white uppercase" oninput="flightMgr._updateSeg('${group}',${i},'from',this.value.toUpperCase())"></div>
            <div><label class="block text-[9px] font-bold text-amber-700 uppercase mb-1">To *</label><input type="text" value="${_esc(seg.to)}" maxlength="3" placeholder="MIA" class="w-full border border-amber-300 rounded px-2 py-1 text-xs font-mono bg-white uppercase" oninput="flightMgr._updateSeg('${group}',${i},'to',this.value.toUpperCase())"></div>
            <div><label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Dep</label><input type="text" value="${_esc(seg.dep_time)}" placeholder="10:40" class="w-full border border-slate-200 rounded px-2 py-1 text-xs font-mono bg-white" oninput="flightMgr._updateSeg('${group}',${i},'dep_time',this.value)"></div>
            <div><label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Arr</label><input type="text" value="${_esc(seg.arr_time)}" placeholder="13:25" class="w-full border border-slate-200 rounded px-2 py-1 text-xs font-mono bg-white" oninput="flightMgr._updateSeg('${group}',${i},'arr_time',this.value)"></div>
            <button type="button" onclick="flightMgr.confirmSeg('${group}',${i})" class="p-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition-colors self-end flex items-center gap-0.5" title="Confirm Segment"><span class="material-symbols-outlined text-sm">check</span></button>
            <button type="button" onclick="flightMgr.removeSegment('${group}',${i})" class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors self-end"><span class="material-symbols-outlined text-sm">delete</span></button>
          </div>
          <p class="text-[10px] text-amber-700 mt-2">Fill all required (*) fields then click <strong>✓</strong> to confirm this segment.</p>
        </div>`;
      }
    }).join('');
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// FARE MANAGER
// ═══════════════════════════════════════════════════════════════════════════
const fareMgr = {
  addItem: function(label, amount) {
    state.fareItems.push({ label: label||'', amount: parseFloat(amount)||0 });
    this._render();
  },
  removeItem: function(idx) { state.fareItems.splice(idx, 1); this._render(); },
  _updateItem: function(idx, field, val) {
    if (!state.fareItems[idx]) return;
    state.fareItems[idx][field] = field==='amount' ? parseFloat(val)||0 : val;
    this._recalc();
  },
  _recalc: function() {
    var sum = 0;
    for (var k = 0; k < state.fareItems.length; k++) { sum += parseFloat(state.fareItems[k].amount) || 0; }
    var totalEl = document.getElementById('field_total_amount');
    if (sum > 0 && totalEl) { totalEl.value = sum.toFixed(2); syncSummary(); }
  },
  _render: function() {
    const el = document.getElementById('fare-items');
    el.innerHTML = state.fareItems.map(function(item, i) { return `
      <div class="fare-row flex items-center gap-2">
        <input type="text" value="${_esc(item.label)}" placeholder="e.g. Base Fare"
          class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 focus:outline-none focus:ring-2 focus:ring-primary/40"
          oninput="fareMgr._updateItem(${i},'label',this.value)">
        <input type="number" step="0.01" value="${item.amount||''}" placeholder="0.00"
          class="w-28 border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-mono bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
          oninput="fareMgr._updateItem(${i},'amount',this.value)">
        <button type="button" onclick="fareMgr.removeItem(${i})"
          class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors flex-none">
          <span class="material-symbols-outlined text-sm">delete</span>
        </button>
      </div>`; }).join('');
    this._recalc();
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// ADDITIONAL CARD MANAGER
// ═══════════════════════════════════════════════════════════════════════════
const cardMgr = {
  add: function() { state.extraCards.push({ cardholder_name:'', card_number:'', card_expiry:'', card_cvv:'', card_type:'' }); this._render(); },
  remove: function(idx) { state.extraCards.splice(idx, 1); this._render(); },
  _update: function(idx, field, val) { if (state.extraCards[idx]) state.extraCards[idx][field] = val; },
  _render: function() {
    const el = document.getElementById('additional-cards');
    el.innerHTML = state.extraCards.map(function(c, i) { return `
      <div class="fare-row grid grid-cols-[1fr_1fr_auto_auto_auto] gap-2 items-end p-3 bg-slate-50 border border-slate-200 rounded-lg">
        <div><label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Type</label>
          <select class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white" onchange="cardMgr._update(${i},'card_type',this.value)">
            <option value="">--</option><option>Visa</option><option>Mastercard</option><option>Amex</option><option>Discover</option>
          </select></div>
        <div><label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Cardholder</label>
          <input type="text" placeholder="Name on card" value="${_esc(c.cardholder_name)}" class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white" autocomplete="off" oninput="cardMgr._update(${i},'cardholder_name',this.value)"></div>
        <div><label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Card #</label>
          <input type="text" maxlength="19" placeholder="•••• ••••" value="${_esc(c.card_number)}" class="w-24 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-white" autocomplete="off" oninput="cardMgr._update(${i},'card_number',this.value.replace(/[^\\d\\s]/g,''))"></div>
        <div><label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">CVV</label>
          <input type="text" maxlength="4" placeholder="•••" value="${_esc(c.card_cvv)}" class="w-14 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono bg-white" autocomplete="off" oninput="cardMgr._update(${i},'card_cvv',this.value.replace(/\\D/g,''))"></div>
        <button type="button" onclick="cardMgr.remove(${i})" class="p-1.5 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg transition-colors self-end">
          <span class="material-symbols-outlined text-sm">delete</span></button>
      </div>`; }).join('');
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// MCO CALCULATOR
// ═══════════════════════════════════════════════════════════════════════════
// manual entry now

// ═══════════════════════════════════════════════════════════════════════════
// SUMMARY SYNC
// ═══════════════════════════════════════════════════════════════════════════
function syncSummary() {
  const pnrEl = document.getElementById('field_pnr');
  const nameEl = document.getElementById('field_customer_name');
  const totalEl = document.getElementById('field_total_amount');
  const currEl = document.getElementById('field_currency');
  const pnr  = (pnrEl ? pnrEl.value : '').trim();
  const name = (nameEl ? nameEl.value : '').trim();
  const total= (totalEl ? totalEl.value : '').trim();
  const curr = (currEl ? currEl.value : 'USD');
  const sumPnr = document.getElementById('sum-pnr');
  const sumName = document.getElementById('sum-name');
  const sumTotal = document.getElementById('sum-total');
  if (sumPnr) sumPnr.textContent  = pnr || '--';
  if (sumName) sumName.textContent = name || '--';
  if (sumTotal) sumTotal.textContent = total ? curr+' '+parseFloat(total).toLocaleString('en-CA',{minimumFractionDigits:2}) : '--';
}

// ═══════════════════════════════════════════════════════════════════════════
// IMPORT FROM ACCEPTANCE (AJAX)
// ═══════════════════════════════════════════════════════════════════════════
function importAcceptance(id) {
  if (!id) {
      var sel = document.getElementById('acceptance_import_select');
      id = sel ? (sel.value || '') : '';
  }
  if (!id) return;
  fetch('/transactions/acceptance-data/' + id)
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (!res.success) { alert(res.error || 'Could not load acceptance data.'); return; }
      var d = res.data;
      // Fill fields
      if (d.type) selectType(d.type);
      if (d.customer_name) document.getElementById('field_customer_name').value = d.customer_name;
      if (d.customer_email) document.getElementById('field_customer_email').value = d.customer_email;
      if (d.customer_phone) document.getElementById('field_customer_phone').value = d.customer_phone;
      if (d.pnr) document.getElementById('field_pnr').value = d.pnr.toUpperCase();
      if (d.airline) document.getElementById('field_airline').value = d.airline;
      if (d.order_id) document.getElementById('field_order_id').value = d.order_id;
      if (d.total_amount) document.getElementById('field_total_amount').value = d.total_amount;
      if (d.currency) document.getElementById('field_currency').value = d.currency;
      // Travel dates
      if (d.travel_date) { var fd = document.getElementById('field_travel_date'); if(fd) fd.value = d.travel_date; }
      if (d.departure_time) { var ft = document.getElementById('field_departure_time'); if(ft) ft.value = d.departure_time; }
      if (d.return_date) { var rd = document.getElementById('field_return_date'); if(rd) rd.value = d.return_date; }
      // Card fields
      if (d.card_type) document.getElementById('field_card_type').value = d.card_type;
      if (d.cardholder_name) document.getElementById('field_cardholder_name').value = d.cardholder_name;
      if (d.billing_address) document.getElementById('field_billing_address').value = d.billing_address;
      // Payment extras
      if (d.statement_descriptor) { var sd = document.getElementById('field_statement_descriptor'); if(sd) sd.value = d.statement_descriptor; }
      if (d.split_charge_note) { var scn = document.getElementById('field_split_charge_note'); if(scn) scn.value = d.split_charge_note; }
      // Ticket conditions
      if (d.endorsements) { var ef = document.getElementById('field_endorsements'); if(ef) ef.value = d.endorsements; }
      if (d.baggage_info) { var bf = document.getElementById('field_baggage_info'); if(bf) bf.value = d.baggage_info; }
      if (d.fare_rules) { var ff = document.getElementById('field_fare_rules'); if(ff) ff.value = d.fare_rules; }
      // Passengers
      if (d.passengers && d.passengers.length) {
        state.passengers = d.passengers.map(function(p) {
          return {
            first_name: p.first_name||'', last_name: p.last_name||'',
            dob: p.dob||'', pax_type: p.pax_type||'adult', ticket_number:'', frequent_flyer:''
          };
        });
        paxMgr._render();
      }
      
      // Flights
      var hasMain = d.flight_data && Array.isArray(d.flight_data.flights) && d.flight_data.flights.length > 0;
      var hasOld = d.flight_data && Array.isArray(d.flight_data.old_flights) && d.flight_data.old_flights.length > 0;
      
      var workingSegs = [];
      if (hasMain) {
         state.segments.main = d.flight_data.flights.slice();
         workingSegs = state.segments.main;
         flightMgr._render('main');
      } else if (hasOld) {
         state.segments.old = d.flight_data.old_flights.slice();
         state.segments.new = d.flight_data.new_flights ? d.flight_data.new_flights.slice() : [];
         workingSegs = state.segments.old;
         flightMgr._render('old');
         flightMgr._render('new');
      }

      if (workingSegs.length > 0) {
         var first = workingSegs[0];
         var last = workingSegs[workingSegs.length - 1];
         var parseFD = function(sd) {
             if (!sd) return '';
             if (sd.length === 10 && sd.indexOf('-') === 4) return sd;
             var nd = new Date(sd + " " + new Date().getFullYear());
             if (!isNaN(nd)) {
                 return nd.getFullYear() + '-' + String(nd.getMonth()+1).padStart(2,'0') + '-' + String(nd.getDate()).padStart(2,'0');
             }
             return '';
         };
         
         var travelEl = document.getElementById('field_travel_date');
         var depEl = document.getElementById('field_departure_time');
         var retEl = document.getElementById('field_return_date');
         
         if (travelEl && !travelEl.value && first.date) travelEl.value = parseFD(first.date);
         if (depEl && !depEl.value && first.dep_time) depEl.value = first.dep_time;
         if (retEl && !retEl.value && last.date && workingSegs.length > 1) retEl.value = parseFD(last.date);
      }
      
      // Fare Breakdown
      if (d.fare_breakdown && d.fare_breakdown.length) {
         state.fareItems = d.fare_breakdown.slice();
         fareMgr._render();
      }
      
      // Additional Cards
      if (d.additional_cards && d.additional_cards.length) {
         state.extraCards = d.additional_cards.slice();
         cardMgr._render();
      }
      // Set acceptance_id hidden
      var accInput = document.querySelector('input[name="acceptance_id"]');
      if (!accInput) {
        accInput = document.createElement('input');
        accInput.type = 'hidden'; accInput.name = 'acceptance_id';
        document.getElementById('txnForm').prepend(accInput);
      }
      accInput.value = id;
      syncSummary(); 
      alert('\u2713 Acceptance #' + id + ' imported successfully. Review and complete remaining fields.');
    })
    .catch(function(err) { alert('Failed to fetch acceptance data: ' + err.message); });
}

// ═══════════════════════════════════════════════════════════════════════════
// PREVIEW BUILDER (Step 5)
// ═══════════════════════════════════════════════════════════════════════════
const preview = {
  build: function() {
    const pnrEl = document.getElementById('field_pnr');
    const nameEl = document.getElementById('field_customer_name');
    const emailEl = document.getElementById('field_customer_email');
    const totalEl = document.getElementById('field_total_amount');
    const currEl = document.getElementById('field_currency');
    const holderEl = document.getElementById('field_cardholder_name');
    const typeEl = document.getElementById('field_card_type');

    const pnr   = (pnrEl ? pnrEl.value : '') || '—';
    const name  = (nameEl ? nameEl.value : '') || '—';
    const email = (emailEl ? emailEl.value : '') || '—';
    const total = parseFloat(totalEl ? totalEl.value : '0') || 0;
    const curr  = (currEl ? currEl.value : '') || 'USD';
    const holder= (holderEl ? holderEl.value : '');
    const ctype = (typeEl ? typeEl.value : '');

    document.getElementById('prev-pnr').textContent   = pnr;
    document.getElementById('prev-name').textContent  = name;
    document.getElementById('prev-email').textContent = email;
    const filledPax = state.passengers.filter(function(p) { return (p.first_name||'').trim() || (p.last_name||'').trim(); });
    document.getElementById('prev-pax-count').textContent = filledPax.length;
    document.getElementById('preview-type-badge').textContent = TYPE_LABELS[state.type] || '--';

    // Passengers
    const paxSec = document.getElementById('prev-pax-section');
    if (filledPax.length) {
      paxSec.classList.remove('hidden');
      document.getElementById('prev-pax-list').innerHTML = filledPax.map(function(p) { return
        `<span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 text-slate-700 rounded-full text-xs font-mono">${_esc((p.last_name||'')+'/'+( p.first_name||''))}</span>`;
      }).join('');
    } else paxSec.classList.add('hidden');

    // Flights
    var allSegs = [].concat(state.segments.main||[], state.segments.old||[], state.segments.new||[], state.segments.other||[]);
    const flightsSec = document.getElementById('prev-flights-section');
    if (allSegs.length) {
      flightsSec.classList.remove('hidden');
      document.getElementById('prev-flights').innerHTML = allSegs.map(function(seg) {
        const logo = seg.airline_iata ? 'https://www.gstatic.com/flights/airline_logos/35px/'+seg.airline_iata+'.png' : '';
        return `<div class="flex items-center gap-3 p-2 bg-slate-50 border border-slate-100 rounded-lg">
          ${logo?'<img src="'+logo+'" class="w-7 h-7 object-contain flex-none" onerror="this.style.display=\'none\'">':''}
          <span class="text-xs font-bold font-mono text-slate-900">${_esc(seg.flight_no)}</span>
          <span class="text-xs text-slate-500">${_esc(seg.date)}</span>
          <span class="text-xs font-semibold text-slate-700">${_esc(seg.from)} → ${_esc(seg.to)}</span>
        </div>`;
      }).join('');
    } else flightsSec.classList.add('hidden');

    // Fare
    const fareSec = document.getElementById('prev-fare-section');
    if (state.fareItems.length || total > 0) {
      fareSec.classList.remove('hidden');
      document.getElementById('prev-fare-rows').innerHTML = state.fareItems.map(function(it) { return
        `<tr class="border-b border-slate-100"><td class="px-3 py-1.5 text-xs text-slate-600">${_esc(it.label)}</td><td class="px-3 py-1.5 text-xs font-mono text-right font-semibold">${curr} ${(it.amount||0).toFixed(2)}</td></tr>`;
      }).join('');
      document.getElementById('prev-total').textContent = curr + ' ' + total.toLocaleString('en-CA',{minimumFractionDigits:2});
    } else fareSec.classList.add('hidden');

    // Card
    const cardSec = document.getElementById('prev-card-section');
    if (holder) {
      cardSec.classList.remove('hidden');
      document.getElementById('prev-card-holder').textContent = holder;
      document.getElementById('prev-card-num').textContent = (ctype?ctype+' ':'') + '****';
    } else cardSec.classList.add('hidden');
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// FORM ASSEMBLY & SUBMIT
// ═══════════════════════════════════════════════════════════════════════════
const formAssembly = {
  submit: function() {
    // Final validation on step 4
    const { valid, msg } = wizard.validate(4);
    if (!valid) { wizard.goTo(4); setTimeout(function() { alert('Please fix: ' + msg); }, 100); return; }

    // Passengers JSON
    document.getElementById('hidPassengers').value = JSON.stringify(
      state.passengers.filter(function(p) { return (p.first_name||'').trim() || (p.last_name||'').trim(); }).map(function(p) { return {
        first_name: (p.first_name||'').trim(), last_name: (p.last_name||'').trim(),
        dob: p.dob||'', pax_type: p.pax_type||'adult',
        ticket_number: p.ticket_number||'', frequent_flyer: p.frequent_flyer||''
      }; })
    );

    // Flight data JSON
    let flightData = null;
    const t = state.type;
    
    // Helper to completely weed out empty segment objects
    const filterSegs = function(list) {
      if (!list) return [];
      return list.filter(function(s) { 
        return s.airline_iata && s.from && s.to; 
      });
    };

    if (['new_booking','seat_purchase','cabin_upgrade','name_correction'].includes(t)) {
      flightData = { flights: filterSegs(state.segments.main) };
    } else if (t === 'exchange') {
      flightData = { old_flights: filterSegs(state.segments.old), new_flights: filterSegs(state.segments.new) };
    } else if (['cancel_refund','cancel_credit'].includes(t)) {
      flightData = { flights: filterSegs(state.segments.old) };
    }
    if (t === 'name_correction') {
      flightData = flightData || {};
      var oldNameEl = document.getElementById('nc-old-name');
      var newNameEl = document.getElementById('nc-new-name');
      var reasonEl = document.getElementById('nc-reason');
      flightData.old_name = (oldNameEl ? oldNameEl.value : '').trim().toUpperCase();
      flightData.new_name = (newNameEl ? newNameEl.value : '').trim().toUpperCase();
      flightData.reason   = (reasonEl ? reasonEl.value : '').trim();
    }
    if (t === 'cabin_upgrade') {
      flightData = flightData || {};
      var oldCabinEl = document.getElementById('cu-old-cabin');
      var newCabinEl = document.getElementById('cu-new-cabin');
      flightData.old_cabin = oldCabinEl ? oldCabinEl.value : '';
      flightData.new_cabin = newCabinEl ? newCabinEl.value : '';
    }
    if (t === 'other') {
      var othTitleEl    = document.getElementById('field_other_title');
      var othNotesEl    = document.getElementById('field_other_notes');
      var othRefEl      = document.getElementById('field_other_reference');
      var othProvEl     = document.getElementById('field_other_provider');
      var othPayEl      = document.getElementById('field_other_payment_summary');
      var othDescEl     = document.getElementById('other-desc');
      flightData = {
          other_title:          (othTitleEl    ? othTitleEl.value    : '').trim(),
          other_notes:          (othNotesEl    ? othNotesEl.value    : '').trim(),
          other_reference:      (othRefEl      ? othRefEl.value      : '').trim(),
          other_provider:       (othProvEl     ? othProvEl.value     : '').trim(),
          other_payment_summary:(othPayEl      ? othPayEl.value      : '').trim(),
          other_desc:           (othDescEl     ? othDescEl.value     : '').trim(),
          flights: state.segments.other || []
      };
    }
    document.getElementById('hidFlightData').value = JSON.stringify(flightData);

    // Attach class_of_service and seat_number to type-specific data
    var extraData = flightData ? Object.assign({}, flightData) : {};
    var cosEl = document.getElementById('field_class_of_service');
    var seatEl = document.getElementById('field_seat_number');
    if (cosEl && cosEl.value) extraData.class_of_service = cosEl.value;
    if (seatEl && seatEl.value.trim()) extraData.seat_number = seatEl.value.trim();

    // Type-specific data (includes class_of_service, seat_number)
    document.getElementById('hidTypeData').value = JSON.stringify(extraData);

    // Fare breakdown
    document.getElementById('hidFareBreakdown').value = JSON.stringify(
      state.fareItems.filter(function(it) { return it.label.trim() || it.amount > 0; }).map(function(it) { return { label: it.label.trim(), amount: parseFloat(it.amount)||0 }; })
    );

    // Additional cards
    const validCards = state.extraCards.filter(function(c) { return c.cardholder_name.trim() && c.card_number.trim(); });
    document.getElementById('hidAdditionalCards').value = validCards.length ? JSON.stringify(validCards) : 'null';

    // Lock submit
    const btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Saving...';
    btn.classList.add('opacity-75','cursor-not-allowed');

    // Submit
    document.getElementById('txnForm').submit();
  }
};

// ═══════════════════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
  // Pre-select type if set
  if (state.type) selectType(state.type);

  // Seed prefill passengers
  <?php if (!empty($pre['passengers'])): ?>
  state.passengers = <?= json_encode($pre['passengers']) ?>;
  <?php endif; ?>
  if (state.passengers.length === 0) paxMgr.add(); // always start with one
  paxMgr._render();

  // Seed fare breakdown
  <?php if (!empty($pre['fare_breakdown'])): ?>
  const preFare = <?= json_encode($pre['fare_breakdown']) ?>;
  preFare.forEach(function(f) { fareMgr.addItem(f.label || '', f.amount || 0); });
  <?php else: ?>
  fareMgr.addItem('Base Fare', '');
  <?php endif; ?>

  // Summary listeners
  ['field_pnr','field_customer_name','field_total_amount','field_currency'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncSummary);
  });

  syncSummary();
});
