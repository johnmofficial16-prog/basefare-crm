<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\AttendanceService;
use App\Services\ShiftService;
use App\Models\AttendanceSession;
use App\Models\AttendanceBreak;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

class DashboardController
{
    private AttendanceService $attendanceService;
    private ShiftService $shiftService;

    public function __construct()
    {
        $this->shiftService = new ShiftService();
        $this->attendanceService = new AttendanceService();
    }

    /**
     * GET /dashboard — Main dashboard for agents and admins
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $role   = $_SESSION['role'] ?? 'agent';

        // Get current attendance state (for widget initialization)
        $stateInfo = $this->attendanceService->getCurrentState($userId);

        // Get today's session stats
        $todaySession = AttendanceSession::forUser($userId)
            ->forDate(date('Y-m-d'))
            ->whereIn('status', [AttendanceSession::STATUS_ACTIVE, AttendanceSession::STATUS_COMPLETED])
            ->latest('id')
            ->first();

        $todayStats = [
            'work_mins'  => $todaySession?->total_work_mins ?? 0,
            'break_mins' => $todaySession?->total_break_mins ?? 0,
            'clock_in'   => $todaySession?->clock_in,
            'clock_out'  => $todaySession?->clock_out,
            'late_mins'  => $todaySession?->late_minutes ?? 0,
            'status'     => $todaySession?->status ?? 'none',
        ];

        // Get this week's sessions for the weekly summary
        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek   = date('Y-m-d', strtotime('sunday this week'));
        $weekSessions = AttendanceSession::forUser($userId)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->orderBy('date', 'asc')
            ->get()
            ->groupBy(function($s) { return date('Y-m-d', strtotime($s->date)); });

        $weekData = [];
        for ($d = 0; $d < 7; $d++) {
            $dateStr = date('Y-m-d', strtotime($startOfWeek . " + $d days"));
            $dayName = date('D', strtotime($dateStr));
            $sessions = $weekSessions->get($dateStr, collect());
            $hasData = $sessions->count() > 0;
            $workMins = $sessions->sum('total_work_mins');
            $breakMins = $sessions->sum('total_break_mins');
            $lateMins = $sessions->sum('late_minutes');

            $weekData[] = [
                'date'       => $dateStr,
                'day'        => $dayName,
                'has_data'   => $hasData,
                'work_mins'  => $workMins,
                'break_mins' => $breakMins,
                'late_mins'  => $lateMins,
                'is_today'   => $dateStr === date('Y-m-d'),
            ];
        }

        // Today's shift info
        $todayShift = $this->shiftService->getAgentShiftForDate($userId, date('Y-m-d'));

        // For admin: get live board summary counts
        $adminCounts = null;
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $boardData = $this->attendanceService->getLiveBoardData();
            $adminCounts = [
                'in'        => count($boardData['in']),
                'on_break'  => count($boardData['on_break']),
                'completed' => count($boardData['completed']),
                'absent'    => count($boardData['absent']),
                'pending'   => count($boardData['pending_override']),
            ];
        }

        ob_start();
        require __DIR__ . '/../Views/dashboard.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }
}
