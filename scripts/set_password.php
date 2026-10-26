<?php
/**
 * Set a CRM user's own password from the server's command line (SSH only —
 * scripts/ is refused over the web by the root .htaccess).
 *
 *   php scripts/set_password.php admin@base-fare.com
 *
 * Asks for the new password twice without showing it, says whether the old one
 * was a well-known default, and stores only the scrambled hash. Added in the
 * security audit of 26 Oct 2026, when the shared ADMIN_PASSWORD_OVERRIDE was
 * removed: the admin account's own password is now the only way in.
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

// Well-known defaults, including the ones the old seed/test scripts used.
$weakList = ['password', 'admin', 'admin123', '123456', '12345678', 'password123', 'basefare', 'basefare123', 'test1234', 'welcome123'];

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/set_password.php <email>\n       php scripts/set_password.php --check-all\n");
    exit(1);
}

// --check-all: list every active account whose password is a well-known default,
// plus every admin account (there should be no surprises in that list).
if ($email === '--check-all') {
    $found = 0;
    foreach (Capsule::table('users')->whereNull('deleted_at')->where('status', 'active')->get(['id', 'name', 'email', 'role', 'password_hash']) as $u) {
        foreach ($weakList as $weak) {
            if ($u->password_hash && password_verify($weak, $u->password_hash)) {
                echo "WEAK  {$u->email} ({$u->role}) uses a well-known default password\n";
                $found++;
                break;
            }
        }
    }
    echo $found ? "\n{$found} account(s) to fix: php scripts/set_password.php <email>\n" : "No account uses a well-known default password.\n";
    echo "\nAdmin accounts (should only be ones you know):\n";
    foreach (Capsule::table('users')->whereNull('deleted_at')->where('role', 'admin')->get(['email', 'status']) as $a) {
        echo "  {$a->email} ({$a->status})\n";
    }
    exit(0);
}
$user = Capsule::table('users')->where('email', $email)->whereNull('deleted_at')->first();
if (!$user) {
    fwrite(STDERR, "No active CRM user with that email.\n");
    exit(1);
}

// Was the old password a well-known default? (Checked against the stored hash only.)
foreach ($weakList as $weak) {
    if ($user->password_hash && password_verify($weak, $user->password_hash)) {
        echo "WARNING: the current password for {$email} is a well-known default. Change it now.\n";
        break;
    }
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

$new = $ask("New password for {$user->name} ({$email}): ");
if (strlen($new) < 12) {
    fwrite(STDERR, "Use at least 12 characters (a short sentence works well). Nothing was changed.\n");
    exit(1);
}
if ($ask('Type it again: ') !== $new) {
    fwrite(STDERR, "The two didn't match. Nothing was changed.\n");
    exit(1);
}

$update = ['password_hash' => password_hash($new, PASSWORD_BCRYPT)];
if (Capsule::schema()->hasColumn('users', 'must_change_password')) {
    $update['must_change_password'] = 0;
}
Capsule::table('users')->where('id', $user->id)->update($update);
Capsule::table('activity_log')->insert([
    'user_id' => $user->id, 'action' => 'password_set_cli', 'entity_type' => 'users', 'entity_id' => $user->id,
    'details' => json_encode(['via' => 'scripts/set_password.php']), 'ip_address' => null, 'created_at' => date('Y-m-d H:i:s'),
]);
echo "Done. {$email} now signs in with the new password.\n";
