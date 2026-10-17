// Attendance words and colours, shared by the staff app, the person page and
// the register. Facts only: there is no "late" and no "absent" (John, 16 Oct).

import { html } from './dom.js';
import * as f from './fmt.js';

export const DAY = {
  worked: { label: 'Worked', sw: 'sw-ok', pill: 'p-ok' },
  in: { label: 'In now', sw: 'sw-in', pill: 'p-ok' },
  missing_out: { label: 'Punch-out missing', sw: 'sw-fix', pill: 'p-bad' },
  leave: { label: 'Leave', sw: 'sw-leave', pill: 'p-info' },
  holiday: { label: 'Holiday', sw: 'sw-hol', pill: 'p-gold' },
  no_punch: { label: 'No punch', sw: 'sw-none', pill: 'p-neutral' },
  today: { label: 'Today', sw: 'sw-none', pill: 'p-neutral' },
  upcoming: { label: 'To come', sw: 'sw-none', pill: 'p-neutral' },
  not_employed: { label: 'Not at Trio', sw: 'sw-none', pill: 'p-neutral' },
};

export const MARK = { day_off: 'Weekly off', unpaid: 'Unpaid', worked: 'Worked, punch missed' };

/** "9:17 am – 6:28 pm" for one punch (or CRM clock-in). */
export const span = (i) => `${f.time(i.in)} – ${i.out ? f.time(i.out) : i.state === 'open' ? 'now' : 'no punch-out'}`;

/** The colour key under a strip or register. */
export function legend(states = ['worked', 'in', 'missing_out', 'leave', 'holiday', 'no_punch']) {
  return html`<div class="legend legend--strip meta">${states.map((s) => html`<span><i class="${DAY[s].sw}"></i>${DAY[s].label}</span>`)}</div>`;
}

/** Hours as "9 h 11 m"; "—" for none. */
export const hours = (m) => (m ? f.hm(m) : '—');

/** Earlier / later month: "2026-10" → "2026-09". */
export function shiftMonth(ym, n) {
  const [y, mo] = ym.split('-').map(Number);
  return new Date(Date.UTC(y, mo - 1 + n, 1)).toISOString().slice(0, 7);
}
