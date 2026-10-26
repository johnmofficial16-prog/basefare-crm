<?php
/**
 * Create (or update) the developer account — SSH only; scripts/ is refused over the web.
 *
 *   php scripts/create_dev_user.php dev@base-fare.com "John (Dev)"
 *
 * The account is an admin with users.is_dev = 1: the /dev view switcher, no
 * office-network limit, Trio HR as owner from the CRM. It signs in with its
 * password AND an authenticator-app code (set up at the first sign-in), and it
 * is left out of staff lists, the survey, KRA and HR's people.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql', 'host' => $_ENV['DB_HOST'], 'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_DATABASE'], 'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();

$email = strtolower(trim($argv[1] ?? ''));
$name  = trim($argv[2] ?? 'Developer');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create_dev_user.php <email> \"<name>\"\n");
    exit(1);
}

$ask = function (string $prompt): string {
    echo $prompt;
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($hide) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string) fgets(STDIN), "\r\n");
    if ($hide) {
        shell_exec('stty echo');
    }
    echo "\n";
    return $v;
};
$pass = $ask("Password for {$email}: ");
if (strlen($pass) < 12) {
    fwrite(STDERR, "Use at least 12 characters. Nothing was changed.\n");
    exit(1);
}
if ($ask('Type it again: ') !== $pass) {
    fwrite(STDERR, "The two didn't match. Nothing was changed.\n");
    exit(1);
}

$row = [
    'name' => $name, 'password_hash' => password_hash($pass, PASSWORD_BCRYPT), 'role' => 'admin',
    'status' => 'active', 'is_dev' => 1, 'reports_to_id' => null, 'updated_at' => date('Y-m-d H:i:s'),
];
$existing = Capsule::table('users')->where('email', $email)->first();
if ($existing) {
    Capsule::table('users')->where('id', $existing->id)->update($row + ['deleted_at' => null]);
    $id = (int) $existing->id;
    echo "Updated {$email} as the developer account.\n";
} else {
    $id = (int) Capsule::table('users')->insertGetId($row + ['email' => $email, 'grace_period_mins' => 30, 'created_at' => date('Y-m-d H:i:s')]);
    echo "Created {$email} as the developer account.\n";
}
Capsule::table('activity_log')->insert([
    'user_id' => $id, 'action' => 'dev_account_set', 'entity_type' => 'users', 'entity_id' => $id,
    'details' => json_encode(['via' => 'scripts/create_dev_user.php']), 'ip_address' => null, 'created_at' => date('Y-m-d H:i:s'),
]);
echo "Sign in at the CRM with it: after the password you'll set up the authenticator app (QR code).\n";
