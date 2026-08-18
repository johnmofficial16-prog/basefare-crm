<?php

namespace App\Services\Buddy;

/**
 * BuddyGeminiClient — Gemini function-calling loop for the buddy system.
 *
 * Same Vertex AI Express Mode surface as GeminiService (same key, same
 * endpoint, same two hard constraints: model gemini-2.5-flash, thinkingBudget
 * 0 — see GeminiService's docblock for why), extended with the tool loop:
 *
 *   send(contents, tools) → model returns functionCall parts
 *     → execute via BuddyToolRegistry → append functionResponse → resend
 *     → ... until the model answers in text (max MAX_HOPS rounds).
 *
 * OPEN VERIFICATION (AI_BUDDY_PLAN.md §2): thinkingBudget=0 is proven for
 * plain generation on this key; if tool-call selection proves poor in live
 * testing, grant a small budget HERE ONLY and re-measure cost.
 *
 * Fail-soft contract, same as everything else: every path returns
 * ['success' => bool, ...]; never throws.
 *
 * Testable offline: the HTTP POST is behind an injectable $transport
 * (fn(array $payload): array{code:int, body:string}), so unit tests drive the
 * whole loop with a fake Gemini.
 */
class BuddyGeminiClient
{
    private const ENDPOINT = 'https://aiplatform.googleapis.com/v1/publishers/google/models/%s:generateContent';
    private const DEFAULT_MODEL     = 'gemini-2.5-flash';
    private const MAX_OUTPUT_TOKENS = 1200;
    // 40s killed real 3.x turns mid-think (measured live: a patterns question
    // died at 41.7s). Wide enough for a thinking hop, still bounded.
    private const TIMEOUT_SECONDS   = 75;
    private const MAX_HOPS          = 4;   // tool rounds before we force a text answer

    private string $apiKey;
    private string $model;
    /** @var callable fn(array $payload): array{code:int, body:string} */
    private $transport;

    private ?string $thinkingLevel;

    public function __construct(?callable $transport = null, ?string $modelOverride = null, ?string $thinkingLevelOverride = null)
    {
        $this->apiKey    = $_ENV['VERTEX_API_KEY'] ?? getenv('VERTEX_API_KEY') ?: '';
        $this->model     = $modelOverride
            ?: ($_ENV['VERTEX_MODEL'] ?? getenv('VERTEX_MODEL') ?: self::DEFAULT_MODEL);
        // 3.x thinking control (opt-in): BUDDY_THINKING_LEVEL in .env, e.g.
        // 'low'. Only ever SENT when set — an unsupported value would 400 the
        // brain, so silence means "let the model decide", which is today's
        // behaviour. The probe validates a level before anyone opts in.
        $envLevel = $_ENV['BUDDY_THINKING_LEVEL'] ?? getenv('BUDDY_THINKING_LEVEL') ?: '';
        $this->thinkingLevel = $thinkingLevelOverride ?? ($envLevel !== '' ? $envLevel : null);
        $this->transport = $transport ?? [$this, 'httpTransport'];
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Run a chat turn with tools.
     *
     * @param string            $systemInstruction
     * @param array             $contents  Gemini contents (history + current user turn).
     * @param BuddyToolRegistry $registry  Role-scoped tools for THIS user.
     *
     * @return array {success: bool, text?: string, error?: string,
     *                hops: int, tool_calls: array<array{name: string, args: array}>}
     */
    public function chat(string $systemInstruction, array $contents, BuddyToolRegistry $registry): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'AI is not configured (missing VERTEX_API_KEY).', 'hops' => 0, 'tool_calls' => [], 'tokens_in' => 0, 'tokens_out' => 0];
        }

        $toolCalls = [];
        $tokensIn  = 0;   // accumulated across hops — every hop bills separately
        $tokensOut = 0;

        // Model-generation awareness (added Aug 2026 ahead of the 3.x upgrade):
        // - gemini-2.5-* REQUIRES thinkingBudget 0 on the Express key; 3.x
        //   models think by default, may reject the parameter, and BILL those
        //   thinking tokens as output — so for 3.x we omit thinkingConfig and
        //   raise maxOutputTokens, or a short visible answer dies at the cap
        //   with an empty reply after the thinking spend.
        // Upgrading the brain stays a one-line .env change (VERTEX_MODEL);
        // this branch is what makes that flip safe rather than a silent 400.
        $legacyThinking = str_starts_with($this->model, 'gemini-2.5');

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $generationConfig = ['maxOutputTokens' => $legacyThinking ? self::MAX_OUTPUT_TOKENS : 2048];
            if ($legacyThinking) {
                // MANDATORY for 2.5-flash on the Express key (see GeminiService).
                $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
            } elseif ($this->thinkingLevel !== null) {
                // 3.x: bound the pondering. Unbounded thinking measured 12s on
                // trivial turns and 41.7s (timeout) on multi-tool ones.
                $generationConfig['thinkingConfig'] = ['thinkingLevel' => $this->thinkingLevel];
            }
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                'contents'          => $contents,
                'generationConfig'  => $generationConfig,
            ];
            $declarations = $registry->declarations();
            if ($declarations !== []) {
                $payload['tools'] = [['functionDeclarations' => $declarations]];
            }

            $resp = ($this->transport)($payload);

            if (($resp['code'] ?? 0) !== 200) {
                $apiMsg  = '';
                $decoded = json_decode((string) ($resp['body'] ?? ''), true);
                if (isset($decoded['error']['message'])) {
                    $apiMsg = $decoded['error']['message'];
                }
                error_log('[BuddyGemini] HTTP ' . ($resp['code'] ?? '???') . ': '
                    . ($apiMsg ?: substr((string) ($resp['body'] ?? ''), 0, 300)));
                // Also into the Error Console — shared hosting hides the PHP
                // error log, and the REAL Google error (429 quota? 400 schema?
                // 403 billing?) is the difference between four unrelated fixes.
                \App\Services\ErrorLogService::log(
                    'warning',
                    '[BuddyGemini] HTTP ' . ($resp['code'] ?? '?') . ' on hop ' . $hop . ': '
                    . mb_substr($apiMsg ?: (string) ($resp['body'] ?? ''), 0, 400)
                );
                return [
                    'success' => false,
                    'error'   => 'The AI service returned an error.',
                    // Verbatim Google message for operators/probes — the
                    // generic line above stays what users might ever see.
                    'api_error'  => 'HTTP ' . ($resp['code'] ?? '?') . ': ' . mb_substr((string) $apiMsg, 0, 300),
                    'hops'    => $hop,
                    'tool_calls' => $toolCalls,
                    'tokens_in'  => $tokensIn,
                    'tokens_out' => $tokensOut,
                ];
            }

            $decoded = json_decode((string) $resp['body'], true);
            $parts   = $decoded['candidates'][0]['content']['parts'] ?? [];

            // Token accounting (P3 cost visibility): Express responses carry
            // usageMetadata; sum it per hop so buddy_messages records what the
            // turn really billed, not just the final hop.
            $tokensIn  += (int) ($decoded['usageMetadata']['promptTokenCount'] ?? 0);
            $tokensOut += (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? 0);
            // 3.x models bill internal reasoning as output — count it, or the
            // cost tile understates real spend by the exact amount that hurts.
            $tokensOut += (int) ($decoded['usageMetadata']['thoughtsTokenCount'] ?? 0);

            $functionCalls = [];
            $textOut       = '';
            foreach ($parts as $part) {
                if (isset($part['functionCall']['name'])) {
                    $functionCalls[] = $part['functionCall'];
                } elseif (isset($part['text'])) {
                    // 3.x thinking models may emit thought-summary parts
                    // (flagged "thought": true). That is internal reasoning,
                    // not the reply — letting it through would leak chain-of-
                    // thought into Aisha's mouth.
                    if (!empty($part['thought'])) {
                        continue;
                    }
                    $textOut .= $part['text'];
                }
            }

            if ($functionCalls === []) {
                if (trim($textOut) === '') {
                    $finish = $decoded['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                    error_log("[BuddyGemini] empty reply (finishReason={$finish})");
                    return ['success' => false, 'error' => 'The AI returned an empty response.', 'hops' => $hop, 'tool_calls' => $toolCalls, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
                }
                return ['success' => true, 'text' => trim($textOut), 'hops' => $hop, 'tool_calls' => $toolCalls, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
            }

            // Echo the model turn (with its functionCall parts) into history,
            // then answer every call with a functionResponse part.
            //
            // REBUILT, not echoed verbatim: json_decode gives PHP arrays, and an
            // empty args {} re-encodes as [] — a JSON *array* where Google's
            // proto wants a Struct *object*. Every parameterless tool call
            // (get_backup_status etc.) therefore 400'd on hop 1 with
            // "Unknown name args at contents[N].parts[0].function_call" —
            // found live via the Error Console, invisible to the fake
            // transport. (object) casts force {} for empty args; unknown
            // response-only part fields are dropped for the same reason.
            $echoParts = [];
            foreach ($parts as $part) {
                if (isset($part['functionCall']['name'])) {
                    $echo = ['functionCall' => [
                        'name' => $part['functionCall']['name'],
                        'args' => (object) ($part['functionCall']['args'] ?? []),
                    ]];
                    // Gemini 3.x thinking models sign their function calls;
                    // the signature MUST travel back with the echoed turn or
                    // the follow-up request is rejected. Same bug class as
                    // the 16 Aug empty-args {} incident: rebuild-and-drop
                    // loses a field the proto considers load-bearing. Absent
                    // on 2.5, harmless to pass through when present.
                    if (isset($part['thoughtSignature'])) {
                        $echo['thoughtSignature'] = $part['thoughtSignature'];
                    }
                    $echoParts[] = $echo;
                } elseif (isset($part['text']) && empty($part['thought'])) {
                    // Thought-summary text is internal — never echoed back.
                    $echo = ['text' => $part['text']];
                    if (isset($part['thoughtSignature'])) {
                        $echo['thoughtSignature'] = $part['thoughtSignature'];
                    }
                    $echoParts[] = $echo;
                }
            }
            $contents[] = ['role' => 'model', 'parts' => $echoParts];

            $responseParts = [];
            foreach ($functionCalls as $call) {
                $name = (string) $call['name'];
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $toolCalls[] = ['name' => $name, 'args' => $args];

                // Execute → scrub (Wall 2) → hand back to the model.
                $result = $registry->execute($name, $args);
                $result = BuddyPromptBuilder::scrubArray($result, "tool:{$name}");

                $responseParts[] = [
                    'functionResponse' => [
                        'name'     => $name,
                        // (object) for the same empty-array-vs-object reason.
                        'response' => (object) ['result' => $result],
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        // Tool ping-pong exceeded the cap — surface as a soft failure.
        error_log('[BuddyGemini] exceeded ' . self::MAX_HOPS . ' tool rounds without a text answer');
        return [
            'success' => false,
            'error'   => 'The AI could not reach an answer after several tool calls.',
            'hops'    => self::MAX_HOPS,
            'tool_calls' => $toolCalls,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }

    // =========================================================================
    // TRANSPORT
    // =========================================================================

    /** Real HTTP POST to Vertex Express. Key in a header, never the URL. */
    private function httpTransport(array $payload): array
    {
        $url = sprintf(self::ENDPOINT, rawurlencode($this->model));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('[BuddyGemini] cURL error: ' . $err);
            return ['code' => 0, 'body' => ''];
        }
        return ['code' => $code, 'body' => (string) $raw];
    }
}
