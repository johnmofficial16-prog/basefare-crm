// Modules that aren't built yet. Each page says plainly what will be here and
// when, and points to what already works today.

import { html, mount, icon } from '../core/dom.js';

const MODULES = {
  hiring: {
    title: 'Hiring & joining', icon: 'brief', phase: 'Phase 4',
    lead: 'From an approved opening to a signed appointment letter and a working CRM login, with the new joiner doing their own paperwork on their phone.',
    items: [
      ['Openings', 'Raised by an admin, approved by the owner.'],
      ['Candidates', 'Walk-in check-in, voice and English scorecard, interviews, where each hire came from.'],
      ['Offers', 'Made from templates, approved, accepted online.'],
      ['Joining form', 'Documents, tax regime, previous income, nominees and policies, filled in on the phone.'],
      ['Checklist', 'Documents checked, ₹1 bank test, appointment letter signed, CRM login created automatically.'],
    ],
  },
  payroll: {
    title: 'Payroll', icon: 'wallet', phase: 'Phase 3',
    lead: 'A guided monthly run: one admin prepares, the owner approves, then it is locked.',
    now: 'Plan: run November alongside today\'s process to compare, then pay December salaries from Trio HR (before 7 Jan 2027).',
    items: [
      ['Steps', 'Attendance locked → incentives approved → changes → tax → review → owner approval → pay → payslips.'],
      ['"How does it compare?"', 'The biggest reasons this month differs from last month, in words.'],
      ['Payslips with "Why?"', 'Every line explains itself.'],
      ['Checks before money moves', 'Bank details, PAN, minimum wage, deductions under 50%, no negative pay.'],
      ['Final settlements', 'Paid within 2 working days of someone leaving, as the law now requires.'],
    ],
  },
  incentives: {
    title: 'Incentives', icon: 'chart', phase: 'Phase 3',
    lead: 'Incentives worked out from CRM bookings, explained to each agent, and locked with payroll.',
    items: [
      ['Plans', 'Slabs, targets and attendance conditions, signed by each agent.'],
      ['Live statements', 'Each agent sees their bookings, the slab ladder and a what-if.'],
      ['Refunds and chargebacks', 'Taken back in the month they happen, with the reason shown.'],
      ['Approval and lock', 'Approved by the owner; a closed month never changes.'],
    ],
  },
  tax: {
    title: 'Tax (TDS)', icon: 'receipt', phase: 'Phase 3',
    lead: 'TDS on salary and incentives under the new Income-tax Act, with everything Trio\'s CA needs.',
    now: 'Next deadlines are on the Compliance page. They are checked with the CA before payroll goes live.',
    items: [
      ['Monthly TDS', 'Worked out per person under section 392, with "Why?" on the payslip.'],
      ['Declarations', 'Tax regime choice, investments (Form 124) and proofs.'],
      ['For the CA', 'Quarterly return data (Form 138) and challans.'],
      ['For staff', 'Form 130 each year, downloadable from their phone.'],
    ],
  },
  letters: {
    title: 'Letters & documents', icon: 'file', phase: 'Next',
    lead: 'Letters on Trio letterhead, signed online, and every document in one place.',
    items: [
      ['Templates', 'Appointment, confirmation, increment, experience, relieving and salary certificate.'],
      ['Appointment-letter catch-up', 'Written appointment letters are now required for existing staff too.'],
      ['Requests from staff', 'Staff ask for a letter from their phone; an admin approves it.'],
      ['Letter of Intent', 'Moves here from the CRM.'],
    ],
  },
  help: {
    title: 'Help desk', icon: 'help', phase: 'Phase 4',
    lead: 'Questions, grievances and the POSH committee, handled privately and on time.',
    items: [
      ['Help desk', 'Staff ask a question; it is answered and kept.'],
      ['Grievances', 'Raised in confidence, tracked to an answer.'],
      ['POSH', 'Case handling for the Internal Committee, with the yearly report.'],
      ['Announcements', 'Policies to read and acknowledge.'],
    ],
  },
  settings: {
    title: 'Settings', icon: 'cog', phase: 'Next',
    lead: 'Company details, centres, the law tables and who can sign in.',
    items: [
      ['Company and centres', 'Trio\'s registration details and each centre\'s state.'],
      ['Law tables', 'Tax slabs, minimum wages, PF ceiling and due dates, each with a start date and a source.'],
      ['Admins', 'Owner and admin accounts, and who can do what.'],
      ['Health', 'The email outbox and background jobs, with plain messages when something fails.'],
    ],
  },
};

export function render({ main, params }) {
  const m = MODULES[params[0]];
  mount(main, html`
    <div class="card soonhero">
      <div>
        <span class="pill p-gold">${icon('spark', 'i--sm')}${m.phase === 'Next' ? 'Being built next' : 'Coming in ' + m.phase}</span>
        <h1>${m.title}</h1>
        <p>${m.lead}</p>
      </div>
      <div class="soonart">${icon(m.icon)}</div>
    </div>
    ${m.now ? html`<div class="banner banner--ok">${icon('check')}<div>${m.now}</div></div>` : ''}
    <div class="card"><div class="card__h"><h2>What will be here</h2><span class="sub">From the approved plan</span></div>
      <div class="card__b"><div class="featurelist">${m.items.map(([t, d]) => html`<div class="feature">${icon('check')}<div><b>${t}</b><span>${d}</span></div></div>`)}</div></div></div>`);
}
