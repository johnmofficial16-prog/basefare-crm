/* Messages — staff writing to each other inside the CRM.
 * Sends over form-encoded POSTs (the hosting firewall is kinder to those than to JSON),
 * multipart only when files are attached, and the open conversation checks for new
 * messages every few seconds. Emoji picker and file tray are shared by all three forms. */
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
  const human = (b) => (b >= 1048576 ? `${Math.round(b / 104857.6) / 10} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

  async function post(url, data, files = []) {
    const headers = { 'X-CSRF-Token': boot.csrf };
    let body;
    if (files.length) {
      body = new FormData();
      for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x)); else body.append(k, v);
      }
      files.forEach((f) => body.append('files[]', f, f.name));
      body.append('csrf_token', boot.csrf);
    } else {
      body = new URLSearchParams();
      for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x)); else body.set(k, v);
      }
      body.set('csrf_token', boot.csrf);
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }
    const r = await fetch(url, { method: 'POST', headers, body, credentials: 'same-origin' });
    let j = null;
    try { j = await r.json(); } catch { /* a login page or firewall page instead of JSON */ }
    if (!j) {
      if (r.status === 413) throw new Error('Those files are too big to send together. Send fewer, or smaller ones.');
      throw new Error(r.status === 403 ? 'The request was blocked. Please refresh the page and try again.' : 'Could not send. Please check your connection and try again.');
    }
    return j;
  }

  // ---- files on a message -----------------------------------------------------------------
  const FILE_ICON = { PDF: 'picture_as_pdf', Word: 'description', Excel: 'table_chart', CSV: 'table_chart', Text: 'article', Image: 'image' };
  const filesHtml = (files) => (files && files.length ? `<div class="msg-files">${files.map((f) => {
    const url = `/messages/file/${f.id}`;
    return f.image
      ? `<a class="msg-pic" href="${url}" target="_blank" rel="noopener" title="${esc(f.name)}"><img src="${url}" alt="${esc(f.name)}" loading="lazy"></a>`
      : `<a class="msg-file" href="${url}" target="_blank" rel="noopener"><span class="material-symbols-outlined">${FILE_ICON[f.kind] || 'draft'}</span><span class="msg-file__t"><b>${esc(f.name)}</b><small>${esc(f.kind + ' · ' + f.size_label)}</small></span></a>`;
  }).join('')}</div>` : '');

  const F = boot.files || { on: false };
  const OK_EXT = new Set(String(F.accept || '').split(',').map((x) => x.replace('.', '').toLowerCase()).filter(Boolean));
  const SHOWABLE = /^image\/(png|jpeg|gif|webp)$/;

  /** The pending files of one form: the paperclip, drag-and-drop and pasted screenshots all land here. */
  function tray(form, errEl) {
    const box = form.querySelector('[data-tray]');
    const input = form.querySelector('[data-files]');
    if (!F.on || !box || !input) return null;
    const list = [];
    const total = () => list.reduce((s, x) => s + x.file.size, 0);
    const draw = () => {
      box.hidden = !list.length;
      box.innerHTML = list.map((x, i) => `<span class="msg-pend">${x.url ? `<img src="${x.url}" alt="">` : '<span class="material-symbols-outlined">draft</span>'}<span class="msg-pend__t"><b>${esc(x.file.name)}</b><small>${human(x.file.size)}</small></span><button type="button" data-unfile="${i}" aria-label="Remove ${esc(x.file.name)}"><span class="material-symbols-outlined">close</span></button></span>`).join('');
    };
    const add = (files) => {
      errEl.textContent = '';
      for (const file of files) {
        const ext = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
        if (!OK_EXT.has(ext)) { errEl.textContent = `"${file.name}" can't be sent. Allowed: PDF, pictures, Word, Excel, CSV and text files.`; continue; }
        if (!file.size) { errEl.textContent = `"${file.name}" is empty.`; continue; }
        if (file.size > F.maxFile) { errEl.textContent = `"${file.name}" is ${human(file.size)}. Up to ${human(F.maxFile)} per file.`; continue; }
        if (list.length >= F.max) { errEl.textContent = `Up to ${F.max} files in one message.`; break; }
        if (total() + file.size > F.maxTotal) { errEl.textContent = `That would be more than ${human(F.maxTotal)} in one message.`; continue; }
        list.push({ file, url: SHOWABLE.test(file.type) ? URL.createObjectURL(file) : null });
      }
      draw();
    };
    input.addEventListener('change', () => { add([...input.files]); input.value = ''; });
    form.querySelectorAll('[data-attach]').forEach((b) => b.addEventListener('click', () => input.click()));
    box.addEventListener('click', (e) => {
      const b = e.target.closest('[data-unfile]');
      if (!b) return;
      const [x] = list.splice(Number(b.dataset.unfile), 1);
      if (x && x.url) URL.revokeObjectURL(x.url);
      draw();
    });
    // A pasted screenshot becomes a file (but pasted text stays text).
    const ta = form.querySelector('textarea');
    ta.addEventListener('paste', (e) => {
      const cd = e.clipboardData;
      const files = cd ? [...cd.files] : [];
      if (!files.length || (cd.getData('text/plain') || '').trim()) return;
      e.preventDefault();
      const d = new Date();
      const tag = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}.${String(d.getMinutes()).padStart(2, '0')}.${String(d.getSeconds()).padStart(2, '0')}`;
      add(files.map((f, i) => (/^image\.\w+$/i.test(f.name) || !f.name
        ? new File([f], `Screenshot ${tag}${files.length > 1 ? ' (' + (i + 1) + ')' : ''}.${(f.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`, { type: f.type })
        : f)));
    });
    return { add, files: () => list.map((x) => x.file), clear: () => { list.forEach((x) => x.url && URL.revokeObjectURL(x.url)); list.length = 0; draw(); } };
  }

  // Drop files anywhere on the right-hand pane.
  let dropTarget = null;
  const pane = $('.msg-pane');
  if (pane && F.on) {
    let depth = 0;
    const hasFiles = (e) => e.dataTransfer && [...e.dataTransfer.types].includes('Files');
    pane.addEventListener('dragenter', (e) => { if (!dropTarget || !hasFiles(e)) return; depth++; pane.classList.add('is-drop'); });
    pane.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; pane.classList.remove('is-drop'); } });
    pane.addEventListener('dragover', (e) => { if (dropTarget && hasFiles(e)) e.preventDefault(); });
    pane.addEventListener('drop', (e) => {
      if (!dropTarget || !hasFiles(e)) return;
      e.preventDefault(); depth = 0; pane.classList.remove('is-drop');
      dropTarget.add([...e.dataTransfer.files]);
    });
  }

  // ---- emoji ------------------------------------------------------------------------------------
  const EMOJI = [
    ['mood', 'Smileys', '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😌 😍 🥰 😘 😋 😛 😜 🤪 😎 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😫 😩 🥺 😢 😭 😤 😠 😡 🤯 😳 😱 😨 😰 😥 😓 🤗 🤔 🤭 🤫 😶 😐 😑 😬 🙄 😯 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🤑 🤠 😈 👻 💀 🤖 🙈 🙉 🙊'],
    ['waving_hand', 'Hands', '👍 👎 👌 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 🫡 🫶 👀 🧠 🙋 🙆 🙅 🤷 🤦 💁 🙇'],
    ['favorite', 'Hearts & signs', '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 💯 ✅ ☑️ ✔️ ❌ ❗ ❓ ⚠️ 🚫 ⛔ 🔥 ⭐ 🌟 ✨ ⚡ 💥 💡 📌 📍 🔔 🔕 ⏰ ⏳ ⌛ 🕐 🆗 🆕 🔝 ➡️ ⬅️ ⬆️ ⬇️ 🔁 🔄 ➕ ➖'],
    ['flight', 'Work & travel', '✈️ 🛫 🛬 🧳 🌍 🌎 🌏 🗺️ 🏨 🏖️ 🏝️ 🗽 🕌 🚕 🚆 🚢 🎫 🛂 💳 💰 💵 💸 🧾 📞 ☎️ 📱 💻 🖥️ 🖨️ 📧 📨 📩 📝 📄 📃 📎 🖇️ 📅 📆 🗓️ 📊 📈 📉 🗂️ 📁 🔍 🔒 🔑 🏢 🎧'],
    ['celebration', 'Food & fun', '🎉 🎊 🎁 🎂 🎈 🏆 🥇 🥈 🥉 🎯 🪔 🎆 🎇 ☕ 🍵 🥤 🍕 🍔 🍟 🌮 🍜 🍛 🍚 🍩 🍪 🍰 🧁 🍫 🍎 🍌 🍉 🌹 🌸 🌻 ☀️ 🌙 🌧️ ❄️ ⚽ 🏏 🎮 🎵 🎶'],
  ].map(([icon, name, list]) => ({ icon, name, list: list.split(' ') }));
  const RECENT_KEY = 'msgEmojiRecent';
  const recent = () => { try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').slice(0, 24); } catch { return []; } };
  let pop = null;
  let popFor = null; // { btn, ta }
  let tab = 'Smileys';
  const closePicker = () => { if (pop) { pop.hidden = true; } if (popFor) popFor.btn.setAttribute('aria-expanded', 'false'); popFor = null; };
  function drawPicker() {
    const r = recent();
    const tabs = (r.length ? [{ icon: 'schedule', name: 'Recent', list: r }] : []).concat(EMOJI);
    const cur = tabs.find((t) => t.name === tab) || tabs[0];
    tab = cur.name;
    pop.innerHTML = `<div class="msg-emoji__tabs" role="tablist">${tabs.map((t) => `<button type="button" role="tab" aria-selected="${t === cur}" data-tab="${esc(t.name)}" title="${esc(t.name)}" aria-label="${esc(t.name)}"><span class="material-symbols-outlined">${t.icon}</span></button>`).join('')}</div>
      <div class="msg-emoji__name">${esc(cur.name)}</div>
      <div class="msg-emoji__grid">${cur.list.map((e) => `<button type="button" data-e="${e}" aria-label="${e}">${e}</button>`).join('')}</div>`;
  }
  function openPicker(btn, ta) {
    if (popFor && popFor.btn === btn && !pop.hidden) { closePicker(); return; }
    if (!pop) {
      pop = document.createElement('div');
      pop.className = 'msg-emoji';
      pop.setAttribute('role', 'dialog');
      pop.setAttribute('aria-label', 'Emoji');
      document.body.appendChild(pop);
      pop.addEventListener('mousedown', (e) => e.preventDefault()); // keep the cursor in the text box
      pop.addEventListener('click', (e) => {
        const t = e.target.closest('[data-tab]');
        if (t) { tab = t.dataset.tab; drawPicker(); return; }
        const b = e.target.closest('[data-e]');
        if (!b || !popFor) return;
        const em = b.dataset.e;
        const area = popFor.ta;
        area.focus();
        area.setRangeText(em, area.selectionStart, area.selectionEnd, 'end');
        area.dispatchEvent(new Event('input', { bubbles: true }));
        try { localStorage.setItem(RECENT_KEY, JSON.stringify([em].concat(recent().filter((x) => x !== em)).slice(0, 24))); } catch { /* private mode */ }
      });
    }
    if (recent().length) tab = 'Recent';
    drawPicker();
    pop.hidden = false;
    popFor = { btn, ta };
    btn.setAttribute('aria-expanded', 'true');
    // Above the reply bar (it sits at the bottom); below the button in the longer forms, so it never covers the text.
    const bar = btn.closest('.msg-reply');
    const r = btn.getBoundingClientRect();
    const w = pop.offsetWidth, h = pop.offsetHeight;
    const above = (bar ? bar.getBoundingClientRect().top : r.top) - h - 6;
    const below = r.bottom + 6;
    const top = bar ? (above >= 8 ? above : below)
      : (below + h <= window.innerHeight - 8 ? below : above >= 8 ? above : window.innerHeight - h - 8);
    pop.style.top = `${Math.max(8, top)}px`;
    pop.style.left = `${Math.min(Math.max(8, r.left), window.innerWidth - w - 8)}px`;
  }
  document.addEventListener('mousedown', (e) => {
    if (popFor && !pop.contains(e.target) && !popFor.btn.contains(e.target)) closePicker();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && popFor) { const ta = popFor.ta; closePicker(); ta.focus(); } });
  window.addEventListener('resize', closePicker);
  const emojiFor = (form, ta) => form.querySelectorAll('[data-emoji]').forEach((b) => {
    b.setAttribute('aria-haspopup', 'dialog');
    b.addEventListener('mousedown', (e) => e.preventDefault());
    b.addEventListener('click', () => openPicker(b, ta));
  });

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
  // Pictures load after the first scroll; keep the newest message in view.
  if (thread) thread.querySelectorAll('.msg-pic img').forEach((img) => img.addEventListener('load', () => { if (thread.scrollHeight - thread.scrollTop - thread.clientHeight < 400) scrollDown(); }, { once: true }));
  let last = boot.open ? boot.open.last : 0;
  const render = (m) => `<li class="msg${m.mine ? ' msg--me' : ''}" data-id="${m.id}">
      ${m.mine ? '' : `<span class="msg-av msg-av--sm">${esc(m.initials)}</span>`}
      <div class="msg-bubble"><div class="msg-meta"><b>${m.mine ? 'You' : esc(m.author)}</b><time>${esc(stamp(m.at))}</time></div>
      ${m.body ? `<div class="msg-body">${esc(m.body)}</div>` : ''}${filesHtml(m.files)}</div></li>`;
  const append = (msgs) => {
    if (!thread || !msgs.length) return;
    const nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120;
    for (const m of msgs) {
      if (thread.querySelector(`[data-id="${m.id}"]`)) continue;
      thread.insertAdjacentHTML('beforeend', render(m));
      thread.lastElementChild.querySelectorAll('.msg-pic img').forEach((img) => img.addEventListener('load', scrollDown, { once: true }));
      last = Math.max(last, m.id);
    }
    if (nearBottom || msgs.some((m) => m.mine)) scrollDown();
  };

  const reply = $('#msg-reply');
  if (reply) {
    const ta = $('#msg-reply-body');
    const err = $('#msg-reply-err');
    const btn = reply.querySelector('button[type="submit"]');
    const files = tray(reply, err);
    dropTarget = files;
    emojiFor(reply, ta);
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; };
    ta.addEventListener('input', grow);
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); reply.requestSubmit(); } });
    reply.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = ta.value.trim();
      const picked = files ? files.files() : [];
      if ((!body && !picked.length) || btn.disabled) return;
      btn.disabled = true; err.textContent = '';
      reply.classList.toggle('is-sending', picked.length > 0);
      try {
        const r = await post(`/messages/${reply.dataset.id}/reply`, { body, after: last }, picked);
        if (!r.ok) { err.textContent = r.error; return; }
        ta.value = ''; grow();
        if (files) files.clear();
        append(r.messages);
      } catch (ex) { err.textContent = ex.message; }
      finally { btn.disabled = false; reply.classList.remove('is-sending'); ta.focus(); }
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

  // ---- a broadcast (admins) ---------------------------------------------------------------
  const bc = $('#msg-broadcast');
  if (bc) {
    const boxes = () => [...bc.querySelectorAll('input[name="groups"]')];
    const all = bc.querySelector('input[value="everyone"]');
    const files = tray(bc, $('#msg-bbody-err'));
    dropTarget = files;
    emojiFor(bc, $('#msg-bbody'));
    const label = () => {
      const picked = boxes().filter((b) => b.checked && b.value !== 'everyone');
      $('#msg-bsend').textContent = all.checked || !picked.length ? 'Send to everyone'
        : 'Send to ' + picked.map((b) => b.parentElement.textContent.replace(/\s*\d+\s*$/, '').trim()).join(', ');
    };
    bc.addEventListener('change', (e) => {
      const b = e.target.closest('input[name="groups"]');
      if (!b) return;
      if (b === all && all.checked) boxes().forEach((x) => { if (x !== all) x.checked = false; });
      if (b !== all && b.checked) all.checked = false;
      if (!boxes().some((x) => x.checked)) all.checked = true;
      $('#msg-groups-err').textContent = '';
      label();
    });
    label();
    bc.addEventListener('submit', async (e) => {
      e.preventDefault();
      ['#msg-groups-err', '#msg-bsubject-err', '#msg-bbody-err'].forEach((s) => { $(s).textContent = ''; });
      const subject = $('#msg-bsubject').value.trim();
      const body = $('#msg-bbody').value;
      const picked = files ? files.files() : [];
      if (!subject) { $('#msg-bsubject-err').textContent = 'Give it a subject.'; $('#msg-bsubject').focus(); return; }
      if (!body.trim() && !picked.length) { $('#msg-bbody-err').textContent = 'Write the message.'; $('#msg-bbody').focus(); return; }
      const groups = boxes().filter((b) => b.checked).map((b) => b.value);
      const btn = bc.querySelector('button[type="submit"]');
      if (!confirm(`${$('#msg-bsend').textContent}?\n\nEveryone it goes to gets it in Messages and a bell notice.`)) return;
      btn.disabled = true;
      try {
        const r = await post('/messages/broadcast', { groups, subject, body }, picked);
        if (r.ok) { location.href = `/messages?c=${r.id}`; return; }
        $(r.field === 'to' ? '#msg-groups-err' : r.field === 'subject' ? '#msg-bsubject-err' : '#msg-bbody-err').textContent = r.error;
      } catch (ex) { $('#msg-bbody-err').textContent = ex.message; }
      finally { btn.disabled = false; }
    });
    $('#msg-bsubject').focus();
  }

  // ---- a new message ------------------------------------------------------------------
  const compose = $('#msg-compose');
  if (compose) {
    const byId = new Map(boot.colleagues.map((p) => [p.id, p]));
    const picked = [];
    const sel = $('#msg-to');
    const chips = $('#msg-chips');
    const bodyEl = $('#msg-body');
    const files = tray(compose, $('#msg-body-err'));
    dropTarget = files;
    emojiFor(compose, bodyEl);
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
    bodyEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); compose.requestSubmit(); } });
    compose.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = compose.querySelector('button[type="submit"]');
      const attached = files ? files.files() : [];
      $('#msg-to-err').textContent = ''; $('#msg-body-err').textContent = '';
      if (!picked.length) { $('#msg-to-err').textContent = 'Pick at least one colleague from the list.'; sel.focus(); return; }
      if (!bodyEl.value.trim() && !attached.length) { $('#msg-body-err').textContent = 'Write your message.'; bodyEl.focus(); return; }
      btn.disabled = true;
      try {
        const r = await post('/messages', { to: picked, subject: $('#msg-subject').value.trim(), body: bodyEl.value }, attached);
        if (r.ok) { location.href = `/messages?c=${r.id}`; return; }
        $(r.field === 'to' ? '#msg-to-err' : '#msg-body-err').textContent = r.error;
      } catch (ex) { $('#msg-body-err').textContent = ex.message; }
      finally { btn.disabled = false; }
    });
    (picked.length ? bodyEl : sel).focus();
  }
})();
