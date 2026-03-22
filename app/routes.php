<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Middleware\AuthMiddleware;

// Require the routes to be injected into the Slim App
$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'processLogin']);
$app->get('/logout', [AuthController::class, 'logout']);

// Protected Route Group
$app->group('', function ($group) {
    // Temporary Dashboard route until we build Phase 5
    $group->get('/dashboard', function ($request, $response) {
        $response->getBody()->write('Welcome to Base Fare CRM, User ID: ' . $_SESSION['user_id'] . ' | Role: ' . $_SESSION['role'] . ' <br><br><a href="/logout">Logout</a>');
        return $response;
    });
})->add(new AuthMiddleware());

// Redirect root to dashboard
$app->get('/', function ($request, $response) {
    return $response->withHeader('Location', '/dashboard')->withStatus(302);
});
