<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\AttendanceBreak;
use App\Models\AttendanceOverride;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\BreakAbuseDetector;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * AttendanceService
 * 
 * The single most important class in the entire CRM.
 * All clock-in/out, break, and state machine logic lives here.
 * Controllers should NEVER contain business logic — only this service.
 *
 * Architecture References: Sections 1.1, 1.3, 1.5, 1.6
 */
class AttendanceService
{
    // State machine states
    const STATE_NOT_CLOCKED_IN = 'not_clocked_in';
    const STATE_CLOCKED_IN     = 'clocked_in';
    const STATE_ON_BREAK       = 'on_break';
    const STATE_CLOCKED_OUT    = 'clocked_out';

    private ShiftService $shiftService;
    private BreakAbuseDetector $abuseDetector;

    public function __construct()
    {
        $this->shiftService  = new ShiftService();
        $this->abuseDetector = new BreakAbuseDetector();
    }

    // =========================================================================
    // STATE MACHINE (Section 1.6)
    // =========================================================================

    /**
     * Get the current attendance state for a user.
     * Uses a 60-second PHP session cache to reduce DB load.
     *
     * @return array ['state' => string, 'session' => ?AttendanceSession, 'break' => ?AttendanceBreak]
     */
    public function getCurrentState(int $userId): array
    {
        $cacheKey = "att_state_{$userId}";
        $cacheTs  = "att_state_ts_{$userId}";

        // Check session cache — only re-query if stale (>60 seconds)
        if (
            isset($_SESSION[$cacheKey], $_SESSION[$cacheTs]) &&
            (time() - $_SESSION[$cacheTs]) < 60
        ) {
            $cached = $_SESSION[$cacheKey];
            // Re-hydrate the session object if we have an active session
            if ($cached['state'] !== self::STATE_NOT_CLOCKED_IN && $cached['state'] !== self::STATE_CLOCKED_OUT) {
                $session = AttendanceSession::find($cached['session_id'] ?? 0);
                if (!$session || $session->status !== AttendanceSession::STATUS_ACTIVE) {
                    // DB says session is no longer active — cache was stale
                    $this->bustStateCache($userId);
                    return $this->getCurrentState($userId); // recurse once
                }
                $activeBreak = $session->getActiveBreak();
                return [
                    'state'   => $activeBreak ? self::STATE_ON_BREAK : self::STATE_CLOCKED_IN,
                    'session' => $session,
                    'break'   => $activeBreak,
                ];
            }
            return ['state' => $cached['state'], 'session' => null, 'break' => null];
        }

        // B1 FIX: Check for ANY active session — no date restriction.
        // The active session always wins regardless of which day it was opened.
        // (Stranded sessions from missed clock-outs are caught this way.)
        $session = AttendanceSession::active()
                       ->forUser($userId)
                       ->latest('id') // most recent active session wins
                       ->first();

        if (!$session) {
            // No active session — check if they completed ANY session today
            $completedSession = AttendanceSession::forUser($userId)
                             ->forDate(date('Y-m-d'))
                             ->whereIn('status', [AttendanceSession::STATUS_COMPLETED, AttendanceSession::STATUS_AUTO_CLOSED])
                             ->latest('id')
                             ->first();

            $state = self::STATE_NOT_CLOCKED_IN;
            
            if ($completedSession) {
                $state = self::STATE_CLOCKED_OUT;
                // OPTION A FIX: If the admin updated their shift schedule AFTER they clocked out,
                // auto-unlock the state so they can clock in for the new shift.
                $shift = $this->shiftService->getAgentShiftForDate($userId, date('Y-m-d'));
                if ($shift && $shift->updated_at && $completedSession->clock_out) {
                    if (strtotime($shift->updated_at) > strtotime($completedSession->clock_out)) {
                        $state = self::STATE_NOT_CLOCKED_IN;
                    }
                }
            }

            $this->cacheState($userId, ['state' => $state]);
            return ['state' => $state, 'session' => null, 'break' => null];
        }

        $activeBreak = $session->getActiveBreak();
        $state = $activeBreak ? self::STATE_ON_BREAK : self::STATE_CLOCKED_IN;

        $this->cacheState($userId, ['state' => $state, 'session_id' => $session->id]);

        return ['state' => $state, 'session' => $session, 'break' => $activeBreak];
    }

    // =========================================================================
    // CLOCK IN (Section 1.3)
    // =========================================================================

    /**
     * Attempt to clock in an agent.
     * Enforces shift scheduling rules and grace periods.
     *
     * @return array ['success' => bool, 'message' => string, 'blocked_reason' => ?string, 'session' => ?AttendanceSession]
     */
    public function attemptClockIn(int $userId, string $ip, string $userAgent): array
    {
        // 1. Verify no active session already exists
        $existing = AttendanceSession::active()->forUser($userId)->first();
        if ($existing) {
            return ['success' => false, 'message' => 'You already have an active clock-in session.', 'blocked_reason' => 'already_active'];
        }

        // 2. Get the user record
        $user = User::find($userId);
        if (!$user || $user->status !== User::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'Your account is not active.', 'blocked_reason' => 'account_inactive'];
        }

        // 3. Admins and Managers bypass shift enforcement
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->createSession($userId, '09:00:00', '18:00:00', 0, $ip, $userAgent);
        }

        // 4. Get today's shift schedule
        $today = date('Y-m-d');
        $shift = $this->shiftService->getAgentShiftForDate($userId, $today);

        if (!$shift) {
            // Check for an override that allows login without a shift
            $override = AttendanceOverride::where('agent_id', $userId)
                            ->where('shift_date', $today)
                            ->where('override_type', AttendanceOverride::TYPE_LATE_LOGIN)
                            ->first();
            if ($override) {
                return $this->createSession($userId, '09:00:00', '18:00:00', 0, $ip, $userAgent);
            }
            return ['success' => false, 'message' => 'You are not scheduled today. Contact your admin.', 'blocked_reason' => 'not_scheduled'];
        }

        // 5. Enforce shift timing (Section 1.3)
        $now = new \DateTime();
        $shiftStart = new \DateTime($today . ' ' . $shift->shift_start);
        $gracePeriod = $user->grace_period_mins ?? 30;

        $earliestAllowed = (clone $shiftStart)->modify("-{$gracePeriod} minutes");
        $latestAllowed   = (clone $shiftStart)->modify("+{$gracePeriod} minutes");

        // Case A: Too early
        if ($now < $earliestAllowed) {
            $startFormatted = $shiftStart->format('g:i A');
            $earliestFormatted = $earliestAllowed->format('g:i A');
            return [
                'success'          => false,
                'message'          => "Too early. Your shift starts at {$startFormatted}. You can clock in from {$earliestFormatted}.",
                'blocked_reason'   => 'too_early',
                'shift_start'      => $shift->shift_start,
                'earliest_allowed' => $earliestFormatted,
            ];
        }

        // Case B: Within grace period window → ALLOW
        if ($now <= $latestAllowed) {
            $lateMins = 0;
            if ($now > $shiftStart) {
                $lateMins = (int) round(($now->getTimestamp() - $shiftStart->getTimestamp()) / 60);
            }
            return $this->createSession($userId, $shift->shift_start, $shift->shift_end, $lateMins, $ip, $userAgent);
        }

        // Case C: Past grace period → BLOCKED, need admin override
        $minsLate = (int) round(($now->getTimestamp() - $shiftStart->getTimestamp()) / 60);

        // Check if admin already approved an override for today
        $override = AttendanceOverride::where('agent_id', $userId)
                        ->where('shift_date', $today)
                        ->where('override_type', AttendanceOverride::TYPE_LATE_LOGIN)
                        ->first();

        if ($override) {
            // Override exists — allow clock-in with full late minutes recorded
            return $this->createSession($userId, $shift->shift_start, $shift->shift_end, $minsLate, $ip, $userAgent);
        }

        // Log the failed attempt
        $this->logFailedAttempt($userId, $today, $minsLate, $ip);

        return [
            'success'        => false,
            'message'        => "You are {$minsLate} minutes late. Contact your admin for an override.",
            'blocked_reason' => 'too_late',
            'late_minutes'   => $minsLate,
        ];
    }

    // =========================================================================
    // CLOCK OUT (Section 1.6)
    // =========================================================================

    /**
     * Clock out the current user. Validates state and computes totals.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function clockOut(int $userId): array
    {
        $stateInfo = $this->getCurrentState($userId);

        // Validate state transition: must be clocked_in (NOT on break)
        if ($stateInfo['state'] === self::STATE_ON_BREAK) {
            // Auto-end the current break before clocking out
            $this->endBreak($userId);
        }

        $session = $stateInfo['session'];
        if (!$session || $stateInfo['state'] === self::STATE_NOT_CLOCKED_IN) {
            return ['success' => false, 'message' => 'You are not clocked in.', 'code' => 409];
        }
        if ($stateInfo['state'] === self::STATE_CLOCKED_OUT) {
            return ['success' => false, 'message' => 'You have already clocked out today.', 'code' => 409];
        }

        try {
            $now = date('Y-m-d H:i:s');

            // Calculate total break minutes
            $totalBreakMins = (int) AttendanceBreak::where('session_id', $session->id)
                ->whereNotNull('break_end')
                ->sum('duration_mins');

            // Calculate total work minutes = (clock_out - clock_in) - total_break_mins
            $clockInTs  = strtotime($session->clock_in);
            $clockOutTs = strtotime($now);
            $grossMins  = (int) round(($clockOutTs - $clockInTs) / 60);
            $netWorkMins = max(0, $grossMins - $totalBreakMins);

            $session->update([
                'clock_out'        => $now,
                'total_work_mins'  => $netWorkMins,
                'total_break_mins' => $totalBreakMins,
                'status'           => AttendanceSession::STATUS_COMPLETED,
            ]);

            // P2 #26 — Detect early departure (overnight-shift aware)
            $earlyNote = '';
            if ($session->scheduled_end) {
                $clockInDate    = date('Y-m-d', $clockInTs);
                $scheduledEndTs = strtotime($clockInDate . ' ' . $session->scheduled_end);
                // If shift crosses midnight (overnight), shift end is on the next day
                if ($scheduledEndTs <= strtotime($clockInDate . ' ' . $session->scheduled_start)) {
                    $scheduledEndTs += 86400;
                }
                if ($clockOutTs < $scheduledEndTs) {
                    $earlyMins = (int) round(($scheduledEndTs - $clockOutTs) / 60);
                    $earlyNote = " (Left {$earlyMins} mins early)";
                    // Log early departure
                    Capsule::table('activity_log')->insert([
                        'user_id'     => $userId,
                        'action'      => 'early_departure',
                        'entity_type' => 'attendance_sessions',
                        'entity_id'   => $session->id,
                        'details'     => json_encode(['early_minutes' => $earlyMins]),
                        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                        'created_at'  => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $this->bustStateCache($userId);

            return ['success' => true, 'message' => "Clocked out. Net work: {$netWorkMins} minutes.{$earlyNote}"];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::clockOut] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while clocking out.'];
        }
    }

    // =========================================================================
    // BREAK MANAGEMENT (Section 1.5, 1.6)
    // =========================================================================

    /**
     * Start a break for the current user.
     * Validates state (must be clocked_in), enforces break type limits.
     *
     * @param  string $type  One of: lunch, short, washroom
     * @return array ['success' => bool, 'message' => string]
     */
    public function startBreak(int $userId, string $type): array
    {
        // Validate break type
        $validTypes = [AttendanceBreak::TYPE_LUNCH, AttendanceBreak::TYPE_SHORT, AttendanceBreak::TYPE_WASHROOM];
        if (!in_array($type, $validTypes)) {
            return ['success' => false, 'message' => "Invalid break type: {$type}", 'code' => 422];
        }

        // Validate state: must be clocked_in (NOT already on break)
        $stateInfo = $this->getCurrentState($userId);
        if ($stateInfo['state'] !== self::STATE_CLOCKED_IN) {
            return [
                'success' => false,
                'message' => 'Cannot start break in current state: ' . $stateInfo['state'],
                'code'    => 409,
                'state'   => $stateInfo['state'],
            ];
        }

        $session = $stateInfo['session'];

        // Enforce break limits (Section 1.5)
        if ($type === AttendanceBreak::TYPE_LUNCH) {
            $lunchCount = AttendanceBreak::where('session_id', $session->id)
                ->where('break_type', AttendanceBreak::TYPE_LUNCH)->count();
            if ($lunchCount >= AttendanceBreak::MAX_LUNCH_BREAKS) {
                return ['success' => false, 'message' => 'You have already taken your lunch break.', 'code' => 422];
            }
        } elseif ($type === AttendanceBreak::TYPE_SHORT) {
            $shortCount = AttendanceBreak::where('session_id', $session->id)
                ->where('break_type', AttendanceBreak::TYPE_SHORT)->count();
            if ($shortCount >= AttendanceBreak::MAX_SHORT_BREAKS) {
                return ['success' => false, 'message' => 'You have used both short breaks.', 'code' => 422];
            }
        }
        // Washroom breaks are unlimited (but monitored by BreakAbuseDetector)

        try {
            AttendanceBreak::create([
                'session_id'  => $session->id,
                'break_type'  => $type,
                'break_start' => date('Y-m-d H:i:s'),
            ]);

            $this->bustStateCache($userId);

            $typeLabel = ucfirst($type);
            return ['success' => true, 'message' => "{$typeLabel} break started."];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::startBreak] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while starting the break.'];
        }
    }

    /**
     * End the current break for the user.
     * Calculates duration, runs washroom abuse detection.
     *
     * @return array ['success' => bool, 'message' => string, 'flagged' => bool]
     */
    public function endBreak(int $userId): array
    {
        $stateInfo = $this->getCurrentState($userId);
        if ($stateInfo['state'] !== self::STATE_ON_BREAK) {
            return [
                'success' => false,
                'message' => 'You are not currently on a break.',
                'code'    => 409,
                'state'   => $stateInfo['state'],
            ];
        }

        $session = $stateInfo['session'];
        $activeBreak = $stateInfo['break'];

        if (!$activeBreak) {
            return ['success' => false, 'message' => 'No active break found.', 'code' => 409];
        }

        try {
            $now = date('Y-m-d H:i:s');
            $duration = $activeBreak->calculateDuration();

            // If calculateDuration returns 0 because break_end wasn't set yet, compute manually
            $startTs = strtotime($activeBreak->break_start);
            $endTs   = strtotime($now);
            $duration = max(1, (int) round(($endTs - $startTs) / 60));

            $activeBreak->update([
                'break_end'     => $now,
                'duration_mins' => $duration,
            ]);

            // Reload the break to get updated values for abuse detection
            $activeBreak->refresh();

            // Update session total_break_mins
            $totalBreakMins = (int) AttendanceBreak::where('session_id', $session->id)
                ->whereNotNull('break_end')
                ->sum('duration_mins');
            $session->update(['total_break_mins' => $totalBreakMins]);

            // Run washroom abuse detection (Section 1.5)
            $abuseResult = $this->abuseDetector->analyze($activeBreak, $session);

            $this->bustStateCache($userId);

            $typeLabel = ucfirst($activeBreak->break_type);
            $msg = "{$typeLabel} break ended ({$duration} mins).";
            if ($abuseResult['flagged']) {
                $msg .= ' ⚠️ Break usage has been flagged for admin review.';
            }

            return ['success' => true, 'message' => $msg, 'flagged' => $abuseResult['flagged']];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::endBreak] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while ending the break.'];
        }
    }

    // =========================================================================
    // ADMIN: Override Approval (Section 1.3, 1.9)
    // =========================================================================

    /**
     * Admin approves a late-login override for an agent.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function approveOverride(int $adminId, int $agentId, string $date, string $reason): array
    {
        // Validate admin role
        $admin = User::find($adminId);
        if (!$admin || !in_array($admin->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        // Validate reason is substantive
        if (strlen(trim($reason)) < 5) {
            return ['success' => false, 'message' => 'Override reason must be at least 5 characters.'];
        }

        try {
            // Create the override record
            AttendanceOverride::create([
                'agent_id'       => $agentId,
                'shift_date'     => $date,
                'override_type'  => AttendanceOverride::TYPE_LATE_LOGIN,
                'override_by'    => $adminId,
                'reason'         => trim($reason),
                'original_value' => 'blocked',
                'new_value'      => 'allowed',
            ]);

            // Log the action
            Capsule::table('activity_log')->insert([
                'user_id'     => $adminId,
                'action'      => 'override_approved',
                'entity_type' => 'attendance_overrides',
                'entity_id'   => $agentId,
                'details'     => json_encode(['agent_id' => $agentId, 'date' => $date, 'reason' => $reason]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            $agent = User::find($agentId);
            $agentName = $agent ? $agent->name : "Agent #{$agentId}";

            return ['success' => true, 'message' => "Override approved for {$agentName} on {$date}. They can now clock in."];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::approveOverride] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while approving the override.'];
        }
    }

    /**
     * Admin denies a late-login override for an agent.
     * P0 #9 — Persist the denial in the database with a reason.
     */
    public function denyOverride(int $adminId, int $agentId, string $date, string $reason): array
    {
        $admin = User::find($adminId);
        if (!$admin || !in_array($admin->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        if (strlen(trim($reason)) < 3) {
            return ['success' => false, 'message' => 'Denial reason must be at least 3 characters.'];
        }

        try {
            AttendanceOverride::create([
                'agent_id'       => $agentId,
                'shift_date'     => $date,
                'override_type'  => AttendanceOverride::TYPE_DENIAL,
                'override_by'    => $adminId,
                'reason'         => trim($reason),
                'original_value' => 'blocked',
                'new_value'      => 'denied',
            ]);

            Capsule::table('activity_log')->insert([
                'user_id'     => $adminId,
                'action'      => 'override_denied',
                'entity_type' => 'attendance_overrides',
                'entity_id'   => $agentId,
                'details'     => json_encode(['agent_id' => $agentId, 'date' => $date, 'reason' => $reason]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            $agent = User::find($agentId);
            $agentName = $agent ? $agent->name : "Agent #{$agentId}";
            return ['success' => true, 'message' => "Override denied for {$agentName} on {$date}."];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::denyOverride] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while denying the override.'];
        }
    }

    /**
     * P1 #10 — Admin manually clocks in an agent, bypassing all rules.
     */
    public function adminClockIn(int $adminId, int $agentId, string $ip): array
    {
        $admin = User::find($adminId);
        if (!$admin || !in_array($admin->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        $existing = AttendanceSession::active()->forUser($agentId)->first();
        if ($existing) {
            return ['success' => false, 'message' => 'Agent already has an active session.'];
        }

        $agent = User::find($agentId);
        if (!$agent) {
            return ['success' => false, 'message' => 'Agent not found.'];
        }

        // Get shift for default times, fallback to 9-6
        $shift = $this->shiftService->getAgentShiftForDate($agentId, date('Y-m-d'));
        $schedStart = $shift ? $shift->shift_start : '09:00:00';
        $schedEnd   = $shift ? $shift->shift_end : '18:00:00';

        $result = $this->createSession($agentId, $schedStart, $schedEnd, 0, $ip, 'admin-manual');

        if ($result['success']) {
            Capsule::table('activity_log')->insert([
                'user_id'     => $adminId,
                'action'      => 'admin_clock_in',
                'entity_type' => 'attendance_sessions',
                'entity_id'   => $result['session']->id ?? null,
                'details'     => json_encode(['agent_id' => $agentId, 'agent_name' => $agent->name]),
                'ip_address'  => $ip,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            // B4 FIX: Bust the agent's state cache so the middleware re-reads from DB.
            // Without this, agent still sees STATE_CLOCKED_OUT from the old cached state.
            $this->bustStateCache($agentId);
        }

        return $result;
    }

    /**
     * P1 #10 — Admin manually clocks out an agent.
     */
    public function adminClockOut(int $adminId, int $agentId): array
    {
        $admin = User::find($adminId);
        if (!$admin || !in_array($admin->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        // Direct DB lookup — bypasses getCurrentState date-scope so stranded sessions are found.
        $session = AttendanceSession::active()->forUser($agentId)->latest('id')->first();
        if (!$session) {
            return ['success' => false, 'message' => 'Agent does not have an active session.', 'code' => 409];
        }

        try {
            $now = date('Y-m-d H:i:s');

            // Auto-close any open break first
            $activeBreak = $session->getActiveBreak();
            if ($activeBreak) {
                $breakDuration = max(1, (int) round((strtotime($now) - strtotime($activeBreak->break_start)) / 60));
                $activeBreak->update(['break_end' => $now, 'duration_mins' => $breakDuration]);
            }

            $totalBreakMins = (int) \App\Models\AttendanceBreak::where('session_id', $session->id)
                ->whereNotNull('break_end')->sum('duration_mins');

            $clockInTs  = strtotime($session->clock_in);
            $clockOutTs = strtotime($now);
            $grossMins  = (int) round(($clockOutTs - $clockInTs) / 60);
            $netWorkMins = max(0, $grossMins - $totalBreakMins);

            $session->update([
                'clock_out'        => $now,
                'total_work_mins'  => $netWorkMins,
                'total_break_mins' => $totalBreakMins,
                'status'           => \App\Models\AttendanceSession::STATUS_COMPLETED,
            ]);

            $this->bustStateCache($agentId);

            $agent = User::find($agentId);
            Capsule::table('activity_log')->insert([
                'user_id'     => $adminId,
                'action'      => 'admin_clock_out',
                'entity_type' => 'attendance_sessions',
                'entity_id'   => $session->id,
                'details'     => json_encode(['agent_id' => $agentId, 'agent_name' => $agent?->name, 'net_work_mins' => $netWorkMins]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => $now,
            ]);

            return ['success' => true, 'message' => "Clocked out {$agent?->name}. Net work: {$netWorkMins} minutes."];
        } catch (\Throwable $e) {
            error_log('[AttendanceService::adminClockOut] Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while clocking out.'];
        }
    }

    /**
     * G2 — Admin force-ends an active break for an agent.
     */
    public function adminForceEndBreak(int $adminId, int $agentId): array
    {
        $admin = User::find($adminId);
        if (!$admin || !in_array($admin->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        $session = AttendanceSession::active()->forUser($agentId)->latest('id')->first();
        if (!$session) {
            return ['success' => false, 'message' => 'Agent is not currently clocked in.'];
        }

        $activeBreak = $session->getActiveBreak();
        if (!$activeBreak) {
            return ['success' => false, 'message' => 'Agent is not currently on a break.'];
        }

        try {
            $now = date('Y-m-d H:i:s');
            $duration = max(1, (int) round((strtotime($now) - strtotime($activeBreak->break_start)) / 60));

            $activeBreak->update(['break_end' => $now, 'duration_mins' => $duration]);

            $totalBreakMins = (int) AttendanceBreak::where('session_id', $session->id)
                ->whereNotNull('break_end')->sum('duration_mins');
            $session->update(['total_break_mins' => $totalBreakMins]);

            Capsule::table('activity_log')->insert([
                'user_id'     => $adminId,
                'action'      => 'admin_force_end_break',
                'entity_type' => 'attendance_breaks',
                'entity_id'   => $activeBreak->id,
                'details'     => json_encode(['agent_id' => $agentId, 'break_type' => $activeBreak->break_type, 'duration_mins' => $duration]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => $now,
            ]);

            $this->bustStateCache($agentId);
            $agent = User::find($agentId);
            return ['success' => true, 'message' => "Break ended for {$agent?->name} ({$duration} mins)."];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::adminForceEndBreak] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while ending the break.'];
        }
    }

    /**
     * P1 #6 — Get remaining break counts for a session.
     */

    public function getBreaksRemaining(?AttendanceSession $session): array
    {
        if (!$session) {
            return ['lunch' => AttendanceBreak::MAX_LUNCH_BREAKS, 'short' => AttendanceBreak::MAX_SHORT_BREAKS, 'washroom' => 'unlimited'];
        }

        $lunchUsed = AttendanceBreak::where('session_id', $session->id)
            ->where('break_type', AttendanceBreak::TYPE_LUNCH)->count();
        $shortUsed = AttendanceBreak::where('session_id', $session->id)
            ->where('break_type', AttendanceBreak::TYPE_SHORT)->count();

        return [
            'lunch'    => max(0, AttendanceBreak::MAX_LUNCH_BREAKS - $lunchUsed),
            'short'    => max(0, AttendanceBreak::MAX_SHORT_BREAKS - $shortUsed),
            'washroom' => 'unlimited',
        ];
    }

    /**
     * P1 #4 — Get agent's attendance history.
     */
    public function getAgentHistory(int $userId, int $days = 30): array
    {
        $sessions = AttendanceSession::forUser($userId)
            ->where('date', '>=', date('Y-m-d', strtotime("-{$days} days")))
            ->orderBy('date', 'desc')
            ->with('breaks')
            ->get();

        $summary = [
            'total_sessions'    => $sessions->count(),
            'total_work_mins'   => $sessions->sum('total_work_mins'),
            'total_break_mins'  => $sessions->sum('total_break_mins'),
            'total_late_mins'   => $sessions->sum('late_minutes'),
            'late_count'        => $sessions->where('late_minutes', '>', 0)->count(),
            'flagged_breaks'    => 0,
        ];

        foreach ($sessions as $s) {
            $summary['flagged_breaks'] += $s->breaks->where('flagged', 1)->count();
        }

        return ['sessions' => $sessions, 'summary' => $summary];
    }

    /**
     * P2 #12 — Get historical attendance data for admin panel.
     */
    public function getHistoricalData(string $date, ?int $agentId = null): array
    {
        $query = AttendanceSession::forDate($date)
            ->with(['breaks', 'user'])
            ->orderBy('clock_in', 'asc');

        if ($agentId) {
            $query->forUser($agentId);
        }

        return $query->get()->toArray();
    }

    // =========================================================================
    // ADMIN: Get Live Board Data (Section 1.9)
    // =========================================================================

    /**
     * Get live attendance status for all agents (admin panel view).
     *
     * @return array ['in' => [], 'on_break' => [], 'absent' => [], 'pending_override' => []]
     */
    public function getLiveBoardData(): array
    {
        $today = date('Y-m-d');

        // All active agents/managers
        $allAgents = User::whereIn('role', [User::ROLE_AGENT, User::ROLE_MANAGER])
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        // B3 FIX: Get ALL sessions for today, then pick the most relevant per user.
        // keyBy would silently drop the active session if a completed one had a lower index.
        // Instead: group by user_id, then prioritise: active > completed > auto_closed.
        // Fetch sessions for today OR any session that is currently still active (e.g. forgot to checkout yesterday)
        $todaySessionsRaw = AttendanceSession::where(function($q) use ($today) {
                $q->where('date', $today)
                  ->orWhere('status', AttendanceSession::STATUS_ACTIVE);
            })
            ->with(['breaks', 'user'])
            ->orderBy('id', 'asc') // oldest first so active (created later) overwrites completed
            ->get();

        // Build a map: user_id => best session (active beats completed)
        $sessionMap = [];
        foreach ($todaySessionsRaw as $s) {
            $uid = $s->user_id;
            if (!isset($sessionMap[$uid])) {
                $sessionMap[$uid] = $s;
            } elseif ($s->status === AttendanceSession::STATUS_ACTIVE) {
                // An active session always replaces a completed one in the map
                $sessionMap[$uid] = $s;
            }
        }

        // B2 FIX: Added 'completed' bucket so finished agents don't vanish from the board.
        $board = [
            'in'               => [],
            'on_break'         => [],
            'completed'        => [], // Agents who finished their shift today
            'absent'           => [],
            'pending_override' => [],
        ];

        foreach ($allAgents as $agent) {
            $session = $sessionMap[$agent->id] ?? null;

            if (!$session) {
                // No session today — check if they're waiting for an override
                $hasOverride = AttendanceOverride::where('agent_id', $agent->id)
                    ->where('shift_date', $today)->exists();
                $failedAttempt = Capsule::table('activity_log')
                    ->where('user_id', $agent->id)
                    ->where('action', 'clock_in_blocked')
                    ->where('created_at', '>=', $today . ' 00:00:00')
                    ->exists();

                if ($failedAttempt && !$hasOverride) {
                    $board['pending_override'][] = $agent;
                } else {
                    $board['absent'][] = $agent;
                }
                continue;
            }

            if ($session->status === AttendanceSession::STATUS_ACTIVE) {
                $activeBreak = $session->getActiveBreak();
                if ($activeBreak) {
                    $board['on_break'][] = [
                        'agent'   => $agent,
                        'session' => $session,
                        'break'   => $activeBreak,
                    ];
                } else {
                    $board['in'][] = [
                        'agent'   => $agent,
                        'session' => $session,
                    ];
                }
            } else {
                // Completed or auto_closed — show in 'Completed Today' section
                $board['completed'][] = [
                    'agent'   => $agent,
                    'session' => $session,
                ];
            }
        }

        return $board;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Create a new attendance session record in the database.
     */
    private function createSession(int $userId, string $schedStart, string $schedEnd, int $lateMins, string $ip, string $ua): array
    {
        try {
            // P1 #24 — Wrap in explicit transaction so SELECT FOR UPDATE actually acquires a row lock
            $session = Capsule::connection()->transaction(function () use ($userId, $schedStart, $schedEnd, $lateMins, $ip, $ua) {
                // Check for existing active session under lock — prevents race on rapid double-click
                $locked = Capsule::connection()->select(
                    'SELECT id FROM attendance_sessions WHERE user_id = ? AND status = ? FOR UPDATE',
                    [$userId, AttendanceSession::STATUS_ACTIVE]
                );
                if (!empty($locked)) {
                    throw new \RuntimeException('already_active');
                }

                $sess = AttendanceSession::create([
                    'user_id'          => $userId,
                    'clock_in'         => date('Y-m-d H:i:s'),
                    'scheduled_start'  => $schedStart,
                    'scheduled_end'    => $schedEnd,
                    'late_minutes'     => $lateMins,
                    'status'           => AttendanceSession::STATUS_ACTIVE,
                    'ip_address'       => $ip,
                    'user_agent'       => $ua,
                    'date'             => date('Y-m-d'),
                ]);

                // Log inside transaction — rolls back with the session if DB fails
                Capsule::table('activity_log')->insert([
                    'user_id'     => $userId,
                    'action'      => 'clock_in',
                    'entity_type' => 'attendance_sessions',
                    'entity_id'   => $sess->id,
                    'details'     => json_encode(['late_minutes' => $lateMins, 'scheduled_start' => $schedStart]),
                    'ip_address'  => $ip,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);

                return $sess;
            });

            $this->bustStateCache($userId);

            $msg = $lateMins > 0
                ? "Clocked in ({$lateMins} minutes late)."
                : "Clocked in successfully.";

            return ['success' => true, 'message' => $msg, 'session' => $session];

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'already_active') {
                return ['success' => false, 'message' => 'You already have an active clock-in session.', 'blocked_reason' => 'already_active'];
            }
            error_log("[AttendanceService::createSession] Runtime error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during clock-in.'];
        } catch (\Throwable $e) {
            error_log("[AttendanceService::createSession] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during clock-in.'];
        }
    }

    /**
     * Log a failed clock-in attempt (for admin override queue).
     */
    private function logFailedAttempt(int $userId, string $date, int $lateMins, string $ip): void
    {
        try {
            Capsule::table('activity_log')->insert([
                'user_id'     => $userId,
                'action'      => 'clock_in_blocked',
                'entity_type' => 'attendance_sessions',
                'entity_id'   => null,
                'details'     => json_encode(['date' => $date, 'late_minutes' => $lateMins, 'reason' => 'exceeded_grace_period']),
                'ip_address'  => $ip,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log("[AttendanceService::logFailedAttempt] Error: " . $e->getMessage());
        }
    }

    /**
     * Cache the current state in PHP session.
     */
    private function cacheState(int $userId, array $state): void
    {
        $_SESSION["att_state_{$userId}"] = $state;
        $_SESSION["att_state_ts_{$userId}"] = time();
    }

    /**
     * Bust the state cache to force a fresh DB query on next call.
     */
    private function bustStateCache(int $userId): void
    {
        unset($_SESSION["att_state_{$userId}"], $_SESSION["att_state_ts_{$userId}"]);
    }
}
