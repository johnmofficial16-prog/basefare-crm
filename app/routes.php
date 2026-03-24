<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Controllers\ShiftController;
use App\Controllers\AttendanceController;
use App\Controllers\DashboardController;
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
// ==========================================================================
$app->group('', function ($group) {
    $group->get('/clock-in', [AttendanceController::class, 'lobbyPage']);
    $group->post('/clock-in', [AttendanceController::class, 'processClockIn']);
    $group->post('/clock-out', [AttendanceController::class, 'clockOut']);
    $group->post('/break/start', [AttendanceController::class, 'startBreak']);
    $group->post('/break/end', [AttendanceController::class, 'endBreak']);
    $group->get('/attendance/status', [AttendanceController::class, 'statusPoll']);
    $group->get('/attendance/waiting', [AttendanceController::class, 'overrideWait']);
    $group->get('/attendance/my', [AttendanceController::class, 'myAttendance']);
})->add(new AuthMiddleware());

// Attendance Admin routes (admin + manager only, outside AttendanceGate)
$app->group('/attendance', function ($group) {
    $group->get('/admin', [AttendanceController::class, 'adminPanel']);
    $group->get('/admin/data', [AttendanceController::class, 'adminBoardData']);
    $group->get('/admin/history', [AttendanceController::class, 'adminHistory']);
    $group->post('/override', [AttendanceController::class, 'approveOverride']);
    $group->post('/deny', [AttendanceController::class, 'denyOverride']);
    $group->post('/admin/clock-in', [AttendanceController::class, 'adminClockIn']);
    $group->post('/admin/clock-out', [AttendanceController::class, 'adminClockOut']);
    $group->post('/admin/force-end-break', [AttendanceController::class, 'adminForceEndBreak']);
})->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

// ==========================================================================
// CRM Core routes — BEHIND the AttendanceGate
// ==========================================================================
$app->group('', function ($group) {
    $group->get('/dashboard', [DashboardController::class, 'index']);
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

