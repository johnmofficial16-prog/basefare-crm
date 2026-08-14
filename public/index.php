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
    // Behind Hostinger's TLS-terminating proxy, $_SERVER['HTTPS'] is often unset
    // and only X-Forwarded-Proto reflects the real scheme — without this check
    // the cookie ships WITHOUT Secure and can leak over a plaintext request.
    'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true, // Prevents Javascript from accessing session cookie
    'samesite' => 'Lax' // Protects against broad cross-site attacks
]);

// Start PHP session
session_start();

// Set application timezone (prevents date() showing wrong day on shared hosts)
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

// Global error capture → error_log table (Admin → Error Console).
// Installed AFTER Capsule boots so the logger has a DB; ErrorLogService is
// fail-soft, so a broken DB degrades to the PHP server log, never a crash.
\App\Services\ErrorLogService::install();

// Instantiate App
$app = AppFactory::create();

// Add Body Parsing Middleware (for JSON requests)
$app->addBodyParsingMiddleware();

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Error Middleware (display errors if APP_ENV=local OR APP_DEBUG=true)
$displayErrorDetails = (($_ENV['APP_ENV'] ?? 'production') === 'local') || (($_ENV['APP_DEBUG'] ?? 'false') === 'true');
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);

// Route exceptions through the Error Console before rendering a response.
// 404/405 are bot noise, not errors — they render but are never logged.
$errorMiddleware->setDefaultErrorHandler(function (
    \Psr\Http\Message\ServerRequestInterface $request,
    \Throwable $exception,
    bool $displayErrorDetails
) use ($app) {
    $is404 = $exception instanceof \Slim\Exception\HttpNotFoundException;
    $is405 = $exception instanceof \Slim\Exception\HttpMethodNotAllowedException;

    if (!$is404 && !$is405) {
        \App\Services\ErrorLogService::logThrowable($exception);
    }

    $status = $exception instanceof \Slim\Exception\HttpException
        ? $exception->getCode()
        : 500;
    $status = ($status >= 400 && $status < 600) ? $status : 500;

    $response = $app->getResponseFactory()->createResponse($status);
    if ($displayErrorDetails) {
        $body = '<h1>' . htmlspecialchars(get_class($exception)) . '</h1>'
              . '<p>' . htmlspecialchars($exception->getMessage()) . '</p>'
              . '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
    } elseif ($is404) {
        $body = '<h1>404 — Page not found</h1>';
    } else {
        $body = '<h1>Something went wrong</h1>'
              . '<p>The error has been logged and the team can see it in the Error Console.</p>';
    }
    $response->getBody()->write($body);
    return $response->withHeader('Content-Type', 'text/html');
});

// Include routes
require __DIR__ . '/../app/routes.php';

// Add Global Security Headers Middleware
$app->add(new \App\Middleware\SecurityHeadersMiddleware());

// Add Global CSRF Middleware (must be added after routes but executes early in stack or added via app directly)
$app->add(new \App\Middleware\CsrfMiddleware());

// Run App
$app->run();
