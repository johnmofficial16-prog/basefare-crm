<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Controllers\ShiftController;
use App\Controllers\AttendanceController;
use App\Controllers\DashboardController;
use App\Controllers\AcceptanceController;
use App\Controllers\TransactionController;
use App\Controllers\UserController;
use App\Controllers\ETicketController;
use App\Controllers\AdminController;
use App\Controllers\PayrollController;
use App\Middleware\AuthMiddleware;
use App\Middleware\IpRestrictionMiddleware;
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
})->add(new IpRestrictionMiddleware())->add(new AuthMiddleware());

// Attendance Admin routes (admin + manager + supervisor, outside AttendanceGate)
$app->group('/attendance', function ($group) {
    $group->get('/admin', [AttendanceController::class, 'adminPanel']);
    $group->get('/admin/data', [AttendanceController::class, 'adminBoardData']);
    $group->get('/admin/history', [AttendanceController::class, 'adminHistory']);
    $group->get('/admin/monthly', [AttendanceController::class, 'adminMonthly']);
    $group->get('/admin/monthly/export', [AttendanceController::class, 'exportMonthlyCsv']);
    $group->get('/admin/export',  [AttendanceController::class, 'exportCsv']);    // admin/manager/supervisor
    $group->post('/override', [AttendanceController::class, 'approveOverride']);
    $group->post('/deny', [AttendanceController::class, 'denyOverride']);
    $group->post('/admin/clock-in', [AttendanceController::class, 'adminClockIn']);
    $group->post('/admin/clock-out', [AttendanceController::class, 'adminClockOut']);
    $group->post('/admin/force-end-break', [AttendanceController::class, 'adminForceEndBreak']);
})->add(new IpRestrictionMiddleware())->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR]));

// ==========================================================================
// CRM Core routes — BEHIND the AttendanceGate
// ==========================================================================
$app->group('', function ($group) {
    $group->get('/dashboard', [DashboardController::class, 'index']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// Shift Scheduling Routes (admin + manager + supervisor, behind AttendanceGate)
$app->group('/shifts', function ($group) {
    $group->get('/week', [ShiftController::class, 'weekView']);
    $group->post('/week/publish', [ShiftController::class, 'publishWeek']);
    $group->post('/week/approve', [ShiftController::class, 'approvePublish']);  // manager/admin only (enforced in controller)
    $group->post('/cell/update', [ShiftController::class, 'updateCell']);
    $group->post('/cell/delete', [ShiftController::class, 'deleteCell']);
    $group->get('/templates', [ShiftController::class, 'getTemplates']);
    $group->post('/templates', [ShiftController::class, 'saveTemplate']);
    $group->get('/pending-approvals', [ShiftController::class, 'pendingApprovals']); // manager/admin only (enforced in controller)
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR]));

// ==========================================================================
// Admin & User Management (admin only, behind AttendanceGate)
// ==========================================================================
// User Management (admin + manager, behind AttendanceGate)
// Controller enforces rank restrictions: managers cannot touch other managers or admins.
$app->group('/users', function ($group) {
    $group->get('',         [UserController::class, 'index']);
    $group->get('/export', [UserController::class, 'exportCsv']); // admin/manager only
    $group->get('/create', [UserController::class, 'createForm']);
    $group->post('/create', [UserController::class, 'store']);
    $group->get('/{id:[0-9]+}/edit', [UserController::class, 'editForm']);
    $group->post('/{id:[0-9]+}/edit', [UserController::class, 'update']);
    $group->post('/{id:[0-9]+}/toggle-status', [UserController::class, 'toggleStatus']);
    $group->post('/{id:[0-9]+}/reset-password', [UserController::class, 'resetPassword']);
    $group->post('/{id:[0-9]+}/delete', [UserController::class, 'delete']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

// Payroll / Salary Slip Maker (admin + manager only)
$app->group('/payroll', function ($group) {
    $group->get('', [PayrollController::class, 'slipMaker']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

$app->group('/admin', function ($group) {
    $group->get('/settings', [AdminController::class, 'settings']);
    $group->post('/settings', [AdminController::class, 'saveSettings']);
    $group->get('/ip-whitelist', [AdminController::class, 'ipWhitelist']);
    $group->post('/ip-whitelist', [AdminController::class, 'addIpWhitelist']);
    $group->post('/ip-whitelist/toggle', [AdminController::class, 'toggleIpWhitelist']);
    $group->post('/ip-whitelist/{id:[0-9]+}/delete', [AdminController::class, 'deleteIpWhitelist']);
    $group->get('/activity-log', [AdminController::class, 'activityLog']);
    $group->get('/error-console', [AdminController::class, 'errorConsole']);
    $group->post('/error-console/clear', [AdminController::class, 'clearErrorLog']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

// ==========================================================================
// Acceptance Module — Agent-facing (behind Auth + AttendanceGate)
// ==========================================================================
$app->group('/acceptance', function ($group) {
    $group->get('',              [AcceptanceController::class, 'index']);
    $group->get('/export',       [AcceptanceController::class, 'exportCsv']);   // admin/manager only — enforced in controller
    $group->get('/create',       [AcceptanceController::class, 'createForm']);  // ?from_preauth=ID for promotion
    $group->post('/create',      [AcceptanceController::class, 'store']);
    $group->get('/{id:[0-9]+}',  [AcceptanceController::class, 'view']);
    $group->get('/{id:[0-9]+}/receipt', [AcceptanceController::class, 'receipt']);
    $group->post('/{id:[0-9]+}/resend',  [AcceptanceController::class, 'resend']);
    $group->post('/{id:[0-9]+}/cancel',  [AcceptanceController::class, 'cancel']);
    $group->post('/{id:[0-9]+}/note',    [AcceptanceController::class, 'addNote']);
    $group->get('/{id:[0-9]+}/download/{type}', [AcceptanceController::class, 'downloadEvidence']);
    $group->post('/{id:[0-9]+}/reveal-cc', [AcceptanceController::class, 'revealCC']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// Transaction Recorder — Agent-facing (behind Auth + AttendanceGate)
// ==========================================================================
$app->group('/transactions', function ($group) {
    // List & Create
    $group->get('',                          [TransactionController::class, 'index']);
    $group->get('/export',                   [TransactionController::class, 'exportCsv']);   // admin/manager only — enforced in controller
    $group->get('/create',                   [TransactionController::class, 'createForm']);
    $group->post('/create',                  [TransactionController::class, 'store']);

    // AJAX endpoints
    $group->get('/autofill-options',         [TransactionController::class, 'autofillOptions']);
    $group->get('/acceptance-data/{id:[0-9]+}', [TransactionController::class, 'acceptanceData']);
    $group->post('/reveal-card',             [TransactionController::class, 'revealCard']);

    // Single transaction actions
    $group->get('/{id:[0-9]+}',              [TransactionController::class, 'view']);
    $group->get('/{id:[0-9]+}/proof',        [TransactionController::class, 'viewProof']);
    $group->get('/{id:[0-9]+}/edit',         [TransactionController::class, 'editForm']);
    $group->post('/{id:[0-9]+}/edit',        [TransactionController::class, 'update']);

    // Approve — manager + supervisor (supervisor scoped to own team, enforced in controller)
    $group->post('/{id:[0-9]+}/approve', [TransactionController::class, 'approve']);

    // Void — manager + admin only (enforced in controller)
    $group->post('/{id:[0-9]+}/void',    [TransactionController::class, 'void']);

    // Dispute & Gateway — admin/manager only (enforced in controller)
    $group->post('/{id:[0-9]+}/dispute', [TransactionController::class, 'updateDispute']);
    $group->post('/{id:[0-9]+}/gateway', [TransactionController::class, 'updateGateway']);

    // Add note — all authenticated users
    $group->post('/{id:[0-9]+}/note',    [TransactionController::class, 'addNote']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// Acceptance Module — Public customer-facing (NO auth — token-based)
// URL: https://base-fare.com/auth?token=xxx
// ==========================================================================
$app->get('/auth',           [AcceptanceController::class, 'publicView']);
$app->post('/auth',          [AcceptanceController::class, 'publicSubmit']);
$app->get('/auth/confirmed', [AcceptanceController::class, 'publicConfirmed']);

// Redirect root to dashboard
$app->get('/', function ($request, $response) {
    return $response->withHeader('Location', '/dashboard')->withStatus(302);
});

// ==========================================================================
// E-Ticket Module — Agent-facing (behind Auth + AttendanceGate)
// ==========================================================================
$app->group('/etickets', function ($group) {
    $group->get('',                                   [ETicketController::class, 'index']);
    $group->get('/create',                            [ETicketController::class, 'createForm']);
    $group->post('/create',                           [ETicketController::class, 'store']);
    $group->get('/autofill-options',                  [ETicketController::class, 'autofillOptions']);
    $group->get('/transaction-data/{id:[0-9]+}',      [ETicketController::class, 'transactionData']);
    $group->get('/{id:[0-9]+}',                       [ETicketController::class, 'view']);
    $group->post('/{id:[0-9]+}/send',                 [ETicketController::class, 'sendEmail']);
    $group->post('/{id:[0-9]+}/note',                 [ETicketController::class, 'addNote']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// E-Ticket Module — Public customer-facing (NO auth — token-based)
// URL: https://base-fare.com/eticket?token=xxx
// ==========================================================================
$app->get('/eticket',             [ETicketController::class, 'publicView']);
$app->post('/eticket/acknowledge',[ETicketController::class, 'publicAcknowledge']);
$app->get('/eticket/confirmed',   [ETicketController::class, 'publicConfirmed']);
