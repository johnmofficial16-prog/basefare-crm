<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\AttendanceService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * AttendanceController
 * 
 * Handles all attendance HTTP endpoints.
 * Contains NO business logic — delegates everything to AttendanceService.
 *
 * Architecture References: Sections 1.3, 1.5, 1.6, 1.8, 1.9
 */
class AttendanceController
{
    private AttendanceService $service;

    public function __construct()
    {
        $this->service = new AttendanceService();
    }

    // =========================================================================
    // CLOCK IN / OUT
    // =========================================================================

    /**
     * GET /clock-in — Show the clock-in lobby page
     */
    public function lobbyPage(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $stateInfo = $this->service->getCurrentState($userId);

        ob_start();
        require __DIR__ . '/../Views/attendance/clock_in.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * POST /clock-in — Process the clock-in attempt
     */
    public function processClockIn(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $result = $this->service->attemptClockIn($userId, $ip, $ua);

        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        // Failed — show the blocked reason on the lobby page
        if (($result['blocked_reason'] ?? '') === 'too_late') {
            return $response->withHeader('Location', '/attendance/waiting')->withStatus(302);
        }

        $_SESSION['flash_error'] = $result['message'];
        return $response->withHeader('Location', '/clock-in')->withStatus(302);
    }

    /**
     * POST /clock-out — Clock out the current user
     */
    public function clockOut(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $result = $this->service->clockOut($userId);

        return $this->jsonResponse($response, $result, $result['success'] ? 200 : ($result['code'] ?? 422));
    }

    // =========================================================================
    // BREAK MANAGEMENT
    // =========================================================================

    /**
     * POST /break/start — Start a break
     * Expects JSON body: { type: "lunch" | "short" | "washroom" }
     */
    public function startBreak(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $body   = json_decode((string) $request->getBody(), true);
        $type   = $body['type'] ?? '';

        $result = $this->service->startBreak($userId, $type);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : ($result['code'] ?? 422));
    }

    /**
     * POST /break/end — End the current break
     */
    public function endBreak(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $result = $this->service->endBreak($userId);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : ($result['code'] ?? 422));
    }

    // =========================================================================
    // STATUS POLLING (Section 1.6)
    // =========================================================================

    /**
     * GET /attendance/status — Lightweight JSON state for 30-second polling
     */
    public function statusPoll(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $stateInfo = $this->service->getCurrentState($userId);

        $breaksRemaining = $this->service->getBreaksRemaining($stateInfo['session']);

        $data = [
            'state'            => $stateInfo['state'],
            'clock_in'         => $stateInfo['session']?->clock_in,
            'break_type'       => $stateInfo['break']?->break_type,
            'break_start'      => $stateInfo['break']?->break_start,
            'total_break_mins' => $stateInfo['session']?->total_break_mins ?? 0,
            'server_time'      => date('Y-m-d H:i:s'),
            'breaks_remaining' => $breaksRemaining,
        ];

        return $this->jsonResponse($response, $data, 200);
    }

    // =========================================================================
    // WAITING FOR OVERRIDE (Section 1.3)
    // =========================================================================

    /**
     * GET /attendance/waiting — Show "waiting for admin override" screen
     */
    public function overrideWait(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $stateInfo = $this->service->getCurrentState($userId);

        ob_start();
        require __DIR__ . '/../Views/attendance/waiting.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // ADMIN: Override Queue + Live Board (Section 1.9)
    // =========================================================================

    /**
     * GET /attendance/admin — Admin attendance panel (live board + override queue)
     */
    public function adminPanel(Request $request, Response $response): Response
    {
        $actorId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? 'agent';

        // Supervisors only see their team on the live board
        $agentIds = null;
        if ($actorRole === User::ROLE_SUPERVISOR) {
            $supervisor = User::find($actorId);
            $teamIds    = $supervisor ? $supervisor->getTeamAgentIds() : [];
            $agentIds   = count($teamIds) ? $teamIds : [-1];
        }

        $boardData = $this->service->getLiveBoardData($agentIds);

        ob_start();
        require __DIR__ . '/../Views/attendance/admin_panel.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * POST /attendance/override — Approve an override
     * Expects JSON body: { agent_id, date, reason }
     */
    public function approveOverride(Request $request, Response $response): Response
    {
        $adminId = $_SESSION['user_id'];
        $body    = json_decode((string) $request->getBody(), true);

        $agentId = (int)($body['agent_id'] ?? 0);
        $date    = $body['date'] ?? date('Y-m-d');
        $reason  = trim($body['reason'] ?? '');

        $result = $this->service->approveOverride($adminId, $agentId, $date, $reason);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /attendance/deny — Deny an override (P0 #9)
     * Expects JSON body: { agent_id, date, reason }
     */
    public function denyOverride(Request $request, Response $response): Response
    {
        $adminId = $_SESSION['user_id'];
        $body    = json_decode((string) $request->getBody(), true);

        $agentId = (int)($body['agent_id'] ?? 0);
        $date    = $body['date'] ?? date('Y-m-d');
        $reason  = trim($body['reason'] ?? '');

        $result = $this->service->denyOverride($adminId, $agentId, $date, $reason);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /attendance/admin/clock-in — Admin manually clocks in an agent (P1 #10)
     * Expects JSON body: { agent_id }
     */
    public function adminClockIn(Request $request, Response $response): Response
    {
        $adminId = $_SESSION['user_id'];
        $body    = json_decode((string) $request->getBody(), true);
        $agentId = (int)($body['agent_id'] ?? 0);
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $result = $this->service->adminClockIn($adminId, $agentId, $ip);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /attendance/admin/clock-out — Admin manually clocks out an agent (P1 #10)
     * Expects JSON body: { agent_id }
     */
    public function adminClockOut(Request $request, Response $response): Response
    {
        $adminId = $_SESSION['user_id'];
        $body    = json_decode((string) $request->getBody(), true);
        $agentId = (int)($body['agent_id'] ?? 0);

        $result = $this->service->adminClockOut($adminId, $agentId);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /attendance/admin/force-end-break — Admin force-ends an agent's open break (G2)
     * Expects JSON body: { agent_id }
     */
    public function adminForceEndBreak(Request $request, Response $response): Response
    {
        $adminId = $_SESSION['user_id'];
        $body    = json_decode((string) $request->getBody(), true);
        $agentId = (int)($body['agent_id'] ?? 0);

        $result = $this->service->adminForceEndBreak($adminId, $agentId);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /attendance/admin/data — JSON board data for AJAX refresh (P1 #11)
     */
    public function adminBoardData(Request $request, Response $response): Response
    {
        $actorId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? 'agent';

        // Scope to supervisor's team
        $agentIds = null;
        if ($actorRole === User::ROLE_SUPERVISOR) {
            $supervisor = User::find($actorId);
            $teamIds    = $supervisor ? $supervisor->getTeamAgentIds() : [];
            $agentIds   = count($teamIds) ? $teamIds : [-1];
        }

        $boardData = $this->service->getLiveBoardData($agentIds);
        $today = date('Y-m-d');

        $abuseAlerts = \Illuminate\Database\Capsule\Manager::table('activity_log')
            ->where('action', 'break_abuse_detected')
            ->where('created_at', '>=', $today . ' 00:00:00')
            ->orderBy('created_at', 'desc')
            ->get();

        // Serialize for JSON
        $data = [
            'in_count'        => count($boardData['in']),
            'break_count'     => count($boardData['on_break']),
            'completed_count' => count($boardData['completed']),
            'absent_count'    => count($boardData['absent']),
            'pending_count'   => count($boardData['pending_override']),
            'abuse_count'     => $abuseAlerts->count(),
            'pending_agents' => array_map(fn($a) => ['id' => $a->id, 'name' => $a->name], $boardData['pending_override']),
            'in_agents'      => array_map(fn($i) => [
                'name' => $i['agent']->name,
                'clock_in' => $i['session']->clock_in,
                'late' => $i['session']->late_minutes ?? 0,
            ], $boardData['in']),
            'break_agents'   => array_map(fn($i) => [
                'id'    => $i['agent']->id,
                'name'  => $i['agent']->name,
                'type'  => $i['break']->break_type,
                'start' => $i['break']->break_start,
            ], $boardData['on_break']),
        ];

        return $this->jsonResponse($response, $data);
    }

    /**
     * GET /attendance/my — Agent's own attendance history (P1 #4)
     */
    public function myAttendance(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $history = $this->service->getAgentHistory($userId, 30);

        ob_start();
        require __DIR__ . '/../Views/attendance/my_attendance.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * GET /attendance/admin/history — Admin historical attendance (P2 #12)
     */
    public function adminHistory(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $date    = $params['date'] ?? date('Y-m-d');
        $agentId = isset($params['agent_id']) ? (int)$params['agent_id'] : null;

        $sessions = $this->service->getHistoricalData($date, $agentId);
        $agents   = \App\Models\User::whereIn('role', ['agent', 'manager'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        ob_start();
        require __DIR__ . '/../Views/attendance/admin_history.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }


    // =========================================================================
    // CSV EXPORT  —  GET /attendance/admin/export  (admin/manager/supervisor)
    // =========================================================================

    public function exportCsv(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $date    = $params['date'] ?? date('Y-m-d');
        $agentId = isset($params['agent_id']) ? (int)$params['agent_id'] : null;

        $sessions = $this->service->getHistoricalData($date, $agentId);

        $headers = [
            'Date', 'Agent', 'Clock In', 'Clock Out',
            'Work (mins)', 'Break (mins)', 'Late (mins)', 'Status',
        ];

        $rows = [];
        foreach ($sessions as $s) {
            $rows[] = [
                $date,
                $s->agent->name ?? '—',
                $s->clock_in ?? '',
                $s->clock_out ?? '',
                $s->work_minutes ?? 0,
                $s->total_break_mins ?? 0,
                $s->late_minutes ?? 0,
                $s->status ?? '',
            ];
        }

        $filename = 'attendance_' . $date . '.csv';
        return $this->csvResponse($response, $headers, $rows, $filename);
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private function jsonResponse(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function csvResponse(Response $response, array $headers, iterable $rows, string $filename): Response
    {
        $tmp = fopen('php://temp', 'r+');
        fputcsv($tmp, $headers);
        foreach ($rows as $row) {
            fputcsv($tmp, array_values((array)$row));
        }
        rewind($tmp);
        $csv = stream_get_contents($tmp);
        fclose($tmp);

        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
