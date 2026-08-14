/**
 * error-beacon.js — client-side error reporting to the Error Console.
 *
 * Loaded FIRST in <head> (before tailwind.js) on every page, so it catches
 * the exact failure class behind the 2026-08-12 incident: a script/stylesheet
 * that fails to load on one user's network renders the CRM as raw HTML with
 * nobody the wiser. With this beacon, that browser tells the server.
 *
 * Reports (max 6 per page load, server rate-limits per IP as backstop):
 *  - resource load failures (script/link/img error events, capture phase)
 *  - uncaught JS exceptions (window.onerror)
 *  - unhandled promise rejections
 *
 * Transport: navigator.sendBeacon → POST /api/client-error (CSRF-exempt,
 * fire-and-forget, works even during page unload). Falls back to fetch.
 * No PII: payload is error text + page URL only.
 */
(function () {
  'use strict';
  var MAX_REPORTS = 6;
  var sent = 0;

  function send(payload) {
    if (sent >= MAX_REPORTS) return;
    sent++;
    payload.url = String(window.location.href).slice(0, 500);
    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon('/api/client-error', body)) return;
    } catch (e) { /* fall through */ }
    try {
      fetch('/api/client-error', { method: 'POST', body: body, keepalive: true });
    } catch (e) { /* give up silently — never break the page over reporting */ }
  }

  // Resource failures + uncaught JS errors (capture phase catches both).
  window.addEventListener('error', function (ev) {
    try {
      var t = ev.target;
      if (t && t !== window && (t.src || t.href)) {
        send({
          type: 'resource',
          message: 'Failed to load ' + (t.tagName || '?').toLowerCase() + ': '
                   + String(t.src || t.href).slice(0, 300)
        });
        return;
      }
      send({
        type: 'js',
        message: String(ev.message || 'Unknown script error').slice(0, 500),
        source: String(ev.filename || '').slice(0, 300),
        line: ev.lineno || 0,
        col: ev.colno || 0
      });
    } catch (e) { /* never throw from the reporter */ }
  }, true);

  window.addEventListener('unhandledrejection', function (ev) {
    try {
      var r = ev.reason;
      var msg = (r && (r.stack || r.message)) ? (r.message || String(r.stack).split('\n')[0]) : String(r);
      send({ type: 'promise', message: String(msg).slice(0, 500) });
    } catch (e) { /* never throw from the reporter */ }
  });
})();
