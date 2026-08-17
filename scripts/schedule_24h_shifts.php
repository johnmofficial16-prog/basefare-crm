<?php
/**
 * 24/7 roster — put every active agent on round-the-clock shifts (client
 * request, 16 Aug 2026). Run over SSH from the repo root:
 *
 *   php scripts/schedule_24h_shifts.php                 # DRY RUN (default)
 *   php scripts/schedule_24h_shifts.php --apply         # write it
 *   php scripts/schedule_24h_shifts.php --apply --days=30
 *   php scripts/schedule_24h_shifts.php --rollback      # undo (script rows only)
 *
 * What --apply does (one transaction):
 *  1. Ensures a shift template "Round-the-Clock (24h)" 18:00→18:00 exists.
 *     18:00 = the operations day anchor, so "late" counts from shift start.
 *  2. Upserts a shift row for EVERY active agent for the next N days
 *     (default 14). Existing rows for those dates are OVERWRITTEN to 24h
 *     (uq_agent_shift_date is respected via ON DUPLICATE KEY UPDATE).
 *  3. Sets users.grace_period_mins = 720 for those agents. THIS is what
 *     actually opens the clock-in gate: AttendanceService only allows
 *     clock-in within ±grace of shift_start, so 18:00 ± 12h = the full day.
 *     Without this step the "24h shift" would still block clock-ins.
 *
 * --rollback deletes ONLY rows created/claimed by this template (today
 * forward — past days keep their history) and resets grace to NULL
 * (= the 30-min default). Hand-made shifts with other templates are untouched.
 *
 * KNOWN TRADE-OFFS (accepted by running this):
 *  - late_minutes are measured from 18:00, so an agent clocking in at 05:00
 *    shows ~660 late minutes. With no fixed report time, lateness stats stop
 *    meaning much. They still flow into the monthly report/payroll CSV.
 *  - A forgotten clock-out on a 24h shift ages past the auto-clockout cron's
 *    24h line and lands in its "no pay computed, admin resolves" branch.
 *    Agents MUST clock out; the resolution queue catches the ones who don't.
 *
 * Keep the roster horizon topped up: re-run with --apply weekly (idempotent),
 * or ask for this to be added as a cron.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$apply    = in_array('--apply', $argv, true);
$rollback = in_array('--rollback', $argv, true);
$days     = 14;
$roles    = ['agent'];
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, min(90, (int) $m[1]));
    }
    // --roles=agent,manager[,csa] — which roles get the 24/7 roster + grace.
    // Managers clock in and appear in the attendance/payroll reports too, so
    // they need the same rows or their clock-ins stay gated to the 30-min
    // default around a shift they don't have.
    if (preg_match('/^--roles=([a-z,]+)$/', $arg, $m)) {
        $roles = array_values(array_intersect(explode(',', $m[1]), ['agent', 'manager', 'csa']));
        if ($roles === []) {
            fwrite(STDERR, "No valid roles in --roles (allowed: agent,manager,csa)\n");
            exit(1);
        }
    }
}
$rolesIn = "'" . implode("','", $roles) . "'";

const TEMPLATE_NAME = 'Round-the-Clock (24h)';
const SHIFT_START   = '18:00:00';
const SHIFT_END     = '18:00:00';
const GRACE_MINUTES = 720;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_USERNAME'] ?? 'root',
    $_ENV['DB_PASSWORD'] ?? '',
    $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    (int) ($_ENV['DB_PORT'] ?? 3306)
);
$db->set_charset('utf8mb4');

$agents = [];
$res = $db->query("SELECT id, name FROM users WHERE role IN ({$rolesIn}) AND status = 'active' AND deleted_at IS NULL ORDER BY id");
while ($row = $res->fetch_assoc()) {
    $agents[] = $row;
}
$res->free();

echo count($agents) . " active users found (roles: " . implode(",", $roles) . ").\n";
if ($agents === []) {
    echo "Nothing to do.\n";
    exit(0);
}

// ── ROLLBACK ─────────────────────────────────────────────────────────────────
if ($rollback) {
    $tid = $db->query("SELECT id FROM shift_templates WHERE name = '" . TEMPLATE_NAME . "'")->fetch_row()[0] ?? null;
    if ($tid === null) {
        echo "Template not found — nothing this script created is in place.\n";
        exit(0);
    }
    $db->begin_transaction();
    $db->query("DELETE FROM shift_schedules WHERE template_id = " . (int) $tid . " AND shift_date >= CURDATE()");
    $deleted = $db->affected_rows;
    $db->query("UPDATE users SET grace_period_mins = NULL WHERE role IN ({$rolesIn})");
    $graced = $db->affected_rows;
    $db->commit();
    echo "Rolled back: {$deleted} future 24h shift rows deleted, grace reset for {$graced} agents (default 30 min applies).\n";
    exit(0);
}

// ── PLAN ─────────────────────────────────────────────────────────────────────
$dates = [];
for ($i = 0; $i < $days; $i++) {
    $dates[] = date('Y-m-d', strtotime("+{$i} days"));
}
echo ($apply ? 'APPLYING' : 'DRY RUN (add --apply to write)') . ":\n";
echo '  ' . count($agents) . ' agents x ' . count($dates) . " days ({$dates[0]} .. " . end($dates) . ")\n";
echo '  shift ' . SHIFT_START . ' -> ' . SHIFT_END . " (24h, overnight-style), grace " . GRACE_MINUTES . " mins = clock-in allowed anytime\n";

$existing = (int) ($db->query(
    "SELECT COUNT(*) FROM shift_schedules WHERE shift_date >= '{$dates[0]}' AND shift_date <= '" . end($dates) . "'"
)->fetch_row()[0]);
echo "  {$existing} existing shift rows in that window will be overwritten to 24h where they collide.\n";

if (!$apply) {
    exit(0);
}

// ── APPLY ────────────────────────────────────────────────────────────────────
$db->begin_transaction();

$tid = $db->query("SELECT id FROM shift_templates WHERE name = '" . TEMPLATE_NAME . "'")->fetch_row()[0] ?? null;
if ($tid === null) {
    $db->query("INSERT INTO shift_templates (name, start_time, end_time) VALUES ('" . TEMPLATE_NAME . "', '" . SHIFT_START . "', '" . SHIFT_END . "')");
    $tid = $db->insert_id;
    echo "  created template #{$tid}\n";
}
$tid = (int) $tid;

$stmt = $db->prepare(
    "INSERT INTO shift_schedules (agent_id, shift_date, shift_start, shift_end, template_id, schedule_week)
     VALUES (?, ?, ?, ?, {$tid}, DATE_SUB(?, INTERVAL WEEKDAY(?) DAY))
     ON DUPLICATE KEY UPDATE shift_start = VALUES(shift_start), shift_end = VALUES(shift_end), template_id = VALUES(template_id)"
);

$written = 0;
foreach ($agents as $agent) {
    foreach ($dates as $d) {
        $aid = (int) $agent['id'];
        $ss  = SHIFT_START;
        $se  = SHIFT_END;
        $stmt->bind_param('isssss', $aid, $d, $ss, $se, $d, $d);
        $stmt->execute();
        $written++;
    }
}
$stmt->close();

$db->query("UPDATE users SET grace_period_mins = " . GRACE_MINUTES . " WHERE role IN ({$rolesIn}) AND status = 'active' AND deleted_at IS NULL");
$graced = $db->affected_rows;

$db->commit();

echo "  upserted {$written} shift rows; grace set to " . GRACE_MINUTES . " mins for {$graced} agents.\n";
echo "Done. Re-run weekly (idempotent) to keep the horizon topped up, or --rollback to undo.\n";
exit(0);
