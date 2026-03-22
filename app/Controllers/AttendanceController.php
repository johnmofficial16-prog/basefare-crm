<?php

namespace App\Controllers;

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

        $data = [
            'state'          => $stateInfo['state'],
            'clock_in'       => $stateInfo['session']?->clock_in,
            'break_type'     => $stateInfo['break']?->break_type,
            'break_start'    => $stateInfo['break']?->break_start,
            'total_break_mins' => $stateInfo['session']?->total_break_mins ?? 0,
            'server_time'    => date('Y-m-d H:i:s'),
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
        $boardData = $this->service->getLiveBoardData();

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

    // =========================================================================
    // HELPER
    // =========================================================================

    private function jsonResponse(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
