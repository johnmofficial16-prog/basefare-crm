<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;
use Throwable;

/**
 * TtsService — Aisha's real voice (Google Cloud Text-to-Speech).
 *
 * The browser's speechSynthesis is robotic; the client wants Aisha to sound
 * like a warm, friendly woman actually greeting the agents. Cloud TTS Neural2/
 * Chirp voices deliver that. This service synthesizes MP3s server-side and
 * caches them by content hash, so repeated greetings cost one API call ever.
 *
 * ACTIVATION (after the GCP billing card lands):
 *   1. Enable texttospeech.googleapis.com in the Cloud console.
 *   2. Create an API key (restrict it to the TTS API).
 *   3. .env: GOOGLE_TTS_API_KEY=<key>   (optional: TTS_VOICE, default below)
 * No deploy needed — the widget probes /buddy/tts and upgrades automatically.
 *
 * FAIL-SOFT: not configured / API error / disk error all return null and the
 * widget falls back to browser speech, then to silence. Voice can never break
 * chat.
 *
 * Cost: greetings are ~400 chars; ~21 users/day ≈ 250k chars/month ≈ $4–8.
 * Cache means repeated text is free.
 */
class TtsService
{
    private const ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';

    /** Warm female Indian-English neural voice; override with TTS_VOICE. */
    private const DEFAULT_VOICE = 'en-IN-Neural2-A';

    private const MAX_CHARS       = 600;
    private const TIMEOUT_SECONDS = 15;

    /** Google's reason for the last failure — shown to the operator, never logged with any key material. */
    public static ?string $lastError = null;

    public static function isConfigured(): bool
    {
        return self::apiKey() !== '';
    }

    /**
     * Key resolution: a dedicated GOOGLE_TTS_API_KEY always wins; without one
     * we TRY the existing VERTEX_API_KEY. Rationale ("can't we just use the
     * key we already have?" — asked 19 Aug): if the key's project happens to
     * have the Text-to-Speech API enabled, the real voice simply works with
     * zero new setup. If it doesn't, Google answers with its exact reason
     * (usually SERVICE_DISABLED), we surface that, and the widget stays on
     * browser speech. The probe costs nothing and turns speculation into an
     * answer either way.
     */
    private static function apiKey(): string
    {
        $own = $_ENV['GOOGLE_TTS_API_KEY'] ?? getenv('GOOGLE_TTS_API_KEY');
        if (is_string($own) && $own !== '') {
            return $own;
        }
        $vertex = $_ENV['VERTEX_API_KEY'] ?? getenv('VERTEX_API_KEY');
        return is_string($vertex) && $vertex !== '' ? $vertex : '';
    }

    public static function cacheDir(): string
    {
        return dirname(__DIR__, 3) . '/storage/buddy/tts';
    }

    /**
     * Synthesize (or fetch cached) speech for $text.
     *
     * Voice character is tunable three ways, all without a deploy:
     *   TTS_VOICE  — Google voice name (default en-IN-Neural2-A)
     *   TTS_RATE   — speaking rate (default 1.04; lower = calmer, softer)
     *   TTS_PITCH  — semitones (default 1.5; NEGATIVE = warmer, lower voice)
     * plus per-call overrides (used by the admin's voice tasting) that are
     * whitelisted + clamped by the caller. The cache hash covers ALL knobs —
     * without that, two variants of one line would collide on one file.
     *
     * @return string|null  cache filename (hash.mp3) or null when unavailable.
     */
    public static function synthesize(string $text, ?string $voice = null, ?float $rate = null, ?float $pitch = null): ?string
    {
        try {
            if (!self::isConfigured()) {
                return null;
            }
            $text = trim(mb_substr($text, 0, self::MAX_CHARS));
            if ($text === '') {
                return null;
            }

            $voice = $voice ?: ($_ENV['TTS_VOICE'] ?? self::DEFAULT_VOICE);
            $rate  = $rate  ?? (float) ($_ENV['TTS_RATE']  ?? 1.04);
            $pitch = $pitch ?? (float) ($_ENV['TTS_PITCH'] ?? 1.5);
            $rate  = max(0.7, min(1.3, $rate));
            $pitch = max(-10.0, min(10.0, $pitch));

            $hash  = md5($voice . '|' . $rate . '|' . $pitch . '|' . $text);
            $dir   = self::cacheDir();
            $file  = $hash . '.mp3';
            $path  = $dir . '/' . $file;

            if (is_file($path) && filesize($path) > 200) {
                return $file;                     // cache hit — free
            }
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return null;
            }

            $payload = [
                'input' => ['text' => $text],
                'voice' => [
                    'languageCode' => substr($voice, 0, 5),
                    'name'         => $voice,
                ],
                'audioConfig' => [
                    'audioEncoding' => 'MP3',
                    'speakingRate'  => $rate,
                    'pitch'         => $pitch,
                ],
            ];

            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . self::apiKey(),
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $code !== 200) {
                $msg = '';
                $dec = json_decode((string) $raw, true);
                if (isset($dec['error']['message'])) {
                    $msg = $dec['error']['message'];
                }
                self::$lastError = "HTTP {$code}: " . mb_substr($msg ?: 'no response', 0, 300);
                ErrorLogService::log('warning', "[tts] synthesize failed HTTP {$code}: " . mb_substr($msg, 0, 200));
                return null;
            }

            $audio = base64_decode((string) (json_decode((string) $raw, true)['audioContent'] ?? ''), true);
            if ($audio === false || strlen($audio) < 200) {
                ErrorLogService::log('warning', '[tts] empty/invalid audioContent');
                return null;
            }

            if (@file_put_contents($path, $audio) !== strlen($audio)) {
                @unlink($path);
                return null;
            }
            return $file;
        } catch (Throwable $e) {
            ErrorLogService::log('warning', '[tts] ' . $e->getMessage());
            return null;
        }
    }

    /** Validate a client-supplied cache filename (route: GET /buddy/tts/{file}). */
    public static function safeFile(string $file): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.mp3$/', $file)) {
            return null;                          // hash-shaped names only — no traversal
        }
        $path = self::cacheDir() . '/' . $file;
        return is_file($path) ? $path : null;
    }
}
