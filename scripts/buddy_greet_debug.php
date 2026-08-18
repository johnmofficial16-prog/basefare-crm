<?php
/**
 * Why didn't she greet? — the server's side of the story, for one person.
 *
 *   php scripts/buddy_greet_debug.php --user=admin@base-fare.com
 *
 * "She isn't greeting me" has several very different causes that all look
 * identical from the browser, because the widget swallows greeting errors on
 * purpose (a greeting is a bonus, never an error the user sees). This prints
 * the facts that tell them apart, and never guesses:
 *
 *   1. She ALREADY greeted them.  Once per browser session, with a 10-minute
 *      cooldown and a once-per-business-day backstop. Opening a second tab, or
 *      reloading, is supposed to be silent. This is the most common answer and
 *      it is not a bug.
 *   2. The request never reached PHP.  The host WAF 403s POSTs from IPs that
 *      are not allowlisted, before any of our code runs — so there is no
 *      conversation, no message, no stamp, no error log. Zero of everything is
 *      the fingerprint. See memory/hosting-waf-constraint.md.
 *   3. The buddy is switched off.
 *   4. She greeted, but silently — voice unconfigured or muted in the browser.
 *
 * Read-only. Touches nothing.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Services\Buddy\BuddyService;
use App\Services\Buddy\TtsService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Same clock the web app and every cron run on. Without this a CLI script sits
// in the server default (UTC) while created_at strings were written in IST —
// and ShiftService::businessDayBounds() then computes the WRONG business day,
// which is a quietly wrong answer rather than an error.
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$email = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--user=')) {
        $email = trim(substr($a, 7));
    }
}
if ($email === null || $email === '') {
    fwrite(STDERR, "Usage: php scripts/buddy_greet_debug.php --user=<email>\n");
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

$now   = time();
$extra = json_decode((string) Capsule::table('buddy_settings')->where('user_id', $user->id)->value('extra_json'), true);
$extra = is_array($extra) ? $extra : [];

[$dayStart] = \App\Services\ShiftService::businessDayBounds();
$dayKey     = substr((string) $dayStart, 0, 10);

$lastAt   = (int) ($extra['last_greeted_at'] ?? 0);
$lastBday = $extra['last_greeted_bday'] ?? null;
$ago      = $lastAt > 0 ? $now - $lastAt : null;

$convs = Capsule::table('buddy_conversations')->where('user_id', $user->id)
    ->orderByDesc('id')->get(['id', 'kind', 'created_at', 'last_message_at']);
$convIds = $convs->pluck('id')->all();

$msgTotal = $convIds ? Capsule::table('buddy_messages')->whereIn('conversation_id', $convIds)->count() : 0;
$lastMsg  = $convIds
    ? Capsule::table('buddy_messages')->whereIn('conversation_id', $convIds)
        ->where('role', 'model')->orderByDesc('id')->first(['content', 'created_at'])
    : null;

echo "\n";
echo "USER            {$user->name} <{$user->email}>  (id {$user->id}, role {$user->role})\n";
echo "SERVER TIME     " . date('Y-m-d H:i:s') . "\n";
echo "BUSINESS DAY    {$dayKey}  (rolls at 18:00)\n";
echo "BUDDY ENABLED   " . (BuddyService::enabled() ? 'yes' : 'NO — kill switch is on') . "\n";
echo "TTS CONFIGURED  " . (TtsService::isConfigured() ? 'yes' : 'no — she would greet in text only') . "\n\n";

echo "GREETING STAMPS\n";
echo "  last_greeted_at    " . ($lastAt > 0
        ? date('Y-m-d H:i:s', $lastAt) . '   (' . (int) round($ago / 60) . ' min ago)'
        : '(never)') . "\n";
echo "  last_greeted_bday  " . ($lastBday ?? '(never)')
        . ($lastBday === $dayKey ? '   ← today, already claimed' : '') . "\n\n";

echo "CONVERSATIONS   " . count($convIds) . "   MESSAGES  {$msgTotal}\n";
foreach ($convs as $c) {
    echo "  #{$c->id}  {$c->kind}  created {$c->created_at}  last {$c->last_message_at}\n";
}
if ($lastMsg !== null) {
    echo "  last thing she said ({$lastMsg->created_at}):\n";
    echo "    \"" . mb_substr(preg_replace('/\s+/', ' ', $lastMsg->content), 0, 150) . "\"\n";
}
echo "\n";

// ── what would happen right now ─────────────────────────────────────────────
// Read the live value, including any BUDDY_GREET_COOLDOWN override, rather
// than hard-coding 600 and reporting a gate that is not the one in force.
$cdRef = new ReflectionMethod(BuddyService::class, 'greetCooldown');
$cdRef->setAccessible(true);
$cooldown = (int) $cdRef->invoke(null);
$freshOk   = $lastAt === 0 || $ago >= $cooldown;
$dayOk     = $lastBday !== $dayKey;

echo "IF THEY OPENED THE APP RIGHT NOW\n";
echo "  new browser session (closed + reopened):  "
   . ($freshOk ? "SHE GREETS" : "silent — cooldown, " . (int) ceil(($cooldown - $ago) / 60) . " min left")
   . "\n";
echo "  same session (reload / navigate):         "
   . ($dayOk ? "SHE GREETS" : "silent — already greeted this business day")
   . "\n\n";

echo "READING THIS\n";
if ($msgTotal === 0 && $lastAt === 0) {
    echo "  Nothing has EVER happened for this user — no message, no stamp. That is the\n";
    echo "  fingerprint of the request not reaching PHP at all: the host WAF 403s POSTs\n";
    echo "  from IPs that are not allowlisted, and the widget swallows the error.\n";
    echo "  Check whether their IP is allowlisted before looking at anything else.\n";
} elseif (!$freshOk || !$dayOk) {
    echo "  She HAS greeted them (see the stamp above). Silence now is the spam guard\n";
    echo "  working as designed, not a fault. To see it again: close the browser fully,\n";
    echo "  wait out the cooldown, or clear the stamp with\n";
    echo "  scripts/buddy_reset_chat.php --user={$email} --apply\n";
} else {
    echo "  Server-side she is ready to greet on the next open. If they still hear and\n";
    echo "  see nothing, the problem is in the browser, not here: check the console on\n";
    echo "  their page for a failed POST to /buddy/admin/greeting (or /buddy/greeting).\n";
}
echo "\n";
