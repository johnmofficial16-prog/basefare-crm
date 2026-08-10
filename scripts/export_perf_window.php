<?php
/**
 * export_perf_window.php — read-only analytical export for centre/agent review.
 *
 * Pulls a date window of EMPLOYEE-level activity out of production into flat
 * CSVs so the analysis can be run off-server. Every query is a SELECT; nothing
 * here writes to the database.
 *
 * DELIBERATELY PII-FREE on the customer side: no customer names, emails, phones,
 * PNRs, card data, note bodies or email bodies are read. Employee names are
 * included because the whole point is per-agent attribution.
 *
 * Usage (over SSH, from the project root):
 *   php scripts/export_perf_window.php 2026-06-01 2026-07-31 ~/bf_export
 *
 * Write the output OUTSIDE public_html. The project root sits inside the
 * docroot, so anything written under it risks being fetchable over HTTP.
 * Delete the export directory once you've pulled it down.
 */

// ── Args ─────────────────────────────────────────────────────────────────────
$from = $argv[1] ?? '2026-06-01';
$to   = $argv[2] ?? '2026-07-31';
$out  = $argv[3] ?? (getenv('HOME') ?: '.') . '/bf_export';

foreach ([$from, $to] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        exit("Bad date '{$d}'. Use YYYY-MM-DD.\n");
    }
}
$fromDt = $from . ' 00:00:00';
$toDt   = $to   . ' 23:59:59';

if (!is_dir($out) && !mkdir($out, 0700, true)) {
    exit("Cannot create output dir: {$out}\n");
}

// ── Connect (reads the app's .env, no framework bootstrap) ───────────────────
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    exit("No .env at {$envPath}\n");
}
$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

try {
    $db = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
            $env['DB_DATABASE'] ?? ''
        ),
        $env['DB_USERNAME'] ?? '',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    exit('DB connect failed: ' . $e->getMessage() . "\n");
}

echo "Window: {$from} .. {$to}\nOutput: {$out}\n\n";

/** Run a SELECT and write it to a CSV. Missing tables are reported, not fatal. */
function dump(PDO $db, string $dir, string $name, string $sql, array $bind = []): void
{
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . '.csv';
    try {
        $st = $db->prepare($sql);
        $st->execute($bind);
    } catch (Throwable $e) {
        echo str_pad($name, 22) . 'SKIPPED — ' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n";
        return;
    }

    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 cleanly
    $n = 0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($n === 0) {
            fputcsv($fh, array_keys($row));
        }
        fputcsv($fh, array_map(fn($v) => $v === null ? '' : $v, $row));
        $n++;
    }
    if ($n === 0) {
        fwrite($fh, "(no rows)\n");
    }
    fclose($fh);
    echo str_pad($name, 22) . $n . " rows\n";
}

// ── 1. Roster: who exists, their rank and reporting line ─────────────────────
// Includes inactive/suspended and soft-deleted users deliberately — someone who
// left mid-window still worked part of it and must appear in the analysis.
dump($db, $out, 'agents', "
    SELECT id, name, role, status, reports_to_id, grace_period_mins,
           created_at, deleted_at
    FROM users
    ORDER BY id
");

// ── 2. Centre configuration (JSR / MOH / DMR membership rules) ───────────────
dump($db, $out, 'centre_config', "
    SELECT `key`, `value`
    FROM system_config
    WHERE `key` LIKE 'centre.%' OR `key` = 'default_currency'
");

// ── 3. Attendance, one row per session ───────────────────────────────────────
dump($db, $out, 'attendance_daily', "
    SELECT s.user_id,
           s.date,
           s.clock_in,
           s.clock_out,
           s.scheduled_start,
           s.scheduled_end,
           s.late_minutes,
           s.total_work_mins,
           s.total_break_mins,
           s.status,
           s.resolution_required,
           (SELECT COUNT(*) FROM attendance_breaks b
             WHERE b.session_id = s.id)                        AS break_count,
           (SELECT COUNT(*) FROM attendance_breaks b
             WHERE b.session_id = s.id AND b.flagged = 1)      AS flagged_breaks,
           (SELECT COALESCE(SUM(b.duration_mins),0) FROM attendance_breaks b
             WHERE b.session_id = s.id AND b.break_type = 'washroom') AS washroom_mins
    FROM attendance_sessions s
    WHERE s.date BETWEEN :f AND :t
    ORDER BY s.user_id, s.date
", ['f' => $from, 't' => $to]);

// ── 4. Roster: what they were SCHEDULED to work ──────────────────────────────
// This is what makes "absent" meaningful — without it, days off read as absence.
dump($db, $out, 'shift_roster', "
    SELECT agent_id, shift_date, shift_start, shift_end
    FROM shift_schedules
    WHERE shift_date BETWEEN :f AND :t
    ORDER BY agent_id, shift_date
", ['f' => $from, 't' => $to]);

// ── 5. Transactions, aggregated per agent per day per status ─────────────────
// No customer columns, no PNR. Money only in aggregate.
dump($db, $out, 'txn_daily', "
    SELECT agent_id,
           DATE(created_at)                AS d,
           type,
           status,
           COUNT(*)                        AS n,
           COALESCE(SUM(total_amount),0)   AS revenue,
           COALESCE(SUM(profit_mco),0)     AS gross_mco,
           COALESCE(SUM(refund_mco_impact),0) AS refund_impact,
           SUM(refund_status <> 'none')    AS refunded_n
    FROM transactions
    WHERE created_at BETWEEN :f AND :t
    GROUP BY agent_id, DATE(created_at), type, status
    ORDER BY agent_id, d
", ['f' => $fromDt, 't' => $toDt]);

// ── 6. Acceptances (auth links sent + approved) — an effort proxy ────────────
dump($db, $out, 'acceptances_daily', "
    SELECT agent_id,
           DATE(created_at) AS d,
           status,
           is_preauth,
           COUNT(*)         AS n
    FROM acceptance_requests
    WHERE created_at BETWEEN :f AND :t
    GROUP BY agent_id, DATE(created_at), status, is_preauth
    ORDER BY agent_id, d
", ['f' => $fromDt, 't' => $toDt]);

// ── 7. CRM work intensity — the stand-in for call volume ─────────────────────
// Action counts only; no entity payloads, no note bodies.
dump($db, $out, 'activity_daily', "
    SELECT user_id,
           DATE(created_at) AS d,
           action,
           COUNT(*)         AS n
    FROM activity_log
    WHERE created_at BETWEEN :f AND :t
    GROUP BY user_id, DATE(created_at), action
    ORDER BY user_id, d
", ['f' => $fromDt, 't' => $toDt]);

dump($db, $out, 'notes_daily', "
    SELECT user_id,
           DATE(created_at) AS d,
           entity_type,
           action,
           COUNT(*)         AS n
    FROM record_notes
    WHERE created_at BETWEEN :f AND :t
    GROUP BY user_id, DATE(created_at), entity_type, action
    ORDER BY user_id, d
", ['f' => $fromDt, 't' => $toDt]);

// ── 8. Other per-agent output surfaces ───────────────────────────────────────
dump($db, $out, 'etickets_daily', "
    SELECT agent_id, DATE(created_at) AS d, COUNT(*) AS n
    FROM etickets
    WHERE created_at BETWEEN :f AND :t
    GROUP BY agent_id, DATE(created_at)
", ['f' => $fromDt, 't' => $toDt]);

dump($db, $out, 'invoices_daily', "
    SELECT created_by AS agent_id, DATE(created_at) AS d, status, COUNT(*) AS n
    FROM invoices
    WHERE created_at BETWEEN :f AND :t
    GROUP BY created_by, DATE(created_at), status
", ['f' => $fromDt, 't' => $toDt]);

// ── 9. Login activity — catches 'logs in, does nothing' ──────────────────────
dump($db, $out, 'logins_daily', "
    SELECT user_id, DATE(created_at) AS d, COUNT(*) AS n
    FROM activity_log
    WHERE action LIKE '%login%' AND created_at BETWEEN :f AND :t
    GROUP BY user_id, DATE(created_at)
", ['f' => $fromDt, 't' => $toDt]);

echo "\nDone. Pull it down, then delete {$out} from the server.\n";
