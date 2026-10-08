/**
 * Fare-terms picker — fare type + fill-in blanks → Fare Rules, Endorsements,
 * policy refund clause and the customer's refund checkbox, all together.
 *
 * Mirrors App\Services\FareTermsService::render(). The browser render is the
 * live preview; the server re-renders on save, so what is stored never depends
 * on this file (except when a manager/admin unlocks manual edit).
 *
 * Usage:
 *   const ft = FareTerms.mount({
 *     root: document.getElementById('fare-terms-picker'),
 *     config: <?= json_encode(FareTermsService::clientConfig()) ?>,
 *     policy: 'acceptance' | 'eticket',
 *     canEdit: true|false,                 // manager/admin may unlock manual edit
 *     initial: { fare_type, values },      // optional
 *     getCurrency: () => 'USD',
 *     fields: { fareRules, endorsements, policy },  // textareas/inputs to fill
 *     policyTransform: text => text           // optional, e.g. reissuance voucher clause 8
 *   });
 *   ft.setCabin('Business');               // auto-select unless the agent picked
 *   ft.missing();                          // → ['Cancellation penalty (per pax)', ...]
 */
(function (global) {
  'use strict';

  var RE = /\{\{\s*([a-z0-9_]+)\s*(?::([^}]*))?\}\}/gi;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fill(text, values) {
    return String(text).replace(RE, function (_, key) {
      key = key.toLowerCase();
      return Object.prototype.hasOwnProperty.call(values, key) && values[key] !== '' ? values[key] : '____';
    });
  }

  function cleanValue(blank, raw) {
    var v = String(raw == null ? '' : raw).replace(/\s+/g, ' ').trim().slice(0, 80).replace(/[{}]/g, '');
    if (blank.type === 'select') return blank.options.indexOf(v) >= 0 ? v : '';
    if (blank.type === 'number') {
      var n = v.replace(/,/g, '');
      if (n === '' || isNaN(Number(n)) || Number(n) < 0) return '';
      if (/_(hours|days)$/.test(blank.key)) return String(parseInt(n, 10));
      return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    return v;
  }

  function render(config, slug, rawValues, currency) {
    var tpl = config.templates[slug] || config.templates.non_refundable;
    var values = {};
    (tpl.blanks || []).forEach(function (b) {
      var v = cleanValue(b, rawValues[b.key]);
      if (v !== '') values[b.key] = v;
    });
    values.currency = String(currency || 'USD').toUpperCase();
    var out = {};
    ['fare_rules', 'endorsements', 'refund_clause', 'checkbox', 'eticket_ack'].forEach(function (p) {
      out[p] = fill(tpl[p], values);
    });
    out.acceptance_policy = config.acceptancePolicy.replace('{{refund_clause}}', out.refund_clause);
    out.eticket_policy = config.eticketPolicy.replace('{{refund_clause}}', out.refund_clause);
    return out;
  }

  var ICONS = {
    non_refundable: 'block',
    refundable_penalty: 'currency_exchange',
    fully_refundable: 'verified',
    custom: 'edit_note'
  };

  function mount(opts) {
    var config = opts.config;
    var root = opts.root;
    var policyKind = opts.policy === 'eticket' ? 'eticket' : 'acceptance';
    var fields = opts.fields || {};
    var init = opts.initial || {};

    var state = {
      slug: config.templates[init.fare_type] ? init.fare_type : 'non_refundable',
      values: Object.assign({}, init.values || {}),
      userPicked: !!(init.fare_type && config.templates[init.fare_type]),
      manual: false,
      cabin: null
    };

    root.innerHTML =
      '<input type="hidden" name="fare_type" data-ft="type">' +
      '<input type="hidden" name="fare_terms_values" data-ft="values">' +
      '<input type="hidden" name="terms_manual" value="0" data-ft="manual">' +
      '<div class="flex items-center justify-between gap-2 mb-2">' +
      '  <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider">Fare Type / Refundability <span class="text-rose-500">*</span></label>' +
      '  <span data-ft="cabin-note" class="text-[10px] text-slate-400"></span>' +
      '</div>' +
      '<div data-ft="cards" class="grid grid-cols-2 lg:grid-cols-4 gap-2"></div>' +
      '<div data-ft="blanks" class="mt-3"></div>' +
      '<div data-ft="preview" class="mt-3"></div>' +
      (opts.canEdit
        ? '<label class="mt-2 inline-flex items-center gap-2 text-[11px] text-slate-500 cursor-pointer select-none">' +
          '<input type="checkbox" data-ft="manual-toggle" class="w-3.5 h-3.5 accent-amber-500"> ' +
          'Edit Fare Rules / Endorsements / Policy text manually (manager &amp; admin only)</label>'
        : '');

    var $ = function (k) { return root.querySelector('[data-ft="' + k + '"]'); };

    function drawCards() {
      $('cards').innerHTML = config.types.map(function (slug) {
        var t = config.templates[slug];
        var on = slug === state.slug;
        return '<button type="button" data-slug="' + slug + '" class="text-left p-2.5 rounded-lg border transition-colors ' +
          (on ? 'border-primary-600 bg-primary-50 ring-2 ring-primary-600/30' : 'border-slate-200 bg-white hover:bg-slate-50') + '">' +
          '<div class="flex items-center gap-1.5 text-xs font-bold ' + (on ? 'text-primary-600' : 'text-slate-700') + '">' +
          '<span class="material-symbols-outlined text-sm">' + (ICONS[slug] || 'description') + '</span>' + esc(t.label) + '</div>' +
          '<div class="text-[10px] text-slate-500 mt-0.5 leading-snug">' + esc(t.hint || '') + '</div></button>';
      }).join('');
      Array.prototype.forEach.call($('cards').querySelectorAll('button'), function (btn) {
        btn.addEventListener('click', function () {
          state.userPicked = true;
          select(btn.getAttribute('data-slug'));
        });
      });
    }

    function drawBlanks() {
      var blanks = config.templates[state.slug].blanks || [];
      if (!blanks.length) { $('blanks').innerHTML = ''; return; }
      $('blanks').innerHTML =
        '<div class="p-3 rounded-lg border border-indigo-200 bg-indigo-50/50">' +
        '<div class="text-[10px] font-bold text-indigo-700 uppercase tracking-wider mb-2">Fill in the blanks</div>' +
        '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">' +
        blanks.map(function (b) {
          var v = state.values[b.key] != null ? state.values[b.key] : '';
          var input;
          if (b.type === 'select') {
            input = '<select data-blank="' + b.key + '" class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">' +
              '<option value="">— choose —</option>' +
              b.options.map(function (o) { return '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') +
              '</select>';
          } else {
            var isNum = b.type === 'number';
            var isMoney = isNum && !/_(hours|days)$/.test(b.key);
            input = '<div class="flex items-center gap-1">' +
              (isMoney ? '<span data-ft="cur" class="text-[10px] font-bold text-slate-400">' + esc(currency()) + '</span>' : '') +
              '<input data-blank="' + b.key + '" type="' + (isNum ? 'number' : 'text') + '"' + (isNum ? ' min="0" step="' + (isMoney ? '0.01' : '1') + '"' : '') +
              ' value="' + esc(String(v).replace(/,/g, '')) + '" class="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400"></div>';
          }
          return '<label class="block"><span class="block text-[10px] font-semibold text-slate-600 mb-1">' + esc(b.label) + ' <span class="text-rose-500">*</span></span>' + input + '</label>';
        }).join('') +
        '</div></div>';
      Array.prototype.forEach.call($('blanks').querySelectorAll('[data-blank]'), function (el) {
        var ev = el.tagName === 'SELECT' ? 'change' : 'input';
        el.addEventListener(ev, function () {
          state.values[el.getAttribute('data-blank')] = el.value;
          apply();
        });
      });
    }

    function currency() { return (opts.getCurrency && opts.getCurrency()) || 'USD'; }

    function apply() {
      var out = render(config, state.slug, state.values, currency());
      $('type').value = state.slug;
      $('values').value = JSON.stringify(state.values);
      $('manual').value = state.manual ? '1' : '0';

      if (!state.manual) {
        if (fields.fareRules) fields.fareRules.value = out.fare_rules;
        if (fields.endorsements) fields.endorsements.value = out.endorsements;
        if (fields.policy) {
          var pol = policyKind === 'eticket' ? out.eticket_policy : out.acceptance_policy;
          fields.policy.value = opts.policyTransform ? opts.policyTransform(pol) : pol;
        }
      }
      [fields.fareRules, fields.endorsements, fields.policy].forEach(function (f) {
        if (!f) return;
        f.readOnly = !state.manual;
        f.classList.toggle('cursor-not-allowed', !state.manual);
        f.classList.toggle('text-slate-500', !state.manual);
      });

      var ack = policyKind === 'eticket'
        ? 'I acknowledge that this e-ticket is <strong>' + esc(out.eticket_ack) + '</strong>.'
        : esc(out.checkbox);
      $('preview').innerHTML =
        '<div class="p-3 rounded-lg border border-amber-200 bg-amber-50/60">' +
        '<div class="text-[10px] font-bold text-amber-700 uppercase tracking-wider mb-1">Customer will tick</div>' +
        '<div class="text-xs text-slate-700 flex gap-2"><span class="material-symbols-outlined text-sm text-amber-600">check_box</span><span>' + ack + '</span></div></div>';

      Array.prototype.forEach.call(root.querySelectorAll('[data-ft="cur"]'), function (el) { el.textContent = currency(); });
      if (opts.onChange) opts.onChange(state.slug);
    }

    function select(slug) {
      if (!config.templates[slug]) return;
      state.slug = slug;
      drawCards();
      drawBlanks();
      apply();
    }

    function setCabin(cabin) {
      state.cabin = cabin || null;
      var note = $('cabin-note');
      if (cabin && config.cabinMap[cabin]) {
        var def = config.cabinMap[cabin];
        note.textContent = cabin + ' → default ' + config.templates[def].label + (state.userPicked && def !== state.slug ? ' (you changed it)' : '');
        if (!state.userPicked && def !== state.slug) select(def);
      } else {
        note.textContent = '';
      }
    }

    function missing() {
      var blanks = config.templates[state.slug].blanks || [];
      return blanks.filter(function (b) { return cleanValue(b, state.values[b.key]) === ''; })
                   .map(function (b) { return b.label; });
    }

    var toggle = $('manual-toggle');
    if (toggle) {
      toggle.addEventListener('change', function () {
        state.manual = toggle.checked;
        apply();
      });
    }

    // Keep blanks' currency label and the rendered text in step with the form
    select(state.slug);

    return {
      setCabin: setCabin,
      missing: missing,
      refresh: apply,
      setType: function (slug, values) {
        state.userPicked = !!slug;
        if (values) state.values = Object.assign({}, values);
        select(slug || 'non_refundable');
      },
      get type() { return state.slug; }
    };
  }

  /** Highest cabin among segments — a Business long-haul with an Economy feeder is a Business fare. */
  function highestCabin(cabins) {
    var rank = { 'Economy': 1, 'Premium Economy': 2, 'Business': 3, 'First': 4 };
    var best = null;
    (cabins || []).forEach(function (c) {
      if (rank[c] && (!best || rank[c] > rank[best])) best = c;
    });
    return best;
  }

  global.FareTerms = { mount: mount, render: render, highestCabin: highestCabin };
})(window);
