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
| 1 | **HR portal: Phase 1 BUILT LOCALLY (15–16 Oct)** | John saw the first slice and said "i likes,, proceed". Phase 1 is now built and checked locally (§2 "PHASE 1 COMPLETION"). Runs at `http://127.0.0.1:8471` (how to run: §2 "Local build"). **All HR code is UNCOMMITTED on purpose.** **Never `git add -A` on dev**: `public_hr/` would become reachable under the CRM domain and the migration would run on production. Next: independent audit, fixes, then the summary to John |
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

**▶ PHASE 1 COMPLETION — 16 Oct, late: server AND front end BUILT and checked locally. Next: an independent audit agent, fix what it finds, then the plain summary to John.**

*Status at this point (16 Oct, ~01:30 IST):*
- Every Phase 1 screen is built: bell, Settings (8 tabs), account, policies (admin + staff), letters (admin list/new/check/issue/void + staff ask/read/acknowledge), print page, documents (record tab + staff), public verify, org chart, statutory fields, dark mode, Today/Home needs.
- **Tests:** `api_test.py` 58/58 and `api_test2.py` 57/57 ALL PASSED after a fresh seed (run with `PYTHONIOENCODING=utf-8`). `shoot.cjs all 1366,1536,1920` and `shoot2.cjs 1366,1536,1920 all` are clean (no console errors, failed requests, sideways scroll or <12px text). Screens were also reviewed by eye.
- **Letter print (`print.html`) is one A4 page** for the appointment letter (checked with `pdfcheck.cjs` + `pdfcheck.py` in `scratchpad/hrlocal`: page count, render, and the QR decodes from the PDF). Long letters flow onto a clean page 2.
  - How: the letter body sits in a table whose `thead`/`tfoot` are blank spacer rows, so Chrome repeats the margins on every printed page. In print, the bands, watermark, arcs, stamp and footer line are `position: fixed`, so they repeat on every page.
  - The verification box (QR + code) sits beside the signature. The printed address is the short `{host}/verify`, which redirects to `/#/verify` (`.htaccess` rule + local router).
- **Fixed during the review:** form inputs misaligned (`.field { align-content: start }`); stacked Settings blocks touching (`#tab` is a `.stack`); law table "7 th" / "50 %" and the DPDP "not in force yet" row; dark-mode contrast (new tokens `--navy-ink` and `--gold-on-navy`); task rows on the admin phone squeezing text; staff Home icons off-centre; "aadhaar card" / "Show pan?" (new `Fmt::lower` / `Fmt::an` / `Fmt::lowerFirst` + JS `f.lower` / `f.lowerFirst`); CRM-bell audit rows now read "Posted to their CRM bell: …"; laptop/image/folder/org icons.
- **Servers running now:** MySQL 3417 (PID 5892), mail sink 2526 (PID 10888), portal 8471 (PID 21604).

*Independent audits (16 Oct, 3 agents: security, server logic, front end/UX):*
- **Security** (no access-control holes found): fixed H1 reset requests counted toward the sign-in throttle (now a separate `kind='reset'` bucket, 10/15 min; sign-in limit raised to 50/15 min because a whole office shares one IP); M1 reset-email timing (all request-time mail now goes out after the reply via `Outbox::flushAfterResponse`); M3 set-up/reset links kept forever in `hr_outbox` (bodies wiped when sent, unsent mail given up and wiped after 8 days); L1 the origin check now also compares http/https; L2 uploads are read with an 8 MB cap. **M2 (leavers kept full access)** became the plan's **alumni mode** (HR_PLAN §2: leavers keep a READ-ONLY sign-in for 12 months after their last working day; any change is refused with reason `leaver`; access ends automatically) **plus** a "Switch off Trio HR sign-in" action on the person page (`POST /people/{id}/access`).
- **Server logic** (20 findings, all fixed): **clock-ins before 18:00 were filed under the previous night** (new `Clock::sessionNight()` = 06:00 morning cutoff, because the CRM allows clock-in from start minus grace = 06:00 on the 24/7 roster); letter issue/cancel/edit/acknowledge races (row lock + conditional updates, clean 409s); year-start letter-number deadlock; money fields dropping the decimal point; law "current value" depending on the label; removed documents leaving requests stuck; coverage counting people not on that night's roster; password re-check mangling spaces; invited admins that couldn't be switched off; reminders nagging withdrawn consents; profile-change lengths; leave for a night already begun; leave across New Year checked against one year; fix times in the future; outbox double sends (rows are claimed); strict SQL mode pinned on HR connections; duplicate-key errors now a 409; first-run defaults and appointment catch-up serialised with `GET_LOCK` (`Kernel::withLock`); verify page shows the name/company frozen at issue; a changed email moves the sign-in (old address is told).
- **New tests:** `scratchpad/hrlocal/api_test3.py` covers every fix above.
- **To check after deploy:** that `REMOTE_ADDR` is the visitor's real IP on the HR subdomain (the CRM's `IpRestrictionMiddleware` notes say it is on this host). Leave `TRUSTED_PROXIES` empty unless a real proxy is added.

*Gotchas from this stretch:*
- **Class names `.sheet*` are taken by the confirm dialog** (opacity 0, fixed position). The letter uses `.ltr*`.
- **Bash heredocs on this PC mangle backslashes** (`\\b` arrived as a backspace byte in a JS file). Write Python/JS helper scripts with the Write tool, then run them.
- **Playwright `page.pdf()` after `emulateMedia({media: 'screen'})` prints SCREEN css.** Reset with `emulateMedia({ media: null })`.

**Decision (John, 16 Oct): NO authenticator app in Trio HR** ("for godsake its an hr module"). All TOTP code, routes, columns and the backup-code table were removed. Sign-in is now:
- email + password;
- the password re-check before viewing bank or ID details;
- a passive new-device alert email;
- the staff emailed code on a new phone, kept but **OFF by default** (owner switch in Settings).

*State right now:*
- My servers are running: MySQL 3417 (PID 5892), mail sink 2526 (PID 10888), and the portal on 8471 (a new PID; find it with netstat).
- The local DB is freshly seeded with the full 24-table schema.

*Server side built (uncommitted; `php -l` clean; 1,083 class references all resolve):*
- **Migration:** 24 tables. The new ones are notifications, law values, login challenges (email only), trusted devices, policies + acks, letter templates, letters and documents.
- **Support:** `Support/Notify` (HR bell + `Crm/CrmBridge::notify` to the CRM bell, bridge command 5) and `Support/Markup` (safe light markup + `{{field}}` fill).
- **Sign-in:** `Auth/SecondStep` (email code, remembered devices, new-device alert); `Auth` (`checkPassword()` + `complete()`, admin invites).
- **Domain:** `Account`, `Settings`, `Setup`, `Defaults`, `Policies`, `Letters`, `Documents`; `Approvals` handles letter and document requests; `Me::requestLetter`.
- **Defaults:** `Defaults::ensure()` installs the law values, letter templates and policy drafts on a fresh install.
- **Routes:**
  - notifications, account, settings, law, admins, health, audit, compliance;
  - policies, letters (form, prepare, submit, second-admin approve, return, void, catch-up), documents (raw upload, encrypted file download);
  - public `/verify`;
  - `/me/policies`, `/me/letters`, `/me/documents`.
- **QR module** `public_hr/assets/js/core/qr.js`: VERIFIED.
  - Bit-exact with segno across versions 1–40 × L/M/Q/H.
  - Data codewords match the hand-computed standard. segno inserts an extra 0x00 before the padding, so its padding differs from ours.
  - Decoded by OpenCV Aruco in every case, and by classic OpenCV for everything except very dense codes.
  - pyzbar's DLL doesn't load on this PC. Tests are in `scratchpad/qrtest` (`gen.mjs`, `decode.py`, `codewords.py`).

*Tests:* `scratchpad/hrlocal/api_test.py` (original 60 checks; expects 11 pending requests) and `api_test2.py` (settings, law, admins, health, audit, emailed code + remembered phone, policies, letters end to end + verify, documents + upload security). **Both ALL PASSED on 16 Oct.** Re-seed after each run.

*Front end:* all 11 items of the earlier to-do list are DONE (see the status block above). What remains: the independent audit agent → fixes → re-test → summary to John.

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
