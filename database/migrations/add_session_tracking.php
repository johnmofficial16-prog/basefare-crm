<?php
/**
 * Migration: Add active_session_id column to users table
 * for concurrent login detection (agent/supervisor only).
 *
 * Run once: php database/migrations/add_session_tracking.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
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
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$schema = $capsule::schema();

// Add active_session_id for concurrent login detection
if (!$schema->hasColumn('users', 'active_session_id')) {
    $schema->table('users', function ($table) {
        $table->string('active_session_id', 128)->nullable()->after('status');
    });
    echo "✅ Added 'active_session_id' column to users table.\n";
} else {
    echo "ℹ️  'active_session_id' column already exists.\n";
}

// Add last_activity_at for inactivity timeout tracking
if (!$schema->hasColumn('users', 'last_activity_at')) {
    $schema->table('users', function ($table) {
        $table->datetime('last_activity_at')->nullable()->after('active_session_id');
    });
    echo "✅ Added 'last_activity_at' column to users table.\n";
} else {
    echo "ℹ️  'last_activity_at' column already exists.\n";
}

echo "\n🔒 Migration complete.\n";
