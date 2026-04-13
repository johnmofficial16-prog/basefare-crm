<?php

namespace App\Services;

use App\Models\ShiftSchedule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

class ShiftService
{
    /**
     * Validate a single schedule entry before any DB write.
     *
     * @param  array  $data  Must contain: agent_id, shift_date, shift_start, shift_end
     * @return array  ['valid' => bool, 'errors' => [field => message]]
     */
    public function validateEntry(array $data): array
    {
        $errors = [];

        // Agent must exist and be active
        if (empty($data['agent_id'])) {
            $errors['agent_id'] = 'Agent is required.';
        } else {
            $agent = User::where('id', $data['agent_id'])
                         ->where('status', User::STATUS_ACTIVE)
                         ->whereNull('deleted_at')
                         ->first();
            if (!$agent) {
                $errors['agent_id'] = "Agent ID {$data['agent_id']} does not exist or is not active.";
            } elseif ($agent->role === User::ROLE_ADMIN) {
                $errors['agent_id'] = 'Admins cannot be assigned to agent shifts.';
            }
        }

        // Date must be a valid date
        if (empty($data['shift_date'])) {
            $errors['shift_date'] = 'Shift date is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['shift_date'])) {
            $errors['shift_date'] = 'Shift date must be in YYYY-MM-DD format.';
        }

        // Shift start and end must be valid times
        if (empty($data['shift_start'])) {
            $errors['shift_start'] = 'Shift start time is required.';
        }
        if (empty($data['shift_end'])) {
            $errors['shift_end'] = 'Shift end time is required.';
        }

        // Shift start must be strictly before shift end.
        // For overnight shifts (e.g. 18:00 → 03:00), end time wraps past midnight.
        // We detect this by checking if end < start, and if so, add 24hrs to end.
        if (empty($errors['shift_start']) && empty($errors['shift_end'])) {
            $start = strtotime($data['shift_start']);
            $end   = strtotime($data['shift_end']);
            if ($end <= $start) {
                // Could be an overnight shift — add 24 hours to end and re-check
                $end += 86400;
            }
            // After adjustment, end must still be after start (catches identical times)
            if ($end <= $start) {
                $errors['shift_end'] = 'Shift end time must be after shift start time.';
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Publish (or update) a full week's schedule for multiple agents.
     * Wraps all writes in a single DB transaction. Rolls back entirely on any error.
     *
     * @param  array  $entries  Array of schedule entries, each with: agent_id, shift_date, shift_start, shift_end, template_id (optional)
     * @param  int    $adminId  The ID of the admin publishing the week
     * @return array  ['success' => bool, 'message' => string, 'errors' => array]
     */
    public function publishWeek(array $entries, int $adminId): array
    {
        // Pre-validate ALL entries before touching the database
        $allErrors = [];
        foreach ($entries as $index => $entry) {
            $result = $this->validateEntry($entry);
            if (!$result['valid']) {
                $allErrors["entry_{$index}"] = $result['errors'];
            }
        }

        if (!empty($allErrors)) {
            return ['success' => false, 'message' => 'Validation failed. No changes were saved.', 'errors' => $allErrors];
        }

        // All entries valid — wrap in a single DB transaction
        Capsule::connection()->beginTransaction();
        try {
            foreach ($entries as $entry) {
                $monday = ShiftSchedule::getMondayOfWeek($entry['shift_date']);

                // UPSERT — if a shift for this agent on this date already exists, update it
                ShiftSchedule::updateOrCreate(
                    [
                        'agent_id'   => (int)$entry['agent_id'],
                        'shift_date' => $entry['shift_date'],
                    ],
                    [
                        'shift_start'   => $entry['shift_start'],
                        'shift_end'     => $entry['shift_end'],
                        'template_id'   => $entry['template_id'] ?? null,
                        'schedule_week' => $monday,
                        'created_by'    => $adminId,
                    ]
                );
            }

            Capsule::connection()->commit();
            return ['success' => true, 'message' => count($entries) . ' shift(s) published successfully.', 'errors' => []];

        } catch (\Throwable $e) {
            Capsule::connection()->rollBack();
            // Log the real error for developers, return safe message for users
            error_log('[ShiftService::publishWeek] DB Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'A database error occurred. All changes have been rolled back.',
                'errors'  => ['db' => $_ENV['APP_ENV'] === 'local' ? $e->getMessage() : 'Internal server error.'],
            ];
        }
    }

    /**
     * Fetch the complete schedule for an ISO week.
     * Returns a 2D array keyed by [agent_id][shift_date] for easy grid rendering.
     *
     * @param  string  $weekStart  Monday of the target week (YYYY-MM-DD)
     * @return array
     */
    public function getWeekSchedule(string $weekStart): array
    {
        $weekEnd = (new \DateTime($weekStart))->modify('+6 days')->format('Y-m-d');

        $rows = ShiftSchedule::with(['agent', 'template'])
            ->whereBetween('shift_date', [$weekStart, $weekEnd])
            ->orderBy('shift_date')
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            // shift_date is cast to a date object, so we must format it as string for the array key
            $dateStr = is_string($row->shift_date) ? $row->shift_date : $row->shift_date->format('Y-m-d');
            $grid[$row->agent_id][$dateStr] = $row;
        }

        return $grid;
    }

    /**
     * Get the shift for a specific agent on a specific date.
     * Used by the Attendance Gate on clock-in.
     * Implements a 60-second PHP session cache to reduce DB load (25 agents × polling).
     *
     * @param  int     $agentId
     * @param  string  $date  YYYY-MM-DD
     * @return ShiftSchedule|null
     */
    public function getAgentShiftForDate(int $agentId, string $date): ?ShiftSchedule
    {
        $cacheKey = "shift_cache_{$agentId}_{$date}";

        // Check session cache — only re-query if stale (>60 seconds)
        if (
            isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts']) &&
            (time() - $_SESSION[$cacheKey . '_ts']) < 60
        ) {
            // Session has a cached "no shift" marker
            if ($_SESSION[$cacheKey] === 'no_shift') {
                return null;
            }

            // Session has a cached shift ID — re-fetch from DB for freshness
            $id = $_SESSION[$cacheKey];
            return ShiftSchedule::find($id);
        }

        // Fetch from DB
        $shift = ShiftSchedule::where('agent_id', $agentId)
                              ->where('shift_date', $date)
                              ->first();

        // Cache the result
        $_SESSION[$cacheKey . '_ts'] = time();
        $_SESSION[$cacheKey] = $shift ? $shift->id : 'no_shift';

        return $shift;
    }

    /**
     * Update a single schedule cell (mid-week edit).
     *
     * @param  int    $agentId
     * @param  string $date
     * @param  array  $data  shift_start, shift_end, template_id (optional)
     * @param  int    $adminId
     * @return array  ['success' => bool, 'message' => string]
     */
    public function updateCell(int $agentId, string $date, array $data, int $adminId): array
    {
        // Validate the entry
        $result = $this->validateEntry(array_merge(['agent_id' => $agentId, 'shift_date' => $date], $data));
        if (!$result['valid']) {
            return ['success' => false, 'message' => 'Validation failed.', 'errors' => $result['errors']];
        }

        try {
            $monday = ShiftSchedule::getMondayOfWeek($date);
            ShiftSchedule::updateOrCreate(
                ['agent_id' => $agentId, 'shift_date' => $date],
                [
                    'shift_start'   => $data['shift_start'],
                    'shift_end'     => $data['shift_end'],
                    'template_id'   => $data['template_id'] ?? null,
                    'schedule_week' => $monday,
                    'created_by'    => $adminId,
                ]
            );

            // Bust the attendance gate cache for this agent/date
            unset($_SESSION["shift_cache_{$agentId}_{$date}"], $_SESSION["shift_cache_{$agentId}_{$date}_ts"]);

            return ['success' => true, 'message' => 'Shift updated successfully.'];
        } catch (\Throwable $e) {
            error_log('[ShiftService::updateCell] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'A database error occurred.',
                'errors'  => ['db' => $_ENV['APP_ENV'] === 'local' ? $e->getMessage() : 'Internal server error.'],
            ];
        }
    }

    /**
     * Delete a shift assignment for a specific agent on a specific date.
     *
     * @param  int    $agentId
     * @param  string $date
     * @return array
     */
    public function deleteCell(int $agentId, string $date): array
    {
        try {
            $deleted = ShiftSchedule::where('agent_id', $agentId)->where('shift_date', $date)->delete();
            if (!$deleted) {
                return ['success' => false, 'message' => 'No shift found for this agent on this date.'];
            }

            // Bust cache
            unset($_SESSION["shift_cache_{$agentId}_{$date}"], $_SESSION["shift_cache_{$agentId}_{$date}_ts"]);

            return ['success' => true, 'message' => 'Shift removed successfully.'];
        } catch (\Throwable $e) {
            error_log('[ShiftService::deleteCell] Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'A database error occurred.'];
        }
    }

    /**
     * Get all active agents for the weekly grid.
     * Returns only users with the 'agent' or 'manager' role who are active.
     */
    public function getActiveAgents(): \Illuminate\Support\Collection
    {
        return User::whereIn('role', [User::ROLE_AGENT, User::ROLE_MANAGER])
                   ->where('status', User::STATUS_ACTIVE)
                   ->whereNull('deleted_at')
                   ->orderBy('name')
                   ->get(['id', 'name', 'role']);
    }
}
