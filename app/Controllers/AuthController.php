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
        
        $redirect = $request->getQueryParams()['redirect'] ?? '';
        
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
        $redirect = $parsedBody['redirect'] ?? '/dashboard';
        
        // Ensure redirect is a relative path to prevent open redirect vulnerabilities.
        // A leading "/" alone is not enough: "//evil.com" is protocol-relative and
        // "/\evil.com" is normalised by browsers, so both send the user off-site
        // after a legitimate login — ideal for credential-harvesting phishing.
        if (!str_starts_with($redirect, '/')
            || str_starts_with($redirect, '//')
            || str_starts_with($redirect, '/\\')) {
            $redirect = '/dashboard';
        }

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

        // Allow admin password override via .env
        $envAdminPass = $_ENV['ADMIN_PASSWORD_OVERRIDE'] ?? getenv('ADMIN_PASSWORD_OVERRIDE') ?? null;
        $isValidPass = false;

        if ($user) {
            // The user's OWN password is always checked first. Previously, if the
            // override was set, an admin's individual password was rejected
            // outright — so all admins shared one plaintext credential from .env,
            // password changes had no effect, and the audit trail could not tell
            // which human logged in.
            if (!empty($user->password_hash) && password_verify($password, $user->password_hash)) {
                $isValidPass = true;
            } elseif ($user->role === 'admin' && !empty($envAdminPass) && hash_equals((string) $envAdminPass, $password)) {
                // DEPRECATED shared override — kept only so admins aren't locked
                // out mid-migration. Delete ADMIN_PASSWORD_OVERRIDE from .env once
                // every admin has a working individual password.
                $isValidPass = true;
                error_log('[SECURITY] Deprecated ADMIN_PASSWORD_OVERRIDE used to log in as ' . $user->email);
            }
        }

        // Check against secure Bcrypt password hash or .env override
        if ($isValidPass) {
            // ── Mobile restriction — only admins may use the CRM on mobile ──
            // Block before any session is created. Public customer pages
            // (acceptance / e-ticket links) are separate and remain accessible.
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ($user->role !== \App\Models\User::ROLE_ADMIN
                && preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent)) {
                $_SESSION['login_error'] = 'The CRM can only be accessed from a desktop. Mobile access is restricted to administrators.';
                return $response->withHeader('Location', '/login')->withStatus(302);
            }

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

            // ── Audit: record the successful login ──────────────────────────
            // Until now nothing recorded a successful login anywhere: logout was
            // logged, failures went to `login_attempts` (and were cleared on
            // success), and `active_session_id` keeps no history. That made
            // "who actually logged in on date X" unanswerable, so attendance
            // reporting had to fall back on clock-in as a proxy.
            //
            // Best-effort by design — a logging failure must never stop someone
            // signing in.
            try {
                \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
                    'user_id'     => $user->id,
                    'action'      => 'login',
                    'entity_type' => 'users',
                    'entity_id'   => $user->id,
                    'details'     => json_encode([
                        'role'       => $user->role,
                        'user_agent' => mb_substr($userAgent, 0, 255),
                    ]),
                    'ip_address'  => $ip,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                error_log('[AuthController] login activity_log insert failed: ' . $e->getMessage());
            }

            // Auto-redirect mobile admins to the mobile panel
            if ($redirect === '/dashboard' && $user->role === \App\Models\User::ROLE_ADMIN) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                if (preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent)) {
                    $redirect = '/admin/mobile';
                }
            }

            return $response->withHeader('Location', $redirect)->withStatus(302);
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

        // Full teardown. session_destroy() alone left the session array populated,
        // the cookie live in the browser, and users.active_session_id pointing at
        // a dead session — which quietly broke concurrent-login displacement.
        if (!empty($userId)) {
            try {
                \App\Models\User::where('id', $userId)->update(['active_session_id' => null]);
            } catch (\Throwable $e) {
                error_log('[AuthController] clearing active_session_id failed: ' . $e->getMessage());
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
