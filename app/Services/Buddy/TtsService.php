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

    public static function isConfigured(): bool
    {
        return (bool) ($_ENV['GOOGLE_TTS_API_KEY'] ?? getenv('GOOGLE_TTS_API_KEY') ?: '');
    }

    public static function cacheDir(): string
    {
        return dirname(__DIR__, 3) . '/storage/buddy/tts';
    }

    /**
     * Synthesize (or fetch cached) speech for $text.
     * @return string|null  cache filename (hash.mp3) or null when unavailable.
     */
    public static function synthesize(string $text): ?string
    {
        try {
            if (!self::isConfigured()) {
                return null;
            }
            $text = trim(mb_substr($text, 0, self::MAX_CHARS));
            if ($text === '') {
                return null;
            }

            $voice = $_ENV['TTS_VOICE'] ?? self::DEFAULT_VOICE;
            $hash  = md5($voice . '|' . $text);
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
                    // Slightly quicker + brighter = friendly, not newsreader.
                    'speakingRate'  => 1.04,
                    'pitch'         => 1.5,
                ],
            ];

            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . ($_ENV['GOOGLE_TTS_API_KEY'] ?? getenv('GOOGLE_TTS_API_KEY')),
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
