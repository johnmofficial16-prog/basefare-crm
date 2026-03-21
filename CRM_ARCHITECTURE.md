# Base Fare CRM — Final Architecture Specification

> **Version:** 1.0 — Locked  
> **Date:** March 20, 2026  
> **Client:** Base Fare (Travel Agency, Albany NY)  
> **Stack:** PHP 8.x (Slim 4 + Eloquent) · MySQL 8.x · Tailwind CSS · Hostinger  
> **Timezone:** IST (Asia/Kolkata) — all timestamps server-side only

---

## Tech Stack (Final)

| Layer | Choice | Rationale |
|-------|--------|-----------|
| **Framework** | Slim 4 + Eloquent (standalone) | 8-12MB/request vs Laravel's 20-40MB. Fits Hostinger 256MB limit for 25+ agents |
| **Frontend** | PHP templates + Tailwind CSS | Server-rendered. No SPA — prevents attendance gate bypass via client-side routing |
| **Database** | MySQL 8.x (STRICT_TRANS_TABLES) | Relational integrity for payroll-critical data. JSON column support for transactions |
| **Sessions** | DB-based (`sessions` table) | File sessions on shared hosting cause lock contention |
| **Auth** | bcrypt + server-side sessions | Simple, proven, no external dependency |
| **Cron** | Plain PHP CLI scripts via Hostinger cron | No Artisan dependency. Minimum interval: verify on plan (5 or 15 min) |
| **Hosting** | Hostinger shared hosting | Git push → auto-deploy |

### Mandatory Server Config
- OPcache enabled (verify on Hostinger — 30-50% overhead reduction)
- `memory_limit = 128M` in `.htaccess` (treat hitting it as a bug)
- `.env` file stored **above webroot** (`/home/user/.env`), not in `public_html/`
- MySQL `STRICT_TRANS_TABLES` mode enforced

---

## RBAC: User Roles

| Role | Access |
|------|--------|
| **Super Admin** | All data, all agents, overrides, payroll, settings |
| **Manager** | Team attendance, team transactions, approve overrides |
| **Agent** | Own attendance, record transactions, own dashboard |

---

## Module 1: Attendance & Time Tracking

> **Priority:** 🔴 CRITICAL — salaries depend on this module being flawless.

### 1.1 Architecture: Middleware + Lobby Page (Layered)

```
Every HTTP request → AttendanceMiddleware::check()
  → Reads attendance_session_id from PHP session
  → Cross-references DB: is session status = 'active'?
    ├── NO active session → redirect to /clock-in (lobby page)
    └── YES → allow request through to CRM
```

- **60-second cache:** Store a session timestamp; only hit DB if >60s stale. Reduces 25 agents × ~1 req/sec to 25 queries/min.
- **DB cross-reference is mandatory** — prevents stale sessions after admin force-closes an agent.

### 1.2 Shift Scheduling: Template System

#### Shift Templates (admin-defined, reusable)
```
"Morning"  → 09:00–18:00
"Evening"  → 14:00–23:00
"Night"    → 22:00–07:00
"Split"    → 10:00–19:00
```

#### Weekly Scheduling UX
- Admin opens "Week of March 24" view → 25 agents × 7 days grid
- Pre-populated from previous week's **pattern** (not copied values)
- Admin adjusts exceptions (typically 5-10 cells)
- "Publish Week" → writes all rows in **single transaction**
- Mid-week edits: individual cell updates + agent notification
- Warning before publish if any agent has no shift for a day

#### Table: `shift_templates`
| Column | Type | Purpose |
|--------|------|---------|
| `id` | INT PK | |
| `name` | VARCHAR(50) | e.g., "Morning" |
| `start_time` | TIME | e.g., 09:00:00 |
| `end_time` | TIME | e.g., 18:00:00 |
| `created_by` | INT FK → users | |

#### Table: `shift_schedules`
| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT PK | |
| `agent_id` | INT FK → users | |
| `shift_date` | DATE | Specific calendar date |
| `shift_start` | TIME | From template or custom |
| `shift_end` | TIME | |
| `template_id` | INT FK NULL | Which template was used |
| `schedule_week` | DATE | Monday of ISO week (enables week-level queries) |
| `created_by` | INT FK → users | Admin who set this |
| `created_at` | DATETIME | |
| **UNIQUE** | `(agent_id, shift_date)` | One shift per agent per day |

### 1.3 Login & Shift Enforcement

```
1. Agent submits credentials → validate username/password
2. Query: SELECT * FROM shift_schedules WHERE agent_id = ? AND shift_date = CURDATE()
   ├── No row found → "You are not scheduled today. Contact admin."
   └── Row found → continue
3. Compare NOW() vs shift_start:
   ├── NOW() < (shift_start - 30 min) → "Too early. Shift starts at {time}."
   ├── NOW() BETWEEN (shift_start - 30 min) AND (shift_start + 30 min) → ALLOW
   │   → Record clock_in, calculate late_minutes
   └── NOW() > (shift_start + 30 min) → BLOCK
       → "You are {X} minutes late. Contact admin for override."
       → Log failed attempt
       → Agent sees waiting screen with live status indicator
       → Admin approves from dashboard → override row inserted
       → Agent's next page load passes through
```

### 1.4 Attendance Session Tables

#### Table: `attendance_sessions`
| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT PK | |
| `user_id` | INT FK → users | |
| `clock_in` | DATETIME | Server timestamp |
| `clock_out` | DATETIME NULL | NULL = still active |
| `scheduled_start` | TIME | Snapshot from shift_schedules at clock-in |
| `scheduled_end` | TIME | Snapshot from shift_schedules at clock-in |
| `late_minutes` | INT DEFAULT 0 | Calculated at clock-in |
| `total_work_mins` | INT NULL | Computed on clock-out: (out - in) - breaks |
| `total_break_mins` | INT DEFAULT 0 | Sum of all breaks |
| `status` | ENUM | `active`, `completed`, `admin_override`, `auto_closed` |
| `resolution_required` | TINYINT(1) DEFAULT 0 | Flagged for admin review |
| `override_by` | INT FK NULL | Admin who approved override |
| `override_reason` | TEXT NULL | |
| `ip_address` | VARCHAR(45) | Audit trail |
| `user_agent` | TEXT | Audit trail |
| `date` | DATE | Indexed for fast daily lookups |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | For optimistic locking |

### 1.5 Break Tracking

**Policy:** 3 structured breaks (1× 30min lunch, 2× 15min short) + unlimited washroom breaks.

#### Table: `attendance_breaks`
| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT PK | |
| `session_id` | BIGINT FK | Parent attendance session |
| `break_type` | ENUM | `lunch`, `short`, `washroom` |
| `break_start` | DATETIME | Server time |
| `break_end` | DATETIME NULL | NULL = currently on break |
| `duration_mins` | SMALLINT NULL | Computed on break-end |
| `flagged` | TINYINT(1) DEFAULT 0 | Abuse flag |

#### Washroom Break Abuse Detection (Real-Time)
Runs on **every break-end write**, not via cron:

```
On washroom break end:
  1. Calculate this break's duration
  2. Query all washroom breaks for this session
  3. Check against thresholds (stored in system_config):
     - Single washroom break > 15 min → flag 'single_too_long'
     - Total washroom breaks > 4 per shift → flag 'too_many'
     - Total washroom time > 45 min per shift → flag 'total_too_long'
  4. If flagged → create admin alert notification + set flagged = 1
```

Thresholds stored in `system_config` table (admin-adjustable from UI).

### 1.6 Multi-Tab Sync: Polling + State Machine

**Server-side state machine** (authoritative):
```
Valid transitions:
  clocked_in  → [start_break, clock_out]
  on_break    → [end_break]
  clocked_out → []

Invalid transition → HTTP 409 + current state in response
```

**Client-side polling:**
- Every open tab polls `/attendance/status` every 30 seconds (lightweight AJAX)
- Response includes current state → tab re-renders if state changed
- No WebSockets, no single-tab enforcement

### 1.7 Auto Clock-Out (Cron)

```
Cron runs every 15 minutes:
  → SELECT * FROM attendance_sessions
    WHERE status = 'active'
    AND (scheduled_end + INTERVAL 1 HOUR) < NOW()

  For sessions < 24 hours stale:
    → Set clock_out = scheduled_end, status = 'auto_closed'
    → Flag resolution_required = 1

  For sessions > 24 hours stale:
    → Set status = 'auto_closed', resolution_required = 1
    → Do NOT compute pay
    → Add to admin "Unresolved" queue
```

### 1.8 Agent Attendance Dashboard
- **Clock-In Widget:** Big button, live timer (hours:minutes:seconds)
- **Break Status:** Currently on break banner with timer, break type
- **Today's Summary:** Clock-in time, breaks taken, net hours so far
- **Weekly Summary:** Table with daily hours, late arrivals highlighted red
- **Monthly Calendar:** Green = on-time, Yellow = late+override, Red = absent, Grey = day off

### 1.9 Admin Attendance Panel
- **Live Board:** Who's in, on break, absent, late-not-yet-approved
- **Override Queue:** Pending override requests with approve/deny
- **Agent Detail View:** Full attendance history, patterns, anomalies
- **Unresolved Queue:** Auto-closed sessions needing manual hour assignment

---

## Module 2: Transaction Recorder

> **Priority:** 🔴 CRITICAL — core business function.

### 2.1 Transaction Types

| Code | Label | Key Specific Fields |
|------|-------|-------------------|
| `new_booking` | New Booking | PNR, airline, route, pax, fare breakdown, payment |
| `exchange` | Exchange/Changes | Old PNR, new PNR, fare diff, penalty |
| `seat_purchase` | Seat Purchase | PNR, seat numbers, cost |
| `cabin_upgrade` | Cabin Upgrade | Old cabin, new cabin, upgrade cost |
| `cancel_refund` | Cancel/Refund | Cancel fee, refund amount, refund method |
| `cancel_credit` | Cancel/Future Credit | Credit amount, expiry, airline credit code |
| `name_correction` | Name Correction | Old name, new name, correction fee |
| `other` | Other | Category, description, amount |

### 2.2 Table: `transactions`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT PK | |
| `agent_id` | INT FK → users | Who recorded it |
| `type` | ENUM | One of 8 types |
| `pnr` | VARCHAR(10) | Generated column from JSON for indexing |
| `customer_name` | VARCHAR(255) | |
| `customer_phone` | VARCHAR(20) | |
| `customer_email` | VARCHAR(255) | |
| `travel_date` | DATE NULL | **Feeds boarding pass notifications** |
| `departure_time` | TIME NULL | For accurate 24hr notification calculation |
| `return_date` | DATE NULL | |
| `total_amount` | DECIMAL(10,2) | Total charged |
| `cost_amount` | DECIMAL(10,2) | Agency cost |
| `profit` | DECIMAL(10,2) | total - cost |
| `payment_method` | ENUM | credit_card, debit, transfer, cash, credit_shell, other |
| `payment_status` | ENUM | pending, paid, partial, refunded, credited |
| `data` | JSON | **Type-specific fields** (route, airline, pax, fare breakdown, etc.) |
| `status` | ENUM | `pending_review`, `approved`, `voided` |
| `notes` | TEXT | Agent notes |
| `checkin_notified` | TINYINT DEFAULT 0 | 24hr notification fired? |
| `checkin_completed` | TINYINT DEFAULT 0 | Agent marked check-in done? |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | Optimistic locking |

**Indexing via generated columns:**
```sql
ALTER TABLE transactions
  ADD COLUMN pnr_idx VARCHAR(10) GENERATED ALWAYS AS (data->>'$.pnr'),
  ADD INDEX idx_pnr (pnr_idx);
```

### 2.3 Immutability Model

```
1. Agent submits → status = 'pending_review'
2. Editable while 'pending_review'
3. Admin/agent approves → status = 'approved' → IMMUTABLE
4. Corrections → void (mandatory reason) + new transaction entry
5. Every void creates a reversal record linked to original
```

### 2.4 Table: `transaction_passengers`

| Column | Type |
|--------|------|
| `id` | BIGINT PK |
| `transaction_id` | BIGINT FK |
| `first_name` | VARCHAR(100) |
| `last_name` | VARCHAR(100) |
| `dob` | DATE NULL |
| `pax_type` | ENUM: adult, child, infant |
| `ticket_number` | VARCHAR(20) NULL |

### 2.5 Table: `payment_cards`

| Column | Type | Visibility |
|--------|------|-----------|
| `id` | BIGINT PK | — |
| `transaction_id` | BIGINT FK | — |
| `card_type` | VARCHAR(20) | Agent + Admin |
| `card_number_enc` | VARBINARY(512) | **Admin only** (AES-256-GCM encrypted) |
| `card_last_4` | CHAR(4) | Agent + Admin |
| `cvv_enc` | VARBINARY(256) | **Admin only** (encrypted, never shown to agents) |
| `expiry` | VARCHAR(7) | Agent + Admin |
| `holder_name` | VARCHAR(255) | Agent + Admin |
| `billing_address` | TEXT | Agent + Admin |
| `amount` | DECIMAL(10,2) | Agent + Admin |

**Encryption:** AES-256-GCM (application-level via PHP `openssl_encrypt`). Key split: half in `.env`, half in file outside webroot. Never use MySQL `AES_ENCRYPT`.

**Admin reveal:** Requires password re-entry → PHP re-validates bcrypt → decrypts → shows in modal → every reveal logged with timestamp + admin user ID.

---

## Module 3: Boarding Pass / Check-In Notifications

> **Priority:** 🟠 HIGH

### 3.1 Dual Trigger System

**Trigger 1 — On transaction save:**
```
If type IN (new_booking, exchange) AND travel_date = tomorrow
  → Immediately create notification (status: pending)
```

**Trigger 2 — Hourly cron (safety net):**
```
SELECT * FROM transactions
  WHERE type IN ('new_booking', 'exchange')
  AND travel_date = CURDATE() + INTERVAL 1 DAY
  AND checkin_notified = 0
  AND status = 'approved'
→ Create notification for each
→ Set checkin_notified = 1
```

**Escalation cron (every 15 min):**
```
SELECT * FROM notifications
  WHERE type = 'checkin_reminder'
  AND status = 'pending'
  AND created_at < NOW() - INTERVAL 4 HOUR
→ Escalate to admin
```

### 3.2 Table: `notifications`

| Column | Type |
|--------|------|
| `id` | BIGINT PK |
| `user_id` | INT FK NULL (NULL = broadcast) |
| `transaction_id` | BIGINT FK NULL |
| `type` | ENUM: checkin_reminder, system, override_request, admin_alert, break_abuse |
| `title` | VARCHAR(255) |
| `message` | TEXT |
| `priority` | ENUM: low, normal, high, urgent |
| `is_read` | TINYINT DEFAULT 0 |
| `created_at` | DATETIME |

### 3.3 Dashboard UI
- Bell icon with unread count badge
- Check-in reminders highlighted amber with action buttons: "Mark Done" / "Snooze 2hrs"

---

## Module 4: Payroll & Reporting

> **Priority:** 🟡 MEDIUM

### 4.1 Table: `payroll_periods`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT PK | |
| `agent_id` | INT FK | |
| `period_start` | DATE | |
| `period_end` | DATE | |
| `finalized_at` | DATETIME NULL | NULL = open, set = locked |
| `finalized_by` | INT FK NULL | Admin who locked |
| `gross_hours` | DECIMAL(6,2) NULL | Computed at finalization |
| `net_payable_hrs` | DECIMAL(6,2) NULL | After break deductions |
| `pdf_path` | VARCHAR(500) NULL | Path to snapshot PDF |
| `notes` | TEXT NULL | |
| **UNIQUE** | `(agent_id, period_start)` | |

### 4.2 Table: `payroll_amendments`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT PK | |
| `payroll_period_id` | BIGINT FK | |
| `amended_by` | INT FK | |
| `reason` | TEXT | Mandatory, min 20 chars |
| `unlocked_at` | DATETIME | |
| `re_finalized_at` | DATETIME NULL | |
| `original_net_hrs` | DECIMAL(6,2) | Snapshot before amendment |
| `revised_net_hrs` | DECIMAL(6,2) NULL | After re-finalization |
| `original_pdf_path` | VARCHAR(500) | Original PDF preserved |
| `revised_pdf_path` | VARCHAR(500) NULL | New PDF after amendment |

### 4.3 Finalization Workflow
1. Admin selects pay period + agent(s) → system checks no `resolution_required` flags exist
2. Computes hours → writes `gross_hours`, `net_payable_hrs`
3. Generates PDF snapshot → stores at `/storage/payroll/{agent_id}/{start}_{end}_v1.pdf`
4. Sets `finalized_at` → all attendance records in range become read-only in UI

### 4.4 Amendment Workflow
1. Admin clicks "Amend Period" → modal requires **typed** reason (min 20 chars)
2. Creates `payroll_amendments` row → unlocks the period
3. Admin edits underlying attendance records
4. Must explicitly re-finalize → system re-computes → new PDF generated → amendment row updated

### 4.5 Reports
- **Attendance Reports:** By agent, date range, late/overtime/absence filters
- **Transaction Reports:** By agent, type, date range, airline, payment status
- **Revenue Reports:** Profit/loss by period, agent, airline
- **Payroll Export:** CSV with: agent name, date, scheduled start, actual clock-in/out, break time, net hours, anomaly flags, override reasons
- **Reconciliation Report:** Scheduled vs actual hours with discrepancy reasons

---

## Module 5: Dashboard

### 5.1 Agent Dashboard
- **Clock-In Widget** (prominent top — big button, live timer)
- **Break Controls** (lunch/short/washroom buttons with state machine)
- **Today's Stats:** Transactions recorded, hours worked, break time
- **My Recent Transactions** (last 10)
- **My Upcoming Check-ins** (next 48 hours)
- **My Attendance This Week** (mini summary)
- **Notifications Bell** (unread count badge)

### 5.2 Admin Dashboard
- **Live Attendance Board:** In / On Break / Absent / Pending Override
- **Today's Transactions:** Count, revenue, by-type breakdown
- **Override Queue:** Pending late-login approvals
- **Unresolved Sessions:** Auto-closed sessions needing review
- **Check-in Alerts:** Upcoming and overdue reminders
- **Break Abuse Alerts:** Flagged washroom breaks
- **Revenue Chart:** Daily/weekly/monthly trends

---

## Module 6: Customer Database

| Feature | Description |
|---------|-------------|
| Customer Profiles | Name, email, phone, address, passport, frequent flyer |
| Booking History | All transactions linked to customer |
| Communication Log | Notes from agent interactions |
| Search | By name, phone, email, PNR |

---

## Module 7: Activity Log

Every state-changing action logged:

| Event | Details |
|-------|---------|
| Login/Logout | Timestamp, IP, device |
| Attendance Clock In/Out/Break | Full state transition |
| Transaction Created/Approved/Voided | Who, when, what changed |
| Override Performed | Admin ID, reason, before/after |
| Card Revealed | Admin ID, timestamp, which card |
| Notification Acknowledged | Which, when |
| Shift Schedule Changed | Who changed, old/new values |
| Payroll Finalized/Amended | Full audit trail |

---

## Security

| Area | Approach |
|------|----------|
| Passwords | bcrypt, min 8 chars |
| CSRF | Token on every state-changing form |
| SQL Injection | Prepared statements (Eloquent handles this) |
| XSS | Output encoding on all user content |
| Rate Limiting | DB-based, 3 failures → 15min lockout |
| Sessions | DB-based, 30min idle timeout |
| Card Data | AES-256-GCM, split key, app-level only |
| Audit Trail | Every state change logged with user, timestamp, IP |
| NTP Drift | Cron periodically logs NOW() vs time() to detect server clock drift |

---

## Supporting Tables

#### `users`
| Column | Type |
|--------|------|
| `id` | INT PK |
| `name` | VARCHAR(255) |
| `email` | VARCHAR(255) UNIQUE |
| `password_hash` | VARCHAR(255) |
| `role` | ENUM: admin, manager, agent |
| `grace_period_mins` | INT DEFAULT 30 |
| `status` | ENUM: active, inactive, suspended |
| `deleted_at` | DATETIME NULL (soft delete) |
| `created_at` | DATETIME |
| `updated_at` | DATETIME |

#### `system_config`
| Column | Type |
|--------|------|
| `key` | VARCHAR(100) PK |
| `value` | TEXT |
| `updated_by` | INT FK NULL |
| `updated_at` | DATETIME |

Default keys: `abuse.single_washroom_max` (15), `abuse.washroom_count_max` (4), `abuse.washroom_total_max` (45), `timezone` (Asia/Kolkata).

#### `attendance_overrides`
| Column | Type |
|--------|------|
| `id` | BIGINT PK |
| `agent_id` | INT FK |
| `shift_date` | DATE |
| `override_type` | ENUM: late_login, early_logout, missed_clockout, manual_entry, time_correction |
| `override_by` | INT FK (admin) |
| `reason` | TEXT |
| `original_value` | TEXT |
| `new_value` | TEXT |
| `created_at` | DATETIME |

#### `activity_log`
| Column | Type |
|--------|------|
| `id` | BIGINT PK |
| `user_id` | INT FK |
| `action` | VARCHAR(100) |
| `entity_type` | VARCHAR(50) |
| `entity_id` | BIGINT NULL |
| `details` | JSON |
| `ip_address` | VARCHAR(45) |
| `created_at` | DATETIME |

---

## Project Structure

```
basefare-crm/
├── public/                     # Web root (Hostinger public_html)
│   ├── index.php               # Slim 4 entry point
│   ├── .htaccess               # URL rewriting + .env deny
│   └── assets/
│       ├── css/                # Compiled Tailwind
│       ├── js/                 # Vanilla JS (polling, UI)
│       └── img/
├── app/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── AttendanceController.php
│   │   ├── TransactionController.php
│   │   ├── DashboardController.php
│   │   ├── AdminController.php
│   │   ├── ShiftController.php
│   │   ├── PayrollController.php
│   │   ├── CustomerController.php
│   │   └── NotificationController.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── AttendanceGateMiddleware.php
│   │   ├── RbacMiddleware.php
│   │   └── CsrfMiddleware.php
│   ├── Models/                 # Eloquent models
│   ├── Services/
│   │   ├── AttendanceService.php
│   │   ├── BreakAbuseDetector.php
│   │   ├── EncryptionService.php
│   │   ├── PayrollService.php
│   │   └── NotificationService.php
│   ├── Views/                  # PHP + Tailwind templates
│   └── Helpers/
├── database/
│   ├── schema.sql              # Full schema DDL
│   └── migrations/
├── cron/
│   ├── checkin_notifier.php    # Hourly: 24hr boarding pass check
│   ├── escalate_notifications.php  # Every 15 min
│   ├── auto_clockout.php       # Every 15 min: close stale sessions
│   ├── shift_gap_alert.php     # Daily 8PM: warn about missing tomorrow shifts
│   └── ntp_drift_check.php     # Daily: log server clock accuracy
├── storage/
│   └── payroll/                # PDF snapshots: {agent_id}/{period}_v{n}.pdf
├── .env                        # Above webroot on production
├── .gitignore
├── composer.json
└── README.md
```

---

## Development Phases

| Phase | Module | Priority | Build Order |
|-------|--------|----------|-------------|
| **1** | Auth + RBAC + Users | 🔴 Critical | First — everything depends on it |
| **2** | Shift Scheduling | 🔴 Critical | Second — attendance depends on it |
| **3** | Attendance & Time Tracking | 🔴 Critical | Third — payroll depends on it |
| **4** | Transaction Recorder | 🔴 Critical | Fourth — core business |
| **5** | Dashboard (Agent + Admin) | 🟠 High | Fifth — ties everything together |
| **6** | Boarding Pass Notifications | 🟠 High | Sixth — cron + notifications |
| **7** | Customer Database | 🟡 Medium | Seventh — basic CRUD |
| **8** | Payroll & Reports | 🟡 Medium | Eighth — needs attendance data to exist first |
| **9** | Activity Log | 🟢 Standard | Woven throughout — add logging as each module is built |

---

## Development Environment: Local ↔ Production Parity

> **Lesson from SkyTeam:** The SQLite (local) ↔ MySQL (production) mismatch caused painful migration bugs. This time: **MySQL everywhere, zero dialect differences.**

### Local Setup (Windows)

| Component | Tool | Notes |
|-----------|------|-------|
| **PHP 8.x** | XAMPP or Laragon | Laragon recommended (lighter, multi-PHP-version) |
| **MySQL 8.x** | Bundled with XAMPP/Laragon | **Same engine as Hostinger — no SQLite** |
| **Web server** | Apache (bundled) | `.htaccess` rules work identically |

### `.env`-Driven Configuration

Every environment-specific value in `.env`, never hardcoded:

```
# Local
APP_ENV=local
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=basefare_crm
ENCRYPTION_KEY_A=local_key_half_1
ENCRYPTION_KEY_B=local_key_half_2

# Production (Hostinger — manually configured, never committed)
APP_ENV=production
DB_HOST=localhost
DB_USER=u569...
DB_PASS=...
DB_NAME=u569...
ENCRYPTION_KEY_A=prod_key_half_1
ENCRYPTION_KEY_B=prod_key_half_2
```

`APP_ENV` controls:
- `local` → full error display, stack traces, debug toolbar, time override enabled
- `production` → errors logged to file only, never displayed to user

### Single Source of Truth: `database/schema.sql`

One master file containing every `CREATE TABLE IF NOT EXISTS` statement:
- Version-controlled — every schema change is a git commit
- Both local and production are built from this same file
- Safe to re-run (`IF NOT EXISTS` on all tables)

For incremental changes after initial deployment:
- `database/migrations/` folder with dated files: `2026_03_21_add_payroll_tables.sql`
- Master `schema.sql` always reflects the **current full schema** (kept in sync)

### Seed Data for Testing

`database/seed.sql` (gitignored — never reaches production):
- Default admin account with test credentials
- 3-4 test agents with different shift patterns
- Sample shift schedules for the current week
- A few test transactions across different types
- After `schema.sql` + `seed.sql` → fully functional local env in 30 seconds

### Error Handling by Environment

| Environment | Behavior |
|------------|----------|
| `local` | Full stack traces, PHP error display ON, debug bar |
| `production` | Errors logged to `/storage/logs/error.log`, user sees generic "Something went wrong" page |

### Backup Strategy

Before every migration/deploy on Hostinger:
1. Export MySQL database via phpMyAdmin (full `.sql` dump)
2. Store backup with date stamp
3. One bad migration on live data with no backup = lost attendance records = salary disputes

### Debug Time Override (Local Only)

For testing attendance features (late lockout, shift enforcement, auto clock-out) without waiting for real clock times:

```
# In .env (local only — ignored if APP_ENV=production)
DEBUG_TIME_OVERRIDE=09:15
```

When set, `AppTime::now()` returns the override value instead of the real server time. **Hard-disabled in production** — the helper function checks `APP_ENV` before applying.

### Local Cron Simulation

Hostinger runs cron jobs; Windows doesn't have cron. For local testing:
- **Option A:** Run cron scripts manually: `php cron/auto_clockout.php`
- **Option B:** Windows Task Scheduler to mimic cron intervals
- **Option C:** A dev-only endpoint `/dev/run-cron?job=auto_clockout` (protected behind `APP_ENV=local` check)

---

## Git Strategy & Deployment

### Repository Structure
The CRM is maintained as a **separate repository** (`basefare-crm`) from the marketing website to ensure clean separation of concerns, independent deployment pipelines, and focused version history.

### Branching Model
- **`main`**: Production-ready code. Every push to `main` triggers an auto-deploy update to the Hostinger server. Only stable, tested features from `dev` are merged here.
- **`dev`**: Primary development branch. All feature integrations and daily work happen here.
- **Feature Branches** (Optional): For larger features (e.g., `feature/attendance`, `feature/card-encryption`), created from `dev` and merged back once complete.

### Deployment Flow
1. **Local Development**: Work happens on the `dev` branch.
2. **Push to GitHub**: Changes are pushed to the `dev` branch on GitHub for backup and review.
3. **Merge to Main**: When a milestone is reached, `dev` is merged into `main`.
4. **Auto-Deploy**: Hostinger's Git integration pulls the latest `main` branch directly to the `public_html` (or designated CRM subdirectory) on the server.

### Environment Safety
- The `.env` file is **never committed** to Git.
- The `database/seed.sql` file is **never committed** to Git.
- Individual agent payroll snapshots in `storage/payroll/` are **never committed** to Git.
- The `.gitignore` must be strictly maintained to prevent accidental disclosure of PII or credentials.

---

## Pre-Build Checklist

- [ ] Verify Hostinger plan: cron min interval, PHP memory_limit, OPcache status
- [ ] Test Slim 4 + Eloquent on Hostinger (basic route + DB query)
- [ ] Confirm `.env` placement above webroot works on Hostinger
- [ ] Get client sign-off on card storage approach (PCI acknowledged)
- [ ] Paper wireframe: shift scheduling UI + payroll finalization flow with client
- [ ] Install MySQL locally (XAMPP/Laragon) and verify version matches Hostinger
- [ ] Create `database/schema.sql` as first deliverable
- [ ] Create `database/seed.sql` for local testing (gitignored)
- [ ] Set up git repo and branching strategy
- [ ] Test `.env`-driven config switching between local and production
