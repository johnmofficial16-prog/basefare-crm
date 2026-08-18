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
     *   TTS_SSML   — auto (default) | on | off — see supportsSsml()
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

            // Markup is part of the recording: the same sentence sent as
            // plain text and as SSML are two different MP3s and must never
            // share one cache key.
            $useSsml = self::supportsSsml($voice);
            $hash    = md5($voice . '|' . $rate . '|' . $pitch . '|' . ($useSsml ? 'ssml' : 'txt') . '|' . $text);
            $dir   = self::cacheDir();
            $file  = $hash . '.mp3';
            $path  = $dir . '/' . $file;

            if (is_file($path) && filesize($path) > 200) {
                return $file;                     // cache hit — free
            }
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return null;
            }

            $audioConfig = [
                'audioEncoding' => 'MP3',
                'speakingRate'  => $rate,
            ];
            // MEASURED 19 Aug 2026: EVERY Chirp family rejects the pitch knob
            // — Chirp3-HD as well as the older Chirp-HD — and a rejected
            // request costs the whole voice, not just the setting. Unlike
            // SSML, this one really is "all Chirp"; see supportsSsml() for the
            // neighbouring rule that looks identical and is not.
            if (stripos($voice, 'chirp') === false) {
                $audioConfig['pitch'] = $pitch;
            }

            $payload = [
                'input'       => $useSsml ? ['ssml' => self::toSsml($text)] : ['text' => $text],
                'voice' => [
                    'languageCode' => substr($voice, 0, 5),
                    'name'         => $voice,
                ],
                'audioConfig' => $audioConfig,
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

    /**
     * Server-side mirror of the widget's plain(): markdown stripped,
     * whitespace collapsed.
     *
     * It exists so the server can pre-synthesize the EXACT string the widget
     * would otherwise have sent — same text in, same cache key out, one MP3
     * instead of two. If this ever drifts from plain() in buddy-widget.js the
     * only symptom is a silent doubling of the TTS bill, so the verifier
     * checks the pair against each other.
     */
    public static function speakable(string $text): string
    {
        $t = (string) $text;
        $t = preg_replace('/\\\\([\\\\$*_`#])/u', '$1', $t);
        $t = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $t);
        $t = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $t);
        $t = preg_replace('/`([^`\n]+)`/u', '$1', $t);
        $t = preg_replace('/^\s*[\*\-•]\s+/mu', '', $t);
        $t = preg_replace('/\s+/u', ' ', $t);

        return trim((string) $t);
    }

    /**
     * Does this voice accept SSML?
     *
     * MEASURED against the live key on 19 Aug 2026 by
     * scripts/tts_voice_probe.php — because the first version of this method
     * reasoned from the documentation and got it wrong. It excluded anything
     * matching "chirp", which silently denied SSML to the entire Chirp3-HD
     * family: the thirty voices most likely to fix the client's "no emotions,
     * it's like reading a paragraph".
     *
     *   Chirp3-HD (30 voices)          SSML HTTP 200      pitch REJECTED
     *   Chirp-HD  (3 voices)           SSML HTTP 400      pitch REJECTED
     *   Neural2 / Wavenet / Standard   SSML HTTP 200      pitch ok
     *
     * So the split is Chirp3 versus the older Chirp — not the word "Chirp".
     * A future family (Chirp4?) is unknown territory: run the probe rather
     * than extending the pattern on a hunch, which is the mistake this
     * comment exists to record.
     *
     * TTS_SSML=on|off forces the decision without a deploy if the API moves.
     */
    public static function supportsSsml(string $voice): bool
    {
        $mode = strtolower(trim((string) ($_ENV['TTS_SSML'] ?? getenv('TTS_SSML') ?: 'auto')));
        if (in_array($mode, ['off', 'false', '0', 'no'], true)) {
            return false;
        }
        if (in_array($mode, ['on', 'true', '1', 'yes'], true)) {
            return true;
        }

        if (stripos($voice, 'chirp') === false) {
            return true;                              // Neural2, Wavenet, Standard, Studio
        }

        return stripos($voice, 'chirp3') !== false;   // Chirp3-HD yes, the older Chirp-HD no
    }

    /**
     * Wrap a line in the smallest SSML that makes it sound spoken rather than
     * read aloud: a beat after the name, a longer one between sentences.
     *
     * Deliberately minimal. Heavy <emphasis> and prosody markup on a
     * conversational line lands as theatrical, which is a different flavour of
     * wrong from robotic. The breaks are the part that stops one flat run-on
     * delivery — the client's "reading a paragraph, not talking".
     */
    public static function toSsml(string $text): string
    {
        $t = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // The pause a person takes between saying your name and saying the
        // thing — "Hey TJ," ... "fresh page today".
        $t = preg_replace('/^([^,!?.]{1,30}[,!])\s+/u', '$1<break time="260ms"/> ', (string) $t, 1);

        // And between sentences, where a person breathes and a robot does not.
        $t = preg_replace('/([.!?])\s+/u', '$1<break time="380ms"/> ', (string) $t);

        return '<speak>' . $t . '</speak>';
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
