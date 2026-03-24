<?php
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Illuminate\Database\Capsule\Manager as Capsule;

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

try {
    Capsule::schema()->table('shift_schedules', function ($table) {
        $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    });
    echo "Successfully added updated_at to shift_schedules\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
