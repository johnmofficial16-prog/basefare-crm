/* Notepad — the signed-in user's own notes, in a slide-in panel on every CRM page.
 * Open: the sidebar's "Notepad" item (href="#notepad") or Alt+N. Close: Esc or ×.
 * Notes save as you type (form-encoded POSTs, which the hosting firewall lets through). */
(function () {
  'use strict';
  const root = document.getElementById('np');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  const csrf = root.dataset.csrf;
  const $ = (s) => root.querySelector(s);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const when = (at) => {
    const [d, t] = String(at).split(' ');
    const [y, mo, da] = d.split('-').map(Number);
    let [hh, mi] = t.split(':').map(Number);
    const ap = hh < 12 ? 'am' : 'pm';
    hh = hh % 12 || 12;
    const now = new Date();
    const today = now.getFullYear() === y && now.getMonth() + 1 === mo && now.getDate() === da;
    return (today ? 'Today' : `${da} ${MONTHS[mo - 1]}`) + `, ${hh}:${String(mi).padStart(2, '0')} ${ap}`;
  };

  async function api(url, data) {
    const opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (data) {
      const body = new URLSearchParams(data);
      body.set('csrf_token', csrf);
      Object.assign(opts, { method: 'POST', body, headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf } });
    }
    const r = await fetch(url, opts);
    let j = null;
    try { j = await r.json(); } catch { /* signed out, or a firewall page */ }
    if (!j) throw new Error(r.status === 403 ? 'Blocked. Refresh the page and try again.' : 'Not saved. Check your connection.');
    if (!j.ok) throw new Error(j.error || 'Not saved.');
    return j;
  }

  let notes = [];
  let current = null;      // the open note
  let dirty = false;
  let saveTimer = null;
  const status = (text, bad = false) => { const s = $('#np-status'); s.textContent = text; s.classList.toggle('is-bad', bad); };

  function drawList() {
    const q = $('#np-q').value.trim().toLowerCase();
    const shown = notes.filter((n) => !q || (n.title + ' ' + n.preview).toLowerCase().includes(q));
    $('#np-items').innerHTML = shown.length ? shown.map((n) => `<button type="button" class="np-item" data-id="${n.id}">
        <b>${n.pinned ? '<span class="material-symbols-outlined">push_pin</span>' : ''}${esc(n.title)}</b>
        ${n.preview && n.preview !== n.title ? `<span>${esc(n.preview)}</span>` : ''}<time>${esc(when(n.updated_at))}</time></button>`).join('')
      : `<div class="np-empty"><span class="material-symbols-outlined">edit_note</span><b>${q ? 'Nothing matches' : 'No notes yet'}</b>${q ? '' : 'Jot down PNRs, call-backs, anything. Only you can see your notes.'}</div>`;
  }

  async function loadList() {
    try { notes = (await api('/api/notes')).notes; } catch (e) { notes = []; }
    drawList();
  }

  function showList() {
    flush();
    current = null;
    $('#np-edit').hidden = true;
    $('#np-list').hidden = false;
    $('#np-back').hidden = true;
    loadList();
    $('#np-q').focus();
  }

  function showNote(n) {
    current = n;
    dirty = false;
    $('#np-list').hidden = true;
    $('#np-edit').hidden = false;
    $('#np-back').hidden = false;
    $('#np-t').value = n.own_title || '';
    $('#np-b').value = n.body || '';
    $('#np-pin').setAttribute('aria-pressed', String(!!n.pinned));
    status(n.updated_at ? 'Saved ' + when(n.updated_at) : '');
    $('#np-b').focus();
  }

  async function openNote(id) {
    try { showNote((await api(`/api/notes/${id}`)).note); } catch (e) { status(e.message, true); }
  }

  async function newNote() {
    flush();
    try { showNote((await api('/api/notes', { title: '', body: '' })).note); } catch (e) { alert(e.message); }
  }

  async function save() {
    if (!current || !dirty) return;
    dirty = false;
    const id = current.id;
    status('Saving…');
    try {
      const r = await api(`/api/notes/${id}`, { title: $('#np-t').value, body: $('#np-b').value });
      if (current && current.id === id) status('Saved ' + when(r.note.updated_at));
    } catch (e) {
      dirty = true; // keep trying on the next change, and tell them why
      status(e.message, true);
    }
  }
  function flush() { clearTimeout(saveTimer); if (dirty) save(); }
  const changed = () => { dirty = true; status('Typing…'); clearTimeout(saveTimer); saveTimer = setTimeout(save, 700); };

  function open() {
    root.hidden = false;
    document.querySelectorAll('a[href="#notepad"]').forEach((a) => a.setAttribute('aria-expanded', 'true'));
    if (current) $('#np-b').focus(); else showList();
  }
  function close() {
    flush();
    root.hidden = true;
    document.querySelectorAll('a[href="#notepad"]').forEach((a) => a.setAttribute('aria-expanded', 'false'));
  }

  $('#np-t').addEventListener('input', changed);
  $('#np-b').addEventListener('input', changed);
  $('#np-q').addEventListener('input', drawList);
  $('#np-items').addEventListener('click', (e) => { const b = e.target.closest('[data-id]'); if (b) openNote(b.dataset.id); });
  $('#np-new').addEventListener('click', newNote);
  $('#np-back').addEventListener('click', showList);
  $('#np-close').addEventListener('click', close);
  $('#np-pin').addEventListener('click', async () => {
    if (!current) return;
    const on = $('#np-pin').getAttribute('aria-pressed') !== 'true';
    try { await api(`/api/notes/${current.id}`, { pinned: on ? '1' : '0' }); $('#np-pin').setAttribute('aria-pressed', String(on)); current.pinned = on; status(on ? 'Pinned to the top' : 'Unpinned'); }
    catch (e) { status(e.message, true); }
  });
  $('#np-del').addEventListener('click', async () => {
    if (!current || !confirm('Delete this note?')) return;
    try { clearTimeout(saveTimer); dirty = false; await api(`/api/notes/${current.id}/delete`, {}); showList(); }
    catch (e) { status(e.message, true); }
  });

  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href="#notepad"]');
    if (!a) return;
    e.preventDefault();
    if (root.hidden) open(); else close();
  });
  document.addEventListener('keydown', (e) => {
    if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'n' || e.key === 'N')) { e.preventDefault(); if (root.hidden) open(); else close(); }
    else if (e.key === 'Escape' && !root.hidden) close();
  });
  window.addEventListener('beforeunload', flush);
  document.addEventListener('visibilitychange', () => { if (document.hidden) flush(); });
})();
