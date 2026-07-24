<?php
/**
 * Notification Bell — shared across all sidebars.
 *
 * A fixed top-right bell with an unread badge + dropdown of recent
 * notifications. Polls GET /api/notifications (which also lazily dispatches
 * due booking reminders). Self-contained: Tailwind + Material Symbols are
 * already loaded by each page.
 *
 * Include once per page (the role sidebars do this):
 *   require __DIR__ . '/notification_bell.php';
 */
$__bellCsrf = $_SESSION['csrf_token'] ?? '';
?>
<div id="notifBell" class="fixed top-4 right-6 z-40">
  <button id="notifBellBtn" type="button" aria-label="Notifications"
    class="relative flex items-center justify-center w-11 h-11 rounded-full bg-white border border-gray-200 shadow-sm hover:shadow-md hover:border-primary/40 transition-all">
    <span class="material-symbols-outlined text-primary text-[22px]">notifications</span>
    <span id="notifBadge"
      class="hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center">0</span>
  </button>

  <!-- Dropdown -->
  <div id="notifPanel"
    class="hidden absolute right-0 mt-2 w-80 max-h-[70vh] bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden flex flex-col">
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-slate-50/60">
      <span class="text-sm font-bold text-slate-800" style="font-family:Manrope">Notifications</span>
      <button id="notifMarkAll" type="button" class="text-xs font-semibold text-primary hover:underline">Mark all read</button>
    </div>
    <div id="notifList" class="flex-1 overflow-y-auto divide-y divide-gray-50">
      <div class="px-4 py-8 text-center text-xs text-slate-400">Loading…</div>
    </div>
    <a href="/notifications" class="block px-4 py-2.5 text-center text-xs font-semibold text-primary border-t border-gray-100 hover:bg-slate-50">
      View all
    </a>
  </div>
</div>

<script>
(function () {
  if (window.__notifBellInit) return;      // guard against double-include
  window.__notifBellInit = true;

  var CSRF = <?= json_encode($__bellCsrf) ?>;
  var btn   = document.getElementById('notifBellBtn');
  var panel = document.getElementById('notifPanel');
  var badge = document.getElementById('notifBadge');
  var list  = document.getElementById('notifList');
  var markAll = document.getElementById('notifMarkAll');
  var wrap  = document.getElementById('notifBell');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function setBadge(n) {
    if (n > 0) {
      badge.textContent = n > 99 ? '99+' : n;
      badge.classList.remove('hidden');
    } else {
      badge.classList.add('hidden');
    }
  }

  function render(items) {
    if (!items || !items.length) {
      list.innerHTML = '<div class="px-4 py-8 text-center text-xs text-slate-400">No notifications yet.</div>';
      return;
    }
    list.innerHTML = items.map(function (it) {
      var unreadDot = it.is_read ? '' :
        '<span class="mt-1.5 shrink-0 w-2 h-2 rounded-full bg-red-500"></span>';
      var bodyHtml = it.body
        ? '<p class="text-xs text-slate-500 mt-0.5 whitespace-pre-line line-clamp-3">' + esc(it.body) + '</p>'
        : '';
      return '<a href="#" data-id="' + it.id + '" data-link="' + esc(it.link || '') + '" ' +
        'class="notif-item flex items-start gap-2 px-4 py-3 hover:bg-slate-50 transition-colors ' +
        (it.is_read ? 'opacity-70' : '') + '">' +
          unreadDot +
          '<div class="flex-1 min-w-0">' +
            '<p class="text-sm font-semibold text-slate-800 leading-snug">' + esc(it.title) + '</p>' +
            bodyHtml +
            '<p class="text-[10px] text-slate-400 mt-1">' + esc(it.ago || it.when || '') + '</p>' +
          '</div>' +
        '</a>';
    }).join('');

    Array.prototype.forEach.call(list.querySelectorAll('.notif-item'), function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var id = a.getAttribute('data-id');
        var link = a.getAttribute('data-link');
        markRead(id).then(function () {
          if (link) window.location.href = link;
        });
      });
    });
  }

  function refresh() {
    fetch('/api/notifications', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.success) return;
        setBadge(d.unread || 0);
        if (!panel.classList.contains('hidden')) render(d.items);
        window.__notifItems = d.items;
      })
      .catch(function () {});
  }

  function markRead(id) {
    return fetch('/notifications/' + id + '/read', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(CSRF)
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && typeof d.unread !== 'undefined') setBadge(d.unread); })
      .catch(function () {});
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var opening = panel.classList.contains('hidden');
    panel.classList.toggle('hidden');
    if (opening) render(window.__notifItems || []);
  });

  markAll.addEventListener('click', function (e) {
    e.stopPropagation();
    fetch('/notifications/read-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(CSRF)
    }).then(function () { setBadge(0); refresh(); }).catch(function () {});
  });

  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) panel.classList.add('hidden');
  });

  refresh();
  setInterval(refresh, 30000);   // poll every 30s
})();
</script>
