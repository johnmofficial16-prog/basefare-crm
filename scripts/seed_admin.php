<?php
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;

// Load env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Boot Eloquent just for this script
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

// Seed Admin User
$email = 'admin@base-fare.com';
$password = 'password';

$exists = Capsule::table('users')->where('email', $email)->first();

if (!$exists) {
    Capsule::table('users')->insert([
        'name' => 'Super Admin',
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => 'admin',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Admin user created!\n";
} else {
    echo "Admin user already exists!\n";
}
