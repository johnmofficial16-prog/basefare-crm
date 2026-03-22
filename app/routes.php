<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Controllers\ShiftController;
use App\Middleware\AuthMiddleware;
use App\Models\User;

// Auth routes (public)
$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'processLogin']);
$app->get('/logout', [AuthController::class, 'logout']);

// Protected Route Group (all authenticated users)
$app->group('', function ($group) {
    // Temporary Dashboard route until we build Phase 5
    $group->get('/dashboard', function ($request, $response) {
        $response->getBody()->write('Welcome to Base Fare CRM, User ID: ' . $_SESSION['user_id'] . ' | Role: ' . $_SESSION['role'] . ' <br><br><a href="/shifts/week">Shift Scheduling</a> | <a href="/logout">Logout</a>');
        return $response;
    });
})->add(new AuthMiddleware());

// Shift Scheduling Routes (admin + manager only)
$app->group('/shifts', function ($group) {
    $group->get('/week', [ShiftController::class, 'weekView']);
    $group->post('/week/publish', [ShiftController::class, 'publishWeek']);
    $group->post('/cell/update', [ShiftController::class, 'updateCell']);
    $group->post('/cell/delete', [ShiftController::class, 'deleteCell']);
    $group->get('/templates', [ShiftController::class, 'getTemplates']);
    $group->post('/templates', [ShiftController::class, 'saveTemplate']);
})->add(new AuthMiddleware([User::ROLE_ADMIN, User::ROLE_MANAGER]));

// Redirect root to dashboard
$app->get('/', function ($request, $response) {
    return $response->withHeader('Location', '/dashboard')->withStatus(302);
});

