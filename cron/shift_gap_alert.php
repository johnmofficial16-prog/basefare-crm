<?php
/**
 * Phase 2 — Daily Missing Shift Alert Cron
 * 
 * Run daily at ~8 PM via Hostinger cron or Windows Task Scheduler.
 * Checks if any active agent has no shift scheduled for tomorrow.
 * Logs warnings so admin can fill gaps before the next workday.
 * 
 * Usage: php cron/shift_gap_alert.php
 */

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Eloquent
$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? 'localhost',
    'database'  => $_ENV['DB_DATABASE'] ?? 'basefare_crm',
    'username'  => $_ENV['DB_USERNAME'] ?? 'root',
    'password'  => $_ENV['DB_PASSWORD'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayOfWeek = date('l', strtotime($tomorrow));

echo "[Shift Gap Alert] Checking for missing shifts on {$tomorrow} ({$dayOfWeek})\n";

// Get all active agents
$agents = \App\Models\User::where('role', \App\Models\User::ROLE_AGENT)
    ->where('status', \App\Models\User::STATUS_ACTIVE)
    ->whereNull('deleted_at')
    ->get();

$gaps = [];

foreach ($agents as $agent) {
    $hasShift = \Illuminate\Database\Capsule\Manager::table('shift_schedules')
        ->where('agent_id', $agent->id)
        ->where('shift_date', $tomorrow)
        ->exists();

    if (!$hasShift) {
        $gaps[] = $agent;
        echo "  ⚠ {$agent->name} (ID:{$agent->id}) — NO SHIFT for {$tomorrow}\n";
    }
}

if (empty($gaps)) {
    echo "  ✓ All agents have shifts scheduled for {$tomorrow}.\n";
} else {
    echo "\n  TOTAL GAPS: " . count($gaps) . " agent(s) without shifts.\n";
    
    // Log to activity_log for admin visibility
    foreach ($gaps as $agent) {
        \Illuminate\Database\Capsule\Manager::table('activity_log')->insert([
            'user_id'     => 0, // system
            'action'      => 'shift_gap_alert',
            'entity_type' => 'shift_schedules',
            'entity_id'   => $agent->id,
            'details'     => json_encode(['agent_name' => $agent->name, 'missing_date' => $tomorrow]),
            'ip_address'  => '127.0.0.1',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

echo "[Shift Gap Alert] Done.\n";
