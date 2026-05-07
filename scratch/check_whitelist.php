<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    $ips = DB::table('ip_whitelist')->get();
    echo json_encode($ips, JSON_PRETTY_PRINT);
} catch (\Exception $e) {
    echo $e->getMessage();
}
