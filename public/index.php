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

// Detect HTTPS — covers direct TLS and Hostinger's reverse proxy (X-Forwarded-Proto)
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Secure session configuration
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,   // Cookie only sent over HTTPS
    'httponly' => true,        // JS cannot access the session cookie
    'samesite' => 'Strict',   // Blocks cookie on all cross-site requests (CSRF defence in depth)
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
