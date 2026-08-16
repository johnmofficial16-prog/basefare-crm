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
use App\Controllers\CustomerEmailController;
use App\Controllers\InvoiceController;
use App\Controllers\AnalyticsController;
use App\Controllers\ChargebackController;
use App\Controllers\ReminderController;
use App\Controllers\NotificationController;
use App\Controllers\AdminController;
use App\Controllers\MobileAdminController;
use App\Controllers\LetterController;
use App\Controllers\PayrollController;
use App\Controllers\VoucherController;
use App\Controllers\PerformanceController;
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
// Live Scoreboard routes (public — no middleware, PIN protected)
// ==========================================================================
$app->get('/liveboard/score', [\App\Controllers\LiveBoardController::class, 'page']);
$app->post('/liveboard/score/auth', [\App\Controllers\LiveBoardController::class, 'auth']);
$app->get('/api/liveboard/feed', [\App\Controllers\LiveBoardController::class, 'feed']);

// ==========================================================================
// Client error beacon (public — pre-auth pages must report too; CSRF-exempt
// in CsrfMiddleware; rate-limited + length-capped in the controller)
// ==========================================================================
$app->post('/api/client-error', [\App\Controllers\ClientErrorController::class, 'report']);

// NOTE: an unauthenticated GET /api/test-shift debug route lived here. It sat
// outside every middleware group and leaked live transaction volume plus the
// 10 most recent transaction IDs to anonymous callers. Removed 2026-07-27.
// The CLI equivalent (scratch/test_shift.php) covers the same debugging need.

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

// Agent Performance / Scores (admin + manager + supervisor, behind AttendanceGate)
// Team leads analyse agent-wise scores; export is PII-free (enforced in controller).
$app->group('/performance', function ($group) {
    $group->get('',                   [PerformanceController::class, 'index']);        // agents → own day-wise view
    $group->get('/export',            [PerformanceController::class, 'export']);       // team leads/admin only (enforced in controller)
    $group->get('/agent/{id:[0-9]+}', [PerformanceController::class, 'agentDetail']);  // team leads/admin drill-down
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_AGENT]));

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

// Payroll / Salary Slip Maker (admin only)
$app->group('/payroll', function ($group) {
    $group->get('', [PayrollController::class, 'slipMaker']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

// ==========================================================================
// AI Buddy — maintenance surface (admin OR users.is_dev; the role list here
// admits any authed user because AuthMiddleware cannot express the dev flag —
// BuddyController::allowed() is the real gate, re-reading is_dev per request)
// ==========================================================================
$app->group('/buddy', function ($group) {
    $group->get('/maintenance',          [\App\Controllers\BuddyController::class, 'maintenancePage']);
    $group->get('/maintenance/digest',   [\App\Controllers\BuddyController::class, 'maintenanceDigest']);
    $group->get('/maintenance/history',  [\App\Controllers\BuddyController::class, 'maintenanceHistory']);
    $group->post('/maintenance/chat',    [\App\Controllers\BuddyController::class, 'maintenanceChat']);
})
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// Agent buddy — self-scoped tools, any authed role, agents behind the
// AttendanceGate (buddy is a work tool; admins/managers are gate-exempt).
$app->group('/buddy', function ($group) {
    $group->get('',           [\App\Controllers\BuddyController::class, 'agentPage']);
    $group->get('/boot',      [\App\Controllers\BuddyController::class, 'boot']);
    $group->get('/history',   [\App\Controllers\BuddyController::class, 'agentHistory']);
    $group->post('/greeting', [\App\Controllers\BuddyController::class, 'agentGreeting']);
    $group->post('/chat',     [\App\Controllers\BuddyController::class, 'agentChat']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// Letters — Letter of Intent maker (admin only)
$app->group('/letters', function ($group) {
    $group->get('', [LetterController::class, 'loiMaker']);
    $group->get('/loi', [LetterController::class, 'loiMaker']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

// Travel Vouchers (admin only)
$app->group('/vouchers', function ($group) {
    $group->get('', [VoucherController::class, 'index']);
    $group->get('/maker', [VoucherController::class, 'maker']);
    $group->post('', [VoucherController::class, 'store']);
    $group->get('/{id}', [VoucherController::class, 'show']);
    $group->delete('/{id}', [VoucherController::class, 'destroy']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

// ==========================================================================
// Centre Analytics (admin only, behind AttendanceGate)
// Per-centre performance + marketing ROI dashboards + AI insights.
// ==========================================================================
$app->group('/analytics', function ($group) {
    $group->get('',                            [AnalyticsController::class, 'index']);
    $group->get('/settings',                   [AnalyticsController::class, 'settings']);
    $group->post('/settings',                  [AnalyticsController::class, 'saveSettings']);
    $group->post('/spend',                     [AnalyticsController::class, 'spendStore']);
    $group->post('/spend/{id:[0-9]+}/delete',  [AnalyticsController::class, 'spendDelete']);
    $group->post('/ai',                        [AnalyticsController::class, 'aiAnalyze']);   // AJAX — PII-free aggregates
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

// ==========================================================================
// Chargebacks & Refunds (admin only, behind AttendanceGate)
// Manually-entered chargeback/refund events, bifurcated per centre.
// ==========================================================================
$app->group('/chargebacks', function ($group) {
    $group->get('',                           [ChargebackController::class, 'index']);
    $group->post('',                          [ChargebackController::class, 'store']);          // AJAX add
    $group->post('/{id:[0-9]+}/update',       [ChargebackController::class, 'update']);         // AJAX edit
    $group->post('/{id:[0-9]+}/delete',       [ChargebackController::class, 'delete']);         // AJAX delete
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

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
->add(new AuthMiddleware([User::ROLE_ADMIN]));

// ==========================================================================
// Mobile Admin Quick Panel (admin ONLY, NO AttendanceGate — so admin
// can approve clock-ins without being clocked in themselves)
// ==========================================================================
$app->group('/admin/mobile', function ($group) {
    $group->get('', [MobileAdminController::class, 'page']);
})
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

$app->group('/api/admin/mobile', function ($group) {
    $group->get('/data',         [MobileAdminController::class, 'dashboardData']);
    $group->get('/transactions', [MobileAdminController::class, 'transactionsData']);
    $group->get('/actions',      [MobileAdminController::class, 'actionsData']);
})
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware([User::ROLE_ADMIN]));

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

    // Refund — admin only (enforced in controller). Keeps the sale; reduces Net MCO.
    $group->post('/{id:[0-9]+}/refund',  [TransactionController::class, 'refund']);

    // Dispute & Gateway — admin/manager only (enforced in controller)
    $group->post('/{id:[0-9]+}/dispute', [TransactionController::class, 'updateDispute']);
    $group->post('/{id:[0-9]+}/gateway', [TransactionController::class, 'updateGateway']);

    // Add note — all authenticated users
    $group->post('/{id:[0-9]+}/note',    [TransactionController::class, 'addNote']);

    // Booking reminders — admin/manager only (enforced in controller)
    $group->post('/{id:[0-9]+}/reminders', [ReminderController::class, 'store']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// Booking Reminders — management page + cancel (admin/manager only)
// ==========================================================================
$app->group('/reminders', function ($group) {
    $group->get('',                      [ReminderController::class, 'index']);
    $group->post('/{id:[0-9]+}/cancel',  [ReminderController::class, 'cancel']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// In-app Notifications (bell) — all authenticated users, outside the gate so
// the bell polls from every page (including attendance/clock-in surfaces).
// ==========================================================================
$app->group('', function ($group) {
    $group->get('/api/notifications',              [NotificationController::class, 'feed']);
    $group->get('/notifications',                  [NotificationController::class, 'index']);
    $group->post('/notifications/{id:[0-9]+}/read',[NotificationController::class, 'markRead']);
    $group->post('/notifications/read-all',        [NotificationController::class, 'markAllRead']);
})
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// Customer Emails — AI-drafted, two-way (behind Auth + AttendanceGate)
// Agents draft (AI) → manager approves → SMTP send. Replies pulled via IMAP.
// ==========================================================================
$app->group('/emails', function ($group) {
    $group->get('',                              [CustomerEmailController::class, 'index']);
    $group->get('/compose',                      [CustomerEmailController::class, 'composeForm']);
    $group->get('/booking-options',              [CustomerEmailController::class, 'bookingOptions']); // AJAX grounding picker
    $group->get('/itinerary/{id:[0-9]+}',        [CustomerEmailController::class, 'itinerary']);      // AJAX itinerary table from booking
    $group->post('/draft',                       [CustomerEmailController::class, 'draft']);          // AJAX → AI
    $group->post('/compose',                     [CustomerEmailController::class, 'store']);
    $group->get('/approvals',                    [CustomerEmailController::class, 'approvals']);      // manager/admin queue
    $group->post('/message/{mid:[0-9]+}/approve',[CustomerEmailController::class, 'approve']);        // manager/admin only
    $group->post('/message/{mid:[0-9]+}/reject', [CustomerEmailController::class, 'reject']);         // manager/admin only
    $group->get('/{id:[0-9]+}',                  [CustomerEmailController::class, 'view']);
    $group->post('/{id:[0-9]+}/reply',           [CustomerEmailController::class, 'reply']);
    $group->post('/{id:[0-9]+}/close',           [CustomerEmailController::class, 'close']);
})
->add(new AttendanceGateMiddleware())
->add(new IpRestrictionMiddleware())
->add(new AuthMiddleware());

// ==========================================================================
// Invoice Generator — Agent-facing (behind Auth + AttendanceGate)
// Anyone (except CSA) drafts; agents/supervisors submit for approval, managers
// /admins save & send. Delivery is a branded inline HTML email to the customer.
// ==========================================================================
$app->group('/invoices', function ($group) {
    $group->get('',                              [InvoiceController::class, 'index']);
    $group->get('/create',                       [InvoiceController::class, 'maker']);
    $group->get('/booking-options',              [InvoiceController::class, 'bookingOptions']);     // AJAX picker
    $group->get('/transaction-data/{id:[0-9]+}', [InvoiceController::class, 'transactionData']);    // AJAX prefill
    $group->post('/create',                      [InvoiceController::class, 'store']);
    $group->get('/approvals',                    [InvoiceController::class, 'approvals']);           // manager/admin only — enforced in controller
    $group->get('/{id:[0-9]+}',                  [InvoiceController::class, 'view']);
    $group->get('/{id:[0-9]+}/edit',             [InvoiceController::class, 'editForm']);
    $group->post('/{id:[0-9]+}/edit',            [InvoiceController::class, 'update']);
    $group->post('/{id:[0-9]+}/approve',         [InvoiceController::class, 'approve']);             // manager/admin only — enforced in controller
    $group->post('/{id:[0-9]+}/reject',          [InvoiceController::class, 'reject']);             // manager/admin only — enforced in controller
    $group->post('/{id:[0-9]+}/send',            [InvoiceController::class, 'send']);               // manager/admin only — enforced in controller
    $group->post('/{id:[0-9]+}/note',            [InvoiceController::class, 'addNote']);
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

// Redirect root
$app->get('/', function ($request, $response) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $userAgent = $request->getHeaderLine('User-Agent');
    $isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent);
    $role = $_SESSION['role'] ?? '';
    
    if ($isMobile && $role === \App\Models\User::ROLE_ADMIN) {
        return $response->withHeader('Location', '/admin/mobile')->withStatus(302);
    }
    
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
$app->post('/eticket/contact',    [ETicketController::class, 'publicContact']);
$app->get('/eticket/confirmed',   [ETicketController::class, 'publicConfirmed']);
