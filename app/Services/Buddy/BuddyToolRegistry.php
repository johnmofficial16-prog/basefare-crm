<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * BuddyToolRegistry — the security wall of the buddy system.
 *
 * THE core invariant (AI_BUDDY_PLAN.md §1, Wall 1): the model can only call
 * functions that exist in ITS registry, and every handler has the acting user
 * bound at construction time. No tool takes a target-user parameter from the
 * model, so a jailbreak can change the buddy's tone but cannot widen its reach
 * — the capability simply is not there.
 *
 * Registries are built per-request by static factories (one per buddy surface,
 * e.g. MaintenanceTools::registry()). The registry is data + dispatch only:
 *  - declarations() feeds Gemini's functionDeclarations,
 *  - execute() dispatches with validation, timing, auditing and fail-soft.
 *
 * Every execution is recorded in buddy_tool_calls — the "what did the AI
 * actually look at" audit trail.
 */
class BuddyToolRegistry
{
    /** @var array<string, array{declaration: array, handler: callable}> */
    private array $tools = [];

    private int $userId;
    private ?int $conversationId = null;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /** Bind the conversation for audit rows (set once the conversation exists). */
    public function setConversation(int $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    /**
     * @param string   $name        Tool name (snake_case, [a-z0-9_]).
     * @param string   $description What the model reads to decide when to call it.
     * @param array    $parameters  JSON-schema for args (Gemini OpenAPI subset).
     *                              Empty array = no parameters.
     * @param callable $handler     fn(array $args): array — returns ONLY
     *                              whitelisted, PII-free data. The acting user is
     *                              captured in the closure, never passed by the model.
     */
    public function register(string $name, string $description, array $parameters, callable $handler): void
    {
        $declaration = [
            'name'        => $name,
            'description' => $description,
        ];
        if ($parameters !== []) {
            $declaration['parameters'] = $parameters;
        }
        $this->tools[$name] = ['declaration' => $declaration, 'handler' => $handler];
    }

    /** Gemini functionDeclarations payload. */
    public function declarations(): array
    {
        return array_values(array_map(fn($t) => $t['declaration'], $this->tools));
    }

    /** @return string[] registered tool names (for tests / red-team assertions) */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Execute a tool call requested by the model. Fail-soft: any handler error
     * becomes ['error' => ...] fed back to the model — never an exception that
     * kills the chat turn.
     */
    public function execute(string $name, array $args): array
    {
        if (!isset($this->tools[$name])) {
            // Model hallucinated a tool. Say so; do not guess.
            return ['error' => "Unknown tool '{$name}'. Use only the tools provided."];
        }

        $t0 = microtime(true);
        try {
            $result = ($this->tools[$name]['handler'])($args);
            if (!is_array($result)) {
                $result = ['result' => (string) $result];
            }
        } catch (Throwable $e) {
            ErrorLogService::log('warning', "[buddy] tool {$name} failed: " . $e->getMessage());
            $result = ['error' => 'The tool failed to run. Report this to the developer rather than retrying.'];
        }
        $durationMs = (int) round((microtime(true) - $t0) * 1000);

        $this->audit($name, $args, $result, $durationMs);

        return $result;
    }

    private function audit(string $name, array $args, array $result, int $durationMs): void
    {
        try {
            // A count that is honest for both list-shaped and scalar results.
            $rows = null;
            foreach ($result as $v) {
                if (is_array($v) && array_is_list($v)) {
                    $rows = count($v);
                    break;
                }
            }

            DB::table('buddy_tool_calls')->insert([
                'conversation_id' => $this->conversationId ?? 0,
                'user_id'         => $this->userId,
                'tool_name'       => substr($name, 0, 64),
                'args_json'       => mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}', 0, 2000),
                'result_rows'     => $rows,
                'duration_ms'     => $durationMs,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Audit must never break the chat; error pipeline picks it up.
            ErrorLogService::log('warning', '[buddy] tool audit insert failed: ' . $e->getMessage());
        }
    }
}
