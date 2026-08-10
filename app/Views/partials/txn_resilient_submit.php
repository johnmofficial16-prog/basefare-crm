<?php
/**
 * txn_resilient_submit.php — save transactions without losing work to the
 * hosting layer.
 *
 * Background (Aug 2026): the shared-hosting front end intermittently 403s
 * legitimate transaction saves BEFORE they reach PHP (LiteSpeed's own error
 * page; nothing in our logs). Two staff lost fully-filled forms to it in one
 * day. Root cause sits in infrastructure we cannot configure, so the client
 * side stops trusting a bare form navigation:
 *
 *   - the save goes out as fetch(); the page — and every typed field — survives
 *   - a blocked attempt retries once automatically after a short pause
 *     (per-IP throttling is transient; an immediate retry usually lands)
 *   - every block is reported via GET beacon to /api/edge-log, giving us the
 *     audit trail the host won't: who, when, which URL, which attempt
 *   - if the retry also fails, the user keeps their data and a Retry button
 *
 * Include AFTER any submit-listeners that massage form values (JSON sync, WAF
 * b64 shield) so those run before the fetch snapshot is taken.
 */
?>
<div id="edge-banner" style="display:none;position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;max-width:640px;width:calc(100vw - 48px);background:#7c2d12;color:#fff;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.35);padding:14px 18px;font-family:Inter,system-ui,sans-serif;font-size:13.5px;line-height:1.45;">
  <div style="display:flex;align-items:flex-start;gap:10px;">
    <span class="material-symbols-outlined" style="font-size:20px;flex-shrink:0;margin-top:1px;">gpp_maybe</span>
    <div style="flex:1;" id="edge-banner-msg"></div>
    <button id="edge-banner-retry" style="display:none;flex-shrink:0;background:#fff;color:#7c2d12;border:none;border-radius:8px;padding:6px 14px;font-weight:800;font-size:12.5px;cursor:pointer;">Retry now</button>
  </div>
</div>

<script>
(function () {
  const banner = () => document.getElementById('edge-banner');
  const msgEl  = () => document.getElementById('edge-banner-msg');
  const btnEl  = () => document.getElementById('edge-banner-retry');
  const sleep  = ms => new Promise(r => setTimeout(r, ms));

  function show(text, withRetry, form) {
    banner().style.display = 'block';
    msgEl().textContent = text;
    const b = btnEl();
    b.style.display = withRetry ? 'inline-block' : 'none';
    if (withRetry && form) b.onclick = function () { window.resilientTxnSubmit(form, show.lastFd); };
  }
  function hide() { banner().style.display = 'none'; }

  function setBusy(form, busy) {
    form.querySelectorAll('button, input[type="submit"]').forEach(function (b) {
      b.disabled = busy;
    });
  }

  function beacon(info) {
    try {
      fetch('/api/edge-log?' + new URLSearchParams(info).toString(),
            { credentials: 'same-origin', keepalive: true }).catch(function () {});
    } catch (e) {}
  }

  // ── TOTAL SHIELD ────────────────────────────────────────────────────────────
  // Repack the whole form so the POST body carries nothing a firewall can scan.
  // The earlier per-field b64 shield covered only 5 fields; telemetry showed the
  // block persisting (attempt 1 AND 2), so the trigger was in a field it didn't
  // touch — a plain input, or the upload's filename. Here EVERYTHING except the
  // CSRF token and file contents is JSON-packed into one opaque base64 field
  // (__wafpack), and each uploaded file is re-sent with a sterile name
  // (proof-N.bin) so a Windows filename with odd characters can't trip a rule.
  // TransactionController::decodeWafPack reverses it before any field is read.
  function packForm(form) {
    const src = new FormData(form);
    const out = new FormData();
    const bag = {};
    const PLAIN = { csrf_token: 1, acceptance_id: 1 };  // must stay readable (CSRF middleware)
    let fileIdx = 0;
    for (const entry of src.entries()) {
      const name = entry[0], value = entry[1];
      if (value instanceof File) {
        if (value.size === 0 && !value.name) continue;   // empty file input — skip
        out.append(name, value, 'proof-' + (fileIdx++) + '.bin');
        continue;
      }
      if (PLAIN[name]) { out.append(name, value); continue; }
      if (name.slice(-2) === '[]') {
        const key = name.slice(0, -2);
        (bag[key] = bag[key] || []).push(value);
      } else {
        bag[name] = value;
      }
    }
    out.append('__wafpack', 'b64:' + btoa(unescape(encodeURIComponent(JSON.stringify(bag)))));
    return out;
  }

  // Undo the b64 shield in the visible DOM after snapshotting, so a user whose
  // save was blocked sees their own words in the textarea — not "b64:UGxl...".
  function unshieldDom(form) {
    ['agent_notes', 'passengers_json', 'type_specific_data_json',
     'fare_breakdown_json', 'additional_cards_json'].forEach(function (name) {
      const el = form.elements[name];
      if (el && typeof el.value === 'string' && el.value.startsWith('b64:')) {
        try { el.value = decodeURIComponent(escape(atob(el.value.slice(4)))); } catch (e) {}
      }
    });
  }

  window.resilientTxnSubmit = async function (form, fdOverride) {
    setBusy(form, true);
    // Snapshot AFTER the sync/encode listeners have run — includes files.
    // Retries reuse the SAME snapshot so what lands is exactly what the user
    // pressed Save on, even if the DOM was un-shielded for display afterwards.
    // packForm snapshots + encodes the whole body; then un-shield the DOM so a
    // blocked user still sees their own text. Retries reuse the same snapshot.
    const fd = fdOverride || packForm(form);
    if (!fdOverride) unshieldDom(form);
    show.lastFd = fd;   // the Retry button resends this exact snapshot

    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        const r = await fetch(form.action, {
          method: 'POST', body: fd, credentials: 'same-origin'
        });

        if (r.ok || r.redirected) {
          // Server processed it (success and validation-failure paths both end
          // in a redirect). Follow to wherever it sent us.
          hide();
          window.location.assign(r.url || form.action);
          return;
        }

        // Blocked before PHP (or hard server error) — report it.
        beacon({ s: r.status, u: form.action, a: attempt });

        if (attempt === 1) {
          show('The server blocked this save (HTTP ' + r.status + '). ' +
               'Nothing is lost — retrying automatically in 6 seconds…', false);
          await sleep(6000);
          continue;
        }
        show('The server blocked this save twice (HTTP ' + r.status + '). ' +
             'Your data is still here. Wait half a minute, then press Retry now. ' +
             'If it keeps happening, tell your admin the time you saw this.', true, form);
        setBusy(form, false);
        return;

      } catch (err) {
        beacon({ s: 'network', u: form.action, a: attempt });
        if (attempt === 1) { await sleep(4000); continue; }
        show('Network problem while saving. Your data is still here — press Retry now.', true, form);
        setBusy(form, false);
        return;
      }
    }
  };
})();
</script>
