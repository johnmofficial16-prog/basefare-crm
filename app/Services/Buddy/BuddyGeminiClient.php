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
    private const TIMEOUT_SECONDS   = 40;
    private const MAX_HOPS          = 4;   // tool rounds before we force a text answer

    private string $apiKey;
    private string $model;
    /** @var callable fn(array $payload): array{code:int, body:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->apiKey    = $_ENV['VERTEX_API_KEY'] ?? getenv('VERTEX_API_KEY') ?: '';
        $this->model     = $_ENV['VERTEX_MODEL']   ?? getenv('VERTEX_MODEL')   ?: self::DEFAULT_MODEL;
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
            return ['success' => false, 'error' => 'AI is not configured (missing VERTEX_API_KEY).', 'hops' => 0, 'tool_calls' => []];
        }

        $toolCalls = [];

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                'contents'          => $contents,
                'generationConfig'  => [
                    'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                    // MANDATORY for 2.5-flash on the Express key (see GeminiService).
                    'thinkingConfig'  => ['thinkingBudget' => 0],
                ],
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
                return [
                    'success' => false,
                    'error'   => 'The AI service returned an error.',
                    'hops'    => $hop,
                    'tool_calls' => $toolCalls,
                ];
            }

            $decoded = json_decode((string) $resp['body'], true);
            $parts   = $decoded['candidates'][0]['content']['parts'] ?? [];

            $functionCalls = [];
            $textOut       = '';
            foreach ($parts as $part) {
                if (isset($part['functionCall']['name'])) {
                    $functionCalls[] = $part['functionCall'];
                } elseif (isset($part['text'])) {
                    $textOut .= $part['text'];
                }
            }

            if ($functionCalls === []) {
                if (trim($textOut) === '') {
                    $finish = $decoded['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                    error_log("[BuddyGemini] empty reply (finishReason={$finish})");
                    return ['success' => false, 'error' => 'The AI returned an empty response.', 'hops' => $hop, 'tool_calls' => $toolCalls];
                }
                return ['success' => true, 'text' => trim($textOut), 'hops' => $hop, 'tool_calls' => $toolCalls];
            }

            // Echo the model turn (with its functionCall parts) into history,
            // then answer every call with a functionResponse part.
            $contents[] = ['role' => 'model', 'parts' => $parts];

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
                        'response' => ['result' => $result],
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
