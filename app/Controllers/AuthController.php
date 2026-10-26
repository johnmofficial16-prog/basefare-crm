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

        // Each person's OWN password only. The shared ADMIN_PASSWORD_OVERRIDE from
        // .env (which signed in as ANY admin) was removed in the audit of 26 Oct
        // 2026: that file was publicly readable for six weeks in July, so the
        // shared value must be treated as known.
        $isValidPass = $user && !empty($user->password_hash) && password_verify($password, $user->password_hash);

        // Per-account limit as well as per-IP: guessing one person's password
        // from many addresses is stopped after ACCOUNT_MAX failures.
        // (Checked for every email typed, real or not, so the message gives away nothing.)
        if (\App\Models\LoginAttempt::accountLocked($email)) {
            $_SESSION['login_error'] = 'Too many failed attempts on this account. Please try again in '
                . \App\Models\LoginAttempt::ACCOUNT_LOCK_MINS . ' minutes, or ask an admin to reset your password.';
            \App\Models\LoginAttempt::recordFailure($ip, $email);
            return $response->withHeader('Location', '/login')->withStatus(302);
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

            // Successful login – reset any failure counters for this IP and account
            \App\Models\LoginAttempt::clearFor($ip);
            \App\Models\LoginAttempt::clearAccount($email);

            // ── Admins: second step (app code) unless this computer is remembered ──
            if (\App\Services\TwoStep::requiredFor($user)
                && !(\App\Services\TwoStep::isSetUp($user) && \App\Services\TwoStep::isRemembered($user))) {
                session_regenerate_id(true);
                $_SESSION['two_step'] = ['uid' => (int) $user->id, 'redirect' => $redirect, 'at' => time(), 'tries' => 0];
                return $response->withHeader('Location', '/login/two-step')->withStatus(302);
            }

            return $this->completeLogin($response, $user, $redirect);
        }

        // Failure – record the attempt (per IP and per account)
        \App\Models\LoginAttempt::recordFailure($ip, $email);
        \App\Models\LoginAttempt::recordAccountFailure($email);
        $_SESSION['login_error'] = 'Invalid email or password.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /**
     * Start the signed-in session (after the password, and for admins after the
     * second step too — TwoStepController calls this).
     */
    public function completeLogin(Response $response, \App\Models\User $user, string $redirect): Response
    {
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        unset($_SESSION['two_step']);
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
        // Aisha greets on EVERY login, not once a day: this flag is the
        // trigger, set here because login is the only moment that reliably
        // means "they just arrived". Closing and reopening the browser ends
        // the session, so the next sign-in gets a fresh greeting.
        $_SESSION['buddy_greet_due'] = true;
        // Staff survey: ask again on every sign-in until it has been filled.
        $_SESSION['staff_survey_due'] = true;
        // Simple view: an admin account flagged for the simplified interface
        // starts there on every sign-in (full access is one click away).
        $_SESSION['simple_mode'] = $user->role === \App\Models\User::ROLE_ADMIN && !empty($user->simple_view);
        $_SESSION['simple_available'] = $_SESSION['simple_mode'];   // shows "Back to Simple view" in the full CRM
        // Theme: their own dark-mode choice (NULL = the standard light look).
        $_SESSION['theme'] = \App\Services\Theme::normalise($user->theme ?? null);
        // Password reset by someone else: they choose their own before anything else.
        $_SESSION['must_change_password'] = !empty($user->must_change_password);
        // Developer account: start in dev view, with the view switcher.
        if (!empty($user->is_dev)) {
            \App\Services\DevMode::begin($user);
            if ($redirect === '/dashboard') {
                $redirect = '/dev';
            }
        }

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

        if ($redirect === '/dashboard' && $_SESSION['simple_mode']) {
            $redirect = '/simple';
        }

        // Auto-redirect mobile admins to the mobile panel
        if ($redirect === '/dashboard' && $user->role === \App\Models\User::ROLE_ADMIN) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent)) {
                $redirect = '/admin/mobile';
            }
        }

        if ($_SESSION['must_change_password']) {
            $redirect = '/account/password';
        }

        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    /**
     * Logout and destroy session
     */
    public function logout(Request $request, Response $response): Response
    {
        if (\App\Services\DevMode::active()) {
            // A developer signing out: never clock out (or end the session of)
            // whoever they were viewing as — only their own account is touched.
            \App\Services\DevMode::log('dev_logout');
            $userId = \App\Services\DevMode::devId();
        } elseif (isset($_SESSION['user_id'])) {
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
