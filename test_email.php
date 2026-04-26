<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use App\Models\AcceptanceRequest;
use App\Services\InternalAlertService;
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_NAME'],
    'username'  => $_ENV['DB_USER'],
    'password'  => $_ENV['DB_PASS'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$a = AcceptanceRequest::find(45);
if(!$a) die('Acceptance 45 not found');

echo "Sending alert...\n";
$svc = new InternalAlertService();
$res = $svc->sendApprovalAlert($a);
echo $res ? "SUCCESS\n" : "FAILED\n";
