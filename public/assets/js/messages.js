/* Messages — staff writing to each other inside the CRM.
 * Sends over form-encoded POSTs (the hosting firewall is kinder to those than to JSON),
 * and the open conversation checks for new messages every few seconds. */
(function () {
  'use strict';
  const boot = JSON.parse(document.getElementById('msg-boot').textContent);
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const stamp = (at) => {
    const [d, t] = String(at).split(' ');
    const [, mo, da] = d.split('-').map(Number);
    let [hh, mi] = t.split(':').map(Number);
    const ap = hh < 12 ? 'am' : 'pm';
    hh = hh % 12 || 12;
    return `${da} ${MONTHS[mo - 1]}, ${hh}:${String(mi).padStart(2, '0')} ${ap}`;
  };

  async function post(url, data) {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(data)) {
      if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x)); else body.set(k, v);
    }
    body.set('csrf_token', boot.csrf);
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': boot.csrf }, body, credentials: 'same-origin' });
    let j = null;
    try { j = await r.json(); } catch { /* a login page or firewall page instead of JSON */ }
    if (!j) throw new Error(r.status === 403 ? 'The request was blocked. Please refresh the page and try again.' : 'Could not send. Please check your connection and try again.');
    return j;
  }

  // ---- search the list ----------------------------------------------------------
  const filter = $('#msg-filter');
  if (filter) {
    filter.addEventListener('input', () => {
      const q = filter.value.trim().toLowerCase();
      document.querySelectorAll('.msg-item').forEach((a) => { a.hidden = q !== '' && !a.dataset.search.includes(q); });
    });
  }

  // ---- the open conversation ----------------------------------------------------------
  const thread = $('#msg-thread');
  const scrollDown = () => { if (thread) thread.scrollTop = thread.scrollHeight; };
  scrollDown();
  let last = boot.open ? boot.open.last : 0;
  const render = (m) => `<li class="msg${m.mine ? ' msg--me' : ''}" data-id="${m.id}">
      ${m.mine ? '' : `<span class="msg-av msg-av--sm">${esc(m.initials)}</span>`}
      <div class="msg-bubble"><div class="msg-meta"><b>${m.mine ? 'You' : esc(m.author)}</b><time>${esc(stamp(m.at))}</time></div>
      <div class="msg-body">${esc(m.body)}</div></div></li>`;
  const append = (msgs) => {
    if (!thread || !msgs.length) return;
    const nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120;
    for (const m of msgs) {
      if (thread.querySelector(`[data-id="${m.id}"]`)) continue;
      thread.insertAdjacentHTML('beforeend', render(m));
      last = Math.max(last, m.id);
    }
    if (nearBottom || msgs.some((m) => m.mine)) scrollDown();
  };

  const reply = $('#msg-reply');
  if (reply) {
    const ta = $('#msg-reply-body');
    const err = $('#msg-reply-err');
    const btn = reply.querySelector('button');
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; };
    ta.addEventListener('input', grow);
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); reply.requestSubmit(); } });
    reply.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = ta.value.trim();
      if (!body || btn.disabled) return;
      btn.disabled = true; err.textContent = '';
      try {
        const r = await post(`/messages/${reply.dataset.id}/reply`, { body, after: last });
        if (!r.ok) { err.textContent = r.error; return; }
        ta.value = ''; grow();
        append(r.messages);
      } catch (ex) { err.textContent = ex.message; }
      finally { btn.disabled = false; ta.focus(); }
    });
    ta.focus();
  }

  if (boot.open) {
    const tick = async () => {
      if (document.hidden) return;
      try {
        const r = await fetch(`/messages/${boot.open.id}/poll?after=${last}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const j = await r.json();
        if (j.ok) append(j.messages);
      } catch { /* try again next tick */ }
    };
    setInterval(tick, 6000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
  }

  // ---- a new message ------------------------------------------------------------------
  const compose = $('#msg-compose');
  if (compose) {
    const byId = new Map(boot.colleagues.map((p) => [p.id, p]));
    const picked = [];
    const sel = $('#msg-to');
    const chips = $('#msg-chips');
    const draw = () => {
      chips.innerHTML = picked.map((id) => { const p = byId.get(id); return `<span class="msg-chip"><span class="msg-av">${esc(p.initials)}</span>${esc(p.name)}<button type="button" data-unpick="${id}" aria-label="Remove ${esc(p.name)}"><span class="material-symbols-outlined">close</span></button></span>`; }).join('');
      for (const o of sel.options) if (o.value) o.disabled = picked.includes(Number(o.value));
      sel.disabled = picked.length >= boot.maxTo;
      sel.options[0].textContent = picked.length ? (picked.length >= boot.maxTo ? `Up to ${boot.maxTo} people` : 'Add someone else…') : 'Pick a colleague…';
    };
    if (boot.preTo && byId.has(boot.preTo)) picked.push(boot.preTo);
    draw();
    sel.addEventListener('change', () => { const id = Number(sel.value); if (id && !picked.includes(id)) picked.push(id); sel.value = ''; $('#msg-to-err').textContent = ''; draw(); });
    chips.addEventListener('click', (e) => { const b = e.target.closest('[data-unpick]'); if (!b) return; picked.splice(picked.indexOf(Number(b.dataset.unpick)), 1); draw(); });
    const bodyEl = $('#msg-body');
    bodyEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); compose.requestSubmit(); } });
    compose.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = compose.querySelector('button[type="submit"]');
      $('#msg-to-err').textContent = ''; $('#msg-body-err').textContent = '';
      if (!picked.length) { $('#msg-to-err').textContent = 'Pick at least one colleague from the list.'; sel.focus(); return; }
      if (!bodyEl.value.trim()) { $('#msg-body-err').textContent = 'Write your message.'; bodyEl.focus(); return; }
      btn.disabled = true;
      try {
        const r = await post('/messages', { to: picked, subject: $('#msg-subject').value.trim(), body: bodyEl.value });
        if (r.ok) { location.href = `/messages?c=${r.id}`; return; }
        $(r.field === 'to' ? '#msg-to-err' : '#msg-body-err').textContent = r.error;
      } catch (ex) { $('#msg-body-err').textContent = ex.message; }
      finally { btn.disabled = false; }
    });
    (picked.length ? bodyEl : sel).focus();
  }
})();
