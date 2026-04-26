<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
use App\Models\AcceptanceRequest;
use App\Services\AcceptanceEmailService;
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// We can test with the same request ID 45
$a = AcceptanceRequest::find(45);
if(!$a) die("Acceptance 45 not found\n");

// Optionally override the customer email to your testing email so it goes to you, not a real customer
$a->customer_email = 'john.mj21@gmail.com'; 

echo "Sending Customer Auth Request to: " . $a->customer_email . "...\n";

$svc = new AcceptanceEmailService();
$res = $svc->send($a);

echo "Result: " . json_encode($res) . "\n";
