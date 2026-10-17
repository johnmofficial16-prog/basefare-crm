// Plain-English formatting. Server times are Indian wall-clock times
// ("2026-10-15 19:40:00"); they are shown as-is, never shifted by the browser.

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const DAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const IST_OFFSET = 330 * 60000;

function parts(s) {
  const [d = '', t = '00:00:00'] = String(s || '').split(/[ T]/);
  const [y, m, dd] = d.split('-').map(Number);
  const [hh = 0, mi = 0, ss = 0] = t.split(':').map(Number);
  return { y, m, d: dd, hh, mi, ss };
}

/** Epoch ms of an Indian wall-clock time. */
export function ts(s) {
  const p = parts(s);
  return Date.UTC(p.y, p.m - 1, p.d, p.hh, p.mi, p.ss) - IST_OFFSET;
}

/** Today's date in India, Y-m-d. */
export function todayIST() {
  const d = new Date(Date.now() + IST_OFFSET);
  return d.toISOString().slice(0, 10);
}

function weekday(p) { return new Date(Date.UTC(p.y, p.m - 1, p.d)).getUTCDay(); }

/** "Tue 3 Nov" (year added when it isn't this year). */
export function day(s) {
  if (!s) return '';
  const p = parts(s);
  const thisYear = Number(todayIST().slice(0, 4));
  return `${DAYS[weekday(p)]} ${p.d} ${MONTHS[p.m - 1]}${p.y !== thisYear ? ' ' + p.y : ''}`;
}

/** "Tuesday 3 November" */
export function dayLong(s) {
  if (!s) return '';
  const p = parts(s);
  return `${DAYS_LONG[weekday(p)]} ${p.d} ${MONTHS_LONG[p.m - 1]}`;
}

export function weekdayShort(s) { return DAYS[weekday(parts(s))]; }
export function dayNum(s) { return parts(s).d; }
export function monthName(ym) { const [y, m] = String(ym).split('-').map(Number); return `${MONTHS_LONG[m - 1]} ${y}`; }

/** "7:40 pm" */
export function time(s) {
  if (!s) return '';
  const p = parts(s);
  const h = p.hh % 12 || 12;
  return `${h}:${String(p.mi).padStart(2, '0')} ${p.hh < 12 ? 'am' : 'pm'}`;
}

/** "18:30" → "6:30 pm" */
export function t12(hm) {
  if (!hm) return '';
  const [h, m] = hm.split(':').map(Number);
  return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
}

/** "just now", "5 min ago", "3 h ago", "yesterday", "Tue 3 Nov" */
export function ago(s) {
  if (!s) return '';
  const diff = (Date.now() - ts(s)) / 60000;
  if (diff < 1) return 'just now';
  if (diff < 60) return `${Math.floor(diff)} min ago`;
  const today = todayIST();
  const date = String(s).slice(0, 10);
  if (date === today) return `${Math.floor(diff / 60)} h ago`;
  const days = Math.round((Date.parse(today) - Date.parse(date)) / 86400000);
  if (days === 1) return 'yesterday';
  if (days < 7) return `${days} days ago`;
  return day(s);
}

/** "Today", "Yesterday", "Mon", or "Tue 3 Nov" for list stamps. */
export function stamp(s) {
  if (!s) return '';
  const today = todayIST();
  const date = String(s).slice(0, 10);
  const days = Math.round((Date.parse(today) - Date.parse(date)) / 86400000);
  if (days === 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days < 7 && days > 0) return DAYS[weekday(parts(s))];
  return day(s);
}

export function plural(n, one, many) {
  const v = Number(n);
  const shown = Number.isInteger(v) ? v : v.toFixed(1).replace(/\.0$/, '');
  return `${shown} ${v === 1 ? one : (many || one + 's')}`;
}

const inr = new Intl.NumberFormat('en-IN');
export const num = (n) => inr.format(n);
export function rupees(n) { return (n < 0 ? '− ' : '') + '₹' + inr.format(Math.abs(n)); }

/** 520 → "8 h 40 m" */
export function hm(mins) {
  if (mins === null || mins === undefined) return '—';
  const h = Math.floor(mins / 60);
  const m = Math.round(mins % 60);
  return h ? `${h} h${m ? ` ${m} m` : ''}` : `${m} m`;
}

export function bal(n) { return Number.isInteger(n) ? String(n) : Number(n).toFixed(1); }

// Words that keep their capital mid-sentence (acronyms like PAN keep theirs anyway).
const NAMES = new Set(['Aadhaar', 'Trio', 'India', 'Mohali', 'Punjab', 'Jamshedpur', 'Jharkhand']);
const lowerWord = (w) => ((w.length > 1 && w.toUpperCase() === w) || NAMES.has(w) ? w : w.toLowerCase());
/** One of our labels mid-sentence: "Experience certificate" → "experience certificate"; "PAN", "Aadhaar" stay. */
export function lower(label) { return String(label ?? '').replace(/\p{L}[\p{L}\p{M}]*/gu, lowerWord); }
/** Someone's own words mid-sentence: only an ordinary first word is lowered. */
export function lowerFirst(text) { return String(text ?? '').replace(/\p{L}[\p{L}\p{M}]*/u, lowerWord); }
