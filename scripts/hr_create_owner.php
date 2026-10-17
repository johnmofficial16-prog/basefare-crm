<?php
/**
 * Trio HR: create the first owner login (run once, over SSH, after the migrations).
 *
 *   php scripts/hr_create_owner.php "Full Name" owner@example.com
 *
 * Prints a one-time set-up link (valid 7 days). Open it in a browser to choose
 * the password. Nothing is emailed. Refuses if an active owner already exists:
 * from then on owners add admins inside Trio HR (Settings → Admins).
 *
 * CLI only: the scripts/ folder is denied over HTTP by the root .htaccess.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

[$name, $email] = [trim((string) ($argv[1] ?? '')), strtolower(trim((string) ($argv[2] ?? '')))];
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/hr_create_owner.php \"Full Name\" owner@example.com\n");
    exit(1);
}

\App\Hr\Kernel::boot();
$db = \App\Hr\Kernel::hr();
if (!$db->getSchemaBuilder()->hasTable('hr_accounts')) {
    fwrite(STDERR, "The Trio HR tables aren't there yet. Run php hostinger_migrate.php first.\n");
    exit(1);
}
if ($db->table('hr_accounts')->where('role', 'owner')->where('status', 'active')->exists()) {
    fwrite(STDERR, "An owner already exists. Add more admins inside Trio HR: Settings, then Admins.\n");
    exit(1);
}
$existing = $db->table('hr_accounts')->where('email', $email)->first();
if ($existing && !($existing->role === 'owner' && $existing->status === 'invited')) {
    fwrite(STDERR, "That email already has a Trio HR account ({$existing->role}). Use another email.\n");
    exit(1);
}
$now = \App\Hr\Kernel::now();
$id = $existing ? (int) $existing->id : (int) $db->table('hr_accounts')->insertGetId([
    'employee_id' => null, 'display_name' => $name, 'email' => $email, 'role' => 'owner', 'status' => 'invited',
    'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
\App\Hr\Domain\Defaults::ensure();
$link = \App\Hr\Auth\Auth::firstOwnerLink($id);
echo "Owner account for {$name} <{$email}> is ready.\n";
echo "Open this link within 7 days to set the password (it works once):\n\n  {$link}\n\n";
