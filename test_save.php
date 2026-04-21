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

$body = [
    'type' => 'new_booking',
    'customer_name' => 'John Doe',
    'customer_email' => 'john@example.com',
    'customer_phone' => '1234567890',
    'pnr' => 'TEST99',
    'total_amount' => 100,
    'currency' => 'USD',
    'agent_notes' => 'Test notes',
    'passengers_json' => '[{"name":"John Doe","dob":"","type":"adult"}]',
    'flight_data_json' => '{"flights":[{"airline_iata":"AA","flight_no":"AA123","cabin_class":"Economy","date":"12MAR","from":"JFK","to":"MIA","dep_time":"10:40","arr_time":"13:25","arr_next_day":false}]}'
];

$flightData = json_decode($body['flight_data_json'], true);
$req = new App\Models\AcceptanceRequest();
$req->agent_id = 1;
$req->token = md5(time());
$req->type = $body['type'];
$req->customer_name = $body['customer_name'];
$req->customer_email = $body['customer_email'];
$req->total_amount = $body['total_amount'];
$req->agent_notes = $body['agent_notes'];
$req->passengers = json_decode($body['passengers_json'], true);
$req->flight_data = $flightData;
$req->save();

echo 'Saved ID: ' . $req->id . "\n";
echo 'DB Flight Data: ' . $req->getAttributes()['flight_data'] . "\n";

$req->delete();
