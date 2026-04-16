<?php
/**
 * Run record_notes migration — run once then delete.
 * Usage: php run_notes_migration.php
 */
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_PORT'] ?? 3306,
    $_ENV['DB_NAME'] ?? 'basefare_crm'
);

$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = file_get_contents(__DIR__ . '/database/migrations/record_notes.sql');
$pdo->exec($sql);

echo "✅ record_notes table created successfully!" . PHP_EOL;
echo "You can now delete run_notes_migration.php" . PHP_EOL;
