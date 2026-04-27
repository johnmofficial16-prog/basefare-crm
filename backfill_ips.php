<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\AcceptanceRequest;

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => $_ENV['DB_CONNECTION'] ?? 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'      => $_ENV['DB_PORT'] ?? '3306',
    'database'  => $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "Starting IP Geolocation Backfill...\n";

// Get all approved requests that have an IP but no city
$acceptances = AcceptanceRequest::whereNotNull('ip_address')
    ->where('ip_address', '!=', 'unknown')
    ->whereNull('ip_city')
    ->get();

if ($acceptances->isEmpty()) {
    echo "No records need updating. All done!\n";
    exit;
}

echo "Found " . $acceptances->count() . " records to update.\n";
$successCount = 0;

foreach ($acceptances as $acceptance) {
    $ip = $acceptance->ip_address;
    
    // Quick validation
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        echo "Skipping invalid/private IP: {$ip} (ID: {$acceptance->id})\n";
        continue;
    }

    echo "Processing IP: {$ip} (ID: {$acceptance->id})... ";

    try {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $ipDataJson = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,zip,isp", false, $ctx);
        
        if ($ipDataJson) {
            $ipData = json_decode($ipDataJson, true);
            if (isset($ipData['status']) && $ipData['status'] === 'success') {
                $acceptance->update([
                    'ip_city'    => $ipData['city']    ?? null,
                    'ip_country' => $ipData['country'] ?? null,
                    'ip_isp'     => $ipData['isp']     ?? null,
                    'ip_zip'     => $ipData['zip']     ?? null,
                ]);
                echo "OK ({$ipData['city']}, {$ipData['country']})\n";
                $successCount++;
                
                // Sleep for 1.5 seconds to respect the ip-api.com free tier rate limit (45 req / minute)
                usleep(1500000); 
            } else {
                echo "FAILED API (Status not success)\n";
            }
        } else {
            echo "FAILED API (No response)\n";
        }
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nDone! Successfully updated {$successCount} records.\n";
