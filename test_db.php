<?php
require 'vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$capsule = new Illuminate\Database\Capsule\Manager;
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

$all = App\Models\AcceptanceRequest::all();
echo "Total records: " . $all->count() . "\n";
foreach($all as $a) {
    if ($a->pnr === 'TEST36') {
        echo "FOUND TEST36: ID " . $a->id . "\n";
        echo "Flights: " . $a->getAttributes()['flight_data'] . "\n";
    }
}
