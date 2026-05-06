<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
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

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$now = Carbon\Carbon::now();
if ($now->hour >= 18) {
    $shiftStart = $now->copy()->startOfDay()->addHours(18); // Today 6 PM
    $shiftEnd   = $now->copy()->addDay()->startOfDay()->addHours(18)->subSecond(); // Tomorrow 5:59:59 PM
} else {
    $shiftStart = $now->copy()->subDay()->startOfDay()->addHours(18); // Yesterday 6 PM
    $shiftEnd   = $now->copy()->startOfDay()->addHours(18)->subSecond(); // Today 5:59:59 PM
}

echo "Current Time: " . $now->toDateTimeString() . "\n";
echo "Shift Start: " . $shiftStart->toDateTimeString() . "\n";
echo "Shift End: " . $shiftEnd->toDateTimeString() . "\n\n";

$txns = App\Models\Transaction::where('status', App\Models\Transaction::STATUS_APPROVED)
    ->orderByDesc('created_at')
    ->limit(5)
    ->get(['id', 'created_at']);

echo "Last 5 Approved Transactions:\n";
foreach ($txns as $t) {
    echo "ID: {$t->id} | Created At: {$t->created_at}\n";
}
