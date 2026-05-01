<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php'; // Or whatever initializes eloquent

use App\Models\Transaction;

// Set up Eloquent (assuming standard setup or mimicking it)
$dbConfig = require __DIR__ . '/../config/database.php';
use Illuminate\Database\Capsule\Manager as Capsule;
$capsule = new Capsule;
$capsule->addConnection($dbConfig['connections'][$dbConfig['default']]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$gross = Transaction::selectRaw("
    SUM(
        COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.fare_breakdown[0].amount')) AS DECIMAL(10,2)),
            profit_mco
        )
    ) as gross
")->value('gross');

$net = Transaction::sum('profit_mco');

echo "Gross: $gross\n";
echo "Net (MCO): $net\n";
