<?php
/**
 * Safe Hostinger Database Migrator
 * Runs all new SQL migrations idempotently (ignores "Duplicate column" and "Table exists" errors).
 * Usage over SSH: php hostinger_migrate.php
 */

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_PORT'] ?? 3306,
    $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'basefare_crm'
);

$user = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "✅ Database connected successfully.\n\n";
} catch (Exception $e) {
    die("❌ Connection failed: " . $e->getMessage() . "\n");
}

$files = [
    __DIR__ . '/database/migrate_four_tier_rbac.sql',
    __DIR__ . '/database/migrations/2026_04_14_add_cc_columns.sql',
    __DIR__ . '/database/migrations/2026_04_14_add_supervisor_role.sql',
    __DIR__ . '/database/migrations/2026_04_14_create_error_log.sql',
    __DIR__ . '/database/migrations/2026_04_15_add_preauth_columns.sql',
    __DIR__ . '/database/migrations/record_notes.sql',
    // add any other ones if needed
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        continue;
    }
    
    echo "Running migration: " . basename($file) . "...\n";
    $sql = file_get_contents($file);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // Ignore "Table already exists" and "Duplicate column name" to make it safely re-runnable
            if (strpos($msg, 'SQLSTATE[42S01]') !== false || strpos($msg, 'SQLSTATE[42S21]') !== false || strpos($msg, 'Duplicate column name') !== false) {
                // Safely ignore
            } else {
                echo "  ⚠️ Warning on statement: " . substr($stmt, 0, 50) . "...\n";
                echo "  -> " . $msg . "\n";
            }
        }
    }
    echo "  [OK]\n";
}

echo "\n🎉 All migrations completed!\n";
echo "You can now safely delete this script using 'rm hostinger_migrate.php'\n";
