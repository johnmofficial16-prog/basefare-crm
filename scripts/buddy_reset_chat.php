<?php
/**
 * Reset one person's buddy conversation — for a clean demo, not for routine use.
 *
 *   php scripts/buddy_reset_chat.php --user=you@base-fare.com
 *       ^ DRY RUN. Prints exactly what would go, changes nothing.
 *
 *   php scripts/buddy_reset_chat.php --user=you@base-fare.com --apply
 *   php scripts/buddy_reset_chat.php --user=you@base-fare.com --memory --apply
 *
 * Written 19 Aug 2026 so the client can meet Aisha with a clean panel rather
 * than scrolling through a session's worth of testing.
 *
 * DEFAULTS ARE THE CAUTIOUS ONES, deliberately:
 *   - Dry run unless --apply. A destructive script whose default is destruction
 *     is a script that will eventually be run by accident.
 *   - History only. Aisha still knows who they are — their name, what they told
 *     her, their goal. --memory is what makes her forget, and it is opt-in
 *     because it throws away things a person actually told her.
 *   - Nudges are left alone. They are her pending observations about real
 *     bookings, not chat clutter; deleting them would make her go quiet about
 *     work that still needs doing.
 *
 * SCOPE: one user, resolved by email. There is no --all and there will not be.
 *
 * WHAT EACH FLAG REMOVES
 *   (default)   buddy_conversations + buddy_messages + buddy_tool_calls
 *               for the chosen surface, and the greeting stamp so she greets
 *               again on the next page load instead of waiting out the
 *               10-minute cooldown.
 *   --memory    buddy_agent_facts (things she was told) and the learned
 *               display_name. She will re-introduce herself and start the
 *               get-to-know-you questions from scratch.
 *   --keep-greeting  leave the greeting stamp alone (she stays quiet until the
 *               normal cooldown expires).
 *
 * --surface=admin|agent|both   which conversation kind to clear (default: both).
 */

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Same clock the web app and every cron run on. Without this a CLI script sits
// in the server default (UTC) while created_at strings were written in IST —
// and ShiftService::businessDayBounds() then computes the WRONG business day,
// which is a quietly wrong answer rather than an error.
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

// ── args ────────────────────────────────────────────────────────────────────
$email        = null;
$surface      = 'both';
$apply        = false;
$wipeMemory   = false;
$keepGreeting = false;

foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--user='))    { $email   = trim(substr($a, 7)); }
    if (str_starts_with($a, '--surface=')) { $surface = trim(substr($a, 10)); }
    if ($a === '--apply')                  { $apply   = true; }
    if ($a === '--memory')                 { $wipeMemory = true; }
    if ($a === '--keep-greeting')          { $keepGreeting = true; }
}

if ($email === null || $email === '') {
    fwrite(STDERR, "Usage: php scripts/buddy_reset_chat.php --user=<email> [--surface=admin|agent|both] [--memory] [--keep-greeting] [--apply]\n");
    exit(1);
}
if (!in_array($surface, ['admin', 'agent', 'both'], true)) {
    fwrite(STDERR, "--surface must be admin, agent or both\n");
    exit(1);
}

$capsule = new Capsule;
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

$user = Capsule::table('users')->where('email', $email)->first(['id', 'name', 'email', 'role']);
if ($user === null) {
    fwrite(STDERR, "No user with email {$email}\n");
    exit(1);
}

$kinds = $surface === 'both' ? ['admin', 'agent'] : [$surface];
$convIds = Capsule::table('buddy_conversations')
    ->where('user_id', $user->id)
    ->whereIn('kind', $kinds)
    ->pluck('id')->all();

$msgCount  = $convIds ? Capsule::table('buddy_messages')->whereIn('conversation_id', $convIds)->count() : 0;
$toolCount = $convIds ? Capsule::table('buddy_tool_calls')->whereIn('conversation_id', $convIds)->count() : 0;
$factCount = Capsule::table('buddy_agent_facts')->where('user_id', $user->id)->count();
$settings  = Capsule::table('buddy_settings')->where('user_id', $user->id)->first(['display_name', 'extra_json']);
$nudgePend = Capsule::table('buddy_nudges')->where('user_id', $user->id)->where('status', 'pending')->count();

echo "\n";
echo "USER      {$user->name} <{$user->email}>  (id {$user->id}, role {$user->role})\n";
echo "SURFACE   " . implode(' + ', $kinds) . "\n";
echo "MODE      " . ($apply ? "APPLY — this will delete" : "DRY RUN — nothing will change") . "\n\n";

echo "WILL DELETE\n";
echo "  conversations     " . count($convIds) . "\n";
echo "  messages          {$msgCount}\n";
echo "  tool call audit   {$toolCount}\n";
if ($wipeMemory) {
    echo "  remembered facts  {$factCount}   (--memory)\n";
    echo "  learned name      " . ($settings->display_name !== null ? "\"{$settings->display_name}\"" : '(none)') . "   (--memory)\n";
}
echo "\nWILL KEEP\n";
if (!$wipeMemory) {
    echo "  remembered facts  {$factCount}   (pass --memory to clear)\n";
    echo "  learned name      " . ($settings->display_name !== null ? "\"{$settings->display_name}\"" : '(none)') . "\n";
}
echo "  pending nudges    {$nudgePend}   (real observations about real bookings — never touched)\n";
echo "  greeting stamp    " . ($keepGreeting ? "kept — she stays quiet until the cooldown expires" : "CLEARED — she greets on the next page load") . "\n\n";

if (!$apply) {
    echo "Dry run. Re-run with --apply to carry this out.\n";
    exit(0);
}

Capsule::connection()->transaction(function () use ($convIds, $user, $wipeMemory, $keepGreeting) {
    if ($convIds) {
        Capsule::table('buddy_messages')->whereIn('conversation_id', $convIds)->delete();
        Capsule::table('buddy_tool_calls')->whereIn('conversation_id', $convIds)->delete();
        Capsule::table('buddy_conversations')->whereIn('id', $convIds)->delete();
    }

    if ($wipeMemory) {
        Capsule::table('buddy_agent_facts')->where('user_id', $user->id)->delete();
        Capsule::table('buddy_settings')->where('user_id', $user->id)->update(['display_name' => null]);
    }

    if (!$keepGreeting) {
        // Surgical: only the two greeting keys. extra_json also carries the
        // agent's self-set goal and the feed pacing stamp, and a blanket NULL
        // here would silently throw away a goal someone chose themselves.
        $row   = Capsule::table('buddy_settings')->where('user_id', $user->id)->first(['extra_json']);
        $extra = json_decode((string) ($row->extra_json ?? ''), true);
        if (is_array($extra)) {
            unset($extra['last_greeted_at'], $extra['last_greeted_bday']);
            Capsule::table('buddy_settings')->where('user_id', $user->id)
                ->update(['extra_json' => json_encode($extra)]);
        }
    }
});

echo "Done. Aisha will greet {$user->name} fresh on the next page load.\n";
echo "Note: the browser also caches an 'arrived' marker per session — close the\n";
echo "tab (or run sessionStorage.removeItem('bwArrived')) so she treats it as a\n";
echo "new arrival.\n";
