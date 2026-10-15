# Trio HR: plan

*14 Oct 2026. Draft for approval. Nothing is built yet.*

**The idea in one line:** one place where Trio Tours and Travels runs its people, from the offer letter to the final settlement. It is linked live to the Base Fare CRM, so attendance, sales and incentives come in automatically and the owners only approve.

**This plan rests on six pieces of research.** The full reports are in the session scratchpad, folder `hr_research/`. Ask if you want them copied into the repo.

| # | Research | What it covered |
|---|---|---|
| 1 | Fumist admin study (code + screenshots) | The design system, components, architecture and test harness we will copy |
| 2 | Fumist agent's own answer | Why it works, what bit it, and what it would change |
| 3 | CRM audit | Every HR feature in the CRM today, what moves, what breaks, the incentive maths, and attendance data quality |
| 4 | Indian payroll tax | The new Income-tax Act 2025, TDS on salary and incentives, forms, and deadlines |
| 5 | Indian labour law | The Labour Codes, Punjab/Jharkhand rules, POSH, DPDP and record keeping |
| 6 | Benchmark | Keka, Darwinbox, greytHR, Zoho, RazorpayX, Rippling, HiBob, BambooHR, Gusto, and the sales-commission tools. Includes a 231-item feature checklist and UX patterns |

---

## 0. Already decided (John, 12–14 Oct)

1. **Separate portal.** HR has its own web address and its own login. It is not part of the CRM admin.
2. **Employer.** Every employee works for **Trio Tours and Travels Pvt Ltd** (CIN U63030PB2022PTC055278, Office No. 51, Plot D-185, Prosperity Square, Phase 8, Industrial Area, Mohali 160062).
3. **Pay.** A fixed monthly salary plus incentives. **TDS is deducted on both.** There are no other deductions unless the CA says otherwise (see §11).
4. **Who runs it.** Admins only: the client and his wife. Managers don't handle HR.
5. **Self-service.** Staff use self-service, but **every request needs an admin's approval.**
6. **Fresh start.** Old spreadsheets are not imported.
7. **HR leaves the CRM admin.** HR features move out of the CRM admin into the portal.
8. **Quality bar.** Built to the Fumist standard: extensive, nothing missed, state-of-the-art and easy to use.

---

## 1. Principles

1. **Two apps, one truth.**
   - Agents keep working in the CRM: clock-in, sales, call logs.
   - People are managed in Trio HR.
   - HR reads the CRM's data directly and never keeps a second copy that can drift.
   - HR writes to the CRM only through a short list of guarded commands: create or switch off a login, publish a roster, approve a late clock-in.
2. **The system prepares, admins approve.**
   - Every staff request lands in **one Approvals inbox**, with the context needed to decide: balance after, who else is off that night, history.
   - Nothing reaches anyone's pay without an approval on record.
3. **Money is never recalculated after the fact.**
   - Payroll and incentives go **preview → approve → lock**.
   - At lock, the CRM numbers used are **snapshotted**. Later refunds or corrections post as adjustments in the next month. A closed month never changes.
   - **Two-person rule:** whoever prepares payroll cannot approve it.
4. **Every number explains itself.**
   - Each amount on a payslip or incentive statement has a "Why?" that traces it to its source: nights worked, the bookings behind an incentive, the TDS working.
   - Plain English everywhere. "Unpaid days", not "LOP"; no system codes on screen, ever.
5. **Law is data, not code.**
   - Tax slabs, minimum wages, leave rules, hour limits, ceilings and due dates are settings, each with an **effective date and a source**.
   - When the law changes mid-month (as the PF ceiling did on 17 Sep 2026), we add a row and nothing is rebuilt.
   - Anything legally unclear is a switch the CA decides, not a guess.
6. **Privacy built in.**
   - PAN, Aadhaar, bank details and salaries are **encrypted with an HR-only key** and masked on screen.
   - **Every view or download of pay or ID data is logged**, not just changes.
   - Exports are owner-only and watermarked.
7. **Night-shift native.**
   - Everything is dated by the **shift day**: the work night that starts at 6 pm, the CRM's business day.
   - Shift times show in IST and US Eastern side by side, including US daylight-saving changes.
8. **Two audiences, one app.**
   - Admins get a laptop-first console.
   - Staff get a **phone-first app** they can install on the home screen, with push notifications.
   - It is the same codebase, with nothing missing on either.
9. **Never twice, never silently.**
   - Every email or notification goes through an outbox that can't send the same thing twice.
   - Background jobs leave a heartbeat on a Health page.
   - Every failure shows a plain message plus a reference code.
10. **The Fumist quality bar.**
    - Restrained premium design and 16px reading text.
    - Records open as full pages.
    - Confirmations in plain words, with undo where it is safe.
    - Every screen is tested at four widths before it counts as done.

---

## 2. Who uses it, and how they sign in

### 2.1 Address

**`hr.triotoursandtravels.in`** (decided 14 Oct).
- **The group already owns this domain.** It is registered to Lets Fly Travel through Hostinger (17 Mar 2025, expires 17 Mar 2027) and is currently parked, so there is nothing to buy.
- **It also gives:**
  - an `hr@triotoursandtravels.in` mailbox, so HR emails come from Trio rather than Base Fare;
  - a careers page later, which fixes Trio's missing online presence for hiring.
- **Fallback:** `hr.base-fare.com`, if the domain can't point at the CRM's folder on the hosting. In that case a tiny forwarding file in the domain's own folder does the job.

### 2.2 Roles

| Role | Who | Can do |
|---|---|---|
| **Owner** | The client | Everything. Final approval on payroll, incentives, salary changes and exits |
| **Admin** | The client's wife (and any future HR admin) | Everything except approving their own payroll run or salary change |
| **Employee** | Every staff member, including team leaders and managers | Their own data and requests only. Never anyone else's pay |
| *Later, optional* | **CA (read-only, time-limited)**: tax and payroll reports only. **POSH committee member**: harassment cases only | Scoped access |

The admin screens are built simple from the start: big type, plain words, confirm sheets. That makes the wife's "Simple view" the default way the portal works, not a separate mode.

### 2.3 Signing in

Trio HR has its own accounts. **It never touches CRM logins or CRM sessions.** That matters because the CRM clocks agents out when the same account signs in elsewhere.

**Everyone**
- **Invitation:** an invite link sets the password.
- **Passwords:** strong hashing (Argon2id), lockout after repeated failures, and **forgotten-password reset by email**.
- **Security emails:** an alert on a new device.
- **Sessions:** a list of signed-in devices with "Sign out that device".

**Admins and the owner**
- **Email and password only. No authenticator app** (John, 16 Oct: "for godsake its an hr module").
- **30-minute idle timeout.**
- **Re-check before sensitive actions:** the password is asked again before viewing or changing bank details, salaries or ID numbers.

**Staff**
- **Optional emailed code on a new phone.** It is off by default; the owner can switch it on in Settings. Once used, the phone is remembered.
- Passkeys (Face ID / fingerprint) can come later.

**Leavers** keep a read-only "alumni" sign-in for 12 months, to download payslips and Form 130. Their CRM access ends on their last working day.

---

## 3. What admins see (the console)

### 3.1 Today: the home screen

- **Greeting**, then **"Needs you"**. Each item is one sentence and one button, worst first, built by the server. For example:
  - "**3 leave requests** waiting · oldest 2 days" → Review
  - "**Ravi's final settlement is due tomorrow** (2-working-day rule)" → Open settlement
  - "**October payroll:** attendance locked, incentives waiting for your approval" → Continue
  - "**2 people haven't signed their appointment letter**" → Remind them
  - "**TDS for October must be deposited by 7 Nov**" → Mark paid
  - "**Night-shift consent missing for 1 woman on tonight's roster**" → Fix roster
- **Tonight on the floor**, from the CRM, live: rostered, in, on break, not in, on leave. Plus late clock-in requests and **who is covering**.
- **This month:** headcount, joiners and leavers, payroll cost, incentive cost as a percentage of Net MCO, and attrition. Each figure has a plain-English note against last month.
- **Payroll tracker:** a stepper running Attendance locked → Incentives approved → Payroll reviewed → Owner approved → Paid → Payslips out. It shows the due date (salary before the 7th).
- **Compliance light:** green (all filed), amber (due within 7 days) or red (overdue), with the list behind it.
- **People moments:** birthdays, work anniversaries, probation ending, contracts ending, documents expiring.

### 3.2 Approvals: one inbox for everything

**What lands here:** leave, attendance corrections, shift and weekly-off changes, profile and bank-detail changes, letter requests, advances, reimbursements, resignations, document uploads, incentive questions and late clock-ins.

**How it is laid out**
- A list, with the full request on the right.
- Each request shows:
  - the **policy check** ("within balance", "needs a medical note");
  - the **balance after**;
  - **who else is off that night** (coverage);
  - the person's recent history.

**Acting on requests**
- **Approve**, or **Send back** with a reason chosen from chips.
- **Edit, then approve.** For example, approve 2 of 3 days.
- **Bulk approve** routine items.
- Keyboard shortcuts.
- **Time limits:** requests left too long turn amber, then red.
- **Delegation** when an admin is away.

**Bank-detail changes** are special:
- re-verified;
- a cooling-off period before the next salary;
- the employee is notified on every channel.

This is the most-used admin screen, because every request needs approval.

### 3.3 People

**Directory and org chart**
- Directory with photo, job, team, centre, shift and an "on shift now" dot.
- The org chart builds itself from reporting lines.
- Ctrl+K search finds people, documents and actions, for example "letter for Priya".

**Employee record**

A full page with sections:

| Section | Contents |
|---|---|
| Personal | Name, date of birth, gender, contacts, address, emergency contact |
| Job, with dated history | Designation; team leader; centre and work state (this drives which state's rules apply); employment type (permanent, fixed-term with end date, trainee); probation; notice period |
| Pay, with dated history | Fixed salary, revisions and arrears, incentive plan, bank (encrypted) |
| Statutory | PAN and its status (valid, inoperative, missing); Aadhaar (optional, vault, masked); UAN/ESIC if they ever apply; tax regime per year; nominees |
| Documents vault | Versions and expiry dates |
| Consents and acknowledgements | Each with its version |
| Assets | Equipment issued |
| CRM link | Their login, role and team in the CRM |

- **Timeline:** joined, confirmed, revised, warned, letters issued, leave, exits.
- **Every change is dated and logged.**
- **Profile-change requests:** staff edit their own details, an admin approves, and the screen shows exactly what changed.

### 3.4 Hiring and joining

**Hiring**
- **Openings**, approved by the owner.
- **Candidate pipeline**:
  - walk-in drive check-in;
  - a voice and English assessment scorecard;
  - interviews;
  - **source of hire**, which is later joined to 90-day retention.
- **Offers:** generated from templates, approved, then accepted online.
- **Careers page**, if Trio has its own domain.

**Joining.** The new joiner gets a link to a phone-friendly joining form. In it they:
- upload Aadhaar, PAN, bank proof, education and experience letters, and a photo;
- choose their tax regime;
- enter previous-employer income (Form 122);
- name nominees;
- sign the policies (privacy notice, code of conduct, POSH, card-data confidentiality / NDA);
- for women on nights, give a separate night-work consent.

**Admin checklist**
- **Verify documents.**
- **Bank check:** a ₹1 test transfer, or the name on the account matched against PAN.
- **Sign the appointment letter** (mandatory, and done before day 1).
- **Create the CRM login automatically:** role, team leader and roster.
- **Equipment:** headset and ID card issued, with acknowledgement.
- **First-week buddy.**

**After joining**
- **Probation is set automatically**, with a review reminder before it ends.
- Check-ins on **day 7, 30, 60 and 90**, where early leavers cluster in sales floors.

### 3.5 Attendance (captured in the CRM, managed in HR)

- **One attendance record per person per shift day**, built from the CRM's clock-ins and clock-outs.
  - A night that crosses midnight stays **one** record.
  - The raw clock-in and clock-out times are kept untouched, for audit.
- **Every day gets a status:** Present, Late, Short hours, Half day, Absent, Unpaid, On leave, Weekly off, Holiday or Comp-off.
  - **Lateness and penalty rules are shown before they apply.**
- **Exceptions queue:** sessions the CRM closed by itself, missed clock-outs, split nights and late-login overrides. Each is resolved once, with a reason.
  - Nothing in the CRM can do this today.
- **Staff can request a correction** from their phone ("I forgot to clock out at 03:10"). It takes four taps, needs admin approval, and has a limit on how far back.
- **Monthly lock before payroll.** After the lock, changes go to the next month as adjustments.
- **Overtime**, if ever used: consent recorded, paid at 2×, with a quarterly cap meter.

### 3.6 Rosters and shifts (moved out of the CRM)

- **Shift templates** shown in **IST and US Eastern**, aware of US daylight-saving dates (the IST time moves an hour twice a year).
- **Roster builder:** draft → **publish**, with notifications.
  - Published rows go into the CRM's existing roster table, which the clock-in gate already reads.
  - Weekly-off rotation, with a fairness report.
- **Staff requests:** shift change, weekly-off change and swaps, each checked against coverage and approved by an admin.
- **Guardrails.** The current law says a "24-hour roster" cannot be one person's duty. Each guardrail is set to **warn** or **block** by the client:
  - maximum daily hours (Punjab 10; 9 for women on the night-shift permission);
  - a 30-minute break after at most 5 hours;
  - a 12-hour spread-over;
  - 48 hours a week;
  - a weekly off every week;
  - minimum rest between nights;
  - consecutive-night limits.
- **Women on nights.** Rostering one checks for:
  - signed consent;
  - transport assigned;
  - in Mohali, the Labour Department night-shift permission and at least 5 women on that night.

  If any is missing, the roster says exactly what is missing.
- **Transport log,** if cabs are provided: vehicle, driver, guard, pickup and drop times, and safe-reach confirmation. No woman is picked up first or dropped last.

### 3.7 Leave and holidays

**Leave types**
- Earned/annual, casual, sick, maternity (26 weeks), paternity and bereavement (both optional by policy), comp-off, and unpaid.
- **Each state starts from its legal minimum.**
  - Mohali (Punjab): 1 earned day per 20 worked, 7 casual, 7 sick.
  - Jamshedpur (Jharkhand): earned leave after 240 days worked, 12 casual, 12 sick at half pay.
  - The client can be more generous, never less.

**Balances**
- **Accruals** that only grow by what the policy allows.
- **Carry-forward caps** of 30 days (45 in Jharkhand).
- **Encashment:** at year-end, and at exit within the 2-working-day deadline.
- **Holidays inside annual leave don't count against it.**

**Applying for leave**
- A balance preview, half days and attachments.
- A **"who else is off that night"** warning.
- Withdrawal and cancellation. An approval after the fact reverses any unpaid day automatically.

**Holiday calendars** per state:
- 3 national holidays plus the state's festival holidays, with double pay if worked;
- polling days;
- separately, a **US-peak operations calendar**, because US holidays drive the workload.

**Calendars:** a team and company leave calendar.

### 3.8 Payroll

**Salary structures**
- Mostly "fixed monthly", but the structure is flexible.
- **Minimum-wage check** by state and category.
- A **"statutory wages" calculator** that applies the new 50% rule. It runs even though PF/ESI are switched off, so the CA can see the exposure.

**The monthly run is a guided record page with steps:**

1. **Attendance.** Lock the month and see payable days. Unpaid days are explained person by person.
2. **Incentives.** Pull in the approved, locked incentive statements, read-only.
3. **Changes.** Arrears, advances recovered, reimbursements, one-off additions and deductions.
   - Each needs a reason.
   - The **50% deduction cap** and the list of legally allowed deductions are enforced.
4. **Tax.** TDS is calculated per person (see §3.10).
5. **Review: "How does it compare?"**
   - The top changes against last month, in words. For example: "+₹38,400: September incentives higher for 6 agents".
   - Drill down to a person or a line.
   - **Pre-run checks**, each **blocking** (red) or **warning** (amber):
     - missing bank details or PAN;
     - unverified bank account;
     - joiners after the cut-off;
     - exits without a final settlement;
     - pending approvals;
     - negative pay;
     - deductions over 50%;
     - unsigned incentive plans.
   - Dry-run payslips.
6. **Approve.** The admin submits and the **owner approves**. The confirm sheet reads, for example: "This pays ₹4,12,300 to 31 people on 6 Nov from HDFC ••4512".
7. **Pay.** A bank bulk-transfer file in the bank's format, then payment status per person.
8. **Payslips out.** Published only after the bank confirms, in batches if needed. Each person is notified.

**Payslips**
- An A4 PDF on Trio's letterhead design, plus an in-app view.
- Laid out as a story: gross → each deduction → net.
- Each line has a "Why?" and a "changed since last month" marker.
- YTD figures and the tax working.

**Other runs and controls**
- **Holds:** hold one person's pay (for example, someone who has absconded) with a reason.
- **Off-cycle runs** for final settlements and corrections.
- **Reports:** a salary register and payroll cost by centre, team and shift.
- **Locked months are never recalculated.**

### 3.9 Incentives (modelled on sales-commission software)

**Plans**
- Each plan has versions with **effective dates**.
- **Each agent signs the version they're on.** A statement stays hidden until it is signed, and any change needs a new signature.

**Plan building blocks.** The client sets the values; we build the engine.
- **Base:** slabs on **Net MCO** (marginal slabs are recommended, because cost stays predictable; cliff slabs are optional).
- **Above or below target:** an accelerator above target, an optional reduced rate below a floor.
- **Targets** by tenure (new, ramping, tenured).
- **Gates**, for example "attendance ≥ 90% of rostered nights" or "no more than 2 unplanned absences".
- **Optional pieces:**
  - a monthly cap;
  - an outlier review for very large bookings;
  - short contests (SPIFs);
  - a team-leader override on team results;
  - an attendance incentive;
  - a nesting stipend.

**Credits come straight from the CRM.** Each approved booking's **Net MCO** is credited to its agent by **shift month**, using the CRM's refund-loss rule.

**Live statement for each agent, on their phone**
- Net MCO so far, target, attainment and incentive earned.
- **Gate status** ("✓ attendance 96% of 90% needed").
- The slab ladder: "you are here", and "₹X more MCO to the next slab".
- The bookings behind it, and a **what-if calculator**.
- "Ask about this line" opens a query with the booking attached.

**Month close**
- **Day 1–2:** the CRM month closes and the numbers freeze and are snapshotted.
- **Day 2–3:** agents check their final statement and raise last queries.
- **Day 3–4:** the admin, then the owner, approves.
- **Lock.** Each component goes to payroll as its own payslip line.

**Refund and chargeback risk**
- An optional **held reserve**: pay P%, hold the rest until a release date.
- A **clawback window**.
- A refund or chargeback inside the window posts as **a negative credit in the month it happens**. Closed months are never reopened.
- It comes off future incentives or the reserve, **never off base salary without the CA's or lawyer's sign-off**, and always within the 50% deduction cap.

**Data the CRM must get right first** (see §6):
- duplicate bookings;
- several currencies added together raw (needs a fixed monthly exchange rate per currency);
- agents able to edit money fields after approval;
- chargebacks not linked to agents;
- credit going to whoever *recorded* the sale.

### 3.10 Tax (TDS on salary and incentives)

Under the **Income-tax Act 2025** (in force from 1 Apr 2026), TDS on salary is now **section 392**, and incentives paid to employees are salary.

- **Regime per employee per tax year.** The new regime is the default. The old regime applies only by declaration, which is then locked for the year (an admin can override it, with an audit trail).
- **Monthly TDS**
  - The "average rate on estimated annual income" method, re-estimated every run, so it corrects itself.
  - **Incentives** are taxed by default in the month they're paid (the extra tax they cause); spreading is an option.
  - **Most agents will owe nil.** Salary plus incentives up to about ₹12.75 lakh a year is tax-free under the default regime. The system still calculates and reports everyone.
- **Inputs from the employee**
  - Previous employer's salary and TDS for mid-year joiners (**Form 122**).
  - Old-regime declarations and proofs (**Form 124**), with a proof window (mid-January to end of February).
- **PAN:** valid, inoperative or missing. The higher no-PAN rate applies when tax is due.
- **For the CA**
  - **Monthly:** the TDS register and challan tracking (deposit by the 7th; March by 30 April).
  - **Quarterly:** **Form 138** data (the old 24Q), due 31 Jul, 31 Oct, 31 Jan and 31 May.
  - **Yearly:** **Form 130** (the old Form 16), issued by 15 June.
- **Every tax amount on a payslip opens its full working.**

### 3.11 Advances, loans and reimbursements

- **Salary advances and loans.** The employee requests, an admin approves, and a repayment schedule is created. Repayments come out of payroll automatically, always within the 50% deduction cap. The balance shows on both sides.
- **Reimbursements** (night meals, cabs, phone and internet), if the client wants them: receipt photo, limits and approval. Paid with salary or separately.

### 3.12 Letters and documents

- **Templates on Trio's letterhead design:**
  - offer and appointment;
  - confirmation and extension of probation;
  - increment, promotion and transfer/shift change;
  - show-cause, warning and performance-improvement plan;
  - relieving and experience;
  - final settlement statement;
  - salary certificate and address proof.
  - The current Letter of Intent becomes one of these.
- **Every letter:**
  - is filled from the employee record;
  - is checked by a second admin before it is issued;
  - is **numbered and archived**;
  - is acknowledged by the employee with a click (Aadhaar eSign can be added later);
  - carries a **verification code or QR**, so background-check calls can confirm it's genuine.
- **Bulk generation**, for example annual increments.
- **The appointment-letter catch-up.** The new law makes written appointment letters mandatory. Existing staff without one get theirs issued in one run.
- **Staff can request a letter** (for example a salary certificate) from their phone. An admin approves, and the employee downloads it.

### 3.13 Policies, announcements and help

- **Policy library:**
  - handbook, attendance, leave, incentive plan, POSH, data and card security, conduct, night-shift safety, transport and grievance;
  - each has **versions**;
  - staff **acknowledge a specific version**, and re-acknowledge when it changes;
  - a completion dashboard with reminders.
- **Announcements:** targeted by centre, shift or role, with read receipts, pinning and expiry. A "Tonight" message for the floor.
- **Help desk:**
  - staff raise a question (pay, attendance, documents, other), with time limits and a reply thread;
  - **grievance committee** process (required at 20+ workers);
  - optional anonymous reporting.
- **POSH:**
  - Internal Committee per centre, required at 10+ staff;
  - confidential complaint intake visible only to committee members;
  - the inquiry timeline;
  - the annual report and the yearly disclosures the company now has to include in its Board report.

### 3.14 Performance and conduct

- **Monthly scorecard per agent, filled automatically from the CRM:** Net MCO, bookings, refunds, call logs, attendance, punctuality. Nobody types anything.
- **Probation:** a review form, then confirm or extend, with the letter generated.
- **Improvement plans:** goals, check-ins, acknowledgement and outcome.
- **Warnings and show-cause notices:** the 7-day-reply process, kept on the record.
- **Coaching notes, kudos and increment reviews** come in a later phase.

### 3.15 Exit and final settlement

**Resignation**
- The employee resigns from their phone and picks a reason.
- An admin accepts it. The last working day is worked out from the notice rules. A buyout or waiver is recorded.

**Exit checklist**
- Equipment back.
- The CRM login switched off at the last working time. The agent is clocked out first.
- Card-data access removed and timestamped (PCI rule).
- Handover.

**Exit interview** with coded reasons, which feed the attrition reports.

**Final settlement**
- **Calculation:** pay to the last day, leave encashment, incentives (the reserve released net of open risk), advances recovered within legal limits, gratuity if eligible, and final TDS.
- **A 2-working-day countdown**, because the law now requires wages within 2 working days. It escalates to the owner.

**After exit**
- Relieving and experience letters.
- Alumni access to their documents.
- A rehire flag.
- **An absconding process:** notices, then separation.

### 3.16 Compliance centre

- **Calendar with reminders:**
  - salary before the 7th;
  - TDS by the 7th;
  - Form 138 quarterly;
  - Form 130 by 15 June;
  - PSDT/PT/PF/ESI if the CA switches them on;
  - the POSH annual report;
  - minimum-wage revisions.
- **The law as data:** a table of every rate, ceiling and limit with its source and status (in force, draft, superseded). A "What changed in the law" log shows what the system updated and when.
- **Registers:**
  - the wage, attendance, leave and overtime registers in each state's format (old state forms until the new state rules are final);
  - the night-shift consent register;
  - transport registers.
- **Thresholds:** an alert when headcount crosses 10, 20 or 50. Those numbers trigger PF, POSH, the grievance committee and the crèche rule.
- **Establishment details per centre:** shop registration, the night-shift permission and its conditions, committee members and vendor licences, with expiry reminders.
- **Data protection:**
  - a staff privacy notice;
  - how long each kind of record is kept (8 years by default);
  - a scheduled clean-up with legal holds;
  - a breach-response checklist;
  - ready well before the full rules apply on **13 May 2027**.

### 3.17 Reports and insights

- **Owner dashboard:** five numbers with trends and plain-English notes.
- **Attrition:** monthly, yearly and **within the first 90 days**, by hiring batch, source, team leader and shift, with coded exit reasons.
- **Attendance:** punctuality and unplanned-leave rate.
- **Cost:** payroll and incentive cost as a percentage of Net MCO, by team.
- **Liabilities:** leave and gratuity.
- **Reports written as questions**, for example "Who was late most this month?". Reports can be saved and emailed on a schedule.
- **Every export is logged and owner-only.**

### 3.18 Settings and security

- **Company and centres:** Trio's details, TAN/PAN, signatory and bank, plus each centre's state.
- **Policies and rule tables:** leave, attendance, holidays, tax, incentive plans, and the statutory values with effective dates.
- **Templates:** letters and notification templates.
- **People and access:** roles and the "who can do what" matrix, with delegations.
- **Automation:** switches for each automation, plus a **dry run** that shows who *would* get what.
- **Safety:** sessions, the full audit log (including who *viewed* salary or ID data) and the System health page.

---

## 4. What staff see (the phone app)

Bottom tabs: **Home · Attendance · Leave · Pay · More**.

### Home: "Tonight"

- Tonight's shift in IST and US Eastern, and whether they're clocked in (from the CRM).
- Incentive so far this month, with gate status.
- Leave balance.
- Their requests and their status.
- Four quick actions.
- Items that need them, for example "You missed a clock-out on 12 Oct: Fix it", or "Sign the new leave policy".

### The other tabs

| Tab | What's in it |
|---|---|
| **Attendance** | Their month, night by night. Correct a night in four taps. Roster and swap requests |
| **Leave** | Balances, apply in **three taps**, team calendar, holidays |
| **Pay** | Payslips with "Why?" on every line. Live incentive statement and what-if. Tax regime, declarations and proofs. Form 130. Advances. Reimbursements |
| **More** | Documents and letters (request a certificate). Policies to acknowledge. Announcements. Help desk. Profile change requests. Resignation. Security (devices, password) |

### Notifications

- **Quiet hours** while night staff sleep in the day.
- Push or email only for things that matter: request decided, payslip ready, shift changed.
- **WhatsApp** can be added later for the top three.

### Speed targets, checked in testing

| Task | Target |
|---|---|
| Apply for leave | 3 taps |
| Fix a missed clock-out | 4 taps |
| Open a payslip and understand a line | 2 taps |
| Approve a routine request (admin) | 2 taps |
| Run payroll for 40 people with no exceptions | 20 minutes |
| Owner's monthly approvals | 5 minutes |

---

## 5. The automations

### A. People lifecycle

| # | When | What happens |
|---|---|---|
| A1 | Offer accepted | Joining link sent, document checklist started, reminders every 2 days |
| A2 | Joining form complete | Admin verification task, bank test transfer, appointment letter drafted for approval |
| A3 | Appointment letter signed | CRM login created (role, team leader), roster slots offered, equipment checklist, buddy assigned, welcome announcement |
| A4 | Day 7 / 30 / 60 / 90 | Check-in tasks for the admin; early-attrition watch |
| A5 | Probation ends in 14 days | Review form for the admin; confirmation or extension letter drafted |
| A6 | Fixed-term contract ends in 30 days | Renew or exit task |
| A7 | Document or consent expires in 30 days | Reminder to the employee, then to the admin |
| A8 | Resignation accepted | Exit checklist, last-working-day countdown, settlement drafted, CRM access switched off at the last working time |
| A9 | Last working day | 2-working-day settlement timer starts, escalating to the owner |

### B. Time

| # | When | What happens |
|---|---|---|
| B1 | Every night after shift end | Attendance built from CRM sessions; exceptions queued; employee told about a missing clock-out the same morning |
| B2 | Late clock-in approved in the CRM | Mirrored into HR for the payroll record |
| B3 | Roster published | Staff notified; CRM roster rows written; coverage gaps flagged |
| B4 | Roster about to run out (7 days) | Admin reminder. Today the 24/7 roster silently expires |
| B5 | Leave approved | Balance updated, roster shows them off, team calendar updated |
| B6 | Month end | Attendance lock reminder; payable-days preview |

### C. Money

| # | When | What happens |
|---|---|---|
| C1 | Every 15 min during the month | Incentive statements refreshed from the CRM; gate status updated |
| C2 | Month day 1–4 | Incentive freeze, agent review, approval reminders |
| C3 | Payroll cut-off | Pre-run checks; draft run prepared; owner told it's ready to approve |
| C4 | Bank confirms | Payslips published and staff notified; TDS register updated |
| C5 | 5th of the month | "TDS deposit due by 7th" reminder with the amount |
| C6 | Refund or chargeback in the CRM | Negative incentive credit posted in the current month, with the reason shown on the agent's statement |
| C7 | Reserve release date | Held incentive released into the next payroll |
| C8 | Old-regime employees, January | Proof window opens; reminders; claims without proof dropped with notice |

### D. Compliance

| # | When | What happens |
|---|---|---|
| D1 | Daily | Compliance calendar checked, then the owner digest |
| D2 | Headcount crosses a threshold | Alert naming what the new number triggers |
| D3 | A law value is entered with a past date | Arrears calculated automatically |
| D4 | Retention period ends | Purge job, unless the record is under a legal hold |

### E. The owner's assistant

| # | When | What happens |
|---|---|---|
| E1 | Daily 10:00 | Morning digest: last night's attendance, requests waiting, money due, deadlines |
| E2 | Monday | Weekly digest: joiners, leavers, attrition, incentive cost against Net MCO, compliance |
| E3 | Something unusual | Alerts: absence spike, a bank detail changed just before payroll, unusually large incentives |
| E4 | Every job run | Heartbeat. Health page flags silent jobs, and the live check runs overdue jobs as a fallback |

---

## 6. How it links to the CRM

### 6.1 Move, stay or share (from the CRM audit)

| CRM feature today | Decision | Notes |
|---|---|---|
| Salary Slips (`/payroll`) | **Move** | Replaced by real payroll. Keep the old page until the first HR payslips are approved |
| Letter of Intent (`/letters`) | **Move** | Becomes one of the HR letter templates. Keep a fallback until HR letters render correctly |
| Monthly attendance report, payroll tab, CSV | **Move** | Replaced by the HR attendance register, which knows about rosters, leave and holidays |
| Shift Schedule: templates, week grid, publish | **Move** (authoring) | HR writes the same roster table that the CRM clock-in gate reads |
| 24/7 roster script and shift-gap alert | **Retire** | Kept as an emergency fallback until HR rosters are live |
| Add user, delete, switch off, reactivate, name/email | **Move** | HR is the master. It calls the CRM's own user service, so the rules and logs stay identical |
| Role, reporting line, password reset, email signature | **Stay** | Reporting lines decide who sees whose data inside the CRM |
| Late-login grace and break thresholds | **Move** (the setting) | Fixes today's conflict, where 24/7 agents can't be edited because their 720-minute grace exceeds the form's limit |
| Clock-in page, gate, breaks, clock-out | **Stay** | Agents work in the CRM |
| Live board, late clock-in approvals, admin clock-in/out | **Share** | Unblocking agents at 2 am must stay in the CRM. Each action also lands in HR for payroll review |
| Per-day attendance history, "My Attendance" | **Share** | CRM shows; HR adds corrections and leave |
| Auto clock-out job | **Share** | Keeps running; HR resolves what it flags |
| Performance, call logs, centres, IP whitelist, login rules | **Stay** | Sales operations |
| Simple view | **Stay, re-pointed** | Its Team page and approvals for shifts and clock-ins link into HR |

### 6.2 What changes in the CRM

**Removed or redirected**
- Sidebar links: Salary Slips, Letter of Intent, Monthly Report, Shift Schedule, and Users → Add.

**Re-pointed in the Simple view**
- "Add a person", "Turn off login", Edit and the shift approvals now link into HR.

**Added**
- An **"HR ↗" link** in every sidebar: staff go to their HR app, admins to the console.
- **HR notifications appear in the CRM bell** for agents, for example "Your leave for 12 Nov was approved".
- **The clock-in page** shows "You're on approved leave tonight" when relevant.

### 6.3 The bridge (HR → CRM writes)

HR changes the CRM only through these guarded commands. Each one is logged in both apps.
1. Create, switch off or reactivate a CRM login, through the CRM's own user service.
2. Clock someone out before switching off their login.
3. Publish roster rows (published only, so drafts never block clock-in).
4. Set the late-login grace window from the attendance policy.
5. Post a notification to an employee's CRM bell.

Everything else is **read-only**: clock-ins and breaks, rosters, bookings and their notes, refunds, call logs and the business-day setting.

---

## 7. CRM fixes needed before the first HR payroll

The CRM audit found that **attendance is not payroll-grade today**. This was found by reading the code. The read-only checks below confirm it on live data before anything is built.

**Attendance**
1. **Early clock-ins under the 24/7 roster.** A clock-in before 6 pm is auto-closed around 7 pm with about an hour credited, and the agent is then locked out.
2. **Forgotten clock-outs** under the 24/7 roster get **zero** hours. No screen exists to fix them.
3. **Agents can't clock themselves in between midnight and 6 am** under the 24/7 roster.
4. **The monthly report drops anyone suspended or deleted** from the month they worked, and calls every day without a session "Absent".
5. **24/7 agents can't be edited** in Users → Edit (grace 720 vs a 120 limit). New hires get 30 minutes of grace and no roster.
6. **The 24/7 roster ran out on 30 Aug** unless someone re-ran the script.
7. **Two mobile admin bugs.** Late-login approvals made between midnight and 5:30 am IST land on the wrong date. The mobile "Clock In" button always fails silently.

**Sales data that feeds incentives**
8. Duplicate bookings are possible.
9. Currencies are added together raw.
10. Agents can still change money fields after approval.
11. An admin status change made through the edit form isn't logged.

**Before any salary or ID data goes into the database**
- **Rotate the database password.** It was exposed in July and still unrotated as of 28 Aug, the last note.
- **Set up the staging subdomain.**

The read-only check queries are ready, in research file 02, §7. **This is Phase 0.**

---

## 8. How it's built

### 8.1 Code layout

The same repository, a second front door:

```
public_hr/            ← hr.<domain> points here (its own web root)
  index.html, js/, css/, .htaccess   (no build step; strict security policy)
  api/index.php       ← one entry point for the HR API, one error handler
app/Hr/               ← HR code (the existing autoloader already covers it)
  Auth/  Domain/{People,Attendance,Roster,Leave,Payroll,Incentives,Tax,Letters,...}
  Crm/                ← the bridge: read queries + the 5 guarded write commands
database/migrations/2026_xx_xx_hr_*.sql   ← same migration runner, ledger and pre-backup
cron/hr_*.php         ← plus fallback triggers from the live check
```

### 8.2 Why this layout

- **One rulebook.** One set of business rules (Net MCO, business day, centres), one migration ledger, one nightly backup, and one deploy: `git pull` + `php hostinger_migrate.php`, exactly as today.
- **Separate where it matters.** HR has its own login tables, session cookie, encryption key, security policy, error tagging and mailbox. HR tables live in the same database on a separately named connection, so moving them to their own database later is a config change.
- **Options ruled out:**
  - a separate repo (duplicate rules that drift, and two migration ledgers on one database);
  - routing HR inside the CRM app (changes the live CRM startup code);
  - a separate database today (the hosting can't join across databases).

### 8.3 Front end

The Fumist approach: plain JavaScript modules, one stylesheet and a JSON API. There is no build step, which suits the hosting.

**Kept from Fumist**
- **Shell:** the app shell and the core helpers.
- **Screens:** the page pattern; the server tells each screen which actions are allowed right now.
- **Live data:** 15-second live updates, the command palette, the notification centre and keyboard shortcuts.
- **Feedback:** confirm sheets and undo toasts.
- **Background work:** the outbox and the heartbeats.

**Fixed on top of it**
- **Routes:** one route list that generates the menu, search shortcuts and tests, with screens loaded only when opened.
- **Tokens:** strict type and spacing tokens, so there is no size drift.
- **Contrast:** darker focus rings and field borders, passing accessibility contrast.
- **Labels:** a label list so no system code ever shows on screen.
- **Phone layouts:** phone lists keep their labels, and filters go in a sheet.
- **Errors:** one API error handler.
- **Sign-in:** the gaps in Fumist's sign-in are filled (idle timeout, forgotten password, new-device alert, session list, re-check for sensitive actions).

### 8.4 Security and quality

**Security**
- Strict security policy with no inline code.
- JSON-only saves, plus a clear message when the hosting firewall blocks one.
- A role check **and a per-person data check on every endpoint**.
- Encryption with libsodium and an HR-only key kept outside the database.
- Pay calculations on the server only, in whole paise.
- Short database transactions (the host drops idle connections).
- No email sent inside a transaction.

**Testing**
- A Playwright sweep of every screen at 1440, 1024, 768 and 390 px under the production security policy. Any JavaScript error, blocked request, failed call or sideways scroll fails it.
- Flow tests that read the emails sent.
- Around 80 security checks: another person's IDs, CSRF, role gates, rate limits.
- Payroll and TDS engine tests built on the CA's worked examples and the 21 tax edge cases the research listed.
- Every screen checked by eye on the real URL before it counts as done.
- **One shadow month:** HR payroll runs alongside the current process, and the two are compared, before HR pays anyone.

---

## 9. Phases

| Phase | Contents | Rough effort |
|---|---|---|
| **0. Groundwork** | Read-only live checks. Fix the CRM attendance issues in §7. Staging subdomain. Database password rotation (John). Domain and mailbox decision and setup | 2–3 days |
| **1. Foundation, people and letters** | Portal shell and design system. Sign-in with every security feature. Audit log (including views). Encryption vault. Outbox, heartbeats and health. Settings (company, centres, law tables). People (records, documents, directory, org chart, timeline, CRM link, change requests). Approvals inbox. Today and the staff Home. Letters on Trio letterhead, including the appointment-letter catch-up. Policies and acknowledgements (privacy notice, POSH, card-data NDA, night consent). **Invite every employee to fill in their own profile**, which gives the fresh start without importing old sheets. LOI moves out of the CRM | 6–8 days |
| **2. Time** | Attendance register from CRM clock-ins, exceptions queue and corrections. Rosters and shifts moved from the CRM (IST + US time, publish, guardrails, swaps). Leave and holidays per state, accruals, coverage check, comp-off. Monthly attendance lock. Consent and transport registers if cabs run. Monthly report and roster authoring move out of the CRM | 6–8 days |
| **3. Money** | Salary structures with the minimum-wage check. The payroll run (steps, checks, "How does it compare?", two-person approval, lock). Payslips with "Why?". Bank file and salary register. The TDS engine and CA reports (Form 138 data, challans, Form 130). Advances and reimbursements. Incentives (plans and sign-off, CRM credits, live statements and what-if, queries, approvals, lock, reserve and clawbacks). Final-settlement engine. **One shadow month**, then live. The slip maker moves out of the CRM | 8–10 days |
| **4. Lifecycle and insight** | Hiring (pipeline, walk-ins, assessments, offers, careers page). Joining form and checklists. Equipment. Probation, improvement plans and warnings. CRM scorecards. Exit (resignation, checklist, exit interview, 2-day settlement timer, letters, alumni access). Help desk and grievances. POSH module. Announcements. Compliance centre. Reports and owner insights. Digests. Optional: WhatsApp notifications, AI "explain this" | 6–8 days |

**Shipping and timing**
- Each phase ships on its own, is tested end to end, is audited, and is shown to the client before the next one starts.
- **Suggested timing:** Phases 0–2 in November. Phase 3 shadow-runs November payroll, then goes live for **December pay (paid before 7 Jan 2027)**. Phase 4 follows.

---

## 10. Needed from the client

1. **Address.** Decided: `hr.triotoursandtravels.in`, which the group already owns. Still needed: connect it to the hosting and create an `hr@` mailbox.
2. **Company details**
   - Trio's **TAN** and **PAN**.
   - The **authorised signatory** (name and designation) for letters and Form 130.
   - The **salary bank account**, which decides the bank-file format.
3. **Centres**
   - The city and state of **DMR** and **JSR** (the research assumed JSR = Jamshedpur, Jharkhand).
   - Each centre's **shop registration number**.
4. **Staff list.** Name, work email and phone for everyone. HR invites them to fill in the rest themselves.
5. **Pay rules**
   - Everyone's fixed monthly salary.
   - Pay day: before the 7th; we suggest the **6th**.
   - Any late or absence penalties they use today.
   - Weekly-off pattern.
   - Leave allowances: the state minimums or more.
6. **Incentive scheme.** How incentives are worked out today, in full:
   - slabs and targets;
   - which bookings count;
   - currencies;
   - what happens on refunds and chargebacks;
   - team-leader share;
   - attendance conditions;
   - when it's paid.
7. **Two-person rule.** Who prepares payroll and who approves it (suggestion: the wife prepares, the client approves).
8. **The CA.** Their contact, to answer the questions in §11. Is anyone filing Form 138 today? The Q2 return is due **31 Oct 2026**.
9. **Rosters.** Replace the 24/7 roster with defined night shifts. That is needed for lawful hours and for attendance that can be used for pay.
10. **Night shift.** Are cabs provided? Does the Mohali centre hold the Labour Department's women's night-shift permission?
11. **First HR payroll month.** Suggested: November in shadow, December live.

---

## 11. Legal and tax points for the CA

This is a summary of the research, not legal advice. Each point has sources in research files 03/04/05. Every item below becomes a setting the CA can switch, not a guess built into code.

1. **TDS section.** The CRM's current slip maker deducts a **flat 5% under "194H"**. That is the commission rule for *non-employees*. For employees, incentives are salary under **s.392** (formerly 192), taxed at the average rate on estimated income. If that is how incentives have actually been taxed, the CA should review it.
   - The next deadlines are **Form 138 for Q2 by 31 Oct 2026** and **October TDS by 7 Nov 2026**.
2. **PF.** The wage ceiling **rose to ₹25,000 a month from 17 Sep 2026** (S.O. 5109(E)) ([SCC Online](https://www.scconline.com/blog/post/2026/09/19/labour-ministry-notifies-25000-wage-ceiling-under-social-security-code/), [BDO](https://www.bdo.in/en-gb/insights/alerts-updates/alert-new-epf-ceiling-hiked)). PF applies to establishments with **20+ employees**, and covers anyone whose "wages" are at or below the ceiling. That may include agents on about ₹25k. The EPF enrolment campaign **ends 31 Oct 2026**.
3. **ESI.** Applies at 10+ employees, with a ₹21,000 ceiling, in notified areas. Check SAS Nagar (Mohali) and East Singhbhum (Jamshedpur).
4. **Punjab State Development Tax.** ₹200 a month on salaried income-tax payees, deducted by the employer. It needs registration and a yearly return (PSDT-6 by 30 April). Which reading of "income-tax payee" applies is for the CA.
5. **Other state levies.** Jharkhand professional tax and the Punjab Labour Welfare Fund, if applicable.
6. **Incentive or commission?** Under the new 50% wage rule, the answer changes PF, ESI, gratuity and bonus calculations.
7. **Labour Codes (in force since 21 Nov 2025)**
   - Salary before the 7th.
   - **Final pay within 2 working days** of exit.
   - Deductions only from the legal list, capped at 50%; fines only under an approved notice.
   - **Written appointment letters are mandatory**, including for existing staff.
8. **Women on night shifts in Mohali.** The Punjab shops law bars 8 pm–6 am unless the Labour Department has granted a permission. That permission comes with 19 conditions, including at least 5 women per night, guards at boarding, transport registers, and no first pickup or last drop. Each woman's written, revocable consent is also needed.
9. **Hours.** Punjab allows 10 hours a day, a break after 5 hours, 48 hours a week, overtime at 2× up to 144 hours a quarter, and a weekly off. **A 24-hour roster can't be one person's duty.**
10. **Minimum wages.** Punjab table from 1 May 2026; Jharkhand Zone A from 1 Apr 2026, with an October revision pending.
11. **POSH and grievances.** A POSH Internal Committee at each centre with 10+ staff, and an annual report. A Grievance Redressal Committee at 20+ workers.
12. **Leave and holidays.** State minimums (Punjab: 1 earned day per 20 worked, 7 casual, 7 sick; Jharkhand: earned leave after 240 days, 12 casual, 12 sick at half pay). 3 national holidays plus festival holidays, at 2× if worked.
13. **Gratuity and maternity.** Gratuity after 5 years, or 1 year for fixed-term staff. Maternity leave is 26 weeks.
14. **Data protection.** The DPDP Act's main duties apply from **13 May 2027**. Aadhaar must stay optional and masked.
15. **Card-data rules (PCI).** Background screening before card-data access, yearly security training, and **access removed immediately at exit**. HR triggers all three.

---

## 12. Risks and how we handle them

| Risk | Handling |
|---|---|
| Attendance data isn't payroll-grade (§7) | Phase 0 fixes it, the live checks confirm it, and a shadow month runs before live pay |
| The hosting firewall blocks some saves from staff phones or home internet | JSON-only saves; a clear "blocked" message with a reference; testing from each centre's network and from mobile data before launch. We can't allowlist phones the way we did for Mohali's office IP |
| Legal gaps found (night shift, hours, PF) may need operational changes | Guardrails start in **warn** mode and switch to **block** when the client is ready. The CA and counsel decide each point |
| Only two admins for the two-person rule | Delegation and time limits. The owner can approve from a phone in under 5 minutes |
| Salary and ID data on shared hosting whose database password was exposed in July | Rotate first. HR-only encryption key. View and export logging. Owner-only exports |
| Scope is large | Four phases, each usable on its own. The highest-value pieces (people, letters, attendance, payroll) come first |
| Recovering incentive clawbacks from pay is legally sensitive | Never taken from base salary without counsel's sign-off; always within the 50% cap |
| Email limits on the hosting mailbox | Fine for about 40 staff. A Trio mailbox keeps HR mail separate. The outbox throttles sending |

---

## 13. Design direction

The visual concept is in **`docs/hr/trio-hr-concept.html`**. Open it in a browser to see the console and the phone app rendered.

**Colours, from Trio's letterhead**
- **Trio navy `#0B2353`** is the one dark anchor: the sidebar and the sign-in screen.
- **Trio gold `#A8842C`** is the one accent: "you are here", highlights and anything the AI wrote. A deeper gold is used wherever gold is text.
- Warm ivory page, white cards, hairline borders.
- Semantic colour pairs (green, amber, red, blue).

**Type**
- **Hanken Grotesk** for everything you read, on a fixed token scale. Body text is 16px and nothing is under 12px (the Sportagon/Fumist discipline).
- **EB Garamond** only for brand moments (greetings, sign-in, letters), matching the letterhead's Garamond wordmark.

**Layout**
- Records open as full pages.
- Quick actions use drawers on desktop and bottom sheets on phone.
- Tabular numbers, Indian digit grouping (₹1,23,456) and dates in words (Tue, 12 Nov).

**Rules**
- Every text colour passes WCAG AA.
- Focus rings and field borders pass 3:1.
- Light theme by default; dark mode follows the phone's setting.
