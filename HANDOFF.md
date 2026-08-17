# Session Handoff — 10 August 2026

Working notes for picking this up in a fresh session. Memory files
(`MEMORY.md`) cover the durable project facts; this covers *today's* state.

> **⚠️ §1–5 below are from 10 Aug and are now STALE.** The server is many
> commits ahead. See the "17 August update" block immediately below for
> current state; the memory files are the source of truth.

---

## 17 Aug (later) — P5 BUILT ✓ (offline-tested 28/28; needs deploy + live drive)

P5 is implemented exactly per the spec below, on local `dev`:
`BuddyService::agentFeed` + `GET /buddy/feed` (atomic claim, batch cap 3,
priority praise→urgency, quota-exempt), widget greeting-on-page-load, 75s
visibility-gated polling, toast-by-orb + badge + spoken delivery with an
autoplay-unlock queue (speech queued until first click/keydown — Chrome
blocks it before a gesture). Two latent bugs fixed en route: the greeting
dedupe check (was "any model message today" — feed messages would have
suppressed the login greeting; now a stamp in buddy_settings.extra_json) and
the silent bulk `markNudgesDelivered` on every chat turn (nudges could be
swallowed unvoiced; the feed is now the only deliverer).
**P6 companion polish (same session, `f62c9ab`):** lag nudges now ESCALATE
(rounds at 4h/24h/72h e-ticket, 6h/24h/72h acceptance — round 1 keeps the old
dedupe key so deploying does NOT re-nudge current laggards); praise carries
SQL-decided personal-best flags ("biggest this month" / "biggest EVER");
stale praise is framed as catching up rather than "just saw this land"; money
reads `$1,240.50` (also fixes how it sounds aloud); the unread badge persists
across page loads (boot counts pending+delivered, `seen` stamped on panel open
or via `?open=1`); `get_my_nudges` is recency-windowed with an unread flag.

Verify: `php scripts/buddy_feed_verify.php` (SQLite, no network, **58 checks**).

**TODO — deploy + live drive.** On the server:
`git pull origin dev && php hostinger_migrate.php` (no new migration, but the
migrator is safe to run). Then, as an agent in Chrome: greeting should speak
after the first click on the page (autoplay policy — it is queued until then);
seed a pending nudge and confirm the toast appears by the orb within ~75s.
Red-team unchanged (no new tools, no new model inputs).

Original design (for reference — all of it is built):

1. **Nudge feed endpoint** `GET /buddy/feed`: drains this user's `pending`
   buddy_nudges → phrases each AS AISHA (template-first for token economy:
   payload has ref/amount/hours; optionally one Gemini call for praise tiers)
   → stores as `model` messages in their agent conversation (history stays
   coherent) → marks delivered → returns the new messages.
2. **Widget polling**: every ~75s while the tab is visible (document.
   visibilitychange gate). New messages: panel open → append + speak
   ('greeting'-class voice); panel closed → toast bubble by the orb with the
   first line + badge + spoken line if voice on. Click toast → open panel.
3. **Greeting fires on page load, not on orb click**: on boot (agent mode),
   call /buddy/greeting; if greeted → auto-open panel briefly or toast +
   SPEAK it. This is the client's "greets him by name when he logs in".
4. Quota note: feed phrasing must not eat the agent's 40/day chat quota —
   nudge deliveries are Aisha-initiated, exclude from quotaCheck.
5. Test plan: offline (feed drains + idempotent, poll gating), then live
   drive via Chrome session as before. Red-team unchanged (no new tools).

Also pending from the user: confirm backup + consolidator crons are actually
REGISTERED in hPanel (both verified working by hand 17 Aug 20:49); GCP card
+ then GOOGLE_TTS_API_KEY (.env) to switch Aisha's real voice on (P4 note
below); ₹94k Search-credit test on the 18th.

---

## 17 August 2026 update — current state

**Deployed:** server on `dev` at/after `c6720d7`. Everything through the full
AI-buddy build (P0–P3) is live and verified. Reliability safety net, the
40-bug audit fixes, self-hosted Tailwind, 24/7 roster — all shipped. See
memory: `ai-buddy-status`, `reliability-safety-net`, `roster-24-7`,
`gcp-credits-deadline`.

**Deploy is unchanged:** `git pull origin dev && php hostinger_migrate.php`
(the migrator is now ledger-based and self-backs-up; safe to run every deploy).

### OPEN OPERATIONAL TASKS (all the user's, none block code)

1. **GCP billing card — DUE 18 Aug.** Free-trial credit expires; without a
   card Gemini goes dark and the whole buddy layer degrades to deterministic
   fallbacks. Also the Customer-Email AI module. Hard deadline.
2. **₹94k GenAI Search credit scope test — 18 Aug.** Re-run queries against
   the `basefare-kb-test` Discovery Engine app after the trial credit lapses;
   check Billing→Reports credit column. (Details in `gcp-credits-deadline`.)
3. **hPanel cron jobs to register:**
   - Nightly backup — daily 03:30:
     `php <crm>/cron/db_backup.php`  *(NOT yet registered — backups are stale)*
   - Buddy triggers — every 15 min:
     `php <crm>/cron/buddy_triggers.php`  *(ALREADY registered)*
   - Buddy consolidator — weekly Sun 04:00:
     `php <crm>/cron/buddy_consolidate.php`  *(not yet registered)*
   (`<crm>` = `/home/u501549865/domains/base-fare.com/public_html/crm`)
4. **24/7 roster weekly top-up** (horizon ends 30 Aug):
   `php <crm>/scripts/schedule_24h_shifts.php --apply --roles=agent,manager`
5. **Buddy kill switch** if ever needed: set `BUDDY_ENABLED=false` in `.env`
   (hides widget + 503s all buddy chat, no deploy).

### STILL GATED / DEFERRED (code decisions, not forgotten)
- Attendance coaching in the buddy stays OFF until the `attendance_sessions.date`
  overnight-split and dead break-tracking are fixed (rewrites history → needs
  client sign-off). Plan §13.5.
- Supervisor role dormant — excluded from all buddy surfaces + the shift bug
  fixes were deferred (role currently unused).
- 5 agents inactive 50–100+ days (surfaced by the Super Buddy dry-spell tool)
  — worth asking the client whether those accounts should stay active; they
  also each hold a 24/7 shift now.

---

## 1. Production state right now

| | |
|---|---|
| **Deployed commit** | `cfadb62` (server is on `dev`, pulled and verified) |
| **Production URL** | `crm.base-fare.com` — path `~/domains/base-fare.com/public_html/crm` |
| **Deploy method** | push to `origin dev` → `git pull origin dev` on the server. **NOT `main`** — `main` is ~241 commits behind and must never be deployed. |
| **Performance hold** | **ACTIVE** — 1–9 Aug hidden from Performance tab for non-admins. 14 safe-booking refs exempt. Must be lifted when the merchant releases funds. |
| **Local `dev`** | 1 commit ahead of origin (see §3) |

---

## 2. What shipped today

| Commit | What |
|---|---|
| `c2102be` | **Performance hold** — merchant hold hides 1–9 Aug from agent/manager/supervisor scores. Admins unaffected. Read-only filter, writes nothing. |
| `2ae76e0` | **Invoice print fix** — native vector print instead of html2canvas bitmap |
| `1a60e7c` | **Invoice font weights** — page now requests Inter 700/800; Manrope 900→800 |
| `388667a` | **Manual e-tickets** — manager/admin can issue an e-ticket with no linked booking. **Requires migration** (already run). |
| `ed1b21b` → `cfadb62` | WAF shield built, then **fully reverted** (see §5) |

---

## 3. Parked / unpushed

**`92239ef` — attendance work. On local `dev` only, NOT pushed.**

Contains: successful-login logging, mandatory reason on admin clock-in,
`created_via`/`created_by_user_id`/`created_reason` columns + migration,
"by admin" badges on Live Board and History.

Why parked: needs `php hostinger_migrate.php`, and it **changes historical hour
figures**. The client should be told before it ships.

---

## 4. Open bugs — found, diagnosed, NOT fixed

1. **91.7-hour monthly-report bug.** `AttendanceService::getMonthlyReport()`
   keys sessions by date (`$sessionMap[$user][$date] = $s`), so an agent with
   two sessions in a day loses the earlier one's hours. JSR alone was
   understated by 91.7 hrs in July. Day counts are correct; hours are not.
   **Payroll currently reads wrong hours.**

2. **Business-day windowing inconsistency.** Business day = 24h from 18:00
   (`ShiftService::businessDayBounds()`). Only 4 places honour it. ~9 places use
   calendar days — `PerformanceController` (custom/monthly), `AnalyticsController`,
   `ChargebackController`, `DashboardController` (month KPIs), `MobileAdminController`,
   `AnalyticsService` (prev-period), `Transaction::scopeByDateRange`,
   and `LiveBoardController` **hardcodes 18** instead of reading config.

3. **`attendance_sessions.date` splits overnight shifts.** Set as
   `date('Y-m-d')` at clock-in, so a shift crossing midnight lands on two dates.
   This is the likely cause of "days present > days rostered" — my earlier claim
   that the roster was unreliable was probably wrong.

4. **Break tracking dead.** Zero break minutes for all 12 JSR agents for all of
   July. Undiagnosed — could be unused feature or not writing.

5. **Notes visibility — UNRESOLVED.** Client reported a manager's remark not
   visible to admin. Leading theory: acceptances and transactions keep
   **separate note timelines** and the transaction view never merges the linked
   acceptance's notes. Diagnostic script was written but **never run** — needs
   `php scripts/tracenotes.php G7BGL3 AHYX2I` (script not on server).

---

## 5. The WAF incident — what actually happened

**Symptom:** transaction saves 403'd with LiteSpeed's error page. Cyrus and Sam
(both Mohali) blocked; Thomas (JSR) fine. Volume dropped 11–16/day → 1.

**Root cause:** Hostinger's shared WAF blocked POSTs from Mohali's static IP
`112.196.52.242` before they reached PHP. Confirmed by Hostinger's own access
log (12× 403 on `POST /transactions/create`). Never our code.

**Fix:** Hostinger added a **WAF allowlist for 112.196.52.242/32**. Stable
because the IP is static.

**What I got wrong, for the record:**
- Built a base64 "WAF shield" that did NOT beat the WAF (the blocks at
  21:38–21:46 were the shield failing), and it introduced a bug: renaming
  uploads to `.bin` broke `saveProofFiles()`'s extension whitelist, so saves
  failed silently after the allowlist landed. Fixed, then the whole shield was
  reverted in `cfadb62`.
- Suggested rebooting the router for a new IP — Mohali's IP is **static**.
- Speculated a credit-card number in a note triggered a PCI rule. **Tested and
  not supported** — a Luhn-valid card number reached PHP fine.

**Rule ID never obtained.** Kodee (Hostinger AI) could only see access logs, not
the ModSecurity audit log. Would need human escalation. Not urgent now.

**Still open risk:** JSR has a **dynamic** IP, so it can't be allowlisted the
same way. If JSR's IP gets flagged, this recurs there.

---

## 6. Unverified

**Invoice PDF fonts.** After `1a60e7c`, a fresh PDF should embed **Inter** and
**Manrope**, with `Arial-Black` gone. Never confirmed — the last PDF checked was
from before the fix. Ask for a fresh PDF and inspect its font table.

---

## 7. Next topics (where the conversation was heading)

**A. VPS migration — decision pending, not urgent.**
- Recommended spec if going ahead: **Hostinger KVM 2 (2 vCPU / 8GB), Mumbai,
  CyberPanel** — cheapest, easiest migration, `.htaccess` keeps working.
  (I initially omitted Hostinger from the options by wrongly assuming they
  wanted to leave the vendor.)
- **Drawbacks discussed:** security becomes theirs (they store card data incl.
  CVV, docroot = repo root, `.env` was public for 6 weeks previously); no 3am
  support; patching/backups become their job; single-person maintenance risk.
- **My advice given:** don't migrate in the aftermath of an incident. The WAF
  problem is already fixed. The AI buddy does **not** need a VPS (it's just API
  calls). Migrate calmly in a month if consolidating projects.
- Three migration landmines: the **encryption key file** (`ENCRYPTION_KEY_FILE` —
  miss it and every stored card is unrecoverable), the **`storage/` tree**
  (gitignored uploads: proofs, signatures, payroll PDFs), and **`.htaccess`**
  (Apache-only; 8 security rules).

**B. AI "CRM buddy" — proposed, not started.**
Per-agent conversational assistant with memory, grounded in their own stats,
coaching toward better scores. Proposed 3 phases: grounded assistant → memory
(conversations + agent-facts tables) → proactive nudges. Hard constraint: **no
customer PII to the AI** (follow the existing `AnalyticsService` aggregate-only
pattern). Cost estimate ~$15–40/mo on Gemini Flash. Uses existing
`VERTEX_API_KEY`.

Open questions I asked and haven't been answered:
1. VPS size / go-ahead?
2. Which other projects move over? (`letsfly-travel.com`, BFHD site?)
3. Buddy v1: coach-only, or can it take actions?

---

## 8. Gotchas for whoever picks this up

- **Deploy from `dev`, never `main`.** The architecture doc is wrong about this.
- **Shipping one commit without dragging others:**
  `git checkout -b ship-x origin/dev && git cherry-pick <sha> && git push origin ship-x:dev`
  then `git checkout dev && git rebase origin/dev && git branch -d ship-x`.
- **`git push` is sometimes blocked** by the environment's permission classifier.
  It worked later in the session; if blocked, the user must run it.
- **`scp` kept failing** because it was run *inside* the SSH session. Either
  push via git or paste a heredoc. Long heredocs can truncate — keep them short.
- **`.gitignore` has `test_*.php`** — a script named `test_shield_save.php` was
  silently ignored. Renamed to `shield_verify.php`.
- **`git add -A` swept in stray files** (`show_tables.php`, `test_miles.sql`,
  `update_enum.sql`) that are untracked and shouldn't ship. Stage explicitly.
- **Server has leftover scripts** in `scripts/` from debugging (`shield_verify.php`,
  `blocks.php`, `edge.php`, `cyrus403.php`, `notes.php`, `hr.php`, `find4.php`,
  `refs.php`, `q.php`, etc.). Harmless (`.htaccess` blocks `scripts/` over HTTP)
  but worth cleaning.
- **The user prefers short answers.** Long structured dumps get pushback.
