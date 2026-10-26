<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'],
    'username'  => $_ENV['DB_USERNAME'],
    'password'  => $_ENV['DB_PASSWORD'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Create a test agent — with a random password shown once (it used to be the
// literal "password", which also reset the live account each run; audit 26 Oct 2026)
$password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
$agent = User::updateOrCreate(
    ['email' => 'agent@base-fare.com'],
    [
        'name' => 'Test Agent',
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => User::ROLE_AGENT,
        'status' => 'active',
        'grace_period_mins' => 30
    ]
);

echo "Test Agent seeded: agent@base-fare.com / {$password}  (shown once)\n";
