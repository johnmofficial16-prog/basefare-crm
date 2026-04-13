<?php

use Slim\Factory\AppFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Setup Eloquent ORM
$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'],
    'username'  => $_ENV['DB_USERNAME'],
    'password'  => $_ENV['DB_PASSWORD'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Secure session configuration
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // Enforce secure cookie if running on HTTPS
    'httponly' => true, // Prevents Javascript from accessing session cookie
    'samesite' => 'Lax' // Protects against broad cross-site attacks
]);

// Start PHP session
session_start();

// Set application timezone (prevents date() showing wrong day on shared hosts)
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

// Instantiate App
$app = AppFactory::create();

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Error Middleware (display errors if APP_ENV=local OR APP_DEBUG=true)
$displayErrorDetails = ($_ENV['APP_ENV'] === 'local') || (($_ENV['APP_DEBUG'] ?? 'false') === 'true');
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);

// Include routes
require __DIR__ . '/../app/routes.php';

// Add Global CSRF Middleware (must be added after routes but executes early in stack or added via app directly)
$app->add(new \App\Middleware\CsrfMiddleware());

// Run App
$app->run();
