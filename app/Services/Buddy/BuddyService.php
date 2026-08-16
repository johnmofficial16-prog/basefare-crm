<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * BuddyService — orchestration for every buddy surface.
 *
 * A chat turn = quota gate → conversation → history → scrub input → Gemini
 * tool loop → persist both sides → reply. Stateless per request (shared
 * hosting; no daemons). Fail-soft everywhere: if the AI layer is down the
 * caller gets a deterministic answer, never a broken page — same contract as
 * GeminiService.
 *
 * P0 ships the MAINTENANCE surface only. Agent/admin surfaces reuse this
 * class with their own registry factories + personas (P1/P2).
 */
class BuddyService
{
    // Maintenance surface quotas (admin/dev audience — generous but bounded).
    private const MAINT_DAILY_LIMIT      = 200;
    private const MAINT_PER_MINUTE_LIMIT = 10;
    private const MAX_INPUT_CHARS        = 2000;

    /** Reuse a conversation if its last message is younger than this. */
    private const CONVERSATION_REUSE_HOURS = 24;

    private BuddyGeminiClient $client;

    public function __construct(?BuddyGeminiClient $client = null)
    {
        $this->client = $client ?? new BuddyGeminiClient();
    }

    // =========================================================================
    // MAINTENANCE CHAT
    // =========================================================================

    /**
     * @return array {success: bool, reply: string, ai: bool, error?: string}
     *         'ai' is false when the reply came from the deterministic fallback.
     */
    public function maintenanceChat(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => 'Empty message.'];
        }
        if (mb_strlen($message) > self::MAX_INPUT_CHARS) {
            return ['success' => false, 'reply' => '', 'ai' => false,
                    'error' => 'Message too long (max ' . self::MAX_INPUT_CHARS . ' characters).'];
        }

        $quota = $this->quotaCheck($userId, 'maintenance');
        if ($quota !== null) {
            return ['success' => false, 'reply' => '', 'ai' => false, 'error' => $quota];
        }

        // Scrub the input too — an admin might paste a log line containing a
        // customer email. Belt and braces before anything reaches Google.
        [$message] = BuddyPromptBuilder::scrub($message);

        $convId  = $this->openConversation($userId, 'maintenance');
        $history = $this->loadHistory($convId);

        $this->storeMessage($convId, 'user', $message);

        $contents   = BuddyPromptBuilder::buildContents($history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $registry = MaintenanceTools::registry($userId);
        $registry->setConversation($convId);

        $result = $this->client->chat(self::maintenancePersona(), $contents, $registry);

        if ($result['success']) {
            $this->storeMessage($convId, 'model', $result['text']);
            return ['success' => true, 'reply' => $result['text'], 'ai' => true];
        }

        // AI down (no key, quota, network) → deterministic digest instead of an
        // error page. The buddy is degraded, not dead — and it says so honestly.
        ErrorLogService::log('warning', '[buddy] maintenance AI turn failed: ' . ($result['error'] ?? '?'));
        $fallback = "The AI layer is unavailable right now (" . ($result['error'] ?? 'unknown error') . ").\n"
                  . "Here is the deterministic system digest instead:\n\n"
                  . self::renderDigestText(self::digest());
        $this->storeMessage($convId, 'model', $fallback);
        return ['success' => true, 'reply' => $fallback, 'ai' => false];
    }

    // =========================================================================
    // DETERMINISTIC DIGEST (status cards + AI fallback — no Gemini involved)
    // =========================================================================

    public static function digest(): array
    {
        // Reuse the exact tool handlers via a throwaway registry so the cards
        // and the AI always see identical numbers. userId 0 = system.
        $registry = MaintenanceTools::registry(0);
        return [
            'errors'     => $registry->execute('get_error_summary', ['hours' => 24]),
            'backups'    => $registry->execute('get_backup_status', []),
            'migrations' => $registry->execute('get_migration_status', []),
            'crons'      => $registry->execute('get_cron_health', []),
            'pulse'      => $registry->execute('get_system_pulse', []),
        ];
    }

    private static function renderDigestText(array $d): string
    {
        $lines   = [];
        $lines[] = 'Errors (24h): total ' . ($d['errors']['total'] ?? '?')
                 . ' — ' . json_encode($d['errors']['by_severity'] ?? []);
        $lines[] = 'Backups: ' . ($d['backups']['verdict'] ?? '?');
        $pending = $d['migrations']['pending_on_disk'] ?? [];
        $lines[] = 'Migrations pending: ' . ($pending === [] ? 'none' : implode(', ', $pending));
        foreach (($d['crons']['jobs'] ?? []) as $j) {
            $lines[] = sprintf('%s: %s%s',
                $j['job'],
                $j['last_trace'] !== null ? "last trace {$j['age_hours']}h ago" : 'no trace yet',
                !empty($j['overdue']) ? ' — OVERDUE' : ''
            );
        }
        return implode("\n", $lines);
    }

    // =========================================================================
    // PERSONA
    // =========================================================================

    private static function maintenancePersona(): string
    {
        return <<<PROMPT
You are the Maintenance Buddy for the Base Fare CRM — a concise, factual site
reliability assistant for the system's admin and developer. Audience: technical
operators, not customers.

RULES (non-negotiable):
- Numbers, filenames, and timestamps come ONLY from tool results in this
  conversation. Never estimate, never invent, never fill gaps from memory.
- Start broad (get_error_summary / get_system_pulse), then drill down with the
  other tools when the question needs it.
- When something looks wrong (overdue cron, stale backup, error spike), say so
  plainly, rank by operational risk, and suggest the next diagnostic step an
  operator would take. You have NO write access — you observe and advise.
- If tools return nothing unusual, say the system looks healthy — do not
  manufacture concerns.
- Scope: this CRM's health only. Politely refuse anything else (customer data,
  HR questions, general chit-chat beyond a greeting) in one short sentence.
- Style: tight and technical. Short paragraphs or bullets. No emoji, no
  pep-talk, no filler.
PROMPT;
    }

    // =========================================================================
    // PERSISTENCE + QUOTAS
    // =========================================================================

    private function openConversation(int $userId, string $kind): int
    {
        $recent = DB::table('buddy_conversations')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where('last_message_at', '>=', date('Y-m-d H:i:s', time() - self::CONVERSATION_REUSE_HOURS * 3600))
            ->orderByDesc('id')
            ->value('id');

        if ($recent !== null) {
            return (int) $recent;
        }

        return (int) DB::table('buddy_conversations')->insertGetId([
            'user_id'         => $userId,
            'kind'            => $kind,
            'title'           => date('M j') . ' ' . $kind . ' chat',
            'created_at'      => date('Y-m-d H:i:s'),
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<array{role: string, content: string}> oldest→newest */
    private function loadHistory(int $convId): array
    {
        return DB::table('buddy_messages')
            ->where('conversation_id', $convId)
            ->whereIn('role', ['user', 'model'])
            ->orderByDesc('id')
            ->limit(BuddyPromptBuilder::HISTORY_MAX_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    private function storeMessage(int $convId, string $role, string $content): void
    {
        try {
            DB::table('buddy_messages')->insert([
                'conversation_id' => $convId,
                'role'            => $role,
                'content'         => mb_substr($content, 0, 60000),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            DB::table('buddy_conversations')->where('id', $convId)
                ->update(['last_message_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            ErrorLogService::log('warning', '[buddy] message persist failed: ' . $e->getMessage());
        }
    }

    /** @return string|null Error message when over quota, null when fine. */
    private function quotaCheck(int $userId, string $kind): ?string
    {
        $base = DB::table('buddy_messages')
            ->join('buddy_conversations', 'buddy_conversations.id', '=', 'buddy_messages.conversation_id')
            ->where('buddy_conversations.user_id', $userId)
            ->where('buddy_conversations.kind', $kind)
            ->where('buddy_messages.role', 'user');

        $today = (clone $base)->where('buddy_messages.created_at', '>=', date('Y-m-d 00:00:00'))->count();
        if ($today >= self::MAINT_DAILY_LIMIT) {
            return 'Daily message limit reached (' . self::MAINT_DAILY_LIMIT . '). Resets at midnight.';
        }

        $lastMinute = (clone $base)->where('buddy_messages.created_at', '>=', date('Y-m-d H:i:s', time() - 60))->count();
        if ($lastMinute >= self::MAINT_PER_MINUTE_LIMIT) {
            return 'Slow down — too many messages this minute.';
        }

        return null;
    }
}
