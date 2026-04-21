<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    /**
     * Display the login page
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302); // Found
        }

        // Render the Tailwind PHP view we got from Stitch MCP
        ob_start();
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);
        
        require __DIR__ . '/../Views/login.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Process the login form submission with rate limiting
     */
    public function processLogin(Request $request, Response $response): Response
    {
        $parsedBody = $request->getParsedBody();
        $email = $parsedBody['email'] ?? '';
        $password = $parsedBody['password'] ?? '';

        // -----------------------------------------------------------------
        // Rate limiting – check if this IP is currently locked out
        // -----------------------------------------------------------------
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $attempt = \App\Models\LoginAttempt::forIp($ip);
        if ($attempt && $attempt->isLockedOut()) {
            // Too many attempts – inform the user and abort login
            $minutes = $attempt->minutesRemaining();
            $_SESSION['login_error'] = "Too many login attempts. Please try again in {$minutes} minute(s).";
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = \App\Models\User::where('email', $email)->where('status', 'active')->first();

        // Check against secure Bcrypt password hash
        if ($user && password_verify($password, $user->password_hash)) {
            // Successful login – reset any failure counters for this IP
            \App\Models\LoginAttempt::clearFor($ip);

            // Regenerate session ID to prevent fixation payload injections
            session_regenerate_id(true);

            // Store the active session ID for concurrent login detection
            $user->active_session_id = session_id();
            $user->save();

            $_SESSION['user_id'] = $user->id;
            $_SESSION['role'] = $user->role;
            $_SESSION['user_name'] = $user->name;
            $_SESSION['active_session_id'] = session_id(); // Cache it in session to save DB queries
            $_SESSION['last_activity'] = time(); // Initialize inactivity timer

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        // Failure – record the attempt
        \App\Models\LoginAttempt::recordFailure($ip, $email);
        $_SESSION['login_error'] = 'Invalid email or password.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /**
     * Logout and destroy session
     */
    public function logout(Request $request, Response $response): Response
    {
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            
            // Auto-clock-out on logout to prevent abuse loopholes
            $attendanceService = new \App\Services\AttendanceService();
            $stateInfo = $attendanceService->getCurrentState($userId);
            
            if (in_array($stateInfo['state'], [\App\Services\AttendanceService::STATE_CLOCKED_IN, \App\Services\AttendanceService::STATE_ON_BREAK])) {
                $attendanceService->clockOut($userId);
                
                // Log the auto-clock-out
                \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
                    'user_id'     => $userId,
                    'action'      => 'auto_clock_out_on_logout',
                    'entity_type' => 'attendance_sessions',
                    'entity_id'   => $stateInfo['session']->id ?? null,
                    'details'     => json_encode(['reason' => 'User logged out while active']),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }

        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
