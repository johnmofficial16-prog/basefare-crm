# Session Handoff — 15 October 2026

This covers 5–15 Oct. `MEMORY.md` + `memory/` carry the durable facts and load automatically. **This document is the action list.** The previous handoff is archived as `HANDOFF-2026-10-05.md`; its open items are carried into §6.

**The CRM never auto-deploys.** John pulls by hand: `cd ~/domains/base-fare.com/public_html/crm && git pull origin dev`, then `php hostinger_migrate.php` when there's a migration.

**Production:**
- Believed to be at **`b6a7ef2`** (Simple view + audit fixes). John pulled and migrated on 12 Oct and created his wife's Simple view account; "it worked after relogging in".
- **`408491d`** (HR plan + Phase 0 check script) was pushed on 14 Oct. **Not confirmed pulled.**

---

## 0. START HERE — where things stand (15 Oct)

**The HR portal plan is APPROVED** ("proceed as recommended", 14 Oct). The plan is `HR_PLAN.md`.

On 15 Oct John asked to **see the HR module running on a local server first**, using no server that belongs to another process. The local build had **just started** when this handoff was written; **no HR portal code exists yet.** Continue with §2.

| # | Item | State / next action |
|---|---|---|
| 1 | **Build the HR portal locally (Phase 1 foundation) and show it to John** | Just started. Layout and ports are in §2. Show it in the browser pane at `http://127.0.0.1:8471`. Nothing goes to the live server until John has seen it locally and approves |
| 2 | **Phase 0, step 1: John runs the read-only check on the server** | `git pull origin dev` then `php scripts/hr_phase0_checks.php`, and he pastes the output. **Not run yet.** The CRM attendance fixes (§4) depend on what it shows |
| 3 | **Client message with deadlines for their CA** | Drafted in chat on 14 Oct. **Unknown whether sent.** It covers: Form 138 (old 24Q) for Q2 due **31 Oct**; incentives are salary TDS (s.392), not the 5% "194H" the old slip maker used; PF ceiling ₹25,000 from 17 Sep 2026, with the EPF enrolment campaign ending **31 Oct**; Punjab PSDT ₹200/month; night-shift permission for women in Mohali |
| 4 | **John's steps after the checks, one at a time** | (a) Rotate the DB password (exposed July, still unrotated). (b) Create the staging subdomain (`STAGING.md`). (c) Connect `triotoursandtravels.in` to the hosting, create `hr.` and an `hr@` mailbox |
| 5 | **Client inputs needed** | Listed in `HR_PLAN.md` §10: TAN/PAN, signatory, salary bank, DMR & JSR cities, staff list, salaries, pay day, incentive rules, who prepares and who approves payroll, CA contact, cabs yes/no |

---

## 1. What shipped since 5 Oct

| Commit | What |
|---|---|
| `ef8bbd5` | **Fix: "Record Transaction" silently did nothing** for split-charge bookings (null-safe `.trim()`; a "nothing was saved" alert). Agent confirmed it works |
| `b7fb596` + `bfec501` | **Reissuance type** with the Future Travel Voucher. **Live and migrated 8 Oct.** The migration also added `award_booking` and restored 8 Award acceptances that had a blank type. `memory/exchange-future-travel-voucher.md` |
| `62d343f` + `b6a7ef2` | **Simple view**, the easy owner's interface for the client's wife: a normal admin account with `users.simple_view = 1`; `/simple` Home, Approvals, Team, Sales, Reports; a plain-English confirm box before every approval, also on the full-CRM forms. An independent audit found no breakage for other users; its 10 findings were fixed in `b6a7ef2`. **Live; her account works.** `memory/simple-view-owner-interface.md` |
| `408491d` | **HR plan** (`HR_PLAN.md`) + `scripts/hr_phase0_checks.php`. The script is read-only: it puts the DB session in READ ONLY mode and prints counts and ids only. `scripts/` is web-denied |
| *(uncommitted until this handoff)* | `HR_PLAN.md` §2.1 / §10: the HR domain is decided as `hr.triotoursandtravels.in` |

---

## 2. HR portal — everything needed to continue

**Decisions (John, 12–15 Oct)**
- **Separate portal:** own URL and own login.
- **Employer:** Trio Tours and Travels Pvt Ltd. Don't ask again.
- **Pay:** fixed salary + incentives, with **TDS on both and nothing else**.
- **Who runs it:** admins only, including the wife's account.
- **Staff self-service:** every request needs admin approval.
- **Data:** fresh start, no old data imported.
- **CRM admin:** HR features move out of it.
- **Quality bar:** the Fumist standard (`memory/admin-ui-quality-bar.md`).

**Domain:** `triotoursandtravels.in` **is already owned by the group.** RDAP shows registrant "Lets Fly Travel", registrar Hostinger, expires 17 Mar 2027, currently parked. Use `hr.triotoursandtravels.in`; the fallback is `hr.base-fare.com`.

**Files**
- **Plan:** `HR_PLAN.md`. Modules §3–4, automations §5, CRM move/stay/share §6, CRM fixes §7, architecture §8, phases §9, client inputs §10, CA flags §11.
- **Research** (local only): `docs/hr/research/` (git-ignored, never deployed).
  - `00` Fumist agent's answer
  - `01` Fumist admin study: tokens, components, what to copy and what to fix
  - `02` CRM HR inventory: move/stay/share, incentive maths, **P0 attendance bugs**, prod SQL checks
  - `03` India payroll TDS: new Act s.392, Form 130/138/124/122, slabs, monthly method, CA questions
  - `04` Labour compliance: Codes, Punjab/Jharkhand, POSH, DPDP, night-shift rules
  - `05` Benchmark: 231-item checklist, incentive (SPM) design, UX patterns
- **Design concept** (local only): `docs/hr/trio-hr-concept.html` + `trio-logo.png`. Checked visually at 1366/1440/1920. Do NOT commit it: `docs/` is not web-denied.

**Architecture (plan §8)**
- **Layout:** same repo, second docroot `public_hr/` on the HR subdomain, HR code in `app/Hr/`.
- **Front end:** Fumist-style no-build: ES modules + one CSS file + JSON API. Strict CSP with no inline script or style, so dynamic widths are set via JS.
- **Auth:** own tables (`hr_accounts`, `hr_sessions`); Argon2id; TOTP for admins; HttpOnly cookie on the HR host only.
- **NEVER reuse CRM auth or sessions.** A CRM login or logout touches `users.active_session_id` and clocks agents out.
- **Data:** HR tables (`hr_*`) live in the same DB, on a named `hr` connection.
- **CRM bridge:** a gateway (`App\Hr\Crm\*`) with read queries plus 5 guarded write commands (plan §6.3). Don't call the CRM's session-coupled services: AttendanceService state/caches, PerformanceHold, `TransactionService::update`.
- **Shared plumbing:** the same migration runner and backup.

**Design tokens**
- Trio navy `#0B2353` (sidebar anchor) and gold `#A8842C`. Deep gold `#7A5F1C` whenever gold is text.
- Ivory `#F7F5F0` canvas, ink `#1F2430`, meta `#59606F`.
- Hanken Grotesk on token sizes 32/28/20/17/16/15/14/12. EB Garamond for brand moments only.
- Full CSS lives in the concept file.

**Local build plan (in progress)**
- **Ports, verified free on 15 Oct with `Get-NetTCPConnection`:**
  - a private MySQL 8.4 on **3417**, own datadir;
  - the HR portal (`php -S` + router) on **8471**.
- **Before starting anything**, re-check the ports and check what is already running.
- **Never stop a process by name** (`taskkill /IM php.exe` kills other projects' servers). Stop only PIDs you started.
- **Local kit:** `docs/hr/local-test-kit/` is a copy of the CRM smoke-test kit: seed, router, fake SMTP, server.cmd. The original test DB datadir is in the session scratchpad (`scratchpad/smoke/mysqldata`, database `crm_smoke`), which may be gone in a new session. In that case rebuild from `database/schema.sql` + migrations (recipe in `memory/customer-pages-are-standalone.md`).
- **Point the HR bootstrap at the local DB via env vars and refuse non-local DB hosts when `HR_ENV=local`.** The repo `.env` holds production credentials.
- **Intended first look:**
  - Sign-in screen.
  - The app shell (navy sidebar, Ctrl K search).
  - **Today** ("Needs you" from real pending items, tonight's floor from CRM sessions/roster by business day, compliance dates, a "What happened" feed).
  - **People**: directory + full-page record linked to the CRM user, with "Invite to Trio HR".
  - **Approvals** inbox with plain-English confirm sheets.
  - The remaining modules shown in the nav as "Coming next".
  - Then the staff phone view.
- **Seed** about 30 fictional employees locally. Never use real staff names in demos.
- **Be honest with John about what works and what is next.** A thin slice called "done" was what he rejected on the Fumist CRM.

**Facts verified in research (Oct 2026; the CA confirms before go-live)**
- **New Income-tax Act 2025** (from 1 Apr 2026): salary TDS is now **s.392**; Form 16 → **130**; 24Q → **138**; 12BB → **124**; 12B → **122**.
- **Most agents owe nil TDS:** new regime, ≤ ₹12.75L a year.
- **EPF wage ceiling ₹25,000 from 17 Sep 2026** (S.O. 5109(E)).
- **Labour Codes** in force since 21 Nov 2025: salary before the 7th; final settlement within **2 working days**; appointment letters mandatory; deductions capped at 50%.
- **Punjab:**
  - women may work 20:00–06:00 only with the Labour Dept night-shift permission (19 conditions) plus written consent;
  - 10 h/day cap, a break after 5 h, OT 2× with a 144 h/quarter cap;
  - PSDT ₹200/month.
- **DPDP** main duties from 13 May 2027.

---

## 3. Phase 0 (prerequisite) — what to fix in the CRM once the live checks are in

The CRM audit found these from the code. **Confirm each with the script's output before fixing; don't assume** (`memory/no-assumptions-doctrine.md`).

**Attendance**
1. **24h template (18:00→18:00) + clock-in before 18:00:** auto-closed about 1 h later and the agent is locked out (`cron/auto_clockout.php` scheduled-end calculation).
2. **24h template + forgotten clock-out:** the >24 h branch leaves `clock_out` and minutes NULL, so zero pay, and there's no UI to resolve it.
3. **Self clock-in dead zone 00:00–05:59:** only the calendar day's shift is checked, not yesterday's overnight one.
4. **Monthly report:** drops suspended or deleted users and calls every day without a session "Absent".
5. **Grace 720 vs the form/server cap of 120:** 24/7 agents can't be edited.
6. **Roster horizon:** the 24/7 script wrote rows only to 30 Aug.
7. **Mobile admin:** late-login overrides use the UTC date between 00:00 and 05:30 IST, and its Clock In button always fails (no reason sent).

**Sales data (also listed in §7)**
- **Leave for the incentive engine (Phase 3)** rather than changing CRM behaviour now: duplicates by `acceptance_id`, currency mixing, and post-approval money edits (an intended feature with a diff audit).
- **Safe to fix now:** add `status` to the audited fields when an admin edits a transaction.

---

## 4. Things that will bite the next session

- **HR auth must never touch CRM users or sessions.** It clocks agents out.
- **Processes.** Check ports and processes before starting servers. **Never `taskkill /IM`.** Stop by PID only. The fake SMTP survives `wmic`-based kills, so find its PID with netstat (`memory/simple-view-owner-interface.md`).
- **The Bash tool collapses `\\` in heredocs:**
  - a `\\'` inside a Python heredoc wrote an unescaped quote into PHP and broke the file (14 Oct);
  - use the Edit tool for PHP strings with quotes or backslashes;
  - always lint afterwards.
- **What the web server denies.** Root `.htaccess` denies `*.md` and `app|vendor|database|scripts|scratch|cron|storage`. It does **not** deny `docs/` or `*.html`. The repo root is inside the web docroot (`public_html/crm/`).
- **Included partials must not leak variables** into host views. Prefix them; an unprefixed `$label` in the Simple sidebar broke a page title.
- **Visual checks.** Use `node <script>` with `C:/Users/john/OneDrive/Desktop/fumist/node_modules/playwright-core` and Chrome (`C:/Program Files/Google/Chrome/Application/chrome.exe`) for full-page screenshots. The built-in browser pane often mis-renders scaled screenshots.
- **John's working preferences:**
  - research and approval before building;
  - one step at a time when he has to act;
  - short, plain client messages;
  - **never say "done" without a visual check in a real browser.**

---

## 5. Known bugs / small follow-ups (CRM)

1. **Transaction edit drops `data.fare_breakdown`** (`TransactionController::update()`). A task chip was raised earlier.
2. **`/users/export` CSV crashes:** it calls `->with('supervisor')`, a relation that doesn't exist (`UserController.php:327`).
3. **Admin edit-form status changes:** they bypass approve/void and aren't in the audited diff.
4. **Acceptance page actions:** Resend has no confirmation; Cancel still uses the browser `confirm()`. They are fetch() actions, which the Simple view guard can't cover.
5. `scripts/buddy_redteam_admin.php` (untracked) has a parse error. Harmless.

---

## 6. Older open items (from the 5 Oct and 31 Aug handoffs, still open)

1. **Unsigned exchange acceptances (4–5 Oct):** they carry the removed voucher wording, so resend them fresh.
2. **Exchange vouchers already issued (4–5 Oct):** the client decides whether to honour or void them.
3. **Trio letterhead v3:** no verdict yet.
4. **Persad e-ticket amount:** USD 133.32 vs 132.90, unconfirmed.
5. **Fare-terms legal review.**
6. **Live checks never done:** a Gmail send with attachments, a JSR upload (WAF), a refundable acceptance signed live.
7. **Award transactions never recorded:** ask the team about the 8 Award acceptances.
8. **CVV storage:** blocks PCI attestation. Performance hold (1–9 Aug): lift when the merchant releases. E-ticket 422 resend. b2b film section.
