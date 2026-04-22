<?php

namespace App\Services;

use App\Models\AttendanceBreak;
use App\Models\AttendanceSession;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * BreakAbuseDetector
 * 
 * Runs on EVERY washroom break end (not via cron).
 * Checks against admin-configurable thresholds stored in system_config.
 * If flagged, sets the break's `flagged` column = 1 and creates an admin alert.
 * 
 * Architecture Reference: Section 1.5 — Washroom Break Abuse Detection
 */
class BreakAbuseDetector
{
    /**
     * Analyze a washroom break that just ended.
     *
     * @param  AttendanceBreak   $break    The break that was just ended
     * @param  AttendanceSession $session  The parent attendance session
     * @return array  ['flagged' => bool, 'reasons' => string[]]
     */
    public function analyze(AttendanceBreak $break, AttendanceSession $session): array
    {
        // Only analyze washroom breaks
        if ($break->break_type !== AttendanceBreak::TYPE_WASHROOM) {
            return ['flagged' => false, 'reasons' => []];
        }

        // Load thresholds from system_config
        $singleMax = (int) $this->getConfig('abuse.single_washroom_max', 15);
        $countMax  = (int) $this->getConfig('abuse.washroom_count_max', 4);
        $totalMax  = (int) $this->getConfig('abuse.washroom_total_max', 45);

        $reasons = [];

        // 1. Single break too long
        // Use the already-persisted duration_mins (saved by endBreak() before analyze() is called)
        // to ensure the detector and DB record are always consistent.
        $duration = (int) ($break->duration_mins ?? $break->calculateDuration());
        if ($duration > $singleMax) {
            $reasons[] = "single_too_long: {$duration} mins (max {$singleMax})";
        }

        // 2. Too many washroom breaks this session
        $washroomBreaks = AttendanceBreak::where('session_id', $session->id)
            ->where('break_type', AttendanceBreak::TYPE_WASHROOM)
            ->whereNotNull('break_end')
            ->get();

        $totalCount = $washroomBreaks->count();
        if ($totalCount > $countMax) {
            $reasons[] = "too_many: {$totalCount} breaks (max {$countMax})";
        }

        // 3. Total washroom time too high
        // Use persisted duration_mins for the same consistency reason as above.
        $totalMins = (int) $washroomBreaks->sum('duration_mins');
        if ($totalMins > $totalMax) {
            $reasons[] = "total_too_long: {$totalMins} mins (max {$totalMax})";
        }

        $flagged = !empty($reasons);

        if ($flagged) {
            // Mark the break as flagged in DB
            $break->update(['flagged' => 1]);

            // Create admin alert notification
            $this->createAdminAlert($session, $reasons);

            // Log the event
            error_log("[BreakAbuseDetector] Agent {$session->user_id} flagged: " . implode(', ', $reasons));
        }

        return ['flagged' => $flagged, 'reasons' => $reasons];
    }

    /**
     * Get a config value from system_config table.
     */
    private function getConfig(string $key, $default = null)
    {
        $row = Capsule::table('system_config')->where('key', $key)->first();
        return $row ? $row->value : $default;
    }

    /**
     * Create an admin notification about break abuse.
     * (Uses a raw insert for now — will use NotificationService in Phase 6)
     */
    private function createAdminAlert(AttendanceSession $session, array $reasons): void
    {
        try {
            // Get agent name for the notification
            $agentName = $session->user ? $session->user->name : "Agent #{$session->user_id}";
            $reasonText = implode('; ', $reasons);

            // Insert into activity_log as an admin-visible alert
            Capsule::table('activity_log')->insert([
                'user_id'     => $session->user_id,
                'action'      => 'break_abuse_detected',
                'entity_type' => 'attendance_breaks',
                'entity_id'   => $session->id,
                'details'     => json_encode([
                    'agent_name' => $agentName,
                    'session_id' => $session->id,
                    'date'       => $session->date,
                    'reasons'    => $reasons,
                ]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log("[BreakAbuseDetector::createAdminAlert] Error: " . $e->getMessage());
        }
    }
}
