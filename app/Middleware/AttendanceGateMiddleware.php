<?php

namespace App\Middleware;

use App\Models\User;
use App\Services\AttendanceService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

/**
 * AttendanceGateMiddleware
 * 
 * Sits on ALL CRM routes (except login, logout, clock-in, and attendance APIs).
 * Forces agents to clock in before they can access any CRM feature.
 * 
 * Admins and Managers bypass this gate entirely.
 * Uses 60-second session cache to reduce DB hits.
 *
 * Architecture Reference: Section 1.1
 */
class AttendanceGateMiddleware
{
    /**
     * Process the request through the gate.
     */
    public function __invoke(Request $request, Handler $handler): Response
    {
        // Must be logged in (AuthMiddleware should have run first)
        if (empty($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $userId = (int) $_SESSION['user_id'];
        $role   = $_SESSION['role'] ?? '';

        // Admins and Managers bypass the attendance gate
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $handler->handle($request);
        }

        // Check if the agent has an active attendance session
        $attendanceService = new AttendanceService();
        $stateInfo = $attendanceService->getCurrentState($userId);

        if ($stateInfo['state'] === AttendanceService::STATE_NOT_CLOCKED_IN) {
            // Agent hasn't clocked in — redirect to the clock-in lobby
            $response = new SlimResponse();
            return $response->withHeader('Location', '/clock-in')->withStatus(302);
        }

        if ($stateInfo['state'] === AttendanceService::STATE_CLOCKED_OUT) {
            // Agent clocked out for the day — redirect to clock-in page with a message
            $_SESSION['flash_info'] = 'You have clocked out for today. Contact admin if you need to resume.';
            $response = new SlimResponse();
            return $response->withHeader('Location', '/clock-in')->withStatus(302);
        }

        // State is 'clocked_in' or 'on_break' — allow access
        // Inject attendance data into request attributes for views to use
        $request = $request->withAttribute('attendance_state', $stateInfo['state']);
        $request = $request->withAttribute('attendance_session', $stateInfo['session']);
        if ($stateInfo['break']) {
            $request = $request->withAttribute('attendance_break', $stateInfo['break']);
        }

        return $handler->handle($request);
    }
}
