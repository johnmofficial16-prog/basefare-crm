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
| 1 | **HR portal: first slice BUILT LOCALLY (15 Oct), being shown to John** | Runs at `http://127.0.0.1:8471` (how to run: §2 "Local build"). 60/60 API checks pass; screenshots at 1366/1536/1920 + phone + tablet are clean. **All HR code is UNCOMMITTED on purpose** (John wants to see it before anything ships). **Never `git add -A` on dev**: `public_hr/` would become reachable under the CRM domain and the migration would run on production. Next: John's reaction, then the rest of Phase 1 (§2) |
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

**▶ PHASE 1 COMPLETION — IN PROGRESS (John said "i likes,, proceed", 15 Oct night). Stopped mid-way at the usage limit.**

*State right now:*
- The local portal on 8471 is **STOPPED**: I killed my PID 16296 to restart with the new `HR_STORAGE_DIR`.
- MySQL on 3417 (PID 5892) and the mail sink on 2526 (PID 10888) may still be running; they are mine.
- The local DB still has the **old** hr_* schema. To bring it back: `source docs/hr/local-test-kit/hr_env.sh` (it now sets `HR_STORAGE_DIR` = `scratchpad/hrlocal/storage`), then `php hr_seed.php` (the seed now **drops and rebuilds all hr_* tables from the migration**), then start `php -S` as below.

*Written this round (uncommitted; every file passes `php -l`):*
- **Migration `2026_10_15_hr_foundation.sql`:** now 25 tables, plus new columns:
  - `hr_employees.pan_status/uan/esic_ip`;
  - `hr_accounts.totp_pending_enc/totp_last_step`;
  - `hr_sessions.must_setup_2fa`;
  - `hr_requests.type += document`.
- **New support and bridge files:**
  - `Support/Notify.php`: HR bell, plus `Crm/CrmBridge::notify`, which writes CRM `notifications` with type `hr` (bridge command 5).
  - `Support/Markup.php`: safe light-markup to HTML and `{{field}}` fill. Tested: escapes HTML; values can't inject formatting.
- **Sign-in and security:**
  - `Auth/Totp.php`: passes the RFC 6238 vectors.
  - `Auth/SecondStep.php`: challenges, emailed codes, backup codes, remembered-device cookie, new-device alert email.
  - `Auth/Auth.php`: `checkPassword()` + `complete()` replace `signIn()`; `sendAccountInvite()` for admin accounts.
  - `Domain/Account.php`: my account, TOTP start/confirm/disable, backup codes, devices.
- **Settings:**
  - `Domain/Settings.php` rewritten: `set()`, `security()`, `RULES`, company/centres defaults.
  - `Domain/Setup.php`: company, centres, rules, security (owner-only), law table, admins (owner-only), health, audit-log viewer, compliance add/edit.
- **Content:** `Domain/Defaults.php` holds the law values (sourced), 8 letter templates and 5 policy drafts. It is **NOT yet hooked**: call `Defaults::ensure()` in `Api::run()` after `Kernel::boot()`.
- **Policies, letters, documents:**
  - `Domain/Policies.php` (versions, publish, remind, sign, withdraw consent).
  - `Domain/Letters.php` (fill, second-admin check, number `TT/HR/YYYY/NNNN`, frozen archive, verify code, catch-up, staff ack, public verify).
  - `Domain/Documents.php` (raw-body upload ≤ 8 MB sniffed by content, encrypted outside the web root, view-logged).
- **Approvals:** handles `letter` (approve creates the letter) and `document` (verify/reject).
- **Api.php routes added:** `/session/verify`, `/session/resend`, `/notifications`, `/account/*`, `/settings*`, `/law`, `/admins*`, `/health*`, `/audit`, `/compliance` POST/PATCH.

*Still to do, in order:*
1. Hook `Defaults::ensure()`.
2. Routes for policies, letters, documents, public `/verify` and `Me::requestLetter`.
   - **Uploads:** let the guard accept non-JSON types for upload routes only.
   - **File download:** a raw (non-JSON) response.
3. **Front end:**
   - The bell.
   - Settings tabs.
   - `#/account` with the TOTP setup; it needs `core/qr.js`.
   - Sign-in code screens and the forced 2FA setup.
   - Policies (admin and staff).
   - Letters: list, new and detail, plus `print.html`/`print.js` with the v3 letterhead (recipe: memory `design-deliverables-standard`).
   - Documents: a tab on the record, and a staff screen.
   - `#/verify/CODE`.
   - Org chart, statutory fields, dark mode.
4. **Seed:** for the demo, set `security` to `admin_totp=false` and `staff_email_code=false`. Call `Defaults::install()` through the Kernel. Publish policies plus sample sign-offs, letters, documents and notifications.
5. **Tests:** extend `api_test.py` (TOTP, email code, policies, letters, documents, verify), then screenshots, then an independent audit agent, then fixes.

*QR encoder:* delegated to a background helper. It writes `public_hr/assets/js/core/qr.js`, with tests in `scratchpad/qrtest`. The result was **unconfirmed** at the cut-off. Check that the file exists and that its decode tests (cv2 + pyzbar) pass before using it; otherwise redo it.

**Local build (built 15 Oct, UNCOMMITTED, local only)**

*What exists (all new files; nothing in the CRM changed):*
- `database/migrations/2026_10_15_hr_foundation.sql`: 15 `hr_*` tables (people, secrets, history, accounts, sessions, tokens, login attempts, requests, leave types/balances/days, settings, compliance items, audit log, outbox). Applied to the LOCAL db only.
- `app/Hr/`:
  - `Kernel` (boot; `crm` + `hr` connections; local mode refuses non-loopback DB and never reads `.env`)
  - `Api` (Slim, `/api` base, guard = session + role + CSRF + same-Origin + JSON-only; one error handler with `HR-xxxxxx` refs, also written to the CRM Error Console tagged `[HR …]`)
  - `Http/{ApiError,Ctx}`, `Support/{Clock,Fmt,Audit,Vault,Outbox}`, `Auth/Auth`, `Crm/CrmGateway` (read-only)
  - `Domain/{Settings,People,Privacy,Leave,Floor,Approvals,Today,Me}`
- `public_hr/`: `index.html`, `.htaccess` (CSP, HTTPS, deny php except entry), `api/index.php`, `assets/css/hr.css`, `assets/js/{app.js, core/*, screens/*}`.

*What works:*
- **Sign-in**:
  - Argon2id; lockout after 5 tries; per-IP throttle; timing-safe unknown email.
  - Invite links and resets (single use, token in the URL fragment).
  - Admin idle sign-out after 30 min, with a 2-minute warning; background polling doesn't count as activity.
  - Devices list; password change.
- **Today**:
  - KPIs.
  - "Needs you", built on the server: a late clock-in waiting, approvals by kind, roster ending, probation and contract end, missed clock-outs, invites, compliance.
  - Tonight's floor, live every 15 s from CRM sessions by shift day (fixes the CRM's calendar-day board).
  - Compliance dates.
  - People moments.
  - "What happened" feed, including CRM late-login approvals.
- **`#/floor`** (everyone tonight) and **`#/compliance`** (mark done).
- **People**:
  - Directory: filters, live dots, invite everyone.
  - Full-page record with tabs: Overview, Attendance (CRM nights, month by month), Leave, Requests, Private details, Timeline.
  - Add a person (+ invite), edit drawer with a dated job history.
  - Private details encrypted (libsodium, `HR_VAULT_KEY`); "Show" needs the admin's password; every look is logged and listed.
- **Approvals**:
  - Request types: leave (balance / notice / coverage per night vs `min_floor` / history), attendance fix (CRM night check), profile change (diff), bank change (name match, IFSC, cooling-off; the account number stays encrypted while pending).
  - Actions: approve, approve some nights, send back with reason chips, bulk-approve routine ones.
  - J/K/A keys; can't decide your own request; emails the employee via the outbox.
- **Staff phone app**:
  - Home: tonight's shift in India + New York time with a US DST note, clocked-in state, needs, leave, requests.
  - Attendance month with "Fix" (a few taps).
  - Ask for leave (3 taps).
  - Pay (honest "from December pay").
  - My details and bank (both go to approval); devices; password.
- **Coming-next pages** for every unbuilt module, saying which phase delivers it.

*NOT done yet (rest of Phase 1, honest list):*
- TOTP for admins.
- New-device alert email.
- Letters (appointment-letter catch-up, LOI move).
- Settings screen (company, centres, law tables, admins).
- Documents vault, policies and acknowledgements.
- Org chart.
- Notification bell.
- Self-hosted fonts (Google Fonts link for now).
- Dark mode.
- Health/outbox page.
- CRM bridge writes (none yet).
- Leave: accruals, holidays and opening-balance screen (Phase 2).

*Run it (ports re-checked free; kill ONLY these PIDs, never by name):*
1. **MySQL on 3417**, private datadir `scratchpad/hrlocal/mysqldata` (a copy of the smoke db; the `crm_smoke` schema + `hr_*`):
   `mysqld.exe --no-defaults --datadir=<scratchpad>\hrlocal\mysqldata --port=3417 --bind-address=127.0.0.1 --mysqlx=OFF --disable-log-bin --default-time-zone=+05:30 --sql-mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`
2. **Mail sink on 2526**: `python docs/hr/local-test-kit/hr_fake_smtp.py` (env `HR_MAIL_DIR` = `scratchpad/hrlocal/mail`).
3. **Portal on 8471**:
   - Run `docs/hr/local-test-kit/hr_server.cmd`; it seeds first, then serves.
   - Or from bash: `source docs/hr/local-test-kit/hr_env.sh`, then `php hr_seed.php`, then `php -d openssl.cafile=<kit>/smtp.crt -S 127.0.0.1:8471 hr_router.php`.
4. **Sign-in details** are in `hr_seed.php` (header comment + constants). They are local-only and never go in chat.
5. **The seed is re-runnable.** It rebuilds all `hr_*` rows plus the demo CRM users, roster and nights around the current time.
   - 33 fictional people on `@trio-demo.test`; "tonight" is built live.
   - It sets the old `@smoke.test` users to `is_dev=1`.
6. **PIDs on 15 Oct:** mysqld 5892, mail 10888, php 16296. They will differ next time; find them with `netstat -ano | grep -E ":(3417|2526|8471) "`.
7. **Tests** (`scratchpad/hrlocal`):
   - `python -X utf8 api_test.py`: 60 checks covering auth, CSRF/Origin/JSON, roles, own-data-only, reveal + audit, approvals, staff flows, and emails reaching the sink.
   - `node shoot.cjs all 1366,1536,1920`: screenshots plus a console / blocked-request / sideways-scroll / <12px sweep.
   - Re-seed after `api_test.py`, because it changes data.

*Gotchas found while building:*
- **Never load the entry module with `?v=`.** `app.js?v=…` plus screens importing `../app.js` created TWO app instances with separate sessions.
- **Slim's PSR request drops the port from `Host`.** The Origin check uses raw `$_SERVER['HTTP_HOST']`.
- **Event listeners on `#main` leaked across screens.** The router now gives each screen a fresh `<main>`.
- **Python on Windows** reads UTF-8 as cp1252 unless run with `-X utf8`.

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
