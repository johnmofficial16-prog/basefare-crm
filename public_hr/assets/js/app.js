// Trio HR — app shell and router.
// Admins (owner/admin) get the laptop console; staff get the phone app.
// Routes are hash-based (#/people/12?tab=attendance), so the server only ever
// serves one static page plus the JSON API.

import { html, mount, $, $$, icon } from './core/dom.js';
import { api, setCsrf, onSignedOut, contact } from './core/api.js';
import { toast, openPalette, openMenu, openPanel, closeAll, confirmSheet, holdFocus } from './core/ui.js';
import { ago } from './core/fmt.js';

const root = document.getElementById('app');
export const state = { session: null, shell: null, cleanup: null, navTimer: null, emailOn: false };

const ROLE_LABELS = { owner: 'Owner', admin: 'Admin', employee: 'Staff' };

const ADMIN_NAV = [
  { key: 'today', label: 'Today', icon: 'home', href: '#/today' },
  { key: 'approvals', label: 'Approvals', icon: 'inbox', href: '#/approvals', badge: true },
  { key: 'mail', label: 'Trio Mail', icon: 'mail', href: '#/mail', badge: true },
  { k: 'People' },
  { key: 'people', label: 'People', icon: 'users', href: '#/people' },
  { key: 'attendance', label: 'Attendance', icon: 'clock', href: '#/attendance' },
  { k: 'Company' },
  { key: 'letters', label: 'Letters', icon: 'file', href: '#/letters', badge: true },
  { key: 'policies', label: 'Policies', icon: 'flag', href: '#/policies' },
  { key: 'compliance', label: 'Compliance', icon: 'shield', href: '#/compliance' },
  { key: 'settings', label: 'Settings', icon: 'cog', href: '#/settings' },
];

// Modules still to come, folded under "Coming next" so the built ones fit a laptop screen.
const SOON_NAV = [
  { key: 'payroll', label: 'Payroll', icon: 'wallet', href: '#/payroll', soon: 'Phase 3' },
  { key: 'incentives', label: 'Incentives', icon: 'chart', href: '#/incentives', soon: 'Phase 3' },
  { key: 'tax', label: 'Tax (TDS)', icon: 'receipt', href: '#/tax', soon: 'Phase 3' },
  { key: 'hiring', label: 'Hiring & joining', icon: 'brief', href: '#/hiring', soon: 'Phase 4' },
];
const navLink = (n) => html`<a href="${n.href}" data-nav="${n.key}">${icon(n.icon)}<span>${n.label}</span>${n.badge ? html`<em data-badge="${n.key}" hidden></em>` : ''}${n.soon ? html`<span class="soon">${n.soon}</span>` : ''}</a>`;

// Pay lives under More until payroll starts.
const STAFF_TABS = [
  { key: 'me', label: 'Home', icon: 'home', href: '#/me' },
  { key: 'inbox', label: 'Inbox', icon: 'mail', href: '#/me/mail', badge: true },
  { key: 'attendance', label: 'Attendance', icon: 'clock', href: '#/me/attendance' },
  { key: 'leave', label: 'Leave', icon: 'sun', href: '#/me/leave' },
  { key: 'more', label: 'More', icon: 'more', href: '#/me/more' },
];

// [pattern, module, export, area, nav key]
const ROUTES = [
  [/^\/signin$/, 'signin', 'signin', 'public'],
  [/^\/forgot$/, 'signin', 'forgot', 'public'],
  [/^\/welcome(?:\/([A-Za-z0-9_-]{43}))?$/, 'signin', 'welcome', 'public'],
  [/^\/reset(?:\/([A-Za-z0-9_-]{43}))?$/, 'signin', 'reset', 'public'],
  [/^\/verify$/, 'verify', 'render', 'public'],
  [/^\/verify\/([A-Za-z0-9-]{4,20})$/, 'verify', 'render', 'public'],
  [/^\/from-crm\/([A-Za-z0-9_-]{20,300}\.[A-Za-z0-9_-]{20,100})$/, 'signin', 'fromCrm', 'public'],
  [/^\/today$/, 'today', 'render', 'admin', 'today'],
  [/^\/floor$/, 'today', 'floorPage', 'admin', 'today'],
  [/^\/compliance$/, 'today', 'compliancePage', 'admin', 'compliance'],
  [/^\/approvals$/, 'approvals', 'render', 'admin', 'approvals'],
  [/^\/mail$/, 'mail', 'render', 'admin', 'mail'],
  [/^\/mail\/new$/, 'mail', 'compose', 'admin', 'mail'],
  [/^\/mail\/staff$/, 'chats', 'adminList', 'admin', 'mail'],
  [/^\/mail\/staff\/(\d+)$/, 'chats', 'adminThread', 'admin', 'mail'],
  [/^\/mail\/(\d+)$/, 'mail', 'render', 'admin', 'mail'],
  [/^\/announcements$/, 'mail', 'announcements', 'admin', 'mail'],
  [/^\/people\/from-crm$/, 'people', 'fromCrm', 'admin', 'people'],
  [/^\/people$/, 'people', 'render', 'admin', 'people'],
  [/^\/people\/new$/, 'person', 'create', 'admin', 'people'],
  [/^\/people\/(\d+)$/, 'person', 'render', 'admin', 'people'],
  [/^\/org$/, 'people', 'org', 'admin', 'people'],
  [/^\/letters$/, 'letters', 'list', 'admin', 'letters'],
  [/^\/letters\/new$/, 'letters', 'create', 'admin', 'letters'],
  [/^\/letters\/(\d+)$/, 'letters', 'detail', 'admin', 'letters'],
  [/^\/policies$/, 'policies', 'list', 'admin', 'policies'],
  [/^\/policies\/new$/, 'policies', 'create', 'admin', 'policies'],
  [/^\/policies\/(\d+)$/, 'policies', 'detail', 'admin', 'policies'],
  [/^\/settings$/, 'settings', 'render', 'admin', 'settings'],
  [/^\/account$/, 'settings', 'account', 'admin', null],
  [/^\/attendance$/, 'attendance', 'register', 'admin', 'attendance'],
  [/^\/attendance\/close$/, 'attendance', 'close', 'admin', 'attendance'],
  [/^\/attendance\/holidays$/, 'attendance', 'holidays', 'admin', 'attendance'],
  [/^\/attendance\/balances$/, 'attendance', 'balances', 'admin', 'attendance'],
  [/^\/(hiring|payroll|incentives|tax)$/, 'soon', 'render', 'admin', '$1'],
  [/^\/help$/, 'mail', 'render', 'admin', 'mail'],
  [/^\/me$/, 'staff', 'home', 'staff', 'me'],
  [/^\/me\/attendance$/, 'staff', 'attendance', 'staff', 'attendance'],
  [/^\/me\/fix\/([ps]\d+)$/, 'staff', 'fix', 'staff', 'attendance'],
  [/^\/me\/leave$/, 'staff', 'leave', 'staff', 'leave'],
  [/^\/me\/leave\/new$/, 'staff', 'leaveNew', 'staff', 'leave'],
  [/^\/me\/pay$/, 'staff', 'pay', 'staff', 'more'],
  [/^\/me\/mail$/, 'inbox', 'list', 'staff', 'inbox'],
  [/^\/me\/chats$/, 'chats', 'list', 'staff', 'inbox'],
  [/^\/me\/chats\/new$/, 'chats', 'compose', 'staff', 'inbox'],
  [/^\/me\/chats\/(\d+)$/, 'chats', 'thread', 'staff', 'inbox'],
  [/^\/me\/mail\/new$/, 'inbox', 'compose', 'staff', 'inbox'],
  [/^\/me\/mail\/(\d+)$/, 'inbox', 'thread', 'staff', 'inbox'],
  [/^\/me\/mail\/a(\d+)$/, 'inbox', 'announcement', 'staff', 'inbox'],
  [/^\/me\/more$/, 'staff', 'more', 'staff', 'more'],
  [/^\/me\/profile$/, 'staff', 'profile', 'staff', 'more'],
  [/^\/me\/bank$/, 'staff', 'bank', 'staff', 'more'],
  [/^\/me\/requests$/, 'staff', 'requests', 'staff', 'more'],
  [/^\/me\/security$/, 'staff', 'security', 'staff', 'more'],
  [/^\/me\/policies$/, 'staff', 'policies', 'staff', 'more'],
  [/^\/me\/policies\/(\d+)$/, 'staff', 'policy', 'staff', 'more'],
  [/^\/me\/letters$/, 'staff', 'letters', 'staff', 'more'],
  [/^\/me\/letters\/new$/, 'staff', 'letterNew', 'staff', 'more'],
  [/^\/me\/letters\/(\d+)$/, 'staff', 'letter', 'staff', 'more'],
  [/^\/me\/documents$/, 'staff', 'documents', 'staff', 'more'],
];

export const isAdmin = () => ['owner', 'admin'].includes(state.session?.account?.role);

const TITLES = {
  signin: 'Sign in', forgot: 'Forgot password', welcome: 'Welcome', reset: 'New password', verify: 'Check a letter',
  today: 'Today', floor: 'Tonight on the floor', compliance: 'Compliance', approvals: 'Approvals', people: 'People', org: 'Org chart',
  letters: 'Letters', policies: 'Policies', settings: 'Settings', account: 'My account', me: 'Home', attendance: 'Attendance',
  leave: 'Leave', pay: 'Pay', more: 'More', hiring: 'Hiring & joining', payroll: 'Payroll',
  incentives: 'Incentives', tax: 'Tax (TDS)', mail: 'Trio Mail', inbox: 'Inbox', fromCrm: 'Opening Trio HR',
};

/** The browser tab's title; screens with a name (a person, a letter) set their own. */
export function setTitle(text) {
  document.title = text ? `${text} · Trio HR` : 'Trio HR';
}

function parseHash() {
  const h = location.hash.replace(/^#/, '') || '/';
  const [path, qs = ''] = h.split('?');
  return { path, query: new URLSearchParams(qs) };
}

/** Navigate, keeping history clean when only the query changes. */
export function go(hash, { replace = false } = {}) {
  if (replace) { history.replaceState(null, '', hash); route(); return; }
  if (location.hash === hash) { route(); return; }
  location.hash = hash; // the hashchange listener routes
}

export function homeHash() {
  if (!state.session) return '#/signin';
  return isAdmin() ? '#/today' : '#/me';
}

// ---- boot -------------------------------------------------------------------

async function boot() {
  try {
    const s = await api.get('/session');
    state.emailOn = !!s.email_on;
    setSession(s.signed_in === false ? null : s);
  } catch (err) {
    mount(root, html`<div class="auth"><div class="auth__panel"><h1>Trio HR is unavailable</h1><p class="lead">${err.message}</p><button class="btn btn--pri" type="button" id="retry">Try again</button></div></div>`);
    root.classList.remove('boot');
    $('#retry').addEventListener('click', () => location.reload());
    return;
  }
  onSignedOut(() => {
    if (!state.session) return;
    try { if (!location.hash.startsWith('#/signin')) sessionStorage.setItem('trio-hr-next', location.hash); } catch {}
    setSession(null);
    closeAll();
    toast('You were signed out. Sign in again to carry on where you were.', { tone: 'bad' });
    go('#/signin', { replace: true });
  });
  window.addEventListener('hashchange', route);
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && state.shell === 'console') {
      e.preventDefault();
      palette();
    }
  });
  route();
}

export function setSession(s) {
  state.session = s;
  if (s && 'email_on' in s) state.emailOn = !!s.email_on;
  setCsrf(s?.csrf || null);
  state.shell = null;
  if (s) startIdleWatch(); else stopIdleWatch();
}

// ---- router -------------------------------------------------------------------

let routing = 0;

async function route() {
  const mine = ++routing;
  const { path, query } = parseHash();
  let match = null;
  for (const [re, mod, fn, area, navKey] of ROUTES) {
    const m = path.match(re);
    if (m) { match = { mod, fn, area, params: m.slice(1), nav: navKey?.startsWith('$') ? m[1] : navKey }; break; }
  }
  if (!match) return go(homeHash(), { replace: true });

  if (match.area === 'public') {
    if (state.session && (match.fn === 'signin' || match.fn === 'forgot')) return go(homeHash(), { replace: true });
  } else if (!state.session) {
    try { sessionStorage.setItem('trio-hr-next', location.hash); } catch {}
    return go('#/signin', { replace: true });
  } else if (match.area === 'admin' && !isAdmin()) {
    return go('#/me', { replace: true });
  } else if (match.area === 'staff' && !state.session.employee) {
    if (isAdmin()) {
      toast("Your account isn't linked to a staff record, so there's no personal app to show.", { tone: 'bad' });
      return go('#/today', { replace: true });
    }
    state.shell = 'public';
    root.classList.remove('boot');
    mount(root, html`<div class="auth"><div class="auth__panel"><h1>Almost there</h1>
      <p class="lead">Your sign-in isn't linked to a staff record yet, so there's nothing to show. Please ask the HR admin to link it.</p>
      <button class="btn btn--pri" type="button" id="soout">Sign out</button></div></div>`);
    $('#soout').addEventListener('click', signOut);
    return;
  }

  closeAll();
  if (typeof state.cleanup === 'function') { try { state.cleanup(); } catch {} }
  state.cleanup = null;

  // Each screen gets a fresh <main>, so listeners from the last screen can't linger.
  const holder = ensureShell(match.area);
  let main = holder;
  if (match.area !== 'public') {
    main = holder.cloneNode(false);
    holder.replaceWith(main);
  }
  markNav(match.area, match.nav);
  setTitle(TITLES[match.nav] || TITLES[match.fn] || '');
  let mod;
  try {
    mod = await import(`./screens/${match.mod}.js`);
  } catch (err) {
    console.error(err);
    mount(main, html`<div class="card card__b">This screen didn't load. Refresh the page to try again.</div>`);
    return;
  }
  if (mine !== routing) return;
  const ctx = { main, params: match.params, query, session: state.session, head: setHead, refreshNav, isCurrent: () => mine === routing };
  try {
    const cleanup = await mod[match.fn](ctx);
    // The user may have moved on while this screen loaded: stop it now rather than keep it running.
    if (mine === routing) state.cleanup = cleanup;
    else if (typeof cleanup === 'function') { try { cleanup(); } catch {} }
  } catch (err) {
    console.error(err);
  }
  if (mine === routing && match.area !== 'public') {
    main.focus({ preventScroll: true });
    window.scrollTo(0, 0);
  }
}

// ---- shells -----------------------------------------------------------------------

function ensureShell(area) {
  root.classList.remove('boot');
  if (area === 'public') {
    state.shell = 'public';
    return root;
  }
  const want = area === 'admin' ? 'console' : 'staff';
  if (state.shell !== want) {
    state.shell = want;
    if (want === 'console') consoleShell(); else staffShell();
  }
  return $('#main');
}

function consoleShell() {
  const s = state.session;
  mount(root, html`
  <div class="app" id="shell">
    <aside class="side" id="side" aria-label="Main menu">
      <a class="brand" href="#/today"><span class="logo-tile"><img src="assets/img/trio-logo.png" alt=""></span><span><b>Trio HR</b><small>Tours &amp; Travels</small></span></a>
      <div class="livebar" id="livebar"><i></i><span>Up to date</span></div>
      <nav class="nav" id="nav">
        ${ADMIN_NAV.map((n) => n.k ? html`<div class="nav__k">${n.k}</div>` : navLink(n))}
        <details class="nav__soon" id="navsoon"><summary>${icon('more')}<span>Coming next</span>${icon('chev', 'chev i--sm')}</summary>
          ${SOON_NAV.map(navLink)}
        </details>
      </nav>
      <div class="side__foot">
        <button class="who-btn" id="whobtn" type="button" aria-haspopup="menu">
          <span class="av av--sm">${s.account.initials}</span><span><b>${s.account.name}</b><small>${ROLE_LABELS[s.account.role]}</small></span>${icon('chevd', 'i--sm')}
        </button>
      </div>
    </aside>
    <div class="col">
      <header class="top">
        <button class="iconbtn menubtn" id="menubtn" type="button" aria-label="Open menu" aria-controls="side" aria-expanded="false">${icon('menu')}</button>
        <button class="searchbtn" id="searchbtn" type="button" aria-label="Search">${icon('search')}<span class="t">Search people, or what you want to do…</span><span class="kbd">Ctrl K</span></button>
        <div class="top__r">
          ${s.local ? html`<span class="envpill" title="This copy runs only on this computer, with made-up people">${icon('info', 'i--sm')}Local preview · sample data</span>` : ''}
          <button class="iconbtn" id="bellbtn" type="button" aria-label="Notifications" aria-expanded="false">${icon('bell')}<span class="badge" id="bellcount" hidden></span></button>
          <a class="btn btn--pri" href="#/people/new">${icon('plus')}<span class="t">Add a person</span></a>
        </div>
      </header>
      <main class="main" id="main" tabindex="-1"></main>
    </div>
  </div>`);
  $('#searchbtn').addEventListener('click', palette);
  $('#menubtn').addEventListener('click', () => toggleNav(true));
  $('#nav').addEventListener('click', () => toggleNav(false));
  $('#whobtn').addEventListener('click', (e) => accountMenu(e.currentTarget));
  $('#bellbtn').addEventListener('click', (e) => bell(e.currentTarget));
  refreshNav();
  clearInterval(state.navTimer);
  state.navTimer = setInterval(() => refreshNav(true), 60000);
}

let navLayer = null;

function toggleNav(open) {
  const shell = $('#shell');
  if (!shell) return;
  if (!!open === shell.classList.contains('nav-open')) return;
  shell.classList.toggle('nav-open', open);
  $('#menubtn')?.setAttribute('aria-expanded', String(!!open));
  let sc = $('#navscrim');
  if (open) {
    if (!sc) {
      sc = document.createElement('div');
      sc.className = 'scrim is-on';
      sc.id = 'navscrim';
      sc.addEventListener('click', () => toggleNav(false));
      document.body.appendChild(sc);
    }
    navLayer = holdFocus($('#side'), { onClose: () => { navLayer = null; toggleNav(false); } });
    $('#nav a')?.focus();
  } else {
    sc?.remove();
    if (navLayer) { const l = navLayer; navLayer = null; l.close(); }
  }
}

function staffShell() {
  clearInterval(state.navTimer);
  state.navTimer = setInterval(() => refreshBell(true), 60000);
  mount(root, html`
  <div class="phone-app">
    <header class="p-head" id="phead"></header>
    <main class="p-body" id="main" tabindex="-1"></main>
    <nav class="tabbar" aria-label="Sections">
      ${STAFF_TABS.map((t) => html`<a href="${t.href}" data-tab="${t.key}">${icon(t.icon)}<span>${t.label}</span>${t.badge ? html`<em class="tabbadge" data-tabbadge="${t.key}" hidden></em>` : ''}</a>`)}
    </nav>
  </div>`);
}

/** Staff app header: title, optional back link, optional right-hand buttons. */
function setHead({ title = '', back = null, right = '' } = {}) {
  const head = $('#phead');
  if (!head) return;
  const s = state.session;
  mount(head, html`
    ${back ? html`<a class="iconbtn back" href="${back}" aria-label="Back">${icon('back')}</a>` : html`<span class="logo-tile"><img src="assets/img/trio-logo.png" alt=""></span>`}
    ${title ? html`<span class="t">${title}</span>` : html`<span class="brandname">Trio HR</span>`}
    <span class="r">${right}${!back && s ? html`<button class="iconbtn bell-sm" id="bellbtn" type="button" aria-label="Notifications" aria-expanded="false">${icon('bell')}<span class="badge" id="bellcount" hidden></span></button><a class="av me" href="#/me/more" aria-label="My account">${s.account.initials}</a>` : ''}</span>`);
  $('#bellbtn', head)?.addEventListener('click', (e) => bell(e.currentTarget));
  refreshBell(true);
}

function markNav(area, key) {
  if (area === 'admin') {
    $$('#nav a').forEach((a) => { if (a.dataset.nav === key) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    if (SOON_NAV.some((n) => n.key === key)) { const d = $('#navsoon'); if (d) d.open = true; }
    toggleNav(false);
  } else if (area === 'staff') {
    $$('.tabbar a').forEach((a) => { if (a.dataset.tab === key) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
  }
}

/** Approvals badge in the sidebar. */
export async function refreshNav(background = false) {
  if (state.shell !== 'console') return;
  try {
    const n = await api.get('/nav', { background });
    const b = $('[data-badge="approvals"]');
    if (b) {
      b.hidden = !n.approvals;
      b.textContent = n.approvals;
    }
    const lb = $('[data-badge="letters"]');
    if (lb) {
      lb.hidden = !n.letters;
      lb.textContent = n.letters;
    }
    const mb = $('[data-badge="mail"]');
    if (mb) {
      mb.hidden = !n.mail;
      mb.textContent = n.mail;
      mb.setAttribute('aria-label', `${n.mail} waiting on HR`);
    }
    setBell(n.unread);
    setLive('ok');
  } catch {
    setLive('off');
  }
}

/** The sidebar's "Up to date" line. */
export function setLive(stateName, when = null) {
  const bar = $('#livebar');
  if (!bar) return;
  bar.classList.toggle('is-off', stateName === 'off');
  bar.classList.toggle('is-stale', stateName === 'stale');
  $('span', bar).textContent = stateName === 'off' ? 'Reconnecting…' : stateName === 'stale' ? 'Updating…' : (when ? `Up to date · ${when}` : 'Up to date');
}

function accountMenu(anchor) {
  const s = state.session;
  const items = [];
  items.push({ label: 'My account and devices', icon: 'lock', href: '#/account' });
  if (s.employee) items.push({ label: 'My own HR app', icon: 'user', href: '#/me' });
  items.push({ label: 'Open the CRM', icon: 'ext', href: s.crm_url, external: true });
  items.push('-');
  items.push({ label: 'Sign out', icon: 'out', onClick: signOut });
  openMenu(anchor, items, html`<b>${s.account.name}</b>${s.account.email}`);
}

// ---- the bell -------------------------------------------------------------------------

function setBell(n) {
  const b = $('#bellcount');
  if (!b) return;
  b.hidden = !n;
  b.textContent = n > 99 ? '99+' : n;
  $('#bellbtn')?.setAttribute('aria-label', n ? `Notifications, ${n} unread` : 'Notifications');
}

async function refreshBell(background = false) {
  if (!state.session) return;
  try { setBell((await api.get('/notifications', { background })).unread); } catch {}
  // Staff: unread replies and announcements on the Inbox tab.
  const tb = $('[data-tabbadge="inbox"]');
  if (tb && state.session.employee) {
    try {
      const n = (await api.get('/me/mail/unread', { background: true })).unread;
      tb.hidden = !n;
      tb.textContent = n > 9 ? '9+' : n;
      tb.closest('a')?.setAttribute('aria-label', n ? `Inbox, ${n} unread` : 'Inbox');
    } catch {}
  }
}

let bellLayer = null;

async function bell(anchor) {
  if (bellLayer) { bellLayer.close(); return; }
  let feed;
  try { feed = await api.get('/notifications'); } catch (err) { toast(err.message, { tone: 'bad' }); return; }
  if (bellLayer) return;
  setBell(feed.unread);
  anchor.setAttribute('aria-expanded', 'true');
  bellLayer = openPanel(anchor, html`
    <div class="panel__h"><b>Notifications</b><span class="panel__acts">${feed.unread ? html`<button class="linkbtn" type="button" data-all>Mark all as read</button>` : ''}<button class="iconbtn iconbtn--sm" type="button" data-close aria-label="Close notifications">${icon('x', 'i--sm')}</button></span></div>
    <div class="panel__list">${feed.items.length ? feed.items.map((n) => html`
      <a class="note-item ${n.read ? '' : 'is-unread'}" href="${n.link || '#'}" data-note="${n.id}">
        <span class="note-dot" aria-hidden="true"></span>
        <span><b>${n.title}</b>${n.body ? html`<small>${n.body}</small>` : ''}<time>${ago(n.at)}</time></span>
      </a>`) : html`<div class="empty"><b>Nothing new</b><p>Requests, letters and decisions show here.</p></div>`}</div>`, {
    label: 'Notifications',
    onClose() { bellLayer = null; anchor.setAttribute('aria-expanded', 'false'); },
    onMount(el, layer) {
      el.addEventListener('click', async (e) => {
        const all = e.target.closest('[data-all]');
        if (all) {
          const r = await api.post('/notifications/read', {});
          setBell(r.unread);
          el.querySelectorAll('.is-unread').forEach((x) => x.classList.remove('is-unread'));
          all.remove();
          return;
        }
        const a = e.target.closest('[data-note]');
        if (!a) return;
        e.preventDefault();
        api.post('/notifications/read', { id: Number(a.dataset.note) }).then((r) => setBell(r.unread)).catch(() => {});
        layer.close();
        const href = a.getAttribute('href');
        if (href && href.startsWith('#/')) go(href);
      });
    },
  });
}

export async function signOut() {
  try { await api.del('/session'); } catch {}
  setSession(null);
  toast('Signed out.');
  go('#/signin', { replace: true });
}

function palette() {
  closeAll();
  openPalette({
    search: (q) => api.get('/search?q=' + encodeURIComponent(q)),
    actions: [
      { label: 'Today', hint: 'What needs you, tonight on the floor', href: '#/today', icon: 'home', keywords: 'home dashboard' },
      { label: 'Approvals', hint: 'Every request waiting for a decision', href: '#/approvals', icon: 'inbox', keywords: 'requests inbox approve' },
      { label: 'Trio Mail', hint: 'Messages from staff waiting on HR', href: '#/mail', icon: 'mail', keywords: 'mail messages inbox help desk reply' },
      { label: 'Write to someone', href: '#/mail/new', icon: 'edit', keywords: 'message send write email' },
      { label: 'Announcements', hint: 'Tell everyone, or one centre', href: '#/announcements', icon: 'speaker', keywords: 'announce notice broadcast' },
      { label: 'Staff conversations', hint: 'Messages between colleagues', href: '#/mail/staff', icon: 'users', keywords: 'chat colleagues staff messages internal' },
      { label: 'Bring in staff from the CRM', href: '#/people/from-crm', icon: 'link', keywords: 'import crm add staff link' },
      { label: 'Leave requests waiting', href: '#/approvals?view=leave', icon: 'sun', keywords: 'leave holiday off' },
      { label: 'Attendance fixes waiting', href: '#/approvals?view=attendance', icon: 'clock', keywords: 'clock out missed fix' },
      { label: 'People', hint: 'Directory and records', href: '#/people', icon: 'users', keywords: 'staff employees directory' },
      { label: 'Add a person', href: '#/people/new', icon: 'plus', keywords: 'new hire join add employee' },
      { label: 'People not on Trio HR yet', href: '#/people?hr=not_joined', icon: 'mail', keywords: 'invite account' },
      { label: 'Tonight on the floor', hint: 'Live from the CRM and Trio HR', href: '#/floor', icon: 'moon', keywords: 'live board clocked in punched' },
      { label: 'Attendance register', hint: 'Every day of the month, everyone', href: '#/attendance', icon: 'clock', keywords: 'attendance register punch days hours' },
      { label: 'Close the month for payroll', href: '#/attendance/close', icon: 'lock', keywords: 'month end lock payroll ready no punch' },
      { label: 'Holidays', href: '#/attendance/holidays', icon: 'sun', keywords: 'holiday calendar diwali' },
      { label: 'Leave balances', hint: 'Opening balances for the year', href: '#/attendance/balances', icon: 'sun', keywords: 'leave balance opening casual sick earned' },
      { label: 'Compliance dates', hint: 'TDS, returns and other deadlines', href: '#/compliance', icon: 'shield', keywords: 'tds form 138 deadline due' },
    ],
  });
}

// ---- idle sign-out (admins: 30 minutes) -------------------------------------------

let idleTimer = null;
let warned = false;
let pinging = false;

/** Tell the server we're still here. False when the session had already ended. */
async function stillHere() {
  const s = await api.get('/session');
  if (s.signed_in === false) {
    setSession(null);
    closeAll();
    toast('You were signed out. Sign in again to carry on where you were.', { tone: 'bad' });
    go('#/signin', { replace: true });
    return false;
  }
  warned = false;
  return true;
}

function activity() {
  // Reading or typing makes no API calls, so the server is told at most once a minute.
  if (state.session && !pinging && Date.now() - contact.at > 60000) {
    pinging = true;
    stillHere().catch(() => {}).finally(() => { pinging = false; });
  }
}

function startIdleWatch() {
  stopIdleWatch();
  contact.at = Date.now();
  warned = false;
  ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((ev) => window.addEventListener(ev, activity, { passive: true }));
  idleTimer = setInterval(() => {
    const idle = (state.session?.idle_seconds || 1800) * 1000;
    const quiet = Date.now() - contact.at; // the server's own clock for this session
    if (quiet >= idle) {
      stopIdleWatch();
      closeAll();
      setSession(null);
      toast('Signed out after a while without activity, to keep pay and ID details safe.', { tone: 'bad' });
      go('#/signin', { replace: true });
    } else if (!warned && idle <= 3600000 && quiet >= idle - 120000) {
      warned = true;
      // Only "Sign out now" signs out; tapping outside or Esc means they're still here.
      confirmSheet({
        title: 'Still there?',
        text: "For safety you'll be signed out in 2 minutes unless you carry on.",
        confirm: 'Keep me signed in', cancel: 'Sign out now', cancelValue: 'signout',
        onConfirm: () => stillHere(),
      }).then((r) => {
        if (r === 'signout') { if (state.session) signOut(); }
        else if (r === null && state.session) stillHere().catch(() => {});
      });
    }
  }, 15000);
}

function stopIdleWatch() {
  clearInterval(idleTimer);
  ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((ev) => window.removeEventListener(ev, activity));
}

boot();
