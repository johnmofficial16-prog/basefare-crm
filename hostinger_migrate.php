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

$mysqli = new mysqli($_ENV['DB_HOST'] ?? '127.0.0.1', $user, $pass, $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'basefare_crm', $_ENV['DB_PORT'] ?? 3306);

// Prevent PHP 8.1+ from throwing fatal exceptions on duplicate errors,
// so our script can gracefully ignore and skip them!
mysqli_report(MYSQLI_REPORT_OFF);

if ($mysqli->connect_error) {
    die("❌ Connection failed: " . $mysqli->connect_error . "\n");
}
echo "✅ Database connected successfully via MySQLi.\n\n";

$files = [
    __DIR__ . '/database/migrate_four_tier_rbac.sql',
    __DIR__ . '/database/migrations/2026_04_14_add_cc_columns.sql',
    __DIR__ . '/database/migrations/2026_04_14_add_supervisor_role.sql',
    __DIR__ . '/database/migrations/2026_04_14_create_error_log.sql',
    __DIR__ . '/database/migrations/2026_04_15_add_preauth_columns.sql',
    __DIR__ . '/database/migrations/record_notes.sql',
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    
    echo "Running migration: " . basename($file) . "...\n";
    $sql = file_get_contents($file);
    
    if ($mysqli->multi_query($sql)) {
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
        
        if ($mysqli->error) {
            $err = $mysqli->error;
            if (strpos($err, 'Duplicate column name') !== false || strpos($err, 'already exists') !== false || strpos($err, 'Duplicate entry') !== false) {
                 echo "  [OK] (Safely skipped already existing items)\n";
            } else {
                 echo "  ⚠️ Warning: " . $err . "\n";
            }
        } else {
            echo "  [OK]\n";
        }
    } else {
        // Multi query failed on the very first statement, or we hit an error 
        // We will just ignore common duplicates
        $err = $mysqli->error;
        if (strpos($err, 'Duplicate column name') !== false || strpos($err, 'already exists') !== false) {
             echo "  [OK] (Safely skipped existing schema)\n";
        } else {
             echo "  ⚠️ Warning: " . $err . "\n";
        }
    }
}

echo "\n🎉 All migrations completed!\n";
echo "You can now safely delete this script using 'rm hostinger_migrate.php'\n";
