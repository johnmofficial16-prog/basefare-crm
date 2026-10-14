<?php
/**
 * hr_phase0_checks.php — read-only health check of the CRM data the HR portal
 * will build on (HR_PLAN.md §7, Phase 0). Confirms on live data what the CRM
 * audit found by reading code, before anything is fixed.
 *
 * READ-ONLY: every statement is a SELECT/SHOW, and the DB session is put into
 * READ ONLY mode first, so a write would be refused by the server anyway.
 * Output is counts and record ids only — no names, emails or money owed to
 * named people — so it can be pasted into chat.
 *
 * Usage (from the project root, over SSH):
 *   php scripts/hr_phase0_checks.php
 *
 * Connection: DB_* from the environment if set, else from .env (the same way
 * the other scripts in this folder connect).
 */

// ── Connect ──────────────────────────────────────────────────────────────────
$env = [];
date_default_timezone_set('Asia/Kolkata');   // same as the app, so "today" is the IST date
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}
$cfg = fn(string $k, string $d = '') => (getenv($k) !== false && getenv($k) !== '') ? getenv($k) : ($env[$k] ?? $d);

try {
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg('DB_HOST', '127.0.0.1'), $cfg('DB_PORT', '3306'), $cfg('DB_DATABASE')),
        $cfg('DB_USERNAME'), $cfg('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $db->exec('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable $e) {
    exit('DB connect failed: ' . $e->getMessage() . "\n");
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function q(PDO $db, string $sql, array $p = []): array
{
    try {
        $st = $db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [['error' => preg_replace('/\s+/', ' ', $e->getMessage())]];
    }
}

function one(PDO $db, string $sql, array $p = [])
{
    $r = q($db, $sql, $p);
    if (!$r) return null;
    if (isset($r[0]['error'])) return 'ERROR: ' . $r[0]['error'];
    return array_values($r[0])[0];
}

function table(array $rows): void
{
    if (!$rows) { echo "  (none)\n"; return; }
    $cols = array_keys($rows[0]);
    $w = [];
    foreach ($cols as $c) {
        $w[$c] = strlen($c);
        foreach ($rows as $r) $w[$c] = max($w[$c], strlen((string) ($r[$c] ?? 'NULL')));
    }
    $line = fn($vals) => '  ' . implode('  ', array_map(fn($c) => str_pad((string) $vals[$c], $w[$c]), $cols));
    echo $line(array_combine($cols, $cols)), "\n";
    echo '  ' . implode('  ', array_map(fn($c) => str_repeat('-', $w[$c]), $cols)), "\n";
    foreach ($rows as $r) echo $line(array_map(fn($v) => $v ?? 'NULL', $r)), "\n";
}

function h(string $t): void { echo "\n== {$t} ", str_repeat('=', max(3, 74 - strlen($t))), "\n"; }

echo "HR Phase 0 checks — run " . date('Y-m-d H:i:s') . " (server clock)\n";

// ── Q0 Server settings ───────────────────────────────────────────────────────
h('Q0 Server settings');
table(q($db, 'SELECT VERSION() AS version, @@global.sql_mode AS global_sql_mode, @@session.sql_mode AS session_sql_mode,
                     @@global.time_zone AS global_tz, @@session.time_zone AS session_tz,
                     NOW() AS db_now, UTC_TIMESTAMP() AS db_utc'));
echo "  PHP time: " . date('Y-m-d H:i:s T') . "\n";
table(q($db, "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS type, IS_NULLABLE AS nullable,
                     COLUMN_DEFAULT AS dflt, EXTRA AS extra
              FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'transactions' AND COLUMN_NAME IN ('profit_mco','currency'))
                  OR (TABLE_NAME = 'users' AND COLUMN_NAME IN ('grace_period_mins','status','role'))
                  OR (TABLE_NAME = 'attendance_overrides' AND COLUMN_NAME = 'override_type')
                  OR (TABLE_NAME = 'attendance_sessions' AND COLUMN_NAME IN ('date','status')))
              ORDER BY tbl, col"));
table(q($db, "SELECT `key`, `value` FROM system_config WHERE `key` IN ('business_day_start_hour','default_currency')"));

// ── Headcount (thresholds that trigger PF / POSH / grievance committee) ─────
h('Headcount — active, not deleted, by role');
table(q($db, "SELECT role, COUNT(*) AS people FROM users
              WHERE deleted_at IS NULL AND status = 'active' GROUP BY role ORDER BY people DESC"));
echo '  Total active: ' . one($db, "SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'") . "\n";
echo '  Suspended/inactive (not deleted): ' . one($db, "SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status <> 'active'") . "\n";

// ── Q1 Grace windows ─────────────────────────────────────────────────────────
h('Q1 Late-login grace (minutes) by role and status');
table(q($db, "SELECT role, status, grace_period_mins AS grace, COUNT(*) AS people
              FROM users WHERE deleted_at IS NULL GROUP BY role, status, grace_period_mins ORDER BY role, status, grace"));

// ── Q2 Roster horizon ────────────────────────────────────────────────────────
h('Q2 Roster: which templates, and how far ahead rows exist');
table(q($db, "SELECT COALESCE(t.name, '(no template)') AS template, MIN(s.shift_date) AS first_date,
                     MAX(s.shift_date) AS last_date, COUNT(*) AS rows_
              FROM shift_schedules s LEFT JOIN shift_templates t ON t.id = s.template_id
              WHERE s.shift_date >= '2026-08-01' GROUP BY t.name ORDER BY last_date DESC"));
echo '  Latest roster date of any kind: ' . one($db, 'SELECT MAX(shift_date) FROM shift_schedules') . "\n";
echo '  Today (server): ' . date('Y-m-d') . "\n";
echo '  Agents with a roster row today: '
    . one($db, "SELECT COUNT(DISTINCT s.agent_id) FROM shift_schedules s JOIN users u ON u.id = s.agent_id
                WHERE s.shift_date = CURDATE() AND u.role = 'agent' AND u.status = 'active' AND u.deleted_at IS NULL")
    . ' of ' . one($db, "SELECT COUNT(*) FROM users WHERE role = 'agent' AND status = 'active' AND deleted_at IS NULL") . " active agents\n";
echo "  Publish status of rows in the last 30 days:\n";
table(q($db, "SELECT publish_status, COUNT(*) AS rows_ FROM shift_schedules
              WHERE shift_date >= CURDATE() - INTERVAL 30 DAY GROUP BY publish_status"));
echo "  Shift start/end patterns in the last 30 days:\n";
table(q($db, "SELECT TIME_FORMAT(shift_start, '%H:%i') AS start_, TIME_FORMAT(shift_end, '%H:%i') AS end_, COUNT(*) AS rows_
              FROM shift_schedules WHERE shift_date >= CURDATE() - INTERVAL 30 DAY
              GROUP BY shift_start, shift_end ORDER BY rows_ DESC LIMIT 10"));

// ── Q3 Who opens sessions ────────────────────────────────────────────────────
h('Q3 Attendance sessions by month and by who opened them');
table(q($db, "SELECT DATE_FORMAT(date, '%Y-%m') AS month, created_via, COUNT(*) AS sessions
              FROM attendance_sessions WHERE date >= '2026-07-01' GROUP BY month, created_via ORDER BY month, created_via"));

// ── Q4 Auto-close artefacts ──────────────────────────────────────────────────
h('Q4 Sessions on 24h shifts (start = end) since 17 Aug: how they ended');
table(q($db, "SELECT status, resolution_required AS needs_fix, (clock_out IS NULL) AS no_clock_out,
                     (TIME(clock_in) < '18:00:00') AS clocked_in_before_6pm, COUNT(*) AS sessions,
                     ROUND(AVG(total_work_mins)) AS avg_mins
              FROM attendance_sessions
              WHERE scheduled_start = scheduled_end AND date >= '2026-08-17'
              GROUP BY status, resolution_required, (clock_out IS NULL), (TIME(clock_in) < '18:00:00')
              ORDER BY sessions DESC"));
h('Q4b All sessions in the last 60 days by status');
table(q($db, "SELECT status, resolution_required AS needs_fix, (clock_out IS NULL) AS no_clock_out, COUNT(*) AS sessions,
                     ROUND(AVG(total_work_mins)) AS avg_mins, SUM(total_work_mins IS NULL) AS no_minutes
              FROM attendance_sessions WHERE date >= CURDATE() - INTERVAL 60 DAY
              GROUP BY status, resolution_required, (clock_out IS NULL) ORDER BY sessions DESC"));
echo '  Auto-closed sessions credited under 2 hours (last 60 days): '
    . one($db, "SELECT COUNT(*) FROM attendance_sessions WHERE status = 'auto_closed' AND total_work_mins < 120
                AND date >= CURDATE() - INTERVAL 60 DAY") . "\n";
echo '  Person-days with more than one session (split nights, last 60 days): '
    . one($db, "SELECT COUNT(*) FROM (SELECT user_id, date FROM attendance_sessions WHERE date >= CURDATE() - INTERVAL 60 DAY
                GROUP BY user_id, date HAVING COUNT(*) > 1) x") . "\n";

// ── Q4c Clock-in blocks by hour (the midnight–6am dead zone) ───────────────
h('Q4c Blocked clock-in attempts in the last 30 days, by hour of day');
table(q($db, "SELECT HOUR(created_at) AS hour_, COUNT(*) AS blocked FROM activity_log
              WHERE action = 'clock_in_blocked' AND created_at >= NOW() - INTERVAL 30 DAY
              GROUP BY HOUR(created_at) ORDER BY hour_"));

// ── Q5 Overrides ─────────────────────────────────────────────────────────────
h('Q5 Late-login override types actually stored');
table(q($db, "SELECT CONCAT('[', override_type, ']') AS override_type, COUNT(*) AS rows_ FROM attendance_overrides GROUP BY override_type"));
echo "  Overrides by hour of creation, last 60 days (the mobile UTC-date bug shows at 00–05):\n";
table(q($db, "SELECT HOUR(created_at) AS hour_, COUNT(*) AS rows_ FROM attendance_overrides
              WHERE created_at >= NOW() - INTERVAL 60 DAY GROUP BY HOUR(created_at) ORDER BY hour_"));

// ── Q6 Cron health ───────────────────────────────────────────────────────────
h('Q6 Background jobs and stranded sessions');
echo '  Last auto clock-out run: ' . one($db, "SELECT MAX(created_at) FROM activity_log WHERE action = 'auto_clockout_ran'") . "\n";
echo '  Auto clock-out runs in the last 24h: ' . one($db, "SELECT COUNT(*) FROM activity_log WHERE action = 'auto_clockout_ran' AND created_at >= NOW() - INTERVAL 1 DAY") . "\n";
echo '  Active sessions opened more than 26h ago: ' . one($db, "SELECT COUNT(*) FROM attendance_sessions WHERE status = 'active' AND clock_in < NOW() - INTERVAL 26 HOUR") . "\n";
echo "  Most recent activity per job-like action (last 7 days):\n";
table(q($db, "SELECT action, COUNT(*) AS runs, MAX(created_at) AS last_run FROM activity_log
              WHERE created_at >= NOW() - INTERVAL 7 DAY AND action LIKE '%ran%' GROUP BY action ORDER BY last_run DESC LIMIT 10"));

// ── Q7 Sales integrity (feeds incentives) ────────────────────────────────────
h('Q7 Sales data that will feed incentives');
echo '  Acceptances with more than one live transaction (possible duplicates): '
    . one($db, "SELECT COUNT(*) FROM (SELECT acceptance_id FROM transactions WHERE acceptance_id IS NOT NULL AND status <> 'voided'
                GROUP BY acceptance_id HAVING COUNT(*) > 1) x") . "\n";
table(q($db, "SELECT acceptance_id, COUNT(*) AS live_txns, GROUP_CONCAT(id ORDER BY id) AS txn_ids
              FROM transactions WHERE acceptance_id IS NOT NULL AND status <> 'voided'
              GROUP BY acceptance_id HAVING COUNT(*) > 1 ORDER BY acceptance_id DESC LIMIT 15"));
echo '  Voided rows with no void record (voided via the edit form): '
    . one($db, "SELECT COUNT(*) FROM transactions WHERE status = 'voided' AND void_of_transaction_id IS NULL AND voided_at IS NULL") . "\n";
echo '  Approved rows with no approval note: '
    . one($db, "SELECT COUNT(*) FROM transactions t WHERE status = 'approved' AND NOT EXISTS
                (SELECT 1 FROM record_notes n WHERE n.entity_type = 'transaction' AND n.entity_id = t.id AND n.action = 'approved')") . "\n";
echo '  Recorded by someone other than the acceptance owner: '
    . one($db, "SELECT COUNT(*) FROM transactions t JOIN acceptance_requests a ON a.id = t.acceptance_id WHERE t.agent_id <> a.agent_id") . "\n";
echo "  By currency and status (count only):\n";
table(q($db, "SELECT currency, status, COUNT(*) AS txns FROM transactions GROUP BY currency, status ORDER BY txns DESC"));
echo "  By type (count, and how many are marked as miles bookings):\n";
table(q($db, "SELECT type, COUNT(*) AS txns, SUM(is_miles_booking = 1) AS miles FROM transactions GROUP BY type ORDER BY txns DESC"));
echo "  Disputes / chargebacks still counted as approved:\n";
table(q($db, "SELECT COALESCE(dispute_status, '(none)') AS dispute_status, COUNT(*) AS approved_txns FROM transactions
              WHERE status = 'approved' GROUP BY dispute_status ORDER BY approved_txns DESC"));

echo "\nDone. Nothing was changed.\n";
