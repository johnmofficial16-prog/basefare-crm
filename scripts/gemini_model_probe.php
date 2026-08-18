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

echo "Probing " . count($candidates) . " model ids against aiplatform.googleapis.com ...\n\n";

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
