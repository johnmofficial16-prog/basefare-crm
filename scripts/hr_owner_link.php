<?php
/**
 * Trio HR: forgot your owner password? Prints a one-time link to set a new one
 * (valid 24 hours; a not-yet-set-up account gets its 7-day set-up link instead).
 * Run over SSH from the repo root:
 *
 *   php scripts/hr_owner_link.php            # John's owner login (the YOUR@EMAIL.COM one)
 *   php scripts/hr_owner_link.php --id=5     # any other Trio HR login, by number
 *
 * Without --id it lists the owner/admin logins if it can't tell which one is
 * meant. Nothing is emailed. The old password keeps working until the new one
 * is set. CLI only: scripts/ is denied over HTTP by the root .htaccess.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

\App\Hr\Kernel::boot();
$db = \App\Hr\Kernel::hr();

$id = null;
foreach ($argv as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $id = (int) $m[1];
    }
}
if ($id === null) {
    $id = (int) ($db->table('hr_accounts')->whereRaw('LOWER(email) = ?', ['your@email.com'])->value('id') ?? 0) ?: null;
}
if ($id === null) {
    echo "Couldn't tell which login you mean. Trio HR owner and admin logins:\n";
    foreach ($db->table('hr_accounts')->whereIn('role', ['owner', 'admin'])->orderBy('id')->get(['id', 'display_name', 'email', 'role', 'status']) as $a) {
        echo "  #{$a->id}  {$a->display_name}  <{$a->email}>  ({$a->role}, {$a->status})\n";
    }
    echo "\nRun again with the number, e.g.: php scripts/hr_owner_link.php --id=1\n";
    exit(1);
}

$acc = $db->table('hr_accounts')->where('id', $id)->first();
if (!$acc) {
    fwrite(STDERR, "There is no Trio HR login #{$id}.\n");
    exit(1);
}
try {
    $out = \App\Hr\Auth\Auth::handoverLink($id, $id);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Trio HR login: {$acc->display_name}\n";
echo "Sign-in email:  {$acc->email}\n\n";
echo "Open this link to " . ($out['setup'] ? 'set your password' : 'choose a new password') . " (works once, valid {$out['valid']}):\n\n  {$out['link']}\n\n";
echo "Then sign in at " . rtrim((string) ($_ENV['HR_APP_URL'] ?? 'https://hr.base-fare.com'), '/') . " with that email and the new password.\n";
