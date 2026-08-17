<?php
/**
 * Buddy Memory Consolidator (plan P3 — the Jarvis transcript-consolidator
 * pattern in PHP). Run WEEKLY via hPanel cron, e.g. Sundays 04:00:
 *
 *   php /home/u501549865/domains/base-fare.com/public_html/crm/cron/buddy_consolidate.php
 *
 * For each agent with meaningful buddy conversation in the last 7 days, ask
 * Gemini to extract 0–3 DURABLE personal facts (preferred name, goals,
 * motivators, standing commitments) that are not already remembered, and save
 * them to buddy_agent_facts (source=consolidator). This is how the buddy's
 * long-term memory grows beyond what remember_fact caught in the moment.
 *
 * Deliberately ADDITIVE-ONLY: transcripts are never deleted or modified —
 * buddy_messages doubles as the audit trail the Super Buddy reads. The 30-fact
 * cap per agent still applies; when full, no new facts are written.
 *
 * Fail-soft: any per-agent failure logs and continues; Gemini down = no-op.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
use App\Services\Buddy\BuddyPromptBuilder;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql', 'host' => $_ENV['DB_HOST'], 'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$dryRun = in_array('--dry-run', $argv, true);
$since  = date('Y-m-d H:i:s', time() - 7 * 86400);

echo '[' . date('Y-m-d H:i:s') . '] Buddy consolidator starting' . ($dryRun ? ' (DRY RUN)' : '') . "...\n";

// Agents with enough recent agent-surface conversation to be worth a pass.
$candidates = Capsule::table('buddy_messages AS m')
    ->join('buddy_conversations AS c', 'c.id', '=', 'm.conversation_id')
    ->where('c.kind', 'agent')
    ->where('m.role', 'user')                    // the AGENT actually said things
    ->where('m.created_at', '>=', $since)
    ->selectRaw('c.user_id, COUNT(*) AS n')
    ->groupBy('c.user_id')
    ->having('n', '>=', 4)
    ->pluck('n', 'user_id');

echo count($candidates) . " agent(s) with enough recent chat.\n";

$saved = 0;
foreach ($candidates as $userId => $msgCount) {
    try {
        $existing = Capsule::table('buddy_agent_facts')
            ->where('user_id', $userId)->where('active', 1)->pluck('fact')->all();
        if (count($existing) >= 30) {
            echo "  user {$userId}: memory full (30) — skipped\n";
            continue;
        }

        $transcript = Capsule::table('buddy_messages AS m')
            ->join('buddy_conversations AS c', 'c.id', '=', 'm.conversation_id')
            ->where('c.user_id', $userId)->where('c.kind', 'agent')
            ->where('m.created_at', '>=', $since)
            ->whereIn('m.role', ['user', 'model'])
            ->orderBy('m.id')->limit(80)
            ->get(['m.role', 'm.content'])
            ->map(fn($m) => ($m->role === 'user' ? 'AGENT: ' : 'BUDDY: ') . mb_substr($m->content, 0, 240))
            ->implode("\n");

        // Transcripts were scrubbed on write; scrub again anyway (choke point).
        [$transcript] = BuddyPromptBuilder::scrub($transcript);

        $system = "You extract DURABLE personal facts about a sales agent from their chat "
                . "with their work buddy. Durable = still true months from now: preferred "
                . "name, goals, motivators, standing commitments, strong preferences. NOT "
                . "durable: one-off numbers, moods, individual bookings, anything about "
                . "customers. Reply with ONLY a JSON array of 0-3 short fact strings "
                . "(max 200 chars each). Do NOT repeat anything from KNOWN FACTS. Reply [] "
                . "if nothing new and durable was said.";
        $user = "KNOWN FACTS:\n" . ($existing ? '- ' . implode("\n- ", $existing) : '(none)')
              . "\n\nTRANSCRIPT (last 7 days):\n" . $transcript;

        // Plain generation call (no tools) via the existing email-AI client.
        $gemini = new \App\Services\GeminiService();
        if (!$gemini->isConfigured()) {
            echo "  Gemini not configured — stopping.\n";
            break;
        }
        $ref = new ReflectionMethod($gemini, 'generate');
        $ref->setAccessible(true);
        $res = $ref->invoke($gemini, $system, $user, true, 400);
        if (!$res['success']) {
            echo "  user {$userId}: AI call failed ({$res['error']}) — skipped\n";
            continue;
        }

        $facts = json_decode(trim($res['text']), true);
        if (!is_array($facts)) {
            echo "  user {$userId}: unparseable reply — skipped\n";
            continue;
        }

        foreach (array_slice($facts, 0, 3) as $fact) {
            $fact = trim((string) $fact);
            if ($fact === '' || mb_strlen($fact) > 200 || in_array($fact, $existing, true)) {
                continue;
            }
            [$fact] = BuddyPromptBuilder::scrub($fact);
            echo "  user {$userId}: + {$fact}\n";
            if (!$dryRun) {
                Capsule::table('buddy_agent_facts')->insert([
                    'user_id'    => $userId,
                    'fact'       => $fact,
                    'source'     => 'consolidator',
                    'active'     => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $saved++;
        }
    } catch (\Throwable $e) {
        error_log('[buddy_consolidate] user ' . $userId . ' failed: ' . $e->getMessage());
        echo "  user {$userId}: error — " . $e->getMessage() . "\n";
    }
    sleep(2);   // Tier-0 friendliness
}

echo "Facts " . ($dryRun ? 'found' : 'saved') . ": {$saved}\n";

// Heartbeat on every real run, even one that saves nothing — see the note in
// buddy_triggers.php. A dry run is a human poking it, not the schedule, so it
// deliberately leaves no trace.
if (!$dryRun) {
    try {
        Capsule::table('activity_log')->insert([
            'user_id'     => null,
            'action'      => 'buddy_consolidate_ran',
            'entity_type' => 'buddy_agent_facts',
            'entity_id'   => null,
            'details'     => json_encode(['facts_saved' => $saved]),
            'ip_address'  => null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[buddy_consolidate] heartbeat failed: ' . $e->getMessage());
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
exit(0);
