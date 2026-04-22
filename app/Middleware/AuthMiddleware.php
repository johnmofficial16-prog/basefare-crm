<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use App\Models\User;

/**
 * AuthMiddleware
 *
 * Security layers:
 * 1. Session authentication (all users)
 * 2. Role-based access control (optional, per-route)
 * 3. Inactivity timeout — 1 hour for agent/supervisor; disabled for admin/manager
 * 4. Concurrent login detection — agent/supervisor: only one session at a time
 */
class AuthMiddleware
{
    private $requiredRoles;

    // Inactivity timeout in seconds (1 hour)
    const INACTIVITY_TIMEOUT = 3600;

    // Roles exempt from inactivity timeout and concurrent-login enforcement
    const EXEMPT_ROLES = ['admin', 'manager'];

    /**
     * Optional: Pass roles (e.g. ['admin', 'manager']) to restrict the route
     */
    public function __construct(array $requiredRoles = [])
    {
        $this->requiredRoles = $requiredRoles;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // ── 1. Basic authentication ──────────────────────────────────────
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $userId   = $_SESSION['user_id'];
        $userRole = $_SESSION['role'] ?? 'guest';

        // ── 2. Role-based access control ─────────────────────────────────
        if (!empty($this->requiredRoles)) {
            if (!in_array($userRole, $this->requiredRoles)) {
                $response = new SlimResponse();
                $response->getBody()->write('403 Forbidden - You do not have permission to access this page.');
                return $response->withStatus(403);
            }
        }

        // ── 3. Inactivity timeout (agent/supervisor only) ────────────────
        if (!in_array($userRole, self::EXEMPT_ROLES)) {
            $lastActivity = $_SESSION['last_activity'] ?? 0;
            $elapsed      = time() - $lastActivity;

            if ($lastActivity > 0 && $elapsed > self::INACTIVITY_TIMEOUT) {

                // ── Auto clock-out before destroying session ─────────────
                // The session data is still available here — use it before destroy.
                $this->autoClockOutOnTimeout($userId, $userRole, $elapsed);

                // Destroy session
                session_destroy();

                // Start a new session to flash a message
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['login_error'] = 'Your session expired due to inactivity (1 hour). You have been automatically clocked out. This has been flagged for admin review.';

                $response = new SlimResponse();
                return $response->withHeader('Location', '/login')->withStatus(302);
            }
        }

        // Update last activity timestamp
        $_SESSION['last_activity'] = time();

        // ── 4. Concurrent login detection (agent/supervisor only) ────────
        if (!in_array($userRole, self::EXEMPT_ROLES)) {
            $currentSessionId = session_id();
            $storedSessionId  = $_SESSION['active_session_id'] ?? null;

            // If we haven't cached the active_session_id yet, fetch from DB
            if ($storedSessionId === null) {
                $user = User::find($userId);
                if ($user) {
                    $storedSessionId = $user->active_session_id;
                    $_SESSION['active_session_id'] = $storedSessionId;
                }
            }

            // If another session has taken over, force-logout this one
            if ($storedSessionId && $storedSessionId !== $currentSessionId) {
                // Check DB to confirm (session cache could be stale)
                $user = User::find($userId);
                if ($user && $user->active_session_id && $user->active_session_id !== $currentSessionId) {
                    // This session was superseded by a newer login — auto clock-out this one too
                    $this->autoClockOutOnTimeout($userId, $userRole, 0, 'concurrent_login_displaced');

                    session_destroy();

                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $_SESSION['login_error'] = 'You have been logged out because your account was accessed from another location.';

                    $response = new SlimResponse();
                    return $response->withHeader('Location', '/login')->withStatus(302);
                }
            }
        }

        // ── Passed all security checks ───────────────────────────────────
        return $handler->handle($request);
    }

    /**
     * Auto clock-out an agent when their session expires (inactivity or concurrent login).
     * Closes the active attendance session and logs the event for admin review.
     */
    private function autoClockOutOnTimeout(int $userId, string $role, int $elapsedSeconds, string $reason = 'inactivity_timeout'): void
    {
        try {
            $attendanceService = new \App\Services\AttendanceService();
            $stateInfo = $attendanceService->getCurrentState($userId);

            // Only clock out if the agent is actually clocked in or on a break
            if (in_array($stateInfo['state'], [
                \App\Services\AttendanceService::STATE_CLOCKED_IN,
                \App\Services\AttendanceService::STATE_ON_BREAK,
            ])) {
                $attendanceService->clockOut($userId);
            }

            // Log the forced session end with flag for admin
            $actionLabel = $reason === 'inactivity_timeout'
                ? 'inactivity_timeout'
                : 'concurrent_login_displaced';

            \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
                'user_id'     => $userId,
                'action'      => $actionLabel,
                'entity_type' => 'users',
                'entity_id'   => $userId,
                'details'     => json_encode([
                    'role'          => $role,
                    'inactive_mins' => $elapsedSeconds > 0 ? round($elapsedSeconds / 60) : null,
                    'flagged'       => true,
                    'auto_clocked_out' => in_array($stateInfo['state'] ?? '', [
                        \App\Services\AttendanceService::STATE_CLOCKED_IN,
                        \App\Services\AttendanceService::STATE_ON_BREAK,
                    ]),
                    'flag_message' => $reason === 'inactivity_timeout'
                        ? "Agent session expired after " . round($elapsedSeconds / 60) . " minutes of inactivity. Auto clocked out."
                        : "Agent was logged out due to concurrent login from another location. Auto clocked out.",
                ]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never let a clock-out failure block the logout redirect
            error_log('AuthMiddleware::autoClockOutOnTimeout failed: ' . $e->getMessage());
        }
    }
}
