<?php
/**
 * Gemini model probe — which model IDs does OUR key actually accept?
 *
 *   php scripts/gemini_model_probe.php
 *
 * Written 19 Aug 2026 when VERTEX_MODEL=gemini-3.6-flash (a name from
 * third-party pricing blogs) took the buddy brain down. Instead of guessing at
 * names, this asks the real endpoint: one tiny "Say OK" generateContent call
 * per candidate, printing the HTTP code and Google's verbatim error. ~9 calls,
 * a few tokens each. The key is read from .env and never printed.
 *
 * 2.5-generation candidates get thinkingBudget 0 (the Express-key requirement);
 * 3.x candidates are sent without thinkingConfig, mirroring BuddyGeminiClient.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$key = $_ENV['VERTEX_API_KEY'] ?? '';
if ($key === '') {
    fwrite(STDERR, "No VERTEX_API_KEY in .env\n");
    exit(1);
}

$candidates = [
    $_ENV['VERTEX_MODEL'] ?? 'gemini-2.5-flash',   // whatever .env says right now, first
    'gemini-2.5-flash',
    'gemini-3.5-flash',
    'gemini-3.6-flash',
    'gemini-3.7-flash',
    'gemini-3.6-flash-001',
    'gemini-3.7-flash-001',
    'gemini-flash-latest',
];
$candidates = array_values(array_unique($candidates));

// ── FULL-LOOP MODE (default): reproduce the buddy's real payload ─────────────
// The bare probe proved 3.x models answer a naked prompt while the buddy still
// failed — so the breakage lives in what the buddy ADDS: system instruction,
// tool declarations, and the function-call echo hop. This mode runs the actual
// BuddyGeminiClient with the actual MaintenanceTools registry and a prompt
// that FORCES a tool round trip, then prints the verbatim API error on
// failure. `--bare` keeps the old minimal probe.
if (!in_array('--bare', $argv, true)) {
    $capsule = new \Illuminate\Database\Capsule\Manager;
    $capsule->addConnection([
        'driver' => 'mysql', 'host' => $_ENV['DB_HOST'], 'database' => $_ENV['DB_DATABASE'],
        'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    // Model × thinking-level matrix, WITH LATENCY — because the live lesson of
    // 19 Aug was that a smarter answer arriving after the timeout is a dumber
    // answer. 'low' is the candidate that could make 3.x usable in a chat
    // bubble; '(default)' shows what unbounded thinking costs.
    $matrix = [
        ['gemini-2.5-flash', null],
        ['gemini-3.5-flash', null], ['gemini-3.5-flash', 'low'],
        ['gemini-3.6-flash', null], ['gemini-3.6-flash', 'low'],
        ['gemini-3.7-flash', 'low'],   // default-thinking 3.7 already proven to time out
    ];
    echo "FULL TOOL-LOOP probe (real registry, forced function call, latency):\n\n";
    foreach ($matrix as [$model, $level]) {
        $client   = new \App\Services\Buddy\BuddyGeminiClient(null, $model, $level);
        $registry = \App\Services\Buddy\MaintenanceTools::registry(0);
        $t0 = microtime(true);
        $res = $client->chat(
            'You are a terse system assistant. You MUST call get_system_pulse before answering anything.',
            [['role' => 'user', 'parts' => [['text' => 'How many users are active right now? One short sentence.']]]],
            $registry
        );
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $label = $model . ' ' . ($level ?? '(default)');
        if ($res['success']) {
            printf("%-32s ✓ %5dms hops=%d tools=%d out_tokens=%d reply=\"%s\"\n",
                $label, $ms, $res['hops'], count($res['tool_calls']),
                $res['tokens_out'], trim(mb_substr((string) $res['text'], 0, 50)));
        } else {
            printf("%-32s ✗ %5dms %s\n", $label, $ms, $res['api_error'] ?? $res['error']);
        }
    }
    echo "\nReading it: pick the smartest ✓ row with latency you would accept in a chat\n"
       . "bubble (2.5's row is the baseline). Then set VERTEX_MODEL and, if the row\n"
       . "used a level, BUDDY_THINKING_LEVEL in .env. No deploy.\n";
    exit(0);
}

echo "Probing " . count($candidates) . " model ids against aiplatform.googleapis.com (bare)...\n\n";

foreach ($candidates as $model) {
    $config = ['maxOutputTokens' => 300];
    if (str_starts_with($model, 'gemini-2.5')) {
        $config['thinkingConfig'] = ['thinkingBudget' => 0];
    }
    $payload = [
        'contents'         => [['role' => 'user', 'parts' => [['text' => 'Say OK']]]],
        'generationConfig' => $config,
    ];

    $url = 'https://aiplatform.googleapis.com/v1/publishers/google/models/'
         . rawurlencode($model) . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $verdict = 'HTTP ' . $code;
    $dec = json_decode((string) $raw, true);
    if ($code === 200) {
        $text     = $dec['candidates'][0]['content']['parts'][0]['text'] ?? '(no text)';
        $thoughts = $dec['usageMetadata']['thoughtsTokenCount'] ?? 0;
        $out      = $dec['usageMetadata']['candidatesTokenCount'] ?? 0;
        $verdict .= " ✓ reply=\"" . trim(mb_substr((string) $text, 0, 40)) . "\""
                  . " out_tokens={$out} thinking_tokens={$thoughts}";
    } else {
        $msg = $dec['error']['message'] ?? mb_substr((string) $raw, 0, 160);
        $verdict .= ' ✗ ' . mb_substr((string) $msg, 0, 220);
    }
    printf("%-26s %s\n", $model, $verdict);
}

echo "\nPick a ✓ model, set VERTEX_MODEL in .env, done — no deploy.\n";
