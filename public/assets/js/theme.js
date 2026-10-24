/**
 * Base Fare CRM theme — Peacock (light) and Night Shift (dark).
 *
 * Loaded in <head> of every staff page by ThemeMiddleware, after the page's own
 * tailwind.config. It points every Tailwind colour at a CSS variable from
 * theme.css, so the theme is decided by one attribute: <html data-theme>.
 * The switch in the sidebar saves the choice to the person's account.
 */
(function () {
  'use strict';
  var SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
  var PALETTES = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green',
    'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
  var TOKENS = ['primary-container', 'on-primary', 'background', 'surface', 'surface-container-lowest',
    'surface-container-low', 'surface-container', 'surface-container-high', 'on-surface', 'on-surface-variant',
    'outline-variant', 'error', 'error-container', 'secondary', 'secondary-container', 'on-secondary-container',
    'white', 'black', 'ink', 'on-background', 'outline', 'surface-variant', 'surface-tint', 'surface-dim',
    'surface-bright', 'surface-container-highest', 'primary-fixed', 'primary-fixed-dim', 'on-primary-fixed',
    'on-primary-fixed-variant', 'on-primary-container', 'secondary-fixed', 'secondary-fixed-dim', 'on-secondary',
    'on-secondary-fixed', 'on-secondary-fixed-variant', 'tertiary', 'tertiary-container', 'tertiary-fixed',
    'tertiary-fixed-dim', 'on-tertiary', 'on-tertiary-container', 'on-tertiary-fixed', 'on-tertiary-fixed-variant',
    'on-error', 'on-error-container', 'on-gold', 'inverse-surface', 'inverse-on-surface', 'inverse-primary'];
  var v = function (name) { return 'rgb(var(--bf-' + name + ') / <alpha-value>)'; };

  var tw = window.tailwind;
  if (tw) {
    var cfg = tw.config || {};
    cfg.theme = cfg.theme || {};
    var ext = cfg.theme.extend = cfg.theme.extend || {};
    var colors = {};
    var own = ext.colors || {};
    for (var k in own) { if (Object.prototype.hasOwnProperty.call(own, k)) colors[k] = own[k]; }
    PALETTES.forEach(function (p) {
      var o = {};
      SHADES.forEach(function (s) { o[s] = v(p + '-' + s); });
      colors[p] = o;
    });
    TOKENS.forEach(function (t) { colors[t] = v(t); });
    var primary = { DEFAULT: v('primary') };
    SHADES.forEach(function (s) { primary[s] = v('blue-' + s); });
    colors.primary = primary;
    colors.gold = { DEFAULT: v('gold'), light: v('gold-light') };
    ext.colors = colors;
    var sans = ['"Hanken Grotesk"', 'system-ui', '-apple-system', '"Segoe UI"', 'sans-serif'];
    var fonts = {};
    var ownFonts = ext.fontFamily || {};
    for (var f in ownFonts) { if (Object.prototype.hasOwnProperty.call(ownFonts, f)) fonts[f] = ownFonts[f]; }
    fonts.headline = sans; fonts.body = sans; fonts.label = sans; fonts.sans = sans;
    ext.fontFamily = fonts;
    tw.config = cfg;
  }

  // Chart.js: axis text and grid lines follow the theme. Chart.js loads after
  // this file, so catch the moment it lands on window.
  function chartDefaults(Chart) {
    try {
      var dark = document.documentElement.getAttribute('data-theme') === 'dark';
      Chart.defaults.color = dark ? '#9AA8C2' : '#4A556B';
      Chart.defaults.borderColor = dark ? 'rgba(154,168,194,0.16)' : 'rgba(14,21,38,0.08)';
      Chart.defaults.font.family = "'Hanken Grotesk', system-ui, sans-serif";
    } catch (e) { /* never break a page over chart colours */ }
  }
  if (window.Chart) {
    chartDefaults(window.Chart);
  } else {
    try {
      var held;
      Object.defineProperty(window, 'Chart', {
        configurable: true,
        get: function () { return held; },
        set: function (c) {
          Object.defineProperty(window, 'Chart', { value: c, writable: true, configurable: true });
          held = c;
          if (c && c.defaults) chartDefaults(c);
        }
      });
    } catch (e) { /* ignore */ }
  }

  // The switch. Saves to the account, then flips the page without a reload
  // (charts redraw on the next page they open).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-bf-theme-toggle]') : null;
    if (!btn || btn.getAttribute('aria-busy') === 'true') return;
    e.preventDefault();
    var root = document.documentElement;
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    var csrf = btn.getAttribute('data-csrf') || '';
    btn.setAttribute('aria-busy', 'true');
    var body = new URLSearchParams();
    body.set('theme', next);
    body.set('csrf_token', csrf);
    fetch('/theme', {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) {
      var t = j && j.theme === 'dark' ? 'dark' : 'light';
      root.setAttribute('data-theme', t);
      document.querySelectorAll('[data-bf-theme-toggle]').forEach(function (b) {
        var label = b.querySelector('[data-bf-theme-label]');
        var icon = b.querySelector('.material-symbols-outlined');
        if (label) label.textContent = t === 'dark' ? 'Light mode' : 'Dark mode';
        if (icon) icon.textContent = t === 'dark' ? 'light_mode' : 'dark_mode';
        b.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
      });
      if (window.Chart && window.Chart.instances) {
        chartDefaults(window.Chart);
        var inst = window.Chart.instances;
        Object.keys(inst).forEach(function (id) { try { inst[id].update('none'); } catch (x) { /* ignore */ } });
      }
    }).catch(function () {
      alert('Could not switch the theme just now. Please try again.');
    }).then(function () {
      btn.removeAttribute('aria-busy');
    });
  });
})();
