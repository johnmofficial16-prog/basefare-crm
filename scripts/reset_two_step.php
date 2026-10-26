<?php
/**
 * Emergency: reset an admin's two-step sign-in from the server (SSH only —
 * scripts/ is refused over the web). Use when every phone with the app AND
 * the backup codes are lost.
 *
 *   php scripts/reset_two_step.php admin@base-fare.com
 *
 * At the next sign-in they set the app up again with a new QR code. Every
 * "remembered" computer is forgotten too.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql', 'host' => $_ENV['DB_HOST'], 'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_DATABASE'], 'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();

$email = strtolower(trim($argv[1] ?? ''));
$user = $email !== '' ? Capsule::table('users')->where('email', $email)->whereNull('deleted_at')->first() : null;
if (!$user) {
    fwrite(STDERR, "Usage: php scripts/reset_two_step.php <email of an existing user>\n");
    exit(1);
}
\App\Services\TwoStep::reset((int) $user->id);
echo "Two-step sign-in reset for {$email}. They'll scan a new QR code at the next sign-in.\n";
