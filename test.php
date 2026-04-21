<?php
require 'vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
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

$reqs = \App\Models\AcceptanceRequest::orderBy('id', 'desc')->take(3)->get();
foreach ($reqs as $req) {
    echo "ID: " . $req->id . " Type: " . $req->type . "\n";
    echo "Flight Data:\n";
    echo json_encode($req->flight_data, JSON_PRETTY_PRINT) . "\n\n";
}
