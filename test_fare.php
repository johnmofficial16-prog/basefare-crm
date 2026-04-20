<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$req = \App\Models\AcceptanceRequest::orderBy('id', 'desc')->first();
echo json_encode($req->fare_breakdown);
echo "\nDBA Name Test: \n";
$fareBreakdown = $req->fare_breakdown ?? [];
$dbaName = 'Lets Fly Travel LLC DBA Base Fare';
if (!empty($fareBreakdown) && is_array($fareBreakdown)) {
    $firstItem = reset($fareBreakdown);
    echo "First item label: " . $firstItem['label'] . "\n";
    if ($firstItem && isset($firstItem['label']) && strtolower(trim($firstItem['label'])) === 'airline tickets') {
        $dbaName = 'Airline Tickets';
    }
}
echo "DBA NAME IS: " . $dbaName . "\n";
