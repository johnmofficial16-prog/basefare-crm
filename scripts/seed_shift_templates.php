<?php
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;

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
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$adminId = Capsule::table('users')->where('email', 'admin@base-fare.com')->value('id');

$templates = [
    ['name' => 'Morning', 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
    ['name' => 'Evening', 'start_time' => '14:00:00', 'end_time' => '23:00:00'],
    ['name' => 'Night',   'start_time' => '22:00:00', 'end_time' => '07:00:00'],
    ['name' => 'Split',   'start_time' => '10:00:00', 'end_time' => '19:00:00'],
];

foreach ($templates as $t) {
    $exists = Capsule::table('shift_templates')->where('name', $t['name'])->first();
    if (!$exists) {
        Capsule::table('shift_templates')->insert(array_merge($t, [
            'created_by' => $adminId,
            'created_at' => date('Y-m-d H:i:s'),
        ]));
        echo "Created template: {$t['name']}\n";
    } else {
        echo "Template already exists: {$t['name']}\n";
    }
}

echo "Done.\n";
