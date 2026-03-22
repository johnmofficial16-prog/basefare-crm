<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Controllers\ShiftController;
use App\Controllers\AttendanceController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AttendanceGateMiddleware;
use App\Models\User;

// ==========================================================================
// Auth routes (public — no middleware)
// ==========================================================================
$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'processLogin']);
$app->get('/logout', [AuthController::class, 'logout']);

// ==========================================================================
// Attendance routes — require auth but OUTSIDE the AttendanceGate
// (Agents must be able to reach these even before clocking in)
// ==========================================================================
$app->group('', function ($group) {
    $group->get('/clock-in', [AttendanceController::class, 'lobbyPage']);
    $group->post('/clock-in', [AttendanceController::class, 'processClockIn']);
    $group->post('/clock-out', [AttendanceController::class, 'clockOut']);
    $group->post('/break/start', [AttendanceController::class, 'startBreak']);
    $group->post('/break/end', [AttendanceController::class, 'endBreak']);
    $group->get('/attendance/status', [AttendanceController::class, 'statusPoll']);
    $group->get('/attendance/waiting', [AttendanceController::class, 'overrideWait']);
})->add(new AuthMiddleware());

// Attendance Admin routes (admin + manager only, outside AttendanceGate)
$app->group('/attendance', function ($group) {
    $group->get('/admin', [AttendanceController::class, 'adminPanel']);
    $group->post('/override', [AttendanceController::class, 'approveOverride']);
})->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

// ==========================================================================
// CRM Core routes — BEHIND the AttendanceGate
// Agents MUST have an active clock-in session to access these.
// Admins/Managers bypass the gate automatically.
// ==========================================================================
$app->group('', function ($group) {
    // Dashboard (temp until Phase 5)
    $group->get('/dashboard', function ($request, $response) {
        $links = '<a href="/shifts/week">Shift Scheduling</a>';
        $links .= ' | <a href="/attendance/admin">Attendance Panel</a>';
        $links .= ' | <a href="/logout">Logout</a>';
        $response->getBody()->write(
            'Welcome to Base Fare CRM, ' . htmlspecialchars($_SESSION['user_name'] ?? 'User') .
            ' | Role: ' . $_SESSION['role'] .
            ' <br><br>' . $links
        );
        return $response;
    });
})
->add(new AttendanceGateMiddleware())
->add(new AuthMiddleware());

// Shift Scheduling Routes (admin + manager only, behind AttendanceGate)
$app->group('/shifts', function ($group) {
    $group->get('/week', [ShiftController::class, 'weekView']);
    $group->post('/week/publish', [ShiftController::class, 'publishWeek']);
    $group->post('/cell/update', [ShiftController::class, 'updateCell']);
    $group->post('/cell/delete', [ShiftController::class, 'deleteCell']);
    $group->get('/templates', [ShiftController::class, 'getTemplates']);
    $group->post('/templates', [ShiftController::class, 'saveTemplate']);
})
->add(new AttendanceGateMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

// Redirect root to dashboard
$app->get('/', function ($request, $response) {
    return $response->withHeader('Location', '/dashboard')->withStatus(302);
});
